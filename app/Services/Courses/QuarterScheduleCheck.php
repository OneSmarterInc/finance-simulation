<?php

namespace App\Services\Courses;

use App\Models\Course;
use App\Support\Dates;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The checks a course schedule must pass, shared by the faculty wizard and the
 * admin's course preparation (live review R10, 20 October 2026).
 *
 * Every comparison is between calendar dates, never instants. A quarter's start
 * and end are dates; its deadline is a moment, stored in UTC, whose date is
 * taken in the display time zone, the zone faculty typed it in. Comparing a
 * New York deadline with a UTC midnight made a deadline on the quarter's last
 * day look "after" the quarter, so no schedule could be saved.
 *
 * Each failure names the quarter and the dates, so faculty can see what to change.
 */
final class QuarterScheduleCheck
{
    /**
     * @param  Collection<int, array<string, mixed>>  $ordered  quarters sorted by number, each deadline already in UTC
     */
    public static function assert(Course $course, Collection $ordered): void
    {
        $problem = self::problem($course, $ordered);
        if ($problem !== null) {
            throw ValidationException::withMessages(['quarters' => $problem]);
        }
    }

    /** @param  Collection<int, array<string, mixed>>  $ordered */
    public static function problem(Course $course, Collection $ordered): ?string
    {
        $courseStart = CarbonImmutable::parse($course->starts_on)->toDateString();
        $courseEnd = CarbonImmutable::parse($course->ends_on)->toDateString();
        $previousEnd = null;

        foreach ($ordered->values() as $index => $quarter) {
            $number = (int) $quarter['quarter_number'];
            if ($number !== $index + 1) {
                return 'Quarters must be numbered 1, 2, 3 and so on, with none missing. Quarter '.($index + 1).' is missing.';
            }

            $start = CarbonImmutable::parse((string) $quarter['starts_on'])->toDateString();
            $end = CarbonImmutable::parse((string) $quarter['ends_on'])->toDateString();
            $deadline = CarbonImmutable::parse((string) $quarter['decision_deadline_at'], 'UTC')->setTimezone(Dates::zone())->toDateString();

            if ($start > $end) {
                return "Quarter {$number} ends (".self::day($end).') before it starts ('.self::day($start).').';
            }
            if ($deadline < $start) {
                return "Quarter {$number}'s deadline (".self::day($deadline).') is before the quarter starts ('.self::day($start).').';
            }
            if ($deadline > $end) {
                return "Quarter {$number}'s deadline (".self::day($deadline).') is after the quarter ends ('.self::day($end).').';
            }
            // The warm-up is played when the course is deployed, so its quarters
            // may sit before the course starts, all on one day (R9, ScheduleRows).
            $warmup = $number <= ScheduleRows::WARMUP_QUARTERS;
            if (! $warmup && $start < $courseStart) {
                return "Quarter {$number} starts (".self::day($start).') before the course starts ('.self::day($courseStart).').';
            }
            if ($end > $courseEnd) {
                return "Quarter {$number} ends (".self::day($end).') after the course ends ('.self::day($courseEnd).').';
            }
            // Warm-up quarters stored on one day (start = end) may share it with the one before.
            $sharedWarmupDay = $warmup && $start === $end && $start === $previousEnd;
            if (! $sharedWarmupDay && $previousEnd !== null && $start <= $previousEnd) {
                return "Quarter {$number} starts (".self::day($start).') before Quarter '.($number - 1).' ends ('.self::day($previousEnd).').';
            }
            $previousEnd = $end;
        }

        return null;
    }

    private static function day(string $date): string
    {
        return CarbonImmutable::parse($date)->format('j M Y');
    }
}
