<?php

namespace App\Services\Engines\CorporateFinance\Contracts;

use App\Services\Engines\CorporateFinance\Exceptions\EngineCalculationException;

final readonly class PpeComponentAllocation
{
    public function __construct(
        public float $manufacturing,
        public float $technology,
        public float $distributionCenter,
    ) {}

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function fromOpeningPlantInvestment(array $parameters): self
    {
        $sourceManufacturing = self::requiredNumber($parameters, 'gross_ppe_mfg');
        $sourceTechnology = self::requiredNumber($parameters, 'gross_ppe_tech');
        $sourceDistributionCenter = self::requiredNumber($parameters, 'gross_ppe_dc');
        $targetPlantInvestment = self::requiredNumber($parameters, 'opening_plant_investment');
        $sourceTotal = $sourceManufacturing + $sourceTechnology + $sourceDistributionCenter;

        if (abs($sourceTotal - 40000000.0) > 0.01) {
            throw new EngineCalculationException("Unexpected legacy PPE source total: {$sourceTotal}.");
        }

        if (abs($targetPlantInvestment - 100000000.0) > 0.01) {
            throw new EngineCalculationException("Unexpected opening plant investment target: {$targetPlantInvestment}.");
        }

        $scaleFactor = $targetPlantInvestment / $sourceTotal;
        $allocation = new self(
            manufacturing: round($sourceManufacturing * $scaleFactor, 2),
            technology: round($sourceTechnology * $scaleFactor, 2),
            distributionCenter: round($sourceDistributionCenter * $scaleFactor, 2),
        );

        $allocatedTotal = $allocation->total();

        if (abs($allocatedTotal - $targetPlantInvestment) > 0.01) {
            throw new EngineCalculationException("Scaled PPE allocation does not equal opening plant investment: allocated={$allocatedTotal}; target={$targetPlantInvestment}.");
        }

        return $allocation;
    }

    /**
     * @param  array<string, mixed>  $priorResult
     */
    public static function fromPriorEngineResult(array $priorResult): self
    {
        return new self(
            manufacturing: (float) ($priorResult['n4_mfg_gross'] ?? 0),
            technology: (float) ($priorResult['n4_tech_gross'] ?? 0),
            distributionCenter: (float) ($priorResult['n4_dc_gross'] ?? 0),
        );
    }

    public function total(): float
    {
        return round($this->manufacturing + $this->technology + $this->distributionCenter, 2);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private static function requiredNumber(array $parameters, string $key): float
    {
        if (! array_key_exists($key, $parameters) || ! is_numeric($parameters[$key])) {
            throw new EngineCalculationException("Missing numeric PPE allocation parameter [{$key}].");
        }

        return (float) $parameters[$key];
    }
}
