<?php

namespace App\Services\Engines\CorporateFinance;

use App\Models\Firm;
use App\Models\Scenario;
use App\Services\Warmup\DeterministicDemandRandomizer;
use App\Services\Warmup\FirmWarmupInitializationCoordinator;
use InvalidArgumentException;
use RuntimeException;

/**
 * Where demand comes from in engine version 2 (ED1 b, "own slice, one economy").
 *
 * Quarters 1 to 3 are the warm-up market, exactly as DeterministicDemandRandomizer
 * makes it: each firm's market grows from the Quarter 0 forecast with its own
 * draws. From Quarter 4 each firm keeps its market's size, but every firm in
 * the course grows with the same draws (stream cf-market-v2|seed|quarter|field),
 * and the season is re-based so it no longer compounds.
 *
 * Port of economics/cfmodel/market.py. The values are units customers would
 * order at base price; the engine applies price and marketing.
 */
final class MarketPath
{
    public const STREAM = 'cf-market-v2';

    public function __construct(private readonly DeterministicDemandRandomizer $randomizer) {}

    /** @param array<string, mixed> $parameters */
    public static function forParameters(array $parameters): self
    {
        $sd = $parameters['demand_growth_standard_deviation'] ?? null;
        if (! is_numeric($sd)) {
            throw new RuntimeException('The market needs the demand growth standard deviation.');
        }

        return new self(new DeterministicDemandRandomizer((float) $sd));
    }

    /**
     * @param  array<string, mixed>  $q0  the scenario's Quarter 0 forecast
     * @param  array<string, mixed>  $parameters
     * @return array<int, array<string, int>> quarter => forecast_p1_r1 ... units
     */
    public function path(array $q0, string $courseSeed, string $firmIdentifier, int $lastQuarter, array $parameters): array
    {
        $path = [];
        $prior = $q0;
        for ($q = 1; $q <= $lastQuarter; $q++) {
            $season = self::season($parameters, $q);
            if ($q <= 3) {
                $path[$q] = $this->randomizer->compound($prior, $courseSeed, $firmIdentifier, $q, $season);
            } else {
                $previous = self::season($parameters, $q - 1);
                $path[$q] = [];
                foreach (DeterministicDemandRandomizer::DEMAND_FIELDS as $field) {
                    $path[$q][$field] = max(0, (int) round((float) $prior[$field] / $previous * $this->classGrowth($courseSeed, $q, $field) * $season));
                }
            }
            $prior = $path[$q];
        }

        return $path;
    }

    /** One draw per quarter and field for the whole course, from Quarter 4. */
    public function classGrowth(string $courseSeed, int $quarter, string $field): float
    {
        if ($quarter < 4) {
            throw new InvalidArgumentException('Course-wide market draws start in Quarter 4.');
        }
        $stream = implode('|', [self::STREAM, $courseSeed, (string) $quarter, $field]);
        $z = DeterministicDemandRandomizer::boxMuller($this->randomizer->uniform($stream.'|u'), $this->randomizer->uniform($stream.'|v'));

        return 1.0 + DeterministicDemandRandomizer::GROWTH_MEAN + ($this->randomizer->standardDeviation() * $z);
    }

    /** @return array<string, int> the market a firm faces in a quarter */
    public static function forFirm(Firm $firm, int $quarter, array $parameters): array
    {
        $firm->loadMissing('course.scenario');

        return self::forParameters($parameters)->path(
            self::q0Forecast($firm->course->scenario),
            FirmWarmupInitializationCoordinator::courseSeed($firm->course),
            $firm->id,
            $quarter,
            $parameters,
        )[$quarter];
    }

    /** @return array<string, mixed> */
    public static function q0Forecast(Scenario $scenario): array
    {
        $rows = $scenario->quarterDecisions()
            ->where('quarter_number', 0)
            ->whereHas('category', fn ($query) => $query->where('code', 'forecasting'))
            ->get();
        if ($rows->count() !== 1) {
            throw new RuntimeException('The market requires exactly one Quarter 0 forecasting baseline row.');
        }

        return $rows->first()->payload;
    }

    /** @param array<string, mixed> $parameters */
    public static function season(array $parameters, int $quarter): float
    {
        $key = 'seasonality_q'.((($quarter - 1) % 4) + 1);
        if (! isset($parameters[$key]) || ! is_numeric($parameters[$key])) {
            throw new RuntimeException("The market needs numeric scenario parameter [{$key}].");
        }

        return (float) $parameters[$key];
    }
}
