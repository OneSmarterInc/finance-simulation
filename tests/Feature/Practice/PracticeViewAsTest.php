<?php

namespace Tests\Feature\Practice;

use App\Models\Course;
use App\Models\Faculty;
use App\Models\PracticeViewSwitch;
use App\Models\User;
use App\Services\Practice\PracticeRunService;
use App\Services\Practice\PracticeViewAs;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Practice\BuildsPracticeSource;
use Tests\TestCase;

/** U9 layer 3: faculty view a practice run through a reference team's eyes (FF5 a). */
class PracticeViewAsTest extends TestCase
{
    use BuildsPracticeSource, RefreshDatabase;

    private Faculty $faculty;

    private Course $source;

    private Course $practice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->faculty = Faculty::query()->firstOrFail();
        config(['flexee.engine_version_for_new_courses' => 2]);
        $this->source = $this->sourceCourse('Spring Section B');
        $runs = app(PracticeRunService::class);
        $this->practice = $runs->playTo($this->faculty, $runs->create($this->faculty, $this->source), 5);
    }

    public function test_faculty_view_a_team_and_come_back(): void
    {
        $careful = $this->practice->firms()->where('code', 'CAREFUL')->with('memberships.student')->firstOrFail();
        $studentUser = $careful->memberships->first()->student->user;

        $this->actingAs($this->faculty->user)
            ->get(route('faculty.courses.practice', $this->source))->assertOk()
            ->assertSee('View as Careful team')
            ->assertSee(route('faculty.courses.practice.view-as', [$this->source, 'careful']));

        $this->post(route('faculty.courses.practice.view-as', [$this->source, 'careful']))
            ->assertRedirect(route('firms.dashboard', $careful));
        $this->assertAuthenticatedAs($studentUser);

        $this->get(route('firms.dashboard', $careful))->assertOk()
            ->assertSee('viewing as Careful team')
            ->assertSee('Back to faculty view')
            ->assertSee($careful->name)
            ->assertDontSee('next scheduled check');
        // The next quarter is open, so faculty see its decision screen as the team does.
        $q6 = $this->practice->quarters()->where('quarter_number', 6)->firstOrFail();
        $this->get(route('student.decisions.workspace', [$careful, $q6]))->assertOk()
            ->assertSee('viewing as Careful team')->assertSee('Submit for the team');
        $this->get(route('firms.history', $careful))->assertOk()->assertSee('viewing as Careful team');
        $this->get(route('student.courses'))->assertOk()->assertSee('viewing as Careful team');

        $this->post(route('practice.back'))->assertRedirect(route('faculty.courses.practice', $this->source));
        $this->assertAuthenticatedAs($this->faculty->user);
        $this->assertNull(session(PracticeViewAs::SESSION_KEY));
        $this->get(route('faculty.courses.practice', $this->source))->assertOk()->assertDontSee('Back to faculty view');

        $this->assertSame(
            [[PracticeViewSwitch::VIEW_AS, 'careful'], [PracticeViewSwitch::BACK, 'careful']],
            PracticeViewSwitch::query()->orderBy('created_at')->orderBy('action', 'desc')->get()->map(fn ($s) => [$s->action, $s->team])->all(),
        );
        $this->assertSame([$this->faculty->id], PracticeViewSwitch::query()->distinct()->pluck('faculty_id')->all());
        $this->assertSame([$this->practice->id], PracticeViewSwitch::query()->distinct()->pluck('course_id')->all());
    }

    public function test_while_viewing_only_the_teams_pages_are_open(): void
    {
        $average = $this->practice->firms()->where('code', 'AVERAGE')->firstOrFail();
        $careful = $this->practice->firms()->where('code', 'CAREFUL')->firstOrFail();
        $this->actingAs($this->faculty->user)->post(route('faculty.courses.practice.view-as', [$this->source, 'average']));

        $this->get(route('profile.edit'))->assertRedirect(route('firms.dashboard', $average));
        $this->get(route('faculty.courses.practice', $this->source))->assertRedirect(route('firms.dashboard', $average));
        $this->get(route('dashboard'))->assertRedirect(route('firms.dashboard', $average));
        $this->get(route('firms.dashboard', $careful))->assertForbidden();

        // Livewire actions replay the check against the page they came from.
        $this->assertContains(\App\Http\Middleware\RestrictPracticeView::class, \Livewire\Livewire::getPersistentMiddleware());
        $this->withHeader('X-Livewire', '1');
        \Livewire\Livewire::test(\App\Livewire\Student\FirmDashboard::class, ['firm' => $average])->assertOk();
        $this->flushHeaders();

        // Signing out while viewing goes back to the faculty view instead.
        $this->post(route('logout'))->assertRedirect(route('practice.back'));
        $this->post(route('practice.back'))->assertRedirect(route('faculty.courses.practice', $this->source));
        $this->assertAuthenticatedAs($this->faculty->user);
    }

    public function test_only_the_owner_can_view_and_only_practice_runs(): void
    {
        $other = Faculty::factory()->create(['institution_id' => $this->faculty->institution_id]);
        $this->actingAs($other->user)->post(route('faculty.courses.practice.view-as', [$this->source, 'careful']))->assertForbidden();

        $student = User::query()->where('email', 'student3@example.com')->firstOrFail();
        $this->actingAs($student)->post(route('faculty.courses.practice.view-as', [$this->source, 'careful']))->assertForbidden();

        $this->actingAs($this->faculty->user);
        $this->post(route('faculty.courses.practice.view-as', [$this->practice, 'careful']))->assertForbidden();
        $this->post(route('faculty.courses.practice.view-as', [$this->sourceCourse('Never rehearsed'), 'careful']))->assertNotFound();
        $this->post('/faculty/courses/'.$this->source->id.'/practice/view-as/somebody')->assertNotFound();

        // Switching back with nothing to switch back from changes nothing.
        $this->post(route('practice.back'))->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($this->faculty->user);
        $this->assertSame(0, PracticeViewSwitch::query()->count());
    }

    public function test_a_practice_student_cannot_sign_in_with_a_password(): void
    {
        $user = $this->practice->firms()->where('code', 'CARELESS')->firstOrFail()->memberships()->firstOrFail()->student->user;

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors();
        $this->assertGuest();
    }
}
