<?php

namespace App\Livewire;

use App\Models\CourseFacultyRole;
use App\Models\Firm;
use App\Models\FirmEngineResult;
use App\Models\FirmQuarterInterpretation;
use App\Services\Accounting\AccountingChangeDisclosures;
use App\Services\Decisions\FirmDecisionHistory;
use App\Services\Decisions\FirmDecisionService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The firm's complete record (walkthrough item 41): every closed quarter,
 * warm-up quarters marked as inherited, quarters side by side (item 40),
 * notes included (item 35) and the decisions beside the statements they
 * produced (item 36). Faculty see it inside the faculty layout (item 34).
 */
class InheritedHistory extends Component
{
    public Firm $firm;

    public bool $facultyView = false;

    public function mount(Firm $firm): void
    {
        $user = auth()->user();

        abort_unless($user !== null, 403);

        $facultyAccess = $user->faculty !== null
            && $user->faculty->institution_id === $firm->institution_id
            && (
                $firm->course()->where('faculty_id', $user->faculty->id)->exists()
                || CourseFacultyRole::query()
                    ->where('course_id', $firm->course_id)
                    ->where('faculty_id', $user->faculty->id)
                    ->exists()
            );

        $studentAccess = $user->student !== null
            && $user->student->institution_id === $firm->institution_id
            && $firm->memberships()
                ->where('student_id', $user->student->id)
                ->exists();

        abort_unless($facultyAccess || $studentAccess, 403);

        $this->facultyView = $facultyAccess && ! $studentAccess;
        $this->firm = $firm->load('course');
    }

    public function render(FirmDecisionHistory $history, FirmDecisionService $decisions, AccountingChangeDisclosures $disclosures): View
    {
        $results = $this->firm->engineResults()->orderBy('quarter_number')->get();

        $columns = [];
        $decisionColumns = [];
        $disclosureColumns = [];
        foreach ($results as $result) {
            $label = self::columnLabel($result);
            $columns[$label] = $result;
            $decisionColumns[$label] = $history->forQuarter($this->firm, $result->quarter_number);
            $disclosureColumns[$label] = $disclosures->forQuarter($this->firm, $result->quarter_number);
        }

        return view('livewire.inherited-history', [
            'results' => $results,
            'columns' => $columns,
            'decisionColumns' => $decisionColumns,
            'disclosureColumns' => $disclosureColumns,
            'parameters' => $decisions->parameters($this->firm),
            // U11: the team's explanations, newest quarter first, with the instructor's comments.
            'explanations' => FirmQuarterInterpretation::query()->where('firm_id', $this->firm->id)
                ->with(['courseQuarter', 'submittedBy.user'])->get()
                ->sortByDesc(fn ($row) => $row->courseQuarter?->quarter_number)->values(),
        ])->layout($this->facultyView ? 'layouts.app' : 'layouts.student', ['title' => $this->firm->name.' history']);
    }

    public static function columnLabel(FirmEngineResult $result): string
    {
        return 'Q'.$result->quarter_number.($result->provenance === FirmEngineResult::PROVENANCE_WARMUP ? ' · warm-up' : '');
    }
}
