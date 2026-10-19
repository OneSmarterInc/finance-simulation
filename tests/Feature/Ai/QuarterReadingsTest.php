<?php

namespace Tests\Feature\Ai;

use App\Livewire\Faculty\Courses\QuarterResults;
use App\Livewire\InheritedHistory;
use App\Models\Course;
use App\Models\Faculty;
use App\Models\Firm;
use App\Models\FirmQuarterInterpretation;
use App\Models\FirmQuarterReading;
use App\Models\Student;
use App\Services\Ai\AdviceCheck;
use App\Services\Ai\AskClaude;
use App\Services\Ai\QuarterReadings;
use App\Services\Ai\ResultMeasures;
use App\Services\Interpretations\InterpretationService;
use App\Services\Practice\PracticeRunService;
use App\Services\Quarters\QuarterManagementService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\Phase7B\BuildsPhase7BCourse;
use Tests\Support\Practice\BuildsPracticeSource;
use Tests\TestCase;

/**
 * Live walkthrough L15 (item 53, N6, AI1 a; U11 layer 2, AR1 and AR2):
 * Claude's reading of each quarter, written once by the clock, checked in
 * code, stored, and shown under each report.
 */
class QuarterReadingsTest extends TestCase
{
    use BuildsPhase7BCourse, BuildsPracticeSource, RefreshDatabase;

    private Course $course;

    private Firm $firm;

    private Student $student;

    private Faculty $faculty;

    private string $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['flexee.ai.enabled' => true, 'services.anthropic.key' => 'test-key']);
        [$this->course, $this->firm, $this->student] = $this->phase7bCourse('Readings course');
        $this->faculty = $this->course->faculty;
        $q3 = $this->firm->engineResults()->where('quarter_number', 3)->firstOrFail();
        $this->cash = ResultMeasures::format('cash', ResultMeasures::value('cash', $q3));
    }

    public function test_a_reading_is_written_once_checked_in_code_and_stored(): void
    {
        $this->fakeReading();
        $q3 = $this->firm->engineResults()->where('quarter_number', 3)->firstOrFail();

        $row = app(QuarterReadings::class)->write($q3);

        $this->assertTrue($row->isWritten());
        $this->assertSame("Cash ended the quarter at {$this->cash}. It rose because customers paid faster.", $row->sections['balance_sheet']);
        $this->assertSame('Revenue held steady while costs rose slightly.', $row->sections['income_statement'], 'advice is dropped');
        $this->assertSame('The inventory note shows goods still on their way from the supplier.', $row->sections['notes'], 'an unmatched figure is dropped');
        $this->assertSame(['It told the team what to do.', 'It quoted $12,345,678.90, which isn\'t in the stored results.'], array_column($row->dropped, 'reason'));
        $this->assertGreaterThan(0, $row->cost_usd);
        $this->assertSame(1, $row->attempts);

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            // Claude Sonnet 5.5 refuses a forced tool_choice: the tool is offered with auto, and the prompt says to use it.
            return $body['tool_choice'] === ['type' => 'auto']
                && $body['tools'][0]['name'] === 'write_reading'
                && $body['thinking'] === ['type' => 'between_tools']
                && str_contains($body['system'][0]['text'], 'Answer only by calling the write_reading tool')
                && ! isset($body['messages'][1])
                && str_contains($body['messages'][0]['content'], 'THE QUARTER TO READ: Quarter 3, a warm-up quarter')
                && str_contains($body['messages'][0]['content'], 'COMPARED WITH: Quarter 2.')
                && str_contains($body['messages'][0]['content'], 'BALANCE SHEET')
                && str_contains($body['system'][0]['text'], 'Never tell the team what to do');
        });
        $this->assertSame($row->cost_usd, round(AskClaude::spentThisMonth(), 4), 'readings count against the site ceiling');
    }

    public function test_the_clock_writes_what_is_missing_once_and_nothing_while_both_switches_are_off(): void
    {
        $readings = app(QuarterReadings::class);
        $this->course->forceFill(['ai_reading_faculty' => false, 'ai_reading_students' => false])->save();
        $this->assertTrue($readings->due(10)->isEmpty(), 'nothing is written while both switches are off');

        $this->course->forceFill(['ai_reading_students' => true])->save();
        $this->fakeReading();

        $this->assertSame(3, $readings->tick(10));
        $this->assertSame([1, 2, 3], FirmQuarterReading::query()->where('status', FirmQuarterReading::WRITTEN)->orderBy('quarter_number')->pluck('quarter_number')->all());
        Http::assertSentCount(3);
        $this->assertSame(64, strlen((string) FirmQuarterReading::query()->value('source_hash')), 'each reading records what it was written from');
        $this->assertTrue($readings->due(10)->isEmpty());

        config(['flexee.ai.enabled' => false]);
        $this->assertFalse(QuarterReadings::writing($this->course->fresh()));
    }

    public function test_a_reading_that_fails_three_times_is_left_out(): void
    {
        Http::fake(['*' => Http::response(['error' => 'overloaded'], 529)]);
        $readings = app(QuarterReadings::class);
        $q1 = fn () => $this->firm->engineResults()->where('quarter_number', 1)->firstOrFail();

        foreach (range(1, 3) as $attempt) {
            $this->assertTrue($readings->due(10)->contains('id', $q1()->id), "due on attempt {$attempt}");
            $readings->tick(1);
            $this->assertFalse($readings->due(10)->contains('id', $q1()->id), 'not retried straight away');
            $this->travel(3)->minutes();
        }
        $this->assertFalse($readings->due(10)->contains('id', $q1()->id), 'three failures and it stops');

        $entries = $readings->forFaculty($this->course, ['Q1 · warm-up' => $q1()])['entries'];
        $this->assertSame('failed', $entries['Q1 · warm-up']['state']);

        // Once the fault is fixed, flexee:ai-retry puts it back in the queue.
        $this->artisan('flexee:ai-retry')->expectsOutputToContain('Cleared 1 failed readings')->assertSuccessful();
        $this->assertTrue($readings->due(10)->contains('id', $q1()->id));
    }

    public function test_students_see_a_quarter_only_after_their_explanation_or_its_deadline(): void
    {
        $readings = app(QuarterReadings::class);
        $this->fakeReading();
        foreach ($this->firm->engineResults()->get() as $result) {
            $readings->write($result);
        }
        $columns = fn () => $this->firm->engineResults()->orderBy('quarter_number')->get()->mapWithKeys(fn ($r) => [InheritedHistory::columnLabel($r) => $r])->all();

        $this->assertNull($readings->forStudents($this->firm, $columns()), '"Show to students" starts off');
        $this->assertNotNull($readings->forFaculty($this->course, $columns()), '"Show to me" starts on');

        $this->course->forceFill(['ai_reading_students' => true, 'interpretations_required' => true])->save();
        $this->firm->refresh();
        $q4 = $this->course->quarters()->where('quarter_number', 4)->firstOrFail();
        $entries = $readings->forStudents($this->firm, $columns())['entries'];
        $this->assertSame('written', $entries['Q2 · warm-up']['state']);
        $this->assertSame('after_explanation', $entries['Q3 · warm-up']['state'], 'Quarter 4 is not open yet');

        app(QuarterManagementService::class)->open($this->faculty, $q4);
        $this->assertSame('after_explanation', $readings->forStudents($this->firm, $columns())['entries']['Q3 · warm-up']['state']);

        app(InterpretationService::class)->submit($this->student, $this->firm, $q4->fresh(), [
            'changed' => 'Net revenue and cash both rose from Quarter 2 to Quarter 3.',
            'why' => 'Customers ordered more, and the simulation produced to match their orders.',
            'notes' => 'The inventory note shows goods in transit that the statements fold into inventory.',
        ]);
        $this->assertSame('written', $readings->forStudents($this->firm, $columns())['entries']['Q3 · warm-up']['state']);

        // A team that never explained sees it once the deadline has passed (AR1).
        FirmQuarterInterpretation::query()->delete();
        $this->assertSame('after_explanation', $readings->forStudents($this->firm, $columns())['entries']['Q3 · warm-up']['state']);
        $q4->refresh()->forceFill(['opened_at' => now()->subDays(30), 'decision_deadline_at' => now()->subDay()])->save();
        $this->assertSame('written', $readings->forStudents($this->firm, $columns())['entries']['Q3 · warm-up']['state']);

        // A course that doesn't ask for explanations shows it straight after the close.
        $q4->forceFill(['decision_deadline_at' => now()->addWeek()])->save();
        $this->course->forceFill(['interpretations_required' => false])->save();
        $this->firm->refresh();
        $this->assertSame('written', $readings->forStudents($this->firm, $columns())['entries']['Q3 · warm-up']['state']);
    }

    public function test_history_and_quarter_results_show_the_reading_under_each_report_with_the_switches(): void
    {
        $this->fakeReading();
        app(QuarterReadings::class)->tick(10);

        $this->actingAs($this->faculty->user);
        Livewire::test(InheritedHistory::class, ['firm' => $this->firm])
            ->assertSee('What this shows')
            ->assertSee('data-test="reading-balance_sheet"', false)
            ->assertSee("Cash ended the quarter at {$this->cash}.")
            ->assertSee('failed the checks and were left out', false);

        Livewire::test(QuarterResults::class, ['course' => $this->course])
            ->assertSee('Claude\'s reading of each quarter', false)
            ->assertSee('Shown to you')
            ->assertSee('Not shown to students')
            ->assertSee('What this shows')
            ->call('toggleReading', 'students')
            ->assertSee('Shown to students')
            ->call('toggleReading', 'faculty')
            ->assertSee('Not shown to you')
            ->assertDontSee('data-test="reading-summary"', false);
        $this->assertTrue($this->course->fresh()->ai_reading_students);

        $this->actingAs($this->student->user);
        Livewire::test(InheritedHistory::class, ['firm' => $this->firm->fresh()])
            ->assertSee('What this shows')
            ->assertSee('It describes what happened. It doesn&#039;t tell you what to decide.', false)
            ->assertDontSee('failed the checks');

        $this->course->refresh()->forceFill(['ai_reading_students' => false])->save();
        Livewire::test(InheritedHistory::class, ['firm' => $this->firm->fresh()])->assertDontSee('What this shows');
    }

    public function test_practice_runs_follow_the_source_courses_show_to_me(): void
    {
        config(['flexee.engine_version_for_new_courses' => 2]);
        $source = $this->sourceCourse('Practice readings');
        $practice = app(PracticeRunService::class)->create($source->faculty, $source);

        $this->assertTrue(QuarterReadings::writing($practice));
        $this->assertTrue(QuarterReadings::showToFaculty($practice));
        $this->assertFalse(QuarterReadings::showToStudents($practice));

        $source->forceFill(['ai_reading_faculty' => false, 'ai_reading_students' => true])->save();
        $this->assertFalse(QuarterReadings::writing($practice->fresh()), 'a practice run follows "Show to me" only');

        // Removing a practice run removes its readings.
        $source->forceFill(['ai_reading_faculty' => true])->save();
        $this->fakeReading();
        app(QuarterReadings::class)->tick(3);
        $this->assertGreaterThan(0, FirmQuarterReading::query()->where('course_id', $practice->id)->count());
        app(PracticeRunService::class)->remove($source->faculty, $practice->fresh());
        $this->assertSame(0, FirmQuarterReading::query()->where('course_id', $practice->id)->count());
    }

    public function test_the_advice_check_drops_sentences_that_tell_the_team_what_to_do(): void
    {
        $out = AdviceCheck::apply('Cash fell. The team should hold more stock. Consider a cheaper supplier. Next quarter, try a higher price. The notes suggest customers paid later. Keep an eye on cash.');

        $this->assertSame('Cash fell. The notes suggest customers paid later.', $out['text']);
        $this->assertCount(4, $out['dropped']);
    }

    public function test_a_carried_forward_quarter_is_named_first_and_readings_can_be_rewritten(): void
    {
        // The live Course 02 case (19 October 2026): nobody submitted Quarter 4, so Quarter 3's decisions were used again.
        $quarters = app(QuarterManagementService::class);
        $q4 = $this->course->quarters()->where('quarter_number', 4)->firstOrFail();
        $quarters->open($this->faculty, $q4);
        $quarters->lockAndQueue($q4->fresh(), carryForward: true);
        $quarters->finalize($q4->fresh());
        $this->fakeReading();

        $result = $this->firm->engineResults()->where('quarter_number', 4)->firstOrFail();
        app(QuarterReadings::class)->write($result);

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return str_contains($body['messages'][0]['content'], "THE QUARTER TO READ: Quarter 4, but the team did not submit decisions for it, so Quarter 3's decisions were used again.")
                && str_contains($body['system'][0]['text'], 'say so in the first sentence of the summary')
                && str_contains($body['system'][0]['text'], 'Don\'t explain a line only by saying an earlier change "did not repeat"');
        });

        // After a change to the prompt, every reading can be written again.
        $this->assertSame(1, FirmQuarterReading::query()->where('status', 'written')->count());
        $this->artisan('flexee:ai-retry', ['--rewrite-readings' => true])->expectsOutputToContain('Cleared 1 readings (to be rewritten)')->assertSuccessful();
        $this->assertTrue(app(QuarterReadings::class)->due(10)->contains('id', $result->id));
    }

    private function fakeReading(): void
    {
        Http::fake(['*' => Http::response([
            'content' => [['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'write_reading', 'input' => [
                'summary' => 'The firm sold about as much as the quarter before.',
                'income_statement' => 'Revenue held steady while costs rose slightly. The team should raise prices next quarter.',
                'balance_sheet' => "Cash ended the quarter at {$this->cash}. It rose because customers paid faster.",
                'cash_flow_statement' => 'Operating activities brought in more cash than the firm spent.',
                'notes' => 'The inventory note shows goods still on their way from the supplier. They were worth $12,345,678.90.',
            ]]],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 3000, 'output_tokens' => 400],
        ])]);
    }
}
