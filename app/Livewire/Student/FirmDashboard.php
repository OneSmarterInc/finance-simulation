<?php

namespace App\Livewire\Student;

use App\Livewire\InheritedHistory;
use App\Models\CourseFacultyRole;
use App\Models\Firm;
use App\Services\Accounting\AccountingChangeDisclosures;
use App\Services\Courses\CourseStatus;
use App\Services\Decisions\FirmDecisionHistory;
use App\Services\Decisions\FirmDecisionService;
use App\Services\Interpretations\InterpretationService;
use Illuminate\Contracts\View\View;
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

        return view('livewire.student.firm-dashboard', [
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
