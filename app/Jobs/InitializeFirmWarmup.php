<?php

namespace App\Jobs;

use App\Models\Firm;
use App\Services\Warmup\InheritedWarmupHistoryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class InitializeFirmWarmup implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 3600;

    public function __construct(public readonly string $firmId) {}

    public function uniqueId(): string
    {
        return $this->firmId;
    }

    public function handle(InheritedWarmupHistoryService $history): void
    {
        $history->initialize(Firm::query()->findOrFail($this->firmId));
    }
}
