<?php

namespace Tests\Unit\Services\Warmup;

use App\Services\Warmup\DeterministicDemandRandomizer;
use App\Support\Scenario\CfManufacturingScenarioDefinition;
use InvalidArgumentException;
use Tests\TestCase;

class DeterministicDemandRandomizerTest extends TestCase
{
    public function test_known_seed_has_literal_uniform_box_muller_growth_and_demand_oracles(): void
    {
        $randomizer = new DeterministicDemandRandomizer;
        $seed = hash('sha256', 'course-alpha');
        $stream = "cf-warmup-v1|{$seed}|firm-001|1|forecast_p1_r1";

        $this->assertEqualsWithDelta(0.32833463841468, $randomizer->uniform($stream.'|u'), 0.00000000000001);
        $this->assertEqualsWithDelta(0.49226718056093, $randomizer->uniform($stream.'|v'), 0.00000000000001);
        $this->assertEqualsWithDelta(-1.6651092223154, DeterministicDemandRandomizer::boxMuller(0.25, 0.5), 0.0000000000001);
        $this->assertEqualsWithDelta(0.93801143935773, $randomizer->growthFactor($seed, 'firm-001', 1, 'forecast_p1_r1'), 0.00000000000001);
        $this->assertSame([
            'forecast_p1_r1' => 23919, 'forecast_p1_r2' => 34246, 'forecast_p1_r3' => 51191,
            'forecast_p2_r1' => 22845, 'forecast_p2_r2' => 16779, 'forecast_p2_r3' => 13739,
        ], $randomizer->compound(CfManufacturingScenarioDefinition::q0ForecastingPayload(), $seed, 'firm-001', 1, 0.85));
    }

    public function test_box_muller_rejects_log_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DeterministicDemandRandomizer::boxMuller(0.0, 0.5);
    }

    public function test_distribution_matches_mean_standard_deviation_sigma_bands_and_has_no_field_bias(): void
    {
        $randomizer = new DeterministicDemandRandomizer;
        $values = [];
        $byField = array_fill_keys(DeterministicDemandRandomizer::DEMAND_FIELDS, []);

        for ($firm = 1; $firm <= 2000; $firm++) {
            $seed = hash('sha256', 'distribution-course-'.intdiv($firm - 1, 100));
            foreach ([1, 2, 3] as $quarter) {
                foreach (DeterministicDemandRandomizer::DEMAND_FIELDS as $field) {
                    $value = $randomizer->growthFactor($seed, "firm-{$firm}", $quarter, $field);
                    $values[] = $value;
                    $byField[$field][] = $value;
                }
            }
        }

        $mean = array_sum($values) / count($values);
        $standardDeviation = sqrt(array_sum(array_map(static fn (float $value): float => ($value - $mean) ** 2, $values)) / count($values));
        $this->assertEqualsWithDelta(1.02, $mean, 0.001);
        $this->assertEqualsWithDelta(0.055, $standardDeviation, 0.001);
        $this->assertEqualsWithDelta(0.6827, $this->sigmaProportion($values, 1), 0.02);
        $this->assertEqualsWithDelta(0.9545, $this->sigmaProportion($values, 2), 0.01);
        $this->assertEqualsWithDelta(0.9973, $this->sigmaProportion($values, 3), 0.003);

        foreach ($byField as $field => $fieldValues) {
            $this->assertEqualsWithDelta(1.02, array_sum($fieldValues) / count($fieldValues), 0.002, "Distribution bias detected for {$field}");
        }
    }

    public function test_only_the_approved_active_standard_deviation_and_mean_are_exposed(): void
    {
        $this->assertSame(0.02, DeterministicDemandRandomizer::GROWTH_MEAN);
        $this->assertSame(0.055, DeterministicDemandRandomizer::GROWTH_STANDARD_DEVIATION);
        $this->assertSame([0.040, 0.055, 0.070], DeterministicDemandRandomizer::APPROVED_STANDARD_DEVIATIONS);
        $this->assertSame(0.055, CfManufacturingScenarioDefinition::parameters()['demand_growth_standard_deviation']);
    }

    /** @param array<int, float> $values */
    private function sigmaProportion(array $values, int $sigma): float
    {
        $distance = $sigma * DeterministicDemandRandomizer::GROWTH_STANDARD_DEVIATION;

        return count(array_filter($values, static fn (float $value): bool => abs($value - 1.02) <= $distance)) / count($values);
    }
}
