<?php

namespace App\Support\Presentation;

use App\Models\FirmEngineResult;

/**
 * Lays out stored engine results as accounting statements (walkthrough
 * items 37 to 39, 54, 55; decision N2: classified balance sheet).
 *
 * Every line value is a stored engine field. Cash flow lines are shown by
 * their effect on cash (deductions negative, rendered in parentheses), using
 * the same signs as AccountingIdentityValidator. Section subtotals are sums
 * of the stored lines above them; statement totals are the stored totals.
 *
 * A row is: ['type' => heading|line|subtotal|total|memo, 'label' => string,
 *            'format' => currency|percent|ratio|flag|units, 'values' => list<float|null>]
 */
final class StatementPresenter
{
    public const STATEMENTS = [
        'income_statement' => 'Income statement',
        'balance_sheet' => 'Balance sheet',
        'cash_flow_statement' => 'Cash flow statement',
    ];

    public const NOTES = [
        'revenue' => 'Revenue by region and product',
        'inventory' => 'Inventory',
        'ppe' => 'Property, plant and equipment',
        'debt' => 'Borrowing and financial ratios',
        'commitments' => 'Commitments, reserves and accruals',
    ];

    /**
     * @param  array<int|string, FirmEngineResult|array<string, mixed>>  $results  column => result (or its fields)
     * @return list<array{type: string, label: string, format: string, values: list<float|null>}>
     */
    public static function rows(string $statement, array $results): array
    {
        $columns = array_map(fn ($result) => self::columnData($result), array_values($results));

        $layout = match ($statement) {
            'income_statement' => self::incomeLayout(),
            'balance_sheet' => self::balanceLayout(),
            'cash_flow_statement' => self::cashFlowLayout(),
            'summary' => self::summaryLayout(),
            default => self::noteLayout($statement),
        };

        $rows = [];
        foreach ($layout as $row) {
            [$type, $label, $source, $format] = $row + [3 => 'currency'];
            $values = [];
            foreach ($columns as $column) {
                $values[] = $source === null ? null : self::resolve($source, $column);
            }
            $rows[] = ['type' => $type, 'label' => $label, 'format' => $format, 'values' => $values];
        }

        return $rows;
    }

    public static function format(?float $value, string $format): string
    {
        if ($value === null) {
            return '';
        }

        return match ($format) {
            'percent' => number_format($value * 100, 1).'%',
            'ratio' => number_format($value, 2).'x',
            'units' => number_format($value, 0),
            'flag' => $value >= 0.5 ? 'Yes' : 'No',
            'shipping' => $value >= 0.5 ? 'FOB shipping point' : 'FOB destination',
            default => self::accounting($value),
        };
    }

    /** Accounting style: $1,234.56 and ($1,234.56) for negatives. */
    public static function accounting(float $value): string
    {
        $rounded = round($value, 2);
        if (abs($rounded) < 0.005) {
            return '$0.00';
        }
        $formatted = '$'.number_format(abs($rounded), 2);

        return $rounded < 0 ? '('.$formatted.')' : $formatted;
    }

    /**
     * Every stored field key with its plain accounting label (item 37).
     *
     * @return array<string, string>
     */
    public static function fieldLabels(): array
    {
        $labels = [];
        foreach ([self::incomeLayout(), self::balanceLayout(), self::cashFlowLayout()] as $layout) {
            foreach ($layout as $row) {
                if (is_string($row[2] ?? null) && str_starts_with($row[2], 'f:')) {
                    $labels[substr($row[2], 2)] ??= $row[1];
                }
                if (is_array($row[2] ?? null) && ($row[2][0] ?? null) === 'f') {
                    $labels[$row[2][1]] ??= $row[1];
                }
            }
        }
        foreach (array_keys(self::NOTES) as $note) {
            foreach (self::noteLayout($note) as $row) {
                if (is_string($row[2] ?? null) && str_starts_with($row[2], 'f:')) {
                    $labels[substr($row[2], 2)] ??= $row[1];
                }
                if (is_array($row[2] ?? null) && ($row[2][0] ?? null) === 'f') {
                    $labels[$row[2][1]] ??= $row[1];
                }
            }
        }

        return $labels;
    }

    /** @return array{fields: array<string, mixed>, income: array<string, mixed>, balance: array<string, mixed>, cash: array<string, mixed>} */
    private static function columnData(FirmEngineResult|array $result): array
    {
        if ($result instanceof FirmEngineResult) {
            return [
                'fields' => $result->fields ?? [],
                'income' => $result->income_statement ?? [],
                'balance' => $result->balance_sheet ?? [],
                'cash' => $result->cash_flow_statement ?? [],
            ];
        }

        return ['fields' => $result, 'income' => [], 'balance' => [], 'cash' => []];
    }

    /**
     * Sources: 'f:key' a stored field; ['f', key, sign] a signed field;
     * 'is:key' / 'bs:key' / 'cf:key' a stored statement total;
     * ['sum', [sources...]] the sum of other sources.
     */
    private static function resolve(string|array $source, array $column): ?float
    {
        if (is_array($source)) {
            if ($source[0] === 'f') {
                return self::field($column, $source[1]) * $source[2];
            }
            if ($source[0] === 'sum') {
                return array_sum(array_map(fn ($part) => self::resolve($part, $column) ?? 0.0, $source[1]));
            }
        }

        [$kind, $key] = explode(':', $source, 2);

        $value = match ($kind) {
            'f' => $column['fields'][$key] ?? null,
            'is' => $column['income'][$key] ?? null,
            'bs' => $column['balance'][$key] ?? null,
            'cf' => $column['cash'][$key] ?? null,
            default => null,
        };

        return is_numeric($value) ? (float) $value : null;
    }

    private static function field(array $column, string $key): float
    {
        return (float) ($column['fields'][$key] ?? 0);
    }

    /** The trend view: a few stored measures across quarters (item 52 Summary tab). */
    private static function summaryLayout(): array
    {
        return [
            ['line', 'Net revenue', 'is:net_revenue'],
            ['line', 'Gross profit', 'is:gross_profit'],
            ['line', 'Net income', 'is:net_income'],
            ['line', 'Net cash from operating activities', 'cf:operating_cash_flow'],
            ['line', 'Cash at end of quarter', 'f:bs_cash'],
            ['line', 'Inventory', 'f:bs_inventory'],
            ['line', 'Total equity', 'bs:equity'],
            ['line', 'Operating margin', 'f:n5_cov_margin', 'percent'],
            ['line', 'Current ratio', 'f:n5_cov_curr', 'ratio'],
            ['line', 'Debt to equity', 'f:n5_cov_de', 'ratio'],
        ];
    }

    private static function incomeLayout(): array
    {
        return [
            ['heading', 'Revenue', null],
            ['line', 'Product 1 sales', 'f:is_p1_rev'],
            ['line', 'Product 2 sales', 'f:is_p2_rev'],
            ['line', 'Less: sales returns', ['f', 'is_returns', -1]],
            ['line', 'Less: customer rebates', ['f', 'is_rebates', -1]],
            ['subtotal', 'Net revenue', 'is:net_revenue'],
            ['heading', 'Cost of goods sold', null],
            ['line', 'Raw materials', 'f:is_raw_mat'],
            ['line', 'Direct labour', 'f:is_labor'],
            ['line', 'Manufacturing overhead', 'f:is_overhead'],
            ['line', 'Excise duty included in cost', 'f:is_excise_cogs'],
            ['line', 'Less: ending inventory', ['f', 'is_end_inv', -1]],
            ['line', 'LIFO adjustment', 'f:is_lifo_adj'],
            ['line', 'Inventory carrying cost', 'f:is_carry'],
            ['subtotal', 'Cost of goods sold', 'is:cost_of_goods_sold'],
            ['total', 'Gross profit', 'is:gross_profit'],
            ['heading', 'Operating expenses', null],
            ['line', 'Marketing', 'f:is_marketing'],
            ['line', 'Freight and logistics', 'f:is_freight'],
            ['line', 'Service-level penalties', 'f:is_sl_penalties'],
            ['line', 'Excise duty (operating expense)', 'f:is_excise'],
            ['line', 'Quality inspection', 'f:is_inspection'],
            ['line', 'Administration', 'f:is_admin'],
            ['line', 'Warranty expense', 'f:is_warranty'],
            ['line', 'Bad debt expense', 'f:is_other'],
            ['subtotal', 'Total operating expenses', 'is:operating_expenses'],
            ['total', 'Operating income', 'is:operating_income'],
            ['line', 'Interest expense', ['f', 'is_interest', -1]],
            ['subtotal', 'Income before income taxes', 'is:income_before_income_taxes'],
            ['line', 'Income tax expense', ['f', 'is_tax', -1]],
            ['total', 'Net income', 'is:net_income'],
        ];
    }

    private static function balanceLayout(): array
    {
        $currentAssets = ['f:bs_cash', 'f:bs_ar', 'f:bs_inventory', 'f:bs_prepaid'];
        $ppe = ['f:bs_ppe_gross', ['f', 'bs_accum_depr', -1]];
        $currentLiabilities = ['f:bs_bank_overdraft', 'f:bs_ap', 'f:bs_wages', 'f:bs_mkt', 'f:bs_warranty_res', 'f:bs_sl_accrual', 'f:bs_excise', 'f:bs_curr_ltd', 'f:bs_def_rev'];
        $longTerm = ['f:bs_ltd', 'f:bs_lease', 'f:bs_dtl'];

        return [
            ['heading', 'Current assets', null],
            ['line', 'Cash', 'f:bs_cash'],
            ['line', 'Accounts receivable', 'f:bs_ar'],
            ['line', 'Inventory', 'f:bs_inventory'],
            ['line', 'Prepaid expenses', 'f:bs_prepaid'],
            ['subtotal', 'Total current assets', ['sum', $currentAssets]],
            ['heading', 'Property, plant and equipment', null],
            ['line', 'Property, plant and equipment, at cost', 'f:bs_ppe_gross'],
            ['line', 'Less: accumulated depreciation', ['f', 'bs_accum_depr', -1]],
            ['subtotal', 'Property, plant and equipment, net', ['sum', $ppe]],
            ['line', 'Right-of-use asset (leases)', 'f:bs_rou'],
            ['total', 'Total assets', 'bs:assets'],
            ['heading', 'Current liabilities', null],
            ['line', 'Bank overdraft', 'f:bs_bank_overdraft'],
            ['line', 'Accounts payable', 'f:bs_ap'],
            ['line', 'Accrued wages', 'f:bs_wages'],
            ['line', 'Accrued marketing', 'f:bs_mkt'],
            ['line', 'Warranty reserve', 'f:bs_warranty_res'],
            ['line', 'Service-level accrual', 'f:bs_sl_accrual'],
            ['line', 'Excise duty payable', 'f:bs_excise'],
            ['line', 'Current portion of long-term debt', 'f:bs_curr_ltd'],
            ['line', 'Deferred revenue', 'f:bs_def_rev'],
            ['subtotal', 'Total current liabilities', ['sum', $currentLiabilities]],
            ['heading', 'Long-term liabilities', null],
            ['line', 'Long-term debt', 'f:bs_ltd'],
            ['line', 'Lease liability', 'f:bs_lease'],
            ['line', 'Deferred tax liability', 'f:bs_dtl'],
            ['subtotal', 'Total long-term liabilities', ['sum', $longTerm]],
            ['total', 'Total liabilities', 'bs:liabilities'],
            ['heading', 'Equity', null],
            ['line', 'Corporate capital', 'f:bs_common'],
            ['line', 'Retained earnings (after dividends)', 'f:bs_retained'],
            ['line', 'Net income for the quarter', 'f:bs_curr_ni'],
            ['total', 'Total equity', 'bs:equity'],
            ['total', 'Total liabilities and equity', ['sum', ['bs:liabilities', 'bs:equity']]],
        ];
    }

    private static function cashFlowLayout(): array
    {
        return [
            ['heading', 'Operating activities', null],
            ['line', 'Net income', 'f:cf_net_income'],
            ['line', 'Depreciation', 'f:cf_depr'],
            ['line', 'Right-of-use asset amortisation', 'f:cf_rou'],
            ['line', 'Change in LIFO reserve', 'f:cf_lifo'],
            ['line', '(Increase) decrease in accounts receivable', ['f', 'cf_ar_chg', -1]],
            ['line', '(Increase) decrease in inventory', ['f', 'cf_inv_chg', -1]],
            ['line', '(Increase) decrease in prepaid expenses', ['f', 'cf_prepaid_chg', -1]],
            ['line', 'Increase (decrease) in accounts payable', 'f:cf_ap_chg'],
            ['line', 'Increase (decrease) in accrued liabilities', 'f:cf_accrued_chg'],
            ['line', 'Increase (decrease) in warranty reserve', 'f:cf_warranty_chg'],
            ['line', 'Increase (decrease) in deferred revenue', 'f:cf_def_rev_chg'],
            ['line', 'Increase (decrease) in excise duty payable', 'f:cf_excise_chg'],
            ['subtotal', 'Net cash from operating activities', 'cf:operating_cash_flow'],
            ['heading', 'Investing activities', null],
            ['line', 'Capital spending: manufacturing', ['f', 'cf_capex_mfg', -1]],
            ['line', 'Capital spending: technology', ['f', 'cf_capex_tech', -1]],
            ['line', 'Capital spending: distribution centres', ['f', 'cf_capex_dc', -1]],
            ['line', 'Proceeds from asset disposals', 'f:cf_disposal'],
            ['subtotal', 'Net cash from investing activities', 'cf:investing_cash_flow'],
            ['heading', 'Financing activities', null],
            ['line', 'Borrowing', 'f:cf_borrow_proc'],
            ['line', 'Debt repayment', ['f', 'cf_borrow_repay', -1]],
            ['line', 'Lease payments', ['f', 'cf_lease_pmt', -1]],
            ['line', 'Dividends paid', 'f:cf_equity'],
            ['line', 'Change in bank overdraft', 'f:cf_overdraft_chg'],
            ['subtotal', 'Net cash from financing activities', 'cf:financing_cash_flow'],
            ['total', 'Net change in cash', ['sum', ['cf:operating_cash_flow', 'cf:investing_cash_flow', 'cf:financing_cash_flow']]],
            ['line', 'Cash at beginning of quarter', 'f:cf_beg_cash'],
            ['total', 'Cash at end of quarter', 'cf:ending_cash'],
            ['heading', 'Supplemental information', null],
            ['memo', 'Cash paid for interest', 'f:cf_cash_interest'],
            ['memo', 'Cash paid for income taxes', 'f:cf_cash_tax'],
        ];
    }

    private static function noteLayout(string $note): array
    {
        return match ($note) {
            'revenue' => [
                ['line', 'Northeast region, Product 1', 'f:n2_r1_p1'],
                ['line', 'Northeast region, Product 2', 'f:n2_r1_p2'],
                ['line', 'Central region, Product 1', 'f:n2_r2_p1'],
                ['line', 'Central region, Product 2', 'f:n2_r2_p2'],
                ['line', 'West region, Product 1', 'f:n2_r3_p1'],
                ['line', 'West region, Product 2', 'f:n2_r3_p2'],
                ['heading', 'Rebate liability', null],
                ['line', 'Opening balance', 'f:n2_rebate_open'],
                ['line', 'Rebates earned by customers', 'f:n2_rebate_added'],
                ['line', 'Rebates paid', ['f', 'n2_rebate_paid', -1]],
            ],
            'inventory' => [
                ['line', 'Raw materials', 'f:n3_raw_mat'],
                ['line', 'Work in process', 'f:n3_wip'],
                ['line', 'Finished goods', 'f:n3_fg'],
                ['line', 'Raw materials in transit', 'f:n3_in_transit'],
                ['line', 'LIFO reserve', 'f:n3_lifo_res'],
                ['line', 'Change in LIFO reserve', 'f:n3_lifo_chg'],
                ['line', 'Manufacturing overhead for the quarter', 'f:n3_overhead'],
            ],
            'ppe' => [
                ['line', 'Plant capacity used this quarter', 'f:n4_util_rate', 'percent'],
                ['heading', 'Manufacturing plant', null],
                ['line', 'At cost', 'f:n4_mfg_gross'],
                ['line', 'Depreciation this quarter', 'f:n4_mfg_depr'],
                ['line', 'Cost less this quarter\'s depreciation', 'f:n4_mfg_net'],
                ['heading', 'Technology', null],
                ['line', 'At cost', 'f:n4_tech_gross'],
                ['line', 'Depreciation this quarter', 'f:n4_tech_depr'],
                ['line', 'Cost less this quarter\'s depreciation', 'f:n4_tech_net'],
                ['heading', 'Distribution centres', null],
                ['line', 'At cost', 'f:n4_dc_gross'],
                ['line', 'Depreciation this quarter', 'f:n4_dc_depr'],
                ['line', 'Cost less this quarter\'s depreciation', 'f:n4_dc_net'],
                ['heading', 'Disposals', null],
                ['line', 'Book value of assets disposed', 'f:n4_disposal_nbv'],
                ['line', 'Disposal proceeds', 'f:n4_disposal_proc'],
                ['line', 'Gain (loss) on disposal', 'f:n4_disposal_gain'],
            ],
            'debt' => [
                ['line', 'Borrowing outstanding', 'f:n5_balance'],
                ['line', 'Interest rate', 'f:n5_rate', 'percent'],
                ['line', 'Committed credit limit', 'f:n5_limit'],
                ['line', 'Automatic draw this quarter', 'f:n5_auto_draw'],
                ['line', 'Draw rate', 'f:n5_draw_rate', 'percent'],
                ['line', 'Bank overdraft', 'f:n5_overdraft'],
                ['line', 'Financial shortfall', 'f:n5_shortfall_indicator', 'flag'],
                ['heading', 'Maturities', null],
                ['line', 'Due within one year', 'f:n5_mat_y1'],
                ['line', 'Due in the second year', 'f:n5_mat_y2'],
                ['line', 'Due after two years', 'f:n5_mat_beyond'],
                ['heading', 'Ratios', null],
                ['line', 'Current ratio', 'f:n5_cov_curr', 'ratio'],
                ['line', 'Debt to equity', 'f:n5_cov_de', 'ratio'],
                ['line', 'Operating margin', 'f:n5_cov_margin', 'percent'],
                ['line', 'Interest coverage', 'f:n5_cov_interest', 'ratio'],
                ['line', 'Cash coverage', 'f:n5_cov_cash', 'ratio'],
                ['memo', 'Interest expense reconciled', 'f:n5_int_recon'],
            ],
            'commitments' => [
                ['line', 'Supplier purchase commitments', 'f:n6_supplier'],
                ['line', 'Shipping terms on purchases', 'f:n6_po_fob', 'shipping'],
                ['line', 'Lease commitments', 'f:n6_lease'],
                ['heading', 'Warranty reserve', null],
                ['line', 'Opening balance', 'f:n6_war_open'],
                ['line', 'Warranty expense', 'f:n6_war_exp'],
                ['line', 'Warranty claims paid', ['f', 'n6_war_paid', -1]],
                ['subtotal', 'Closing balance', 'f:n6_war_close'],
                ['heading', 'Service-level costs', null],
                ['line', 'Opening accrual', 'f:n6_sl_open'],
                ['line', 'Service-level costs incurred', 'f:n6_sl_exp'],
                ['line', 'Service-level costs paid', ['f', 'n6_sl_paid', -1]],
                ['subtotal', 'Closing accrual', 'f:n6_sl_close'],
                ['heading', 'Excise duty', null],
                ['line', 'Opening balance', 'f:n6_exc_open'],
                ['line', 'Excise duty accrued', 'f:n6_exc_acc'],
                ['line', 'Excise duty paid', ['f', 'n6_exc_paid', -1]],
                ['subtotal', 'Closing balance', 'f:n6_exc_close'],
            ],
            default => [],
        };
    }
}
