<?php

namespace App\Services\Courses;

use Carbon\CarbonImmutable;

/**
 * The rows "Generate rows" fills in, shared by the faculty wizard and the
 * admin's course preparation (live review R9, 21 October 2026).
 *
 * Quarters 1 to 3 are the warm-up. The simulation plays them the moment the
 * course is deployed, so they take none of the term: they're stored on the
 * day before the course starts, and the screens show them as one row with no
 * dates. Quarter 4 starts on the course start date, and Quarters 4 onward
 * share the whole term.
 */
final class ScheduleRows
{
    public const WARMUP_QUARTERS = 3;

    /** How many quarters a course needs, warm-up included. */
    public static function minimum(bool $evaluationStage, ?int $build = null, ?int $evaluation = null, ?int $after = null): int
    {
        return $evaluationStage
            ? max(4, self::WARMUP_QUARTERS + (int) $build + (int) $evaluation + (int) $after)
            : 4;
    }

    /** The date the warm-up quarters are stored on: the day before the course starts. */
    public static function warmupDate(string $courseStart): string
    {
        return CarbonImmutable::parse($courseStart)->subDay()->toDateString();
    }

    /**
     * @return list<array{quarter_number: int, starts_on: string, ends_on: string, decision_deadline_at: string}>
     */
    public static function build(string $courseStart, string $courseEnd, int $count): array
    {
        $count = max(4, $count);
        $start = CarbonImmutable::parse($courseStart);
        $end = CarbonImmutable::parse($courseEnd);
        $warmup = self::warmupDate($courseStart);

        $rows = [];
        for ($number = 1; $number <= self::WARMUP_QUARTERS; $number++) {
            $rows[] = ['quarter_number' => $number, 'starts_on' => $warmup, 'ends_on' => $warmup, 'decision_deadline_at' => $warmup];
        }

        $studentQuarters = $count - self::WARMUP_QUARTERS;
        $days = max(1, (int) $start->diffInDays($end) + 1);
        $length = max(1, intdiv($days, $studentQuarters));

        for ($i = 0; $i < $studentQuarters; $i++) {
            $quarterStart = $start->addDays($i * $length);
            $quarterEnd = $i === $studentQuarters - 1 ? $end : $quarterStart->addDays($length - 1);
            if ($quarterEnd->lt($quarterStart)) {
                $quarterEnd = $quarterStart;
            }
            $rows[] = [
                'quarter_number' => self::WARMUP_QUARTERS + 1 + $i,
                'starts_on' => $quarterStart->toDateString(),
                'ends_on' => $quarterEnd->toDateString(),
                'decision_deadline_at' => $quarterEnd->toDateString(),
            ];
        }

        return $rows;
    }
}
