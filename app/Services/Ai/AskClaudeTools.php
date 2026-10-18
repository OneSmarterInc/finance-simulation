<?php

namespace App\Services\Ai;

use App\Models\Course;
use App\Models\CourseQuarter;
use App\Models\Firm;
use App\Models\FirmAccountingElectionRequest;
use App\Models\FirmEngineResult;
use App\Models\Student;
use App\Services\Courses\CourseStatus;
use App\Services\Decisions\FirmDecisionService;
use App\Services\Practice\PracticeRunService;
use App\Services\Practice\ReferenceTeams;
use App\Support\Dates;
use App\Support\Presentation\DecisionPresenter;
use App\Support\Product;
use Illuminate\Support\Collection;

/**
 * The lookups Ask Claude may use (U1, AC2). Read-only, and every one is
 * limited to the course on screen: the course id comes from the page, never
 * from the model, so no question can reach another course. Students reach
 * Claude only as "Student 1", "Student 2" (AC3); emails never leave the server.
 *
 * Layer 1: the course's setup and where it stands. Layer 2: stored results,
 * the table shown beside an answer (Claude names the measures, firms and
 * quarters; the app fills in every number), decisions, method requests and
 * the practice run's scoreboard. The evaluation stage follows in layer 3.
 */
class AskClaudeTools
{
    /** @var array<string, string> student id => "Student n", stable within one answer */
    private array $pseudonyms = [];

    /** @var list<array{title: string, columns: list<string>, rows: list<array{label: string, cells: list<array{text: string, source: string}>}>}> tables to show beside the answer */
    private array $views = [];

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
                'description' => 'Stored results for firms in the course on screen, quarter by quarter, already formatted as the screens show them. Quarters 1 to 3 are the warm-up. Leave firms out for every firm, and quarters out for the last three quarters with results. Quote these figures exactly as given.',
                'input_schema' => $this->resultsSchema(),
            ],
            [
                'name' => 'show_table',
                'description' => 'Show the instructor a table of stored results beside your answer. Call it once when figures matter to the answer, naming the measures, firms and quarters; the app fills in every number from the stored results and returns them to you. Keep it small: one to six measures.',
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
            'engine' => $course->engineVersion() === 2 ? 'current engine (demand comes from the market)' : 'earlier engine (courses created before October 2026)',
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
                'team_rules' => ReferenceTeams::RULES,
            ] : 'none',
        ];
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

        $out = [];
        $rows = [];
        foreach ($firms as $firm) {
            $own = $results->get($firm->id, collect());
            foreach ($measures as $measure) {
                [$label, $where] = ResultMeasures::MEASURES[$measure];
                $cells = [];
                foreach ($quarters as $q) {
                    $result = $own->firstWhere('quarter_number', $q);
                    $text = $result ? ResultMeasures::format($measure, ResultMeasures::value($measure, $result, $own)) : '–';
                    $out[$firm->name]['Quarter '.$q.($q <= 3 ? ' (warm-up)' : '')][$label] = $text;
                    $cells[] = ['text' => $text, 'source' => "{$firm->name} · Quarter {$q} · {$where} · {$label}"];
                }
                $rows[] = ['label' => $firms->count() > 1 ? ($measures === [$measure] || count($measures) === 1 ? $firm->name : "{$firm->name} · {$label}") : $label, 'cells' => $cells];
            }
        }
        if ($show && count($this->views) < 2) {
            $this->views[] = [
                'title' => mb_substr(trim((string) ($input['title'] ?? 'Stored results')), 0, 80),
                'columns' => $quarters->map(fn ($q) => 'Q'.$q.($q <= 3 ? ' warm-up' : ''))->all(),
                'rows' => $rows,
            ];
        }

        return ['results' => $out] + ($show ? ['shown' => 'The instructor sees this table beside your answer.'] : []);
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
        $query = FirmAccountingElectionRequest::query()->with(['firm', 'courseQuarter'])->where('course_id', $this->course->id)->latest();
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
                'status' => $r->status === 'pending' ? 'waiting for the instructor' : $r->status,
                'changes' => $changes,
                'reason' => filled($r->justification) ? $r->justification : 'No reason given (filed before reasons were required).',
                'applies_from_quarter' => $r->effective_quarter_number,
                'instructor_note' => $r->faculty_note,
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
            'team_rules' => collect(ReferenceTeams::RULES)->mapWithKeys(fn ($rule, $t) => [ReferenceTeams::NAMES[$t] => $rule])->all(),
            'results' => $out,
        ];
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
        $picked = $all->filter(fn (Firm $f) => in_array(mb_strtolower($f->name), array_map('mb_strtolower', $names), true))->values();

        return $picked->count() === count(array_unique(array_map('mb_strtolower', $names)))
            ? $picked
            : 'Unknown firm. The firms in this course are: '.$all->pluck('name')->join(', ').'.';
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
