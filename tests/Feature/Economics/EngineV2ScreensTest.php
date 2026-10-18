<?php

namespace Tests\Feature\Economics;

use App\Livewire\Student\Decisions\DecisionWorkspace;
use App\Models\Course;
use App\Models\CourseDecisionEnablement;
use App\Models\CourseQuarter;
use App\Services\Courses\CourseConfigurationService;
use App\Services\Quarters\QuarterManagementService;
use App\Support\Presentation\StatementPresenter;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Phase7B\BuildsPhase7BCourse;
use Tests\TestCase;

/** Layer 3: what students and faculty see in an engine version 2 course. */
class EngineV2ScreensTest extends TestCase
{
    use BuildsPhase7BCourse, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function fixtureRow(string $team, int $quarter): array
    {
        $fixtures = json_decode((string) file_get_contents(base_path('economics/fixtures/v2_reference.json')), true, flags: JSON_THROW_ON_ERROR);

        return $fixtures['teams'][$team][$quarter - 1];
    }

    private function labels(string $statement, array $fields): array
    {
        return collect(StatementPresenter::rows($statement, ['Q' => $fields]))
            ->mapWithKeys(fn (array $row) => [$row['label'] => $row['values'][0]])->all();
    }

    private function setEnabled(CourseQuarter $quarter, string $code, bool $enabled): void
    {
        $categoryId = $quarter->course->scenario->decisionCategories()->where('code', $code)->value('id');
        CourseDecisionEnablement::query()->updateOrCreate(
            ['course_quarter_id' => $quarter->id, 'scenario_decision_category_id' => $categoryId],
            ['enabled' => $enabled],
        );
    }

    public function test_the_income_statement_shows_beginning_inventory_in_cost_of_goods_sold(): void
    {
        // The careless team starts Quarter 5 with unsold stock.
        $row = $this->fixtureRow('careless', 5);
        $lines = $this->labels('income_statement', $row['fields']);

        $this->assertGreaterThan(0, $lines['Beginning inventory']);
        $this->assertEquals($row['fields']['is_beg_inv'], $lines['Beginning inventory']);
    }

    public function test_the_ppe_note_shows_accumulated_depreciation_and_totals_to_the_balance_sheet(): void
    {
        $fields = $this->fixtureRow('careless', 9)['fields'];
        $rows = StatementPresenter::rows('ppe', ['Q9' => $fields]);
        $labels = array_column($rows, 'label');

        $this->assertContains('Less: accumulated depreciation', $labels);
        $this->assertContains('Net book value', $labels);
        $this->assertNotContains("Cost less this quarter's depreciation", $labels);
        $total = collect($rows)->firstWhere('label', 'Property, plant and equipment, net')['values'][0];
        $this->assertEqualsWithDelta($fields['bs_ppe_gross'] - $fields['bs_accum_depr'], $total, 0.01);
    }

    public function test_the_revenue_and_inventory_notes_show_units_ordered_sold_not_filled_and_in_stock(): void
    {
        $fields = $this->fixtureRow('average', 8)['fields'];
        $labels = array_column(StatementPresenter::rows('revenue', ['Q8' => $fields]), 'label');
        foreach (['Units customers ordered', 'Units sold', 'Orders not filled', 'Northeast, Product 1'] as $label) {
            $this->assertContains($label, $labels);
        }
        $this->assertGreaterThan(0, $fields['n2_short_p1'] + $fields['n2_short_p2'], 'the average team misses orders in the peak quarter');

        $inventory = StatementPresenter::rows('inventory', ['Q8' => $this->fixtureRow('careless', 8)['fields']]);
        $this->assertContains('Units left in stock at quarter end', array_column($inventory, 'label'));
        $total = collect($inventory)->firstWhere('label', 'Inventory')['values'][0];
        $this->assertEquals($this->fixtureRow('careless', 8)['fields']['bs_inventory'], $total);
    }

    public function test_version_1_notes_are_unchanged(): void
    {
        $v1 = json_decode((string) file_get_contents(base_path('economics/fixtures/v1_stored_results.json')), true)['firms'][0]['quarters'][2]['fields'];

        $this->assertContains("Cost less this quarter's depreciation", array_column(StatementPresenter::rows('ppe', ['Q3' => $v1]), 'label'));
        $this->assertNotContains('Units customers ordered', array_column(StatementPresenter::rows('revenue', ['Q3' => $v1]), 'label'));
    }

    public function test_the_version_2_decision_form_asks_for_expected_orders_and_hides_unused_inputs(): void
    {
        config(['flexee.engine_version_for_new_courses' => 2]);
        [$course, $firm, $student, $q4] = $this->phase7bCourse('Version 2 form');
        $this->setEnabled($q4, 'production', false);
        $this->setEnabled($q4, 'marketing', true);
        app(QuarterManagementService::class)->open($course->faculty, $q4);

        Livewire::actingAs($student->user)->test(DecisionWorkspace::class, ['firm' => $firm, 'quarter' => $q4->fresh()])
            ->assertSee('Product 1 orders you expect, Northeast (units)')
            ->assertSee('The plant makes what you forecast, less the units you already have in stock.')
            ->assertSee('5% keeps orders where they are.')
            ->assertDontSee('Segment shares')
            ->assertDontSee('Champions segment')
            ->assertDontSee('Postponed production')
            ->call('submit')
            ->assertHasNoErrors();
    }

    public function test_once_production_is_open_a_version_2_team_is_not_asked_to_forecast(): void
    {
        config(['flexee.engine_version_for_new_courses' => 2]);
        [$course, $firm, $student, $q4] = $this->phase7bCourse('Version 2 production');
        app(QuarterManagementService::class)->open($course->faculty, $q4);

        Livewire::actingAs($student->user)->test(DecisionWorkspace::class, ['firm' => $firm, 'quarter' => $q4])
            ->assertDontSee('orders you expect')
            ->assertSee('Customers are sold the units already in stock first')
            ->call('submit')
            ->assertHasNoErrors();
    }

    public function test_the_version_1_decision_form_is_unchanged(): void
    {
        [$course, $firm, $student, $q4] = $this->phase7bCourse('Version 1 form');
        app(QuarterManagementService::class)->open($course->faculty, $q4);

        Livewire::actingAs($student->user)->test(DecisionWorkspace::class, ['firm' => $firm, 'quarter' => $q4])
            ->assertSee('Product 1 demand forecast, Northeast (units)')
            ->assertDontSee('orders you expect');
    }

    public function test_quarter_4_requires_forecasting_and_sourcing_only_in_version_2(): void
    {
        $this->assertSame(['forecasting', 'sourcing', 'production'], CourseConfigurationService::requiredQuarterFour(null));
        config(['flexee.engine_version_for_new_courses' => 2]);
        $this->assertSame(['forecasting', 'sourcing'], CourseConfigurationService::requiredQuarterFour(null));
        $v1Course = Course::query()->make(['engine_version' => 1]);
        $v1Course->exists = true;
        $this->assertSame(['forecasting', 'sourcing', 'production'], CourseConfigurationService::requiredQuarterFour($v1Course));
    }
}
