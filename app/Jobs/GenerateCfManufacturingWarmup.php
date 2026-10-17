<?php

namespace App\Jobs;

use App\Services\Warmup\CfManufacturingWarmupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateCfManufacturingWarmup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public readonly string $courseSeedHash;

    public function __construct(
        string $courseInstanceToken,
        public readonly string $firmIdentifier,
        public readonly string $scenarioIdentifier = 'cf_manufacturing',
    ) {
        $this->courseSeedHash = hash('sha256', $courseInstanceToken);
    }

    public function handle(CfManufacturingWarmupService $service): void
    {
        $service->generateFromSeedHash($this->courseSeedHash, $this->firmIdentifier, $this->scenarioIdentifier);
    }
}
