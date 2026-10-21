<?php

namespace App\Services\Ai;

use App\Models\AccountingRequestReview;
use App\Models\CfPlusAuctionResult;
use App\Models\CfPlusConsultantReport;
use App\Models\CfPlusOffer;
use App\Models\Course;
use App\Models\CourseQuarter;
use App\Models\Firm;
use App\Models\FirmAccountingElectionRequest;
use App\Models\FirmEngineResult;
use App\Models\FirmRivalAnalysis;
use App\Models\Student;
use App\Services\Courses\CourseStatus;
use App\Services\Decisions\FirmDecisionService;
use App\Services\Interpretations\InterpretationService;
use App\Services\Interpretations\RivalAnalysisService;
use App\Services\Practice\PracticeRunService;
use App\Services\Practice\ReferenceTeams;
use App\Services\Practice\TeamSettings;
use App\Services\Projection\CatchUpProjection;
use App\Support\Dates;
use App\Support\Presentation\DecisionPresenter;
use App\Support\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The lookups Ask Claude may use (U1, AC2). Read-only, and every one is
 * limited to the course on screen: the course id comes from the page, never
 * from the model, so no question can reach another course. Students reach
 * Claude only as "Student 1", "Student 2" (AC3); emails never leave the server.
 *
 * Layer 1: the course's setup and where it stands. Layer 2: stored results,
 * the table shown beside an answer (Claude names the measures, firms and
 * quarters; the app fills in every number), decisions, method requests and
 * the practice run's scoreboard. Layer 3: the evaluation stage, shown as the
 * faculty page shows it (offer counts, never amounts, until settlement; true
 * worth only after it). U11 layer 2: the teams' explanations, with the
 * students' own words kept apart so the figure check lets Claude quote them
 * but never state them as fact (AE1).
 */
class AskClaudeTools
{
    /** @var array<string, string> student id => "Student n", stable within one answer */
    private array $pseudonyms = [];

    /** @var list<array{title: string, columns: list<string>, rows: list<array{label: string, cells: list<array{text: string, source: string}>}>}> tables to show beside the answer */
    private array $views = [];

    /** @var list<string> students' and the instructor's own words, which Claude may quote but not state as fact */
    private array $quotable = [];

    public function __construct(private readonly Course $course) {}

    /** @return list<array{name: string, description: string, input_schema: array<string, mixed>}> */
    public function definitions(): array
    {
        return [
            [
                'name' => 'get_course_setup',
                'description' => 'The course on screen as the instructor set it up: product, dates, every quarter with its dates and decision deadline, which decision areas students control in each quarter, the starting accounting methods, whether teams may ask to change methods, and the evaluation stage if the course has one.',
                'input_schema' => ['type' => 'object', 'properties' => new \stdClass],
            ],
            [
                'name' => 'get_course_status',
                'description' => 'Where the course on screen stands now: the open quarter and its deadline, each firm\'s submission status for it, firms whose results failed to calculate, method-change requests waiting, the evaluation stage, and whether the instructor has a practice run of it. Students appear as Student 1, Student 2 and so on.',
                'input_schema' => ['type' => 'object', 'properties' => new \stdClass],
            ],
            [
                'name' => 'get_results',
                'description' => 'Stored results for firms in the course on screen, quarter by quarter, already formatted as the screens show them. Quarters 1 to 3 are the warm-up. Leave firms out for every firm, and quarters out for the last three quarters with results. For the practice run, name its teams (Careful team, Average team, Careless team). Quote these figures exactly as given.',
                'input_schema' => $this->resultsSchema(),
            ],
            [
                'name' => 'show_table',
                'description' => 'Show the instructor a table of stored results beside your answer. Call it once when figures matter to the answer, naming the measures, firms and quarters; the app fills in every number from the stored results and returns them to you. The practice run\'s teams (Careful team, Average team, Careless team) can be named too. Keep it small: one to six measures.',
                'input_schema' => $this->resultsSchema(true),
            ],
            [
                'name' => 'get_decisions',
                'description' => 'The decisions a firm\'s quarter was calculated from, area by area, with the dividend declared and who submitted them (as Student n). Warm-up quarters 1 to 3 were decided by the simulation.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'firm' => ['type' => 'string', 'description' => 'The firm\'s name.'],
                    'quarter' => ['type' => 'integer', 'minimum' => 4],
                ], 'required' => ['firm', 'quarter']],
            ],
            [
                'name' => 'get_method_requests',
                'description' => 'Teams\' requests to change accounting methods: the firm, who filed it (as Student n), when, what changes from what to what, the team\'s reason, its status and the quarter it applies from.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'status' => ['type' => 'string', 'enum' => ['waiting', 'decided', 'all']],
                ], 'required' => ['status']],
            ],
            [
                'name' => 'get_evaluation_stage',
                'description' => 'The evaluation stage of a Flexee Financial Accounting+ course: when it opens, whether it is open or settled, each firm\'s consultant value and how many offers it has received. Offer amounts and each firm\'s true worth are kept back until the instructor settles the stage; after that, who won each firm, the price paid, the true worth of the stake and how far the price was above or below it.',
                'input_schema' => ['type' => 'object', 'properties' => new \stdClass],
            ],
            [
                'name' => 'get_team_explanations',
                'description' => 'The teams\' written explanations of one quarter\'s statements (what changed, why it changed, what the notes show), with who submitted each (as Student n), when, whether it is a draft, and the instructor\'s comment. A team explains a quarter before it submits the next quarter\'s decisions. The answers are the students\' own words: any figure in them is what the team wrote, not a stored result, so check it with get_results before saying whether it matches. Leave quarter out for the latest quarter explained.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'quarter' => ['type' => 'integer', 'minimum' => 3, 'description' => 'The quarter the teams explained.'],
                ]],
            ],
            [
                'name' => 'get_rival_analyses',
                'description' => 'Flexee Financial Accounting+ courses: each team\'s written analysis of a rival it bid on in the evaluation stage, with its own figure for 51% of the rival. Before the instructor settles the stage, only who has analysed whom (the text stays sealed, like the offers). After settlement, the answers and figures, the team\'s offer, the price paid and the true worth. The answers and the team\'s figure are the students\' own words, not stored results.',
                'input_schema' => ['type' => 'object', 'properties' => new \stdClass],
            ],
            [
                'name' => 'project_catch_up',
                'description' => 'Can anyone catch the leader? Plays the rest of the course for every firm from its real position at the last closed quarter, with the course\'s own market, and compares the firms quarter by quarter on one measure. Nothing is saved. Each firm repeats its latest decisions unless you give it a behaviour (on courses created from October 2026 also: careful, average or careless, the practice run\'s reference teams). Returns real and projected figures, who leads each quarter, and a one-line answer worked out by the app; the instructor sees the table beside your answer. Say clearly that projected quarters are a projection.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'measure' => ['type' => 'string', 'enum' => array_keys(CatchUpProjection::MEASURES)],
                    'behaviours' => ['type' => 'object', 'description' => 'Firm name => repeat, careful, average or careless. Firms left out repeat.', 'additionalProperties' => ['type' => 'string', 'enum' => array_keys(CatchUpProjection::BEHAVIOURS)]],
                ]],
            ],
            [
                'name' => 'get_practice_scoreboard',
                'description' => 'The instructor\'s practice run of this course, if there is one: value created, net income and cash for the Careful, Average and Careless reference teams in each quarter played, the leader in each quarter, and each team\'s rule.',
                'input_schema' => ['type' => 'object', 'properties' => new \stdClass],
            ],
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function run(string $name, array $input): array
    {
        return match ($name) {
            'get_course_setup' => $this->setup(),
            'get_course_status' => $this->status(),
            'get_results' => $this->results($input, false),
            'show_table' => $this->results($input, true),
            'get_decisions' => $this->decisions($input),
            'get_method_requests' => $this->requests((string) ($input['status'] ?? 'waiting')),
            'get_practice_scoreboard' => $this->scoreboard(),
            'get_evaluation_stage' => $this->evaluation(),
            'get_team_explanations' => $this->explanations($input),
            'get_rival_analyses' => $this->rivalAnalyses(),
            'project_catch_up' => $this->catchUp($input),
            default => ['error' => "There is no lookup called {$name}."],
        };
    }

    /** @return array<string, mixed> */
    private function setup(): array
    {
        $course = $this->course->loadMissing(['quarters.enablements.category']);
        $quarters = $course->quarters->sortBy('quarter_number')->values();
        $areas = [];
        foreach ($quarters->where('quarter_number', '>=', 4) as $quarter) {
            $areas['Quarter '.$quarter->quarter_number] = $quarter->enablements->where('enabled', true)
                ->map(fn ($row) => $this->areaName((string) $row->category->code))->sort()->values()->all();
        }
        $methods = [];
        foreach ($course->startingElections() as $key => $value) {
            $methods[DecisionPresenter::fieldLabel($key, $course->engineVersion())] = DecisionPresenter::valueLabel($key, $value);
        }

        return [
            'course' => $course->name,
            'product' => Product::fullName($course),
            'starts_on' => Dates::day($course->starts_on),
            'ends_on' => Dates::day($course->ends_on),
            'status' => $course->status,
            'engine' => ($course->engineVersion() === 2 ? 'current engine' : 'earlier engine (courses created before October 2026)').'. '.self::howSalesAreSet($course),
            'quarters' => $quarters->map(fn (CourseQuarter $q): array => [
                'quarter' => $q->quarter_number,
                'warm_up' => $q->quarter_number <= 3,
                'starts_on' => Dates::day($q->starts_on),
                'ends_on' => Dates::day($q->ends_on),
                'decisions_due' => $q->quarter_number >= 4 ? Dates::dateTime($q->decision_deadline_at) : null,
            ])->all(),
            'decision_areas_open_to_students' => $areas,
            'starting_accounting_methods' => $methods,
            'teams_may_ask_to_change_methods' => (bool) $course->accounting_election_enabled,
            'evaluation_stage' => $course->cf_plus_enabled ? [
                'opens_with_quarter' => CourseStatus::for($course)->evaluationStart(),
                'build_quarters' => $course->build_quarters,
                'offer_quarters' => $course->adversarial_quarters,
                'quarters_after_offers' => $course->post_bid_quarters,
            ] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function status(): array
    {
        $status = CourseStatus::for($this->course);
        $active = $status->active();
        $firms = $this->course->firms()->with('memberships.student')->orderBy('name')->get();

        $quarter = null;
        if ($active) {
            $quarter = [
                'quarter' => $active->quarter_number,
                'state' => $status->stateLabel($active),
                'decisions_due' => Dates::dateTime($active->decision_deadline_at),
                'deadline_has_passed' => $active->decision_deadline_at?->isPast() ?? false,
                'firms' => $firms->map(function (Firm $firm) use ($status, $active): array {
                    $row = $status->firmStatus($firm, $active);
                    $by = $row['decision']?->submitted_by_student_id;

                    return [
                        'firm' => $firm->name,
                        'members' => $firm->memberships->map(fn ($m) => $this->pseudonym((string) $m->student_id))->values()->all(),
                        'status' => match ($row['state']) {
                            'submitted' => 'submitted'.($by ? ' by '.$this->pseudonym((string) $by) : ''),
                            'draft' => 'draft saved, not submitted',
                            'locked' => $row['decision']?->carried_forward ? 'carried forward, no submission' : 'submitted',
                            default => 'nothing saved',
                        },
                    ];
                })->all(),
                'failed_results' => $status->failedCloses($active)->map(fn ($close) => $close->firm?->name)->filter()->values()->all(),
            ];
        }

        $practice = $this->course->is_practice ? null : app(PracticeRunService::class)->current($this->course);

        return [
            'today' => Dates::day(now()),
            'open_quarter' => $quarter,
            'next_quarter' => ($next = $status->next()) ? ['quarter' => $next->quarter_number, 'opens' => $status->opensWhen($next)] : null,
            'last_closed_quarter' => $status->lastClosed()?->quarter_number,
            'course_finished' => $status->isFinished(),
            'method_change_requests_waiting' => $this->course->accounting_election_enabled ? $status->pendingRequests() : null,
            'evaluation_stage_open' => $this->course->cf_plus_enabled ? $status->evaluationOpen() : null,
            'practice_run' => $practice ? [
                'played_to_quarter' => app(PracticeRunService::class)->playedTo($practice),
                'team_rules' => app(PracticeRunService::class)->rules($practice),
                'areas_open_to_students' => $this->openAreaWords(app(PracticeRunService::class)->openAreas($practice)),
            ] : 'none',
        ];
    }

    /**
     * @param  list<string>  $areas
     * @return list<string>
     */
    private function openAreaWords(array $areas): array
    {
        return array_values(array_map(fn (string $area): string => TeamSettings::AREA_WORDS[$area] ?? $area, $areas));
    }

    /** @return array<string, mixed> */
    private function resultsSchema(bool $withTitle = false): array
    {
        $properties = [
            'measures' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_keys(ResultMeasures::MEASURES)], 'minItems' => 1, 'maxItems' => 8],
            'firms' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Firm names; leave out for every firm.'],
            'quarters' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1], 'description' => 'Quarter numbers; leave out for the last three with results.'],
        ];
        if ($withTitle) {
            $properties = ['title' => ['type' => 'string', 'description' => 'A short title, for example "Cash, Quarters 3 to 5".']] + $properties;
        }

        return ['type' => 'object', 'properties' => $properties, 'required' => $withTitle ? ['title', 'measures'] : ['measures']];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function results(array $input, bool $show): array
    {
        $measures = array_values(array_intersect((array) ($input['measures'] ?? []), array_keys(ResultMeasures::MEASURES)));
        if ($measures === []) {
            return ['error' => 'Name at least one measure from the list.'];
        }
        $firms = $this->firms((array) ($input['firms'] ?? []));
        if (is_string($firms)) {
            return ['error' => $firms];
        }
        $results = FirmEngineResult::query()->whereIn('firm_id', $firms->pluck('id'))->orderBy('quarter_number')->get()->groupBy('firm_id');
        $available = $results->flatten()->pluck('quarter_number')->unique()->sort()->values();
        $quarters = collect((array) ($input['quarters'] ?? []))->map(fn ($q) => (int) $q)->filter(fn ($q) => $available->contains($q))->unique()->sort()->values();
        if ($quarters->isEmpty()) {
            $quarters = $available->slice(-3)->values();
        }
        $quarters = $quarters->slice(-12)->values();

        // Live, 19 October 2026: without these, Claude took a carried-forward quarter's repeated figures for a fault.
        $carried = DB::table('firm_quarter_decisions as d')->join('course_quarters as q', 'q.id', '=', 'd.course_quarter_id')
            ->whereIn('d.firm_id', $firms->pluck('id'))->whereIn('q.quarter_number', $quarters->all())
            ->get(['d.firm_id', 'q.quarter_number', 'd.carried_forward', 'd.submitted_at']);
        $out = [];
        $rows = [];
        foreach ($firms as $firm) {
            $own = $results->get($firm->id, collect());
            foreach ($quarters as $q) {
                if ($q >= 4 && $own->firstWhere('quarter_number', $q)) {
                    $d = $carried->first(fn ($row) => $row->firm_id === $firm->id && (int) $row->quarter_number === $q);
                    $out[$firm->name]['Quarter '.$q]['Decisions'] = $d && $d->carried_forward
                        ? 'carried forward: the team did not submit, so Quarter '.($q - 1).'\'s decisions were used again'
                        : 'submitted by the team';
                }
            }
            foreach ($measures as $measure) {
                [$label, $where] = ResultMeasures::MEASURES[$measure];
                $cells = [];
                $stored = false;
                foreach ($quarters as $q) {
                    $result = $own->firstWhere('quarter_number', $q);
                    $value = $result ? ResultMeasures::value($measure, $result, $own) : null;
                    $text = ! $result ? '–' : ($value === null ? 'not recorded' : ResultMeasures::format($measure, $value));
                    $out[$firm->name]['Quarter '.$q.($q <= 3 ? ' (warm-up)' : '')][$label] = $text;
                    // R20: a missing figure is a plain dash with no source to click.
                    $cells[] = $value === null ? ['text' => '—', 'source' => null] : ['text' => $text, 'source' => "{$firm->name} · Quarter {$q} · {$where} · {$label}"];
                    $stored = $stored || $value !== null;
                }
                // R20: a row with nothing stored in any column is left out of the table.
                if (! $stored) {
                    continue;
                }
                $rows[] = ['label' => $firms->count() > 1 ? ($measures === [$measure] || count($measures) === 1 ? $firm->name : "{$firm->name} · {$label}") : $label, 'cells' => $cells];
            }
        }
        if ($show && $rows !== [] && count($this->views) < 2) {
            $this->views[] = [
                'title' => mb_substr(trim((string) ($input['title'] ?? 'Stored results')), 0, 80),
                'columns' => $quarters->map(fn ($q) => 'Q'.$q.($q <= 3 ? ' warm-up' : ''))->all(),
                'rows' => $rows,
            ];
        }

        $note = $rows === [] ? 'Nothing is stored for these measures on this course, so no table is shown.' : 'The instructor sees this table beside your answer.';

        return ['results' => $out, 'how_sales_are_set' => self::howSalesAreSet($this->course)] + ($show ? ['shown' => $note] : []);
    }

    /** What decides a firm's sales on this course's engine, in a sentence Claude can rely on. */
    public static function howSalesAreSet(Course $course): string
    {
        return $course->engineVersion() === 2
            ? 'On this course\'s engine, demand comes from one market shared by the course\'s firms, so a firm\'s sales also depend on its rivals\' prices and marketing.'
            : 'On this course\'s earlier engine, each firm sells what its own sales forecast says, so the same decisions give the same sales, purchases, receivables and payables. A quarter played on repeated decisions can show the same figures as the quarter before; that is expected, not a fault. Depreciation on new capital spending still changes the result.';
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function decisions(array $input): array
    {
        $firms = $this->firms([(string) ($input['firm'] ?? '')]);
        if (is_string($firms)) {
            return ['error' => $firms];
        }
        $firm = $firms->first();
        $quarter = $this->course->quarters()->where('quarter_number', (int) ($input['quarter'] ?? 0))->first();
        if (! $quarter) {
            return ['error' => 'That quarter isn\'t in this course.'];
        }
        $decision = $firm->quarterDecisions()->where('course_quarter_id', $quarter->id)->first();
        if (! $decision || ! $decision->resolved_payload) {
            return ['firm' => $firm->name, 'quarter' => $quarter->quarter_number, 'decisions' => 'Not calculated yet: the quarter hasn\'t closed for this firm.'];
        }
        $parameters = app(FirmDecisionService::class)->parameters($firm);
        $areas = [];
        foreach ((array) $decision->resolved_payload as $category => $fields) {
            foreach ((array) $fields as $key => $value) {
                if (! DecisionPresenter::isInert($category, $key, $this->course->engineVersion())) {
                    $areas[DecisionPresenter::categoryLabel($category)][DecisionPresenter::fieldLabel($key, $this->course->engineVersion())] = DecisionPresenter::valueLabel($key, $value, $parameters);
                }
            }
        }

        return [
            'firm' => $firm->name,
            'quarter' => $quarter->quarter_number,
            'submitted' => $decision->carried_forward ? 'No submission: last quarter\'s decisions carried forward' : ($decision->submitted_by_student_id ? 'Submitted by '.$this->pseudonym((string) $decision->submitted_by_student_id) : 'Submitted'),
            'dividend_declared' => '$'.number_format((float) $decision->dividend, 2),
            'decisions' => $areas,
        ];
    }

    /** @return array<string, mixed> */
    private function requests(string $status): array
    {
        if (! $this->course->accounting_election_enabled) {
            return ['requests' => [], 'note' => 'Teams can\'t ask to change methods in this course.'];
        }
        $query = FirmAccountingElectionRequest::query()->with(['firm', 'courseQuarter', 'claudeReview'])->where('course_id', $this->course->id)->latest();
        match ($status) {
            'waiting' => $query->where('status', 'pending'),
            'decided' => $query->where('status', '!=', 'pending'),
            default => null,
        };

        return ['requests' => $query->limit(20)->get()->map(function (FirmAccountingElectionRequest $r): array {
            $changes = [];
            foreach ((array) $r->proposed_elections as $key => $value) {
                $before = ((array) $r->previous_elections)[$key] ?? null;
                if ($before !== $value) {
                    $changes[] = ['method' => DecisionPresenter::fieldLabel($key), 'from' => $before === null ? 'not recorded' : DecisionPresenter::valueLabel($key, $before), 'to' => DecisionPresenter::valueLabel($key, $value)];
                }
            }

            return [
                'firm' => $r->firm?->name,
                'filed_by' => $r->student_id ? $this->pseudonym((string) $r->student_id) : null,
                'filed_on' => Dates::day($r->created_at),
                'filed_in_quarter' => $r->courseQuarter?->quarter_number,
                'status' => match ($r->status) {
                    'pending' => 'waiting for the instructor',
                    'rejected' => 'declined',
                    'returned' => 'sent back to the team for its reason',
                    default => $r->status,
                },
                'changes' => $changes,
                'reason' => filled($r->justification) ? $r->justification : 'No reason given (filed before reasons were required).',
                'applies_from_quarter' => $r->effective_quarter_number,
                'instructor_note' => $r->faculty_note,
                // U12: Claude's stored review, when the course has it switched on.
                'claude_review' => RequestReviews::enabled($this->course) && $r->claudeReview?->isWritten() ? [
                    'suggests' => AccountingRequestReview::RECOMMENDATIONS[$r->claudeReview->recommendation] ?? $r->claudeReview->recommendation,
                    'reasons' => (array) $r->claudeReview->reasons,
                ] : null,
            ];
        })->all()];
    }

    /** @return array<string, mixed> */
    private function scoreboard(): array
    {
        $runs = app(PracticeRunService::class);
        $practice = $this->course->is_practice ? null : $runs->current($this->course);
        if (! $practice) {
            return ['practice_run' => 'none', 'note' => 'The instructor hasn\'t started a practice run of this course.'];
        }
        $board = $runs->scoreboard($practice);
        $out = [];
        foreach ($board as $team => $quarters) {
            foreach ($quarters as $q => $row) {
                $out[ReferenceTeams::NAMES[$team]]['Quarter '.$q.($q <= 3 ? ' (warm-up)' : '')] = [
                    'Value created' => ResultMeasures::format('value_created', $row['value']),
                    'Net income' => ResultMeasures::format('net_income', $row['net_income']),
                    'Cash at quarter end' => ResultMeasures::format('cash', $row['cash']),
                    'Orders not filled' => ResultMeasures::format('orders_not_filled', $row['short']),
                    'Units left in stock' => ResultMeasures::format('units_in_stock', $row['stock']),
                ];
            }
        }

        return [
            'played_to_quarter' => $runs->playedTo($practice),
            'leader_by_quarter' => collect($runs->leaders($practice))->mapWithKeys(fn ($t, $q) => ['Quarter '.$q => ReferenceTeams::NAMES[$t]])->all(),
            'team_rules' => collect($runs->rules($practice))->mapWithKeys(fn ($rule, $t) => [ReferenceTeams::NAMES[$t] => $rule])->all(),
            'areas_open_to_students' => $this->openAreaWords($runs->openAreas($practice)),
            'note_on_rules' => 'Each team rule covers only the areas students decide in this course. The simulation carries every other area forward, so it is not a reason for any difference between the teams.',
            'teams_on_custom_settings' => array_map(fn ($t) => ReferenceTeams::NAMES[$t], $runs->customTeams($practice)),
            'results' => $out,
        ];
    }

    /** @return array<string, mixed> */
    private function evaluation(): array
    {
        if (! $this->course->cf_plus_enabled) {
            return ['evaluation_stage' => 'none', 'note' => 'This course has no evaluation stage (it is Flexee Financial Accounting, not the + version).'];
        }
        $status = CourseStatus::for($this->course);
        $money = fn ($v) => $v === null ? null : ResultMeasures::format('cash', (float) $v);
        $settled = CfPlusAuctionResult::query()->with(['targetFirm', 'winner'])->where('course_id', $this->course->id)->whereNotNull('settled_at')->get();
        $reports = CfPlusConsultantReport::query()->where('course_id', $this->course->id)->latest('generated_at')->get()->unique('target_firm_id')->keyBy('target_firm_id');

        $out = [
            'opens_with_quarter' => $status->evaluationStart(),
            'open' => $status->evaluationOpen(),
            'settled' => $settled->isNotEmpty(),
            'firms' => $this->course->firms()->orderBy('name')->get()->map(fn (Firm $firm): array => [
                'firm' => $firm->name,
                'consultant_value' => $money($reports->get($firm->id)?->consultant_value),
                'offers_received' => CfPlusOffer::query()->where('course_id', $this->course->id)->where('target_firm_id', $firm->id)->count(),
            ])->all(),
        ];
        if ($settled->isEmpty()) {
            return $out + ['note' => 'Offer amounts and true worth stay hidden until the instructor settles the stage, as on the Evaluation stage page.'];
        }

        return $out + ['results' => $settled->map(fn (CfPlusAuctionResult $r): array => [
            'target' => $r->targetFirm?->name,
            'won_by' => $r->winner?->name ?? 'no winning offer',
            'price_paid' => $money($r->price_paid),
            'stake' => number_format((float) $r->stake * 100, 0).'%',
            'consultant_value_of_stake' => $money($r->consultant_stake_value),
            'true_worth_of_stake' => $money($r->true_stake_worth),
            'paid_above_true_worth' => $r->price_paid !== null && $r->true_stake_worth !== null ? $money((float) $r->price_paid - (float) $r->true_stake_worth) : null,
            'tie_settled_by_instructor' => (bool) $r->faculty_override,
        ])->all()];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function explanations(array $input): array
    {
        $interpretations = app(InterpretationService::class);
        $all = $interpretations->forCourse($this->course);
        $explained = $all->map(fn ($row) => $row->courseQuarter?->quarter_number)->filter()->unique()->sort()->values();
        $number = isset($input['quarter']) ? (int) $input['quarter'] : ($explained->last() ?? CourseStatus::for($this->course)->lastClosed()?->quarter_number);
        $out = [
            'course_asks_for_explanations' => $interpretations->required($this->course),
            'quarters_with_explanations' => $explained->map(fn ($q) => 'Quarter '.$q)->all(),
        ];
        $quarter = $number ? $this->course->quarters()->where('quarter_number', $number)->first() : null;
        if (! $quarter) {
            return $out + ['note' => 'No quarter has been explained yet. Teams first explain Quarter 3 before they submit Quarter 4\'s decisions.'];
        }
        $next = $this->course->quarters()->where('quarter_number', $quarter->quarter_number + 1)->first();
        $rows = $all->where('course_quarter_id', $quarter->id)->keyBy('firm_id');

        return $out + [
            'quarter' => $quarter->quarter_number,
            'due' => $next?->decision_deadline_at ? Dates::dateTime($next->decision_deadline_at).', with Quarter '.$next->quarter_number.'\'s decisions' : null,
            'firms' => $this->course->firms()->orderBy('name')->get()->map(function (Firm $firm) use ($rows): array {
                $row = $rows->get($firm->id);
                if (! $row) {
                    return ['firm' => $firm->name, 'status' => 'nothing written'];
                }
                $answers = [];
                foreach (['changed' => 'What changed?', 'why' => 'Why did it change?', 'notes' => 'What do the notes show?'] as $key => $prompt) {
                    $text = trim((string) ($row->answers[$key] ?? ''));
                    $answers[$prompt] = $text === '' ? 'Not written.' : $text;
                    $this->quotable[] = $text;
                }
                if ($row->faculty_comment) {
                    $this->quotable[] = (string) $row->faculty_comment;
                }

                return [
                    'firm' => $firm->name,
                    'status' => $row->isSubmitted()
                        ? 'submitted by '.($row->submitted_by_student_id ? $this->pseudonym((string) $row->submitted_by_student_id) : 'the team').' on '.Dates::day($row->submitted_at)
                        : 'draft only, not submitted',
                    'answers' => $answers,
                    'instructor_comment' => $row->faculty_comment,
                ];
            })->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function rivalAnalyses(): array
    {
        if (! $this->course->cf_plus_enabled) {
            return ['rival_analyses' => 'none', 'note' => 'This course has no evaluation stage.'];
        }
        $service = app(RivalAnalysisService::class);
        $settled = $service->settled($this->course);
        $results = CfPlusAuctionResult::query()->where('course_id', $this->course->id)->with('winner')->get()->keyBy('target_firm_id');
        $offers = CfPlusOffer::query()->where('course_id', $this->course->id)->get()->keyBy(fn ($o) => $o->bidder_firm_id.'|'.$o->target_firm_id);
        $money = fn ($v) => $v === null ? null : ResultMeasures::format('cash', (float) $v);

        $rows = $service->forCourse($this->course)->map(function ($row) use ($settled, $results, $offers, $money): array {
            $out = [
                'team' => $row->bidder?->name,
                'rival' => $row->target?->name,
                'status' => $row->isSubmitted()
                    ? 'submitted by '.($row->submitted_by_student_id ? $this->pseudonym((string) $row->submitted_by_student_id) : 'the team').' on '.Dates::day($row->submitted_at)
                    : 'draft only, not submitted',
            ];
            if (! $settled) {
                return $out;
            }
            $answers = [];
            foreach (FirmRivalAnalysis::PROMPTS as $key => $prompt) {
                $text = trim((string) ($row->answers[$key] ?? ''));
                $answers[$prompt] = $text === '' ? 'Not written.' : $text;
                $this->quotable[] = $text;
            }
            $figure = $money($row->estimated_stake_value);
            $this->quotable[] = (string) $figure;
            if ($row->faculty_comment) {
                $this->quotable[] = (string) $row->faculty_comment;
            }
            $result = $results->get($row->target_firm_id);
            $offer = $offers->get($row->bidder_firm_id.'|'.$row->target_firm_id);

            return $out + [
                'answers' => $answers,
                'teams_figure_for_51_percent' => $figure,
                'teams_offer' => $offer ? $money($offer->offered_amount) : null,
                'price_paid' => $result?->price_paid === null ? 'not sold' : $money($result->price_paid),
                'won_by' => $result?->winner?->name,
                'true_worth_of_51_percent' => $result ? $money($result->true_stake_worth) : null,
                'instructor_comment' => $row->faculty_comment,
            ];
        })->all();

        return [
            'settled' => $settled,
            'analyses' => $rows,
        ] + ($settled ? [] : ['note' => 'The text and figures stay sealed until the instructor settles the evaluation stage, like the offers.']);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> U10: the catch-up projection, shown beside the answer */
    private function catchUp(array $input): array
    {
        $firms = $this->course->firms()->get();
        $behaviours = [];
        foreach ((array) ($input['behaviours'] ?? []) as $name => $behaviour) {
            $firm = $firms->first(fn (Firm $f) => mb_strtolower($f->name) === mb_strtolower((string) $name));
            if (! $firm) {
                return ['error' => 'Unknown firm. The firms in this course are: '.$firms->sortBy('name')->pluck('name')->join(', ').'.'];
            }
            $behaviours[$firm->id] = (string) $behaviour;
        }
        $p = app(CatchUpProjection::class)->project($this->course, $behaviours, (string) ($input['measure'] ?? 'value_created'));
        $format = fn (?float $v) => ResultMeasures::format('cash', $v);
        $allowed = CatchUpProjection::behaviours($this->course);
        $all = collect($p['firms'])->flatMap(fn ($f) => array_keys($f['values']))->unique()->sort()->values();
        $first = collect($p['firms'])->flatMap(fn ($f) => $f['projected'])->min();
        $quarters = $all->filter(fn ($q) => $first ? $q >= $first - 1 : $q >= ($all->contains(4) ? 4 : 3))->values();

        $out = [];
        $rows = [];
        foreach ($p['firms'] as $f) {
            $cells = [];
            foreach ($quarters as $q) {
                $projected = in_array($q, $f['projected'], true);
                $text = $format($f['values'][$q] ?? null);
                $out[$f['name']]['plays_from_here'] = $allowed[$f['behaviour']];
                $out[$f['name']]['Quarter '.$q.($projected ? ' (projected)' : '')] = $text;
                $cells[] = ['text' => $text, 'source' => $projected
                    ? "Projection · {$f['name']} · Quarter {$q} · {$allowed[$f['behaviour']]}"
                    : "{$f['name']} · Quarter {$q} · stored result"];
            }
            $rows[] = ['label' => $f['name'], 'cells' => $cells];
        }
        if (count($this->views) < 2) {
            $this->views[] = [
                'title' => 'Can anyone catch up? '.$p['label'].' (shaded: projected)',
                'columns' => $quarters->map(fn ($q) => 'Q'.$q.(in_array($q, $p['firms'][0]['projected'] ?? [], true) ? ' projected' : ''))->all(),
                'rows' => $rows,
            ];
        }

        return [
            'measure' => $p['label'],
            'answer' => $p['answer'],
            'leader_by_quarter' => collect($p['leaders'])->filter(fn ($id, $q) => $q >= 4)->mapWithKeys(fn ($id, $q) => ['Quarter '.$q => collect($p['firms'])->firstWhere('id', $id)['name'] ?? null])->all(),
            'firms' => $out,
            'note' => trim('Projected quarters are played by the engine from each firm\'s real position with the course\'s own market; nothing is saved. '.($p['note'] ?? '')),
        ];
    }

    /** @return list<string> the students' and instructor's own words from this answer's lookups */
    public function quotable(): array
    {
        return array_values(array_filter($this->quotable));
    }

    /**
     * What the figure check may treat as fact from a lookup: everything but the students' and
     * the instructor's own words, which go in quotable() instead (AE1).
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public static function factual(string $name, array $result): array
    {
        $own = ['answers' => true, 'instructor_comment' => true, 'teams_figure_for_51_percent' => true];
        if ($name === 'get_team_explanations' && isset($result['firms'])) {
            $result['firms'] = array_map(fn ($firm) => array_diff_key($firm, $own), $result['firms']);
        }
        if ($name === 'get_rival_analyses' && isset($result['analyses'])) {
            $result['analyses'] = array_map(fn ($row) => array_diff_key($row, $own), $result['analyses']);
        }

        return $result;
    }

    /**
     * @param  list<string>  $names
     * @return Collection<int, Firm>|string the firms, or a message for Claude
     */
    private function firms(array $names)
    {
        $all = $this->course->firms()->orderBy('name')->get();
        $names = array_values(array_filter(array_map('trim', $names)));
        if ($names === []) {
            return $all;
        }
        $wanted = array_unique(array_map(fn (string $n): string => mb_strtolower($n), $names));
        $match = fn ($firms) => $firms->filter(fn (Firm $f) => in_array(mb_strtolower($f->name), $wanted, true) || in_array(mb_strtolower(str_replace(' team', '', $f->name)), $wanted, true))->values();
        $picked = $match($all);
        if ($picked->count() === count($wanted)) {
            return $picked;
        }
        // R21: the practice run's reference teams, asked about on the Practice run page or by name.
        $practice = $this->course->is_practice ? null : app(PracticeRunService::class)->current($this->course);
        $teams = $practice ? $practice->firms()->orderBy('code')->get() : collect();
        $picked = $match($teams);
        if ($teams->isNotEmpty() && $picked->count() === count($wanted)) {
            return $picked;
        }

        return 'Unknown firm. The firms in this course are: '.$all->pluck('name')->join(', ').'.'
            .($teams->isNotEmpty() ? ' The practice run\'s teams are: '.$teams->pluck('name')->join(', ').'.' : '');
    }

    /** @return list<array{title: string, columns: list<string>, rows: list<array{label: string, cells: list<array{text: string, source: string}>}>}> */
    public function views(): array
    {
        return $this->views;
    }

    private function pseudonym(string $studentId): string
    {
        return $this->pseudonyms[$studentId] ??= 'Student '.(count($this->pseudonyms) + 1);
    }

    /** @return array<string, string> "Student n" => the student's name, for the panel to put names back (AC3) */
    public function names(): array
    {
        if ($this->pseudonyms === []) {
            return [];
        }
        $names = Student::query()->with('user')->whereIn('id', array_keys($this->pseudonyms))->get()
            ->mapWithKeys(fn ($s) => [(string) $s->id => (string) $s->user?->name]);
        $out = [];
        foreach ($this->pseudonyms as $id => $label) {
            $out[$label] = $names[$id] ?? $label;
        }

        return $out;
    }

    private function areaName(string $code): string
    {
        return [
            'forecasting' => 'Forecasting', 'sourcing' => 'Sourcing', 'pricing' => 'Pricing', 'production' => 'Production',
            'marketing' => 'Marketing', 'quality' => 'Quality', 'logistics' => 'Logistics',
            'notes_elections' => 'Accounting methods', 'certification' => 'Certification',
        ][$code] ?? $code;
    }
}
