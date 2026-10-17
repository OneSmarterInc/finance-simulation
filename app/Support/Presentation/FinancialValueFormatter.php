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

    public static function label(string $key): string
    {
        return self::LABELS[$key] ?? Str::of($key)->replace('_', ' ')->title()->toString();
    }
}
