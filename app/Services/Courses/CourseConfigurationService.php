<?php

namespace App\Services\Courses;

use App\Events\CourseDeploymentReachedWarmupBoundary;
use App\Models\Course;
use App\Models\CourseDecisionEnablement;
use App\Models\CourseFacultyRole;
use App\Models\CourseQuarter;
use App\Models\Faculty;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CourseConfigurationService
{
    public const REQUIRED_QUARTER_FOUR = ['forecasting', 'sourcing', 'production'];

    /** @param array<string, mixed> $input */
    public function saveSetup(Faculty $actor, array $input, ?Course $course = null): Course
    {
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'institution_id' => ['required', 'uuid', Rule::exists('institutions', 'id')->whereNull('deleted_at')],
            'scenario_id' => ['required', 'uuid', Rule::exists('scenarios', 'id')->where('active', true)->whereNull('deleted_at')],
            'starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'payment_mode' => ['nullable', 'string', 'max:100'], 'cf_plus_enabled' => ['required', 'boolean'], 'accounting_election_enabled' => ['required', 'boolean'],
            'build_quarters' => ['nullable', 'integer', 'min:1'], 'adversarial_quarters' => ['nullable', 'integer', 'min:1'],
            'post_bid_quarters' => ['nullable', 'integer', 'min:1'],
        ])->validate();
        if ($actor->institution_id !== $data['institution_id']) {
            throw new AuthorizationException('Faculty may only create courses for their institution.');
        }
        if ($course) {
            $this->authorize($actor, $course);
        }
        if ($data['cf_plus_enabled'] && collect(['build_quarters', 'adversarial_quarters', 'post_bid_quarters'])->contains(fn ($key) => empty($data[$key]))) {
            throw ValidationException::withMessages(['cf_plus_enabled' => 'All CF Plus phase counts are required.']);
        }
        if (! $data['cf_plus_enabled']) {
            $data['build_quarters'] = $data['adversarial_quarters'] = $data['post_bid_quarters'] = null;
        }
        $course ??= new Course(['faculty_id' => $actor->id, 'status' => Course::STATUS_DRAFT]);
        $course->fill($data)->save();
        CourseFacultyRole::withTrashed()->updateOrCreate(
            ['course_id' => $course->id, 'faculty_id' => $actor->id],
            ['role' => CourseFacultyRole::ROLE_OWNER, 'deleted_at' => null],
        );

        return $course->refresh();
    }

    /** @param array<int, array{quarter_number: int, starts_on: string, ends_on: string, decision_deadline_at: string}> $quarters */
    public function saveSchedule(Faculty $actor, Course $course, array $quarters): void
    {
        $this->authorize($actor, $course);
        Validator::make(['quarters' => $quarters], [
            'quarters' => ['required', 'array', 'min:4'], 'quarters.*.quarter_number' => ['required', 'integer', 'min:1', 'distinct'],
            'quarters.*.starts_on' => ['required', 'date'], 'quarters.*.ends_on' => ['required', 'date'],
            'quarters.*.decision_deadline_at' => ['required', 'date'],
        ])->validate();
        $ordered = collect($quarters)->sortBy('quarter_number')->values();
        foreach ($ordered as $index => $quarter) {
            $start = CarbonImmutable::parse($quarter['starts_on']);
            $end = CarbonImmutable::parse($quarter['ends_on']);
            $deadline = CarbonImmutable::parse($quarter['decision_deadline_at']);
            if ((int) $quarter['quarter_number'] !== $index + 1 || $start->gt($end) || $deadline->lt($start) || $deadline->gt($end)) {
                throw ValidationException::withMessages(['quarters' => 'Quarter sequence and dates must be coherent.']);
            }
            if ($start->lt($course->starts_on) || $end->gt($course->ends_on) || ($index && $start->lte(CarbonImmutable::parse($ordered[$index - 1]['ends_on'])))) {
                throw ValidationException::withMessages(['quarters' => 'Quarters must be within course anchors and must not overlap.']);
            }
        }
        $minimum = 3 + (int) $course->build_quarters + (int) $course->adversarial_quarters + (int) $course->post_bid_quarters;
        if ($course->cf_plus_enabled && $ordered->count() < $minimum) {
            throw ValidationException::withMessages(['quarters' => "CF Plus requires at least {$minimum} quarters."]);
        }
        DB::transaction(function () use ($course, $ordered): void {
            $keep = [];
            foreach ($ordered as $quarter) {
                $row = CourseQuarter::withTrashed()->updateOrCreate(
                    ['course_id' => $course->id, 'quarter_number' => $quarter['quarter_number']],
                    [...$quarter, 'deleted_at' => null],
                );
                $keep[] = $row->id;
            }
            $course->quarters()->whereNotIn('id', $keep)->delete();
        });
    }

    /** @param array<int, array<string, bool>> $matrix */
    public function saveEnablements(Faculty $actor, Course $course, array $matrix): void
    {
        $this->authorize($actor, $course);
        if (collect(array_keys($matrix))->contains(fn (int|string $quarter): bool => (int) $quarter < 4)) {
            throw ValidationException::withMessages(['matrix' => 'Quarters 1 through 3 are reserved for system-generated warm-up history. Student enablement begins at Quarter 4.']);
        }
        $categories = $course->scenario->decisionCategories()->orderBy('sort_order')->get()->keyBy('code');
        $quarters = $course->quarters()->where('quarter_number', '>=', 4)->orderBy('quarter_number')->get();
        if ($categories->count() !== 9 || $quarters->isEmpty()) {
            throw ValidationException::withMessages(['matrix' => 'The scenario categories and student quarters must be configured first.']);
        }
        foreach ($quarters as $quarter) {
            foreach ($categories as $code => $category) {
                if (! array_key_exists($code, $matrix[$quarter->quarter_number] ?? [])) {
                    throw ValidationException::withMessages(['matrix' => "Quarter {$quarter->quarter_number} is missing {$code}."]);
                }
            }
        }
        foreach (self::REQUIRED_QUARTER_FOUR as $code) {
            if (! ($matrix[4][$code] ?? false)) {
                throw ValidationException::withMessages(['matrix' => "Quarter 4 must enable {$code}."]);
            }
        }
        foreach ($categories->keys()->reject(fn ($code) => $code === 'certification') as $code) {
            $opened = false;
            foreach ($quarters as $quarter) {
                $enabled = (bool) $matrix[$quarter->quarter_number][$code];
                if ($opened && ! $enabled) {
                    throw ValidationException::withMessages(['matrix' => "{$code} cannot be disabled after activation."]);
                }
                $opened = $opened || $enabled;
            }
        }
        DB::transaction(function () use ($quarters, $categories, $matrix): void {
            foreach ($quarters as $quarter) {
                foreach ($categories as $code => $category) {
                    CourseDecisionEnablement::withTrashed()->updateOrCreate(
                        ['course_quarter_id' => $quarter->id, 'scenario_decision_category_id' => $category->id],
                        ['enabled' => (bool) $matrix[$quarter->quarter_number][$code], 'deleted_at' => null],
                    );
                }
            }
        });
    }

    /** @param array<int, string> $coFacultyIds */
    public function saveFacultyRoles(Faculty $actor, Course $course, array $coFacultyIds): void
    {
        $this->authorize($actor, $course);
        $ids = collect($coFacultyIds)->filter()->unique()->reject(fn ($id) => $id === $actor->id)->values();
        if (Faculty::query()->where('institution_id', $course->institution_id)->whereIn('id', $ids)->count() !== $ids->count()) {
            throw ValidationException::withMessages(['co_faculty_ids' => 'Every co-faculty member must be active faculty in this institution.']);
        }
        DB::transaction(function () use ($course, $actor, $ids): void {
            CourseFacultyRole::query()->where('course_id', $course->id)->where('role', CourseFacultyRole::ROLE_CO_FACULTY)->whereNotIn('faculty_id', $ids)->delete();
            foreach ($ids as $id) {
                CourseFacultyRole::withTrashed()->updateOrCreate(['course_id' => $course->id, 'faculty_id' => $id], ['role' => CourseFacultyRole::ROLE_CO_FACULTY, 'deleted_at' => null]);
            }
            CourseFacultyRole::withTrashed()->updateOrCreate(['course_id' => $course->id, 'faculty_id' => $actor->id], ['role' => CourseFacultyRole::ROLE_OWNER, 'deleted_at' => null]);
        });
    }

    public function deploy(Faculty $actor, Course $course): Course
    {
        $this->authorize($actor, $course);
        if ($course->status === Course::STATUS_DEPLOYED) {
            return $course;
        }

        $deployed = DB::transaction(function () use ($course): Course {
            $course->refresh()->load(['quarters.enablements.category', 'facultyRoles', 'faculty']);
            if ($course->quarters->where('quarter_number', '<', 4)->flatMap->enablements->isNotEmpty()) {
                throw ValidationException::withMessages(['deploy' => 'Quarters 1 through 3 are reserved for warm-up and cannot contain student decision enablements.']);
            }
            $expected = $course->quarters->where('quarter_number', '>=', 4)->count() * 9;
            if ($course->quarters->count() < 4 || $course->quarters->flatMap->enablements->count() !== $expected || ! $course->facultyRoles->contains('role', CourseFacultyRole::ROLE_OWNER)) {
                throw ValidationException::withMessages(['deploy' => 'Course configuration is incomplete.']);
            }
            $matrix = [];
            foreach ($course->quarters->where('quarter_number', '>=', 4) as $quarter) {
                foreach ($quarter->enablements as $row) {
                    $matrix[$quarter->quarter_number][$row->category->code] = $row->enabled;
                }
            }
            $this->saveEnablements($course->faculty, $course, $matrix);
            $course->forceFill(['status' => Course::STATUS_DEPLOYED, 'deployed_at' => now()])->save();

            return $course->fresh();
        });

        event(CourseDeploymentReachedWarmupBoundary::fromCourse($deployed));

        return $deployed;
    }

    public function authorize(Faculty $actor, Course $course): void
    {
        if ($course->trashed() || $course->faculty_id !== $actor->id || $course->institution_id !== $actor->institution_id) {
            throw new AuthorizationException('This course is outside your faculty scope.');
        }
    }
}
