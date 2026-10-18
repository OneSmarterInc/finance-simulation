<?php

namespace Tests\Feature\Courses;

use App\Livewire\Faculty\Courses\CourseWizard;
use App\Models\Course;
use App\Models\CourseDecisionEnablement;
use App\Models\CourseFacultyRole;
use App\Models\CourseQuarter;
use App\Models\Faculty;
use App\Models\Firm;
use App\Models\Scenario;
use App\Models\Student;
use App\Services\Accounting\FirmAccountingElectionService;
use App\Services\Courses\CourseConfigurationService;
use App\Services\Decisions\FirmDecisionService;
use App\Services\Warmup\InheritedWarmupHistoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Phase7B\BuildsPhase7BCourse;
use Tests\TestCase;

/** ED9: faculty choose the accounting methods every firm starts with. */
class StartingMethodsTest extends TestCase
{
    use BuildsPhase7BCourse, RefreshDatabase;

    private const CHOSEN = [
        'depreciation_method' => 'double_declining_balance',
        'shipping_terms' => 'fob_destination',
        'bad_debt_method' => 'percentage_of_sales',
        'warranty_method' => 'expense_as_incurred',
        'excise_tax_treatment' => 'separate_operating_expense',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function draftCourse(Faculty $faculty, array $attributes = []): Course
    {
        $course = Course::query()->create([
            'institution_id' => $faculty->institution_id, 'faculty_id' => $faculty->id,
            'scenario_id' => Scenario::query()->where('code', 'cf_manufacturing')->value('id'),
            'name' => 'Starting methods', 'starts_on' => '2026-09-01', 'ends_on' => '2028-01-01', 'status' => 'draft',
            'cf_plus_enabled' => false, 'accounting_election_enabled' => false, ...$attributes,
        ]);
        CourseFacultyRole::query()->create(['course_id' => $course->id, 'faculty_id' => $faculty->id, 'role' => CourseFacultyRole::ROLE_OWNER]);

        return $course;
    }

    public function test_courses_start_from_the_standard_methods_unless_faculty_choose(): void
    {
        $this->assertSame(
            ['inventory_method' => 'fifo', 'depreciation_method' => 'straight_line', 'shipping_terms' => 'fob_shipping_point', 'bad_debt_method' => 'percentage_of_receivables', 'warranty_method' => 'percentage_of_sales', 'excise_tax_treatment' => 'cogs_inclusion'],
            (new Course)->startingElections(),
        );
    }

    public function test_the_wizard_saves_the_starting_methods_and_shows_them_at_review(): void
    {
        $faculty = Faculty::query()->firstOrFail();
        $this->actingAs($faculty->user);
        $course = $this->draftCourse($faculty);

        $component = Livewire::test(CourseWizard::class, ['course' => $course])
            ->call('goToStep', 4)
            ->assertSee('Starting accounting methods')
            ->assertSee('Inventory is always first-in, first-out.');
        foreach (self::CHOSEN as $key => $value) {
            $component->set("starting_elections.{$key}", $value);
        }
        $component->call('saveIndustry')->assertHasNoErrors()->assertSet('step', 5);

        $this->assertSame(['inventory_method' => 'fifo', ...self::CHOSEN], $course->fresh()->startingElections());
        $this->assertSame(['inventory_method' => 'fifo', ...self::CHOSEN], $course->fresh()->starting_elections);
    }

    public function test_the_starting_methods_cannot_change_once_the_course_is_running(): void
    {
        $faculty = Faculty::query()->firstOrFail();
        $course = $this->draftCourse($faculty, ['status' => 'deployed']);

        $this->expectException(ValidationException::class);
        app(CourseConfigurationService::class)->saveStartingElections($faculty, $course, self::CHOSEN);
    }

    public function test_the_inventory_method_stays_first_in_first_out(): void
    {
        $faculty = Faculty::query()->firstOrFail();
        $course = $this->draftCourse($faculty);

        $this->expectException(ValidationException::class);
        app(CourseConfigurationService::class)->saveStartingElections($faculty, $course, ['inventory_method' => 'lifo', ...self::CHOSEN]);
    }

    public function test_the_warm_up_and_later_quarters_use_the_starting_methods(): void
    {
        $faculty = Faculty::query()->firstOrFail();
        foreach ([1, 2] as $version) {
            config(['flexee.engine_version_for_new_courses' => $version]);
            $course = $this->draftCourse($faculty, ['status' => 'deployed', 'starting_elections' => ['inventory_method' => 'fifo', ...self::CHOSEN], 'accounting_election_enabled' => $version === 2]);
            foreach (range(1, 4) as $n) {
                CourseQuarter::query()->create(['course_id' => $course->id, 'quarter_number' => $n, 'starts_on' => now()->addMonths($n)->toDateString(), 'ends_on' => now()->addMonths($n + 1)->toDateString(), 'decision_deadline_at' => now()->addMonths($n + 1), 'state' => $n <= 3 ? CourseQuarter::CLOSED : CourseQuarter::LOCKED]);
            }
            $firm = Firm::factory()->create(['course_id' => $course->id, 'institution_id' => $course->institution_id]);
            $this->addMember($firm, Student::query()->firstOrFail(), $faculty);
            app(InheritedWarmupHistoryService::class)->initialize($firm);

            $q1 = $firm->engineResults()->where('quarter_number', 1)->firstOrFail()->fields;
            $this->assertEquals(0, $q1['n6_war_close'], "v{$version}: warranty expensed as incurred leaves no reserve");
            $this->assertEquals(0, $q1['n3_in_transit'], "v{$version}: FOB destination leaves goods in transit with the supplier");
            $this->assertEqualsWithDelta(2 * $q1['n4_tech_gross'] / 5 / 4, $q1['n4_tech_depr'] + 2 * 200000 / 5 / 4, 1.0, "v{$version}: declining balance charges twice straight-line on a new asset");

            $q4 = CourseQuarter::query()->where('course_id', $course->id)->where('quarter_number', 4)->firstOrFail();
            foreach (['forecasting', 'sourcing', 'production'] as $code) {
                CourseDecisionEnablement::query()->create(['course_quarter_id' => $q4->id, 'scenario_decision_category_id' => $course->scenario->decisionCategories()->where('code', $code)->value('id'), 'enabled' => true]);
            }
            $expected = ['inventory_method' => 'fifo', ...self::CHOSEN];
            if ($version === 2) {
                $this->assertSame($expected, app(FirmAccountingElectionService::class)->effective($firm->fresh('course'), $q4));
            } else {
                $this->assertSame($expected, app(FirmDecisionService::class)->resolveCompleteDecisions($firm->fresh('course'), $q4, $this->completePayload())['notes_elections']);
            }
        }
    }
}
