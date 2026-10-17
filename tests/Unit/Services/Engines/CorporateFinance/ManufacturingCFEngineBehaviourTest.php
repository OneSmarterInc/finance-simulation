<?php

namespace Tests\Unit\Services\Engines\CorporateFinance;

use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngineInput;
use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngineResult;
use App\Services\Engines\CorporateFinance\Contracts\OpeningFinancialPosition;
use App\Services\Engines\CorporateFinance\Contracts\PpeComponentAllocation;
use App\Services\Engines\CorporateFinance\Exceptions\EngineCalculationException;
use App\Services\Engines\CorporateFinance\ManufacturingCFEngine;
use App\Support\Scenario\CfManufacturingScenarioDefinition;
use Tests\TestCase;

class ManufacturingCFEngineBehaviourTest extends TestCase
{
    public function test_worked_quarter_one_oracle_pins_all_result_fields_and_statement_structures(): void
    {
        $result = $this->calculate();

        $expectedFields = [
            'is_p1_rev' => 62500000.0, 'is_p2_rev' => 51000000.0, 'is_returns' => 306450.0, 'is_rebates' => 2270000.0,
            'is_raw_mat' => 22200000.0, 'is_labor' => 8325000.0, 'is_overhead' => 2375000.0, 'is_end_inv' => 0.0,
            'is_lifo_adj' => 0.0, 'is_carry' => 0.0, 'is_excise_cogs' => 3904608.0, 'is_marketing' => 5675000.0,
            'is_freight' => 1948750.0, 'is_sl_penalties' => 111000.0, 'is_excise' => 0.0, 'is_inspection' => 370000.0,
            'is_admin' => 1125000.0, 'is_warranty' => 1109235.5, 'is_interest' => 0.0, 'is_disposal' => 0.0,
            'is_other' => 443694.2, 'is_tax' => 15834065.58,
            'bs_cash' => 35566893.82, 'bs_bank_overdraft' => 0.0, 'bs_ar' => 21741015.8, 'bs_inventory' => 0.0, 'bs_prepaid' => 0.0,
            'bs_ppe_gross' => 100800000.0, 'bs_accum_depr' => 2343750.0, 'bs_rou' => 0.0, 'bs_tech_net' => 0.0,
            'bs_ap' => 6660000.0, 'bs_wages' => 0.0, 'bs_mkt' => 0.0, 'bs_warranty_res' => 665541.3,
            'bs_sl_accrual' => 55500.0, 'bs_excise' => 780921.6, 'bs_curr_ltd' => 0.0, 'bs_def_rev' => 0.0,
            'bs_ltd' => 0.0, 'bs_lease' => 0.0, 'bs_dtl' => 0.0, 'bs_common' => 100100000.0,
            'bs_retained' => 0.0, 'bs_curr_ni' => 47502196.72,
            'cf_net_income' => 47502196.72, 'cf_depr' => 2343750.0, 'cf_rou' => 0.0, 'cf_lifo' => 0.0,
            'cf_ar_chg' => 21741015.8, 'cf_inv_chg' => 0.0, 'cf_prepaid_chg' => 0.0, 'cf_ap_chg' => 6660000.0,
            'cf_accrued_chg' => 55500.0, 'cf_warranty_chg' => 665541.3, 'cf_def_rev_chg' => 0.0, 'cf_excise_chg' => 780921.6,
            'cf_capex_mfg' => 500000.0, 'cf_capex_tech' => 200000.0, 'cf_capex_dc' => 100000.0, 'cf_disposal' => 0.0,
            'cf_borrow_proc' => 0.0, 'cf_borrow_repay' => 0.0, 'cf_overdraft_chg' => 0.0, 'cf_lease_pmt' => 0.0, 'cf_equity' => 0.0,
            'cf_beg_cash' => 100000.0, 'cf_cash_interest' => 0.0, 'cf_cash_tax' => 15834065.58,
            'n2_r1_p1' => 14423000.0, 'n2_r1_p2' => 22573450.0, 'n2_r2_p1' => 19231000.0, 'n2_r2_p2' => 15049250.0,
            'n2_r3_p1' => 28846000.0, 'n2_r3_p2' => 13377300.0, 'n2_rebate_open' => 0.0, 'n2_rebate_added' => 2270000.0,
            'n2_rebate_paid' => 1589000.0,
            'n3_raw_mat' => 0.0, 'n3_wip' => 0.0, 'n3_fg' => 0.0, 'n3_in_transit' => 1776000.0,
            'n3_lifo_res' => 0.0, 'n3_lifo_chg' => 0.0, 'n3_overhead' => 2375000.0,
            'n4_mfg_gross' => 50500000.0, 'n4_mfg_depr' => 1250000.0, 'n4_mfg_net' => 49250000.0,
            'n4_tech_gross' => 12700000.0, 'n4_tech_depr' => 625000.0, 'n4_tech_net' => 12075000.0,
            'n4_dc_gross' => 37600000.0, 'n4_dc_depr' => 468750.0, 'n4_dc_net' => 37131250.0,
            'n4_disposal_nbv' => 0.0, 'n4_disposal_proc' => 0.0, 'n4_disposal_gain' => 0.0, 'n4_util_rate' => 0.74,
            'n5_balance' => 0.0, 'n5_overdraft' => 0.0, 'n5_shortfall_indicator' => 0.0,
            'n5_rate' => 0.08, 'n5_limit' => 100000000.0, 'n5_auto_draw' => 0.0,
            'n5_draw_rate' => 0.08, 'n5_mat_y1' => 0.0, 'n5_mat_y2' => 0.0, 'n5_mat_beyond' => 0.0,
            'n5_cov_curr' => 7.021339146248755, 'n5_cov_de' => 0.0, 'n5_cov_margin' => 0.5709902207421237, 'n5_cov_interest' => 0.0,
            'n5_cov_cash' => 35.56689382, 'n5_int_recon' => 0.0,
            'n6_supplier' => 22200000.0, 'n6_po_fob' => 1.0, 'n6_lease' => 0.0,
            'n6_war_open' => 0.0, 'n6_war_exp' => 1109235.5, 'n6_war_paid' => 443694.2, 'n6_war_close' => 665541.3,
            'n6_sl_open' => 0.0, 'n6_sl_exp' => 111000.0, 'n6_sl_paid' => 55500.0, 'n6_sl_close' => 55500.0,
            'n6_exc_open' => 0.0, 'n6_exc_acc' => 3904608.0, 'n6_exc_paid' => 3123686.4, 'n6_exc_close' => 780921.6,
        ];

        $expectedKeys = array_keys($expectedFields);
        $actualKeys = array_keys($result->fields);
        sort($expectedKeys);
        sort($actualKeys);
        $this->assertSame($expectedKeys, $actualKeys);
        foreach ($expectedFields as $field => $expectedValue) {
            $this->assertEqualsWithDelta($expectedValue, $result->fields[$field], 0.01, $field);
        }

        $this->assertEqualsWithDelta(110923550.0, $result->incomeStatement['net_revenue'], 0.01);
        $this->assertEqualsWithDelta(36804608.0, $result->incomeStatement['cost_of_goods_sold'], 0.01);
        $this->assertEqualsWithDelta(74118942.0, $result->incomeStatement['gross_profit'], 0.01);
        $this->assertEqualsWithDelta(10782679.7, $result->incomeStatement['operating_expenses'], 0.01);
        $this->assertEqualsWithDelta(63336262.3, $result->incomeStatement['operating_income'], 0.01);
        $this->assertEqualsWithDelta(443694.2, $result->incomeStatement['bad_debt_expense'], 0.01);
        $this->assertEqualsWithDelta(47502196.72, $result->incomeStatement['net_income'], 0.01);

        $this->assertEqualsWithDelta(155764159.62, $result->balanceSheet['assets'], 0.01);
        $this->assertEqualsWithDelta(8161962.9, $result->balanceSheet['liabilities'], 0.01);
        $this->assertEqualsWithDelta(147602196.72, $result->balanceSheet['equity'], 0.01);
        $this->assertEqualsWithDelta(36266893.82, $result->cashFlowStatement['operating_cash_flow'], 0.01);
        $this->assertEqualsWithDelta(-800000.0, $result->cashFlowStatement['investing_cash_flow'], 0.01);
        $this->assertEqualsWithDelta(0.0, $result->cashFlowStatement['financing_cash_flow'], 0.01);
        $this->assertEqualsWithDelta(35566893.82, $result->cashFlowStatement['ending_cash'], 0.01);
    }

    public function test_price_elasticity_and_capacity_allocation_behaviours_are_explicit(): void
    {
        $decisions = $this->decisions();
        $decisions['forecasting'] = array_merge($decisions['forecasting'], [
            'forecast_p1_r1' => 10000, 'forecast_p1_r2' => 0, 'forecast_p1_r3' => 0,
            'forecast_p2_r1' => 0, 'forecast_p2_r2' => 0, 'forecast_p2_r3' => 0,
        ]);
        $decisions['pricing']['price_p1_r1'] = 550;
        $decisions['production']['p1_production'] = 20000;
        $decisions['production']['p2_production'] = 0;

        $result = $this->calculate($decisions);
        $expectedDemand = round(10000 * ((550 / 500) ** -1.2));

        $this->assertEqualsWithDelta($expectedDemand, $result->fields['n2_r1_p1'] / 550, 0.01);
        $this->assertSame(0.0, $result->fields['n2_r2_p1']);
        $this->assertSame(0.0, $result->fields['n2_r3_p1']);

        $decisions['pricing']['price_p1_r1'] = 500;
        $result = $this->calculate($decisions);
        $this->assertEqualsWithDelta(10000.0, $result->fields['n2_r1_p1'] / 500, 0.01);
    }

    public function test_inventory_cogs_and_regional_sales_edge_cases_are_explicit(): void
    {
        $capped = $this->decisions();
        $capped['forecasting'] = array_merge($capped['forecasting'], [
            'forecast_p1_r1' => 10000, 'forecast_p1_r2' => 10000, 'forecast_p1_r3' => 10000,
            'forecast_p2_r1' => 0, 'forecast_p2_r2' => 0, 'forecast_p2_r3' => 0,
        ]);
        $capped['production']['p1_production'] = 9000;
        $capped['production']['p2_production'] = 0;
        $cappedResult = $this->calculate($capped);

        $this->assertEqualsWithDelta(3000 * 500, $cappedResult->fields['n2_r1_p1'], 0.01);
        $this->assertEqualsWithDelta(3000 * 500, $cappedResult->fields['n2_r2_p1'], 0.01);
        $this->assertEqualsWithDelta(3000 * 500, $cappedResult->fields['n2_r3_p1'], 0.01);

        $inventory = $this->decisions();
        $inventory['forecasting'] = array_merge($inventory['forecasting'], [
            'forecast_p1_r1' => 1000, 'forecast_p1_r2' => 0, 'forecast_p1_r3' => 0,
            'forecast_p2_r1' => 0, 'forecast_p2_r2' => 0, 'forecast_p2_r3' => 0,
        ]);
        $inventory['production']['p1_production'] = 2000;
        $inventory['production']['p2_production'] = 0;
        $fifo = $this->calculate($inventory);
        $inventory['notes_elections']['inventory_method'] = 'lifo';
        $lifo = $this->calculate($inventory);

        $this->assertGreaterThan(0, $fifo->fields['bs_inventory']);
        $this->assertSame(0.0, $fifo->fields['is_lifo_adj']);
        $this->assertGreaterThan(0, $lifo->fields['is_lifo_adj']);
        $this->assertGreaterThan($fifo->fields['incomeStatement']['cost_of_goods_sold'] ?? $fifo->incomeStatement['cost_of_goods_sold'], $lifo->incomeStatement['cost_of_goods_sold']);

        $zeroDemand = $this->decisions();
        foreach (['forecast_p1_r1', 'forecast_p1_r2', 'forecast_p1_r3', 'forecast_p2_r1', 'forecast_p2_r2', 'forecast_p2_r3'] as $field) {
            $zeroDemand['forecasting'][$field] = 0;
        }
        $zeroResult = $this->calculate($zeroDemand);
        $this->assertSame(0.0, $zeroResult->fields['is_p1_rev']);
        $this->assertSame(0.0, $zeroResult->fields['is_p2_rev']);
    }

    public function test_election_methods_change_warranty_bad_debt_and_excise_statement_lines(): void
    {
        $percentageWarranty = $this->calculate()->fields;
        $incurred = $this->decisions();
        $incurred['notes_elections']['warranty_method'] = 'expense_as_incurred';
        $incurredResult = $this->calculate($incurred)->fields;
        $this->assertLessThan($percentageWarranty['is_warranty'], $incurredResult['is_warranty']);

        $salesBadDebt = $this->decisions();
        $salesBadDebt['notes_elections']['bad_debt_method'] = 'percentage_of_sales';
        $salesBadDebtResult = $this->calculate($salesBadDebt);
        $this->assertEqualsWithDelta($salesBadDebtResult->incomeStatement['net_revenue'] * 0.02, $salesBadDebtResult->fields['is_other'], 0.01);
        $this->assertGreaterThan($percentageWarranty['is_other'], $salesBadDebtResult->fields['is_other']);

        $cogs = $this->calculate();
        $separate = $this->decisions();
        $separate['notes_elections']['excise_tax_treatment'] = 'separate_operating_expense';
        $separateResult = $this->calculate($separate);
        $this->assertGreaterThan(0, $cogs->fields['is_excise_cogs']);
        $this->assertSame(0.0, $cogs->fields['is_excise']);
        $this->assertSame(0.0, $separateResult->fields['is_excise_cogs']);
        $this->assertGreaterThan(0, $separateResult->fields['is_excise']);
    }

    public function test_financing_working_capital_tax_and_credit_behaviours_are_explicit(): void
    {
        $borrowingDecisions = $this->decisions();
        foreach (['forecast_p1_r1', 'forecast_p1_r2', 'forecast_p1_r3', 'forecast_p2_r1', 'forecast_p2_r2', 'forecast_p2_r3'] as $field) {
            $borrowingDecisions['forecasting'][$field] = 0;
        }
        $borrowingDecisions['production']['p1_production'] = 0;
        $borrowingDecisions['production']['p2_production'] = 0;
        $borrowing = $this->calculate($borrowingDecisions);
        $this->assertGreaterThan(0, $borrowing->fields['cf_borrow_proc']);
        $this->assertGreaterThan(0, $borrowing->fields['n5_balance']);

        $debtOpening = new OpeningFinancialPosition(
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
        $debtResult = $this->calculate(opening: $debtOpening);
        $this->assertGreaterThan(0, $debtResult->fields['is_interest']);
        $this->assertGreaterThan(0, $debtResult->fields['cf_borrow_repay']);
        $this->assertLessThan(4000000.0, $debtResult->fields['n5_balance']);

        $opening = new OpeningFinancialPosition(
            cash: 100000.0,
            accountsReceivable: 1000000.0,
            inventory: 500000.0,
            accountsPayable: 250000.0,
            debt: 0.0,
            retainedEarnings: 750000.0,
            accumulatedDepreciation: 100000.0,
            plantInvestment: 100000000.0,
            corporateCapitalization: 100325000.0,
            ppeComponents: new PpeComponentAllocation(50000000.0, 12500000.0, 37500000.0),
            warrantyReserve: 100000.0,
            serviceLevelAccrual: 25000.0,
            excisePayable: 50000.0,
        );
        $result = $this->calculate(opening: $opening);

        $this->assertGreaterThan(0, $result->fields['cf_ar_chg']);
        $this->assertLessThan(0, $result->fields['cf_inv_chg']);
        $this->assertGreaterThan(0, $result->fields['cf_ap_chg']);
        $this->assertSame(0.08, $result->fields['n5_rate']);
        $this->assertEqualsWithDelta(max(0, $result->intermediates['operating_income'] - $result->fields['is_interest']) * 0.25, $result->fields['is_tax'], 0.01);
    }

    public function test_quarter_two_carries_every_opening_balance_and_reacts_to_mutation(): void
    {
        $quarterOne = $this->calculate();
        $opening = OpeningFinancialPosition::fromPriorEngineResult($quarterOne->fields);
        $quarterTwo = $this->calculate(opening: $opening);

        $this->assertSame($quarterOne->fields['bs_cash'], $quarterTwo->fields['cf_beg_cash']);
        $this->assertSame($quarterOne->fields['bs_ar'], $opening->accountsReceivable);
        $this->assertSame($quarterOne->fields['bs_inventory'], $opening->inventory);
        $this->assertSame($quarterOne->fields['bs_ap'], $opening->accountsPayable);
        $this->assertSame($quarterOne->fields['bs_curr_ltd'] + $quarterOne->fields['bs_ltd'], $opening->debt);
        $this->assertSame($quarterOne->fields['bs_retained'] + $quarterOne->fields['bs_curr_ni'], $opening->retainedEarnings);
        $this->assertSame($quarterOne->fields['n4_mfg_gross'], $opening->ppeComponents->manufacturing);
        $this->assertSame($quarterOne->fields['n4_tech_gross'], $opening->ppeComponents->technology);
        $this->assertSame($quarterOne->fields['n4_dc_gross'], $opening->ppeComponents->distributionCenter);
        $this->assertSame($quarterOne->fields['bs_accum_depr'], $opening->accumulatedDepreciation);
        $this->assertSame($quarterOne->fields['bs_warranty_res'], $opening->warrantyReserve);
        $this->assertSame($quarterOne->fields['bs_sl_accrual'], $opening->serviceLevelAccrual);
        $this->assertSame($quarterOne->fields['bs_excise'], $opening->excisePayable);

        $mutated = new OpeningFinancialPosition(
            cash: $opening->cash + 12345.0,
            accountsReceivable: $opening->accountsReceivable,
            inventory: $opening->inventory,
            accountsPayable: $opening->accountsPayable,
            debt: $opening->debt,
            retainedEarnings: $opening->retainedEarnings,
            accumulatedDepreciation: $opening->accumulatedDepreciation,
            plantInvestment: $opening->plantInvestment,
            corporateCapitalization: $opening->corporateCapitalization + 12345.0,
            ppeComponents: $opening->ppeComponents,
            warrantyReserve: $opening->warrantyReserve,
            serviceLevelAccrual: $opening->serviceLevelAccrual,
            excisePayable: $opening->excisePayable,
        );
        $mutatedQuarterTwo = $this->calculate(opening: $mutated);
        $this->assertNotSame($quarterTwo->fields['bs_cash'], $mutatedQuarterTwo->fields['bs_cash']);
    }

    public function test_complete_fixture_chains_three_quarters_and_reconciles_public_statements(): void
    {
        $firstRun = $this->calculateChainedQuarters();
        $secondRun = $this->calculateChainedQuarters();

        $expected = [
            1 => [
                'assets' => 155764159.62,
                'liabilities' => 8161962.9,
                'equity' => 147602196.72,
                'net_revenue' => 110923550.0,
                'operating_income' => 63336262.3,
                'net_income' => 47502196.72,
                'bs_cash' => 35566893.82,
                'n5_balance' => 0.0,
                'retained_earnings' => 47502196.72,
            ],
            2 => [
                'assets' => 203248543.84,
                'liabilities' => 8161962.9,
                'equity' => 195086580.94,
                'net_revenue' => 110923550.0,
                'operating_income' => 63312512.3,
                'net_income' => 47484384.22,
                'bs_cash' => 84618778.04,
                'n5_balance' => 0.0,
                'retained_earnings' => 94986580.94,
            ],
            3 => [
                'assets' => 250715115.56,
                'liabilities' => 8161962.9,
                'equity' => 242553152.66,
                'net_revenue' => 110923550.0,
                'operating_income' => 63288762.3,
                'net_income' => 47466571.72,
                'bs_cash' => 133676599.76,
                'n5_balance' => 0.0,
                'retained_earnings' => 142453152.66,
            ],
        ];

        foreach ($firstRun as $quarter => $result) {
            $difference = round($result->balanceSheet['assets'] - ($result->balanceSheet['liabilities'] + $result->balanceSheet['equity']), 2);

            $this->assertEqualsWithDelta(0.0, $difference, 0.01, "Q{$quarter} balance sheet identity");
            $this->assertEqualsWithDelta($expected[$quarter]['assets'], $result->balanceSheet['assets'], 0.01, "Q{$quarter} assets");
            $this->assertEqualsWithDelta($expected[$quarter]['liabilities'], $result->balanceSheet['liabilities'], 0.01, "Q{$quarter} liabilities");
            $this->assertEqualsWithDelta($expected[$quarter]['equity'], $result->balanceSheet['equity'], 0.01, "Q{$quarter} equity");
            $this->assertEqualsWithDelta($expected[$quarter]['net_revenue'], $result->incomeStatement['net_revenue'], 0.01, "Q{$quarter} net revenue");
            $this->assertEqualsWithDelta($expected[$quarter]['operating_income'], $result->incomeStatement['operating_income'], 0.01, "Q{$quarter} operating income");
            $this->assertEqualsWithDelta($expected[$quarter]['net_income'], $result->incomeStatement['net_income'], 0.01, "Q{$quarter} net income");
            $this->assertEqualsWithDelta($expected[$quarter]['bs_cash'], $result->fields['bs_cash'], 0.01, "Q{$quarter} ending cash");
            $this->assertEqualsWithDelta($expected[$quarter]['n5_balance'], $result->fields['n5_balance'], 0.01, "Q{$quarter} ending debt");
            $this->assertEqualsWithDelta($expected[$quarter]['retained_earnings'], $result->fields['bs_retained'] + $result->fields['bs_curr_ni'], 0.01, "Q{$quarter} retained earnings");
            $this->assertSame($result->fields, $secondRun[$quarter]->fields, "Q{$quarter} deterministic repeat");
        }
    }

    public function test_chained_cash_flow_uses_public_two_decimal_statement_line_items(): void
    {
        $quarters = $this->calculateChainedQuarters();
        $quarterThree = $quarters[3];
        $fields = $quarterThree->fields;

        $operatingCashFlow = $fields['cf_net_income']
            + $fields['cf_depr']
            + $fields['cf_rou']
            + $fields['cf_lifo']
            - $fields['cf_ar_chg']
            - $fields['cf_inv_chg']
            - $fields['cf_prepaid_chg']
            + $fields['cf_ap_chg']
            + $fields['cf_accrued_chg']
            + $fields['cf_warranty_chg']
            + $fields['cf_def_rev_chg']
            + $fields['cf_excise_chg'];
        $endingCash = $fields['cf_beg_cash']
            + $operatingCashFlow
            - $fields['cf_capex_mfg']
            - $fields['cf_capex_tech']
            - $fields['cf_capex_dc']
            + $fields['cf_disposal']
            + $fields['cf_borrow_proc']
            - $fields['cf_borrow_repay']
            - $fields['cf_lease_pmt']
            + $fields['cf_equity'];

        $this->assertEqualsWithDelta(133676599.76, $fields['bs_cash'], 0.01);
        $this->assertEqualsWithDelta($endingCash, $fields['bs_cash'], 0.01);
        $this->assertEqualsWithDelta(0.0, round($quarterThree->balanceSheet['assets'] - ($quarterThree->balanceSheet['liabilities'] + $quarterThree->balanceSheet['equity']), 2), 0.01);
    }

    public function test_monetary_statement_lines_round_only_after_full_precision_calculation(): void
    {
        $parameters = $this->parameters();
        $decisions = $this->decisions();
        $decisions['sourcing']['primary_alloc'] = 0.733333333333;
        $decisions['sourcing']['secondary_supplier'] = 'SUP002';
        $decisions['forecasting'] = array_merge($decisions['forecasting'], [
            'forecast_p1_r1' => 333,
            'forecast_p1_r2' => 111,
            'forecast_p1_r3' => 57,
            'forecast_p2_r1' => 0,
            'forecast_p2_r2' => 0,
            'forecast_p2_r3' => 0,
        ]);
        $decisions['production']['p1_production'] = 1001;
        $decisions['production']['p2_production'] = 0;

        $result = $this->calculate($decisions, $parameters);
        $blendedCost = (120 * 0.733333333333) + (195 * (1 - 0.733333333333));
        $unitCost = $blendedCost + 45 + (2375000 / 1001);
        $endingUnits = 500.0;

        $this->assertEqualsWithDelta(round(1001 * $blendedCost, 2), $result->fields['is_raw_mat'], 0.01);
        $this->assertEqualsWithDelta(round($endingUnits * $unitCost, 2), $result->fields['is_end_inv'], 0.01);
        $this->assertNotEquals(round($endingUnits * round($unitCost, 2), 2), $result->fields['is_end_inv']);
        $this->assertEqualsWithDelta($result->fields['is_p1_rev'] + $result->fields['is_p2_rev'] - $result->fields['is_returns'] - $result->fields['is_rebates'], $result->incomeStatement['net_revenue'], 0.01);
    }

    public function test_non_monetary_ratios_retain_precision_while_statement_totals_use_displayed_money(): void
    {
        $result = $this->calculate();
        $fields = $result->fields;

        $this->assertSame(0.5709902207421237, $fields['n5_cov_margin']);
        $this->assertSame(7.021339146248755, $fields['n5_cov_curr']);
        $this->assertSame(35.56689382, $fields['n5_cov_cash']);
        $this->assertNotSame(round($fields['n5_cov_margin'], 2), $fields['n5_cov_margin']);

        $this->assertEqualsWithDelta(
            $fields['is_p1_rev'] + $fields['is_p2_rev'] - $fields['is_returns'] - $fields['is_rebates'],
            $result->incomeStatement['net_revenue'],
            0.01,
        );
        $this->assertEqualsWithDelta(
            $fields['is_raw_mat'] + $fields['is_labor'] + $fields['is_overhead'] + $fields['is_excise_cogs'] - $fields['is_end_inv'] + $fields['is_lifo_adj'] + $fields['is_carry'],
            $result->incomeStatement['cost_of_goods_sold'],
            0.01,
        );
    }

    public function test_working_capital_stocks_settle_prior_balances_and_reach_constant_decision_steady_state(): void
    {
        $quarters = $this->calculateChainedQuarters();

        $expected = [
            1 => ['ar_open' => 0.0, 'ar_change' => 21741015.8, 'ap_open' => 0.0, 'ap_change' => 6660000.0, 'war_open' => 0.0, 'war_paid' => 443694.2, 'war_change' => 665541.3, 'sl_open' => 0.0, 'sl_paid' => 55500.0, 'sl_change' => 55500.0, 'exc_open' => 0.0, 'exc_paid' => 3123686.4, 'exc_change' => 780921.6],
            2 => ['ar_open' => 21741015.8, 'ar_change' => 0.0, 'ap_open' => 6660000.0, 'ap_change' => 0.0, 'war_open' => 665541.3, 'war_paid' => 1109235.5, 'war_change' => 0.0, 'sl_open' => 55500.0, 'sl_paid' => 111000.0, 'sl_change' => 0.0, 'exc_open' => 780921.6, 'exc_paid' => 3904608.0, 'exc_change' => 0.0],
            3 => ['ar_open' => 21741015.8, 'ar_change' => 0.0, 'ap_open' => 6660000.0, 'ap_change' => 0.0, 'war_open' => 665541.3, 'war_paid' => 1109235.5, 'war_change' => 0.0, 'sl_open' => 55500.0, 'sl_paid' => 111000.0, 'sl_change' => 0.0, 'exc_open' => 780921.6, 'exc_paid' => 3904608.0, 'exc_change' => 0.0],
        ];

        foreach ($quarters as $quarter => $result) {
            $fields = $result->fields;
            $this->assertEqualsWithDelta($expected[$quarter]['ar_open'], $fields['bs_ar'] - $fields['cf_ar_chg'], 0.01, "Q{$quarter} AR opening");
            $this->assertEqualsWithDelta($expected[$quarter]['ar_change'], $fields['cf_ar_chg'], 0.01, "Q{$quarter} AR movement");
            $this->assertEqualsWithDelta(21741015.8, $fields['bs_ar'], 0.01, "Q{$quarter} AR ending");
            $this->assertEqualsWithDelta($expected[$quarter]['ap_open'], $fields['bs_ap'] - $fields['cf_ap_chg'], 0.01, "Q{$quarter} AP opening");
            $this->assertEqualsWithDelta($expected[$quarter]['ap_change'], $fields['cf_ap_chg'], 0.01, "Q{$quarter} AP movement");
            $this->assertEqualsWithDelta(6660000.0, $fields['bs_ap'], 0.01, "Q{$quarter} AP ending");
            $this->assertEqualsWithDelta($expected[$quarter]['war_open'], $fields['n6_war_open'], 0.01, "Q{$quarter} warranty opening");
            $this->assertEqualsWithDelta($expected[$quarter]['war_paid'], $fields['n6_war_paid'], 0.01, "Q{$quarter} warranty paid");
            $this->assertEqualsWithDelta($expected[$quarter]['war_change'], $fields['cf_warranty_chg'], 0.01, "Q{$quarter} warranty movement");
            $this->assertEqualsWithDelta($expected[$quarter]['sl_open'], $fields['n6_sl_open'], 0.01, "Q{$quarter} SL opening");
            $this->assertEqualsWithDelta($expected[$quarter]['sl_paid'], $fields['n6_sl_paid'], 0.01, "Q{$quarter} SL paid");
            $this->assertEqualsWithDelta($expected[$quarter]['sl_change'], $fields['cf_accrued_chg'], 0.01, "Q{$quarter} SL movement");
            $this->assertEqualsWithDelta($expected[$quarter]['exc_open'], $fields['n6_exc_open'], 0.01, "Q{$quarter} excise opening");
            $this->assertEqualsWithDelta($expected[$quarter]['exc_paid'], $fields['n6_exc_paid'], 0.01, "Q{$quarter} excise paid");
            $this->assertEqualsWithDelta($expected[$quarter]['exc_change'], $fields['cf_excise_chg'], 0.01, "Q{$quarter} excise movement");
        }
    }

    public function test_inventory_units_roll_from_production_less_sales_to_ending_value(): void
    {
        $decisions = $this->decisions();
        $decisions['forecasting'] = array_merge($decisions['forecasting'], [
            'forecast_p1_r1' => 1000,
            'forecast_p1_r2' => 0,
            'forecast_p1_r3' => 0,
            'forecast_p2_r1' => 0,
            'forecast_p2_r2' => 0,
            'forecast_p2_r3' => 0,
        ]);
        $decisions['production']['p1_production'] = 2000;
        $decisions['production']['p2_production'] = 0;

        $result = $this->calculate($decisions);
        $endingUnits = 1000.0;
        $unitCost = 120 + 45 + (2375000 / 2000);

        $this->assertEqualsWithDelta(2000.0 - 1000.0, $endingUnits, 0.01);
        $this->assertEqualsWithDelta(round($endingUnits * $unitCost, 2), $result->fields['bs_inventory'], 0.01);
        $this->assertEqualsWithDelta($result->fields['bs_inventory'], $result->fields['cf_inv_chg'], 0.01);
    }

    public function test_randomized_complete_decision_chains_reconcile_identities(): void
    {
        $seed = 20260725;
        mt_srand($seed);
        $cases = 300;
        $quartersPerCase = 4;
        $counts = ['calls' => 0, 'losses' => 0, 'debt_draws' => 0, 'limit_binding' => 0, 'overdrafts' => 0, 'negative_retained' => 0, 'negative_cash_movement' => 0];

        for ($case = 1; $case <= $cases; $case++) {
            $parameters = $this->randomizedParameters();
            $decisions = $this->randomizedDecisions();
            $opening = OpeningFinancialPosition::fromScenarioParameters($parameters);

            foreach (['price_p1_r1', 'price_p1_r2', 'price_p1_r3'] as $field) {
                $this->assertGreaterThanOrEqual(210.0, $decisions['pricing'][$field]);
                $this->assertLessThanOrEqual(380.0, $decisions['pricing'][$field]);
            }
            foreach (['price_p2_r1', 'price_p2_r2', 'price_p2_r3'] as $field) {
                $this->assertGreaterThanOrEqual(280.0, $decisions['pricing'][$field]);
                $this->assertLessThanOrEqual(455.0, $decisions['pricing'][$field]);
            }

            for ($quarter = 1; $quarter <= $quartersPerCase; $quarter++) {
                try {
                    $result = (new ManufacturingCFEngine)->calculate(new ManufacturingEngineInput($quarter, $decisions, $parameters, $opening));
                } catch (\Throwable $exception) {
                    $this->fail("Seed {$seed} case {$case} quarter {$quarter} failed: {$exception->getMessage()} input=".json_encode(['parameters' => $parameters, 'decisions' => $decisions], JSON_THROW_ON_ERROR));
                }

                foreach ($result->fields as $field => $value) {
                    $this->assertTrue(is_finite($value), "Seed {$seed} case {$case} quarter {$quarter} {$field} is not finite");
                }

                $inputSnapshot = json_encode(['parameters' => $parameters, 'decisions' => $decisions], JSON_THROW_ON_ERROR);
                $this->assertSame(0.0, round($result->balanceSheet['assets'] - ($result->balanceSheet['liabilities'] + $result->balanceSheet['equity']), 2), "Seed {$seed} case {$case} quarter {$quarter} balance identity input={$inputSnapshot}");
                $this->assertSame(0.0, round(($result->fields['bs_cash'] - $opening->cash) - ($result->cashFlowStatement['operating_cash_flow'] + $result->cashFlowStatement['investing_cash_flow'] + $result->cashFlowStatement['financing_cash_flow']), 2), "Seed {$seed} case {$case} quarter {$quarter} cash identity input={$inputSnapshot}");
                $this->assertSame(0.0, round(($result->fields['bs_inventory'] - $opening->inventory) - $result->fields['cf_inv_chg'], 2), "Seed {$seed} case {$case} quarter {$quarter} inventory identity input={$inputSnapshot}");
                foreach (['bs_ar', 'bs_inventory', 'bs_ppe_gross', 'bs_accum_depr', 'bs_ap', 'bs_warranty_res', 'bs_sl_accrual', 'bs_excise', 'n5_balance'] as $field) {
                    $this->assertGreaterThanOrEqual(0.0, $result->fields[$field], "Seed {$seed} case {$case} quarter {$quarter} {$field} negative");
                }

                $counts['calls']++;
                $counts['losses'] += (int) ($result->incomeStatement['net_income'] < 0.0);
                $counts['debt_draws'] += (int) ($result->fields['cf_borrow_proc'] > 0.01);
                $counts['limit_binding'] += (int) ($result->fields['cf_borrow_proc'] > 0.01 && abs($result->fields['cf_borrow_proc'] - $result->fields['n5_limit']) <= 0.01);
                $counts['overdrafts'] += (int) ($result->fields['bs_bank_overdraft'] > 0.01);
                $counts['negative_retained'] += (int) (($result->fields['bs_retained'] + $result->fields['bs_curr_ni']) < 0.0);
                $counts['negative_cash_movement'] += (int) (($result->fields['bs_cash'] - $opening->cash) < 0.0);
                $this->assertGreaterThanOrEqual(0.0, $result->fields['bs_cash']);
                if ($result->fields['bs_bank_overdraft'] > 0.01) {
                    $this->assertEqualsWithDelta($result->fields['n5_limit'], $result->fields['cf_borrow_proc'], 0.01, "Seed {$seed} case {$case} quarter {$quarter} negative cash without exhausting the current-quarter borrowing limit");
                    $this->assertSame(1.0, $result->fields['n5_shortfall_indicator']);
                    $this->assertEqualsWithDelta($result->fields['bs_bank_overdraft'], $result->fields['n5_overdraft'], 0.01);
                }

                $opening = OpeningFinancialPosition::fromPriorEngineResult($result->fields);
            }
        }

        $this->assertSame(['calls' => 1200, 'losses' => 344, 'debt_draws' => 432, 'limit_binding' => 41, 'overdrafts' => 37, 'negative_retained' => 340, 'negative_cash_movement' => 18], $counts);
    }

    public function test_ppe_scaling_component_proportions_and_removed_cwip_are_explicit(): void
    {
        $opening = OpeningFinancialPosition::fromScenarioParameters($this->parameters());
        $this->assertSame(50000000.0, $opening->ppeComponents->manufacturing);
        $this->assertSame(12500000.0, $opening->ppeComponents->technology);
        $this->assertSame(37500000.0, $opening->ppeComponents->distributionCenter);
        $this->assertSame(100000000.0, $opening->ppeComponents->total());
        $this->assertSame(4.0, $opening->ppeComponents->manufacturing / $opening->ppeComponents->technology);
        $this->assertSame(3.0, $opening->ppeComponents->distributionCenter / $opening->ppeComponents->technology);
        $this->assertArrayNotHasKey('n4_cwip', $this->calculate()->fields);
    }

    public function test_bad_debt_is_operating_expense_without_changing_taxable_income_or_net_income(): void
    {
        $result = $this->calculate();

        $this->assertEqualsWithDelta(443694.20, $result->fields['is_other'], 0.01);
        $this->assertEqualsWithDelta(443694.20, $result->incomeStatement['bad_debt_expense'], 0.01);

        $operatingExpensesWithoutBadDebt = $result->fields['is_marketing']
            + $result->fields['is_freight']
            + $result->fields['is_sl_penalties']
            + $result->fields['is_excise']
            + $result->fields['is_inspection']
            + $result->fields['is_admin']
            + $result->fields['is_warranty'];

        $this->assertEqualsWithDelta($operatingExpensesWithoutBadDebt + 443694.20, $result->incomeStatement['operating_expenses'], 0.01);
        $this->assertEqualsWithDelta($result->incomeStatement['gross_profit'] - $operatingExpensesWithoutBadDebt - 443694.20, $result->incomeStatement['operating_income'], 0.01);
        $this->assertEqualsWithDelta($result->incomeStatement['operating_income'] - $result->fields['is_interest'], $result->fields['cf_net_income'] + $result->fields['is_tax'], 0.01);
        $this->assertEqualsWithDelta(47502196.72, $result->incomeStatement['net_income'], 0.01);
    }

    public function test_depreciation_classification_amendment_places_each_component_once(): void
    {
        $result = $this->calculate();

        $this->assertEqualsWithDelta(1250000.00, $result->fields['n4_mfg_depr'], 0.01);
        $this->assertEqualsWithDelta(625000.00, $result->fields['n4_tech_depr'], 0.01);
        $this->assertEqualsWithDelta(468750.00, $result->fields['n4_dc_depr'], 0.01);

        $this->assertEqualsWithDelta(2375000.00, $result->fields['is_overhead'], 0.01);
        $this->assertEqualsWithDelta(2375000.00, $result->fields['n3_overhead'], 0.01);
        $this->assertEqualsWithDelta(1948750.00, $result->fields['is_freight'], 0.01);
        $this->assertEqualsWithDelta(1125000.00, $result->fields['is_admin'], 0.01);

        $this->assertEqualsWithDelta(2343750.00, $result->fields['bs_accum_depr'], 0.01);
        $this->assertEqualsWithDelta(2343750.00, $result->fields['cf_depr'], 0.01);
        $this->assertEqualsWithDelta(0.00, $result->fields['bs_tech_net'], 0.01);
    }

    public function test_amended_classifications_preserve_statement_identities(): void
    {
        $result = $this->calculate();

        $this->assertEqualsWithDelta($result->balanceSheet['assets'], $result->balanceSheet['liabilities'] + $result->balanceSheet['equity'], 0.01);
        $this->assertEqualsWithDelta($result->fields['bs_inventory'], $result->fields['cf_inv_chg'], 0.01);
        $this->assertEqualsWithDelta($result->cashFlowStatement['ending_cash'], $result->fields['bs_cash'], 0.01);
        $this->assertEqualsWithDelta(0.0, $result->fields['n5_balance'], 0.01);
    }

    public function test_all_categories_and_each_field_are_mandatory(): void
    {
        $decisions = $this->decisions();
        unset($decisions['forecasting']);
        $this->expectException(EngineCalculationException::class);
        $this->expectExceptionMessage('Missing complete decision category [forecasting].');
        $this->calculate($decisions);
    }

    public function test_missing_required_field_fails_clearly(): void
    {
        $decisions = $this->decisions();
        unset($decisions['notes_elections']['shipping_terms']);
        $this->expectException(EngineCalculationException::class);
        $this->expectExceptionMessage('Missing required decision field [notes_elections.shipping_terms].');
        $this->calculate($decisions);
    }

    public function test_engine_does_not_require_obsolete_regional_base_demand_compatibility_parameters(): void
    {
        $parameters = $this->parameters();
        unset($parameters['base_demand_r1'], $parameters['base_demand_r2'], $parameters['base_demand_r3']);

        $result = $this->calculate(parameters: $parameters);
        $this->assertSame(62500000.0, $result->fields['is_p1_rev']);
        $this->assertSame(51000000.0, $result->fields['is_p2_rev']);
    }

    /**
     * @param  array<string, array<string, mixed>>|null  $decisions
     * @param  array<string, mixed>|null  $parameters
     */
    private function calculate(?array $decisions = null, ?array $parameters = null, ?OpeningFinancialPosition $opening = null): ManufacturingEngineResult
    {
        $parameters ??= $this->parameters();

        return (new ManufacturingCFEngine)->calculate(new ManufacturingEngineInput(
            quarterNumber: 1,
            decisions: $decisions ?? $this->decisions(),
            parameters: $parameters,
            openingPosition: $opening ?? OpeningFinancialPosition::fromScenarioParameters($parameters),
        ));
    }

    /**
     * @return array<int, ManufacturingEngineResult>
     */
    private function calculateChainedQuarters(): array
    {
        $parameters = $this->parameters();
        $decisions = $this->decisions();
        $opening = OpeningFinancialPosition::fromScenarioParameters($parameters);
        $engine = new ManufacturingCFEngine;
        $results = [];

        for ($quarter = 1; $quarter <= 3; $quarter++) {
            $results[$quarter] = $engine->calculate(new ManufacturingEngineInput(
                quarterNumber: $quarter,
                decisions: $decisions,
                parameters: $parameters,
                openingPosition: $opening,
            ));
            $opening = OpeningFinancialPosition::fromPriorEngineResult($results[$quarter]->fields);
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    private function parameters(): array
    {
        $parameters = CfManufacturingScenarioDefinition::parameters();
        // This worked-oracle suite intentionally preserves the historical 500/850 fixture.
        $parameters['p1_base_price'] = 500;
        $parameters['p2_base_price'] = 850;

        foreach (array_keys($parameters) as $key) {
            if (str_starts_with($key, 'starting_')) {
                unset($parameters[$key]);
            }
        }

        return $parameters;
    }

    /**
     * @return array<string, mixed>
     */
    private function randomizedParameters(): array
    {
        $parameters = $this->parameters();
        $parameters['p1_base_price'] = 270;
        $parameters['p2_base_price'] = 350;
        $parameters['tax_rate'] = $this->randomFloat(0.18, 0.32);
        $parameters['returns_rate_on_defects'] = $this->randomFloat(0.05, 0.20);
        $parameters['rebate_rate'] = $this->randomFloat(0.00, 0.04);
        $parameters['warranty_claims_rate'] = $this->randomFloat(0.10, 0.90);
        $parameters['sl_payment_rate'] = $this->randomFloat(0.20, 1.00);
        $parameters['excise_rate_r2'] = $this->randomFloat(0.00, 0.08);
        $parameters['excise_rate_r3'] = $this->randomFloat(0.00, 0.10);
        $parameters['excise_payment_rate'] = $this->randomFloat(0.20, 1.00);
        $parameters['borrow_repay_rate'] = $this->randomFloat(0.10, 0.50);
        $parameters['minimum_cash_buffer'] = $this->randomFloat(250000.0, 2000000.0);

        return $parameters;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function randomizedDecisions(): array
    {
        $suppliers = ['SUP001', 'SUP002', 'SUP003', 'SUP004', 'SUP005', 'SUP006'];
        $primary = $suppliers[mt_rand(0, count($suppliers) - 1)];
        $secondaryOptions = array_values(array_diff(['NONE', ...$suppliers], [$primary]));

        return [
            'forecasting' => [
                'forecast_p1_r1' => mt_rand(0, 90000),
                'forecast_p1_r2' => mt_rand(0, 90000),
                'forecast_p1_r3' => mt_rand(0, 90000),
                'forecast_p2_r1' => mt_rand(0, 70000),
                'forecast_p2_r2' => mt_rand(0, 70000),
                'forecast_p2_r3' => mt_rand(0, 70000),
                'forecast_confidence' => ['low', 'medium', 'high'][mt_rand(0, 2)],
            ],
            'sourcing' => [
                'primary_supplier' => $primary,
                'primary_alloc' => $this->randomFloat(0.50, 1.00),
                'secondary_supplier' => $secondaryOptions[mt_rand(0, count($secondaryOptions) - 1)],
            ],
            'pricing' => [
                'price_p1_r1' => $this->randomFloat(210.0, 380.0),
                'price_p1_r2' => $this->randomFloat(210.0, 380.0),
                'price_p1_r3' => $this->randomFloat(210.0, 380.0),
                'price_p2_r1' => $this->randomFloat(280.0, 455.0),
                'price_p2_r2' => $this->randomFloat(280.0, 455.0),
                'price_p2_r3' => $this->randomFloat(280.0, 455.0),
                'discount_policy' => ['none', 'rebate'][mt_rand(0, 1)],
            ],
            'production' => [
                'p1_production' => mt_rand(0, 260000),
                'p2_production' => mt_rand(0, 180000),
                'shifts' => mt_rand(1, 3),
                'postponed_flag' => ['YES', 'NO'][mt_rand(0, 1)],
                'postponed_pct' => $this->randomFloat(0.00, 0.50),
            ],
            'marketing' => [
                'marketing_budget_pct' => $this->randomFloat(0.00, 0.12),
                'marketing_alloc_champions' => $this->randomFloat(0.20, 0.60),
                'marketing_alloc_growth' => $this->randomFloat(0.10, 0.40),
                'marketing_alloc_at_risk' => $this->randomFloat(0.05, 0.30),
                'marketing_alloc_other' => $this->randomFloat(0.00, 0.20),
            ],
            'quality' => [
                'inspection_level' => ['NONE', 'BASIC', 'FULL'][mt_rand(0, 2)],
            ],
            'logistics' => [
                'logistics_mode' => ['STANDARD', 'EXPRESS', 'AIR'][mt_rand(0, 2)],
            ],
            'notes_elections' => [
                'inventory_method' => ['fifo', 'weighted_average', 'lifo'][mt_rand(0, 2)],
                'depreciation_method' => 'straight_line',
                'shipping_terms' => 'fob_shipping_point',
                'bad_debt_method' => ['percentage_of_receivables', 'percentage_of_sales'][mt_rand(0, 1)],
                'warranty_method' => ['percentage_of_sales', 'expense_as_incurred'][mt_rand(0, 1)],
                'excise_tax_treatment' => ['cogs_inclusion', 'separate_operating_expense'][mt_rand(0, 1)],
            ],
            'certification' => [
                'attestation_scope' => 'all_statements_attested',
            ],
        ];
    }

    private function randomFloat(float $min, float $max): float
    {
        return $min + (($max - $min) * (mt_rand() / mt_getrandmax()));
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
                'price_p1_r1' => 500,
                'price_p1_r2' => 500,
                'price_p1_r3' => 500,
                'price_p2_r1' => 850,
                'price_p2_r2' => 850,
                'price_p2_r3' => 850,
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
