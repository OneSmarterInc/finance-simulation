<?php

namespace Tests\Unit\Services\Engines\CorporateFinance;

use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngineInput;
use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngineResult;
use App\Services\Engines\CorporateFinance\Contracts\OpeningFinancialPosition;
use App\Services\Engines\CorporateFinance\ManufacturingCFEngine;
use App\Support\Scenario\CfManufacturingScenarioDefinition;
use Tests\TestCase;

/**
 * Secondary mixed-provenance regression guard only.
 *
 * This is not executed legacy parity evidence. Its 68 non-comparable fields are
 * documentation-only; the executed legacy fixture is the authoritative oracle.
 */
class ManufacturingCFEngineReconstructedMixedProvenanceNonAuthoritativeTest extends TestCase
{
    private const REBUILT_ONLY_OVERDRAFT_FIELDS = [
        'bs_bank_overdraft' => 0.0,
        'cf_overdraft_chg' => 0.0,
        'n5_overdraft' => 0.0,
        'n5_shortfall_indicator' => 0.0,
    ];

    public function test_reconstructed_mixed_provenance_non_authoritative_fixture_has_no_unexplained_differences(): void
    {
        $fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/CorporateFinance/phase3-reconstructed-mixed-provenance-non-authoritative.json')), true, flags: JSON_THROW_ON_ERROR);
        $result = $this->calculate();
        $classifiedFieldPaths = [];

        foreach ($fixture['independently_source_derived_expected_values'] as $line) {
            $field = $this->fieldName($line['field_path']);
            $classifiedFieldPaths[] = $line['field_path'];

            $this->assertSame($line['legacy_value'], $line['rebuilt_expected_value'], "Source-derived fixture values differ for {$field}");
            $this->assertSame(round((float) $line['rebuilt_expected_value'], 2), round($result->fields[$field], 2), "Source-derived result differs for {$field}");
            $this->assertContains($line['comparison_rule'], ['strict_monetary_equality_after_rounding', 'strict_numeric_equality']);
        }

        foreach ($fixture['reconstructed_using_rebuilt_output_and_known_deltas'] as $line) {
            $field = $this->fieldName($line['field_path']);
            $classifiedFieldPaths[] = $line['field_path'];
            $authorizedDifference = $line['gate_a_effect'] + $line['ppe_scaling_effect'] + $line['technology_dedup_effect'];

            $this->assertSame(round((float) $line['rebuilt_expected_value'], 2), round($result->fields[$field], 2), "Reconciled rebuilt field differs for {$field}");
            $this->assertEqualsWithDelta($line['total_expected_difference'], $authorizedDifference, 0.01, "Authorized effects do not sum for {$field}");
            $this->assertEqualsWithDelta($line['actual_difference'], $line['rebuilt_expected_value'] - $line['legacy_value'], 0.01, "Actual fixture difference is wrong for {$field}");
            $this->assertEqualsWithDelta($line['actual_difference'], $authorizedDifference, 0.01, "Unexplained parity remainder for {$field}");
        }

        foreach ($fixture['non_comparable'] as $line) {
            $classifiedFieldPaths[] = $line['field_path'];
            $this->assertNotSame('', $line['reason'], "Non-comparable field must document reason {$line['field_path']}");
        }
        $this->assertCount(68, $fixture['non_comparable']);
        $this->assertSame(
            'Secondary reconstructed mixed-provenance regression guard only. This is not executed legacy parity evidence. The 68 non-comparable fields are documentation-only.',
            $fixture['non_authoritative_notice'],
        );

        foreach (self::REBUILT_ONLY_OVERDRAFT_FIELDS as $field => $expected) {
            $classifiedFieldPaths[] = "fields.{$field}";
            $this->assertSame($expected, $result->fields[$field], "Rebuilt-only overdraft field changed: {$field}");
        }

        $resultFieldPaths = array_map(fn (string $field): string => "fields.{$field}", array_keys($result->fields));
        sort($classifiedFieldPaths);
        sort($resultFieldPaths);

        $this->assertSame($resultFieldPaths, array_values(array_unique($classifiedFieldPaths)));
        $this->assertArrayNotHasKey('n4_cwip', $result->fields);
        $this->assertSame([], array_diff(array_keys($fixture), [
            'non_authoritative_notice',
            'generation',
            'legacy_inputs',
            'rebuilt_inputs',
            'independently_source_derived_expected_values',
            'reconstructed_using_rebuilt_output_and_known_deltas',
            'non_comparable',
            'depreciation_cascade',
        ]));

        $cascade = $fixture['depreciation_cascade'];
        $this->assertSame(750000, $cascade['manufacturing']['difference']);
        $this->assertSame(375000, $cascade['technology']['difference']);
        $this->assertSame(281250, $cascade['distribution_center']['difference']);
        $this->assertSame(1406250, $cascade['total_depreciation_difference']);
    }

    private function fieldName(string $fieldPath): string
    {
        return str_replace('fields.', '', $fieldPath);
    }

    private function calculate(): ManufacturingEngineResult
    {
        $parameters = CfManufacturingScenarioDefinition::parameters();
        // Secondary reconstructed evidence remains tied to the historical legacy prices.
        $parameters['p1_base_price'] = 500;
        $parameters['p2_base_price'] = 850;

        foreach (array_keys($parameters) as $key) {
            if (str_starts_with($key, 'starting_')) {
                unset($parameters[$key]);
            }
        }

        $decisions = [
            'forecasting' => [
                'forecast_p1_r1' => 30000,
                'forecast_p1_r2' => 40000,
                'forecast_p1_r3' => 60000,
                'forecast_p2_r1' => 27000,
                'forecast_p2_r2' => 18000,
                'forecast_p2_r3' => 16000,
                'forecast_confidence' => 'medium',
            ],
            'sourcing' => [
                'primary_supplier' => 'SUP001',
                'primary_alloc' => 1.0,
                'secondary_supplier' => 'NONE',
            ],
            'pricing' => [
                'price_p1_r1' => 500,
                'price_p1_r2' => 500,
                'price_p1_r3' => 500,
                'price_p2_r1' => 850,
                'price_p2_r2' => 850,
                'price_p2_r3' => 850,
                'discount_policy' => 'none',
            ],
            'production' => [
                'p1_production' => 125000,
                'p2_production' => 60000,
                'shifts' => 1,
                'postponed_flag' => 'NO',
                'postponed_pct' => 0,
            ],
            'marketing' => [
                'marketing_budget_pct' => 0.05,
                'marketing_alloc_champions' => 0.50,
                'marketing_alloc_growth' => 0.30,
                'marketing_alloc_at_risk' => 0.15,
                'marketing_alloc_other' => 0.05,
            ],
            'quality' => [
                'inspection_level' => 'BASIC',
            ],
            'logistics' => [
                'logistics_mode' => 'STANDARD',
            ],
            'notes_elections' => [
                'inventory_method' => 'fifo',
                'depreciation_method' => 'straight_line',
                'shipping_terms' => 'fob_shipping_point',
                'bad_debt_method' => 'percentage_of_receivables',
                'warranty_method' => 'percentage_of_sales',
                'excise_tax_treatment' => 'cogs_inclusion',
            ],
            'certification' => [
                'attestation_scope' => 'all_statements_attested',
            ],
        ];

        return (new ManufacturingCFEngine)->calculate(new ManufacturingEngineInput(
            quarterNumber: 1,
            decisions: $decisions,
            parameters: $parameters,
            openingPosition: OpeningFinancialPosition::fromScenarioParameters($parameters),
        ));
    }
}
