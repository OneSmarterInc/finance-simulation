<?php

namespace Tests\Feature\Walkthrough;

use App\Models\Admin;
use App\Models\Faculty;
use App\Models\Firm;
use App\Models\FirmAccountingElectionRequest;
use App\Models\User;
use App\Services\Courses\CourseStatus;
use App\Services\Practice\PracticeRunService;
use App\Services\Quarters\QuarterManagementService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase7B\BuildsPhase7BCourse;
use Tests\Support\Practice\BuildsPracticeSource;
use Tests\TestCase;

/**
 * The live-site walkthrough of 18 October 2026
 * (CF_V3_Live_Walkthrough_Change_List.md, items L1 to L22).
 */
class LiveWalkthroughTest extends TestCase
{
    use BuildsPhase7BCourse, BuildsPracticeSource, RefreshDatabase;

    private Faculty $faculty;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->faculty = Faculty::query()->firstOrFail();
    }

    /** L4 to L6: breadcrumbs that start with the area, and the person's name and role at the top right. */
    public function test_every_signed_in_page_says_where_you_are_and_who_you_are(): void
    {
        [$course, $firm, $student, $q4] = $this->phase7bCourse('Course 02');
        app(QuarterManagementService::class)->open($course->faculty, $q4);
        $name = $this->faculty->user->name;

        $this->actingAs($this->faculty->user)->get(route('faculty.courses.quarters', $course))->assertOk()
            ->assertSeeInOrder(['data-test="top-bar"', '>Faculty</a>', '>Course 02</a>', 'aria-current="page"', 'Quarters'], false)
            ->assertSeeInOrder(['data-test="top-bar-user"', $name, 'Faculty'], false);
        $this->get(route('firms.history', $firm))->assertOk()
            ->assertSeeInOrder(['>Faculty</a>', '>Course 02</a>', '>Firms</a>', '>'.e($firm->name).'</a>', 'History'], false);
        $this->get(route('dashboard'))->assertOk()->assertSeeInOrder(['>Faculty</a>', 'aria-current="page"', 'Dashboard'], false);

        $this->actingAs($student->user)->get(route('student.decisions.workspace', [$firm, $q4]))->assertOk()
            ->assertSeeInOrder(['>Student</a>', '>Course 02</a>', '>'.e($firm->name).'</a>', 'Quarter 4 decisions'], false)
            ->assertSeeInOrder(['data-test="top-bar-user"', e($student->user->name), 'Student'], false);

        $admin = User::factory()->create();
        Admin::query()->create(['user_id' => $admin->id]);
        $this->actingAs($admin)->get(route('admin.courses'))->assertOk()
            ->assertSeeInOrder(['>Admin</a>', 'aria-current="page"', 'Courses'], false);
    }

    /** L5: viewing a practice run as a team says so beside the name. */
    public function test_viewing_a_practice_run_as_a_team_shows_in_the_role(): void
    {
        config(['flexee.engine_version_for_new_courses' => 2]);
        $source = $this->sourceCourse('Rehearsed');
        app(PracticeRunService::class)->create($this->faculty, $source);
        $this->actingAs($this->faculty->user)->post(route('faculty.courses.practice.view-as', [$source, 'careful']))->assertRedirect();
        $this->followingRedirects()->get(route('student.courses'))->assertOk()->assertSee('Faculty, viewing as Careful team');
    }

    /** L1: a New course button on the dashboard, and an empty state that offers one. */
    public function test_faculty_can_start_a_new_course_from_the_dashboard(): void
    {
        $this->actingAs($this->faculty->user)->get(route('dashboard'))->assertOk()
            ->assertSee('data-test="new-course"', false)
            ->assertSee(route('faculty.courses.create'), false)
            ->assertSee('Set up your first course');

        $this->phase7bCourse('Has a course');
        $this->get(route('dashboard'))->assertOk()->assertSee('data-test="new-course"', false)->assertDontSee('Set up your first course');
    }

    /** L7, L12, L18: once a stated date has passed, the screens say so instead of naming it as if ahead. */
    public function test_a_passed_deadline_is_worded_as_passed_and_the_form_turns_read_only(): void
    {
        [$course, $firm, $student, $q4, $q5] = $this->phase7bCourse('Course 02');
        app(QuarterManagementService::class)->open($course->faculty, $q4);
        $course->forceFill(['accounting_election_enabled' => true])->save();
        $q4->forceFill(['opened_at' => now()->subDays(30), 'decision_deadline_at' => now()->subDays(14)])->save();
        $q5->forceFill(['decision_deadline_at' => now()->subDays(10)])->save();
        FirmAccountingElectionRequest::query()->create([
            'firm_id' => $firm->id, 'course_id' => $course->id, 'course_quarter_id' => $q4->id, 'student_id' => $student->id,
            'proposed_elections' => ['depreciation_method' => 'double_declining_balance'], 'previous_elections' => ['depreciation_method' => 'straight_line'],
            'status' => 'pending', 'effective_quarter_number' => 4,
        ]);

        $this->actingAs($student->user)->get(route('firms.dashboard', $firm))->assertOk()
            ->assertSee('Quarter 4 is waiting to close.')
            ->assertSee("Your instructor will either close the quarter, using last quarter's decisions for any team that didn't submit, or set a new deadline.")
            ->assertDontSee('Decisions are due');
        $this->get(route('student.decisions.workspace', [$firm, $q4]))->assertOk()
            ->assertSee("Quarter 4's deadline has passed, so decisions can't be changed.")
            ->assertDontSee('Submit for the team')
            ->assertDontSee('If nothing is submitted by the deadline');
        $this->get(route('student.courses'))->assertOk()->assertSee("has passed. It's waiting to close.", false);

        $this->actingAs($this->faculty->user)->get(route('dashboard'))->assertOk()
            ->assertSee("Quarter 4's deadline passed 14 days ago and the quarter hasn't closed.");
        $this->get(route('faculty.courses.quarters', $course))->assertOk()
            ->assertSee('The deadline passed 14 days ago and the quarter hasn')
            ->assertSee("Opens as soon as Quarter 4 closes. Quarter 4's deadline")
            ->assertSee('so when it opens it waits for a new deadline');
        $firm->forceFill(['warmup_status' => Firm::WARMUP_INITIALIZED])->save();
        $this->get(route('faculty.courses.firms', $course))->assertOk()->assertSee('Q4: Deadline passed');
        $this->get(route('faculty.courses.accounting-election-requests', $course))->assertOk()
            ->assertSee('Quarter 4 was due to close on')
            ->assertSee('and is still open');

        $course->forceFill(['cf_plus_enabled' => true, 'build_quarters' => 1, 'adversarial_quarters' => 1, 'post_bid_quarters' => 1])->save();
        $this->assertSame("Opens with Quarter 5. Quarter 4's deadline has passed, so the stage opens as soon as Quarter 4 closes.", CourseStatus::for($course->fresh())->evaluationOpensText());
    }
}
