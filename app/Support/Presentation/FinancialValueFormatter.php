<?php

namespace App\Support\Presentation;

use Illuminate\Support\Str;

final class FinancialValueFormatter
{
    /** @var array<string, string> */
    private const LABELS = [
        'bs_cash' => 'Cash',
        'bs_ar' => 'Accounts Receivable',
        'bs_inventory' => 'Inventory',
        'bs_prepaid' => 'Prepaid Expenses',
        'bs_ppe_gross' => 'Gross PPE',
        'bs_accum_depr' => 'Accumulated Depreciation',
        'bs_rou' => 'Right-of-Use Asset',
        'bs_ap' => 'Accounts Payable',
        'bs_wages' => 'Accrued Wages',
        'bs_mkt' => 'Accrued Marketing',
        'bs_warranty_res' => 'Warranty Reserve',
        'bs_sl_accrual' => 'Service-Level Accrual',
        'bs_excise' => 'Excise Payable',
        'bs_curr_ltd' => 'Current Portion of Long-Term Debt',
        'bs_def_rev' => 'Deferred Revenue',
        'bs_ltd' => 'Long-Term Debt',
        'bs_lease' => 'Lease Liability',
        'bs_dtl' => 'Deferred Tax Liability',
        'bs_bank_overdraft' => 'Bank Overdraft',
        'bs_common' => 'Corporate Capital',
        'bs_retained' => 'Retained Earnings',
        'bs_curr_ni' => 'Current-Period Net Income',
        'cf_overdraft_chg' => 'Bank Overdraft Financing Movement',
        'cf_equity' => 'Dividend Financing Movement',
    ];

    public static function currency(int|float|string $value): string
    {
        $amount = (float) $value;
        $formatted = '$'.number_format(abs($amount), 2);

        return $amount < 0 ? '-'.$formatted : $formatted;
    }

    public static function number(int|float|string $value, int $precision = 2): string
    {
        return number_format((float) $value, $precision);
    }

    /** @var array<string, string> Stored statement totals and wrappers. */
    private const STATEMENT_LABELS = [
        'net_revenue' => 'Net revenue',
        'cost_of_goods_sold' => 'Cost of goods sold',
        'gross_profit' => 'Gross profit',
        'operating_expenses' => 'Total operating expenses',
        'operating_income' => 'Operating income',
        'bad_debt_expense' => 'Bad debt expense',
        'interest_expense' => 'Interest expense',
        'income_before_income_taxes' => 'Income before income taxes',
        'income_tax_expense' => 'Income tax expense',
        'net_income' => 'Net income',
        'assets' => 'Total assets',
        'liabilities' => 'Total liabilities',
        'equity' => 'Total equity',
        'line_items' => 'Line items',
        'operating_cash_flow' => 'Net cash from operating activities',
        'investing_cash_flow' => 'Net cash from investing activities',
        'financing_cash_flow' => 'Net cash from financing activities',
        'ending_cash' => 'Cash at end of quarter',
        'revenue' => 'Revenue by region and product',
        'inventory' => 'Inventory',
        'ppe' => 'Property, plant and equipment',
        'debt' => 'Borrowing and financial ratios',
        'commitments' => 'Commitments, reserves and accruals',
    ];

    /**
     * Plain accounting label for any stored key (walkthrough item 37).
     * Falls back to a readable phrase, never a raw database key.
     */
    public static function label(string $key): string
    {
        static $fieldLabels = null;
        $fieldLabels ??= StatementPresenter::fieldLabels();

        return self::STATEMENT_LABELS[$key]
            ?? $fieldLabels[$key]
            ?? self::LABELS[$key]
            ?? Str::of($key)->replace('_', ' ')->ucfirst()->toString();
    }

    /** Display format for a stored field: currency, percent, ratio, flag or shipping. */
    public static function formatFor(string $key): string
    {
        return match ($key) {
            'n4_util_rate', 'n5_rate', 'n5_draw_rate', 'n5_cov_margin' => 'percent',
            'n5_cov_curr', 'n5_cov_de', 'n5_cov_interest', 'n5_cov_cash' => 'ratio',
            'n5_shortfall_indicator' => 'flag',
            'n6_po_fob' => 'shipping',
            default => 'currency',
        };
    }
}
