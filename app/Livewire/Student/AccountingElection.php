<?php

namespace App\Livewire\Student;

use App\Models\CourseQuarter;
use App\Models\Firm;
use App\Models\FirmAccountingElectionRequest;
use App\Services\Accounting\FirmAccountingElectionService;
use App\Services\Decisions\FirmDecisionService;
use App\Services\CfPlus\FirmAccountingElectionService as LegacyFirmAccountingElectionService;
use App\Support\Scenario\CfManufacturingScenarioDefinition;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.student')]
class AccountingElection extends Component
{
    public Firm $firm;

    public CourseQuarter $quarter;

    public array $fields = [];

    public array $defaultSettings = [];

    public array $effectiveSettings = [];

    /**
     * All accounting-election requests submitted by members
     * of this firm for the current quarter.
     */
    public Collection $requests;

    /**
     * Student's accounting-election workflow choice.
     *
     * default = use current/default accounting settings
     * change  = propose different accounting methods
     */
    public string $choice = 'default';

    public array $election = [
        'depreciation_method' => null,
        'shipping_terms' => null,
        'bad_debt_method' => null,
        'warranty_method' => null,
        'excise_tax_treatment' => null,
    ];

    public function mount(
        Firm $firm,
        CourseQuarter $quarter
    ): void {
        $this->requests = new Collection();

        $service = app(FirmDecisionService::class);

        $service->authorize(
            auth()->user()->student,
            $firm
        );

        abort_unless(
            $quarter->course_id === $firm->course_id,
            404
        );

        abort_unless(
            $quarter->quarter_number >= 4,
            404
        );

        $this->firm = $firm;
        $this->quarter = $quarter;

        $this->loadFields();
        $this->loadEffectiveSettings();
        $this->loadRequests();

        if (! $this->firm->course->accounting_election_enabled) {
            $this->choice = 'default';

            return;
        }
    }

    private function loadFields(): void
    {
        $definition = CfManufacturingScenarioDefinition::inputFieldsForCategory(
            'notes_elections'
        );

        $allFields = collect($definition['fields']);

        /*
         * Keep the scenario definition as the single source of truth
         * for the configured accounting defaults.
         */
        $this->defaultSettings = $allFields
            ->mapWithKeys(
                fn(array $field): array => [
                    $field['key'] => $field['default'] ?? null,
                ]
            )
            ->all();

        /*
         * Inventory Method remains fixed to FIFO and is not selectable
         * by the student.
         */
        $this->fields = $allFields
            ->reject(
                fn(array $field): bool =>
                $field['key'] === 'inventory_method'
            )
            ->values()
            ->all();
    }

    private function loadEffectiveSettings(): void
    {
        /*
         * The new accounting-election service resolves the effective
         * settings for this specific firm and quarter.
         *
         * It checks:
         * 1. an approved/effective record for this quarter,
         * 2. the previous quarter's effective record,
         * 3. legacy firm-level settings,
         * 4. scenario defaults.
         */
        $effective = app(FirmAccountingElectionService::class)
            ->effective($this->firm, $this->quarter);

        $effective['inventory_method'] = 'fifo';

        $this->effectiveSettings = $effective;

         
    }

    private function loadRequests(): void
    {
        $this->requests = FirmAccountingElectionRequest::query()
            ->where('firm_id', $this->firm->id)
            ->where('course_quarter_id', $this->quarter->id)
            ->with([
                'firm',
                'student.user',
                'courseQuarter',
                'reviewedByFaculty.user',
            ])
            ->latest('created_at')
            ->get();
    }

    public function selectDefault(): void
    {
        $this->choice = 'default';
    }

    public function selectChange(): void
    {
        $this->choice = 'change';

        /*
         * Start a new proposal from the current effective settings.
         *
         * This is important for later quarters because the current
         * effective setting may have been inherited from the previous
         * quarter or established by an earlier approved request.
         */
        foreach (array_keys($this->election) as $key) {
            $this->election[$key] = $this->effectiveSettings[$key]
                ?? $this->defaultSettings[$key]
                ?? null;
        }
    }

    public function save(): void
    {
        /*
         * The Accounting Election workflow is available only when
         * faculty enabled the course-level control.
         */
        abort_unless(

            $this->firm->course->accounting_election_enabled,

            403

        );

        abort_unless(

            $this->quarter->state === CourseQuarter::OPEN,

            403

        );

        /*
         * This action is specifically for the "Change Accounting
         * Settings" workflow.
         *
         * Selecting "Use Default Accounting Settings" does not create
         * a request and does not require faculty approval.
         */
        if ($this->choice !== 'change') {
            $this->choice = 'default';

            return;
        }

        /*
         * Refresh the firm before resolving the current effective
         * settings so the proposal comparison uses the latest state.
         */
        $this->firm->refresh();

        $effectiveService = app(FirmAccountingElectionService::class);

        $this->effectiveSettings = $effectiveService->effective(
            $this->firm,
            $this->quarter
        );

        $this->effectiveSettings['inventory_method'] = 'fifo';

        /*
         * Validate the five student-visible accounting elections.
         */
        $validated = app(LegacyFirmAccountingElectionService::class)
            ->validate($this->election);

        /*
         * Store only the student-changeable accounting methods.
         *
         * Inventory Method is intentionally not stored here because
         * it is permanently fixed to FIFO.
         */
        $proposed = collect($validated)
            ->except('inventory_method')
            ->all();

        /*
         * Do not create a request when the proposed settings are
         * identical to the current effective settings.
         */
        $currentEffective = collect($this->effectiveSettings)
            ->except('inventory_method')
            ->only(array_keys($proposed))
            ->all();

        if ($proposed === $currentEffective) {
            throw ValidationException::withMessages([
                'election' =>
                'Your proposed accounting settings are the same as the current effective settings. Choose different accounting methods to submit a change request.',
            ]);
        }

        /*
         * Multiple team members are intentionally allowed to submit
         * requests for the same firm and quarter.
         *
         * The faculty approval service applies first-approved-wins:
         * once one request is approved, all other pending requests for
         * the same firm and quarter are automatically rejected.
         */
        FirmAccountingElectionRequest::query()->create([
            'firm_id' => $this->firm->id,
            'course_id' => $this->firm->course_id,
            'course_quarter_id' => $this->quarter->id,
            'student_id' => auth()->user()->student->id,
            'proposed_elections' => $proposed,
            'status' => 'pending',
        ]);

        /*
         * Refresh the firm-wide request history so the newly submitted
         * request immediately appears for all team members.
         */
        $this->loadRequests();

        $this->choice = 'default';

        session()->flash(
            'success',
            'Your accounting-election change request has been submitted to faculty for review.'
        );
    }

    public function render()
    {
        return view('livewire.student.accounting-election');
    }
}
