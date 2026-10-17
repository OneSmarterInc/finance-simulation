<?php

namespace Tests\Unit\Services\CfPlus;

use App\Models\CfPlusDisclosureSnapshot;
use App\Services\CfPlus\ConsultantValuationPrototype;
use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngineInput;
use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngineResult;
use App\Services\Engines\CorporateFinance\Contracts\OpeningFinancialPosition;
use App\Services\Engines\CorporateFinance\ManufacturingCFEngine;
use App\Support\Scenario\CfManufacturingScenarioDefinition;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class ConsultantCalibrationDiagnosticsTest extends TestCase
{
    public function test_growth_cap_must_be_below_exact_quarterly_discount_rate(): void
    {
        $prototype = new ConsultantValuationPrototype;
        $this->assertEqualsWithDelta(0.024113689, $prototype->quarterlyDiscountRate(0.10), 0.000000001);
        $this->assertEqualsWithDelta(0.028737345, $prototype->quarterlyDiscountRate(0.12), 0.000000001);
        $this->assertEqualsWithDelta(0.033299485, $prototype->quarterlyDiscountRate(0.14), 0.000000001);
        $prototype->validateCandidate(0.10, 0.02, 0.015, 12);
        $this->addToAssertionCount(1);

        foreach ([[0.12, 0.03, 0.02], [0.14, 0.05, 0.025]] as [$return, $cap, $terminal]) {
            try {
                $prototype->validateCandidate($return, $cap, $terminal, 12);
                $this->fail('Invalid candidate must be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('must be below quarterly discount rate', $exception->getMessage());
            }
        }
    }

    public function test_annual_terminal_growth_is_converted_before_quarterly_gordon_formula(): void
    {
        $prototype = new ConsultantValuationPrototype;
        $result = $prototype->evaluate($this->snapshot([7600000, 7800000, 8000000]), 0.10, 0.02, 0.015, 12);

        $this->assertEqualsWithDelta(0.003729088938093, $prototype->quarterlyTerminalGrowthRate(0.015), 0.000000000000001);
        $this->assertEqualsWithDelta(0.003729088938093, $result['quarterly_terminal_growth'], 0.000000000000001);
        $this->assertSame(0.015, $result['annual_terminal_growth']);
        $this->assertEqualsWithDelta(0.020384600146352, $result['quarterly_required_return'] - $result['quarterly_terminal_growth'], 0.000000000000001);
        $this->assertSame(487091978.41, $result['terminal_value']);
        $this->assertSame(365959412.78, $result['terminal_present_value']);
        $this->assertSame(91191796.43, $result['explicit_period_present_value']);
        $this->assertSame(457151209.21, $result['consultant_value']);
    }

    public function test_terminal_growth_and_discount_rate_must_share_period_and_preserve_strict_order(): void
    {
        $prototype = new ConsultantValuationPrototype;
        $this->assertLessThan($prototype->quarterlyDiscountRate(0.10), $prototype->quarterlyTerminalGrowthRate(0.015));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('quarterly terminal growth');
        $prototype->validateCandidate(0.10, 0.02, 0.10, 12);
    }

    public function test_corrected_candidate_is_deterministic_and_pedagogical_direction_is_sample_specific(): void
    {
        $prototype = new ConsultantValuationPrototype;
        $aggressiveReported = $prototype->evaluate($this->snapshot([63336262.30, 63312512.30, 63288762.30]), 0.10, 0.02, 0.015, 12);
        $aggressiveCash = $prototype->evaluate($this->snapshot([36266893.82, 49851884.22, 49857821.72]), 0.10, 0.02, 0.015, 12);
        $conservativeReported = $prototype->evaluate($this->snapshot([59217735.50, 59170235.50, 59122735.50]), 0.10, 0.02, 0.015, 12);
        $conservativeCash = $prototype->evaluate($this->snapshot([37296525.52, 49112676.62, 49124551.62]), 0.10, 0.02, 0.015, 12);

        $this->assertSame(2983113681.16, $aggressiveReported['consultant_value']);
        $this->assertSame(2921775531.96, $aggressiveCash['consultant_value']);
        $this->assertGreaterThan($aggressiveCash['consultant_value'], $aggressiveReported['consultant_value']);
        $this->assertLessThan($conservativeCash['consultant_value'], $conservativeReported['consultant_value']);
        $this->assertSame($aggressiveReported, $prototype->evaluate($this->snapshot([63336262.30, 63312512.30, 63288762.30]), 0.10, 0.02, 0.015, 12));
    }

    public function test_matched_method_after_tax_reported_counterfactual_uses_identical_mechanics(): void
    {
        $prototype = new ConsultantValuationPrototype;
        $reported = $prototype->evaluate($this->snapshot([63336262.30, 63312512.30, 63288762.30]), 0.10, 0.02, 0.015, 12);
        $afterTax = $prototype->evaluate($this->snapshot([47502196.725, 47484384.225, 47466571.725]), 0.10, 0.02, 0.015, 12);
        $cash = $prototype->evaluate($this->snapshot([36266893.82, 49851884.22, 49857821.72]), 0.10, 0.02, 0.015, 12);

        $this->assertSame(2983113681.16, $reported['consultant_value']);
        $this->assertSame(2237335260.78, $afterTax['consultant_value']);
        $this->assertSame(2921775531.96, $cash['consultant_value']);
        $this->assertEqualsWithDelta($reported['observed_growth'], $afterTax['observed_growth'], 0.000000000000001);
        $this->assertEqualsWithDelta($reported['capped_quarterly_growth'], $afterTax['capped_quarterly_growth'], 0.000000000000001);
        $this->assertSame($reported['quarterly_required_return'], $cash['quarterly_required_return']);
        $this->assertSame($reported['quarterly_terminal_growth'], $cash['quarterly_terminal_growth']);
    }

    public function test_terminal_share_and_horizon_decomposition_are_deterministic(): void
    {
        $snapshot = $this->snapshot([7600000, 7800000, 8000000]);
        $prototype = new ConsultantValuationPrototype;
        $runs = [];
        foreach ([4, 8, 12] as $periods) {
            $runs[$periods] = $prototype->evaluate($snapshot, 0.10, 0.02, 0.015, $periods);
            $this->assertSame($runs[$periods]['consultant_value'], round($runs[$periods]['explicit_period_present_value'] + $runs[$periods]['terminal_present_value'], 2));
            $this->assertEqualsWithDelta($runs[$periods]['terminal_present_value'] / $runs[$periods]['consultant_value'], $runs[$periods]['terminal_share'], 0.0000001);
            $this->assertGreaterThan(0.5, $runs[$periods]['terminal_share']);
            $this->assertSame($runs[$periods], $prototype->evaluate($snapshot, 0.10, 0.02, 0.015, $periods));
        }
        $this->assertGreaterThan($runs[4]['explicit_period_present_value'], $runs[8]['explicit_period_present_value']);
        $this->assertGreaterThan($runs[8]['explicit_period_present_value'], $runs[12]['explicit_period_present_value']);
    }

    public function test_each_election_is_isolated_against_genuine_engine_output(): void
    {
        $baseline = $this->calculate($this->decisions());
        $options = CfManufacturingScenarioDefinition::inputFieldsForCategory('notes_elections')['fields'];
        $runs = [];
        foreach ($options as $field) {
            foreach ($field['allowed_values'] as $option) {
                $decisions = $this->decisions();
                $decisions['notes_elections'][$field['key']] = $option;
                $runs[$field['key']][$option] = $this->metrics($this->calculate($decisions));
                $this->assertSame($runs[$field['key']][$option], $this->metrics($this->calculate($decisions)));
                $this->assertSame($option, $decisions['notes_elections'][$field['key']]);
                $this->assertCount(6, $decisions['notes_elections']);
            }
        }
        $this->assertSame($this->metrics($baseline), $runs['inventory_method']['fifo']);
        $this->assertSame($runs['inventory_method']['fifo'], $runs['inventory_method']['weighted_average']);
        $this->assertSame($runs['inventory_method']['fifo'], $runs['inventory_method']['lifo']);
        $this->assertNotSame($runs['depreciation_method']['straight_line']['operating_income'], $runs['depreciation_method']['double_declining_balance']['operating_income']);
        $this->assertNotSame($runs['bad_debt_method']['percentage_of_receivables']['operating_income'], $runs['bad_debt_method']['percentage_of_sales']['operating_income']);
    }

    public function test_engine_produced_pair_and_bidder_own_funds_remain_comparable_with_narrow_auction_schema(): void
    {
        $conservative = $this->decisions();
        $aggressive = $this->decisions();
        $conservative['notes_elections']['depreciation_method'] = 'double_declining_balance';
        $conservative['notes_elections']['bad_debt_method'] = 'percentage_of_sales';
        $aggressive['notes_elections']['depreciation_method'] = 'straight_line';
        $aggressive['notes_elections']['bad_debt_method'] = 'percentage_of_receivables';

        $conservativeResults = $this->chain($conservative);
        $aggressiveResults = $this->chain($aggressive);
        $this->assertGreaterThan($conservativeResults[3]->incomeStatement['operating_income'], $aggressiveResults[3]->incomeStatement['operating_income']);
        $this->assertNotSame($conservativeResults[3]->incomeStatement['net_income'], $aggressiveResults[3]->incomeStatement['net_income']);

        $prototype = new ConsultantValuationPrototype;
        $conservativeValue = $prototype->evaluate($this->snapshotFromResults($conservativeResults), 0.12, 0.02, 0.015, 12)['consultant_value'];
        $aggressiveValue = $prototype->evaluate($this->snapshotFromResults($aggressiveResults), 0.12, 0.02, 0.015, 12)['consultant_value'];
        $this->assertGreaterThan($conservativeValue, $aggressiveValue);

        $bidder = $aggressiveResults[3];
        $fundableCap = $bidder->fields['bs_cash'] + max(0, $bidder->fields['n5_limit'] - $bidder->fields['n5_balance']);
        $this->assertEqualsWithDelta($bidder->fields['bs_cash'] + $bidder->fields['n5_limit'] - $bidder->fields['n5_balance'], $fundableCap, 0.01);
        $this->assertGreaterThan(0, $fundableCap);
        $this->assertTrue(Schema::hasTable('cf_plus_offers'));
        $this->assertTrue(Schema::hasTable('cf_plus_auction_results'));
        $this->assertFalse(Schema::hasTable('cf_plus_auction_outcomes'));
        $this->assertFalse(Schema::hasTable('cf_plus_reveals'));
    }

    /** @return array<int, ManufacturingEngineResult> */
    private function chain(array $decisions): array
    {
        $parameters = $this->parameters();
        $opening = OpeningFinancialPosition::fromScenarioParameters($parameters);
        $results = [];
        foreach (range(1, 3) as $quarter) {
            $results[$quarter] = (new ManufacturingCFEngine)->calculate(new ManufacturingEngineInput($quarter, $decisions, $parameters, $opening));
            $opening = OpeningFinancialPosition::fromPriorEngineResult($results[$quarter]->fields);
        }

        return $results;
    }

    private function calculate(array $decisions): ManufacturingEngineResult
    {
        $parameters = $this->parameters();

        return (new ManufacturingCFEngine)->calculate(new ManufacturingEngineInput(1, $decisions, $parameters, OpeningFinancialPosition::fromScenarioParameters($parameters)));
    }

    private function snapshot(array $operatingIncome): CfPlusDisclosureSnapshot
    {
        return new CfPlusDisclosureSnapshot(['published_statements' => collect($operatingIncome)->values()->map(fn ($value, int $index): array => ['quarter_number' => $index + 1, 'income_statement' => ['operating_income' => $value]])->all()]);
    }

    /** @param array<int, ManufacturingEngineResult> $results */
    private function snapshotFromResults(array $results): CfPlusDisclosureSnapshot
    {
        return $this->snapshot(array_map(fn (ManufacturingEngineResult $result): float => (float) $result->incomeStatement['operating_income'], $results));
    }

    /** @return array<string, float> */
    private function metrics(ManufacturingEngineResult $result): array
    {
        return ['revenue' => (float) $result->incomeStatement['net_revenue'], 'cogs' => (float) $result->incomeStatement['cost_of_goods_sold'], 'operating_expenses' => (float) $result->incomeStatement['operating_expenses'], 'operating_income' => (float) $result->incomeStatement['operating_income'], 'pre_tax_income' => (float) $result->incomeStatement['income_before_income_taxes'], 'tax' => (float) $result->incomeStatement['income_tax_expense'], 'net_income' => (float) $result->incomeStatement['net_income'], 'cash' => (float) $result->fields['bs_cash'], 'inventory' => (float) $result->fields['bs_inventory'], 'retained_earnings' => (float) ($result->fields['bs_retained'] + $result->fields['bs_curr_ni'])];
    }

    /** @return array<string, mixed> */
    private function parameters(): array
    {
        $parameters = CfManufacturingScenarioDefinition::parameters();
        $parameters['p1_base_price'] = 500;
        $parameters['p2_base_price'] = 850;

        return $parameters;
    }

    /** @return array<string, array<string, mixed>> */
    private function decisions(): array
    {
        return ['forecasting' => ['forecast_p1_r1' => 30000, 'forecast_p1_r2' => 40000, 'forecast_p1_r3' => 60000, 'forecast_p2_r1' => 27000, 'forecast_p2_r2' => 18000, 'forecast_p2_r3' => 16000, 'forecast_confidence' => 'medium'], 'sourcing' => ['primary_supplier' => 'SUP001', 'primary_alloc' => 1.0, 'secondary_supplier' => 'NONE'], 'pricing' => ['price_p1_r1' => 500, 'price_p1_r2' => 500, 'price_p1_r3' => 500, 'price_p2_r1' => 850, 'price_p2_r2' => 850, 'price_p2_r3' => 850, 'discount_policy' => 'none'], 'production' => ['p1_production' => 125000, 'p2_production' => 60000, 'shifts' => 1, 'postponed_flag' => 'NO', 'postponed_pct' => 0], 'marketing' => ['marketing_budget_pct' => 0.05, 'marketing_alloc_champions' => 0.50, 'marketing_alloc_growth' => 0.30, 'marketing_alloc_at_risk' => 0.15, 'marketing_alloc_other' => 0.05], 'quality' => ['inspection_level' => 'BASIC'], 'logistics' => ['logistics_mode' => 'STANDARD'], 'notes_elections' => CfManufacturingScenarioDefinition::fixedWarmupPayload('notes_elections'), 'certification' => CfManufacturingScenarioDefinition::fixedWarmupPayload('certification')];
    }
}
