<?php

namespace App\Livewire\Faculty\Courses;

use App\Models\Course;
use App\Models\Faculty;
use App\Models\Firm;
use App\Models\Student;
use App\Services\Courses\CourseConfigurationService;
use App\Services\Courses\CourseStatus;
use App\Services\Firms\FirmAssignmentService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Firms page (walkthrough items 28 to 33): who is in each firm, how each
 * firm stands this quarter, and the setup forms moved below once play starts.
 */
#[Layout('layouts.app')]
class FirmManager extends Component
{
	public Course $course;

	public string $code = '';

	public string $name = '';

	public string $studentId = '';

	public string $firmId = '';

	public string $statusMessage = '';

	public function mount(Course $course): void
	{
		app(CourseConfigurationService::class)->authorize($this->faculty(), $course);

		$this->course = $course;
	}

	public function createFirm(FirmAssignmentService $service): void
	{
		$firm = $service->createFirm($this->faculty(), $this->course, $this->code, $this->name);
		$this->reset('code', 'name');
		$this->statusMessage = "{$firm->name} created. Its warm-up quarters are generated once a student is placed in it.";
	}

	public function enrollAndAssign(FirmAssignmentService $service): void
	{
		if (blank($this->studentId) || blank($this->firmId)) {
			$this->addError('placement', 'Choose a student and a firm.');

			return;
		}
		$student = Student::query()->findOrFail($this->studentId);
		$firm = Firm::query()->findOrFail($this->firmId);

		$service->enroll($this->faculty(), $this->course, $student);
		$service->assign($this->faculty(), $this->course, $firm, $student);

		$this->reset('studentId', 'firmId');
		$this->statusMessage = "{$student->user?->name} is now in {$firm->name}.";
	}

	/** Item 30: the service could remove a student from a firm, but no screen called it. */
	public function removeMember(string $studentId, FirmAssignmentService $service): void
	{
		$student = Student::query()->with('user')->findOrFail($studentId);
		$service->unassign($this->faculty(), $this->course, $student);
		$this->statusMessage = "{$student->user?->name} has been removed from their firm.";
	}

	public function importDemoStudents(FirmAssignmentService $service): void
	{
		abort_unless(config('flexee.demo_tools'), 404);

		$service->importDemoStudents($this->faculty(), $this->course);
	}

	public function render(): View
	{
		$status = CourseStatus::for($this->course);
		$firms = $this->course->firms()
			->with(['memberships.student.user'])
			->orderBy('code')
			->get();

		$students = Student::query()
			->with('user')
			->where('institution_id', $this->course->institution_id)
			->whereHas('enrollments', fn ($query) => $query->where('course_id', $this->course->id))
			->whereDoesntHave('firmMemberships', fn ($query) => $query->where('course_id', $this->course->id))
			->orderBy('id')
			->get();

		$current = $status->current();
		$started = $this->course->quarters()->where('quarter_number', '>=', 4)->where('state', '!=', 'locked')->exists();

		$showDemoImport = config('flexee.demo_tools')
			&& ! $started
			&& $firms->isNotEmpty()
			&& Student::query()
				->where('institution_id', $this->course->institution_id)
				->whereHas('user', fn ($query) => $query->where('email', 'like', 'student%@example.com'))
				->whereDoesntHave('enrollments', fn ($query) => $query->where('course_id', $this->course->id))
				->exists();

		return view('livewire.faculty.courses.firm-manager', [
			'status' => $status,
			'current' => $current,
			'started' => $started,
			'firms' => $firms,
			'students' => $students,
			'showDemoImport' => $showDemoImport,
		]);
	}

	private function faculty(): Faculty
	{
		abort_unless(auth()->check() && auth()->user()->faculty, 403);

		return auth()->user()->faculty;
	}
}
