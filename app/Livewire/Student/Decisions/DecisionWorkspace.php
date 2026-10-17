<?php

namespace App\Livewire\Student\Decisions;

use App\Models\CourseQuarter;
use App\Models\Firm;
use App\Models\FirmQuarterDecision;
use App\Models\Student;
use App\Services\Courses\CourseStatus;
use App\Services\Decisions\FirmDecisionService;
use App\Services\Engines\CorporateFinance\Exceptions\EngineCalculationException;
use App\Support\Presentation\DecisionPresenter;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

/**
 * The team's decisions for a quarter (walkthrough items 10, 73 to 79).
 *
 * Every field starts from the team's saved draft, or else from last
 * quarter's decisions, with "Last quarter" shown under it. Products,
 * regions and suppliers have names; shares are entered as percentages.
 * Inputs the engine never uses are filled in silently rather than shown.
 */
#[Layout('layouts.student')]
class DecisionWorkspace extends Component
{
    public Firm $firm;

    public CourseQuarter $quarter;

    /** @var array<string, array<string, mixed>> form values in display units (percentages as 5, not 0.05) */
    public array $decisions = [];

    public string $dividend = '0.00';

    public ?float $maximumDividend = null;

    public string $statusMessage = '';

    public bool $prefilledFromLastQuarter = false;

    public function mount(Firm $firm, CourseQuarter $quarter, FirmDecisionService $service): void
    {
        $service->authorize($this->student(), $firm);
        abort_unless($quarter->course_id === $firm->course_id, 404);
        $this->firm = $firm->load('course');
        $this->quarter = $quarter;

        if ($quarter->quarter_number < 4) {
            return;
        }

        $record = $service->decision($firm, $quarter);
        $saved = $record->state === FirmQuarterDecision::DRAFT ? ($record->draft_payload ?? []) : ($record->submitted_payload ?? $record->draft_payload ?? []);

        if ($saved !== []) {
            $engineValues = $saved;
            $this->dividend = number_format((float) $record->dividend, 2, '.', '');
        } else {
            [$engineValues, $dividend] = $service->carryForwardPayload($firm, $quarter);
            $this->dividend = number_format($dividend, 2, '.', '');
            $this->prefilledFromLastQuarter = $service->previousDecisions($firm, $quarter) !== null;
        }

        foreach ($service->enabledCategories($quarter) as $code => $category) {
            foreach ($service->decisionFields($quarter, $category) as $field) {
                $value = $engineValues[$code][$field['key']] ?? $service->fieldDefault($firm, $field);
                $this->decisions[$code][$field['key']] = $this->toDisplay($field['key'], $value);
            }
        }

        $this->refreshMaximum($service);
    }

    public function updatedDecisions(): void
    {
        $this->refreshMaximum(app(FirmDecisionService::class));
    }

    /** Item 10: the button existed but the method did not. */
    public function saveDraft(FirmDecisionService $service): void
    {
        $service->saveDraft($this->student(), $this->firm, $this->quarter, $this->enginePayload($service), $this->dividend);
        $this->statusMessage = 'Draft saved. Your teammates see it too. Submit before the deadline, or last quarter\'s decisions are used.';
    }

    public function submit(FirmDecisionService $service): void
    {
        $this->resetValidation('dividend');
        try {
            $service->submit($this->student(), $this->firm, $this->quarter, $this->enginePayload($service), $this->dividend);
        } catch (EngineCalculationException $exception) {
            $this->addError('dividend', $exception->getMessage());

            return;
        }
        $this->statusMessage = 'Submitted for your team. You can still change it until the deadline.';
        $this->refreshMaximum($service);
    }

    public function render(FirmDecisionService $service): View
    {
        $isStudentQuarter = $this->quarter->quarter_number >= 4;
        $record = $isStudentQuarter ? $service->decision($this->firm, $this->quarter) : null;
        $status = CourseStatus::for($this->firm->course);

        $categories = $isStudentQuarter ? $service->enabledCategories($this->quarter) : [];
        $categoryFields = collect($categories)->mapWithKeys(fn ($category, string $code): array => [
            $code => array_values(array_filter(
                $service->decisionFields($this->quarter, $category),
                fn (array $field) => ! DecisionPresenter::isInert($code, $field['key']),
            )),
        ])->filter()->all();

        $previous = $isStudentQuarter ? $service->previousDecisions($this->firm, $this->quarter) : null;
        $lastResult = $this->firm->engineResults()->where('quarter_number', $this->quarter->quarter_number - 1)->first();

        return view('livewire.student.decisions.decision-workspace', [
            'categories' => array_intersect_key($categories, $categoryFields),
            'categoryFields' => $categoryFields,
            'record' => $record,
            'teamStatus' => $isStudentQuarter ? $status->firmStatus($this->firm, $this->quarter) : null,
            'readOnly' => ! $isStudentQuarter || $this->quarter->state !== CourseQuarter::OPEN || $record?->state === FirmQuarterDecision::LOCKED,
            'previous' => $previous['payload'] ?? [],
            'lastResult' => $lastResult,
            'parameters' => $service->parameters($this->firm),
            'result' => $this->firm->engineResults()->where('quarter_number', $this->quarter->quarter_number)->first(),
        ]);
    }

    /** @return array<string, array<string, mixed>> engine units, inert inputs filled with their defaults */
    private function enginePayload(FirmDecisionService $service): array
    {
        $payload = [];
        foreach ($service->enabledCategories($this->quarter) as $code => $category) {
            foreach ($service->decisionFields($this->quarter, $category) as $field) {
                $key = $field['key'];
                $payload[$code][$key] = DecisionPresenter::isInert($code, $key)
                    ? $service->fieldDefault($this->firm, $field)
                    : $this->toEngine($key, $this->decisions[$code][$key] ?? null);
            }
        }

        return $payload;
    }

    private function toDisplay(string $key, mixed $value): mixed
    {
        if (in_array($key, DecisionPresenter::PERCENT_FIELDS, true) && is_numeric($value)) {
            return round((float) $value * 100, 4);
        }

        return $value;
    }

    private function toEngine(string $key, mixed $value): mixed
    {
        if ($value === '' || $value === null) {
            return $value;
        }
        if (in_array($key, DecisionPresenter::PERCENT_FIELDS, true) && is_numeric($value)) {
            return round((float) $value / 100, 6);
        }
        if (is_numeric($value)) {
            $number = (float) $value;

            return floor($number) === $number ? (int) $number : $number;
        }

        return $value;
    }

    private function refreshMaximum(FirmDecisionService $service): void
    {
        try {
            $this->maximumDividend = $service->dividendLimit($this->firm, $this->quarter, $this->enginePayload($service), 0)->maximum;
        } catch (Throwable) {
            $this->maximumDividend = null;
        }
    }

    private function student(): Student
    {
        abort_unless(auth()->check() && auth()->user()->student, 403);

        return auth()->user()->student;
    }
}
