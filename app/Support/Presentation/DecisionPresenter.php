<?php

namespace App\Support\Presentation;

use App\Support\Scenario\CfManufacturingScenarioDefinition;

/**
 * Plain names and values for decision fields (walkthrough items 36, 60, 74, 75).
 *
 * Codes never reach a student or faculty screen: P1/R1/SUP001/STANDARD/
 * fob_shipping_point become "Product 1", "Northeast", "China",
 * "Standard", "FOB shipping point". Fractions stored by the engine
 * (0.05) are shown and entered as percentages (5%).
 */
final class DecisionPresenter
{
    public const CATEGORY_LABELS = [
        'forecasting' => 'Forecasting',
        'sourcing' => 'Sourcing',
        'pricing' => 'Pricing',
        'production' => 'Production',
        'marketing' => 'Marketing',
        'quality' => 'Quality',
        'logistics' => 'Logistics',
        'notes_elections' => 'Accounting methods',
        'certification' => 'Certification',
        'dividend' => 'Dividend',
    ];

    /**
     * Inputs that ManufacturingCFEngine requires but never uses in a
     * calculation (item 76). They are filled with their default and not
     * shown to students, so no choice on screen is without effect.
     */
    public const INERT_FIELDS = [
        'forecasting' => ['forecast_confidence'],
        'pricing' => ['discount_policy'],
        'certification' => ['attestation_scope'],
    ];

    /** Stored as a fraction, entered and shown as a percentage (item 75). */
    public const PERCENT_FIELDS = [
        'primary_alloc', 'postponed_pct', 'marketing_budget_pct',
        'marketing_alloc_champions', 'marketing_alloc_growth', 'marketing_alloc_at_risk', 'marketing_alloc_other',
    ];

    public const PRICE_FIELDS = [
        'price_p1_r1', 'price_p1_r2', 'price_p1_r3', 'price_p2_r1', 'price_p2_r2', 'price_p2_r3',
    ];

    public const REGIONS = ['r1' => 'Northeast', 'r2' => 'Central', 'r3' => 'West'];

    public const PRODUCTS = ['p1' => 'Product 1', 'p2' => 'Product 2'];

    private const FIELD_LABELS = [
        'primary_supplier' => 'Main supplier',
        'primary_alloc' => 'Share of materials from the main supplier',
        'secondary_supplier' => 'Second supplier (for the rest)',
        'p1_production' => 'Product 1 units to make',
        'p2_production' => 'Product 2 units to make',
        'shifts' => 'Shifts (250,000 units of capacity each)',
        'postponed_flag' => 'Postponed production (make shells in the Northeast, finish in Central and West)',
        'postponed_pct' => 'Share of Central and West volume made this way',
        'marketing_budget_pct' => 'Marketing budget, as a share of revenue',
        'marketing_alloc_champions' => 'Champions segment',
        'marketing_alloc_growth' => 'Growth segment',
        'marketing_alloc_at_risk' => 'At-risk segment',
        'marketing_alloc_other' => 'Other segment',
        'inspection_level' => 'Quality inspection',
        'logistics_mode' => 'Shipping mode',
        'inventory_method' => 'Inventory method',
        'depreciation_method' => 'Depreciation method',
        'shipping_terms' => 'Shipping terms',
        'bad_debt_method' => 'Bad debt method',
        'warranty_method' => 'Warranty method',
        'excise_tax_treatment' => 'Excise duty',
        'attestation_scope' => 'Attestation',
        'forecast_confidence' => 'Forecast confidence',
        'discount_policy' => 'Discount policy',
        'dividend' => 'Dividend declared',
    ];

    private const VALUE_LABELS = [
        'NONE' => 'None', 'BASIC' => 'Basic', 'FULL' => 'Full',
        'STANDARD' => 'Standard', 'EXPRESS' => 'Express', 'AIR' => 'Air freight',
        'YES' => 'Yes', 'NO' => 'No',
        'low' => 'Low', 'medium' => 'Medium', 'high' => 'High',
        'none' => 'None', 'moderate' => 'Moderate', 'aggressive' => 'Aggressive',
        'fifo' => 'FIFO', 'weighted_average' => 'Weighted average', 'lifo' => 'LIFO',
        'straight_line' => 'Straight-line', 'double_declining_balance' => 'Double-declining balance',
        'units_of_production' => 'Units of production',
        'fob_shipping_point' => 'FOB shipping point', 'fob_destination' => 'FOB destination',
        'percentage_of_receivables' => 'Percentage of receivables', 'percentage_of_sales' => 'Percentage of sales',
        'expense_as_incurred' => 'Expense as incurred',
        'cogs_inclusion' => 'Included in cost of goods sold', 'separate_operating_expense' => 'Separate operating expense',
        'all_statements_attested' => 'All statements attested', 'not_attested' => 'Not attested',
    ];

    public static function categoryLabel(string $code): string
    {
        return self::CATEGORY_LABELS[$code] ?? ucfirst(str_replace('_', ' ', $code));
    }

    public static function fieldLabel(string $key, int $engineVersion = 1): string
    {
        if (preg_match('/^forecast_(p[12])_(r[123])$/', $key, $m)) {
            // Version 1: the forecast is the demand. Version 2: it is the team's estimate of orders.
            return $engineVersion === 2
                ? self::PRODUCTS[$m[1]].' orders you expect, '.self::REGIONS[$m[2]].' (units)'
                : self::PRODUCTS[$m[1]].' demand forecast, '.self::REGIONS[$m[2]].' (units)';
        }
        if (preg_match('/^price_(p[12])_(r[123])$/', $key, $m)) {
            return self::PRODUCTS[$m[1]].' price, '.self::REGIONS[$m[2]];
        }

        return self::FIELD_LABELS[$key] ?? ucfirst(str_replace('_', ' ', $key));
    }

    /**
     * @param  array<string, mixed>  $parameters  scenario parameters (for supplier names)
     */
    public static function valueLabel(string $key, mixed $value, array $parameters = []): string
    {
        if ($value === null || $value === '') {
            return '–';
        }
        if (in_array($key, ['primary_supplier', 'secondary_supplier'], true) && is_string($value)) {
            return $value === 'NONE' ? 'None' : self::supplierName($value, $parameters);
        }
        if (in_array($key, self::PERCENT_FIELDS, true) && is_numeric($value)) {
            return rtrim(rtrim(number_format((float) $value * 100, 1), '0'), '.').'%';
        }
        if ((in_array($key, self::PRICE_FIELDS, true) || $key === 'dividend') && is_numeric($value)) {
            return '$'.number_format((float) $value, 2);
        }
        if (is_numeric($value)) {
            return number_format((float) $value, fmod((float) $value, 1.0) === 0.0 ? 0 : 2);
        }
        if (is_string($value)) {
            return self::VALUE_LABELS[$value] ?? ucfirst(str_replace('_', ' ', $value));
        }

        return (string) $value;
    }

    public static function supplierName(string $code, array $parameters = []): string
    {
        $key = 'supplier_'.strtolower($code).'_name';

        return (string) ($parameters[$key] ?? CfManufacturingScenarioDefinition::parameters()[$key] ?? $code);
    }

    /** "China: $120 a unit, 3.0% defects, 45-day lead time" for choosing a supplier. */
    public static function supplierDetail(string $code, array $parameters = []): string
    {
        if ($code === 'NONE') {
            return 'None';
        }
        $defaults = CfManufacturingScenarioDefinition::parameters();
        $p = fn (string $suffix) => $parameters['supplier_'.strtolower($code).'_'.$suffix] ?? $defaults['supplier_'.strtolower($code).'_'.$suffix] ?? null;
        $lead = (int) $p('lead_days');

        return sprintf(
            '%s: $%s a unit, %s%% defects, %s',
            $p('name') ?? $code,
            number_format((float) $p('cost'), 0),
            number_format((float) $p('defect_rate') * 100, 1),
            $lead === 0 ? 'no lead time' : $lead.'-day lead time',
        );
    }

    /**
     * Engine version 2 reads fewer inputs (ED8, ED10): the marketing segment
     * shares and postponed production are gone as well.
     */
    public const INERT_FIELDS_V2 = [
        'forecasting' => ['forecast_confidence'],
        'pricing' => ['discount_policy'],
        'certification' => ['attestation_scope'],
        'marketing' => ['marketing_alloc_champions', 'marketing_alloc_growth', 'marketing_alloc_at_risk', 'marketing_alloc_other'],
        'production' => ['postponed_flag', 'postponed_pct'],
        // ED12: inventory is first-in, first-out in version 2.
        'notes_elections' => ['inventory_method'],
    ];

    public static function isInert(string $category, string $key, int $engineVersion = 1): bool
    {
        $inert = $engineVersion === 2 ? self::INERT_FIELDS_V2 : self::INERT_FIELDS;

        return in_array($key, $inert[$category] ?? [], true);
    }

    /**
     * Rows for a read-only decisions table, one column per quarter.
     *
     * @param  array<int|string, array{payload: array<string, array<string, mixed>>|null, chosen_by: array<string, string>, note?: string|null}>  $columns
     * @return list<array{type: string, label: string, values: list<string>}>
     */
    public static function rows(array $columns, array $parameters = []): array
    {
        $columns = array_values($columns);
        $categories = [];
        foreach ($columns as $column) {
            foreach (array_keys($column['payload'] ?? []) as $category) {
                $categories[$category] = true;
            }
        }
        $order = array_keys(self::CATEGORY_LABELS);
        $categories = array_values(array_filter($order, fn ($c) => isset($categories[$c])));

        $rows = [];
        foreach ($categories as $category) {
            $keys = [];
            foreach ($columns as $column) {
                foreach (array_keys($column['payload'][$category] ?? []) as $key) {
                    if (! self::isInert($category, $key)) {
                        $keys[$key] = true;
                    }
                }
            }
            if ($keys === []) {
                continue;
            }
            $rows[] = [
                'type' => 'heading',
                'label' => self::categoryLabel($category),
                'values' => array_map(fn ($column) => $column['chosen_by'][$category] ?? '', $columns),
            ];
            foreach (array_keys($keys) as $key) {
                $rows[] = [
                    'type' => 'line',
                    'label' => self::fieldLabel($key),
                    'values' => array_map(
                        fn ($column) => array_key_exists($key, $column['payload'][$category] ?? [])
                            ? self::valueLabel($key, $column['payload'][$category][$key], $parameters)
                            : '–',
                        $columns,
                    ),
                ];
            }
        }

        return $rows;
    }
}
