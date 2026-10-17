<?php

namespace Tests\Unit\Services\Engines\CorporateFinance;

use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngineInput;
use App\Services\Engines\CorporateFinance\Contracts\OpeningFinancialPosition;
use App\Services\Engines\CorporateFinance\Exceptions\PlausibilityValidationException;
use App\Services\Engines\CorporateFinance\FinancialPlausibilityValidator;
use App\Services\Engines\CorporateFinance\ManufacturingCFEngine;
use App\Support\Scenario\CfManufacturingScenarioDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ManufacturingCFSignedMovementsCorrectiveTest extends TestCase
{
    #[DataProvider('signedLiabilityCases')]
    public function test_signed_accrued_liability_cases_are_literal_and_reconcile(
        array $openingBalances,
        array $parameterOverrides,
        array $expectedEnding,
        array $expectedMovements,
        float $expectedOperatingCashFlow,
        float $expectedEndingCash,
    ): void {
        $parameters = array_replace($this->parameters(), $parameterOverrides);
        $opening = $this->opening($parameters, ...$openingBalances);
        $result = (new ManufacturingCFEngine)->calculate(new ManufacturingEngineInput(2, $this->decisions(), $parameters, $opening));

        $this->assertSame($openingBalances['warrantyReserve'], $result->fields['n6_war_open']);
        $this->assertSame($openingBalances['serviceLevelAccrual'], $result->fields['n6_sl_open']);
        $this->assertSame($openingBalances['excisePayable'], $result->fields['n6_exc_open']);
        $this->assertEqualsWithDelta($expectedEnding['warranty'], $result->fields['bs_warranty_res'], 0.01);
        $this->assertEqualsWithDelta($expectedEnding['service'], $result->fields['bs_sl_accrual'], 0.01);
        $this->assertEqualsWithDelta($expectedEnding['excise'], $result->fields['bs_excise'], 0.01);
        $this->assertEqualsWithDelta($expectedMovements['warranty'], $result->fields['cf_warranty_chg'], 0.01);
        $this->assertEqualsWithDelta($expectedMovements['service'], $result->fields['cf_accrued_chg'], 0.01);
        $this->assertEqualsWithDelta($expectedMovements['excise'], $result->fields['cf_excise_chg'], 0.01);
        $this->assertEqualsWithDelta($expectedMovements['aggregate'], $result->fields['cf_warranty_chg'] + $result->fields['cf_accrued_chg'] + $result->fields['cf_excise_chg'], 0.01);
        $this->assertEqualsWithDelta($expectedOperatingCashFlow, $result->cashFlowStatement['operating_cash_flow'], 0.01);
        $this->assertEqualsWithDelta($expectedEndingCash, $result->fields['bs_cash'], 0.01);
        $this->assertEqualsWithDelta(0.0, $result->balanceSheet['assets'] - ($result->balanceSheet['liabilities'] + $result->balanceSheet['equity']), 0.01);
        $this->assertEqualsWithDelta(0.0, ($result->fields['bs_cash'] - $opening->cash) - ($result->cashFlowStatement['operating_cash_flow'] + $result->cashFlowStatement['investing_cash_flow'] + $result->cashFlowStatement['financing_cash_flow']), 0.01);
        $this->assertEqualsWithDelta(0.0, ($result->fields['bs_inventory'] - $opening->inventory) - $result->fields['cf_inv_chg'], 0.01);
    }

    public static function signedLiabilityCases(): array
    {
        return [
            'warranty declines; service and excise rise' => [
                ['warrantyReserve' => 700000.0, 'serviceLevelAccrual' => 50000.0, 'excisePayable' => 700000.0],
                [],
                ['warranty' => 665541.30, 'service' => 55500.0, 'excise' => 780921.60],
                ['warranty' => -34458.70, 'service' => 5500.0, 'excise' => 80921.60, 'aggregate' => 51962.90],
                34816893.82,
                35566893.82,
            ],
            'service declines; warranty and excise rise' => [
                ['warrantyReserve' => 600000.0, 'serviceLevelAccrual' => 60000.0, 'excisePayable' => 700000.0],
                [],
                ['warranty' => 665541.30, 'service' => 55500.0, 'excise' => 780921.60],
                ['warranty' => 65541.30, 'service' => -4500.0, 'excise' => 80921.60, 'aggregate' => 141962.90],
                34906893.82,
                35566893.82,
            ],
            'excise declines; warranty and service rise' => [
                ['warrantyReserve' => 600000.0, 'serviceLevelAccrual' => 50000.0, 'excisePayable' => 800000.0],
                [],
                ['warranty' => 665541.30, 'service' => 55500.0, 'excise' => 780921.60],
                ['warranty' => 65541.30, 'service' => 5500.0, 'excise' => -19078.40, 'aggregate' => 51962.90],
                34816893.82,
                35566893.82,
            ],
            'all three liabilities decline' => [
                ['warrantyReserve' => 700000.0, 'serviceLevelAccrual' => 60000.0, 'excisePayable' => 800000.0],
                [],
                ['warranty' => 665541.30, 'service' => 55500.0, 'excise' => 780921.60],
                ['warranty' => -34458.70, 'service' => -4500.0, 'excise' => -19078.40, 'aggregate' => -58037.10],
                34706893.82,
                35566893.82,
            ],
            'service liability declines exactly to zero' => [
                ['warrantyReserve' => 0.0, 'serviceLevelAccrual' => 100.0, 'excisePayable' => 0.0],
                ['sl_payment_rate' => 1.0],
                ['warranty' => 665541.30, 'service' => 0.0, 'excise' => 780921.60],
                ['warranty' => 665541.30, 'service' => -100.0, 'excise' => 780921.60, 'aggregate' => 1446362.90],
                36211293.82,
                35511393.82,
            ],
        ];
    }

    public function test_original_excise_failure_is_pinned_and_removed(): void
    {
        $parameters = $this->parameters();
        $opening = $this->opening($parameters, 0.0, 0.0, 786208.92);
        $result = (new ManufacturingCFEngine)->calculate(new ManufacturingEngineInput(2, $this->decisions(), $parameters, $opening));

        $this->assertSame(786208.92, $result->fields['n6_exc_open']);
        $this->assertSame(780921.60, $result->fields['n6_exc_close']);
        $this->assertSame(-5287.32, $result->fields['cf_excise_chg']);
        $this->assertSame(10574.64, 2 * abs($result->fields['cf_excise_chg']));
        $this->assertSame(0.0, round(($result->fields['bs_cash'] - $opening->cash) - array_sum([
            $result->cashFlowStatement['operating_cash_flow'],
            $result->cashFlowStatement['investing_cash_flow'],
            $result->cashFlowStatement['financing_cash_flow'],
        ]), 2));
    }

    public function test_minimal_service_level_failure_is_pinned_and_removed(): void
    {
        $parameters = $this->parameters();
        $opening = $this->opening($parameters, 0.0, 55509.62, 0.0);
        $result = (new ManufacturingCFEngine)->calculate(new ManufacturingEngineInput(2, $this->decisions(), $parameters, $opening));

        $this->assertSame(55509.62, $result->fields['n6_sl_open']);
        $this->assertSame(55500.0, $result->fields['n6_sl_close']);
        $this->assertSame(-9.62, $result->fields['cf_accrued_chg']);
        $this->assertSame(19.24, 2 * abs($result->fields['cf_accrued_chg']));
        $this->assertSame(0.0, round(($result->fields['bs_cash'] - $opening->cash) - array_sum([
            $result->cashFlowStatement['operating_cash_flow'],
            $result->cashFlowStatement['investing_cash_flow'],
            $result->cashFlowStatement['financing_cash_flow'],
        ]), 2));
    }

    public function test_deterministic_declining_demand_regime_covers_signed_paths_and_plausibility(): void
    {
        $parameters = $this->currentPriceParameters();
        $engine = new ManufacturingCFEngine;
        $plausibility = new FinancialPlausibilityValidator;
        $declines = ['warranty' => 0, 'service' => 0, 'excise' => 0, 'aggregate' => 0, 'receivables' => 0, 'payables' => 0, 'inventory' => 0];
        $quarters = 0;
        $flaggedQuarters = 0;
        $violationQuarters = 0;
        $violationRules = [];

        for ($case = 1; $case <= 20; $case++) {
            $demand = [30000, 40000, 60000, 27000, 18000, 16000];
            $opening = OpeningFinancialPosition::fromScenarioParameters($parameters);
            $priorResult = null;

            for ($quarter = 1; $quarter <= 4; $quarter++) {
                $factor = 0.90 + ((($case + $quarter) % 8) * 0.01);
                $demand = array_map(static fn (int $value): int => (int) round($value * $factor), $demand);
                $result = $engine->calculate(new ManufacturingEngineInput($quarter, $this->decisionsForDemand($demand), $parameters, $opening));
                $quarters++;

                $warranty = round($result->fields['bs_warranty_res'] - $opening->warrantyReserve, 2);
                $service = round($result->fields['bs_sl_accrual'] - $opening->serviceLevelAccrual, 2);
                $excise = round($result->fields['bs_excise'] - $opening->excisePayable, 2);
                $aggregate = round($warranty + $service + $excise, 2);
                $ar = round($result->fields['bs_ar'] - $opening->accountsReceivable, 2);
                $ap = round($result->fields['bs_ap'] - $opening->accountsPayable, 2);
                $inventory = round($result->fields['bs_inventory'] - $opening->inventory, 2);

                foreach (compact('warranty', 'service', 'excise', 'aggregate') as $key => $movement) {
                    $declines[$key] += $movement < 0 ? 1 : 0;
                }
                $declines['receivables'] += $ar < 0 ? 1 : 0;
                $declines['payables'] += $ap < 0 ? 1 : 0;
                $declines['inventory'] += $inventory < 0 ? 1 : 0;

                $this->assertSame($warranty, $result->fields['cf_warranty_chg']);
                $this->assertSame($service, $result->fields['cf_accrued_chg']);
                $this->assertSame($excise, $result->fields['cf_excise_chg']);
                $this->assertSame($aggregate, round($result->fields['cf_warranty_chg'] + $result->fields['cf_accrued_chg'] + $result->fields['cf_excise_chg'], 2));
                $this->assertEqualsWithDelta(0.0, $result->balanceSheet['assets'] - ($result->balanceSheet['liabilities'] + $result->balanceSheet['equity']), 0.01);
                $this->assertEqualsWithDelta(0.0, ($result->fields['bs_cash'] - $opening->cash) - ($result->cashFlowStatement['operating_cash_flow'] + $result->cashFlowStatement['investing_cash_flow'] + $result->cashFlowStatement['financing_cash_flow']), 0.01);
                $this->assertEqualsWithDelta(0.0, ($result->fields['bs_inventory'] - $opening->inventory) - $result->fields['cf_inv_chg'], 0.01);

                $flaggedQuarters += $plausibility->flags($result) !== [] ? 1 : 0;
                try {
                    $plausibility->validate($result, $opening, $priorResult);
                } catch (PlausibilityValidationException $exception) {
                    $violationQuarters++;
                    foreach ($exception->violations() as $violation) {
                        $violationRules[$violation['rule']] = true;
                    }
                }

                $opening = OpeningFinancialPosition::fromPriorEngineResult($result->fields);
                $priorResult = $result;
            }
        }

        $this->assertSame(80, $quarters);
        $this->assertSame(['warranty' => 60, 'service' => 60, 'excise' => 60, 'aggregate' => 60, 'receivables' => 60, 'payables' => 60, 'inventory' => 0], $declines);
        $this->assertGreaterThan(0, $declines['warranty']);
        $this->assertGreaterThan(0, $declines['service']);
        $this->assertGreaterThan(0, $declines['excise']);
        $this->assertSame(0, $flaggedQuarters);
        $this->assertSame(0, $violationQuarters);
        $this->assertSame([], array_keys($violationRules));
    }

    private function opening(array $parameters, float $warrantyReserve, float $serviceLevelAccrual, float $excisePayable): OpeningFinancialPosition
    {
        $base = OpeningFinancialPosition::fromScenarioParameters($parameters);

        // Opening cash includes accrued liabilities so these fixtures isolate signed CFO movement, not ending-cash variation.
        return new OpeningFinancialPosition(
            cash: $base->cash + $warrantyReserve + $serviceLevelAccrual + $excisePayable,
            accountsReceivable: 0.0,
            inventory: 0.0,
            accountsPayable: 0.0,
            debt: 0.0,
            retainedEarnings: 0.0,
            accumulatedDepreciation: 0.0,
            plantInvestment: $base->plantInvestment,
            corporateCapitalization: $base->corporateCapitalization,
            ppeComponents: $base->ppeComponents,
            warrantyReserve: $warrantyReserve,
            serviceLevelAccrual: $serviceLevelAccrual,
            excisePayable: $excisePayable,
        );
    }

    private function parameters(): array
    {
        $parameters = CfManufacturingScenarioDefinition::parameters();
        $parameters['p1_base_price'] = 500;
        $parameters['p2_base_price'] = 850;

        return $parameters;
    }

    private function currentPriceParameters(): array
    {
        $parameters = CfManufacturingScenarioDefinition::parameters();
        $parameters['p1_base_price'] = 270;
        $parameters['p2_base_price'] = 350;

        return $parameters;
    }

    private function decisions(): array
    {
        return $this->decisionsForDemand([30000, 40000, 60000, 27000, 18000, 16000], 500, 850, 125000, 60000);
    }

    private function decisionsForDemand(array $demand, int $p1Price = 270, int $p2Price = 350, ?int $p1Production = null, ?int $p2Production = null): array
    {
        $p1Production ??= array_sum(array_slice($demand, 0, 3));
        $p2Production ??= array_sum(array_slice($demand, 3, 3));

        return [
            'forecasting' => array_combine(['forecast_p1_r1', 'forecast_p1_r2', 'forecast_p1_r3', 'forecast_p2_r1', 'forecast_p2_r2', 'forecast_p2_r3'], $demand) + ['forecast_confidence' => 'medium'],
            'sourcing' => ['primary_supplier' => 'SUP001', 'primary_alloc' => 1.0, 'secondary_supplier' => 'NONE'],
            'pricing' => ['price_p1_r1' => $p1Price, 'price_p1_r2' => $p1Price, 'price_p1_r3' => $p1Price, 'price_p2_r1' => $p2Price, 'price_p2_r2' => $p2Price, 'price_p2_r3' => $p2Price, 'discount_policy' => 'none'],
            'production' => ['p1_production' => $p1Production, 'p2_production' => $p2Production, 'shifts' => 1, 'postponed_flag' => 'NO', 'postponed_pct' => 0],
            'marketing' => ['marketing_budget_pct' => 0.05, 'marketing_alloc_champions' => 0.50, 'marketing_alloc_growth' => 0.30, 'marketing_alloc_at_risk' => 0.15, 'marketing_alloc_other' => 0.05],
            'quality' => ['inspection_level' => 'BASIC'],
            'logistics' => ['logistics_mode' => 'STANDARD'],
            'notes_elections' => ['inventory_method' => 'fifo', 'depreciation_method' => 'straight_line', 'shipping_terms' => 'fob_shipping_point', 'bad_debt_method' => 'percentage_of_receivables', 'warranty_method' => 'percentage_of_sales', 'excise_tax_treatment' => 'cogs_inclusion'],
            'certification' => ['attestation_scope' => 'all_statements_attested'],
        ];
    }
}
