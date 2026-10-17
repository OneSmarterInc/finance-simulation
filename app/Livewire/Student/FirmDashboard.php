<?php

namespace App\Livewire\Student;

use App\Models\CourseFacultyRole;
use App\Models\Firm;
use App\Models\FirmEngineResult;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.student')]
class FirmDashboard extends Component
{
    /** @var list<string> */
    public const NON_MONETARY_NOTE_KEYS = [
        'n4_util_rate', 'n5_rate', 'n5_draw_rate', 'n5_cov_curr', 'n5_cov_de',
        'n5_cov_margin', 'n5_cov_interest', 'n5_cov_cash',
        'n5_shortfall_indicator', 'n6_po_fob',
    ];

    public Firm $firm;

    #[Url(as: 'quarter')]
    public ?int $selectedQuarter = null;

    public function mount(Firm $firm): void
    {
        $this->authorizeAccess($firm);
        $this->firm = $firm->load('course');
        $available = $firm->engineResults()->orderBy('quarter_number')->pluck('quarter_number');

        if ($this->selectedQuarter === null || ! $available->contains($this->selectedQuarter)) {
            $this->selectedQuarter = $available->last();
        }
    }

    public function selectQuarter(int $quarter): void
    {
        abort_unless($this->firm->engineResults()->where('quarter_number', $quarter)->exists(), 404);
        $this->selectedQuarter = $quarter;
    }

    public function render(): View
    {
        /** @var Collection<int, FirmEngineResult> $results */
        $results = $this->firm->engineResults()->orderBy('quarter_number')->get();
        $selected = $results->firstWhere('quarter_number', $this->selectedQuarter);

        return view('livewire.student.firm-dashboard', [
            'results' => $results,
            'selected' => $selected,
            'trendRows' => $results->map(fn (FirmEngineResult $result): array => [
                'quarter' => $result->quarter_number,
                'revenue' => $result->income_statement['net_revenue'] ?? null,
                'cash' => $result->fields['bs_cash'] ?? null,
                'equity' => $result->balance_sheet['equity'] ?? null,
                'operating_margin' => $result->fields['n5_cov_margin'] ?? null,
            ]),
            'nonMonetaryNoteKeys' => self::NON_MONETARY_NOTE_KEYS,
        ]);
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
    }
}
