<?php

namespace App\Services\Engines\CorporateFinance\Contracts;

use App\Services\Engines\CorporateFinance\Exceptions\EngineCalculationException;

final readonly class OpeningFinancialPosition
{
    public function __construct(
        public float $cash,
        public float $accountsReceivable,
        public float $inventory,
        public float $accountsPayable,
        public float $debt,
        public float $retainedEarnings,
        public float $accumulatedDepreciation,
        public float $plantInvestment,
        public float $corporateCapitalization,
        public PpeComponentAllocation $ppeComponents,
        public float $warrantyReserve = 0.0,
        public float $serviceLevelAccrual = 0.0,
        public float $excisePayable = 0.0,
        public float $bankOverdraft = 0.0,
    ) {}

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function fromScenarioParameters(array $parameters): self
    {
        foreach ([
            'opening_cash',
            'opening_plant_investment',
            'opening_liabilities',
            'opening_corporate_capitalization',
        ] as $key) {
            if (! array_key_exists($key, $parameters) || ! is_numeric($parameters[$key])) {
                throw new EngineCalculationException("Missing numeric opening-position parameter [{$key}].");
            }
        }

        return new self(
            cash: (float) $parameters['opening_cash'],
            accountsReceivable: 0.0,
            inventory: 0.0,
            accountsPayable: 0.0,
            debt: (float) $parameters['opening_liabilities'],
            retainedEarnings: 0.0,
            accumulatedDepreciation: 0.0,
            plantInvestment: (float) $parameters['opening_plant_investment'],
            corporateCapitalization: (float) $parameters['opening_corporate_capitalization'],
            ppeComponents: PpeComponentAllocation::fromOpeningPlantInvestment($parameters),
            warrantyReserve: 0.0,
            serviceLevelAccrual: 0.0,
            excisePayable: 0.0,
            bankOverdraft: 0.0,
        );
    }

    /**
     * @param  array<string, mixed>  $priorResult
     */
    public static function fromPriorEngineResult(array $priorResult): self
    {
        return new self(
            cash: self::number($priorResult, 'bs_cash'),
            accountsReceivable: self::number($priorResult, 'bs_ar'),
            inventory: self::number($priorResult, 'bs_inventory'),
            accountsPayable: self::number($priorResult, 'bs_ap'),
            debt: self::number($priorResult, 'bs_curr_ltd') + self::number($priorResult, 'bs_ltd'),
            retainedEarnings: self::number($priorResult, 'bs_retained') + self::number($priorResult, 'bs_curr_ni'),
            accumulatedDepreciation: self::number($priorResult, 'bs_accum_depr'),
            plantInvestment: self::number($priorResult, 'bs_ppe_gross'),
            corporateCapitalization: self::number($priorResult, 'bs_common'),
            ppeComponents: PpeComponentAllocation::fromPriorEngineResult($priorResult),
            warrantyReserve: self::number($priorResult, 'bs_warranty_res'),
            serviceLevelAccrual: self::number($priorResult, 'bs_sl_accrual'),
            excisePayable: self::number($priorResult, 'bs_excise'),
            bankOverdraft: self::number($priorResult, 'bs_bank_overdraft'),
        );
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function number(array $values, string $key): float
    {
        return (float) ($values[$key] ?? 0);
    }
}
