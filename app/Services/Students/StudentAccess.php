<?php

namespace App\Services\Students;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Student;

/**
 * Decision N3 (Vikram, 2026-10-17): students pay outside Flexee and One
 * Smarter marks them paid. Before that a student can sign in and see the
 * course and firm, but the firm's pages show a plain "access opens once
 * your registration is confirmed" page instead.
 */
final class StudentAccess
{
    public static function required(): bool
    {
        return (bool) config('flexee.require_payment');
    }

    public static function isPaid(Student $student, Course $course): bool
    {
        if (! self::required()) {
            return true;
        }

        return Enrollment::query()
            ->where('course_id', $course->id)
            ->where('student_id', $student->id)
            ->whereNotNull('paid_at')
            ->exists();
    }
}
