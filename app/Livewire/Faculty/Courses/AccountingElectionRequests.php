<?php

namespace App\Livewire\Faculty\Courses;

use App\Models\Course;
use App\Models\Faculty;
use App\Models\FirmAccountingElectionRequest;
use App\Services\Accounting\FirmAccountingElectionRequestService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class AccountingElectionRequests extends Component
{
    public Course $course;

    public function mount(Course $course): void
    {
        app(FirmAccountingElectionRequestService::class)
            ->authorize($this->faculty(), $course);

        $this->course = $course;

    }

    public function approve(string $requestId): void
    {
        $request = FirmAccountingElectionRequest::query()
            ->where('id', $requestId)
            ->where('course_id', $this->course->id)
            ->where('status', 'pending')
            ->firstOrFail();

        app(FirmAccountingElectionRequestService::class)
            ->approve($this->faculty(), $request);

        session()->flash(
            'success',
            'The accounting-election change request has been approved.'
        );
    }

    public function reject(string $requestId): void
    {
        $request = FirmAccountingElectionRequest::query()
            ->where('id', $requestId)
            ->where('course_id', $this->course->id)
            ->where('status', 'pending')
            ->firstOrFail();

        app(FirmAccountingElectionRequestService::class)
            ->reject($this->faculty(), $request);

        session()->flash(
            'success',
            'The accounting-election change request has been rejected.'
        );
    }

    public function render(
        FirmAccountingElectionRequestService $service
    ): View {
        $requests = $service->pending(
            $this->faculty(),
            $this->course
        );

        return view(
            'livewire.faculty.courses.accounting-election-requests',
            [
                'requests' => $requests,
            ]
        );
    }

    private function faculty(): Faculty
    {
        $faculty = auth()->user()?->faculty;
        abort_unless($faculty !== null, 403);

        return $faculty;
    }
}