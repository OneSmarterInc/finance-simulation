<?php

namespace App\Livewire\Faculty\Courses;

use App\Models\Course;
use App\Models\CourseFacultyRole;
use App\Models\Faculty;
use App\Models\Firm;
use App\Models\FirmEngineResult;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class QuarterResults extends Component
{
    public Course $course;

    #[Url(as: 'firm')]
    public ?string $selectedFirm = null;

    #[Url(as: 'quarter')]
    public ?int $selectedQuarter = null;

    public function mount(Course $course): void
    {
        $this->authorizeAccess($course);

        $this->course = $course;

        $firms = $this->course->firms()
            ->orderBy('code')
            ->get();

        if ($firms->isEmpty()) {
            return;
        }

        if (
            $this->selectedFirm === null
            || ! $firms->contains('id', $this->selectedFirm)
        ) {
            $this->selectedFirm = $firms->first()->id;
        }

        $this->setDefaultQuarter();
    }

    public function selectFirm(string $firmId): void
    {
        abort_unless(
            $this->course->firms()
                ->whereKey($firmId)
                ->exists(),
            404
        );

        $this->selectedFirm = $firmId;
        $this->setDefaultQuarter();
    }

    public function selectQuarter(int $quarter): void
    {
        abort_unless(
            $this->selectedFirm !== null
            && FirmEngineResult::query()
                ->where('course_id', $this->course->id)
                ->where('firm_id', $this->selectedFirm)
                ->where('quarter_number', $quarter)
                ->exists(),
            404
        );

        $this->selectedQuarter = $quarter;
    }

    public function render(): View
    {
        /** @var Collection<int, Firm> $firms */
        $firms = $this->course->firms()
            ->orderBy('code')
            ->get();

        /** @var Collection<int, FirmEngineResult> $results */
        $results = collect();

        $selected = null;

        if ($this->selectedFirm !== null) {
            $results = FirmEngineResult::query()
                ->where('course_id', $this->course->id)
                ->where('firm_id', $this->selectedFirm)
                ->orderBy('quarter_number')
                ->get();

            $selected = $results->firstWhere(
                'quarter_number',
                $this->selectedQuarter
            );
        }

        return view('livewire.faculty.courses.quarter-results', [
            'firms' => $firms,
            'results' => $results,
            'selected' => $selected,
            'trendRows' => $results->map(
                fn (FirmEngineResult $result): array => [
                    'quarter' => $result->quarter_number,
                    'revenue' => $result->income_statement['net_revenue'] ?? null,
                    'cash' => $result->fields['bs_cash'] ?? null,
                    'equity' => $result->balance_sheet['equity'] ?? null,
                    'operating_margin' => $result->fields['n5_cov_margin'] ?? null,
                ]
            ),
        ]);
    }

    private function setDefaultQuarter(): void
    {
        if ($this->selectedFirm === null) {
            $this->selectedQuarter = null;

            return;
        }

        $available = FirmEngineResult::query()
            ->where('course_id', $this->course->id)
            ->where('firm_id', $this->selectedFirm)
            ->orderBy('quarter_number')
            ->pluck('quarter_number');

        if (
            $this->selectedQuarter === null
            || ! $available->contains($this->selectedQuarter)
        ) {
            $this->selectedQuarter = $available->last();
        }
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