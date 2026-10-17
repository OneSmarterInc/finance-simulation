<?php

namespace Database\Factories;

use App\Models\Scenario;
use App\Models\ScenarioDecisionCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScenarioDecisionCategory>
 */
class ScenarioDecisionCategoryFactory extends Factory
{
    protected $model = ScenarioDecisionCategory::class;

    public function definition(): array
    {
        return [
            'scenario_id' => Scenario::factory(),
            'code' => fake()->unique()->slug(2),
            'display_name' => fake()->words(2, true),
            'sort_order' => fake()->unique()->numberBetween(1, 100),
            'input_fields' => [
                'fields' => [],
                'version' => 'factory',
                'source' => 'factory',
            ],
            'default_open_quarter' => null,
        ];
    }
}
