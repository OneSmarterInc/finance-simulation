<?php

namespace App\Livewire\Student;

use App\Livewire\InheritedHistory;
use App\Models\CourseFacultyRole;
use App\Models\Firm;
use App\Models\StudentExplainer;
use App\Services\Accounting\AccountingChangeDisclosures;
use App\Services\Courses\CourseStatus;
use App\Services\Decisions\FirmDecisionHistory;
use App\Services\Decisions\FirmDecisionService;
use App\Services\Interpretations\InterpretationService;
use App\Support\Explainers\Explainers;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Renderless;
use Livewire\Component;

/**
 * Where the firm stands now (walkthrough items 70, 71): what the team has
 * to do this quarter, then the latest quarter beside the one before it.
 * The full record lives on History (item 41).
 */
class FirmDashboard extends Component
{
    /** @var list<string> */
    public const NON_MONETARY_NOTE_KEYS = [
        'n4_util_rate', 'n5_rate', 'n5_draw_rate', 'n5_cov_curr', 'n5_cov_de',
        'n5_cov_margin', 'n5_cov_interest', 'n5_cov_cash',
        'n5_shortfall_indicator', 'n6_po_fob',
    ];

    public Firm $firm;

    public bool $facultyView = false;

    public function mount(Firm $firm): void
    {
        $this->authorizeAccess($firm);
        $this->firm = $firm->load('course');
    }

    /**
     * U4 layer 2 (MO3): the student reached the explainer's last step. The
     * card stays for the rest of this visit and is gone the next time.
     */
    #[Renderless]
    public function explainerWatched(): void
    {
        if ($student = $this->member()) {
            StudentExplainer::mark($student, $this->firm->course_id, Explainers::QUARTER, 'watched_at');
        }
    }

    /** U4 layer 2 (MO3): "Hide this". The explainer stays on the Guide page. */
    public function dismissExplainer(): void
    {
        if ($student = $this->member()) {
            StudentExplainer::mark($student, $this->firm->course_id, Explainers::QUARTER, 'dismissed_at');
        }
    }

    /** The signed-in student's id, when they are on this firm (never for faculty viewing it). */
    private function member(): ?string
    {
        $student = auth()->user()?->student;

        return ! $this->facultyView && $student && $this->firm->memberships()->where('student_id', $student->id)->exists() ? $student->id : null;
    }

    public function render(FirmDecisionHistory $history, FirmDecisionService $decisions, AccountingChangeDisclosures $disclosures): View
    {
        $status = CourseStatus::for($this->firm->course);
        $active = $status->active();
        $results = $this->firm->engineResults()->orderBy('quarter_number')->get();
        $latest = $results->slice(-2);

        $columns = [];
        $decisionColumns = [];
        $disclosureColumns = [];
        foreach ($latest as $result) {
            $label = InheritedHistory::columnLabel($result);
            $columns[$label] = $result;
            $decisionColumns[$label] = $history->forQuarter($this->firm, $result->quarter_number);
            $disclosureColumns[$label] = $disclosures->forQuarter($this->firm, $result->quarter_number);
        }

        $member = $this->member();
        $explainer = $member && ! $this->firm->course->is_practice
            && ! StudentExplainer::settled($member, $this->firm->course_id, Explainers::QUARTER)
            ? Explainers::all()[Explainers::QUARTER] : null;

        return view('livewire.student.firm-dashboard', [
            'explainer' => $explainer,
            'status' => $status,
            'active' => $active,
            'teamStatus' => $active ? $status->firmStatus($this->firm, $active) : null,
            // U11: the quarter the team still has to explain before it can submit, if any.
            'toExplain' => $active && ! app(InterpretationService::class)->satisfied($this->firm, $active)
                ? app(InterpretationService::class)->explainedQuarter($this->firm, $active) : null,
            'results' => $results,
            'columns' => $columns,
            'decisionColumns' => $decisionColumns,
            'disclosureColumns' => $disclosureColumns,
            'parameters' => $decisions->parameters($this->firm),
            'members' => $this->firm->memberships()->with('student.user')->get()->map(fn ($m) => $m->student?->user?->name)->filter()->values(),
        ])->layout($this->facultyView ? 'layouts.app' : 'layouts.student', ['title' => $this->firm->name]);
    }

    private function authorizeAccess(Firm $firm): void
    {
        $user = auth()->user();
        abort_unless($user !== null, 403);

        $studentAccess = $user->student !== null
            && $user->student->institution_id === $firm->institution_id
            && $firm->memberships()->where('student_id', $user->student->id)->exists();

        $facultyAccess = $user->faculty !== null
            && $user->faculty->institution_id === $firm->institution_id
            && ($firm->course()->where('faculty_id', $user->faculty->id)->exists()
                || CourseFacultyRole::query()
                    ->where('course_id', $firm->course_id)
                    ->where('faculty_id', $user->faculty->id)
                    ->exists());

        abort_unless($studentAccess || $facultyAccess, 403);

        $this->facultyView = $facultyAccess && ! $studentAccess;
    }
}
