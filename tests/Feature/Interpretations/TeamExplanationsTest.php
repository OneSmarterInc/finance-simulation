<?php

namespace Tests\Feature\Interpretations;

use App\Livewire\Faculty\Courses\TeamExplanations;
use App\Livewire\Student\Decisions\DecisionWorkspace;
use App\Models\Faculty;
use App\Models\FirmQuarterInterpretation;
use App\Services\Decisions\FirmDecisionService;
use App\Services\Interpretations\InterpretationService;
use App\Services\Practice\PracticeRunService;
use App\Services\Quarters\QuarterManagementService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Phase7B\BuildsPhase7BCourse;
use Tests\Support\Practice\BuildsPracticeSource;
use Tests\TestCase;

/**
 * U11 (IN1 to IN3, decided 19 October 2026): the team explains its last
 * closed quarter before it submits the next quarter's decisions; faculty
 * read, comment and download.
 */
class TeamExplanationsTest extends TestCase
{
    use BuildsPhase7BCourse, BuildsPracticeSource, RefreshDatabase;

    private const ANSWERS = [
        'changed' => 'Net revenue rose from $58.9 million to $75.1 million, and cash rose by $7.6 million.',
        'why' => 'Customers ordered more in the busy season, and we forecast closer to their orders than before.',
        'notes' => 'The inventory note shows goods on their way from India, which we own under FOB shipping point.',
    ];

    private Faculty $faculty;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->faculty = Faculty::query()->firstOrFail();
    }

    public function test_a_team_explains_quarter_three_before_it_can_submit_quarter_four(): void
    {
        [$course, $firm, $student, $q4] = $this->phase7bCourse('Explained');
        $course->forceFill(['interpretations_required' => true])->save();
        app(QuarterManagementService::class)->open($course->faculty, $q4);
        $decisions = app(FirmDecisionService::class);
        $explanations = app(InterpretationService::class);
        $this->assertSame(3, $explanations->explainedQuarter($firm->fresh(), $q4->fresh())->quarter_number);

        $decisions->saveDraft($student, $firm, $q4->fresh(), $this->completePayload(), 0);
        try {
            $decisions->submit($student, $firm, $q4->fresh(), $this->completePayload(), 0);
            $this->fail('Decisions must wait for the explanation.');
        } catch (ValidationException $e) {
            $this->assertSame("Submit your team's explanation of Quarter 3 first. Then you can submit Quarter 4's decisions.", $e->errors()['interpretation'][0]);
        }

        $explanations->saveDraft($student, $firm, $q4->fresh(), ['changed' => 'Revenue rose.']);
        $this->assertFalse($explanations->satisfied($firm, $q4->fresh()), 'a draft is not enough');
        try {
            $explanations->submit($student, $firm, $q4->fresh(), ['changed' => 'Revenue rose.', 'why' => '', 'notes' => '']);
            $this->fail('Short answers must be refused.');
        } catch (ValidationException $e) {
            $this->assertSame(['explanation.changed', 'explanation.why', 'explanation.notes'], array_keys($e->errors()));
        }

        $row = $explanations->submit($student, $firm, $q4->fresh(), self::ANSWERS);
        $this->assertTrue($row->isSubmitted());
        $this->assertSame($student->id, $row->submitted_by_student_id);
        $this->assertSame('submitted', $decisions->submit($student, $firm, $q4->fresh(), $this->completePayload(), 0)->state);

        // Past the deadline the explanation is read-only.
        $q4->forceFill(['opened_at' => now()->subDays(30), 'decision_deadline_at' => now()->subDay()])->save();
        $this->expectException(ValidationException::class);
        $explanations->saveDraft($student, $firm, $q4->fresh(), self::ANSWERS);
    }

    public function test_the_requirement_can_be_switched_off_and_running_courses_and_practice_runs_skip_it(): void
    {
        [$course, $firm, $student, $q4] = $this->phase7bCourse('Running course');
        $this->assertFalse((bool) $course->fresh()->interpretations_required, 'courses already running keep going without it');
        app(QuarterManagementService::class)->open($course->faculty, $q4);
        $this->assertTrue(app(InterpretationService::class)->satisfied($firm, $q4->fresh()));

        config(['flexee.engine_version_for_new_courses' => 2]);
        $source = $this->sourceCourse('New course');
        $this->assertTrue((bool) $source->interpretations_required, 'new courses ask for explanations');
        $runs = app(PracticeRunService::class);
        $practice = $runs->playTo($this->faculty, $runs->create($this->faculty, $source), 5);
        $this->assertSame(5, $runs->playedTo($practice), 'the reference teams play on without writing');

        $this->actingAs($this->faculty->user);
        Livewire::test(TeamExplanations::class, ['course' => $course])
            ->assertSee('Explanations are switched off for this course')
            ->call('toggleRequired')
            ->assertSee('Teams explain each quarter before they decide');
        $this->assertTrue((bool) $course->fresh()->interpretations_required);
        $this->assertFalse(app(InterpretationService::class)->satisfied($firm->fresh(), $q4->fresh()));
    }

    public function test_the_decisions_page_asks_for_the_explanation_first_and_unlocks_submit(): void
    {
        [$course, $firm, $student, $q4] = $this->phase7bCourse('Screens');
        $course->forceFill(['interpretations_required' => true])->save();
        app(QuarterManagementService::class)->open($course->faculty, $q4);

        $this->actingAs($student->user)->get(route('firms.dashboard', $firm))->assertOk()->assertSee('data-test="explain-first"', false);
        $page = Livewire::actingAs($student->user)->test(DecisionWorkspace::class, ['firm' => $firm, 'quarter' => $q4->fresh()])
            ->assertSee('Explain Quarter 3 before you decide')
            ->assertSee('What changed?')->assertSee('Why did it change?')->assertSee('What do the notes show?')
            ->assertSee('data-test="decisions-locked"', false)
            ->assertSee("Submit your team's explanation of Quarter 3 first.", false)
            ->set('explanation.changed', 'Too short.')
            ->call('submitExplanation')
            ->assertHasErrors(['explanation.changed']);
        $page->set('explanation', self::ANSWERS)
            ->call('submitExplanation')
            ->assertHasNoErrors()
            ->assertSee('Explanation submitted for your team. You can now submit the decisions.')
            ->assertDontSee('data-test="decisions-locked"', false)
            ->assertSee('Submit for the team');
        $this->actingAs($student->user)->get(route('firms.dashboard', $firm))->assertOk()->assertDontSee('data-test="explain-first"', false);
    }

    public function test_faculty_read_comment_and_download_and_the_team_sees_the_comment(): void
    {
        [$course, $firm, $student, $q4] = $this->phase7bCourse('Course 02');
        $course->forceFill(['interpretations_required' => true])->save();
        app(QuarterManagementService::class)->open($course->faculty, $q4);
        app(InterpretationService::class)->submit($student, $firm, $q4->fresh(), self::ANSWERS);

        $this->actingAs($this->faculty->user)->get(route('faculty.courses.explanations', $course))->assertOk()
            ->assertSee('Team explanations')
            ->assertSee('1 of 1 firm submitted an explanation of Quarter 3.')
            ->assertSee(self::ANSWERS['notes'])
            ->assertSeeInOrder(['data-test="top-bar"', 'aria-current="page"', 'Team explanations'], false);
        $comment = 'Good use of the inventory note. Next time, say how much of the cash went into stock.';
        Livewire::test(TeamExplanations::class, ['course' => $course])
            ->set("comments.{$firm->id}", $comment)
            ->call('saveComment', $firm->id)
            ->assertSee('sees it on its decisions and History pages');
        $this->assertSame($comment, FirmQuarterInterpretation::query()->firstOrFail()->faculty_comment);

        $this->actingAs($student->user)->get(route('student.decisions.workspace', [$firm, $q4]))->assertOk()
            ->assertSee("Your instructor's comment", false)->assertSee($comment);
        $this->get(route('firms.history', $firm))->assertOk()
            ->assertSee('data-test="history-explanations"', false)->assertSee(self::ANSWERS['changed'])->assertSee($comment);
        $this->get(route('faculty.courses.explanations', $course))->assertForbidden();
        $this->get(route('faculty.courses.explanations.download', $course))->assertForbidden();

        $response = $this->actingAs($this->faculty->user)->get(route('faculty.courses.explanations.download', $course))->assertOk();
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($response->getFile()->getPathname()) === true);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertStringContainsString(htmlspecialchars(self::ANSWERS['why'], ENT_XML1 | ENT_QUOTES), $sheet);
        $this->assertStringContainsString(htmlspecialchars($comment, ENT_XML1 | ENT_QUOTES), $sheet);
        $zip->close();
    }
}
