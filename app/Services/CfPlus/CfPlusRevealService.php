<?php

namespace App\Services\CfPlus;

use App\Models\CfPlusAuctionResult;
use App\Models\CfPlusOffer;
use App\Models\Course;
use App\Models\Faculty;
use App\Models\Firm;
use DomainException;

final class CfPlusRevealService
{
    public function __construct(private readonly CfPlusAuctionEconomicsSnapshotService $snapshots) {}

    /** @return array<string, mixed> */
    public function forTarget(Course $course, Firm $viewer, Firm $target): array
    {
        if ($viewer->course_id !== $course->id || $target->course_id !== $course->id) {
            throw new DomainException('CF Plus reveal is course scoped.');
        }
        $snapshot = $this->snapshots->forCourse($course);
        $result = CfPlusAuctionResult::query()->where('course_id', $course->id)->where('target_firm_id', $target->id)->firstOrFail();
        $offer = CfPlusOffer::query()->where('course_id', $course->id)->where('bidder_firm_id', $viewer->id)->where('target_firm_id', $target->id)->first();
        $difference = $result->price_paid === null ? null : round((float) $result->price_paid - (float) $result->true_stake_worth, 2);

        return [
            'target_id' => $target->id, 'target_name' => $target->name, 'valuation_date' => $snapshot->valuation_date->toDateString(),
            'full_consultant_value' => (float) $result->full_consultant_value, 'consultant_stake_value' => (float) $result->consultant_stake_value,
            'full_true_worth' => (float) $result->displayed_full_true_worth, 'true_stake_worth' => (float) $result->true_stake_worth,
            'going_concern_status' => (float) $result->displayed_full_true_worth <= 0 ? 'NO POSITIVE GOING-CONCERN VALUE' : 'POSITIVE GOING-CONCERN VALUE',
            'winning_bidder_id' => $result->winning_bidder_firm_id, 'price_paid' => $result->price_paid === null ? null : (float) $result->price_paid,
            'price_vs_true_worth' => $difference, 'outcome' => $difference === null ? 'unsold' : ($difference > 0 ? 'overpaid' : ($difference < 0 ? 'underpaid' : 'exact')),
            'viewer_offer' => $offer?->offered_amount === null ? null : (float) $offer->offered_amount,
            'viewer_fundable_cap' => $offer?->fundable_cap === null ? null : (float) $offer->fundable_cap,
            'viewer_offer_vs_true_worth' => $offer === null ? null : round((float) $offer->offered_amount - (float) $result->true_stake_worth, 2),
            'viewer_unused_capacity' => $offer === null ? null : round((float) $offer->fundable_cap - (float) $offer->offered_amount, 2),
        ];
    }

    /** @return array<int, array{bidder_id: string, bidder_name: string, valuation_date: string, target_id: string, target_name: string, offered_amount: float, fundable_cap: float, consultant_stake_value: float, true_stake_worth: float, submitted_at: mixed}> */
    public function forFaculty(Course $course, Faculty $faculty): array
    {
        $authorized = $faculty->institution_id === $course->institution_id
            && ($course->faculty_id === $faculty->id || $course->facultyRoles()->where('faculty_id', $faculty->id)->exists());
        abort_unless($authorized, 403);
        if (! CfPlusAuctionResult::query()->where('course_id', $course->id)->exists()) {
            throw new DomainException('Sealed bidder evidence is unavailable before settlement.');
        }

        $snapshot = $this->snapshots->forCourse($course);
        $results = CfPlusAuctionResult::query()->where('course_id', $course->id)->get()->keyBy('target_firm_id');

        return CfPlusOffer::query()->where('course_id', $course->id)
            ->with(['bidderFirm', 'targetFirm'])
            ->orderBy('target_firm_id')->orderByDesc('offered_amount')->orderBy('submitted_at')->orderBy('id')->get()
            ->map(function (CfPlusOffer $offer) use ($results, $snapshot): array {
                $result = $results->get($offer->target_firm_id);
                if (! $result instanceof CfPlusAuctionResult) {
                    throw new DomainException('Frozen auction result is unavailable for bidder evidence.');
                }

                return [
                    'bidder_id' => $offer->bidder_firm_id, 'bidder_name' => $offer->bidderFirm->name, 'valuation_date' => $snapshot->valuation_date->toDateString(),
                    'target_id' => $offer->target_firm_id, 'target_name' => $offer->targetFirm->name,
                    'offered_amount' => (float) $offer->offered_amount, 'fundable_cap' => (float) $offer->fundable_cap,
                    'consultant_stake_value' => (float) $result->consultant_stake_value, 'true_stake_worth' => (float) $result->true_stake_worth,
                    'submitted_at' => $offer->submitted_at,
                ];
            })->all();
    }
}
