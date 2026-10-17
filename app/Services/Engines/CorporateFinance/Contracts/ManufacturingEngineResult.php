<?php

namespace App\Services\Engines\CorporateFinance\Contracts;

final readonly class ManufacturingEngineResult
{
    /**
     * @param  array<string, float>  $fields
     * @param  array<string, mixed>  $incomeStatement
     * @param  array<string, mixed>  $balanceSheet
     * @param  array<string, mixed>  $cashFlowStatement
     * @param  array<string, mixed>  $notes
     * @param  array<string, mixed>  $intermediates
     */
    public function __construct(
        public array $fields,
        public array $incomeStatement,
        public array $balanceSheet,
        public array $cashFlowStatement,
        public array $notes,
        public array $intermediates,
    ) {}
}
