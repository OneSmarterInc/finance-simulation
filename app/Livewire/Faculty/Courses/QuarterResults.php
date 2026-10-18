<?php

namespace App\Livewire\Faculty\Courses;

use App\Livewire\InheritedHistory;
use App\Models\Course;
use App\Models\CourseFacultyRole;
use App\Models\Firm;
use App\Models\FirmEngineResult;
use App\Services\Accounting\AccountingChangeDisclosures;
use App\Services\Ai\ResultMeasures;
use App\Services\Decisions\FirmDecisionHistory;
use App\Services\Decisions\FirmDecisionService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
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

    /**
     * Live walkthrough L16: "All firms, every quarter" compares one measure
     * across the course, with the best firm marked each quarter. True means
     * higher is better.
     */
    public const COMPARE_MEASURES = [
        'value_created' => true,
        'net_income' => true,
        'cash' => true,
        'orders_not_filled' => false,
        'units_in_stock' => false,
    ];

    #[Url(as: 'measure')]
    public string $measure = 'value_created';

    public function mount(Course $course): void
    {
        $this->authorizeAccess($course);
        $this->course = $course;
        $this->mode = in_array($this->mode, ['firm', 'quarter', 'compare'], true) ? $this->mode : 'firm';
        $this->measure = array_key_exists($this->measure, self::COMPARE_MEASURES) ? $this->measure : 'value_created';

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

    public function showCompare(?string $measure = null): void
    {
        $this->mode = 'compare';
        if ($measure !== null) {
            abort_unless(array_key_exists($measure, self::COMPARE_MEASURES), 404);
            $this->measure = $measure;
        }
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

        // L16: one measure, every firm, every quarter, with the best firm marked from Quarter 4.
        $compare = [];
        $best = [];
        if ($this->mode === 'compare') {
            $all = FirmEngineResult::query()->where('course_id', $this->course->id)->orderBy('quarter_number')->get()->groupBy('firm_id');
            foreach ($firms as $firm) {
                $own = $all->get($firm->id, collect());
                foreach ($own as $result) {
                    $compare[$firm->id][$result->quarter_number] = ResultMeasures::value($this->measure, $result, $own);
                }
            }
            $higher = self::COMPARE_MEASURES[$this->measure];
            foreach ($this->availableQuarters() as $quarter) {
                if ($quarter < 4) {
                    continue;
                }
                $values = collect($compare)->map(fn ($row) => $row[$quarter] ?? null)->filter(fn ($v) => $v !== null);
                if ($values->count() > 1 && $values->unique()->count() > 1) {
                    $best[$quarter] = $higher ? $values->sortDesc()->keys()->first() : $values->sort()->keys()->first();
                }
            }
        }

        return view('livewire.faculty.courses.quarter-results', [
            'compare' => $compare,
            'best' => $best,
            'measures' => collect(self::COMPARE_MEASURES)->mapWithKeys(fn ($higher, $key) => [$key => ResultMeasures::MEASURES[$key][0]])->all(),
            'firms' => $firms,
            'quarters' => $this->availableQuarters(),
            'columns' => $columns,
            'decisionColumns' => $decisionColumns,
            'disclosureColumns' => $disclosureColumns,
            'parameters' => $firms->isNotEmpty() ? $decisions->parameters($firms->first()) : [],
        ]);
    }

    /** @return Collection<int, int> */
    private function availableQuarters(): Collection
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
