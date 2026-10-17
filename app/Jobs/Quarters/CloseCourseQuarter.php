<?php

namespace App\Jobs\Quarters;

use App\Models\CourseQuarter;
use App\Models\FirmQuarterClose;
use App\Services\Quarters\QuarterManagementService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CloseCourseQuarter implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $courseQuarterId) {}

    public function handle(QuarterManagementService $quarters): void
    {
        $quarter = CourseQuarter::query()->findOrFail($this->courseQuarterId);
        $quarter->closeAttempts()->whereIn('status', [FirmQuarterClose::PENDING, FirmQuarterClose::FAILED])->each(
            fn (FirmQuarterClose $attempt) => CloseFirmQuarter::dispatch($attempt->id),
        );
        $quarters->finalize($quarter);
    }
}
