<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;

/**
 * One readable date style on every faculty and student screen
 * (walkthrough items 25 and 43): "19 Sep 2026" and "20 Oct 2026, 11:59 pm EDT".
 *
 * Timestamps are stored in UTC; they are shown, and deadlines are entered,
 * in config('flexee.display_timezone').
 */
final class Dates
{
    public static function zone(): string
    {
        return (string) (config('flexee.display_timezone') ?: 'UTC');
    }

    /** For date-only values (a quarter's start date): no time zone shift. */
    public static function day(?DateTimeInterface $date): string
    {
        return $date ? $date->format('j M Y') : '';
    }

    public static function dateTime(?DateTimeInterface $date): string
    {
        return $date ? CarbonImmutable::instance($date)->setTimezone(self::zone())->format('j M Y, g:i a T') : '';
    }

    /** "3 days 4 hours left", or "passed" when the moment is behind us. */
    public static function remaining(?DateTimeInterface $date): string
    {
        if (! $date) {
            return '';
        }

        $now = now();
        if ($date <= $now) {
            return 'passed';
        }

        return $now->diffForHumans($date, ['syntax' => CarbonInterface::DIFF_ABSOLUTE, 'parts' => 2]).' left';
    }

    /**
     * A deadline typed by faculty, in the display zone, as UTC. A bare date
     * ("2026-10-20") means the end of that day, 23:59.
     */
    public static function deadlineFromInput(string $value): CarbonImmutable
    {
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $value .= ' 23:59:00';
        }

        return CarbonImmutable::parse($value, self::zone())->utc();
    }

    /** The calendar date of a stored deadline as faculty see it, for date inputs. */
    public static function localDate(?DateTimeInterface $date): ?string
    {
        return $date ? CarbonImmutable::instance($date)->setTimezone(self::zone())->toDateString() : null;
    }
}
