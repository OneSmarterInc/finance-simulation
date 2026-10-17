<?php

namespace App\Services\Engines\CorporateFinance\Contracts;

final readonly class DividendLimit
{
    public function __construct(
        public float $requested,
        public float $distributableRetainedEarnings,
        public float $cashAvailableAboveBuffer,
        public float $maximum,
    ) {}
}
