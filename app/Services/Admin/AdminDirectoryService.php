<?php

namespace App\Services\Admin;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Faculty;
use App\Models\FirmMembership;
use App\Models\Institution;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * What One Smarter manages in admin (walkthrough items 4 to 7, 45): create
 * and rename institutions, add faculty, enrol or unenrol students, and mark
 * the paid list from Chuck.
 */
class AdminDirectoryService
{
    public function createInstitution(string $name): Institution
    {
        $data = Validator::make(['name' => trim($name)], [
            'name' => ['required', 'string', 'max:255', Rule::unique('institutions', 'name')->whereNull('deleted_at')],
        ])->validate();

        return Institution::query()->create($data);
    }

    public function renameInstitution(Institution $institution, string $name): Institution
    {
        $data = Validator::make(['name' => trim($name)], [
            'name' => ['required', 'string', 'max:255', Rule::unique('institutions', 'name')->ignore($institution->id)->whereNull('deleted_at')],
        ])->validate();
        $institution->forceFill($data)->save();

        return $institution;
    }

    /** @return array{faculty: Faculty, emailed: bool} */
    public function createFaculty(string $name, string $email, string $institutionId): array
    {
        $data = Validator::make(compact('name', 'email') + ['institution_id' => $institutionId], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'institution_id' => ['required', Rule::exists('institutions', 'id')->whereNull('deleted_at')],
        ])->validate();

        $faculty = DB::transaction(function () use ($data): Faculty {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => strtolower($data['email']),
                'password' => Str::password(32),
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            return Faculty::query()->create(['user_id' => $user->id, 'institution_id' => $data['institution_id']]);
        });

        try {
            $emailed = Password::sendResetLink(['email' => $faculty->user->email]) === Password::RESET_LINK_SENT;
        } catch (Throwable) {
            $emailed = false;
        }

        return ['faculty' => $faculty, 'emailed' => $emailed];
    }

    public function moveFaculty(Faculty $faculty, string $institutionId): void
    {
        if ($faculty->courses()->exists()) {
            throw ValidationException::withMessages(['institution_id' => 'This faculty member already has courses at their institution.']);
        }
        Validator::make(['institution_id' => $institutionId], [
            'institution_id' => ['required', Rule::exists('institutions', 'id')->whereNull('deleted_at')],
        ])->validate();
        $faculty->forceFill(['institution_id' => $institutionId])->save();
    }

    public function enroll(Student $student, Course $course): Enrollment
    {
        if ($student->institution_id !== $course->institution_id) {
            throw ValidationException::withMessages(['course' => 'The course belongs to a different institution.']);
        }
        $enrollment = Enrollment::withTrashed()->firstOrNew(['course_id' => $course->id, 'student_id' => $student->id]);
        $enrollment->forceFill(['institution_id' => $course->institution_id, 'deleted_at' => null])->save();

        return $enrollment;
    }

    public function unenroll(Enrollment $enrollment): void
    {
        if (FirmMembership::query()->where('course_id', $enrollment->course_id)->where('student_id', $enrollment->student_id)->exists()) {
            throw ValidationException::withMessages(['enrollment' => 'Remove the student from their firm first (on the faculty Firms page).']);
        }
        $enrollment->delete();
    }

    /**
     * Item 45: the paid list from Chuck. Marks the matching enrolments paid
     * and reports names on the list with no registration, and registered
     * students not on the list.
     *
     * @return array{marked: list<string>, already: list<string>, not_registered: list<string>, not_on_list: list<string>}
     */
    public function markPaid(Course $course, string $list, User $by): array
    {
        preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $list, $matches);
        $emails = collect($matches[0])->map(fn ($e) => strtolower($e))->unique()->values();
        if ($emails->isEmpty()) {
            throw ValidationException::withMessages(['list' => 'Paste the email addresses from the paid list, one per line or separated by commas.']);
        }

        $enrollments = $course->enrollments()->with('student.user')->get()
            ->keyBy(fn ($e) => strtolower((string) $e->student?->user?->email));

        $report = ['marked' => [], 'already' => [], 'not_registered' => [], 'not_on_list' => []];
        foreach ($emails as $email) {
            $enrollment = $enrollments->get($email);
            if (! $enrollment) {
                $report['not_registered'][] = $email;
            } elseif ($enrollment->paid_at) {
                $report['already'][] = $email;
            } else {
                $enrollment->forceFill(['paid_at' => now(), 'paid_marked_by_user_id' => $by->id])->save();
                $report['marked'][] = $email;
            }
        }
        $report['not_on_list'] = $enrollments->keys()->diff($emails)->filter()->values()->all();

        return $report;
    }
}
