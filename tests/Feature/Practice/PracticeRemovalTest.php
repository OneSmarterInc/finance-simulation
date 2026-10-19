<?php

namespace Tests\Feature\Practice;

use App\Livewire\Faculty\Courses\PracticeRun;
use App\Models\Course;
use App\Models\Faculty;
use App\Services\Practice\PracticeRunService;
use App\Support\Dates;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Phase7B\BuildsPhase7BCourse;
use Tests\Support\Practice\BuildsPracticeSource;
use Tests\TestCase;

/**
 * U9 layer 6 (design note FF6, FF10): practice runs are removed for good,
 * on request or 30 days after they were last played. The tamper guards let
 * a practice run's records go and still protect every real course.
 */
class PracticeRemovalTest extends TestCase
{
    use BuildsPhase7BCourse, BuildsPracticeSource, RefreshDatabase;

    private Faculty $faculty;

    private const COURSE_TABLES = [
        'cf_plus_auction_results', 'cf_plus_offers', 'cf_plus_auction_economics', 'cf_plus_auction_economics_snapshots',
        'cf_plus_consultant_reports', 'cf_plus_disclosure_snapshots', 'firm_quarter_closes', 'firm_quarter_decisions',
        'firm_accounting_election_requests', 'firm_accounting_elections', 'firm_engine_results', 'firm_memberships',
        'enrollments', 'firms', 'course_quarters', 'course_faculty_roles',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->faculty = Faculty::query()->firstOrFail();
        config(['flexee.engine_version_for_new_courses' => 2]);
    }

    public function test_removing_a_settled_run_deletes_everything_it_made_and_nothing_else(): void
    {
        $runs = app(PracticeRunService::class);
        [$real] = $this->phase7bCourse('A real class');
        $realCounts = $this->counts($real->id);
        $source = $this->sourceCourse('Plus to remove', plus: true);
        $practice = $runs->playTo($this->faculty, $runs->create($this->faculty, $source), 99);
        $this->assertSame(3, DB::table('cf_plus_auction_results')->where('course_id', $practice->id)->count(), 'the run is settled');
        $userIds = DB::table('students')->whereIn('id', DB::table('enrollments')->where('course_id', $practice->id)->select('student_id'))->pluck('user_id');
        $this->assertCount(3, $userIds);

        $this->post(route('faculty.courses.practice.view-as', [$source, 'careful'])); // leaves a switch in the log
        $runs->remove($this->faculty, $practice);

        foreach ($this->counts($practice->id) as $table => $count) {
            $this->assertSame(0, $count, "{$table} still holds rows of the removed run");
        }
        $this->assertSame(0, DB::table('courses')->where('id', $practice->id)->count());
        $this->assertSame(0, DB::table('users')->whereIn('id', $userIds)->count(), 'practice students and their sign-ins are gone');
        $this->assertNotNull($source->fresh(), 'the course being rehearsed stays');
        $this->assertSame($realCounts, $this->counts($real->id), 'a real class is untouched');
    }

    public function test_the_guards_still_protect_real_courses_and_still_refuse_changes(): void
    {
        $runs = app(PracticeRunService::class);
        [$real] = $this->phase7bCourse('Protected class');
        $this->assertGuarded(fn () => DB::table('firm_engine_results')->where('course_id', $real->id)->where('quarter_number', 2)->delete());

        $practice = $runs->playTo($this->faculty, $runs->create($this->faculty, $this->sourceCourse('Guards', plus: true)), 7);
        $this->assertGuarded(fn () => DB::table('firm_engine_results')->where('course_id', $practice->id)->where('quarter_number', 2)->update(['quarter_number' => 2]));
        $this->assertGuarded(fn () => DB::table('cf_plus_auction_economics')->where('course_id', $practice->id)->update(['course_id' => $practice->id]));

        // The same records, on a course that is not a practice run, can't be deleted.
        DB::table('courses')->where('id', $practice->id)->update(['is_practice' => false]);
        $this->assertGuarded(fn () => DB::table('cf_plus_auction_economics')->where('course_id', $practice->id)->delete());
        $this->assertGuarded(fn () => DB::table('cf_plus_auction_economics_snapshots')->where('course_id', $practice->id)->delete());
        $this->assertGuarded(fn () => DB::table('firm_engine_results')->where('course_id', $practice->id)->where('quarter_number', 1)->delete());
    }

    public function test_start_again_leaves_no_hidden_copy_and_the_tidy_up_removes_old_runs(): void
    {
        $runs = app(PracticeRunService::class);
        $source = $this->sourceCourse('Tidy');
        $first = $runs->create($this->faculty, $source);
        $second = $runs->startAgain($this->faculty, $first);
        $this->assertSame(1, Course::withTrashed()->where('practice_source_course_id', $source->id)->count(), 'Start again removes the old run for good');

        // A copy hidden by the earlier Start again, a run 31 days old, and one 29 days old.
        $hidden = $runs->create($this->faculty, $this->sourceCourse('Hidden copy'));
        $hidden->delete();
        $old = $runs->create($this->faculty, $this->sourceCourse('Old'));
        $old->forceFill(['practice_last_played_at' => now()->subDays(31)])->save();
        $second->forceFill(['practice_last_played_at' => now()->subDays(29)])->save();

        $this->artisan('flexee:practice-tidy')->expectsOutput('Practice runs removed: 2')->assertExitCode(0);
        $this->assertSame(0, DB::table('courses')->whereIn('id', [$hidden->id, $old->id])->count());
        $this->assertNotNull($second->fresh(), 'a run played within 30 days stays');
        $this->assertSame(now()->addDay()->toDateString(), $runs->removedOn($second->fresh())->toDateString(), 'and is due to go 30 days after it was last played');
    }

    public function test_the_page_says_when_the_run_goes_and_removes_it_on_request(): void
    {
        $runs = app(PracticeRunService::class);
        $source = $this->sourceCourse('Page removal');
        $practice = $runs->create($this->faculty, $source);
        $this->actingAs($this->faculty->user);
        $date = $runs->removedOn($practice)->setTimezone(Dates::zone())->format('j F Y');

        Livewire::test(PracticeRun::class, ['course' => $source])
            ->assertSee("It's removed 30 days after it was last played, so on {$date} unless you play it again.", false)
            ->call('$set', 'confirmingRemove', true)
            ->assertSee('Remove this practice run?')
            ->call('remove')
            ->assertSee('Start a practice run')
            ->assertSee("It's removed 30 days after it was last played, or sooner if you remove it.", false);
        $this->assertSame(0, DB::table('courses')->where('id', $practice->id)->count());
    }

    /** @return array<string, int> */
    private function counts(string $courseId): array
    {
        $out = [];
        foreach (self::COURSE_TABLES as $table) {
            $out[$table] = DB::table($table)->where('course_id', $courseId)->count();
        }

        return $out;
    }

    private function assertGuarded(callable $write): void
    {
        DB::beginTransaction();
        try {
            $write();
            $this->fail('The guard should have refused this.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        } finally {
            DB::rollBack();
        }
    }
}
