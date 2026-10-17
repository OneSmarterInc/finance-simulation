<?php

namespace App\Services\CfPlus;

use InvalidArgumentException;

final class CfPlusValuationConfiguration
{
    public const ANNUAL_REQUIRED_RETURN = 0.12;

    public const QUARTERLY_GROWTH_CAP = 0.02;

    public const FORECAST_QUARTERS = 12;

    public const ANNUAL_TERMINAL_GROWTH = 0.015;

    public const TERMINAL_METHOD = 'gordon_growth';

    public static function quarterlyRate(float $annualRate): float
    {
        if ($annualRate <= -1) {
            throw new InvalidArgumentException('Annual valuation rates must be greater than -100%.');
        }

        return pow(1 + $annualRate, 0.25) - 1;
    }

    /**
     * @return array{
     *   annual_required_return: float,
     *   quarterly_discount_rate: float,
     *   quarterly_growth_cap: float,
     *   forecast_quarters: int,
     *   terminal_method: string,
     *   annual_terminal_growth: float,
     *   quarterly_terminal_growth: float
     * }
     */
    public static function approved(): array
    {
        $discount = self::quarterlyRate(self::ANNUAL_REQUIRED_RETURN);
        $terminalGrowth = self::quarterlyRate(self::ANNUAL_TERMINAL_GROWTH);
        if ($terminalGrowth >= $discount) {
            throw new InvalidArgumentException('Quarterly terminal growth must be below the quarterly discount rate.');
        }

        return [
            'annual_required_return' => self::ANNUAL_REQUIRED_RETURN,
            'quarterly_discount_rate' => $discount,
            'quarterly_growth_cap' => self::QUARTERLY_GROWTH_CAP,
            'forecast_quarters' => self::FORECAST_QUARTERS,
            'terminal_method' => self::TERMINAL_METHOD,
            'annual_terminal_growth' => self::ANNUAL_TERMINAL_GROWTH,
            'quarterly_terminal_growth' => $terminalGrowth,
        ];
    }
}
