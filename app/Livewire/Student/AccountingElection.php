<?php

namespace App\Livewire\Student;

use App\Models\CourseQuarter;
use App\Models\Firm;
use App\Models\FirmAccountingElectionRequest;
use App\Services\Accounting\FirmAccountingElectionRequestService;
use App\Services\Accounting\FirmAccountingElectionService;
use App\Services\CfPlus\FirmAccountingElectionService as LegacyFirmAccountingElectionService;
use App\Services\Decisions\FirmDecisionService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The team's accounting methods and its change request for the quarter
 * (walkthrough items 82 to 84, N7).
 */
#[Layout('layouts.student')]
class AccountingElection extends Component
{
    public Firm $firm;

    public CourseQuarter $quarter;

    public bool $editing = false;

    /** @var array<string, string|null> */
    public array $election = [];

    public string $justification = '';

    public function mount(Firm $firm, CourseQuarter $quarter): void
    {
        app(FirmDecisionService::class)->authorize(auth()->user()->student, $firm);
        abort_unless($quarter->course_id === $firm->course_id && $quarter->quarter_number >= 4, 404);

        $this->firm = $firm->load('course');
        $this->quarter = $quarter;
    }

    public function startRequest(): void
    {
        abort_unless($this->firm->course->accounting_election_enabled, 403);
        $this->editing = true;
        $current = $this->current();
        foreach ($this->fields() as $field) {
            $this->election[$field['key']] = $current[$field['key']] ?? $field['default'] ?? null;
        }
    }

    public function cancel(): void
    {
        $this->editing = false;
        $this->reset('election', 'justification');
        $this->resetValidation();
    }

    public function save(FirmAccountingElectionRequestService $service): void
    {
        $proposed = [];
        foreach ($this->fields() as $field) {
            $proposed[$field['key']] = $this->election[$field['key']] ?? null;
        }
        $service->submit(auth()->user()->student, $this->firm, $this->quarter, $proposed, $this->justification);

        $this->cancel();
        session()->flash('success', 'Your team\'s request has gone to your instructor.');
    }

    public function withdraw(string $requestId, FirmAccountingElectionRequestService $service): void
    {
        $request = FirmAccountingElectionRequest::query()
            ->where('id', $requestId)->where('firm_id', $this->firm->id)->firstOrFail();
        $service->withdraw(auth()->user()->student, $request);
        session()->flash('success', 'Request withdrawn. Your team can file a different one.');
    }

    public function render(FirmAccountingElectionRequestService $service): View
    {
        $request = $service->teamRequest($this->firm, $this->quarter);

        return view('livewire.student.accounting-election', [
            'fields' => $this->fields(),
            'current' => $this->current(),
            'request' => $request,
            'earlier' => FirmAccountingElectionRequest::query()
                ->where('firm_id', $this->firm->id)
                ->where('course_quarter_id', '!=', $this->quarter->id)
                ->whereIn('status', [FirmAccountingElectionRequestService::APPROVED, FirmAccountingElectionRequestService::REJECTED])
                ->with('courseQuarter', 'student.user')
                ->latest('created_at')->get(),
            'canFile' => $this->firm->course->accounting_election_enabled
                && $this->quarter->state === CourseQuarter::OPEN
                && ($request === null || $request->status === FirmAccountingElectionRequestService::RETURNED),
        ]);
    }

    /** @return array<int, array<string, mixed>> the five methods a team may change (inventory stays FIFO) */
    private function fields(): array
    {
        return app(LegacyFirmAccountingElectionService::class)->studentVisibleFields();
    }

    /** @return array<string, string> */
    private function current(): array
    {
        $current = app(FirmAccountingElectionService::class)->effective($this->firm, $this->quarter);
        $current['inventory_method'] = 'fifo';

        return $current;
    }
}
