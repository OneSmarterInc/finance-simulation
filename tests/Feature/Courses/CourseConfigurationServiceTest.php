<?php

namespace Tests\Feature\Courses;

use App\Events\CourseDeploymentReachedWarmupBoundary;
use App\Models\Course;
use App\Models\CourseDecisionEnablement;
use App\Models\CourseQuarter;
use App\Models\Faculty;
use App\Models\Institution;
use App\Models\Scenario;
use App\Services\Courses\CourseConfigurationService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CourseConfigurationServiceTest extends TestCase
{
    use RefreshDatabase;

    private CourseConfigurationService $service;

    private Faculty $faculty;

    private Scenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->service = app(CourseConfigurationService::class);
        $this->faculty = Faculty::factory()->create();
        $this->scenario = Scenario::query()->where('code', 'cf_manufacturing')->firstOrFail();
    }

    public function test_course_uses_uuid_relationships_and_soft_deletes(): void
    {
        $course = $this->course();
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $course->id);
        $this->assertTrue($course->institution->is($this->faculty->institution));
        $this->assertTrue($course->faculty->is($this->faculty));
        $this->assertTrue($course->scenario->is($this->scenario));
        $course->delete();
        $this->assertSoftDeleted('courses', ['id' => $course->id]);
    }

    public function test_setup_rejects_another_institution_and_clears_disabled_cf_plus_counts(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->service->saveSetup($this->faculty, [...$this->setupData(), 'institution_id' => Institution::factory()->create()->id]);
    }

    public function test_disabled_cf_plus_clears_phase_counts(): void
    {
        $course = $this->course();
        $this->assertFalse($course->cf_plus_enabled);
        $this->assertNull($course->build_quarters);
        $this->assertNull($course->adversarial_quarters);
        $this->assertNull($course->post_bid_quarters);
    }

    public function test_schedule_rejects_reversed_dates_deadlines_overlap_and_sequence_gaps(): void
    {
        foreach (['reversed', 'deadline', 'overlap', 'gap'] as $case) {
            $course = $this->course();
            // Quarters 1 to 3 are put on the warm-up date on every save (R9), so the cases use Quarters 4 and 5.
            $quarters = $this->schedule(5);
            if ($case === 'reversed') {
                $quarters[3]['starts_on'] = '2027-12-01';
            }
            if ($case === 'deadline') {
                $quarters[3]['decision_deadline_at'] = '2026-12-31';
            }
            if ($case === 'overlap') {
                $quarters[4]['starts_on'] = $quarters[3]['ends_on'];
            }
            if ($case === 'gap') {
                $quarters[2]['quarter_number'] = 8;
            }
            try {
                $this->service->saveSchedule($this->faculty, $course, $quarters);
                $this->fail("{$case} schedule should fail");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('quarters', $exception->errors());
            }
        }
    }

    public function test_cf_plus_exact_minimum_passes_and_one_short_fails(): void
    {
        $course = $this->service->saveSetup($this->faculty, [...$this->setupData(), 'cf_plus_enabled' => true, 'build_quarters' => 2, 'adversarial_quarters' => 1, 'post_bid_quarters' => 1]);
        $this->service->saveSchedule($this->faculty, $course, $this->schedule(7));
        $this->assertCount(7, $course->quarters);
        $this->expectException(ValidationException::class);
        $this->service->saveSchedule($this->faculty, $course, $this->schedule(6));
    }

    #[DataProvider('missingQuarterFourProvider')]
    public function test_quarter_four_minimum_rejects_each_missing_combination(array $missing): void
    {
        $course = $this->configuredSchedule();
        $matrix = $this->matrix($course);
        foreach ($missing as $code) {
            $matrix[4][$code] = false;
        }
        $this->expectException(ValidationException::class);
        $this->service->saveEnablements($this->faculty, $course, $matrix);
    }

    public static function missingQuarterFourProvider(): array
    {
        return [[['forecasting']], [['sourcing']], [['production']], [['forecasting', 'sourcing']], [['forecasting', 'sourcing', 'production']]];
    }

    public function test_complete_minimum_saves_all_keys_without_duplicates(): void
    {
        $course = $this->configuredSchedule(6);
        $this->service->saveEnablements($this->faculty, $course, $this->matrix($course));
        $this->service->saveEnablements($this->faculty, $course, $this->matrix($course));
        $this->assertSame(27, CourseDecisionEnablement::query()->count());
    }

    #[DataProvider('monotonicProvider')]
    public function test_monotonic_operating_patterns_are_enforced(array $pattern, bool $valid): void
    {
        $course = $this->configuredSchedule(6);
        $matrix = $this->matrix($course);
        foreach ([4, 5, 6] as $index => $quarter) {
            $matrix[$quarter]['marketing'] = $pattern[$index];
        }
        if (! $valid) {
            $this->expectException(ValidationException::class);
        }
        $this->service->saveEnablements($this->faculty, $course, $matrix);
        if ($valid) {
            $this->assertTrue(true);
        }
    }

    public static function monotonicProvider(): array
    {
        return [[[false, true, true], true], [[false, false, true], true], [[true, true, true], true], [[true, false, false], false], [[true, true, false], false]];
    }

    public function test_certification_is_independent_and_not_part_of_minimum(): void
    {
        $course = $this->configuredSchedule(6);
        $matrix = $this->matrix($course);
        $matrix[4]['certification'] = true;
        $matrix[5]['certification'] = false;
        $matrix[6]['certification'] = true;
        $this->service->saveEnablements($this->faculty, $course, $matrix);
        $values = CourseDecisionEnablement::query()->whereHas('category', fn ($q) => $q->where('code', 'certification'))->whereHas('quarter', fn ($q) => $q->orderBy('quarter_number'))->get()->sortBy(fn ($row) => $row->quarter->quarter_number)->pluck('enabled')->all();
        $this->assertSame([true, false, true], $values);
    }

    public function test_faculty_roles_are_scoped_unique_and_keep_owner(): void
    {
        $course = $this->course();
        $co = Faculty::factory()->for($this->faculty->institution)->create();
        $this->service->saveFacultyRoles($this->faculty, $course, [$co->id, $co->id]);
        $this->assertDatabaseHas('course_faculty_roles', ['course_id' => $course->id, 'faculty_id' => $this->faculty->id, 'role' => 'owner']);
        $this->assertDatabaseHas('course_faculty_roles', ['course_id' => $course->id, 'faculty_id' => $co->id, 'role' => 'co_faculty']);
        $this->expectException(ValidationException::class);
        $this->service->saveFacultyRoles($this->faculty, $course, [Faculty::factory()->create()->id]);
    }

    public function test_duplicate_quarter_and_enablement_constraints_are_database_enforced(): void
    {
        $course = $this->configuredSchedule();
        $quarter = $course->quarters()->firstOrFail();
        try {
            CourseQuarter::query()->create($quarter->only(['course_id', 'quarter_number', 'starts_on', 'ends_on', 'decision_deadline_at']));
            $this->fail('Duplicate quarter should fail.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    public function test_complete_deploy_is_idempotent_emits_boundary_and_does_not_create_phase_seven_b_tables(): void
    {
        Event::fake([CourseDeploymentReachedWarmupBoundary::class]);
        Queue::fake();
        $course = $this->service->saveSetup($this->faculty, [...$this->setupData(),
            'cf_plus_enabled' => true,
            'build_quarters' => 2,
            'adversarial_quarters' => 1,
            'post_bid_quarters' => 1,
        ]);
        $this->service->saveSchedule($this->faculty, $course, $this->schedule(7));
        $this->service->saveEnablements($this->faculty, $course, $this->matrix($course));
        $coFaculty = Faculty::factory()->for($this->faculty->institution)->create();
        $this->service->saveFacultyRoles($this->faculty, $course, [$coFaculty->id]);

        $quarterIds = $course->quarters()->orderBy('quarter_number')->pluck('id')->all();
        $enablementCount = CourseDecisionEnablement::query()->count();
        $roleCount = $course->facultyRoles()->count();
        $setup = [
            'name' => $course->name,
            'institution_id' => $course->institution_id,
            'faculty_id' => $course->faculty_id,
            'scenario_id' => $course->scenario_id,
            'starts_on' => $course->starts_on->toDateString(),
            'ends_on' => $course->ends_on->toDateString(),
            'payment_mode' => $course->payment_mode,
        ];

        $deployed = $this->service->deploy($this->faculty, $course);
        $this->assertSame(Course::STATUS_DEPLOYED, $deployed->status);
        $this->assertNotNull($deployed->deployed_at);
        $this->assertSame($setup, [
            'name' => $deployed->name,
            'institution_id' => $deployed->institution_id,
            'faculty_id' => $deployed->faculty_id,
            'scenario_id' => $deployed->scenario_id,
            'starts_on' => $deployed->starts_on->toDateString(),
            'ends_on' => $deployed->ends_on->toDateString(),
            'payment_mode' => $deployed->payment_mode,
        ]);
        $this->assertSame($quarterIds, $deployed->quarters()->orderBy('quarter_number')->pluck('id')->all());
        $this->assertSame(7, $deployed->quarters()->count());
        $this->assertSame(36, $enablementCount);
        $this->assertSame($enablementCount, CourseDecisionEnablement::query()->count());
        $this->assertSame(2, $roleCount);
        $this->assertSame($roleCount, $deployed->facultyRoles()->count());
        $this->assertTrue($deployed->cf_plus_enabled);
        $this->assertSame(2, $deployed->build_quarters);
        $this->assertSame(1, $deployed->adversarial_quarters);
        $this->assertSame(1, $deployed->post_bid_quarters);
        Event::assertDispatchedTimes(CourseDeploymentReachedWarmupBoundary::class, 1);
        Event::assertDispatched(CourseDeploymentReachedWarmupBoundary::class, function ($event) use ($deployed): bool {
            $this->assertSame($deployed->id, $event->courseId);
            $this->assertSame($deployed->scenario_id, $event->scenarioId);
            $this->assertSame('cf_manufacturing', $event->scenarioCode);
            $this->assertSame($deployed->institution_id, $event->institutionId);
            $this->assertSame($deployed->faculty_id, $event->owningFacultyId);
            $this->assertSame('deployed', $event->status);
            $this->assertSame(range(1, 7), array_column($event->quarters, 'quarter_number'));
            $this->assertSame('2026-12-31', $event->quarters[0]['starts_on'], 'the warm-up sits on the day before the course starts (R9)');
            $this->assertSame('2027-12-31', $event->quarters[6]['ends_on']);
            $this->assertTrue($event->cfPlusEnabled);
            $this->assertSame(2, $event->buildQuarters);
            $this->assertSame(1, $event->adversarialQuarters);
            $this->assertSame(1, $event->postBidQuarters);
            $this->assertSame(4, $event->studentControlStartsAtQuarter);
            $serialized = json_encode(get_object_vars($event), JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('password', strtolower($serialized));
            $this->assertStringNotContainsString('token', strtolower($serialized));

            return true;
        });
        Queue::assertNothingPushed();
        $this->assertTrue(Schema::hasTable('firms'));
        $this->assertTrue(Schema::hasTable('enrollments'));
        $this->assertFalse(Schema::hasTable('quarter_closes'));
        $this->assertFalse(Schema::hasTable('warmup_results'));

        $beforeRepeat = $deployed->fresh()->getAttributes();
        $again = $this->service->deploy($this->faculty, $deployed);
        $this->assertSame(Course::STATUS_DEPLOYED, $again->status);
        $this->assertSame($beforeRepeat, $again->fresh()->getAttributes());
        $this->assertSame($quarterIds, $again->quarters()->orderBy('quarter_number')->pluck('id')->all());
        $this->assertSame(7, $again->quarters()->count());
        $this->assertSame($enablementCount, CourseDecisionEnablement::query()->count());
        $this->assertSame($roleCount, $again->facultyRoles()->count());
        Event::assertDispatchedTimes(CourseDeploymentReachedWarmupBoundary::class, 1);
        Queue::assertNothingPushed();
    }

    public function test_direct_enablement_save_rejects_warmup_quarters(): void
    {
        $course = $this->configuredSchedule();
        $matrix = $this->matrix($course);
        $matrix[1] = $matrix[4];

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Quarters 1 through 3 are reserved');
        $this->service->saveEnablements($this->faculty, $course, $matrix);
    }

    public function test_deployment_rejects_a_warmup_quarter_enablement(): void
    {
        $course = $this->configuredSchedule();
        $this->service->saveEnablements($this->faculty, $course, $this->matrix($course));
        $quarterOne = $course->quarters()->where('quarter_number', 1)->firstOrFail();
        $category = $this->scenario->decisionCategories()->where('code', 'forecasting')->firstOrFail();
        CourseDecisionEnablement::query()->create([
            'course_quarter_id' => $quarterOne->id,
            'scenario_decision_category_id' => $category->id,
            'enabled' => true,
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('reserved for warm-up');
        $this->service->deploy($this->faculty, $course);
    }

    public function test_incomplete_course_cannot_deploy_and_remains_draft(): void
    {
        $course = $this->configuredSchedule();
        try {
            $this->service->deploy($this->faculty, $course);
        } catch (ValidationException) {
        }
        $this->assertSame('draft', $course->refresh()->status);
        $this->assertNull($course->deployed_at);
    }

    public function test_cross_course_access_is_rejected(): void
    {
        $course = $this->course();
        $this->expectException(AuthorizationException::class);
        $this->service->authorize(Faculty::factory()->create(), $course);
    }

    private function course(): Course
    {
        return $this->service->saveSetup($this->faculty, $this->setupData());
    }

    private function setupData(): array
    {
        return ['name' => 'Corporate Finance Lab', 'institution_id' => $this->faculty->institution_id, 'scenario_id' => $this->scenario->id, 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31', 'payment_mode' => 'invoice', 'cf_plus_enabled' => false, 'accounting_election_enabled' => false, 'build_quarters' => 1, 'adversarial_quarters' => 1, 'post_bid_quarters' => 1];
    }

    private function configuredSchedule(int $count = 4): Course
    {
        $course = $this->course();
        $this->service->saveSchedule($this->faculty, $course, $this->schedule($count));

        return $course->refresh();
    }

    private function schedule(int $count): array
    {
        $rows = [];
        $start = CarbonImmutable::parse('2027-01-01');
        for ($q = 1; $q <= $count; $q++) {
            $end = $q === $count ? CarbonImmutable::parse('2027-12-31') : $start->addDays(29);
            $rows[] = ['quarter_number' => $q, 'starts_on' => $start->toDateString(), 'ends_on' => $end->toDateString(), 'decision_deadline_at' => $end->toDateString()];
            $start = $end->addDay();
        }

        return $rows;
    }

    private function matrix(Course $course): array
    {
        $codes = $this->scenario->decisionCategories()->pluck('code');
        $matrix = [];
        foreach ($course->quarters()->where('quarter_number', '>=', 4)->pluck('quarter_number') as $quarter) {
            foreach ($codes as $code) {
                $matrix[$quarter][$code] = in_array($code, CourseConfigurationService::REQUIRED_QUARTER_FOUR, true);
            }
        }

        return $matrix;
    }
}
