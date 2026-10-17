<?php

namespace App\Services\Accounting;

use App\Models\Course;
use App\Models\CourseFacultyRole;
use App\Models\CourseQuarter;
use App\Models\Faculty;
use App\Models\Firm;
use App\Models\FirmAccountingElectionRequest;
use App\Models\FirmMembership;
use App\Models\Student;
use App\Services\CfPlus\FirmAccountingElectionService as LegacyFirmAccountingElectionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Accounting-method change requests (walkthrough items 56 to 62, 82, 84;
 * decision N7).
 *
 * A request belongs to the team: one per firm per quarter, filed by any
 * member on the team's behalf, with a written justification. Teammates can
 * withdraw and replace it until faculty decide. An approved change applies
 * from the quarter it was filed in if that quarter is still open, otherwise
 * from the next quarter, and that quarter's statements disclose it.
 */
class FirmAccountingElectionRequestService
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const WITHDRAWN = 'withdrawn';

    public const MIN_JUSTIFICATION = 30;

    public function authorize(Faculty $faculty, Course $course): void
    {
        $assigned = CourseFacultyRole::query()
            ->where('course_id', $course->id)
            ->where('faculty_id', $faculty->id)
            ->exists();

        if (! $assigned || $faculty->institution_id !== $course->institution_id) {
            throw new AuthorizationException('This course is outside your faculty assignment.');
        }
    }

    /** @return Collection<int, FirmAccountingElectionRequest> every request in the course, newest first */
    public function pending(Faculty $faculty, Course $course): Collection
    {
        $this->authorize($faculty, $course);

        return FirmAccountingElectionRequest::query()
            ->where('course_id', $course->id)
            ->where('status', '!=', self::WITHDRAWN)
            ->with(['firm', 'student.user', 'courseQuarter', 'reviewedByFaculty.user'])
            ->latest('created_at')
            ->get();
    }

    /** The team's request for this quarter that still counts (pending or decided), if any. */
    public function teamRequest(Firm $firm, CourseQuarter $quarter): ?FirmAccountingElectionRequest
    {
        return FirmAccountingElectionRequest::query()
            ->where('firm_id', $firm->id)
            ->where('course_quarter_id', $quarter->id)
            ->where('status', '!=', self::WITHDRAWN)
            ->with(['student.user', 'reviewedByFaculty.user'])
            ->latest('created_at')
            ->first();
    }

    /** @param array<string, string> $proposed the five student-visible methods */
    public function submit(Student $student, Firm $firm, CourseQuarter $quarter, array $proposed, string $justification): FirmAccountingElectionRequest
    {
        $this->assertMember($student, $firm);
        $firm->loadMissing('course');

        if (! $firm->course->accounting_election_enabled) {
            throw new AuthorizationException('Accounting-method requests are not used in this course.');
        }
        if ($quarter->course_id !== $firm->course_id || $quarter->state !== CourseQuarter::OPEN) {
            throw ValidationException::withMessages(['election' => 'Requests can be filed only while a quarter is open.']);
        }
        if (mb_strlen(trim($justification)) < self::MIN_JUSTIFICATION) {
            throw ValidationException::withMessages(['justification' => 'Explain why the new method suits your firm better, in at least a sentence or two.']);
        }

        $validated = collect(app(LegacyFirmAccountingElectionService::class)->validate($proposed))->except('inventory_method')->all();
        $current = collect(app(FirmAccountingElectionService::class)->effective($firm, $quarter))->except('inventory_method')->only(array_keys($validated))->all();
        if ($validated === $current) {
            throw ValidationException::withMessages(['election' => 'These are the methods your firm already uses. Change at least one to file a request.']);
        }

        return DB::transaction(function () use ($student, $firm, $quarter, $validated, $justification): FirmAccountingElectionRequest {
            Firm::query()->whereKey($firm->id)->lockForUpdate()->first();
            if ($existing = $this->teamRequest($firm, $quarter)) {
                throw ValidationException::withMessages(['election' => $existing->status === self::PENDING
                    ? 'Your team already has a request waiting for your instructor. Withdraw it first to file a different one.'
                    : 'Your team has already had a request decided this quarter. You can file again next quarter.']);
            }

            return FirmAccountingElectionRequest::query()->create([
                'firm_id' => $firm->id,
                'course_id' => $firm->course_id,
                'course_quarter_id' => $quarter->id,
                'student_id' => $student->id,
                'proposed_elections' => $validated,
                'justification' => trim($justification),
                'status' => self::PENDING,
            ]);
        });
    }

    public function withdraw(Student $student, FirmAccountingElectionRequest $request): void
    {
        $firm = $request->firm()->firstOrFail();
        $this->assertMember($student, $firm);
        $updated = FirmAccountingElectionRequest::query()->whereKey($request->id)->where('status', self::PENDING)
            ->update(['status' => self::WITHDRAWN, 'updated_at' => now()]);
        if ($updated === 0) {
            throw ValidationException::withMessages(['election' => 'Only a request that is still waiting can be withdrawn.']);
        }
    }

    /**
     * The quarter an approval would take effect in: the filing quarter if it
     * is still open, otherwise the next quarter that has not closed.
     */
    public function effectiveQuarter(FirmAccountingElectionRequest $request): ?CourseQuarter
    {
        $filed = $request->courseQuarter()->firstOrFail();
        if ($filed->state === CourseQuarter::OPEN) {
            return $filed;
        }

        return CourseQuarter::query()
            ->where('course_id', $request->course_id)
            ->where('quarter_number', '>', $filed->quarter_number)
            ->where('state', '!=', CourseQuarter::CLOSED)
            ->where('state', '!=', CourseQuarter::PROCESSING)
            ->orderBy('quarter_number')
            ->first();
    }

    public function approve(Faculty $faculty, FirmAccountingElectionRequest $request): FirmAccountingElectionRequest
    {
        $course = $request->course()->firstOrFail();
        $this->authorize($faculty, $course);

        if ($request->status !== self::PENDING) {
            throw ValidationException::withMessages(['request' => 'This request has already been decided.']);
        }

        $target = $this->effectiveQuarter($request);
        if (! $target) {
            throw ValidationException::withMessages(['request' => 'There is no quarter left for this change to take effect in.']);
        }
        $firm = $request->firm()->firstOrFail();

        DB::transaction(function () use ($faculty, $request, $firm, $target): void {
            $pending = FirmAccountingElectionRequest::query()
                ->where('firm_id', $request->firm_id)
                ->where('status', self::PENDING)
                ->lockForUpdate()
                ->get();

            $locked = $pending->firstWhere('id', $request->id);
            if (! $locked) {
                throw ValidationException::withMessages(['request' => 'This request has already been decided.']);
            }

            $elections = app(FirmAccountingElectionService::class);
            $previous = $elections->effective($firm, $target);
            $elections->setEffective($firm, $target, $locked->proposed_elections);

            $locked->forceFill([
                'status' => self::APPROVED,
                'reviewed_by_faculty_id' => $faculty->id,
                'reviewed_at' => now(),
                'previous_elections' => $previous,
                'effective_quarter_number' => $target->quarter_number,
            ])->save();

            // Older pending requests for the firm are superseded by this approval;
            // a newer request (filed in a later quarter) stays for faculty to decide.
            $pending->reject(fn ($p) => $p->id === $locked->id || $p->created_at->gt($locked->created_at))->each(fn ($p) => $p->forceFill([
                'status' => self::REJECTED,
                'reviewed_by_faculty_id' => $faculty->id,
                'reviewed_at' => now(),
                'faculty_note' => 'Superseded by the change approved on '.now()->format('j M Y').'.',
            ])->save());
        });

        return $request->refresh();
    }

    public function reject(Faculty $faculty, FirmAccountingElectionRequest $request, ?string $note = null): FirmAccountingElectionRequest
    {
        $course = $request->course()->firstOrFail();
        $this->authorize($faculty, $course);

        $updated = FirmAccountingElectionRequest::query()->whereKey($request->id)->where('status', self::PENDING)->update([
            'status' => self::REJECTED,
            'reviewed_by_faculty_id' => $faculty->id,
            'reviewed_at' => now(),
            'faculty_note' => filled($note) ? trim($note) : null,
            'updated_at' => now(),
        ]);
        if ($updated === 0) {
            throw ValidationException::withMessages(['request' => 'This request has already been decided.']);
        }

        return $request->refresh();
    }

    private function assertMember(Student $student, Firm $firm): void
    {
        $member = FirmMembership::query()
            ->where('firm_id', $firm->id)
            ->where('student_id', $student->id)
            ->exists();

        if (! $member || $student->institution_id !== $firm->institution_id) {
            throw new AuthorizationException('This firm is outside your membership.');
        }
    }
}
