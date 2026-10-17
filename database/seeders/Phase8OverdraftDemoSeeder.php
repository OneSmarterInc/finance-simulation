<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\CourseQuarter;
use App\Models\Faculty;
use App\Models\FirmEngineResult;
use App\Models\Scenario;
use App\Models\Student;
use App\Services\Courses\CourseConfigurationService;
use App\Services\Decisions\FirmDecisionService;
use App\Services\Firms\FirmAssignmentService;
use App\Services\Quarters\QuarterManagementService;
use App\Support\Scenario\CfManufacturingScenarioDefinition;
use Illuminate\Database\Seeder;
use RuntimeException;

class Phase8OverdraftDemoSeeder extends Seeder
{
    public const COURSE = 'Phase 8 Demo - Legitimate Overdraft';

    public const FIRM_CODE = 'P8-OD';

    public function run(
        CourseConfigurationService $courses,
        FirmAssignmentService $firms,
        FirmDecisionService $decisions,
        QuarterManagementService $quarters,
    ): void {
        $existing = Course::query()->where('name', self::COURSE)->first();
        if ($existing !== null) {
            $this->assertComplete($existing);
            $this->command->info('Phase 8 legitimate overdraft demo already exists and is complete.');

            return;
        }

        $faculty = Faculty::query()->firstOrFail();
        $student = Student::query()->where('institution_id', $faculty->institution_id)->firstOrFail();
        $scenario = Scenario::query()->where('code', CfManufacturingScenarioDefinition::CODE)->firstOrFail();
        $course = $courses->saveSetup($faculty, [
            'name' => self::COURSE,
            'institution_id' => $faculty->institution_id,
            'scenario_id' => $scenario->id,
            'starts_on' => '2027-01-01',
            'ends_on' => '2028-03-31',
            'payment_mode' => null,
            'cf_plus_enabled' => false,
              'accounting_election_enabled' => false,
        ]);
        $courses->saveSchedule($faculty, $course, $this->schedule());
        $codes = $scenario->decisionCategories()->orderBy('sort_order')->pluck('code')->all();
        $operational = ['forecasting', 'sourcing', 'pricing', 'production', 'marketing', 'quality', 'logistics'];
        $matrix = [];
        foreach ([4, 5] as $quarterNumber) {
            $matrix[$quarterNumber] = array_fill_keys($codes, false);
            foreach ($operational as $code) {
                $matrix[$quarterNumber][$code] = true;
            }
        }
        $courses->saveEnablements($faculty, $course, $matrix);
        $courses->saveFacultyRoles($faculty, $course, []);

        $firm = $firms->createFirm($faculty, $course, self::FIRM_CODE, 'Phase 8 Legitimate Overdraft Firm');
        $firms->enroll($faculty, $course, $student);
        $firms->assign($faculty, $course, $firm, $student);

        $originalQueue = config('queue.default');
        config(['queue.default' => 'sync']);

        try {
            $course = $courses->deploy($faculty, $course);
            $quarter = $course->quarters()->where('quarter_number', 4)->firstOrFail();
            $quarters->open($faculty, $quarter);
            $decisions->submit($student, $firm, $quarter, $this->legalAggressiveDecisions(), 0);
            $quarters->queueClose($faculty, $quarter);
            $this->assertComplete($course->fresh());

            $result = $firm->engineResults()->where('quarter_number', 4)->firstOrFail();
            $this->command->info('Phase 8 legitimate overdraft demo ready. Stored Q4 overdraft $'.number_format((float) $result->fields['bs_bank_overdraft'], 2).'.');
        } finally {
            config(['queue.default' => $originalQueue]);
        }
    }

    /** @return list<array{quarter_number: int, starts_on: string, ends_on: string, decision_deadline_at: string}> */
    private function schedule(): array
    {
        $schedule = [];
        foreach (range(1, 5) as $quarterNumber) {
            $month = 1 + (($quarterNumber - 1) * 3);
            $start = now()->setDate(2027, $month, 1)->startOfDay();
            $end = $start->addMonths(3)->subDay();
            $schedule[] = [
                'quarter_number' => $quarterNumber,
                'starts_on' => $start->toDateString(),
                'ends_on' => $end->toDateString(),
                'decision_deadline_at' => $end->toDateString(),
            ];
        }

        return $schedule;
    }

    /** @return array<string, array<string, mixed>> */
    private function legalAggressiveDecisions(): array
    {
        return [
            'forecasting' => array_fill_keys(array_keys(CfManufacturingScenarioDefinition::q0ForecastingPayload()), 100000) + ['forecast_confidence' => 'high'],
            'sourcing' => ['primary_supplier' => 'SUP006', 'primary_alloc' => 1.0, 'secondary_supplier' => 'NONE'],
            'pricing' => ['price_p1_r1' => 210, 'price_p1_r2' => 210, 'price_p1_r3' => 210, 'price_p2_r1' => 280, 'price_p2_r2' => 280, 'price_p2_r3' => 280, 'discount_policy' => 'aggressive'],
            'production' => ['p1_production' => 500000, 'p2_production' => 500000, 'shifts' => 3, 'postponed_flag' => 'NO', 'postponed_pct' => 0],
            'marketing' => ['marketing_budget_pct' => 0.20, 'marketing_alloc_champions' => 0.25, 'marketing_alloc_growth' => 0.25, 'marketing_alloc_at_risk' => 0.25, 'marketing_alloc_other' => 0.25],
            'quality' => ['inspection_level' => 'FULL'],
            'logistics' => ['logistics_mode' => 'AIR'],
        ];
    }

    private function assertComplete(Course $course): void
    {
        $result = FirmEngineResult::query()
            ->where('course_id', $course->id)
            ->where('quarter_number', 4)
            ->where('provenance', FirmEngineResult::PROVENANCE_STUDENT_CLOSE)
            ->first();

        if ($course->firms()->count() !== 1
            || $course->firms()->where('code', self::FIRM_CODE)->whereHas('memberships')->count() !== 1
            || $course->quarters()->where('quarter_number', 4)->value('state') !== CourseQuarter::CLOSED
            || $result === null
            || (float) ($result->fields['bs_bank_overdraft'] ?? 0) <= 0.01
            || (float) ($result->fields['n5_shortfall_indicator'] ?? 0) !== 1.0
            || ! $result->immutable) {
            throw new RuntimeException('Existing Phase 8 legitimate overdraft demo state is incomplete. Run migrate:fresh --seed before recreating it.');
        }
    }
}
