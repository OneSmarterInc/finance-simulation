<?php

namespace Tests\Feature\Courses;

use App\Livewire\Admin\Courses as AdminCourses;
use App\Livewire\Faculty\Courses\CourseWizard;
use App\Livewire\Faculty\Dashboard;
use App\Models\Admin;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Faculty;
use App\Models\Scenario;
use App\Services\Courses\CourseConfigurationService;
use App\Services\Courses\ScheduleRows;
use App\Services\Students\StudentCourseRegistrationService;
use App\Support\Dates;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Phase7B\BuildsPhase7BCourse;
use Tests\TestCase;

/** Live review, 20 and 21 October 2026: R7, R9, R11, R12 and R14. */
class LiveReviewCourseSetupTest extends TestCase
{
    use BuildsPhase7BCourse, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function wizard(Faculty $faculty): Testable
    {
        $this->actingAs($faculty->user);

        return Livewire::test(CourseWizard::class)
            ->set('name', 'Spring section')
            ->set('institution_id', $faculty->institution_id)
            ->set('scenario_id', Scenario::query()->where('code', 'cf_manufacturing')->value('id'))
            ->set('starts_on', '2027-01-11')
            ->set('ends_on', '2027-04-30')
            ->set('cf_plus_enabled', true)
            ->set('build_quarters', 3)
            ->set('adversarial_quarters', 2)
            ->set('post_bid_quarters', 3);
    }

    /** R9: the warm-up takes none of the term, and the box starts at the number the course needs. */
    public function test_generated_rows_start_quarter_four_on_the_course_start_date(): void
    {
        $wizard = $this->wizard(Faculty::factory()->create())->call('saveSetup')->assertSet('step', 2);

        $wizard->assertSet('quarter_count', 11);
        $rows = collect($wizard->get('quarters'));
        $this->assertCount(11, $rows);
        foreach ($rows->take(3) as $row) {
            $this->assertSame(['2027-01-10', '2027-01-10', '2027-01-10'], [$row['starts_on'], $row['ends_on'], $row['decision_deadline_at']]);
        }
        $this->assertSame('2027-01-11', $rows[3]['starts_on']);
        $this->assertSame('2027-04-30', $rows[10]['ends_on']);
        $wizard->assertSee('Played when you deploy, before the course starts.')->assertDontSee('Warm-up / reserved');

        $wizard->call('saveSchedule')->assertHasNoErrors()->assertSet('step', 3);
    }

    public function test_the_shared_builder_spreads_the_term_over_quarters_four_onward(): void
    {
        $rows = ScheduleRows::build('2027-01-11', '2027-04-30', 6);
        $this->assertSame(['2027-01-11', '2027-02-16', '2027-03-24'], array_column(array_slice($rows, 3), 'starts_on'));
        $this->assertSame('2027-04-30', $rows[5]['ends_on']);
        $this->assertSame(4, ScheduleRows::minimum(false));
        $this->assertSame(11, ScheduleRows::minimum(true, 3, 2, 3));
    }

    public function test_an_overlap_among_operating_quarters_is_still_refused(): void
    {
        $faculty = Faculty::factory()->create();
        $wizard = $this->wizard($faculty)->set('cf_plus_enabled', false)->call('saveSetup');
        $course = Course::query()->where('name', 'Spring section')->firstOrFail();
        $rows = ScheduleRows::build('2027-01-11', '2027-04-30', 5);
        $rows[4]['starts_on'] = $rows[3]['ends_on'];

        $this->expectException(ValidationException::class);
        app(CourseConfigurationService::class)->saveSchedule($faculty, $course, $rows);
    }

    /** R11: after the first save the address names the course, and a draft reopens where it was left. */
    public function test_a_draft_keeps_its_address_and_reopens_at_the_next_step(): void
    {
        $faculty = Faculty::factory()->create();
        $wizard = $this->wizard($faculty)->call('saveSetup');
        $course = Course::query()->where('name', 'Spring section')->firstOrFail();
        $expressions = collect($wizard->effects['xjs'] ?? [])->map(fn ($effect) => is_array($effect) ? ($effect['expression'] ?? '') : (string) $effect)->implode(' ');
        $this->assertStringContainsString(json_encode(route('faculty.courses.wizard', $course)), $expressions);

        Livewire::test(CourseWizard::class, ['course' => $course])->assertSet('step', 2);
        $wizard->call('saveSchedule');
        Livewire::test(CourseWizard::class, ['course' => $course->fresh()])->assertSet('step', 3);
    }

    /** R12: the owner deletes a never-deployed draft; nobody else can, and a deployed course can't be deleted this way. */
    public function test_faculty_delete_their_own_draft_only(): void
    {
        $faculty = Faculty::factory()->create();
        $this->wizard($faculty)->call('saveSetup');
        $course = Course::query()->where('name', 'Spring section')->firstOrFail();

        $other = Faculty::factory()->create(['institution_id' => $faculty->institution_id]);
        $this->actingAs($other->user);
        Livewire::test(Dashboard::class)->call('deleteDraft', $course->id)->assertForbidden();
        $this->assertNotSoftDeleted($course);

        $this->actingAs($faculty->user);
        Livewire::test(Dashboard::class)->assertSee('Delete draft')->call('deleteDraft', $course->id);
        $this->assertSoftDeleted($course);
        $this->get(route('faculty.courses.wizard', $course))->assertNotFound();

        [$deployed] = $this->phase7bCourse('Running course');
        $this->actingAs($deployed->faculty->user);
        Livewire::test(Dashboard::class)->assertDontSee('Delete draft')->call('deleteDraft', $deployed->id)->assertHasErrors('course');
        $this->assertNotSoftDeleted($deployed);
    }

    public function test_the_wizard_deletes_its_draft_and_goes_to_the_dashboard(): void
    {
        $faculty = Faculty::factory()->create();
        $wizard = $this->wizard($faculty)->call('saveSetup');
        $wizard->assertSee('Delete draft')->call('deleteDraft')->assertRedirect(route('dashboard'));
        $this->assertSoftDeleted(Course::withTrashed()->where('name', 'Spring section')->firstOrFail());
    }

    /** R12: an admin removes a deployed test course; it leaves the students' lists and its firm pages close. */
    public function test_an_admin_removes_a_deployed_course(): void
    {
        [$course, $firm] = $this->phase7bCourse('Test course');
        $membership = $firm->memberships()->with('student.user')->firstOrFail();
        $admin = Admin::factory()->create();

        $this->actingAs($admin->user);
        Livewire::test(AdminCourses::class)->assertSee('Test course')->call('remove', $course->id)->assertDontSee('Test course');

        $this->assertSoftDeleted($course);
        $this->assertSame(0, Enrollment::query()->where('course_id', $course->id)->count());
        $this->actingAs($membership->student->user)->get(route('firms.dashboard', $firm))->assertNotFound();
        $this->get(route('student.courses'))->assertOk()->assertDontSee('Test course');
    }

    /** R7: no open sign-up; the sign-in page points students to their course link. */
    public function test_open_sign_up_is_off(): void
    {
        $this->get('/register')->assertNotFound();
        $this->get(route('login'))->assertOk()->assertSee('Students: join from the course link your instructor sends.')->assertDontSee('Sign up');
    }

    /** R14: Quarter 4 opens and registration windows follow New York's day, not UTC's. */
    public function test_quarter_four_waits_for_the_new_york_day(): void
    {
        config(['flexee.display_timezone' => 'America/New_York']);
        $this->travelTo(CarbonImmutable::parse('2027-01-11 02:00:00', 'UTC'));
        $this->assertSame('2027-01-10', Dates::today());
        $this->travelTo(CarbonImmutable::parse('2027-01-11 06:00:00', 'UTC'));
        $this->assertSame('2027-01-11', Dates::today());
    }

    /** Review follow-up: students can join before the course starts, since Quarter 4 opens on the start date. */
    public function test_registration_opens_before_the_course_starts(): void
    {
        [$course] = $this->phase7bCourse('Joins early');
        $course->forceFill(['registration_enabled' => true, 'starts_on' => now()->addDays(10)->toDateString(), 'ends_on' => now()->addDays(120)->toDateString()])->save();

        $out = app(StudentCourseRegistrationService::class)->register($course->fresh(), 'Early Student', 'early@example.com', 'Correct-Horse-9-Battery');
        $this->assertTrue($out['created_account']);
    }

    /** Review follow-up: hidden warm-up rows always follow the course start date when the schedule is saved. */
    public function test_saving_puts_the_warm_up_on_the_day_before_the_start(): void
    {
        $faculty = Faculty::factory()->create();
        $wizard = $this->wizard($faculty)->set('cf_plus_enabled', false)->call('saveSetup');
        $course = Course::query()->where('name', 'Spring section')->firstOrFail();
        $rows = ScheduleRows::build('2027-01-11', '2027-04-30', 5);
        // Stale warm-up rows from an older draft, spread across the term.
        foreach ([0 => ['2027-01-11', '2027-01-20'], 1 => ['2027-01-21', '2027-01-30'], 2 => ['2027-01-31', '2027-02-09']] as $i => [$start, $end]) {
            $rows[$i] = ['quarter_number' => $i + 1, 'starts_on' => $start, 'ends_on' => $end, 'decision_deadline_at' => $end];
        }
        app(CourseConfigurationService::class)->saveSchedule($faculty, $course, $rows);

        $this->assertSame(['2027-01-10'], $course->quarters()->where('quarter_number', '<=', 3)->get()->map(fn ($q) => $q->starts_on->toDateString())->unique()->values()->all());
    }
}
