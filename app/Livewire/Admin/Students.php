<?php

namespace App\Livewire\Admin;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Student as StudentModel;
use App\Services\Admin\AdminDirectoryService;
use App\Services\Students\StudentAccess;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Items 6 and 45: enrol or unenrol a student, and mark the paid list from
 * Chuck so students' firm pages open (N3).
 */
#[Layout('layouts.admin')]
class Students extends Component
{
    public string $paidCourseId = '';

    public string $paidList = '';

    /** @var array<string, list<string>>|null */
    public ?array $paidReport = null;

    /** @var array<string, string> course chosen per student id */
    public array $enrollIn = [];

    public string $statusMessage = '';

    public function markPaid(AdminDirectoryService $service): void
    {
        if (blank($this->paidCourseId)) {
            $this->addError('paidCourseId', 'Choose the course the paid list is for.');

            return;
        }
        $course = Course::query()->findOrFail($this->paidCourseId);
        $this->paidReport = $service->markPaid($course, $this->paidList, auth()->user());
        $this->statusMessage = count($this->paidReport['marked']).' marked paid in '.$course->name.'.';
        $this->reset('paidList');
    }

    public function enroll(string $studentId, AdminDirectoryService $service): void
    {
        if (blank($this->enrollIn[$studentId] ?? null)) {
            $this->addError('enrollIn', 'Choose a course first.');

            return;
        }
        $student = StudentModel::query()->with('user')->findOrFail($studentId);
        $course = Course::query()->findOrFail($this->enrollIn[$studentId]);
        $service->enroll($student, $course);
        unset($this->enrollIn[$studentId]);
        $this->statusMessage = "{$student->user?->name} enrolled in {$course->name}. Their faculty can now place them in a firm.";
    }

    public function unenroll(string $enrollmentId, AdminDirectoryService $service): void
    {
        $enrollment = Enrollment::query()->with(['student.user', 'course'])->findOrFail($enrollmentId);
        $service->unenroll($enrollment);
        $this->statusMessage = "{$enrollment->student?->user?->name} removed from {$enrollment->course?->name}.";
    }

    public function render(): View
    {
        return view('livewire.admin.students', [
            'students' => StudentModel::query()
                ->where('is_practice', false)
                ->with(['user', 'institution', 'enrollments.course', 'firmMemberships.firm'])
                ->get()
                ->sortBy(fn ($s) => [$s->enrollments->isNotEmpty() ? 1 : 0, strtolower((string) $s->user?->name)])
                ->values(),
            'courses' => Course::query()->real()->orderBy('name')->get(),
            'paymentRequired' => StudentAccess::required(),
        ]);
    }
}
