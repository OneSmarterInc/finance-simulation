<?php

namespace App\Livewire\Student;

use App\Models\CfPlusAuctionResult;
use App\Models\CfPlusOffer;
use App\Models\Firm;
use App\Services\CfPlus\CfPlusAcquisitionFundingService;
use App\Services\CfPlus\CfPlusDisclosureService;
use App\Services\CfPlus\CfPlusGate;
use App\Services\CfPlus\CfPlusOfferService;
use App\Services\CfPlus\CfPlusRevealService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.student')]
class CfPlusEvaluation extends Component
{
    public Firm $firm;

    /** @var array<string, string> */
    public array $offerAmounts = [];

    public string $statusMessage = '';

    public ?string $availabilityMessage = null;

    public function mount(
        Firm $firm,
        CfPlusGate $gate,
        CfPlusDisclosureService $disclosures
    ): void {
        $this->authorizeStudent($firm);

        $this->firm = $firm->load('course');

        abort_unless($firm->course->cf_plus_enabled, 404);

        try {
            $gate->assertEvaluationAvailable($firm->course);
        } catch (\DomainException $exception) {
            $this->availabilityMessage = $exception->getMessage();

            return;
        }

        foreach ($firm->course->firms()->whereKeyNot($firm->id)->get() as $target) {
            $disclosures->rivalSnapshot($firm->course, $firm, $target);
        }

        $this->offerAmounts = CfPlusOffer::query()
            ->where('bidder_firm_id', $firm->id)
            ->pluck('offered_amount', 'target_firm_id')
            ->map(fn ($amount): string => (string) $amount)
            ->all();
    }

    public function saveOffer(
        string $targetId,
        CfPlusOfferService $offers
    ): void {
        $target = $this->firm->course->firms()->findOrFail($targetId);

        $raw = $this->offerAmounts[$targetId] ?? '';

        if ($raw === '' || ! is_numeric($raw)) {
            $this->addError(
                'offerAmounts.'.$targetId,
                'Enter a monetary offer or 0 for no bid.'
            );

            return;
        }

        try {
            $offer = $offers->submit(
                $this->firm->course,
                $this->firm,
                $target,
                (float) $raw
            );

            $this->statusMessage = (float) $offer->offered_amount === 0.0
                ? 'Explicit $0.00 offer saved.'
                : 'Sealed offer saved.';

            $this->offerAmounts[$targetId] = (string) $offer->offered_amount;

            $this->resetErrorBag('offerAmounts.'.$targetId);
        } catch (ValidationException $exception) {
            $this->addError(
                'offerAmounts.'.$targetId,
                $exception->errors()['offer'][0]
            );
        }
    }

    public function render(
        CfPlusAcquisitionFundingService $funding,
        CfPlusRevealService $reveal
    ): View {
        if ($this->availabilityMessage !== null) {
            return view('livewire.student.cf-plus-evaluation', [
                'rows' => collect(),
                'settled' => false,
            ]);
        }

        $course = $this->firm->course;

        $settled = CfPlusAuctionResult::query()
            ->where('course_id', $course->id)
            ->exists();

        $targets = $course->firms()
            ->whereKeyNot($this->firm->id)
            ->with(['disclosureSnapshots.consultantReport'])
            ->orderBy('name')
            ->get();

        $rows = $targets->map(
            function (Firm $target) use (
                $funding,
                $reveal,
                $course,
                $settled
            ): array {
                $snapshot = $target->disclosureSnapshots
                    ->firstWhere('course_id', $course->id);

                $report = $snapshot?->consultantReport;

                return [
                    'target' => $target,
                    'snapshot' => $snapshot,
                    'report' => $report,
                    'consultant_stake_value' => round(
                        max(0, (float) $report?->consultant_value)
                        * CfPlusAcquisitionFundingService::CONTROL_STAKE,
                        2
                    ),
                    'funding' => $settled
                        ? null
                        : $funding->calculate(
                            $course,
                            $this->firm,
                            $target
                        ),
                    'reveal' => $settled
                        ? $reveal->forTarget(
                            $course,
                            $this->firm,
                            $target
                        )
                        : null,
                ];
            }
        );

        return view('livewire.student.cf-plus-evaluation', [
            'rows' => $rows,
            'settled' => $settled,
        ]);
    }

    private function authorizeStudent(Firm $firm): void
    {
        $student = auth()->user()?->student;

        abort_unless(
            $student
            && $student->institution_id === $firm->institution_id
            && $firm->memberships()
                ->where('student_id', $student->id)
                ->exists(),
            403
        );
    }
}