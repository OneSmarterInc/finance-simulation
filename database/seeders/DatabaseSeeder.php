<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\Faculty;
use App\Models\Institution;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(CfManufacturingScenarioSeeder::class);

        $institution = Institution::create([
            'name' => 'CF Manufacturing Demo Institute',
        ]);

        $adminUser = User::factory()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);

        Admin::create([
            'user_id' => $adminUser->id,
        ]);

        $facultyUser = User::factory()->create([
            'name' => 'Chuck Nemer',
            'email' => 'faculty@example.com',
            'password' => 'password',
        ]);

        Faculty::create([
            'user_id' => $facultyUser->id,
            'institution_id' => $institution->id,
        ]);

        foreach (
            [
                ['Student One', 'student@example.com'],
                ['Student Two', 'student2@example.com'],
                ['Student Three', 'student3@example.com'],
                ['Student Four', 'student4@example.com'],
                ['Student Five', 'student5@example.com'],
                ['Student Six', 'student6@example.com'],
                ['Student Seven', 'student7@example.com'],
                ['Student Eight', 'student8@example.com'],
                ['Student Nine', 'student9@example.com'],
                ['Student Ten', 'student10@example.com'],
                ['Student Eleven', 'student11@example.com'],
                ['Student Twelve', 'student12@example.com'],
                ['Student Thirteen', 'student13@example.com'],
                ['Student Fourteen', 'student14@example.com'],
                ['Student Fifteen', 'student15@example.com'],
            ] as [$name, $email]
        ) {
            $studentUser = User::factory()->create([
                'name' => $name,
                'email' => $email,
                'password' => 'password',
            ]);

            Student::create([
                'user_id' => $studentUser->id,
                'institution_id' => $institution->id,
            ]);
        }
    }
}
