<?php

namespace App\Jobs\Quarters;

use App\Models\CourseQuarter;
use App\Models\FirmQuarterClose;
use App\Services\Quarters\FirmQuarterCloseService;
use App\Services\Quarters\QuarterManagementService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CloseFirmQuarter implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $attemptId) {}

    public function handle(FirmQuarterCloseService $closer, QuarterManagementService $quarters): void
    {
        $attempt = FirmQuarterClose::query()->findOrFail($this->attemptId);
        $closer->close($attempt);
        $quarters->finalize(CourseQuarter::query()->findOrFail($attempt->course_quarter_id));
    }
}
