<?php

namespace Database\Factories;

use App\Models\Faculty;
use App\Models\Firm;
use App\Models\FirmMembership;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FirmMembership> */
class FirmMembershipFactory extends Factory
{
    protected $model = FirmMembership::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'course_id' => fn (array $attributes) => Firm::query()->whereKey($attributes['firm_id'])->valueOrFail('course_id'),
            'institution_id' => fn (array $attributes) => Firm::query()->whereKey($attributes['firm_id'])->valueOrFail('institution_id'),
            'student_id' => Student::factory(),
            'assigned_by_faculty_id' => Faculty::factory(),
            'assigned_at' => now(),
        ];
    }
}
