<?php

namespace App\Services\Quarters;

use App\Jobs\Quarters\CloseCourseQuarter;
use App\Models\Course;
use App\Models\CourseQuarter;
use App\Models\FirmQuarterClose;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Runs every minute (routes/console.php, walkthrough item 46).
 *
 * For each deployed course: close an open quarter whose deadline has
 * passed; re-queue a close that has sat pending; open the next quarter once
 * the previous one has closed; open Quarter 4 on its start date when every
 * firm's warm-up is ready. Failed firm closes are left for faculty to retry
 * and hold the next quarter until they succeed.
 */
class QuarterScheduler
{
    public function __construct(private readonly QuarterManagementService $quarters) {}

    /** @return list<string> what happened, for the command output */
    public function tick(): array
    {
        $log = [];

        Course::query()->where('status', Course::STATUS_DEPLOYED)->each(function (Course $course) use (&$log): void {
            try {
                if ($line = $this->tickCourse($course)) {
                    $log[] = $course->name.': '.$line;
                }
            } catch (Throwable $e) {
                Log::warning('quarter_scheduler_failed', ['course_id' => $course->id, 'error' => $e->getMessage()]);
                $log[] = $course->name.': skipped ('.class_basename($e).')';
            }
        });

        return $log;
    }

    public function tickCourse(Course $course): ?string
    {
        $quarters = $course->quarters()->where('quarter_number', '>=', 4)->orderBy('quarter_number')->get();

        $open = $quarters->firstWhere('state', CourseQuarter::OPEN);
        if ($open) {
            if ($open->decision_deadline_at && $open->decision_deadline_at->lte(now())) {
                $this->quarters->lockAndQueue($open, carryForward: true);

                return "Quarter {$open->quarter_number} closed at its deadline; results are being calculated.";
            }

            return null;
        }

        $processing = $quarters->firstWhere('state', CourseQuarter::PROCESSING);
        if ($processing) {
            $this->quarters->finalize($processing);
            if ($processing->fresh()->state === CourseQuarter::CLOSED) {
                return $this->openNext($course, $processing->quarter_number + 1) ?? "Quarter {$processing->quarter_number} closed.";
            }
            $stale = $processing->closeAttempts()
                ->where('status', FirmQuarterClose::PENDING)
                ->where('updated_at', '<', now()->subMinutes(10))
                ->exists();
            if ($stale) {
                CloseCourseQuarter::dispatch($processing->id);

                return "Quarter {$processing->quarter_number}: re-queued pending closes.";
            }

            return null;
        }

        $lastClosed = $quarters->where('state', CourseQuarter::CLOSED)->last();
        if ($lastClosed) {
            return $this->openNext($course, $lastClosed->quarter_number + 1);
        }

        $q4 = $quarters->firstWhere('quarter_number', 4);
        if ($q4 && $q4->state === CourseQuarter::LOCKED && $q4->starts_on && $q4->starts_on->startOfDay()->lte(now())) {
            try {
                $this->quarters->openQuarter($q4);

                return 'Quarter 4 opened on its start date.';
            } catch (ValidationException) {
                return null; // warm-up not ready yet; try again next minute
            }
        }

        return null;
    }

    private function openNext(Course $course, int $number): ?string
    {
        $next = $course->quarters()->where('quarter_number', $number)->first();
        if (! $next || $next->state !== CourseQuarter::LOCKED) {
            return null;
        }
        $this->quarters->openQuarter($next);

        return "Quarter {$number} opened.";
    }
}
