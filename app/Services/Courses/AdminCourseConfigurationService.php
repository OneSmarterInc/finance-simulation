<?php

namespace App\Services\Courses;

use App\Models\Admin;
use Illuminate\Auth\Access\AuthorizationException;
use App\Models\Course;
use App\Models\CourseFacultyRole;
use App\Models\CourseQuarter;

use App\Models\CourseDecisionEnablement;
use App\Models\ScenarioDecisionCategory;
use App\Models\Faculty;
use App\Events\CourseDeploymentReachedWarmupBoundary;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class AdminCourseConfigurationService
{
    public function authorize(Admin $actor): void
    {
        if ($actor->trashed()) {
            throw new AuthorizationException('This admin account is inactive.');
        }
    }

    public function saveSetup(Admin $actor, array $input, ?Course $course = null): Course
    {
        $this->authorize($actor);

        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'institution_id' => [
                'required',
                'uuid',
                Rule::exists('institutions', 'id')->whereNull('deleted_at'),
            ],
            'faculty_id' => [
                'required',
                'uuid',
                Rule::exists('faculty', 'id')->whereNull('deleted_at'),
            ],
            'scenario_id' => [
                'required',
                'uuid',
                Rule::exists('scenarios', 'id')
                    ->where('active', true)
                    ->whereNull('deleted_at'),
            ],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'payment_mode' => ['nullable', 'string', 'max:100'],
            'cf_plus_enabled' => ['required', 'boolean'],
            'accounting_election_enabled' => ['required', 'boolean'],
            'build_quarters' => ['nullable', 'integer', 'min:1'],
            'adversarial_quarters' => ['nullable', 'integer', 'min:1'],
            'post_bid_quarters' => ['nullable', 'integer', 'min:1'],
        ])->validate();

        if (
            ! \App\Models\Faculty::query()
                ->whereKey($data['faculty_id'])
                ->where('institution_id', $data['institution_id'])
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'faculty_id' => 'The selected Faculty must belong to the selected institution.',
            ]);
        }

        if (
            $data['cf_plus_enabled']
            && collect(['build_quarters', 'adversarial_quarters', 'post_bid_quarters'])
            ->contains(fn($key) => empty($data[$key]))
        ) {
            throw ValidationException::withMessages([
                'cf_plus_enabled' => 'Enter the number of quarters for each part of the evaluation stage.',
            ]);
        }

        if (! $data['cf_plus_enabled']) {
            $data['build_quarters'] = null;
            $data['adversarial_quarters'] = null;
            $data['post_bid_quarters'] = null;
        }

        $course ??= new Course([
            'status' => Course::STATUS_DRAFT,
        ]);

        DB::transaction(function () use ($course, $data): void {
            $course->fill($data)->save();

            CourseFacultyRole::withTrashed()->updateOrCreate(
                [
                    'course_id' => $course->id,
                    'faculty_id' => $course->faculty_id,
                ],
                [
                    'role' => CourseFacultyRole::ROLE_OWNER,
                    'deleted_at' => null,
                ],
            );
        });

        return $course->refresh();
    }

    /** @param array<int, array{quarter_number: int, starts_on: string, ends_on: string, decision_deadline_at: string}> $quarters */
    public function saveSchedule(Admin $actor, Course $course, array $quarters): void
    {
        $this->authorize($actor);

        Validator::make(['quarters' => $quarters], [
            'quarters' => ['required', 'array', 'min:4'],
            'quarters.*.quarter_number' => ['required', 'integer', 'min:1', 'distinct'],
            'quarters.*.starts_on' => ['required', 'date'],
            'quarters.*.ends_on' => ['required', 'date'],
            'quarters.*.decision_deadline_at' => ['required', 'date'],
        ])->validate();

        $ordered = collect($quarters)->sortBy('quarter_number')->values();

        foreach ($ordered as $index => $quarter) {
            $start = \Carbon\CarbonImmutable::parse($quarter['starts_on']);
            $end = \Carbon\CarbonImmutable::parse($quarter['ends_on']);
            $deadline = \Carbon\CarbonImmutable::parse($quarter['decision_deadline_at']);

            if (
                (int) $quarter['quarter_number'] !== $index + 1
                || $start->gt($end)
                || $deadline->lt($start)
                || $deadline->gt($end)
            ) {
                throw ValidationException::withMessages([
                    'quarters' => 'Quarter sequence and dates must be coherent.',
                ]);
            }

            if (
                $start->lt($course->starts_on)
                || $end->gt($course->ends_on)
                || ($index && $start->lte(
                    \Carbon\CarbonImmutable::parse($ordered[$index - 1]['ends_on'])
                ))
            ) {
                throw ValidationException::withMessages([
                    'quarters' => 'Quarters must be within course anchors and must not overlap.',
                ]);
            }
        }

        $minimum = 3
            + (int) $course->build_quarters
            + (int) $course->adversarial_quarters
            + (int) $course->post_bid_quarters;

        if ($course->cf_plus_enabled && $ordered->count() < $minimum) {
            throw ValidationException::withMessages([
                'quarters' => "With the evaluation stage added, this course needs at least {$minimum} quarters.",
            ]);
        }

        DB::transaction(function () use ($course, $ordered): void {
            $keep = [];

            foreach ($ordered as $quarter) {
                $row = CourseQuarter::withTrashed()->updateOrCreate(
                    [
                        'course_id' => $course->id,
                        'quarter_number' => $quarter['quarter_number'],
                    ],
                    [
                        ...$quarter,
                        'deleted_at' => null,
                    ],
                );

                $keep[] = $row->id;
            }

            $course->quarters()->whereNotIn('id', $keep)->delete();
        });
    }

    /** @param array<int, array<string, bool>> $matrix */
    public function saveEnablements(Admin $actor, Course $course, array $matrix): void
    {
        $this->authorize($actor);

        if (collect(array_keys($matrix))->contains(
            fn(int|string $quarter): bool => (int) $quarter < 4
        )) {
            throw ValidationException::withMessages([
                'matrix' => 'Quarters 1 through 3 are reserved for system-generated warm-up history. Student enablement begins at Quarter 4.',
            ]);
        }

        $categories = $course->scenario
            ->decisionCategories()
            ->orderBy('sort_order')
            ->get()
            ->keyBy('code');

        $quarters = $course->quarters()
            ->where('quarter_number', '>=', 4)
            ->orderBy('quarter_number')
            ->get();

        if ($categories->count() !== 9 || $quarters->isEmpty()) {
            throw ValidationException::withMessages([
                'matrix' => 'The scenario categories and student quarters must be configured first.',
            ]);
        }

        foreach ($quarters as $quarter) {
            foreach ($categories as $code => $category) {
                if (! array_key_exists(
                    $code,
                    $matrix[$quarter->quarter_number] ?? []
                )) {
                    throw ValidationException::withMessages([
                        'matrix' => "Quarter {$quarter->quarter_number} is missing {$code}.",
                    ]);
                }
            }
        }

        foreach (CourseConfigurationService::REQUIRED_QUARTER_FOUR as $code) {
            if (! ($matrix[4][$code] ?? false)) {
                throw ValidationException::withMessages([
                    'matrix' => "Quarter 4 must enable {$code}.",
                ]);
            }
        }

        foreach (
            $categories->keys()->reject(fn($code) => $code === 'certification')
            as $code
        ) {
            $opened = false;

            foreach ($quarters as $quarter) {
                $enabled = (bool) $matrix[$quarter->quarter_number][$code];

                if ($opened && ! $enabled) {
                    throw ValidationException::withMessages([
                        'matrix' => "{$code} cannot be disabled after activation.",
                    ]);
                }

                $opened = $opened || $enabled;
            }
        }

        DB::transaction(function () use ($quarters, $categories, $matrix): void {
            foreach ($quarters as $quarter) {
                foreach ($categories as $code => $category) {
                    CourseDecisionEnablement::withTrashed()->updateOrCreate(
                        [
                            'course_quarter_id' => $quarter->id,
                            'scenario_decision_category_id' => $category->id,
                        ],
                        [
                            'enabled' => (bool) $matrix[$quarter->quarter_number][$code],
                            'deleted_at' => null,
                        ],
                    );
                }
            }
        });
    }

    public function deploy(Admin $actor, Course $course): Course
    {
        $this->authorize($actor);

        if ($course->status === Course::STATUS_DEPLOYED) {
            return $course;
        }

        $deployed = DB::transaction(function () use ($actor, $course): Course {
            $course->refresh()->load([
                'quarters.enablements.category',
                'facultyRoles',
                'faculty',
            ]);

            if (! $course->faculty) {
                throw ValidationException::withMessages([
                    'deploy' => 'An operational Faculty must be assigned before hand-over.',
                ]);
            }

            if (
                $course->quarters
                ->where('quarter_number', '<', 4)
                ->flatMap
                ->enablements
                ->isNotEmpty()
            ) {
                throw ValidationException::withMessages([
                    'deploy' => 'Quarters 1 through 3 are reserved for warm-up and cannot contain student decision enablements.',
                ]);
            }

            $studentQuarters = $course->quarters
                ->where('quarter_number', '>=', 4);

            $expected = $studentQuarters->count() * 9;

            if (
                $course->quarters->count() < 4
                || $studentQuarters->isEmpty()
                || $course->quarters->flatMap->enablements->count() !== $expected
                || ! $course->facultyRoles->contains(
                    fn($role) =>
                    $role->faculty_id === $course->faculty_id
                        && $role->role === CourseFacultyRole::ROLE_OWNER
                )
            ) {
                throw ValidationException::withMessages([
                    'deploy' => 'Course configuration is incomplete.',
                ]);
            }

            $matrix = [];

            foreach ($studentQuarters as $quarter) {
                foreach ($quarter->enablements as $row) {
                    $matrix[$quarter->quarter_number][$row->category->code] = $row->enabled;
                }
            }

            $this->saveEnablements($actor, $course, $matrix);

            $course->forceFill([
                'status' => Course::STATUS_DEPLOYED,
                'deployed_at' => now(),
                'registration_token' => $course->registration_token ?: Str::random(64),
            ])->save();

            return $course->fresh();
        });

        event(CourseDeploymentReachedWarmupBoundary::fromCourse($deployed));

        return $deployed;
    }
}
