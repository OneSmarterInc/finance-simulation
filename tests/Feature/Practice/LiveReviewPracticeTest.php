<?php

namespace Tests\Feature\Practice;

use App\Livewire\Faculty\Courses\Debrief;
use App\Livewire\Faculty\Courses\PracticeRun;
use App\Models\Faculty;
use App\Services\Ai\AskClaudeTools;
use App\Services\Debrief\DebriefBuilder;
use App\Services\Practice\PracticeRunService;
use App\Services\Practice\TeamSettings;
use App\Support\Presentation\StatementPresenter;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Practice\BuildsPracticeSource;
use Tests\TestCase;

/** Live review, 21 October 2026: the practice run and its debrief (R17, R18, R24, R26 to R30). */
class LiveReviewPracticeTest extends TestCase
{
    use BuildsPracticeSource, RefreshDatabase;

    private Faculty $faculty;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->faculty = Faculty::query()->firstOrFail();
        config(['flexee.engine_version_for_new_courses' => 2]);
    }

    /** R17 and R18: a team's rule names only what it decides in the course, in plain phrases. */
    public function test_rules_cover_only_the_areas_the_course_opens(): void
    {
        $open = ['forecasting', 'sourcing', 'methods'];
        $careless = TeamSettings::STANDARD['careless'];

        $this->assertSame(
            "Plans from last quarter's orders, plus 25%. Buys from Vietnam. Uses double-declining balance depreciation from Quarter 6. Pays the largest dividend allowed.",
            TeamSettings::describe('careless', $careless, $open),
        );
        $this->assertSame(TeamSettings::STANDARD_RULES['careless'], TeamSettings::describe('careless', $careless), 'every area open: the standard words');

        // A change in an area students don't decide does nothing, so the team isn't custom.
        $priced = [...$careless, 'price_pct' => 100];
        $this->assertSame([], TeamSettings::effectiveDifferences('careless', $priced, $open));
        $this->assertSame('', TeamSettings::changeSummary('careless', $priced, $open));
        $this->assertSame('prices at 100%', TeamSettings::changeSummary('careless', $priced));

        $this->assertSame("Pricing and marketing aren't open to students in this course, so the simulation sets them.", TeamSettings::closedNote(['price_pct', 'marketing_pct'], $open));
        $this->assertNull(TeamSettings::closedNote(['forecast_basis', 'forecast_adjust'], $open));
        $this->assertTrue(TeamSettings::takesEffect('forecast_adjust', ['production']), 'the plan drives production');

        // R18: "with no inspection", never "with None inspection".
        $custom = TeamSettings::describe('careless', [...$careless, 'forecast_adjust' => 10]);
        $this->assertStringContainsString('Buys from Vietnam with no inspection and air freight.', $custom);
        $this->assertStringNotContainsString('None inspection', $custom);
    }

    public function test_the_page_and_ask_claude_follow_the_course_areas(): void
    {
        self::$practiceOpens = ['forecasting' => 4, 'sourcing' => 4];
        $source = $this->sourceCourse('Two areas', methodChanges: true);
        $runs = app(PracticeRunService::class);
        $this->assertEqualsCanonicalizing(['forecasting', 'sourcing', 'methods'], $runs->openAreas($source));

        $runs->saveSettings($this->faculty, $source, ['careless' => [...TeamSettings::STANDARD['careless'], 'price_pct' => 100]]);
        $this->assertSame([], $runs->customTeams($source->fresh()), 'a price change does nothing here');
        $this->assertStringNotContainsString('Cuts prices', $runs->rules($source->fresh())['careless']);

        $practice = $runs->playTo($this->faculty, $runs->create($this->faculty, $source->fresh()), 4);
        $this->actingAs($this->faculty->user);
        Livewire::test(PracticeRun::class, ['course' => $source->fresh()])
            ->assertDontSee('Cuts prices 10%')
            ->assertDontSee('data-test="custom-badge"', false)
            ->call('editTeams')
            ->assertSee('Pricing and marketing aren&#039;t open to students in this course, so the simulation sets them.', false)
            ->assertDontSee('Price (% of base price)');

        $board = (new AskClaudeTools($source->fresh()))->run('get_practice_scoreboard', []);
        $this->assertEqualsCanonicalizing(['planning', 'the supplier', 'accounting methods'], $board['areas_open_to_students']);
        $this->assertStringNotContainsString('marketing', $board['team_rules']['Careless team']);
        $this->assertSame(4, $runs->playedTo($practice));
        self::$practiceOpens = ['forecasting' => 4, 'sourcing' => 4, 'notes_elections' => 4, 'pricing' => 5, 'marketing' => 6, 'production' => 7, 'quality' => 8, 'logistics' => 8];
    }

    /** R26, R28, R30: after the last quarter, the methods slide and the teams' own colours. */
    public function test_a_finished_run_says_so_and_its_debrief_shows_the_teams_method_changes(): void
    {
        $source = $this->sourceCourse('Methods rehearsed', methodChanges: true);
        $runs = app(PracticeRunService::class);
        $run = $runs->playTo($this->faculty, $runs->create($this->faculty, $source), 11);

        $this->actingAs($this->faculty->user);
        Livewire::test(PracticeRun::class, ['course' => $source->fresh()])
            ->assertSee('The run is over. View as a team to see its final pages.')
            ->assertDontSee('The next quarter is open');

        $built = app(DebriefBuilder::class)->build($run->fresh());
        $rows = collect($built['slides']['methods']['data']['rows'] ?? []);
        $this->assertTrue($rows->contains(fn ($r) => $r['firm'] === 'Careless team' && $r['quarter'] === 6 && str_contains($r['change'], 'Double-declining')), 'Careless switches in Quarter 6');
        $this->assertSame(['careful', 'average', 'careless'], collect($built['firms'])->sortBy(fn ($f) => array_search($f['team'], ['careful', 'average', 'careless'], true))->pluck('team')->values()->all());

        $html = Livewire::test(Debrief::class, ['course' => $run->fresh()])->html();
        $this->assertMatchesRegularExpression('/<polyline[^>]+stroke="var\(--series-3\)"/', $html, 'Careful is green');
        // Round axis values: whole millions or tenths at most, never $224.5M-style odd steps between ticks.
        preg_match_all('/text-anchor="end" font-size="12" fill="var\(--viz-ink-2\)">\(?\$([\d.]+)M/', $html, $m);
        $ticks = array_map('floatval', $m[1]);
        $this->assertGreaterThanOrEqual(3, count($ticks));
        $steps = array_unique(array_map(fn ($a, $b) => round($b - $a, 3), array_slice($ticks, 0, -1), array_slice($ticks, 1)));
        $this->assertCount(1, $steps, 'evenly spaced ticks');
        $this->assertContains(abs(reset($steps)) / 10 ** floor(log10(abs(reset($steps)))), [1.0, 2.0, 2.5, 5.0]);
    }

    /** R24 and R27: US spellings on the statements; money on slides in millions, negatives in brackets. */
    public function test_statement_spellings_and_slide_money(): void
    {
        $labels = collect(['income_statement', 'cash_flow_statement', 'balance_sheet'])
            ->flatMap(fn ($s) => collect(StatementPresenter::rows($s, []))->pluck('label'))->implode(' | ');
        foreach (['Direct labor', 'Right-of-use asset amortization', 'Capital spending: distribution centers'] as $label) {
            $this->assertStringContainsString($label, $labels);
        }
        $this->assertDoesNotMatchRegularExpression('/labour|centres|amortisation/', $labels);

        $this->assertSame('$217.0M', DebriefBuilder::money(217038711.88));
        $this->assertSame('($24.9M)', DebriefBuilder::money(-24923444.00));
        $this->assertSame('$62.4K', DebriefBuilder::money(62368.42));
        $this->assertSame(['$3.21M', '$3.19M'], DebriefBuilder::moneyPair(3214000, 3187000), 'two amounts never read the same when they differ');
    }
}
