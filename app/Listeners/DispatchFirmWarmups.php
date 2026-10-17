<?php

namespace App\Listeners;

use App\Events\CourseDeploymentReachedWarmupBoundary;
use App\Services\Warmup\FirmWarmupInitializationCoordinator;

class DispatchFirmWarmups
{
    public function __construct(private readonly FirmWarmupInitializationCoordinator $coordinator) {}

    public function handle(CourseDeploymentReachedWarmupBoundary $event): void
    {
        $this->coordinator->courseReachedWarmupBoundary($event);
    }
}
