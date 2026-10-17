<?php

namespace Tests\Feature\Foundation;

use App\Models\Admin;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Faculty;
use App\Models\Institution;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelRelationshipTest extends TestCase
{
    use RefreshDatabase;

    public function test_institution_has_many_faculty_and_students(): void
    {
        $institution = Institution::factory()->create();
        $faculty = Faculty::factory()->for($institution)->create();
        $student = Student::factory()->for($institution)->create();

        $this->assertTrue($institution->faculty->contains($faculty));
        $this->assertTrue($institution->students->contains($student));
    }

    public function test_faculty_belongs_to_institution_and_user(): void
    {
        $faculty = Faculty::factory()->create();

        $this->assertInstanceOf(Institution::class, $faculty->institution);
        $this->assertInstanceOf(User::class, $faculty->user);
    }

    public function test_faculty_exposes_future_courses_relationship(): void
    {
        $relationship = Faculty::factory()->make()->courses();

        $this->assertInstanceOf(HasMany::class, $relationship);
        $this->assertSame(Course::class, $relationship->getRelated()::class);
    }

    public function test_student_belongs_to_institution_and_user(): void
    {
        $student = Student::factory()->create();

        $this->assertInstanceOf(Institution::class, $student->institution);
        $this->assertInstanceOf(User::class, $student->user);
    }

    public function test_student_exposes_future_enrollments_relationship(): void
    {
        $relationship = Student::factory()->make()->enrollments();

        $this->assertInstanceOf(HasMany::class, $relationship);
        $this->assertSame(Enrollment::class, $relationship->getRelated()::class);
    }

    public function test_admin_belongs_to_user(): void
    {
        $admin = Admin::factory()->create();

        $this->assertInstanceOf(User::class, $admin->user);
    }

    public function test_user_has_one_profile_of_each_type(): void
    {
        $admin = Admin::factory()->create();
        $faculty = Faculty::factory()->create();
        $student = Student::factory()->create();

        $this->assertTrue($admin->user->admin->is($admin));
        $this->assertTrue($faculty->user->faculty->is($faculty));
        $this->assertTrue($student->user->student->is($student));
        $this->assertTrue($admin->user->isAdmin());
        $this->assertTrue($faculty->user->isFaculty());
        $this->assertTrue($student->user->isStudent());
    }
}
