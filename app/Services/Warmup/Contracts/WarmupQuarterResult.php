<?php

namespace App\Services\Warmup\Contracts;

use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngineResult;
use App\Services\Engines\CorporateFinance\Contracts\OpeningFinancialPosition;

final readonly class WarmupQuarterResult
{
    /**
     * @param  array<string, int>  $demand
     * @param  array<string, array<string, mixed>>  $decisions
     * @param  array<string, array{evaluated: bool, calculated: float|int|string|null, permitted: string, passed: bool|null, skip_reason: string|null}>  $plausibility
     * @param  array<int, array{flag: string, amount: float, quarter: int|string}>  $flags
     */
    public function __construct(
        public int $quarter,
        public array $demand,
        public array $decisions,
        public OpeningFinancialPosition $opening,
        public ManufacturingEngineResult $statements,
        public array $plausibility,
        public array $flags,
    ) {}
}
