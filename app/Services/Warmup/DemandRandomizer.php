<?php

namespace App\Services\Warmup;

interface DemandRandomizer
{
    /**
     * @param  array<string, mixed>  $priorDemand
     * @return array<string, int>
     */
    public function compound(array $priorDemand, string $courseSeedHash, string $firmIdentifier, int $quarter, float $seasonality): array;

    public function growthFactor(string $courseSeedHash, string $firmIdentifier, int $quarter, string $field): float;
}
