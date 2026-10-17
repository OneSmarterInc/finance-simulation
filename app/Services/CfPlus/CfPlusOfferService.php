<?php

namespace App\Services\CfPlus;

use App\Models\CfPlusAuctionResult;
use App\Models\CfPlusOffer;
use App\Models\Course;
use App\Models\CourseQuarter;
use App\Models\Firm;
use DomainException;
use Illuminate\Validation\ValidationException;

final class CfPlusOfferService
{
    public function __construct(private readonly CfPlusGate $gate, private readonly CfPlusAcquisitionFundingService $funding) {}

    /** @return array{bidder_cash: float, unused_committed_credit: float, annual_normalized_recurring_cash: float, target_acquisition_financing: float, maximum_fundable_offer: float} */
    public function fundingContext(Course $course, Firm $bidder, Firm $target): array
    {
        return $this->funding->calculate($course, $bidder, $target);
    }

    public function submit(Course $course, Firm $bidder, Firm $target, float $amount): CfPlusOffer
    {
        $this->assertWindowOpen($course);
        if ($amount < 0) {
            throw ValidationException::withMessages(['offer' => 'Offer must be zero or greater.']);
        }
        $context = $this->funding->calculate($course, $bidder, $target);
        $amount = round($amount, 2);
        if ((int) round($amount * 100) > (int) round($context['maximum_fundable_offer'] * 100)) {
            throw ValidationException::withMessages(['offer' => 'Offer exceeds the maximum fundable offer of $'.number_format($context['maximum_fundable_offer'], 2).'.']);
        }

        $existing = CfPlusOffer::withTrashed()->where('course_id', $course->id)->where('bidder_firm_id', $bidder->id)->where('target_firm_id', $target->id)->first();
        $sameBindingAmount = $existing !== null && ! $existing->trashed()
            && (int) round((float) $existing->offered_amount * 100) === (int) round($amount * 100);
        if ($sameBindingAmount) {
            return $existing;
        }

        $existing?->restore();
        $submittedAt = now();

        return CfPlusOffer::query()->updateOrCreate(
            ['course_id' => $course->id, 'bidder_firm_id' => $bidder->id, 'target_firm_id' => $target->id],
            ['institution_id' => $course->institution_id, 'offered_amount' => $amount, 'stake' => CfPlusAcquisitionFundingService::CONTROL_STAKE, 'fundable_cap' => $context['maximum_fundable_offer'], 'bidder_cash' => $context['bidder_cash'], 'unused_committed_credit' => $context['unused_committed_credit'], 'target_acquisition_financing' => $context['target_acquisition_financing'], 'submitted_at' => $submittedAt, 'sealed_at' => now(), 'locked_at' => null],
        );
    }

    public function assertWindowOpen(Course $course): void
    {
        $this->gate->assertEnabled($course);
        if (CfPlusAuctionResult::query()->where('course_id', $course->id)->exists()) {
            throw new DomainException('Offers are final once the evaluation stage has been settled.');
        }
        $start = $this->gate->evaluationStartQuarter($course);
        $open = $course->quarters()->where('quarter_number', '>=', $start)->where('state', CourseQuarter::OPEN)->exists();
        if (! $open) {
            throw new DomainException('Offers can only be placed while an evaluation-stage quarter is open.');
        }
    }
}
