<?php

namespace App\Services\Accounting;

use App\Models\Course;
use App\Models\Faculty;
use App\Models\FirmAccountingElectionRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FirmAccountingElectionRequestService
{
    public function authorize(Faculty $faculty, Course $course): void

    {

        $assigned = \App\Models\CourseFacultyRole::query()

            ->where('course_id', $course->id)

            ->where('faculty_id', $faculty->id)

            ->exists();

        if (

            ! $assigned

            || $faculty->institution_id !== $course->institution_id

        ) {

            throw new AuthorizationException(

                'This course is outside your faculty assignment.'

            );
        }
    }

    /**
     * @return Collection<int, FirmAccountingElectionRequest>
     */
    public function pending(Faculty $faculty, Course $course): Collection

    {
        $this->authorize($faculty, $course);
        return FirmAccountingElectionRequest::query()
            ->where('course_id', $course->id)
            ->with([
                'firm',
                'student.user',
                'courseQuarter',
                'reviewedByFaculty.user',
            ])
            ->latest('created_at')
            ->get();
    }

    public function approve(
        Faculty $faculty,
        FirmAccountingElectionRequest $request
    ): FirmAccountingElectionRequest {
        $course = $request->course()->firstOrFail();

        $this->authorize($faculty, $course);

        if ($request->status !== 'pending') {
            throw ValidationException::withMessages([
                'request' => 'This accounting-election request has already been reviewed.',
            ]);
        }

        $quarter = $request->courseQuarter()->firstOrFail(); 

        $firm = $request->firm()->firstOrFail();

        DB::transaction(function () use ($faculty, $request, $firm, $quarter): void {

            /*
         * Lock all currently pending requests for this firm and quarter.
         *
         * This makes approval first-approved-wins even if two faculty
         * actions happen at nearly the same time.
         */
            $pendingRequests = FirmAccountingElectionRequest::query()
                ->where('firm_id', $request->firm_id)
                ->where('course_quarter_id', $request->course_quarter_id)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->get();

            /*
         * The requested record must still be pending after acquiring
         * the database lock.
         */
            $targetRequest = $pendingRequests->firstWhere('id', $request->id);

            if (! $targetRequest) {
                throw ValidationException::withMessages([
                    'request' => 'This accounting-election request has already been reviewed.',
                ]);
            }

            /*
         * Apply the approved accounting methods to the firm.
         *
         * Inventory remains locked to FIFO by the accounting service.
         */
            app(FirmAccountingElectionService::class)

                ->setEffective(

                    $firm,

                    $quarter,

                    $targetRequest->proposed_elections

                );

            /*
         * Approve the faculty-selected request.
         */
            $targetRequest->forceFill([
                'status' => 'approved',
                'reviewed_by_faculty_id' => $faculty->id,
                'reviewed_at' => now(),
            ])->save();

            /*
         * Any other pending request for the same firm and quarter
         * automatically loses because one request has now been approved.
         *
         * These remain in the request history as rejected records.
         */
            $pendingRequests
                ->reject(
                    fn(FirmAccountingElectionRequest $pending): bool =>
                    $pending->id === $targetRequest->id
                )
                ->each(function (FirmAccountingElectionRequest $pending) use ($faculty): void {
                    $pending->forceFill([
                        'status' => 'rejected',
                        'reviewed_by_faculty_id' => $faculty->id,
                        'reviewed_at' => now(),
                    ])->save();
                });
        });

        return $request->refresh();
    }

    public function reject(
        Faculty $faculty,
        FirmAccountingElectionRequest $request
    ): FirmAccountingElectionRequest {
        $course = $request->course()->firstOrFail();

        $this->authorize($faculty, $course);

        if ($request->status !== 'pending') {
            throw ValidationException::withMessages([
                'request' => 'This accounting-election request has already been reviewed.',
            ]);
        }

        $request->forceFill([
            'status' => 'rejected',
            'reviewed_by_faculty_id' => $faculty->id,
            'reviewed_at' => now(),
        ])->save();

        return $request->refresh();
    }
}
