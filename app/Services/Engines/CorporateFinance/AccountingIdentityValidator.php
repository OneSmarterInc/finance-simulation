<?php

namespace App\Services\Engines\CorporateFinance;

use App\Services\Engines\CorporateFinance\Contracts\OpeningFinancialPosition;
use App\Services\Engines\CorporateFinance\Exceptions\EngineCalculationException;

final class AccountingIdentityValidator
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function validate(array $fields, OpeningFinancialPosition $opening, float $tolerance = 0.01): void
    {
        $this->validateOverdraftCoherence($fields, $opening, $tolerance);
        $this->validateBalanceSheet($fields, $tolerance);
        $this->validateCashReconciliation($fields, $opening, $tolerance);
        $this->validateInventoryReconciliation($fields, $opening, $tolerance);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function validateOverdraftCoherence(array $fields, OpeningFinancialPosition $opening, float $tolerance = 0.01): void
    {
        $cash = $this->number($fields, 'bs_cash');
        $overdraft = $this->number($fields, 'bs_bank_overdraft');
        $noteOverdraft = $this->number($fields, 'n5_overdraft');
        $indicator = $this->number($fields, 'n5_shortfall_indicator');
        $expectedMovement = round($overdraft - $opening->bankOverdraft, 2);
        $reportedMovement = $this->number($fields, 'cf_overdraft_chg');

        if ($cash < -$tolerance || $overdraft < -$tolerance || ($cash > $tolerance && $overdraft > $tolerance)) {
            throw new EngineCalculationException("Overdraft presentation failed: cash={$cash}; overdraft={$overdraft}; cash must be non-negative and cannot coexist with an overdraft.");
        }

        if (abs(round($overdraft - $noteOverdraft, 2)) > $tolerance || (($overdraft > $tolerance) !== ($indicator === 1.0))) {
            throw new EngineCalculationException("Overdraft amount coherence failed: balance_sheet_overdraft={$overdraft}; note_overdraft={$noteOverdraft}; shortfall_indicator={$indicator}.");
        }

        if (abs(round($reportedMovement - $expectedMovement, 2)) > $tolerance) {
            throw new EngineCalculationException("Overdraft financing movement failed: opening_overdraft={$opening->bankOverdraft}; ending_overdraft={$overdraft}; expected_movement={$expectedMovement}; reported_movement={$reportedMovement}.");
        }
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function validateBalanceSheet(array $fields, float $tolerance = 0.01): void
    {
        $assets = $this->assets($fields);
        $liabilities = $this->liabilities($fields);
        $equity = $this->equity($fields);
        $difference = round($assets - ($liabilities + $equity), 2);

        if (abs($difference) > $tolerance) {
            throw new EngineCalculationException("Balance-sheet identity failed: total_assets={$assets}; total_liabilities={$liabilities}; total_equity={$equity}; difference={$difference}.");
        }
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function validateCashReconciliation(array $fields, OpeningFinancialPosition $opening, float $tolerance = 0.01): void
    {
        $cashDelta = $this->number($fields, 'bs_cash') - $opening->cash;
        $cashFlowDelta = $this->operatingCashFlow($fields) + $this->investingCashFlow($fields) + $this->financingCashFlow($fields);
        $difference = round($cashDelta - $cashFlowDelta, 2);

        if (abs($difference) > $tolerance) {
            throw new EngineCalculationException("Cash reconciliation failed: ending_cash={$this->number($fields, 'bs_cash')}; opening_cash={$opening->cash}; operating_cash_flow={$this->operatingCashFlow($fields)}; investing_cash_flow={$this->investingCashFlow($fields)}; financing_cash_flow={$this->financingCashFlow($fields)}; difference={$difference}.");
        }
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function validateInventoryReconciliation(array $fields, OpeningFinancialPosition $opening, float $tolerance = 0.01): void
    {
        $movement = $this->number($fields, 'bs_inventory') - $opening->inventory;
        $cashFlowLine = $this->number($fields, 'cf_inv_chg');
        $difference = round($movement - $cashFlowLine, 2);

        if (abs($difference) > $tolerance) {
            throw new EngineCalculationException("Inventory reconciliation failed: opening_inventory={$opening->inventory}; ending_inventory={$this->number($fields, 'bs_inventory')}; balance_sheet_movement={$movement}; cash_flow_inventory_change={$cashFlowLine}; difference={$difference}.");
        }
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function assets(array $fields): float
    {
        return $this->number($fields, 'bs_cash')
            + $this->number($fields, 'bs_ar')
            + $this->number($fields, 'bs_inventory')
            + $this->number($fields, 'bs_prepaid')
            + $this->number($fields, 'bs_ppe_gross')
            - $this->number($fields, 'bs_accum_depr')
            + $this->number($fields, 'bs_rou');
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function liabilities(array $fields): float
    {
        return $this->number($fields, 'bs_ap')
            + $this->number($fields, 'bs_wages')
            + $this->number($fields, 'bs_mkt')
            + $this->number($fields, 'bs_warranty_res')
            + $this->number($fields, 'bs_sl_accrual')
            + $this->number($fields, 'bs_excise')
            + $this->number($fields, 'bs_curr_ltd')
            + $this->number($fields, 'bs_def_rev')
            + $this->number($fields, 'bs_ltd')
            + $this->number($fields, 'bs_lease')
            + $this->number($fields, 'bs_dtl')
            + $this->number($fields, 'bs_bank_overdraft');
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function equity(array $fields): float
    {
        return $this->number($fields, 'bs_common')
            + $this->number($fields, 'bs_retained')
            + $this->number($fields, 'bs_curr_ni');
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function operatingCashFlow(array $fields): float
    {
        return $this->number($fields, 'cf_net_income')
            + $this->number($fields, 'cf_depr')
            + $this->number($fields, 'cf_rou')
            + $this->number($fields, 'cf_lifo')
            - $this->number($fields, 'cf_ar_chg')
            - $this->number($fields, 'cf_inv_chg')
            - $this->number($fields, 'cf_prepaid_chg')
            + $this->number($fields, 'cf_ap_chg')
            + $this->number($fields, 'cf_accrued_chg')
            + $this->number($fields, 'cf_warranty_chg')
            + $this->number($fields, 'cf_def_rev_chg')
            + $this->number($fields, 'cf_excise_chg');
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function investingCashFlow(array $fields): float
    {
        return -$this->number($fields, 'cf_capex_mfg')
            - $this->number($fields, 'cf_capex_tech')
            - $this->number($fields, 'cf_capex_dc')
            + $this->number($fields, 'cf_disposal');
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function financingCashFlow(array $fields): float
    {
        return $this->number($fields, 'cf_borrow_proc')
            - $this->number($fields, 'cf_borrow_repay')
            - $this->number($fields, 'cf_lease_pmt')
            + $this->number($fields, 'cf_equity')
            + $this->number($fields, 'cf_overdraft_chg');
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function number(array $values, string $key): float
    {
        return (float) ($values[$key] ?? 0);
    }
}
