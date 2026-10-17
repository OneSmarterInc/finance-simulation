<?php

namespace Tests\Feature\Walkthrough;

use App\Models\CourseQuarter;
use App\Models\Firm;
use App\Models\FirmQuarterDecision;
use App\Models\Student;
use App\Services\Decisions\FirmDecisionService;
use App\Services\Quarters\QuarterManagementService;
use App\Services\Quarters\QuarterScheduler;
use App\Services\Warmup\InheritedWarmupHistoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\Phase7B\BuildsPhase7BCourse;
use Tests\TestCase;

/** Walkthrough items 46 to 51, decisions N4 and N5. */
class QuarterSchedulerTest extends TestCase
{
    use BuildsPhase7BCourse, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_quarter_four_opens_on_its_start_date_and_not_before(): void
    {
        [, , , $q4] = $this->phase7bCourse('Opens on schedule');
        $scheduler = app(QuarterScheduler::class);

        $scheduler->tick();
        $this->assertSame(CourseQuarter::LOCKED, $q4->fresh()->state);

        $q4->forceFill(['starts_on' => now()->subDay()->toDateString()])->save();
        $scheduler->tick();
        $this->assertSame(CourseQuarter::OPEN, $q4->fresh()->state);
    }

    public function test_quarter_stays_open_until_the_deadline_even_when_every_firm_has_submitted(): void
    {
        [$course, $firm, $student, $q4] = $this->phase7bCourse('Stays open');
        app(QuarterManagementService::class)->open($course->faculty, $q4);
        app(FirmDecisionService::class)->submit($student, $firm, $q4, $this->completePayload(), 0);

        app(QuarterScheduler::class)->tick();

        $this->assertSame(CourseQuarter::OPEN, $q4->fresh()->state);
    }

    public function test_at_the_deadline_results_are_calculated_non_submitters_carry_forward_and_the_next_quarter_opens(): void
    {
        [$course, $firm, $student, $q4, $q5] = $this->phase7bCourse('Deadline close');
        $silent = Firm::factory()->create(['course_id' => $course->id, 'institution_id' => $course->institution_id, 'code' => 'ZZ', 'name' => 'Silent Firm']);
        $this->addMember($silent, Student::factory()->create(['institution_id' => $course->institution_id]), $course->faculty);
        app(InheritedWarmupHistoryService::class)->initialize($silent);

        $quarters = app(QuarterManagementService::class);
        $quarters->open($course->faculty, $q4);
        app(FirmDecisionService::class)->submit($student, $firm, $q4, $this->completePayload(), 0);
        // A draft is not used for a firm that never submits (N4).
        $draftStudent = $silent->memberships()->firstOrFail()->student;
        app(FirmDecisionService::class)->saveDraft($draftStudent, $silent, $q4, ['forecasting' => ['forecast_p1_r1' => 1]], 0);

        $q4->forceFill(['opened_at' => now()->subDays(7), 'decision_deadline_at' => now()->subMinute()])->save();
        $scheduler = app(QuarterScheduler::class);
        $scheduler->tick();

        $this->assertSame(CourseQuarter::CLOSED, $q4->fresh()->state);
        $this->assertSame(1, $firm->engineResults()->where('quarter_number', 4)->count());
        $this->assertSame(1, $silent->engineResults()->where('quarter_number', 4)->count());

        $carried = FirmQuarterDecision::query()->where('firm_id', $silent->id)->where('course_quarter_id', $q4->id)->firstOrFail();
        $this->assertTrue($carried->carried_forward);
        $this->assertSame(FirmQuarterDecision::LOCKED, $carried->state);
        $this->assertNotSame(1, $carried->submitted_payload['forecasting']['forecast_p1_r1']);
        $this->assertGreaterThan(1000, $carried->submitted_payload['forecasting']['forecast_p1_r1']);

        $submitted = FirmQuarterDecision::query()->where('firm_id', $firm->id)->where('course_quarter_id', $q4->id)->firstOrFail();
        $this->assertFalse($submitted->carried_forward);
        $this->assertSame($student->id, $submitted->submitted_by_student_id);

        $scheduler->tick();
        $this->assertSame(CourseQuarter::OPEN, $q5->fresh()->state);
    }

    public function test_a_quarter_that_opens_after_its_deadline_waits_for_a_new_deadline_instead_of_closing(): void
    {
        [$course, , , $q4] = $this->phase7bCourse('Late open');
        $q4->forceFill(['starts_on' => now()->subMonths(3)->toDateString(), 'decision_deadline_at' => now()->subMonth()])->save();
        $scheduler = app(QuarterScheduler::class);

        $scheduler->tick();
        $scheduler->tick();

        $this->assertSame(CourseQuarter::OPEN, $q4->fresh()->state);
        $this->assertTrue(QuarterScheduler::needsNewDeadline($q4->fresh()));
        $this->assertNotEmpty(collect(\App\Services\Courses\CourseStatus::for($course->fresh())->needsYou())
            ->filter(fn ($item) => str_contains($item['text'], 'opened after its deadline')));

        app(QuarterManagementService::class)->extendDeadline($course->faculty, $q4->fresh(), now()->addWeek()->toDateTimeString());
        $this->assertFalse(QuarterScheduler::needsNewDeadline($q4->fresh()));
    }

    public function test_faculty_can_extend_the_deadline_but_not_shorten_it_or_pass_the_next_deadline(): void
    {
        [$course, , , $q4, $q5] = $this->phase7bCourse('Extend deadline');
        $quarters = app(QuarterManagementService::class);
        $quarters->open($course->faculty, $q4);
        $current = $q4->fresh()->decision_deadline_at;

        $quarters->extendDeadline($course->faculty, $q4->fresh(), $current->addDays(2)->toDateTimeString());
        $this->assertTrue($q4->fresh()->decision_deadline_at->equalTo($current->addDays(2)));
        $this->assertNotNull($q4->fresh()->deadline_extended_at);

        foreach ([$current->subDay(), $q5->decision_deadline_at->addDay()] as $bad) {
            try {
                $quarters->extendDeadline($course->faculty, $q4->fresh(), $bad->toDateTimeString());
                $this->fail('Expected the extension to be refused.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }
}
