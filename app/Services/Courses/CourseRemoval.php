<?php

namespace App\Services\Courses;

use App\Models\Admin;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Faculty;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Removing a course (live review R12, decided by Vikram on 20 October 2026).
 *
 * Faculty can delete a draft they own that was never deployed: it has no
 * firms, students or results, so nothing else is lost. Admins can also remove
 * a deployed course, such as a test course; its enrollments go with it, so it
 * leaves every student's list. Either way the course is soft-deleted, so One
 * Smarter can restore it. Any practice run of it is marked removed too, and
 * the daily practice tidy-up clears it for good.
 */
final class CourseRemoval
{
    public function removeDraft(Faculty $faculty, Course $course): void
    {
        if ($course->is_practice || $course->faculty_id !== $faculty->id) {
            throw new AuthorizationException('Only the course owner can delete it.');
        }
        DB::transaction(function () use ($course): void {
            // Read the status under a lock, so a deploy at the same moment can't slip in between.
            if (Course::query()->whereKey($course->id)->lockForUpdate()->value('status') === Course::STATUS_DEPLOYED) {
                throw ValidationException::withMessages(['course' => 'A course that has been deployed can only be removed by One Smarter.']);
            }
            $this->removePracticeRuns($course);
            $course->delete();
        });
        Log::info('course removed', ['course_id' => $course->id, 'by' => 'faculty', 'faculty_id' => $faculty->id]);
    }

    public function removeByAdmin(Admin $admin, Course $course): void
    {
        if ($course->is_practice) {
            throw new AuthorizationException('A practice run is removed from its own page.');
        }

        DB::transaction(function () use ($course): void {
            $this->removePracticeRuns($course);
            Enrollment::query()->where('course_id', $course->id)->get()->each->delete();
            $course->delete();
        });
        Log::info('course removed', ['course_id' => $course->id, 'by' => 'admin', 'admin_id' => $admin->id]);
    }

    private function removePracticeRuns(Course $course): void
    {
        Course::query()->where('is_practice', true)->where('practice_source_course_id', $course->id)->get()->each->delete();
    }
}
