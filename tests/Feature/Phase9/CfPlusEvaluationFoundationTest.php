<?php

namespace Tests\Feature\Phase9;

use App\Models\CfPlusDisclosureSnapshot;
use App\Models\Course;
use App\Models\CourseDecisionEnablement;
use App\Models\CourseQuarter;
use App\Models\Faculty;
use App\Models\Firm;
use App\Models\FirmEngineResult;
use App\Models\Scenario;
use App\Services\CfPlus\CfPlusConsultantReportService;
use App\Services\CfPlus\CfPlusDisclosureService;
use App\Services\CfPlus\CfPlusGate;
use App\Services\CfPlus\CfPlusTrueWorthService;
use App\Services\CfPlus\ConsultantValuationPrototype;
use App\Services\CfPlus\FirmAccountingElectionService;
use App\Services\Decisions\FirmDecisionService;
use App\Support\Scenario\CfManufacturingScenarioDefinition;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class CfPlusEvaluationFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_cf_plus_gate_and_q4_accounting_elections_are_explicit_and_locked(): void
    {
        [$course, $firm] = $this->courseAndFirm(true);
        $q4 = CourseQuarter::query()->create($this->quarter($course, 4));
        $q5 = CourseQuarter::query()->create($this->quarter($course, 5));

        CourseDecisionEnablement::query()->create([
            'course_quarter_id' => $q4->id,
            'scenario_decision_category_id' => $course->scenario->decisionCategories()->where('code', 'notes_elections')->value('id'),
            'enabled' => true,
        ]);

        $decisionService = app(FirmDecisionService::class);

        $this->assertSame(6, app(CfPlusGate::class)->evaluationStartQuarter($course));
        $this->assertArrayHasKey('notes_elections', $decisionService->enabledCategories($q4));
        $this->assertArrayNotHasKey('notes_elections', $decisionService->enabledCategories($q5));

        $fifo = CfManufacturingScenarioDefinition::fixedWarmupPayload('notes_elections');
        $alternative = [...$fifo, 'bad_debt_method' => 'percentage_of_sales', 'warranty_method' => 'expense_as_incurred'];
        app(FirmAccountingElectionService::class)->lock($firm, $alternative);
        $firmB = Firm::factory()->create(['course_id' => $course->id, 'institution_id' => $course->institution_id]);
        app(FirmAccountingElectionService::class)->lock($firmB, $fifo);
        $this->assertSame($alternative, $firm->fresh()->accounting_elections);
        $this->assertSame($fifo, $firmB->fresh()->accounting_elections);
        $this->assertNotSame($firm->accounting_elections, $firmB->accounting_elections);
        $this->assertNotNull($firm->fresh()->accounting_elections_locked_at);

        foreach (['forecasting', 'sourcing', 'production'] as $code) {
            CourseDecisionEnablement::query()->create(['course_quarter_id' => $q5->id, 'scenario_decision_category_id' => $course->scenario->decisionCategories()->where('code', $code)->value('id'), 'enabled' => true]);
        }
        $submitted = $this->operationalPayload();
        $resolved = $decisionService->resolveCompleteDecisions($firm->fresh(), $q5, $submitted);
        $this->assertSame($alternative, $resolved['notes_elections']);
        try {
            $decisionService->resolveCompleteDecisions($firm->fresh(), $q5, [...$submitted, 'notes_elections' => $fifo]);
            $this->fail('A later-quarter election payload must be rejected at the resolver boundary.');
        } catch (ValidationException $exception) {
            $this->assertSame('Accounting elections were locked in Quarter 4 and cannot be submitted again.', $exception->errors()['notes_elections'][0]);
        }

        try {
            app(FirmAccountingElectionService::class)->lock($firm, $fifo);
            $this->fail('Changing a locked election must fail.');
        } catch (ValidationException $exception) {
            $this->assertSame('Accounting elections were locked in Quarter 4 and cannot be changed.', $exception->errors()['notes_elections'][0]);
        }
    }

    public function test_q4_student_election_fields_hide_inventory_and_validation_injects_locked_fifo(): void
    {
        [$course] = $this->courseAndFirm(true);
        $quarter = CourseQuarter::query()->create($this->quarter($course, 4));
        $category = $course->scenario->decisionCategories()->where('code', 'notes_elections')->firstOrFail();
        $service = app(FirmAccountingElectionService::class);
        $fields = app(FirmDecisionService::class)->decisionFields($quarter, $category);
        $visibleKeys = array_column($fields, 'key');

        $this->assertSame(['depreciation_method', 'shipping_terms', 'bad_debt_method', 'warranty_method', 'excise_tax_treatment'], $visibleKeys);
        $this->assertNotContains('inventory_method', $visibleKeys);
        $submitted = array_diff_key(CfManufacturingScenarioDefinition::fixedWarmupPayload('notes_elections'), ['inventory_method' => true]);
        $validated = $service->validate($submitted);
        $this->assertSame(['inventory_method', ...$visibleKeys], array_keys($validated));
        $this->assertSame('fifo', $validated['inventory_method']);
        $this->assertCount(6, $validated);

        try {
            $service->validate([...$validated, 'inventory_method' => 'lifo']);
            $this->fail('Student tampering with the fixed inventory method was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame('Inventory method is fixed and locked to FIFO.', $exception->errors()['notes_elections.inventory_method'][0]);
        }
    }

    public function test_plain_cf_is_rejected_and_disclosure_freezes_only_published_statements_notes_and_elections(): void
    {
        [$plain, $plainFirm] = $this->courseAndFirm(false);
        $this->expectException(DomainException::class);
        app(CfPlusDisclosureService::class)->openForFirm($plain, $plainFirm);
    }

    public function test_cf_plus_disclosure_is_immutable_rival_only_and_contains_no_private_decisions(): void
    {
        [$course, $target] = $this->courseAndFirm(true);
        $viewer = Firm::factory()->create(['course_id' => $course->id, 'institution_id' => $course->institution_id]);
        app(FirmAccountingElectionService::class)->lock($target, CfManufacturingScenarioDefinition::fixedWarmupPayload('notes_elections'));
        foreach ([3 => 100, 4 => 120, 5 => 140] as $quarter => $operatingIncome) {
            $this->persistResult($target, $quarter, $operatingIncome, $operatingIncome * 0.75);
        }

        $snapshot = app(CfPlusDisclosureService::class)->openForFirm($course, $target);
        $same = app(CfPlusDisclosureService::class)->openForFirm($course, $target);
        $rival = app(CfPlusDisclosureService::class)->rivalSnapshot($course, $viewer, $target);

        $this->assertTrue($snapshot->is($same));
        $this->assertTrue($snapshot->is($rival));
        $this->assertSame([3, 4, 5], collect($snapshot->published_statements)->pluck('quarter_number')->all());
        $this->assertCount(3, $snapshot->source_result_ids);
        $this->assertSame(CfManufacturingScenarioDefinition::fixedWarmupPayload('notes_elections'), $snapshot->accounting_elections);
        $encoded = json_encode($snapshot->toArray(), JSON_THROW_ON_ERROR);
        foreach (['pricing', 'production', 'marketing', 'draft_payload', 'submitted_payload', 'random_seed'] as $privateKey) {
            $this->assertStringNotContainsString($privateKey, $encoded);
        }
        $this->assertSame(64, strlen($snapshot->payload_hash));
        $this->expectException(LogicException::class);
        $snapshot->forceFill(['opened_at_quarter' => 99])->save();
    }

    public function test_consultant_prototype_uses_literal_three_quarter_median_and_is_deterministic(): void
    {
        [$course, $target] = $this->courseAndFirm(true);
        app(FirmAccountingElectionService::class)->lock($target, CfManufacturingScenarioDefinition::fixedWarmupPayload('notes_elections'));
        foreach ([3 => 9000000, 4 => 12000000, 5 => 10000000] as $quarter => $operatingIncome) {
            $this->persistResult($target, $quarter, $operatingIncome, 7000000);
        }
        $snapshot = app(CfPlusDisclosureService::class)->openForFirm($course, $target);
        $prototype = app(ConsultantValuationPrototype::class);
        $first = $prototype->evaluateApproved($snapshot);
        $second = $prototype->evaluateApproved($snapshot);

        $this->assertSame([3, 4, 5], $first['reported_quarters']);
        $this->assertSame([9000000.0, 12000000.0, 10000000.0], $first['reported_operating_income']);
        $this->assertSame(10000000.0, $first['median_reported_base']);
        $this->assertSame(0.02, $first['capped_quarterly_growth']);
        $this->assertSame('gordon_growth', $first['terminal_method']);
        $this->assertSame(12, $first['projected_periods']);
        $this->assertSame($first, $second);
        $this->assertArrayNotHasKey('operating_cash_flow', $first);
        $this->assertGreaterThan(0, $first['consultant_value']);
        $this->assertEqualsWithDelta($first['consultant_value'], $first['explicit_period_present_value'] + $first['terminal_present_value'], 0.01);
        $this->assertGreaterThan(0.5, $first['terminal_share']);

        $stored = app(CfPlusConsultantReportService::class)->generateApproved($snapshot);
        $this->assertSame('10000000.00', $stored->median_reported_base);
        $this->assertSame($first['consultant_value'], (float) $stored->consultant_value);
        $this->assertSame($first['template_sections'], $stored->template_sections);
    }

    public function test_candidate_grid_remains_reported_only_without_raw_operating_cash_proxy(): void
    {
        $prototype = app(ConsultantValuationPrototype::class);
        $samples = [
            'conservative' => [7600000, 7800000, 8000000],
            'neutral' => [9000000, 9200000, 9400000],
            'aggressive' => [13000000, 13600000, 14200000],
            'strong' => [15000000, 16000000, 17000000],
            'distressed' => [1000000, 700000, 400000],
        ];
        $values = [];
        foreach ($samples as $name => $reported) {
            $snapshot = new CfPlusDisclosureSnapshot(['published_statements' => $this->statementSeries($reported)]);
            $detail = $prototype->evaluateApproved($snapshot);
            $values[$name] = $detail['consultant_value'];
            $this->assertGreaterThanOrEqual(0, $values[$name]);
            $this->assertArrayNotHasKey('operating_cash_flow', $detail);
        }
        $this->assertGreaterThan($values['neutral'], $values['aggressive']);
        $this->assertGreaterThan($values['aggressive'], $values['strong']);
        $this->assertLessThan($values['conservative'], $values['distressed']);
    }

    public function test_true_worth_uses_after_tax_operating_income_plus_depreciation_and_excludes_working_capital(): void
    {
        [$course, $target] = $this->courseAndFirm(true);
        app(FirmAccountingElectionService::class)->lock($target, CfManufacturingScenarioDefinition::fixedWarmupPayload('notes_elections'));
        foreach ([3 => [10000000, 2000000, -9000000], 4 => [12000000, 2100000, 18000000], 5 => [11000000, 2200000, 7000000]] as $quarter => [$operatingIncome, $depreciation, $workingCapital]) {
            $this->persistResult($target, $quarter, $operatingIncome, $operatingIncome + $workingCapital, $depreciation, $workingCapital);
        }
        $snapshot = app(CfPlusDisclosureService::class)->openForFirm($course, $target);
        $first = app(CfPlusTrueWorthService::class)->calculate($snapshot);
        $second = app(CfPlusTrueWorthService::class)->calculate($snapshot);

        $this->assertSame([3, 4, 5], $first['source_quarters']);
        $this->assertSame(0.25, $first['tax_rate']);
        $this->assertSame([9500000.0, 11100000.0, 10450000.0], $first['normalized_quarterly_recurring_cash']);
        $this->assertSame(10450000.0, $first['representative_quarterly_recurring_cash']);
        $this->assertSame(41800000.0, $first['annual_normalized_recurring_cash']);
        $this->assertSame(12, $first['projected_periods']);
        $this->assertSame('gordon_growth', $first['terminal_method']);
        $this->assertSame(0.12, $first['required_return']);
        $this->assertSame(0.02, $first['capped_quarterly_growth']);
        $this->assertSame(0.015, $first['annual_terminal_growth']);
        $this->assertSame($first, $second);
        $this->assertGreaterThan(0, $first['true_worth']);
        $this->assertNotSame([1000000.0, 30000000.0, 18000000.0], $first['normalized_quarterly_recurring_cash']);
    }

    /** @return array{Course, Firm} */
    private function courseAndFirm(bool $cfPlus): array
    {
        $faculty = Faculty::query()->firstOrFail();
        $course = Course::query()->create(['institution_id' => $faculty->institution_id, 'faculty_id' => $faculty->id, 'scenario_id' => Scenario::query()->where('code', 'cf_manufacturing')->value('id'), 'name' => fake()->unique()->sentence(3), 'starts_on' => '2026-09-01', 'ends_on' => '2028-01-01', 'cf_plus_enabled' => $cfPlus, 'build_quarters' => $cfPlus ? 2 : null, 'adversarial_quarters' => $cfPlus ? 2 : null, 'post_bid_quarters' => $cfPlus ? 1 : null, 'status' => Course::STATUS_DEPLOYED]);

        return [$course, Firm::factory()->create(['course_id' => $course->id, 'institution_id' => $course->institution_id])];
    }

    private function quarter(Course $course, int $number): array
    {
        return ['course_id' => $course->id, 'quarter_number' => $number, 'starts_on' => now()->addMonths($number), 'ends_on' => now()->addMonths($number + 1), 'decision_deadline_at' => now()->addMonths($number + 1), 'state' => CourseQuarter::OPEN];
    }

    private function persistResult(Firm $firm, int $quarter, float $operatingIncome, float $operatingCashFlow, float $depreciation = 0, float $accountsReceivableMovement = 0): FirmEngineResult
    {
        $payload = ['firm_id' => $firm->id, 'course_id' => $firm->course_id, 'institution_id' => $firm->institution_id, 'quarter_number' => $quarter, 'fields' => [], 'income_statement' => ['net_revenue' => $operatingIncome * 4, 'operating_income' => $operatingIncome, 'net_income' => $operatingIncome * 0.75], 'balance_sheet' => ['assets' => $operatingIncome * 8, 'liabilities' => $operatingIncome * 2, 'equity' => $operatingIncome * 6, 'line_items' => []], 'cash_flow_statement' => ['operating_cash_flow' => $operatingCashFlow, 'investing_cash_flow' => 0, 'financing_cash_flow' => 0, 'ending_cash' => $operatingCashFlow, 'line_items' => ['cf_depr' => $depreciation, 'cf_ar_chg' => $accountsReceivableMovement]], 'notes' => ['accounting_policies' => $firm->accounting_elections], 'intermediates' => [], 'engine_contract' => FirmEngineResult::ENGINE_CONTRACT, 'provenance' => FirmEngineResult::PROVENANCE_STUDENT_CLOSE, 'immutable' => true, 'validated_at' => now()];

        return FirmEngineResult::query()->create($payload + ['payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR))]);
    }

    /** @param array<int, int|float> $values */
    private function statementSeries(array $values): array
    {
        return collect($values)->values()->map(fn ($value, int $index): array => ['quarter_number' => $index + 1, 'income_statement' => ['operating_income' => $value]])->all();
    }

    /** @return array<string, array<string, mixed>> */
    private function operationalPayload(): array
    {
        return [
            'forecasting' => ['forecast_p1_r1' => 30000, 'forecast_p1_r2' => 40000, 'forecast_p1_r3' => 60000, 'forecast_p2_r1' => 27000, 'forecast_p2_r2' => 18000, 'forecast_p2_r3' => 16000, 'forecast_confidence' => 'medium'],
            'sourcing' => ['primary_supplier' => 'SUP001', 'primary_alloc' => 1, 'secondary_supplier' => 'NONE'],
            'production' => ['p1_production' => 130000, 'p2_production' => 61000, 'shifts' => 1, 'postponed_flag' => 'NO', 'postponed_pct' => 0],
        ];
    }
}
