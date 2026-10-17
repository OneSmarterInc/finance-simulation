<?php

namespace App\Services\Engines\CorporateFinance\Exceptions;

final class PlausibilityValidationException extends EngineCalculationException
{
    /**
     * @param  array<int, array<string, mixed>>  $violations
     */
    public function __construct(private readonly array $violations)
    {
        $firstRule = $violations[0]['rule'] ?? 'unknown';

        parent::__construct(
            "Plausibility rule [{$firstRule}] failed; violations="
            .json_encode($violations, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function violations(): array
    {
        return $this->violations;
    }
}
