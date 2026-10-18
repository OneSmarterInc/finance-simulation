<?php

namespace Tests\Feature\Practice;

use App\Livewire\Faculty\Courses\PracticeRun;
use App\Models\CfPlusAuctionEconomicsSnapshot;
use App\Models\CfPlusOffer;
use App\Models\Faculty;
use App\Models\FirmQuarterDecision;
use App\Services\Decisions\FirmDecisionService;
use App\Services\Practice\PracticeRunService;
use App\Services\Practice\TeamSettings;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Practice\BuildsPracticeSource;
use Tests\TestCase;

/**
 * U9 layer 5 (design note FF3, FF7 to FF9): faculty can change the reference
 * teams. Standard settings are the fixed teams exactly; saving starts the run
 * again and replays it; bids follow the settings; the page stops judging
 * the leader once a team is custom.
 */
class TeamSettingsTest extends TestCase
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

    public function test_standard_settings_are_the_fixed_teams_and_keep_their_words(): void
    {
        $settings = TeamSettings::resolve(null);
        $this->assertSame(TeamSettings::STANDARD, $settings);
        foreach (['careful', 'average', 'careless'] as $team) {
            $this->assertTrue(TeamSettings::isStandard($team, $settings[$team]));
            $this->assertSame(TeamSettings::STANDARD_RULES[$team], TeamSettings::describe($team, $settings[$team]));
            $this->assertSame(TeamSettings::STANDARD_BID_RULES[$team], TeamSettings::describeBid($team, $settings[$team]));
        }

        $changed = [...$settings['average'], 'price_pct' => 95, 'dividend_pct' => 50, 'bid_basis' => 'true_worth', 'bid_pct' => 90];
        $this->assertSame(
            "Plans from last quarter's sales. Makes 100% of the plan. Prices at 95% of the base price and spends 5% of revenue on marketing. Buys from China with Basic inspection and express shipping. Uses units of production depreciation and FOB destination terms. Pays 50% of the largest dividend allowed.",
            TeamSettings::describe('average', $changed),
        );
        $this->assertSame('Offers 90% of what the stake is truly worth.', TeamSettings::describeBid('average', $changed));

        // The Custom badge says what changed, in a few words.
        $likeCareful = [...$settings['average'], 'forecast_basis' => 'orders', 'forecast_season' => true, 'forecast_adjust' => 2, 'supplier' => 'SUP006'];
        $this->assertSame('plans like Careful, buys from India', TeamSettings::changeSummary('average', $likeCareful));
        $this->assertSame('prices at 95%, pays 50% dividend, bids 90% of true worth', TeamSettings::changeSummary('average', $changed));
        $this->assertSame('', TeamSettings::changeSummary('careful', $settings['careful']));
        $this->assertSame('India ($110, 2.8% defects)', TeamSettings::choiceLabel('supplier', 'SUP006'));
    }

    public function test_settings_are_checked_before_anything_is_saved(): void
    {
        $source = $this->sourceCourse('Checked');
        try {
            app(PracticeRunService::class)->saveSettings($this->faculty, $source, [
                'careful' => ['price_pct' => 300, 'supplier' => 'SUP999'],
                'careless' => ['forecast_adjust' => '2.5'],
            ]);
            $this->fail('Out-of-range settings must be refused.');
        } catch (ValidationException $e) {
            $this->assertSame(['settings.careful.price_pct', 'settings.careful.supplier', 'settings.careless.forecast_adjust'], array_keys($e->errors()));
            $this->assertSame('Price (% of base price) must be a whole number from 50 to 150.', $e->errors()['settings.careful.price_pct'][0]);
        }
        $this->assertNull($source->fresh()->practice_team_settings);
    }

    public function test_saving_starts_the_run_again_replays_it_and_the_teams_decide_by_their_new_settings(): void
    {
        $runs = app(PracticeRunService::class);
        $source = $this->sourceCourse('Changed teams');
        $first = $runs->playTo($this->faculty, $runs->create($this->faculty, $source), 6);

        $settings = TeamSettings::resolve(null);
        $settings['careful']['price_pct'] = 80;
        $settings['careful']['marketing_pct'] = 10;
        $settings['careful']['supplier'] = 'SUP001';
        $settings['careful']['production_nets_stock'] = false;
        $settings['careful']['production_pct'] = 100;
        $run = $runs->saveSettings($this->faculty, $source, $settings);

        $this->assertNotSame($first->id, $run->id, 'the run was started again');
        $this->assertDatabaseMissing('courses', ['id' => $first->id]);
        $this->assertSame(6, $runs->playedTo($run), 'and replayed to the quarter it had reached');
        $this->assertEquals(['careful' => ['price_pct' => 80, 'marketing_pct' => 10, 'supplier' => 'SUP001', 'production_pct' => 100, 'production_nets_stock' => false]], $source->fresh()->practice_team_settings, 'only the changes are stored');
        $this->assertSame($source->fresh()->practice_team_settings, $run->practice_team_settings, 'the run keeps the settings it was played with');
        $this->assertSame(['careful'], $runs->customTeams($run));
        $this->assertStringStartsWith("Plans from last quarter's orders, adjusted for the season, plus 2%. Makes 100% of the plan.", $runs->rules($run)['careful']);

        $careful = $run->firms()->get()->first(fn ($f) => strtolower($f->code) === 'careful');
        $q6 = $run->quarters()->where('quarter_number', 6)->firstOrFail();
        $payload = FirmQuarterDecision::query()->where('firm_id', $careful->id)->where('course_quarter_id', $q6->id)->firstOrFail()->submitted_payload;
        $base = (float) app(FirmDecisionService::class)->parameters($careful)['p1_base_price'];
        $this->assertEqualsWithDelta(round($base * 0.8, 2), (float) $payload['pricing']['price_p1_r1'], 0.001);
        $this->assertEqualsWithDelta(0.10, (float) $payload['marketing']['marketing_budget_pct'], 1e-9);
        $this->assertSame('SUP001', $payload['sourcing']['primary_supplier']);

        // Back to standard rules clears the stored settings and replays again.
        $run = $runs->saveSettings($this->faculty, $source->fresh(), TeamSettings::STANDARD);
        $this->assertNull($source->fresh()->practice_team_settings);
        $this->assertSame([], $runs->customTeams($run));
        $this->assertSame(6, $runs->playedTo($run));
    }

    public function test_the_teams_bid_by_their_bid_settings(): void
    {
        $runs = app(PracticeRunService::class);
        $source = $this->sourceCourse('Bids', plus: true);
        $settings = TeamSettings::resolve(null);
        $settings['careless']['bid_basis'] = 'true_worth';
        $settings['careless']['bid_pct'] = 50;
        $runs->saveSettings($this->faculty, $source, $settings);

        $run = $runs->playTo($this->faculty, $runs->create($this->faculty, $source), 6);
        $economics = CfPlusAuctionEconomicsSnapshot::query()->where('course_id', $run->id)->firstOrFail()->economics()->get()->keyBy('firm_id');
        $careless = $run->firms()->get()->first(fn ($f) => strtolower($f->code) === 'careless');
        $offers = CfPlusOffer::query()->where('course_id', $run->id)->where('bidder_firm_id', $careless->id)->get();
        $this->assertCount(2, $offers);
        foreach ($offers as $offer) {
            $this->assertEqualsWithDelta(min(0.5 * (float) $economics[$offer->target_firm_id]->displayed_true_stake_worth, (float) $offer->fundable_cap), (float) $offer->offered_amount, 0.011);
        }
        $this->assertSame('Offers 50% of what the stake is truly worth.', $runs->bidRules($run)['careless']);
    }

    public function test_the_page_changes_the_teams_and_stops_judging_the_leader(): void
    {
        $runs = app(PracticeRunService::class);
        $source = $this->sourceCourse('Page teams');
        $runs->playTo($this->faculty, $runs->create($this->faculty, $source), 5);
        $this->actingAs($this->faculty->user);

        $page = Livewire::test(PracticeRun::class, ['course' => $source])
            ->assertSee('Change the teams')
            ->assertSee('Careful leads in every quarter from 4 to 5')
            ->call('editTeams')
            ->assertSee('data-test="team-settings"', false)
            ->assertSee('Save and start again')
            ->assertSee('Price (% of base price)')
            ->assertDontSee('Dividend (% of the most allowed)')
            ->assertSee('More settings: making, methods, dividend')
            ->call('toggleMore', 'careful')
            ->assertSee('Dividend (% of the most allowed)')
            ->assertDontSee('Bids in the evaluation stage')
            ->call('toggleMore', 'careful')
            ->set('settings.careless.dividend_pct', 120)
            ->call('saveTeams')
            ->assertHasErrors(['settings.careless.dividend_pct'])
            ->assertSet('moreOpen.careless', true)
            ->assertSee('Dividend (% of the most allowed) must be a whole number from 0 to 100.')
            ->set('settings.careless.dividend_pct', 100)
            ->set('settings.careless.price_pct', 300)
            ->call('saveTeams')
            ->assertHasErrors(['settings.careless.price_pct'])
            ->assertSee('must be a whole number from 50 to 150');

        $page->set('settings.careless.price_pct', 100)
            ->set('settings.careless.forecast_adjust', 0)
            ->call('saveTeams')
            ->assertHasNoErrors()
            ->assertSet('editingTeams', false)
            ->assertSee('Custom: plans from orders')
            ->assertSee('One team plays custom settings.')
            ->assertSee('data-test="practice-leader-spans"', false)
            ->assertDontSee('Careful leads in every quarter')
            ->assertSee('Prices at 100% of the base price and spends 12% of revenue on marketing.');
        $this->assertSame(5, $runs->playedTo($runs->current($source)));

        $page->call('editTeams')->call('standardTeam', 'careless')->call('saveTeams')
            ->assertDontSee('One team plays custom settings.')
            ->assertSee('Careful leads in every quarter from 4 to 5');
    }
}
