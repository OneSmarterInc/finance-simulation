<?php

namespace Tests\Feature\Warmup;

use App\Jobs\GenerateCfManufacturingWarmup;
use App\Models\Scenario;
use App\Models\ScenarioQuarterDecision;
use App\Services\Engines\CorporateFinance\CfManufacturingSimRulesService;
use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngine;
use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngineResult;
use App\Services\Engines\CorporateFinance\Exceptions\EngineCalculationException;
use App\Services\Engines\CorporateFinance\Exceptions\PlausibilityValidationException;
use App\Services\Warmup\CfManufacturingWarmupService;
use App\Services\Warmup\Contracts\WarmupGenerationResult;
use App\Services\Warmup\DemandRandomizer;
use App\Services\Warmup\DeterministicDemandRandomizer;
use App\Services\Warmup\Exceptions\WarmupGenerationException;
use App\Support\Scenario\CfManufacturingScenarioDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CfManufacturingWarmupServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_full_stack_generates_three_chained_valid_quarters_and_readiness_four(): void
    {
        $result = (new CfManufacturingWarmupService)->generate('course-alpha', 'firm-001');

        $this->assertSame([1, 2, 3], array_keys($result->quarters));
        $this->assertSame(4, $result->readyForQuarter);
        foreach ($result->quarters as $quarter => $generated) {
            $this->assertSame($quarter, $generated->quarter);
            $this->assertSame(DeterministicDemandRandomizer::DEMAND_FIELDS, array_keys($generated->demand));
            $this->assertSame(['forecasting', 'sourcing', 'pricing', 'production', 'marketing', 'quality', 'logistics', 'notes_elections', 'certification'], array_keys($generated->decisions));
            $expectedElections = CfManufacturingScenarioDefinition::fixedWarmupPayload('notes_elections');
            $actualElections = $generated->decisions['notes_elections'];
            ksort($expectedElections);
            ksort($actualElections);
            $this->assertSame($expectedElections, $actualElections);
            $this->assertSame(CfManufacturingScenarioDefinition::fixedWarmupPayload('certification'), $generated->decisions['certification']);
            $this->assertEqualsWithDelta(0.0, $generated->statements->balanceSheet['assets'] - ($generated->statements->balanceSheet['liabilities'] + $generated->statements->balanceSheet['equity']), 0.01);
            $this->assertSame([], $generated->flags);
        }

        $this->assertSame($result->quarters[1]->statements->fields['bs_cash'], $result->quarters[2]->opening->cash);
        $this->assertSame($result->quarters[2]->statements->fields['bs_cash'], $result->quarters[3]->opening->cash);
        $this->assertSame($result->quarters[1]->statements->fields['bs_retained'] + $result->quarters[1]->statements->fields['bs_curr_ni'], $result->quarters[2]->opening->retainedEarnings);
    }

    public function test_signed_two_course_rung_has_zero_dividends_and_no_new_financing(): void
    {
        $this->assertSame(0.055, CfManufacturingScenarioDefinition::parameters()['demand_growth_standard_deviation']);
        $service = new CfManufacturingWarmupService;

        foreach (['course-alpha', 'course-beta'] as $course) {
            $result = $service->generate($course, 'firm-001');

            foreach ([1, 2, 3] as $quarter) {
                $generated = $result->quarters[$quarter];
                $this->assertSame(0.0, $generated->statements->intermediates['dividend']);
                $this->assertSame(0.0, $generated->statements->fields['cf_equity']);
                $this->assertSame(0.0, $generated->statements->fields['cf_borrow_proc']);
                $this->assertSame(0.0, $generated->statements->fields['n5_auto_draw']);
                $this->assertSame(0.0, $generated->statements->fields['n5_overdraft']);
                $this->assertSame(0.0, $generated->statements->fields['bs_bank_overdraft']);
                $this->assertSame([], $generated->flags);
                $this->assertTrue($generated->plausibility['retained_earnings_coherence']['passed']);
                $this->assertEqualsWithDelta(
                    0.0,
                    $generated->statements->balanceSheet['assets'] - ($generated->statements->balanceSheet['liabilities'] + $generated->statements->balanceSheet['equity']),
                    0.01,
                );
            }
        }
    }

    public function test_q0_to_q1_to_q2_to_q3_compounding_and_seasonality_are_literal(): void
    {
        $seed = hash('sha256', 'course-alpha');
        $randomizer = new DeterministicDemandRandomizer;
        $result = (new CfManufacturingWarmupService)->generateFromSeedHash($seed, 'firm-001');
        $prior = CfManufacturingScenarioDefinition::q0ForecastingPayload();

        foreach ([1 => 0.85, 2 => 1.0, 3 => 1.0] as $quarter => $seasonality) {
            $expected = $randomizer->compound($prior, $seed, 'firm-001', $quarter, $seasonality);
            $this->assertSame($expected, $result->quarters[$quarter]->demand);
            $prior = $expected;
        }
    }

    public function test_explicit_scenario_model_and_code_select_the_same_generation_contract(): void
    {
        $scenario = Scenario::query()->where('code', CfManufacturingScenarioDefinition::CODE)->firstOrFail();
        $service = new CfManufacturingWarmupService;

        $this->assertEquals(
            $service->generate('course-alpha', 'firm-001', $scenario),
            $service->generate('course-alpha', 'firm-001', CfManufacturingScenarioDefinition::CODE),
        );
    }

    public function test_reproducibility_and_variation_do_not_use_persistence(): void
    {
        $service = new CfManufacturingWarmupService;
        $before = ScenarioQuarterDecision::query()->count();
        $first = $service->generate('course-alpha', 'firm-001');
        $repeat = $service->generate('course-alpha', 'firm-001');
        $otherFirm = $service->generate('course-alpha', 'firm-002');
        $otherCourse = $service->generate('course-beta', 'firm-001');

        $this->assertEquals($first, $repeat);
        $this->assertNotEquals($first->quarters[3]->demand, $otherFirm->quarters[3]->demand);
        $this->assertNotEquals($first->quarters[3]->demand, $otherCourse->quarters[3]->demand);
        $this->assertNotEquals($first->quarters[1]->demand, $first->quarters[2]->demand);
        $this->assertSame($before, ScenarioQuarterDecision::query()->count());
    }

    public function test_multiple_courses_and_firms_have_no_duplicate_histories(): void
    {
        $service = new CfManufacturingWarmupService;
        $demand = [];
        $statements = [];

        foreach (['course-alpha', 'course-beta'] as $course) {
            foreach (range(1, 20) as $firm) {
                $result = $service->generate($course, "firm-{$firm}");
                $demand[] = hash('sha256', serialize(array_map(fn ($quarter) => $quarter->demand, $result->quarters)));
                $statements[] = hash('sha256', serialize(array_map(fn ($quarter) => $quarter->statements->fields, $result->quarters)));
            }
        }

        $this->assertCount(40, array_unique($demand));
        $this->assertCount(40, array_unique($statements));
    }

    public function test_missing_q0_baseline_fails_before_history_is_returned(): void
    {
        ScenarioQuarterDecision::query()->where('quarter_number', 0)->delete();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exactly one Q0 forecasting baseline');
        (new CfManufacturingWarmupService)->generate('course-alpha', 'firm-001');
    }

    public function test_missing_election_row_fails_in_the_named_quarter(): void
    {
        $scenario = Scenario::query()->where('code', CfManufacturingScenarioDefinition::CODE)->firstOrFail();
        $category = $scenario->decisionCategories()->where('code', 'notes_elections')->firstOrFail();
        ScenarioQuarterDecision::query()->where('scenario_decision_category_id', $category->id)->where('quarter_number', 2)->delete();

        try {
            (new CfManufacturingWarmupService)->generate('course-alpha', 'firm-001');
            $this->fail('Expected warm-up generation to fail.');
        } catch (WarmupGenerationException $exception) {
            $this->assertSame(2, $exception->quarter);
            $this->assertStringContainsString('notes_elections', $exception->getMessage());
            $this->assertArrayNotHasKey('course_token', $exception->context);
        }
    }

    public function test_missing_certification_row_fails_in_the_named_quarter(): void
    {
        $scenario = Scenario::query()->where('code', CfManufacturingScenarioDefinition::CODE)->firstOrFail();
        $category = $scenario->decisionCategories()->where('code', 'certification')->firstOrFail();
        ScenarioQuarterDecision::query()->where('scenario_decision_category_id', $category->id)->where('quarter_number', 3)->delete();

        try {
            (new CfManufacturingWarmupService)->generate('course-alpha', 'firm-001');
            $this->fail('Expected warm-up generation to fail.');
        } catch (WarmupGenerationException $exception) {
            $this->assertSame(3, $exception->quarter);
            $this->assertStringContainsString('certification', $exception->getMessage());
        }
    }

    public function test_accounting_failure_is_wrapped_without_partial_history(): void
    {
        $engine = Mockery::mock(ManufacturingEngine::class);
        $engine->shouldReceive('calculate')->once()->andThrow(new EngineCalculationException('forced accounting failure'));

        try {
            (new CfManufacturingWarmupService(null, new CfManufacturingSimRulesService, $engine))->generate('course-alpha', 'firm-001');
            $this->fail('Expected warm-up generation to fail.');
        } catch (WarmupGenerationException $exception) {
            $this->assertSame(1, $exception->quarter);
            $this->assertInstanceOf(EngineCalculationException::class, $exception->getPrevious());
            $this->assertStringContainsString('forced accounting failure', $exception->getMessage());
        }
    }

    public function test_plausibility_failure_is_wrapped_without_partial_history(): void
    {
        $baseline = (new CfManufacturingWarmupService)->generate('course-alpha', 'firm-001')->quarters[1]->statements;
        $fields = $baseline->fields;
        $fields['n5_balance'] += 1.0;
        $mutated = new ManufacturingEngineResult($fields, $baseline->incomeStatement, $baseline->balanceSheet, $baseline->cashFlowStatement, $baseline->notes, $baseline->intermediates);
        $engine = Mockery::mock(ManufacturingEngine::class);
        $engine->shouldReceive('calculate')->once()->andReturn($mutated);

        try {
            (new CfManufacturingWarmupService(null, new CfManufacturingSimRulesService, $engine))->generate('course-alpha', 'firm-001');
            $this->fail('Expected warm-up generation to fail.');
        } catch (WarmupGenerationException $exception) {
            $this->assertSame(1, $exception->quarter);
            $this->assertInstanceOf(PlausibilityValidationException::class, $exception->getPrevious());
            $this->assertStringContainsString('debt_coherence', $exception->getMessage());
        }
    }

    public function test_invalid_q2_randomizer_output_is_atomic_and_reports_safe_context(): void
    {
        $randomizer = new class implements DemandRandomizer
        {
            public function compound(array $priorDemand, string $courseSeedHash, string $firmIdentifier, int $quarter, float $seasonality): array
            {
                return $quarter === 2 ? ['unexpected' => 1] : CfManufacturingScenarioDefinition::q0ForecastingPayload();
            }

            public function growthFactor(string $courseSeedHash, string $firmIdentifier, int $quarter, string $field): float
            {
                return 1.02;
            }
        };

        try {
            (new CfManufacturingWarmupService($randomizer))->generate('secret-course-token', 'firm-001');
            $this->fail('Expected warm-up generation to fail.');
        } catch (WarmupGenerationException $exception) {
            $this->assertSame(2, $exception->quarter);
            $this->assertSame(RuntimeException::class, $exception->context['failure_type']);
            $this->assertStringNotContainsString('secret-course-token', $exception->getMessage());
            $this->assertArrayHasKey('firm_hash', $exception->context);
            $this->assertCount(6, $exception->context['growth_trace']);
        }
    }

    public function test_job_shell_invokes_exactly_one_firm_without_persistence(): void
    {
        $service = Mockery::mock(CfManufacturingWarmupService::class);
        $service->shouldReceive('generateFromSeedHash')->once()->with(hash('sha256', 'course-alpha'), 'firm-001', CfManufacturingScenarioDefinition::CODE)->andReturn(new WarmupGenerationResult(hash('sha256', 'course-alpha'), 'firm-001', []));
        $before = ScenarioQuarterDecision::query()->count();

        (new GenerateCfManufacturingWarmup('course-alpha', 'firm-001'))->handle($service);

        $this->assertSame($before, ScenarioQuarterDecision::query()->count());
    }
}
