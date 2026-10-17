<?php

namespace App\Services\Firms;

use App\Models\CfPlusAuctionEconomicsSnapshot;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Faculty;
use App\Models\Firm;
use App\Models\FirmMembership;
use App\Models\Student;
use App\Services\Courses\CourseConfigurationService;
use App\Services\Warmup\FirmWarmupInitializationCoordinator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class FirmAssignmentService
{
    public function __construct(
        private readonly CourseConfigurationService $courses,
        private readonly FirmWarmupInitializationCoordinator $warmups,
    ) {}

    public function createFirm(Faculty $actor, Course $course, string $code, string $name): Firm
    {
        $this->courses->authorize($actor, $course);
        $this->assertAuctionUniverseMutable($course);
        $data = Validator::make(compact('code', 'name'), [
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
            'name' => ['required', 'string', 'max:255'],
        ])->validate();

        return $course->firms()->create($data + [
            'institution_id' => $course->institution_id,
            'warmup_status' => Firm::WARMUP_PENDING,
        ]);
    }

    public function enroll(Faculty $actor, Course $course, Student $student): Enrollment
    {
        $this->courses->authorize($actor, $course);
        $this->assertSameInstitution($course, $student);

        $enrollment = Enrollment::withTrashed()->firstOrNew([
            'course_id' => $course->id,
            'student_id' => $student->id,
        ]);
        if (! $enrollment->exists || $enrollment->trashed()) {
            $enrollment->forceFill([
                'institution_id' => $course->institution_id,
                'deleted_at' => null,
            ])->save();
        }

        return $enrollment->refresh();
    }

    public function importDemoStudents(Faculty $actor, Course $course): int
    {
        $this->courses->authorize($actor, $course);
        $this->assertAuctionUniverseMutable($course);

        $demoEmails = [
            'student@example.com',
            'student2@example.com',
            'student3@example.com',
            'student4@example.com',
            'student5@example.com',
            'student6@example.com',
            'student7@example.com',
            'student8@example.com',
            'student9@example.com',
            'student10@example.com',
            'student11@example.com',
            'student12@example.com',
            'student13@example.com',
            'student14@example.com',
            'student15@example.com',
        ];

        $firms = $course->firms()
            ->orderBy('code')
            ->get();

        if ($firms->isEmpty()) {
            throw ValidationException::withMessages([
                'demo' => 'Please create at least one firm before importing demo students.',
            ]);
        }

        /*
     * Find demo students belonging to the same institution
     * as the course.
     */
        $students = Student::query()
            ->with('user')
            ->where('institution_id', $course->institution_id)
            ->whereHas('user', function ($query) use ($demoEmails) {
                $query->whereIn('email', $demoEmails);
            })
            ->whereDoesntHave('enrollments', function ($query) use ($course) {
                $query->where('course_id', $course->id);
            })
            ->orderBy('id')
            ->get();

        if ($students->isEmpty()) {
            throw ValidationException::withMessages([
                'demo' => 'All demo students are already registered for this course.',
            ]);
        }

        $imported = 0;

        DB::transaction(function () use (
            $actor,
            $course,
            $firms,
            $students,
            &$imported
        ) {
            foreach ($firms as $firm) {
                /*
             * Maximum 2 students per firm.
             */
                $existingCount = FirmMembership::query()
                    ->where('course_id', $course->id)
                    ->where('firm_id', $firm->id)
                    ->count();

                $availableSlots = max(0, 2 - $existingCount);

                if ($availableSlots === 0) {
                    continue;
                }

                for ($i = 0; $i < $availableSlots; $i++) {
                    $student = $students->shift();

                    if (! $student) {
                        return;
                    }

                    $this->enroll(
                        $actor,
                        $course,
                        $student
                    );

                    $this->assign(
                        $actor,
                        $course,
                        $firm,
                        $student
                    );

                    $imported++;
                }
            }
        });

        if ($imported === 0) {
            throw ValidationException::withMessages([
                'demo' => 'All firms already have two students assigned.',
            ]);
        }

        return $imported;
    }

    public function assign(Faculty $actor, Course $course, Firm $firm, Student $student): FirmMembership
    {
        $this->courses->authorize($actor, $course);
        $this->assertFirmScope($course, $firm);
        $snapshot = CfPlusAuctionEconomicsSnapshot::query()->where('course_id', $course->id)->first();
        if ($snapshot && ! in_array($firm->id, $snapshot->firm_ids, true)) {
            throw ValidationException::withMessages(['phase9' => 'Firm membership is fixed once the evaluation stage opens.']);
        }
        $this->assertSameInstitution($course, $student);
        if (Enrollment::query()->whereBelongsTo($course)->whereBelongsTo($student)->doesntExist()) {
            throw ValidationException::withMessages(['student' => 'The student must be enrolled before firm assignment.']);
        }

        $membership = DB::transaction(function () use ($actor, $course, $firm, $student): FirmMembership {
            $existing = FirmMembership::withTrashed()->where('course_id', $course->id)->where('student_id', $student->id)->lockForUpdate()->first();
            if ($existing && ! $existing->trashed() && $existing->firm_id !== $firm->id && CfPlusAuctionEconomicsSnapshot::query()->where('course_id', $course->id)->exists()) {
                throw ValidationException::withMessages(['phase9' => 'Students cannot move between firms once the evaluation stage opens.']);
            }
            if ($existing && $existing->firm_id !== $firm->id && $existing->firm->engineResults()->exists()) {
                throw ValidationException::withMessages(['student' => 'A student cannot be reassigned after inherited history exists.']);
            }

            $membership = $existing ?? new FirmMembership;
            $membership->forceFill([
                'firm_id' => $firm->id,
                'course_id' => $course->id,
                'student_id' => $student->id,
                'institution_id' => $course->institution_id,
                'assigned_by_faculty_id' => $actor->id,
                'assigned_at' => now(),
                'deleted_at' => null,
            ])->save();

            return $membership->refresh();
        });

        if (! $snapshot) {
            $this->warmups->dispatchFirmIfReady($firm);
        }

        return $membership;
    }

    public function unassign(Faculty $actor, Course $course, Student $student): void
    {
        $this->courses->authorize($actor, $course);
        $membership = FirmMembership::query()
            ->where('course_id', $course->id)
            ->where('student_id', $student->id)
            ->firstOrFail();

        if (CfPlusAuctionEconomicsSnapshot::query()->where('course_id', $course->id)->exists()) {
            throw ValidationException::withMessages(['phase9' => 'Firm membership cannot be changed once the evaluation stage opens.']);
        }

        $membership->delete();
    }

    private function assertAuctionUniverseMutable(Course $course): void
    {
        if (CfPlusAuctionEconomicsSnapshot::query()->where('course_id', $course->id)->exists()) {
            throw ValidationException::withMessages(['phase9' => 'Firms and student assignments are locked once the evaluation stage opens.']);
        }
    }

    private function assertSameInstitution(Course $course, Student $student): void
    {
        if ($student->institution_id !== $course->institution_id) {
            throw ValidationException::withMessages(['student' => 'The student is outside the course institution.']);
        }
    }

    private function assertFirmScope(Course $course, Firm $firm): void
    {
        if ($firm->course_id !== $course->id || $firm->institution_id !== $course->institution_id) {
            throw ValidationException::withMessages(['firm' => 'The firm is outside the course scope.']);
        }
    }
}
