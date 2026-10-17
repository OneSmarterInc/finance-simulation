<?php

namespace App\Services\Warmup;

use InvalidArgumentException;

final class DeterministicDemandRandomizer implements DemandRandomizer
{
    public const GROWTH_MEAN = 0.02;

    public const GROWTH_STANDARD_DEVIATION = 0.055;

    public const APPROVED_STANDARD_DEVIATIONS = [0.040, 0.055, 0.070];

    public const DEMAND_FIELDS = ['forecast_p1_r1', 'forecast_p1_r2', 'forecast_p1_r3', 'forecast_p2_r1', 'forecast_p2_r2', 'forecast_p2_r3'];

    public function __construct(private readonly float $standardDeviation = self::GROWTH_STANDARD_DEVIATION)
    {
        if ($standardDeviation <= 0.0) {
            throw new InvalidArgumentException('Demand standard deviation must be positive.');
        }
    }

    /**
     * @param  array<string, mixed>  $priorDemand
     * @return array<string, int>
     */
    public function compound(array $priorDemand, string $courseSeedHash, string $firmIdentifier, int $quarter, float $seasonality): array
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $courseSeedHash)) {
            throw new InvalidArgumentException('Course seed hash must be a lowercase 64-character SHA-256 digest.');
        }
        if ($firmIdentifier === '') {
            throw new InvalidArgumentException('Firm identifier must not be empty.');
        }
        if ($quarter < 1 || $quarter > 3) {
            throw new InvalidArgumentException('Warm-up demand quarter must be between 1 and 3.');
        }
        if ($seasonality <= 0.0) {
            throw new InvalidArgumentException('Warm-up seasonality must be positive.');
        }

        $demand = [];
        foreach (self::DEMAND_FIELDS as $field) {
            if (! array_key_exists($field, $priorDemand) || ! is_numeric($priorDemand[$field]) || (float) $priorDemand[$field] < 0.0) {
                throw new InvalidArgumentException("Demand field [{$field}] is required and must be a non-negative number.");
            }
            $demand[$field] = max(0, (int) round((float) $priorDemand[$field] * $this->growthFactor($courseSeedHash, $firmIdentifier, $quarter, $field) * $seasonality));
        }

        return $demand;
    }

    public function growthFactor(string $courseSeedHash, string $firmIdentifier, int $quarter, string $field): float
    {
        if (! in_array($field, self::DEMAND_FIELDS, true)) {
            throw new InvalidArgumentException("Unknown warm-up demand field [{$field}].");
        }
        $stream = implode('|', ['cf-warmup-v1', $courseSeedHash, $firmIdentifier, (string) $quarter, $field]);
        $u = $this->uniform($stream.'|u');
        $v = $this->uniform($stream.'|v');
        $z = self::boxMuller($u, $v);

        return 1.0 + self::GROWTH_MEAN + ($this->standardDeviation * $z);
    }

    public static function boxMuller(float $u, float $v): float
    {
        if ($u <= 0.0 || $u >= 1.0 || $v < 0.0 || $v >= 1.0) {
            throw new InvalidArgumentException('Box-Muller uniforms must satisfy 0 < u < 1 and 0 <= v < 1.');
        }

        return sqrt(-2.0 * log($u)) * cos(2.0 * M_PI * $v);
    }

    public function uniform(string $stream): float
    {
        return (hexdec(substr(hash('sha256', $stream), 0, 13)) + 0.5) / 4503599627370496.0;
    }
}
