<?php

namespace Tests\Unit\Services\Engines\CorporateFinance;

use App\Services\Engines\CorporateFinance\AccountingIdentityValidator;
use App\Services\Engines\CorporateFinance\CfManufacturingSimRulesService;
use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngineInput;
use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngineResult;
use App\Services\Engines\CorporateFinance\Contracts\OpeningFinancialPosition;
use App\Services\Engines\CorporateFinance\Exceptions\EngineCalculationException;
use App\Services\Engines\CorporateFinance\Exceptions\PlausibilityValidationException;
use App\Services\Engines\CorporateFinance\FinancialPlausibilityValidator;
use App\Services\Engines\CorporateFinance\ManufacturingCFEngine;
use App\Support\Scenario\CfManufacturingScenarioDefinition;
use Tests\TestCase;

class ManufacturingCFDividendTest extends TestCase
{
    public function test_legal_dividend_has_only_the_four_approved_statement_deltas(): void
    {
        $opening = $this->plentyOfCashOpening();
        $control = $this->calculate(4, 0, $opening);
        $result = $this->calculate(4, 500000, $opening);

        $this->assertSame(66462412.46, $control->fields['bs_cash']);
        $this->assertSame(65962412.46, $result->fields['bs_cash']);
        $this->assertSame(10000000.0, $control->fields['bs_retained']);
        $this->assertSame(9500000.0, $result->fields['bs_retained']);
        $this->assertSame(168102418.61, $control->balanceSheet['equity']);
        $this->assertSame(167602418.61, $result->balanceSheet['equity']);
        $this->assertSame(0.0, $control->cashFlowStatement['financing_cash_flow']);
        $this->assertSame(-500000.0, $result->fields['cf_equity']);
        $this->assertSame(-500000.0, $result->cashFlowStatement['financing_cash_flow']);
        $this->assertSame(8102418.61, $control->incomeStatement['net_income']);
        $this->assertSame(8102418.61, $result->incomeStatement['net_income']);
        $this->assertSame(17602418.61, OpeningFinancialPosition::fromPriorEngineResult($result->fields)->retainedEarnings);
        $this->assertNotSame(18102418.61, OpeningFinancialPosition::fromPriorEngineResult($result->fields)->retainedEarnings);

        foreach (['is_p1_rev', 'is_p2_rev', 'is_returns', 'is_rebates', 'is_raw_mat', 'is_labor', 'is_overhead', 'is_end_inv', 'is_marketing', 'is_freight', 'is_sl_penalties', 'is_excise', 'is_inspection', 'is_admin', 'is_warranty', 'is_interest', 'is_tax', 'bs_ar', 'bs_inventory', 'bs_ap', 'bs_warranty_res', 'bs_sl_accrual', 'bs_excise', 'bs_ppe_gross', 'bs_accum_depr', 'cf_net_income', 'cf_depr', 'cf_ar_chg', 'cf_inv_chg', 'cf_ap_chg'] as $field) {
            $this->assertSame($control->fields[$field], $result->fields[$field], "Unexpected dividend movement in {$field}");
        }

        $this->assertSame(0.0, round($result->balanceSheet['assets'] - ($result->balanceSheet['liabilities'] + $result->balanceSheet['equity']), 2));
        $this->assertSame(0.0, round(($result->fields['bs_cash'] - 60000000) - ($result->cashFlowStatement['operating_cash_flow'] + $result->cashFlowStatement['investing_cash_flow'] + $result->cashFlowStatement['financing_cash_flow']), 2));
        $this->assertSame(0.0, $result->fields['cf_borrow_proc']);
        $this->assertSame(0.0, $result->fields['n5_overdraft']);
    }

    public function test_financing_equity_and_cash_mutations_fail_independently_by_one_dollar(): void
    {
        $opening = $this->plentyOfCashOpening();
        $result = $this->calculate(4, 500000, $opening);
        $validator = new AccountingIdentityValidator;

        $financingMutation = $result->fields;
        $financingMutation['cf_equity'] += 1.0;
        $cashDifference = round(($financingMutation['bs_cash'] - $opening->cash) - ($validator->operatingCashFlow($financingMutation) + $validator->investingCashFlow($financingMutation) + $validator->financingCashFlow($financingMutation)), 2);
        $this->assertSame(-1.0, $cashDifference);
        $this->assertValidatorFailure(fn () => $validator->validateCashReconciliation($financingMutation, $opening), 'difference=-1');

        $retainedMutation = $result->fields;
        $retainedMutation['bs_retained'] += 1.0;
        $balanceDifference = round($validator->assets($retainedMutation) - ($validator->liabilities($retainedMutation) + $validator->equity($retainedMutation)), 2);
        $this->assertSame(-1.0, $balanceDifference);
        $this->assertValidatorFailure(fn () => $validator->validateBalanceSheet($retainedMutation), 'difference=-1');

        $cashMutation = $result->fields;
        $cashMutation['bs_cash'] += 1.0;
        $this->assertSame(1.0, round($validator->assets($cashMutation) - ($validator->liabilities($cashMutation) + $validator->equity($cashMutation)), 2));
        $this->assertSame(1.0, round(($cashMutation['bs_cash'] - $opening->cash) - ($validator->operatingCashFlow($cashMutation) + $validator->investingCashFlow($cashMutation) + $validator->financingCashFlow($cashMutation)), 2));
        $this->assertValidatorFailure(fn () => $validator->validate($cashMutation, $opening), 'difference=1');
    }

    public function test_plausibility_accepts_dividend_and_rejects_retained_mutation(): void
    {
        $opening = $this->plentyOfCashOpening();
        $result = $this->calculate(4, 500000, $opening);
        $validator = new FinancialPlausibilityValidator;
        $rules = $validator->evaluate($result, $opening);
        $this->assertTrue($rules['retained_earnings_coherence']['passed']);

        $fields = $result->fields;
        $fields['bs_retained'] += 1.0;
        $mutated = new ManufacturingEngineResult($fields, $result->incomeStatement, $result->balanceSheet, $result->cashFlowStatement, $result->notes, $result->intermediates);

        try {
            $validator->validate($mutated, $opening);
            $this->fail('Expected retained-earnings mutation to fail plausibility validation.');
        } catch (PlausibilityValidationException $exception) {
            $this->assertContains('retained_earnings_coherence', array_column($exception->violations(), 'rule'));
        }
    }

    public function test_pre_borrowing_cash_cap_accepts_exact_limit_without_financing(): void
    {
        $parameters = $this->parameters();
        $parameters['capex_mfg'] = 6962412.46;
        $result = $this->calculate(4, 250000, $this->capOpening(1250000, 91250000), $parameters);

        $this->assertEqualsWithDelta(1250000.0, $result->intermediates['pre_dividend_cash'], 0.001);
        $this->assertSame(250000.0, $result->intermediates['maximum_dividend']);
        $this->assertSame(1000000.0, $result->fields['bs_cash']);
        $this->assertSame(-250000.0, $result->fields['cf_equity']);
        $this->assertSame(0.0, $result->fields['cf_borrow_proc']);
        $this->assertSame(0.0, $result->fields['n5_auto_draw']);
        $this->assertSame(0.0, $result->fields['n5_overdraft']);
    }

    public function test_one_cent_over_pre_borrowing_cash_cap_is_rejected_not_financed(): void
    {
        $parameters = $this->parameters();
        $parameters['capex_mfg'] = 6962412.46;

        $this->expectException(EngineCalculationException::class);
        $this->expectExceptionMessage('250000.01 exceeds the maximum permitted dividend 250000.00');
        $this->calculate(4, 250000.01, $this->capOpening(1250000, 91250000), $parameters);
    }

    public function test_operational_shortfall_cannot_be_used_to_finance_dividend(): void
    {
        $parameters = $this->parameters();
        $parameters['capex_mfg'] = 6962412.46;
        $opening = $this->capOpening(900000, 90900000);
        $control = $this->calculate(4, 0, $opening, $parameters);

        $this->assertEqualsWithDelta(900000.0, $control->intermediates['pre_dividend_cash'], 0.001);
        $this->assertSame(0.0, $control->intermediates['maximum_dividend']);
        $this->assertSame(100000.0, $control->fields['cf_borrow_proc']);
        $this->assertSame(1000000.0, $control->fields['bs_cash']);

        $this->expectException(EngineCalculationException::class);
        $this->expectExceptionMessage('maximum permitted dividend 0.00');
        $this->calculate(4, 0.01, $opening, $parameters);
    }

    public function test_engine_defends_negative_deficit_and_warmup_dividends(): void
    {
        foreach ([1, 2, 3] as $quarter) {
            try {
                $this->calculate($quarter, 0.01, $this->plentyOfCashOpening());
                $this->fail("Expected Quarter {$quarter} dividend rejection.");
            } catch (EngineCalculationException $exception) {
                $this->assertStringContainsString('maximum permitted dividend 0.00', $exception->getMessage());
            }
        }

        $deficitControl = $this->calculate(4, 0, $this->deficitOpening());
        $this->assertSame(0.0, $deficitControl->intermediates['maximum_dividend']);
        $this->assertSame(0.0, $deficitControl->fields['cf_equity']);
        $this->assertSame(0.0, round($deficitControl->balanceSheet['assets'] - ($deficitControl->balanceSheet['liabilities'] + $deficitControl->balanceSheet['equity']), 2));

        foreach ([-0.01, 0.01] as $dividend) {
            $opening = $dividend < 0 ? $this->plentyOfCashOpening() : $this->deficitOpening();
            try {
                $this->calculate(4, $dividend, $opening);
                $this->fail('Expected engine dividend guardrail rejection.');
            } catch (EngineCalculationException $exception) {
                $this->assertStringContainsString($dividend < 0 ? 'nonnegative' : 'maximum permitted dividend 0.00', $exception->getMessage());
            }
        }
    }

    public function test_retained_earnings_chain_never_restores_dividends(): void
    {
        // A: Q4 opens at 10m, earns 2m, pays 0.5m; Q5 earns 1.25m and pays zero.
        $this->assertSame(11500000.0, 10000000.0 + 2000000.0 - 500000.0);
        $oneThenZeroQ5 = OpeningFinancialPosition::fromPriorEngineResult($this->priorFields(9500000, 2000000));
        $this->assertSame(11500000.0, $oneThenZeroQ5->retainedEarnings);
        $this->assertNotSame(12000000.0, $oneThenZeroQ5->retainedEarnings);
        $this->assertSame(12750000.0, 11500000.0 + 1250000.0);
        $this->assertSame(12750000.0, OpeningFinancialPosition::fromPriorEngineResult($this->priorFields(11500000, 1250000))->retainedEarnings);

        // B: the same Q4 is followed by a Q5 dividend of 0.25m.
        $this->assertSame(12500000.0, 11500000.0 + 1250000.0 - 250000.0);
        $this->assertSame(12500000.0, OpeningFinancialPosition::fromPriorEngineResult($this->priorFields(11250000, 1250000))->retainedEarnings);

        // C: the same Q4 dividend is followed by a Q5 loss of 3m.
        $this->assertSame(8500000.0, 11500000.0 - 3000000.0);
        $this->assertSame(8500000.0, OpeningFinancialPosition::fromPriorEngineResult($this->priorFields(11500000, -3000000))->retainedEarnings);

        // D: zero dividends preserve the ordinary retained-earnings chain.
        $this->assertSame(12000000.0, 10000000.0 + 2000000.0);
        $this->assertSame(12000000.0, OpeningFinancialPosition::fromPriorEngineResult($this->priorFields(10000000, 2000000))->retainedEarnings);
        $this->assertSame(13250000.0, 12000000.0 + 1250000.0);
        $this->assertSame(13250000.0, OpeningFinancialPosition::fromPriorEngineResult($this->priorFields(12000000, 1250000))->retainedEarnings);
    }

    public function test_omitted_dividend_is_identical_to_explicit_zero(): void
    {
        $opening = $this->plentyOfCashOpening();
        $explicit = $this->calculate(4, 0, $opening);
        $omitted = (new ManufacturingCFEngine)->calculate(new ManufacturingEngineInput(
            quarterNumber: 4,
            decisions: $this->decisions(),
            parameters: $this->parameters(),
            openingPosition: $opening,
        ));

        $this->assertSame($explicit->fields, $omitted->fields);
        $this->assertSame(0.0, $omitted->intermediates['dividend']);
    }

    public function test_seeded_deterministic_legal_dividends_preserve_identities_and_never_increase_debt(): void
    {
        $opening = $this->plentyOfCashOpening();
        $control = $this->calculate(4, 0, $opening);
        $maximum = 18102418.61;
        $dividends = [0.0, 1000.0, 250000.0, $maximum];

        foreach (range(1, 8) as $seed) {
            $fraction = hexdec(substr(hash('sha256', "dividend-seed-{$seed}"), 0, 8)) / 0xFFFFFFFF;
            $dividends[] = round($maximum * $fraction, 2);
        }

        foreach ($dividends as $dividend) {
            $result = $this->calculate(4, $dividend, $opening);
            $this->assertGreaterThanOrEqual(0, $dividend);
            $this->assertLessThanOrEqual($maximum, $dividend);
            $this->assertSame(round($control->fields['bs_cash'] - $dividend, 2), $result->fields['bs_cash']);
            $this->assertSame($control->fields['cf_borrow_proc'], $result->fields['cf_borrow_proc']);
            $this->assertSame($control->fields['n5_overdraft'], $result->fields['n5_overdraft']);
            $this->assertSame(0.0, round($result->balanceSheet['assets'] - ($result->balanceSheet['liabilities'] + $result->balanceSheet['equity']), 2));
            $this->assertSame(0.0, round(($result->fields['bs_cash'] - $opening->cash) - ($result->cashFlowStatement['operating_cash_flow'] + $result->cashFlowStatement['investing_cash_flow'] + $result->cashFlowStatement['financing_cash_flow']), 2));
            $this->assertSame(round($opening->retainedEarnings + $result->incomeStatement['net_income'] - $dividend, 2), round($result->fields['bs_retained'] + $result->fields['bs_curr_ni'], 2));
        }
    }

    private function calculate(int $quarter, float $dividend, OpeningFinancialPosition $opening, ?array $parameters = null): ManufacturingEngineResult
    {
        return (new ManufacturingCFEngine)->calculate(new ManufacturingEngineInput(
            quarterNumber: $quarter,
            decisions: $this->decisions(),
            parameters: $parameters ?? $this->parameters(),
            openingPosition: $opening,
            dividend: $dividend,
        ));
    }

    private function plentyOfCashOpening(): OpeningFinancialPosition
    {
        return $this->capOpening(60000000, 150000000);
    }

    private function deficitOpening(): OpeningFinancialPosition
    {
        return $this->capOpening(60000000, 171000000, -11000000);
    }

    private function capOpening(float $cash, float $capital, float $retained = 10000000): OpeningFinancialPosition
    {
        $base = OpeningFinancialPosition::fromScenarioParameters($this->parameters());

        return new OpeningFinancialPosition($cash, 0, 0, 0, 0, $retained, 0, $base->plantInvestment, $capital, $base->ppeComponents);
    }

    /** @return array<string, mixed> */
    private function priorFields(float $retained, float $netIncome): array
    {
        return ['bs_retained' => $retained, 'bs_curr_ni' => $netIncome];
    }

    private function assertValidatorFailure(callable $callback, string $message): void
    {
        try {
            $callback();
            $this->fail('Expected accounting validator failure.');
        } catch (EngineCalculationException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function parameters(): array
    {
        return CfManufacturingScenarioDefinition::parameters();
    }

    /** @return array<string, array<string, mixed>> */
    private function decisions(): array
    {
        $parameters = $this->parameters();
        $demand = CfManufacturingScenarioDefinition::q0ForecastingPayload();
        $operational = (new CfManufacturingSimRulesService)->deriveCompleteDecisions(4, $demand, $parameters);

        return array_merge($operational, [
            'notes_elections' => CfManufacturingScenarioDefinition::fixedWarmupPayload('notes_elections'),
            'certification' => CfManufacturingScenarioDefinition::fixedWarmupPayload('certification'),
        ]);
    }
}
