<?php

namespace Tests\Feature\Economics;

use App\Services\Engines\CorporateFinance\CfManufacturingSimRulesService;
use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngineInput;
use App\Services\Engines\CorporateFinance\Contracts\OpeningFinancialPosition;
use App\Services\Engines\CorporateFinance\CurrencyRounding;
use App\Services\Engines\CorporateFinance\ManufacturingCFEngine;
use App\Support\Scenario\CfManufacturingScenarioDefinition as Scenario;
use Tests\TestCase;

/**
 * Ties the Python economics package (economics/) to the PHP code.
 *
 * The Python reference model reads economics/data/scenario.json and is
 * checked against economics/fixtures/v1_stored_results.json. These tests
 * hold the PHP side to the same files, so neither side can drift alone.
 */
class EconomicsPackageTest extends TestCase
{
    private function fixture(string $path): array
    {
        return json_decode((string) file_get_contents(base_path('economics/'.$path)), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_scenario_data_file_matches_the_scenario_definition(): void
    {
        $data = $this->fixture('data/scenario.json');

        $this->assertEquals(Scenario::parameters(), $data['parameters'], 'economics/data/scenario.json is out of date: re-export it from CfManufacturingScenarioDefinition.');
        $this->assertEquals(Scenario::q0ForecastingPayload(), $data['q0_forecast']);
        $this->assertEquals(Scenario::fixedWarmupPayload('notes_elections'), $data['warmup_notes_elections']);
        $this->assertEquals(Scenario::fixedWarmupPayload('certification'), $data['warmup_certification']);
    }

    public function test_engine_version_1_still_reproduces_the_stored_results(): void
    {
        $engine = new ManufacturingCFEngine;
        $checked = 0;

        foreach ($this->fixture('fixtures/v1_stored_results.json')['firms'] as $firm) {
            $opening = OpeningFinancialPosition::fromScenarioParameters($firm['parameters']);

            foreach ($firm['quarters'] as $quarter) {
                $decisions = $quarter['decisions'];
                unset($decisions['dividend']);
                $result = $engine->calculate(new ManufacturingEngineInput(
                    quarterNumber: $quarter['quarter'],
                    decisions: $decisions,
                    parameters: $firm['parameters'],
                    openingPosition: $opening,
                    dividend: $quarter['dividend'] ?? 0.0,
                ));

                foreach ($quarter['fields'] as $key => $stored) {
                    $this->assertEqualsWithDelta($stored, $result->fields[$key], max(1e-4, 1e-6 * abs($stored)), "{$firm['code']} Q{$quarter['quarter']} {$key}");
                    $checked++;
                }
                foreach ($quarter['income_statement'] as $key => $stored) {
                    $this->assertEqualsWithDelta($stored, $result->incomeStatement[$key], 1e-4, "{$firm['code']} Q{$quarter['quarter']} {$key}");
                    $checked++;
                }
                $opening = OpeningFinancialPosition::fromPriorEngineResult($quarter['fields']);
            }
        }

        $this->assertSame(1529, $checked);
    }

    public function test_sim_rules_reproduce_the_stored_warmup_decisions(): void
    {
        $rules = new CfManufacturingSimRulesService;
        $firm = collect($this->fixture('fixtures/v1_stored_results.json')['firms'])->firstWhere('code', 'P8-A');

        foreach (array_slice($firm['quarters'], 0, 3) as $quarter) {
            $stored = $quarter['decisions'];
            $derived = $rules->deriveCompleteDecisions($quarter['quarter'], $stored['forecasting'], $firm['parameters']);

            foreach ($derived as $category => $payload) {
                $this->assertEquals($stored[$category], $payload, "Q{$quarter['quarter']} {$category}");
            }
        }
    }

    /**
     * The fixtures need PHP 8.4's round(): earlier versions pre-round to 15
     * significant digits and can land a cent away on values like 38013.00499999999.
     */
    public function test_currency_rounding_matches_the_model(): void
    {
        $cases = $this->fixture('fixtures/rounding_cases.json')['cases'];
        $wrong = array_filter($cases, fn (array $case): bool => CurrencyRounding::cents((float) $case[0]) !== (float) $case[1]);

        $this->assertSame([], array_slice(array_values($wrong), 0, 5), count($wrong).' of '.count($cases).' values round differently from the model; this server needs PHP 8.4 or later.');
    }

    public function test_version_2_fixtures_are_well_formed(): void
    {
        $fixtures = $this->fixture('fixtures/v2_reference.json');

        $this->assertSame(2, $fixtures['engine_version']);
        $this->assertSame(hash_file('sha256', base_path('economics/data/scenario.json')), $fixtures['scenario_sha256'], 'Rebuild the fixtures: python3 economics/build.py');
        $this->assertSame(['careful', 'average', 'careless'], array_keys($fixtures['teams']));
        $this->assertSame(array_values(array_diff($fixtures['fields'], $fixtures['new_fields'])), ManufacturingCFEngine::FIELD_KEYS);

        foreach ($fixtures['teams'] as $rows) {
            $this->assertCount(11, $rows);
        }
    }
}
