<?php

namespace Tests\Feature\Walkthrough;

use App\Livewire\Admin\Faculty as AdminFaculty;
use App\Livewire\Admin\Institutions;
use App\Livewire\Admin\Students as AdminStudents;
use App\Models\Admin;
use App\Models\Enrollment;
use App\Models\Faculty;
use App\Models\Institution;
use App\Models\Student;
use App\Models\User;
use App\Services\Firms\FirmAssignmentService;
use App\Services\Quarters\QuarterManagementService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Phase7B\BuildsPhase7BCourse;
use Tests\TestCase;

/** Walkthrough items 4 to 6, 28, 45 and decision N3. */
class AdminAndAccessTest extends TestCase
{
    use BuildsPhase7BCourse, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_admin_can_add_institutions_and_faculty(): void
    {
        $admin = Admin::query()->firstOrFail()->user;

        Livewire::actingAs($admin)->test(Institutions::class)->set('name', 'Riverside College')->call('create')->assertHasNoErrors();
        $institution = Institution::query()->where('name', 'Riverside College')->firstOrFail();

        Livewire::actingAs($admin)->test(AdminFaculty::class)
            ->set('name', 'Dana Lee')->set('email', 'dana@riverside.edu')->set('institutionId', $institution->id)
            ->call('create')->assertHasNoErrors()->assertSee('Dana Lee added');
        $this->assertTrue(Faculty::query()->where('institution_id', $institution->id)->whereHas('user', fn ($q) => $q->where('email', 'dana@riverside.edu'))->exists());
    }

    public function test_paid_list_marks_matches_and_reports_the_rest_and_unpaid_students_see_a_plain_page(): void
    {
        config(['flexee.require_payment' => true]);
        [$course, $firm, $student] = $this->phase7bCourse('Paid list');
        Enrollment::query()->create(['course_id' => $course->id, 'student_id' => $student->id, 'institution_id' => $course->institution_id]);

        $this->actingAs($student->user)->get(route('firms.dashboard', $firm))
            ->assertOk()->assertSee('Access opens once your registration is confirmed')->assertDontSee('Where your firm stands');

        $admin = Admin::query()->firstOrFail()->user;
        Livewire::actingAs($admin)->test(AdminStudents::class)
            ->set('paidCourseId', $course->id)
            ->set('paidList', $student->user->email.", nobody@example.org")
            ->call('markPaid')
            ->assertSee('1 marked paid')
            ->assertSee('nobody@example.org');

        $this->actingAs($student->user)->get(route('firms.dashboard', $firm))->assertOk()->assertSee('Where your firm stands');
    }

    public function test_deadlines_are_entered_and_shown_in_the_display_time_zone(): void
    {
        config(['flexee.display_timezone' => 'America/New_York']);

        $stored = \App\Support\Dates::deadlineFromInput('2026-10-20');
        $this->assertSame('2026-10-21 03:59:00', $stored->toDateTimeString());
        $this->assertSame('20 Oct 2026, 11:59 pm EDT', \App\Support\Dates::dateTime($stored));
        $this->assertSame('2026-10-20', \App\Support\Dates::localDate($stored));
    }

    public function test_an_unpaid_student_cannot_act_through_an_open_livewire_page(): void
    {
        config(['flexee.require_payment' => true]);
        [$course, $firm, $student, $q4] = $this->phase7bCourse('Gate on actions');
        Enrollment::query()->create(['course_id' => $course->id, 'student_id' => $student->id, 'institution_id' => $course->institution_id]);
        app(QuarterManagementService::class)->open($course->faculty, $q4);

        $this->actingAs($student->user)->get(route('student.decisions.workspace', [$firm, $q4]))
            ->assertOk()->assertSee('Access opens once your registration is confirmed')->assertDontSee('Save draft');
    }

    public function test_firms_cannot_be_added_once_quarter_four_opens(): void
    {
        [$course, , , $q4] = $this->phase7bCourse('Firm lock');
        app(QuarterManagementService::class)->open($course->faculty, $q4);

        $this->expectException(ValidationException::class);
        app(FirmAssignmentService::class)->createFirm($course->faculty, $course, 'LATE', 'Late Firm');
    }
}
