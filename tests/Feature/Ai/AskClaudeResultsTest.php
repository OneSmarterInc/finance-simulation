<?php

namespace Tests\Feature\Ai;

use App\Livewire\AskClaudePanel;
use App\Models\Course;
use App\Models\Faculty;
use App\Models\Firm;
use App\Models\FirmAccountingElectionRequest;
use App\Models\FirmQuarterDecision;
use App\Services\Ai\AskClaude;
use App\Services\Ai\AskClaudeTools;
use App\Services\Ai\ResultMeasures;
use App\Services\Practice\PracticeRunService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\Phase7B\BuildsPhase7BCourse;
use Tests\Support\Practice\BuildsPracticeSource;
use Tests\TestCase;

/**
 * Ask Claude layer 2 (U1 b): answers from stored results, the table shown
 * beside an answer (Claude names the measures; the app supplies every
 * number), decisions, method requests, the practice run, and the dashboard.
 */
class AskClaudeResultsTest extends TestCase
{
    use BuildsPhase7BCourse, BuildsPracticeSource, RefreshDatabase;

    private Course $course;

    private Firm $firm;

    private Faculty $faculty;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['flexee.ai.enabled' => true, 'services.anthropic.key' => 'test-key']);
        [$this->course, $this->firm] = $this->phase7bCourse('Results course');
        $this->faculty = $this->course->faculty;
    }

    public function test_the_shown_table_carries_stored_figures_with_their_sources_and_claude_may_quote_them(): void
    {
        $q3 = $this->firm->engineResults()->where('quarter_number', 3)->firstOrFail();
        $cash = ResultMeasures::format('cash', ResultMeasures::value('cash', $q3));
        $this->fakeClaude(
            ['name' => 'show_table', 'input' => ['title' => 'Cash and net income', 'measures' => ['cash', 'net_income'], 'quarters' => [2, 3]]],
            "Cash ended Quarter 3 at {$cash}, more than at the end of Quarter 2.",
        );

        $result = app(AskClaude::class)->ask($this->faculty, $this->course, $this->context(), 'How did cash move?');

        $this->assertSame([], $result['dropped'], 'a figure the table returned may be quoted');
        $view = $result['views'][0];
        $this->assertSame('Cash and net income', $view['title']);
        $this->assertSame(['Q2 warm-up', 'Q3 warm-up'], $view['columns']);
        $this->assertSame(['Cash at quarter end', 'Net income'], array_column($view['rows'], 'label'));
        $this->assertSame($cash, $view['rows'][0]['cells'][1]['text']);
        $this->assertSame("{$this->firm->name} · Quarter 3 · Balance sheet · Cash at quarter end", $view['rows'][0]['cells'][1]['source']);

    }

    public function test_the_panel_shows_the_table_with_each_figures_source(): void
    {
        $cash = ResultMeasures::format('cash', ResultMeasures::value('cash', $this->firm->engineResults()->where('quarter_number', 3)->firstOrFail()));
        $this->fakeClaude(['name' => 'show_table', 'input' => ['title' => 'Cash', 'measures' => ['cash'], 'quarters' => [3]]], "Cash was {$cash}.");

        $this->actingAs($this->faculty->user);
        Livewire::test(AskClaudePanel::class, ['course' => $this->course, 'context' => $this->context(), 'suggestions' => []])
            ->call('ask', 'Cash?')
            ->assertSee('data-test="ask-claude-view"', false)
            ->assertSee($cash)
            ->assertSee("{$this->firm->name} · Quarter 3 · Balance sheet · Cash at quarter end");
    }

    public function test_results_default_to_the_last_three_quarters_and_refuse_unknown_firms_and_measures(): void
    {
        $tools = new AskClaudeTools($this->course);

        $all = $tools->run('get_results', ['measures' => ['net_revenue']]);
        $this->assertSame(['Quarter 1 (warm-up)', 'Quarter 2 (warm-up)', 'Quarter 3 (warm-up)'], array_keys($all['results'][$this->firm->name]));
        $this->assertSame([], $tools->views(), 'reading results shows nothing');

        $this->assertStringContainsString($this->firm->name, $tools->run('get_results', ['measures' => ['cash'], 'firms' => ['Nobody']])['error']);
        $this->assertArrayHasKey('error', $tools->run('get_results', ['measures' => ['made_up']]));
    }

    public function test_decisions_and_method_requests_reach_claude_with_students_as_numbers(): void
    {
        $student = $this->firm->memberships()->firstOrFail()->student;
        $q4 = $this->course->quarters()->where('quarter_number', 4)->firstOrFail();
        FirmQuarterDecision::query()->create([
            'firm_id' => $this->firm->id, 'course_id' => $this->course->id, 'course_quarter_id' => $q4->id, 'institution_id' => $this->course->institution_id,
            'resolved_payload' => ['sourcing' => ['primary_supplier' => 'SUP006', 'primary_alloc' => 1, 'secondary_supplier' => 'NONE']],
            'dividend' => 1250000, 'state' => FirmQuarterDecision::LOCKED, 'submitted_by_student_id' => $student->id,
        ]);
        $this->course->forceFill(['accounting_election_enabled' => true])->save();
        FirmAccountingElectionRequest::query()->create([
            'firm_id' => $this->firm->id, 'course_id' => $this->course->id, 'course_quarter_id' => $q4->id, 'student_id' => $student->id,
            'proposed_elections' => ['depreciation_method' => 'double_declining_balance'], 'previous_elections' => ['depreciation_method' => 'straight_line'],
            'status' => 'pending', 'effective_quarter_number' => 4,
        ]);
        $tools = new AskClaudeTools($this->course->fresh());

        $decisions = $tools->run('get_decisions', ['firm' => $this->firm->name, 'quarter' => 4]);
        $this->assertSame('Submitted by Student 1', $decisions['submitted']);
        $this->assertSame('$1,250,000.00', $decisions['dividend_declared']);
        $this->assertStringStartsWith('India', array_values($decisions['decisions'])[0]['Main supplier']);

        $requests = $tools->run('get_method_requests', ['status' => 'waiting'])['requests'];
        $this->assertSame('Student 1', $requests[0]['filed_by']);
        $this->assertSame('No reason given (filed before reasons were required).', $requests[0]['reason']);
        $this->assertSame([['method' => 'Depreciation method', 'from' => 'Straight-line', 'to' => 'Double-declining balance']], $requests[0]['changes']);

        $sent = json_encode([$decisions, $requests]);
        $this->assertStringNotContainsString($student->user->name, $sent);
        $this->assertStringNotContainsString($student->user->email, $sent);
        $this->assertSame(['Student 1' => $student->user->name], $tools->names());
    }

    public function test_the_practice_run_scoreboard_is_there_when_the_instructor_has_one(): void
    {
        config(['flexee.engine_version_for_new_courses' => 2]);
        $source = $this->sourceCourse('Rehearsed');
        $this->assertSame('none', (new AskClaudeTools($source))->run('get_practice_scoreboard', [])['practice_run']);

        $runs = app(PracticeRunService::class);
        $runs->playTo($this->faculty, $runs->create($this->faculty, $source), 5);
        $board = (new AskClaudeTools($source))->run('get_practice_scoreboard', []);

        $this->assertSame(5, $board['played_to_quarter']);
        $this->assertSame(['Quarter 4', 'Quarter 5'], array_keys($board['leader_by_quarter']));
        $this->assertArrayHasKey('Careless team', $board['team_rules']);
        $this->assertArrayHasKey('Quarter 5', $board['results']['Careful team']);
    }

    public function test_on_the_dashboard_the_panel_asks_which_course_and_elsewhere_it_cannot_leave_the_page(): void
    {
        $this->actingAs($this->faculty->user)->get(route('dashboard'))->assertOk()->assertSee('data-test="ask-claude-button"', false);

        $other = $this->phase7bCourse('Second course')[0];
        $options = [$this->course->id => $this->course->name, $other->id => $other->name];
        Livewire::test(AskClaudePanel::class, ['course' => $this->course, 'context' => ['page' => 'dashboard', 'label' => 'Dashboard', 'details' => []], 'suggestions' => [], 'courseOptions' => $options])
            ->assertSee('Ask about')
            ->set('courseId', $other->id)->assertSet('courseId', $other->id)->assertSee('Second course')
            ->set('courseId', 'not-a-course')->assertSet('courseId', $this->course->id);

        Livewire::test(AskClaudePanel::class, ['course' => $this->course, 'context' => $this->context(), 'suggestions' => []])
            ->set('courseId', $other->id)
            ->assertForbidden();
    }

    /** @return array{page: string, label: string, details: array<string, string>} */
    private function context(): array
    {
        return ['page' => 'faculty.courses.quarter-results', 'label' => 'Quarter results', 'details' => []];
    }

    /** @param array{name: string, input: array<string, mixed>} $lookup */
    private function fakeClaude(array $lookup, string $answer): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['content' => [['type' => 'tool_use', 'id' => 'tu_1', 'name' => $lookup['name'], 'input' => $lookup['input']]], 'stop_reason' => 'tool_use', 'usage' => ['input_tokens' => 900, 'output_tokens' => 40]])
            ->push(['content' => [['type' => 'text', 'text' => $answer]], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1100, 'output_tokens' => 90]]),
        ]);
    }
}
