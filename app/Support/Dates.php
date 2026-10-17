<?php

namespace App\Support;

use DateTimeInterface;

/**
 * One readable date style on every faculty and student screen
 * (walkthrough items 25 and 43): "19 Sep 2026" and "20 Oct 2026, 11:59 pm".
 */
final class Dates
{
    public static function day(?DateTimeInterface $date): string
    {
        return $date ? $date->format('j M Y') : '';
    }

    public static function dateTime(?DateTimeInterface $date): string
    {
        return $date ? $date->format('j M Y, g:i a') : '';
    }

    /** "in 3 days", "in 5 hours", or "passed" when the moment is behind us. */
    public static function remaining(?DateTimeInterface $date): string
    {
        if (! $date) {
            return '';
        }

        $now = now();
        if ($date <= $now) {
            return 'passed';
        }

        return $now->diffForHumans($date, ['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE, 'parts' => 2]).' left';
    }
}
