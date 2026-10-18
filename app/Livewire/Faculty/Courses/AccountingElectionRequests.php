<?php

namespace App\Livewire\Faculty\Courses;

use App\Models\Course;
use App\Models\Faculty;
use App\Models\FirmAccountingElectionRequest;
use App\Services\Accounting\AccountingChangeDisclosures;
use App\Services\Accounting\FirmAccountingElectionRequestService;
use App\Services\Accounting\FirmAccountingElectionService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Accounting-method requests (walkthrough items 56 to 62): headed by the
 * firm, current and proposed side by side with changes highlighted, the
 * team's reason, when the change takes effect, and a note on decline.
 */
#[Layout('layouts.app')]
class AccountingElectionRequests extends Component
{
    public Course $course;

    /** @var array<string, string> decline notes keyed by request id */
    public array $notes = [];

    public function mount(Course $course): void
    {
        app(FirmAccountingElectionRequestService::class)->authorize($this->faculty(), $course);
        $this->course = $course;
    }

    public function approve(string $requestId, FirmAccountingElectionRequestService $service): void
    {
        $request = $this->pendingRequest($requestId);
        $service->approve($this->faculty(), $request);
        $request->refresh();
        session()->flash('success', "{$request->firm->name}'s methods change from Quarter {$request->effective_quarter_number}.");
    }

    public function reject(string $requestId, FirmAccountingElectionRequestService $service): void
    {
        $request = $this->pendingRequest($requestId);
        $service->reject($this->faculty(), $request, $this->notes[$requestId] ?? null);
        unset($this->notes[$requestId]);
        session()->flash('success', "{$request->firm->name}'s request was declined. The team can see your note.");
    }

    public function askForReason(string $requestId, FirmAccountingElectionRequestService $service): void
    {
        $request = $this->pendingRequest($requestId);
        $service->askForReason($this->faculty(), $request, $this->currentMethods($request, $service));
        session()->flash('success', "{$request->firm->name} has been asked for their reason and can file again this quarter.");
    }

    public function render(FirmAccountingElectionRequestService $service): View
    {
        $requests = $service->pending($this->faculty(), $this->course);

        $rows = $requests->map(function (FirmAccountingElectionRequest $request) use ($service) {
            $target = $request->status === 'pending' ? $service->effectiveQuarter($request) : null;
            $current = $this->currentMethods($request, $service);

            return [
                'request' => $request,
                'target' => $target,
                'current' => $current,
                'changes' => AccountingChangeDisclosures::changes($current, $request->proposed_elections ?? []),
            ];
        });

        return view('livewire.faculty.courses.accounting-election-requests', [
            'pending' => $rows->where('request.status', 'pending')->values(),
            'decided' => $rows->where('request.status', '!=', 'pending')->values(),
        ]);
    }

    /** @return array<string, string> the methods the request would change */
    private function currentMethods(FirmAccountingElectionRequest $request, FirmAccountingElectionRequestService $service): array
    {
        $elections = app(FirmAccountingElectionService::class);
        $target = $request->status === 'pending' ? $service->effectiveQuarter($request) : null;

        return $request->previous_elections
            ?? ($target ? $elections->effective($request->firm, $target) : $elections->effective($request->firm, $request->courseQuarter));
    }

    private function pendingRequest(string $id): FirmAccountingElectionRequest
    {
        return FirmAccountingElectionRequest::query()
            ->where('id', $id)
            ->where('course_id', $this->course->id)
            ->where('status', 'pending')
            ->with('firm')
            ->firstOrFail();
    }

    private function faculty(): Faculty
    {
        $faculty = auth()->user()?->faculty;
        abort_unless($faculty !== null, 403);

        return $faculty;
    }
}
