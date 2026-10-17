<?php

namespace App\Services\Engines\CorporateFinance;

use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngineResult;
use App\Services\Engines\CorporateFinance\Contracts\OpeningFinancialPosition;
use App\Services\Engines\CorporateFinance\Exceptions\EngineCalculationException;
use App\Services\Engines\CorporateFinance\Exceptions\PlausibilityValidationException;

final class FinancialPlausibilityValidator
{
    /**
     * @return array<string, array{evaluated: bool, calculated: float|int|string|null, permitted: string, passed: bool|null, skip_reason: string|null}>
     */
    public function evaluate(ManufacturingEngineResult $result, OpeningFinancialPosition $opening, ?ManufacturingEngineResult $priorResult = null): array
    {
        $fields = $result->fields;
        $netRevenue = (float) $result->incomeStatement['net_revenue'];
        $supplierPurchases = (float) $fields['n6_supplier'];
        $cogs = (float) $result->incomeStatement['cost_of_goods_sold'];
        $isZeroActivity = $this->isZeroActivityQuarter($result);

        $reportedDebt = round($fields['bs_curr_ltd'] + $fields['bs_ltd'], 2);
        $debtDifference = round($reportedDebt - $fields['n5_balance'], 2);
        $dividend = round(max(0.0, -(float) $fields['cf_equity']), 2);
        $expectedRetained = round($opening->retainedEarnings - $dividend, 2);
        $retainedDifference = round($fields['bs_retained'] - $expectedRetained, 2);
        $netIncomeDifference = round($fields['bs_curr_ni'] - $result->incomeStatement['net_income'], 2);

        $rules = [
            'receivables_to_revenue_ratio' => $this->rangeEvaluation(
                $this->ratio($fields['bs_ar'], $netRevenue),
                0.0,
                0.25
            ),

            'payables_to_purchases_ratio' => $this->rangeEvaluation(
                $this->ratio($fields['bs_ap'], $supplierPurchases),
                0.0,
                0.35
            ),

            'inventory_to_cogs_ratio' => $this->rangeEvaluation(
                $this->ratio($fields['bs_inventory'], $cogs),
                0.0,
                1.0
            ),

            'receivables_turnover' => $isZeroActivity

                ? $this->skippedEvaluation(

                    'Zero-activity quarter; receivables turnover is not applicable.'

                )

                : $this->rangeEvaluation(

                    $this->turnover(

                        $netRevenue * 0.20,

                        $opening->accountsReceivable,

                        $fields['bs_ar']

                    ),

                    (

                        $priorResult !== null

                        && $this->ratio(

                            (float) $priorResult->fields['bs_ar'],

                            max((float) $priorResult->incomeStatement['net_revenue'], 1.0)

                        ) <= 0.25 + 0.000001

                        && $fields['bs_ar'] < $opening->accountsReceivable - 0.01

                    )

                        ? 0.0

                        : 0.50,

                    12.00

                ),

            'payables_turnover' => $isZeroActivity
                ? $this->skippedEvaluation(
                    'Zero-activity quarter; payables turnover is not applicable.'
                )
                : $this->rangeEvaluation(
                    $this->turnover(
                        $supplierPurchases,
                        $opening->accountsPayable,
                        $fields['bs_ap']
                    ),
                    0.50,
                    12.00
                ),

            'cash_movement_to_net_revenue' => $isZeroActivity
                ? $this->skippedEvaluation(
                    'Zero-activity quarter; cash movement relative to net revenue is not applicable.'
                )
                : $this->rangeEvaluation(
                    $this->ratio(
                        abs($fields['bs_cash'] - $opening->cash),
                        max($netRevenue, 1.0)
                    ),
                    0.0,
                    1.5
                ),

            'debt_coherence' => $this->exactEvaluation(
                $debtDifference,
                '0.00 difference and non-negative reported debt',
                abs($debtDifference) <= 0.01 && $reportedDebt >= 0
            ),

            'retained_earnings_coherence' => $this->exactEvaluation(
                max(abs($retainedDifference), abs($netIncomeDifference)),
                '0.00..0.01 maximum roll-forward difference',
                abs($retainedDifference) <= 0.01 && abs($netIncomeDifference) <= 0.01,
            ),

            'bank_overdraft_state' => $this->exactEvaluation(
                (float) ($fields['bs_bank_overdraft'] ?? 0.0),
                'non-negative; positive values are a flagged simulation state',
                (float) ($fields['bs_bank_overdraft'] ?? 0.0) >= 0.0,
            ),
        ];

        $rules['inventory_turnover'] = $opening->inventory > 0.01
            ? $this->rangeEvaluation(
                $this->turnover(
                    $cogs,
                    $opening->inventory,
                    $fields['bs_inventory']
                ),
                0.25,
                20.00
            )
            : $this->skippedEvaluation(
                'Opening inventory is zero; first-quarter inventory turnover has no meaningful denominator.'
            );

        foreach (['bs_ar', 'bs_ap'] as $field) {
            $rule = "bounded_qoq_{$field}";

            $rules[$rule] = $priorResult === null
                ? $this->skippedEvaluation('No prior-quarter result was supplied.')
                : (
                    $isZeroActivity && in_array($field, ['bs_ar', 'bs_ap'], true)
                    ? $this->skippedEvaluation(
                        'Zero-activity quarter; working-capital movement is not applicable.'
                    )
                    : $this->rangeEvaluation(
                        $this->ratio(
                            abs($fields[$field] - $priorResult->fields[$field]),
                            max($netRevenue, 1.0)
                        ),
                        0.0,
                        0.30
                    )
                );
        }

        return $rules;
    }

    /**
     * @return array<int, array{flag: string, amount: float, quarter: int|string}>
     */
    public function flags(ManufacturingEngineResult $result): array
    {
        $overdraft = (float) ($result->fields['bs_bank_overdraft'] ?? 0.0);

        if ($overdraft <= 0.01) {
            return [];
        }

        return [[
            'flag' => 'bank_overdraft',
            'amount' => $overdraft,
            'quarter' => $result->intermediates['quarter_number'] ?? 'unknown',
        ]];
    }

    public function validate(
        ManufacturingEngineResult $result,
        OpeningFinancialPosition $opening,
        ?ManufacturingEngineResult $priorResult = null
    ): void {
        $fields = $result->fields;
        $quarter = $result->intermediates['quarter_number'] ?? 'unknown';
        $violations = [];

        /*
     * Operating-outcome plausibility rules are intentionally NOT hard-blocking.
     *
     * These rules remain available through evaluate() so unusual but valid
     * student decisions can still be calculated, reported, and diagnosed.
     *
     * Non-blocking rules include:
     * - receivables_to_revenue_ratio
     * - payables_to_purchases_ratio
     * - inventory_to_cogs_ratio
     * - receivables_turnover
     * - payables_turnover
     * - inventory_turnover
     * - cash_movement_to_net_revenue
     * - bounded_qoq_bs_ar
     * - bounded_qoq_bs_ap
     *
     * Only internal-consistency checks remain in this hard-block path.
     */

        $reportedDebt = round($fields['bs_curr_ltd'] + $fields['bs_ltd'], 2);

        if (abs($reportedDebt - $fields['n5_balance']) > 0.01 || $reportedDebt < 0) {
            $violations[] = $this->violation(
                'debt_coherence',
                $reportedDebt,
                '0.00..reported n5_balance',
                $quarter,
                [
                    'bs_curr_ltd' => $fields['bs_curr_ltd'],
                    'bs_ltd' => $fields['bs_ltd'],
                    'n5_balance' => $fields['n5_balance'],
                ],
            );
        }

        $dividend = round(max(0.0, -(float) $fields['cf_equity']), 2);
        $expectedRetained = round($opening->retainedEarnings - $dividend, 2);

        if (
            abs($fields['bs_retained'] - $expectedRetained) > 0.01
            || abs($fields['bs_curr_ni'] - $result->incomeStatement['net_income']) > 0.01
        ) {
            $violations[] = $this->violation(
                'retained_earnings_coherence',
                $fields['bs_curr_ni'],
                'reported net income',
                $quarter,
                [
                    'opening_retained' => $opening->retainedEarnings,
                    'dividend' => $dividend,
                    'expected_retained' => $expectedRetained,
                    'bs_retained' => $fields['bs_retained'],
                    'bs_curr_ni' => $fields['bs_curr_ni'],
                    'net_income' => $result->incomeStatement['net_income'],
                ],
            );
        }

        $this->throwIfViolations($violations);
    }

    /**
     * @param  array<int, ManufacturingEngineResult>  $results
     */
    public function validateConstantDecisionSteadyState(array $results, bool $constantInputs): void
    {
        if (! $constantInputs) {
            return;
        }

        if (count($results) < 3) {
            throw new EngineCalculationException('Plausibility rule [working_capital_steady_state] requires at least three repeated quarters.');
        }

        $orderedResults = array_values($results);
        $second = $orderedResults[1];
        $last = $orderedResults[count($orderedResults) - 1];
        $violations = [];

        foreach (['bs_ar', 'bs_ap', 'bs_inventory', 'bs_warranty_res', 'bs_sl_accrual', 'bs_excise'] as $field) {
            $delta = round(abs($last->fields[$field] - $second->fields[$field]), 2);

            if ($delta > 0.01) {
                $violations[] = $this->violation(
                    'working_capital_steady_state',
                    $delta,
                    '0.00..0.01',
                    'constant-chain',
                    ['field' => $field, 'second' => $second->fields[$field], 'last' => $last->fields[$field]],
                );
            }
        }

        $this->throwIfViolations($violations);
    }

    /**

     * @param  array<int, array<string, mixed>>  $violations

     */
    private function validateTurnoverMetrics(

        array &$violations,

        ManufacturingEngineResult $result,

        OpeningFinancialPosition $opening,

        mixed $quarter,

        bool $isZeroActivity,

        ?ManufacturingEngineResult $priorResult = null

    ): void {
        $fields = $result->fields;
        $netRevenue = (float) $result->incomeStatement['net_revenue'];
        $supplierPurchases = (float) $fields['n6_supplier'];
        $cogs = (float) $result->incomeStatement['cost_of_goods_sold'];

        $receivablesTurnover = $this->turnover(
            $netRevenue * 0.20,
            $opening->accountsReceivable,
            $fields['bs_ar']
        );

        $payablesTurnover = $this->turnover(
            $supplierPurchases,
            $opening->accountsPayable,
            $fields['bs_ap']
        );

        $inventoryTurnover = $opening->inventory > 0.01
            ? $this->turnover(
                $cogs,
                $opening->inventory,
                $fields['bs_inventory']
            )
            : null;

        if (! $isZeroActivity) {
            $priorReceivablesWereReasonable = false;

            if ($priorResult !== null) {
                $priorNetRevenue = (float) $priorResult->incomeStatement['net_revenue'];
                $priorAr = (float) $priorResult->fields['bs_ar'];

                $priorReceivablesRatio = $this->ratio(
                    $priorAr,
                    max($priorNetRevenue, 1.0)
                );

                $priorReceivablesWereReasonable =
                    $priorReceivablesRatio <= 0.25 + 0.000001;
            }

            $collectingPriorReceivables =
                $fields['bs_ar'] < $opening->accountsReceivable - 0.01;

            $receivablesTurnoverLowerBound = (
                $priorReceivablesWereReasonable
                && $collectingPriorReceivables
            )
                ? 0.0
                : 0.50;

            $this->addRangeViolation(
                $violations,
                'receivables_turnover',
                $receivablesTurnover,
                $receivablesTurnoverLowerBound,
                12.00,
                $quarter,
                [
                    'net_credit_revenue' => $netRevenue * 0.20,
                    'opening_ar' => $opening->accountsReceivable,
                    'ending_ar' => $fields['bs_ar'],
                    'prior_receivables_were_reasonable' => $priorReceivablesWereReasonable,
                    'collecting_prior_receivables' => $collectingPriorReceivables,
                    'lower_bound' => $receivablesTurnoverLowerBound,
                ],
            );

            $this->addRangeViolation(
                $violations,
                'payables_turnover',
                $payablesTurnover,
                0.50,
                12.00,
                $quarter,
                [
                    'supplier_purchases' => $supplierPurchases,
                    'opening_ap' => $opening->accountsPayable,
                    'ending_ap' => $fields['bs_ap'],
                ],
            );
        }

        if ($inventoryTurnover !== null && is_finite($inventoryTurnover)) {
            $this->addRangeViolation(
                $violations,
                'inventory_turnover',
                $inventoryTurnover,
                0.25,
                20.00,
                $quarter,
                [
                    'cogs' => $cogs,
                    'opening_inventory' => $opening->inventory,
                    'ending_inventory' => $fields['bs_inventory'],
                ],
            );
        }
    }


    private function isZeroActivityQuarter(ManufacturingEngineResult $result): bool

    {

        $netRevenue = (float) $result->incomeStatement['net_revenue'];

        $supplierPurchases = (float) $result->fields['n6_supplier'];

        $totalProduction = (float) ($result->intermediates['total_production'] ?? 0.0);

        return abs($netRevenue) <= 0.01

            && abs($supplierPurchases) <= 0.01

            && abs($totalProduction) <= 0.01;
    }

    /**
     * @param  array<int, array<string, mixed>>  $violations
     * @param  array<string, mixed>  $values
     */
    private function addRangeViolation(array &$violations, string $rule, float $value, float $lower, float $upper, mixed $quarter, array $values): void
    {
        if ($value < $lower || $value > $upper) {
            $violations[] = $this->violation($rule, $value, "{$lower}..{$upper}", $quarter, $values);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function violation(string $rule, float|int $calculated, string $allowedRange, mixed $quarter, array $values): array
    {
        return [
            'rule' => $rule,
            'calculated' => is_finite((float) $calculated) ? $calculated : (string) $calculated,
            'allowed_range' => $allowedRange,
            'quarter' => $quarter,
            'values' => $values,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $violations
     */
    private function throwIfViolations(array $violations): void
    {
        if ($violations !== []) {
            throw new PlausibilityValidationException($violations);
        }
    }

    private function ratio(float|int $numerator, float|int $denominator): float
    {
        if (abs((float) $denominator) < 0.01) {
            return abs((float) $numerator) < 0.01 ? 0.0 : INF;
        }

        return (float) $numerator / (float) $denominator;
    }

    private function turnover(float|int $flow, float|int $openingBalance, float|int $endingBalance): float
    {
        if ($openingBalance < -0.01 || $endingBalance < -0.01) {
            return -INF;
        }

        $averageBalance = ((float) $openingBalance + (float) $endingBalance) / 2;

        if (abs($averageBalance) < 0.01) {
            return abs((float) $flow) < 0.01 ? 0.0 : INF;
        }

        return (float) $flow / $averageBalance;
    }

    /**
     * @return array{evaluated: true, calculated: float, permitted: string, passed: bool, skip_reason: null}
     */
    private function rangeEvaluation(float $calculated, float $lower, float $upper): array
    {
        return [
            'evaluated' => true,
            'calculated' => $calculated,
            'permitted' => "{$lower}..{$upper}",
            'passed' => $calculated >= $lower && $calculated <= $upper,
            'skip_reason' => null,
        ];
    }

    /**
     * @return array{evaluated: true, calculated: float, permitted: string, passed: bool, skip_reason: null}
     */
    private function exactEvaluation(float $calculated, string $permitted, bool $passed): array
    {
        return [
            'evaluated' => true,
            'calculated' => $calculated,
            'permitted' => $permitted,
            'passed' => $passed,
            'skip_reason' => null,
        ];
    }

    /**
     * @return array{evaluated: false, calculated: null, permitted: string, passed: null, skip_reason: string}
     */
    private function skippedEvaluation(string $reason): array
    {
        return [
            'evaluated' => false,
            'calculated' => null,
            'permitted' => 'not applicable',
            'passed' => null,
            'skip_reason' => $reason,
        ];
    }
}
