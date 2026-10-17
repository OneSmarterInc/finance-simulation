<?php

namespace App\Livewire\Student\Decisions;

use App\Models\CourseQuarter;
use App\Models\Firm;
use App\Models\FirmQuarterDecision;
use App\Models\Student;
use App\Services\Decisions\FirmDecisionService;
use App\Services\Engines\CorporateFinance\Exceptions\EngineCalculationException; 
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

#[Layout('layouts.student')]
class DecisionWorkspace extends Component
{
    public Firm $firm;

    public CourseQuarter $quarter;

    /** @var array<string, mixed> */
    public array $decisions = [];

    public string $dividend = '0.00';

    public ?float $maximumDividend = null;

    public string $statusMessage = '';

    public function mount(Firm $firm, CourseQuarter $quarter, FirmDecisionService $service): void
    {
        $service->authorize($this->student(), $firm);
        abort_unless($quarter->course_id === $firm->course_id, 404);
        $this->firm = $firm;
        $this->quarter = $quarter;
        if ($quarter->quarter_number >= 4) {
            $record = $service->decision($firm, $quarter);

            $this->decisions = $record->draft_payload ?? []; 
            $this->dividend = (string) $record->dividend; 
            $this->seedDefaults($service); 
            $this->refreshMaximum($service);
        }
    }

    public function updatedDecisions(FirmDecisionService $service): void
    {
        $this->refreshMaximum($service);
    }



    public function submit(FirmDecisionService $service): void
    {
        $this->resetValidation('dividend');
        try {
            $service->submit($this->student(), $this->firm, $this->quarter, $this->decisions, $this->dividend);
        } catch (EngineCalculationException $exception) {
            $this->addError('dividend', $exception->getMessage());

            return;
        }
        $this->statusMessage = 'Firm decision submitted. It remains editable until faculty begins close.';
        $this->refreshMaximum($service);
    }

    public function render(FirmDecisionService $service): View
    {
        $record = $this->quarter->quarter_number >= 4 ? $service->decision($this->firm, $this->quarter) : null;

        return view('livewire.student.decisions.decision-workspace', [
            'categories' => $this->quarter->quarter_number >= 4 ? $service->enabledCategories($this->quarter) : [],
            'categoryFields' => $this->quarter->quarter_number >= 4
                ? collect($service->enabledCategories($this->quarter))->mapWithKeys(fn($category, string $code): array => [$code => $service->decisionFields($this->quarter, $category)])->all()
                : [],
            'record' => $record,
            'readOnly' => $this->quarter->quarter_number < 4 || $this->quarter->state !== CourseQuarter::OPEN || $record?->state === FirmQuarterDecision::LOCKED,
            'result' => $this->firm->engineResults()->where('quarter_number', $this->quarter->quarter_number)->first(),
        ]);
    }

    private function seedDefaults(FirmDecisionService $service): void

    {

        foreach ($service->enabledCategories($this->quarter) as $code => $category) {

            foreach ($service->decisionFields($this->quarter, $category) as $field) {

                $this->decisions[$code][$field['key']] ??= $field['default'] ?? null;
            }
        }
    } 
   
    private function refreshMaximum(FirmDecisionService $service): void
    {
        try {
            $this->maximumDividend = $service->dividendLimit($this->firm, $this->quarter, $this->decisions, 0)->maximum;
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
