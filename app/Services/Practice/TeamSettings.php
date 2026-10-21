<?php

namespace App\Services\Practice;

use App\Support\Presentation\DecisionPresenter;
use App\Support\Scenario\CfManufacturingScenarioDefinition;
use Illuminate\Validation\ValidationException;

/**
 * U9 layer 5 (design note FF3, FF7, FF8): what faculty can change about each
 * reference team. The standard settings are the fixed teams of layers 1 to 4
 * exactly, so a run on standard settings still matches the engine's golden
 * fixtures (tests/Feature/Practice/ReferenceTeamsTest).
 *
 * Percentages are whole numbers (103 means 103%) and are turned into
 * multipliers by dividing by 100, which gives the same figures the fixed
 * rules used (103 / 100 is the same number as 1.03).
 */
final class TeamSettings
{
    public const STANDARD = [
        'careful' => [
            'forecast_basis' => 'orders', 'forecast_season' => true, 'forecast_adjust' => 2,
            'production_pct' => 103, 'production_nets_stock' => true,
            'price_pct' => 100, 'marketing_pct' => 5,
            'supplier' => 'SUP006', 'inspection' => 'BASIC', 'logistics' => 'STANDARD',
            'depreciation_method' => '', 'depreciation_from' => 4, 'shipping_terms' => '',
            'dividend_pct' => 25, 'bid_basis' => 'true_worth', 'bid_pct' => 95,
        ],
        'average' => [
            'forecast_basis' => 'sales', 'forecast_season' => false, 'forecast_adjust' => 0,
            'production_pct' => 100, 'production_nets_stock' => false,
            'price_pct' => 100, 'marketing_pct' => 5,
            'supplier' => 'SUP001', 'inspection' => 'BASIC', 'logistics' => 'EXPRESS',
            'depreciation_method' => 'units_of_production', 'depreciation_from' => 4, 'shipping_terms' => 'fob_destination',
            'dividend_pct' => 0, 'bid_basis' => 'consultant', 'bid_pct' => 100,
        ],
        'careless' => [
            'forecast_basis' => 'orders', 'forecast_season' => false, 'forecast_adjust' => 25,
            'production_pct' => 100, 'production_nets_stock' => false,
            'price_pct' => 90, 'marketing_pct' => 12,
            'supplier' => 'SUP004', 'inspection' => 'NONE', 'logistics' => 'AIR',
            'depreciation_method' => 'double_declining_balance', 'depreciation_from' => 6, 'shipping_terms' => '',
            'dividend_pct' => 100, 'bid_basis' => 'consultant', 'bid_pct' => 125,
        ],
    ];

    /** Whole-number settings and the range each may take. */
    public const RANGES = [
        'forecast_adjust' => [-50, 100],
        'production_pct' => [50, 200],
        'price_pct' => [50, 150],
        'marketing_pct' => [0, 30],
        'depreciation_from' => [4, 40],
        'dividend_pct' => [0, 100],
        'bid_pct' => [0, 200],
    ];

    public const BOOLEANS = ['forecast_season', 'production_nets_stock'];

    /** The standard rules, in the words faculty have seen since layer 2. */
    public const STANDARD_RULES = [
        'careful' => "Plans from last quarter's orders, adjusted for the season, and keeps a small buffer. Buys from India with Basic inspection. Pays a quarter of the dividend allowed.",
        'average' => "Repeats last quarter's sales and ignores the season. Stays with the default supplier and shipping. Uses units of production and FOB destination. Pays no dividend.",
        'careless' => "Plans 25% above last quarter's orders and makes all of it. Cuts prices 10% and spends 12% on marketing. Switches to declining balance. Skips inspection and ships by air. Pays the largest dividend allowed.",
    ];

    /** FF4, in the words shown since layer 4. */
    public const STANDARD_BID_RULES = [
        'careful' => 'Offers 95% of what the stake is truly worth: it reads past reported income.',
        'average' => "Offers the consultant's value of the stake.",
        'careless' => "Offers 25% above the consultant's value of the stake.",
    ];

    /**
     * Every team's settings: stored changes laid over the standard ones.
     *
     * @param  array<string, array<string, mixed>>|null  $stored
     * @return array<string, array<string, mixed>>
     */
    public static function resolve(?array $stored): array
    {
        $out = [];
        foreach (ReferenceTeams::TEAMS as $team) {
            $out[$team] = array_replace(self::STANDARD[$team], array_intersect_key($stored[$team] ?? [], self::STANDARD[$team]));
        }

        return $out;
    }

    /** @param array<string, mixed> $settings */
    public static function isStandard(string $team, array $settings): bool
    {
        return self::differences($team, $settings) === [];
    }

    /**
     * The settings that differ from the team's standard ones, for storing.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function differences(string $team, array $settings): array
    {
        $out = [];
        foreach (self::STANDARD[$team] as $key => $standard) {
            if (array_key_exists($key, $settings) && $settings[$key] !== $standard) {
                $out[$key] = $settings[$key];
            }
        }

        return $out;
    }

    /**
     * Check and tidy all three teams' settings as typed on the page.
     *
     * @param  array<string, array<string, mixed>>  $input
     * @return array<string, array<string, mixed>>
     */
    public static function validate(array $input): array
    {
        $errors = [];
        $out = [];
        foreach (ReferenceTeams::TEAMS as $team) {
            $row = $input[$team] ?? [];
            $clean = [];
            foreach (self::STANDARD[$team] as $key => $standard) {
                $value = $row[$key] ?? $standard;
                $field = "settings.{$team}.{$key}";
                if (in_array($key, self::BOOLEANS, true)) {
                    $clean[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);

                    continue;
                }
                if (isset(self::RANGES[$key])) {
                    [$min, $max] = self::RANGES[$key];
                    if (! is_numeric($value) || (float) $value != (int) $value || (int) $value < $min || (int) $value > $max) {
                        $errors[$field] = self::label($key).' must be a whole number from '.$min.' to '.$max.'.';

                        continue;
                    }
                    $clean[$key] = (int) $value;

                    continue;
                }
                if (! in_array($value, self::choices($key), true)) {
                    $errors[$field] = 'Choose one of the listed options for '.lcfirst(self::label($key)).'.';

                    continue;
                }
                $clean[$key] = $value;
            }
            $out[$team] = $clean;
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    /** @return list<string> */
    public static function choices(string $key): array
    {
        return match ($key) {
            'forecast_basis' => ['orders', 'sales'],
            'supplier' => self::allowed('sourcing', 'primary_supplier'),
            'inspection' => self::allowed('quality', 'inspection_level'),
            'logistics' => self::allowed('logistics', 'logistics_mode'),
            'depreciation_method' => ['', ...self::allowed('notes_elections', 'depreciation_method')],
            'shipping_terms' => ['', ...self::allowed('notes_elections', 'shipping_terms')],
            'bid_basis' => ['true_worth', 'consultant'],
            default => [],
        };
    }

    /** What a choice is called on the page. */
    public static function choiceLabel(string $key, string $value): string
    {
        return match (true) {
            $key === 'forecast_basis' => $value === 'orders' ? "Last quarter's orders" : "Last quarter's sales",
            $key === 'bid_basis' => $value === 'true_worth' ? 'True worth' : "Consultant's value",
            $key === 'supplier' => self::supplierOption($value),
            $value === '' => "Course's starting method",
            default => DecisionPresenter::valueLabel(match ($key) {
                'inspection' => 'inspection_level',
                'logistics' => 'logistics_mode',
                default => $key,
            }, $value),
        };
    }

    public static function label(string $key): string
    {
        return [
            'forecast_basis' => 'Plans from',
            'forecast_season' => 'Allows for the season',
            'forecast_adjust' => 'Adjust the plan by (%)',
            'production_pct' => 'Makes (% of the plan)',
            'production_nets_stock' => 'Uses stock on hand first',
            'price_pct' => 'Price (% of base price)',
            'marketing_pct' => 'Marketing (% of revenue)',
            'supplier' => 'Supplier',
            'inspection' => 'Inspection',
            'logistics' => 'Shipping',
            'depreciation_method' => 'Depreciation',
            'depreciation_from' => 'Depreciation from Quarter',
            'shipping_terms' => 'Shipping terms',
            'dividend_pct' => 'Dividend (% of the most allowed)',
            'bid_basis' => 'Bids from',
            'bid_pct' => 'Bid (% of that)',
        ][$key] ?? $key;
    }

    /** The settings shown first; the rest sit under "More settings". */
    public const MAIN_GROUPS = [
        'Planning' => ['forecast_basis', 'forecast_season', 'forecast_adjust'],
        'Price and marketing' => ['price_pct', 'marketing_pct'],
        'Supply and shipping' => ['supplier', 'inspection', 'logistics'],
    ];

    /**
     * The decision area each setting plays in (live review R17). A practice run
     * plays with the course's own areas, so a setting in an area students don't
     * decide in this course changes nothing: the simulation carries that area
     * forward. The dividend is always decided, so it has no area here.
     * "methods" is open when the course has notes elections or method-change
     * requests; "evaluation" when it has the evaluation stage.
     */
    public const AREAS = [
        'forecast_basis' => 'forecasting', 'forecast_season' => 'forecasting', 'forecast_adjust' => 'forecasting',
        'production_pct' => 'production', 'production_nets_stock' => 'production',
        'price_pct' => 'pricing', 'marketing_pct' => 'marketing',
        'supplier' => 'sourcing', 'inspection' => 'quality', 'logistics' => 'logistics',
        'depreciation_method' => 'methods', 'depreciation_from' => 'methods', 'shipping_terms' => 'methods',
        'bid_basis' => 'evaluation', 'bid_pct' => 'evaluation',
    ];

    /** Each area as faculty read it in "Pricing isn't open to students in this course". */
    public const AREA_WORDS = [
        'forecasting' => 'planning', 'production' => 'production', 'pricing' => 'pricing',
        'marketing' => 'marketing', 'sourcing' => 'the supplier', 'quality' => 'inspection',
        'logistics' => 'shipping', 'methods' => 'accounting methods', 'evaluation' => 'bidding',
    ];

    /**
     * Whether a setting changes anything in a course whose open areas are
     * $open (null means every area is open). The plan also drives how much a
     * team makes, so planning counts when production is open.
     *
     * @param  list<string>|null  $open
     */
    public static function takesEffect(string $key, ?array $open): bool
    {
        $area = self::AREAS[$key] ?? null;
        if ($open === null || $area === null) {
            return true;
        }
        if ($area === 'forecasting') {
            return in_array('forecasting', $open, true) || in_array('production', $open, true);
        }

        return in_array($area, $open, true);
    }

    /** @param list<string>|null $open */
    public static function allOpen(?array $open): bool
    {
        return $open === null || array_diff(array_unique(array_values(self::AREAS)), $open) === [];
    }

    /**
     * The changes from the standard rules that take effect in the course.
     *
     * @param  array<string, mixed>  $settings
     * @param  list<string>|null  $open
     * @return array<string, mixed>
     */
    public static function effectiveDifferences(string $team, array $settings, ?array $open): array
    {
        return array_filter(self::differences($team, $settings), fn (string $key): bool => self::takesEffect($key, $open), ARRAY_FILTER_USE_KEY);
    }

    /**
     * "Pricing and marketing aren't open to students in this course, so the
     * simulation sets them.", for the settings in $keys that change nothing.
     *
     * @param  list<string>  $keys
     * @param  list<string>|null  $open
     */
    public static function closedNote(array $keys, ?array $open): ?string
    {
        $areas = [];
        foreach ($keys as $key) {
            if (! self::takesEffect($key, $open)) {
                $areas[self::AREAS[$key]] = self::AREA_WORDS[self::AREAS[$key]];
            }
        }
        if ($areas === []) {
            return null;
        }
        $words = array_values($areas);
        $list = count($words) === 1 ? $words[0] : implode(', ', array_slice($words, 0, -1)).' and '.end($words);
        $plural = count($words) > 1 || $words[0] === 'accounting methods';

        return ucfirst($list).($plural ? " aren't" : " isn't").' open to students in this course, so the simulation sets '.($plural ? 'them' : 'it').'.';
    }

    public const MORE_GROUPS = [
        'Making' => ['production_pct', 'production_nets_stock'],
        'Accounting methods' => ['depreciation_method', 'depreciation_from', 'shipping_terms'],
        'Dividend' => ['dividend_pct'],
        'Bids in the evaluation stage' => ['bid_basis', 'bid_pct'],
    ];

    /**
     * What changed from the team's standard rules, in a few words, for the
     * Custom badge: "plans like Careful, buys from India". Only changes that
     * take effect in the course are named (R17).
     *
     * @param  array<string, mixed>  $s
     * @param  list<string>|null  $open
     */
    public static function changeSummary(string $team, array $s, ?array $open = null): string
    {
        $changed = self::effectiveDifferences($team, $s, $open);
        if ($changed === []) {
            return '';
        }
        $has = fn (array $keys): bool => array_intersect_key($changed, array_flip($keys)) !== [];
        $parts = [];
        $planning = ['forecast_basis', 'forecast_season', 'forecast_adjust'];
        if ($has($planning)) {
            $like = null;
            foreach (ReferenceTeams::TEAMS as $other) {
                if ($other !== $team && array_intersect_key(self::STANDARD[$other], array_flip($planning)) == array_intersect_key($s, array_flip($planning))) {
                    $like = ReferenceTeams::NAMES[$other];
                }
            }
            $parts[] = $like
                ? 'plans like '.str_replace(' team', '', $like)
                : 'plans from '.($s['forecast_basis'] === 'orders' ? 'orders' : 'sales').($s['forecast_season'] ? ' with the season' : '').($s['forecast_adjust'] !== 0 ? ' '.($s['forecast_adjust'] > 0 ? '+' : '−').abs($s['forecast_adjust']).'%' : '');
        }
        if ($has(['production_pct', 'production_nets_stock'])) {
            $parts[] = 'makes '.$s['production_pct'].'%'.($s['production_nets_stock'] ? ' less stock' : '');
        }
        if (isset($changed['price_pct'])) {
            $parts[] = 'prices at '.$s['price_pct'].'%';
        }
        if (isset($changed['marketing_pct'])) {
            $parts[] = $s['marketing_pct'].'% marketing';
        }
        if (isset($changed['supplier'])) {
            $parts[] = 'buys from '.DecisionPresenter::supplierName($s['supplier']);
        }
        if (isset($changed['inspection'])) {
            $parts[] = $s['inspection'] === 'NONE' ? 'no inspection' : strtolower(DecisionPresenter::valueLabel('inspection_level', $s['inspection'])).' inspection';
        }
        if (isset($changed['logistics'])) {
            $parts[] = $s['logistics'] === 'AIR' ? 'ships by air' : strtolower(DecisionPresenter::valueLabel('logistics_mode', $s['logistics'])).' shipping';
        }
        if ($has(['depreciation_method', 'depreciation_from', 'shipping_terms'])) {
            $parts[] = 'other methods';
        }
        if (isset($changed['dividend_pct'])) {
            $parts[] = 'pays '.$s['dividend_pct'].'% dividend';
        }
        if ($has(['bid_basis', 'bid_pct'])) {
            $parts[] = 'bids '.$s['bid_pct'].'% of '.($s['bid_basis'] === 'true_worth' ? 'true worth' : "the consultant's value");
        }
        if (count($parts) > 3) {
            $more = count($parts) - 2;
            $parts = [...array_slice($parts, 0, 2), $more.' more changes'];
        }

        return implode(', ', $parts);
    }

    /**
     * The team's rule in plain words: the standard wording when nothing has
     * changed and every area is open, otherwise a description of the settings
     * that take effect in the course (R17). Each option has its own phrase, so
     * no card reads "with None inspection" (R18).
     *
     * @param  array<string, mixed>  $s
     * @param  list<string>|null  $open
     */
    public static function describe(string $team, array $s, ?array $open = null): string
    {
        if (self::allOpen($open) && self::isStandard($team, $s)) {
            return self::STANDARD_RULES[$team];
        }
        $on = fn (string $key): bool => self::takesEffect($key, $open);
        $parts = [];
        if ($on('forecast_basis')) {
            $plan = $s['forecast_basis'] === 'orders' ? "last quarter's orders" : "last quarter's sales";
            $parts[] = 'Plans from '.$plan.($s['forecast_season'] ? ', adjusted for the season' : '').self::adjust($s['forecast_adjust']).'.';
        }
        if ($on('production_pct')) {
            $parts[] = 'Makes '.$s['production_pct'].'% of the plan'.($s['production_nets_stock'] ? ', less the stock on hand' : '').'.';
        }
        $selling = [];
        if ($on('price_pct')) {
            $selling[] = 'prices at '.$s['price_pct'].'% of the base price';
        }
        if ($on('marketing_pct')) {
            $selling[] = 'spends '.$s['marketing_pct'].'% of revenue on marketing';
        }
        if ($selling !== []) {
            $parts[] = ucfirst(implode(' and ', $selling)).'.';
        }
        $inspection = $s['inspection'] === 'NONE' ? 'no inspection' : DecisionPresenter::valueLabel('inspection_level', $s['inspection']).' inspection';
        $shipping = $s['logistics'] === 'AIR' ? 'air freight' : strtolower(DecisionPresenter::valueLabel('logistics_mode', $s['logistics'])).' shipping';
        $with = array_values(array_filter([$on('inspection') ? $inspection : null, $on('logistics') ? $shipping : null]));
        if ($on('supplier')) {
            $parts[] = 'Buys from '.DecisionPresenter::supplierName($s['supplier']).($with !== [] ? ' with '.implode(' and ', $with) : '').'.';
        } elseif ($with !== []) {
            $parts[] = 'Uses '.implode(' and ', $with).'.';
        }
        if ($on('depreciation_method')) {
            if ($s['depreciation_method'] === '' && $s['shipping_terms'] === '') {
                $parts[] = "Keeps the course's starting methods.";
            } else {
                $methods = [];
                if ($s['depreciation_method'] !== '') {
                    $methods[] = lcfirst(DecisionPresenter::valueLabel('depreciation_method', $s['depreciation_method'])).' depreciation'.($s['depreciation_from'] > 4 ? ' from Quarter '.$s['depreciation_from'] : '');
                }
                if ($s['shipping_terms'] !== '') {
                    $methods[] = DecisionPresenter::valueLabel('shipping_terms', $s['shipping_terms']).' terms';
                }
                $parts[] = 'Uses '.implode(' and ', $methods).'.';
            }
        }
        $parts[] = match (true) {
            $s['dividend_pct'] === 0 => 'Pays no dividend.',
            $s['dividend_pct'] === 100 => 'Pays the largest dividend allowed.',
            default => 'Pays '.$s['dividend_pct'].'% of the largest dividend allowed.',
        };

        return implode(' ', $parts);
    }

    /** @param array<string, mixed> $s */
    public static function describeBid(string $team, array $s): string
    {
        if ($s['bid_basis'] === self::STANDARD[$team]['bid_basis'] && $s['bid_pct'] === self::STANDARD[$team]['bid_pct']) {
            return self::STANDARD_BID_RULES[$team];
        }

        return 'Offers '.$s['bid_pct'].'% of '.($s['bid_basis'] === 'true_worth' ? 'what the stake is truly worth.' : "the consultant's value of the stake.");
    }

    /** "India ($110, 2.8% defects)": short enough for a narrow dropdown. */
    private static function supplierOption(string $code): string
    {
        $p = CfManufacturingScenarioDefinition::parameters();
        $key = 'supplier_'.strtolower($code).'_';

        return sprintf('%s ($%s, %s%% defects)', $p[$key.'name'] ?? $code, number_format((float) ($p[$key.'cost'] ?? 0), 0), number_format((float) ($p[$key.'defect_rate'] ?? 0) * 100, 1));
    }

    private static function adjust(int $pct): string
    {
        return match (true) {
            $pct > 0 => ', plus '.$pct.'%',
            $pct < 0 => ', less '.abs($pct).'%',
            default => '',
        };
    }

    /** @return list<string> */
    private static function allowed(string $category, string $key): array
    {
        foreach (CfManufacturingScenarioDefinition::inputFieldsForCategory($category)['fields'] as $field) {
            if ($field['key'] === $key) {
                return array_values(array_filter($field['allowed_values'] ?? [], fn ($v) => $v !== 'NONE' || $key !== 'primary_supplier'));
            }
        }

        return [];
    }
}
