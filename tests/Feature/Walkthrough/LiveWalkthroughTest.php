<?php

namespace Tests\Feature\Walkthrough;

use App\Livewire\Faculty\Courses\AccountingElectionRequests;
use App\Livewire\Faculty\Courses\QuarterResults;
use App\Models\Admin;
use App\Models\Faculty;
use App\Models\Firm;
use App\Models\FirmAccountingElectionRequest;
use App\Models\FirmQuarterClose;
use App\Models\Student;
use App\Models\User;
use App\Services\Accounting\FirmAccountingElectionRequestService;
use App\Services\Courses\CourseStatus;
use App\Services\Decisions\FirmDecisionService;
use App\Services\Practice\PracticeRunService;
use App\Services\Quarters\FirmQuarterCloseService;
use App\Services\Quarters\QuarterManagementService;
use App\Services\Warmup\InheritedWarmupHistoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
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

    /** L9, L10, L11, L17: one name, a plainer opening line, and asking the team for a missing reason. */
    public function test_accounting_methods_have_one_name_and_a_request_without_a_reason_can_go_back_for_one(): void
    {
        [$course, $firm, $student, $q4] = $this->phase7bCourse('Course 02');
        app(QuarterManagementService::class)->open($course->faculty, $q4);
        $course->forceFill(['accounting_election_enabled' => true])->save();
        $request = FirmAccountingElectionRequest::query()->create([
            'firm_id' => $firm->id, 'course_id' => $course->id, 'course_quarter_id' => $q4->id, 'student_id' => $student->id,
            'proposed_elections' => ['depreciation_method' => 'double_declining_balance'], 'previous_elections' => ['depreciation_method' => 'straight_line'],
            'status' => 'pending', 'effective_quarter_number' => 4,
        ]);

        $this->actingAs($student->user)->get(route('student.accounting-election', [$firm, $q4]))->assertOk()
            ->assertSee('Accounting methods')->assertDontSee('Accounting elections')
            ->assertSee('These are the methods your firm uses to measure and report its results. Changing one moves the reported figures, and cash only through the tax on them. Your statements will show you how.')
            ->assertSee('No reason given: filed before reasons were required.');

        $note = 'Please file again with your reason: why does double-declining balance describe your firm better than straight-line?';
        $this->actingAs($this->faculty->user)->get(route('faculty.courses.accounting-election-requests', $course))->assertOk()
            ->assertSee('Accounting method requests')->assertDontSee('Accounting elections')
            ->assertSee('data-test="ask-for-reason"', false)
            ->assertSee($note);
        Livewire::test(AccountingElectionRequests::class, ['course' => $course])->call('askForReason', $request->id);
        $this->assertSame(FirmAccountingElectionRequestService::RETURNED, $request->fresh()->status);
        $this->assertSame($note, $request->fresh()->faculty_note);

        $this->actingAs($student->user)->get(route('student.accounting-election', [$firm, $q4]))->assertOk()
            ->assertSee('Your instructor asked for your reason')
            ->assertSee($note)
            ->assertSee('Start a request');
        $again = app(FirmAccountingElectionRequestService::class)->submit($student, $firm, $q4,
            ['depreciation_method' => 'double_declining_balance', 'shipping_terms' => 'fob_shipping_point', 'bad_debt_method' => 'percentage_of_receivables', 'warranty_method' => 'percentage_of_sales', 'excise_tax_treatment' => 'cogs_inclusion'],
            'Our plant loses most of its productive value early, so double-declining balance matches how it wears.');
        $this->assertSame('pending', $again->status, 'the team can file again in the same quarter');
    }

    /** L14, ED17 a: the dividend limit comes from the opening position, and the close cuts what the quarter can't fund. */
    public function test_the_dividend_limit_comes_from_the_opening_position_and_the_close_cuts_what_it_cant_fund(): void
    {
        [$course, $firm, $student, $q4] = $this->phase7bCourse('Dividends');
        $quarters = app(QuarterManagementService::class);
        $quarters->open($course->faculty, $q4);
        $decisions = app(FirmDecisionService::class);
        $opening = $decisions->opening($firm, $q4);
        $buffer = (float) $decisions->parameters($firm)['minimum_cash_buffer'];
        $expected = round(min(max(0.0, $opening->retainedEarnings), max(0.0, $opening->cash - $buffer)), 2);

        $low = $this->completePayload();
        $high = $this->completePayload(50000);
        $this->assertSame($expected, $decisions->dividendLimit($firm, $q4, $low)->maximum);
        $this->assertSame($expected, $decisions->dividendLimit($firm, $q4, $high)->maximum, 'the limit does not move with the decisions');

        $this->actingAs($student->user)->get(route('student.decisions.workspace', [$firm, $q4]))->assertOk()
            ->assertSee('Most you can declare:')->assertSee('$'.number_format($expected, 2))
            ->assertSee('so it stays the same whatever you decide above');

        // A declared dividend the quarter can't fund is cut at the close instead of failing it.
        $decisions->submit($student, $firm, $q4, $low, 0);
        $firm->quarterDecisions()->where('course_quarter_id', $q4->id)->update(['dividend' => 999999999999]);
        Queue::fake();
        $quarters->queueClose($course->faculty, $q4);
        app(FirmQuarterCloseService::class)->close(FirmQuarterClose::query()->firstOrFail());
        $result = $firm->engineResults()->where('quarter_number', 4)->firstOrFail();
        $this->assertEqualsWithDelta((float) $result->intermediates['maximum_dividend'], (float) $result->intermediates['dividend'], 0.001);
        $this->assertLessThan(999999999999, (float) $result->intermediates['dividend']);
    }

    /** L16: "All firms, every quarter" for one measure with the best firm marked, and the .xlsx download. */
    public function test_results_compare_every_firm_and_download_as_a_spreadsheet(): void
    {
        [$course, $firm] = $this->phase7bCourse('Course 02');
        $other = Firm::factory()->create(['course_id' => $course->id, 'institution_id' => $course->institution_id, 'name' => 'Beta']);
        app(InheritedWarmupHistoryService::class)->initialize($other);
        $this->actingAs($this->faculty->user);

        Livewire::test(QuarterResults::class, ['course' => $course])
            ->call('showCompare')
            ->assertSee('data-test="results-compare"', false)
            ->assertSee('Value created (equity plus dividends paid from Quarter 4)')
            ->assertSee('Best this quarter')
            ->call('showCompare', 'units_in_stock')
            ->assertSet('measure', 'units_in_stock')
            ->assertSee('Fewer is better here');

        $this->get(route('faculty.courses.quarter-results', $course))->assertOk()->assertSee('data-test="results-download"', false);
        $response = $this->get(route('faculty.courses.quarter-results.download', $course))->assertOk();
        $this->assertStringContainsString('Course-02-results-', $response->headers->get('content-disposition'));
        $path = $response->getFile()->getPathname();
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $workbook = $zip->getFromName('xl/workbook.xml');
        foreach (['About', 'Summary', 'Income statement', 'Balance sheet', 'Cash flow statement', 'Revenue note', 'Inventory note'] as $sheet) {
            $this->assertStringContainsString('name="'.$sheet.'"', $workbook);
        }
        $income = $zip->getFromName('xl/worksheets/sheet3.xml');
        $this->assertStringContainsString(htmlspecialchars($firm->name, ENT_XML1 | ENT_QUOTES).' · Q1 · warm-up', $income);
        $this->assertStringContainsString('Beta · Q3 · warm-up', $income);
        $netIncome = (float) $firm->engineResults()->where('quarter_number', 3)->firstOrFail()->income_statement['net_income'];
        $this->assertStringContainsString('<v>'.rtrim(rtrim(sprintf('%.10F', round($netIncome, 6)), '0'), '.').'</v>', $income, 'figures are numbers, not text');
        $zip->close();

        $this->actingAs(Student::query()->firstOrFail()->user)->get(route('faculty.courses.quarter-results.download', $course))->assertForbidden();
    }

    /** L20, L21, L22: the practice-run link on the evaluation stage, the last-member warning, and whether the course link works. */
    public function test_small_faculty_fixes(): void
    {
        // Courses on the earlier engine can't be rehearsed, so they keep the description alone.
        [$course, $firm, $student] = $this->phase7bCourse('Course 02');
        $course->forceFill(['cf_plus_enabled' => true, 'build_quarters' => 1, 'adversarial_quarters' => 1, 'post_bid_quarters' => 1])->save();
        $this->assertSame(1, $course->fresh()->engineVersion());
        $this->actingAs($this->faculty->user)->get(route('faculty.courses.cf-plus-auction', $course))->assertOk()->assertDontSee('data-test="evaluation-practice-link"', false);

        config(['flexee.engine_version_for_new_courses' => 2]);
        $plus = $this->sourceCourse('Plus to rehearse', plus: true);
        $plus->forceFill(['status' => 'deployed'])->save();
        $this->get(route('faculty.courses.cf-plus-auction', $plus))->assertOk()
            ->assertSee('data-test="evaluation-practice-link"', false)
            ->assertSee(route('faculty.courses.practice', $plus), false);

        $this->get(route('faculty.courses.firms', $course))->assertOk()
            ->assertSee(e($firm->name).' will have no one left. Its last decisions will carry forward every quarter', false);

        $course->forceFill(['registration_token' => 'tok-123', 'registration_enabled' => false])->save();
        $this->get(route('faculty.courses.students', $course))->assertOk()
            ->assertSee('Registration is closed: students who follow this link are turned away.')
            ->assertSee('Open it on Review setup');
        $course->forceFill(['registration_enabled' => true])->save();
        $this->get(route('faculty.courses.students', $course))->assertOk()
            ->assertSee('Registration is open: anyone with this link can join.');
    }
}
