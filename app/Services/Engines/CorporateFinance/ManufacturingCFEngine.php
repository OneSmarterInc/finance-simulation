<?php

namespace App\Services\Engines\CorporateFinance;

use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngine;
use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngineInput;
use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngineResult;
use App\Services\Engines\CorporateFinance\Exceptions\EngineCalculationException;

final class ManufacturingCFEngine implements ManufacturingEngine
{
    public const FIELD_KEYS = [
        'is_p1_rev', 'is_p2_rev', 'is_returns', 'is_rebates', 'is_raw_mat', 'is_labor', 'is_overhead', 'is_end_inv',
        'is_lifo_adj', 'is_carry', 'is_excise_cogs', 'is_marketing', 'is_freight', 'is_sl_penalties', 'is_excise', 'is_inspection',
        'is_admin', 'is_warranty', 'is_interest', 'is_disposal', 'is_other', 'is_tax',
        'bs_cash', 'bs_ar', 'bs_inventory', 'bs_prepaid', 'bs_ppe_gross', 'bs_accum_depr', 'bs_rou', 'bs_tech_net',
        'bs_ap', 'bs_wages', 'bs_mkt', 'bs_warranty_res', 'bs_sl_accrual', 'bs_excise', 'bs_curr_ltd', 'bs_def_rev',
        'bs_ltd', 'bs_lease', 'bs_dtl', 'bs_bank_overdraft', 'bs_common', 'bs_retained', 'bs_curr_ni',
        'cf_net_income', 'cf_depr', 'cf_rou', 'cf_lifo', 'cf_ar_chg', 'cf_inv_chg', 'cf_prepaid_chg', 'cf_ap_chg',
        'cf_accrued_chg', 'cf_warranty_chg', 'cf_def_rev_chg', 'cf_excise_chg', 'cf_capex_mfg', 'cf_capex_tech',
        'cf_capex_dc', 'cf_disposal', 'cf_borrow_proc', 'cf_borrow_repay', 'cf_lease_pmt', 'cf_equity', 'cf_beg_cash',
        'cf_cash_interest', 'cf_cash_tax', 'cf_overdraft_chg',
        'n2_r1_p1', 'n2_r1_p2', 'n2_r2_p1', 'n2_r2_p2', 'n2_r3_p1', 'n2_r3_p2', 'n2_rebate_open',
        'n2_rebate_added', 'n2_rebate_paid',
        'n3_raw_mat', 'n3_wip', 'n3_fg', 'n3_in_transit', 'n3_lifo_res', 'n3_lifo_chg', 'n3_overhead',
        'n4_mfg_gross', 'n4_mfg_depr', 'n4_mfg_net', 'n4_tech_gross', 'n4_tech_depr', 'n4_tech_net', 'n4_dc_gross',
        'n4_dc_depr', 'n4_dc_net', 'n4_disposal_nbv', 'n4_disposal_proc', 'n4_disposal_gain', 'n4_util_rate',
        'n5_balance', 'n5_rate', 'n5_limit', 'n5_auto_draw', 'n5_draw_rate', 'n5_overdraft', 'n5_shortfall_indicator', 'n5_mat_y1', 'n5_mat_y2', 'n5_mat_beyond',
        'n5_cov_curr', 'n5_cov_de', 'n5_cov_margin', 'n5_cov_interest', 'n5_cov_cash', 'n5_int_recon',
        'n6_supplier', 'n6_po_fob', 'n6_lease', 'n6_war_open', 'n6_war_exp', 'n6_war_paid', 'n6_war_close',
        'n6_sl_open', 'n6_sl_exp', 'n6_sl_paid', 'n6_sl_close', 'n6_exc_open', 'n6_exc_acc', 'n6_exc_paid', 'n6_exc_close',
    ];

    private const NON_MONETARY_FIELD_KEYS = [
        'n4_util_rate',
        'n5_rate',
        'n5_draw_rate',
        'n5_cov_curr',
        'n5_cov_de',
        'n5_cov_margin',
        'n5_cov_interest',
        'n5_cov_cash',
        'n5_shortfall_indicator',
        'n6_po_fob',
    ];

    public function __construct(
        private readonly DecisionAdapter $decisionAdapter = new DecisionAdapter,
        private readonly AccountingIdentityValidator $validator = new AccountingIdentityValidator,
        private readonly DividendDecisionValidator $dividendValidator = new DividendDecisionValidator,
    ) {}

    public function calculate(ManufacturingEngineInput $input): ManufacturingEngineResult
    {
        $this->validateDecisionFields($input->decisions);

        $context = [
            'input' => $input,
            'decisions' => $this->decisionAdapter->adapt($input->decisions),
            'computed' => [],
        ];

        $partial = array_merge(
            $this->calculateDemandAndRevenue($context),
            $this->calculateCogsAndInventory($context),
            $this->calculateOperatingExpensesAndWarranty($context),
            $this->calculateBelowTheLine($context),
        );
        $publishedStatement = $this->incomeStatement(
            $this->normalize($partial),
            $this->requiredNumber($input->parameters, 'tax_rate'),
        );
        $context['computed']['is_tax'] = $publishedStatement['income_tax_expense'];
        $context['computed']['net_income'] = $publishedStatement['net_income'];
        $context['published_income_tax_expense'] = $publishedStatement['income_tax_expense'];
        $context['published_net_income'] = $publishedStatement['net_income'];

        $fields = $this->normalize(array_merge(
            $partial,
            ['is_tax' => $publishedStatement['income_tax_expense']],
            $this->calculateBalanceSheetAndPpeBeforeCash($context),
            $this->calculateCashFlowAndDebt($context),
        ));

        $this->validator->validate($fields, $input->openingPosition);

        return new ManufacturingEngineResult(
            fields: $fields,
            incomeStatement: $this->incomeStatement($fields, $this->requiredNumber($input->parameters, 'tax_rate')),
            balanceSheet: $this->balanceSheet($fields),
            cashFlowStatement: $this->cashFlowStatement($fields),
            notes: $this->notes($fields),
            intermediates: $context['computed'],
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, float>
     */
    private function calculateDemandAndRevenue(array &$context): array
    {
        $decisions = $context['decisions'];
        $parameters = $context['input']->parameters;
        $forecasting = $decisions['forecasting'];
        $pricing = $decisions['pricing'];
        $production = $decisions['production'];

        $p1BasePrice = $this->requiredNumber($parameters, 'p1_base_price');
        $p2BasePrice = $this->requiredNumber($parameters, 'p2_base_price');
        $elasticity = $this->requiredNumber($parameters, 'price_elasticity');

        $p1Demand = [
            'r1' => $this->elasticDemand($this->number($forecasting, 'forecast_p1_r1'), $this->number($pricing, 'price_p1_r1'), $p1BasePrice, $elasticity),
            'r2' => $this->elasticDemand($this->number($forecasting, 'forecast_p1_r2'), $this->number($pricing, 'price_p1_r2'), $p1BasePrice, $elasticity),
            'r3' => $this->elasticDemand($this->number($forecasting, 'forecast_p1_r3'), $this->number($pricing, 'price_p1_r3'), $p1BasePrice, $elasticity),
        ];
        $p2Demand = [
            'r1' => $this->elasticDemand($this->number($forecasting, 'forecast_p2_r1'), $this->number($pricing, 'price_p2_r1'), $p2BasePrice, $elasticity),
            'r2' => $this->elasticDemand($this->number($forecasting, 'forecast_p2_r2'), $this->number($pricing, 'price_p2_r2'), $p2BasePrice, $elasticity),
            'r3' => $this->elasticDemand($this->number($forecasting, 'forecast_p2_r3'), $this->number($pricing, 'price_p2_r3'), $p2BasePrice, $elasticity),
        ];

        $p1Production = min($this->number($production, 'p1_production'), $this->productionCapacity($production, $parameters));
        $p2Production = min($this->number($production, 'p2_production'), max(0, $this->productionCapacity($production, $parameters) - $p1Production));
        $p1Sold = min(array_sum($p1Demand), $p1Production);
        $p2Sold = min(array_sum($p2Demand), $p2Production);
        $p1RegionalSold = $this->allocateRegionalSold($p1Demand, $p1Sold);
        $p2RegionalSold = $this->allocateRegionalSold($p2Demand, $p2Sold);

        $n2R1P1 = $p1RegionalSold['r1'] * $this->number($pricing, 'price_p1_r1');
        $n2R2P1 = $p1RegionalSold['r2'] * $this->number($pricing, 'price_p1_r2');
        $n2R3P1 = $p1RegionalSold['r3'] * $this->number($pricing, 'price_p1_r3');
        $n2R1P2 = $p2RegionalSold['r1'] * $this->number($pricing, 'price_p2_r1');
        $n2R2P2 = $p2RegionalSold['r2'] * $this->number($pricing, 'price_p2_r2');
        $n2R3P2 = $p2RegionalSold['r3'] * $this->number($pricing, 'price_p2_r3');
        $grossRevenue = $n2R1P1 + $n2R2P1 + $n2R3P1 + $n2R1P2 + $n2R2P2 + $n2R3P2;

        $quality = $decisions['quality'];
        $sourcing = $decisions['sourcing'];
        $catchRate = $this->requiredNumber($parameters, 'inspection_'.strtolower((string) $quality['inspection_level']).'_catch_rate');
        $defectRate = $this->requiredNumber($parameters, $this->supplierParameterKey((string) $sourcing['primary_supplier'], 'defect_rate'));
        $totalUnitsSold = $p1Sold + $p2Sold;
        $defectiveUnits = round($totalUnitsSold * $defectRate * (1 - $catchRate));
        $avgPrice = $totalUnitsSold > 0 ? $grossRevenue / $totalUnitsSold : 0.0;
        $returns = $defectiveUnits * $avgPrice * $this->requiredNumber($parameters, 'returns_rate_on_defects');
        $rebates = $grossRevenue * $this->requiredNumber($parameters, 'rebate_rate');

        $context['computed'] = array_merge($context['computed'], [
            'p1_sold' => $p1Sold,
            'p2_sold' => $p2Sold,
            'p1_unsold' => max(0, $p1Production - $p1Sold),
            'p2_unsold' => max(0, $p2Production - $p2Sold),
            'total_units_sold' => $totalUnitsSold,
            'total_production' => $p1Production + $p2Production,
            'is_p1_rev' => $n2R1P1 + $n2R2P1 + $n2R3P1,
            'is_p2_rev' => $n2R1P2 + $n2R2P2 + $n2R3P2,
            'is_returns' => $returns,
            'is_rebates' => $rebates,
            'n2_r1_p1' => $n2R1P1,
            'n2_r1_p2' => $n2R1P2,
            'n2_r2_p1' => $n2R2P1,
            'n2_r2_p2' => $n2R2P2,
            'n2_r3_p1' => $n2R3P1,
            'n2_r3_p2' => $n2R3P2,
            'n2_rebate_added' => $rebates,
        ]);

        return [
            'is_p1_rev' => $n2R1P1 + $n2R2P1 + $n2R3P1,
            'is_p2_rev' => $n2R1P2 + $n2R2P2 + $n2R3P2,
            'is_returns' => $returns,
            'is_rebates' => $rebates,
            'n2_r1_p1' => $n2R1P1,
            'n2_r1_p2' => $n2R1P2,
            'n2_r2_p1' => $n2R2P1,
            'n2_r2_p2' => $n2R2P2,
            'n2_r3_p1' => $n2R3P1,
            'n2_r3_p2' => $n2R3P2,
            'n2_rebate_added' => $rebates,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, float>
     */
    private function calculateCogsAndInventory(array &$context): array
    {
        $decisions = $context['decisions'];
        $parameters = $context['input']->parameters;
        $computed = $context['computed'];
        $production = $decisions['production'];
        $sourcing = $decisions['sourcing'];
        $notes = $decisions['notes_elections'];

        $totalProduction = $this->number($computed, 'total_production');
        $primaryCost = $this->requiredNumber($parameters, $this->supplierParameterKey((string) $sourcing['primary_supplier'], 'cost'));
        $secondarySupplier = strtoupper((string) ($sourcing['secondary_supplier'] ?? 'NONE'));
        $primaryAlloc = $this->number($sourcing, 'primary_alloc');
        $blendedCost = $secondarySupplier === 'NONE'
            ? $primaryCost
            : ($primaryCost * $primaryAlloc) + ($this->requiredNumber($parameters, $this->supplierParameterKey($secondarySupplier, 'cost')) * (1 - $primaryAlloc));

        $rawMaterials = $totalProduction * $blendedCost;
        $overtimeMultiplier = ((int) $this->number($production, 'shifts') === 3) ? 1 + $this->requiredNumber($parameters, 'overtime_premium') : 1.0;
        $baseLaborPerUnit = $this->requiredNumber($parameters, 'base_labor_per_unit');
        $labor = $totalProduction * $baseLaborPerUnit * $overtimeMultiplier;
        $depreciation = $this->periodDepreciation($notes, $context['input']->openingPosition->ppeComponents);
        $fixedManufacturingOverhead = $this->requiredNumber($parameters, 'fixed_overhead_per_period') * $this->number($notes, 'overhead_rate');
        $overhead = $fixedManufacturingOverhead + $depreciation['mfg'];
        $unitCost = $blendedCost + ($baseLaborPerUnit * $overtimeMultiplier) + (($fixedManufacturingOverhead + $depreciation['mfg']) / max($totalProduction, 1));
        $endingUnits = $this->number($computed, 'p1_unsold') + $this->number($computed, 'p2_unsold');
        $endingInventory = $endingUnits * $unitCost;
        $lifoAdjustment = (string) $notes['inv_method'] === 'LIFO' ? $endingUnits * $blendedCost * $this->requiredNumber($parameters, 'inflation_rate') : 0.0;
        $carryingCosts = $endingInventory * $this->requiredNumber($parameters, 'carrying_cost_rate');
        $capitalizedCarrying = (string) $notes['carry_cost_treat'] === 'Product Cost (Capitalize)' ? $carryingCosts : 0.0;
        $carryingExpense = $capitalizedCarrying > 0 ? 0.0 : $carryingCosts;
        $rawMatInventory = $endingUnits > 0 ? $endingUnits * $blendedCost * $this->requiredNumber($parameters, 'inventory_raw_mat_ratio') : 0.0;
        $wipInventory = (string) ($production['postponed_flag'] ?? 'NO') === 'YES' ? $endingUnits * $unitCost * $this->requiredNumber($parameters, 'inventory_wip_ratio') : 0.0;
        $inTransit = (string) $notes['proc_terms'] === 'FOB Shipping Point' ? $blendedCost * $totalProduction * $this->requiredNumber($parameters, 'inventory_in_transit_ratio') : 0.0;
        $totalCogs = $rawMaterials + $labor + $overhead - $endingInventory + $lifoAdjustment + $carryingExpense;
        $grossProfit = $this->number($computed, 'is_p1_rev') + $this->number($computed, 'is_p2_rev') - $this->number($computed, 'is_returns') - $this->number($computed, 'is_rebates') - $totalCogs;

        $context['computed'] = array_merge($computed, [
            'blended_cost' => $blendedCost,
            'ending_inventory' => $endingInventory,
            'capitalized_carrying' => $capitalizedCarrying,
            'total_cogs' => $totalCogs,
            'gross_profit' => $grossProfit,
            'n3_lifo_chg' => $lifoAdjustment,
        ]);

        return [
            'is_raw_mat' => $rawMaterials,
            'is_labor' => $labor,
            'is_overhead' => $overhead,
            'is_end_inv' => $endingInventory,
            'is_lifo_adj' => $lifoAdjustment,
            'is_carry' => $carryingExpense,
            'n3_raw_mat' => $rawMatInventory,
            'n3_wip' => $wipInventory,
            'n3_fg' => max(0, $endingInventory - $wipInventory - $rawMatInventory),
            'n3_in_transit' => $inTransit,
            'n3_lifo_res' => $lifoAdjustment,
            'n3_lifo_chg' => $lifoAdjustment,
            'n3_overhead' => $overhead,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, float>
     */
    private function calculateOperatingExpensesAndWarranty(array &$context): array
    {
        $decisions = $context['decisions'];
        $parameters = $context['input']->parameters;
        $opening = $context['input']->openingPosition;
        $computed = $context['computed'];
        $notes = $decisions['notes_elections'];

        $grossRevenue = $this->number($computed, 'is_p1_rev') + $this->number($computed, 'is_p2_rev');
        $netRevenue = $grossRevenue - $this->number($computed, 'is_returns') - $this->number($computed, 'is_rebates');
        $marketingExpense = $grossRevenue * $this->number($decisions['marketing'], 'marketing_budget_pct');
        $logisticsMode = strtolower((string) $decisions['logistics']['logistics_mode']);
        $depreciation = $this->periodDepreciation($notes, $opening->ppeComponents);
        $freight = ($this->number($computed, 'total_units_sold') * $this->requiredNumber($parameters, "logistics_{$logisticsMode}_cost")) + $depreciation['dc'];
        $lateUnits = $this->number($computed, 'total_units_sold') * (1 - $this->requiredNumber($parameters, "logistics_{$logisticsMode}_on_time_rate"));
        $slPenalties = $lateUnits * $this->requiredNumber($parameters, 'sl_penalty_per_unit');
        $r2Revenue = $this->number($computed, 'n2_r2_p1') + $this->number($computed, 'n2_r2_p2');
        $r3Revenue = $this->number($computed, 'n2_r3_p1') + $this->number($computed, 'n2_r3_p2');
        $excise = ($r2Revenue * $this->requiredNumber($parameters, 'excise_rate_r2')) + ($r3Revenue * $this->requiredNumber($parameters, 'excise_rate_r3'));
        if ((string) ($decisions['production']['postponed_flag'] ?? 'NO') === 'YES') {
            $excise *= (1 - ($this->number($decisions['production'], 'postponed_pct') * $this->requiredNumber($parameters, 'postponed_duty_saving')));
        }

        $inspectionLevel = strtolower((string) $decisions['quality']['inspection_level']);
        $inspection = $this->number($computed, 'total_production') * $this->requiredNumber($parameters, "inspection_{$inspectionLevel}_cost");
        $periodDepreciation = $depreciation['mfg'] + $depreciation['tech'] + $depreciation['dc'];
        $admin = $this->requiredNumber($parameters, 'admin_overhead') + $depreciation['tech'];
        $warrantyEconomicCost = $netRevenue * $this->number($notes, 'warranty_rate');
        $warrantyPaid = $warrantyEconomicCost * $this->requiredNumber($parameters, 'warranty_claims_rate');
        $warrantyExpense = ($decisions['notes_elections']['warranty_method'] ?? 'percentage_of_sales') === 'expense_as_incurred'
            ? $warrantyPaid
            : $warrantyEconomicCost;
        $warranty = $this->rollForward($opening->warrantyReserve, $warrantyExpense, $opening->warrantyReserve + $warrantyPaid);
        $serviceLevel = $this->rollForward($opening->serviceLevelAccrual, $slPenalties, $opening->serviceLevelAccrual + ($slPenalties * $this->requiredNumber($parameters, 'sl_payment_rate')));
        $exciseRollForward = $this->rollForward($opening->excisePayable, $excise, $opening->excisePayable + ($excise * $this->requiredNumber($parameters, 'excise_payment_rate')));
        $exciseExpense = ($decisions['notes_elections']['excise_tax_treatment'] ?? 'cogs_inclusion') === 'separate_operating_expense' ? $excise : 0.0;
        $exciseCogs = $excise - $exciseExpense;
        $badDebtExpense = $this->badDebtExpense(array_merge($computed, [
            'net_revenue' => $netRevenue,
            'gross_ar' => $opening->accountsReceivable + ($netRevenue * 0.20),
            'current_credit_sales' => $netRevenue * 0.20,
        ]), $notes, $opening);
        $operatingIncome = $this->number($computed, 'gross_profit') - $exciseCogs - ($marketingExpense + $freight + $slPenalties + $exciseExpense + $inspection + $admin + $warrantyExpense + $badDebtExpense);

        $context['computed'] = array_merge($computed, [
            'net_revenue' => $netRevenue,
            'gross_ar' => $opening->accountsReceivable + ($netRevenue * 0.20),
            'current_credit_sales' => $netRevenue * 0.20,
            'excise_cogs' => $exciseCogs,
            'gross_profit' => $this->number($computed, 'gross_profit') - $exciseCogs,
            'period_depr' => $periodDepreciation,
            'depr_mfg' => $depreciation['mfg'],
            'depr_tech' => $depreciation['tech'],
            'depr_dc' => $depreciation['dc'],
            'total_opex' => $marketingExpense + $freight + $slPenalties + $excise + $inspection + $admin + $warrantyExpense + $badDebtExpense,
            'bad_debt_expense' => $badDebtExpense,
            'operating_income' => $operatingIncome,
            'n6_war_close' => $warranty['close'],
            'n6_sl_close' => $serviceLevel['close'],
            'n6_exc_close' => $exciseRollForward['close'],
        ]);

        return [
            'is_marketing' => $marketingExpense,
            'is_freight' => $freight,
            'is_sl_penalties' => $slPenalties,
            'is_excise_cogs' => $exciseCogs,
            'is_excise' => $exciseExpense,
            'is_inspection' => $inspection,
            'is_admin' => $admin,
            'is_warranty' => $warrantyExpense,
            'n6_supplier' => $this->number($computed, 'total_production') * $this->number($computed, 'blended_cost'),
            'n6_po_fob' => (string) $notes['proc_terms'] === 'FOB Shipping Point' ? 1.0 : 0.0,
            'n6_lease' => 0.0,
            'n6_war_open' => $warranty['open'],
            'n6_war_exp' => $warranty['addition'],
            'n6_war_paid' => $warranty['paid'],
            'n6_war_close' => $warranty['close'],
            'n6_sl_open' => $serviceLevel['open'],
            'n6_sl_exp' => $serviceLevel['addition'],
            'n6_sl_paid' => $serviceLevel['paid'],
            'n6_sl_close' => $serviceLevel['close'],
            'n6_exc_open' => $exciseRollForward['open'],
            'n6_exc_acc' => $exciseRollForward['addition'],
            'n6_exc_paid' => $exciseRollForward['paid'],
            'n6_exc_close' => $exciseRollForward['close'],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, float>
     */
    private function calculateBelowTheLine(array &$context): array
    {
        $parameters = $context['input']->parameters;
        $opening = $context['input']->openingPosition;
        $computed = $context['computed'];
        $creditScore = $this->calculateCreditScore($computed);
        $creditTier = $this->assignCreditTier($creditScore, $parameters);
        $interest = $opening->debt * ($creditTier['rate'] / 4);
        $badDebtExpense = $this->number($computed, 'bad_debt_expense');
        $taxableIncome = $this->number($computed, 'operating_income') - $interest;
        $tax = max(0, $taxableIncome) * $this->requiredNumber($parameters, 'tax_rate');
        $netIncome = $taxableIncome - $tax;

        $context['computed'] = array_merge($computed, [
            'credit_score' => $creditScore,
            'interest_rate' => $creditTier['rate'],
            'borrowing_limit' => $creditTier['limit'],
            'is_interest' => $interest,
            'bad_debt_expense' => $badDebtExpense,
            'is_tax' => $tax,
            'net_income' => $netIncome,
        ]);
        $context['income_before_income_taxes'] = $taxableIncome;

        return [
            'is_interest' => $interest,
            'is_other' => $badDebtExpense,
            'is_tax' => $tax,
            'n5_rate' => $creditTier['rate'],
            'n5_limit' => $creditTier['limit'],
            'n5_draw_rate' => $creditTier['rate'],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, float>
     */
    private function calculateBalanceSheetAndPpeBeforeCash(array &$context): array
    {
        $input = $context['input'];
        $parameters = $input->parameters;
        $computed = $context['computed'];
        $opening = $input->openingPosition;
        $capexMfg = $this->requiredNumber($parameters, 'capex_mfg');
        $capexTech = $this->requiredNumber($parameters, 'capex_tech');
        $capexDc = $this->requiredNumber($parameters, 'capex_dc');
        $grossPpeMfg = $opening->ppeComponents->manufacturing + $capexMfg;
        $grossPpeTech = $opening->ppeComponents->technology + $capexTech;
        $grossPpeDc = $opening->ppeComponents->distributionCenter + $capexDc;
        $ppeGross = $grossPpeMfg + $grossPpeTech + $grossPpeDc;
        $accumulatedDepreciation = $opening->accumulatedDepreciation + $this->number($computed, 'period_depr');
        $mfgDepr = $this->number($computed, 'depr_mfg');
        $techDepr = $this->number($computed, 'depr_tech');
        $dcDepr = $this->number($computed, 'depr_dc');
        $inventory = max(0.0, $this->number($computed, 'ending_inventory') + $this->number($computed, 'capitalized_carrying') - $this->number($computed, 'n3_lifo_chg'));
        $rawMaterialPurchases = $this->number($computed, 'total_production') * $this->number($computed, 'blended_cost');
        $accountsPayable = $rawMaterialPurchases * 0.30;
        $accountsReceivable = max(0.0, $this->number($computed, 'current_credit_sales') - $this->number($computed, 'bad_debt_expense'));
        $currentDebt = round($opening->debt * 0.10, 2);
        $longTermDebt = round($opening->debt - $currentDebt, 2);

        $context['computed'] = array_merge($computed, [
            'bs_ar' => $accountsReceivable,
            'bs_inventory' => $inventory,
            'bs_ppe_gross' => $ppeGross,
            'bs_accum_depr' => $accumulatedDepreciation,
            'bs_ap' => $accountsPayable,
            'bs_curr_ltd' => $currentDebt,
            'bs_ltd' => $longTermDebt,
            'bs_common' => $opening->corporateCapitalization,
            'bs_retained' => $opening->retainedEarnings,
            'bs_curr_ni' => (float) $context['published_net_income'],
            'ar_change' => $accountsReceivable - $opening->accountsReceivable,
            'ap_change' => $accountsPayable - $opening->accountsPayable,
        ]);

        return [
            'bs_ar' => $accountsReceivable,
            'bs_inventory' => $inventory,
            'bs_prepaid' => 0.0,
            'bs_ppe_gross' => $ppeGross,
            'bs_accum_depr' => $accumulatedDepreciation,
            'bs_rou' => 0.0,
            'bs_tech_net' => 0.0,
            'bs_ap' => $accountsPayable,
            'bs_wages' => 0.0,
            'bs_mkt' => 0.0,
            'bs_warranty_res' => $this->number($computed, 'n6_war_close'),
            'bs_sl_accrual' => $this->number($computed, 'n6_sl_close'),
            'bs_excise' => $this->number($computed, 'n6_exc_close'),
            'bs_curr_ltd' => $currentDebt,
            'bs_def_rev' => 0.0,
            'bs_ltd' => $longTermDebt,
            'bs_lease' => 0.0,
            'bs_dtl' => 0.0,
            'bs_common' => $opening->corporateCapitalization,
            'bs_retained' => $opening->retainedEarnings,
            'bs_curr_ni' => (float) $context['published_net_income'],
            'n2_rebate_open' => 0.0,
            'n2_rebate_paid' => $this->number($computed, 'n2_rebate_added') * $this->requiredNumber($parameters, 'rebate_payment_rate'),
            'n4_mfg_gross' => $grossPpeMfg,
            'n4_mfg_depr' => $mfgDepr,
            'n4_mfg_net' => $grossPpeMfg - $mfgDepr,
            'n4_tech_gross' => $grossPpeTech,
            'n4_tech_depr' => $techDepr,
            'n4_tech_net' => $grossPpeTech - $techDepr,
            'n4_dc_gross' => $grossPpeDc,
            'n4_dc_depr' => $dcDepr,
            'n4_dc_net' => $grossPpeDc - $dcDepr,
            'n4_util_rate' => min(1.0, $this->number($computed, 'total_production') / max($this->productionCapacity($context['decisions']['production'], $parameters), 1)),
            'n4_disposal_nbv' => 0.0,
            'n4_disposal_proc' => 0.0,
            'n4_disposal_gain' => 0.0,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, float>
     */
    private function calculateCashFlowAndDebt(array &$context): array
    {
        $input = $context['input'];
        $parameters = $input->parameters;
        $opening = $input->openingPosition;
        $computed = $context['computed'];
        $netIncome = (float) $context['published_net_income'];
        $periodDepreciation = $this->money($computed, 'period_depr');
        $accountsReceivableChange = $this->money($computed, 'bs_ar') - $opening->accountsReceivable;
        $inventoryChange = $this->money($computed, 'bs_inventory') - $opening->inventory;
        $accountsPayableChange = $this->money($computed, 'bs_ap') - $opening->accountsPayable;
        $warrantyChange = $this->money($computed, 'n6_war_close') - $opening->warrantyReserve;
        $serviceLevelChange = $this->money($computed, 'n6_sl_close') - $opening->serviceLevelAccrual;
        $exciseChange = $this->money($computed, 'n6_exc_close') - $opening->excisePayable;
        $accruedChange = $warrantyChange + $serviceLevelChange + $exciseChange;
        $operatingCash = $netIncome
            + $periodDepreciation
            - $accountsReceivableChange
            - $inventoryChange
            + $accountsPayableChange
            + $accruedChange;

        $capexMfg = $this->requiredNumber($parameters, 'capex_mfg');
        $capexTech = $this->requiredNumber($parameters, 'capex_tech');
        $capexDc = $this->requiredNumber($parameters, 'capex_dc');
        $investingCash = -$capexMfg - $capexTech - $capexDc;
        $currentDebt = $this->money($computed, 'bs_curr_ltd');
        $longTermDebt = $this->money($computed, 'bs_ltd');
        $borrowRepayment = round($currentDebt * $this->requiredNumber($parameters, 'borrow_repay_rate'), 2);
        $leasePayment = 0.0;
        $cashBeforeBorrowing = $opening->cash - $opening->bankOverdraft + $operatingCash + $investingCash - $borrowRepayment - $leasePayment;
        $dividendLimit = $this->dividendValidator->validate(
            quarterNumber: $input->quarterNumber,
            requestedDividend: $input->dividend,
            openingRetainedEarnings: $opening->retainedEarnings,
            currentNetIncome: $netIncome,
            preDividendCash: $cashBeforeBorrowing,
            minimumCashBuffer: $this->requiredNumber($parameters, 'minimum_cash_buffer'),
        );
        $dividend = $dividendLimit->requested;
        $autoDraw = $cashBeforeBorrowing < $this->requiredNumber($parameters, 'minimum_cash_buffer')
            ? round(min($this->requiredNumber($parameters, 'minimum_cash_buffer') - $cashBeforeBorrowing, $this->number($computed, 'borrowing_limit')), 2)
            : 0.0;
        $cashAfterCommittedBorrowing = round($cashBeforeBorrowing - $dividend + $autoDraw, 2);
        $endingOverdraft = round(max(0.0, -$cashAfterCommittedBorrowing), 2);
        $overdraftMovement = round($endingOverdraft - $opening->bankOverdraft, 2);
        $financingCash = $autoDraw - $borrowRepayment - $leasePayment - $dividend + $overdraftMovement;
        $endingCash = round(max(0.0, $cashAfterCommittedBorrowing), 2);
        $endingCurrentDebt = round(max(0.0, $currentDebt - $borrowRepayment), 2);
        $endingLongTermDebt = round($longTermDebt + $autoDraw, 2);
        $closingDebt = round($endingCurrentDebt + $endingLongTermDebt, 2);

        $currentAssets = $endingCash + $this->number($computed, 'bs_ar') + $this->number($computed, 'bs_inventory');
        $currentLiabilities = $this->number($computed, 'bs_ap') + $this->number($computed, 'n6_war_close') + $this->number($computed, 'n6_sl_close') + $this->number($computed, 'n6_exc_close') + $endingCurrentDebt + $endingOverdraft;
        $endingRetainedEarnings = round($opening->retainedEarnings - $dividend, 2);
        $totalEquity = $this->number($computed, 'bs_common') + $endingRetainedEarnings + $this->number($computed, 'bs_curr_ni');
        $netRevenue = $this->number($computed, 'net_revenue');
        $operatingIncome = $this->number($computed, 'operating_income');
        $interest = $this->number($computed, 'is_interest');

        $context['computed'] = array_merge($computed, [
            'dividend' => $dividend,
            'distributable_retained_earnings' => $dividendLimit->distributableRetainedEarnings,
            'cash_available_for_dividend' => $dividendLimit->cashAvailableAboveBuffer,
            'maximum_dividend' => $dividendLimit->maximum,
            'pre_dividend_cash' => $cashBeforeBorrowing,
        ]);

        return [
            'bs_cash' => $endingCash,
            'bs_retained' => $endingRetainedEarnings,
            'bs_curr_ltd' => $endingCurrentDebt,
            'bs_ltd' => $endingLongTermDebt,
            'bs_bank_overdraft' => $endingOverdraft,
            'cf_net_income' => $netIncome,
            'cf_depr' => $periodDepreciation,
            'cf_rou' => 0.0,
            'cf_lifo' => 0.0,
            'cf_ar_chg' => $accountsReceivableChange,
            'cf_inv_chg' => $inventoryChange,
            'cf_prepaid_chg' => 0.0,
            'cf_ap_chg' => $accountsPayableChange,
            'cf_accrued_chg' => $serviceLevelChange,
            'cf_warranty_chg' => $warrantyChange,
            'cf_def_rev_chg' => 0.0,
            'cf_excise_chg' => $exciseChange,
            'cf_capex_mfg' => $capexMfg,
            'cf_capex_tech' => $capexTech,
            'cf_capex_dc' => $capexDc,
            'cf_disposal' => 0.0,
            'cf_borrow_proc' => $autoDraw,
            'cf_borrow_repay' => $borrowRepayment,
            'cf_lease_pmt' => $leasePayment,
            'cf_equity' => -$dividend,
            'cf_beg_cash' => $opening->cash,
            'cf_cash_interest' => $interest,
            'cf_cash_tax' => (float) $context['published_income_tax_expense'],
            'cf_overdraft_chg' => $overdraftMovement,
            'n5_balance' => $closingDebt,
            'n5_auto_draw' => $autoDraw,
            'n5_overdraft' => $endingOverdraft,
            'n5_shortfall_indicator' => $endingOverdraft > 0.0 ? 1.0 : 0.0,
            'n5_mat_y1' => $currentDebt,
            'n5_mat_y2' => $longTermDebt * 0.40,
            'n5_mat_beyond' => $longTermDebt * 0.60,
            'n5_cov_curr' => $currentLiabilities > 0 ? $currentAssets / $currentLiabilities : 0.0,
            'n5_cov_de' => $totalEquity > 0 ? $closingDebt / $totalEquity : 0.0,
            'n5_cov_margin' => $netRevenue > 0 ? $operatingIncome / $netRevenue : 0.0,
            'n5_cov_interest' => $interest > 0 ? $operatingIncome / $interest : 0.0,
            'n5_cov_cash' => $this->requiredNumber($parameters, 'minimum_cash_buffer') > 0 ? $endingCash / $this->requiredNumber($parameters, 'minimum_cash_buffer') : 0.0,
            'n5_int_recon' => $interest,
        ];
    }

    /**
     * @param  array<string, float>  $fields
     * @return array<string, mixed>
     */
    private function incomeStatement(array $fields, float $taxRate): array
    {
        $netRevenue = CurrencyRounding::cents($fields['is_p1_rev'] + $fields['is_p2_rev'] - $fields['is_returns'] - $fields['is_rebates']);
        $cogs = CurrencyRounding::cents($fields['is_raw_mat'] + $fields['is_labor'] + $fields['is_overhead'] + $fields['is_excise_cogs'] - $fields['is_end_inv'] + $fields['is_lifo_adj'] + $fields['is_carry']);
        $grossProfit = CurrencyRounding::cents($netRevenue - $cogs);
        $operatingExpenses = CurrencyRounding::cents($fields['is_marketing'] + $fields['is_freight'] + $fields['is_sl_penalties'] + $fields['is_excise'] + $fields['is_inspection'] + $fields['is_admin'] + $fields['is_warranty'] + $fields['is_other']);
        $operatingIncome = CurrencyRounding::cents($grossProfit - $operatingExpenses);
        $interestExpense = CurrencyRounding::cents($fields['is_interest']);
        $incomeBeforeIncomeTaxes = CurrencyRounding::cents($operatingIncome - $interestExpense);
        $incomeTaxExpense = $incomeBeforeIncomeTaxes <= 0
            ? 0.0
            : CurrencyRounding::cents($incomeBeforeIncomeTaxes * $taxRate);
        $netIncome = CurrencyRounding::cents($incomeBeforeIncomeTaxes - $incomeTaxExpense);

        return [
            'net_revenue' => $netRevenue,
            'cost_of_goods_sold' => $cogs,
            'gross_profit' => $grossProfit,
            'operating_expenses' => $operatingExpenses,
            'operating_income' => $operatingIncome,
            'bad_debt_expense' => $fields['is_other'],
            'interest_expense' => $interestExpense,
            'income_before_income_taxes' => $incomeBeforeIncomeTaxes,
            'income_tax_expense' => $incomeTaxExpense,
            'net_income' => $netIncome,
        ];
    }

    /**
     * @param  array<string, float>  $fields
     * @return array<string, mixed>
     */
    private function balanceSheet(array $fields): array
    {
        return [
            'assets' => $this->validator->assets($fields),
            'liabilities' => $this->validator->liabilities($fields),
            'equity' => $this->validator->equity($fields),
            'line_items' => array_intersect_key($fields, array_flip(array_filter(self::FIELD_KEYS, fn (string $key): bool => str_starts_with($key, 'bs_')))),
        ];
    }

    /**
     * @param  array<string, float>  $fields
     * @return array<string, mixed>
     */
    private function cashFlowStatement(array $fields): array
    {
        return [
            'operating_cash_flow' => $this->validator->operatingCashFlow($fields),
            'investing_cash_flow' => $this->validator->investingCashFlow($fields),
            'financing_cash_flow' => $this->validator->financingCashFlow($fields),
            'ending_cash' => $fields['bs_cash'],
            'line_items' => array_intersect_key($fields, array_flip(array_filter(self::FIELD_KEYS, fn (string $key): bool => str_starts_with($key, 'cf_')))),
        ];
    }

    /**
     * @param  array<string, float>  $fields
     * @return array<string, mixed>
     */
    private function notes(array $fields): array
    {
        return [
            'revenue' => array_intersect_key($fields, array_flip(array_filter(self::FIELD_KEYS, fn (string $key): bool => str_starts_with($key, 'n2_')))),
            'inventory' => array_intersect_key($fields, array_flip(array_filter(self::FIELD_KEYS, fn (string $key): bool => str_starts_with($key, 'n3_')))),
            'ppe' => array_intersect_key($fields, array_flip(array_filter(self::FIELD_KEYS, fn (string $key): bool => str_starts_with($key, 'n4_')))),
            'debt' => array_intersect_key($fields, array_flip(array_filter(self::FIELD_KEYS, fn (string $key): bool => str_starts_with($key, 'n5_')))),
            'commitments' => array_intersect_key($fields, array_flip(array_filter(self::FIELD_KEYS, fn (string $key): bool => str_starts_with($key, 'n6_')))),
        ];
    }

    /**
     * @param  array<string, float>  $partial
     * @return array<string, float>
     */
    private function normalize(array $partial): array
    {
        foreach (array_keys($partial) as $key) {
            if (! in_array($key, self::FIELD_KEYS, true)) {
                throw new EngineCalculationException("Unknown engine result field [{$key}].");
            }
        }

        $fields = array_merge(array_fill_keys(self::FIELD_KEYS, 0.0), $partial);

        foreach ($fields as $key => $value) {
            $fields[$key] = in_array($key, self::NON_MONETARY_FIELD_KEYS, true)
                ? (float) $value
                : CurrencyRounding::cents((float) $value);
        }

        return $fields;
    }

    /**
     * @param  array<string, array<string, mixed>>  $decisions
     */
    private function validateDecisionFields(array $decisions): void
    {
        $requiredFields = [
            'forecasting' => ['forecast_p1_r1', 'forecast_p1_r2', 'forecast_p1_r3', 'forecast_p2_r1', 'forecast_p2_r2', 'forecast_p2_r3', 'forecast_confidence'],
            'sourcing' => ['primary_supplier', 'primary_alloc', 'secondary_supplier'],
            'pricing' => ['price_p1_r1', 'price_p1_r2', 'price_p1_r3', 'price_p2_r1', 'price_p2_r2', 'price_p2_r3', 'discount_policy'],
            'production' => ['p1_production', 'p2_production', 'shifts', 'postponed_flag', 'postponed_pct'],
            'marketing' => ['marketing_budget_pct', 'marketing_alloc_champions', 'marketing_alloc_growth', 'marketing_alloc_at_risk', 'marketing_alloc_other'],
            'quality' => ['inspection_level'],
            'logistics' => ['logistics_mode'],
            'notes_elections' => ['inventory_method', 'depreciation_method', 'shipping_terms', 'bad_debt_method', 'warranty_method', 'excise_tax_treatment'],
            'certification' => ['attestation_scope'],
        ];

        foreach ($requiredFields as $category => $fields) {
            if (! array_key_exists($category, $decisions)) {
                throw new EngineCalculationException("Missing required decision category [{$category}].");
            }

            foreach ($fields as $field) {
                if (! array_key_exists($field, $decisions[$category])) {
                    throw new EngineCalculationException("Missing required decision field [{$category}.{$field}].");
                }
            }
        }
    }

    private function elasticDemand(float $forecast, float $price, float $basePrice, float $elasticity): float
    {
        if ($forecast <= 0 || $price <= 0 || $basePrice <= 0) {
            return 0.0;
        }

        return (float) round($forecast * (($price / $basePrice) ** $elasticity));
    }

    /**
     * @param  array{r1: float, r2: float, r3: float}  $regionalDemand
     * @return array{r1: float, r2: float, r3: float}
     */
    private function allocateRegionalSold(array $regionalDemand, float $sold): array
    {
        $totalDemand = array_sum($regionalDemand);

        if ($totalDemand <= 0 || $sold <= 0) {
            return ['r1' => 0.0, 'r2' => 0.0, 'r3' => 0.0];
        }

        $r1 = (float) round($sold * $regionalDemand['r1'] / $totalDemand);
        $r2 = (float) round($sold * $regionalDemand['r2'] / $totalDemand);

        return ['r1' => $r1, 'r2' => $r2, 'r3' => $sold - $r1 - $r2];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function number(array $values, string $key): float
    {
        return (float) ($values[$key] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function money(array $values, string $key): float
    {
        return round($this->number($values, $key), 2);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function requiredNumber(array $parameters, string $key): float
    {
        if (! array_key_exists($key, $parameters) || ! is_numeric($parameters[$key])) {
            throw new EngineCalculationException("Missing numeric scenario parameter [{$key}].");
        }

        return (float) $parameters[$key];
    }

    /**
     * @return array{open: float, addition: float, paid: float, close: float}
     */
    private function rollForward(float $opening, float $addition, float $payment): array
    {
        return ['open' => $opening, 'addition' => $addition, 'paid' => $payment, 'close' => $opening + $addition - $payment];
    }

    /**
     * @param  array<string, mixed>  $computed
     * @param  array<string, mixed>  $notes
     */
    private function badDebtExpense(array $computed, array $notes, Contracts\OpeningFinancialPosition $opening): float
    {
        $allowanceRate = $this->number($notes, 'allowance_pct');

        if (($notes['bad_debt_method'] ?? 'percentage_of_receivables') === 'percentage_of_sales') {
            return $this->number($computed, 'net_revenue') * $allowanceRate;
        }

        $targetAllowance = $this->number($computed, 'gross_ar') * $allowanceRate;

        return max(0.0, $targetAllowance - ($opening->accountsReceivable * $allowanceRate));
    }

    /**
     * @param  array<string, mixed>  $notes
     * @return array{mfg: float, tech: float, dc: float}
     */
    private function periodDepreciation(array $notes, Contracts\PpeComponentAllocation $ppeComponents): array
    {
        $multiplier = (string) $notes['depr_method'] === 'Straight-Line' ? 1.0 : 2.0;

        return [
            'mfg' => $ppeComponents->manufacturing * ($multiplier / max($this->number($notes, 'life_mfg'), 1) / 4),
            'tech' => $ppeComponents->technology * ($multiplier / max($this->number($notes, 'life_tech'), 1) / 4),
            'dc' => $ppeComponents->distributionCenter * ($multiplier / max($this->number($notes, 'life_dc'), 1) / 4),
        ];
    }

    /**
     * @param  array<string, mixed>  $computed
     */
    private function calculateCreditScore(array $computed): float
    {
        $netRevenue = max($this->number($computed, 'net_revenue'), 1);

        return min(100, max(0, (($this->number($computed, 'operating_income') / $netRevenue) * 200 * 0.50)
            + (($this->number($computed, 'gross_profit') / $netRevenue) * 200 * 0.30)
            + ((100 - (($this->number($computed, 'total_opex') / $netRevenue) * 100)) * 0.20)));
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{rate: float, limit: float}
     */
    private function assignCreditTier(float $score, array $parameters): array
    {
        foreach (['excellent', 'good', 'fair', 'poor'] as $tier) {
            if ($score >= $this->requiredNumber($parameters, "credit_tier_{$tier}_min_score")) {
                return [
                    'rate' => $this->requiredNumber($parameters, "credit_tier_{$tier}_rate"),
                    'limit' => $this->requiredNumber($parameters, "credit_tier_{$tier}_limit"),
                ];
            }
        }

        return [
            'rate' => $this->requiredNumber($parameters, 'credit_tier_distressed_rate'),
            'limit' => $this->requiredNumber($parameters, 'credit_tier_distressed_limit'),
        ];
    }

    /**
     * @param  array<string, mixed>  $production
     * @param  array<string, mixed>  $parameters
     */
    private function productionCapacity(array $production, array $parameters): float
    {
        return $this->number($production, 'shifts') * $this->requiredNumber($parameters, 'capacity_per_shift');
    }

    private function supplierParameterKey(string $supplier, string $attribute): string
    {
        return 'supplier_'.strtolower($supplier).'_'.$attribute;
    }
}
