<?php

namespace App\Livewire\Faculty\Courses;

use App\Livewire\InheritedHistory;
use App\Models\Course;
use App\Models\CourseFacultyRole;
use App\Models\Firm;
use App\Models\FirmEngineResult;
use App\Services\Accounting\AccountingChangeDisclosures;
use App\Services\Decisions\FirmDecisionHistory;
use App\Services\Decisions\FirmDecisionService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Quarter results (walkthrough item 52): one report at a time in tabs.
 * Read one firm across its quarters, or compare every firm in one quarter.
 */
#[Layout('layouts.app')]
class QuarterResults extends Component
{
    public Course $course;

    #[Url(as: 'view')]
    public string $mode = 'firm';

    #[Url(as: 'firm')]
    public ?string $selectedFirm = null;

    #[Url(as: 'quarter')]
    public ?int $selectedQuarter = null;

    public function mount(Course $course): void
    {
        $this->authorizeAccess($course);
        $this->course = $course;
        $this->mode = in_array($this->mode, ['firm', 'quarter'], true) ? $this->mode : 'firm';

        $firms = $this->course->firms()->orderBy('code')->get();
        if ($firms->isEmpty()) {
            return;
        }
        if ($this->selectedFirm === null || ! $firms->contains('id', $this->selectedFirm)) {
            $this->selectedFirm = $firms->first()->id;
        }
        $quarters = $this->availableQuarters();
        if ($this->selectedQuarter === null || ! $quarters->contains($this->selectedQuarter)) {
            $this->selectedQuarter = $quarters->last();
        }
    }

    public function showFirm(string $firmId): void
    {
        abort_unless($this->course->firms()->whereKey($firmId)->exists(), 404);
        $this->mode = 'firm';
        $this->selectedFirm = $firmId;
    }

    public function showQuarter(int $quarter): void
    {
        abort_unless($this->availableQuarters()->contains($quarter), 404);
        $this->mode = 'quarter';
        $this->selectedQuarter = $quarter;
    }

    public function render(FirmDecisionHistory $history, FirmDecisionService $decisions, AccountingChangeDisclosures $disclosures): View
    {
        $firms = $this->course->firms()->orderBy('code')->get();
        $columns = [];
        $decisionColumns = [];
        $disclosureColumns = [];

        if ($this->mode === 'firm' && $this->selectedFirm) {
            $firm = $firms->firstWhere('id', $this->selectedFirm);
            $results = FirmEngineResult::query()->where('course_id', $this->course->id)
                ->where('firm_id', $this->selectedFirm)->orderBy('quarter_number')->get();
            foreach ($results as $result) {
                $label = InheritedHistory::columnLabel($result);
                $columns[$label] = $result;
                $decisionColumns[$label] = $history->forQuarter($firm, $result->quarter_number);
                $disclosureColumns[$label] = $disclosures->forQuarter($firm, $result->quarter_number);
            }
        } elseif ($this->mode === 'quarter' && $this->selectedQuarter) {
            $results = FirmEngineResult::query()->where('course_id', $this->course->id)
                ->where('quarter_number', $this->selectedQuarter)->get()->keyBy('firm_id');
            foreach ($firms as $firm) {
                if ($result = $results->get($firm->id)) {
                    $columns[$firm->name] = $result;
                    $decisionColumns[$firm->name] = $history->forQuarter($firm, $this->selectedQuarter);
                    $disclosureColumns[$firm->name] = $disclosures->forQuarter($firm, $this->selectedQuarter);
                }
            }
        }

        return view('livewire.faculty.courses.quarter-results', [
            'firms' => $firms,
            'quarters' => $this->availableQuarters(),
            'columns' => $columns,
            'decisionColumns' => $decisionColumns,
            'disclosureColumns' => $disclosureColumns,
            'parameters' => $firms->isNotEmpty() ? $decisions->parameters($firms->first()) : [],
        ]);
    }

    /** @return \Illuminate\Support\Collection<int, int> */
    private function availableQuarters(): \Illuminate\Support\Collection
    {
        return FirmEngineResult::query()->where('course_id', $this->course->id)
            ->distinct()->orderBy('quarter_number')->pluck('quarter_number');
    }

    private function authorizeAccess(Course $course): void
    {
        $user = auth()->user();

        abort_unless($user?->faculty !== null, 403);

        $faculty = $user->faculty;

        $allowed = $course->faculty_id === $faculty->id
            || CourseFacultyRole::query()
                ->where('course_id', $course->id)
                ->where('faculty_id', $faculty->id)
                ->where('role', 'co_faculty')
                ->exists();

        abort_unless($allowed, 403);
    }
}
