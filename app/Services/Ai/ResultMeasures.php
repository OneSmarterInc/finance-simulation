<?php

namespace App\Services\Ai;

use App\Models\FirmEngineResult;

/**
 * The stored measures Ask Claude can read and show (U1 b, layer 2). Every
 * value comes from a firm's stored engine result; a few are the stored
 * figures added together (units across regions, equity's three lines), and
 * value created adds dividends paid since Quarter 4, as the practice run does.
 * The model never supplies a number: it names measures, the app fills them in.
 */
final class ResultMeasures
{
    /** key => [label, statement or note the figure belongs to, format] */
    public const MEASURES = [
        'net_revenue' => ['Net revenue', 'Income statement', 'money'],
        'cost_of_goods_sold' => ['Cost of goods sold', 'Income statement', 'money'],
        'gross_profit' => ['Gross profit', 'Income statement', 'money'],
        'operating_expenses' => ['Operating expenses', 'Income statement', 'money'],
        'operating_income' => ['Operating income', 'Income statement', 'money'],
        'net_income' => ['Net income', 'Income statement', 'money'],
        'cash' => ['Cash at quarter end', 'Balance sheet', 'money'],
        'accounts_receivable' => ['Accounts receivable', 'Balance sheet', 'money'],
        'inventory' => ['Inventory', 'Balance sheet', 'money'],
        'accounts_payable' => ['Accounts payable', 'Balance sheet', 'money'],
        'warranty_reserve' => ['Warranty reserve', 'Balance sheet', 'money'],
        'total_equity' => ['Total equity', 'Balance sheet', 'money'],
        'operating_cash_flow' => ['Net cash from operating activities', 'Cash flow statement', 'money'],
        'dividends_paid' => ['Dividends paid', 'Cash flow statement', 'money'],
        'units_ordered' => ['Units customers ordered', 'Revenue note', 'units'],
        'units_sold' => ['Units sold', 'Revenue note', 'units'],
        'orders_not_filled' => ['Orders not filled', 'Revenue note', 'units'],
        'units_in_stock' => ['Units left in stock', 'Inventory note', 'units'],
        'goods_in_transit' => ['Materials in transit', 'Inventory note', 'money'],
        'operating_margin' => ['Operating margin', 'Ratios note', 'percent'],
        'current_ratio' => ['Current ratio', 'Ratios note', 'ratio'],
        'value_created' => ['Value created (equity plus dividends paid from Quarter 4)', 'Worked out from the balance sheet and cash flow', 'money'],
    ];

    /**
     * @param  iterable<FirmEngineResult>  $results  one firm's results in quarter order (for value created)
     * @return float|null null when the stored result doesn't carry the measure
     */
    public static function value(string $measure, FirmEngineResult $result, iterable $results = []): ?float
    {
        $f = (array) $result->fields;
        $is = (array) $result->income_statement;
        $sum = function (string $prefix) use ($f): ?float {
            $keys = array_filter(array_keys($f), fn ($k) => str_starts_with($k, $prefix));

            return $keys === [] ? null : array_sum(array_map(fn ($k) => (float) $f[$k], $keys));
        };
        $field = fn (string $key): ?float => array_key_exists($key, $f) ? (float) $f[$key] : null;

        return match ($measure) {
            'net_revenue', 'cost_of_goods_sold', 'gross_profit', 'operating_expenses', 'operating_income', 'net_income' => isset($is[$measure]) ? (float) $is[$measure] : null,
            'cash' => $field('bs_cash'),
            'accounts_receivable' => $field('bs_ar'),
            'inventory' => $field('bs_inventory'),
            'accounts_payable' => $field('bs_ap'),
            'warranty_reserve' => $field('bs_warranty_res'),
            'total_equity' => (float) ($f['bs_common'] ?? 0) + (float) ($f['bs_retained'] ?? 0) + (float) ($f['bs_curr_ni'] ?? 0),
            'operating_cash_flow' => isset($result->cash_flow_statement['operating_cash_flow']) ? (float) $result->cash_flow_statement['operating_cash_flow'] : null,
            'dividends_paid' => array_key_exists('cf_equity', $f) ? -(float) $f['cf_equity'] : null,
            'units_ordered' => $sum('n2_ord_'),
            'units_sold' => $sum('n2_sold_'),
            'orders_not_filled' => $sum('n2_short_'),
            'units_in_stock' => array_key_exists('n3_units_p1', $f) ? (float) $f['n3_units_p1'] + (float) ($f['n3_units_p2'] ?? 0) : null,
            'goods_in_transit' => $field('n3_in_transit'),
            'operating_margin' => array_key_exists('n5_cov_margin', $f) ? (float) $f['n5_cov_margin'] : null,
            'current_ratio' => $field('n5_cov_curr'),
            'value_created' => self::valueCreated($result, $results),
            default => null,
        };
    }

    public static function format(string $measure, ?float $value): string
    {
        if ($value === null) {
            return '–';
        }

        return match (self::MEASURES[$measure][2] ?? 'money') {
            'units' => number_format($value),
            'percent' => number_format($value * 100, 1).'%',
            'ratio' => number_format($value, 2).'x',
            default => ($value < 0 ? '−$' : '$').number_format(abs($value), 2),
        };
    }

    /** @param iterable<FirmEngineResult> $results */
    private static function valueCreated(FirmEngineResult $result, iterable $results): float
    {
        $f = (array) $result->fields;
        $paid = 0.0;
        foreach ($results as $r) {
            if ($r->quarter_number >= 4 && $r->quarter_number <= $result->quarter_number) {
                $paid += -(float) (((array) $r->fields)['cf_equity'] ?? 0);
            }
        }

        return (float) ($f['bs_common'] ?? 0) + (float) ($f['bs_retained'] ?? 0) + (float) ($f['bs_curr_ni'] ?? 0) + $paid;
    }
}
