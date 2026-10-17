<?php

namespace Tests\Feature\Phase7A;

use App\Events\CourseDeploymentReachedWarmupBoundary;
use App\Jobs\InitializeFirmWarmup;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Faculty;
use App\Models\Firm;
use App\Models\FirmEngineResult;
use App\Models\FirmMembership;
use App\Models\Scenario;
use App\Models\Student;
use App\Services\Firms\FirmAssignmentService;
use App\Services\Warmup\FirmWarmupInitializationCoordinator;
use App\Services\Warmup\InheritedWarmupHistoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class WarmupPersistenceHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_successful_initialization_persists_dense_validated_q1_q3_history(): void
    {
        [$course, $firm, $membership] = $this->readyFirm('DENSE');
        $seed = FirmWarmupInitializationCoordinator::courseSeed($course);
        app(InheritedWarmupHistoryService::class)->initialize($firm);
        $results = $firm->engineResults()->orderBy('quarter_number')->get();

        $this->assertTrue(Str::isUuid($firm->id));
        $this->assertSame($course->id, $firm->course_id);
        $this->assertTrue($membership->is($firm->memberships()->firstOrFail()));
        $this->assertCount(3, $results);
        $this->assertSame([1, 2, 3], $results->pluck('quarter_number')->all());
        $this->assertSame([FirmEngineResult::PROVENANCE_WARMUP, FirmEngineResult::PROVENANCE_WARMUP, FirmEngineResult::PROVENANCE_WARMUP], $results->pluck('provenance')->all());
        $this->assertTrue($results->every(fn (FirmEngineResult $result): bool => $result->fields !== [] && $result->income_statement !== [] && $result->balance_sheet !== [] && $result->cash_flow_statement !== [] && $result->notes !== [] && $result->intermediates !== []));
        $this->assertTrue($results->every(fn (FirmEngineResult $result): bool => $result->validated_at !== null && $result->immutable));
        $this->assertTrue($results->every(fn (FirmEngineResult $result): bool => strlen($result->payload_hash) === 64));
        $this->assertSame([0.0, 0.0, 0.0], $results->map(fn (FirmEngineResult $result): float => (float) $result->intermediates['dividend'])->all());
        $this->assertSame([0.0, 0.0, 0.0], $results->map(fn (FirmEngineResult $result): float => (float) $result->fields['cf_equity'])->all());
        $this->assertTrue($results->every(fn (FirmEngineResult $result): bool => $result->course_id === $course->id && $result->firm_id === $firm->id));
        $this->assertSame(Firm::WARMUP_INITIALIZED, $firm->refresh()->warmup_status);
        $this->assertNotNull($firm->initialized_at);
        $this->assertSame($seed, FirmWarmupInitializationCoordinator::courseSeed($firm->course));
        $this->assertTrue(Schema::hasTable('firm_quarter_decisions'));
        $this->assertDatabaseCount('firm_quarter_decisions', 0);
        $this->assertFalse(Schema::hasTable('quarter_closes'));
        $this->assertFalse(Schema::hasColumn('firm_engine_results', 'accounting_validation'));
        $this->assertFalse(Schema::hasColumn('firm_engine_results', 'plausibility_validation'));
    }

    public function test_every_duplicate_path_preserves_the_complete_persisted_snapshot(): void
    {
        Queue::fake();
        [$course, $firm, $membership] = $this->readyFirm('DUP');
        $history = app(InheritedWarmupHistoryService::class);
        $history->initialize($firm);
        $before = $this->snapshot($firm);
        $firmBefore = $this->firmState($firm->fresh());

        $history->initialize($firm->fresh());
        $this->assertSame($before, $this->snapshot($firm));
        $this->assertSame($firmBefore, $this->firmState($firm->fresh()));

        (new InitializeFirmWarmup($firm->id))->handle($history);
        $this->assertSame($before, $this->snapshot($firm));
        $this->assertSame($firmBefore, $this->firmState($firm->fresh()));

        $this->assertFalse(app(FirmWarmupInitializationCoordinator::class)->dispatchFirmIfReady($firm->fresh()));
        $this->assertSame($before, $this->snapshot($firm));
        $this->assertSame($firmBefore, $this->firmState($firm->fresh()));

        app(FirmAssignmentService::class)->assign(Faculty::query()->firstOrFail(), $course, $firm->fresh(), $membership->student);
        $this->assertSame($before, $this->snapshot($firm));
        $this->assertSame($firmBefore, $this->firmState($firm->fresh()));

        $event = CourseDeploymentReachedWarmupBoundary::fromCourse($course);
        app(FirmWarmupInitializationCoordinator::class)->courseReachedWarmupBoundary($event);
        app(FirmWarmupInitializationCoordinator::class)->courseReachedWarmupBoundary($event);
        $this->assertSame($before, $this->snapshot($firm));
        $this->assertSame($firmBefore, $this->firmState($firm->fresh()));
        $this->assertDatabaseCount('firm_engine_results', 3);
        $this->assertDatabaseCount('firm_memberships', 1);
        Queue::assertNothingPushed();
    }

    public function test_shared_result_table_allows_future_q4_append_without_weakening_inherited_rows(): void
    {
        [, $firm] = $this->readyFirm('APPEND');
        app(InheritedWarmupHistoryService::class)->initialize($firm);
        $q3 = $firm->engineResults()->where('quarter_number', 3)->firstOrFail();
        $payload = $q3->only(['firm_id', 'course_id', 'institution_id', 'fields', 'income_statement', 'balance_sheet', 'cash_flow_statement', 'notes', 'intermediates', 'engine_contract']);
        $q4 = FirmEngineResult::query()->create($payload + [
            'quarter_number' => 4,
            'provenance' => 'student_quarter_close',
            'immutable' => true,
            'payload_hash' => str_repeat('a', 64),
            'validated_at' => now(),
        ]);

        $this->assertSame(4, $q4->quarter_number);
        $this->assertSame('student_quarter_close', $q4->provenance);
        $this->assertDatabaseCount('firm_engine_results', 4);
        $this->expectException(LogicException::class);
        $firm->engineResults()->where('quarter_number', 1)->firstOrFail()->forceFill(['quarter_number' => 9])->save();
    }

    /** @return array{Course, Firm, FirmMembership} */
    private function readyFirm(string $code): array
    {
        $faculty = Faculty::query()->firstOrFail();
        $student = Student::query()->firstOrFail();
        $course = Course::query()->create([
            'institution_id' => $faculty->institution_id,
            'faculty_id' => $faculty->id,
            'scenario_id' => Scenario::query()->where('code', 'cf_manufacturing')->value('id'),
            'name' => "Course {$code}",
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-12-31',
            'cf_plus_enabled' => false,
            'status' => Course::STATUS_DEPLOYED,
            'deployed_at' => now(),
        ]);
        $firm = Firm::factory()->create(['course_id' => $course->id, 'institution_id' => $course->institution_id, 'code' => $code]);
        Enrollment::query()->create(['course_id' => $course->id, 'student_id' => $student->id, 'institution_id' => $course->institution_id]);
        $membership = FirmMembership::factory()->create([
            'firm_id' => $firm->id,
            'course_id' => $course->id,
            'student_id' => $student->id,
            'institution_id' => $course->institution_id,
            'assigned_by_faculty_id' => $faculty->id,
        ]);

        return [$course, $firm, $membership];
    }

    /** @return array<string, mixed> */
    private function firmState(Firm $firm): array
    {
        return collect(['warmup_status', 'initialized_at', 'initialization_error', 'initialization_failed_at', 'updated_at'])
            ->mapWithKeys(fn (string $key): array => [$key => $firm->getRawOriginal($key)])->all();
    }

    /** @return array<string, mixed> */
    private function snapshot(Firm $firm): array
    {
        return $firm->engineResults()->orderBy('quarter_number')->get()->map(fn (FirmEngineResult $result): array => $result->only([
            'id', 'firm_id', 'course_id', 'quarter_number', 'payload_hash', 'fields', 'income_statement',
            'balance_sheet', 'cash_flow_statement', 'notes', 'intermediates', 'provenance',
        ]) + [
            'created_at' => $result->created_at?->toJSON(),
            'updated_at' => $result->updated_at?->toJSON(),
        ])->all();
    }
}
