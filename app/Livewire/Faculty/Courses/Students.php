<?php

namespace App\Livewire\Faculty\Courses;

use App\Models\Course;
use App\Models\Faculty;
use App\Models\Firm;
use App\Models\Student;
use App\Services\Firms\FirmAssignmentService;
use App\Services\Students\StudentAccess;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Students page (walkthrough items 42 to 45): the course link, everyone
 * registered with their firm and access status, unplaced students first
 * with a firm picker right in the row.
 */
#[Layout('layouts.app')]
class Students extends Component
{
    public Course $course;

    /** @var array<string, string> firm chosen per unplaced student id */
    public array $placement = [];

    public string $statusMessage = '';

    public function mount(Course $course): void
    {
        $faculty = $this->faculty();

        abort_unless(
            $course->faculty_id === $faculty->id
                || $course->facultyRoles()->where('faculty_id', $faculty->id)->where('role', 'co_faculty')->exists(),
            403
        );

        $this->course = $course;
    }

    public function place(string $studentId, FirmAssignmentService $service): void
    {
        abort_unless($this->course->faculty_id === $this->faculty()->id, 403);
        if (blank($this->placement[$studentId] ?? null)) {
            $this->addError('placement', 'Choose a firm first.');

            return;
        }
        $student = Student::query()->with('user')->findOrFail($studentId);
        $firm = Firm::query()->where('course_id', $this->course->id)->findOrFail($this->placement[$studentId]);

        $service->assign($this->faculty(), $this->course, $firm, $student);
        unset($this->placement[$studentId]);
        $this->statusMessage = "{$student->user?->name} is now in {$firm->name}.";
    }

    public function render(): View
    {
        $enrollments = $this->course->enrollments()
            ->with([
                'student.user',
                'student.firmMemberships' => fn ($query) => $query->where('course_id', $this->course->id)->with('firm'),
            ])
            ->get()
            ->sortBy(fn ($enrollment) => [
                $enrollment->student?->firmMemberships->isNotEmpty() ? 1 : 0,
                $enrollment->student?->firmMemberships->first()?->firm?->name ?? '',
                strtolower($enrollment->student?->user?->name ?? ''),
            ])
            ->values();

        return view('livewire.faculty.courses.students', [
            'enrollments' => $enrollments,
            'firms' => $this->course->firms()->orderBy('code')->get(),
            'paymentRequired' => StudentAccess::required(),
            'isOwner' => $this->course->faculty_id === $this->faculty()->id,
            'registrationUrl' => $this->course->registration_token ? url('/register/course/'.$this->course->registration_token) : null,
        ]);
    }

    private function faculty(): Faculty
    {
        abort_unless(auth()->check() && auth()->user()->faculty, 403);

        return auth()->user()->faculty;
    }
}
