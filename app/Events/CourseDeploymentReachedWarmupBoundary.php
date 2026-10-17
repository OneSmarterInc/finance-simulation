<?php

namespace App\Events;

use App\Models\Course;
use App\Models\CourseQuarter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

class CourseDeploymentReachedWarmupBoundary
{
    use Dispatchable;

    /**
     * @param  array<int, array{quarter_number: int, starts_on: string, ends_on: string, decision_deadline_at: string}>  $quarters
     */
    public function __construct(
        public readonly string $courseId,
        public readonly string $scenarioId,
        public readonly string $scenarioCode,
        public readonly string $institutionId,
        public readonly string $owningFacultyId,
        public readonly string $status,
        public readonly array $quarters,
        public readonly bool $cfPlusEnabled,
        public readonly ?int $buildQuarters,
        public readonly ?int $adversarialQuarters,
        public readonly ?int $postBidQuarters,
        public readonly int $studentControlStartsAtQuarter = 4,
    ) {}

    public static function fromCourse(Course $course): self
    {
        $course->loadMissing(['scenario', 'quarters']);

        return new self(
            courseId: $course->id,
            scenarioId: $course->scenario_id,
            scenarioCode: $course->scenario->code,
            institutionId: $course->institution_id,
            owningFacultyId: $course->faculty_id,
            status: $course->status,
            quarters: $course->quarters
                ->sortBy('quarter_number')
                ->map(fn (CourseQuarter $quarter): array => [
                    'quarter_number' => $quarter->quarter_number,
                    'starts_on' => CarbonImmutable::parse($quarter->starts_on)->toDateString(),
                    'ends_on' => CarbonImmutable::parse($quarter->ends_on)->toDateString(),
                    'decision_deadline_at' => CarbonImmutable::parse($quarter->decision_deadline_at)->toDateString(),
                ])->values()->all(),
            cfPlusEnabled: $course->cf_plus_enabled,
            buildQuarters: $course->build_quarters,
            adversarialQuarters: $course->adversarial_quarters,
            postBidQuarters: $course->post_bid_quarters,
        );
    }
}
