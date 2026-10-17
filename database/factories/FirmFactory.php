<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\Firm;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Firm> */
class FirmFactory extends Factory
{
    protected $model = Firm::class;

    public function definition(): array
    {
        return [
            'course_id' => Course::query()->value('id'),
            'institution_id' => fn (array $attributes) => Course::query()->whereKey($attributes['course_id'])->valueOrFail('institution_id'),
            'code' => strtoupper(fake()->unique()->bothify('F-###')),
            'name' => fake()->unique()->company(),
            'warmup_status' => Firm::WARMUP_PENDING,
        ];
    }
}
