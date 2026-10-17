<?php

namespace App\Services\CfPlus;

use App\Models\CfPlusDisclosureSnapshot;
use DomainException;

final class CfPlusTrueWorthService
{
    public function __construct(private readonly ConsultantValuationPrototype $valuation) {}

    /** @return array<string, mixed> */
    public function calculate(CfPlusDisclosureSnapshot $snapshot): array
    {
        $statements = collect($snapshot->published_statements)->sortBy('quarter_number')->values();
        if ($statements->count() !== 3) {
            throw new DomainException('True worth requires exactly three disclosed operating quarters.');
        }
        $snapshot->loadMissing('course.scenario');
        $parameter = $snapshot->course->scenario->parameters()->where('parameter_key', 'tax_rate')->firstOrFail();
        $taxRate = (float) $parameter->parameter_value['value'];

        return $this->calculateFromStatements($statements->all(), $taxRate);
    }

    /**
     * @param  array<int, array<string, mixed>>  $statements
     * @return array<string, mixed>
     */
    public function calculateFromStatements(array $statements, float $taxRate): array
    {
        $statements = collect($statements)->sortBy('quarter_number')->values();
        if ($statements->count() !== 3 || $taxRate < 0 || $taxRate > 1) {
            throw new DomainException('True worth requires three quarters and a tax rate between zero and one.');
        }
        $normalized = $statements->map(function (array $quarter) use ($taxRate): float {
            $operatingIncome = $this->number($quarter, ['income_statement', 'operating_income']);
            $depreciation = $this->number($quarter, ['cash_flow_statement', 'line_items', 'cf_depr']);
            $afterTaxOperatingIncome = $operatingIncome > 0 ? $operatingIncome * (1 - $taxRate) : $operatingIncome;

            return round($afterTaxOperatingIncome + $depreciation, 2);
        })->all();

        $config = CfPlusValuationConfiguration::approved();
        $valuation = $this->valuation->evaluateSeries($normalized, $config['annual_required_return'], $config['quarterly_growth_cap'], $config['annual_terminal_growth'], $config['forecast_quarters']);

        return $valuation + [
            'source_quarters' => $statements->pluck('quarter_number')->all(),
            'tax_rate' => $taxRate,
            'normalized_quarterly_recurring_cash' => $normalized,
            'representative_quarterly_recurring_cash' => $valuation['median_reported_base'],
            'annual_normalized_recurring_cash' => round($valuation['median_reported_base'] * 4, 2),
            'true_worth' => $valuation['consultant_value'],
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<int, string>  $path
     */
    private function number(array $values, array $path): float
    {
        $value = $values;
        foreach ($path as $key) {
            if (! is_array($value) || ! array_key_exists($key, $value)) {
                throw new DomainException('True-worth source statements are incomplete.');
            }
            $value = $value[$key];
        }
        if (! is_numeric($value)) {
            throw new DomainException('True-worth source statements are incomplete.');
        }

        return (float) $value;
    }
}
