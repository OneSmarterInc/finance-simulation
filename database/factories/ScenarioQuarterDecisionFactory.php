<?php

namespace Database\Factories;

use App\Models\Scenario;
use App\Models\ScenarioDecisionCategory;
use App\Models\ScenarioQuarterDecision;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScenarioQuarterDecision>
 */
class ScenarioQuarterDecisionFactory extends Factory
{
    protected $model = ScenarioQuarterDecision::class;

    public function definition(): array
    {
        return [
            'scenario_id' => Scenario::factory(),
            'scenario_decision_category_id' => ScenarioDecisionCategory::factory(),
            'quarter_number' => fake()->numberBetween(1, 3),
            'payload' => [],
            'null_fields' => [],
            'source' => 'factory',
        ];
    }
}
