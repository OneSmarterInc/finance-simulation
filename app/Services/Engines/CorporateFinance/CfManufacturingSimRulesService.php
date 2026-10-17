<?php

namespace App\Services\Engines\CorporateFinance;

use App\Services\Engines\CorporateFinance\Exceptions\EngineCalculationException;
use App\Support\Scenario\CfManufacturingScenarioDefinition;

final class CfManufacturingSimRulesService
{
    private const OPERATIONAL_CATEGORIES = [
        'forecasting',
        'sourcing',
        'pricing',
        'production',
        'marketing',
        'quality',
        'logistics',
    ];

    private const DEMAND_FIELDS = [
        'forecast_p1_r1',
        'forecast_p1_r2',
        'forecast_p1_r3',
        'forecast_p2_r1',
        'forecast_p2_r2',
        'forecast_p2_r3',
    ];

    /**
     * @param  array<string, mixed>  $demandContext
     * @param  array<string, mixed>  $parameters
     * @param  array<int, string>  $studentControlledCategories
     * @param  array<string, array<string, mixed>>  $studentDecisions
     * @return array<string, array<string, mixed>>
     */
    public function deriveCompleteDecisions(
        int $quarterNumber,
        array $demandContext,
        array $parameters,
        array $studentControlledCategories = [],
        array $studentDecisions = [],
    ): array {
        if ($quarterNumber < 1) {
            throw new EngineCalculationException('Sim rules require Quarter 1 or later.');
        }

        $this->validateDemandContext($demandContext);
        $controlled = array_values(array_unique($studentControlledCategories));

        foreach ($controlled as $category) {
            if (! in_array($category, self::OPERATIONAL_CATEGORIES, true)) {
                throw new EngineCalculationException("Student-controlled category [{$category}] is not an operational sim-rule category.");
            }

            if (! array_key_exists($category, $studentDecisions)) {
                throw new EngineCalculationException("Missing student-controlled decision payload [{$category}].");
            }

            $this->validateCategoryPayload($category, $studentDecisions[$category]);
        }

        $decisions = [
            'forecasting' => in_array('forecasting', $controlled, true) ? $studentDecisions['forecasting'] : $this->deriveForecasting($demandContext),
            'pricing' => in_array('pricing', $controlled, true) ? $studentDecisions['pricing'] : $this->derivePricing($parameters),
            'production' => in_array('production', $controlled, true) ? $studentDecisions['production'] : $this->deriveProduction($demandContext, $parameters, [], $studentDecisions),
            'marketing' => in_array('marketing', $controlled, true) ? $studentDecisions['marketing'] : $this->deriveMarketing($parameters),
            'quality' => in_array('quality', $controlled, true) ? $studentDecisions['quality'] : $this->deriveQuality(),
            'logistics' => in_array('logistics', $controlled, true) ? $studentDecisions['logistics'] : $this->deriveLogistics(),
        ];

        $decisions['sourcing'] = in_array('sourcing', $controlled, true)
            ? $studentDecisions['sourcing']
            : $this->deriveSourcing($parameters, $decisions, $studentDecisions);

        $ordered = [];

        foreach (self::OPERATIONAL_CATEGORIES as $category) {
            $ordered[$category] = $decisions[$category];
        }

        return $ordered;
    }

    public function deriveDividend(int $quarterNumber, bool $studentControlled = false, mixed $studentDividend = null): float
    {
        if ($quarterNumber < 1) {
            throw new EngineCalculationException('Sim rules require Quarter 1 or later.');
        }

        if ($quarterNumber < 4 || ! $studentControlled) {
            return 0.0;
        }

        if (! is_numeric($studentDividend) || (float) $studentDividend < 0) {
            throw new EngineCalculationException('Student-controlled dividend must be a nonnegative numeric value.');
        }

        return round((float) $studentDividend, 2);
    }

    /**
     * @param  array<string, mixed>  $demandContext
     * @return array<string, mixed>
     */
    public function deriveForecasting(array $demandContext): array
    {
        $this->validateDemandContext($demandContext);

        return array_merge(
            array_intersect_key($demandContext, array_flip(self::DEMAND_FIELDS)),
            ['forecast_confidence' => 'medium'],
        );
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public function derivePricing(array $parameters): array
    {
        $p1Price = $this->requiredNumber($parameters, 'p1_base_price');
        $p2Price = $this->requiredNumber($parameters, 'p2_base_price');

        return [
            'price_p1_r1' => $p1Price,
            'price_p1_r2' => $p1Price,
            'price_p1_r3' => $p1Price,
            'price_p2_r1' => $p2Price,
            'price_p2_r2' => $p2Price,
            'price_p2_r3' => $p2Price,
            'discount_policy' => 'none',
        ];
    }

    /**
     * @param  array<string, mixed>  $demandContext
     * @param  array<string, mixed>  $parameters
     * @param  array<string, array<string, mixed>>  $derivedDecisions
     * @param  array<string, array<string, mixed>>  $studentDecisions
     * @return array<string, mixed>
     */
    public function deriveProduction(array $demandContext, array $parameters, array $derivedDecisions = [], array $studentDecisions = []): array
    {
        $planningDemand = $studentDecisions['forecasting'] ?? $derivedDecisions['forecasting'] ?? $demandContext;
        $this->validateDemandContext($planningDemand);

        $p1Demand = $this->sum($planningDemand, ['forecast_p1_r1', 'forecast_p1_r2', 'forecast_p1_r3']);
        $p2Demand = $this->sum($planningDemand, ['forecast_p2_r1', 'forecast_p2_r2', 'forecast_p2_r3']);
        $capacityPerShift = max(1.0, $this->requiredNumber($parameters, 'capacity_per_shift'));
        $shifts = min(3, max(1, (int) ceil(($p1Demand + $p2Demand) / $capacityPerShift)));

        return [
            'p1_production' => round($p1Demand),
            'p2_production' => round($p2Demand),
            'shifts' => $shifts,
            'postponed_flag' => 'NO',
            'postponed_pct' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, array<string, mixed>>  $derivedDecisions
     * @param  array<string, array<string, mixed>>  $studentDecisions
     * @return array<string, mixed>
     */
    public function deriveSourcing(array $parameters, array $derivedDecisions = [], array $studentDecisions = []): array
    {
        $production = $studentDecisions['production'] ?? $derivedDecisions['production'] ?? null;

        if ($production === null) {
            throw new EngineCalculationException('Closed sourcing requires a production decision to derive procurement alignment.');
        }

        foreach (['p1_production', 'p2_production'] as $field) {
            if (! array_key_exists($field, $production) || ! is_numeric($production[$field])) {
                throw new EngineCalculationException("Closed sourcing requires numeric production field [{$field}].");
            }
        }

        $this->requiredNumber($parameters, 'supplier_sup001_cost');

        return [
            'primary_supplier' => 'SUP001',
            'primary_alloc' => 1.0,
            'secondary_supplier' => 'NONE',
        ];
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public function deriveMarketing(array $parameters): array
    {
        return [
            'marketing_budget_pct' => 0.05,
            'marketing_alloc_champions' => $this->requiredNumber($parameters, 'marketing_champions_base_revenue_pct'),
            'marketing_alloc_growth' => $this->requiredNumber($parameters, 'marketing_growth_base_revenue_pct'),
            'marketing_alloc_at_risk' => $this->requiredNumber($parameters, 'marketing_at_risk_base_revenue_pct'),
            'marketing_alloc_other' => $this->requiredNumber($parameters, 'marketing_other_base_revenue_pct'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function deriveQuality(): array
    {
        return ['inspection_level' => 'BASIC'];
    }

    /**
     * @return array<string, string>
     */
    public function deriveLogistics(): array
    {
        return ['logistics_mode' => 'EXPRESS'];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validateCategoryPayload(string $category, array $payload): void
    {
        $schema = CfManufacturingScenarioDefinition::inputFieldsForCategory($category)['fields'];

        foreach ($schema as $field) {
            $key = (string) $field['key'];

            if (! array_key_exists($key, $payload)) {
                throw new EngineCalculationException("Missing required decision field [{$category}.{$key}].");
            }

            if (($field['type'] ?? null) === 'number' && ! is_numeric($payload[$key])) {
                throw new EngineCalculationException("Decision field [{$category}.{$key}] must be numeric.");
            }

            if (isset($field['allowed_values']) && ! in_array($payload[$key], $field['allowed_values'], true)) {
                throw new EngineCalculationException("Decision field [{$category}.{$key}] has unsupported value [{$payload[$key]}].");
            }
        }

        $expectedKeys = array_map(static fn (array $field): string => (string) $field['key'], $schema);

        foreach (array_keys($payload) as $key) {
            if (! in_array($key, $expectedKeys, true)) {
                throw new EngineCalculationException("Unexpected decision field [{$category}.{$key}].");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $demandContext
     */
    private function validateDemandContext(array $demandContext): void
    {
        foreach (self::DEMAND_FIELDS as $field) {
            if (! array_key_exists($field, $demandContext) || ! is_numeric($demandContext[$field])) {
                throw new EngineCalculationException("Demand context field [{$field}] is required and must be numeric.");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<int, string>  $keys
     */
    private function sum(array $values, array $keys): float
    {
        return array_sum(array_map(fn (string $key): float => (float) $values[$key], $keys));
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function requiredNumber(array $parameters, string $key): float
    {
        if (! array_key_exists($key, $parameters) || ! is_numeric($parameters[$key])) {
            throw new EngineCalculationException("Missing numeric sim-rule parameter [{$key}].");
        }

        return (float) $parameters[$key];
    }
}
