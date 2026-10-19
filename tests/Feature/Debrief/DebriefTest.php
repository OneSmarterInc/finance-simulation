<?php

namespace Tests\Feature\Debrief;

use App\Livewire\Faculty\Courses\Debrief;
use App\Livewire\Student\CourseDebrief;
use App\Livewire\Student\Courses;
use App\Models\Course;
use App\Models\CourseDebrief as DebriefRecord;
use App\Models\CourseQuarter;
use App\Models\Faculty;
use App\Models\FirmAccountingElectionRequest;
use App\Models\FirmEngineResult;
use App\Models\FirmQuarterDecision;
use App\Services\Ai\AskClaude;
use App\Services\CfPlus\CfPlusAuctionSettlementService;
use App\Services\Debrief\DebriefBuilder;
use App\Services\Debrief\DebriefCaptions;
use App\Services\Debrief\QuarterReplay;
use App\Services\Practice\PracticeRunService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\CfPlus\BuildsCfPlusMarket;
use Tests\Support\Practice\BuildsPracticeSource;
use Tests\TestCase;

/**
 * U4 layer 1 (DB1 to DB4, decided 19 October 2026): the class debrief, built
 * from stored results, worded by Claude with a fallback, hidden slide by
 * slide, and shared with students only after the course.
 */
class DebriefTest extends TestCase
{
    use BuildsCfPlusMarket, BuildsPracticeSource, RefreshDatabase;

    private Faculty $faculty;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->faculty = Faculty::query()->firstOrFail();
        config(['flexee.engine_version_for_new_courses' => 2]);
    }

    public function test_a_practice_run_gives_the_four_slides_and_the_method_slide_is_worked_out_by_the_engine(): void
    {
        $runs = app(PracticeRunService::class);
        $run = $runs->playTo($this->faculty, $runs->create($this->faculty, $this->sourceCourse('Debriefed', plus: true)), 11);
        $average = $run->firms()->where('code', 'AVERAGE')->firstOrFail();

        // Replaying a quarter with its own methods gives back the stored quarter to the cent.
        $stored = FirmEngineResult::query()->where('firm_id', $average->id)->where('quarter_number', 6)->firstOrFail();
        $this->assertEqualsWithDelta((float) $stored->income_statement['net_income'], app(QuarterReplay::class)->replay($average, 6)->incomeStatement['net_income'], 0.005);

        $built = app(DebriefBuilder::class)->build($run);
        $this->assertTrue($built['finished']);
        $this->assertSame(['race', 'profit_cash', 'reveal'], array_keys($built['slides']), 'no method changes, no method slide');
        $this->assertStringContainsString('ended Quarter 11 in front', $built['slides']['race']['fallback']);
        $this->assertGreaterThanOrEqual(4, $built['slides']['profit_cash']['data']['quarter']);
        $this->assertCount(3, $built['slides']['reveal']['data']['rows']);

        // An approved change from Quarter 6: the same quarter played again with the old method.
        $q6 = $run->quarters()->where('quarter_number', 6)->firstOrFail();
        $used = (array) FirmQuarterDecision::query()->where('firm_id', $average->id)->where('course_quarter_id', $q6->id)->firstOrFail()->resolved_payload['notes_elections'];
        $old = [...$used, 'depreciation_method' => $used['depreciation_method'] === 'straight_line' ? 'double_declining_balance' : 'straight_line'];
        FirmAccountingElectionRequest::query()->create(['firm_id' => $average->id, 'course_id' => $run->id, 'course_quarter_id' => $q6->id,
            'student_id' => $average->memberships()->value('student_id'), 'proposed_elections' => $used, 'previous_elections' => $old,
            'justification' => 'A reason.', 'status' => 'approved', 'effective_quarter_number' => 6, 'reviewed_at' => now()]);

        $row = app(DebriefBuilder::class)->build($run->fresh())['slides']['methods']['data']['rows'][0];
        $this->assertEqualsWithDelta((float) $stored->income_statement['net_income'], $row['net_income'], 0.005);
        $this->assertNotEquals($row['net_income'], $row['net_income_old'], 'the old method gives a different profit');
        $this->assertStringStartsWith('Depreciation method: ', $row['change']);
    }

    public function test_captions_come_from_claude_when_they_pass_the_checks_and_the_fallback_otherwise(): void
    {
        [$course] = $this->finishedMarket();
        $built = app(DebriefBuilder::class)->build($course);
        $captions = app(DebriefCaptions::class);
        $this->assertFalse($captions->due($course, $built), 'nothing is written while AI is off');
        $this->assertSame('code', $captions->captions($course, $built)['race']['by']);

        config(['flexee.ai.enabled' => true, 'services.anthropic.key' => 'test-key']);
        Http::fake(['*' => Http::response(['content' => [['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'write_captions', 'input' => [
            'race' => 'Charlie led in every quarter after the warm-up.',
            'profit_cash' => 'Profit and cash moved apart by $123,456,789.00.',
            'reveal' => 'Teams should read the notes before they bid.',
        ]]], 'stop_reason' => 'tool_use', 'usage' => ['input_tokens' => 2000, 'output_tokens' => 200]])]);

        $this->assertTrue($captions->due($course, $built));
        $record = $captions->write($course, $built);
        $out = $captions->captions($course, $built);

        $this->assertSame(['text' => 'Charlie led in every quarter after the warm-up.', 'by' => 'claude'], $out['race']);
        $this->assertSame('code', $out['profit_cash']['by'], 'an unmatched figure falls back');
        $this->assertSame('code', $out['reveal']['by'], 'advice falls back');
        $this->assertCount(2, $record->dropped);
        $this->assertFalse($captions->due($course, $built), 'written once for these figures');
        $this->assertSame($record->cost_usd, round(AskClaude::spentThisMonth(), 4));
        Http::assertSentCount(1);
    }

    public function test_faculty_hide_slides_and_share_after_the_course_and_students_see_what_is_shown(): void
    {
        [$course, $firms, $student] = $this->cfPlusMarket('Shared debrief');
        $this->actingAs($this->faculty->user);

        Livewire::test(Debrief::class, ['course' => $course])
            ->assertSee('The course is still running')
            ->call('toggleShared')
            ->assertSee('You can share the debrief once every quarter has closed and the evaluation stage is settled.');
        $this->assertFalse((bool) DebriefRecord::query()->where('course_id', $course->id)->value('shared'));

        [$course] = $this->finishedMarket($course);
        Livewire::test(Debrief::class, ['course' => $course->fresh()])
            ->assertSee('data-test="slide-race"', false)
            ->assertSee('data-test="slide-reveal"', false)
            ->call('toggleHidden', 'race')
            ->assertSee('(hidden)')
            ->assertDontSee('data-test="slide-race"', false)
            ->call('toggleShared')
            ->assertSee('Students can now see the debrief');

        $this->actingAs($student->user);
        Livewire::test(Courses::class)->assertSee('See the class debrief');
        Livewire::test(CourseDebrief::class, ['firm' => $firms['alpha']])
            ->assertSee('Class debrief')
            ->assertSee('data-test="slide-reveal"', false)
            ->assertDontSee('data-test="slide-race"', false);

        DebriefRecord::query()->update(['shared' => false]);
        $this->get(route('firms.debrief', $firms['alpha']))->assertNotFound();
    }

    public function test_a_practice_run_has_a_debrief_that_is_never_shared(): void
    {
        $runs = app(PracticeRunService::class);
        $run = $runs->playTo($this->faculty, $runs->create($this->faculty, $this->sourceCourse('Rehearsed debrief')), 5);
        $this->actingAs($this->faculty->user);

        Livewire::test(Debrief::class, ['course' => $run])
            ->assertSee('This is the debrief your practice run would give a class.')
            ->assertDontSee('Share with students')
            ->call('toggleShared')
            ->assertNotFound();
    }

    /** @return array{0: Course} the + market with every quarter closed and the stage settled */
    private function finishedMarket(?Course $course = null): array
    {
        $course ??= $this->cfPlusMarket('Finished market')[0];
        $course->quarters()->update(['state' => CourseQuarter::CLOSED]);
        app(CfPlusAuctionSettlementService::class)->settle($course->faculty, $course);

        return [$course->fresh()];
    }
}
