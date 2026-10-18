<?php

namespace Tests\Feature\Practice;

use App\Models\Course;
use App\Models\CourseQuarter;
use App\Models\Faculty;
use App\Models\FirmEngineResult;
use App\Models\Student;
use App\Models\User;
use App\Services\Accounting\FirmAccountingElectionService;
use App\Services\Practice\PracticeRunService;
use App\Services\Practice\ReferenceTeams;
use App\Services\Quarters\QuarterManagementService;
use App\Services\Quarters\QuarterScheduler;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\Practice\BuildsPracticeSource;
use Tests\TestCase;

/**
 * U9 layer 1: a practice run is a private copy of a faculty member's course,
 * played by the three reference teams through the class's real pipeline,
 * and kept out of every list, count and the scheduler.
 */
class PracticeRunTest extends TestCase
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

    public function test_a_practice_run_copies_the_course_and_warms_up_three_reference_teams(): void
    {
        $source = $this->sourceCourse('Spring Section B');
        $practice = app(PracticeRunService::class)->create($this->faculty, $source);

        $this->assertTrue($practice->is_practice);
        $this->assertSame($source->id, $practice->practice_source_course_id);
        $this->assertSame('Practice run: Spring Section B', $practice->name);
        $this->assertSame(Course::STATUS_DEPLOYED, $practice->status);
        $this->assertSame(2, $practice->engineVersion());
        $this->assertSame($source->startingElections(), $practice->startingElections());
        $this->assertFalse((bool) $practice->registration_enabled);

        // Same schedule and the same areas opening in the same quarters.
        $this->assertSame($this->schedule($source), $this->schedule($practice));
        $this->assertSame($this->matrix($source), $this->matrix($practice));

        $firms = $practice->firms()->with('memberships.student.user')->orderBy('code')->get();
        $this->assertSame(['AVERAGE', 'CAREFUL', 'CARELESS'], $firms->pluck('code')->all());
        foreach ($firms as $firm) {
            $this->assertSame('initialized', $firm->warmup_status);
            $this->assertSame([1, 2, 3], $firm->engineResults()->orderBy('quarter_number')->pluck('quarter_number')->all());
            $this->assertSame([FirmEngineResult::ENGINE_CONTRACT_V2], $firm->engineResults()->distinct()->pluck('engine_contract')->all());
            $student = $firm->memberships->sole()->student;
            $this->assertTrue((bool) $student->is_practice);
            $this->assertStringEndsWith('@practice.flexee.invalid', $student->user->email);
        }

        // The three teams face one market, so they differ only by their rules.
        $markets = $firms->map(fn ($firm) => $firm->engineResults()->orderBy('quarter_number')->get()->map(fn ($r) => $r->intermediates['market'])->all());
        $this->assertEquals($markets[0], $markets[1]);
        $this->assertEquals($markets[0], $markets[2]);

        $this->assertSame($practice->id, app(PracticeRunService::class)->current($source)?->id);
        $this->assertSame(3, app(PracticeRunService::class)->playedTo($practice));
    }

    public function test_playing_forward_closes_each_quarter_with_every_team_deciding_by_its_rules(): void
    {
        $runs = app(PracticeRunService::class);
        $practice = $runs->create($this->faculty, $this->sourceCourse('Play forward'));

        $practice = $runs->playTo($this->faculty, $practice, 6);
        $this->assertSame(6, $runs->playedTo($practice));
        $this->assertSame(CourseQuarter::LOCKED, $practice->quarters()->where('quarter_number', 7)->value('state'));

        $firms = $practice->firms()->get()->keyBy(fn ($firm) => strtolower($firm->code));
        foreach ($firms as $firm) {
            $this->assertSame([1, 2, 3, 4, 5, 6], $firm->engineResults()->orderBy('quarter_number')->pluck('quarter_number')->all());
        }

        // Quarter 6: pricing and marketing are open, so the teams' prices and budgets are what they chose.
        $q6 = $practice->quarters()->where('quarter_number', 6)->firstOrFail();
        $resolved = fn (string $team): array => $firms[$team]->quarterDecisions()->where('course_quarter_id', $q6->id)->firstOrFail()->resolved_payload;
        $this->assertEquals(0.12, $resolved('careless')['marketing']['marketing_budget_pct']);
        $this->assertEquals(0.05, $resolved('careful')['marketing']['marketing_budget_pct']);
        $this->assertLessThan($resolved('careful')['pricing']['price_p1_r1'], $resolved('careless')['pricing']['price_p1_r1']);
        $this->assertSame('SUP006', $resolved('careful')['sourcing']['primary_supplier']);
        $this->assertSame('SUP004', $resolved('careless')['sourcing']['primary_supplier']);

        // Careless pays the largest dividend allowed, Average none.
        $this->assertGreaterThan(0, (float) $firms['careless']->quarterDecisions()->where('course_quarter_id', $q6->id)->value('dividend'));
        $this->assertEquals(0, (float) $firms['average']->quarterDecisions()->where('course_quarter_id', $q6->id)->value('dividend'));

        // Playing to the end, then asking again, does nothing more.
        $practice = $runs->playTo($this->faculty, $practice, 99);
        $this->assertSame(11, $runs->playedTo($practice));
        $this->assertSame(11, $runs->playTo($this->faculty, $practice, 11)->quarters()->where('state', CourseQuarter::CLOSED)->max('quarter_number'));

        // The calibration the fixtures show holds in this course's market too: Careful leads on value created every quarter.
        $values = $runs->valueCreated($practice);
        $this->assertSame(range(4, 11), array_keys($values));
        foreach ($values as $quarter => $value) {
            arsort($value);
            $this->assertSame('careful', array_key_first($value), "Quarter {$quarter}: ".json_encode($value));
        }
    }

    public function test_with_method_change_requests_on_the_teams_methods_are_set_directly(): void
    {
        $runs = app(PracticeRunService::class);
        $practice = $runs->playTo($this->faculty, $runs->create($this->faculty, $this->sourceCourse('Method changes', methodChanges: true)), 6);
        $q6 = $practice->quarters()->where('quarter_number', 6)->firstOrFail();
        $effective = fn (string $code): array => app(FirmAccountingElectionService::class)->effective($practice->firms()->where('code', $code)->firstOrFail(), $q6);

        $this->assertSame('double_declining_balance', $effective('CARELESS')['depreciation_method']);
        $this->assertSame('units_of_production', $effective('AVERAGE')['depreciation_method']);
        $this->assertSame('fob_destination', $effective('AVERAGE')['shipping_terms']);
        $this->assertSame($practice->startingElections()['depreciation_method'], $effective('CAREFUL')['depreciation_method']);
        $this->assertSame(6, $runs->playedTo($practice));
    }

    public function test_the_scheduler_never_plays_a_practice_run(): void
    {
        $runs = app(PracticeRunService::class);
        $practice = $runs->create($this->faculty, $this->sourceCourse('Not by the clock'));
        $q4 = $practice->quarters()->where('quarter_number', 4)->firstOrFail();
        app(QuarterManagementService::class)->open($this->faculty, $q4);
        $q4->forceFill(['decision_deadline_at' => now()->subDay(), 'opened_at' => now()->subWeek()])->save();

        $this->travel(1)->hours();
        $log = app(QuarterScheduler::class)->tick();

        $this->assertSame(CourseQuarter::OPEN, $q4->fresh()->state);
        $this->assertEmpty(array_filter($log, fn ($line) => str_contains($line, 'Practice run')));
    }

    public function test_practice_runs_and_their_students_stay_out_of_lists_and_counts(): void
    {
        $source = $this->sourceCourse('Visible course');
        $practice = app(PracticeRunService::class)->create($this->faculty, $source);

        $this->assertFalse($this->faculty->teachingCourses()->contains('id', $practice->id));
        $this->assertTrue($this->faculty->teachingCourses()->contains('id', $source->id));
        $this->assertFalse(Course::query()->real()->whereKey($practice->id)->exists());

        $this->actingAs($this->faculty->user)->get(route('dashboard'))->assertOk()
            ->assertSee('Visible course')->assertDontSee('Practice run: Visible course');

        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $this->actingAs($admin)->get(route('admin.courses'))->assertOk()->assertDontSee('Practice run: Visible course');
        $this->actingAs($admin)->get(route('admin.students'))->assertOk()->assertDontSee('practice.flexee.invalid')->assertDontSee('Careful team (practice)');

        $this->assertSame(3, Student::query()->where('is_practice', true)->count());
    }

    public function test_starting_again_replaces_the_run_with_a_fresh_copy(): void
    {
        $runs = app(PracticeRunService::class);
        $source = $this->sourceCourse('Again');
        $first = $runs->playTo($this->faculty, $runs->create($this->faculty, $source), 5);
        $firstQ5 = $first->firms()->where('code', 'CAREFUL')->firstOrFail()->engineResults()->where('quarter_number', 5)->firstOrFail()->fields;

        $second = $runs->startAgain($this->faculty, $first);

        $this->assertSoftDeleted('courses', ['id' => $first->id]);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($second->id, $runs->current($source)?->id);
        $this->assertSame(3, $runs->playedTo($second));

        // Every run of a course plays the same way: its market is drawn from the course it rehearses and the team's name, not from new ids.
        $runs->playTo($this->faculty, $second, 5);
        $secondQ5 = $second->firms()->where('code', 'CAREFUL')->firstOrFail()->engineResults()->where('quarter_number', 5)->firstOrFail()->fields;
        $this->assertEquals($firstQ5, $secondQ5);
    }

    public function test_only_a_course_on_the_current_engine_can_be_rehearsed(): void
    {
        $runs = app(PracticeRunService::class);

        config(['flexee.engine_version_for_new_courses' => 1]);
        try {
            $runs->create($this->faculty, $this->sourceCourse('Old engine'));
            $this->fail('a version 1 course was rehearsed');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('current engine', $e->errors()['practice'][0]);
        }

        config(['flexee.engine_version_for_new_courses' => 2]);
        $practice = $runs->create($this->faculty, $this->sourceCourse('New engine'));
        $this->expectException(ValidationException::class);
        $runs->create($this->faculty, $practice);
    }

    public function test_the_practice_command_rehearses_a_course_for_release_checks(): void
    {
        $source = $this->sourceCourse('Release check');

        $this->artisan('flexee:practice', ['course' => $source->id, '--to' => 5])
            ->expectsOutputToContain('Practice run: Release check: played to Quarter 5')
            ->doesntExpectOutputToContain('calibration problem')
            ->assertSuccessful();
        $this->artisan('flexee:practice', ['course' => $source->id, '--to' => 6])->assertSuccessful();
        $this->assertSame(6, app(PracticeRunService::class)->playedTo(app(PracticeRunService::class)->current($source)));

        $this->artisan('flexee:practice', ['course' => $source->id, '--to' => 4, '--again' => true])->assertSuccessful();
        $this->assertSame(4, app(PracticeRunService::class)->playedTo(app(PracticeRunService::class)->current($source)));
        $this->assertSame(1, Course::query()->where('practice_source_course_id', $source->id)->count(), 'the old run is gone');
    }

    public function test_the_team_rules_have_a_sentence_and_a_name_for_every_team(): void
    {
        foreach (ReferenceTeams::TEAMS as $team) {
            $this->assertNotEmpty(ReferenceTeams::RULES[$team]);
            $this->assertNotEmpty(ReferenceTeams::NAMES[$team]);
            $this->assertArrayHasKey($team, ReferenceTeams::DIVIDEND_SHARE);
        }
    }

    /** @return list<array<string, string>> */
    private function schedule(Course $course): array
    {
        return $course->quarters()->orderBy('quarter_number')->get()->map(fn (CourseQuarter $q): array => [
            'n' => (string) $q->quarter_number, 'start' => $q->starts_on->toDateString(), 'end' => $q->ends_on->toDateString(), 'deadline' => (string) $q->decision_deadline_at?->toIso8601String(),
        ])->all();
    }

    /** @return array<int, array<string, bool>> */
    private function matrix(Course $course): array
    {
        $out = [];
        foreach ($course->quarters()->with('enablements.category')->orderBy('quarter_number')->get() as $quarter) {
            foreach ($quarter->enablements->sortBy('category.code') as $row) {
                $out[$quarter->quarter_number][$row->category->code] = (bool) $row->enabled;
            }
        }

        return $out;
    }
}
