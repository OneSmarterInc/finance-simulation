<?php

namespace App\Services\Warmup;

use App\Models\Scenario;
use App\Models\ScenarioParameter;
use App\Services\Engines\CorporateFinance\AccountingIdentityValidator;
use App\Services\Engines\CorporateFinance\CfManufacturingSimRulesService;
use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngine;
use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngineInput;
use App\Services\Engines\CorporateFinance\Contracts\OpeningFinancialPosition;
use App\Services\Engines\CorporateFinance\FinancialPlausibilityValidator;
use App\Services\Engines\CorporateFinance\MarketPath;
use App\Services\Engines\CorporateFinance\VersionedManufacturingEngine;
use App\Services\Warmup\Contracts\WarmupGenerationResult;
use App\Services\Warmup\Contracts\WarmupQuarterResult;
use App\Services\Warmup\Exceptions\WarmupGenerationException;
use App\Support\Scenario\CfManufacturingScenarioDefinition;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

class CfManufacturingWarmupService
{
    public function __construct(
        private readonly ?DemandRandomizer $randomizer = null,
        private readonly CfManufacturingSimRulesService $simRules = new CfManufacturingSimRulesService,
        private readonly ManufacturingEngine $engine = new VersionedManufacturingEngine,
        private readonly AccountingIdentityValidator $accountingValidator = new AccountingIdentityValidator,
        private readonly FinancialPlausibilityValidator $plausibilityValidator = new FinancialPlausibilityValidator,
    ) {}

    public function generate(string $courseInstanceToken, string $firmIdentifier, Scenario|string|null $scenario = null, int $engineVersion = 1): WarmupGenerationResult
    {
        if ($courseInstanceToken === '') {
            throw new RuntimeException('Course-instance token must not be empty.');
        }

        return $this->generateFromSeedHash(hash('sha256', $courseInstanceToken), $firmIdentifier, $scenario, $engineVersion);
    }

    /**
     * Engine version 2 runs the same warm-up market through the version 2
     * rules and engine; the warm-up still sells everything it makes.
     */
    public function generateFromSeedHash(string $courseSeedHash, string $firmIdentifier, Scenario|string|null $scenario = null, int $engineVersion = 1, ?array $startingElections = null): WarmupGenerationResult
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $courseSeedHash)) {
            throw new RuntimeException('Course seed hash must be a lowercase 64-character SHA-256 digest.');
        }
        if ($firmIdentifier === '') {
            throw new RuntimeException('Firm identifier must not be empty.');
        }

        $scenario = $this->resolveScenario($scenario);
        $parameters = $scenario->parameters()->get()->mapWithKeys(
            fn (ScenarioParameter $parameter): array => [$parameter->parameter_key => $parameter->parameter_value['value']],
        )->all();
        $standardDeviation = $parameters['demand_growth_standard_deviation'] ?? null;
        if (! is_numeric($standardDeviation) || ! in_array((float) $standardDeviation, DeterministicDemandRandomizer::APPROVED_STANDARD_DEVIATIONS, true)) {
            throw new RuntimeException('Warm-up requires one approved demand growth standard deviation.');
        }
        $randomizer = $this->randomizer ?? new DeterministicDemandRandomizer((float) $standardDeviation);
        $rows = $scenario->quarterDecisions()->with('category:id,code')->get();
        $q0Rows = $rows->filter(fn ($row): bool => $row->quarter_number === 0 && $row->category->code === 'forecasting');
        if ($q0Rows->count() !== 1) {
            throw new RuntimeException('Warm-up requires exactly one Q0 forecasting baseline row.');
        }

        $priorDemand = $q0Rows->first()->payload;
        $v2Market = $engineVersion === 2 && $randomizer instanceof DeterministicDemandRandomizer
            ? (new MarketPath($randomizer))->path($priorDemand, $courseSeedHash, $firmIdentifier, 3, $parameters)
            : [];
        $opening = OpeningFinancialPosition::fromScenarioParameters($parameters);
        $priorResult = null;
        $quarterResults = [];

        foreach ([1, 2, 3] as $quarter) {
            $seasonalityKey = "seasonality_q{$quarter}";
            if (! isset($parameters[$seasonalityKey]) || ! is_numeric($parameters[$seasonalityKey])) {
                throw new RuntimeException("Warm-up requires numeric scenario parameter [{$seasonalityKey}].");
            }

            try {
                $growthTrace = [];
                foreach (DeterministicDemandRandomizer::DEMAND_FIELDS as $field) {
                    $growthTrace[$field] = $randomizer->growthFactor($courseSeedHash, $firmIdentifier, $quarter, $field);
                }
                // Version 2 applies the season to the market's level (ED16); version 1 compounds it.
                $demand = $engineVersion === 2 && isset($v2Market[$quarter])
                    ? $v2Market[$quarter]
                    : $randomizer->compound($priorDemand, $courseSeedHash, $firmIdentifier, $quarter, (float) $parameters[$seasonalityKey]);
                $this->validateRandomizedDemand($demand);
                $decisions = $engineVersion === 2
                    ? array_merge($this->simRules->deriveCompleteDecisionsV2($quarter, $demand, $parameters, [], [], ['p1' => $opening->unitsOnHandP1, 'p2' => $opening->unitsOnHandP2]), [
                        'notes_elections' => $startingElections ?? $this->lockedPayload($rows, $quarter, 'notes_elections'),
                    ])
                    : array_merge($this->simRules->deriveCompleteDecisions($quarter, $demand, $parameters), [
                        'notes_elections' => $startingElections ?? $this->lockedPayload($rows, $quarter, 'notes_elections'),
                        'certification' => $this->lockedPayload($rows, $quarter, 'certification'),
                    ]);
                $result = $this->engine->calculate(new ManufacturingEngineInput(
                    quarterNumber: $quarter,
                    decisions: $decisions,
                    parameters: $parameters,
                    openingPosition: $opening,
                    dividend: $this->simRules->deriveDividend($quarter),
                    engineVersion: $engineVersion,
                    market: $engineVersion === 2 ? $demand : null,
                ));
                $this->accountingValidator->validate($result->fields, $opening);
                $this->plausibilityValidator->validate($result, $opening, $priorResult);
                $quarterResults[$quarter] = new WarmupQuarterResult($quarter, $demand, $decisions, $opening, $result, $this->plausibilityValidator->evaluate($result, $opening, $priorResult), $this->plausibilityValidator->flags($result));
            } catch (WarmupGenerationException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                throw new WarmupGenerationException($quarter, [
                    'firm_hash' => hash('sha256', $firmIdentifier),
                    'demand' => $demand ?? null,
                    'prior_demand' => $priorDemand,
                    'growth_trace' => $growthTrace,
                    'seasonality' => (float) $parameters[$seasonalityKey],
                    'failure_type' => $exception::class,
                ], $exception);
            }
            $priorDemand = $demand;
            $opening = OpeningFinancialPosition::fromPriorEngineResult($result->fields);
            $priorResult = $result;
        }

        return new WarmupGenerationResult($courseSeedHash, $firmIdentifier, $quarterResults);
    }

    private function resolveScenario(Scenario|string|null $scenario): Scenario
    {
        if ($scenario instanceof Scenario) {
            if (! $scenario->active || $scenario->code !== CfManufacturingScenarioDefinition::CODE) {
                throw new RuntimeException('Warm-up requires the active CF Manufacturing scenario.');
            }

            return $scenario;
        }

        $identifier = $scenario ?? CfManufacturingScenarioDefinition::CODE;

        return Scenario::query()
            ->where('active', true)
            ->where(Str::isUuid($identifier) ? 'id' : 'code', $identifier)
            ->firstOrFail();
    }

    /** @param array<string, mixed> $demand */
    private function validateRandomizedDemand(array $demand): void
    {
        if (array_keys($demand) !== DeterministicDemandRandomizer::DEMAND_FIELDS) {
            throw new RuntimeException('Warm-up randomizer returned an invalid demand field set.');
        }

        foreach ($demand as $field => $value) {
            if (! is_int($value) || $value < 0) {
                throw new RuntimeException("Warm-up randomizer returned invalid demand for [{$field}].");
            }
        }
    }

    /**
     * @param  Collection<int, mixed>  $rows
     * @return array<string, mixed>
     */
    private function lockedPayload(Collection $rows, int $quarter, string $category): array
    {
        $matches = $rows->filter(fn ($row): bool => $row->quarter_number === $quarter && $row->category->code === $category);
        if ($matches->count() !== 1) {
            throw new RuntimeException("Warm-up requires exactly one locked [{$category}] row for Quarter {$quarter}.");
        }

        return $matches->first()->payload;
    }
}
