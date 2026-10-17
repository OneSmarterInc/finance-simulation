<?php

namespace Tests\Unit\Services\Engines\CorporateFinance;

use App\Services\Engines\CorporateFinance\CfManufacturingSimRulesService;
use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngineInput;
use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngineResult;
use App\Services\Engines\CorporateFinance\Contracts\OpeningFinancialPosition;
use App\Services\Engines\CorporateFinance\Contracts\PpeComponentAllocation;
use App\Services\Engines\CorporateFinance\CurrencyRounding;
use App\Services\Engines\CorporateFinance\ManufacturingCFEngine;
use App\Support\Scenario\CfManufacturingScenarioDefinition;
use Tests\TestCase;

class ManufacturingCFIncomeStatementCaptionsTest extends TestCase
{
    public function test_profitable_quarter_with_debt_exposes_literal_captions_and_two_route_reconciliation(): void
    {
        $opening = new OpeningFinancialPosition(
            cash: 10000000.0,
            accountsReceivable: 0.0,
            inventory: 0.0,
            accountsPayable: 0.0,
            debt: 4000000.0,
            retainedEarnings: 0.0,
            accumulatedDepreciation: 0.0,
            plantInvestment: 100000000.0,
            corporateCapitalization: 106000000.0,
            ppeComponents: new PpeComponentAllocation(50000000.0, 12500000.0, 37500000.0),
        );

        $this->assertRegime($this->workedResult($opening), [
            'operating_income' => 63336262.30,
            'interest_expense' => 80000.00,
            'income_before_income_taxes' => 63256262.30,
            'income_tax_expense' => 15814065.58,
            'net_income' => 47442196.72,
        ]);
    }

    public function test_profitable_quarter_without_debt_exposes_literal_captions_and_two_route_reconciliation(): void
    {
        $this->assertRegime($this->workedResult(), [
            'operating_income' => 63336262.30,
            'interest_expense' => 0.00,
            'income_before_income_taxes' => 63336262.30,
            'income_tax_expense' => 15834065.58,
            'net_income' => 47502196.72,
        ]);
    }

    public function test_loss_quarter_preserves_zero_benefit_tax_floor_and_two_route_reconciliation(): void
    {
        $parameters = CfManufacturingScenarioDefinition::parameters();
        $parameters['p1_base_price'] = 210;
        $parameters['p2_base_price'] = 280;
        $decisions = $this->standardDecisions(210, 280);
        $decisions['forecasting'] = array_fill_keys(array_keys($this->standardDemand()), 1000) + ['forecast_confidence' => 'low'];
        $decisions['production'] = ['p1_production' => 3000, 'p2_production' => 3000, 'shifts' => 1, 'postponed_flag' => 'NO', 'postponed_pct' => 0];
        $decisions['sourcing'] = ['primary_supplier' => 'SUP002', 'primary_alloc' => 1.0, 'secondary_supplier' => 'NONE'];
        $decisions['marketing']['marketing_budget_pct'] = 0.20;
        $decisions['quality'] = ['inspection_level' => 'FULL'];
        $decisions['logistics'] = ['logistics_mode' => 'AIR'];

        $this->assertRegime($this->calculate($decisions, $parameters), [
            'operating_income' => -4571763.34,
            'interest_expense' => 0.00,
            'income_before_income_taxes' => -4571763.34,
            'income_tax_expense' => 0.00,
            'net_income' => -4571763.34,
        ]);
    }

    public function test_overdraft_quarter_exposes_actual_zero_interest_result_without_inventing_overdraft_cost(): void
    {
        $parameters = CfManufacturingScenarioDefinition::parameters();
        foreach (['credit_tier_excellent_limit', 'credit_tier_good_limit', 'credit_tier_fair_limit', 'credit_tier_poor_limit', 'credit_tier_distressed_limit'] as $key) {
            $parameters[$key] = 1000;
        }
        $demand = array_fill_keys(array_keys(CfManufacturingScenarioDefinition::q0ForecastingPayload()), 100);
        $decisions = $this->completeDecisions($demand, $parameters);
        $decisions['production']['p1_production'] = 300;
        $decisions['production']['p2_production'] = 300;
        foreach (['price_p1_r1', 'price_p1_r2', 'price_p1_r3'] as $field) {
            $decisions['pricing'][$field] = 210;
        }
        foreach (['price_p2_r1', 'price_p2_r2', 'price_p2_r3'] as $field) {
            $decisions['pricing'][$field] = 280;
        }
        $decisions['marketing']['marketing_budget_pct'] = 0.20;
        $decisions['quality']['inspection_level'] = 'FULL';
        $decisions['logistics']['logistics_mode'] = 'AIR';
        $result = $this->calculate($decisions, $parameters);

        $this->assertSame(2344121.75, $result->fields['bs_bank_overdraft']);
        $this->assertRegime($result, [
            'operating_income' => -3984109.32,
            'interest_expense' => 0.00,
            'income_before_income_taxes' => -3984109.32,
            'income_tax_expense' => 0.00,
            'net_income' => -3984109.32,
        ]);
    }

    public function test_amendment_result_surfaces_are_frozen_with_approved_rounding_movements(): void
    {
        $debtOpening = new OpeningFinancialPosition(10000000, 0, 0, 0, 4000000, 0, 0, 100000000, 106000000, new PpeComponentAllocation(50000000, 12500000, 37500000));
        $results = [
            'no_debt' => [$this->workedResult(), 'eab749f7af2c5992c32e5b937137d5587e87c16f25147ce73331ac2ae58d9406'],
            'debt' => [$this->workedResult($debtOpening), 'ad66de7000c47c4e09233939f61b4e261847fc737a91e769bda1590fc463d2a3'],
            'loss' => [$this->lossResult(), 'a3c3bf0f124894cf48ae7abf86e211431016124a0412610e79dfea2475e000c9'],
            'overdraft' => [$this->overdraftResult(), '1c064884af85545888455d39fc72614f4096ae95703de7dafa88f63213436639'],
        ];

        foreach ($results as $name => [$result, $expectedHash]) {
            $incomeStatement = $result->incomeStatement;
            unset($incomeStatement['interest_expense'], $incomeStatement['income_before_income_taxes'], $incomeStatement['income_tax_expense']);
            $surface = ['fields' => $result->fields, 'income_statement' => $incomeStatement, 'balance_sheet' => $result->balanceSheet, 'cash_flow_statement' => $result->cashFlowStatement, 'notes' => $result->notes, 'intermediates' => $result->intermediates];

            $this->assertSame($expectedHash, hash('sha256', json_encode($surface, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)), "Pre-existing {$name} result surface changed.");
        }

        $legacyFixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/Engines/CorporateFinance/manufacturing_cf_legacy_executed_output.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(125, $legacyFixture['provenance']['legacy_field_count']);
        $this->assertCount(125, $legacyFixture['fields']);
        $this->assertCount(129, ManufacturingCFEngine::FIELD_KEYS);
    }

    public function test_currency_rounding_is_explicitly_half_away_from_zero(): void
    {
        $this->assertSame(1.01, CurrencyRounding::cents(1.005));
        $this->assertSame(-1.01, CurrencyRounding::cents(-1.005));
    }

    /** @param array<string, float> $expected */
    private function assertRegime(ManufacturingEngineResult $result, array $expected): void
    {
        foreach ($expected as $key => $value) {
            $this->assertSame($this->cents($value), $this->cents($result->incomeStatement[$key]), $key);
        }
        $statement = $result->incomeStatement;
        $this->assertSame($this->cents($statement['gross_profit']), $this->cents($statement['net_revenue']) - $this->cents($statement['cost_of_goods_sold']));
        $this->assertSame($this->cents($statement['operating_income']), $this->cents($statement['gross_profit']) - $this->cents($statement['operating_expenses']));
        $this->assertSame($this->cents($statement['income_before_income_taxes']), $this->cents($statement['operating_income']) - $this->cents($statement['interest_expense']));
        $this->assertSame($this->cents($statement['net_income']), $this->cents($statement['income_before_income_taxes']) - $this->cents($statement['income_tax_expense']));
        $this->assertSame($this->cents($statement['income_before_income_taxes']), $this->cents($statement['net_income']) + $this->cents($statement['income_tax_expense']));
        $this->assertSame($statement['net_income'], $result->fields['bs_curr_ni']);
        $this->assertSame($statement['net_income'], $result->fields['cf_net_income']);
        $this->assertSame($statement['net_income'], $result->intermediates['net_income']);
        $this->assertSame($statement['income_tax_expense'], $result->intermediates['is_tax']);
    }

    private function cents(float $value): int
    {
        return (int) round($value * 100, 0, PHP_ROUND_HALF_UP);
    }

    private function workedResult(?OpeningFinancialPosition $opening = null): ManufacturingEngineResult
    {
        $parameters = CfManufacturingScenarioDefinition::parameters();
        $parameters['p1_base_price'] = 500;
        $parameters['p2_base_price'] = 850;
        $decisions = $this->standardDecisions(500, 850, 125000, 60000);
        $decisions['logistics']['logistics_mode'] = 'STANDARD';

        return $this->calculate($decisions, $parameters, $opening);
    }

    private function lossResult(): ManufacturingEngineResult
    {
        $parameters = CfManufacturingScenarioDefinition::parameters();
        $parameters['p1_base_price'] = 210;
        $parameters['p2_base_price'] = 280;
        $decisions = $this->standardDecisions(210, 280);
        $decisions['forecasting'] = array_fill_keys(array_keys($this->standardDemand()), 1000) + ['forecast_confidence' => 'low'];
        $decisions['production'] = ['p1_production' => 3000, 'p2_production' => 3000, 'shifts' => 1, 'postponed_flag' => 'NO', 'postponed_pct' => 0];
        $decisions['sourcing'] = ['primary_supplier' => 'SUP002', 'primary_alloc' => 1.0, 'secondary_supplier' => 'NONE'];
        $decisions['marketing']['marketing_budget_pct'] = 0.20;
        $decisions['quality'] = ['inspection_level' => 'FULL'];
        $decisions['logistics'] = ['logistics_mode' => 'AIR'];

        return $this->calculate($decisions, $parameters);
    }

    private function overdraftResult(): ManufacturingEngineResult
    {
        $parameters = CfManufacturingScenarioDefinition::parameters();
        foreach (['credit_tier_excellent_limit', 'credit_tier_good_limit', 'credit_tier_fair_limit', 'credit_tier_poor_limit', 'credit_tier_distressed_limit'] as $key) {
            $parameters[$key] = 1000;
        }
        $demand = array_fill_keys(array_keys(CfManufacturingScenarioDefinition::q0ForecastingPayload()), 100);
        $decisions = $this->completeDecisions($demand, $parameters);
        $decisions['production'] = ['p1_production' => 300, 'p2_production' => 300, 'shifts' => 1, 'postponed_flag' => 'NO', 'postponed_pct' => 0];
        foreach (['price_p1_r1', 'price_p1_r2', 'price_p1_r3'] as $field) {
            $decisions['pricing'][$field] = 210;
        }
        foreach (['price_p2_r1', 'price_p2_r2', 'price_p2_r3'] as $field) {
            $decisions['pricing'][$field] = 280;
        }
        $decisions['marketing']['marketing_budget_pct'] = 0.20;
        $decisions['quality']['inspection_level'] = 'FULL';
        $decisions['logistics']['logistics_mode'] = 'AIR';

        return $this->calculate($decisions, $parameters);
    }

    /** @return array<string, array<string, mixed>> */
    private function standardDecisions(float $p1, float $p2, int $p1Production = 130000, int $p2Production = 61000): array
    {
        return [
            'forecasting' => $this->standardDemand() + ['forecast_confidence' => 'medium'],
            'sourcing' => ['primary_supplier' => 'SUP001', 'primary_alloc' => 1.0, 'secondary_supplier' => 'NONE'],
            'pricing' => ['price_p1_r1' => $p1, 'price_p1_r2' => $p1, 'price_p1_r3' => $p1, 'price_p2_r1' => $p2, 'price_p2_r2' => $p2, 'price_p2_r3' => $p2, 'discount_policy' => 'none'],
            'production' => ['p1_production' => $p1Production, 'p2_production' => $p2Production, 'shifts' => 1, 'postponed_flag' => 'NO', 'postponed_pct' => 0],
            'marketing' => ['marketing_budget_pct' => 0.05, 'marketing_alloc_champions' => 0.50, 'marketing_alloc_growth' => 0.30, 'marketing_alloc_at_risk' => 0.15, 'marketing_alloc_other' => 0.05],
            'quality' => ['inspection_level' => 'BASIC'],
            'logistics' => ['logistics_mode' => 'EXPRESS'],
            'notes_elections' => CfManufacturingScenarioDefinition::fixedWarmupPayload('notes_elections'),
            'certification' => CfManufacturingScenarioDefinition::fixedWarmupPayload('certification'),
        ];
    }

    /** @return array<string, int> */
    private function standardDemand(): array
    {
        return ['forecast_p1_r1' => 30000, 'forecast_p1_r2' => 40000, 'forecast_p1_r3' => 60000, 'forecast_p2_r1' => 27000, 'forecast_p2_r2' => 18000, 'forecast_p2_r3' => 16000];
    }

    /** @return array<string, array<string, mixed>> */
    private function completeDecisions(array $demand, array $parameters): array
    {
        return array_merge((new CfManufacturingSimRulesService)->deriveCompleteDecisions(1, $demand, $parameters), [
            'notes_elections' => CfManufacturingScenarioDefinition::fixedWarmupPayload('notes_elections'),
            'certification' => CfManufacturingScenarioDefinition::fixedWarmupPayload('certification'),
        ]);
    }

    /** @param array<string, array<string, mixed>> $decisions */
    private function calculate(array $decisions, array $parameters, ?OpeningFinancialPosition $opening = null): ManufacturingEngineResult
    {
        return (new ManufacturingCFEngine)->calculate(new ManufacturingEngineInput(1, $decisions, $parameters, $opening ?? OpeningFinancialPosition::fromScenarioParameters($parameters)));
    }
}
