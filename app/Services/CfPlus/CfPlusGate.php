<?php

namespace App\Services\CfPlus;

use App\Models\Course;
use App\Models\CourseQuarter;
use DomainException;

class CfPlusGate
{
    public function assertEnabled(Course $course): void
    {
        if (! $course->cf_plus_enabled) {
            throw new DomainException('CF Plus evaluation is unavailable for a plain CF course.');
        }
    }

    public function evaluationStartQuarter(Course $course): int
    {
        $this->assertEnabled($course);

        return 4 + (int) $course->build_quarters;
    }

    public function assertEvaluationAvailable(Course $course): void
    {
        $start = $this->evaluationStartQuarter($course);
        $available = $course->quarters()->where('quarter_number', '>=', $start)->where('state', '!=', CourseQuarter::LOCKED)->exists();
        if (! $available) {
            throw new DomainException('The CF Plus evaluation window has not opened.');
        }
    }
}
