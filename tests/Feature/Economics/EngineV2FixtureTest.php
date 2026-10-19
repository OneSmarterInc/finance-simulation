<?php

namespace Tests\Feature\Economics;

use App\Services\Engines\CorporateFinance\CfManufacturingSimRulesService;
use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngineInput;
use App\Services\Engines\CorporateFinance\Contracts\OpeningFinancialPosition;
use App\Services\Engines\CorporateFinance\Contracts\PpeComponentAllocation;
use App\Services\Engines\CorporateFinance\ManufacturingCFEngineV2;
use App\Services\Engines\CorporateFinance\MarketPath;
use App\Services\Engines\CorporateFinance\VersionedManufacturingEngine;
use Tests\TestCase;

/**
 * Engine version 2 must reproduce every value in economics/fixtures/v2_reference.json
 * (relative 1e-6, absolute 1e-4): three teams over Quarters 1-11, the variants
 * and the edge cases, each quarter replayed from its own opening position.
 */
class EngineV2FixtureTest extends TestCase
{
    private static ?array $fixtures = null;

    private function fixtures(): array
    {
        return self::$fixtures ??= json_decode((string) file_get_contents(base_path('economics/fixtures/v2_reference.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    private function parameters(): array
    {
        return json_decode((string) file_get_contents(base_path('economics/data/scenario.json')), true, flags: JSON_THROW_ON_ERROR)['parameters'];
    }

    public static function opening(array $o): OpeningFinancialPosition
    {
        return new OpeningFinancialPosition(
            cash: $o['cash'], accountsReceivable: $o['ar'], inventory: $o['inventory'], accountsPayable: $o['ap'],
            debt: $o['debt'], retainedEarnings: $o['retained'], accumulatedDepreciation: $o['accum_depr'],
            plantInvestment: $o['plant'], corporateCapitalization: $o['capital'],
            ppeComponents: new PpeComponentAllocation($o['ppe_mfg'], $o['ppe_tech'], $o['ppe_dc']),
            warrantyReserve: $o['warranty'], serviceLevelAccrual: $o['sl_accrual'], excisePayable: $o['excise'],
            bankOverdraft: $o['overdraft'],
            unitsOnHandP1: $o['fg_units_p1'], unitsOnHandP2: $o['fg_units_p2'],
            stockValueP1: $o['fg_value_p1'], stockValueP2: $o['fg_value_p2'], lifoReserve: $o['lifo_reserve'],
            accumulatedManufacturing: $o['accum_mfg'], accumulatedTechnology: $o['accum_tech'], accumulatedDistributionCenter: $o['accum_dc'],
        );
    }

    /** @return array<string, array> label => fixture row */
    private function rows(): array
    {
        $f = $this->fixtures();
        $rows = [];
        foreach ($f['teams'] as $team => $quarters) {
            foreach ($quarters as $row) {
                $rows["{$team} Q{$row['quarter']}"] = $row;
            }
        }
        foreach ($f['variants'] as $name => $quarters) {
            foreach ($quarters as $row) {
                $rows["variant {$name} Q{$row['quarter']}"] = $row;
            }
        }
        foreach ($f['edge_cases'] as $name => $row) {
            $rows["edge case {$name}"] = $row;
        }

        return $rows;
    }

    public function test_version_2_reproduces_every_fixture_value(): void
    {
        $engine = new VersionedManufacturingEngine;
        $parameters = $this->parameters();
        $tolerance = $this->fixtures()['tolerance'];
        $checked = 0;
        $failures = [];

        foreach ($this->rows() as $label => $row) {
            $result = $engine->calculate(new ManufacturingEngineInput(
                quarterNumber: $row['quarter'],
                decisions: $row['decisions'],
                parameters: $parameters,
                openingPosition: self::opening($row['opening']),
                dividend: $row['dividend_paid'] ?? $row['dividend_declared'],
                engineVersion: 2,
                market: $row['market'],
            ));

            $this->assertSame(ManufacturingCFEngineV2::fieldKeys(), array_keys($result->fields), "{$label}: field set");
            foreach ($row['fields'] as $key => $want) {
                $got = $result->fields[$key];
                $checked++;
                if (abs($got - $want) > max($tolerance['absolute'], $tolerance['relative'] * abs($want))) {
                    $failures[] = "{$label} {$key}: fixture {$want}, PHP {$got}";
                }
            }
            foreach ($row['income_statement'] as $key => $want) {
                $checked++;
                if (abs($result->incomeStatement[$key] - $want) > $tolerance['absolute']) {
                    $failures[] = "{$label} statement {$key}: fixture {$want}, PHP {$result->incomeStatement[$key]}";
                }
            }
        }

        $this->assertSame([], array_slice($failures, 0, 20), count($failures).' values differ');
        $this->assertGreaterThan(20000, $checked);
    }

    public function test_the_market_reproduces_the_fixture_market(): void
    {
        $f = $this->fixtures();
        $course = $f['reference_course'];
        $path = MarketPath::forParameters($this->parameters())->path($f['q0_forecast'], $course['seed'], $course['warmup_firm_id'], 11, $this->parameters());

        foreach ($f['teams']['careful'] as $row) {
            $this->assertSame($row['market'], $path[$row['quarter']], "Q{$row['quarter']}");
        }

        // From Quarter 4 another firm in the same course grows with the same draws.
        $other = MarketPath::forParameters($this->parameters())->path($f['q0_forecast'], $course['seed'], $course['second_firm_id'], 11, $this->parameters());
        $this->assertNotSame($path[3], $other[3]);
        foreach (array_keys($path[11]) as $field) {
            $this->assertEqualsWithDelta($path[11][$field] / $path[3][$field], $other[11][$field] / $other[3][$field], 1e-3 * $path[11][$field] / $path[3][$field]);
        }
    }

    public function test_the_sim_rules_reproduce_the_fixture_decisions(): void
    {
        $rules = new CfManufacturingSimRulesService;
        $parameters = $this->parameters();
        $f = $this->fixtures();
        $chains = $f['teams'];
        foreach ($f['variants'] as $name => $rows) {
            $chains["variant {$name}"] = $rows;
        }
        $checked = 0;
        foreach ($chains as $label => $rows) {
            $prior = str_starts_with($label, 'variant') ? $f['teams']['careful'][2]['fields'] : null;
            foreach ($rows as $row) {
                $context = $rules->demandContextV2($row['quarter'], $row['market'], $prior, $parameters);
                $derived = $rules->deriveCompleteDecisionsV2($row['quarter'], $context, $parameters, $row['student_areas'], $row['student_payload'], $row['units_on_hand']);
                $want = $row['decisions'];
                unset($want['notes_elections']);
                $this->assertEquals($want, $derived, "{$label} Q{$row['quarter']}");
                $prior = $row['fields'];
                $checked++;
            }
        }
        $this->assertGreaterThan(100, $checked);
    }

    public function test_each_quarter_opens_from_the_prior_quarters_stored_fields(): void
    {
        foreach ($this->fixtures()['teams'] as $team => $quarters) {
            foreach (array_slice($quarters, 1) as $i => $row) {
                $built = OpeningFinancialPosition::fromPriorEngineResult($quarters[$i]['fields']);
                $this->assertEquals(self::opening($row['opening']), $built, "{$team} Q{$row['quarter']}");
            }
        }
    }
}
