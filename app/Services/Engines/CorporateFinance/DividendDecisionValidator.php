<?php

namespace App\Services\Engines\CorporateFinance;

use App\Services\Engines\CorporateFinance\Contracts\DividendLimit;
use App\Services\Engines\CorporateFinance\Exceptions\EngineCalculationException;

final class DividendDecisionValidator
{
    public function validate(
        int $quarterNumber,
        mixed $requestedDividend,
        float $openingRetainedEarnings,
        float $currentNetIncome,
        float $preDividendCash,
        float $minimumCashBuffer,
    ): DividendLimit {
        if (! is_numeric($requestedDividend)) {
            throw new EngineCalculationException('Dividend must be a numeric monetary value.');
        }

        $requestedAmount = (float) $requestedDividend;

        if ($requestedAmount < 0) {
            throw new EngineCalculationException('Dividend must be nonnegative.');
        }

        $requested = round($requestedAmount, 2);

        $distributable = round(max(0.0, $openingRetainedEarnings + $currentNetIncome), 2);
        $cashAvailable = round(max(0.0, $preDividendCash - $minimumCashBuffer), 2);
        $maximum = $quarterNumber >= 4 ? round(min($distributable, $cashAvailable), 2) : 0.0;

        if ($requested > $maximum) {
            throw new EngineCalculationException(sprintf(
                'Requested dividend %.2f exceeds the maximum permitted dividend %.2f for Quarter %d.',
                $requested,
                $maximum,
                $quarterNumber,
            ));
        }

        return new DividendLimit($requested, $distributable, $cashAvailable, $maximum);
    }
}
