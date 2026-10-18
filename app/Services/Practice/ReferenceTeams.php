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

    /** Share of the largest dividend allowed that each team declares on its standard settings. */
    public const DIVIDEND_SHARE = ['careful' => 0.25, 'average' => 0.0, 'careless' => 1.0];

    /** The rule each team follows on its standard settings, in the words faculty see. */
    public const RULES = TeamSettings::STANDARD_RULES;

    private const PRODUCTS = ['p1', 'p2'];

    private const REGIONS = ['r1', 'r2', 'r3'];

    /**
     * A team's decisions for Quarter $quarter (4 or later), every area, from
     * last quarter's engine fields. The caller keeps the areas that are open.
     * Without settings the team plays its standard rules (layer 5, FF3).
     *
     * @param  array<string, float>  $prior  last quarter's engine fields (engine version 2)
     * @param  array<string, mixed>  $parameters
     * @param  array<string, string>  $startingElections  the course's starting methods
     * @param  array<string, mixed>|null  $settings  the team's settings (TeamSettings)
     * @return array{payload: array<string, array<string, mixed>>, notes: array<string, string>}
     */
    public function decide(string $team, int $quarter, array $prior, array $parameters, array $startingElections, ?array $settings = null): array
    {
        if (! in_array($team, self::TEAMS, true)) {
            throw new InvalidArgumentException("Unknown reference team [{$team}].");
        }
        if (! array_key_exists('n2_ord_r1_p1', $prior)) {
            throw new InvalidArgumentException('Reference teams need engine version 2 results to plan from.');
        }
        $s = $settings ?? TeamSettings::STANDARD[$team];

        $forecast = $this->forecast($s, $quarter, $prior, $parameters);
        $payload = ['forecasting' => $forecast];
        $payload['sourcing'] = ['primary_supplier' => $s['supplier'], 'primary_alloc' => 1.0, 'secondary_supplier' => 'NONE'];
        $priceMultiplier = $s['price_pct'] / 100;
        $payload['pricing'] = [];
        foreach (self::PRODUCTS as $product) {
            foreach (self::REGIONS as $region) {
                $payload['pricing']["price_{$product}_{$region}"] = round((float) $parameters["{$product}_base_price"] * $priceMultiplier, 2);
            }
        }
        $payload['marketing'] = ['marketing_budget_pct' => $s['marketing_pct'] / 100];

        $units = [];
        foreach (self::PRODUCTS as $product) {
            $want = 0;
            foreach (self::REGIONS as $region) {
                $want += $forecast["forecast_{$product}_{$region}"];
            }
            $units[$product] = $s['production_nets_stock']
                ? max(0.0, round($want * ($s['production_pct'] / 100) - (float) $prior["n3_units_{$product}"]))
                : (float) round($want * ($s['production_pct'] / 100));
        }
        $payload['production'] = [
            'p1_production' => $units['p1'],
            'p2_production' => $units['p2'],
            'shifts' => (new CfManufacturingSimRulesService)->shiftsFor($units['p1'] + $units['p2'], $parameters),
        ];
        $payload['quality'] = ['inspection_level' => $s['inspection']];
        $payload['logistics'] = ['logistics_mode' => $s['logistics']];

        $notes = $startingElections;
        if ($s['depreciation_method'] !== '' && $quarter >= $s['depreciation_from']) {
            $notes['depreciation_method'] = $s['depreciation_method'];
        }
        if ($s['shipping_terms'] !== '') {
            $notes['shipping_terms'] = $s['shipping_terms'];
        }

        return ['payload' => $payload, 'notes' => $notes];
    }

    /**
     * @param  array<string, mixed>  $s
     * @return array<string, int>
     */
    private function forecast(array $s, int $quarter, array $prior, array $parameters): array
    {
        $out = [];
        foreach (self::PRODUCTS as $product) {
            foreach (self::REGIONS as $region) {
                $value = (float) $prior[$s['forecast_basis'] === 'orders' ? "n2_ord_{$region}_{$product}" : "n2_sold_{$region}_{$product}"];
                if ($s['forecast_season']) {
                    $value = $value / MarketPath::season($parameters, $quarter - 1) * MarketPath::season($parameters, $quarter);
                }
                if ($s['forecast_adjust'] !== 0) {
                    $value = $value * ((100 + $s['forecast_adjust']) / 100);
                }
                $out["forecast_{$product}_{$region}"] = (int) round($value);
            }
        }

        return $out;
    }
}
