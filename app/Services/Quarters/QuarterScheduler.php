<?php

namespace App\Services\Quarters;

use App\Jobs\Quarters\CloseFirmQuarter;
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

        // Practice runs (U9) are played on demand, never by the clock.
        Course::query()->real()->where('status', Course::STATUS_DEPLOYED)->each(function (Course $course) use (&$log): void {
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
            // A quarter that opened late, at or near its deadline, is never closed
            // automatically: that would play the course forward with nobody deciding.
            // Faculty set a new deadline instead (QuarterManagementService::extendDeadline).
            if (self::needsNewDeadline($open)) {
                return null;
            }
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
            // A worker that died mid-close leaves the attempt 'processing' forever.
            // Mark it failed so faculty can retry it (no automatic retry of failures).
            $stuck = $processing->closeAttempts()
                ->where('status', FirmQuarterClose::PROCESSING)
                ->where('started_at', '<', now()->subMinutes(15))
                ->update([
                    'status' => FirmQuarterClose::FAILED,
                    'safe_error' => json_encode(['category' => 'system', 'message' => 'The calculation was interrupted. It is safe to retry.']),
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);

            // Pending closes that no worker has picked up: re-send only those, and
            // touch them so they are not re-sent again every minute.
            $stale = $processing->closeAttempts()
                ->where('status', FirmQuarterClose::PENDING)
                ->where('updated_at', '<', now()->subMinutes(10))
                ->get();
            foreach ($stale as $attempt) {
                $attempt->touch();
                CloseFirmQuarter::dispatch($attempt->id);
            }

            if ($stuck || $stale->isNotEmpty()) {
                return "Quarter {$processing->quarter_number}: {$stuck} interrupted, {$stale->count()} re-queued.";
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

    /** The deadline is not comfortably after the moment the quarter opened. */
    public static function needsNewDeadline(CourseQuarter $quarter): bool
    {
        if ($quarter->state !== CourseQuarter::OPEN || ! $quarter->decision_deadline_at || ! $quarter->opened_at) {
            return false;
        }
        if ($quarter->deadline_extended_at && $quarter->deadline_extended_at->gte($quarter->opened_at)) {
            return false;
        }

        return $quarter->decision_deadline_at->lt($quarter->opened_at->addHour());
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
