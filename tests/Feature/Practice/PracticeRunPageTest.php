<?php

namespace Tests\Feature\Practice;

use App\Livewire\Faculty\Courses\CourseWizard;
use App\Livewire\Faculty\Courses\PracticeRun;
use App\Models\Faculty;
use App\Models\User;
use App\Services\Practice\PracticeRunService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Practice\BuildsPracticeSource;
use Tests\TestCase;

/** U9 layer 2: the Practice run page, the menu item and the wizard button. */
class PracticeRunPageTest extends TestCase
{
    use BuildsPracticeSource, RefreshDatabase;

    private Faculty $faculty;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->faculty = Faculty::query()->firstOrFail();
        config(['flexee.engine_version_for_new_courses' => 2]);
    }

    public function test_faculty_start_play_and_start_again_from_the_page(): void
    {
        $source = $this->sourceCourse('Spring Section B');
        $this->actingAs($this->faculty->user);

        $page = Livewire::test(PracticeRun::class, ['course' => $source])
            ->assertSee('Rehearse this course')
            ->assertSee('Start a practice run')
            ->call('start')
            ->assertHasNoErrors()
            ->assertSee('Played to Quarter 3 of 11')
            ->assertSee('Careful team')
            ->assertSee('Plans from last quarter&#039;s orders', false)
            ->assertSet('target', 4);

        $page->set('target', 6)->call('play')
            ->assertSee('Played to Quarter 6 of 11')
            ->assertSee('Careful leads in every quarter from 4 to 6')
            ->assertSee('Leading on value created')
            ->assertSet('target', 7);

        $page->call('playToEnd')->assertSee('Played to Quarter 11 of 11')->assertSee('The run has reached the last quarter.');

        $first = app(PracticeRunService::class)->current($source);
        $page->set('confirmingStartAgain', true)->assertSee('Keep this run')
            ->call('startAgain')
            ->assertSee('Played to Quarter 3 of 11')
            ->assertSet('confirmingStartAgain', false);
        $this->assertNotSame($first->id, app(PracticeRunService::class)->current($source)->id);
    }

    public function test_the_page_explains_when_a_course_cannot_be_rehearsed(): void
    {
        $this->actingAs($this->faculty->user);

        config(['flexee.engine_version_for_new_courses' => 1]);
        Livewire::test(PracticeRun::class, ['course' => $this->sourceCourse('Old engine')])
            ->assertSee('This course runs on the earlier engine')
            ->assertDontSee('Start a practice run');

        config(['flexee.engine_version_for_new_courses' => 2]);
        $bare = $this->sourceCourse('No areas yet');
        $bare->quarters()->where('quarter_number', '>=', 4)->each(fn ($q) => $q->enablements()->delete());
        Livewire::test(PracticeRun::class, ['course' => $bare->fresh()])
            ->call('start')
            ->assertSee('Save the course schedule and decision areas before rehearsing it.');
    }

    public function test_only_the_course_owner_can_open_the_page_and_never_on_a_practice_run(): void
    {
        $source = $this->sourceCourse('Owner only');
        $practice = app(PracticeRunService::class)->create($this->faculty, $source);

        $other = Faculty::factory()->create(['institution_id' => $this->faculty->institution_id]);
        $this->actingAs($other->user)->get(route('faculty.courses.practice', $source))->assertForbidden();
        $student = User::query()->where('email', 'student3@example.com')->firstOrFail();
        $this->actingAs($student)->get(route('faculty.courses.practice', $source))->assertForbidden();
        $this->actingAs($this->faculty->user)->get(route('faculty.courses.practice', $practice))->assertNotFound();
        $this->actingAs($this->faculty->user)->get(route('faculty.courses.practice', $source))->assertOk()->assertSee('Played to Quarter 3 of 11');
    }

    public function test_the_menu_and_the_wizard_lead_to_the_practice_run(): void
    {
        $source = $this->sourceCourse('Menu course');
        $this->actingAs($this->faculty->user);

        $this->get(route('faculty.courses.wizard', $source))->assertOk()
            ->assertSee(route('faculty.courses.practice', $source))
            ->assertSee('Practice run');
        Livewire::test(CourseWizard::class, ['course' => $source])->call('goToStep', 6)
            ->assertSee('Rehearse this course')
            ->assertSee(route('faculty.courses.practice', $source));

        config(['flexee.engine_version_for_new_courses' => 1]);
        $old = $this->sourceCourse('Old menu course');
        $this->get(route('faculty.courses.wizard', $old))->assertOk()
            ->assertDontSee(route('faculty.courses.practice', $old));
        Livewire::test(CourseWizard::class, ['course' => $old])->call('goToStep', 6)
            ->assertDontSee('Rehearse this course');
    }
}
