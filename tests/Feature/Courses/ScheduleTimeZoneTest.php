<?php

namespace Tests\Feature\Courses;

use App\Livewire\Faculty\Courses\CourseWizard;
use App\Models\Admin;
use App\Models\Course;
use App\Models\Faculty;
use App\Models\Scenario;
use App\Services\Courses\AdminCourseConfigurationService;
use App\Services\Courses\CourseConfigurationService;
use App\Services\Courses\ScheduleRows;
use App\Support\Dates;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Live review R10 (20 October 2026): with the site showing New York time,
 * a schedule with each deadline on its quarter's last day couldn't be saved.
 */
class ScheduleTimeZoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function wizardAtSchedule(): Testable
    {
        config(['flexee.display_timezone' => 'America/New_York']);
        $faculty = Faculty::factory()->create();
        $this->actingAs($faculty->user);
        $scenario = Scenario::query()->where('code', 'cf_manufacturing')->firstOrFail();

        return Livewire::test(CourseWizard::class)
            ->set('name', 'V3 review (delete me)')
            ->set('institution_id', $faculty->institution_id)
            ->set('scenario_id', $scenario->id)
            ->set('starts_on', '2027-01-11')
            ->set('ends_on', '2027-04-30')
            ->set('cf_plus_enabled', true)
            ->set('build_quarters', 3)
            ->set('adversarial_quarters', 2)
            ->set('post_bid_quarters', 3)
            ->set('accounting_election_enabled', true)
            ->call('saveSetup')
            ->assertHasNoErrors()
            ->assertSet('step', 2)
            ->set('quarter_count', 11)
            ->call('buildScheduleRows');
    }

    public function test_generated_rows_save_in_new_york_time(): void
    {
        $this->wizardAtSchedule()
            ->call('saveSchedule')
            ->assertHasNoErrors()
            ->assertSet('step', 3);
    }

    public function test_deadlines_a_day_early_save_in_new_york_time(): void
    {
        $wizard = $this->wizardAtSchedule();
        foreach ($wizard->get('quarters') as $index => $quarter) {
            if ($quarter['quarter_number'] >= 4) {
                $wizard->set("quarters.{$index}.decision_deadline_at", CarbonImmutable::parse($quarter['ends_on'])->subDay()->toDateString());
            }
        }
        $wizard->call('saveSchedule')->assertHasNoErrors()->assertSet('step', 3);
    }

    public function test_a_failed_save_then_corrected_deadlines_saves(): void
    {
        $wizard = $this->wizardAtSchedule();
        $wizard->call('saveSchedule');
        $wizard->set('quarters.3.decision_deadline_at', '2027-02-25')->call('saveSchedule');
        foreach ($wizard->get('quarters') as $index => $quarter) {
            if ($quarter['quarter_number'] >= 4) {
                $wizard->set("quarters.{$index}.decision_deadline_at", CarbonImmutable::parse($quarter['ends_on'])->subDay()->toDateString());
            }
        }
        $wizard->call('saveSchedule')->assertHasNoErrors()->assertSet('step', 3);
    }

    /** @return list<array{quarter_number: int, starts_on: string, ends_on: string, decision_deadline_at: string}> */
    private function fourQuarters(): array
    {
        // Quarters 1 to 3 are the warm-up (R9); Quarters 4 to 6 share the term.
        return ScheduleRows::build('2027-01-11', '2027-04-30', 6);
    }

    private function draftCourse(Faculty $faculty): Course
    {
        $scenario = Scenario::query()->where('code', 'cf_manufacturing')->firstOrFail();

        return app(CourseConfigurationService::class)->saveSetup($faculty, [
            'name' => 'Time zone check', 'institution_id' => $faculty->institution_id, 'scenario_id' => $scenario->id,
            'starts_on' => '2027-01-11', 'ends_on' => '2027-04-30', 'payment_mode' => null,
            'cf_plus_enabled' => false, 'accounting_election_enabled' => false, 'build_quarters' => null, 'adversarial_quarters' => null, 'post_bid_quarters' => null,
        ]);
    }

    public function test_a_last_day_deadline_is_stored_as_1159_pm_new_york_time(): void
    {
        config(['flexee.display_timezone' => 'America/New_York']);
        $faculty = Faculty::factory()->create();
        $course = $this->draftCourse($faculty);
        app(CourseConfigurationService::class)->saveSchedule($faculty, $course, $this->fourQuarters());

        $q4 = $course->quarters()->where('quarter_number', 4)->firstOrFail();
        $this->assertSame('2027-02-16 04:59:00', $q4->decision_deadline_at->utc()->toDateTimeString());
        $this->assertSame('15 Feb 2027, 11:59 pm EST', Dates::dateTime($q4->decision_deadline_at));
    }

    public function test_admin_preparation_saves_last_day_deadlines_in_new_york_time(): void
    {
        config(['flexee.display_timezone' => 'America/New_York']);
        $faculty = Faculty::factory()->create();
        $course = $this->draftCourse($faculty);
        $admin = Admin::factory()->create();

        app(AdminCourseConfigurationService::class)->saveSchedule($admin, $course, $this->fourQuarters());

        $this->assertSame(6, $course->quarters()->count());
    }

    public function test_a_wrong_row_is_named_with_its_dates(): void
    {
        config(['flexee.display_timezone' => 'America/New_York']);
        $faculty = Faculty::factory()->create();
        $course = $this->draftCourse($faculty);
        $service = app(CourseConfigurationService::class);
        $cases = [
            [3, 'decision_deadline_at', '2027-02-20', "Quarter 4's deadline (20 Feb 2027) is after the quarter ends (15 Feb 2027)."],
            [4, 'decision_deadline_at', '2027-02-10', "Quarter 5's deadline (10 Feb 2027) is before the quarter starts (16 Feb 2027)."],
            [3, 'starts_on', '2027-01-10', 'Quarter 4 starts (10 Jan 2027) before the course starts (11 Jan 2027).'],
            [5, 'ends_on', '2027-05-01', 'Quarter 6 ends (1 May 2027) after the course ends (30 Apr 2027).'],
            [5, 'starts_on', '2027-03-23', 'Quarter 6 starts (23 Mar 2027) before Quarter 5 ends (23 Mar 2027).'],
        ];
        foreach ($cases as [$row, $field, $value, $message]) {
            $quarters = $this->fourQuarters();
            $quarters[$row][$field] = $value;
            try {
                $service->saveSchedule($faculty, $course, $quarters);
                $this->fail("Expected: {$message}");
            } catch (ValidationException $e) {
                $this->assertSame($message, $e->errors()['quarters'][0]);
            }
        }
    }
}
