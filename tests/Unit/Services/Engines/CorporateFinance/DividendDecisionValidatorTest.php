<?php

namespace Tests\Unit\Services\Engines\CorporateFinance;

use App\Services\Engines\CorporateFinance\CfManufacturingSimRulesService;
use App\Services\Engines\CorporateFinance\DividendDecisionValidator;
use App\Services\Engines\CorporateFinance\Exceptions\EngineCalculationException;
use PHPUnit\Framework\TestCase;

class DividendDecisionValidatorTest extends TestCase
{
    public function test_literal_earnings_and_cash_caps_and_exact_boundaries(): void
    {
        $validator = new DividendDecisionValidator;

        $cashLimited = $validator->validate(4, 250000, 2000000, 500000, 1250000, 1000000);
        $this->assertSame(2500000.0, $cashLimited->distributableRetainedEarnings);
        $this->assertSame(250000.0, $cashLimited->cashAvailableAboveBuffer);
        $this->assertSame(250000.0, $cashLimited->maximum);
        $this->assertSame(250000.0, $cashLimited->requested);

        $earningsLimited = $validator->validate(4, 300000, 200000, 100000, 5000000, 1000000);
        $this->assertSame(300000.0, $earningsLimited->maximum);
        $this->assertSame(300000.0, $earningsLimited->requested);

        $this->assertSame(0.0, $validator->validate(4, 0, -500000, 100000, 5000000, 1000000)->maximum);
    }

    public function test_one_cent_above_cash_cap_is_rejected_with_maximum(): void
    {
        $this->expectException(EngineCalculationException::class);
        $this->expectExceptionMessage('250000.01 exceeds the maximum permitted dividend 250000.00');

        (new DividendDecisionValidator)->validate(4, 250000.01, 2000000, 500000, 1250000, 1000000);
    }

    public function test_one_cent_above_earnings_cap_is_rejected(): void
    {
        $this->expectException(EngineCalculationException::class);
        $this->expectExceptionMessage('300000.01 exceeds the maximum permitted dividend 300000.00');

        (new DividendDecisionValidator)->validate(4, 300000.01, 200000, 100000, 5000000, 1000000);
    }

    public function test_deficit_and_below_buffer_cases_allow_only_zero(): void
    {
        $validator = new DividendDecisionValidator;

        $this->assertSame(0.0, $validator->validate(4, 0, -1000000, 500000, 5000000, 1000000)->maximum);
        $this->assertSame(0.0, $validator->validate(4, 0, 5000000, 500000, 900000, 1000000)->maximum);

        foreach ([
            [-1000000, 500000, 5000000],
            [5000000, 500000, 900000],
        ] as [$retained, $income, $cash]) {
            try {
                $validator->validate(4, 0.01, $retained, $income, $cash, 1000000);
                $this->fail('Expected a positive dividend to be rejected.');
            } catch (EngineCalculationException $exception) {
                $this->assertStringContainsString('maximum permitted dividend 0.00', $exception->getMessage());
            }
        }
    }

    public function test_negative_and_non_numeric_dividends_are_rejected(): void
    {
        $validator = new DividendDecisionValidator;

        foreach ([-0.01, -0.001, 'not-money'] as $invalid) {
            try {
                $validator->validate(4, $invalid, 1000000, 1000000, 5000000, 1000000);
                $this->fail('Expected invalid dividend input to be rejected.');
            } catch (EngineCalculationException $exception) {
                $this->assertStringContainsString('Dividend must be', $exception->getMessage());
            }
        }
    }

    public function test_quarters_one_through_three_reject_positive_dividends(): void
    {
        foreach ([1, 2, 3] as $quarter) {
            try {
                (new DividendDecisionValidator)->validate($quarter, 0.01, 10000000, 10000000, 50000000, 1000000);
                $this->fail("Expected Quarter {$quarter} dividend to be rejected.");
            } catch (EngineCalculationException $exception) {
                $this->assertStringContainsString("maximum permitted dividend 0.00 for Quarter {$quarter}", $exception->getMessage());
            }
        }
    }

    public function test_sim_rules_default_zero_without_changing_operational_categories(): void
    {
        $service = new CfManufacturingSimRulesService;

        $this->assertSame(0.0, $service->deriveDividend(1, true, 999));
        $this->assertSame(0.0, $service->deriveDividend(2, true, 999));
        $this->assertSame(0.0, $service->deriveDividend(3, true, 999));
        $this->assertSame(0.0, $service->deriveDividend(4));
        $this->assertSame(500000.0, $service->deriveDividend(4, true, 500000));
    }
}
