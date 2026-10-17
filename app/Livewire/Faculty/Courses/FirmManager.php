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
		$service->createFirm(
			$this->faculty(),
			$this->course,
			$this->code,
			$this->name
		);

		$this->reset('code', 'name');
	}

	public function enrollAndAssign(FirmAssignmentService $service): void
	{
		$student = Student::query()->findOrFail($this->studentId);
		$firm = Firm::query()->findOrFail($this->firmId);

		$service->enroll(
			$this->faculty(),
			$this->course,
			$student
		);

		$service->assign(
			$this->faculty(),
			$this->course,
			$firm,
			$student
		);

		$this->reset('studentId', 'firmId');
	}

	public function importDemoStudents(FirmAssignmentService $service): void
	{
		$service->importDemoStudents(
			$this->faculty(),
			$this->course,
		);
	}

	public function render(): View
	{
		$firms = $this->course->firms()
			->withCount(['memberships', 'engineResults'])
			->orderBy('code')
			->get();

		/*
         * IMPORTANT:
         * Only students enrolled in THIS course are available
         * for firm assignment.
         */
		$students = Student::query()
			->with('user')
			->where('institution_id', $this->course->institution_id)
			->whereHas('enrollments', function ($query) {
				$query->where('course_id', $this->course->id);
			})
			->whereDoesntHave('firmMemberships', function ($query) {
                $query->where('course_id', $this->course->id);
        	})
			->orderBy('id')
			->get();

		$enrolledCount = $this->course->enrollments()->count();

		$assignedCount = $this->course->enrollments()
			->whereHas('student.firmMemberships', function ($query) {
				$query->where('course_id', $this->course->id);
			})
			->count();

		$pendingCount = $enrolledCount - $assignedCount;

		/*
         * Demo import should only be visible while there are
         * unassigned demo students that can still be imported.
         */
		$demoStudents = [
			['Student One', 'student@example.com'],
			['Student Two', 'student2@example.com'],
			['Student Three', 'student3@example.com'],
			['Student Four', 'student4@example.com'],
			['Student Five', 'student5@example.com'],
			['Student Six', 'student6@example.com'],
			['Student Seven', 'student7@example.com'],
			['Student Eight', 'student8@example.com'],
			['Student Nine', 'student9@example.com'],
			['Student Ten', 'student10@example.com'],
			['Student Eleven', 'student11@example.com'],
			['Student Twelve', 'student12@example.com'],
			['Student Thirteen', 'student13@example.com'],
			['Student Fourteen', 'student14@example.com'],
			['Student Fifteen', 'student15@example.com'],
		];

		$demoEmails = collect($demoStudents)
			->pluck(1)
			->values();

		$importableDemoCount = Student::query()
			->where('institution_id', $this->course->institution_id)
			->whereHas('user', function ($query) use ($demoEmails) {
				$query->whereIn('email', $demoEmails);
			})
			->whereDoesntHave('enrollments', function ($query) {
				$query->where('course_id', $this->course->id);
			})
			->count();

		$totalFirmCapacity = $firms->count() * 2;
		$assignedStudentsCount = $firms->sum('memberships_count');
		$availableFirmSlots = max(
				0,
				$totalFirmCapacity - $assignedStudentsCount
		);

		$showDemoImport = $firms->isNotEmpty()
		&& $importableDemoCount > 0
		&& $availableFirmSlots > 0;

		return view('livewire.faculty.courses.firm-manager', [
			'firms' => $firms,
			'students' => $students,
			'enrolledCount' => $enrolledCount,
			'assignedCount' => $assignedCount,
			'pendingCount' => $pendingCount,
			'showDemoImport' => $showDemoImport,
			'importableDemoCount' => $importableDemoCount,
			'availableFirmSlots' => $availableFirmSlots,
		]);
	}

	private function faculty(): Faculty
	{
		abort_unless(
			auth()->check() && auth()->user()->faculty,
			403
		);

		return auth()->user()->faculty;
	}
}
