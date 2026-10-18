<?php

namespace App\Services\Ai;

use App\Models\Course;
use App\Models\CourseQuarter;
use App\Models\Firm;
use App\Models\Student;
use App\Services\Courses\CourseStatus;
use App\Services\Practice\PracticeRunService;
use App\Services\Practice\ReferenceTeams;
use App\Support\Dates;
use App\Support\Presentation\DecisionPresenter;
use App\Support\Product;

/**
 * The lookups Ask Claude may use (U1, AC2). Read-only, and every one is
 * limited to the course on screen: the course id comes from the page, never
 * from the model, so no question can reach another course. Students reach
 * Claude only as "Student 1", "Student 2" (AC3); emails never leave the server.
 *
 * Layer 1: the course's setup and where it stands. Stored results, the
 * practice run's figures and the evaluation stage follow in layers 2 and 3.
 */
class AskClaudeTools
{
    /** @var array<string, string> student id => "Student n", stable within one answer */
    private array $pseudonyms = [];

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
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function run(string $name, array $input): array
    {
        return match ($name) {
            'get_course_setup' => $this->setup(),
            'get_course_status' => $this->status(),
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
