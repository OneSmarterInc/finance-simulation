<?php

namespace Database\Seeders;

use App\Models\Scenario;
use App\Models\ScenarioDecisionCategory;
use App\Models\ScenarioParameter;
use App\Models\ScenarioQuarterDecision;
use App\Support\Scenario\CfManufacturingScenarioDefinition;
use Illuminate\Database\Seeder;

class CfManufacturingScenarioSeeder extends Seeder
{
    public function run(): void
    {
        Scenario::query()->updateOrCreate(
            ['code' => CfManufacturingScenarioDefinition::CODE],
            [
                'name' => 'CF Manufacturing',
                'narrative_text' => 'Corporate Finance manufacturing scenario deterministic baseline for Phase 2.',
                'version' => '2.0',
                'engine_class_name' => null,
                'active' => true,
                'opening_position' => CfManufacturingScenarioDefinition::OPENING_POSITION,
            ],
        );

        $scenario = Scenario::query()
            ->where('code', CfManufacturingScenarioDefinition::CODE)
            ->firstOrFail();

        foreach (CfManufacturingScenarioDefinition::categories() as $sortOrder => $categoryDefinition) {
            ScenarioDecisionCategory::query()->updateOrCreate(
                [
                    'scenario_id' => $scenario->id,
                    'code' => $categoryDefinition['code'],
                ],
                [
                    'display_name' => $categoryDefinition['display_name'],
                    'sort_order' => $sortOrder + 1,
                    'input_fields' => CfManufacturingScenarioDefinition::inputFieldsForCategory($categoryDefinition['code']),
                    'default_open_quarter' => CfManufacturingScenarioDefinition::defaultOpenQuarterForCategory($categoryDefinition['code']),
                ],
            );
        }

        foreach (CfManufacturingScenarioDefinition::parameters() as $key => $value) {
            ScenarioParameter::query()->updateOrCreate(
                [
                    'scenario_id' => $scenario->id,
                    'parameter_key' => $key,
                ],
                [
                    'parameter_value' => ['value' => $value],
                    'data_type' => CfManufacturingScenarioDefinition::parameterDataType($value),
                    'description' => CfManufacturingScenarioDefinition::parameterDescription($key),
                ],
            );
        }

        $categories = $scenario->decisionCategories()->get()->keyBy('code');

        $desiredRows = [];
        $forecastingCategory = $categories->get('forecasting');

        $desiredRows[] = $this->upsertQuarterDecision(
            $scenario->id,
            $forecastingCategory->id,
            0,
            CfManufacturingScenarioDefinition::q0ForecastingPayload(),
            'q0_scm_industry_initial_demand',
        );

        foreach ([1, 2, 3] as $quarterNumber) {
            foreach (['notes_elections', 'certification'] as $categoryCode) {
                $category = $categories->get($categoryCode);

                $desiredRows[] = $this->upsertQuarterDecision(
                    $scenario->id,
                    $category->id,
                    $quarterNumber,
                    CfManufacturingScenarioDefinition::fixedWarmupPayload($categoryCode),
                    'fixed_warmup_election_baseline',
                );
            }
        }

        ScenarioQuarterDecision::withTrashed()
            ->where('scenario_id', $scenario->id)
            ->get()
            ->each(function (ScenarioQuarterDecision $decision) use ($desiredRows): void {
                $key = implode(':', [
                    $decision->scenario_id,
                    $decision->scenario_decision_category_id,
                    $decision->quarter_number,
                ]);

                if (! in_array($key, $desiredRows, true)) {
                    $decision->forceDelete();
                }
            });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function upsertQuarterDecision(string $scenarioId, string $categoryId, int $quarterNumber, array $payload, string $source): string
    {
        $decision = ScenarioQuarterDecision::withTrashed()->firstOrNew([
            'scenario_id' => $scenarioId,
            'quarter_number' => $quarterNumber,
            'scenario_decision_category_id' => $categoryId,
        ]);

        $decision
            ->forceFill([
                'payload' => $payload,
                'null_fields' => [],
                'source' => $source,
                'deleted_at' => null,
            ])
            ->save();

        return implode(':', [$scenarioId, $categoryId, $quarterNumber]);
    }
}
