<?php

namespace Tests\Unit\Services\CfPlus;

use App\Models\CfPlusDisclosureSnapshot;
use App\Services\CfPlus\AcquisitionFinancingCalibrationService;
use App\Services\CfPlus\CfPlusTrueWorthService;
use App\Services\CfPlus\CfPlusValuationConfiguration;
use App\Services\CfPlus\ConsultantValuationPrototype;
use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngineInput;
use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngineResult;
use App\Services\Engines\CorporateFinance\Contracts\OpeningFinancialPosition;
use App\Services\Engines\CorporateFinance\ManufacturingCFEngine;
use App\Support\Scenario\CfManufacturingScenarioDefinition;
use Tests\TestCase;

class Phase9AmendmentOneDiagnosticTest extends TestCase
{
    public function test_approved_valuation_configuration_is_exact_and_same_period(): void
    {
        $config = CfPlusValuationConfiguration::approved();

        $this->assertSame(0.12, $config['annual_required_return']);
        $this->assertEqualsWithDelta(0.028737344722080, $config['quarterly_discount_rate'], 0.000000000000001);
        $this->assertSame(0.02, $config['quarterly_growth_cap']);
        $this->assertSame(12, $config['forecast_quarters']);
        $this->assertSame('gordon_growth', $config['terminal_method']);
        $this->assertSame(0.015, $config['annual_terminal_growth']);
        $this->assertEqualsWithDelta(0.003729088938093, $config['quarterly_terminal_growth'], 0.000000000000001);
        $this->assertLessThan($config['quarterly_discount_rate'], $config['quarterly_terminal_growth']);
    }

    public function test_financing_diagnostic_arithmetic_is_cents_safe_and_excludes_overdraft(): void
    {
        $service = new AcquisitionFinancingCalibrationService;
        $result = $service->impliedMultiples(180000000, 2200000000, 100000000, 75000000);

        $this->assertSame(175000000.0, $result['own_funds']);
        $this->assertSame(2025000000.0, $result['required_financing_1_0']);
        $this->assertSame(11.25, $result['implied_multiple_1_0']);
        $this->assertSame(2465000000.0, $result['required_financing_1_2']);
        $this->assertEqualsWithDelta(13.694444444444, $result['implied_multiple_1_2'], 0.000000000001);
        $this->assertSame(3125000000.0, $result['required_financing_1_5']);
        $this->assertEqualsWithDelta(17.361111111111, $result['implied_multiple_1_5'], 0.000000000001);
        $this->assertSame(1435000000.0, $service->fundabilityAtDiagnosticMultiple(100000000, 75000000, 180000000, 7));
    }

    public function test_ten_genuine_engine_profiles_produce_deterministic_matched_diagnostics(): void
    {
        $rows = $this->diagnosticRows();
        $this->assertCount(10, $rows);
        $this->assertSame(array_keys($this->profiles()), array_column($rows, 'target'));

        foreach ($rows as $row) {
            $this->assertCount(3, $row['operating_income']);
            $this->assertCount(3, $row['depreciation']);
            $this->assertCount(3, $row['normalized_quarterly_cash']);
            $this->assertIsFloat($row['annual_normalized_cash']);
            $this->assertIsFloat($row['consultant_value']);
            $this->assertIsFloat($row['true_worth']);
            $this->assertGreaterThanOrEqual(0, $row['overdraft']);
            $this->assertEqualsWithDelta($row['cash'] + $row['unused_credit'], $row['own_funds'], 0.01);
            if ($row['annual_normalized_cash'] > 0) {
                $this->assertGreaterThanOrEqual(0, $row['implied_1_0']);
                $this->assertGreaterThanOrEqual($row['implied_1_0'], $row['implied_1_2']);
                $this->assertGreaterThanOrEqual($row['implied_1_2'], $row['implied_1_5']);
            } else {
                $this->assertNull($row['implied_1_0']);
                $this->assertNull($row['implied_1_2']);
                $this->assertNull($row['implied_1_5']);
            }
        }
        $this->assertSame($rows, $this->diagnosticRows());

        if (getenv('PHASE9_DIAGNOSTIC_OUTPUT') === '1') {
            fwrite(STDOUT, "\nPHASE9_DIAGNOSTIC=".json_encode($rows, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)."\n");
        }
    }

    public function test_aggressive_accounting_amplifies_consultant_more_than_normalized_true_worth(): void
    {
        $rows = collect($this->diagnosticRows())->keyBy('target');
        $conservative = $rows['conservative'];
        $aggressive = $rows['aggressive'];

        $this->assertSame($conservative['operating_signature'], $aggressive['operating_signature']);
        $this->assertNotSame($conservative['elections'], $aggressive['elections']);
        $this->assertGreaterThan($conservative['operating_income'][2], $aggressive['operating_income'][2]);
        $this->assertGreaterThan($conservative['consultant_value'], $aggressive['consultant_value']);
        $this->assertGreaterThan($aggressive['true_worth'], $aggressive['consultant_value']);
        $this->assertGreaterThan(
            $aggressive['true_worth'] - $conservative['true_worth'],
            $aggressive['consultant_value'] - $conservative['consultant_value'],
        );
    }

    public function test_historical_candidate_multiple_sweep_and_bidder_sensitivity_remain_deterministic(): void
    {
        $service = new AcquisitionFinancingCalibrationService;
        $rows = array_values(array_filter($this->diagnosticRows(), fn (array $row): bool => $row['annual_normalized_cash'] > 0 && $row['true_worth'] > 0));
        foreach ($rows as $row) {
            $caps = collect([13.0, 14.0, 15.0])->map(fn (float $multiple): float => $service->fundabilityAtDiagnosticMultiple($row['cash'], $row['unused_credit'], $row['annual_normalized_cash'], $multiple));
            $this->assertLessThan($caps[1], $caps[0]);
            $this->assertLessThan($caps[2], $caps[1]);
        }

        $target = collect($rows)->firstWhere('target', 'aggressive');
        $low = $service->impliedMultiples($target['annual_normalized_cash'], $target['true_worth'], 28763824.68, 50000000.0);
        $median = $service->impliedMultiples($target['annual_normalized_cash'], $target['true_worth'], 137026863.80, 100000000.0);
        $high = $service->impliedMultiples($target['annual_normalized_cash'], $target['true_worth'], 201761761.01, 100000000.0);
        $this->assertGreaterThan($median['implied_multiple_1_2'], $low['implied_multiple_1_2']);
        $this->assertGreaterThan($high['implied_multiple_1_2'], $median['implied_multiple_1_2']);
        $this->assertSame($target['annual_normalized_cash'], $target['annual_normalized_cash']);
        $this->assertFalse(defined(AcquisitionFinancingCalibrationService::class.'::APPROVED_MULTIPLE'));
    }

    public function test_required_return_sensitivity_changes_only_discount_rate_and_reduces_values_monotonically(): void
    {
        $rows = $this->requiredReturnSensitivityRows();
        $this->assertSame([10, 12, 14], array_keys($rows));
        $this->assertEqualsWithDelta(0.024113689084445, $rows[10]['quarterly_discount_rate'], 0.000000000000001);
        $this->assertEqualsWithDelta(0.028737344722080, $rows[12]['quarterly_discount_rate'], 0.000000000000001);
        $this->assertEqualsWithDelta(0.033299484758960, $rows[14]['quarterly_discount_rate'], 0.000000000000001);

        foreach ($rows as $rateRows) {
            $this->assertSame(0.02, $rateRows['quarterly_growth_cap']);
            $this->assertSame(12, $rateRows['forecast_quarters']);
            $this->assertSame('gordon_growth', $rateRows['terminal_method']);
            $this->assertSame(0.015, $rateRows['annual_terminal_growth']);
            $this->assertEqualsWithDelta(0.003729088938093, $rateRows['quarterly_terminal_growth'], 0.000000000000001);
            $this->assertLessThan($rateRows['quarterly_discount_rate'], $rateRows['quarterly_terminal_growth']);
            $this->assertCount(10, $rateRows['samples']);
            foreach ($rateRows['samples'] as $sample) {
                $this->assertSame($rateRows['annual_required_return'], $sample['annual_required_return']);
                $this->assertSame(0.02, $sample['quarterly_growth_cap']);
                $this->assertSame(12, $sample['forecast_quarters']);
                $this->assertSame('gordon_growth', $sample['terminal_method']);
                $this->assertEqualsWithDelta($sample['cash'] + $sample['unused_credit'], $sample['own_funds'], 0.01);
            }
        }

        foreach (array_keys($this->profiles()) as $target) {
            $ten = $this->sensitivitySample($rows[10]['samples'], $target);
            $twelve = $this->sensitivitySample($rows[12]['samples'], $target);
            $fourteen = $this->sensitivitySample($rows[14]['samples'], $target);
            $this->assertSame($ten['annual_normalized_cash'], $twelve['annual_normalized_cash']);
            $this->assertSame($twelve['annual_normalized_cash'], $fourteen['annual_normalized_cash']);
            if ($ten['annual_normalized_cash'] > 0) {
                $this->assertGreaterThan($twelve['consultant_value'], $ten['consultant_value']);
                $this->assertGreaterThan($fourteen['consultant_value'], $twelve['consultant_value']);
                $this->assertGreaterThan($twelve['true_worth'], $ten['true_worth']);
                $this->assertGreaterThan($fourteen['true_worth'], $twelve['true_worth']);
            } else {
                $this->assertGreaterThan(abs($twelve['consultant_value']), abs($ten['consultant_value']));
                $this->assertGreaterThan(abs($fourteen['consultant_value']), abs($twelve['consultant_value']));
                $this->assertGreaterThan(abs($twelve['true_worth']), abs($ten['true_worth']));
                $this->assertGreaterThan(abs($fourteen['true_worth']), abs($twelve['true_worth']));
            }
        }
        $this->assertSame($rows, $this->requiredReturnSensitivityRows());

        if (getenv('PHASE9_SENSITIVITY_OUTPUT') === '1') {
            fwrite(STDOUT, "\nPHASE9_SENSITIVITY=".json_encode($rows, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)."\n");
        }
    }

    public function test_accounting_lesson_survives_all_required_return_candidates(): void
    {
        foreach ($this->requiredReturnSensitivityRows() as $rateRows) {
            $conservative = $this->sensitivitySample($rateRows['samples'], 'conservative');
            $aggressive = $this->sensitivitySample($rateRows['samples'], 'aggressive');
            $this->assertSame($conservative['operating_signature'], $aggressive['operating_signature']);
            $this->assertNotSame($conservative['elections'], $aggressive['elections']);
            $this->assertGreaterThan($conservative['consultant_value'], $aggressive['consultant_value']);
            $this->assertGreaterThan($aggressive['true_worth'], $aggressive['consultant_value']);
            $this->assertGreaterThan(
                $aggressive['true_worth'] - $conservative['true_worth'],
                $aggressive['consultant_value'] - $conservative['consultant_value'],
            );
        }
    }

    public function test_distressed_presentation_proposal_preserves_raw_values_and_zeroes_display_and_capacity(): void
    {
        $service = new AcquisitionFinancingCalibrationService;
        foreach ($this->requiredReturnSensitivityRows() as $rateRows) {
            $distressed = $this->sensitivitySample($rateRows['samples'], 'distressed');
            $presentation = $service->nonPositiveTargetPresentation(
                $distressed['consultant_value'],
                $distressed['true_worth'],
                $distressed['annual_normalized_cash'],
            );
            $this->assertLessThan(0, $presentation['raw_consultant_value']);
            $this->assertLessThan(0, $presentation['raw_true_worth']);
            $this->assertSame(0.0, $presentation['displayed_consultant_value']);
            $this->assertSame(0.0, $presentation['displayed_true_worth']);
            $this->assertSame('NO POSITIVE GOING-CONCERN VALUE', $presentation['viability_status']);
            $this->assertSame(0.0, $presentation['acquisition_financing_capacity']);
        }
    }

    public function test_literal_required_return_oracles_pin_aggressive_and_distressed_results(): void
    {
        $expected = [
            10 => ['consultant' => 3072222556.21, 'true_worth' => 2428216409.73, 'multiple' => 11.841583545305365, 'implied_1_0' => 10.683205415152623, 'implied_1_1' => 11.86736376966853, 'implied_1_2' => 13.051522124233204, 'implied_1_5' => 16.603997187829687, 'distressed_consultant' => -730474767.04, 'distressed_true' => -618474372.04],
            12 => ['consultant' => 2506811343.77, 'true_worth' => 1981084381.04, 'multiple' => 9.661073088206836, 'implied_1_0' => 8.502694958054095, 'implied_1_1' => 9.468802266855272, 'implied_1_2' => 10.434909575705214, 'implied_1_5' => 13.333231502157512, 'distressed_consultant' => -595982724.53, 'distressed_true' => -504603386.63],
            14 => ['consultant' => 2122145814.89, 'true_worth' => 1676895128.24, 'multiple' => 8.177645813693138, 'implied_1_0' => 7.019267683540395, 'implied_1_1' => 7.837032264890203, 'implied_1_2' => 8.654796846288775, 'implied_1_5' => 11.108090590386963, 'distressed_consultant' => -504485637.08, 'distressed_true' => -427135134.12],
        ];

        foreach ($this->requiredReturnSensitivityRows() as $rate => $rateRows) {
            $aggressive = $this->sensitivitySample($rateRows['samples'], 'aggressive');
            $distressed = $this->sensitivitySample($rateRows['samples'], 'distressed');
            $this->assertSame(205058419.8, $aggressive['annual_normalized_cash']);
            $this->assertSame(237535188.9, $aggressive['own_funds']);
            $this->assertSame($expected[$rate]['consultant'], $aggressive['consultant_value']);
            $this->assertSame($expected[$rate]['true_worth'], $aggressive['true_worth']);
            $this->assertEqualsWithDelta($expected[$rate]['multiple'], $aggressive['true_worth_annual_cash_multiple'], 0.000000000001);
            foreach (['implied_1_0', 'implied_1_1', 'implied_1_2', 'implied_1_5'] as $key) {
                $this->assertEqualsWithDelta($expected[$rate][$key], $aggressive[$key], 0.000000000001);
            }
            $this->assertSame(-52294032.56, $distressed['annual_normalized_cash']);
            $this->assertSame($expected[$rate]['distressed_consultant'], $distressed['consultant_value']);
            $this->assertSame($expected[$rate]['distressed_true'], $distressed['true_worth']);
            $this->assertNull($distressed['implied_1_0']);
        }
    }

    public function test_stake_diagnostic_covers_full_and_proportional_financing_with_solved_intervals(): void
    {
        $service = new AcquisitionFinancingCalibrationService;
        $samples = array_values(array_filter($this->diagnosticRows(), fn (array $row): bool => $row['annual_normalized_cash'] > 0));
        $this->assertCount(9, $samples);

        foreach ($samples as $sample) {
            foreach ([0.25, 0.3333, 0.40, 0.45, 0.50, 0.51, 0.55, 0.60, 0.67, 0.75, 1.0] as $stake) {
                foreach ([4.0, 5.0, 6.0] as $multiple) {
                    foreach ([AcquisitionFinancingCalibrationService::FULL_TARGET_FINANCING, AcquisitionFinancingCalibrationService::PROPORTIONAL_STAKE_FINANCING] as $interpretation) {
                        $first = $service->stakeDiagnostic($sample['consultant_value'], $sample['true_worth'], $sample['annual_normalized_cash'], $sample['cash'], $sample['unused_credit'], $stake, $multiple, $interpretation);
                        $second = $service->stakeDiagnostic($sample['consultant_value'], $sample['true_worth'], $sample['annual_normalized_cash'], $sample['cash'], $sample['unused_credit'], $stake, $multiple, $interpretation);
                        $this->assertSame($first, $second);
                        $this->assertSame(round($sample['consultant_value'] * $stake, 2), $first['stake_consultant_value']);
                        $this->assertSame(round($sample['true_worth'] * $stake, 2), $first['stake_true_worth']);
                        $this->assertSame($sample['own_funds'], $first['own_funds']);
                        $expectedCapacity = $sample['annual_normalized_cash'] * $multiple * ($interpretation === AcquisitionFinancingCalibrationService::PROPORTIONAL_STAKE_FINANCING ? $stake : 1);
                        $this->assertSame(round($expectedCapacity, 2), $first['acquisition_financing_capacity']);
                        $this->assertEqualsWithDelta($first['stake_consultant_value'] / $first['maximum_fundable_offer'], $first['consultant_fundable_ratio'], 0.000000000001);
                    }
                }
            }
            foreach ([4.0, 5.0, 6.0] as $multiple) {
                foreach ([AcquisitionFinancingCalibrationService::FULL_TARGET_FINANCING, AcquisitionFinancingCalibrationService::PROPORTIONAL_STAKE_FINANCING] as $interpretation) {
                    $interval = $service->viableStakeInterval($sample['consultant_value'], $sample['true_worth'], $sample['annual_normalized_cash'], $sample['cash'], $sample['unused_credit'], $multiple, $interpretation);
                    if ($interval['interval_exists']) {
                        $this->assertLessThan($interval['maximum_stake'], $interval['minimum_stake']);
                        $this->assertGreaterThan(0, $interval['interval_width']);
                    } else {
                        $this->assertSame(0.0, $interval['interval_width']);
                    }
                }
            }
        }
    }

    public function test_literal_stake_oracle_distinguishes_full_target_from_proportional_capacity(): void
    {
        $service = new AcquisitionFinancingCalibrationService;
        $aggressive = $this->sensitivitySample($this->requiredReturnSensitivityRows()[10]['samples'], 'aggressive');
        $full = $service->stakeDiagnostic($aggressive['consultant_value'], $aggressive['true_worth'], $aggressive['annual_normalized_cash'], $aggressive['cash'], $aggressive['unused_credit'], 0.51, 5.0, AcquisitionFinancingCalibrationService::FULL_TARGET_FINANCING);
        $proportional = $service->stakeDiagnostic($aggressive['consultant_value'], $aggressive['true_worth'], $aggressive['annual_normalized_cash'], $aggressive['cash'], $aggressive['unused_credit'], 0.51, 5.0, AcquisitionFinancingCalibrationService::PROPORTIONAL_STAKE_FINANCING);

        $this->assertSame(1566833503.67, $full['stake_consultant_value']);
        $this->assertSame(1238390368.96, $full['stake_true_worth']);
        $this->assertSame(237535188.9, $full['own_funds']);
        $this->assertSame(1025292099.0, $full['acquisition_financing_capacity']);
        $this->assertSame(1262827287.9, $full['maximum_fundable_offer']);
        $this->assertTrue($full['fundable']);
        $this->assertTrue($full['consultant_separation']);
        $this->assertSame(522898970.49, $proportional['acquisition_financing_capacity']);
        $this->assertSame(760434159.39, $proportional['maximum_fundable_offer']);
        $this->assertFalse($proportional['fundable']);
        $this->assertTrue($proportional['consultant_separation']);
    }

    public function test_horizon_matrix_changes_only_rate_and_horizon_and_preserves_accounting_lesson(): void
    {
        $matrix = $this->fundingModelDiagnostic();
        $this->assertCount(6, $matrix['horizons']);
        foreach ($matrix['horizons'] as $row) {
            $this->assertContains($row['annual_required_return'], [0.10, 0.12]);
            $this->assertContains($row['horizon'], [4, 8, 12]);
            $this->assertSame(0.02, $row['quarterly_growth_cap']);
            $this->assertSame(0.015, $row['annual_terminal_growth']);
            $this->assertSame('gordon_growth', $row['terminal_method']);
            $this->assertCount(9, $row['positive_samples']);
            $this->assertGreaterThan(0, $row['aggressive_consultant_gap']);
            $this->assertGreaterThan(0, $row['aggressive_true_worth_gap']);
            $this->assertGreaterThan($row['aggressive_true_worth_gap'], $row['aggressive_consultant_gap']);
        }
        $this->assertSame($matrix, $this->fundingModelDiagnostic());

        if (getenv('PHASE9_FUNDING_MODEL_OUTPUT') === '1') {
            fwrite(STDOUT, "\nPHASE9_FUNDING_MODEL=".json_encode($matrix, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)."\n");
        }
    }

    public function test_distressed_stake_diagnostic_preserves_negative_raw_values_and_zero_capacity(): void
    {
        $service = new AcquisitionFinancingCalibrationService;
        $distressed = $this->sensitivitySample($this->requiredReturnSensitivityRows()[10]['samples'], 'distressed');
        $presentation = $service->nonPositiveTargetPresentation($distressed['consultant_value'], $distressed['true_worth'], $distressed['annual_normalized_cash']);

        $this->assertSame(-730474767.04, $presentation['raw_consultant_value']);
        $this->assertSame(-618474372.04, $presentation['raw_true_worth']);
        foreach ([0.25, 0.50, 0.51, 1.0] as $stake) {
            $this->assertLessThan(0, $presentation['raw_consultant_value'] * $stake);
            $this->assertLessThan(0, $presentation['raw_true_worth'] * $stake);
            $this->assertSame(0.0, $presentation['displayed_consultant_value'] * $stake);
            $this->assertSame(0.0, $presentation['displayed_true_worth'] * $stake);
            $this->assertSame(0.0, $presentation['acquisition_financing_capacity']);
        }
    }

    public function test_twelve_percent_control_funding_confirmation_applies_revised_acceptance_criteria(): void
    {
        $diagnostic = $this->controlFundingConfirmationDiagnostic();
        $this->assertSame(0.12, $diagnostic['annual_required_return']);
        $this->assertEqualsWithDelta(0.028737344722080, $diagnostic['quarterly_discount_rate'], 0.000000000000001);
        $this->assertSame(0.02, $diagnostic['quarterly_growth_cap']);
        $this->assertSame(12, $diagnostic['forecast_quarters']);
        $this->assertSame('gordon_growth', $diagnostic['terminal_method']);
        $this->assertSame(0.015, $diagnostic['annual_terminal_growth']);
        $this->assertSame(0.51, $diagnostic['stake']);
        $this->assertSame(5.0, $diagnostic['financing_multiple']);
        $this->assertSame(AcquisitionFinancingCalibrationService::FULL_TARGET_FINANCING, $diagnostic['interpretation']);
        $this->assertCount(9, $diagnostic['positive_profiles']);

        $expected = $this->controlFundingLiteralOracle();
        foreach ($diagnostic['positive_profiles'] as $row) {
            $literal = $expected[$row['target']];
            foreach (['full_consultant_value', 'full_true_worth', 'stake_consultant_value', 'stake_true_worth', 'annual_normalized_cash', 'acquisition_financing_capacity', 'bidder_cash', 'unused_credit', 'maximum_fundable_offer', 'dollar_overpayment_headroom'] as $key) {
                $this->assertEqualsWithDelta($literal[$key], $row[$key], 0.01, $row['target'].' '.$key);
            }
            foreach (['fundable_consultant_ratio', 'fundable_true_worth_ratio', 'consultant_true_worth_ratio', 'percentage_overpayment_headroom'] as $key) {
                $this->assertEqualsWithDelta($literal[$key], $row[$key], 0.000000000001, $row['target'].' '.$key);
            }
            $this->assertSame($literal['behavioral_classification'], $row['behavioral_classification']);
            $this->assertSame(round($row['full_consultant_value'] * 0.51, 2), $row['stake_consultant_value']);
            $this->assertSame(round($row['full_true_worth'] * 0.51, 2), $row['stake_true_worth']);
            $this->assertSame(round($row['annual_normalized_cash'] * 5, 2), $row['acquisition_financing_capacity']);
            $this->assertSame(round($row['bidder_cash'] + $row['unused_credit'], 2), $row['own_funds']);
            $this->assertSame(round($row['own_funds'] + $row['acquisition_financing_capacity'], 2), $row['maximum_fundable_offer']);
            $this->assertEqualsWithDelta($row['maximum_fundable_offer'] / $row['stake_consultant_value'], $row['fundable_consultant_ratio'], 0.000000000001);
            $this->assertEqualsWithDelta($row['maximum_fundable_offer'] / $row['stake_true_worth'], $row['fundable_true_worth_ratio'], 0.000000000001);
            $this->assertEqualsWithDelta($row['stake_consultant_value'] / $row['stake_true_worth'], $row['consultant_true_worth_ratio'], 0.000000000001);
            $this->assertSame($row['criterion_a'] && $row['criterion_b'], $row['passes_both']);
        }
        $this->assertSame($diagnostic, $this->controlFundingConfirmationDiagnostic());

        if (getenv('PHASE9_CONTROL_CONFIRMATION_OUTPUT') === '1') {
            fwrite(STDOUT, "\nPHASE9_CONTROL_CONFIRMATION=".json_encode($diagnostic, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)."\n");
        }
    }

    public function test_control_confirmation_pins_key_profiles_distressed_rule_and_bidder_sensitivity(): void
    {
        $diagnostic = $this->controlFundingConfirmationDiagnostic();
        $profiles = collect($diagnostic['positive_profiles'])->keyBy('target');
        $aggressive = $profiles['aggressive'];
        $conservative = $profiles['conservative'];
        $weak = $profiles['weak'];
        $costPressure = $profiles['cost_pressure'];

        $this->assertSame(205058419.8, $aggressive['annual_normalized_cash']);
        $this->assertSame(1025292099.0, $aggressive['acquisition_financing_capacity']);
        $this->assertSame(137535188.9, $aggressive['bidder_cash']);
        $this->assertSame(100000000.0, $aggressive['unused_credit']);
        $this->assertSame(1262827287.9, $aggressive['maximum_fundable_offer']);
        $this->assertSame(1278473785.32, $aggressive['stake_consultant_value']);
        $this->assertSame(1010353034.33, $aggressive['stake_true_worth']);
        $this->assertTrue($aggressive['criterion_a']);
        $this->assertTrue($aggressive['criterion_b']);
        $this->assertTrue($aggressive['passes_both']);

        $this->assertGreaterThan($conservative['stake_true_worth'], $conservative['stake_consultant_value']);
        $this->assertGreaterThan($aggressive['stake_true_worth'], $aggressive['stake_consultant_value']);
        $this->assertGreaterThan(
            $conservative['stake_consultant_value'] - $conservative['stake_true_worth'],
            $aggressive['stake_consultant_value'] - $aggressive['stake_true_worth'],
        );
        $this->assertIsBool($weak['passes_both']);
        $this->assertIsBool($costPressure['passes_both']);

        $distressed = $diagnostic['distressed'];
        $this->assertSame(-595982724.53, $distressed['raw_consultant_value']);
        $this->assertSame(-504603386.63, $distressed['raw_true_worth']);
        $this->assertSame(-303951189.51, $distressed['raw_stake_consultant_value']);
        $this->assertSame(-257347727.18, $distressed['raw_stake_true_worth']);
        $this->assertSame(-52294032.56, $distressed['annual_normalized_cash']);
        $this->assertSame(0.0, $distressed['acquisition_financing_capacity']);
        $this->assertSame(0.0, $distressed['displayed_consultant_value']);
        $this->assertSame(0.0, $distressed['displayed_true_worth']);

        foreach ($diagnostic['bidder_sensitivity'] as $target => $states) {
            $this->assertContains($target, ['aggressive', 'weak', 'strong']);
            $this->assertSame(['low', 'median', 'high'], array_keys($states));
            $this->assertLessThan($states['median']['fundable_consultant_ratio'], $states['low']['fundable_consultant_ratio']);
            $this->assertLessThan($states['high']['fundable_consultant_ratio'], $states['median']['fundable_consultant_ratio']);
            $this->assertLessThan($states['median']['fundable_true_worth_ratio'], $states['low']['fundable_true_worth_ratio']);
            $this->assertLessThan($states['high']['fundable_true_worth_ratio'], $states['median']['fundable_true_worth_ratio']);
        }
    }

    /** @return array<string, array<string, float|string>> */
    private function controlFundingLiteralOracle(): array
    {
        return [
            'conservative' => ['full_consultant_value' => 2335363616.72, 'full_true_worth' => 1953443833.75, 'stake_consultant_value' => 1191035444.53, 'stake_true_worth' => 996256355.21, 'annual_normalized_cash' => 201944439.56, 'acquisition_financing_capacity' => 1009722197.80, 'bidder_cash' => 137026863.80, 'unused_credit' => 100000000.00, 'maximum_fundable_offer' => 1246749061.60, 'fundable_consultant_ratio' => 1.0467774635304705, 'fundable_true_worth_ratio' => 1.2514339859214236, 'consultant_true_worth_ratio' => 1.1955110131056004, 'dollar_overpayment_headroom' => 250492706.39, 'percentage_overpayment_headroom' => 0.25143398592142363, 'behavioral_classification' => 'B. FUNDING NEAR CONSULTANT WITH >=20% TRUE-WORTH HEADROOM'],
            'aggressive' => ['full_consultant_value' => 2506811343.77, 'full_true_worth' => 1981084381.04, 'stake_consultant_value' => 1278473785.32, 'stake_true_worth' => 1010353034.33, 'annual_normalized_cash' => 205058419.80, 'acquisition_financing_capacity' => 1025292099.00, 'bidder_cash' => 137535188.90, 'unused_credit' => 100000000.00, 'maximum_fundable_offer' => 1262827287.90, 'fundable_consultant_ratio' => 0.9877615813482765, 'fundable_true_worth_ratio' => 1.2498871631908588, 'consultant_true_worth_ratio' => 1.265373331775858, 'dollar_overpayment_headroom' => 252474253.57, 'percentage_overpayment_headroom' => 0.2498871631908588, 'behavioral_classification' => 'B. FUNDING NEAR CONSULTANT WITH >=20% TRUE-WORTH HEADROOM'],
            'neutral' => ['full_consultant_value' => 2506811343.77, 'full_true_worth' => 1981084381.04, 'stake_consultant_value' => 1278473785.32, 'stake_true_worth' => 1010353034.33, 'annual_normalized_cash' => 205058419.80, 'acquisition_financing_capacity' => 1025292099.00, 'bidder_cash' => 137535188.90, 'unused_credit' => 100000000.00, 'maximum_fundable_offer' => 1262827287.90, 'fundable_consultant_ratio' => 0.9877615813482765, 'fundable_true_worth_ratio' => 1.2498871631908588, 'consultant_true_worth_ratio' => 1.265373331775858, 'dollar_overpayment_headroom' => 252474253.57, 'percentage_overpayment_headroom' => 0.2498871631908588, 'behavioral_classification' => 'B. FUNDING NEAR CONSULTANT WITH >=20% TRUE-WORTH HEADROOM'],
            'weak' => ['full_consultant_value' => 455581407.15, 'full_true_worth' => 442602073.21, 'stake_consultant_value' => 232346517.65, 'stake_true_worth' => 225727057.34, 'annual_normalized_cash' => 45618874.44, 'acquisition_financing_capacity' => 228094372.20, 'bidder_cash' => 28763824.68, 'unused_credit' => 50000000.00, 'maximum_fundable_offer' => 306858196.88, 'fundable_consultant_ratio' => 1.3206920421430297, 'fundable_true_worth_ratio' => 1.359421420258878, 'consultant_true_worth_ratio' => 1.0293250635878777, 'dollar_overpayment_headroom' => 81131139.54, 'percentage_overpayment_headroom' => 0.3594214202588779, 'behavioral_classification' => 'C. FUNDING MATERIALLY ABOVE CONSULTANT'],
            'strong' => ['full_consultant_value' => 3711742472.57, 'full_true_worth' => 2884787064.60, 'stake_consultant_value' => 1892988661.01, 'stake_true_worth' => 1471241402.95, 'annual_normalized_cash' => 298712732.96, 'acquisition_financing_capacity' => 1493563664.80, 'bidder_cash' => 201761761.01, 'unused_credit' => 100000000.00, 'maximum_fundable_offer' => 1795325425.81, 'fundable_consultant_ratio' => 0.9484079132582379, 'fundable_true_worth_ratio' => 1.2202792976123265, 'consultant_true_worth_ratio' => 1.2866608139319289, 'dollar_overpayment_headroom' => 324084022.86, 'percentage_overpayment_headroom' => 0.22027929761232645, 'behavioral_classification' => 'D. FUNDING MATERIALLY BELOW CONSULTANT'],
            'growth' => ['full_consultant_value' => 2632002616.92, 'full_true_worth' => 2074978471.10, 'stake_consultant_value' => 1342321334.63, 'stake_true_worth' => 1058239020.26, 'annual_normalized_cash' => 214789040.92, 'acquisition_financing_capacity' => 1073945204.60, 'bidder_cash' => 143635241.36, 'unused_credit' => 100000000.00, 'maximum_fundable_offer' => 1317580445.96, 'fundable_consultant_ratio' => 0.9815685797195352, 'fundable_true_worth_ratio' => 1.245068855650666, 'consultant_true_worth_ratio' => 1.2684481567313626, 'dollar_overpayment_headroom' => 259341425.70, 'percentage_overpayment_headroom' => 0.24506885565066594, 'behavioral_classification' => 'B. FUNDING NEAR CONSULTANT WITH >=20% TRUE-WORTH HEADROOM'],
            'premium' => ['full_consultant_value' => 2449048555.71, 'full_true_worth' => 1937761974.66, 'stake_consultant_value' => 1249014763.41, 'stake_true_worth' => 988258607.08, 'annual_normalized_cash' => 200568744.84, 'acquisition_financing_capacity' => 1002843724.20, 'bidder_cash' => 134201083.25, 'unused_credit' => 100000000.00, 'maximum_fundable_offer' => 1237044807.45, 'fundable_consultant_ratio' => 0.9904164816056135, 'fundable_true_worth_ratio' => 1.2517420021314933, 'consultant_true_worth_ratio' => 1.263854171835097, 'dollar_overpayment_headroom' => 248786200.37, 'percentage_overpayment_headroom' => 0.25174200213149334, 'behavioral_classification' => 'B. FUNDING NEAR CONSULTANT WITH >=20% TRUE-WORTH HEADROOM'],
            'cost_pressure' => ['full_consultant_value' => 1260632842.01, 'full_true_worth' => 1147347033.31, 'stake_consultant_value' => 642922749.43, 'stake_true_worth' => 585146986.99, 'annual_normalized_cash' => 118404987.04, 'acquisition_financing_capacity' => 592024935.20, 'bidder_cash' => 80556873.48, 'unused_credit' => 75000000.00, 'maximum_fundable_offer' => 747581808.68, 'fundable_consultant_ratio' => 1.1627863679466752, 'fundable_true_worth_ratio' => 1.2775966129904655, 'consultant_true_worth_ratio' => 1.098737178392046, 'dollar_overpayment_headroom' => 162434821.69, 'percentage_overpayment_headroom' => 0.27759661299046545, 'behavioral_classification' => 'C. FUNDING MATERIALLY ABOVE CONSULTANT'],
            'high_volume' => ['full_consultant_value' => 2982910698.87, 'full_true_worth' => 2338161029.51, 'stake_consultant_value' => 1521284456.42, 'stake_true_worth' => 1192462125.05, 'annual_normalized_cash' => 242063701.72, 'acquisition_financing_capacity' => 1210318508.60, 'bidder_cash' => 162693911.24, 'unused_credit' => 100000000.00, 'maximum_fundable_offer' => 1473012419.84, 'fundable_consultant_ratio' => 0.9682688951587677, 'fundable_true_worth_ratio' => 1.2352697741056022, 'consultant_true_worth_ratio' => 1.275750755065879, 'dollar_overpayment_headroom' => 280550294.79, 'percentage_overpayment_headroom' => 0.23526977410560224, 'behavioral_classification' => 'B. FUNDING NEAR CONSULTANT WITH >=20% TRUE-WORTH HEADROOM'],
        ];
    }

    /** @return array<string, mixed> */
    private function controlFundingConfirmationDiagnostic(): array
    {
        $service = new AcquisitionFinancingCalibrationService;
        $rateRows = $this->requiredReturnSensitivityRows()[12];
        $positive = [];
        foreach ($rateRows['samples'] as $source) {
            if ($source['annual_normalized_cash'] <= 0) {
                continue;
            }
            $row = $service->controlFundingConfirmation($source['consultant_value'], $source['true_worth'], $source['annual_normalized_cash'], $source['cash'], $source['unused_credit']);
            $positive[] = ['target' => $source['target'], 'bidder_cash' => $source['cash'], 'unused_credit' => $source['unused_credit']] + $row;
        }
        $distressedSource = $this->sensitivitySample($rateRows['samples'], 'distressed');
        $distressedPresentation = $service->nonPositiveTargetPresentation($distressedSource['consultant_value'], $distressedSource['true_worth'], $distressedSource['annual_normalized_cash']);
        $bidderStates = [
            'low' => ['cash' => 28763824.68, 'credit' => 50000000.0],
            'median' => ['cash' => 137026863.80, 'credit' => 100000000.0],
            'high' => ['cash' => 201761761.01, 'credit' => 100000000.0],
        ];
        $sensitivity = [];
        foreach (['aggressive', 'weak', 'strong'] as $target) {
            $source = $this->sensitivitySample($rateRows['samples'], $target);
            foreach ($bidderStates as $state => $funds) {
                $sensitivity[$target][$state] = $service->controlFundingConfirmation($source['consultant_value'], $source['true_worth'], $source['annual_normalized_cash'], $funds['cash'], $funds['credit']);
            }
        }

        return [
            'annual_required_return' => 0.12,
            'quarterly_discount_rate' => CfPlusValuationConfiguration::quarterlyRate(0.12),
            'quarterly_growth_cap' => 0.02,
            'forecast_quarters' => 12,
            'terminal_method' => 'gordon_growth',
            'annual_terminal_growth' => 0.015,
            'stake' => 0.51,
            'financing_multiple' => 5.0,
            'interpretation' => AcquisitionFinancingCalibrationService::FULL_TARGET_FINANCING,
            'positive_profiles' => $positive,
            'distressed' => $distressedPresentation + [
                'raw_stake_consultant_value' => round($distressedSource['consultant_value'] * 0.51, 2),
                'raw_stake_true_worth' => round($distressedSource['true_worth'] * 0.51, 2),
                'annual_normalized_cash' => $distressedSource['annual_normalized_cash'],
            ],
            'bidder_sensitivity' => $sensitivity,
        ];
    }

    /** @return array<string, mixed> */
    private function fundingModelDiagnostic(): array
    {
        $prototype = new ConsultantValuationPrototype;
        $financing = new AcquisitionFinancingCalibrationService;
        $sourceRows = $this->diagnosticRows();
        $positive = array_values(array_filter($sourceRows, fn (array $row): bool => $row['annual_normalized_cash'] > 0));
        $stakeRows = [];
        $intervals = [];
        foreach ([AcquisitionFinancingCalibrationService::FULL_TARGET_FINANCING, AcquisitionFinancingCalibrationService::PROPORTIONAL_STAKE_FINANCING] as $interpretation) {
            foreach ([4.0, 5.0, 6.0] as $multiple) {
                foreach ([0.25, 0.3333, 0.40, 0.45, 0.50, 0.51, 0.55, 0.60, 0.67, 0.75, 1.0] as $stake) {
                    foreach ($positive as $sample) {
                        $stakeRows[] = ['target' => $sample['target']] + $financing->stakeDiagnostic($sample['consultant_value'], $sample['true_worth'], $sample['annual_normalized_cash'], $sample['cash'], $sample['unused_credit'], $stake, $multiple, $interpretation);
                    }
                }
                foreach ($positive as $sample) {
                    $intervals[] = ['target' => $sample['target'], 'financing_multiple' => $multiple] + $financing->viableStakeInterval($sample['consultant_value'], $sample['true_worth'], $sample['annual_normalized_cash'], $sample['cash'], $sample['unused_credit'], $multiple, $interpretation);
                }
            }
        }

        $horizons = [];
        foreach ([0.10, 0.12] as $rate) {
            foreach ([4, 8, 12] as $horizon) {
                $samples = [];
                foreach ($positive as $source) {
                    $consultant = $prototype->evaluateSeries($source['operating_income'], $rate, 0.02, 0.015, $horizon);
                    $trueWorth = $prototype->evaluateSeries($source['normalized_quarterly_cash'], $rate, 0.02, 0.015, $horizon);
                    $implied = $financing->impliedMultiples($source['annual_normalized_cash'], $trueWorth['consultant_value'], $source['cash'], $source['unused_credit']);
                    $ratios = [];
                    foreach ([4.0, 5.0, 6.0] as $multiple) {
                        $fundable = $financing->fundabilityAtDiagnosticMultiple($source['cash'], $source['unused_credit'], $source['annual_normalized_cash'], $multiple);
                        $ratios[(string) $multiple] = $consultant['consultant_value'] / $fundable;
                    }
                    $samples[] = [
                        'target' => $source['target'],
                        'annual_normalized_cash' => $source['annual_normalized_cash'],
                        'consultant_value' => $consultant['consultant_value'],
                        'true_worth' => $trueWorth['consultant_value'],
                        'true_worth_cash_multiple' => $trueWorth['consultant_value'] / $source['annual_normalized_cash'],
                        'consultant_cash_multiple' => $consultant['consultant_value'] / $source['annual_normalized_cash'],
                        'implied_financing_1_0' => $implied['implied_multiple_1_0'],
                        'consultant_fundable' => $ratios,
                    ];
                }
                $byTarget = collect($samples)->keyBy('target');
                $horizons[] = [
                    'annual_required_return' => $rate,
                    'quarterly_discount_rate' => CfPlusValuationConfiguration::quarterlyRate($rate),
                    'horizon' => $horizon,
                    'quarterly_growth_cap' => 0.02,
                    'annual_terminal_growth' => 0.015,
                    'terminal_method' => 'gordon_growth',
                    'positive_samples' => $samples,
                    'aggressive_consultant_gap' => $byTarget['aggressive']['consultant_value'] - $byTarget['conservative']['consultant_value'],
                    'aggressive_true_worth_gap' => $byTarget['aggressive']['true_worth'] - $byTarget['conservative']['true_worth'],
                ];
            }
        }

        return ['stake_rows' => $stakeRows, 'stake_intervals' => $intervals, 'horizons' => $horizons];
    }

    /** @return array<int, array<string, mixed>> */
    private function requiredReturnSensitivityRows(): array
    {
        $prototype = new ConsultantValuationPrototype;
        $financing = new AcquisitionFinancingCalibrationService;
        $rows = [];
        foreach (['10' => 0.10, '12' => 0.12, '14' => 0.14] as $label => $rate) {
            $samples = [];
            foreach ($this->diagnosticRows() as $source) {
                $consultant = $prototype->evaluateSeries($source['operating_income'], $rate, 0.02, 0.015, 12);
                $trueWorth = $prototype->evaluateSeries($source['normalized_quarterly_cash'], $rate, 0.02, 0.015, 12);
                $multiples = $financing->impliedMultiples($source['annual_normalized_cash'], $trueWorth['consultant_value'], $source['cash'], $source['unused_credit']);
                $samples[] = array_merge($source, [
                    'annual_required_return' => $rate,
                    'quarterly_growth_cap' => 0.02,
                    'forecast_quarters' => 12,
                    'terminal_method' => 'gordon_growth',
                    'consultant_value' => $consultant['consultant_value'],
                    'true_worth' => $trueWorth['consultant_value'],
                    'consultant_true_worth_ratio' => $trueWorth['consultant_value'] == 0.0 ? null : $consultant['consultant_value'] / $trueWorth['consultant_value'],
                    'true_worth_annual_cash_multiple' => $source['annual_normalized_cash'] > 0 ? $trueWorth['consultant_value'] / $source['annual_normalized_cash'] : null,
                    'own_funds_annual_cash_multiple' => $source['annual_normalized_cash'] > 0 ? $multiples['own_funds'] / $source['annual_normalized_cash'] : null,
                    'implied_1_0' => $multiples['implied_multiple_1_0'],
                    'implied_1_1' => $multiples['implied_multiple_1_1'],
                    'implied_1_2' => $multiples['implied_multiple_1_2'],
                    'implied_1_5' => $multiples['implied_multiple_1_5'],
                ]);
            }
            $rows[$label] = [
                'annual_required_return' => $rate,
                'quarterly_discount_rate' => CfPlusValuationConfiguration::quarterlyRate($rate),
                'quarterly_growth_cap' => 0.02,
                'forecast_quarters' => 12,
                'terminal_method' => 'gordon_growth',
                'annual_terminal_growth' => 0.015,
                'quarterly_terminal_growth' => CfPlusValuationConfiguration::quarterlyRate(0.015),
                'samples' => $samples,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $samples
     * @return array<string, mixed>
     */
    private function sensitivitySample(array $samples, string $target): array
    {
        foreach ($samples as $sample) {
            if ($sample['target'] === $target) {
                return $sample;
            }
        }

        throw new \RuntimeException("Missing sensitivity sample [{$target}].");
    }

    /** @return array<int, array<string, mixed>> */
    private function diagnosticRows(): array
    {
        $prototype = new ConsultantValuationPrototype;
        $trueWorthService = new CfPlusTrueWorthService($prototype);
        $financing = new AcquisitionFinancingCalibrationService;
        $rows = [];
        foreach ($this->profiles() as $name => $profile) {
            $results = $this->chain($profile);
            $statements = array_map(fn (ManufacturingEngineResult $result, int $quarter): array => [
                'quarter_number' => $quarter,
                'income_statement' => $result->incomeStatement,
                'cash_flow_statement' => $result->cashFlowStatement,
            ], $results, array_keys($results));
            $snapshot = new CfPlusDisclosureSnapshot(['published_statements' => $statements]);
            $consultant = $prototype->evaluateApproved($snapshot);
            $trueWorth = $trueWorthService->calculateFromStatements($statements, 0.25);
            $last = $results[3];
            $cash = (float) $last->fields['bs_cash'];
            $unusedCredit = max(0, (float) $last->fields['n5_limit'] - (float) $last->fields['n5_balance']);
            $multiples = $financing->impliedMultiples($trueWorth['annual_normalized_recurring_cash'], $trueWorth['true_worth'], $cash, $unusedCredit);
            $rows[] = [
                'target' => $name,
                'operating_profile' => $profile['operating_profile'],
                'accounting_profile' => $profile['accounting_profile'],
                'operating_signature' => $profile['operating_signature'],
                'elections' => $profile['elections'],
                'operating_income' => array_map(fn (ManufacturingEngineResult $result): float => (float) $result->incomeStatement['operating_income'], $results),
                'depreciation' => array_map(fn (ManufacturingEngineResult $result): float => (float) $result->fields['cf_depr'], $results),
                'normalized_quarterly_cash' => $trueWorth['normalized_quarterly_recurring_cash'],
                'annual_normalized_cash' => $trueWorth['annual_normalized_recurring_cash'],
                'consultant_value' => $consultant['consultant_value'],
                'true_worth' => $trueWorth['true_worth'],
                'cash' => round($cash, 2),
                'unused_credit' => round($unusedCredit, 2),
                'own_funds' => $multiples['own_funds'],
                'overdraft' => (float) $last->fields['bs_bank_overdraft'],
                'implied_1_0' => $multiples['implied_multiple_1_0'],
                'implied_1_2' => $multiples['implied_multiple_1_2'],
                'implied_1_5' => $multiples['implied_multiple_1_5'],
            ];
        }

        return $rows;
    }

    /** @return array<string, array<string, mixed>> */
    private function profiles(): array
    {
        $fifo = CfManufacturingScenarioDefinition::fixedWarmupPayload('notes_elections');
        $aggressive = [...$fifo, 'depreciation_method' => 'straight_line', 'bad_debt_method' => 'percentage_of_receivables'];
        $conservative = [...$fifo, 'depreciation_method' => 'double_declining_balance', 'bad_debt_method' => 'percentage_of_sales'];

        return [
            'conservative' => $this->profile('baseline', 'conservative', 500, 850, 1.0, 0.05, 'BASIC', 'SUP001', 'STANDARD', $conservative),
            'aggressive' => $this->profile('baseline', 'aggressive', 500, 850, 1.0, 0.05, 'BASIC', 'SUP001', 'STANDARD', $aggressive),
            'neutral' => $this->profile('baseline', 'neutral', 500, 850, 1.0, 0.05, 'BASIC', 'SUP001', 'STANDARD', $fifo),
            'weak' => $this->profile('weak', 'neutral', 270, 350, 1.0, 0.05, 'BASIC', 'SUP001', 'STANDARD', $fifo),
            'distressed' => $this->profile('distressed', 'neutral', 225, 300, 0.70, 0.15, 'FULL', 'SUP002', 'AIR', $fifo),
            'strong' => $this->profile('strong', 'neutral', 600, 950, 1.15, 0.04, 'BASIC', 'SUP001', 'STANDARD', $fifo),
            'growth' => $this->profile('growth', 'neutral', 540, 900, 1.10, 0.08, 'BASIC', 'SUP005', 'EXPRESS', $fifo),
            'premium' => $this->profile('premium', 'aggressive', 650, 1000, 0.90, 0.06, 'FULL', 'SUP003', 'AIR', $aggressive),
            'cost_pressure' => $this->profile('cost_pressure', 'conservative', 450, 760, 1.0, 0.07, 'BASIC', 'SUP002', 'EXPRESS', $conservative),
            'high_volume' => $this->profile('high_volume', 'neutral', 480, 800, 1.25, 0.05, 'BASIC', 'SUP004', 'STANDARD', $fifo),
        ];
    }

    /**
     * @param  array<string, mixed>  $elections
     * @return array<string, mixed>
     */
    private function profile(string $operating, string $accounting, float $p1, float $p2, float $scale, float $marketing, string $quality, string $supplier, string $logistics, array $elections): array
    {
        return ['operating_profile' => $operating, 'accounting_profile' => $accounting, 'operating_signature' => [$p1, $p2, $scale, $marketing, $quality, $supplier, $logistics], 'p1' => $p1, 'p2' => $p2, 'scale' => $scale, 'marketing' => $marketing, 'quality' => $quality, 'supplier' => $supplier, 'logistics' => $logistics, 'elections' => $elections];
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<int, ManufacturingEngineResult>
     */
    private function chain(array $profile): array
    {
        $parameters = CfManufacturingScenarioDefinition::parameters();
        $parameters['p1_base_price'] = $profile['p1'];
        $parameters['p2_base_price'] = $profile['p2'];
        $opening = OpeningFinancialPosition::fromScenarioParameters($parameters);
        $decisions = $this->decisions($profile);
        $results = [];
        foreach (range(1, 3) as $quarter) {
            $results[$quarter] = (new ManufacturingCFEngine)->calculate(new ManufacturingEngineInput($quarter, $decisions, $parameters, $opening));
            $opening = OpeningFinancialPosition::fromPriorEngineResult($results[$quarter]->fields);
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, array<string, mixed>>
     */
    private function decisions(array $profile): array
    {
        $demand = array_map(fn (int $value): int => (int) round($value * $profile['scale']), CfManufacturingScenarioDefinition::q0ForecastingPayload());
        $p1 = $demand['forecast_p1_r1'] + $demand['forecast_p1_r2'] + $demand['forecast_p1_r3'];
        $p2 = $demand['forecast_p2_r1'] + $demand['forecast_p2_r2'] + $demand['forecast_p2_r3'];

        return [
            'forecasting' => $demand + ['forecast_confidence' => 'medium'],
            'sourcing' => ['primary_supplier' => $profile['supplier'], 'primary_alloc' => 1.0, 'secondary_supplier' => 'NONE'],
            'pricing' => ['price_p1_r1' => $profile['p1'], 'price_p1_r2' => $profile['p1'], 'price_p1_r3' => $profile['p1'], 'price_p2_r1' => $profile['p2'], 'price_p2_r2' => $profile['p2'], 'price_p2_r3' => $profile['p2'], 'discount_policy' => 'none'],
            'production' => ['p1_production' => $p1, 'p2_production' => $p2, 'shifts' => 1, 'postponed_flag' => 'NO', 'postponed_pct' => 0],
            'marketing' => ['marketing_budget_pct' => $profile['marketing'], 'marketing_alloc_champions' => 0.50, 'marketing_alloc_growth' => 0.30, 'marketing_alloc_at_risk' => 0.15, 'marketing_alloc_other' => 0.05],
            'quality' => ['inspection_level' => $profile['quality']],
            'logistics' => ['logistics_mode' => $profile['logistics']],
            'notes_elections' => $profile['elections'],
            'certification' => CfManufacturingScenarioDefinition::fixedWarmupPayload('certification'),
        ];
    }
}
