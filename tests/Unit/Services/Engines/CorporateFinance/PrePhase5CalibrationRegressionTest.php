<?php

namespace Tests\Unit\Services\Engines\CorporateFinance;

use App\Services\Engines\CorporateFinance\AccountingIdentityValidator;
use App\Services\Engines\CorporateFinance\CfManufacturingSimRulesService;
use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngineInput;
use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngineResult;
use App\Services\Engines\CorporateFinance\Contracts\OpeningFinancialPosition;
use App\Services\Engines\CorporateFinance\Exceptions\EngineCalculationException;
use App\Services\Engines\CorporateFinance\Exceptions\PlausibilityValidationException;
use App\Services\Engines\CorporateFinance\FinancialPlausibilityValidator;
use App\Services\Engines\CorporateFinance\ManufacturingCFEngine;
use App\Support\Scenario\CfManufacturingScenarioDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PrePhase5CalibrationRegressionTest extends TestCase
{
    public function test_active_prices_and_references_are_calibrated_together(): void
    {
        $parameters = CfManufacturingScenarioDefinition::parameters();
        $decisions = (new CfManufacturingSimRulesService)->deriveCompleteDecisions(1, $this->standardDemand(), $parameters);

        $this->assertSame(270, $parameters['p1_base_price']);
        $this->assertSame(350, $parameters['p2_base_price']);
        $this->assertSame(270.0, $decisions['pricing']['price_p1_r1']);
        $this->assertSame(350.0, $decisions['pricing']['price_p2_r1']);
        $this->assertSame(1.0, $decisions['pricing']['price_p1_r1'] / $parameters['p1_base_price']);
        $this->assertSame(1.0, $decisions['pricing']['price_p2_r1'] / $parameters['p2_base_price']);
    }

    public function test_valid_loss_path_reports_literal_rule_evaluations(): void
    {
        [$result, $opening] = $this->lossResult();
        $validator = new FinancialPlausibilityValidator;
        $validator->validate($result, $opening);
        $rules = $validator->evaluate($result, $opening);

        $this->assertEqualsWithDelta(-4571763.34, $result->incomeStatement['net_income'], 0.01);
        $this->assertEqualsWithDelta(3840749.41, $result->fields['n5_balance'], 0.01);
        $this->assertEqualsWithDelta(0.196, $rules['receivables_to_revenue_ratio']['calculated'], 0.000001);
        $this->assertEqualsWithDelta(0.30, $rules['payables_to_purchases_ratio']['calculated'], 0.000001);
        $this->assertEqualsWithDelta(0.0, $rules['inventory_to_cogs_ratio']['calculated'], 0.000001);
        $this->assertEqualsWithDelta(0.6248033832406904, $rules['cash_movement_to_net_revenue']['calculated'], 0.000001);
        $this->assertSame(0.0, $rules['debt_coherence']['calculated']);
        $this->assertSame(0.0, $rules['retained_earnings_coherence']['calculated']);

        foreach (['receivables_to_revenue_ratio', 'payables_to_purchases_ratio', 'inventory_to_cogs_ratio', 'cash_movement_to_net_revenue', 'debt_coherence', 'retained_earnings_coherence'] as $rule) {
            $this->assertTrue($rules[$rule]['evaluated']);
            $this->assertTrue($rules[$rule]['passed']);
            $this->assertNull($rules[$rule]['skip_reason']);
        }

        $this->assertFalse($rules['inventory_turnover']['evaluated']);
        $this->assertSame('Opening inventory is zero; first-quarter inventory turnover has no meaningful denominator.', $rules['inventory_turnover']['skip_reason']);

        foreach (['bounded_qoq_bs_ar', 'bounded_qoq_bs_ap'] as $rule) {
            $this->assertFalse($rules[$rule]['evaluated']);
            $this->assertSame('No prior-quarter result was supplied.', $rules[$rule]['skip_reason']);
        }
    }

    public function test_inventory_can_increase_by_more_than_thirty_percent_of_revenue(): void
    {
        $parameters = CfManufacturingScenarioDefinition::parameters();
        $opening = OpeningFinancialPosition::fromScenarioParameters($parameters);

        $decisions = $this->standardDecisions(
            $parameters['p1_base_price'],
            $parameters['p2_base_price'],
        );

        $decisions['production'] = [
            'p1_production' => 250000,
            'p2_production' => 0,
            'shifts' => 1,
            'postponed_flag' => 'NO',
            'postponed_pct' => 0,
        ];

        $result = $this->calculate(1, $decisions, $parameters, $opening);

        $inventoryMovementRatio = abs(
            $result->fields['bs_inventory'] - $opening->inventory
        ) / max($result->incomeStatement['net_revenue'], 1.0);

        $this->assertGreaterThan(0.30, $inventoryMovementRatio);

        (new AccountingIdentityValidator)->validate($result->fields, $opening);
        (new FinancialPlausibilityValidator)->validate($result, $opening);

        $rules = (new FinancialPlausibilityValidator)->evaluate($result, $opening);

        $this->assertArrayNotHasKey('bounded_qoq_bs_inventory', $rules);
    }

    public function test_five_weak_quarters_roll_losses_retained_earnings_and_debt_as_stocks(): void
    {
        $parameters = $this->lossParameters();
        $decisions = $this->lossDecisions();
        $opening = OpeningFinancialPosition::fromScenarioParameters($parameters);
        $netIncome = [-4571763.34, -4883569.55, -5156024.76, -5447132.87, -5758292.84];
        $debt = [3840749.41, 7156818.96, 10721593.72, 14553726.59, 18673269.43];
        $retained = [-4571763.34, -9455332.89, -14611357.65, -20058490.52, -25816783.36];
        $prior = null;

        for ($quarter = 1; $quarter <= 5; $quarter++) {
            $result = $this->calculate($quarter, $decisions, $parameters, $opening);
            (new AccountingIdentityValidator)->validate($result->fields, $opening);
            (new FinancialPlausibilityValidator)->validate($result, $opening, $prior);

            $this->assertEqualsWithDelta($netIncome[$quarter - 1], $result->incomeStatement['net_income'], 0.01);
            $this->assertEqualsWithDelta($debt[$quarter - 1], $result->fields['n5_balance'], 0.01);
            $this->assertEqualsWithDelta($retained[$quarter - 1], $result->fields['bs_retained'] + $result->fields['bs_curr_ni'], 0.01);
            $this->assertEqualsWithDelta($opening->retainedEarnings + $result->incomeStatement['net_income'], $result->fields['bs_retained'] + $result->fields['bs_curr_ni'], 0.01);
            $this->assertEqualsWithDelta($opening->debt + $result->fields['cf_borrow_proc'] - $result->fields['cf_borrow_repay'], $result->fields['n5_balance'], 0.01);
            $this->assertLessThan(0, $result->fields['bs_retained'] + $result->fields['bs_curr_ni']);

            if ($quarter > 1) {
                $this->assertGreaterThan(0, $opening->debt);
                $this->assertGreaterThan(0, $result->fields['cf_borrow_proc']);
                $this->assertGreaterThan(0, $result->fields['cf_borrow_repay']);
            }

            $prior = $result;
            $opening = OpeningFinancialPosition::fromPriorEngineResult($result->fields);
        }
    }

    public function test_loss_side_debt_mutation_is_isolated(): void
    {
        [$result, $opening] = $this->lossResult();
        $fields = $result->fields;
        $fields['n5_balance'] += 1;
        $violations = $this->violations($this->withFields($result, $fields), $opening);

        $this->assertSame(['debt_coherence'], array_column($violations, 'rule'));
        $this->assertEqualsWithDelta(3840749.41, $violations[0]['calculated'], 0.01);
        $this->assertSame('0.00..reported n5_balance', $violations[0]['allowed_range']);
        $this->assertEqualsWithDelta(3840750.41, $violations[0]['values']['n5_balance'], 0.01);
    }

    public function test_loss_side_retained_mutation_is_isolated(): void
    {
        [$result, $opening] = $this->lossResult();
        $fields = $result->fields;
        $fields['bs_curr_ni'] += 1;
        $violations = $this->violations($this->withFields($result, $fields), $opening);

        $this->assertSame(['retained_earnings_coherence'], array_column($violations, 'rule'));
        $this->assertEqualsWithDelta(-4571762.34, $violations[0]['calculated'], 0.01);
        $this->assertSame('reported net income', $violations[0]['allowed_range']);
        $this->assertEqualsWithDelta(-4571763.34, $violations[0]['values']['net_income'], 0.01);
    }

    public function test_loss_side_multiple_mutations_collect_in_stable_order(): void
    {
        [$result, $opening] = $this->lossResult();
        $fields = $result->fields;
        $fields['n5_balance'] += 1;
        $fields['bs_curr_ni'] += 1;
        $violations = $this->violations($this->withFields($result, $fields), $opening);

        $this->assertSame(['debt_coherence', 'retained_earnings_coherence'], array_column($violations, 'rule'));
        $this->assertEqualsWithDelta(3840749.41, $violations[0]['calculated'], 0.01);
        $this->assertEqualsWithDelta(-4571762.34, $violations[1]['calculated'], 0.01);
    }

    public function test_loss_side_cash_mutation_fails_cash_identity(): void
    {
        [$result, $opening] = $this->lossResult();
        $fields = $result->fields;
        $fields['bs_cash'] += 1;
        $this->expectException(EngineCalculationException::class);
        $this->expectExceptionMessage('Balance-sheet identity failed');
        (new AccountingIdentityValidator)->validate($fields, $opening);
    }

    #[DataProvider('priceCorners')]
    public function test_price_range_corner_with_matched_reference(float $p1, float $p2): void
    {
        $parameters = CfManufacturingScenarioDefinition::parameters();
        $parameters['p1_base_price'] = $p1;
        $parameters['p2_base_price'] = $p2;
        $decisions = $this->standardDecisions($p1, $p2);
        $opening = OpeningFinancialPosition::fromScenarioParameters($parameters);
        $result = $this->calculate(1, $decisions, $parameters, $opening);
        $rules = (new FinancialPlausibilityValidator)->evaluate($result, $opening);

        (new AccountingIdentityValidator)->validate($result->fields, $opening);
        (new FinancialPlausibilityValidator)->validate($result, $opening);
        $this->assertGreaterThanOrEqual(210, $p1);
        $this->assertLessThanOrEqual(380, $p1);
        $this->assertGreaterThanOrEqual(280, $p2);
        $this->assertLessThanOrEqual(455, $p2);
        $this->assertSame(191000.0, $result->intermediates['total_units_sold']);
        $this->assertTrue($rules['debt_coherence']['passed']);
        $this->assertTrue($rules['retained_earnings_coherence']['passed']);
    }

    public static function priceCorners(): array
    {
        return ['low-low' => [210.0, 280.0], 'low-high' => [210.0, 455.0], 'high-low' => [380.0, 280.0], 'high-high' => [380.0, 455.0]];
    }

    private function lossResult(): array
    {
        $parameters = $this->lossParameters();
        $opening = OpeningFinancialPosition::fromScenarioParameters($parameters);

        return [$this->calculate(1, $this->lossDecisions(), $parameters, $opening), $opening];
    }

    private function lossParameters(): array
    {
        $parameters = CfManufacturingScenarioDefinition::parameters();
        $parameters['p1_base_price'] = 210;
        $parameters['p2_base_price'] = 280;

        return $parameters;
    }

    private function lossDecisions(): array
    {
        $decisions = $this->standardDecisions(210, 280);
        $decisions['forecasting'] = ['forecast_p1_r1' => 1000, 'forecast_p1_r2' => 1000, 'forecast_p1_r3' => 1000, 'forecast_p2_r1' => 1000, 'forecast_p2_r2' => 1000, 'forecast_p2_r3' => 1000, 'forecast_confidence' => 'low'];
        $decisions['production'] = ['p1_production' => 3000, 'p2_production' => 3000, 'shifts' => 1, 'postponed_flag' => 'NO', 'postponed_pct' => 0];
        $decisions['sourcing'] = ['primary_supplier' => 'SUP002', 'primary_alloc' => 1.0, 'secondary_supplier' => 'NONE'];
        $decisions['marketing']['marketing_budget_pct'] = 0.20;
        $decisions['quality'] = ['inspection_level' => 'FULL'];
        $decisions['logistics'] = ['logistics_mode' => 'AIR'];

        return $decisions;
    }

    private function standardDecisions(float $p1, float $p2): array
    {
        return [
            'forecasting' => $this->standardDemand() + ['forecast_confidence' => 'medium'],
            'sourcing' => ['primary_supplier' => 'SUP001', 'primary_alloc' => 1.0, 'secondary_supplier' => 'NONE'],
            'pricing' => ['price_p1_r1' => $p1, 'price_p1_r2' => $p1, 'price_p1_r3' => $p1, 'price_p2_r1' => $p2, 'price_p2_r2' => $p2, 'price_p2_r3' => $p2, 'discount_policy' => 'none'],
            'production' => ['p1_production' => 130000, 'p2_production' => 61000, 'shifts' => 1, 'postponed_flag' => 'NO', 'postponed_pct' => 0],
            'marketing' => ['marketing_budget_pct' => 0.05, 'marketing_alloc_champions' => 0.50, 'marketing_alloc_growth' => 0.30, 'marketing_alloc_at_risk' => 0.15, 'marketing_alloc_other' => 0.05],
            'quality' => ['inspection_level' => 'BASIC'],
            'logistics' => ['logistics_mode' => 'EXPRESS'],
            'notes_elections' => CfManufacturingScenarioDefinition::fixedWarmupPayload('notes_elections'),
            'certification' => CfManufacturingScenarioDefinition::fixedWarmupPayload('certification'),
        ];
    }

    private function standardDemand(): array
    {
        return ['forecast_p1_r1' => 30000, 'forecast_p1_r2' => 40000, 'forecast_p1_r3' => 60000, 'forecast_p2_r1' => 27000, 'forecast_p2_r2' => 18000, 'forecast_p2_r3' => 16000];
    }

    private function calculate(int $quarter, array $decisions, array $parameters, OpeningFinancialPosition $opening): ManufacturingEngineResult
    {
        return (new ManufacturingCFEngine)->calculate(new ManufacturingEngineInput($quarter, $decisions, $parameters, $opening));
    }

    private function withFields(ManufacturingEngineResult $result, array $fields): ManufacturingEngineResult
    {
        return new ManufacturingEngineResult($fields, $result->incomeStatement, $result->balanceSheet, $result->cashFlowStatement, $result->notes, $result->intermediates);
    }

    private function violations(ManufacturingEngineResult $result, OpeningFinancialPosition $opening): array
    {
        try {
            (new FinancialPlausibilityValidator)->validate($result, $opening);
        } catch (PlausibilityValidationException $exception) {
            return $exception->violations();
        }

        return [];
    }
}
