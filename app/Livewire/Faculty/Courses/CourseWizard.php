<?php

namespace App\Livewire\Faculty\Courses;

use App\Models\Course;
use App\Models\CourseQuarter;
use App\Models\Faculty;
use App\Models\Institution;
use App\Models\Scenario;
use App\Services\Courses\CourseConfigurationService;
use App\Services\Courses\CourseRemoval;
use App\Services\Courses\ScheduleRows;
use App\Support\Dates;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class CourseWizard extends Component
{
    public ?Course $course = null;

    public int $step = 1;

    public string $name = '';

    public string $institution_id = '';

    public string $scenario_id = '';

    public string $starts_on = '';

    public string $ends_on = '';

    public string $payment_mode = '';

    public bool $cf_plus_enabled = false;

    public bool $accounting_election_enabled = false;

    public int $build_quarters = 1;

    public int $adversarial_quarters = 1;

    public int $post_bid_quarters = 1;

    public int $quarter_count = 4;

    /** @var array<int, array{quarter_number: int, starts_on: string, ends_on: string, decision_deadline_at: string}> */
    public array $quarters = [];

    /** @var array<int, array<string, bool>> */
    public array $matrix = [];

    /** @var array<int, string> */
    public array $co_faculty_ids = [];

    public bool $industry_acknowledged = false;

    /** @var array<string, string> ED9: the methods every firm starts with */
    public array $starting_elections = [];

    public bool $deployed = false;

    /**
     * Each click starts with a clean slate, so a message from an earlier,
     * failed save never stays on screen after the next save works (live review R10).
     */
    public function hydrate(): void
    {
        $this->resetErrorBag();
    }

    public function mount(?Course $course = null): void
    {
        $faculty = $this->faculty();

        if ($course) {
            app(CourseConfigurationService::class)->authorize($faculty, $course);

            $this->course = $course;

            $this->name = $course->name;
            $this->institution_id = $course->institution_id;
            $this->scenario_id = $course->scenario_id;
            $this->payment_mode = $course->payment_mode ?? '';
            $this->cf_plus_enabled = $course->cf_plus_enabled;
            $this->accounting_election_enabled = $course->accounting_election_enabled;
            $this->build_quarters = $course->build_quarters ?? 1;
            $this->adversarial_quarters = $course->adversarial_quarters ?? 1;
            $this->post_bid_quarters = $course->post_bid_quarters ?? 1;
            $this->starts_on = CarbonImmutable::parse($course->starts_on)->toDateString();
            $this->ends_on = CarbonImmutable::parse($course->ends_on)->toDateString();

            $this->loadPersistedConfiguration();
            $this->starting_elections = $course->startingElections();

            /*
             * Deployed courses are review-only.
             * Always land on the final review step.
             */
            if ($course->status === Course::STATUS_DEPLOYED) {
                $this->deployed = true;
                $this->step = 6;
            } else {
                // R11: a draft opens where it was left, at the first step not yet saved.
                $this->step = $this->quarters === [] ? 2 : ($this->matrix === [] ? 3 : 6);
            }
        } else {
            $this->institution_id = $faculty->institution_id;

            $scenario = Scenario::query()
                ->where('code', 'cf_manufacturing')
                ->where('active', true)
                ->first();

            $this->scenario_id = (string) $scenario?->id;
            $this->starting_elections = (new Course)->startingElections();
        }
    }

    public function saveSetup(CourseConfigurationService $service): void
    {
        $this->ensureEditable();
        $wasNew = $this->course === null;

        $this->course = $service->saveSetup($this->faculty(), [
            'name' => $this->name,
            'institution_id' => $this->institution_id,
            'scenario_id' => $this->scenario_id,
            'starts_on' => $this->starts_on,
            'ends_on' => $this->ends_on,
            'payment_mode' => $this->payment_mode ?: null,
            'cf_plus_enabled' => $this->cf_plus_enabled,
            'accounting_election_enabled' => $this->accounting_election_enabled,
            'build_quarters' => $this->build_quarters,
            'adversarial_quarters' => $this->adversarial_quarters,
            'post_bid_quarters' => $this->post_bid_quarters,
        ], $this->course);

        // R11: from the first save on, the address names the course, so a reload keeps it.
        if ($wasNew) {
            $this->js('history.replaceState(history.state, "", '.json_encode(route('faculty.courses.wizard', $this->course)).')');
        }

        $this->step = 2;

        if ($this->quarters === []) {
            $this->quarter_count = max($this->quarter_count, $this->minimumQuarters());
            $this->buildScheduleRows();
        }
    }

    public function buildScheduleRows(): void
    {
        $this->ensureEditable();

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

    public function saveSchedule(CourseConfigurationService $service): void
    {
        $this->ensureEditable();

        $service->saveSchedule(
            $this->faculty(),
            $this->requiredCourse(),
            $this->quarters
        );

        $this->initializeMatrix();
        $this->step = 3;
    }

    public function toggleCategory(int $quarter, string $code, bool $enabled): void
    {
        $this->ensureEditable();

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
                if ($later >= $quarter) {
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
            if ($earlier < $quarter && ($values[$code] ?? false)) {
                return true;
            }
        }

        return false;
    }

    public function saveEnablements(CourseConfigurationService $service): void
    {
        $this->ensureEditable();

        $service->saveEnablements(
            $this->faculty(),
            $this->requiredCourse(),
            $this->matrix
        );

        $this->step = 4;
    }

    public function saveIndustry(CourseConfigurationService $service): void
    {
        $this->ensureEditable();

        $this->course = $service->saveStartingElections($this->faculty(), $this->requiredCourse(), $this->starting_elections);
        $this->starting_elections = $this->course->startingElections();

        $this->industry_acknowledged = true;
        $this->step = 5;
    }

    public function saveFacultyRoles(CourseConfigurationService $service): void
    {
        $this->ensureEditable();

        $service->saveFacultyRoles(
            $this->faculty(),
            $this->requiredCourse(),
            $this->co_faculty_ids
        );

        $this->step = 6;
    }

    public function toggleRegistration(): void
    {
        $course = $this->requiredCourse();

        app(CourseConfigurationService::class)->authorize(
            $this->faculty(),
            $course
        );

        abort_unless(
            $course->status === Course::STATUS_DEPLOYED,
            403
        );

        $course->update([
            'registration_enabled' => ! $course->registration_enabled,
        ]);

        $this->course->refresh();
    }

    public function deploy(CourseConfigurationService $service): void
    {
        if ($this->isDeployed()) {
            return;
        }

        $this->course = $service->deploy(
            $this->faculty(),
            $this->requiredCourse()
        );

        $this->deployed = true;
        $this->step = 6;
    }

    /** R12: the owner deletes this draft, which was never deployed. */
    public function deleteDraft(CourseRemoval $removal): void
    {
        $this->ensureEditable();
        $removal->removeDraft($this->faculty(), $this->requiredCourse());
        $this->redirectRoute('dashboard', navigate: true);
    }

    public function goToStep(int $step): void
    {
        if ($step < 1 || $step > 6) {
            return;
        }

        /*
         * A deployed course is permanently review-only.
         * It may only remain on the final review step.
         */
        if ($this->isDeployed()) {
            $this->step = 6;

            return;
        }

        if ($step === 1 || $this->course) {
            $this->step = $step;
        }
    }

    public function render(): View
    {
        return view('livewire.faculty.courses.course-wizard', [
            'institutions' => Institution::query()
                ->whereKey($this->faculty()->institution_id)
                ->get(),

            'scenarios' => Scenario::query()
                ->where('active', true)
                ->orderBy('name')
                ->get(),

            'categories' => $this->course?->scenario
                ->decisionCategories()
                ->orderBy('sort_order')
                ->get() ?? collect(),

            'facultyChoices' => Faculty::query()
                ->with('user')
                ->where('institution_id', $this->faculty()->institution_id)
                ->whereKeyNot($this->faculty()->id)
                ->get(),
        ]);
    }

    private function initializeMatrix(): void
    {
        $codes = $this->requiredCourse()
            ->scenario
            ->decisionCategories()
            ->pluck('code');

        foreach (
            $this->requiredCourse()
                ->quarters()
                ->where('quarter_number', '>=', 4)
                ->pluck('quarter_number') as $quarter
        ) {
            foreach ($codes as $code) {
                $this->matrix[$quarter][$code] ??=
                    in_array(
                        $code,
                        CourseConfigurationService::requiredQuarterFour($this->requiredCourse()),
                        true
                    );
            }
        }
    }

    private function loadPersistedConfiguration(): void
    {
        $this->quarters = $this->course
            ->quarters()
            ->orderBy('quarter_number')
            ->get()
            ->map(fn (CourseQuarter $q): array => [
                'quarter_number' => $q->quarter_number,
                'starts_on' => CarbonImmutable::parse($q->starts_on)->toDateString(),
                'ends_on' => CarbonImmutable::parse($q->ends_on)->toDateString(),
                'decision_deadline_at' => Dates::localDate($q->decision_deadline_at),
            ])
            ->all();

        $this->quarter_count = max(4, count($this->quarters));

        foreach ($this->course->quarters()->with('enablements.category')->get() as $quarter) {
            foreach ($quarter->enablements as $row) {
                $this->matrix[$quarter->quarter_number][$row->category->code] = $row->enabled;
            }
        }

        $this->co_faculty_ids = $this->course
            ->facultyRoles()
            ->where('role', 'co_faculty')
            ->pluck('faculty_id')
            ->all();
    }

    private function isDeployed(): bool
    {
        return $this->deployed
            || $this->course?->status === Course::STATUS_DEPLOYED;
    }

    private function ensureEditable(): void
    {
        abort_unless(! $this->isDeployed(), 403);
    }

    private function faculty(): Faculty
    {
        abort_unless(
            auth()->check() && auth()->user()->faculty,
            403
        );

        return auth()->user()->faculty;
    }

    private function requiredCourse(): Course
    {
        abort_unless($this->course !== null, 404);

        return $this->course;
    }
}
