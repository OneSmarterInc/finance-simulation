<?php

namespace App\Services\CfPlus;

use InvalidArgumentException;

final class AcquisitionFinancingCalibrationService
{
    public const FULL_TARGET_FINANCING = 'full_target';

    public const PROPORTIONAL_STAKE_FINANCING = 'proportional_stake';

    /** @return array<string, float|null> */
    public function impliedMultiples(float $annualNormalizedCash, float $trueWorth, float $bidderCash, float $unusedCommittedCredit): array
    {
        if ($bidderCash < 0 || $unusedCommittedCredit < 0) {
            throw new InvalidArgumentException('Financing calibration bidder funding inputs must be nonnegative.');
        }
        $ownFunds = round($bidderCash + $unusedCommittedCredit, 2);
        $result = ['own_funds' => $ownFunds];
        foreach ([1.0, 1.1, 1.2, 1.5] as $level) {
            $required = round(max(0, ($trueWorth * $level) - $ownFunds), 2);
            $key = str_replace('.', '_', number_format($level, 1));
            $result["required_financing_{$key}"] = $required;
            $result["implied_multiple_{$key}"] = $annualNormalizedCash > 0 ? $required / $annualNormalizedCash : null;
        }

        return $result;
    }

    public function fundabilityAtDiagnosticMultiple(float $bidderCash, float $unusedCommittedCredit, float $annualNormalizedCash, float $diagnosticMultiple): float
    {
        if ($diagnosticMultiple < 0) {
            throw new InvalidArgumentException('Diagnostic multiple must be nonnegative.');
        }

        return round($bidderCash + $unusedCommittedCredit + (max(0, $annualNormalizedCash) * $diagnosticMultiple), 2);
    }

    /** @return array{raw_consultant_value: float, raw_true_worth: float, displayed_consultant_value: float, displayed_true_worth: float, viability_status: string, acquisition_financing_capacity: float} */
    public function nonPositiveTargetPresentation(float $rawConsultantValue, float $rawTrueWorth, float $annualNormalizedCash): array
    {
        if ($annualNormalizedCash > 0) {
            throw new InvalidArgumentException('The non-positive target diagnostic requires non-positive normalized annual cash.');
        }

        return [
            'raw_consultant_value' => $rawConsultantValue,
            'raw_true_worth' => $rawTrueWorth,
            'displayed_consultant_value' => max(0.0, $rawConsultantValue),
            'displayed_true_worth' => max(0.0, $rawTrueWorth),
            'viability_status' => 'NO POSITIVE GOING-CONCERN VALUE',
            'acquisition_financing_capacity' => 0.0,
        ];
    }

    /**
     * @return array{
     *     interpretation: string,
     *     stake: float,
     *     full_consultant_value: float,
     *     full_true_worth: float,
     *     annual_normalized_cash: float,
     *     financing_multiple: float,
     *     acquisition_financing_capacity: float,
     *     own_funds: float,
     *     maximum_fundable_offer: float,
     *     stake_consultant_value: float,
     *     stake_true_worth: float,
     *     fundable_true_worth_ratio: float,
     *     consultant_fundable_ratio: float,
     *     implied_financing_multiple: float,
     *     fundable: bool,
     *     consultant_separation: bool
     * }
     */
    public function stakeDiagnostic(float $fullConsultantValue, float $fullTrueWorth, float $annualNormalizedCash, float $bidderCash, float $unusedCommittedCredit, float $stake, float $financingMultiple, string $interpretation): array
    {
        if ($fullTrueWorth <= 0 || $annualNormalizedCash <= 0 || $bidderCash < 0 || $unusedCommittedCredit < 0 || $stake <= 0 || $stake > 1 || $financingMultiple < 0) {
            throw new InvalidArgumentException('Stake calibration requires positive target values, a stake in (0, 1], and nonnegative funding inputs.');
        }
        if (! in_array($interpretation, [self::FULL_TARGET_FINANCING, self::PROPORTIONAL_STAKE_FINANCING], true)) {
            throw new InvalidArgumentException('Unknown stake-financing interpretation.');
        }

        $ownFunds = round($bidderCash + $unusedCommittedCredit, 2);
        $financingCashBase = $interpretation === self::PROPORTIONAL_STAKE_FINANCING ? $annualNormalizedCash * $stake : $annualNormalizedCash;
        $capacity = round($financingCashBase * $financingMultiple, 2);
        $fundable = round($ownFunds + $capacity, 2);
        $stakeConsultant = round($fullConsultantValue * $stake, 2);
        $stakeTrueWorth = round($fullTrueWorth * $stake, 2);
        $requiredFinancing = round(max(0, $stakeTrueWorth - $ownFunds), 2);

        return [
            'interpretation' => $interpretation,
            'stake' => $stake,
            'full_consultant_value' => $fullConsultantValue,
            'full_true_worth' => $fullTrueWorth,
            'annual_normalized_cash' => $annualNormalizedCash,
            'financing_multiple' => $financingMultiple,
            'acquisition_financing_capacity' => $capacity,
            'own_funds' => $ownFunds,
            'maximum_fundable_offer' => $fundable,
            'stake_consultant_value' => $stakeConsultant,
            'stake_true_worth' => $stakeTrueWorth,
            'fundable_true_worth_ratio' => $fundable / $stakeTrueWorth,
            'consultant_fundable_ratio' => $stakeConsultant / $fundable,
            'implied_financing_multiple' => $requiredFinancing / $financingCashBase,
            'fundable' => $fundable >= $stakeTrueWorth,
            'consultant_separation' => ($stakeConsultant / $fundable) > 1.15,
        ];
    }

    /** @return array{interpretation: string, minimum_stake: float|null, maximum_stake: float|null, interval_exists: bool, interval_width: float} */
    public function viableStakeInterval(float $fullConsultantValue, float $fullTrueWorth, float $annualNormalizedCash, float $bidderCash, float $unusedCommittedCredit, float $financingMultiple, string $interpretation): array
    {
        if ($fullConsultantValue <= 0 || $fullTrueWorth <= 0 || $annualNormalizedCash <= 0 || $bidderCash < 0 || $unusedCommittedCredit < 0 || $financingMultiple < 0) {
            throw new InvalidArgumentException('Stake interval calibration requires positive target values and nonnegative funding inputs.');
        }
        if (! in_array($interpretation, [self::FULL_TARGET_FINANCING, self::PROPORTIONAL_STAKE_FINANCING], true)) {
            throw new InvalidArgumentException('Unknown stake-financing interpretation.');
        }

        $ownFunds = $bidderCash + $unusedCommittedCredit;
        if ($interpretation === self::FULL_TARGET_FINANCING) {
            $fundable = $ownFunds + ($annualNormalizedCash * $financingMultiple);
            $minimum = 1.15 * $fundable / $fullConsultantValue;
            $maximum = min(1.0, $fundable / $fullTrueWorth);
        } else {
            $financingPerStake = $annualNormalizedCash * $financingMultiple;
            $consultantDenominator = $fullConsultantValue - (1.15 * $financingPerStake);
            $minimum = $consultantDenominator > 0 ? (1.15 * $ownFunds) / $consultantDenominator : null;
            $trueWorthDenominator = $fullTrueWorth - $financingPerStake;
            $maximum = $trueWorthDenominator > 0 ? min(1.0, $ownFunds / $trueWorthDenominator) : 1.0;
        }

        $exists = $minimum !== null && $minimum < $maximum && $maximum > 0 && $minimum < 1;

        return [
            'interpretation' => $interpretation,
            'minimum_stake' => $minimum === null ? null : max(0.0, $minimum),
            'maximum_stake' => max(0.0, $maximum),
            'interval_exists' => $exists,
            'interval_width' => $exists ? $maximum - max(0.0, $minimum) : 0.0,
        ];
    }

    /** @return array<string, float|string|bool|null> */
    public function controlFundingConfirmation(float $fullConsultantValue, float $fullTrueWorth, float $annualNormalizedCash, float $bidderCash, float $unusedCommittedCredit): array
    {
        $result = $this->stakeDiagnostic(
            $fullConsultantValue,
            $fullTrueWorth,
            $annualNormalizedCash,
            $bidderCash,
            $unusedCommittedCredit,
            0.51,
            5.0,
            self::FULL_TARGET_FINANCING,
        );
        $fundableConsultant = $result['maximum_fundable_offer'] / $result['stake_consultant_value'];
        $fundableTrueWorth = $result['maximum_fundable_offer'] / $result['stake_true_worth'];
        $consultantTrueWorth = $result['stake_consultant_value'] / $result['stake_true_worth'];
        $criterionA = $fundableConsultant >= 0.95 && $fundableConsultant <= 1.10;
        $criterionB = $fundableTrueWorth >= 1.20;

        $classification = match (true) {
            $criterionA && $criterionB => 'B. FUNDING NEAR CONSULTANT WITH >=20% TRUE-WORTH HEADROOM',
            $fundableConsultant > 1.10 => 'C. FUNDING MATERIALLY ABOVE CONSULTANT',
            $fundableConsultant < 0.95 && $fundableTrueWorth < 1.20 => 'A. FUNDING BELOW CONSULTANT AND BELOW 20% HEADROOM',
            default => 'D. FUNDING MATERIALLY BELOW CONSULTANT',
        };

        return $result + [
            'fundable_consultant_ratio' => $fundableConsultant,
            'fundable_true_worth_ratio' => $fundableTrueWorth,
            'consultant_true_worth_ratio' => $consultantTrueWorth,
            'dollar_overpayment_headroom' => $result['maximum_fundable_offer'] - $result['stake_true_worth'],
            'percentage_overpayment_headroom' => $fundableTrueWorth - 1,
            'consultant_distance' => $result['maximum_fundable_offer'] - $result['stake_consultant_value'],
            'consultant_distance_percentage' => $fundableConsultant - 1,
            'criterion_a' => $criterionA,
            'criterion_b' => $criterionB,
            'passes_both' => $criterionA && $criterionB,
            'behavioral_classification' => $classification,
        ];
    }
}
