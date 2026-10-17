<?php

namespace Tests\Feature\Scenario;

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

class CfManufacturingSimRulesIntegrationTest extends TestCase
{
    public function test_all_categories_sim_derived_run_through_engine_and_plausibility_validation(): void
    {
        $results = $this->runChainedSimRules();

        foreach ($results as $quarter => $result) {
            $this->assertEqualsWithDelta(0.0, round($result->balanceSheet['assets'] - ($result->balanceSheet['liabilities'] + $result->balanceSheet['equity']), 2), 0.01, "Q{$quarter} balance sheet");
            $this->assertGreaterThan(0, $result->incomeStatement['net_revenue']);
            $this->assertLessThan(0.58, $result->incomeStatement['operating_income'] / $result->incomeStatement['net_revenue']);
        }
    }

    public function test_zero_activity_quarter_is_financially_valid_but_activity_dependent_plausibility_rules_are_not_applicable(): void
    {
        $results = $this->runChainedSimRules();
        $parameters = $this->parameters();
        $opening = OpeningFinancialPosition::fromPriorEngineResult($results[3]->fields);

        $operational = (new CfManufacturingSimRulesService)->deriveCompleteDecisions(
            4,
            $this->demand(),
            $parameters
        );

        $decisions = array_merge(
            $operational,
            [
                'production' => [
                    'p1_production' => 0,
                    'p2_production' => 0,
                    'shifts' => 1,
                    'postponed_flag' => 'NO',
                    'postponed_pct' => 0,
                ],
            ],
            $this->fixedElections()
        );

        $result = (new ManufacturingCFEngine)->calculate(
            new ManufacturingEngineInput(4, $decisions, $parameters, $opening)
        );

        $this->assertSame(0.0, $result->incomeStatement['net_revenue']);
        $this->assertSame(0.0, $result->intermediates['total_production']);
        $this->assertSame(0.0, $result->fields['n6_supplier']);

        $this->assertEqualsWithDelta(
            0.0,
            round(
                $result->balanceSheet['assets']
                    - ($result->balanceSheet['liabilities'] + $result->balanceSheet['equity']),
                2
            ),
            0.01
        );

        (new FinancialPlausibilityValidator)->validate(

            $result,

            $opening,

            $results[3]

        );

        $evaluation = (new FinancialPlausibilityValidator)->evaluate(

            $result,

            $opening,

            $results[3]

        );

        $skippedRules = collect($evaluation)

            ->filter(fn(array $item) => ($item['evaluated'] ?? true) === false)

            ->keys()

            ->all();

        $this->assertSame([

            'receivables_turnover',

            'payables_turnover',

            'cash_movement_to_net_revenue',

            'inventory_turnover',

            'bounded_qoq_bs_ar',

            'bounded_qoq_bs_ap',

        ], $skippedRules);
    }

    public function test_mixed_student_and_sim_controlled_decisions_integrate_with_engine(): void
    {
        $parameters = $this->parameters();
        $demand = $this->demand();
        $studentForecast = array_merge($demand, ['forecast_confidence' => 'high']);
        $studentPricing = ['price_p1_r1' => 275, 'price_p1_r2' => 275, 'price_p1_r3' => 275, 'price_p2_r1' => 355, 'price_p2_r2' => 355, 'price_p2_r3' => 355, 'discount_policy' => 'moderate'];
        $operational = (new CfManufacturingSimRulesService)->deriveCompleteDecisions(4, $demand, $parameters, ['forecasting', 'pricing'], ['forecasting' => $studentForecast, 'pricing' => $studentPricing]);
        $decisions = array_merge($operational, $this->fixedElections());
        $opening = OpeningFinancialPosition::fromScenarioParameters($parameters);
        $result = (new ManufacturingCFEngine)->calculate(new ManufacturingEngineInput(4, $decisions, $parameters, $opening));

        (new AccountingIdentityValidator)->validate($result->fields, $opening);
        (new FinancialPlausibilityValidator)->validate($result, $opening);

        $this->assertSame($studentForecast, $operational['forecasting']);
        $this->assertSame($studentPricing, $operational['pricing']);
        $this->assertSame(130000.0, $operational['production']['p1_production']);
    }

    public function test_symmetric_working_capital_overstatement_is_reported_by_plausibility_evaluator(): void
    {
        $parameters = $this->parameters();
        $opening = OpeningFinancialPosition::fromScenarioParameters($parameters);
        $result = $this->runChainedSimRules()[1];
        $fields = $result->fields;

        $fields['bs_ar'] += 10000000.0;
        $fields['bs_ap'] += 10000000.0;
        $fields['cf_ar_chg'] += 10000000.0;
        $fields['cf_ap_chg'] += 10000000.0;

        $mutated = new ManufacturingEngineResult(
            $fields,
            $result->incomeStatement,
            $result->balanceSheet,
            $result->cashFlowStatement,
            $result->notes,
            $result->intermediates
        );

        (new AccountingIdentityValidator)->validate($fields, $opening);

        $evaluation = (new FinancialPlausibilityValidator)->evaluate(
            $mutated,
            $opening
        );

        $this->assertFalse($evaluation['receivables_to_revenue_ratio']['passed']);
        $this->assertFalse($evaluation['payables_to_purchases_ratio']['passed']);

        (new FinancialPlausibilityValidator)->validate($mutated, $opening);
        $this->addToAssertionCount(1);
    }

    public function test_single_plausibility_violation_payload_is_structured(): void
    {
        $result = $this->runChainedSimRules()[1];
        $opening = OpeningFinancialPosition::fromScenarioParameters($this->parameters());
        $fields = $result->fields;

        $fields['bs_cash'] = $opening->cash - ($result->incomeStatement['net_revenue'] * 2);

        $mutated = new ManufacturingEngineResult(
            $fields,
            $result->incomeStatement,
            $result->balanceSheet,
            $result->cashFlowStatement,
            $result->notes,
            $result->intermediates
        );

        $evaluation = (new FinancialPlausibilityValidator)->evaluate(
            $mutated,
            $opening
        );

        $violation = $evaluation['cash_movement_to_net_revenue'];

        $this->assertTrue($violation['evaluated']);
        $this->assertFalse($violation['passed']);
        $this->assertSame('0..1.5', $violation['permitted']);
        $this->assertNotNull($violation['calculated']);

        (new FinancialPlausibilityValidator)->validate($mutated, $opening);
        $this->addToAssertionCount(1);
    }

    public function test_multiple_plausibility_violations_are_collected_in_deterministic_order(): void
    {
        $result = $this->runChainedSimRules()[1];
        $opening = OpeningFinancialPosition::fromScenarioParameters($this->parameters());
        $fields = $result->fields;

        $fields['bs_ar'] += 20000000.0;
        $fields['bs_ap'] += 20000000.0;
        $fields['n5_balance'] = 1.0;
        $fields['bs_curr_ni'] += 1.0;

        $mutated = new ManufacturingEngineResult(
            $fields,
            $result->incomeStatement,
            $result->balanceSheet,
            $result->cashFlowStatement,
            $result->notes,
            $result->intermediates
        );

        $evaluation = (new FinancialPlausibilityValidator)->evaluate(
            $mutated,
            $opening
        );

        $this->assertFalse($evaluation['receivables_to_revenue_ratio']['passed']);
        $this->assertFalse($evaluation['payables_to_purchases_ratio']['passed']);

        $violations = $this->plausibilityViolationsFor(
            fn() => (new FinancialPlausibilityValidator)->validate($mutated, $opening)
        );

        $this->assertSame([
            'debt_coherence',
            'retained_earnings_coherence',
        ], array_column($violations, 'rule'));

        $this->assertSame(
            $violations,
            $this->plausibilityViolationsFor(
                fn() => (new FinancialPlausibilityValidator)->validate($mutated, $opening)
            )
        );
    }

    public function test_historical_working_capital_overstatement_replay_is_reported(): void
    {
        $results = $this->runChainedSimRules();

        $opening = OpeningFinancialPosition::fromPriorEngineResult($results[2]->fields);
        $result = $results[3];
        $fields = $result->fields;

        $overstatement = 24485888.70 - 8161962.90;

        $fields['bs_ar'] += $overstatement;
        $fields['bs_ap'] += $overstatement;
        $fields['cf_ar_chg'] += $overstatement;
        $fields['cf_ap_chg'] += $overstatement;

        $mutated = new ManufacturingEngineResult(
            $fields,
            $result->incomeStatement,
            $result->balanceSheet,
            $result->cashFlowStatement,
            $result->notes,
            $result->intermediates
        );

        (new AccountingIdentityValidator)->validate($fields, $opening);

        $evaluation = (new FinancialPlausibilityValidator)->evaluate(
            $mutated,
            $opening
        );

        $this->assertFalse($evaluation['receivables_to_revenue_ratio']['passed']);
        $this->assertFalse($evaluation['payables_to_purchases_ratio']['passed']);

        (new FinancialPlausibilityValidator)->validate($mutated, $opening);
        $this->addToAssertionCount(1);
    }

    public function test_repeated_constant_demand_working_capital_reaches_steady_state(): void
    {
        $results = $this->runChainedSimRules();

        (new FinancialPlausibilityValidator)->validateConstantDecisionSteadyState($results, constantInputs: true);

        $this->assertSame($results[2]->fields['bs_ar'], $results[3]->fields['bs_ar']);
        $this->assertSame($results[2]->fields['bs_ap'], $results[3]->fields['bs_ap']);
        $this->assertSame($results[2]->fields['bs_inventory'], $results[3]->fields['bs_inventory']);
    }

    public function test_steady_state_rule_is_skipped_when_inputs_are_not_constant(): void
    {
        $results = $this->runChainedSimRules();
        $fields = $results[3]->fields;
        $fields['bs_ar'] += 1000.0;
        $results[3] = new ManufacturingEngineResult($fields, $results[3]->incomeStatement, $results[3]->balanceSheet, $results[3]->cashFlowStatement, $results[3]->notes, $results[3]->intermediates);

        (new FinancialPlausibilityValidator)->validateConstantDecisionSteadyState($results, constantInputs: false);

        $this->assertTrue(true);
    }

    public function test_steady_state_rule_skips_changing_demand_context(): void
    {
        $results = $this->runChainedSimRules();
        $fields = $results[3]->fields;
        $fields['bs_inventory'] += 1000.0;
        $results[3] = new ManufacturingEngineResult($fields, $results[3]->incomeStatement, $results[3]->balanceSheet, $results[3]->cashFlowStatement, $results[3]->notes, $results[3]->intermediates);

        (new FinancialPlausibilityValidator)->validateConstantDecisionSteadyState($results, constantInputs: false);

        $this->assertTrue(true);
    }

    public function test_steady_state_rule_skips_changing_decision_context(): void
    {
        $results = $this->runChainedSimRules();
        $fields = $results[3]->fields;
        $fields['bs_ap'] += 1000.0;
        $results[3] = new ManufacturingEngineResult($fields, $results[3]->incomeStatement, $results[3]->balanceSheet, $results[3]->cashFlowStatement, $results[3]->notes, $results[3]->intermediates);

        (new FinancialPlausibilityValidator)->validateConstantDecisionSteadyState($results, constantInputs: false);

        $this->assertTrue(true);
    }

    public function test_steady_state_rule_fails_under_constant_inputs_with_continued_drift(): void
    {
        $results = $this->runChainedSimRules();
        $fields = $results[3]->fields;
        $fields['bs_ar'] += 1000.0;
        $results[3] = new ManufacturingEngineResult($fields, $results[3]->incomeStatement, $results[3]->balanceSheet, $results[3]->cashFlowStatement, $results[3]->notes, $results[3]->intermediates);

        $this->expectException(EngineCalculationException::class);
        $this->expectExceptionMessage('Plausibility rule [working_capital_steady_state] failed');

        (new FinancialPlausibilityValidator)->validateConstantDecisionSteadyState($results, constantInputs: true);
    }

    public function test_reasonable_balance_decreases_pass_plausibility_validation(): void
    {
        $result = $this->runChainedSimRules()[1];
        $opening = new OpeningFinancialPosition(
            cash: $result->fields['bs_cash'] + 1000.0,
            accountsReceivable: $result->fields['bs_ar'] + 1000.0,
            inventory: $result->fields['bs_inventory'] + 4000000.0,
            accountsPayable: $result->fields['bs_ap'] + 1000.0,
            debt: 0.0,
            retainedEarnings: 0.0,
            accumulatedDepreciation: 0.0,
            plantInvestment: 100000000.0,
            corporateCapitalization: 100100000.0,
            ppeComponents: OpeningFinancialPosition::fromScenarioParameters($this->parameters())->ppeComponents,
        );

        (new FinancialPlausibilityValidator)->validate($result, $opening);

        $this->assertTrue(true);
    }

    public function test_large_receivables_collection_does_not_fail_turnover_validation(): void
    {
        $result = $this->runChainedSimRules()[3];

        $opening = OpeningFinancialPosition::fromPriorEngineResult(
            $this->runChainedSimRules()[2]->fields
        );

        $fields = $result->fields;

        // Simulate a quarter where most prior receivables are collected
        // while current-quarter credit sales are much lower.
        $fields['bs_ar'] = $opening->accountsReceivable * 0.15;
        $fields['cf_ar_chg'] = $fields['bs_ar'] - $opening->accountsReceivable;
        $fields['bs_cash'] += $opening->accountsReceivable - $fields['bs_ar'];

        $mutated = new ManufacturingEngineResult(
            $fields,
            $result->incomeStatement,
            $result->balanceSheet,
            $result->cashFlowStatement,
            $result->notes,
            $result->intermediates
        );

        (new AccountingIdentityValidator)->validate(
            $mutated->fields,
            $opening
        );

        $evaluation = (new FinancialPlausibilityValidator)->evaluate(
            $mutated,
            $opening,
            $this->runChainedSimRules()[2]
        );

        $this->assertTrue($evaluation['receivables_turnover']['evaluated']);
        $this->assertTrue($evaluation['receivables_turnover']['passed']);

        (new FinancialPlausibilityValidator)->validate(
            $mutated,
            $opening,
            $this->runChainedSimRules()[2]
        );

        $this->addToAssertionCount(1);
    }

    public function test_excessive_cash_movement_is_reported_without_blocking(): void
    {
        $result = $this->runChainedSimRules()[1];
        $opening = OpeningFinancialPosition::fromScenarioParameters($this->parameters());
        $fields = $result->fields;

        $fields['bs_cash'] = $opening->cash - ($result->incomeStatement['net_revenue'] * 2);

        $mutated = new ManufacturingEngineResult(
            $fields,
            $result->incomeStatement,
            $result->balanceSheet,
            $result->cashFlowStatement,
            $result->notes,
            $result->intermediates
        );

        $evaluation = (new FinancialPlausibilityValidator)->evaluate(
            $mutated,
            $opening
        );

        $this->assertTrue($evaluation['cash_movement_to_net_revenue']['evaluated']);
        $this->assertFalse($evaluation['cash_movement_to_net_revenue']['passed']);
        $this->assertSame('0..1.5', $evaluation['cash_movement_to_net_revenue']['permitted']);

        (new FinancialPlausibilityValidator)->validate($mutated, $opening);
        $this->addToAssertionCount(1);
    }

    public function test_receivables_turnover_negative_rule_is_reported_without_blocking(): void
    {
        $result = $this->runChainedSimRules()[1];

        $opening = new OpeningFinancialPosition(
            cash: 100000.0,
            accountsReceivable: $result->incomeStatement['net_revenue'] * 0.70,
            inventory: 0.0,
            accountsPayable: 0.0,
            debt: 0.0,
            retainedEarnings: 0.0,
            accumulatedDepreciation: 0.0,
            plantInvestment: 100000000.0,
            corporateCapitalization: 100100000.0,
            ppeComponents: OpeningFinancialPosition::fromScenarioParameters($this->parameters())->ppeComponents,
        );

        $evaluation = (new FinancialPlausibilityValidator)->evaluate(
            $result,
            $opening
        );

        $this->assertTrue($evaluation['receivables_turnover']['evaluated']);
        $this->assertFalse($evaluation['receivables_turnover']['passed']);
        $this->assertSame('0.5..12', $evaluation['receivables_turnover']['permitted']);

        (new FinancialPlausibilityValidator)->validate($result, $opening);
        $this->addToAssertionCount(1);
    }

    public function test_receivables_turnover_allows_substantial_collection_of_prior_ar(): void
    {
        $result = $this->runChainedSimRules()[3];

        $opening = new OpeningFinancialPosition(
            cash: 100000.0,
            accountsReceivable: 10_198_928.60,
            inventory: 0.0,
            accountsPayable: 0.0,
            debt: 0.0,
            retainedEarnings: 0.0,
            accumulatedDepreciation: 0.0,
            plantInvestment: 100000000.0,
            corporateCapitalization: 100100000.0,
            ppeComponents: OpeningFinancialPosition::fromScenarioParameters($this->parameters())->ppeComponents,
        );

        $fields = $result->fields;
        $fields['bs_ar'] = 1_522_528.00;

        $incomeStatement = $result->incomeStatement;
        $incomeStatement['net_revenue'] = 7_768_000.00;

        $mutated = new ManufacturingEngineResult(
            $fields,
            $incomeStatement,
            $result->balanceSheet,
            $result->cashFlowStatement,
            $result->notes,
            $result->intermediates
        );

        $prior = $this->runChainedSimRules()[2];
        $rules = (new FinancialPlausibilityValidator)->evaluate(

            $mutated,

            $opening,

            $prior

        );

        $this->assertLessThan(
            0.50,
            $rules['receivables_turnover']['calculated']
        );

        $this->assertTrue(
            $rules['receivables_turnover']['passed']
        );
    }

    public function test_payables_turnover_negative_rule_is_reported_without_blocking(): void
    {
        $result = $this->runChainedSimRules()[1];

        $opening = new OpeningFinancialPosition(
            cash: 100000.0,
            accountsReceivable: 0.0,
            inventory: 0.0,
            accountsPayable: $result->fields['n6_supplier'] * 4,
            debt: 0.0,
            retainedEarnings: 0.0,
            accumulatedDepreciation: 0.0,
            plantInvestment: 100000000.0,
            corporateCapitalization: 100100000.0,
            ppeComponents: OpeningFinancialPosition::fromScenarioParameters($this->parameters())->ppeComponents,
        );

        $evaluation = (new FinancialPlausibilityValidator)->evaluate(
            $result,
            $opening
        );

        $this->assertTrue($evaluation['payables_turnover']['evaluated']);
        $this->assertFalse($evaluation['payables_turnover']['passed']);
        $this->assertSame('0.5..12', $evaluation['payables_turnover']['permitted']);

        (new FinancialPlausibilityValidator)->validate($result, $opening);
        $this->addToAssertionCount(1);
    }

    public function test_inventory_ratio_is_reported_without_blocking(): void
    {
        $result = $this->runChainedSimRules()[1];
        $fields = $result->fields;

        $fields['bs_inventory'] = $result->incomeStatement['cost_of_goods_sold'] * 1.1;

        $mutated = new ManufacturingEngineResult(
            $fields,
            $result->incomeStatement,
            $result->balanceSheet,
            $result->cashFlowStatement,
            $result->notes,
            $result->intermediates
        );

        $opening = OpeningFinancialPosition::fromScenarioParameters(
            $this->parameters()
        );

        $evaluation = (new FinancialPlausibilityValidator)->evaluate(
            $mutated,
            $opening
        );

        $this->assertTrue($evaluation['inventory_to_cogs_ratio']['evaluated']);
        $this->assertFalse($evaluation['inventory_to_cogs_ratio']['passed']);
        $this->assertSame('0..1', $evaluation['inventory_to_cogs_ratio']['permitted']);

        (new FinancialPlausibilityValidator)->validate($mutated, $opening);
        $this->addToAssertionCount(1);
    }

    public function test_first_quarter_zero_opening_inventory_keeps_ratio_active_but_skips_turnover(): void
    {
        $result = $this->runChainedSimRules()[1];
        $fields = $result->fields;

        $fields['bs_inventory'] = $result->incomeStatement['cost_of_goods_sold'] * 1.1;

        $mutated = new ManufacturingEngineResult(
            $fields,
            $result->incomeStatement,
            $result->balanceSheet,
            $result->cashFlowStatement,
            $result->notes,
            $result->intermediates
        );

        $opening = OpeningFinancialPosition::fromScenarioParameters(
            $this->parameters()
        );

        $evaluation = (new FinancialPlausibilityValidator)->evaluate(
            $mutated,
            $opening
        );

        $this->assertTrue($evaluation['inventory_to_cogs_ratio']['evaluated']);
        $this->assertFalse($evaluation['inventory_to_cogs_ratio']['passed']);

        $this->assertFalse($evaluation['inventory_turnover']['evaluated']);

        $this->assertNull($evaluation['inventory_turnover']['passed']);

        $this->assertSame(
            'Opening inventory is zero; first-quarter inventory turnover has no meaningful denominator.',
            $evaluation['inventory_turnover']['skip_reason']
        );

        (new FinancialPlausibilityValidator)->validate(
            $mutated,
            $opening
        );

        $this->addToAssertionCount(1);
    }

    public function test_inventory_turnover_runs_when_opening_inventory_exists(): void
    {
        $result = $this->runChainedSimRules()[1];

        $opening = new OpeningFinancialPosition(
            cash: 100000.0,
            accountsReceivable: 0.0,
            inventory: $result->incomeStatement['cost_of_goods_sold'] * 10,
            accountsPayable: 0.0,
            debt: 0.0,
            retainedEarnings: 0.0,
            accumulatedDepreciation: 0.0,
            plantInvestment: 100000000.0,
            corporateCapitalization: 100100000.0,
            ppeComponents: OpeningFinancialPosition::fromScenarioParameters($this->parameters())->ppeComponents,
        );

        $evaluation = (new FinancialPlausibilityValidator)->evaluate(
            $result,
            $opening
        );

        $this->assertTrue($evaluation['inventory_turnover']['evaluated']);
        $this->assertFalse($evaluation['inventory_turnover']['passed']);
        $this->assertSame('0.25..20', $evaluation['inventory_turnover']['permitted']);

        (new FinancialPlausibilityValidator)->validate(
            $result,
            $opening
        );

        $this->addToAssertionCount(1);
    }

    public function test_debt_coherence_negative_rule_is_isolated(): void
    {
        $result = $this->runChainedSimRules()[1];
        $fields = $result->fields;
        $fields['n5_balance'] = 1.00;
        $mutated = new ManufacturingEngineResult($fields, $result->incomeStatement, $result->balanceSheet, $result->cashFlowStatement, $result->notes, $result->intermediates);

        $this->expectException(EngineCalculationException::class);
        $this->expectExceptionMessage('Plausibility rule [debt_coherence] failed');

        (new FinancialPlausibilityValidator)->validate($mutated, OpeningFinancialPosition::fromScenarioParameters($this->parameters()));
    }

    public function test_retained_earnings_negative_rule_is_isolated(): void
    {
        $result = $this->runChainedSimRules()[1];
        $fields = $result->fields;
        $fields['bs_curr_ni'] += 1.00;
        $mutated = new ManufacturingEngineResult($fields, $result->incomeStatement, $result->balanceSheet, $result->cashFlowStatement, $result->notes, $result->intermediates);

        $this->expectException(EngineCalculationException::class);
        $this->expectExceptionMessage('Plausibility rule [retained_earnings_coherence] failed');

        (new FinancialPlausibilityValidator)->validate($mutated, OpeningFinancialPosition::fromScenarioParameters($this->parameters()));
    }

    public function test_quarter_over_quarter_movement_negative_rule_is_isolated(): void
    {
        $results = $this->runChainedSimRules();

        $priorFields = $results[1]->fields;
        $priorFields['bs_ar'] = -$results[2]->incomeStatement['net_revenue'] * 0.31;

        $prior = new ManufacturingEngineResult(
            $priorFields,
            $results[1]->incomeStatement,
            $results[1]->balanceSheet,
            $results[1]->cashFlowStatement,
            $results[1]->notes,
            $results[1]->intermediates
        );

        $opening = OpeningFinancialPosition::fromPriorEngineResult(
            $results[1]->fields
        );

        $evaluation = (new FinancialPlausibilityValidator)->evaluate(
            $results[2],
            $opening,
            $prior
        );

        $this->assertTrue($evaluation['bounded_qoq_bs_ar']['evaluated']);
        $this->assertFalse($evaluation['bounded_qoq_bs_ar']['passed']);
        $this->assertSame(
            '0..0.3',
            $evaluation['bounded_qoq_bs_ar']['permitted']
        );

        (new FinancialPlausibilityValidator)->validate(
            $results[2],
            $opening,
            $prior
        );

        $this->addToAssertionCount(1);
    }

    public function test_quarter_over_quarter_payables_movement_negative_rule_is_isolated(): void
    {
        $results = $this->runChainedSimRules();

        $priorFields = $results[1]->fields;
        $priorFields['bs_ap'] = $results[2]->fields['bs_ap'] + ($results[2]->incomeStatement['net_revenue'] * 0.31);

        $prior = new ManufacturingEngineResult(
            $priorFields,
            $results[1]->incomeStatement,
            $results[1]->balanceSheet,
            $results[1]->cashFlowStatement,
            $results[1]->notes,
            $results[1]->intermediates
        );

        $opening = OpeningFinancialPosition::fromPriorEngineResult(
            $results[1]->fields
        );

        $evaluation = (new FinancialPlausibilityValidator)->evaluate(
            $results[2],
            $opening,
            $prior
        );

        $this->assertTrue($evaluation['bounded_qoq_bs_ap']['evaluated']);
        $this->assertFalse($evaluation['bounded_qoq_bs_ap']['passed']);
        $this->assertSame(
            '0..0.3',
            $evaluation['bounded_qoq_bs_ap']['permitted']
        );

        (new FinancialPlausibilityValidator)->validate(
            $results[2],
            $opening,
            $prior
        );

        $this->addToAssertionCount(1);
    }

    public function test_margin_and_inventory_calibration_fixture_is_representative_for_phase_four(): void
    {
        $results = $this->runChainedSimRules();
        $quarterOne = $results[1];

        $grossMargin = $quarterOne->incomeStatement['gross_profit'] / $quarterOne->incomeStatement['net_revenue'];
        $operatingMargin = $quarterOne->incomeStatement['operating_income'] / $quarterOne->incomeStatement['net_revenue'];
        $inventoryTurnover = $quarterOne->fields['bs_inventory'] > 0
            ? $quarterOne->incomeStatement['cost_of_goods_sold'] / $quarterOne->fields['bs_inventory']
            : INF;

        $this->assertEqualsWithDelta(0.35, $grossMargin, 0.02);
        $this->assertEqualsWithDelta(0.20, $operatingMargin, 0.02);
        $this->assertSame(0.0, $quarterOne->fields['bs_inventory']);
        $this->assertTrue(is_infinite($inventoryTurnover));
        $this->assertSame(0.0, $results[2]->fields['cf_inv_chg']);
        $this->assertSame(0.0, $results[3]->fields['cf_inv_chg']);
    }

    /**
     * @return array<int, ManufacturingEngineResult>
     */
    private function runChainedSimRules(): array
    {
        $parameters = $this->parameters();
        $demand = $this->demand();
        $service = new CfManufacturingSimRulesService;
        $engine = new ManufacturingCFEngine;
        $accounting = new AccountingIdentityValidator;
        $plausibility = new FinancialPlausibilityValidator;
        $opening = OpeningFinancialPosition::fromScenarioParameters($parameters);
        $prior = null;
        $results = [];

        for ($quarter = 1; $quarter <= 3; $quarter++) {
            $operational = $service->deriveCompleteDecisions($quarter, $demand, $parameters);
            $result = $engine->calculate(new ManufacturingEngineInput($quarter, array_merge($operational, $this->fixedElections()), $parameters, $opening));
            $accounting->validate($result->fields, $opening);
            $plausibility->validate($result, $opening, $prior);
            $results[$quarter] = $result;
            $opening = OpeningFinancialPosition::fromPriorEngineResult($result->fields);
            $prior = $result;
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    private function demand(): array
    {
        return [
            'forecast_p1_r1' => 30000,
            'forecast_p1_r2' => 40000,
            'forecast_p1_r3' => 60000,
            'forecast_p2_r1' => 27000,
            'forecast_p2_r2' => 18000,
            'forecast_p2_r3' => 16000,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parameters(): array
    {
        return CfManufacturingScenarioDefinition::parameters();
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function fixedElections(): array
    {
        return [
            'notes_elections' => CfManufacturingScenarioDefinition::fixedWarmupPayload('notes_elections'),
            'certification' => CfManufacturingScenarioDefinition::fixedWarmupPayload('certification'),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function plausibilityViolationsFor(callable $callback): array
    {
        try {
            $callback();
        } catch (PlausibilityValidationException $exception) {
            return $exception->violations();
        }

        $this->fail('Expected a plausibility validation exception.');
    }
}
