<?php

namespace Tests\Unit\Services\Engines\CorporateFinance;

use App\Services\Engines\CorporateFinance\CfManufacturingSimRulesService;
use App\Services\Engines\CorporateFinance\Exceptions\EngineCalculationException;
use App\Support\Scenario\CfManufacturingScenarioDefinition;
use Tests\TestCase;

class CfManufacturingSimRulesServiceTest extends TestCase
{
    public function test_forecasting_copies_fixed_demand_context_without_randomization(): void
    {
        $decisions = $this->service()->deriveCompleteDecisions(1, $this->demand(), $this->parameters());

        $this->assertSame([
            'forecast_p1_r1' => 30000,
            'forecast_p1_r2' => 40000,
            'forecast_p1_r3' => 60000,
            'forecast_p2_r1' => 27000,
            'forecast_p2_r2' => 18000,
            'forecast_p2_r3' => 16000,
            'forecast_confidence' => 'medium',
        ], $decisions['forecasting']);
    }

    public function test_closed_rules_derive_competent_middle_operational_payloads(): void
    {
        $decisions = $this->service()->deriveCompleteDecisions(1, $this->demand(), $this->parameters());

        $this->assertSame(['forecasting', 'sourcing', 'pricing', 'production', 'marketing', 'quality', 'logistics'], array_keys($decisions));
        $this->assertSame(['forecast_p1_r1', 'forecast_p1_r2', 'forecast_p1_r3', 'forecast_p2_r1', 'forecast_p2_r2', 'forecast_p2_r3', 'forecast_confidence'], array_keys($decisions['forecasting']));
        $this->assertSame(['primary_supplier', 'primary_alloc', 'secondary_supplier'], array_keys($decisions['sourcing']));
        $this->assertSame(['price_p1_r1', 'price_p1_r2', 'price_p1_r3', 'price_p2_r1', 'price_p2_r2', 'price_p2_r3', 'discount_policy'], array_keys($decisions['pricing']));
        $this->assertSame(['p1_production', 'p2_production', 'shifts', 'postponed_flag', 'postponed_pct'], array_keys($decisions['production']));
        $this->assertSame(['marketing_budget_pct', 'marketing_alloc_champions', 'marketing_alloc_growth', 'marketing_alloc_at_risk', 'marketing_alloc_other'], array_keys($decisions['marketing']));
        $this->assertSame(['inspection_level'], array_keys($decisions['quality']));
        $this->assertSame(['logistics_mode'], array_keys($decisions['logistics']));
        $this->assertSame(['primary_supplier' => 'SUP001', 'primary_alloc' => 1.0, 'secondary_supplier' => 'NONE'], $decisions['sourcing']);
        $this->assertSame(270.0, $decisions['pricing']['price_p1_r1']);
        $this->assertSame(350.0, $decisions['pricing']['price_p2_r3']);
        $this->assertSame('none', $decisions['pricing']['discount_policy']);
        $this->assertSame(['p1_production' => 130000.0, 'p2_production' => 61000.0, 'shifts' => 1, 'postponed_flag' => 'NO', 'postponed_pct' => 0], $decisions['production']);
        $this->assertSame(0.05, $decisions['marketing']['marketing_budget_pct']);
        $this->assertSame(0.50, $decisions['marketing']['marketing_alloc_champions']);
        $this->assertSame(['inspection_level' => 'BASIC'], $decisions['quality']);
        $this->assertSame(['logistics_mode' => 'EXPRESS'], $decisions['logistics']);
    }

    public function test_complete_worked_derivation_oracle_pins_every_operational_field(): void
    {
        $demand = $this->demand();
        $parameters = $this->parameters();
        $expected = [
            'forecasting' => ['forecast_p1_r1' => 30000, 'forecast_p1_r2' => 40000, 'forecast_p1_r3' => 60000, 'forecast_p2_r1' => 27000, 'forecast_p2_r2' => 18000, 'forecast_p2_r3' => 16000, 'forecast_confidence' => 'medium'],
            'sourcing' => ['primary_supplier' => 'SUP001', 'primary_alloc' => 1.0, 'secondary_supplier' => 'NONE'],
            'pricing' => ['price_p1_r1' => 270.0, 'price_p1_r2' => 270.0, 'price_p1_r3' => 270.0, 'price_p2_r1' => 350.0, 'price_p2_r2' => 350.0, 'price_p2_r3' => 350.0, 'discount_policy' => 'none'],
            'production' => ['p1_production' => 130000.0, 'p2_production' => 61000.0, 'shifts' => 1, 'postponed_flag' => 'NO', 'postponed_pct' => 0],
            'marketing' => ['marketing_budget_pct' => 0.05, 'marketing_alloc_champions' => 0.50, 'marketing_alloc_growth' => 0.30, 'marketing_alloc_at_risk' => 0.15, 'marketing_alloc_other' => 0.05],
            'quality' => ['inspection_level' => 'BASIC'],
            'logistics' => ['logistics_mode' => 'EXPRESS'],
        ];

        $first = $this->service()->deriveCompleteDecisions(1, $demand, $parameters);
        $second = $this->service()->deriveCompleteDecisions(1, $demand, $parameters);

        $this->assertSame($expected, $first);
        $this->assertSame($expected, $second);
        $this->assertSame($this->demand(), $demand);
        $this->assertSame($this->parameters(), $parameters);
    }

    public function test_student_controlled_forecasting_drives_closed_production_and_sourcing(): void
    {
        $studentForecast = [
            'forecast_p1_r1' => 10,
            'forecast_p1_r2' => 20,
            'forecast_p1_r3' => 30,
            'forecast_p2_r1' => 40,
            'forecast_p2_r2' => 50,
            'forecast_p2_r3' => 60,
            'forecast_confidence' => 'high',
        ];

        $decisions = $this->service()->deriveCompleteDecisions(
            4,
            $this->demand(),
            $this->parameters(),
            ['forecasting'],
            ['forecasting' => $studentForecast],
        );

        $this->assertSame($studentForecast, $decisions['forecasting']);
        $this->assertSame(60.0, $decisions['production']['p1_production']);
        $this->assertSame(150.0, $decisions['production']['p2_production']);
        $this->assertSame(['primary_supplier' => 'SUP001', 'primary_alloc' => 1.0, 'secondary_supplier' => 'NONE'], $decisions['sourcing']);
    }

    public function test_student_controlled_production_is_preserved_and_closed_sourcing_follows_it(): void
    {
        $production = ['p1_production' => 12345, 'p2_production' => 6789, 'shifts' => 2, 'postponed_flag' => 'YES', 'postponed_pct' => 0.25];

        $decisions = $this->service()->deriveCompleteDecisions(
            4,
            $this->demand(),
            $this->parameters(),
            ['production'],
            ['production' => $production],
        );

        $this->assertSame($production, $decisions['production']);
        $this->assertSame(['primary_supplier' => 'SUP001', 'primary_alloc' => 1.0, 'secondary_supplier' => 'NONE'], $decisions['sourcing']);
    }

    public function test_student_controlled_pricing_is_preserved_while_marketing_is_derived(): void
    {
        $pricing = [
            'price_p1_r1' => 610,
            'price_p1_r2' => 620,
            'price_p1_r3' => 630,
            'price_p2_r1' => 900,
            'price_p2_r2' => 910,
            'price_p2_r3' => 920,
            'discount_policy' => 'aggressive',
        ];

        $decisions = $this->service()->deriveCompleteDecisions(
            4,
            $this->demand(),
            $this->parameters(),
            ['pricing'],
            ['pricing' => $pricing],
        );

        $this->assertSame($pricing, $decisions['pricing']);
        $this->assertSame(0.05, $decisions['marketing']['marketing_budget_pct']);
    }

    public function test_all_operational_categories_controlled_are_preserved_exactly(): void
    {
        $studentDecisions = $this->completeOperationalDecisions();

        $decisions = $this->service()->deriveCompleteDecisions(
            4,
            $this->demand(),
            $this->parameters(),
            array_keys($studentDecisions),
            $studentDecisions,
        );

        $this->assertSame($studentDecisions, $decisions);
    }

    public function test_elections_and_certification_are_never_returned_or_derived(): void
    {
        $decisions = $this->service()->deriveCompleteDecisions(
            1,
            $this->demand(),
            $this->parameters(),
            [],
            [
                'notes_elections' => ['inventory_method' => 'lifo'],
                'certification' => ['attestation_scope' => 'not_attested'],
            ],
        );

        $this->assertArrayNotHasKey('notes_elections', $decisions);
        $this->assertArrayNotHasKey('certification', $decisions);
        $this->assertSame(['forecasting', 'sourcing', 'pricing', 'production', 'marketing', 'quality', 'logistics'], array_keys($decisions));
    }

    public function test_derivation_is_deterministic_and_does_not_mutate_inputs(): void
    {
        $demand = $this->demand();
        $parameters = $this->parameters();
        $originalDemand = $demand;
        $originalParameters = $parameters;

        $first = $this->service()->deriveCompleteDecisions(1, $demand, $parameters);
        $second = $this->service()->deriveCompleteDecisions(1, $demand, $parameters);

        $this->assertSame($first, $second);
        $this->assertSame($originalDemand, $demand);
        $this->assertSame($originalParameters, $parameters);
    }

    public function test_incomplete_required_input_fails_clearly(): void
    {
        $demand = $this->demand();
        unset($demand['forecast_p2_r3']);

        $this->expectException(EngineCalculationException::class);
        $this->expectExceptionMessage('Demand context field [forecast_p2_r3] is required and must be numeric.');

        $this->service()->deriveCompleteDecisions(1, $demand, $this->parameters());
    }

    public function test_student_controlled_payload_missing_required_field_fails_clearly(): void
    {
        $pricing = $this->completeOperationalDecisions()['pricing'];
        unset($pricing['price_p2_r3']);

        $this->expectException(EngineCalculationException::class);
        $this->expectExceptionMessage('Missing required decision field [pricing.price_p2_r3].');

        $this->service()->deriveCompleteDecisions(4, $this->demand(), $this->parameters(), ['pricing'], ['pricing' => $pricing]);
    }

    public function test_student_controlled_payload_unexpected_field_fails_clearly(): void
    {
        $pricing = $this->completeOperationalDecisions()['pricing'];
        $pricing['surprise'] = 'nope';

        $this->expectException(EngineCalculationException::class);
        $this->expectExceptionMessage('Unexpected decision field [pricing.surprise].');

        $this->service()->deriveCompleteDecisions(4, $this->demand(), $this->parameters(), ['pricing'], ['pricing' => $pricing]);
    }

    public function test_student_controlled_payload_invalid_enum_fails_clearly(): void
    {
        $pricing = $this->completeOperationalDecisions()['pricing'];
        $pricing['discount_policy'] = 'extreme';

        $this->expectException(EngineCalculationException::class);
        $this->expectExceptionMessage('Decision field [pricing.discount_policy] has unsupported value [extreme].');

        $this->service()->deriveCompleteDecisions(4, $this->demand(), $this->parameters(), ['pricing'], ['pricing' => $pricing]);
    }

    public function test_student_controlled_payload_nonnumeric_number_fails_clearly(): void
    {
        $pricing = $this->completeOperationalDecisions()['pricing'];
        $pricing['price_p1_r1'] = 'not-a-number';

        $this->expectException(EngineCalculationException::class);
        $this->expectExceptionMessage('Decision field [pricing.price_p1_r1] must be numeric.');

        $this->service()->deriveCompleteDecisions(4, $this->demand(), $this->parameters(), ['pricing'], ['pricing' => $pricing]);
    }

    private function service(): CfManufacturingSimRulesService
    {
        return new CfManufacturingSimRulesService;
    }

    /**
     * @return array<string, mixed>
     */
    private function demand(): array
    {
        return [
            'forecast_p1_r1' => 30000,
            'forecast_p1_r2' => 40000,
            'forecast_p1_r3' => 60000,
            'forecast_p2_r1' => 27000,
            'forecast_p2_r2' => 18000,
            'forecast_p2_r3' => 16000,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parameters(): array
    {
        return CfManufacturingScenarioDefinition::parameters();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function completeOperationalDecisions(): array
    {
        return [
            'forecasting' => array_merge($this->demand(), ['forecast_confidence' => 'low']),
            'sourcing' => ['primary_supplier' => 'SUP002', 'primary_alloc' => 0.75, 'secondary_supplier' => 'SUP003'],
            'pricing' => ['price_p1_r1' => 510, 'price_p1_r2' => 520, 'price_p1_r3' => 530, 'price_p2_r1' => 860, 'price_p2_r2' => 870, 'price_p2_r3' => 880, 'discount_policy' => 'moderate'],
            'production' => ['p1_production' => 130000, 'p2_production' => 61000, 'shifts' => 2, 'postponed_flag' => 'NO', 'postponed_pct' => 0],
            'marketing' => ['marketing_budget_pct' => 0.08, 'marketing_alloc_champions' => 0.45, 'marketing_alloc_growth' => 0.30, 'marketing_alloc_at_risk' => 0.15, 'marketing_alloc_other' => 0.10],
            'quality' => ['inspection_level' => 'FULL'],
            'logistics' => ['logistics_mode' => 'AIR'],
        ];
    }
}
