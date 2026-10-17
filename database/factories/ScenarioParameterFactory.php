<?php

namespace Database\Factories;

use App\Models\Scenario;
use App\Models\ScenarioParameter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScenarioParameter>
 */
class ScenarioParameterFactory extends Factory
{
    protected $model = ScenarioParameter::class;

    public function definition(): array
    {
        return [
            'scenario_id' => Scenario::factory(),
            'parameter_key' => fake()->unique()->slug(2),
            'parameter_value' => ['value' => fake()->numberBetween(1, 100)],
            'data_type' => 'number',
            'description' => fake()->sentence(),
        ];
    }
}
