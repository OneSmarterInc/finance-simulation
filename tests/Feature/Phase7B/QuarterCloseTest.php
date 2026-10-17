<?php

namespace Tests\Feature\Phase7B;

use App\Jobs\Quarters\CloseCourseQuarter;
use App\Models\Course;
use App\Models\CourseDecisionEnablement;
use App\Models\CourseFacultyRole;
use App\Models\CourseQuarter;
use App\Models\Faculty;
use App\Models\Firm;
use App\Models\FirmMembership;
use App\Models\FirmQuarterClose;
use App\Models\FirmQuarterDecision;
use App\Models\Scenario;
use App\Models\Student;
use App\Services\Decisions\FirmDecisionService;
use App\Services\Engines\CorporateFinance\AccountingIdentityValidator;
use App\Services\Quarters\FirmQuarterCloseService;
use App\Services\Quarters\QuarterManagementService;
use App\Services\Warmup\InheritedWarmupHistoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class QuarterCloseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_q4_close_queues_locks_resolves_validates_and_appends_without_mutating_warmup(): void
    {
        [$course, $firm, $student, $q4] = $this->readyCourse();
        $hashes = $firm->engineResults()->orderBy('quarter_number')->pluck('payload_hash')->all();
        $quarters = app(QuarterManagementService::class);
        $quarters->open($course->faculty, $q4);
        $decision = app(FirmDecisionService::class)->submit($student, $firm, $q4, $this->payload(), 1000);
        Queue::fake();
        $quarters->queueClose($course->faculty, $q4);

        Queue::assertPushed(CloseCourseQuarter::class, 1);
        $this->assertSame(CourseQuarter::PROCESSING, $q4->fresh()->state);
        $this->assertSame(FirmQuarterDecision::LOCKED, $decision->fresh()->state);
        $this->assertNotNull($decision->fresh()->locked_at);

        $attempt = FirmQuarterClose::query()->firstOrFail();
        app(FirmQuarterCloseService::class)->close($attempt);
        $quarters->finalize($q4);

        $attempt->refresh();
        $decision->refresh();
        $result = $firm->engineResults()->where('quarter_number', 4)->firstOrFail();
        $this->assertSame(FirmQuarterClose::SUCCEEDED, $attempt->status);
        $this->assertSame(CourseQuarter::CLOSED, $q4->fresh()->state);
        $this->assertSame([1, 2, 3, 4], $firm->engineResults()->orderBy('quarter_number')->pluck('quarter_number')->all());
        $this->assertSame($hashes, $firm->engineResults()->where('quarter_number', '<=', 3)->orderBy('quarter_number')->pluck('payload_hash')->all());
        $this->assertSame('student_quarter_close', $result->provenance);
        $this->assertSame('1000.00', $decision->dividend);
        $this->assertEquals(-1000.0, $result->fields['cf_equity']);
        $this->assertNotEmpty($decision->resolved_payload);
        $this->assertArrayHasKey('pricing', $decision->resolved_payload);
        $this->assertArrayHasKey('notes_elections', $decision->resolved_payload);
        $this->assertNotEmpty($result->income_statement);
        $this->assertNotEmpty($result->balance_sheet);
        $this->assertNotEmpty($result->cash_flow_statement);
        $this->assertNotEmpty($result->notes);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result->payload_hash);
        $identity = new AccountingIdentityValidator;
        $this->assertEqualsWithDelta($identity->assets($result->fields), $identity->liabilities($result->fields) + $identity->equity($result->fields), 0.01);
    }

    public function test_q5_opens_from_persisted_q4_and_appends_an_independently_validated_result(): void
    {
        [$course, $firm, $student, $q4, $q5] = $this->readyCourse();
        $quarters = app(QuarterManagementService::class);
        $decisions = app(FirmDecisionService::class);
        $quarters->open($course->faculty, $q4);
        $decisions->submit($student, $firm, $q4, $this->payload(), 0);
        Queue::fake();
        $quarters->queueClose($course->faculty, $q4);
        app(FirmQuarterCloseService::class)->close(FirmQuarterClose::query()->where('course_quarter_id', $q4->id)->firstOrFail());
        $quarters->finalize($q4);
        $q4Result = $firm->engineResults()->where('quarter_number', 4)->firstOrFail();

        $quarters->open($course->faculty, $q5);
        $opening = $decisions->opening($firm, $q5);
        $this->assertSame((float) $q4Result->fields['bs_cash'], $opening->cash);
        $this->assertSame((float) $q4Result->fields['bs_ar'], $opening->accountsReceivable);
        $this->assertSame((float) $q4Result->fields['bs_inventory'], $opening->inventory);
        $this->assertSame((float) $q4Result->fields['bs_retained'] + (float) $q4Result->fields['bs_curr_ni'], $opening->retainedEarnings);
        $decisions->submit($student, $firm, $q5, $this->payload(), 0);
        $quarters->queueClose($course->faculty, $q5);
        app(FirmQuarterCloseService::class)->close(FirmQuarterClose::query()->where('course_quarter_id', $q5->id)->firstOrFail());
        $quarters->finalize($q5);

        $this->assertSame([1, 2, 3, 4, 5], $firm->engineResults()->orderBy('quarter_number')->pluck('quarter_number')->all());
        $this->assertSame(CourseQuarter::CLOSED, $q5->fresh()->state);
        $this->assertSame('student_quarter_close', $firm->engineResults()->where('quarter_number', 5)->value('provenance'));
    }

    public function test_next_quarter_cannot_open_before_q4_is_closed_and_duplicate_close_is_idempotent(): void
    {
        [$course, $firm, $student, $q4, $q5] = $this->readyCourse();
        $service = app(QuarterManagementService::class);
        try {
            $service->open($course->faculty, $q5);
            $this->fail('Q5 opened early.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('quarter', $e->errors());
        }
        $service->open($course->faculty, $q4);
        app(FirmDecisionService::class)->submit($student, $firm, $q4, $this->payload(), 0);
        Queue::fake();
        $service->queueClose($course->faculty, $q4);
        $service->queueClose($course->faculty, $q4);
        $this->assertDatabaseCount('firm_quarter_closes', 1);
        $this->assertDatabaseCount('firm_quarter_decisions', 1);
        Queue::assertPushed(CloseCourseQuarter::class, 2);
    }

    /** @return array{Course, Firm, Student, CourseQuarter, CourseQuarter} */
    private function readyCourse(): array
    {
        $faculty = Faculty::query()->firstOrFail();
        $course = Course::query()->create(['institution_id' => $faculty->institution_id, 'faculty_id' => $faculty->id, 'scenario_id' => Scenario::query()->where('code', 'cf_manufacturing')->value('id'), 'name' => 'Close Course', 'starts_on' => '2026-09-01', 'ends_on' => '2028-01-01', 'status' => 'deployed']);
        CourseFacultyRole::query()->create(['course_id' => $course->id, 'faculty_id' => $faculty->id, 'role' => CourseFacultyRole::ROLE_OWNER]);
        $quarters = collect();
        foreach (range(1, 5) as $number) {
            $quarters[$number] = CourseQuarter::query()->create(['course_id' => $course->id, 'quarter_number' => $number, 'starts_on' => now()->addMonths($number)->toDateString(), 'ends_on' => now()->addMonths($number + 1)->toDateString(), 'decision_deadline_at' => now()->addMonths($number + 1), 'state' => $number <= 3 ? CourseQuarter::CLOSED : CourseQuarter::LOCKED]);
        }
        foreach ([4, 5] as $number) {
            foreach (['forecasting', 'sourcing', 'production'] as $code) {
                CourseDecisionEnablement::query()->create(['course_quarter_id' => $quarters[$number]->id, 'scenario_decision_category_id' => $course->scenario->decisionCategories()->where('code', $code)->value('id'), 'enabled' => true]);
            }
        }
        $firm = Firm::factory()->create(['course_id' => $course->id, 'institution_id' => $course->institution_id]);
        $student = Student::query()->firstOrFail();
        FirmMembership::query()->create(['firm_id' => $firm->id, 'course_id' => $course->id, 'student_id' => $student->id, 'institution_id' => $course->institution_id, 'assigned_by_faculty_id' => $faculty->id, 'assigned_at' => now()]);
        app(InheritedWarmupHistoryService::class)->initialize($firm);

        return [$course->fresh(['faculty']), $firm->fresh(), $student, $quarters[4], $quarters[5]];
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['forecasting' => ['forecast_p1_r1' => 30000, 'forecast_p1_r2' => 40000, 'forecast_p1_r3' => 60000, 'forecast_p2_r1' => 27000, 'forecast_p2_r2' => 18000, 'forecast_p2_r3' => 16000, 'forecast_confidence' => 'medium'],
            'sourcing' => ['primary_supplier' => 'SUP001', 'primary_alloc' => 1, 'secondary_supplier' => 'NONE'],
            'production' => ['p1_production' => 130000, 'p2_production' => 61000, 'shifts' => 1, 'postponed_flag' => 'NO', 'postponed_pct' => 0]];
    }
}
