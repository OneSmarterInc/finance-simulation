<?php

namespace Tests\Feature\Projection;

use App\Livewire\Faculty\Courses\QuarterResults;
use App\Models\Faculty;
use App\Services\Ai\AskClaudeTools;
use App\Services\Decisions\FirmDecisionService;
use App\Services\Practice\PracticeRunService;
use App\Services\Projection\CatchUpProjection;
use App\Services\Quarters\QuarterManagementService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Phase7B\BuildsPhase7BCourse;
use Tests\Support\Practice\BuildsPracticeSource;
use Tests\TestCase;

/**
 * U10 (PJ1 to PJ3, decided 19 October 2026): the catch-up projection plays
 * the remaining quarters in memory from each firm's real position.
 */
class CatchUpProjectionTest extends TestCase
{
    use BuildsPhase7BCourse, BuildsPracticeSource, RefreshDatabase;

    private Faculty $faculty;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->faculty = Faculty::query()->firstOrFail();
        config(['flexee.engine_version_for_new_courses' => 2]);
    }

    public function test_a_practice_run_projected_from_quarter_five_matches_what_it_then_plays_to_the_cent(): void
    {
        $runs = app(PracticeRunService::class);
        $practice = $runs->playTo($this->faculty, $runs->create($this->faculty, $this->sourceCourse('Projected')), 5);
        $firms = $practice->firms()->get();
        $behaviours = $firms->mapWithKeys(fn ($f) => [$f->id => strtolower((string) $f->code)])->all();

        $counts = $this->rowCounts();
        $projection = app(CatchUpProjection::class);
        $value = $projection->project($practice, $behaviours, 'value_created');
        $cash = $projection->project($practice, $behaviours, 'cash');
        $this->assertSame($counts, $this->rowCounts(), 'a projection saves nothing');
        $this->assertSame([6, 7, 8, 9, 10, 11], $value['firms'][0]['projected']);

        $runs->playTo($this->faculty, $practice->fresh(), 11);
        $board = $runs->scoreboard($practice->fresh());
        foreach ($value['firms'] as $i => $firm) {
            $team = $behaviours[$firm['id']];
            foreach (range(6, 11) as $q) {
                $this->assertEqualsWithDelta($board[$team][$q]['value'], $firm['values'][$q], 0.005, "{$team} value created, Quarter {$q}");
                $this->assertEqualsWithDelta($board[$team][$q]['cash'], $cash['firms'][$i]['values'][$q], 0.005, "{$team} cash, Quarter {$q}");
            }
        }
    }

    public function test_on_the_earlier_engine_repeat_matches_the_real_close_with_submitted_and_carried_forward_decisions(): void
    {
        config(['flexee.engine_version_for_new_courses' => 1]);
        [$course, $firm, $student, $q4, $q5] = $this->phase7bCourse('Earlier engine');
        $this->assertSame([CatchUpProjection::REPEAT], array_keys(CatchUpProjection::behaviours($course)), 'PJ3: repeat only');
        $quarters = app(QuarterManagementService::class);
        $quarters->open($course->faculty, $q4);
        app(FirmDecisionService::class)->submit($student, $firm->fresh(), $q4->fresh(), $this->completePayload(), 0);

        $projection = app(CatchUpProjection::class);
        $before = $projection->project($course->fresh(), [$firm->id => 'careful'], 'cash');
        $this->assertSame('repeat', $before['firms'][0]['behaviour'], 'a behaviour the course does not offer falls back to repeat');
        $this->assertStringContainsString('earlier engine', (string) $before['note']);
        $this->assertSame([4, 5], $before['firms'][0]['projected']);

        // Quarter 4 closes with the submitted decisions; Quarter 5 carries them forward (N4).
        $quarters->lockAndQueue($q4->fresh(), carryForward: true);
        $quarters->finalize($q4->fresh());
        $quarters->open($course->faculty, $q5->fresh());
        $quarters->lockAndQueue($q5->fresh(), carryForward: true);
        $quarters->finalize($q5->fresh());

        foreach ([4, 5] as $q) {
            $stored = (float) $firm->engineResults()->where('quarter_number', $q)->firstOrFail()->fields['bs_cash'];
            $this->assertEqualsWithDelta($stored, $before['firms'][0]['values'][$q], 0.005, "cash, Quarter {$q}");
        }
        $after = $projection->project($course->fresh(), [], 'cash');
        $this->assertSame([], $after['firms'][0]['projected']);
        $this->assertSame('Every quarter has closed, so there is nothing left to project.', $after['answer']);
    }

    public function test_quarter_results_has_the_catch_up_view_and_ask_claude_can_run_it(): void
    {
        $runs = app(PracticeRunService::class);
        $practice = $runs->playTo($this->faculty, $runs->create($this->faculty, $this->sourceCourse('Viewed')), 5);
        $careless = $practice->firms()->where('code', 'CARELESS')->firstOrFail();
        $this->actingAs($this->faculty->user);

        $page = Livewire::test(QuarterResults::class, ['course' => $practice])
            ->call('showCatchUp')
            ->assertSee('Can anyone catch up?')
            ->assertSee('data-test="catch-up-answer"', false)
            ->assertSee('projected')
            ->assertSee('Play like the Careful team')
            ->assertSet("behaviours.{$careless->id}", 'repeat')
            ->set("behaviours.{$careless->id}", 'careful')
            ->assertSet("behaviours.{$careless->id}", 'careful')
            ->set('projectMeasure', 'consultant_value')
            ->assertSet('projectMeasure', 'value_created');
        $this->assertStringContainsString('if each plays as chosen below.', $page->viewData('projection')['answer']);

        $tools = new AskClaudeTools($practice->fresh());
        $out = $tools->run('project_catch_up', ['measure' => 'cash', 'behaviours' => ['Careless team' => 'careful']]);
        $this->assertSame('Cash at quarter end', $out['measure']);
        $this->assertSame('Play like the Careful team', $out['firms']['Careless team']['plays_from_here']);
        $this->assertArrayHasKey('Quarter 6 (projected)', $out['firms']['Careless team']);
        $this->assertStringStartsWith('Projection · ', $tools->views()[0]['rows'][0]['cells'][1]['source']);
        $this->assertArrayHasKey('error', $tools->run('project_catch_up', ['behaviours' => ['Nobody' => 'careful']]));
    }

    /** @return array<string, int> */
    private function rowCounts(): array
    {
        return collect(['firm_engine_results', 'firm_quarter_decisions', 'firm_accounting_elections', 'firm_quarter_closes', 'course_quarters'])
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    }
}
