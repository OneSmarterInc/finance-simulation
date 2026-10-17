<?php

namespace Database\Factories;

use App\Models\Scenario;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Scenario>
 */
class ScenarioFactory extends Factory
{
    protected $model = Scenario::class;

    public function definition(): array
    {
        return [
            'code' => fake()->unique()->slug(2),
            'name' => fake()->words(3, true),
            'narrative_text' => fake()->paragraph(),
            'version' => '1.0',
            'engine_class_name' => null,
            'active' => true,
            'opening_position' => null,
        ];
    }
}
