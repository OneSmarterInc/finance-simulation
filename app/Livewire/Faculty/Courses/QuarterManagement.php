<?php

namespace App\Livewire\Faculty\Courses;

use App\Models\Course;
use App\Models\Faculty;
use App\Services\Courses\CourseStatus;
use App\Services\Quarters\QuarterManagementService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Quarters page (walkthrough items 47 to 51, N5). Quarters open and close on
 * schedule; faculty can extend the open quarter's deadline and retry a firm
 * whose results failed to calculate.
 */
#[Layout('layouts.app')]
class QuarterManagement extends Component
{
    public Course $course;

    public string $statusMessage = '';

    public string $newDeadline = '';

    public function mount(Course $course, QuarterManagementService $service): void
    {
        $service->authorize($this->faculty(), $course);
        $this->course = $course;
    }

    public function extend(string $quarterId, QuarterManagementService $service): void
    {
        $quarter = $this->course->quarters()->findOrFail($quarterId);
        $service->extendDeadline($this->faculty(), $quarter, $this->newDeadline);
        $this->newDeadline = '';
        $this->statusMessage = "Quarter {$quarter->quarter_number}'s deadline is now ".\App\Support\Dates::dateTime($quarter->fresh()->decision_deadline_at).'.';
    }

    public function retry(string $quarterId, QuarterManagementService $service): void
    {
        $quarter = $this->course->quarters()->findOrFail($quarterId);
        $service->retry($this->faculty(), $quarter);
        $this->statusMessage = "Retrying the firms in Quarter {$quarter->quarter_number} whose results failed.";
    }

    public function render(): View
    {
        return view('livewire.faculty.courses.quarter-management', [
            'quarters' => $this->course->quarters()->with(['closeAttempts.firm'])->orderBy('quarter_number')->get(),
            'status' => CourseStatus::for($this->course),
        ]);
    }

    private function faculty(): Faculty
    {
        abort_unless(auth()->check() && auth()->user()->faculty, 403);

        return auth()->user()->faculty;
    }
}
