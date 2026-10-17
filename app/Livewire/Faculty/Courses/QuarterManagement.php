<?php

namespace App\Livewire\Faculty\Courses;

use App\Models\Course;
use App\Models\Faculty;
use App\Services\Quarters\QuarterManagementService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class QuarterManagement extends Component
{
    public Course $course;

    public string $statusMessage = '';

    public function mount(Course $course, QuarterManagementService $service): void
    {
        $service->authorize($this->faculty(), $course);
        $this->course = $course;
    }

    public function open(string $quarterId, QuarterManagementService $service): void
    {
        $quarter = $this->course->quarters()->findOrFail($quarterId);
        $service->open($this->faculty(), $quarter);
        $this->statusMessage = "Quarter {$quarter->quarter_number} is open.";
    }

    public function close(string $quarterId, QuarterManagementService $service): void
    {
        $quarter = $this->course->quarters()->findOrFail($quarterId);
        $service->queueClose($this->faculty(), $quarter);
        $this->statusMessage = "Quarter {$quarter->quarter_number} close is queued.";
    }

    public function render(): View
    {
        return view('livewire.faculty.courses.quarter-management', [
            'quarters' => $this->course->quarters()->with(['closeAttempts.firm'])->orderBy('quarter_number')->get(),
        ]);
    }

    private function faculty(): Faculty
    {
        abort_unless(auth()->check() && auth()->user()->faculty, 403);

        return auth()->user()->faculty;
    }
}
