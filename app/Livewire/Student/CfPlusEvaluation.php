<?php

namespace App\Livewire\Student;

use App\Models\CfPlusAuctionResult;
use App\Models\CfPlusOffer;
use App\Models\Firm;
use App\Models\FirmRivalAnalysis;
use App\Services\CfPlus\CfPlusAcquisitionFundingService;
use App\Services\CfPlus\CfPlusDisclosureService;
use App\Services\CfPlus\CfPlusGate;
use App\Services\CfPlus\CfPlusOfferService;
use App\Services\CfPlus\CfPlusRevealService;
use App\Services\Interpretations\RivalAnalysisService;
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

    /** @var array<string, array{matters: string, misses: string, worth: string, estimate: string}> U11 layer 3: the team's analysis of each rival, as typed */
    public array $analysis = [];

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

        $this->loadAnalyses();

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

    public function saveAnalysis(string $targetId, RivalAnalysisService $analyses): void
    {
        $this->writeAnalysis($targetId, $analyses, false);
    }

    public function submitAnalysis(string $targetId, RivalAnalysisService $analyses): void
    {
        $this->writeAnalysis($targetId, $analyses, true);
    }

    private function writeAnalysis(string $targetId, RivalAnalysisService $analyses, bool $submit): void
    {
        $target = $this->firm->course->firms()->whereKeyNot($this->firm->id)->findOrFail($targetId);
        $typed = $this->analysis[$targetId] ?? [];
        $answers = array_intersect_key($typed, FirmRivalAnalysis::PROMPTS);
        $this->resetErrorBag();
        try {
            $student = auth()->user()->student;
            $submit
                ? $analyses->submit($student, $this->firm, $target, $answers, $typed['estimate'] ?? null)
                : $analyses->saveDraft($student, $this->firm, $target, $answers, $typed['estimate'] ?? null);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $this->addError($key === 'analysis' ? "analysis.{$targetId}.analysis" : str_replace('analysis.', "analysis.{$targetId}.", $key), $messages[0]);
            }

            return;
        }
        $this->statusMessage = $submit
            ? "Your team's analysis of {$target->name} is submitted. You can now save a positive offer for it."
            : "Draft of your analysis of {$target->name} saved.";
        $this->loadAnalyses();
    }

    private function loadAnalyses(): void
    {
        $rows = FirmRivalAnalysis::query()->where('bidder_firm_id', $this->firm->id)->get()->keyBy('target_firm_id');
        foreach ($this->firm->course->firms()->whereKeyNot($this->firm->id)->pluck('id') as $id) {
            $row = $rows->get($id);
            $this->analysis[$id] = [
                'matters' => (string) ($row?->answers['matters'] ?? ''),
                'misses' => (string) ($row?->answers['misses'] ?? ''),
                'worth' => (string) ($row?->answers['worth'] ?? ''),
                'estimate' => $row?->estimated_stake_value === null ? '' : number_format((float) $row->estimated_stake_value, 2, '.', ''),
            ];
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
                'analysisRequired' => false,
                'analysisEditable' => false,
                'analyses' => collect(),
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

        $analyses = app(RivalAnalysisService::class);

        return view('livewire.student.cf-plus-evaluation', [
            'rows' => $rows,
            'settled' => $settled,
            // U11 layer 3 (RA1): the team's analysis of each rival, needed before a positive offer.
            'analysisRequired' => $analyses->required($course),
            'analysisEditable' => $analyses->editable($course),
            'analyses' => FirmRivalAnalysis::query()->where('bidder_firm_id', $this->firm->id)->with('submittedBy.user')->get()->keyBy('target_firm_id'),
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
