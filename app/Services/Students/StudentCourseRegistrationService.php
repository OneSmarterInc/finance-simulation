<?php

namespace App\Services\Students;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\User;
use App\Support\Dates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class StudentCourseRegistrationService
{
    /**
     * Register a student for a course.
     *
     * Rules:
     * - New email -> create user + student + enrollment.
     * - Existing student -> verify password and enroll.
     * - Existing admin -> reject.
     * - Existing faculty -> reject.
     * - Existing non-student account -> reject.
     * - Existing student from another institution -> reject.
     * - Existing enrollment -> remain idempotent.
     *
     * @return array{
     *     user: User,
     *     student: Student,
     *     enrollment: Enrollment,
     *     created_account: bool,
     *     already_enrolled: bool
     * }
     */
    public function register(
        Course $course,
        string $name,
        string $email,
        string $password
    ): array {
        return DB::transaction(function () use (
            $course,
            $name,
            $email,
            $password
        ): array {
            /*
             * Lock the course so its registration state cannot change
             * while this registration transaction is being processed.
             */
            $course = Course::query()->real()
                ->lockForUpdate()
                ->findOrFail($course->id);

            $this->assertRegistrationOpen($course);

            $email = strtolower(trim($email));
            $name = trim($name);

            /*
             * Find the account by email.
             *
             * The users.email column is unique, but using a
             * case-insensitive lookup makes the registration behaviour
             * consistent with the normalized email we store.
             */
            $user = User::query()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->lockForUpdate()
                ->first();

            $createdAccount = false;

            if ($user) {

                /*
                 * Existing administrator accounts can never be converted
                 * or enrolled as students.
                 */
                if ($user->admin !== null) {
                    throw ValidationException::withMessages([
                        'email' => 'This email address is already registered as an administrator account and cannot be enrolled as a student.',
                    ]);
                }

                /*
                 * Existing faculty accounts can never be converted
                 * or enrolled as students.
                 */
                if ($user->faculty !== null) {
                    throw ValidationException::withMessages([
                        'email' => 'This email address is already registered as a faculty account and cannot be enrolled as a student.',
                    ]);
                }

                /*
                 * The existing account must belong to a student.
                 */
                $student = Student::query()
                    ->where('user_id', $user->id)
                    ->first();

                if (! $student) {
                    throw ValidationException::withMessages([
                        'email' => 'An account already exists with this email address and cannot be registered as a student.',
                    ]);
                }

                /*
                 * A student can only participate in courses belonging
                 * to the same institution.
                 */
                if ($student->institution_id !== $course->institution_id) {
                    throw ValidationException::withMessages([
                        'email' => 'This student account belongs to another institution and cannot be enrolled in this course.',
                    ]);
                }

                /*
                 * Existing student accounts must authenticate using
                 * their existing password.
                 *
                 * We deliberately do NOT replace the existing password
                 * with the password submitted on this registration form.
                 */
                if (! Hash::check($password, $user->password)) {
                    throw ValidationException::withMessages([
                        'password' => 'The password is incorrect for this existing student account.',
                    ]);
                }
            } else {

                /*
                * No existing account -> create a new user.
                */
                $user = User::query()->create([
                    'name' => $name,
                    'email' => $email,
                    'password' => Hash::make($password),
                ]);

                /*
                * Create the student profile using the course's
                * institution.
                */
                $student = Student::query()->create([
                    'user_id' => $user->id,
                    'institution_id' => $course->institution_id,
                ]);

                /*
                * Mark that this was a newly created account.
                */
                $createdAccount = true;

                /*
                * Send the verification email after the database
                * transaction has successfully committed.
                */
                DB::afterCommit(function () use ($user): void {
                    $user->sendEmailVerificationNotification();
                });
            }

            /*
             * Check whether the student is already enrolled.
             *
             * withTrashed() is important because a previously deleted
             * enrollment can be restored instead of creating a duplicate.
             */
            $existingEnrollment = Enrollment::withTrashed()
                ->where('course_id', $course->id)
                ->where('student_id', $student->id)
                ->lockForUpdate()
                ->first();

            $alreadyEnrolled = false;

            if ($existingEnrollment) {

                if ($existingEnrollment->trashed()) {
                    $existingEnrollment->forceFill([
                        'institution_id' => $course->institution_id,
                        'deleted_at' => null,
                    ])->save();
                }

                $enrollment = $existingEnrollment->refresh();
                $alreadyEnrolled = true;
            } else {

                /*
                 * New enrollment.
                 */
                $enrollment = Enrollment::query()->create([
                    'course_id' => $course->id,
                    'student_id' => $student->id,
                    'institution_id' => $course->institution_id,
                ]);
            }

            return [
                'user' => $user->fresh(),
                'student' => $student->fresh(),
                'enrollment' => $enrollment,
                'created_account' => $createdAccount,
                'already_enrolled' => $alreadyEnrolled,
            ];
        });
    }

    /**
     * Ensure the course is currently accepting registrations.
     */
    private function assertRegistrationOpen(Course $course): void
    {
        if ($course->status !== Course::STATUS_DEPLOYED) {
            throw ValidationException::withMessages([
                'registration' => 'This course is not currently available for registration.',
            ]);
        }

        if (! $course->registration_enabled) {
            throw ValidationException::withMessages([
                'registration' => 'Registration for this course is currently closed.',
            ]);
        }

        // Registration opens once the course is deployed and registration is turned on, before the
        // course starts: the warm-up is played at deploy and Quarter 4 opens on the start date (R9).

        if (
            $course->ends_on &&
            Dates::today() > $course->ends_on->toDateString()
        ) {
            throw ValidationException::withMessages([
                'registration' => 'Registration for this course has closed.',
            ]);
        }
    }
}
