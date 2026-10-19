<?php

namespace Tests\Feature\Debrief;

use App\Livewire\Student\FirmDashboard;
use App\Models\StudentExplainer;
use App\Services\Tour\TourFigures;
use App\Support\Explainers\Explainers;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Phase7B\BuildsPhase7BCourse;
use Tests\TestCase;

/**
 * U4 layer 2 (MO1 to MO3, decided 19 October 2026): two animated explainers
 * in the app, with figures from the stored reference run, on the Guide page
 * for everyone and as a dashboard card for students until they watch or
 * hide it.
 */
class ExplainersTest extends TestCase
{
    use BuildsPhase7BCourse, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_the_stored_figures_hold_what_the_depreciation_explainer_says(): void
    {
        $e = TourFigures::load()['depreciation_explainer'];
        $sl = $e['straight_line'];
        $db = $e['double_declining'];

        $this->assertGreaterThan($sl['depreciation'], $db['depreciation'], 'double-declining balance charges more');
        $this->assertLessThan($sl['net_income'], $db['net_income'], 'so net income falls');
        $this->assertLessThan($sl['income_tax'], $db['income_tax'], 'and so does tax');
        $this->assertGreaterThan($sl['operating_cash_flow'], $db['operating_cash_flow'], 'so cash from operations rises');
        $this->assertGreaterThan($sl['cash'], $db['cash']);
        $this->assertEqualsWithDelta($db['depreciation'] - $sl['depreciation'], $sl['net_plant'] - $db['net_plant'], 0.01, 'the plant is lower by the extra depreciation');
        $this->assertEqualsWithDelta($db['net_income'] - $sl['net_income'], $db['equity'] - $sl['equity'], 0.01, 'equity moves with net income');
        $this->assertSame($sl['units_sold'], $db['units_sold'], 'the units sold are the same');
    }

    public function test_both_explainers_are_on_the_guide_for_students_and_faculty_with_the_stored_figures(): void
    {
        [$course, , $student] = $this->phase7bCourse('Explained');
        $x = TourFigures::load();
        $all = Explainers::all($x);
        $this->assertSame(Explainers::keys(), array_keys($all));
        foreach ($all as $explainer) {
            $this->assertCount(6, $explainer['scenes']);
        }

        foreach ([$student->user, $course->faculty->user] as $user) {
            $this->actingAs($user)->get(route('guide'))->assertOk()
                ->assertSee('See it move')
                ->assertSee('id="how-a-quarter-works"', false)->assertSee('id="one-choice-four-statements"', false)
                ->assertSee('How a quarter works')->assertSee('One choice, four statements')
                ->assertSee(Explainers::usd($x['careful']['3']['net_income']))
                ->assertSee(Explainers::usd($x['depreciation_explainer']['straight_line']['depreciation']))
                ->assertSee(Explainers::usd($x['depreciation_explainer']['double_declining']['depreciation']))
                ->assertSee(Explainers::SOURCE);
        }

        // Students read every word: no reference team's name, and never "the engine", "the model" or "AI".
        $words = json_encode($all, JSON_UNESCAPED_UNICODE);
        foreach (['engine', 'model', 'AI ', 'Careful', 'Average', 'Careless', 'round', 'lever'] as $banned) {
            $this->assertStringNotContainsStringIgnoringCase($banned, $words, "the explainers never say \"{$banned}\"");
        }
    }

    public function test_a_student_sees_the_card_until_they_watch_or_hide_it_and_faculty_never_do(): void
    {
        [$course, $firm, $student] = $this->phase7bCourse('Carded');

        $this->actingAs($student->user)->get(route('firms.dashboard', $firm))->assertOk()
            ->assertSee('New here? See how a quarter works')->assertSee('data-test="explainer-card"', false);
        $this->actingAs($course->faculty->user)->get(route('firms.dashboard', $firm))->assertOk()
            ->assertDontSee('New here? See how a quarter works');

        // Watching to the last step records it; the card stays for this visit and is gone the next time.
        $this->actingAs($student->user);
        Livewire::test(FirmDashboard::class, ['firm' => $firm])->call('explainerWatched')->assertSee('New here? See how a quarter works');
        $this->assertNotNull(StudentExplainer::query()->where(['student_id' => $student->id, 'course_id' => $course->id])->value('watched_at'));
        $this->get(route('firms.dashboard', $firm))->assertDontSee('New here? See how a quarter works');

        // Hiding it works the same way, and is per course.
        StudentExplainer::query()->delete();
        Livewire::test(FirmDashboard::class, ['firm' => $firm])->call('dismissExplainer')->assertDontSee('New here? See how a quarter works');
        $row = StudentExplainer::query()->where(['student_id' => $student->id, 'course_id' => $course->id, 'explainer' => Explainers::QUARTER])->firstOrFail();
        $this->assertNotNull($row->dismissed_at);
        $this->assertNull($row->watched_at);

        // Faculty viewing the firm can't record anything for the student.
        StudentExplainer::query()->delete();
        $this->actingAs($course->faculty->user);
        Livewire::test(FirmDashboard::class, ['firm' => $firm])->call('dismissExplainer')->call('explainerWatched');
        $this->assertSame(0, StudentExplainer::query()->count());
    }
}
