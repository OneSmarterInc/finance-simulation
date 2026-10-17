<?php

namespace App\Livewire\Faculty\Courses;

use App\Models\Course;
use App\Models\Faculty;
use App\Models\Firm;
use App\Models\Student;
use App\Services\Courses\CourseConfigurationService;
use App\Services\Firms\FirmAssignmentService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class FirmManager extends Component
{
    public Course $course;

    public string $code = '';

    public string $name = '';

    public string $studentId = '';

    public string $firmId = '';

    public function mount(Course $course): void
    {
        app(CourseConfigurationService::class)->authorize($this->faculty(), $course);
        $this->course = $course;
    }

    public function createFirm(FirmAssignmentService $service): void
    {
        $service->createFirm($this->faculty(), $this->course, $this->code, $this->name);
        $this->reset('code', 'name');
    }

    public function enrollAndAssign(FirmAssignmentService $service): void
    {
        $student = Student::query()->findOrFail($this->studentId);
        $firm = Firm::query()->findOrFail($this->firmId);
        $service->enroll($this->faculty(), $this->course, $student);
        $service->assign($this->faculty(), $this->course, $firm, $student);
        $this->reset('studentId', 'firmId');
    }

    public function render(): View
    {
        return view('livewire.faculty.courses.firm-manager', [
            'firms' => $this->course->firms()
                ->withCount(['memberships', 'engineResults'])
                ->orderBy('code')
                ->get(),

            'students' => Student::query()
                ->with('user')
                ->where('institution_id', $this->course->institution_id)
                ->whereHas('enrollments', function ($query) {
                    $query->where('course_id', $this->course->id);
                })
                ->orderBy('id')
                ->get(),
        ]);
    }

    private function faculty(): Faculty
    {
        abort_unless(auth()->check() && auth()->user()->faculty, 403);

        return auth()->user()->faculty;
    }
}
