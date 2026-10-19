<?php

namespace Tests\Support\CfPlus;

use App\Models\Course;
use App\Models\CourseQuarter;
use App\Models\Enrollment;
use App\Models\Faculty;
use App\Models\Firm;
use App\Models\FirmEngineResult;
use App\Models\FirmMembership;
use App\Models\Scenario;
use App\Models\Student;
use App\Services\CfPlus\CfPlusAuctionEconomicsSnapshotService;
use App\Services\CfPlus\FirmAccountingElectionService;
use App\Support\Scenario\CfManufacturingScenarioDefinition;

/**
 * A + course with four firms in an open evaluation-stage quarter (Quarter 6),
 * the economics snapshot taken, and the first seeded student on Alpha. The
 * same market the Phase 9 lifecycle tests use.
 */
trait BuildsCfPlusMarket
{
    /** @return array{0: Course, 1: array<string, Firm>, 2: Student} */
    protected function cfPlusMarket(string $name = 'Plus market'): array
    {
        $faculty = Faculty::query()->firstOrFail();
        $course = Course::query()->create(['institution_id' => $faculty->institution_id, 'faculty_id' => $faculty->id, 'scenario_id' => Scenario::query()->where('code', 'cf_manufacturing')->value('id'), 'name' => $name, 'starts_on' => '2026-01-01', 'ends_on' => '2028-01-01', 'cf_plus_enabled' => true, 'build_quarters' => 2, 'adversarial_quarters' => 2, 'post_bid_quarters' => 1, 'status' => Course::STATUS_DEPLOYED]);
        for ($quarter = 1; $quarter <= 7; $quarter++) {
            CourseQuarter::query()->create(['course_id' => $course->id, 'quarter_number' => $quarter, 'starts_on' => now()->addMonths($quarter), 'ends_on' => now()->addMonths($quarter + 1), 'decision_deadline_at' => now()->addMonths($quarter + 1), 'state' => $quarter === 6 ? CourseQuarter::OPEN : CourseQuarter::CLOSED]);
        }
        $series = ['alpha' => [10000000, 12000000, 11000000], 'bravo' => [10000000, 12000000, 11000000], 'charlie' => [11000000, 13000000, 12000000], 'delta' => [9000000, 10000000, 9500000]];
        $firms = [];
        foreach ($series as $key => $income) {
            $firm = Firm::factory()->create(['course_id' => $course->id, 'institution_id' => $course->institution_id, 'name' => ucfirst($key)]);
            app(FirmAccountingElectionService::class)->lock($firm, CfManufacturingScenarioDefinition::fixedWarmupPayload('notes_elections'));
            $firms[$key] = $firm->fresh();
            foreach (array_values($income) as $offset => $operating) {
                $payload = ['firm_id' => $firm->id, 'course_id' => $course->id, 'institution_id' => $course->institution_id, 'quarter_number' => $offset + 3, 'fields' => ['bs_cash' => 100000000.0, 'n5_limit' => 50000000.0, 'n5_balance' => 10000000.0, 'bs_bank_overdraft' => 999999999.0], 'income_statement' => ['net_revenue' => $operating * 4, 'operating_income' => $operating, 'net_income' => $operating * 0.75], 'balance_sheet' => ['assets' => 200000000, 'liabilities' => 50000000, 'equity' => 150000000, 'line_items' => []], 'cash_flow_statement' => ['operating_cash_flow' => $operating, 'investing_cash_flow' => 0, 'financing_cash_flow' => 0, 'ending_cash' => 100000000, 'line_items' => ['cf_depr' => 2000000]], 'notes' => ['accounting_policies' => $firm->accounting_elections], 'intermediates' => [], 'engine_contract' => FirmEngineResult::ENGINE_CONTRACT, 'provenance' => FirmEngineResult::PROVENANCE_STUDENT_CLOSE, 'immutable' => true, 'validated_at' => now()];
                FirmEngineResult::query()->create($payload + ['payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR))]);
            }
        }
        $student = Student::query()->firstOrFail();
        Enrollment::query()->create(['course_id' => $course->id, 'student_id' => $student->id, 'institution_id' => $course->institution_id]);
        FirmMembership::factory()->create(['firm_id' => $firms['alpha']->id, 'course_id' => $course->id, 'student_id' => $student->id, 'institution_id' => $course->institution_id, 'assigned_by_faculty_id' => $course->faculty_id]);
        app(CfPlusAuctionEconomicsSnapshotService::class)->createAtEvaluationOpening($course, $course->quarters()->where('quarter_number', 6)->firstOrFail());

        return [$course->fresh(), $firms, $student];
    }
}
