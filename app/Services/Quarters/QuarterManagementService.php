<?php

namespace App\Services\Quarters;

use App\Jobs\Quarters\CloseCourseQuarter;
use App\Models\Course;
use App\Models\CourseFacultyRole;
use App\Models\CourseQuarter;
use App\Models\Faculty;
use App\Models\FirmQuarterClose;
use App\Models\FirmQuarterDecision;
use App\Services\CfPlus\CfPlusAuctionEconomicsSnapshotService;
use App\Services\CfPlus\CfPlusGate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuarterManagementService
{
    public function __construct(private readonly CfPlusAuctionEconomicsSnapshotService $auctionSnapshots, private readonly CfPlusGate $cfPlusGate) {}

    public function authorize(Faculty $faculty, Course $course): void
    {
        $assigned = CourseFacultyRole::query()->where('course_id', $course->id)->where('faculty_id', $faculty->id)->exists();
        if (! $assigned || $faculty->institution_id !== $course->institution_id) {
            throw new AuthorizationException('This course is outside your faculty assignment.');
        }
    }

    public function open(Faculty $faculty, CourseQuarter $quarter): void
    {
        $quarter->loadMissing('course.firms');
        $this->authorize($faculty, $quarter->course);
        if ($quarter->quarter_number < 4 || $quarter->state !== CourseQuarter::LOCKED) {
            throw ValidationException::withMessages(['quarter' => 'Only the next locked student quarter may be opened.']);
        }
        if ($quarter->course->quarters()->where(
            fn ($query) => $query->where('state', CourseQuarter::OPEN)->orWhere('state', CourseQuarter::PROCESSING),
        )->exists()) {
            throw ValidationException::withMessages(['quarter' => 'Only one student quarter may be active.']);
        }
        $priorStudent = $quarter->course->quarters()->where('quarter_number', $quarter->quarter_number - 1)->first();
        if ($quarter->quarter_number === 4) {
            if ($quarter->course->firms()->where('warmup_status', '!=', 'initialized')->exists() || $quarter->course->firms()->whereDoesntHave('engineResults', fn ($q) => $q->where('quarter_number', 3))->exists()) {
                throw ValidationException::withMessages(['quarter' => 'Every active firm requires persisted Q1-Q3 warm-up history before Quarter 4 opens.']);
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

    public function queueClose(Faculty $faculty, CourseQuarter $quarter): void
    {
        $quarter->loadMissing('course.firms');
        $this->authorize($faculty, $quarter->course);
        if (! in_array($quarter->state, [CourseQuarter::OPEN, CourseQuarter::PROCESSING], true) || $quarter->quarter_number < 4) {
            throw ValidationException::withMessages(['quarter' => 'Only an open student quarter may be closed.']);
        }
        DB::transaction(function () use ($quarter): void {
            $lockedQuarter = CourseQuarter::query()->lockForUpdate()->findOrFail($quarter->id);
            if ($lockedQuarter->state === CourseQuarter::OPEN) {
                foreach ($lockedQuarter->course->firms()->get() as $firm) {
                    $decision = FirmQuarterDecision::query()->where('firm_id', $firm->id)->where('course_quarter_id', $lockedQuarter->id)->lockForUpdate()->first();
                    if (! $decision || $decision->state !== FirmQuarterDecision::SUBMITTED) {
                        throw ValidationException::withMessages(['quarter' => "Firm {$firm->code} requires a valid submitted decision before close."]);
                    }
                    $decision->forceFill(['state' => FirmQuarterDecision::LOCKED, 'locked_at' => now()])->save();
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
}
