<?php

namespace App\Services\Engines\CorporateFinance\Contracts;

use App\Services\Engines\CorporateFinance\Exceptions\EngineCalculationException;

final readonly class ManufacturingEngineInput
{
    /**
     * @param  array<string, array<string, mixed>>  $decisions
     * @param  array<string, mixed>  $parameters
     */
    public function __construct(
        public int $quarterNumber,
        public array $decisions,
        public array $parameters,
        public OpeningFinancialPosition $openingPosition,
        public float|int|string|null $dividend = 0.0,
        public int $engineVersion = 1,
        /** Version 2: units customers would order at base price this quarter (forecast_p1_r1 ... keys). */
        public ?array $market = null,
    ) {
        if (! in_array($engineVersion, [1, 2], true)) {
            throw new EngineCalculationException("Unknown engine version [{$engineVersion}].");
        }
        if ($engineVersion === 2 && $market === null) {
            throw new EngineCalculationException('Engine version 2 needs the quarter\'s market.');
        }
        if ($quarterNumber < 1) {
            throw new EngineCalculationException('The manufacturing engine only calculates Quarter 1 and later.');
        }

        foreach ([
            'forecasting',
            'sourcing',
            'pricing',
            'production',
            'marketing',
            'quality',
            'logistics',
            'notes_elections',
            ...($engineVersion === 1 ? ['certification'] : []),
        ] as $category) {
            if (! array_key_exists($category, $decisions)) {
                throw new EngineCalculationException("Missing complete decision category [{$category}].");
            }
        }
    }
}
