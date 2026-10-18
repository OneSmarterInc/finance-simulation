<?php

namespace App\Services\Engines\CorporateFinance;

use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngine;
use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngineInput;
use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngineResult;

/**
 * Runs the engine version the input asks for. A course keeps the version it
 * started with to the end (ED11), so callers pass the course's version.
 */
final class VersionedManufacturingEngine implements ManufacturingEngine
{
    public function __construct(
        private readonly ManufacturingCFEngine $v1 = new ManufacturingCFEngine,
        private readonly ManufacturingCFEngineV2 $v2 = new ManufacturingCFEngineV2,
    ) {}

    public function calculate(ManufacturingEngineInput $input): ManufacturingEngineResult
    {
        return $input->engineVersion === ManufacturingCFEngineV2::VERSION
            ? $this->v2->calculate($input)
            : $this->v1->calculate($input);
    }
}
