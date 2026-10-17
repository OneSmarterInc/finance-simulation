<?php

namespace App\Support;

use App\Models\Course;

/**
 * Product naming (decision N1, 2026-10-17).
 *
 * The product is "Flexee Financial Accounting". A course becomes
 * "Flexee Financial Accounting+" when the faculty member adds the
 * evaluation stage at setup. "CF" and "CF Plus" stay inside the code
 * and never appear on a faculty or student screen.
 */
final class Product
{
    public const NAME = 'Flexee Financial Accounting';

    public const PLUS_NAME = 'Flexee Financial Accounting+';

    public const INDUSTRY = 'Manufacturing';

    public const EVALUATION_STAGE = 'Evaluation stage';

    public static function name(?Course $course = null): string
    {
        return $course?->cf_plus_enabled ? self::PLUS_NAME : self::NAME;
    }

    public static function fullName(?Course $course = null): string
    {
        return self::name($course).' · '.self::INDUSTRY;
    }
}
