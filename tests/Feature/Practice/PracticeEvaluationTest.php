<?php

namespace Tests\Feature\Practice;

use App\Livewire\Faculty\Courses\PracticeRun;
use App\Models\CfPlusAuctionEconomicsSnapshot;
use App\Models\CfPlusAuctionResult;
use App\Models\CfPlusOffer;
use App\Models\Faculty;
use App\Services\CfPlus\CfPlusOfferService;
use App\Services\Decisions\FirmDecisionService;
use App\Services\Practice\PracticeRunService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Practice\BuildsPracticeSource;
use Tests\TestCase;

/**
 * U9 layer 4: on a Flexee Financial Accounting+ course, the practice run
 * plays the evaluation stage too. The teams bid for each other by rule (FF4),
 * capped at what they can fund, and the stage settles after the last quarter.
 */
class PracticeEvaluationTest extends TestCase
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

    public function test_the_teams_bid_by_rule_and_the_stage_settles_after_the_last_quarter(): void
    {
        $runs = app(PracticeRunService::class);
        $practice = $runs->create($this->faculty, $this->sourceCourse('Plus rehearsal', plus: true));
        $this->assertSame(6, $runs->evaluation($practice)['opens_with']);

        // Played to Quarter 4, the stage hasn't opened.
        $practice = $runs->playTo($this->faculty, $practice, 4);
        $this->assertFalse($runs->evaluation($practice)['open']);

        // Played to Quarter 5, Quarter 6 is open, so the stage is too, but nobody has bid yet.
        $practice = $runs->playTo($this->faculty, $practice, 5);
        $this->assertTrue($runs->evaluation($practice)['open']);
        $this->assertNotNull(CfPlusAuctionEconomicsSnapshot::query()->where('course_id', $practice->id)->first());
        $this->assertSame(0, CfPlusOffer::query()->where('course_id', $practice->id)->count());

        // Playing Quarter 6, the teams bid.
        $practice = $runs->playTo($this->faculty, $practice, 6);
        $economics = CfPlusAuctionEconomicsSnapshot::query()->where('course_id', $practice->id)->firstOrFail()->economics()->get()->keyBy('firm_id');
        $firms = $practice->firms()->get()->keyBy(fn ($f) => strtolower($f->code));
        $offers = CfPlusOffer::query()->where('course_id', $practice->id)->get();
        $this->assertCount(6, $offers, 'each of three teams bids for each of its two rivals');
        foreach ($offers as $offer) {
            $bidder = strtolower($practice->firms()->whereKey($offer->bidder_firm_id)->value('code'));
            $e = $economics[$offer->target_firm_id];
            $wanted = match ($bidder) {
                'careful' => 0.95 * (float) $e->displayed_true_stake_worth,
                'average' => (float) $e->consultant_stake_value,
                'careless' => 1.25 * (float) $e->consultant_stake_value,
            };
            $this->assertEqualsWithDelta(min($wanted, (float) $offer->fundable_cap), (float) $offer->offered_amount, 0.011, "{$bidder}'s offer follows its rule");
        }
        $this->assertFalse($runs->evaluation($practice)['settled']);

        // Playing to the end settles it, as the instructor would.
        $practice = $runs->playTo($this->faculty, $practice, 99);
        $this->assertSame(11, $runs->playedTo($practice));
        $this->assertSame(3, CfPlusAuctionResult::query()->where('course_id', $practice->id)->count());

        $evaluation = $runs->evaluation($practice);
        $this->assertTrue($evaluation['settled']);
        $this->assertSame(['careful', 'average', 'careless'], array_column($evaluation['firms'], 'team'));
        $this->assertSame(['careful', 'average', 'careless'], array_column($evaluation['results'], 'target'));
        foreach ($evaluation['results'] as $result) {
            if ($result['price'] !== null) {
                $this->assertEqualsWithDelta($result['price'] - $result['true_stake'], $result['difference'], 0.001);
            }
        }
        // Offers can't be changed once settled.
        $this->expectException(\DomainException::class);
        app(CfPlusOfferService::class)->submit($practice, $firms['careful'], $firms['average'], 1.0);
    }

    public function test_the_practice_run_page_shows_the_stage_from_closed_to_the_reveal(): void
    {
        $source = $this->sourceCourse('Plus page', plus: true);
        $runs = app(PracticeRunService::class);
        $practice = $runs->create($this->faculty, $source);
        $this->actingAs($this->faculty->user);

        Livewire::test(PracticeRun::class, ['course' => $source])
            ->assertSee('Opens with Quarter 6')
            ->assertSee('Play to Quarter 5 to open the stage')
            ->assertDontSee('Offers for a 51% stake');

        $runs->playTo($this->faculty, $practice, 6);
        Livewire::test(PracticeRun::class, ['course' => $source])
            ->assertSee('Open: offers placed')
            ->assertSee('What each firm is worth')
            ->assertSee('Offers for a 51% stake')
            ->assertSee('offers 95% of what the stake is truly worth')
            ->assertSee('The offers stay sealed until the last quarter closes.')
            ->assertDontSee('data-test="practice-reveal"', false);

        $runs->playTo($this->faculty, $practice->fresh(), 99);
        $component = Livewire::test(PracticeRun::class, ['course' => $source])
            ->assertSee('Settled after Quarter 11')
            ->assertSee('data-test="practice-reveal"', false)
            ->assertSee('Outbid');
        foreach ($runs->evaluation($practice->fresh())['results'] as $result) {
            if ($result['winner_name'] !== null) {
                $component->assertSee("won {$result['target_name']} for", false);
            }
        }

        // Viewing as a team opens its own evaluation-stage page, as its students would see it.
        $careful = $practice->firms()->get()->first(fn ($f) => strtolower($f->code) === 'careful');
        $this->post(route('faculty.courses.practice.view-as', [$source, 'careful']));
        $this->get(route('student.cf-plus.evaluation', $careful))->assertOk();
    }

    public function test_a_plus_course_closes_accounting_methods_after_quarter_four_so_later_quarters_can_be_submitted(): void
    {
        // The setup matrix keeps an area open once it opens, so the methods area is switched on in every quarter from 4.
        $course = $this->sourceCourse('Plus methods', plus: true);
        $decisions = app(FirmDecisionService::class);
        $q4 = $course->quarters()->where('quarter_number', 4)->firstOrFail();
        $q5 = $course->quarters()->where('quarter_number', 5)->firstOrFail();

        $this->assertArrayHasKey('notes_elections', $decisions->enabledCategories($q4), 'methods are chosen in Quarter 4');
        $this->assertArrayNotHasKey('notes_elections', $decisions->enabledCategories($q5), 'and locked after it');
        $this->assertArrayHasKey('notes_elections', $decisions->enabledCategories($this->sourceCourse('Standard methods')->quarters()->where('quarter_number', 5)->firstOrFail()), 'standard courses are unchanged');
    }

    public function test_a_course_without_the_evaluation_stage_has_no_evaluation_section(): void
    {
        $runs = app(PracticeRunService::class);
        $source = $this->sourceCourse('No plus');
        $this->assertNull($runs->evaluation($runs->create($this->faculty, $source)));
        $this->actingAs($this->faculty->user);
        Livewire::test(PracticeRun::class, ['course' => $source])->assertDontSee('data-test="practice-evaluation"', false);
    }
}
