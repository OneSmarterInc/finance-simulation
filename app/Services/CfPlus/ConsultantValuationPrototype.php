<?php

namespace App\Services\CfPlus;

use App\Models\CfPlusDisclosureSnapshot;
use InvalidArgumentException;

class ConsultantValuationPrototype
{
    public function quarterlyDiscountRate(float $annualRequiredReturn): float
    {
        return CfPlusValuationConfiguration::quarterlyRate($annualRequiredReturn);
    }

    public function quarterlyTerminalGrowthRate(float $annualTerminalGrowth): float
    {
        return CfPlusValuationConfiguration::quarterlyRate($annualTerminalGrowth);
    }

    /** @return array<string, mixed> */
    public function evaluateApproved(CfPlusDisclosureSnapshot $snapshot): array
    {
        return $this->evaluate(
            $snapshot,
            CfPlusValuationConfiguration::ANNUAL_REQUIRED_RETURN,
            CfPlusValuationConfiguration::QUARTERLY_GROWTH_CAP,
            CfPlusValuationConfiguration::ANNUAL_TERMINAL_GROWTH,
            CfPlusValuationConfiguration::FORECAST_QUARTERS,
        );
    }

    public function validateCandidate(float $requiredReturn, float $growthCap, float $terminalGrowth, int $periods): void
    {
        if ($requiredReturn <= 0 || $periods < 1 || $growthCap < 0) {
            throw new InvalidArgumentException('Candidate DCF assumptions are invalid.');
        }
        $quarterlyReturn = $this->quarterlyDiscountRate($requiredReturn);
        $quarterlyTerminalGrowth = $this->quarterlyTerminalGrowthRate($terminalGrowth);
        if ($growthCap >= $quarterlyReturn) {
            throw new InvalidArgumentException(sprintf('Candidate quarterly growth cap %.6f must be below quarterly discount rate %.6f.', $growthCap, $quarterlyReturn));
        }
        if ($quarterlyTerminalGrowth >= $quarterlyReturn) {
            throw new InvalidArgumentException(sprintf('Candidate quarterly terminal growth %.6f must be below quarterly discount rate %.6f.', $quarterlyTerminalGrowth, $quarterlyReturn));
        }
    }

    /** @return array<string, mixed> */
    public function evaluate(CfPlusDisclosureSnapshot $snapshot, float $requiredReturn, float $growthCap, float $terminalGrowth, int $periods = 12): array
    {
        $this->validateCandidate($requiredReturn, $growthCap, $terminalGrowth, $periods);
        $quarters = collect($snapshot->published_statements)->sortBy('quarter_number')->values();
        if ($quarters->count() !== 3) {
            throw new InvalidArgumentException('Consultant valuation requires exactly three disclosed quarters.');
        }
        $reported = $quarters->map(fn (array $quarter): float => (float) $quarter['income_statement']['operating_income'])->all();

        return $this->evaluateSeries($reported, $requiredReturn, $growthCap, $terminalGrowth, $periods) + [
            'reported_quarters' => $quarters->pluck('quarter_number')->all(),
            'reported_operating_income' => $reported,
            'template_sections' => [
                'scope' => 'Valuation based on the target firm’s three disclosed operating quarters.',
                'reported_performance' => 'Reported operating income is accepted as presented; accounting policies are not normalized.',
                'method' => 'Median reported operating income projected with capped quarterly growth and discounted using a same-period quarterly Gordon Growth terminal value.',
                'conclusion' => 'The indicated value reflects reported performance under the approved assumptions and is not an answer key.',
            ],
        ];
    }

    /**
     * @param  array<int, float|int>  $series
     * @return array<string, mixed>
     */
    public function evaluateSeries(array $series, float $requiredReturn, float $growthCap, float $terminalGrowth, int $periods = 12): array
    {
        if (count($series) !== 3) {
            throw new InvalidArgumentException('Valuation requires exactly three quarterly observations.');
        }
        $reported = array_map('floatval', array_values($series));
        $sorted = $reported;
        sort($sorted, SORT_NUMERIC);
        $median = $sorted[1];
        $growths = [];
        for ($index = 1; $index < count($reported); $index++) {
            if ($reported[$index - 1] > 0 && $reported[$index] > 0) {
                $growths[] = ($reported[$index] - $reported[$index - 1]) / $reported[$index - 1];
            }
        }
        $observedGrowth = $growths === [] ? 0.0 : array_sum($growths) / count($growths);
        $quarterlyGrowth = max(-$growthCap, min($growthCap, $observedGrowth));
        $quarterlyReturn = $this->quarterlyDiscountRate($requiredReturn);
        $quarterlyTerminalGrowth = $this->quarterlyTerminalGrowthRate($terminalGrowth);
        $projected = [];
        $presentValues = [];
        for ($period = 1; $period <= $periods; $period++) {
            $cashFlow = $median * pow(1 + $quarterlyGrowth, $period);
            $projected[] = round($cashFlow, 2);
            $presentValues[] = $cashFlow / pow(1 + $quarterlyReturn, $period);
        }
        $terminalValue = (end($projected) * (1 + $quarterlyTerminalGrowth)) / ($quarterlyReturn - $quarterlyTerminalGrowth);
        $terminalPresentValue = $terminalValue / pow(1 + $quarterlyReturn, $periods);
        $explicitPresentValue = array_sum($presentValues);
        $consultantValue = $explicitPresentValue + $terminalPresentValue;

        return ['median_reported_base' => round($median, 2), 'observed_growth' => $observedGrowth, 'capped_quarterly_growth' => $quarterlyGrowth, 'required_return' => $requiredReturn, 'quarterly_required_return' => $quarterlyReturn, 'terminal_method' => 'gordon_growth', 'terminal_growth' => $terminalGrowth, 'annual_terminal_growth' => $terminalGrowth, 'quarterly_terminal_growth' => $quarterlyTerminalGrowth, 'projected_periods' => $periods, 'projected_reported_base' => $projected, 'projected_present_values' => array_map(fn (float $amount): float => round($amount, 2), $presentValues), 'explicit_period_present_value' => round($explicitPresentValue, 2), 'terminal_value' => round($terminalValue, 2), 'terminal_present_value' => round($terminalPresentValue, 2), 'terminal_share' => $consultantValue === 0.0 ? 0.0 : $terminalPresentValue / $consultantValue, 'consultant_value' => round($consultantValue, 2)];
    }
}
