<?php

namespace Tests\Feature\Phase9;

use App\Livewire\Faculty\Courses\FirmManager as FacultyFirmManager;
use App\Livewire\Student\CfPlusEvaluation;
use App\Models\CfPlusAuctionEconomics;
use App\Models\CfPlusAuctionEconomicsSnapshot;
use App\Models\CfPlusOffer;
use App\Models\Course;
use App\Models\CourseDecisionEnablement;
use App\Models\CourseFacultyRole;
use App\Models\CourseQuarter;
use App\Models\Enrollment;
use App\Models\Faculty;
use App\Models\Firm;
use App\Models\FirmEngineResult;
use App\Models\FirmMembership;
use App\Models\FirmQuarterClose;
use App\Models\Scenario;
use App\Models\Student;
use App\Services\CfPlus\CfPlusAcquisitionFundingService;
use App\Services\CfPlus\CfPlusAuctionEconomicsSnapshotService;
use App\Services\CfPlus\CfPlusAuctionSettlementService;
use App\Services\CfPlus\CfPlusOfferService;
use App\Services\CfPlus\CfPlusRevealService;
use App\Services\CfPlus\FirmAccountingElectionService;
use App\Services\Decisions\FirmDecisionService;
use App\Services\Firms\FirmAssignmentService;
use App\Services\Quarters\FirmQuarterCloseService;
use App\Services\Quarters\QuarterManagementService;
use App\Services\Warmup\InheritedWarmupHistoryService;
use App\Support\Scenario\CfManufacturingScenarioDefinition;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use LogicException;
use Tests\Support\Phase7B\BuildsPhase7BCourse;
use Tests\TestCase;

class CfPlusAuctionEconomicsSnapshotTest extends TestCase
{
    use BuildsPhase7BCourse, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Carbon::setTestNow('2026-08-11 10:30:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_snapshot_is_created_once_at_evaluation_opening(): void
    {
        [$course, $firms, $quarter] = $this->boundaryMarket(false);
        app(QuarterManagementService::class)->open($course->faculty, $quarter);

        $snapshot = CfPlusAuctionEconomicsSnapshot::query()->whereBelongsTo($course)->firstOrFail();
        $this->assertSame(1, CfPlusAuctionEconomicsSnapshot::query()->whereBelongsTo($course)->count());
        $this->assertSame($course->id, $snapshot->course_id);
        $this->assertSame(6, $snapshot->evaluation_start_quarter);
        $this->assertSame('2026-08-11', $snapshot->valuation_date->toDateString());
        $this->assertSame($snapshot->id, CfPlusAuctionEconomicsSnapshot::query()->whereBelongsTo($course)->value('id'));
        $this->assertCount(2, $snapshot->economics);
        $this->assertSame(2, CfPlusAuctionEconomics::query()->whereBelongsTo($course)->count());
        $this->assertEqualsCanonicalizing(collect($firms)->pluck('id')->all(), $snapshot->firm_ids);
        $this->assertSame(2, $snapshot->economics->pluck('firm_id')->unique()->count());
        foreach ($firms as $key => $firm) {
            $row = $snapshot->economics->firstWhere('firm_id', $firm->id);
            $boundary = $firm->engineResults()->where('quarter_number', 5)->firstOrFail();
            $this->assertNotNull($row, "Missing frozen economics for {$key}.");
            $this->assertSame($firm->id, $row->firm_id);
            $this->assertSame($course->id, $row->course_id);
            $this->assertSame($boundary->id, $row->boundary_result_id);
            $this->assertSame($boundary->payload_hash, $row->boundary_result_hash);
            $this->assertSame($boundary->id, $row->source_result_ids[2]);
            $this->assertSame($boundary->payload_hash, $row->source_result_hashes[$boundary->id]);
            $this->assertSame($row->disclosure_snapshot_id, $row->disclosureSnapshot->id);
            $this->assertSame((float) $boundary->fields['bs_cash'], (float) $row->bidder_cash);
            $this->assertSame((float) $boundary->fields['n5_limit'], (float) $row->committed_credit_limit);
            $this->assertSame((float) $boundary->fields['n5_balance'], (float) $row->committed_credit_balance);
            $this->assertSame(max(0, (float) $boundary->fields['n5_limit'] - (float) $boundary->fields['n5_balance']), (float) $row->unused_committed_credit);
            $this->assertSame((float) $row->bidder_cash + (float) $row->unused_committed_credit, (float) $row->bidder_own_funds);
            $this->assertSame((float) $row->normalized_quarterly_cash * 4, (float) $row->normalized_annual_cash);
            $this->assertSame(round((float) $row->full_consultant_value * 0.51, 2), (float) $row->consultant_stake_value);
            $this->assertSame(round((float) $row->raw_full_true_worth * 0.51, 2), (float) $row->raw_true_stake_worth);
            $this->assertSame(max(0, (float) $row->raw_full_true_worth), (float) $row->displayed_full_true_worth);
            $this->assertSame(round((float) $row->displayed_full_true_worth * 0.51, 2), (float) $row->displayed_true_stake_worth);
            $this->assertSame('POSITIVE GOING-CONCERN VALUE', $row->going_concern_status);
            $this->assertSame((float) $row->normalized_annual_cash * 5, (float) $row->target_acquisition_financing);
        }
    }

    public function test_snapshot_trigger_is_idempotent_and_preserves_rows_and_timestamps(): void
    {
        [$course, , $quarter, $snapshot] = $this->boundaryMarket();
        $before = $this->captureSnapshot($snapshot);
        $again = app(CfPlusAuctionEconomicsSnapshotService::class)->createAtEvaluationOpening($course, $quarter);

        $this->assertSnapshotUnchanged($before, $again);
        $this->assertSame(1, CfPlusAuctionEconomicsSnapshot::query()->whereBelongsTo($course)->count());
        $this->assertSame(2, CfPlusAuctionEconomics::query()->whereBelongsTo($course)->count());
        $this->engineResult($course->firms()->orderBy('id')->firstOrFail(), 6, 12345678, cash: 123, limit: 456, debt: 400);
        $afterLaterState = app(CfPlusAuctionEconomicsSnapshotService::class)->createAtEvaluationOpening($course, $quarter);
        $this->assertSnapshotUnchanged($before, $afterLaterState);
        $this->assertSame(1, CfPlusAuctionEconomicsSnapshot::query()->whereBelongsTo($course)->count());
        $this->assertSame(2, CfPlusAuctionEconomics::query()->whereBelongsTo($course)->count());
    }

    public function test_disclosure_and_auction_source_identifiers_and_hashes_align(): void
    {
        [, $firms, , $snapshot] = $this->boundaryMarket();

        foreach ($firms as $firm) {
            $economics = $snapshot->economics->firstWhere('firm_id', $firm->id);
            $disclosure = $economics->disclosureSnapshot;
            $boundary = $firm->engineResults()->where('quarter_number', 5)->firstOrFail();
            $this->assertSame($firm->id, $economics->firm_id);
            $this->assertSame($firm->id, $disclosure->target_firm_id);
            $this->assertSame(5, $economics->boundaryResult->quarter_number);
            $this->assertSame($boundary->id, $economics->boundary_result_id);
            $this->assertSame($disclosure->source_result_ids, $economics->source_result_ids);
            foreach ($economics->source_result_ids as $id) {
                $this->assertSame(FirmEngineResult::query()->findOrFail($id)->payload_hash, $economics->source_result_hashes[$id]);
            }
            $this->assertSame($economics->boundary_result_id, $disclosure->source_result_ids[2]);
            $this->assertSame($economics->boundary_result_hash, $economics->source_result_hashes[$economics->boundary_result_id]);
            $this->assertSame($disclosure->id, $economics->disclosure_snapshot_id);
            $this->assertSame($disclosure->id, $disclosure->consultantReport->disclosure_snapshot_id);
            $this->assertSame($economics->source_result_ids, array_keys($economics->source_result_hashes));
            $this->assertFalse($firm->engineResults()->where('quarter_number', '>', 5)->whereIn('id', $economics->source_result_ids)->exists());
        }
    }

    public function test_missing_boundary_result_rolls_back_evaluation_opening(): void
    {
        [$course, , $quarter] = $this->boundaryMarket(false, omitBoundaryFor: 'bravo');
        $sourceState = FirmEngineResult::query()->whereBelongsTo($course)->orderBy('id')->pluck('payload_hash', 'id')->all();

        $this->expectDomain(fn () => app(QuarterManagementService::class)->open($course->faculty, $quarter), 'FIRM MISSING CLOSED BOUNDARY RESULT');
        $this->assertSame(CourseQuarter::LOCKED, $quarter->fresh()->state);
        $this->assertDatabaseCount('cf_plus_auction_economics_snapshots', 0);
        $this->assertDatabaseCount('cf_plus_auction_economics', 0);
        $this->assertDatabaseCount('cf_plus_disclosure_snapshots', 0);
        $this->assertDatabaseCount('cf_plus_consultant_reports', 0);
        $this->assertSame($sourceState, FirmEngineResult::query()->whereBelongsTo($course)->orderBy('id')->pluck('payload_hash', 'id')->all());
    }

    public function test_late_firm_creation_is_rejected_without_mutating_snapshot(): void
    {
        [$course, , , $snapshot] = $this->boundaryMarket();
        $beforeRows = $snapshot->economics()->count();

        $this->expectValidation(fn () => app(FirmAssignmentService::class)->createFirm($course->faculty, $course, 'late', 'Late Firm'), 'phase9');
        $this->assertSame($snapshot->firm_ids, $snapshot->fresh()->firm_ids);
        $this->assertSame(2, $course->firms()->count());
        $this->assertSame($beforeRows, $snapshot->economics()->count());
        $this->assertFalse($course->firms()->where('code', 'late')->exists());
        $this->assertSame($snapshot->id, app(CfPlusAuctionEconomicsSnapshotService::class)->forCourse($course)->id);
    }

    public function test_late_student_enrollment_succeeds_without_mutating_snapshot_or_auction_universe(): void
    {
        [$course, , , $snapshot] = $this->boundaryMarket();
        $student = Student::factory()->create(['institution_id' => $course->institution_id]);
        $beforeEnrollmentCount = $course->enrollments()->count();

        $enrollment = app(FirmAssignmentService::class)->enroll($course->faculty, $course, $student);
        $this->assertSame($student->id, $enrollment->student_id);
        $this->assertSame($snapshot->student_ids, $snapshot->fresh()->student_ids);
        $this->assertSame($beforeEnrollmentCount + 1, $course->enrollments()->count());
        $this->assertTrue(Enrollment::query()->whereBelongsTo($course)->whereBelongsTo($student)->exists());
        $this->assertFalse(FirmMembership::query()->where('course_id', $course->id)->where('student_id', $student->id)->exists());
        $this->assertSame($snapshot->id, app(CfPlusAuctionEconomicsSnapshotService::class)->forCourse($course)->id);
    }

    public function test_late_student_assignment_to_existing_firm_succeeds_without_mutating_snapshot(): void
    {
        [$course, $firms, , $snapshot] = $this->boundaryMarket();
        $student = Student::factory()->create(['institution_id' => $course->institution_id]);
        $enrollment = Enrollment::query()->create(['course_id' => $course->id, 'student_id' => $student->id, 'institution_id' => $course->institution_id]);
        $enrollmentUpdatedAt = $enrollment->updated_at->format('Y-m-d H:i:s.u');
        $beforeMembershipCount = FirmMembership::query()->where('course_id', $course->id)->count();

        $this->assertSame($enrollment->id, app(FirmAssignmentService::class)->enroll($course->faculty, $course, $student)->id);
        $this->assertSame($enrollment->id, $enrollment->fresh()->id);
        $this->assertSame($enrollmentUpdatedAt, $enrollment->fresh()->updated_at->format('Y-m-d H:i:s.u'));
        $this->assertNull($enrollment->fresh()->deleted_at);
        $snapshotState = $this->captureSnapshot($snapshot);
        $membership = app(FirmAssignmentService::class)->assign($course->faculty, $course, $firms['alpha'], $student);
        $this->assertSame($firms['alpha']->id, $membership->firm_id);
        $this->assertSame($snapshot->student_assignments, $snapshot->fresh()->student_assignments);
        $this->assertSame($beforeMembershipCount + 1, FirmMembership::query()->where('course_id', $course->id)->count());
        $this->assertTrue(Enrollment::query()->whereBelongsTo($course)->whereBelongsTo($student)->exists());
        $this->assertTrue(FirmMembership::query()->where('course_id', $course->id)->where('student_id', $student->id)->where('firm_id', $firms['alpha']->id)->exists());
        $this->assertSnapshotUnchanged($snapshotState, $snapshot->fresh());
        $this->assertSame(337500000.0, app(CfPlusAcquisitionFundingService::class)->calculate($course, $firms['alpha'], $firms['bravo'])['maximum_fundable_offer']);
    }

    public function test_moving_existing_student_between_snapshotted_firms_is_rejected_before_write(): void
    {
        [$course, $firms, , $snapshot] = $this->boundaryMarket();
        $membership = FirmMembership::query()->where('course_id', $course->id)->firstOrFail();
        $student = $membership->student;
        $before = $membership->getAttributes();

        $this->expectValidation(fn () => app(FirmAssignmentService::class)->assign($course->faculty, $course, $firms['bravo'], $student), 'phase9');
        $this->assertSame($before, $membership->fresh()->getAttributes());
        $this->assertSame($firms['alpha']->id, $membership->fresh()->firm_id);
        $this->assertSame($snapshot->student_assignments, $snapshot->fresh()->student_assignments);
        $this->assertSame($snapshot->id, app(CfPlusAuctionEconomicsSnapshotService::class)->forCourse($course)->id);
        $this->assertFalse(FirmMembership::query()->where('student_id', $student->id)->where('firm_id', $firms['bravo']->id)->exists());
    }

    public function test_direct_membership_soft_delete_is_rejected_without_mutating_roster_or_snapshot(): void
    {
        [$course, $firms, , $snapshot] = $this->boundaryMarket();
        $membership = FirmMembership::query()->where('course_id', $course->id)->firstOrFail();
        $snapshotState = $this->captureSnapshot($snapshot);

        $this->expectQueryRejection(fn () => DB::transaction(fn () => $membership->delete()), 'Firm membership is immutable');
        $this->assertDatabaseHas('firm_memberships', ['id' => $membership->id, 'firm_id' => $firms['alpha']->id, 'deleted_at' => null]);
        $this->assertSame(1, $firms['alpha']->memberships()->count());
        $this->assertSame(337500000.0, app(CfPlusAcquisitionFundingService::class)->calculate($course, $firms['alpha'], $firms['bravo'])['maximum_fundable_offer']);
        $this->assertSnapshotUnchanged($snapshotState, $snapshot->fresh());
    }

    public function test_supported_membership_removal_is_rejected_after_snapshot(): void
    {
        [$course, $firms, , $snapshot] = $this->boundaryMarket();
        $membership = FirmMembership::query()->where('course_id', $course->id)->firstOrFail();
        $snapshotState = $this->captureSnapshot($snapshot);

        $this->expectValidation(fn () => app(FirmAssignmentService::class)->unassign($course->faculty, $course, $membership->student), 'phase9');
        $this->assertDatabaseHas('firm_memberships', ['id' => $membership->id, 'firm_id' => $firms['alpha']->id, 'deleted_at' => null]);
        $this->assertSame(1, $firms['alpha']->memberships()->count());
        $this->assertSnapshotUnchanged($snapshotState, $snapshot->fresh());
    }

    public function test_late_student_can_enroll_and_remain_unassigned_without_breaking_cf_plus(): void
    {
        [$course, $firms, , $snapshot] = $this->boundaryMarket();
        $student = Student::factory()->create(['institution_id' => $course->institution_id]);
        $before = [$course->enrollments()->count(), FirmMembership::query()->where('course_id', $course->id)->count()];

        $enrollment = app(FirmAssignmentService::class)->enroll($course->faculty, $course, $student);
        $this->assertSame($student->id, $enrollment->student_id);
        $this->assertSame($before[0] + 1, $course->enrollments()->count());
        $this->assertSame($before[1], FirmMembership::query()->where('course_id', $course->id)->count());
        $this->assertTrue(Enrollment::query()->whereBelongsTo($course)->whereBelongsTo($student)->exists());
        $this->assertFalse(FirmMembership::query()->where('student_id', $student->id)->exists());
        $this->assertSame($snapshot->id, app(CfPlusAuctionEconomicsSnapshotService::class)->forCourse($course)->id);
        $this->assertSame(337500000.0, app(CfPlusAcquisitionFundingService::class)->calculate($course, $firms['alpha'], $firms['bravo'])['maximum_fundable_offer']);
    }

    public function test_direct_membership_move_and_hard_delete_are_rejected_by_postgresql(): void
    {
        [$course, $firms, , $snapshot] = $this->boundaryMarket();
        $membership = FirmMembership::query()->where('course_id', $course->id)->firstOrFail();
        $snapshotState = $this->captureSnapshot($snapshot);

        $this->expectQueryRejection(fn () => DB::transaction(fn () => DB::table('firm_memberships')->where('id', $membership->id)->update(['firm_id' => $firms['bravo']->id])), 'Firm membership is immutable');
        $this->assertDatabaseHas('firm_memberships', ['id' => $membership->id, 'firm_id' => $firms['alpha']->id, 'deleted_at' => null]);
        $this->expectQueryRejection(fn () => DB::transaction(fn () => DB::table('firm_memberships')->where('id', $membership->id)->delete()), 'Firm membership is immutable');
        $this->assertSame(1, FirmMembership::query()->whereKey($membership->id)->count());
        $this->assertSame($firms['alpha']->id, $membership->fresh()->firm_id);
        $this->assertNull($membership->fresh()->deleted_at);
        $this->assertSnapshotUnchanged($snapshotState, $snapshot->fresh());
    }

    public function test_direct_new_firm_creation_is_rejected_by_postgresql(): void
    {
        [$course, , , $snapshot] = $this->boundaryMarket();
        $snapshotState = $this->captureSnapshot($snapshot);

        $this->expectQueryRejection(fn () => DB::transaction(fn () => Firm::factory()->create(['course_id' => $course->id, 'institution_id' => $course->institution_id, 'code' => 'bypass'])), 'New firms cannot enter');
        $this->assertFalse($course->firms()->where('code', 'bypass')->exists());
        $this->assertSame(2, $course->firms()->count());
        $this->assertSame(2, $snapshot->economics()->count());
        $this->assertSnapshotUnchanged($snapshotState, $snapshot->fresh());
    }

    public function test_faculty_roster_displays_phase9_rejections_for_new_firm_and_inter_firm_move(): void
    {
        [$course, $firms] = $this->boundaryMarket();
        $membership = FirmMembership::query()->where('course_id', $course->id)->firstOrFail();
        $this->actingAs($course->faculty->user);

        Livewire::test(FacultyFirmManager::class, ['course' => $course])
            ->set('code', 'late')
            ->set('name', 'Late Firm')
            ->call('createFirm')
            ->assertHasErrors('phase9')
            ->assertSee('locked once the evaluation stage opens');

        Livewire::test(FacultyFirmManager::class, ['course' => $course])
            ->set('studentId', $membership->student_id)
            ->set('firmId', $firms['bravo']->id)
            ->call('enrollAndAssign')
            ->assertHasErrors('phase9')
            ->assertSee('cannot move between firms');

        $this->assertSame($firms['alpha']->id, $membership->fresh()->firm_id);
        $this->assertFalse($course->firms()->where('code', 'late')->exists());
    }

    public function test_late_member_inherits_existing_firm_offer_workspace_without_rival_private_state(): void
    {
        [$course, $firms, , $snapshot] = $this->boundaryMarket();
        $snapshotState = $this->captureSnapshot($snapshot);
        $alphaOffer = app(CfPlusOfferService::class)->submit($course, $firms['alpha'], $firms['bravo'], 100000000.0);
        $rivalOffer = app(CfPlusOfferService::class)->submit($course, $firms['bravo'], $firms['alpha'], 75000000.0);
        $student = Student::factory()->create(['institution_id' => $course->institution_id]);

        app(FirmAssignmentService::class)->enroll($course->faculty, $course, $student);
        app(FirmAssignmentService::class)->assign($course->faculty, $course, $firms['alpha'], $student);
        $this->actingAs($student->user);
        $component = Livewire::test(CfPlusEvaluation::class, ['firm' => $firms['alpha']]);
        $offers = $component->get('offerAmounts');

        $this->assertSame([$firms['bravo']->id => '100000000.00'], $offers);
        $this->assertArrayNotHasKey($firms['alpha']->id, $offers);
        $this->assertNotContains((string) $rivalOffer->offered_amount, $offers, true);
        $this->assertSame($firms['alpha']->id, $alphaOffer->fresh()->bidder_firm_id);
        $this->assertSame(1, CfPlusOffer::query()->where('bidder_firm_id', $firms['alpha']->id)->where('target_firm_id', $firms['bravo']->id)->count());
        $this->assertSame(2, $snapshot->economics()->count());
        $this->assertSnapshotUnchanged($snapshotState, $snapshot->fresh());

        $course->quarters()->update(['state' => CourseQuarter::CLOSED]);
        $result = app(CfPlusAuctionSettlementService::class)->settle($course->faculty, $course)->firstWhere('target_firm_id', $firms['bravo']->id);
        $reveal = app(CfPlusRevealService::class)->forTarget($course, $firms['alpha'], $firms['bravo']);
        $this->assertSame($firms['alpha']->id, $result->winning_bidder_firm_id);
        $this->assertSame($alphaOffer->id, $result->winning_offer_id);
        $this->assertSame((float) $alphaOffer->offered_amount, $reveal['viewer_offer']);
        $this->assertSame((float) $alphaOffer->fundable_cap, $reveal['viewer_fundable_cap']);
        $this->assertSame($snapshot->valuation_date->toDateString(), $reveal['valuation_date']);
    }

    public function test_snapshot_rejects_bad_course_timing_before_final_build_close(): void
    {
        [$course, , $quarter] = $this->boundaryMarket(false);
        $sourceState = FirmEngineResult::query()->whereBelongsTo($course)->orderBy('id')->pluck('payload_hash', 'id')->all();
        $course->quarters()->where('quarter_number', 5)->update(['state' => CourseQuarter::OPEN]);
        $quarter->update(['state' => CourseQuarter::OPEN]);

        $this->expectDomain(fn () => app(CfPlusAuctionEconomicsSnapshotService::class)->createAtEvaluationOpening($course, $quarter->fresh()), 'FINAL BUILD QUARTER IS NOT CLOSED');
        $this->assertDatabaseCount('cf_plus_auction_economics_snapshots', 0);
        $this->assertDatabaseCount('cf_plus_auction_economics', 0);
        $this->assertDatabaseCount('cf_plus_disclosure_snapshots', 0);
        $this->assertDatabaseCount('cf_plus_consultant_reports', 0);
        $this->assertSame(CourseQuarter::OPEN, $quarter->fresh()->state);
        $this->assertSame($sourceState, FirmEngineResult::query()->whereBelongsTo($course)->orderBy('id')->pluck('payload_hash', 'id')->all());
    }

    public function test_snapshot_rejects_missing_required_auction_figure(): void
    {
        [$course, , $quarter] = $this->boundaryMarket(false, missingBoundaryFieldFor: 'bravo');
        $sourceState = FirmEngineResult::query()->whereBelongsTo($course)->orderBy('id')->pluck('payload_hash', 'id')->all();
        $quarter->update(['state' => CourseQuarter::OPEN]);

        $this->expectDomain(fn () => app(CfPlusAuctionEconomicsSnapshotService::class)->createAtEvaluationOpening($course, $quarter->fresh()), 'MISSING AUCTION FIGURE [n5_balance]');
        $this->assertDatabaseCount('cf_plus_auction_economics_snapshots', 0);
        $this->assertDatabaseCount('cf_plus_auction_economics', 0);
        $this->assertDatabaseCount('cf_plus_disclosure_snapshots', 0);
        $this->assertDatabaseCount('cf_plus_consultant_reports', 0);
        $this->assertSame($sourceState, FirmEngineResult::query()->whereBelongsTo($course)->orderBy('id')->pluck('payload_hash', 'id')->all());
        $this->assertArrayNotHasKey('n5_balance', $course->firms()->where('name', 'Bravo')->firstOrFail()->engineResults()->where('quarter_number', 5)->firstOrFail()->fields);
    }

    public function test_multi_firm_snapshot_creation_is_atomic_after_first_economics_insert_failure(): void
    {
        [$course, , $quarter] = $this->boundaryMarket(false);
        $quarter->update(['state' => CourseQuarter::OPEN, 'opened_at' => now()]);
        $event = 'eloquent.created: '.CfPlusAuctionEconomics::class;
        Event::listen($event, fn () => throw new DomainException('CONTROLLED SNAPSHOT ROW FAILURE'));

        try {
            $this->expectDomain(fn () => app(CfPlusAuctionEconomicsSnapshotService::class)->createAtEvaluationOpening($course, $quarter->fresh()), 'CONTROLLED SNAPSHOT ROW FAILURE');
        } finally {
            Event::forget($event);
        }
        $this->assertDatabaseCount('cf_plus_auction_economics_snapshots', 0);
        $this->assertDatabaseCount('cf_plus_auction_economics', 0);
        $this->assertDatabaseCount('cf_plus_disclosure_snapshots', 0);
        $this->assertDatabaseCount('cf_plus_consultant_reports', 0);
        $this->assertSame(CourseQuarter::OPEN, $quarter->fresh()->state);

        $snapshot = app(CfPlusAuctionEconomicsSnapshotService::class)->createAtEvaluationOpening($course, $quarter->fresh());
        $this->assertSame(1, CfPlusAuctionEconomicsSnapshot::query()->whereBelongsTo($course)->count());
        $this->assertSame(2, $snapshot->economics()->count());
        $this->assertSame(2, $course->cfPlusDisclosureSnapshots()->count());
        $this->assertSame(2, $course->cfPlusConsultantReports()->count());
    }

    public function test_bidder_cash_is_frozen_from_the_boundary_result(): void
    {
        [$course, $firms] = $this->boundaryMarket();
        $funding = app(CfPlusAcquisitionFundingService::class)->calculate($course, $firms['alpha'], $firms['bravo']);

        $this->assertSame(100000000.0, $funding['bidder_cash']);
    }

    public function test_unused_committed_credit_is_frozen_from_boundary_facility_context(): void
    {
        [$course, $firms] = $this->boundaryMarket();
        $funding = app(CfPlusAcquisitionFundingService::class)->calculate($course, $firms['alpha'], $firms['bravo']);

        $this->assertSame(40000000.0, $funding['unused_committed_credit']);
    }

    public function test_target_normalized_cash_is_frozen_at_evaluation_start(): void
    {
        [, $firms, , $snapshot] = $this->boundaryMarket();
        $row = $snapshot->economics->firstWhere('firm_id', $firms['bravo']->id);

        $this->assertSame('39500000.00', $row->normalized_annual_cash);
        $this->assertSame('9875000.00', $row->normalized_quarterly_cash);
    }

    public function test_target_five_times_acquisition_financing_is_frozen(): void
    {
        [, $firms, , $snapshot] = $this->boundaryMarket();
        $row = $snapshot->economics->firstWhere('firm_id', $firms['bravo']->id);

        $this->assertSame('197500000.00', $row->target_acquisition_financing);
        $this->assertSame((float) $row->normalized_annual_cash * 5.0, (float) $row->target_acquisition_financing);
    }

    public function test_consultant_whole_company_and_control_stake_values_are_frozen(): void
    {
        [, $firms, , $snapshot] = $this->boundaryMarket();
        $row = $snapshot->economics->firstWhere('firm_id', $firms['bravo']->id);

        $this->assertGreaterThan(0, (float) $row->full_consultant_value);
        $this->assertSame(round((float) $row->full_consultant_value * 0.51, 2), (float) $row->consultant_stake_value);
    }

    public function test_raw_and_displayed_true_worth_are_frozen(): void
    {
        [, $firms, , $snapshot] = $this->boundaryMarket();
        $row = $snapshot->economics->firstWhere('firm_id', $firms['bravo']->id);

        $this->assertGreaterThan(0, (float) $row->raw_full_true_worth);
        $this->assertSame((float) $row->raw_full_true_worth, (float) $row->displayed_full_true_worth);
        $this->assertSame(round((float) $row->displayed_full_true_worth * 0.51, 2), (float) $row->displayed_true_stake_worth);
    }

    public function test_maximum_fundable_offer_uses_only_snapshot_components(): void
    {
        [$course, $firms] = $this->boundaryMarket();
        $funding = app(CfPlusAcquisitionFundingService::class)->calculate($course, $firms['alpha'], $firms['bravo']);

        $this->assertSame(337500000.0, $funding['maximum_fundable_offer']);
        $this->assertSame($funding['bidder_cash'] + $funding['unused_committed_credit'] + $funding['target_acquisition_financing'], $funding['maximum_fundable_offer']);
    }

    public function test_saved_offer_cap_and_components_equal_snapshot_cap_exactly(): void
    {
        [$course, $firms, , $snapshot] = $this->boundaryMarket();
        $offers = app(CfPlusOfferService::class);
        Carbon::setTestNow('2026-08-11 11:00:00');
        $positive = $offers->submit($course, $firms['alpha'], $firms['bravo'], 100000000.0);
        $firstTimestamp = $positive->submitted_at->format('Y-m-d H:i:s.u');
        Carbon::setTestNow('2026-08-11 11:01:00');
        $edited = $offers->submit($course, $firms['alpha'], $firms['bravo'], 120000000.0);
        $editedTimestamp = $edited->submitted_at->format('Y-m-d H:i:s.u');
        $zero = $offers->submit($course, $firms['bravo'], $firms['alpha'], 0.0);
        Carbon::setTestNow('2026-08-11 11:02:00');
        $unchanged = $offers->submit($course, $firms['alpha'], $firms['bravo'], 120000000.0);

        $this->assertSame($positive->id, $edited->id);
        $this->assertNotSame($firstTimestamp, $editedTimestamp);
        $this->assertSame($editedTimestamp, $unchanged->submitted_at->format('Y-m-d H:i:s.u'));
        $this->assertSame('120000000.00', $unchanged->offered_amount);
        $this->assertSame('0.00', $zero->offered_amount);
        $this->assertSame(2, CfPlusOffer::query()->whereBelongsTo($course)->count());

        foreach (CfPlusOffer::query()->whereBelongsTo($course)->orderBy('id')->get() as $offer) {
            $bidder = $snapshot->economics->firstWhere('firm_id', $offer->bidder_firm_id);
            $target = $snapshot->economics->firstWhere('firm_id', $offer->target_firm_id);
            $authoritative = app(CfPlusAcquisitionFundingService::class)->calculate($course, $offer->bidderFirm, $offer->targetFirm);
            $expectedCap = round((float) $bidder->bidder_cash + (float) $bidder->unused_committed_credit + (float) $target->target_acquisition_financing, 2);
            $this->assertSame((float) $bidder->bidder_cash, (float) $offer->bidder_cash);
            $this->assertSame((float) $bidder->unused_committed_credit, (float) $offer->unused_committed_credit);
            $this->assertSame((float) $target->target_acquisition_financing, (float) $offer->target_acquisition_financing);
            $this->assertSame($expectedCap, (float) $offer->fundable_cap);
            $this->assertSame($authoritative['maximum_fundable_offer'], (float) $offer->fundable_cap);
            $this->assertSame($authoritative['bidder_cash'], (float) $offer->bidder_cash);
            $this->assertSame($authoritative['unused_committed_credit'], (float) $offer->unused_committed_credit);
            $this->assertSame($authoritative['target_acquisition_financing'], (float) $offer->target_acquisition_financing);
        }
        $this->assertNotSame((float) $edited->bidder_cash, (float) $zero->bidder_cash);
        $this->assertNotSame((float) $edited->target_acquisition_financing, (float) $zero->target_acquisition_financing);
    }

    public function test_later_operating_result_does_not_reprice_any_auction_economics(): void
    {
        [$course, $firms, , $snapshot] = $this->boundaryMarket();
        $before = $this->captureSnapshot($snapshot);
        $boundary = $firms['alpha']->engineResults()->where('quarter_number', 5)->firstOrFail();
        $later = $this->engineResult($firms['alpha'], 6, 15000000, cash: 25000000, limit: 70000000, debt: 65000000);

        $after = app(CfPlusAuctionEconomicsSnapshotService::class)->forCourse($course);
        $this->assertNotSame($boundary->id, $later->id);
        $this->assertNotSame($boundary->payload_hash, $later->payload_hash);
        $this->assertNotSame((float) $boundary->fields['bs_cash'], (float) $later->fields['bs_cash']);
        $this->assertSame(6, $later->quarter_number);
        $this->assertSnapshotUnchanged($before, $after);
    }

    public function test_later_legal_dividend_cash_reduction_does_not_reprice_cap(): void
    {
        [$course, $bidder, $student, $q4, $q5] = $this->phase7bCourse('Amendment 3 genuine dividend lifecycle');
        $target = Firm::factory()->create(['course_id' => $course->id, 'institution_id' => $course->institution_id]);
        foreach ([$bidder, $target] as $firm) {
            app(FirmAccountingElectionService::class)->lock($firm, CfManufacturingScenarioDefinition::fixedWarmupPayload('notes_elections'));
        }
        $targetStudent = Student::query()->whereKeyNot($student->id)->firstOrFail();
        $this->addMember($target, $targetStudent, $course->faculty);
        app(InheritedWarmupHistoryService::class)->initialize($target);
        $q6 = CourseQuarter::query()->create(['course_id' => $course->id, 'quarter_number' => 6, 'starts_on' => now()->addMonths(6), 'ends_on' => now()->addMonths(7), 'decision_deadline_at' => now()->addMonths(7), 'state' => CourseQuarter::LOCKED]);
        CourseQuarter::query()->create(['course_id' => $course->id, 'quarter_number' => 7, 'starts_on' => now()->addMonths(7), 'ends_on' => now()->addMonths(8), 'decision_deadline_at' => now()->addMonths(8), 'state' => CourseQuarter::LOCKED]);
        foreach (['forecasting', 'sourcing', 'production'] as $code) {
            CourseDecisionEnablement::query()->create(['course_quarter_id' => $q6->id, 'scenario_decision_category_id' => $course->scenario->decisionCategories()->where('code', $code)->value('id'), 'enabled' => true]);
        }
        foreach ([$q4, $q5] as $quarter) {
            $this->closeRealQuarter($course, $quarter, [[$bidder, $student], [$target, $targetStudent]], 0);
        }
        $course->update(['cf_plus_enabled' => true, 'build_quarters' => 2, 'adversarial_quarters' => 2, 'post_bid_quarters' => 1]);
        app(QuarterManagementService::class)->open($course->faculty, $q6);
        $snapshot = CfPlusAuctionEconomicsSnapshot::query()->whereBelongsTo($course)->firstOrFail();
        $snapshotDate = $snapshot->valuation_date->format('Y-m-d H:i:s.u');
        $bidderFrozen = $snapshot->economics->firstWhere('firm_id', $bidder->id);
        $targetFrozen = $snapshot->economics->firstWhere('firm_id', $target->id);
        $boundary = $bidder->engineResults()->where('quarter_number', 5)->firstOrFail();
        $before = app(CfPlusAcquisitionFundingService::class)->calculate($course, $bidder, $target);
        $offer = app(CfPlusOfferService::class)->submit($course, $bidder, $target, min(1000000, $before['maximum_fundable_offer']));
        $savedOffer = $offer->getAttributes();
        app(FirmDecisionService::class)->submit($student, $bidder, $q6, $this->completePayload(), 1000);
        app(FirmDecisionService::class)->submit($targetStudent, $target, $q6, $this->completePayload(), 0);
        $this->finishOpenQuarter($course, $q6);
        $after = app(CfPlusAcquisitionFundingService::class)->calculate($course, $bidder, $target);
        $later = $bidder->engineResults()->where('quarter_number', 6)->firstOrFail();
        $bidderAfter = $snapshot->fresh()->economics->firstWhere('firm_id', $bidder->id);
        $targetAfter = $snapshot->fresh()->economics->firstWhere('firm_id', $target->id);

        $this->assertSame($before, $after);
        $this->assertSame($snapshot->id, CfPlusAuctionEconomicsSnapshot::query()->whereBelongsTo($course)->value('id'));
        $this->assertSame($snapshotDate, $snapshot->fresh()->valuation_date->format('Y-m-d H:i:s.u'));
        $this->assertNotSame($boundary->id, $later->id);
        $this->assertNotSame($boundary->payload_hash, $later->payload_hash);
        $this->assertNotSame((float) $boundary->fields['bs_cash'], (float) $later->fields['bs_cash']);
        $this->assertSame(CourseQuarter::CLOSED, $q6->fresh()->state);
        $this->assertSame(1000.0, (float) $later->intermediates['dividend']);
        $this->assertSame($bidderFrozen->boundary_result_id, $bidderAfter->boundary_result_id);
        $this->assertSame($bidderFrozen->boundary_result_hash, $bidderAfter->boundary_result_hash);
        $this->assertSame((float) $bidderFrozen->bidder_cash, (float) $bidderAfter->bidder_cash);
        $this->assertSame((float) $bidderFrozen->unused_committed_credit, (float) $bidderAfter->unused_committed_credit);
        $this->assertSame((float) $bidderFrozen->bidder_own_funds, (float) $bidderAfter->bidder_own_funds);
        $this->assertSame((float) $targetFrozen->normalized_quarterly_cash, (float) $targetAfter->normalized_quarterly_cash);
        $this->assertSame((float) $targetFrozen->normalized_annual_cash, (float) $targetAfter->normalized_annual_cash);
        $this->assertSame((float) $targetFrozen->target_acquisition_financing, (float) $targetAfter->target_acquisition_financing);
        $this->assertSame((float) $targetFrozen->full_consultant_value, (float) $targetAfter->full_consultant_value);
        $this->assertSame((float) $targetFrozen->consultant_stake_value, (float) $targetAfter->consultant_stake_value);
        $this->assertSame((float) $targetFrozen->raw_full_true_worth, (float) $targetAfter->raw_full_true_worth);
        $this->assertSame((float) $targetFrozen->displayed_true_stake_worth, (float) $targetAfter->displayed_true_stake_worth);
        $this->assertSame(round((float) $bidderFrozen->bidder_cash + (float) $bidderFrozen->unused_committed_credit + (float) $targetFrozen->target_acquisition_financing, 2), $after['maximum_fundable_offer']);
        $this->assertSame($before['maximum_fundable_offer'], (float) $offer->fresh()->fundable_cap);
        $this->assertSame((float) $bidderFrozen->bidder_cash, (float) $offer->fresh()->bidder_cash);
        $this->assertSame((float) $bidderFrozen->unused_committed_credit, (float) $offer->fresh()->unused_committed_credit);
        $this->assertSame((float) $targetFrozen->target_acquisition_financing, (float) $offer->fresh()->target_acquisition_financing);
        $this->assertSame((float) $savedOffer['fundable_cap'], (float) $offer->fresh()->getRawOriginal('fundable_cap'));
        $this->assertSame((float) $savedOffer['bidder_cash'], (float) $offer->fresh()->getRawOriginal('bidder_cash'));
        $this->assertSame((float) $savedOffer['unused_committed_credit'], (float) $offer->fresh()->getRawOriginal('unused_committed_credit'));
        $this->assertSame((float) $savedOffer['target_acquisition_financing'], (float) $offer->fresh()->getRawOriginal('target_acquisition_financing'));
    }

    public function test_offer_edit_reuses_the_same_frozen_cap_after_later_state_changes(): void
    {
        [$course, $firms] = $this->boundaryMarket();
        $first = app(CfPlusOfferService::class)->submit($course, $firms['alpha'], $firms['bravo'], 100000000.0);
        $this->engineResult($firms['alpha'], 6, 10000000, cash: 1, limit: 1, debt: 1);
        $edited = app(CfPlusOfferService::class)->submit($course, $firms['alpha'], $firms['bravo'], 120000000.0);

        $this->assertSame($first->id, $edited->id);
        $this->assertSame('337500000.00', $edited->fundable_cap);
        $this->assertSame('100000000.00', $edited->bidder_cash);
    }

    public function test_settlement_uses_frozen_values_after_later_results_exist(): void
    {
        [$course, $firms, , $snapshot] = $this->boundaryMarket();
        $offer = app(CfPlusOfferService::class)->submit($course, $firms['alpha'], $firms['bravo'], 100000000.0);
        $this->engineResult($firms['bravo'], 6, -50000000, cash: 1, limit: 1, debt: 1);
        $course->quarters()->update(['state' => CourseQuarter::CLOSED]);
        $result = app(CfPlusAuctionSettlementService::class)->settle($course->faculty, $course)->firstWhere('target_firm_id', $firms['bravo']->id);
        $frozen = $snapshot->economics->firstWhere('firm_id', $firms['bravo']->id);

        $this->assertSame((float) $frozen->full_consultant_value, (float) $result->full_consultant_value);
        $this->assertSame((float) $frozen->consultant_stake_value, (float) $result->consultant_stake_value);
        $this->assertSame((float) $frozen->raw_full_true_worth, (float) $result->raw_full_true_worth);
        $this->assertSame((float) $frozen->displayed_true_stake_worth, (float) $result->true_stake_worth);
        $this->assertSame((float) $offer->fundable_cap, (float) $result->winning_fundable_cap);
        $this->assertSame((float) $frozen->displayed_full_true_worth, (float) $result->displayed_full_true_worth);
        $this->assertNotSame((float) $firms['bravo']->engineResults()->where('quarter_number', 6)->firstOrFail()->fields['bs_cash'], (float) $frozen->bidder_cash);
        $this->assertSame($snapshot->valuation_date->toDateString(), app(CfPlusRevealService::class)->forTarget($course, $firms['alpha'], $firms['bravo'])['valuation_date']);
    }

    public function test_reveal_reports_the_common_evaluation_start_valuation_date(): void
    {
        [$course, $firms, , $snapshot] = $this->boundaryMarket();
        $offer = app(CfPlusOfferService::class)->submit($course, $firms['alpha'], $firms['bravo'], 100000000.0);
        $frozen = $snapshot->economics->firstWhere('firm_id', $firms['bravo']->id);
        $course->quarters()->update(['state' => CourseQuarter::CLOSED]);
        app(CfPlusAuctionSettlementService::class)->settle($course->faculty, $course);
        $reveal = app(CfPlusRevealService::class)->forTarget($course, $firms['alpha'], $firms['bravo']);

        $this->assertSame($snapshot->valuation_date->toDateString(), $reveal['valuation_date']);
        $this->assertSame((float) $frozen->full_consultant_value, $reveal['full_consultant_value']);
        $this->assertSame((float) $frozen->consultant_stake_value, $reveal['consultant_stake_value']);
        $this->assertSame((float) $frozen->displayed_true_stake_worth, $reveal['true_stake_worth']);
        $this->assertSame((float) $offer->offered_amount, $reveal['price_paid']);
        $this->assertSame((float) $offer->offered_amount, $reveal['viewer_offer']);
        $this->assertSame((float) $offer->fundable_cap, $reveal['viewer_fundable_cap']);
        $this->assertSame(round((float) $offer->fundable_cap - (float) $offer->offered_amount, 2), $reveal['viewer_unused_capacity']);
    }

    public function test_repeated_trigger_after_later_state_change_cannot_regenerate_snapshot(): void
    {
        [$course, $firms, $quarter, $snapshot] = $this->boundaryMarket();
        $hash = $snapshot->source_universe_hash;
        $this->engineResult($firms['alpha'], 6, 20000000, cash: 300000000, limit: 100000000, debt: 0);
        $again = app(CfPlusAuctionEconomicsSnapshotService::class)->createAtEvaluationOpening($course, $quarter);

        $this->assertSame($snapshot->id, $again->id);
        $this->assertSame($hash, $again->source_universe_hash);
        $this->assertSame('100000000.00', $again->economics->firstWhere('firm_id', $firms['alpha']->id)->bidder_cash);
    }

    public function test_auction_paths_have_no_live_engine_result_funding_or_repricing_reads(): void
    {
        $paths = [
            'app/Services/CfPlus/CfPlusAcquisitionFundingService.php' => 'CfPlusAuctionEconomics',
            'app/Services/CfPlus/CfPlusOfferService.php' => 'funding->calculate',
            'app/Services/CfPlus/CfPlusAuctionSettlementService.php' => 'auctionSnapshot->economics',
            'app/Services/CfPlus/CfPlusRevealService.php' => 'snapshots->forCourse',
            'app/Livewire/Student/CfPlusEvaluation.php' => 'funding->calculate',
        ];

        foreach ($paths as $path => $requiredSnapshotPath) {
            $source = file_get_contents(base_path($path));
            $this->assertIsString($source);
            $this->assertStringContainsString($requiredSnapshotPath, $source, $path);
            $this->assertStringNotContainsString('FirmEngineResult', $source, $path);
            $this->assertStringNotContainsString('engineResults()', $source, $path);
            $this->assertStringNotContainsString("orderByDesc('quarter_number')", $source, $path);
        }
    }

    public function test_snapshot_model_guard_rejects_mutation_without_changing_header(): void
    {
        [, , , $snapshot] = $this->boundaryMarket();
        $beforeId = $snapshot->id;
        $beforeDate = $snapshot->valuation_date->format('Y-m-d H:i:s.u');
        $beforeHash = $snapshot->source_universe_hash;
        $beforeUpdatedAt = $snapshot->updated_at->format('Y-m-d H:i:s.u');
        try {
            $snapshot->update(['valuation_date' => now()->addDay()]);
            $this->fail('Snapshot model mutation was accepted.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }
        $after = $snapshot->fresh();
        $this->assertSame($beforeId, $after->id);
        $this->assertSame($beforeDate, $after->valuation_date->format('Y-m-d H:i:s.u'));
        $this->assertSame($beforeHash, $after->source_universe_hash);
        $this->assertSame($beforeUpdatedAt, $after->updated_at->format('Y-m-d H:i:s.u'));
    }

    public function test_postgresql_trigger_rejects_snapshot_header_update(): void
    {
        [, , , $snapshot] = $this->boundaryMarket();
        $before = $snapshot->valuation_date->format('Y-m-d H:i:s.u');

        $this->expectQueryRejection(fn () => DB::transaction(fn () => DB::table('cf_plus_auction_economics_snapshots')->where('id', $snapshot->id)->update(['valuation_date' => now()->addDay()])), 'immutable');
        $this->assertDatabaseHas('cf_plus_auction_economics_snapshots', ['id' => $snapshot->id]);
        $this->assertSame($before, $snapshot->fresh()->valuation_date->format('Y-m-d H:i:s.u'));
        $this->assertSame(1, CfPlusAuctionEconomicsSnapshot::query()->count());
    }

    public function test_postgresql_trigger_rejects_snapshot_header_delete(): void
    {
        [, , , $snapshot] = $this->boundaryMarket();

        $this->expectQueryRejection(fn () => DB::transaction(fn () => DB::table('cf_plus_auction_economics_snapshots')->where('id', $snapshot->id)->delete()), 'immutable');
        $this->assertDatabaseHas('cf_plus_auction_economics_snapshots', ['id' => $snapshot->id]);
        $this->assertSame(1, CfPlusAuctionEconomicsSnapshot::query()->count());
        $this->assertSame(2, CfPlusAuctionEconomics::query()->where('snapshot_id', $snapshot->id)->count());
    }

    public function test_postgresql_trigger_rejects_firm_economics_update(): void
    {
        [, , , $snapshot] = $this->boundaryMarket();
        $row = $snapshot->economics()->orderBy('id')->firstOrFail();
        $before = $row->bidder_cash;

        $this->expectQueryRejection(fn () => DB::transaction(fn () => DB::table('cf_plus_auction_economics')->where('id', $row->id)->update(['bidder_cash' => 1])), 'immutable');
        $this->assertDatabaseHas('cf_plus_auction_economics', ['id' => $row->id]);
        $this->assertSame($before, $row->fresh()->bidder_cash);
        $this->assertSame(2, $snapshot->economics()->count());
    }

    public function test_postgresql_trigger_rejects_firm_economics_delete(): void
    {
        [, , , $snapshot] = $this->boundaryMarket();
        $row = $snapshot->economics()->orderBy('id')->firstOrFail();

        $this->expectQueryRejection(fn () => DB::transaction(fn () => DB::table('cf_plus_auction_economics')->where('id', $row->id)->delete()), 'immutable');
        $this->assertDatabaseHas('cf_plus_auction_economics', ['id' => $row->id]);
        $this->assertSame(2, $snapshot->economics()->count());
        $this->assertSame(1, CfPlusAuctionEconomics::query()->whereKey($row->id)->count());
    }

    /** @return array{Course, array<string, Firm>, CourseQuarter, CfPlusAuctionEconomicsSnapshot|null} */
    private function boundaryMarket(bool $createSnapshot = true, ?string $omitBoundaryFor = null, ?string $missingBoundaryFieldFor = null): array
    {
        $faculty = Faculty::query()->firstOrFail();
        $course = Course::query()->create([
            'institution_id' => $faculty->institution_id, 'faculty_id' => $faculty->id,
            'scenario_id' => Scenario::query()->where('code', 'cf_manufacturing')->value('id'),
            'name' => 'Amendment 3 Market', 'starts_on' => '2026-01-01', 'ends_on' => '2028-01-01',
            'cf_plus_enabled' => true, 'build_quarters' => 2, 'adversarial_quarters' => 2, 'post_bid_quarters' => 1,
            'status' => Course::STATUS_DEPLOYED,
        ]);
        CourseFacultyRole::query()->create(['course_id' => $course->id, 'faculty_id' => $faculty->id, 'role' => CourseFacultyRole::ROLE_OWNER]);
        for ($number = 1; $number <= 7; $number++) {
            $quarters[$number] = CourseQuarter::query()->create([
                'course_id' => $course->id, 'quarter_number' => $number,
                'starts_on' => now()->addMonths($number), 'ends_on' => now()->addMonths($number + 1),
                'decision_deadline_at' => now()->addMonths($number + 1),
                'state' => $number === 6 ? ($createSnapshot ? CourseQuarter::OPEN : CourseQuarter::LOCKED) : CourseQuarter::CLOSED,
            ]);
        }
        foreach (['alpha' => [10000000, 12000000, 11000000], 'bravo' => [9000000, 11000000, 10500000]] as $key => $series) {
            $firms[$key] = Firm::factory()->create(['course_id' => $course->id, 'institution_id' => $course->institution_id, 'name' => ucfirst($key)]);
            app(FirmAccountingElectionService::class)->lock($firms[$key], CfManufacturingScenarioDefinition::fixedWarmupPayload('notes_elections'));
            foreach ($series as $offset => $income) {
                $quarterNumber = $offset + 3;
                if ($key === $omitBoundaryFor && $quarterNumber === 5) {
                    continue;
                }
                $fields = $key === 'alpha'
                    ? ['bs_cash' => 100000000.0, 'n5_limit' => 50000000.0, 'n5_balance' => 10000000.0, 'bs_bank_overdraft' => 0.0]
                    : ['bs_cash' => 80000000.0, 'n5_limit' => 60000000.0, 'n5_balance' => 20000000.0, 'bs_bank_overdraft' => 0.0];
                if ($key === $missingBoundaryFieldFor && $quarterNumber === 5) {
                    unset($fields['n5_balance']);
                }
                $this->engineResult($firms[$key], $quarterNumber, $income, fields: $fields);
            }
        }
        $student = Student::query()->firstOrFail();
        Enrollment::query()->create(['course_id' => $course->id, 'student_id' => $student->id, 'institution_id' => $course->institution_id]);
        FirmMembership::factory()->create(['firm_id' => $firms['alpha']->id, 'course_id' => $course->id, 'student_id' => $student->id, 'institution_id' => $course->institution_id, 'assigned_by_faculty_id' => $faculty->id]);
        $snapshot = $createSnapshot ? app(CfPlusAuctionEconomicsSnapshotService::class)->createAtEvaluationOpening($course, $quarters[6]) : null;

        return [$course->load('faculty'), $firms, $quarters[6], $snapshot];
    }

    /** @param array<string, float>|null $fields */
    private function engineResult(Firm $firm, int $quarter, float $income, float $cash = 100000000, float $limit = 50000000, float $debt = 10000000, ?array $fields = null): FirmEngineResult
    {
        $fields ??= ['bs_cash' => $cash, 'n5_limit' => $limit, 'n5_balance' => $debt, 'bs_bank_overdraft' => 0.0];
        $payload = [
            'firm_id' => $firm->id, 'course_id' => $firm->course_id, 'institution_id' => $firm->institution_id, 'quarter_number' => $quarter,
            'fields' => $fields,
            'income_statement' => ['net_revenue' => $income * 4, 'operating_income' => $income, 'net_income' => $income * 0.75],
            'balance_sheet' => ['assets' => 200000000, 'liabilities' => 50000000, 'equity' => 150000000, 'line_items' => []],
            'cash_flow_statement' => ['operating_cash_flow' => $income, 'investing_cash_flow' => 0, 'financing_cash_flow' => 0, 'ending_cash' => $cash, 'line_items' => ['cf_depr' => 2000000]],
            'notes' => ['accounting_policies' => $firm->accounting_elections], 'intermediates' => [],
            'engine_contract' => FirmEngineResult::ENGINE_CONTRACT, 'provenance' => FirmEngineResult::PROVENANCE_STUDENT_CLOSE,
            'immutable' => true, 'validated_at' => now(),
        ];

        return FirmEngineResult::query()->create($payload + ['payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR))]);
    }

    private function expectDomain(callable $callback, string $fragment): void
    {
        try {
            $callback();
            $this->fail('Expected domain rejection did not occur.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString($fragment, $exception->getMessage());
        }
    }

    private function expectValidation(callable $callback, string $key): void
    {
        try {
            $callback();
            $this->fail('Expected validation rejection did not occur.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors());
        }
    }

    private function expectQueryRejection(callable $callback, string $fragment): void
    {
        try {
            $callback();
            $this->fail('Expected PostgreSQL immutability rejection did not occur.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString($fragment, $exception->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function captureSnapshot(CfPlusAuctionEconomicsSnapshot $snapshot): array
    {
        return [
            'id' => $snapshot->id,
            'created_at' => $snapshot->created_at->format('Y-m-d H:i:s.u'),
            'valuation_date' => $snapshot->valuation_date->format('Y-m-d H:i:s.u'),
            'source_universe_hash' => $snapshot->source_universe_hash,
            'rows' => $snapshot->economics()->orderBy('firm_id')->get()->mapWithKeys(fn (CfPlusAuctionEconomics $row): array => [$row->firm_id => [
                'id' => $row->id,
                'created_at' => $row->created_at->format('Y-m-d H:i:s.u'),
                'boundary_result_id' => $row->boundary_result_id,
                'boundary_result_hash' => $row->boundary_result_hash,
                'source_result_ids' => $row->source_result_ids,
                'source_result_hashes' => $row->source_result_hashes,
                'disclosure_snapshot_id' => $row->disclosure_snapshot_id,
                'bidder_cash' => $row->bidder_cash,
                'unused_committed_credit' => $row->unused_committed_credit,
                'bidder_own_funds' => $row->bidder_own_funds,
                'normalized_quarterly_cash' => $row->normalized_quarterly_cash,
                'normalized_annual_cash' => $row->normalized_annual_cash,
                'full_consultant_value' => $row->full_consultant_value,
                'consultant_stake_value' => $row->consultant_stake_value,
                'raw_full_true_worth' => $row->raw_full_true_worth,
                'raw_true_stake_worth' => $row->raw_true_stake_worth,
                'displayed_full_true_worth' => $row->displayed_full_true_worth,
                'displayed_true_stake_worth' => $row->displayed_true_stake_worth,
                'going_concern_status' => $row->going_concern_status,
                'target_acquisition_financing' => $row->target_acquisition_financing,
            ]])->all(),
        ];
    }

    /** @param array<string, mixed> $before */
    private function assertSnapshotUnchanged(array $before, CfPlusAuctionEconomicsSnapshot $after): void
    {
        $this->assertSame($before['id'], $after->id);
        $this->assertSame($before['created_at'], $after->created_at->format('Y-m-d H:i:s.u'));
        $this->assertSame($before['valuation_date'], $after->valuation_date->format('Y-m-d H:i:s.u'));
        $this->assertSame($before['source_universe_hash'], $after->source_universe_hash);
        $rows = $after->economics()->orderBy('firm_id')->get()->keyBy('firm_id');
        $this->assertCount(count($before['rows']), $rows);
        foreach ($before['rows'] as $firmId => $expected) {
            $row = $rows->get($firmId);
            $this->assertNotNull($row);
            $this->assertSame($expected['id'], $row->id);
            $this->assertSame($expected['created_at'], $row->created_at->format('Y-m-d H:i:s.u'));
            $this->assertSame($expected['boundary_result_id'], $row->boundary_result_id);
            $this->assertSame($expected['boundary_result_hash'], $row->boundary_result_hash);
            $this->assertSame($expected['source_result_ids'], $row->source_result_ids);
            $this->assertSame($expected['source_result_hashes'], $row->source_result_hashes);
            $this->assertSame($expected['disclosure_snapshot_id'], $row->disclosure_snapshot_id);
            $this->assertSame($expected['bidder_cash'], $row->bidder_cash);
            $this->assertSame($expected['unused_committed_credit'], $row->unused_committed_credit);
            $this->assertSame($expected['bidder_own_funds'], $row->bidder_own_funds);
            $this->assertSame($expected['normalized_quarterly_cash'], $row->normalized_quarterly_cash);
            $this->assertSame($expected['normalized_annual_cash'], $row->normalized_annual_cash);
            $this->assertSame($expected['full_consultant_value'], $row->full_consultant_value);
            $this->assertSame($expected['consultant_stake_value'], $row->consultant_stake_value);
            $this->assertSame($expected['raw_full_true_worth'], $row->raw_full_true_worth);
            $this->assertSame($expected['raw_true_stake_worth'], $row->raw_true_stake_worth);
            $this->assertSame($expected['displayed_full_true_worth'], $row->displayed_full_true_worth);
            $this->assertSame($expected['displayed_true_stake_worth'], $row->displayed_true_stake_worth);
            $this->assertSame($expected['going_concern_status'], $row->going_concern_status);
            $this->assertSame($expected['target_acquisition_financing'], $row->target_acquisition_financing);
        }
    }

    /** @param array<int, array{Firm, Student}> $participants */
    private function closeRealQuarter(Course $course, CourseQuarter $quarter, array $participants, float $dividend): void
    {
        app(QuarterManagementService::class)->open($course->faculty, $quarter);
        foreach ($participants as [$firm, $student]) {
            app(FirmDecisionService::class)->submit($student, $firm, $quarter, $this->completePayload(), $dividend);
        }
        $this->finishOpenQuarter($course, $quarter);
    }

    private function finishOpenQuarter(Course $course, CourseQuarter $quarter): void
    {
        Queue::fake();
        app(QuarterManagementService::class)->queueClose($course->faculty, $quarter);
        foreach (FirmQuarterClose::query()->where('course_quarter_id', $quarter->id)->get() as $close) {
            app(FirmQuarterCloseService::class)->close($close);
        }
        app(QuarterManagementService::class)->finalize($quarter);
        $this->assertSame(CourseQuarter::CLOSED, $quarter->fresh()->state);
    }
}
