<?php

namespace App\Livewire\Faculty\Courses;

use App\Models\Course;
use App\Models\CourseQuarter;
use App\Models\FirmQuarterInterpretation;
use App\Services\Courses\CourseStatus;
use App\Services\Interpretations\InterpretationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * U11 (IN3): each team's explanation of a quarter, read beside its status,
 * with a comment the team sees, the course's requirement switch and a
 * spreadsheet of every explanation for grading outside Flexee.
 */
#[Layout('layouts.app')]
class TeamExplanations extends Component
{
    public Course $course;

    #[Url(as: 'quarter')]
    public ?int $selectedQuarter = null;

    /** @var array<string, string> comments as typed, keyed by firm id */
    public array $comments = [];

    public string $statusMessage = '';

    public function mount(Course $course, InterpretationService $service): void
    {
        $faculty = auth()->user()?->faculty;
        abort_unless($faculty !== null, 403);
        try {
            $service->authorizeFaculty($faculty, $course);
        } catch (AuthorizationException) {
            abort(403);
        }
        abort_if($course->is_practice, 404);
        $this->course = $course;
        $quarters = $this->quarters();
        if ($this->selectedQuarter === null || ! $quarters->contains($this->selectedQuarter)) {
            $this->selectedQuarter = $quarters->last();
        }
        $this->loadComments();
    }

    public function showQuarter(int $quarter): void
    {
        abort_unless($this->quarters()->contains($quarter), 404);
        $this->selectedQuarter = $quarter;
        $this->statusMessage = '';
        $this->loadComments();
    }

    public function saveComment(string $firmId, InterpretationService $service): void
    {
        $row = $this->rows()->firstWhere('firm_id', $firmId);
        abort_unless($row !== null, 404);
        try {
            $service->comment(auth()->user()->faculty, $row, $this->comments[$firmId] ?? '');
        } catch (ValidationException $e) {
            $this->addError('comments.'.$firmId, collect($e->errors())->flatten()->first());

            return;
        }
        $this->statusMessage = "Comment saved. {$row->firm->name} sees it on its decisions and History pages.";
    }

    public function toggleRequired(InterpretationService $service): void
    {
        $service->setRequired(auth()->user()->faculty, $this->course, ! $this->course->interpretations_required);
        $this->course->refresh();
        $this->statusMessage = $this->course->interpretations_required
            ? 'Teams now explain each quarter before they can submit the next quarter\'s decisions.'
            : 'Teams can now submit decisions without an explanation. Explanations already written stay here.';
    }

    public function render(): View
    {
        $status = CourseStatus::for($this->course);
        $rows = $this->rows()->keyBy('firm_id');
        $quarter = $this->selectedQuarter ? $status->quarters->firstWhere('quarter_number', $this->selectedQuarter) : null;
        $next = $quarter ? $status->quarters->firstWhere('quarter_number', $quarter->quarter_number + 1) : null;

        return view('livewire.faculty.courses.team-explanations', [
            'quarters' => $this->quarters(),
            'firms' => $status->firms,
            'rows' => $rows,
            'quarter' => $quarter,
            'dueBy' => $next?->decision_deadline_at,
            'submitted' => $rows->filter(fn (FirmQuarterInterpretation $r) => $r->isSubmitted())->count(),
        ]);
    }

    /** Quarters a team explains: from the last warm-up quarter up to the one before the open or next quarter. */
    private function quarters(): Collection
    {
        $status = CourseStatus::for($this->course);
        $current = $status->current()?->quarter_number ?? ($status->isFinished() ? (int) $status->quarters->max('quarter_number') + 1 : 4);
        $written = FirmQuarterInterpretation::query()->where('firm_quarter_interpretations.course_id', $this->course->id)
            ->join('course_quarters', 'course_quarters.id', '=', 'firm_quarter_interpretations.course_quarter_id')
            ->pluck('course_quarters.quarter_number');

        return collect(range(3, max(3, $current - 1)))->merge($written)->unique()->sort()->values()
            ->filter(fn ($q) => $status->quarters->contains('quarter_number', $q))->values();
    }

    /** @return Collection<int, FirmQuarterInterpretation> */
    private function rows(): Collection
    {
        $quarter = CourseQuarter::query()->where('course_id', $this->course->id)->where('quarter_number', $this->selectedQuarter)->first();

        return $quarter ? FirmQuarterInterpretation::query()->where('course_quarter_id', $quarter->id)->with(['firm', 'submittedBy.user'])->get() : collect();
    }

    private function loadComments(): void
    {
        $this->comments = $this->rows()->mapWithKeys(fn (FirmQuarterInterpretation $r) => [$r->firm_id => (string) $r->faculty_comment])->all();
    }
}
