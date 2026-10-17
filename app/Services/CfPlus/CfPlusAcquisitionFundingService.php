<?php

namespace App\Services\CfPlus;

use App\Models\CfPlusAuctionEconomics;
use App\Models\Course;
use App\Models\Firm;
use DomainException;

final class CfPlusAcquisitionFundingService
{
    public const CONTROL_STAKE = 0.51;

    public const FINANCING_MULTIPLE = 5.0;

    public function __construct(private readonly CfPlusGate $gate, private readonly CfPlusAuctionEconomicsSnapshotService $snapshots) {}

    /** @return array{bidder_cash: float, unused_committed_credit: float, annual_normalized_recurring_cash: float, target_acquisition_financing: float, maximum_fundable_offer: float} */
    public function calculate(Course $course, Firm $bidder, Firm $target): array
    {
        $this->gate->assertEnabled($course);
        if ($bidder->course_id !== $course->id || $target->course_id !== $course->id || $bidder->is($target)) {
            throw new DomainException('Acquisition funding is available only for rival firms in the same CF Plus course.');
        }
        $snapshot = $this->snapshots->forCourse($course);
        $rows = CfPlusAuctionEconomics::query()->where('snapshot_id', $snapshot->id)->whereIn('firm_id', [$bidder->id, $target->id])->get()->keyBy('firm_id');
        $bidderEconomics = $rows->get($bidder->id) ?? throw new DomainException('Frozen bidder auction economics are unavailable.');
        $targetEconomics = $rows->get($target->id) ?? throw new DomainException('Frozen target auction economics are unavailable.');
        $cash = (float) $bidderEconomics->bidder_cash;
        $unused = (float) $bidderEconomics->unused_committed_credit;
        $financing = (float) $targetEconomics->target_acquisition_financing;

        return [
            'bidder_cash' => $cash,
            'unused_committed_credit' => $unused,
            'annual_normalized_recurring_cash' => (float) $targetEconomics->normalized_annual_cash,
            'target_acquisition_financing' => $financing,
            'maximum_fundable_offer' => $this->sum($cash, $unused, $financing),
        ];
    }

    private function sum(float ...$amounts): float
    {
        return array_sum(array_map(fn (float $amount): int => (int) round($amount * 100), $amounts)) / 100;
    }
}
