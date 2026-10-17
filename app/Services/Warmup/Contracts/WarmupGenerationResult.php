<?php

namespace App\Services\Warmup\Contracts;

final readonly class WarmupGenerationResult
{
    /** @param array<int, WarmupQuarterResult> $quarters */
    public function __construct(
        public string $courseSeedHash,
        public string $firmIdentifier,
        public array $quarters,
        public int $readyForQuarter = 4,
    ) {}
}
