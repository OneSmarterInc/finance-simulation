<?php

namespace App\Services\Warmup;

use App\Events\CourseDeploymentReachedWarmupBoundary;
use App\Jobs\InitializeFirmWarmup;
use App\Models\Course;
use App\Models\Firm;
use App\Models\FirmEngineResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FirmWarmupInitializationCoordinator
{
    public function courseReachedWarmupBoundary(CourseDeploymentReachedWarmupBoundary $event): void
    {
        if ($event->status !== Course::STATUS_DEPLOYED || $event->studentControlStartsAtQuarter !== 4) {
            throw new RuntimeException('The deployment event does not represent the signed warm-up boundary.');
        }

        $course = Course::query()->findOrFail($event->courseId);
        if ($course->scenario_id !== $event->scenarioId || $course->institution_id !== $event->institutionId || $course->faculty_id !== $event->owningFacultyId) {
            throw new RuntimeException('The deployment event does not match the persisted course boundary.');
        }

        $course->firms()->whereHas('memberships')->each(fn (Firm $firm) => $this->dispatchFirmIfReady($firm));
    }

    public function dispatchFirmIfReady(Firm $firm): bool
    {
        return DB::transaction(function () use ($firm): bool {
            $locked = Firm::query()->lockForUpdate()->findOrFail($firm->id);
            $quarters = $locked->engineResults()->where('provenance', FirmEngineResult::PROVENANCE_WARMUP)
                ->pluck('quarter_number')->sort()->values()->all();

            if ($quarters === [1, 2, 3]) {
                return false;
            }
            if ($quarters !== []) {
                throw new RuntimeException('Partial inherited warm-up history requires manual investigation.');
            }
            if ($locked->course()->where('status', Course::STATUS_DEPLOYED)->doesntExist() || $locked->memberships()->doesntExist()) {
                return false;
            }
            if ($locked->warmup_status === Firm::WARMUP_QUEUED) {
                return false;
            }

            $locked->forceFill([
                'warmup_status' => Firm::WARMUP_QUEUED,
                'initialization_error' => null,
                'initialization_failed_at' => null,
            ])->save();
            InitializeFirmWarmup::dispatch($locked->id)->afterCommit();

            return true;
        });
    }

    public static function courseSeed(Course $course): string
    {
        return hash('sha256', "cf-course-instance-v1|{$course->id}|{$course->scenario_id}");
    }
}
