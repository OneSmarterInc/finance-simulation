<?php

namespace Tests\Feature\Phase7A;

use App\Events\CourseDeploymentReachedWarmupBoundary;
use App\Jobs\InitializeFirmWarmup;
use App\Models\Course;
use App\Models\Faculty;
use App\Models\Firm;
use App\Models\Scenario;
use App\Models\Student;
use App\Services\Firms\FirmAssignmentService;
use App\Services\Warmup\FirmWarmupInitializationCoordinator;
use App\Services\Warmup\InheritedWarmupHistoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class FirmsAndWarmupPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_faculty_can_enroll_and_assign_students_within_course_scope(): void
    {
        Queue::fake();
        [$course, $faculty] = $this->course();
        $students = Student::query()->take(2)->get();
        $service = app(FirmAssignmentService::class);
        $firm = $service->createFirm($faculty, $course, 'A1', 'Alpha');
        foreach ($students as $student) {
            $service->enroll($faculty, $course, $student);
            $service->assign($faculty, $course, $firm, $student);
        }

        $this->assertCount(2, $firm->memberships);
        $this->assertDatabaseCount('enrollments', 2);
        Queue::assertPushed(InitializeFirmWarmup::class, 1);
    }

    public function test_cross_institution_student_assignment_is_rejected(): void
    {
        [$course, $faculty] = $this->course();
        $student = Student::factory()->create();

        $this->expectException(ValidationException::class);
        app(FirmAssignmentService::class)->enroll($faculty, $course, $student);
    }

    public function test_validated_q1_through_q3_history_is_atomic_self_contained_and_write_once(): void
    {
        [$course, $faculty] = $this->course();
        $firm = Firm::factory()->create(['course_id' => $course->id, 'institution_id' => $course->institution_id]);
        $student = Student::query()->firstOrFail();
        $assignments = app(FirmAssignmentService::class);
        $assignments->enroll($faculty, $course, $student);
        Queue::fake();
        $assignments->assign($faculty, $course, $firm, $student);

        $history = app(InheritedWarmupHistoryService::class);
        $history->initialize($firm);
        $stored = $firm->engineResults()->orderBy('quarter_number')->get();
        $hashes = $stored->pluck('payload_hash')->all();
        $timestamps = $stored->pluck('updated_at')->map->toJSON()->all();

        $this->assertSame([1, 2, 3], $stored->pluck('quarter_number')->all());
        $this->assertTrue($stored->every(fn ($row) => $row->immutable && $row->validated_at && $row->fields && $row->income_statement && $row->balance_sheet && $row->cash_flow_statement));
        $this->assertSame([0, 0, 0], $stored->pluck('fields')->map(fn ($fields) => $fields['cf_equity'])->all());
        $history->initialize($firm);
        (new InitializeFirmWarmup($firm->id))->handle($history);

        $lateStudent = Student::query()->whereKeyNot($student->id)->firstOrFail();
        $assignments->enroll($faculty, $course, $lateStudent);
        Queue::fake();
        $assignments->assign($faculty, $course, $firm, $lateStudent);
        Queue::assertNothingPushed();

        $again = $firm->engineResults()->orderBy('quarter_number')->get();
        $this->assertSame($hashes, $again->pluck('payload_hash')->all());
        $this->assertSame($timestamps, $again->pluck('updated_at')->map->toJSON()->all());
        $this->assertSame($stored[0]->fields, $history->toEngineResult($stored[0])->fields);

        $this->expectException(LogicException::class);
        $history->replaceInherited($stored[0], []);
    }

    public function test_deployment_event_dispatches_ready_firms_and_duplicate_delivery_is_idempotent(): void
    {
        Queue::fake();
        [$course, $faculty] = $this->course();
        $firm = Firm::factory()->create(['course_id' => $course->id, 'institution_id' => $course->institution_id]);
        $student = Student::query()->firstOrFail();
        $service = app(FirmAssignmentService::class);
        $service->enroll($faculty, $course, $student);
        $service->assign($faculty, $course, $firm, $student);
        $firm->forceFill(['warmup_status' => Firm::WARMUP_PENDING])->save();

        $event = new CourseDeploymentReachedWarmupBoundary($course->id, $course->scenario_id, $course->scenario->code, $course->institution_id, $course->faculty_id, Course::STATUS_DEPLOYED, [], false, null, null, null);
        $coordinator = app(FirmWarmupInitializationCoordinator::class);
        $coordinator->courseReachedWarmupBoundary($event);
        $coordinator->courseReachedWarmupBoundary($event);

        Queue::assertPushed(InitializeFirmWarmup::class, 1);
    }

    public function test_firm_history_is_visible_only_to_its_faculty_and_members(): void
    {
        Queue::fake();
        [$course, $faculty] = $this->course();
        $firm = Firm::factory()->create(['course_id' => $course->id, 'institution_id' => $course->institution_id]);
        $member = Student::query()->firstOrFail();
        $outsider = Student::query()->whereKeyNot($member->id)->firstOrFail();
        $service = app(FirmAssignmentService::class);
        $service->enroll($faculty, $course, $member);
        $service->assign($faculty, $course, $firm, $member);

        $this->actingAs($faculty->user)->get(route('firms.history', $firm))->assertOk();
        $this->actingAs($member->user)->get(route('firms.history', $firm))->assertOk();
        $this->actingAs($outsider->user)->get(route('firms.history', $firm))->assertForbidden();
    }

    /** @return array{Course, Faculty} */
    private function course(): array
    {
        $faculty = Faculty::query()->firstOrFail();
        $course = Course::query()->create([
            'institution_id' => $faculty->institution_id,
            'faculty_id' => $faculty->id,
            'scenario_id' => Scenario::query()->where('code', 'cf_manufacturing')->value('id'),
            'name' => 'Phase 7A Test Course',
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-12-31',
            'cf_plus_enabled' => false,
            'status' => Course::STATUS_DEPLOYED,
            'deployed_at' => now(),
        ]);

        return [$course, $faculty];
    }
}
