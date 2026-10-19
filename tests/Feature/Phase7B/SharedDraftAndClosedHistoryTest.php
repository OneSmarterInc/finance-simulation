<?php

namespace Tests\Feature\Phase7B;

use App\Livewire\Student\Decisions\DecisionWorkspace;
use App\Models\CourseQuarter;
use App\Models\FirmQuarterClose;
use App\Models\FirmQuarterDecision;
use App\Models\Student;
use App\Services\Decisions\FirmDecisionService;
use App\Services\Quarters\FirmQuarterCloseService;
use App\Services\Quarters\QuarterManagementService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use LogicException;
use Tests\Support\Phase7B\BuildsPhase7BCourse;
use Tests\TestCase;

class SharedDraftAndClosedHistoryTest extends TestCase
{
    use BuildsPhase7BCourse, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_shared_draft_is_explicit_last_write_wins_without_duplicate_or_partial_merge(): void
    {
        [$course, $firm, $studentA, $q4] = $this->phase7bCourse('Shared last-write-wins');
        $studentB = Student::query()->whereKeyNot($studentA->id)->firstOrFail();
        $this->addMember($firm, $studentB, $course->faculty);
        $quarters = app(QuarterManagementService::class);
        $quarters->open($course->faculty, $q4);
        $service = app(FirmDecisionService::class);
        $aLoaded = $service->decision($firm, $q4);
        $bLoaded = $service->decision($firm, $q4);
        $this->assertSame($aLoaded->id, $bLoaded->id);
        $service->saveDraft($studentA, $firm, $q4, $this->completePayload(10), 10);
        $bPayload = $this->completePayload(20);
        $service->saveDraft($studentB, $firm, $q4, $bPayload, 20);
        $current = $service->decision($firm, $q4);
        $this->assertEquals($bPayload, $current->draft_payload);
        $this->assertSame('20.00', $current->dividend);
        $this->assertDatabaseCount('firm_quarter_decisions', 1);
        $this->assertSame($aLoaded->id, $current->id);
        $this->assertSame(array_keys($bPayload), array_keys($current->draft_payload));
    }

    public function test_two_submitters_still_produce_one_logical_submission_and_one_close_snapshot(): void
    {
        [$course, $firm, $studentA, $q4] = $this->phase7bCourse('Shared submit race');
        $studentB = Student::query()->whereKeyNot($studentA->id)->firstOrFail();
        $this->addMember($firm, $studentB, $course->faculty);
        $quarters = app(QuarterManagementService::class);
        $quarters->open($course->faculty, $q4);
        $service = app(FirmDecisionService::class);
        $first = $service->submit($studentA, $firm, $q4, $this->completePayload(10), 0);
        $second = $service->submit($studentB, $firm, $q4, $this->completePayload(20), 0);
        $this->assertSame($first->id, $second->id);
        $this->assertEquals($this->completePayload(20), $second->submitted_payload);
        $this->assertSame(FirmQuarterDecision::SUBMITTED, $second->state);
        $this->assertNotNull($second->submitted_at);
        Queue::fake();
        $quarters->queueClose($course->faculty, $q4);
        $this->assertDatabaseCount('firm_quarter_decisions', 1);
        $this->assertDatabaseCount('firm_quarter_closes', 1);
        $this->assertSame(FirmQuarterDecision::LOCKED, $second->fresh()->state);
        $this->assertNotNull($second->fresh()->locked_at);
        $this->assertSame('0.00', $second->fresh()->dividend);
    }

    public function test_save_submit_and_dividend_change_are_rejected_after_close_snapshot_lock(): void
    {
        [$course, $firm, $studentA, $q4] = $this->phase7bCourse('Save during close');
        $studentB = Student::query()->whereKeyNot($studentA->id)->firstOrFail();
        $this->addMember($firm, $studentB, $course->faculty);
        $quarters = app(QuarterManagementService::class);
        $quarters->open($course->faculty, $q4);
        $service = app(FirmDecisionService::class);
        $decision = $service->submit($studentA, $firm, $q4, $this->completePayload(), 0);
        Queue::fake();
        $quarters->queueClose($course->faculty, $q4);
        $before = [$decision->fresh()->submitted_payload, $decision->fresh()->dividend, $decision->fresh()->locked_at->toJSON()];
        foreach ([
            fn () => $service->saveDraft($studentB, $firm, $q4, $this->completePayload(30), 50),
            fn () => $service->submit($studentB, $firm, $q4, $this->completePayload(40), 0),
            fn () => $service->saveDraft($studentB, $firm, $q4, $this->completePayload(), 100),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('Locked mutation was accepted.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('quarter', $e->errors());
            }
        }
        $after = $decision->fresh();
        $this->assertSame($before, [$after->submitted_payload, $after->dividend, $after->locked_at->toJSON()]);
    }

    public function test_closed_q4_result_is_immutable_through_normal_model_and_close_retry_paths(): void
    {
        [$course, $firm, $student, $q4] = $this->phase7bCourse('Closed Q4 immutability');
        $quarters = app(QuarterManagementService::class);
        $quarters->open($course->faculty, $q4);
        app(FirmDecisionService::class)->submit($student, $firm, $q4, $this->completePayload(), 0);
        Queue::fake();
        $quarters->queueClose($course->faculty, $q4);
        $attempt = FirmQuarterClose::query()->firstOrFail();
        $closer = app(FirmQuarterCloseService::class);
        $closer->close($attempt);
        $quarters->finalize($q4);
        $result = $firm->engineResults()->where('quarter_number', 4)->firstOrFail();
        $snapshot = [$result->id, $result->toArray(), $result->payload_hash, $result->created_at->toJSON(), $result->updated_at->toJSON(), $result->provenance];
        foreach ([
            fn () => $result->forceFill(['provenance' => 'replacement'])->save(),
            fn () => $result->delete(),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('Closed result mutation was accepted.');
            } catch (LogicException $e) {
                $this->assertStringContainsString('write-once', $e->getMessage());
            }
        }
        $closer->close($attempt->fresh());
        $after = $result->fresh();
        $this->assertSame($snapshot, [$after->id, $after->toArray(), $after->payload_hash, $after->created_at->toJSON(), $after->updated_at->toJSON(), $after->provenance]);
        $this->assertDatabaseCount('firm_engine_results', 4);
        $this->assertSame(CourseQuarter::CLOSED, $q4->fresh()->state);
    }

    public function test_student_workspace_renders_real_schema_dividend_maximum_errors_and_closed_history(): void
    {
        [$course, $firm, $student, $q4] = $this->phase7bCourse('Rendered student workspace');
        $quarters = app(QuarterManagementService::class);
        $quarters->open($course->faculty, $q4);
        $component = Livewire::actingAs($student->user)->test(DecisionWorkspace::class, ['firm' => $firm, 'quarter' => $q4])
            ->assertSee($firm->name)->assertSee('Quarter 4')->assertSee('Forecasting')->assertSee('Sourcing')->assertSee('Production')
            ->assertSee('Dividend')->assertSee('Most you can declare:')->assertSee('Save draft')->assertSee('Submit for the team')
            // Prefilled from the Quarter 3 warm-up decisions (walkthrough item 73), shares shown as percentages (item 75).
            ->assertSee('Last quarter:')->assertSet('decisions.sourcing.primary_alloc', 100.0);
        $display = $this->completePayload();
        $display['sourcing']['primary_alloc'] = 100;
        $component->set('decisions', $display);
        $component->set('dividend', '999999999999')->call('submit')->assertHasErrors('dividend')->assertSee('maximum permitted dividend');
        $component->set('dividend', '0')->call('submit')->assertHasNoErrors()->assertSee('Submitted for your team');
        $this->assertEquals(1.0, $firm->quarterDecisions()->firstOrFail()->submitted_payload['sourcing']['primary_alloc']);
        Queue::fake();
        $quarters->queueClose($course->faculty, $q4);
        $attempt = FirmQuarterClose::query()->firstOrFail();
        app(FirmQuarterCloseService::class)->close($attempt);
        $quarters->finalize($q4);
        Livewire::actingAs($student->user)->test(DecisionWorkspace::class, ['firm' => $firm, 'quarter' => $q4->fresh()])
            ->assertSee('decisions are locked')->assertSee('results are ready')->assertSee('Dividend')
            ->assertDontSee('Save draft')->assertDontSee('Submit for the team');
    }
}
