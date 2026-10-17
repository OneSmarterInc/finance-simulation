<?php

namespace App\Services\Engines\CorporateFinance;

final class CurrencyRounding
{
    public static function cents(float $value): float
    {
        return round($value, 2, PHP_ROUND_HALF_UP);
    }
}
