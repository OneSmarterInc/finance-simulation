<?php

namespace Tests\Feature\Courses;

use App\Livewire\Faculty\Courses\CourseWizard;
use App\Models\Faculty;
use App\Models\Institution;
use App\Models\Scenario;
use App\Models\User;
use App\Services\Courses\CourseConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CourseWizardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_guest_is_rejected_and_non_faculty_is_forbidden(): void
    {
        $this->get(route('faculty.courses.create'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['email_verified_at' => now()]))->get(route('faculty.courses.create'))->assertForbidden();
    }

    public function test_faculty_can_render_setup_and_create_a_scoped_draft(): void
    {
        $faculty = Faculty::factory()->create();
        $this->actingAs($faculty->user);
        $scenario = Scenario::query()->where('code', 'cf_manufacturing')->firstOrFail();
        Livewire::test(CourseWizard::class)
            ->assertSee('Step 1 of 6')->assertSee('Enable CF Plus')
            ->set('name', 'Livewire Course')->set('institution_id', $faculty->institution_id)->set('scenario_id', $scenario->id)
            ->set('starts_on', '2027-01-01')->set('ends_on', '2027-12-31')->set('payment_mode', 'invoice')
            ->call('saveSetup')->assertHasNoErrors()->assertSet('step', 2);
        $this->assertDatabaseHas('courses', ['name' => 'Livewire Course', 'faculty_id' => $faculty->id, 'status' => 'draft']);
    }

    public function test_component_rejects_institution_parameter_tampering(): void
    {
        $faculty = Faculty::factory()->create();
        $this->actingAs($faculty->user);
        Livewire::test(CourseWizard::class)->set('name', 'Tampered')->set('institution_id', Institution::factory()->create()->id)
            ->set('scenario_id', Scenario::query()->where('code', 'cf_manufacturing')->value('id'))->set('starts_on', '2027-01-01')->set('ends_on', '2027-12-31')
            ->call('saveSetup')->assertForbidden();
    }

    public function test_toggle_propagates_operating_category_but_not_certification(): void
    {
        $faculty = Faculty::factory()->create();
        $this->actingAs($faculty->user);
        $component = Livewire::test(CourseWizard::class)->set('matrix', [4 => ['marketing' => false, 'certification' => false], 5 => ['marketing' => false, 'certification' => false], 6 => ['marketing' => false, 'certification' => false]]);
        $component->call('toggleCategory', 4, 'marketing', true)->assertSet('matrix.5.marketing', true)->assertSet('matrix.6.marketing', true);
        $component->call('toggleCategory', 4, 'certification', true)->assertSet('matrix.5.certification', false)->assertSet('matrix.6.certification', false);
    }

    public function test_backward_navigation_preserves_valid_draft_state(): void
    {
        $faculty = Faculty::factory()->create();
        $this->actingAs($faculty->user);
        $scenario = Scenario::query()->where('code', 'cf_manufacturing')->firstOrFail();
        Livewire::test(CourseWizard::class)->set('name', 'Persistent Draft')->set('institution_id', $faculty->institution_id)->set('scenario_id', $scenario->id)
            ->set('starts_on', '2027-01-01')->set('ends_on', '2027-12-31')->call('saveSetup')->call('goToStep', 1)
            ->assertSet('name', 'Persistent Draft')->assertSet('step', 1);
    }

    public function test_step_three_starts_at_quarter_four_and_rejects_warmup_tampering(): void
    {
        $faculty = Faculty::factory()->create();
        $this->actingAs($faculty->user);
        $scenario = Scenario::query()->where('code', 'cf_manufacturing')->firstOrFail();
        $service = app(CourseConfigurationService::class);
        $course = $service->saveSetup($faculty, [
            'name' => 'Warm-up Boundary', 'institution_id' => $faculty->institution_id, 'scenario_id' => $scenario->id,
            'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31', 'payment_mode' => null,
            'cf_plus_enabled' => false, 'accounting_election_enabled' => false, 'build_quarters' => null, 'adversarial_quarters' => null, 'post_bid_quarters' => null,
        ]);
        $service->saveSchedule($faculty, $course, [
            ['quarter_number' => 1, 'starts_on' => '2027-01-01', 'ends_on' => '2027-03-31', 'decision_deadline_at' => '2027-03-31'],
            ['quarter_number' => 2, 'starts_on' => '2027-04-01', 'ends_on' => '2027-06-30', 'decision_deadline_at' => '2027-06-30'],
            ['quarter_number' => 3, 'starts_on' => '2027-07-01', 'ends_on' => '2027-09-30', 'decision_deadline_at' => '2027-09-30'],
            ['quarter_number' => 4, 'starts_on' => '2027-10-01', 'ends_on' => '2027-12-31', 'decision_deadline_at' => '2027-12-31'],
        ]);

        Livewire::test(CourseWizard::class, ['course' => $course])
            ->call('saveSchedule')
            ->assertSet('step', 3)
            ->assertSet('matrix', fn (array $matrix): bool => array_keys($matrix) === [4])
            ->assertSee('Student control begins at Quarter 4')
            ->assertSee('Q4')
            ->call('toggleCategory', 1, 'forecasting', true)
            ->assertHasErrors(['matrix'])
            ->assertSet('matrix', fn (array $matrix): bool => ! array_key_exists(1, $matrix));
    }

    public function test_invalid_setup_exposes_errors_and_remains_on_step_one(): void
    {
        $faculty = Faculty::factory()->create();
        $this->actingAs($faculty->user);

        Livewire::test(CourseWizard::class)
            ->call('saveSetup')
            ->assertHasErrors(['name', 'starts_on', 'ends_on'])
            ->assertSet('step', 1)
            ->assertSee('This step needs attention.');
    }
}
