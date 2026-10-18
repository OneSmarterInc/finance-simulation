<?php

namespace App\Services\Practice;

use App\Services\Engines\CorporateFinance\CfManufacturingSimRulesService;
use App\Services\Engines\CorporateFinance\MarketPath;
use InvalidArgumentException;

/**
 * The three reference teams that play a practice run (U9, design note FF3).
 *
 * Port of economics/cfmodel/teams.py: the same rules play the engine's golden
 * fixtures, so tests/Feature/Practice/ReferenceTeamsTest holds this class to
 * the decisions recorded there. Change the Python first, rebuild the
 * fixtures, then change this class to match.
 *
 * Each team decides from what the screens show it: last quarter's revenue
 * note (units ordered and sold) and inventory note (units left in stock),
 * and the season table.
 */
final class ReferenceTeams
{
    public const TEAMS = ['careful', 'average', 'careless'];

    public const NAMES = ['careful' => 'Careful team', 'average' => 'Average team', 'careless' => 'Careless team'];

    /** Share of the largest dividend allowed that each team declares. */
    public const DIVIDEND_SHARE = ['careful' => 0.25, 'average' => 0.0, 'careless' => 1.0];

    /** The rule each team follows, in the words faculty see. */
    public const RULES = [
        'careful' => "Plans from last quarter's orders, adjusted for the season, and keeps a small buffer. Buys from India with Basic inspection. Pays a quarter of the dividend allowed.",
        'average' => "Repeats last quarter's sales and ignores the season. Stays with the default supplier and shipping. Uses units of production and FOB destination. Pays no dividend.",
        'careless' => "Plans 25% above last quarter's orders and makes all of it. Cuts prices 10% and spends 12% on marketing. Switches to declining balance. Skips inspection and ships by air. Pays the largest dividend allowed.",
    ];

    private const PRODUCTS = ['p1', 'p2'];

    private const REGIONS = ['r1', 'r2', 'r3'];

    /**
     * A team's decisions for Quarter $quarter (4 or later), every area, from
     * last quarter's engine fields. The caller keeps the areas that are open.
     *
     * @param  array<string, float>  $prior  last quarter's engine fields (engine version 2)
     * @param  array<string, mixed>  $parameters
     * @param  array<string, string>  $startingElections  the course's starting methods
     * @return array{payload: array<string, array<string, mixed>>, notes: array<string, string>}
     */
    public function decide(string $team, int $quarter, array $prior, array $parameters, array $startingElections): array
    {
        if (! in_array($team, self::TEAMS, true)) {
            throw new InvalidArgumentException("Unknown reference team [{$team}].");
        }
        if (! array_key_exists('n2_ord_r1_p1', $prior)) {
            throw new InvalidArgumentException('Reference teams need engine version 2 results to plan from.');
        }

        $forecast = $this->forecast($team, $quarter, $prior, $parameters);
        $payload = ['forecasting' => $forecast];
        $payload['sourcing'] = [
            'careful' => ['primary_supplier' => 'SUP006', 'primary_alloc' => 1.0, 'secondary_supplier' => 'NONE'],
            'average' => ['primary_supplier' => 'SUP001', 'primary_alloc' => 1.0, 'secondary_supplier' => 'NONE'],
            'careless' => ['primary_supplier' => 'SUP004', 'primary_alloc' => 1.0, 'secondary_supplier' => 'NONE'],
        ][$team];
        $priceMultiplier = $team === 'careless' ? 0.90 : 1.0;
        $payload['pricing'] = [];
        foreach (self::PRODUCTS as $product) {
            foreach (self::REGIONS as $region) {
                $payload['pricing']["price_{$product}_{$region}"] = round((float) $parameters["{$product}_base_price"] * $priceMultiplier, 2);
            }
        }
        $payload['marketing'] = ['marketing_budget_pct' => $team === 'careless' ? 0.12 : 0.05];

        $units = [];
        foreach (self::PRODUCTS as $product) {
            $want = 0;
            foreach (self::REGIONS as $region) {
                $want += $forecast["forecast_{$product}_{$region}"];
            }
            $units[$product] = $team === 'careful'
                ? max(0.0, round($want * 1.03 - (float) $prior["n3_units_{$product}"]))
                : (float) $want;
        }
        $payload['production'] = [
            'p1_production' => $units['p1'],
            'p2_production' => $units['p2'],
            'shifts' => (new CfManufacturingSimRulesService)->shiftsFor($units['p1'] + $units['p2'], $parameters),
        ];
        $payload['quality'] = ['inspection_level' => ['careful' => 'BASIC', 'average' => 'BASIC', 'careless' => 'NONE'][$team]];
        $payload['logistics'] = ['logistics_mode' => ['careful' => 'STANDARD', 'average' => 'EXPRESS', 'careless' => 'AIR'][$team]];

        $notes = $startingElections;
        if ($team === 'average') {
            $notes['depreciation_method'] = 'units_of_production';
            $notes['shipping_terms'] = 'fob_destination';
        }
        if ($team === 'careless' && $quarter >= 6) {
            $notes['depreciation_method'] = 'double_declining_balance';
        }

        return ['payload' => $payload, 'notes' => $notes];
    }

    /** @return array<string, int> */
    private function forecast(string $team, int $quarter, array $prior, array $parameters): array
    {
        $out = [];
        foreach (self::PRODUCTS as $product) {
            foreach (self::REGIONS as $region) {
                $ordered = (float) $prior["n2_ord_{$region}_{$product}"];
                $sold = (float) $prior["n2_sold_{$region}_{$product}"];
                $value = match ($team) {
                    'careful' => $ordered / MarketPath::season($parameters, $quarter - 1) * MarketPath::season($parameters, $quarter) * 1.02,
                    'average' => $sold,
                    'careless' => $ordered * 1.25,
                };
                $out["forecast_{$product}_{$region}"] = (int) round($value);
            }
        }

        return $out;
    }
}
