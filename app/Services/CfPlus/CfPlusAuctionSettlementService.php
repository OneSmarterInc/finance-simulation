<?php

namespace App\Services\CfPlus;

use App\Models\CfPlusAuctionResult;
use App\Models\CfPlusOffer;
use App\Models\Course;
use App\Models\CourseFacultyRole;
use App\Models\CourseQuarter;
use App\Models\Faculty;
use App\Models\Firm;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class CfPlusAuctionSettlementService
{
    public function __construct(
        private readonly CfPlusGate $gate,
        private readonly CfPlusAuctionEconomicsSnapshotService $snapshots,
        private readonly CfPlusSettlementHook $hook,
    ) {}

    /**
     * @param  array<string, string>  $overrides  target firm id => tied winner firm id
     * @return Collection<int, CfPlusAuctionResult>
     */
    public function settle(Faculty $faculty, Course $course, array $overrides = [], ?string $reason = null): Collection
    {
        $this->authorizeFaculty($faculty, $course);
        $this->gate->assertEnabled($course);
        // Quarters 1 to 3 are the warm-up: they hold history and never open or close.
        if ($course->quarters()->where('quarter_number', '>=', 4)->where('state', '!=', CourseQuarter::CLOSED)->exists()) {
            throw new DomainException('The sealed auction can settle only after every course quarter is closed.');
        }

        $auctionSnapshot = $this->snapshots->forCourse($course);
        $economics = $auctionSnapshot->economics()->get()->keyBy('firm_id');

        return DB::transaction(function () use ($faculty, $course, $overrides, $reason, $economics): Collection {
            $existing = CfPlusAuctionResult::query()->where('course_id', $course->id)->lockForUpdate()->get();
            if ($existing->isNotEmpty()) {
                if ($existing->count() !== $course->firms()->count()) {
                    throw new DomainException('A partial auction settlement exists and requires review.');
                }

                return $existing;
            }

            $now = now();
            CfPlusOffer::query()->where('course_id', $course->id)->update(['locked_at' => $now]);
            foreach ($course->firms()->orderBy('id')->get() as $target) {
                $offers = CfPlusOffer::query()->where('course_id', $course->id)->where('target_firm_id', $target->id)->where('offered_amount', '>', 0)->orderByDesc('offered_amount')->orderBy('submitted_at')->orderBy('id')->get();
                $top = $offers->first();
                $tied = $top ? $offers->filter(fn (CfPlusOffer $offer): bool => $offer->offered_amount === $top->offered_amount)->values() : collect();
                $normal = $tied->first();
                $winner = $normal;
                $override = $overrides[$target->id] ?? null;
                if ($override !== null) {
                    if ($tied->count() < 2 || ! $tied->contains(fn (CfPlusOffer $offer): bool => $offer->bidder_firm_id === $override)) {
                        throw new DomainException('Faculty may override only among bidders tied for the exact top offer.');
                    }
                    $winner = $tied->first(fn (CfPlusOffer $offer): bool => $offer->bidder_firm_id === $override);
                }

                $targetEconomics = $economics->get($target->id) ?? throw new DomainException('Frozen target auction economics are unavailable at settlement.');
                $true = (float) $targetEconomics->raw_full_true_worth;
                $displayedConsultant = (float) $targetEconomics->full_consultant_value;
                $displayedTrue = (float) $targetEconomics->displayed_full_true_worth;

                $result = CfPlusAuctionResult::query()->create([
                    'course_id' => $course->id, 'target_firm_id' => $target->id, 'institution_id' => $course->institution_id,
                    'normal_winner_firm_id' => $normal?->bidder_firm_id, 'winning_bidder_firm_id' => $winner?->bidder_firm_id, 'winning_offer_id' => $winner?->id,
                    'price_paid' => $winner?->offered_amount, 'stake' => CfPlusAcquisitionFundingService::CONTROL_STAKE,
                    'full_consultant_value' => round($displayedConsultant, 2), 'consultant_stake_value' => $targetEconomics->consultant_stake_value,
                    'raw_full_true_worth' => round($true, 2), 'displayed_full_true_worth' => round($displayedTrue, 2), 'true_stake_worth' => $targetEconomics->displayed_true_stake_worth,
                    'winning_fundable_cap' => $winner?->fundable_cap, 'tied_top_bidder_ids' => $tied->pluck('bidder_firm_id')->all(),
                    'faculty_override' => $override !== null, 'override_faculty_id' => $override !== null ? $faculty->id : null,
                    'override_reason' => $override !== null ? $reason : null, 'overridden_at' => $override !== null ? $now : null, 'settled_at' => $now,
                ]);
                $this->hook->afterResultPersisted($result);
            }

            return CfPlusAuctionResult::query()->where('course_id', $course->id)->orderBy('target_firm_id')->get();
        });
    }

    public function authorizeFaculty(Faculty $faculty, Course $course): void
    {
        $authorized = $faculty->institution_id === $course->institution_id
            && ($course->faculty_id === $faculty->id || CourseFacultyRole::query()->where('course_id', $course->id)->where('faculty_id', $faculty->id)->exists());
        abort_unless($authorized, 403);
    }

    public function overrideTie(Faculty $faculty, CfPlusAuctionResult $result, Firm $winner, string $reason): CfPlusAuctionResult
    {
        $result->loadMissing('course');
        $this->authorizeFaculty($faculty, $result->course);
        if (count($result->tied_top_bidder_ids) < 2 || ! in_array($winner->id, $result->tied_top_bidder_ids, true) || $winner->course_id !== $result->course_id) {
            throw new DomainException('Faculty may override only among bidders tied for the exact top offer.');
        }
        $offer = CfPlusOffer::query()->where('course_id', $result->course_id)->where('target_firm_id', $result->target_firm_id)->where('bidder_firm_id', $winner->id)->where('offered_amount', $result->price_paid)->firstOrFail();
        $result->update(['winning_bidder_firm_id' => $winner->id, 'winning_offer_id' => $offer->id, 'winning_fundable_cap' => $offer->fundable_cap, 'faculty_override' => true, 'override_faculty_id' => $faculty->id, 'override_reason' => $reason, 'overridden_at' => now()]);

        return $result->fresh();
    }
}
