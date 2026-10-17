<?php

namespace Tests\Unit\Services\Engines\CorporateFinance;

use App\Services\Engines\CorporateFinance\AccountingIdentityValidator;
use App\Services\Engines\CorporateFinance\CfManufacturingSimRulesService;
use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngineInput;
use App\Services\Engines\CorporateFinance\Contracts\OpeningFinancialPosition;
use App\Services\Engines\CorporateFinance\Exceptions\EngineCalculationException;
use App\Services\Engines\CorporateFinance\ManufacturingCFEngine;
use App\Support\Scenario\CfManufacturingScenarioDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ManufacturingCFOverdraftTest extends TestCase
{
    public function test_literal_overdraft_oracle_reconciles_statements_and_shortfall_fields(): void
    {
        [$result, $opening] = $this->overdraftResult();

        $this->assertSame(0.0, $result->fields['bs_cash']);
        $this->assertSame(2344121.75, $result->fields['bs_bank_overdraft']);
        $this->assertSame(1000.0, $result->fields['cf_borrow_proc']);
        $this->assertSame(2344121.75, $result->fields['cf_overdraft_chg']);
        $this->assertSame(2344121.75, $result->fields['n5_overdraft']);
        $this->assertSame(1.0, $result->fields['n5_shortfall_indicator']);
        $this->assertSame(2345121.75, $result->cashFlowStatement['financing_cash_flow']);
        $this->assertEqualsWithDelta(0.0, $result->balanceSheet['assets'] - ($result->balanceSheet['liabilities'] + $result->balanceSheet['equity']), 0.01);
        $this->assertEqualsWithDelta(0.0, ($result->fields['bs_cash'] - $opening->cash) - array_sum([$result->cashFlowStatement['operating_cash_flow'], $result->cashFlowStatement['investing_cash_flow'], $result->cashFlowStatement['financing_cash_flow']]), 0.01);
    }

    public function test_no_overdraft_oracle_is_explicit(): void
    {
        $parameters = CfManufacturingScenarioDefinition::parameters();
        $demand = CfManufacturingScenarioDefinition::q0ForecastingPayload();
        $decisions = $this->completeDecisions($demand, $parameters);
        $opening = OpeningFinancialPosition::fromScenarioParameters($parameters);
        $result = (new ManufacturingCFEngine)->calculate(new ManufacturingEngineInput(1, $decisions, $parameters, $opening));

        $this->assertSame(0.0, $result->fields['bs_bank_overdraft']);
        $this->assertSame(0.0, $result->fields['cf_overdraft_chg']);
        $this->assertSame(0.0, $result->fields['n5_overdraft']);
        $this->assertSame(0.0, $result->fields['n5_shortfall_indicator']);
        $this->assertGreaterThanOrEqual(0.0, $result->fields['bs_cash']);
    }

    #[DataProvider('incoherentMutations')]
    public function test_incoherent_overdraft_mutations_fail_loudly(string $field, float $delta, string $message): void
    {
        [$result, $opening] = $this->overdraftResult();
        $fields = $result->fields;
        $fields[$field] += $delta;

        $this->expectException(EngineCalculationException::class);
        $this->expectExceptionMessage($message);
        (new AccountingIdentityValidator)->validate($fields, $opening);
    }

    public static function incoherentMutations(): array
    {
        return [
            'balance-sheet overdraft' => ['bs_bank_overdraft', 1.0, 'Overdraft amount coherence failed'],
            'financing movement' => ['cf_overdraft_chg', 1.0, 'Overdraft financing movement failed'],
            'committed debt' => ['bs_ltd', 1.0, 'Balance-sheet identity failed'],
        ];
    }

    private function overdraftResult(): array
    {
        $parameters = CfManufacturingScenarioDefinition::parameters();
        foreach (['credit_tier_excellent_limit', 'credit_tier_good_limit', 'credit_tier_fair_limit', 'credit_tier_poor_limit', 'credit_tier_distressed_limit'] as $key) {
            $parameters[$key] = 1000;
        }
        $demand = array_fill_keys(CfManufacturingScenarioDefinition::q0ForecastingPayload() ? array_keys(CfManufacturingScenarioDefinition::q0ForecastingPayload()) : [], 100);
        $decisions = $this->completeDecisions($demand, $parameters);
        $decisions['production']['p1_production'] = 300;
        $decisions['production']['p2_production'] = 300;
        foreach (['price_p1_r1', 'price_p1_r2', 'price_p1_r3'] as $field) {
            $decisions['pricing'][$field] = 210;
        }
        foreach (['price_p2_r1', 'price_p2_r2', 'price_p2_r3'] as $field) {
            $decisions['pricing'][$field] = 280;
        }
        $decisions['marketing']['marketing_budget_pct'] = 0.20;
        $decisions['quality']['inspection_level'] = 'FULL';
        $decisions['logistics']['logistics_mode'] = 'AIR';
        $opening = OpeningFinancialPosition::fromScenarioParameters($parameters);

        return [(new ManufacturingCFEngine)->calculate(new ManufacturingEngineInput(1, $decisions, $parameters, $opening)), $opening];
    }

    private function completeDecisions(array $demand, array $parameters): array
    {
        return array_merge((new CfManufacturingSimRulesService)->deriveCompleteDecisions(1, $demand, $parameters), [
            'notes_elections' => CfManufacturingScenarioDefinition::fixedWarmupPayload('notes_elections'),
            'certification' => CfManufacturingScenarioDefinition::fixedWarmupPayload('certification'),
        ]);
    }
}
