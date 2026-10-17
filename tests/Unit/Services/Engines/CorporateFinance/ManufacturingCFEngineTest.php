<?php

namespace Tests\Unit\Services\Engines\CorporateFinance;

use App\Services\Engines\CorporateFinance\AccountingIdentityValidator;
use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngineInput;
use App\Services\Engines\CorporateFinance\Contracts\OpeningFinancialPosition;
use App\Services\Engines\CorporateFinance\Exceptions\EngineCalculationException;
use App\Services\Engines\CorporateFinance\ManufacturingCFEngine;
use App\Support\Scenario\CfManufacturingScenarioDefinition;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ManufacturingCFEngineTest extends TestCase
{
    public function test_quarter_one_opening_position_comes_from_phase_2_opening_parameters(): void
    {
        $opening = OpeningFinancialPosition::fromScenarioParameters($this->parameters());

        $this->assertSame(100000.0, $opening->cash);
        $this->assertSame(100000000.0, $opening->plantInvestment);
        $this->assertSame(100100000.0, $opening->corporateCapitalization);
        $this->assertSame(50000000.0, $opening->ppeComponents->manufacturing);
        $this->assertSame(12500000.0, $opening->ppeComponents->technology);
        $this->assertSame(37500000.0, $opening->ppeComponents->distributionCenter);
        $this->assertSame(100000000.0, $opening->ppeComponents->total());
        $this->assertSame(0.0, $opening->debt);
        $this->assertSame(0.0, $opening->inventory);
        $this->assertSame(0.0, $opening->retainedEarnings);
        $this->assertSame(0.0, $opening->accumulatedDepreciation);
    }

    public function test_complete_decisions_produce_statement_structures_without_retired_starting_keys(): void
    {
        $parameters = $this->parameters();
        $result = $this->engine()->calculate(new ManufacturingEngineInput(
            quarterNumber: 1,
            decisions: $this->decisions(),
            parameters: $parameters,
            openingPosition: OpeningFinancialPosition::fromScenarioParameters($parameters),
        ));

        $this->assertSame(33_750_000.0, $result->fields['is_p1_rev']);
        $this->assertSame(21_000_000.0, $result->fields['is_p2_rev']);
        $this->assertSame(1_095_000.0, $result->fields['is_rebates']);
        $this->assertEqualsWithDelta(147_825.0, $result->fields['is_returns'], 0.01);
        $this->assertArrayHasKey('net_income', $result->incomeStatement);
        $this->assertArrayHasKey('assets', $result->balanceSheet);
        $this->assertArrayHasKey('operating_cash_flow', $result->cashFlowStatement);
        $this->assertArrayHasKey('ppe', $result->notes);
        $this->assertSame(0.0, $result->fields['bs_tech_net']);
    }

    public function test_quarter_two_opening_position_comes_from_quarter_one_ending_balances(): void
    {
        $parameters = $this->parameters();
        $quarterOne = $this->engine()->calculate(new ManufacturingEngineInput(
            quarterNumber: 1,
            decisions: $this->decisions(),
            parameters: $parameters,
            openingPosition: OpeningFinancialPosition::fromScenarioParameters($parameters),
        ));

        $quarterTwo = $this->engine()->calculate(new ManufacturingEngineInput(
            quarterNumber: 2,
            decisions: $this->decisions(),
            parameters: $parameters,
            openingPosition: OpeningFinancialPosition::fromPriorEngineResult($quarterOne->fields),
        ));

        $this->assertSame($quarterOne->fields['bs_cash'], $quarterTwo->fields['cf_beg_cash']);
        $this->assertSame($quarterOne->fields['bs_ppe_gross'] + 800000.0, $quarterTwo->fields['bs_ppe_gross']);
        $this->assertSame($quarterOne->fields['bs_retained'] + $quarterOne->fields['bs_curr_ni'], $quarterTwo->fields['bs_retained']);
    }

    public function test_accounting_identities_pass_for_valid_result(): void
    {
        $parameters = $this->parameters();
        $opening = OpeningFinancialPosition::fromScenarioParameters($parameters);
        $result = $this->engine()->calculate(new ManufacturingEngineInput(1, $this->decisions(), $parameters, $opening));
        $validator = new AccountingIdentityValidator;

        $validator->validate($result->fields, $opening);

        $this->assertEqualsWithDelta($result->balanceSheet['assets'], $result->balanceSheet['liabilities'] + $result->balanceSheet['equity'], 0.01);
        $this->assertEqualsWithDelta(
            $result->fields['bs_cash'] - $opening->cash,
            $result->cashFlowStatement['operating_cash_flow'] + $result->cashFlowStatement['investing_cash_flow'] + $result->cashFlowStatement['financing_cash_flow'],
            0.01,
        );
        $this->assertEqualsWithDelta($result->fields['bs_inventory'] - $opening->inventory, $result->fields['cf_inv_chg'], 0.01);
    }

    public function test_negative_balance_sheet_identity_throws_from_validator(): void
    {
        $fields = $this->validFields();
        $fields['bs_cash'] += 1.00;

        $this->expectException(EngineCalculationException::class);
        $this->expectExceptionMessage('Balance-sheet identity failed');

        (new AccountingIdentityValidator)->validate($fields, OpeningFinancialPosition::fromScenarioParameters($this->parameters()));
    }

    public function test_negative_cash_reconciliation_throws_from_validator(): void
    {
        $fields = $this->validFields();
        $fields['cf_borrow_proc'] += 1.00;

        $this->expectException(EngineCalculationException::class);
        $this->expectExceptionMessage('Cash reconciliation failed');

        (new AccountingIdentityValidator)->validateCashReconciliation($fields, OpeningFinancialPosition::fromScenarioParameters($this->parameters()));
    }

    public function test_negative_inventory_reconciliation_throws_from_validator(): void
    {
        $fields = $this->validFields();
        $fields['cf_inv_chg'] += 1.00;

        $this->expectException(EngineCalculationException::class);
        $this->expectExceptionMessage('Inventory reconciliation failed');

        (new AccountingIdentityValidator)->validateInventoryReconciliation($fields, OpeningFinancialPosition::fromScenarioParameters($this->parameters()));
    }

    public function test_technology_is_not_double_counted_and_depreciation_is_in_combined_ppe(): void
    {
        $parameters = $this->parameters();
        $result = $this->engine()->calculate(new ManufacturingEngineInput(
            quarterNumber: 1,
            decisions: $this->decisions(),
            parameters: $parameters,
            openingPosition: OpeningFinancialPosition::fromScenarioParameters($parameters),
        ));

        $this->assertSame(0.0, $result->fields['bs_tech_net']);
        $this->assertSame(625000.0, $result->fields['n4_tech_depr']);
        $this->assertSame(50500000.0, $result->fields['n4_mfg_gross']);
        $this->assertSame(12700000.0, $result->fields['n4_tech_gross']);
        $this->assertSame(37600000.0, $result->fields['n4_dc_gross']);
        $this->assertArrayNotHasKey('n4_cwip', $result->fields);
        $this->assertEqualsWithDelta(
            $result->fields['n4_mfg_depr'] + $result->fields['n4_tech_depr'] + $result->fields['n4_dc_depr'],
            $result->fields['bs_accum_depr'],
            0.01,
        );
    }

    public function test_repeated_calls_are_deterministic_and_do_not_query_or_persist(): void
    {
        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });

        $parameters = $this->parameters();
        $input = new ManufacturingEngineInput(1, $this->decisions(), $parameters, OpeningFinancialPosition::fromScenarioParameters($parameters));

        $first = $this->engine()->calculate($input);
        $second = $this->engine()->calculate($input);

        $this->assertSame($first->fields, $second->fields);
        $this->assertSame(0, $queries);
    }

    /**
     * @return array<string, float>
     */
    private function validFields(): array
    {
        $parameters = $this->parameters();

        return $this->engine()
            ->calculate(new ManufacturingEngineInput(1, $this->decisions(), $parameters, OpeningFinancialPosition::fromScenarioParameters($parameters)))
            ->fields;
    }

    private function engine(): ManufacturingCFEngine
    {
        return new ManufacturingCFEngine;
    }

    /**
     * @return array<string, mixed>
     */
    private function parameters(): array
    {
        $parameters = CfManufacturingScenarioDefinition::parameters();

        foreach (array_keys($parameters) as $key) {
            if (str_starts_with($key, 'starting_')) {
                unset($parameters[$key]);
            }
        }

        return $parameters;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function decisions(): array
    {
        return [
            'forecasting' => [
                'forecast_p1_r1' => 30000,
                'forecast_p1_r2' => 40000,
                'forecast_p1_r3' => 60000,
                'forecast_p2_r1' => 27000,
                'forecast_p2_r2' => 18000,
                'forecast_p2_r3' => 16000,
                'forecast_confidence' => 'medium',
            ],
            'sourcing' => [
                'primary_supplier' => 'SUP001',
                'primary_alloc' => 1.0,
                'secondary_supplier' => 'NONE',
            ],
            'pricing' => [
                'price_p1_r1' => 270,
                'price_p1_r2' => 270,
                'price_p1_r3' => 270,
                'price_p2_r1' => 350,
                'price_p2_r2' => 350,
                'price_p2_r3' => 350,
                'discount_policy' => 'none',
            ],
            'production' => [
                'p1_production' => 125000,
                'p2_production' => 60000,
                'shifts' => 1,
                'postponed_flag' => 'NO',
                'postponed_pct' => 0,
            ],
            'marketing' => [
                'marketing_budget_pct' => 0.05,
                'marketing_alloc_champions' => 0.50,
                'marketing_alloc_growth' => 0.30,
                'marketing_alloc_at_risk' => 0.15,
                'marketing_alloc_other' => 0.05,
            ],
            'quality' => [
                'inspection_level' => 'BASIC',
            ],
            'logistics' => [
                'logistics_mode' => 'STANDARD',
            ],
            'notes_elections' => [
                'inventory_method' => 'fifo',
                'depreciation_method' => 'straight_line',
                'shipping_terms' => 'fob_shipping_point',
                'bad_debt_method' => 'percentage_of_receivables',
                'warranty_method' => 'percentage_of_sales',
                'excise_tax_treatment' => 'cogs_inclusion',
            ],
            'certification' => [
                'attestation_scope' => 'all_statements_attested',
            ],
        ];
    }
}
