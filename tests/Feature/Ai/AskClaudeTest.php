<?php

namespace Tests\Feature\Ai;

use App\Livewire\AskClaudePanel;
use App\Models\AiQuestion;
use App\Models\Course;
use App\Models\Faculty;
use App\Models\Firm;
use App\Services\Ai\AskClaude;
use App\Services\Ai\AskClaudeTools;
use App\Services\Ai\FigureCheck;
use App\Services\Quarters\QuarterManagementService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Phase7B\BuildsPhase7BCourse;
use Tests\TestCase;

/**
 * Ask Claude on faculty pages, layer 1 (U1, design note AC1 to AC8). Every
 * test runs against recorded Anthropic responses (Http::fake), so the suite
 * never calls the real API and costs nothing.
 */
class AskClaudeTest extends TestCase
{
    use BuildsPhase7BCourse, RefreshDatabase;

    private Course $course;

    private Firm $firm;

    private Faculty $faculty;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['flexee.ai.enabled' => true, 'services.anthropic.key' => 'test-key']);
        [$this->course, $this->firm, , $q4] = $this->phase7bCourse('Ask Claude course');
        $this->faculty = $this->course->faculty;
        app(QuarterManagementService::class)->open($this->faculty, $q4);
    }

    public function test_an_answer_uses_the_lookups_and_drops_a_sentence_whose_figure_nobody_returned(): void
    {
        $this->fakeClaude("Quarter 4 is open, and {$this->firm->name} has nothing saved yet. Student 1 is its only member.\n\nThat left 94,659 units unsold.");

        $result = app(AskClaude::class)->ask($this->faculty, $this->course, $this->context(), 'Who still has to submit?');

        $this->assertSame("Quarter 4 is open, and {$this->firm->name} has nothing saved yet. Student 1 is its only member.", $result['answer']);
        $this->assertSame([['sentence' => 'That left 94,659 units unsold.', 'figures' => ['94,659']]], $result['dropped']);
        $this->assertSame(['get_course_status'], array_column($result['lookups'], 'name'));

        $record = AiQuestion::query()->sole();
        $this->assertSame(AiQuestion::ANSWERED, $record->status);
        $this->assertSame('faculty.courses.quarters', $record->page);
        $this->assertSame(2400, $record->input_tokens);
        $this->assertSame(180, $record->output_tokens);
        $this->assertEqualsWithDelta(2400 / 1e6 * 2 + 180 / 1e6 * 10, $record->cost_usd, 0.0001);
        $this->assertNotNull($record->dropped);

        // Two calls: the lookup, then the answer. The model is the configured one; the guide is cached.
        Http::assertSentCount(2);
        // Live, 19 October 2026: a lookup with no arguments must go back with input {}, not [], or the API refuses it.
        Http::assertSent(fn (Request $request) => str_contains($request->body(), '"name":"get_course_status","input":{}'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->body(), '"input":[]'));
        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $request->hasHeader('x-api-key', 'test-key')
                && $body['model'] === 'claude-sonnet-5-5'
                && ($body['system'][0]['cache_control']['type'] ?? null) === 'ephemeral'
                && str_contains($body['system'][1]['text'], 'Quarters')
                && collect($body['tools'])->pluck('name')->take(2)->all() === ['get_course_setup', 'get_course_status'];
        });
    }

    public function test_a_reply_that_only_announces_its_lookups_is_sent_back_once(): void
    {
        // Live, 19 October 2026: "I'll look at Alpha's receivables, revenue, and decisions around Quarter 4 ..." and nothing else.
        $announce = ['content' => [['type' => 'text', 'text' => "I'll look at Alpha's receivables, revenue, and decisions around Quarter 4, along with the course setup."]], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 900, 'output_tokens' => 30]];
        $lookup = ['content' => [['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'get_course_status', 'input' => new \stdClass]], 'stop_reason' => 'tool_use', 'usage' => ['input_tokens' => 1000, 'output_tokens' => 40]];
        $answer = ['content' => [['type' => 'text', 'text' => 'Quarter 4 is open.']], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1100, 'output_tokens' => 20]];
        $paused = ['content' => [['type' => 'text', 'text' => 'Both firms are still deciding.']], 'stop_reason' => 'pause_turn', 'usage' => ['input_tokens' => 100, 'output_tokens' => 5]];
        $endWithLookup = ['stop_reason' => 'end_turn'] + $lookup;
        Http::fake(['*' => Http::sequence()->push($announce)->push($lookup)->push($answer)->push($paused)->push($endWithLookup)->push($answer)]);

        $result = app(AskClaude::class)->ask($this->faculty, $this->course, $this->context(), "Why didn't Alpha's receivables change in Quarter 4?");

        $this->assertSame('Quarter 4 is open.', $result['answer']);
        $this->assertSame(['get_course_status'], array_column($result['lookups'], 'name'));
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $r) => str_contains(json_encode($r->data()['messages']), 'Go ahead: use the lookups now'));

        // A lookup that comes with any stop reason is still run, and a paused turn carries on.
        $again = app(AskClaude::class)->ask($this->faculty, $this->course, $this->context(), 'Who still has to submit?');
        $this->assertSame(["Both firms are still deciding.\n\nQuarter 4 is open.", ['get_course_status']], [$again['answer'], array_column($again['lookups'], 'name')], 'the paused turn\'s text is part of the answer');

        $this->assertFalse(AskClaude::announcesLookups('Quarter 4 is open, and nobody has submitted yet.'));
    }

    public function test_the_answer_keeps_what_claude_wrote_beside_a_lookup(): void
    {
        // Live, 19 October 2026: the explanation came beside the show_table call, and the last turn said only "The table above ...".
        $withLookup = ['content' => [
            ['type' => 'text', 'text' => 'Nobody has submitted Quarter 4 yet, so the course is waiting on both firms.'],
            ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'get_course_status', 'input' => new \stdClass],
        ], 'stop_reason' => 'tool_use', 'usage' => ['input_tokens' => 1000, 'output_tokens' => 60]];
        $last = ['content' => [['type' => 'text', 'text' => 'The table above shows where each firm stands.']], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1100, 'output_tokens' => 20]];
        Http::fake(['*' => Http::sequence()->push($withLookup)->push($last)]);

        $result = app(AskClaude::class)->ask($this->faculty, $this->course, $this->context(), 'Who still has to submit?');

        $this->assertSame("Nobody has submitted Quarter 4 yet, so the course is waiting on both firms.\n\nThe table above shows where each firm stands.", $result['answer']);
    }

    public function test_results_say_when_decisions_were_carried_forward_and_how_this_engine_sets_sales(): void
    {
        // Live, 19 October 2026: Claude took Course 02's carried-forward Quarter 4 (same sales, same receivables) for a fault.
        $quarters = app(QuarterManagementService::class);
        $q4 = $this->course->quarters()->where('quarter_number', 4)->firstOrFail();
        $quarters->lockAndQueue($q4->fresh(), carryForward: true);
        $quarters->finalize($q4->fresh());

        $out = (new AskClaudeTools($this->course->fresh()))->run('get_results', ['measures' => ['accounts_receivable', 'units_sold'], 'quarters' => [3, 4]]);

        $q4row = $out['results'][$this->firm->name]['Quarter 4'];
        $this->assertSame("carried forward: the team did not submit, so Quarter 3's decisions were used again", $q4row['Decisions']);
        $this->assertArrayNotHasKey('Decisions', $out['results'][$this->firm->name]['Quarter 3 (warm-up)']);
        $this->assertStringContainsString('the same decisions give the same sales, purchases, receivables and payables', $out['how_sales_are_set']);
        $this->assertStringContainsString('not a fault', $out['how_sales_are_set']);
        $this->assertSame('not recorded', $q4row['Units sold'], 'a figure this engine does not keep says so, rather than a dash');
    }

    public function test_earlier_answers_go_back_as_checked_and_a_first_question_gets_no_such_line(): void
    {
        // Live, 19 October 2026: with no lookups in view, Claude told the instructor to disregard its own correct earlier answer.
        $answer = fn (string $text) => ['content' => [['type' => 'text', 'text' => $text]], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 100, 'output_tokens' => 10]];
        Http::fake(['*' => Http::sequence()->push($answer('Quarter 4 is open.'))->push($answer('Still open.'))]);

        app(AskClaude::class)->ask($this->faculty, $this->course, $this->context(), 'Who still has to submit?');
        app(AskClaude::class)->ask($this->faculty, $this->course, $this->context(), 'And now?', [['question' => 'Who still has to submit?', 'answer' => 'Quarter 4 is open.']]);

        [$first, $second] = array_map(fn ($pair) => $pair[0]->data(), Http::recorded()->all());
        $this->assertStringNotContainsString('Your earlier answers', $first['system'][1]['text']);
        $this->assertStringContainsString('every figure in them was checked', $second['system'][1]['text']);
        $this->assertSame(['role' => 'assistant', 'content' => 'Quarter 4 is open.'], $second['messages'][1]);
    }

    public function test_students_reach_claude_only_as_numbers_and_the_panel_puts_names_back(): void
    {
        $this->fakeClaude('Student 1 is the only member and has nothing saved yet.');
        $student = $this->firm->memberships()->firstOrFail()->student->user;

        $this->actingAs($this->faculty->user);
        Livewire::test(AskClaudePanel::class, ['course' => $this->course, 'context' => $this->context(), 'suggestions' => []])
            ->set('question', 'Who is in the firm?')
            ->call('ask')
            ->assertHasNoErrors()
            ->assertSee($student->name)
            ->assertDontSee('Student 1 is the only member')
            ->assertSee('Show what Claude saw')
            ->set('showSeen', true)
            ->assertSee('Student 1 is the only member');

        Http::assertSent(function (Request $request) use ($student): bool {
            $sent = json_encode($request->data());

            return ! str_contains($sent, $student->email) && ! str_contains($sent, $student->name);
        });
    }

    public function test_the_course_switch_the_allowance_and_the_site_ceiling_stop_questions_before_anything_is_sent(): void
    {
        Http::fake();
        $ask = fn () => app(AskClaude::class)->ask($this->faculty, $this->course->fresh(), $this->context(), 'Anything?');

        $this->course->forceFill(['ai_helper_enabled' => false])->save();
        $this->assertRefused($ask, 'off for this course');

        $this->course->forceFill(['ai_helper_enabled' => true, 'ai_questions_per_month' => 1])->save();
        AiQuestion::query()->create(['course_id' => $this->course->id, 'faculty_id' => $this->faculty->id, 'page' => 'x', 'context' => [], 'question' => 'q', 'lookups' => [], 'status' => AiQuestion::ANSWERED]);
        $this->assertSame(0, app(AskClaude::class)->questionsLeft($this->course->fresh()));
        $this->assertRefused($ask, 'used all its questions');

        $this->course->forceFill(['ai_questions_per_month' => null])->save();
        config(['flexee.ai.monthly_spend_ceiling_usd' => 1.0]);
        AiQuestion::query()->update(['cost_usd' => 1.5]);
        $this->assertRefused($ask, 'limit for the whole site');

        Http::assertNothingSent();
    }

    public function test_only_faculty_who_teach_the_course_get_the_button_and_only_when_the_site_is_ready(): void
    {
        $quarters = route('faculty.courses.quarters', $this->course);

        $this->actingAs($this->faculty->user)->get($quarters)->assertOk()->assertSee('data-test="ask-claude-button"', false);

        config(['services.anthropic.key' => null]);
        $this->get($quarters)->assertOk()->assertDontSee('data-test="ask-claude-button"', false);
        config(['services.anthropic.key' => 'test-key', 'flexee.ai.enabled' => false]);
        $this->get($quarters)->assertOk()->assertDontSee('data-test="ask-claude-button"', false);
        config(['flexee.ai.enabled' => true]);

        $other = Faculty::factory()->create(['institution_id' => $this->faculty->institution_id]);
        $this->expectException(HttpException::class);
        app(AskClaude::class)->ask($other, $this->course, $this->context(), 'Who still has to submit?');
    }

    public function test_students_never_see_the_button(): void
    {
        $student = $this->firm->memberships()->firstOrFail()->student->user;
        $this->actingAs($student)->get(route('firms.dashboard', $this->firm))->assertOk()->assertDontSee('data-test="ask-claude-button"', false);
    }

    public function test_when_claude_cant_be_reached_the_question_is_logged_and_nothing_is_counted(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'overloaded']], 529)]);

        $this->assertRefused(fn () => app(AskClaude::class)->ask($this->faculty, $this->course, $this->context(), 'Anything?'), 'couldn\'t be reached');
        $this->assertSame(AiQuestion::FAILED, AiQuestion::query()->sole()->status);
        $this->assertSame(100, app(AskClaude::class)->questionsLeft($this->course));
    }

    public function test_the_log_is_kept_for_ninety_days(): void
    {
        $old = AiQuestion::query()->create(['course_id' => $this->course->id, 'faculty_id' => $this->faculty->id, 'page' => 'x', 'context' => [], 'question' => 'old', 'lookups' => [], 'status' => AiQuestion::ANSWERED]);
        $old->forceFill(['created_at' => now()->subDays(91)])->save();
        AiQuestion::query()->create(['course_id' => $this->course->id, 'faculty_id' => $this->faculty->id, 'page' => 'x', 'context' => [], 'question' => 'new', 'lookups' => [], 'status' => AiQuestion::ANSWERED]);

        $this->artisan('model:prune', ['--model' => [AiQuestion::class]])->assertSuccessful();

        $this->assertSame(['new'], AiQuestion::query()->pluck('question')->all());
    }

    public function test_the_figure_check_compares_figures_as_numbers(): void
    {
        $sources = ['{"cash":21613457.97,"margin":"18.6%","due":"4 Oct 2026, 11:59 pm UTC"}'];
        $check = FigureCheck::apply("Cash was \$21,613,457.97 and the margin 18.6%. It's due on 4 Oct 2026. Revenue was \$48.5M.", $sources);

        $this->assertSame("Cash was \$21,613,457.97 and the margin 18.6%. It's due on 4 Oct 2026.", $check['answer']);
        $this->assertSame(['$48.5'], $check['dropped'][0]['figures']);
        $this->assertSame(5, $check['checked']);
        $this->assertSame('', FigureCheck::apply('There were 7 firms.', $sources)['answer']);
    }

    public function test_the_connection_check_reports_what_it_finds(): void
    {
        $text = Http::response(['content' => [['type' => 'thinking', 'thinking' => '', 'signature' => 's'], ['type' => 'text', 'text' => 'ready']], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 5, 'output_tokens' => 1]]);
        $tool = Http::response(['content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'write_check', 'input' => ['word' => 'ready']]], 'stop_reason' => 'tool_use', 'usage' => ['input_tokens' => 5, 'output_tokens' => 1]]);
        Http::fakeSequence()->pushResponse($text)->pushResponse($tool)->pushResponse($text)
            // The live fault of 19 October: Claude Sonnet 5.5 refused a forced tool_choice.
            ->push(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'tool_choice: type "tool" and "any" are not supported for this model.']], 400);
        $this->artisan('flexee:ai-check')
            ->expectsOutputToContain('Anthropic answered with claude-sonnet-5-5: ready')
            ->expectsOutputToContain('Tool use works (between_tools thinking): {"word":"ready"}')
            ->assertSuccessful();
        Http::assertSent(fn ($r) => ($r->data()['tool_choice'] ?? null) === ['type' => 'auto'] && $r->data()['thinking'] === ['type' => 'between_tools']);
        $this->artisan('flexee:ai-check')->expectsOutputToContain('A tool-use request failed')->assertFailed();

        config(['services.anthropic.key' => null]);
        $this->artisan('flexee:ai-check')->expectsOutputToContain('ANTHROPIC_API_KEY is not set')->assertFailed();
    }

    /** @return array{page: string, label: string, details: array<string, string>} */
    private function context(): array
    {
        return ['page' => 'faculty.courses.quarters', 'label' => 'Quarters', 'details' => []];
    }

    private function fakeClaude(string $answer): void
    {
        Http::fake(['*' => Http::sequence()
            ->push([
                'content' => [['type' => 'text', 'text' => 'Let me look.'], ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'get_course_status', 'input' => new \stdClass]],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 1000, 'output_tokens' => 60, 'cache_creation_input_tokens' => 200],
            ])
            ->push([
                'content' => [['type' => 'text', 'text' => $answer]],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 1200, 'output_tokens' => 120],
            ]),
        ]);
    }

    private function assertRefused(callable $ask, string $words): void
    {
        try {
            $ask();
            $this->fail('the question was not refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($words, $e->errors()['question'][0]);
        }
    }
}
