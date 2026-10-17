<?php

namespace Tests\Feature\Phase9;

use App\Livewire\Faculty\Courses\CfPlusAuction;
use App\Livewire\Student\CfPlusEvaluation;
use App\Models\CfPlusAuctionResult;
use App\Models\CfPlusOffer;
use App\Models\Course;
use App\Models\CourseQuarter;
use App\Models\Enrollment;
use App\Models\Faculty;
use App\Models\Firm;
use App\Models\FirmEngineResult;
use App\Models\FirmMembership;
use App\Models\Scenario;
use App\Models\Student;
use App\Services\CfPlus\CfPlusAcquisitionFundingService;
use App\Services\CfPlus\CfPlusAuctionEconomicsSnapshotService;
use App\Services\CfPlus\CfPlusAuctionSettlementService;
use App\Services\CfPlus\CfPlusGate;
use App\Services\CfPlus\CfPlusOfferService;
use App\Services\CfPlus\CfPlusRevealService;
use App\Services\CfPlus\CfPlusSettlementHook;
use App\Services\CfPlus\CfPlusValuationConfiguration;
use App\Services\CfPlus\FirmAccountingElectionService;
use App\Support\Scenario\CfManufacturingScenarioDefinition;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CfPlusAcquisitionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_amendment_two_configuration_and_full_target_funding_are_literal_and_operationally_isolated(): void
    {
        [$course, $firms] = $this->market();
        $context = app(CfPlusAcquisitionFundingService::class)->calculate($course, $firms['alpha'], $firms['bravo']);
        $config = CfPlusValuationConfiguration::approved();

        $this->assertSame(0.12, $config['annual_required_return']);
        $this->assertEqualsWithDelta(0.028737344722080, $config['quarterly_discount_rate'], 0.000000000000001);
        $this->assertSame(0.02, $config['quarterly_growth_cap']);
        $this->assertSame(12, $config['forecast_quarters']);
        $this->assertSame('gordon_growth', $config['terminal_method']);
        $this->assertSame(0.015, $config['annual_terminal_growth']);
        $this->assertLessThan($config['quarterly_discount_rate'], $config['quarterly_terminal_growth']);
        $this->assertSame(100000000.0, $context['bidder_cash']);
        $this->assertSame(40000000.0, $context['unused_committed_credit']);
        $this->assertSame(41000000.0, $context['annual_normalized_recurring_cash']);
        $this->assertSame(205000000.0, $context['target_acquisition_financing']);
        $this->assertSame(345000000.0, $context['maximum_fundable_offer']);
        $this->assertNotSame(104550000.0, $context['target_acquisition_financing']);
        $firms['bravo']->consultantReports()->firstOrFail()->update(['consultant_value' => 9999999999.99]);
        $this->assertSame($context, app(CfPlusAcquisitionFundingService::class)->calculate($course, $firms['alpha'], $firms['bravo']));
        $this->assertSame(0, $firms['alpha']->engineResults()->where('quarter_number', '>', 5)->count());
        $this->assertFalse(Schema::hasTable('acquisition_debts'));
        $this->assertFalse(Schema::hasTable('consolidated_statements'));
    }

    public function test_offer_guardrail_uses_binding_timestamp_preserves_zero_and_rejects_without_clamping(): void
    {
        [$course, $firms] = $this->market();
        $service = app(CfPlusOfferService::class);
        $context = $service->fundingContext($course, $firms['alpha'], $firms['bravo']);
        Carbon::setTestNow('2026-08-11 10:00:00');
        $offer = $service->submit($course, $firms['alpha'], $firms['bravo'], $context['maximum_fundable_offer']);
        $this->assertSame('345000000.00', $offer->offered_amount);
        $this->assertSame('2026-08-11 10:00:00', $offer->submitted_at->format('Y-m-d H:i:s'));

        Carbon::setTestNow('2026-08-11 11:00:00');
        $edited = $service->submit($course, $firms['alpha'], $firms['bravo'], 300000000.00);
        $this->assertSame('2026-08-11 11:00:00', $edited->submitted_at->format('Y-m-d H:i:s'));
        Carbon::setTestNow('2026-08-11 12:00:00');
        $unchanged = $service->submit($course, $firms['alpha'], $firms['bravo'], 300000000.00);
        $this->assertSame('2026-08-11 11:00:00', $unchanged->submitted_at->format('Y-m-d H:i:s'));

        try {
            $service->submit($course, $firms['alpha'], $firms['bravo'], 345000000.01);
            $this->fail('An over-cap offer was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame('Offer exceeds the maximum fundable offer of $345,000,000.00.', $exception->errors()['offer'][0]);
        }
        $afterRejection = $edited->fresh();
        $this->assertSame('300000000.00', $afterRejection->offered_amount);
        $this->assertSame('2026-08-11 11:00:00', $afterRejection->submitted_at->format('Y-m-d H:i:s'));
        $this->assertSame('345000000.00', $afterRejection->fundable_cap);

        Carbon::setTestNow('2026-08-11 13:00:00');
        $zero = $service->submit($course, $firms['alpha'], $firms['bravo'], 0);
        $this->assertSame('0.00', $zero->offered_amount);
        $this->assertSame('2026-08-11 13:00:00', $zero->submitted_at->format('Y-m-d H:i:s'));
        $this->assertNull($zero->deleted_at);
        Carbon::setTestNow();
    }

    public function test_rival_course_toggle_window_and_negative_offer_boundaries_are_enforced(): void
    {
        [$course, $firms] = $this->market();
        $service = app(CfPlusOfferService::class);
        $this->expectDomain(fn () => $service->submit($course, $firms['alpha'], $firms['alpha'], 1), 'rival firms');
        try {
            $service->submit($course, $firms['alpha'], $firms['bravo'], -0.01);
            $this->fail('A negative offer was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame('Offer must be zero or greater.', $exception->errors()['offer'][0]);
        }

        $other = $this->course(true, 'Other course');
        $outsider = $this->firm($other, 'Outsider');
        $this->expectDomain(fn () => $service->submit($course, $firms['alpha'], $outsider, 1), 'rival firms in the same course');
        $plain = $this->course(false, 'Plain CF');
        $plainBidder = $this->firm($plain, 'Plain A');
        $plainTarget = $this->firm($plain, 'Plain B');
        $this->expectDomain(fn () => $service->submit($plain, $plainBidder, $plainTarget, 1), 'has no evaluation stage');
        $course->quarters()->where('state', CourseQuarter::OPEN)->update(['state' => CourseQuarter::CLOSED]);
        $this->expectDomain(fn () => $service->submit($course, $firms['alpha'], $firms['bravo'], 1), 'evaluation-stage quarter is open');
        $locked = $this->course(true, 'Locked evaluation');
        $locked->quarters()->update(['state' => CourseQuarter::LOCKED]);
        $this->expectDomain(fn () => app(CfPlusGate::class)->assertEvaluationAvailable($locked), 'has not opened');
    }

    public function test_sealed_auction_is_first_price_tie_stable_multi_win_no_win_unsold_idempotent_and_override_audited(): void
    {
        [$course, $firms] = $this->market();
        $offers = app(CfPlusOfferService::class);
        Carbon::setTestNow('2026-08-11 08:59:00');
        $offers->submit($course, $firms['bravo'], $firms['delta'], 60000000);
        Carbon::setTestNow('2026-08-11 09:00:00');
        $offers->submit($course, $firms['alpha'], $firms['bravo'], 100000000);
        $offers->submit($course, $firms['alpha'], $firms['charlie'], 90000000);
        $offers->submit($course, $firms['alpha'], $firms['delta'], 60000000);
        $offers->submit($course, $firms['bravo'], $firms['charlie'], 70000000);
        Carbon::setTestNow('2026-08-11 09:01:00');
        $offers->submit($course, $firms['charlie'], $firms['delta'], 60000000);
        $offers->submit($course, $firms['delta'], $firms['bravo'], 80000000);
        $course->quarters()->update(['state' => CourseQuarter::CLOSED]);

        $service = app(CfPlusAuctionSettlementService::class);
        $faculty = $course->faculty;
        $first = $service->settle($faculty, $course);
        $second = $service->settle($faculty, $course);
        $this->assertCount(4, $first);
        $this->assertSame($first->pluck('id')->sort()->values()->all(), $second->pluck('id')->sort()->values()->all());
        $this->assertSame(4, CfPlusAuctionResult::query()->where('course_id', $course->id)->count());
        $this->assertNull($this->auctionResult($course, $firms['alpha'])->winning_bidder_firm_id);
        $this->assertSame($firms['alpha']->id, $this->auctionResult($course, $firms['bravo'])->winning_bidder_firm_id);
        $this->assertSame('100000000.00', $this->auctionResult($course, $firms['bravo'])->price_paid);
        $this->assertSame($firms['alpha']->id, $this->auctionResult($course, $firms['charlie'])->winning_bidder_firm_id);
        $delta = $this->auctionResult($course, $firms['delta']);
        $this->assertSame($firms['bravo']->id, $delta->winning_bidder_firm_id);
        $this->assertSame([$firms['bravo']->id, $firms['alpha']->id, $firms['charlie']->id], $delta->tied_top_bidder_ids);
        $this->assertFalse($delta->faculty_override);
        $overridden = $service->overrideTie($faculty, $delta, $firms['charlie'], 'Faculty accepted the tied alternative presentation.');
        $this->assertSame($firms['charlie']->id, $overridden->winning_bidder_firm_id);
        $this->assertTrue($overridden->faculty_override);
        $this->assertSame($faculty->id, $overridden->override_faculty_id);
        $this->assertNotNull($overridden->overridden_at);
        $this->assertDatabaseCount('cf_plus_auction_results', 4);
        $this->expectDomain(fn () => $service->overrideTie($faculty, $delta, $firms['delta'], 'Invalid'), 'tied for the exact top offer');
        $this->expectDomain(fn () => $offers->submit($course, $firms['alpha'], $firms['bravo'], 1), 'final once the evaluation stage has been settled');
        Carbon::setTestNow();
    }

    public function test_reveal_exposes_facts_for_each_bidder_without_scores_and_preserves_distressed_internal_sign(): void
    {
        [$course, $firms] = $this->market(['delta' => [-10000000, -12000000, -11000000]]);
        $offers = app(CfPlusOfferService::class);
        $offers->submit($course, $firms['alpha'], $firms['bravo'], 100000000);
        $offers->submit($course, $firms['alpha'], $firms['delta'], 50000000);
        $course->quarters()->update(['state' => CourseQuarter::CLOSED]);
        app(CfPlusAuctionSettlementService::class)->settle($course->faculty, $course);
        $reveal = app(CfPlusRevealService::class)->forTarget($course, $firms['alpha'], $firms['bravo']);
        $distressed = app(CfPlusRevealService::class)->forTarget($course, $firms['alpha'], $firms['delta']);

        $this->assertSame(100000000.0, $reveal['viewer_offer']);
        $this->assertSame(345000000.0, $reveal['viewer_fundable_cap']);
        $this->assertSame(100000000.0, $reveal['price_paid']);
        $this->assertContains($reveal['outcome'], ['overpaid', 'underpaid', 'exact']);
        $this->assertArrayNotHasKey('score', $reveal);
        $this->assertArrayNotHasKey('grade', $reveal);
        $this->assertSame(0.0, $distressed['full_consultant_value']);
        $this->assertSame(0.0, $distressed['full_true_worth']);
        $this->assertSame(0.0, $distressed['true_stake_worth']);
        $this->assertSame('NO POSITIVE GOING-CONCERN VALUE', $distressed['going_concern_status']);
        $this->assertLessThan(0, (float) $this->auctionResult($course, $firms['delta'])->raw_full_true_worth);
        $this->assertSame('0.00', CfPlusOffer::query()->where('target_firm_id', $firms['delta']->id)->value('target_acquisition_financing'));
        $losing = app(CfPlusRevealService::class)->forTarget($course, $firms['alpha'], $firms['delta']);
        $this->assertSame(50000000.0, $losing['viewer_offer']);
        $this->assertGreaterThan(50000000.0, $losing['viewer_fundable_cap']);
        foreach (['grades', 'scores', 'subsidiaries', 'consolidated_statements', 'post_bid_quarters'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
    }

    public function test_student_workspace_displays_maximum_hides_rival_offers_and_faculty_sees_participation_only_before_settlement(): void
    {
        [$course, $firms] = $this->market();
        app(CfPlusOfferService::class)->submit($course, $firms['charlie'], $firms['bravo'], 12345678.90);
        $student = Student::query()->firstOrFail();

        Livewire::actingAs($student->user)->test(CfPlusEvaluation::class, ['firm' => $firms['alpha']])
            ->assertSee('Maximum fundable offer')
            ->assertSee('$345,000,000.00')
            ->assertSee('Implied 51% consultant value')
            ->assertDontSee('12,345,678.90')
            ->set('offerAmounts.'.$firms['bravo']->id, '345000000.01')
            ->call('saveOffer', $firms['bravo']->id)
            ->assertHasErrors('offerAmounts.'.$firms['bravo']->id)
            ->set('offerAmounts.'.$firms['bravo']->id, '345000000.00')
            ->call('saveOffer', $firms['bravo']->id)
            ->assertSee('Sealed offer saved.');

        Livewire::actingAs($course->faculty->user)->test(CfPlusAuction::class, ['course' => $course])
            ->assertSee('Participation')
            ->assertSee('submitted offers')
            ->assertDontSee('12,345,678.90');
    }

    public function test_binding_offer_edit_prevents_placeholder_priority_gaming_and_uuid_is_final_fallback(): void
    {
        [$course, $firms] = $this->market();
        $offers = app(CfPlusOfferService::class);
        Carbon::setTestNow('2026-08-11 10:00:00');
        $offers->submit($course, $firms['alpha'], $firms['delta'], 1);
        Carbon::setTestNow('2026-08-11 10:30:00');
        $bravo = $offers->submit($course, $firms['bravo'], $firms['delta'], 100000000);
        Carbon::setTestNow('2026-08-11 11:00:00');
        $alpha = $offers->submit($course, $firms['alpha'], $firms['delta'], 100000000);
        $this->assertTrue($bravo->submitted_at->lessThan($alpha->submitted_at));

        $course->quarters()->update(['state' => CourseQuarter::CLOSED]);
        $first = app(CfPlusAuctionSettlementService::class)->settle($course->faculty, $course);
        $result = $first->firstWhere('target_firm_id', $firms['delta']->id);
        $this->assertSame($firms['bravo']->id, $result->winning_bidder_firm_id);
        $this->assertSame('100000000.00', $result->price_paid);
        $this->assertSame($first->pluck('id')->all(), app(CfPlusAuctionSettlementService::class)->settle($course->faculty, $course)->pluck('id')->all());
        Carbon::setTestNow();

        [$fallbackCourse, $fallbackFirms] = $this->market();
        Carbon::setTestNow('2026-08-11 12:00:00');
        $one = app(CfPlusOfferService::class)->submit($fallbackCourse, $fallbackFirms['alpha'], $fallbackFirms['delta'], 50000000);
        $two = app(CfPlusOfferService::class)->submit($fallbackCourse, $fallbackFirms['bravo'], $fallbackFirms['delta'], 50000000);
        $three = app(CfPlusOfferService::class)->submit($fallbackCourse, $fallbackFirms['charlie'], $fallbackFirms['delta'], 50000000);
        $fallbackCourse->quarters()->update(['state' => CourseQuarter::CLOSED]);
        $fallback = app(CfPlusAuctionSettlementService::class)->settle($fallbackCourse->faculty, $fallbackCourse)->firstWhere('target_firm_id', $fallbackFirms['delta']->id);
        $ordered = collect([$one, $two, $three])->sortBy('id')->values();
        $this->assertSame($ordered->first()->bidder_firm_id, $fallback->winning_bidder_firm_id);
        $this->assertSame($ordered->pluck('bidder_firm_id')->all(), $fallback->tied_top_bidder_ids);
        Carbon::setTestNow();
    }

    public function test_explicit_zero_is_persisted_revealed_and_never_competes_as_a_winning_offer(): void
    {
        [$course, $firms] = $this->market(['delta' => [-10000000, -12000000, -11000000]]);
        $zero = app(CfPlusOfferService::class)->submit($course, $firms['bravo'], $firms['delta'], 0);
        $this->assertSame('0.00', $zero->offered_amount);
        $this->assertNotNull($zero->submitted_at);
        $this->assertDatabaseMissing('cf_plus_offers', ['bidder_firm_id' => $firms['alpha']->id, 'target_firm_id' => $firms['delta']->id]);
        $course->quarters()->update(['state' => CourseQuarter::CLOSED]);
        app(CfPlusAuctionSettlementService::class)->settle($course->faculty, $course);
        $result = $this->auctionResult($course, $firms['delta']);
        $this->assertNull($result->winning_bidder_firm_id);
        $this->assertNull($result->price_paid);
        $zeroReveal = app(CfPlusRevealService::class)->forTarget($course, $firms['bravo'], $firms['delta']);
        $neverReveal = app(CfPlusRevealService::class)->forTarget($course, $firms['alpha'], $firms['delta']);
        $this->assertSame(0.0, $zeroReveal['viewer_offer']);
        $this->assertNotNull($zeroReveal['viewer_fundable_cap']);
        $this->assertNull($neverReveal['viewer_offer']);
        $this->assertNull($neverReveal['viewer_fundable_cap']);
        $this->assertSame('NO POSITIVE GOING-CONCERN VALUE', $zeroReveal['going_concern_status']);
    }

    public function test_settlement_is_deeply_idempotent_and_injected_failure_rolls_back_every_write(): void
    {
        [$course, $firms] = $this->market();
        app(CfPlusOfferService::class)->submit($course, $firms['alpha'], $firms['bravo'], 100000000);
        app(CfPlusOfferService::class)->submit($course, $firms['bravo'], $firms['charlie'], 90000000);
        $course->quarters()->update(['state' => CourseQuarter::CLOSED]);
        $engineHashes = FirmEngineResult::query()->where('course_id', $course->id)->orderBy('id')->pluck('payload_hash')->all();

        $hook = new class extends CfPlusSettlementHook
        {
            public int $calls = 0;

            public function afterResultPersisted(CfPlusAuctionResult $result): void
            {
                if (++$this->calls === 1) {
                    throw new \RuntimeException('Injected settlement failure.');
                }
            }
        };
        app()->instance(CfPlusSettlementHook::class, $hook);
        try {
            app(CfPlusAuctionSettlementService::class)->settle($course->faculty, $course);
            $this->fail('Injected failure did not abort settlement.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected settlement failure.', $exception->getMessage());
        }
        $this->assertDatabaseCount('cf_plus_auction_results', 0);
        $this->assertSame(0, CfPlusOffer::query()->where('course_id', $course->id)->whereNotNull('locked_at')->count());
        $this->assertSame($engineHashes, FirmEngineResult::query()->where('course_id', $course->id)->orderBy('id')->pluck('payload_hash')->all());

        app()->instance(CfPlusSettlementHook::class, new CfPlusSettlementHook);
        $service = app(CfPlusAuctionSettlementService::class);
        $first = $service->settle($course->faculty, $course);
        $snapshot = $first->map(fn (CfPlusAuctionResult $result): array => [$result->id, $result->winning_bidder_firm_id, $result->winning_offer_id, $result->price_paid, $result->winning_fundable_cap, $result->settled_at->format('Y-m-d H:i:s.u'), $result->faculty_override, $result->override_faculty_id, $result->override_reason, $result->overridden_at?->format('Y-m-d H:i:s.u')])->all();
        $offerSnapshot = CfPlusOffer::query()->where('course_id', $course->id)->orderBy('id')->get()->map->getAttributes()->all();
        $second = $service->settle($course->faculty, $course);
        $this->assertCount(4, $first);
        $this->assertSame($snapshot, $second->map(fn (CfPlusAuctionResult $result): array => [$result->id, $result->winning_bidder_firm_id, $result->winning_offer_id, $result->price_paid, $result->winning_fundable_cap, $result->settled_at->format('Y-m-d H:i:s.u'), $result->faculty_override, $result->override_faculty_id, $result->override_reason, $result->overridden_at?->format('Y-m-d H:i:s.u')])->all());
        $this->assertSame($offerSnapshot, CfPlusOffer::query()->where('course_id', $course->id)->orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame($engineHashes, FirmEngineResult::query()->where('course_id', $course->id)->orderBy('id')->pluck('payload_hash')->all());
    }

    public function test_faculty_reveal_returns_all_saved_bidder_caps_only_after_settlement_and_student_reveal_is_viewer_scoped(): void
    {
        [$course, $firms] = $this->market();
        $offers = app(CfPlusOfferService::class);
        $alpha = $offers->submit($course, $firms['alpha'], $firms['delta'], 100000000);
        $bravo = $offers->submit($course, $firms['bravo'], $firms['delta'], 90000000);
        $zero = $offers->submit($course, $firms['charlie'], $firms['delta'], 0);
        $snapshot = app(CfPlusAuctionEconomicsSnapshotService::class)->forCourse($course);
        $economics = $snapshot->economics()->get()->keyBy('firm_id');
        $savedCaps = [$alpha->id => $alpha->fundable_cap, $bravo->id => $bravo->fundable_cap, $zero->id => $zero->fundable_cap];
        $firms['delta']->consultantReports()->firstOrFail()->update(['consultant_value' => 1]);

        try {
            app(CfPlusRevealService::class)->forFaculty($course, $course->faculty);
            $this->fail('Faculty received sealed values before settlement.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('unavailable before settlement', $exception->getMessage());
        }
        $course->quarters()->update(['state' => CourseQuarter::CLOSED]);
        app(CfPlusAuctionSettlementService::class)->settle($course->faculty, $course);
        $evidence = collect(app(CfPlusRevealService::class)->forFaculty($course, $course->faculty))->where('target_id', $firms['delta']->id)->values();
        $this->assertCount(3, $evidence);
        $this->assertSame([100000000.0, 90000000.0, 0.0], $evidence->pluck('offered_amount')->all());
        foreach ([$alpha, $bravo, $zero] as $offer) {
            $row = $evidence->firstWhere('bidder_id', $offer->bidder_firm_id);
            $bidder = $economics->get($offer->bidder_firm_id);
            $target = $economics->get($offer->target_firm_id);
            $this->assertSame((float) $savedCaps[$offer->id], $row['fundable_cap']);
            $this->assertSame($snapshot->valuation_date->toDateString(), $row['valuation_date']);
            $this->assertSame((float) $bidder->bidder_cash, (float) $offer->bidder_cash);
            $this->assertSame((float) $bidder->unused_committed_credit, (float) $offer->unused_committed_credit);
            $this->assertSame((float) $target->target_acquisition_financing, (float) $offer->target_acquisition_financing);
            $this->assertSame(round((float) $bidder->bidder_cash + (float) $bidder->unused_committed_credit + (float) $target->target_acquisition_financing, 2), $row['fundable_cap']);
            $this->assertSame((float) $target->consultant_stake_value, $row['consultant_stake_value']);
            $this->assertSame((float) $target->displayed_true_stake_worth, $row['true_stake_worth']);
        }
        $studentReveal = app(CfPlusRevealService::class)->forTarget($course, $firms['alpha'], $firms['delta']);
        $this->assertSame(100000000.0, $studentReveal['viewer_offer']);
        $this->assertSame((float) $alpha->fundable_cap, $studentReveal['viewer_fundable_cap']);
        $this->assertSame($snapshot->valuation_date->toDateString(), $studentReveal['valuation_date']);
        $this->assertSame((float) $economics->get($firms['delta']->id)->full_consultant_value, $studentReveal['full_consultant_value']);
        $this->assertSame((float) $economics->get($firms['delta']->id)->consultant_stake_value, $studentReveal['consultant_stake_value']);
        $this->assertSame((float) $economics->get($firms['delta']->id)->displayed_true_stake_worth, $studentReveal['true_stake_worth']);
        $this->assertArrayNotHasKey('all_bidder_offers', $studentReveal);
        $this->assertArrayNotHasKey('ranking', $studentReveal);
        $this->assertArrayNotHasKey('leader', $studentReveal);
    }

    public function test_invalid_tie_override_cannot_mutate_settled_result(): void
    {
        [$course, $firms] = $this->market();
        Carbon::setTestNow('2026-08-11 09:00:00');
        app(CfPlusOfferService::class)->submit($course, $firms['alpha'], $firms['delta'], 100);
        Carbon::setTestNow('2026-08-11 09:01:00');
        app(CfPlusOfferService::class)->submit($course, $firms['bravo'], $firms['delta'], 100);
        app(CfPlusOfferService::class)->submit($course, $firms['charlie'], $firms['delta'], 90);
        $course->quarters()->update(['state' => CourseQuarter::CLOSED]);
        $service = app(CfPlusAuctionSettlementService::class);
        $result = $service->settle($course->faculty, $course)->firstWhere('target_firm_id', $firms['delta']->id);
        $before = $result->getAttributes();
        $this->expectDomain(fn () => $service->overrideTie($course->faculty, $result, $firms['charlie'], 'Invalid lower bid'), 'tied for the exact top offer');
        $this->assertSame($before, $result->fresh()->getAttributes());
        $valid = $service->overrideTie($course->faculty, $result, $firms['bravo'], 'Valid tied top override');
        $this->assertSame($firms['bravo']->id, $valid->winning_bidder_firm_id);
        $this->assertSame('100.00', $valid->price_paid);
        $this->assertTrue($valid->faculty_override);
        Carbon::setTestNow();
    }

    public function test_first_price_outcomes_cover_highest_bid_no_offer_no_win_and_multiple_wins(): void
    {
        [$course, $firms] = $this->market();
        $offers = app(CfPlusOfferService::class);
        $offers->submit($course, $firms['alpha'], $firms['bravo'], 100);
        $offers->submit($course, $firms['charlie'], $firms['bravo'], 90);
        $offers->submit($course, $firms['alpha'], $firms['charlie'], 80);
        $course->quarters()->update(['state' => CourseQuarter::CLOSED]);
        app(CfPlusAuctionSettlementService::class)->settle($course->faculty, $course);
        $bravo = $this->auctionResult($course, $firms['bravo']);
        $charlie = $this->auctionResult($course, $firms['charlie']);
        $alpha = $this->auctionResult($course, $firms['alpha']);
        $delta = $this->auctionResult($course, $firms['delta']);
        $this->assertSame($firms['alpha']->id, $bravo->winning_bidder_firm_id);
        $this->assertSame('100.00', $bravo->price_paid);
        $this->assertSame($firms['alpha']->id, $charlie->winning_bidder_firm_id);
        $this->assertSame('80.00', $charlie->price_paid);
        $this->assertNull($alpha->winning_bidder_firm_id);
        $this->assertNull($delta->winning_bidder_firm_id);
        $this->assertSame(2, CfPlusAuctionResult::query()->where('winning_bidder_firm_id', $firms['alpha']->id)->count());
        $this->assertSame(0, CfPlusAuctionResult::query()->where('winning_bidder_firm_id', $firms['bravo']->id)->count());
    }

    public function test_override_authorization_rejects_cross_course_bidder_and_unrelated_faculty(): void
    {
        [$course, $firms] = $this->market();
        Carbon::setTestNow('2026-08-11 09:00:00');
        app(CfPlusOfferService::class)->submit($course, $firms['alpha'], $firms['delta'], 100);
        app(CfPlusOfferService::class)->submit($course, $firms['bravo'], $firms['delta'], 100);
        $course->quarters()->update(['state' => CourseQuarter::CLOSED]);
        $service = app(CfPlusAuctionSettlementService::class);
        $result = $service->settle($course->faculty, $course)->firstWhere('target_firm_id', $firms['delta']->id);
        $otherCourse = $this->course(true, 'Cross course');
        $outsider = $this->firm($otherCourse, 'Cross-course bidder');
        $before = $result->getAttributes();
        $this->expectDomain(fn () => $service->overrideTie($course->faculty, $result, $outsider, 'Invalid'), 'tied for the exact top offer');
        $this->assertSame($before, $result->fresh()->getAttributes());
        $unrelated = Faculty::factory()->create();
        try {
            $service->overrideTie($unrelated, $result, $firms['bravo'], 'Unauthorized');
            $this->fail('Unrelated faculty override was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame($before, $result->fresh()->getAttributes());
        Carbon::setTestNow();
    }

    public function test_student_component_never_hydrates_another_bidder_sealed_values(): void
    {
        [$course, $firms] = $this->market();
        app(CfPlusOfferService::class)->submit($course, $firms['charlie'], $firms['bravo'], 12345678.90);
        $student = Student::query()->firstOrFail();
        $component = Livewire::actingAs($student->user)->test(CfPlusEvaluation::class, ['firm' => $firms['alpha']]);
        $serialized = json_encode($component->get('offerAmounts'), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('12345678.90', $serialized);
        $this->assertArrayNotHasKey($firms['bravo']->id, $component->get('offerAmounts'));
        $component->call('saveOffer', $firms['bravo']->id)->assertHasErrors('offerAmounts.'.$firms['bravo']->id);
        $this->assertSame('12345678.90', CfPlusOffer::query()->where('bidder_firm_id', $firms['charlie']->id)->where('target_firm_id', $firms['bravo']->id)->value('offered_amount'));
    }

    /** @return array{Course, array<string, Firm>} */
    private function market(array $overrides = []): array
    {
        $course = $this->course(true, 'Phase 9 Market');
        $series = ['alpha' => [10000000, 12000000, 11000000], 'bravo' => [10000000, 12000000, 11000000], 'charlie' => [11000000, 13000000, 12000000], 'delta' => [9000000, 10000000, 9500000]];
        $firms = [];
        foreach ($series as $key => $income) {
            $firms[$key] = $this->firm($course, ucfirst($key));
            $this->history($firms[$key], $overrides[$key] ?? $income);
        }

        $student = Student::query()->firstOrFail();
        Enrollment::query()->create([
            'course_id' => $course->id,
            'student_id' => $student->id,
            'institution_id' => $course->institution_id,
        ]);
        FirmMembership::factory()->create([
            'firm_id' => $firms['alpha']->id,
            'course_id' => $course->id,
            'student_id' => $student->id,
            'institution_id' => $course->institution_id,
            'assigned_by_faculty_id' => $course->faculty_id,
        ]);

        app(CfPlusAuctionEconomicsSnapshotService::class)->createAtEvaluationOpening(
            $course,
            $course->quarters()->where('quarter_number', 6)->firstOrFail(),
        );

        return [$course, $firms];
    }

    private function course(bool $cfPlus, string $name): Course
    {
        $faculty = Faculty::query()->firstOrFail();
        $course = Course::query()->create(['institution_id' => $faculty->institution_id, 'faculty_id' => $faculty->id, 'scenario_id' => Scenario::query()->where('code', 'cf_manufacturing')->value('id'), 'name' => $name, 'starts_on' => '2026-01-01', 'ends_on' => '2028-01-01', 'cf_plus_enabled' => $cfPlus, 'build_quarters' => $cfPlus ? 2 : null, 'adversarial_quarters' => $cfPlus ? 2 : null, 'post_bid_quarters' => $cfPlus ? 1 : null, 'status' => Course::STATUS_DEPLOYED]);
        for ($quarter = 1; $quarter <= 7; $quarter++) {
            CourseQuarter::query()->create(['course_id' => $course->id, 'quarter_number' => $quarter, 'starts_on' => now()->addMonths($quarter), 'ends_on' => now()->addMonths($quarter + 1), 'decision_deadline_at' => now()->addMonths($quarter + 1), 'state' => $quarter === 6 ? CourseQuarter::OPEN : CourseQuarter::CLOSED]);
        }

        return $course;
    }

    private function firm(Course $course, string $name): Firm
    {
        $firm = Firm::factory()->create(['course_id' => $course->id, 'institution_id' => $course->institution_id, 'name' => $name]);
        app(FirmAccountingElectionService::class)->lock($firm, CfManufacturingScenarioDefinition::fixedWarmupPayload('notes_elections'));

        return $firm->fresh();
    }

    /** @param array<int, int|float> $operatingIncome */
    private function history(Firm $firm, array $operatingIncome): void
    {
        foreach (array_values($operatingIncome) as $offset => $income) {
            $quarter = $offset + 3;
            $payload = ['firm_id' => $firm->id, 'course_id' => $firm->course_id, 'institution_id' => $firm->institution_id, 'quarter_number' => $quarter, 'fields' => ['bs_cash' => 100000000.0, 'n5_limit' => 50000000.0, 'n5_balance' => 10000000.0, 'bs_bank_overdraft' => 999999999.0], 'income_statement' => ['net_revenue' => $income * 4, 'operating_income' => $income, 'net_income' => $income * 0.75], 'balance_sheet' => ['assets' => 200000000, 'liabilities' => 50000000, 'equity' => 150000000, 'line_items' => []], 'cash_flow_statement' => ['operating_cash_flow' => $income, 'investing_cash_flow' => 0, 'financing_cash_flow' => 0, 'ending_cash' => 100000000, 'line_items' => ['cf_depr' => 2000000]], 'notes' => ['accounting_policies' => $firm->accounting_elections], 'intermediates' => [], 'engine_contract' => FirmEngineResult::ENGINE_CONTRACT, 'provenance' => FirmEngineResult::PROVENANCE_STUDENT_CLOSE, 'immutable' => true, 'validated_at' => now()];
            FirmEngineResult::query()->create($payload + ['payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR))]);
        }
    }

    private function auctionResult(Course $course, Firm $target): CfPlusAuctionResult
    {
        return CfPlusAuctionResult::query()->where('course_id', $course->id)->where('target_firm_id', $target->id)->firstOrFail();
    }

    private function expectDomain(callable $callback, string $messageFragment): void
    {
        try {
            $callback();
            $this->fail('Expected domain rejection did not occur.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString($messageFragment, $exception->getMessage());
        }
    }
}
