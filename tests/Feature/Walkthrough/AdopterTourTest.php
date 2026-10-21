<?php

namespace Tests\Feature\Walkthrough;

use App\Models\Course;
use App\Models\Faculty;
use App\Models\User;
use App\Services\Tour\TourFigures;
use App\Support\Tour\AdopterTour;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Live walkthrough L8: the public tour for faculty thinking of adopting
 * Flexee. Nine steps, no sign-in, every figure read from the stored tour
 * figures, ending with an address to copy.
 */
class AdopterTourTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_step_opens_without_signing_in_and_quotes_the_stored_figures(): void
    {
        $x = TourFigures::load();
        $steps = AdopterTour::steps($x);
        $this->assertCount(9, $steps);

        $this->get(route('home'))->assertOk()->assertSee('data-test="see-how-it-works"', false)->assertSee(route('tour'), false);
        foreach ($steps as $i => $step) {
            $this->get(route('tour', $i + 1))->assertOk()
                ->assertSee($step['title'])
                ->assertSee('Step '.($i + 1).' of 9')
                ->assertSee($step['lesson']);
        }
        $this->get(route('tour'))->assertOk()->assertSee('A course in reading financial statements');
        $this->get('/tour/10')->assertNotFound();
        $this->get('/tour/0')->assertNotFound();

        $this->get(route('tour', 2))->assertSee(AdopterTour::usd($x['careful'][1]['revenue']))->assertSee(AdopterTour::m($x['careful'][3]['cash']));
        $this->get(route('tour', 4))->assertSee(AdopterTour::usd($x['careful'][4]['net_income']))->assertSee(AdopterTour::m($x['careful'][4]['dividend']));
        $this->get(route('tour', 7))->assertSee(AdopterTour::m($x['value_created']['careful'][11]));
        $this->get(route('tour', 8))->assertSee(AdopterTour::m($x['evaluation']['firms'][0]['true_worth']));
        $this->get(route('tour', 9))->assertSee(AdopterTour::CONTACT)->assertSee('nothing is sent from this page')->assertDontSee('<form', false);
    }

    public function test_the_stored_figures_hold_what_the_story_says(): void
    {
        $x = TourFigures::load();
        foreach (range(4, 11) as $q) {
            $this->assertGreaterThan($x['value_created']['average'][$q], $x['value_created']['careful'][$q], "Careful leads in Quarter {$q}");
            $this->assertGreaterThan($x['value_created']['careless'][$q], $x['value_created']['careful'][$q], "Careful leads in Quarter {$q}");
        }
        $this->assertGreaterThan($x['careless_depreciation']['before'], $x['careless_depreciation']['after'], 'declining balance raises depreciation');
        $results = collect($x['evaluation']['results'])->keyBy('target');
        $this->assertSame('careless', $results['careful']['winner']);
        $this->assertGreaterThan(0, $results['careful']['difference'], 'Careless overpaid for Careful');
        $this->assertSame('careful', $results['careless']['winner']);
        $this->assertLessThan(0, $results['careless']['difference'], 'Careful paid less than Careless was worth');
    }

    public function test_the_figures_command_plays_a_reference_course_and_leaves_nothing_behind(): void
    {
        $this->seed(DatabaseSeeder::class);
        $users = User::count();
        $figures = app(TourFigures::class)->generate(Faculty::query()->firstOrFail());

        $this->assertSame(0, Course::withTrashed()->count(), 'the throwaway course and its practice run are gone');
        $this->assertSame($users, User::count(), 'and so are the practice students');
        $this->assertSame(2, $figures['engine_version']);
        $this->assertSame(7, $figures['evaluation_opens_with']);
        $this->assertSame(['careful', 'average', 'careless'], array_column($figures['evaluation']['firms'], 'team'));
        $this->assertSame(range(4, 11), array_keys($figures['value_created']['careful']));
        $this->assertSame(array_keys(TourFigures::load()), array_keys($figures), 'the stored file has the same shape');
    }

    /** Live review, 20 October 2026: R1 to R4 and R31. */
    public function test_the_tour_follows_the_figures_and_the_day_it_is_viewed(): void
    {
        $x = TourFigures::load();
        // R1: revenue peaked in Quarter 2 and levelled off.
        $this->get(route('tour', 2))->assertSee('by Quarter 2 and then leveled off', false);

        // R2: the drawing says what the team changed.
        $this->get(route('tour', 3))->assertSee('data-test="tour-step3-caption"', false)->assertSee('raised its forecasts');

        // R3: dates follow today, in the display time zone.
        config(['flexee.display_timezone' => 'America/New_York']);
        $this->travelTo(CarbonImmutable::parse('2027-03-02 15:00', 'America/New_York'));
        $this->get(route('tour', 1))->assertSee('Decisions are due 8 Mar, 11:59 pm.')->assertDontSee('24 Oct');
        $this->get(route('tour', 6))->assertSee('8 Mar, 11:59 pm</b> (6 days left)', false)->assertSee('Decisions due 22 Mar, 11:59 pm.');

        // R4: Quarter 11 shows without scrolling past Quarter 10; the team names stay pinned.
        $html = $this->get(route('tour', 7))->getContent();
        foreach (['Q4', 'Q6', 'Q8', 'Q10', 'Q11'] as $q) {
            $this->assertStringContainsString('>'.$q.'</th>', $html);
        }
        $this->assertStringNotContainsString('>Q5</th>', $html);
        $this->assertStringContainsString('sticky left-0', $html);
        $this->assertStringContainsString(AdopterTour::m($x['value_created']['careless'][11]), $html);

        // R31: the current step is scrolled into view and named above the title on a phone.
        $this->get(route('tour', 7))->assertSee('data-test="tour-step-count"', false)->assertSee("scrollIntoView({ block: 'nearest', inline: 'center' })", false);
    }
}
