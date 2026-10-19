<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\CourseQuarter;
use App\Models\Faculty;
use App\Models\Scenario;
use App\Models\Student;
use App\Services\Courses\CourseConfigurationService;
use App\Services\Decisions\FirmDecisionService;
use App\Services\Firms\FirmAssignmentService;
use App\Services\Interpretations\InterpretationService;
use App\Services\Quarters\QuarterManagementService;
use App\Support\Scenario\CfManufacturingScenarioDefinition;
use Illuminate\Database\Seeder;
use RuntimeException;

class Phase8DemoSeeder extends Seeder
{
    public const CLOSED_COURSE = 'Phase 8 Demo - Closed History';

    public const WALKTHROUGH_COURSE = 'Phase 8 Demo - Live Walkthrough';

    /** A team's explanation of Quarter 3 (U11), as a demo team might write it. */
    public const DEMO_EXPLANATION = [
        'changed' => 'Net revenue rose again in Quarter 3, and cash at quarter end grew by about $10 million, much faster than net income.',
        'why' => 'Customers ordered more in Quarter 3 because of the season, and our forecast kept up. Cash grew faster than profit because depreciation is an expense that uses no cash.',
        'notes' => 'The inventory note shows goods still on their way from the supplier, which we own under FOB shipping point, so inventory and accounts payable both include them.',
    ];

    public function run(
        CourseConfigurationService $courses,
        FirmAssignmentService $firms,
        FirmDecisionService $decisions,
        QuarterManagementService $quarters,
    ): void {
        $existing = Course::query()->whereIn('name', [self::CLOSED_COURSE, self::WALKTHROUGH_COURSE])->get();
        if ($existing->count() === 2) {
            $this->assertExistingDemoIsComplete($existing->firstWhere('name', self::CLOSED_COURSE), $existing->firstWhere('name', self::WALKTHROUGH_COURSE));
            $this->command->info('Phase 8 demo state already exists and is complete.');

            return;
        }
        if ($existing->isNotEmpty()) {
            throw new RuntimeException('Partial Phase 8 demo state exists. Run migrate:fresh --seed before recreating the opt-in demo.');
        }

        $faculty = Faculty::query()->with('user')->firstOrFail();
        $students = Student::query()->with('user')->where('institution_id', $faculty->institution_id)->orderBy('id')->get();
        if ($students->count() < 3) {
            throw new RuntimeException('Phase 8 demo seeding requires the three standard development students.');
        }

        $originalQueue = config('queue.default');
        config(['queue.default' => 'sync']);

        try {
            $closed = $this->configuredCourse($courses, $faculty, self::CLOSED_COURSE);
            $firmA = $firms->createFirm($faculty, $closed, 'P8-A', 'Phase 8 Firm A');
            $firmB = $firms->createFirm($faculty, $closed, 'P8-B', 'Phase 8 Firm B');
            foreach ([[$firmA, $students[0]], [$firmA, $students[1]], [$firmB, $students[2]]] as [$firm, $student]) {
                $firms->enroll($faculty, $closed, $student);
                $firms->assign($faculty, $closed, $firm, $student);
            }
            $closed = $courses->deploy($faculty, $closed);

            $q4 = $closed->quarters()->where('quarter_number', 4)->firstOrFail();
            $quarters->open($faculty, $q4);
            $payload = $this->quarterFourPayload();
            $limit = $decisions->dividendLimit($firmA, $q4, $payload);
            $dividend = round(min($limit->maximum, 250000.00), 2);
            if ($dividend <= 0) {
                throw new RuntimeException('The real dividend validator did not provide a positive legal Phase 8 demo dividend.');
            }
            // U11: each team explains Quarter 3 before it submits Quarter 4's decisions.
            $explanations = app(InterpretationService::class);
            $explanations->submit($students[0], $firmA, $q4, self::DEMO_EXPLANATION);
            $explanations->submit($students[2], $firmB, $q4, self::DEMO_EXPLANATION);
            $decisions->submit($students[0], $firmA, $q4, $payload, $dividend);
            $decisions->submit($students[2], $firmB, $q4, $payload, 0);
            $quarters->queueClose($faculty, $q4);

            $walkthrough = $this->configuredCourse($courses, $faculty, self::WALKTHROUGH_COURSE);
            $walkthroughFirm = $firms->createFirm($faculty, $walkthrough, 'P8-LIVE', 'Phase 8 Walkthrough Firm');
            $firms->enroll($faculty, $walkthrough, $students[0]);
            $firms->assign($faculty, $walkthrough, $walkthroughFirm, $students[0]);
            $walkthrough = $courses->deploy($faculty, $walkthrough);
            $quarters->open($faculty, $walkthrough->quarters()->where('quarter_number', 4)->firstOrFail());

            $this->assertExistingDemoIsComplete($closed->fresh(), $walkthrough->fresh());
            $this->command->info("Phase 8 demo ready. Closed course {$closed->id}; legal Firm A Q4 dividend $".number_format($dividend, 2).'.');
            $this->command->info("Live walkthrough course {$walkthrough->id}; Q4 is open for {$students[0]->user->email}.");
        } finally {
            config(['queue.default' => $originalQueue]);
        }
    }

    private function configuredCourse(CourseConfigurationService $courses, Faculty $faculty, string $name): Course
    {
        $scenario = Scenario::query()->where('code', CfManufacturingScenarioDefinition::CODE)->firstOrFail();
        $course = $courses->saveSetup($faculty, [
            'name' => $name,
            'institution_id' => $faculty->institution_id,
            'scenario_id' => $scenario->id,
            'starts_on' => '2027-01-01',
            'ends_on' => '2028-03-31',
            'payment_mode' => null,
            'cf_plus_enabled' => false,
              'accounting_election_enabled' => false,
        ]);
        $schedule = [];
        foreach (range(1, 5) as $quarter) {
            $month = 1 + (($quarter - 1) * 3);
            $start = now()->setDate(2027, $month, 1)->startOfDay();
            $end = $start->addMonths(3)->subDay();
            $schedule[] = [
                'quarter_number' => $quarter,
                'starts_on' => $start->toDateString(),
                'ends_on' => $end->toDateString(),
                'decision_deadline_at' => $end->toDateString(),
            ];
        }
        $courses->saveSchedule($faculty, $course, $schedule);
        $codes = $scenario->decisionCategories()->orderBy('sort_order')->pluck('code')->all();
        $matrix = [];
        foreach ([4, 5] as $quarter) {
            $matrix[$quarter] = array_fill_keys($codes, false);
            foreach (CourseConfigurationService::REQUIRED_QUARTER_FOUR as $code) {
                $matrix[$quarter][$code] = true;
            }
        }
        $courses->saveEnablements($faculty, $course, $matrix);
        $courses->saveFacultyRoles($faculty, $course, []);

        return $course->fresh();
    }

    /** @return array<string, array<string, mixed>> */
    private function quarterFourPayload(): array
    {
        return [
            'forecasting' => CfManufacturingScenarioDefinition::q0ForecastingPayload() + ['forecast_confidence' => 'medium'],
            'sourcing' => ['primary_supplier' => 'SUP001', 'primary_alloc' => 1.0, 'secondary_supplier' => 'NONE'],
            'production' => ['p1_production' => 130000, 'p2_production' => 61000, 'shifts' => 1, 'postponed_flag' => 'NO', 'postponed_pct' => 0],
        ];
    }

    private function assertExistingDemoIsComplete(?Course $closed, ?Course $walkthrough): void
    {
        if (! $closed || ! $walkthrough
            || $closed->firms()->count() !== 2
            || $closed->firms()->withCount('memberships')->get()->pluck('memberships_count')->sort()->values()->all() !== [1, 2]
            || $closed->firms()->whereHas('engineResults', fn ($query) => $query->where('quarter_number', 4))->count() !== 2
            || $closed->quarters()->where('quarter_number', 4)->value('state') !== CourseQuarter::CLOSED
            || $walkthrough->firms()->count() !== 1
            || $walkthrough->firms()->whereDoesntHave('engineResults', fn ($query) => $query->whereIn('quarter_number', [1, 2, 3]))->exists()
            || $walkthrough->quarters()->where('quarter_number', 4)->value('state') !== CourseQuarter::OPEN) {
            throw new RuntimeException('Existing Phase 8 demo state is incomplete. Run migrate:fresh --seed before recreating it.');
        }
    }
}
