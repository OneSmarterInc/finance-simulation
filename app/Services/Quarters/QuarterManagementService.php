<?php

namespace App\Services\Quarters;

use App\Jobs\Quarters\CloseCourseQuarter;
use App\Models\Course;
use App\Models\CourseFacultyRole;
use App\Models\CourseQuarter;
use App\Models\Faculty;
use App\Models\Firm;
use App\Models\FirmQuarterClose;
use App\Models\FirmQuarterDecision;
use App\Services\CfPlus\CfPlusAuctionEconomicsSnapshotService;
use App\Services\CfPlus\CfPlusGate;
use App\Services\Decisions\FirmDecisionService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Opening and closing student quarters.
 *
 * Walkthrough design (Vikram, 2026-10-17): a quarter stays open until its
 * deadline, even if every firm has submitted. At the deadline the system
 * locks decisions, closes every firm and opens the next quarter (item 46);
 * see QuarterScheduler. A firm with no submission keeps its previous
 * quarter's decisions (N4). Faculty can extend the open quarter's deadline
 * and retry a firm whose close failed; there is no early close (N5).
 */
class QuarterManagementService
{
    public function __construct(
        private readonly CfPlusAuctionEconomicsSnapshotService $auctionSnapshots,
        private readonly CfPlusGate $cfPlusGate,
    ) {}

    public function authorize(Faculty $faculty, Course $course): void
    {
        $assigned = CourseFacultyRole::query()->where('course_id', $course->id)->where('faculty_id', $faculty->id)->exists();
        if (! $assigned || $faculty->institution_id !== $course->institution_id) {
            throw new AuthorizationException('This course is outside your faculty assignment.');
        }
    }

    /** Faculty-initiated open (kept for scripted runs and tests; the scheduler opens quarters in normal use). */
    public function open(Faculty $faculty, CourseQuarter $quarter): void
    {
        $quarter->loadMissing('course.firms');
        $this->authorize($faculty, $quarter->course);
        $this->openQuarter($quarter);
    }

    public function openQuarter(CourseQuarter $quarter): void
    {
        $quarter->loadMissing('course.firms');
        if ($quarter->quarter_number < 4 || $quarter->state !== CourseQuarter::LOCKED) {
            throw ValidationException::withMessages(['quarter' => 'Only the next quarter that has not opened yet can be opened.']);
        }
        if ($quarter->course->quarters()->where(
            fn ($query) => $query->where('state', CourseQuarter::OPEN)->orWhere('state', CourseQuarter::PROCESSING),
        )->exists()) {
            throw ValidationException::withMessages(['quarter' => 'Only one student quarter may be active.']);
        }
        $priorStudent = $quarter->course->quarters()->where('quarter_number', $quarter->quarter_number - 1)->first();
        if ($quarter->quarter_number === 4) {
            if ($quarter->course->firms()->doesntExist()
                || $quarter->course->firms()->where('warmup_status', '!=', 'initialized')->exists()
                || $quarter->course->firms()->whereDoesntHave('engineResults', fn ($q) => $q->where('quarter_number', 3))->exists()) {
                throw ValidationException::withMessages(['quarter' => 'Every firm needs its warm-up Quarters 1 to 3 before Quarter 4 opens.']);
            }
        } elseif (! $priorStudent || $priorStudent->state !== CourseQuarter::CLOSED) {
            throw ValidationException::withMessages(['quarter' => 'The prior quarter must be closed before the next quarter opens.']);
        }
        DB::transaction(function () use ($quarter): void {
            $quarter->forceFill(['state' => CourseQuarter::OPEN, 'opened_at' => now()])->save();
            if ($quarter->course->cf_plus_enabled && $quarter->quarter_number === $this->cfPlusGate->evaluationStartQuarter($quarter->course)) {
                $this->auctionSnapshots->createAtEvaluationOpening($quarter->course, $quarter);
            }
        });
    }

    /**
     * Faculty-initiated close. Requires every firm to have submitted; kept
     * for scripted runs and tests. Normal closes happen at the deadline.
     */
    public function queueClose(Faculty $faculty, CourseQuarter $quarter): void
    {
        $quarter->loadMissing('course.firms');
        $this->authorize($faculty, $quarter->course);
        $this->lockAndQueue($quarter, carryForward: false);
    }

    /** N5: retry the firms whose close failed. */
    public function retry(Faculty $faculty, CourseQuarter $quarter): void
    {
        $quarter->loadMissing('course');
        $this->authorize($faculty, $quarter->course);
        if ($quarter->state !== CourseQuarter::PROCESSING) {
            throw ValidationException::withMessages(['quarter' => 'Only a quarter that is calculating results can be retried.']);
        }
        CloseCourseQuarter::dispatch($quarter->id);
    }

    /** N5: move the open quarter's deadline later. */
    public function extendDeadline(Faculty $faculty, CourseQuarter $quarter, string $deadline): void
    {
        $quarter->loadMissing('course');
        $this->authorize($faculty, $quarter->course);

        if ($quarter->state !== CourseQuarter::OPEN) {
            throw ValidationException::withMessages(['deadline' => 'Only the open quarter\'s deadline can be extended.']);
        }
        try {
            $new = CarbonImmutable::parse($deadline);
        } catch (Throwable) {
            throw ValidationException::withMessages(['deadline' => 'Enter a valid date and time.']);
        }
        if ($new->lte(now()) || ($quarter->decision_deadline_at && $new->lte($quarter->decision_deadline_at))) {
            throw ValidationException::withMessages(['deadline' => 'The new deadline must be later than the current one.']);
        }
        $next = $quarter->course->quarters()->where('quarter_number', $quarter->quarter_number + 1)->first();
        if ($next?->decision_deadline_at && $new->gte($next->decision_deadline_at)) {
            throw ValidationException::withMessages(['deadline' => 'The new deadline must be before Quarter '.$next->quarter_number.'\'s deadline.']);
        }

        $quarter->forceFill([
            'decision_deadline_at' => $new,
            'ends_on' => $quarter->ends_on && $quarter->ends_on->lt($new->startOfDay()) ? $new->toDateString() : $quarter->ends_on,
            'deadline_extended_at' => now(),
        ])->save();
    }

    /**
     * Lock every firm's decision and queue the close. With $carryForward, a
     * firm without a submitted decision keeps its previous quarter's
     * decisions (N4); without it, such a firm blocks the close.
     */
    public function lockAndQueue(CourseQuarter $quarter, bool $carryForward = true): void
    {
        $quarter->loadMissing('course.firms');
        if (! in_array($quarter->state, [CourseQuarter::OPEN, CourseQuarter::PROCESSING], true) || $quarter->quarter_number < 4) {
            throw ValidationException::withMessages(['quarter' => 'Only an open student quarter may be closed.']);
        }
        DB::transaction(function () use ($quarter, $carryForward): void {
            $lockedQuarter = CourseQuarter::query()->lockForUpdate()->findOrFail($quarter->id);
            if ($lockedQuarter->state === CourseQuarter::OPEN) {
                foreach ($lockedQuarter->course->firms()->get() as $firm) {
                    $decision = FirmQuarterDecision::query()->where('firm_id', $firm->id)->where('course_quarter_id', $lockedQuarter->id)->lockForUpdate()->first();
                    if (! $decision || $decision->state !== FirmQuarterDecision::SUBMITTED) {
                        if (! $carryForward) {
                            throw ValidationException::withMessages(['quarter' => "Firm {$firm->code} requires a valid submitted decision before close."]);
                        }
                        $decision = $this->carryForward($firm, $lockedQuarter, $decision);
                    } else {
                        $decision->forceFill(['state' => FirmQuarterDecision::LOCKED, 'locked_at' => now()])->save();
                    }
                    FirmQuarterClose::query()->firstOrCreate(
                        ['firm_id' => $firm->id, 'course_quarter_id' => $lockedQuarter->id],
                        ['course_id' => $lockedQuarter->course_id, 'firm_quarter_decision_id' => $decision->id, 'status' => FirmQuarterClose::PENDING],
                    );
                }
                $lockedQuarter->forceFill(['state' => CourseQuarter::PROCESSING, 'close_started_at' => now()])->save();
            }
        });
        CloseCourseQuarter::dispatch($quarter->id);
    }

    public function finalize(CourseQuarter $quarter): void
    {
        $quarter->refresh();
        $attempts = $quarter->closeAttempts;
        if ($attempts->isNotEmpty() && $attempts->every(fn ($attempt) => $attempt->status === FirmQuarterClose::SUCCEEDED)) {
            $quarter->forceFill(['state' => CourseQuarter::CLOSED, 'closed_at' => now()])->save();
        }
    }

    /** N4: no submission at the deadline, so the previous quarter's decisions are used. A saved draft is not. */
    private function carryForward(Firm $firm, CourseQuarter $quarter, ?FirmQuarterDecision $decision): FirmQuarterDecision
    {
        $service = app(FirmDecisionService::class);
        [$payload, $dividend] = $service->carryForwardPayload($firm, $quarter);

        $decision ??= $service->decision($firm, $quarter);
        $decision->forceFill([
            'submitted_payload' => $payload,
            'dividend' => $dividend,
            'state' => FirmQuarterDecision::LOCKED,
            'locked_at' => now(),
            'carried_forward' => true,
            'submitted_at' => null,
            'submitted_by_student_id' => null,
        ])->save();

        return $decision;
    }
}
