<?php

namespace Tests\Feature\Foundation;

use App\Models\Admin;
use App\Models\Faculty;
use App\Models\Institution;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_entities_generate_uuid_primary_keys(): void
    {
        foreach ([Institution::factory()->create(), Admin::factory()->create(), Faculty::factory()->create(), Student::factory()->create()] as $model) {
            $this->assertIsString($model->getKey());
            $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $model->getKey());
            $this->assertFalse($model->getIncrementing());
            $this->assertSame('string', $model->getKeyType());
        }
    }

    public function test_business_models_use_soft_deletes(): void
    {
        $institution = Institution::factory()->create();
        $admin = Admin::factory()->create();
        $faculty = Faculty::factory()->for($institution)->create();
        $student = Student::factory()->for($institution)->create();

        foreach ([$institution, $admin, $faculty, $student] as $model) {
            $model->delete();
            $this->assertSoftDeleted($model);
        }
    }

    public function test_admin_user_uniqueness_constraint_is_enforced(): void
    {
        $user = User::factory()->create();

        Admin::create(['user_id' => $user->id]);

        $this->expectException(QueryException::class);
        Admin::create(['user_id' => $user->id]);
    }

    public function test_faculty_user_uniqueness_constraint_is_enforced(): void
    {
        $institution = Institution::factory()->create();
        $user = User::factory()->create();

        Faculty::create(['user_id' => $user->id, 'institution_id' => $institution->id]);

        $this->expectException(QueryException::class);
        Faculty::create(['user_id' => $user->id, 'institution_id' => $institution->id]);
    }

    public function test_student_user_uniqueness_constraint_is_enforced(): void
    {
        $institution = Institution::factory()->create();
        $user = User::factory()->create();

        Student::create(['user_id' => $user->id, 'institution_id' => $institution->id]);

        $this->expectException(QueryException::class);
        Student::create(['user_id' => $user->id, 'institution_id' => $institution->id]);
    }

    public function test_required_foreign_keys_are_enforced(): void
    {
        $this->expectException(QueryException::class);

        Faculty::create([
            'user_id' => 999999,
            'institution_id' => '00000000-0000-0000-0000-000000000000',
        ]);
    }

    public function test_user_deletion_cascades_profile_records(): void
    {
        $faculty = Faculty::factory()->create();

        $faculty->user->delete();

        $this->assertDatabaseMissing('faculty', ['id' => $faculty->id]);
    }

    public function test_institution_deletion_is_restricted_when_profiles_reference_it(): void
    {
        $institution = Institution::factory()->create();
        Faculty::factory()->for($institution)->create();
        Student::factory()->for($institution)->create();

        $this->expectException(QueryException::class);
        $institution->forceDelete();
    }
}
