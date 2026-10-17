<?php

namespace App\Support\Scenario;

use Illuminate\Support\Str;

class CfManufacturingScenarioDefinition
{
    public const CODE = 'cf_manufacturing';

    public const OPENING_POSITION = [
        'cash' => 100000,
        'plant_investment' => 100000000,
        'total_assets' => 100100000,
        'liabilities' => 0,
        'corporate_capitalization' => 100100000,
        'total_liabilities_plus_equity' => 100100000,
    ];

    /**
     * @return array<int, array{code: string, display_name: string, provenance: string}>
     */
    public static function categories(): array
    {
        return [
            ['code' => 'forecasting', 'display_name' => 'Forecasting', 'provenance' => 'legacy-derived'],
            ['code' => 'sourcing', 'display_name' => 'Sourcing', 'provenance' => 'legacy-derived'],
            ['code' => 'pricing', 'display_name' => 'Pricing', 'provenance' => 'legacy-derived'],
            ['code' => 'production', 'display_name' => 'Production', 'provenance' => 'legacy-derived'],
            ['code' => 'marketing', 'display_name' => 'Marketing', 'provenance' => 'legacy-derived'],
            ['code' => 'quality', 'display_name' => 'Quality', 'provenance' => 'legacy-derived'],
            ['code' => 'logistics', 'display_name' => 'Logistics', 'provenance' => 'legacy-derived'],
            ['code' => 'notes_elections', 'display_name' => 'Notes Elections', 'provenance' => 'authored-cf-field'],
            ['code' => 'certification', 'display_name' => 'Certification', 'provenance' => 'authored-cf-field'],
        ];
    }

    /**
     * @return array{fields: array<int, array<string, mixed>>, version: string, source: string}
     */
    public static function inputFieldsForCategory(string $code): array
    {
        $fields = [
            'forecasting' => [
                self::numberField('forecast_p1_r1', 'P1 Demand Forecast - R1 (Northeast)', 0, 'Estimated P1 unit demand for the Northeast region.'),
                self::numberField('forecast_p1_r2', 'P1 Demand Forecast - R2 (Central)', 0, 'Estimated P1 unit demand for the Central region.'),
                self::numberField('forecast_p1_r3', 'P1 Demand Forecast - R3 (West)', 0, 'Estimated P1 unit demand for the West region.'),
                self::numberField('forecast_p2_r1', 'P2 Demand Forecast - R1 (Northeast)', 0, 'Estimated P2 unit demand for the Northeast region.'),
                self::numberField('forecast_p2_r2', 'P2 Demand Forecast - R2 (Central)', 0, 'Estimated P2 unit demand for the Central region.'),
                self::numberField('forecast_p2_r3', 'P2 Demand Forecast - R3 (West)', 0, 'Estimated P2 unit demand for the West region.'),
                ['key' => 'forecast_confidence', 'label' => 'Forecast Confidence', 'type' => 'dropdown', 'allowed_values' => ['low', 'medium', 'high'], 'default' => 'medium', 'help_text' => "Confidence level for this quarter's demand forecast."],
            ],
            'sourcing' => [
                ['key' => 'primary_supplier', 'label' => 'Primary Supplier', 'type' => 'dropdown', 'allowed_values' => ['SUP001', 'SUP002', 'SUP003', 'SUP004', 'SUP005', 'SUP006'], 'default' => 'SUP001', 'help_text' => 'Primary raw material supplier.'],
                self::numberField('primary_alloc', 'Primary Supplier Allocation', 1.0, 'Fraction of raw material volume from primary supplier.', 0.5, 1.0),
                ['key' => 'secondary_supplier', 'label' => 'Secondary Supplier', 'type' => 'dropdown', 'allowed_values' => ['NONE', 'SUP001', 'SUP002', 'SUP003', 'SUP004', 'SUP005', 'SUP006'], 'default' => 'NONE', 'help_text' => 'Secondary supplier for remaining raw material allocation.'],
            ],
            'pricing' => [
                self::parameterField('price_p1_r1', 'P1 Price - R1 (Northeast)', 'p1_base_price'),
                self::parameterField('price_p1_r2', 'P1 Price - R2 (Central)', 'p1_base_price'),
                self::parameterField('price_p1_r3', 'P1 Price - R3 (West)', 'p1_base_price'),
                self::parameterField('price_p2_r1', 'P2 Price - R1 (Northeast)', 'p2_base_price'),
                self::parameterField('price_p2_r2', 'P2 Price - R2 (Central)', 'p2_base_price'),
                self::parameterField('price_p2_r3', 'P2 Price - R3 (West)', 'p2_base_price'),
                ['key' => 'discount_policy', 'label' => 'Discount Policy', 'type' => 'dropdown', 'allowed_values' => ['none', 'moderate', 'aggressive'], 'default' => 'none', 'help_text' => 'Select the discount posture for this quarter.'],
            ],
            'production' => [
                self::numberField('p1_production', 'P1 Production Volume (units)', 0, 'Units of P1 to produce this quarter.'),
                self::numberField('p2_production', 'P2 Production Volume (units)', 0, 'Units of P2 to produce this quarter.'),
                self::numberField('shifts', 'Number of Shifts', 1, 'Production shifts per period (1-3).', 1, 3),
                ['key' => 'postponed_flag', 'label' => 'Use Postponed Production Strategy?', 'type' => 'dropdown', 'allowed_values' => ['YES', 'NO'], 'default' => 'NO', 'help_text' => 'Produce shells in R1, finish in R2/R3. Reduces excise duties by 60%.'],
                self::numberField('postponed_pct', '% of R2/R3 Volume Postponed', 0, 'Percentage of R2/R3 production using postponed strategy.', 0, 1),
            ],
            'marketing' => [
                self::numberField('marketing_budget_pct', 'Marketing Budget % of Revenue', 0.05, 'Marketing spend as fraction of gross revenue.', 0, 0.20),
                self::numberField('marketing_alloc_champions', 'Champions Segment Allocation', 0.50, 'Fraction of marketing budget directed to Champions segment.', 0, 1.0),
                self::numberField('marketing_alloc_growth', 'Growth Segment Allocation', 0.30, 'Fraction of marketing budget directed to Growth segment.', 0, 1.0),
                self::numberField('marketing_alloc_at_risk', 'At-Risk Segment Allocation', 0.15, 'Fraction of marketing budget directed to At-Risk segment.', 0, 1.0),
                self::numberField('marketing_alloc_other', 'Other Segment Allocation', 0.05, 'Fraction of marketing budget directed to Other segment.', 0, 1.0),
            ],
            'quality' => [
                ['key' => 'inspection_level', 'label' => 'Quality Inspection Level', 'type' => 'dropdown', 'allowed_values' => ['NONE', 'BASIC', 'FULL'], 'default' => 'NONE', 'help_text' => 'NONE: $0/unit, all defects escape | BASIC: $2/unit, 70% caught | FULL: $5/unit, 95% caught.'],
            ],
            'logistics' => [
                ['key' => 'logistics_mode', 'label' => 'Logistics Mode', 'type' => 'dropdown', 'allowed_values' => ['STANDARD', 'EXPRESS', 'AIR'], 'default' => 'STANDARD', 'help_text' => 'STANDARD: $8/unit, 88% on-time | EXPRESS: $15/unit, 95% on-time | AIR: $35/unit, 99% on-time.'],
            ],
            'notes_elections' => [
                self::selectField('inventory_method', 'Inventory Method', 'fifo', ['fifo', 'weighted_average', 'lifo']),
                self::selectField('depreciation_method', 'Depreciation Method', 'straight_line', ['straight_line', 'double_declining_balance', 'units_of_production']),
                self::selectField('shipping_terms', 'Shipping Terms', 'fob_shipping_point', ['fob_shipping_point', 'fob_destination']),
                self::selectField('bad_debt_method', 'Bad Debt Method', 'percentage_of_receivables', ['percentage_of_receivables', 'percentage_of_sales']),
                self::selectField('warranty_method', 'Warranty Method', 'percentage_of_sales', ['percentage_of_sales', 'expense_as_incurred']),
                self::selectField('excise_tax_treatment', 'Excise Tax Treatment', 'cogs_inclusion', ['cogs_inclusion', 'separate_operating_expense']),
            ],
            'certification' => [
                self::selectField('attestation_scope', 'Attestation Scope', 'all_statements_attested', ['all_statements_attested', 'not_attested']),
            ],
        ];

        return [
            'fields' => $fields[$code] ?? [],
            'version' => in_array($code, ['notes_elections', 'certification'], true) ? 'phase_2_authored' : 'phase_4_batch_2',
            'source' => in_array($code, ['notes_elections', 'certification'], true) ? 'source_inventory_approved_2026_07_21' : 'legacy_cf_input_fields',
        ];
    }

    /**
     * @return array<string, int|float|string|bool>
     */
    public static function parameters(): array
    {
        return [
            'p1_base_price' => 270, 'p2_base_price' => 350, 'capacity_per_shift' => 250000, 'overtime_premium' => 0.25, 'carrying_cost_rate' => 0.05, 'admin_overhead' => 500000,
            'supplier_sup001_name' => 'China', 'supplier_sup001_cost' => 120, 'supplier_sup001_lead_days' => 45, 'supplier_sup001_defect_rate' => 0.030, 'supplier_sup001_region' => 'global',
            'supplier_sup002_name' => 'USA', 'supplier_sup002_cost' => 195, 'supplier_sup002_lead_days' => 0, 'supplier_sup002_defect_rate' => 0.008, 'supplier_sup002_region' => 'regional',
            'supplier_sup003_name' => 'Germany', 'supplier_sup003_cost' => 185, 'supplier_sup003_lead_days' => 30, 'supplier_sup003_defect_rate' => 0.005, 'supplier_sup003_region' => 'global',
            'supplier_sup004_name' => 'Vietnam', 'supplier_sup004_cost' => 115, 'supplier_sup004_lead_days' => 45, 'supplier_sup004_defect_rate' => 0.025, 'supplier_sup004_region' => 'global',
            'supplier_sup005_name' => 'Mexico', 'supplier_sup005_cost' => 150, 'supplier_sup005_lead_days' => 14, 'supplier_sup005_defect_rate' => 0.015, 'supplier_sup005_region' => 'regional',
            'supplier_sup006_name' => 'India', 'supplier_sup006_cost' => 110, 'supplier_sup006_lead_days' => 45, 'supplier_sup006_defect_rate' => 0.028, 'supplier_sup006_region' => 'global',
            'seasonality_q1' => 0.85, 'seasonality_q2' => 1.00, 'seasonality_q3' => 1.00, 'seasonality_q4' => 1.25, 'demand_growth_standard_deviation' => 0.055,
            'marketing_champions_base_revenue_pct' => 0.50, 'marketing_champions_retention_sensitivity' => 0.3, 'marketing_growth_base_revenue_pct' => 0.30, 'marketing_growth_retention_sensitivity' => 0.6,
            'marketing_at_risk_base_revenue_pct' => 0.15, 'marketing_at_risk_retention_sensitivity' => 1.2, 'marketing_other_base_revenue_pct' => 0.05, 'marketing_other_retention_sensitivity' => 0.2,
            'inspection_none_catch_rate' => 0.00, 'inspection_basic_catch_rate' => 0.70, 'inspection_full_catch_rate' => 0.95, 'inspection_none_cost' => 0, 'inspection_basic_cost' => 2, 'inspection_full_cost' => 5,
            'logistics_standard_cost' => 8, 'logistics_express_cost' => 15, 'logistics_air_cost' => 35, 'logistics_standard_on_time_rate' => 0.88, 'logistics_express_on_time_rate' => 0.95, 'logistics_air_on_time_rate' => 0.99,
            'excise_rate_r2' => 0.04, 'excise_rate_r3' => 0.06, 'postponed_duty_saving' => 0.60, 'tax_rate' => 0.25,
            'credit_tier_excellent_min_score' => 80, 'credit_tier_excellent_rate' => 0.08, 'credit_tier_excellent_limit' => 100000000, 'credit_tier_good_min_score' => 60, 'credit_tier_good_rate' => 0.12, 'credit_tier_good_limit' => 75000000,
            'credit_tier_fair_min_score' => 40, 'credit_tier_fair_rate' => 0.18, 'credit_tier_fair_limit' => 50000000, 'credit_tier_poor_min_score' => 20, 'credit_tier_poor_rate' => 0.24, 'credit_tier_poor_limit' => 25000000,
            'credit_tier_distressed_min_score' => 0, 'credit_tier_distressed_rate' => 0.30, 'credit_tier_distressed_limit' => 12500000,
            'warranty_claims_rate' => 0.40, 'warranty_cost_per_claim' => 150, 'perfect_order_on_time_weight' => 0.25, 'perfect_order_in_full_weight' => 0.25, 'perfect_order_damage_free_weight' => 0.25, 'perfect_order_docs_weight' => 0.25,
            'base_demand_r1' => 400000, 'base_demand_r2' => 300000, 'base_demand_r3' => 250000, 'price_elasticity' => -1.2, 'rebate_rate' => 0.02, 'returns_rate_on_defects' => 0.30,
            'base_labor_per_unit' => 45, 'fixed_overhead_per_period' => 1500000, 'inflation_rate' => 0.03, 'inventory_raw_mat_ratio' => 0.15, 'inventory_wip_ratio' => 0.30, 'inventory_in_transit_ratio' => 0.08,
            'gross_ppe_mfg' => 20000000, 'gross_ppe_tech' => 5000000, 'gross_ppe_dc' => 15000000, 'sl_penalty_per_unit' => 5, 'sl_payment_rate' => 0.50, 'excise_payment_rate' => 0.80,
            'rebate_payment_rate' => 0.70, 'capex_mfg' => 500000, 'capex_tech' => 200000, 'capex_dc' => 100000, 'borrow_repay_rate' => 0.25, 'lease_payment_per_period' => 50000, 'minimum_cash_buffer' => 1000000,
            'opening_cash' => 100000, 'opening_plant_investment' => 100000000, 'opening_total_assets' => 100100000, 'opening_liabilities' => 0, 'opening_corporate_capitalization' => 100100000, 'opening_total_liabilities_plus_equity' => 100100000,
        ];
    }

    /**
     * @return array<string, int>
     */
    public static function q0ForecastingPayload(): array
    {
        return [
            'forecast_p1_r1' => 30000,
            'forecast_p1_r2' => 40000,
            'forecast_p1_r3' => 60000,
            'forecast_p2_r1' => 27000,
            'forecast_p2_r2' => 18000,
            'forecast_p2_r3' => 16000,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function fixedWarmupPayload(string $categoryCode): array
    {
        return match ($categoryCode) {
            'notes_elections' => ['inventory_method' => 'fifo', 'depreciation_method' => 'straight_line', 'shipping_terms' => 'fob_shipping_point', 'bad_debt_method' => 'percentage_of_receivables', 'warranty_method' => 'percentage_of_sales', 'excise_tax_treatment' => 'cogs_inclusion'],
            'certification' => ['attestation_scope' => 'all_statements_attested'],
            default => [],
        };
    }

    public static function defaultOpenQuarterForCategory(string $categoryCode): ?int
    {
        return match ($categoryCode) {
            'forecasting', 'sourcing', 'production' => 4,
            default => null,
        };
    }

    public static function parameterDataType(int|float|string|bool $value): string
    {
        return match (true) {
            is_bool($value) => 'boolean',
            is_int($value), is_float($value) => 'number',
            default => 'string',
        };
    }

    public static function parameterDescription(string $key): string
    {
        return match ($key) {
            'opening_cash' => 'Approved Quarter 0 cash balance.',
            'opening_plant_investment' => 'Approved Quarter 0 plant investment balance.',
            'opening_total_assets' => 'Approved Quarter 0 total assets.',
            'opening_liabilities' => 'Approved Quarter 0 liabilities.',
            'opening_corporate_capitalization' => 'Approved reconciled Quarter 0 corporate capitalization.',
            'opening_total_liabilities_plus_equity' => 'Approved Quarter 0 total liabilities plus equity.',
            'excise_payment_rate' => 'Fraction of accrued excise duty remitted this period.',
            default => Str::of($key)->replace('_', ' ')->title()->prepend('Manufacturing CF parameter: ')->toString(),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function numberField(string $key, string $label, int|float $default, string $helpText, int|float $min = 0, int|float|null $max = null): array
    {
        $validation = ['min' => $min];

        if ($max !== null) {
            $validation['max'] = $max;
        }

        return ['key' => $key, 'label' => $label, 'type' => 'number', 'default' => $default, 'validation' => $validation, 'help_text' => $helpText];
    }

    /**
     * @return array<string, mixed>
     */
    private static function parameterField(string $key, string $label, string $parameter): array
    {
        return ['key' => $key, 'label' => $label, 'type' => 'number', 'default_from_parameter' => $parameter, 'validation' => ['min' => 0], 'help_text' => 'Default from scenario base price.'];
    }

    /**
     * @param  array<int, string>  $allowedValues
     * @return array<string, mixed>
     */
    private static function selectField(string $key, string $label, string $default, array $allowedValues): array
    {
        return ['key' => $key, 'label' => $label, 'type' => 'select', 'allowed_values' => $allowedValues, 'default' => $default, 'provenance' => 'authored-cf-field'];
    }
}
