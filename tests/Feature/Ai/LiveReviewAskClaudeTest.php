<?php

namespace Tests\Feature\Ai;

use App\Livewire\Faculty\Courses\QuarterResults;
use App\Models\Faculty;
use App\Providers\AppServiceProvider;
use App\Services\Ai\AskClaude;
use App\Services\Ai\AskClaudeTools;
use App\Services\Ai\FigureCheck;
use App\Services\Practice\PracticeRunService;
use App\Support\Presentation\AnswerFormat;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Phase7B\BuildsPhase7BCourse;
use Tests\Support\Practice\BuildsPracticeSource;
use Tests\TestCase;

/** Live review, 21 October 2026: Ask Claude, results and sign-in (R15, R16, R19 to R23). */
class LiveReviewAskClaudeTest extends TestCase
{
    use BuildsPhase7BCourse, BuildsPracticeSource, RefreshDatabase;

    private Faculty $faculty;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->faculty = Faculty::query()->firstOrFail();
    }

    /** R16: a sentence ends after a closing quotation mark, so a quote and a fact are judged apart. */
    public function test_the_figure_check_ends_a_sentence_after_a_closing_quote(): void
    {
        $check = FigureCheck::apply('Alpha wrote "cash rose 40%." Revenue grew 40% in Quarter 4.', ['Quarter 4'], ['cash rose 40%']);

        $this->assertSame('Alpha wrote "cash rose 40%."', $check['answer']);
        $this->assertSame([['sentence' => 'Revenue grew 40% in Quarter 4.', 'figures' => ['40%']]], $check['dropped']);
    }

    /** R19: the answer opens once, and lines about Claude's own steps are left out. */
    public function test_the_answer_opens_once_without_step_lines(): void
    {
        $final = "Beta is ahead on value created. In Quarter 4, Beta's value created was \$1.00 against Alpha's \$0.90, and the gap stayed small through Quarter 6. Neither firm paid a dividend, so value created is each firm's equity.";
        $this->assertSame($final, AskClaude::compose(["Beta is ahead on value created, though only slightly. I'll show the table.", $final], $final));

        $parts = [
            "The practice run's firm names aren't the same as the live course's, so I'll try the team names from the scoreboard.",
            "The table came back empty, so the figures below come from the practice scoreboard instead.\n\nCareless paid the largest dividend allowed.",
        ];
        $this->assertSame('Careless paid the largest dividend allowed.', AskClaude::compose($parts, $parts[1]));

        // A short last round ("The table above shows ...") keeps the explanation written beside the table.
        $this->assertSame("Alpha's cash fell.\n\nThe table above shows it.", AskClaude::compose(["Alpha's cash fell.", 'The table above shows it.'], 'The table above shows it.'));
    }

    /** R20: on the earlier engine, unit rows with nothing stored are left out of the table. */
    public function test_rows_with_nothing_stored_are_left_out(): void
    {
        [$course] = $this->phase7bCourse('Earlier engine');
        $this->assertSame(1, $course->engineVersion());
        $tools = new AskClaudeTools($course);
        $tools->run('show_table', ['title' => 'Cash and orders', 'measures' => ['cash', 'orders_not_filled']]);

        $labels = collect($tools->views()[0]['rows'])->pluck('label')->implode(' | ');
        $this->assertStringContainsString('Cash', $labels);
        $this->assertStringNotContainsString('Orders not filled', $labels);

        $tools = new AskClaudeTools($course);
        $out = $tools->run('show_table', ['title' => 'Orders', 'measures' => ['orders_not_filled']]);
        $this->assertSame([], $tools->views());
        $this->assertStringContainsString('no table is shown', $out['shown']);
    }

    /** R21: the table lookup knows the practice run's teams. */
    public function test_the_table_lookup_takes_the_practice_teams(): void
    {
        config(['flexee.engine_version_for_new_courses' => 2]);
        $source = $this->sourceCourse('Rehearsed');
        $runs = app(PracticeRunService::class);
        $runs->playTo($this->faculty, $runs->create($this->faculty, $source), 5);

        $tools = new AskClaudeTools($source->fresh());
        $out = $tools->run('show_table', ['title' => 'Cash', 'measures' => ['cash'], 'firms' => ['Careless team', 'Careful']]);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertEqualsCanonicalizing(['Careless team', 'Careful team'], array_keys($out['results']));
        $this->assertCount(2, $tools->views()[0]['rows']);

        $error = (new AskClaudeTools($source->fresh()))->run('get_results', ['measures' => ['cash'], 'firms' => ['Nobody']]);
        $this->assertStringContainsString("The practice run's teams are: ", $error['error']);
    }

    /** R22: bold and short lists render; nothing else becomes markup. */
    public function test_answers_render_bold_and_lists_safely(): void
    {
        $html = AnswerFormat::render(e("Two things lowered cash.\n\n- **Overproduction:** stock piled up.\n- **Dividend:** the largest allowed.\n\n<script>alert(1)</script> **bold**"));

        $this->assertStringContainsString('<p>Two things lowered cash.</p>', $html);
        $this->assertStringContainsString('<ul class="list-disc space-y-1 ps-5"><li><strong>Overproduction:</strong> stock piled up.</li><li><strong>Dividend:</strong> the largest allowed.</li></ul>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('**', $html);
    }

    /** R23: no unit measures on the earlier engine, and the value-created note only with that measure. */
    public function test_the_compare_grid_lists_only_recorded_measures(): void
    {
        [$course] = $this->phase7bCourse('Earlier engine grid');
        $this->actingAs($course->faculty->user);
        Livewire::withQueryParams(['view' => 'compare', 'measure' => 'cash'])->test(QuarterResults::class, ['course' => $course])
            ->assertDontSee('Orders not filled')
            ->assertSee("which doesn't record units", false)
            ->assertDontSee('Value created is equity plus dividends');
        Livewire::withQueryParams(['view' => 'compare', 'measure' => 'orders_not_filled'])->test(QuarterResults::class, ['course' => $course])
            ->assertSet('measure', 'value_created')
            ->assertSee('Value created is equity plus dividends');
    }

    /** R15: a page left open past the session explains the sign-in page. */
    public function test_the_sign_in_page_says_why_after_an_idle_sign_out(): void
    {
        $this->post(route('login.store'), ['email' => 'faculty@example.com', 'password' => 'password'])->assertCookie(AppServiceProvider::SIGNED_IN_COOKIE);
        $this->post(route('logout'))->assertCookieExpired(AppServiceProvider::SIGNED_IN_COOKIE);

        $this->withCookie(AppServiceProvider::SIGNED_IN_COOKIE, '1')->withSession(['url.intended' => url('/dashboard')])
            ->get(route('login'))->assertSee('You were signed out after two hours without activity. Sign in to carry on.');
        $this->defaultCookies = [];
        $this->withSession(['url.intended' => url('/dashboard')])->get(route('login'))->assertDontSee('You were signed out');
    }
}
