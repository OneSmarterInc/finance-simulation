<?php

namespace Tests\Feature\Phase7A;

use App\Models\Admin;
use App\Models\Course;
use App\Models\Faculty;
use App\Models\Firm;
use App\Models\Institution;
use App\Models\Scenario;
use App\Models\Student;
use App\Services\Firms\FirmAssignmentService;
use App\Services\Warmup\InheritedWarmupHistoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FirmAuthorizationAndMultiFirmTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Queue::fake();
    }

    public function test_duplicate_membership_is_idempotent_and_cross_course_firm_substitution_is_rejected(): void
    {
        [$courseA, $owner] = $this->course('Course A');
        [$courseB] = $this->course('Course B');
        $service = app(FirmAssignmentService::class);
        $firmA = $service->createFirm($owner, $courseA, 'A', 'Firm A');
        $firmB = Firm::factory()->create(['course_id' => $courseB->id, 'institution_id' => $courseB->institution_id, 'code' => 'B']);
        $student = Student::query()->firstOrFail();
        $service->enroll($owner, $courseA, $student);
        $first = $service->assign($owner, $courseA, $firmA, $student);
        $second = $service->assign($owner, $courseA, $firmA, $student);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('firm_memberships', 1);
        try {
            $service->assign($owner, $courseA, $firmB, $student);
            $this->fail('Cross-course firm substitution must be rejected.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('firm_memberships', 1);
            $this->assertSame($firmA->id, $student->firmMemberships()->firstOrFail()->firm_id);
        }
    }

    public function test_three_firms_nine_results_and_member_isolation_are_enforced(): void
    {
        [$course, $owner] = $this->course('Multi Firm');
        $service = app(FirmAssignmentService::class);
        $firms = collect([
            $service->createFirm($owner, $course, 'F1', 'Firm One'),
            $service->createFirm($owner, $course, 'F2', 'Firm Two'),
            $service->createFirm($owner, $course, 'F3', 'Firm Three'),
        ]);
        $students = Student::query()
            ->where('institution_id', $course->institution_id)
            ->orderBy('id')
            ->limit(3)
            ->get();

        $students->push(
            Student::factory()->create([
                'institution_id' => $course->institution_id,
            ])
        );
        foreach ($students as $index => $student) {
            $firm = $index < 2 ? $firms[0] : $firms[$index - 1];
            $service->enroll($owner, $course, $student);
            $service->assign($owner, $course, $firm, $student);
        }
        foreach ($firms as $firm) {
            app(InheritedWarmupHistoryService::class)->initialize($firm);
        }

        $this->assertSame(2, $firms[0]->memberships()->count());
        $this->assertSame(1, $firms[1]->memberships()->count());
        $this->assertSame(1, $firms[2]->memberships()->count());
        foreach ($firms as $firm) {
            $this->assertSame([1, 2, 3], $firm->engineResults()->orderBy('quarter_number')->pluck('quarter_number')->all());
            $this->assertTrue($firm->engineResults()->get()->every(fn($result): bool => $result->firm_id === $firm->id));
        }
        $this->assertDatabaseCount('firm_engine_results', 9);
        $hashSets = $firms->map(fn(Firm $firm): array => $firm->engineResults()->orderBy('quarter_number')->pluck('payload_hash')->all())->all();
        $this->assertNotSame($hashSets[0], $hashSets[1]);
        $this->assertNotSame($hashSets[1], $hashSets[2]);
        $this->assertNotSame($hashSets[0], $hashSets[2]);
        $this->assertSame($firms[0]->engineResults()->pluck('id')->all(), $students[0]->firmMemberships()->firstOrFail()->firm->engineResults()->pluck('id')->all());
        $this->assertSame($firms[0]->engineResults()->pluck('id')->all(), $students[1]->firmMemberships()->firstOrFail()->firm->engineResults()->pluck('id')->all());

        $this->actingAs($owner->user)->get(route('faculty.courses.firms', $course))->assertOk()->assertSee('Firm One')->assertSee('Firm Two')->assertSee('Firm Three');
        $this->actingAs($students[0]->user)->get(route('firms.history', $firms[0]))
            ->assertOk()->assertSee('Q1 · warm-up')->assertSee('Income statement')->assertSee('Cash flow');
        $this->actingAs($students[2]->user)->get(route('firms.history', $firms[0]))->assertForbidden();
        $this->actingAs($students[0]->user)->get(route('firms.history', $firms[1]))->assertForbidden();
    }

    public function test_route_authorization_covers_guest_roles_faculty_scope_soft_deletes_and_uuid_substitution(): void
    {
        [$course, $owner] = $this->course('Authorization');
        $service = app(FirmAssignmentService::class);
        $firm = $service->createFirm($owner, $course, 'AUTH', 'Authorized Firm');
        $member = Student::query()->firstOrFail();
        $outsider = Student::query()->whereKeyNot($member->id)->firstOrFail();
        $service->enroll($owner, $course, $member);
        $membership = $service->assign($owner, $course, $firm, $member);
        app(InheritedWarmupHistoryService::class)->initialize($firm);
        $sameInstitutionFaculty = Faculty::factory()->create(['institution_id' => $course->institution_id]);
        $otherInstitution = Institution::factory()->create();
        $crossInstitutionFaculty = Faculty::factory()->create(['institution_id' => $otherInstitution->id]);
        $admin = Admin::query()->firstOrFail();

        $this->get(route('faculty.courses.firms', $course))->assertRedirect(route('login'));
        $this->get(route('firms.history', $firm))->assertRedirect(route('login'));
        $this->actingAs($admin->user)->get(route('faculty.courses.firms', $course))->assertForbidden();
        $this->actingAs($owner->user)->get(route('faculty.courses.firms', $course))->assertOk();
        $this->actingAs($sameInstitutionFaculty->user)->get(route('faculty.courses.firms', $course))->assertForbidden();
        $this->actingAs($crossInstitutionFaculty->user)->get(route('faculty.courses.firms', $course))->assertForbidden();
        $this->actingAs($member->user)->get(route('firms.history', $firm))->assertOk();
        $this->actingAs($outsider->user)->get(route('firms.history', $firm))->assertForbidden();

        $membership->delete();
        $this->actingAs($member->user)->get(route('firms.history', $firm))->assertForbidden();
        $firm->delete();
        $this->actingAs($owner->user)->get(route('firms.history', $firm->id))->assertNotFound();
    }

    /** @return array{Course, Faculty} */
    private function course(string $name): array
    {
        $faculty = Faculty::query()->firstOrFail();
        $course = Course::query()->create([
            'institution_id' => $faculty->institution_id,
            'faculty_id' => $faculty->id,
            'scenario_id' => Scenario::query()->where('code', 'cf_manufacturing')->value('id'),
            'name' => $name,
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-12-31',
            'cf_plus_enabled' => false,
            'status' => Course::STATUS_DEPLOYED,
            'deployed_at' => now(),
        ]);

        return [$course, $faculty];
    }
}
