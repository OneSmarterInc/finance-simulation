<?php

namespace Tests\Feature\Scenario;

use App\Models\Scenario;
use App\Models\ScenarioDecisionCategory;
use App\Models\ScenarioQuarterDecision;
use Database\Seeders\CfManufacturingScenarioSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScenarioDataLayerTest extends TestCase
{
    use RefreshDatabase;

    public function test_cf_manufacturing_scenario_seeds_exact_phase_2_counts(): void
    {
        $this->seed(CfManufacturingScenarioSeeder::class);

        $scenario = Scenario::query()->where('code', 'cf_manufacturing')->firstOrFail();

        $this->assertSame('CF Manufacturing', $scenario->name);
        $this->assertTrue($scenario->active);
        $this->assertSame(9, $scenario->decisionCategories()->count());
        $this->assertSame(117, $scenario->parameters()->count());
        $this->assertSame(7, $scenario->quarterDecisions()->count());

        $this->seed(CfManufacturingScenarioSeeder::class);

        $this->assertSame(1, Scenario::query()->where('code', 'cf_manufacturing')->count());
        $this->assertSame(9, $scenario->decisionCategories()->count());
        $this->assertSame(117, $scenario->parameters()->count());
        $this->assertSame(7, $scenario->quarterDecisions()->count());
        $expectedParameterKeys = $this->expectedParameterKeys();
        sort($expectedParameterKeys);

        $this->assertSame($expectedParameterKeys, $scenario->parameters()->orderBy('parameter_key')->pluck('parameter_key')->all());

        $duplicates = ScenarioQuarterDecision::query()
            ->get(['scenario_id', 'quarter_number', 'scenario_decision_category_id'])
            ->groupBy(fn (ScenarioQuarterDecision $decision): string => implode(':', [
                $decision->scenario_id,
                $decision->quarter_number,
                $decision->scenario_decision_category_id,
            ]))
            ->filter(fn ($group): bool => $group->count() > 1)
            ->count();

        $this->assertSame(0, $duplicates);
    }

    public function test_opening_position_uses_approved_reconciled_values(): void
    {
        $this->seed();

        $scenario = Scenario::query()->where('code', 'cf_manufacturing')->firstOrFail();

        $expectedOpeningPosition = [
            'cash' => 100000,
            'plant_investment' => 100000000,
            'total_assets' => 100100000,
            'liabilities' => 0,
            'corporate_capitalization' => 100100000,
            'total_liabilities_plus_equity' => 100100000,
        ];
        $actualOpeningPosition = $scenario->opening_position;
        ksort($expectedOpeningPosition);
        ksort($actualOpeningPosition);

        $this->assertSame($expectedOpeningPosition, $actualOpeningPosition);

        $this->assertSame(100100000, 100000 + 100000000);
        $this->assertSame(100100000, 0 + 100100000);

        $parameterKeys = $scenario->parameters()->pluck('parameter_key')->all();

        $this->assertSame(0, $scenario->parameters()->where('parameter_key', 'like', 'starting_%')->count());
        $this->assertSame([], array_values(array_filter(
            $parameterKeys,
            fn (string $parameterKey): bool => str_starts_with($parameterKey, 'starting_'),
        )));
    }

    public function test_q0_forecasting_baseline_is_exact_and_source_only(): void
    {
        $this->seed();

        $scenario = Scenario::query()->where('code', 'cf_manufacturing')->firstOrFail();
        $forecasting = $scenario->decisionCategories()->where('code', 'forecasting')->firstOrFail();

        $decision = ScenarioQuarterDecision::query()
            ->where('scenario_id', $scenario->id)
            ->where('scenario_decision_category_id', $forecasting->id)
            ->where('quarter_number', 0)
            ->firstOrFail();

        $this->assertSame(1, ScenarioQuarterDecision::query()
            ->where('scenario_id', $scenario->id)
            ->where('scenario_decision_category_id', $forecasting->id)
            ->where('quarter_number', 0)
            ->count());

        $this->assertSame([
            'forecast_p1_r1' => 30000,
            'forecast_p1_r2' => 40000,
            'forecast_p1_r3' => 60000,
            'forecast_p2_r1' => 27000,
            'forecast_p2_r2' => 18000,
            'forecast_p2_r3' => 16000,
        ], $decision->payload);
        $this->assertSame([], $decision->null_fields);
        $this->assertSame('q0_scm_industry_initial_demand', $decision->source);
        $this->assertArrayNotHasKey('forecast_confidence', $decision->payload);
        $this->assertArrayNotHasKey('inventory_method', $decision->payload);
        $this->assertArrayNotHasKey('attestation_scope', $decision->payload);
    }

    public function test_operational_q1_through_q3_rows_are_not_seeded(): void
    {
        $this->seed();

        $scenario = Scenario::query()->where('code', 'cf_manufacturing')->firstOrFail();

        foreach (['forecasting', 'sourcing', 'pricing', 'production', 'marketing', 'quality', 'logistics'] as $categoryCode) {
            $category = $scenario->decisionCategories()->where('code', $categoryCode)->firstOrFail();

            $this->assertSame(0, ScenarioQuarterDecision::query()
                ->where('scenario_id', $scenario->id)
                ->where('scenario_decision_category_id', $category->id)
                ->whereIn('quarter_number', [1, 2, 3])
                ->count(), "{$categoryCode} should not have Q1-Q3 scenario-quarter rows.");
        }
    }

    public function test_fixed_warmup_election_and_certification_rows_are_exact(): void
    {
        $this->seed();

        $scenario = Scenario::query()->where('code', 'cf_manufacturing')->firstOrFail();
        $notes = $scenario->decisionCategories()->where('code', 'notes_elections')->firstOrFail();
        $certification = $scenario->decisionCategories()->where('code', 'certification')->firstOrFail();

        $this->assertSame([1, 2, 3], ScenarioQuarterDecision::query()
            ->where('scenario_id', $scenario->id)
            ->where('scenario_decision_category_id', $notes->id)
            ->orderBy('quarter_number')
            ->pluck('quarter_number')
            ->all());

        $this->assertSame([1, 2, 3], ScenarioQuarterDecision::query()
            ->where('scenario_id', $scenario->id)
            ->where('scenario_decision_category_id', $certification->id)
            ->orderBy('quarter_number')
            ->pluck('quarter_number')
            ->all());

        foreach ([1, 2, 3] as $quarterNumber) {
            $notesDecision = ScenarioQuarterDecision::query()
                ->where('scenario_id', $scenario->id)
                ->where('scenario_decision_category_id', $notes->id)
                ->where('quarter_number', $quarterNumber)
                ->firstOrFail();

            $certificationDecision = ScenarioQuarterDecision::query()
                ->where('scenario_id', $scenario->id)
                ->where('scenario_decision_category_id', $certification->id)
                ->where('quarter_number', $quarterNumber)
                ->firstOrFail();

            $expectedNotesPayload = [
                'inventory_method' => 'fifo',
                'depreciation_method' => 'straight_line',
                'shipping_terms' => 'fob_shipping_point',
                'bad_debt_method' => 'percentage_of_receivables',
                'warranty_method' => 'percentage_of_sales',
                'excise_tax_treatment' => 'cogs_inclusion',
            ];
            $actualNotesPayload = $notesDecision->payload;
            ksort($expectedNotesPayload);
            ksort($actualNotesPayload);

            $this->assertSame($expectedNotesPayload, $actualNotesPayload);
            $this->assertSame([], $notesDecision->null_fields);
            $this->assertSame('fixed_warmup_election_baseline', $notesDecision->source);

            $this->assertSame(['attestation_scope' => 'all_statements_attested'], $certificationDecision->payload);
            $this->assertSame([], $certificationDecision->null_fields);
            $this->assertSame('fixed_warmup_election_baseline', $certificationDecision->source);
        }
    }

    public function test_base_demand_parameters_remain_market_level_and_distinct_from_q0_forecast_payload(): void
    {
        $this->seed();

        $scenario = Scenario::query()->where('code', 'cf_manufacturing')->firstOrFail();
        $forecasting = $scenario->decisionCategories()->where('code', 'forecasting')->firstOrFail();
        $q0Forecasting = ScenarioQuarterDecision::query()
            ->where('scenario_id', $scenario->id)
            ->where('scenario_decision_category_id', $forecasting->id)
            ->where('quarter_number', 0)
            ->firstOrFail();

        $this->assertSame(400000, $scenario->parameters()->where('parameter_key', 'base_demand_r1')->firstOrFail()->parameter_value['value']);
        $this->assertSame(300000, $scenario->parameters()->where('parameter_key', 'base_demand_r2')->firstOrFail()->parameter_value['value']);
        $this->assertSame(250000, $scenario->parameters()->where('parameter_key', 'base_demand_r3')->firstOrFail()->parameter_value['value']);

        $this->assertArrayNotHasKey('base_demand_r1', $q0Forecasting->payload);
        $this->assertArrayNotHasKey('base_demand_r2', $q0Forecasting->payload);
        $this->assertArrayNotHasKey('base_demand_r3', $q0Forecasting->payload);
        $this->assertNotSame(400000, $q0Forecasting->payload['forecast_p1_r1']);
        $this->assertNotSame(300000, $q0Forecasting->payload['forecast_p1_r2']);
        $this->assertNotSame(250000, $q0Forecasting->payload['forecast_p1_r3']);
    }

    public function test_calibrated_price_parameters_and_parameter_backed_decision_defaults_are_pinned(): void
    {
        $this->seed(CfManufacturingScenarioSeeder::class);

        $scenario = Scenario::query()->where('code', 'cf_manufacturing')->firstOrFail();
        $parameters = $scenario->parameters()
            ->whereIn('parameter_key', ['p1_base_price', 'p2_base_price'])
            ->pluck('parameter_value', 'parameter_key');
        $pricingFields = collect($scenario->decisionCategories()
            ->where('code', 'pricing')
            ->firstOrFail()
            ->input_fields['fields'])
            ->keyBy('key');

        $this->assertSame(270, $parameters['p1_base_price']['value']);
        $this->assertSame(350, $parameters['p2_base_price']['value']);

        foreach (['price_p1_r1', 'price_p1_r2', 'price_p1_r3'] as $field) {
            $this->assertSame('p1_base_price', $pricingFields[$field]['default_from_parameter']);
        }

        foreach (['price_p2_r1', 'price_p2_r2', 'price_p2_r3'] as $field) {
            $this->assertSame('p2_base_price', $pricingFields[$field]['default_from_parameter']);
        }
    }

    public function test_notes_elections_and_certification_use_approved_authored_schemas(): void
    {
        $this->seed();

        $scenario = Scenario::query()->where('code', 'cf_manufacturing')->firstOrFail();
        $notes = $scenario->decisionCategories()->where('code', 'notes_elections')->firstOrFail();
        $certification = $scenario->decisionCategories()->where('code', 'certification')->firstOrFail();

        $this->assertSame($this->expectedNotesElectionFields(), $this->canonicalizeFieldDefinitions($notes->input_fields['fields']));
        $this->assertSame($this->expectedCertificationFields(), $this->canonicalizeFieldDefinitions($certification->input_fields['fields']));

        foreach ([$notes, $certification] as $category) {
            foreach ($category->input_fields['fields'] as $field) {
                $this->assertSame('select', $field['type']);
                $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $field['default']);

                foreach ($field['allowed_values'] as $allowedValue) {
                    $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $allowedValue);
                }
            }
        }

        foreach ([1, 2, 3] as $quarterNumber) {
            $notesDecision = ScenarioQuarterDecision::query()
                ->where('scenario_id', $scenario->id)
                ->where('quarter_number', $quarterNumber)
                ->where('scenario_decision_category_id', $notes->id)
                ->firstOrFail();

            $certificationDecision = ScenarioQuarterDecision::query()
                ->where('scenario_id', $scenario->id)
                ->where('quarter_number', $quarterNumber)
                ->where('scenario_decision_category_id', $certification->id)
                ->firstOrFail();

            foreach (array_merge($notesDecision->payload, $certificationDecision->payload) as $value) {
                $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $value);
            }
        }
    }

    public function test_category_input_field_keys_are_pinned(): void
    {
        $this->seed();

        $scenario = Scenario::query()->where('code', 'cf_manufacturing')->firstOrFail();

        $expectedKeys = [
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

        foreach ($expectedKeys as $categoryCode => $keys) {
            $category = $scenario->decisionCategories()->where('code', $categoryCode)->firstOrFail();

            $this->assertSame($keys, collect($category->input_fields['fields'])->pluck('key')->all());
        }
    }

    public function test_default_open_quarter_values_are_pinned(): void
    {
        $this->seed();

        $scenario = Scenario::query()->where('code', 'cf_manufacturing')->firstOrFail();
        $actual = $scenario->decisionCategories()
            ->orderBy('sort_order')
            ->pluck('default_open_quarter', 'code')
            ->all();

        $this->assertSame([
            'forecasting' => 4,
            'sourcing' => 4,
            'pricing' => null,
            'production' => 4,
            'marketing' => null,
            'quality' => null,
            'logistics' => null,
            'notes_elections' => null,
            'certification' => null,
        ], $actual);

        $this->assertSame(3, $scenario->decisionCategories()->whereNotNull('default_open_quarter')->count());
    }

    public function test_scenario_models_use_uuids_soft_deletes_and_relationships(): void
    {
        $this->seed();

        $scenario = Scenario::query()->where('code', 'cf_manufacturing')->firstOrFail();
        $category = $scenario->decisionCategories()->where('code', 'forecasting')->firstOrFail();
        $parameter = $scenario->parameters()->where('parameter_key', 'opening_cash')->firstOrFail();
        $quarterDecision = $scenario->quarterDecisions()->where('scenario_decision_category_id', $category->id)->firstOrFail();

        foreach ([$scenario, $category, $parameter, $quarterDecision] as $model) {
            $this->assertIsString($model->getKey());
            $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $model->getKey());
            $this->assertFalse($model->getIncrementing());
            $this->assertSame('string', $model->getKeyType());
        }

        $this->assertTrue($scenario->decisionCategories->contains($category));
        $this->assertTrue($scenario->parameters->contains($parameter));
        $this->assertTrue($scenario->quarterDecisions->contains($quarterDecision));
        $this->assertTrue($category->quarterDecisions->contains($quarterDecision));
        $this->assertTrue($category->scenario->is($scenario));
        $this->assertTrue($parameter->scenario->is($scenario));
        $this->assertTrue($quarterDecision->scenario->is($scenario));
        $this->assertTrue($quarterDecision->category->is($category));

        $quarterDecision->delete();
        $this->assertSoftDeleted($quarterDecision);
    }

    public function test_scenario_unique_constraints_are_enforced(): void
    {
        $scenario = Scenario::factory()->create(['code' => 'duplicate']);

        $this->expectException(QueryException::class);
        Scenario::factory()->create(['code' => $scenario->code]);
    }

    public function test_quarter_decision_unique_constraint_is_enforced(): void
    {
        $scenario = Scenario::factory()->create();
        $category = ScenarioDecisionCategory::factory()->for($scenario)->create(['code' => 'forecasting', 'sort_order' => 1]);

        ScenarioQuarterDecision::factory()->for($scenario)->for($category, 'category')->create(['quarter_number' => 1]);

        $this->expectException(QueryException::class);
        ScenarioQuarterDecision::factory()->for($scenario)->for($category, 'category')->create(['quarter_number' => 1]);
    }

    public function test_scenario_json_columns_are_postgresql_jsonb(): void
    {
        $columns = collect(DB::select(<<<'SQL'
            select table_name, column_name, data_type
            from information_schema.columns
            where table_schema = 'public'
              and table_name in ('scenarios', 'scenario_decision_categories', 'scenario_parameters', 'scenario_quarter_decisions')
              and column_name in ('opening_position', 'input_fields', 'parameter_value', 'payload', 'null_fields')
            order by table_name, column_name
        SQL))->mapWithKeys(fn ($column) => ["{$column->table_name}.{$column->column_name}" => $column->data_type]);

        $this->assertSame('jsonb', $columns['scenarios.opening_position']);
        $this->assertSame('jsonb', $columns['scenario_decision_categories.input_fields']);
        $this->assertSame('jsonb', $columns['scenario_parameters.parameter_value']);
        $this->assertSame('jsonb', $columns['scenario_quarter_decisions.payload']);
        $this->assertSame('jsonb', $columns['scenario_quarter_decisions.null_fields']);
    }

    public function test_excise_rates_remain_separate_from_notes_election(): void
    {
        $this->seed();

        $scenario = Scenario::query()->where('code', 'cf_manufacturing')->firstOrFail();
        $notesDecision = $scenario->quarterDecisions()
            ->whereHas('category', fn ($query) => $query->where('code', 'notes_elections'))
            ->firstOrFail();

        $this->assertSame('cogs_inclusion', $notesDecision->payload['excise_tax_treatment']);
        $this->assertArrayNotHasKey('excise_rate_r2', $notesDecision->payload);
        $this->assertArrayNotHasKey('excise_rate_r3', $notesDecision->payload);
        $this->assertArrayNotHasKey('excise_payment_rate', $notesDecision->payload);
        $this->assertSame(0.04, $scenario->parameters()->where('parameter_key', 'excise_rate_r2')->firstOrFail()->parameter_value['value']);
        $this->assertSame(0.06, $scenario->parameters()->where('parameter_key', 'excise_rate_r3')->firstOrFail()->parameter_value['value']);
        $this->assertSame(0.80, $scenario->parameters()->where('parameter_key', 'excise_payment_rate')->firstOrFail()->parameter_value['value']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function canonicalizeFieldDefinitions(array $fields): array
    {
        return array_map(fn (array $field): array => [
            'key' => $field['key'],
            'label' => $field['label'],
            'type' => $field['type'],
            'allowed_values' => $field['allowed_values'],
            'default' => $field['default'],
            'provenance' => $field['provenance'],
        ], $fields);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function expectedNotesElectionFields(): array
    {
        return [
            [
                'key' => 'inventory_method',
                'label' => 'Inventory Method',
                'type' => 'select',
                'allowed_values' => ['fifo', 'weighted_average', 'lifo'],
                'default' => 'fifo',
                'provenance' => 'authored-cf-field',
            ],
            [
                'key' => 'depreciation_method',
                'label' => 'Depreciation Method',
                'type' => 'select',
                'allowed_values' => ['straight_line', 'double_declining_balance', 'units_of_production'],
                'default' => 'straight_line',
                'provenance' => 'authored-cf-field',
            ],
            [
                'key' => 'shipping_terms',
                'label' => 'Shipping Terms',
                'type' => 'select',
                'allowed_values' => ['fob_shipping_point', 'fob_destination'],
                'default' => 'fob_shipping_point',
                'provenance' => 'authored-cf-field',
            ],
            [
                'key' => 'bad_debt_method',
                'label' => 'Bad Debt Method',
                'type' => 'select',
                'allowed_values' => ['percentage_of_receivables', 'percentage_of_sales'],
                'default' => 'percentage_of_receivables',
                'provenance' => 'authored-cf-field',
            ],
            [
                'key' => 'warranty_method',
                'label' => 'Warranty Method',
                'type' => 'select',
                'allowed_values' => ['percentage_of_sales', 'expense_as_incurred'],
                'default' => 'percentage_of_sales',
                'provenance' => 'authored-cf-field',
            ],
            [
                'key' => 'excise_tax_treatment',
                'label' => 'Excise Tax Treatment',
                'type' => 'select',
                'allowed_values' => ['cogs_inclusion', 'separate_operating_expense'],
                'default' => 'cogs_inclusion',
                'provenance' => 'authored-cf-field',
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function expectedCertificationFields(): array
    {
        return [
            [
                'key' => 'attestation_scope',
                'label' => 'Attestation Scope',
                'type' => 'select',
                'allowed_values' => ['all_statements_attested', 'not_attested'],
                'default' => 'all_statements_attested',
                'provenance' => 'authored-cf-field',
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function expectedParameterKeys(): array
    {
        return [
            'admin_overhead',
            'base_demand_r1',
            'base_demand_r2',
            'base_demand_r3',
            'base_labor_per_unit',
            'borrow_repay_rate',
            'capex_dc',
            'capex_mfg',
            'capex_tech',
            'capacity_per_shift',
            'carrying_cost_rate',
            'credit_tier_distressed_limit',
            'credit_tier_distressed_min_score',
            'credit_tier_distressed_rate',
            'demand_growth_standard_deviation',
            'credit_tier_excellent_limit',
            'credit_tier_excellent_min_score',
            'credit_tier_excellent_rate',
            'credit_tier_fair_limit',
            'credit_tier_fair_min_score',
            'credit_tier_fair_rate',
            'credit_tier_good_limit',
            'credit_tier_good_min_score',
            'credit_tier_good_rate',
            'credit_tier_poor_limit',
            'credit_tier_poor_min_score',
            'credit_tier_poor_rate',
            'excise_payment_rate',
            'excise_rate_r2',
            'excise_rate_r3',
            'fixed_overhead_per_period',
            'gross_ppe_dc',
            'gross_ppe_mfg',
            'gross_ppe_tech',
            'inflation_rate',
            'inspection_basic_catch_rate',
            'inspection_basic_cost',
            'inspection_full_catch_rate',
            'inspection_full_cost',
            'inspection_none_catch_rate',
            'inspection_none_cost',
            'inventory_in_transit_ratio',
            'inventory_raw_mat_ratio',
            'inventory_wip_ratio',
            'lease_payment_per_period',
            'logistics_air_cost',
            'logistics_air_on_time_rate',
            'logistics_express_cost',
            'logistics_express_on_time_rate',
            'logistics_standard_cost',
            'logistics_standard_on_time_rate',
            'marketing_at_risk_base_revenue_pct',
            'marketing_at_risk_retention_sensitivity',
            'marketing_champions_base_revenue_pct',
            'marketing_champions_retention_sensitivity',
            'marketing_growth_base_revenue_pct',
            'marketing_growth_retention_sensitivity',
            'marketing_other_base_revenue_pct',
            'marketing_other_retention_sensitivity',
            'minimum_cash_buffer',
            'opening_cash',
            'opening_corporate_capitalization',
            'opening_liabilities',
            'opening_plant_investment',
            'opening_total_assets',
            'opening_total_liabilities_plus_equity',
            'overtime_premium',
            'p1_base_price',
            'p2_base_price',
            'perfect_order_damage_free_weight',
            'perfect_order_docs_weight',
            'perfect_order_in_full_weight',
            'perfect_order_on_time_weight',
            'postponed_duty_saving',
            'price_elasticity',
            'rebate_payment_rate',
            'rebate_rate',
            'returns_rate_on_defects',
            'seasonality_q1',
            'seasonality_q2',
            'seasonality_q3',
            'seasonality_q4',
            'sl_payment_rate',
            'sl_penalty_per_unit',
            'supplier_sup001_cost',
            'supplier_sup001_defect_rate',
            'supplier_sup001_lead_days',
            'supplier_sup001_name',
            'supplier_sup001_region',
            'supplier_sup002_cost',
            'supplier_sup002_defect_rate',
            'supplier_sup002_lead_days',
            'supplier_sup002_name',
            'supplier_sup002_region',
            'supplier_sup003_cost',
            'supplier_sup003_defect_rate',
            'supplier_sup003_lead_days',
            'supplier_sup003_name',
            'supplier_sup003_region',
            'supplier_sup004_cost',
            'supplier_sup004_defect_rate',
            'supplier_sup004_lead_days',
            'supplier_sup004_name',
            'supplier_sup004_region',
            'supplier_sup005_cost',
            'supplier_sup005_defect_rate',
            'supplier_sup005_lead_days',
            'supplier_sup005_name',
            'supplier_sup005_region',
            'supplier_sup006_cost',
            'supplier_sup006_defect_rate',
            'supplier_sup006_lead_days',
            'supplier_sup006_name',
            'supplier_sup006_region',
            'tax_rate',
            'warranty_claims_rate',
            'warranty_cost_per_claim',
        ];
    }
}
