<?php

namespace App\Livewire\Admin;

use App\Models\Course;
use App\Models\Faculty;
use App\Models\Institution;
use App\Models\Scenario;
use App\Services\Courses\AdminCourseConfigurationService;
use App\Services\Courses\CourseConfigurationService;
use App\Services\Courses\ScheduleRows;
use App\Support\Dates;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin')]
class CoursePreparation extends Component
{
    public ?Course $course = null;

    public int $step = 1;

    public int $quarter_count = 4;

    /** @var array<int, array{quarter_number: int, starts_on: string, ends_on: string, decision_deadline_at: string}> */
    public array $quarters = [];

    /** @var array<int, array<string, bool>> */
    public array $matrix = [];

    public string $name = '';

    public string $institution_id = '';

    public string $faculty_id = '';

    public string $scenario_id = '';

    public ?string $starts_on = null;

    public ?string $ends_on = null;

    public string $payment_mode = 'standard';

    public bool $cf_plus_enabled = false;

    public ?int $build_quarters = null;

    public ?int $adversarial_quarters = null;

    public ?int $post_bid_quarters = null;

    public bool $accounting_election_enabled = false;

    public bool $industry_acknowledged = false;

    /**
     * Each click starts with a clean slate, so a message from an earlier,
     * failed save never stays on screen after the next save works (live review R10).
     */
    public function hydrate(): void
    {
        $this->resetErrorBag();
    }

    public function mount(
        ?Course $course,
        AdminCourseConfigurationService $configurationService
    ): void {
        $configurationService->authorize(auth()->user()->admin);

        if ($course === null || ! $course->exists) {
            return;
        }

        $this->course = $course->load([
            'institution',
            'faculty.user',
            'scenario',
            'quarters',
            'facultyRoles',
        ]);

        if ($this->course->status === Course::STATUS_DEPLOYED) {
            $this->step = 6;
        }

        $this->name = $course->name;
        $this->institution_id = $course->institution_id;
        $this->faculty_id = $course->faculty_id;
        $this->scenario_id = $course->scenario_id;
        $this->starts_on = $course->starts_on?->format('Y-m-d');
        $this->ends_on = $course->ends_on?->format('Y-m-d');
        $this->payment_mode = $course->payment_mode ?? 'standard';
        $this->cf_plus_enabled = (bool) $course->cf_plus_enabled;
        $this->build_quarters = $course->build_quarters;
        $this->adversarial_quarters = $course->adversarial_quarters;
        $this->post_bid_quarters = $course->post_bid_quarters;
        $this->accounting_election_enabled = (bool) $course->accounting_election_enabled;

        $this->quarters = $course->quarters
            ->sortBy('quarter_number')
            ->map(fn ($quarter): array => [
                'quarter_number' => (int) $quarter->quarter_number,
                'starts_on' => $quarter->starts_on->format('Y-m-d'),
                'ends_on' => $quarter->ends_on->format('Y-m-d'),
                'decision_deadline_at' => Dates::localDate($quarter->decision_deadline_at),
            ])
            ->values()
            ->all();

        $this->quarter_count = max(4, count($this->quarters));

        foreach (
            $course->quarters()
                ->where('quarter_number', '>=', 4)
                ->with('enablements.category')
                ->orderBy('quarter_number')
                ->get() as $quarter
        ) {
            foreach ($quarter->enablements as $enablement) {
                $this->matrix[$quarter->quarter_number][$enablement->category->code] =
                    (bool) $enablement->enabled;
            }
        }
    }

    public function render(): View
    {
        return view('livewire.admin.course-preparation', [
            'institutions' => Institution::query()
                ->orderBy('name')
                ->get(),

            'faculties' => Faculty::query()
                ->with('user')
                ->orderBy('id')
                ->get(),

            'scenarios' => Scenario::query()
                ->orderBy('name')
                ->get(),

            'categories' => $this->course?->scenario
                ?->decisionCategories()
                ->orderBy('sort_order')
                ->get() ?? collect(),
        ]);
    }

    public function saveSetup(
        AdminCourseConfigurationService $configurationService
    ): void {
        $admin = auth()->user()->admin;

        $this->course = $configurationService->saveSetup(
            $admin,
            [
                'name' => $this->name,
                'institution_id' => $this->institution_id,
                'faculty_id' => $this->faculty_id,
                'scenario_id' => $this->scenario_id,
                'starts_on' => $this->starts_on,
                'ends_on' => $this->ends_on,
                'payment_mode' => $this->payment_mode,
                'cf_plus_enabled' => $this->cf_plus_enabled,
                'build_quarters' => $this->build_quarters,
                'adversarial_quarters' => $this->adversarial_quarters,
                'post_bid_quarters' => $this->post_bid_quarters,
                'accounting_election_enabled' => $this->accounting_election_enabled,
            ],
            $this->course,
        );

        $this->step = 2;

        if ($this->quarters === []) {
            $this->quarter_count = max($this->quarter_count, $this->minimumQuarters());
            $this->buildScheduleRows();
        }
    }

    public function buildScheduleRows(): void
    {
        if (! $this->starts_on || ! $this->ends_on || $this->quarter_count < 4) {
            return;
        }

        // R9: the warm-up takes none of the term; Quarter 4 starts on the course start date.
        $this->quarters = ScheduleRows::build($this->starts_on, $this->ends_on, $this->quarter_count);
    }

    /** How many quarters this course needs, warm-up included (the box starts here). */
    public function minimumQuarters(): int
    {
        return ScheduleRows::minimum($this->cf_plus_enabled, $this->build_quarters, $this->adversarial_quarters, $this->post_bid_quarters);
    }

    public function saveSchedule(
        AdminCourseConfigurationService $configurationService
    ): void {
        $admin = auth()->user()->admin;

        $configurationService->saveSchedule(
            $admin,
            $this->course,
            $this->quarters,
        );

        $this->course->refresh();

        $this->initializeMatrix();

        $this->step = 3;
    }

    public function toggleCategory(
        int $quarter,
        string $code,
        bool $enabled
    ): void {
        if ($quarter < 4) {
            $this->addError(
                'matrix',
                'Quarters 1 through 3 are reserved for system-generated warm-up history.'
            );

            return;
        }

        $this->matrix[$quarter][$code] = $enabled;

        if ($code !== 'certification' && $enabled) {
            foreach (array_keys($this->matrix) as $later) {
                if ((int) $later >= $quarter) {
                    $this->matrix[$later][$code] = true;
                }
            }
        }
    }

    public function isLocked(int $quarter, string $code): bool
    {
        if ($code === 'certification') {
            return false;
        }

        foreach ($this->matrix as $earlier => $values) {
            if ((int) $earlier < $quarter && ($values[$code] ?? false)) {
                return true;
            }
        }

        return false;
    }

    public function saveEnablements(
        AdminCourseConfigurationService $configurationService
    ): void {
        $admin = auth()->user()->admin;

        $configurationService->saveEnablements(
            $admin,
            $this->course,
            $this->matrix,
        );

        $this->step = 4;
    }

    public function saveIndustry(): void
    {
        $this->industry_acknowledged = true;

        $this->step = 5;
    }

    public function deploy(AdminCourseConfigurationService $configurationService): void
    {
        $admin = auth()->user()->admin;

        $this->course = $configurationService->deploy(
            $admin,
            $this->course,
        );

        $this->step = 6;
    }

    public function goToStep(int $step): void
    {
        if ($step < 1 || $step > 6) {
            return;
        }

        if ($this->course?->status === Course::STATUS_DEPLOYED) {
            $this->step = 6;

            return;
        }

        if ($step === 1 || $this->course) {
            $this->step = $step;
        }
    }

    private function initializeMatrix(): void
    {
        $categories = $this->course->scenario
            ->decisionCategories()
            ->orderBy('sort_order')
            ->get();

        $studentQuarters = $this->course->quarters()
            ->where('quarter_number', '>=', 4)
            ->orderBy('quarter_number')
            ->get();

        foreach ($studentQuarters as $quarter) {
            foreach ($categories as $category) {
                $this->matrix[$quarter->quarter_number][$category->code] ??=
                    in_array(
                        $category->code,
                        CourseConfigurationService::requiredQuarterFour($this->course),
                        true
                    );
            }
        }
    }
}
