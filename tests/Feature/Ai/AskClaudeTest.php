<?php

namespace Tests\Feature\Ai;

use App\Livewire\AskClaudePanel;
use App\Models\AiQuestion;
use App\Models\Course;
use App\Models\Faculty;
use App\Models\Firm;
use App\Services\Ai\AskClaude;
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
        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $request->hasHeader('x-api-key', 'test-key')
                && $body['model'] === 'claude-sonnet-5-5'
                && ($body['system'][0]['cache_control']['type'] ?? null) === 'ephemeral'
                && str_contains($body['system'][1]['text'], 'Quarters')
                && collect($body['tools'])->pluck('name')->take(2)->all() === ['get_course_setup', 'get_course_status'];
        });
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
