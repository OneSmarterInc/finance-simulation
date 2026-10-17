<?php

namespace App\Services\Engines\CorporateFinance\Contracts;

interface ManufacturingEngine
{
    public function calculate(ManufacturingEngineInput $input): ManufacturingEngineResult;
}
