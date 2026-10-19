<?php

namespace App\Support\Explainers;

use App\Services\Tour\TourFigures;
use App\Support\Presentation\AccountingMethodGuide;

/**
 * U4 layer 2 (MO1 to MO3): two short animated explainers, played in the
 * app. "How a quarter works" walks a team through its first quarter;
 * "One choice, four statements" follows a change of depreciation method
 * through the income statement, balance sheet, cash flow statement and
 * notes. Every figure comes from the stored tour figures, a practice run
 * of a reference course played by the same simulation a class uses
 * (TourFigures), so the numbers are real and change only when the figures
 * are written again. Every sentence a student reads is here.
 */
final class Explainers
{
    public const QUARTER = 'how-a-quarter-works';

    public const METHODS = 'one-choice-four-statements';

    public const SOURCE = 'Figures from a sample course, played by the same simulation your class uses.';

    /** @return list<string> */
    public static function keys(): array
    {
        return [self::QUARTER, self::METHODS];
    }

    /**
     * @param  array<string, mixed>|null  $x  the tour figures (loaded when null)
     * @return array<string, array{key: string, title: string, summary: string, scenes: list<array<string, mixed>>}>
     */
    public static function all(?array $x = null): array
    {
        $x ??= TourFigures::load();

        return [self::QUARTER => self::quarter($x), self::METHODS => self::methods($x)];
    }

    /**
     * @param  array<string, mixed>  $x
     * @return array{key: string, title: string, summary: string, scenes: list<array<string, mixed>>}
     */
    private static function quarter(array $x): array
    {
        $c = $x['careful'];
        $q3 = $c['3'];
        $q4 = $c['4'];
        $forecast = $x['decisions']['forecast'];
        $allUp = collect($forecast)->every(fn ($row) => $row['value'] > $row['last_orders']);
        $unfilled = max(0, (int) $q4['ordered'] - (int) $q4['sold']);

        $close = 'At the deadline, the simulation plays the quarter. Customers ordered '.self::units($q4['ordered']).' units and the firm sold '.self::units($q4['sold']).'.';
        if ($q4['stock'] > 0) {
            $close .= ' '.self::units($q4['stock']).' units it made were left in stock.';
        } elseif ($unfilled > 0) {
            $close .= ' It sold everything it made, so '.self::units($unfilled).' orders went unfilled.';
        }

        return [
            'key' => self::QUARTER,
            'title' => 'How a quarter works',
            'summary' => 'Six steps, about a minute: the firm you take over, the statements you read, the decisions you make, and what happens at the deadline.',
            'scenes' => [
                [
                    'title' => 'You take over a firm',
                    'caption' => 'Your team runs a firm that has already traded for three quarters. Those quarters are on your History page, so you start with a record to read, not a blank page.',
                    'visual' => 'history',
                    'bars' => array_map(fn ($q) => ['label' => 'Quarter '.$q, 'value' => $c[(string) $q]['revenue'], 'text' => self::m($c[(string) $q]['revenue']), 'note' => 'Net income '.self::m($c[(string) $q]['net_income'])], [1, 2, 3]),
                    'measure' => 'Revenue',
                ],
                [
                    'title' => 'Four statements every quarter',
                    'caption' => 'Each quarter gives you an income statement, a balance sheet, a cash flow statement and notes. Reading them, and explaining why they moved, is what the course is about.',
                    'visual' => 'statements',
                    'cards' => [
                        ['name' => 'Income statement', 'line' => 'Net income, Quarter 3', 'value' => self::usd($q3['net_income']), 'what' => 'What the firm earned.'],
                        ['name' => 'Balance sheet', 'line' => 'Cash at the end of Quarter 3', 'value' => self::usd($q3['cash']), 'what' => 'What it owns and owes.'],
                        ['name' => 'Cash flow statement', 'line' => 'Cash, Quarter 2 to Quarter 3', 'value' => self::m($c['2']['cash']).' to '.self::m($q3['cash']), 'what' => 'Where the cash came from and went.'],
                        ['name' => 'Notes', 'line' => 'Depreciation method', 'value' => 'Straight-line', 'what' => 'The methods and detail behind the numbers.'],
                    ],
                ],
                [
                    'title' => 'Decide, starting from last quarter',
                    'caption' => 'Every decision arrives filled in with last quarter\'s values, so your team can submit with a few changes, or dig into the full controls.'
                        .($allUp ? ' Here the team set its Quarter 4 forecasts above last quarter\'s orders.' : ''),
                    'visual' => 'forecast',
                    'rows' => array_map(fn ($row) => ['label' => $row['label'], 'last' => self::units($row['last_orders']), 'value' => self::units($row['value']), 'up' => $row['value'] > $row['last_orders']], $forecast),
                ],
                [
                    'title' => 'Submit by the deadline',
                    'caption' => 'Anyone on the team can save a draft. One person submits for the team, and you can change it until the deadline. If nothing is submitted, last quarter\'s decisions are used again.',
                    'visual' => 'deadline',
                    'steps' => ['Save a draft', 'Submit for the team', 'The deadline'],
                ],
                [
                    'title' => 'The quarter closes',
                    'caption' => $close,
                    'visual' => 'close',
                    'bars' => array_values(array_filter([
                        ['label' => 'Ordered', 'value' => (float) $q4['ordered'], 'text' => self::units($q4['ordered'])],
                        ['label' => 'Sold', 'value' => (float) $q4['sold'], 'text' => self::units($q4['sold'])],
                        $q4['stock'] > 0 ? ['label' => 'Left in stock', 'value' => (float) $q4['stock'], 'text' => self::units($q4['stock'])] : null,
                        $q4['stock'] <= 0 && $unfilled > 0 ? ['label' => 'Not filled', 'value' => (float) $unfilled, 'text' => self::units($unfilled)] : null,
                    ])),
                    'measure' => 'Units, Quarter 4',
                ],
                [
                    'title' => 'New statements to explain',
                    'caption' => 'Quarter 4\'s statements appear on your dashboard and History, beside Quarter 3. Your instructor may ask your team to explain what changed and why before you decide Quarter 5.',
                    'visual' => 'compare',
                    'columns' => ['Quarter 3', 'Quarter 4'],
                    'rows' => [
                        self::row('Revenue', $q3['revenue'], $q4['revenue']),
                        self::row('Net income', $q3['net_income'], $q4['net_income']),
                        self::row('Cash', $q3['cash'], $q4['cash']),
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $x
     * @return array{key: string, title: string, summary: string, scenes: list<array<string, mixed>>}
     */
    private static function methods(array $x): array
    {
        $e = $x['depreciation_explainer'];
        $sl = $e['straight_line'];
        $db = $e['double_declining'];
        $q = (int) $e['quarter'];
        $d = fn (string $key): float => round($db[$key] - $sl[$key], 2);
        $columns = ['Straight-line', 'Double-declining balance'];

        $balance = 'The plant is carried at '.self::usd(-$d('net_plant')).' less, the extra depreciation. Equity is '.self::usd(-$d('equity')).' lower, the same amount as net income.';
        if (abs($d('inventory')) >= 0.01) {
            $balance .= ' Inventory is '.self::usd($d('inventory')).' higher, because part of the factory\'s depreciation is in the cost of units still in stock.';
        }
        $balanceRows = [self::row('Plant and equipment, net', $sl['net_plant'], $db['net_plant'])];
        if (abs($d('inventory')) >= 0.01) {
            $balanceRows[] = self::row('Inventory', $sl['inventory'], $db['inventory']);
        }
        $balanceRows[] = self::row('Cash', $sl['cash'], $db['cash']);
        $balanceRows[] = self::row('Equity', $sl['equity'], $db['equity']);

        return [
            'key' => self::METHODS,
            'title' => 'One choice, four statements',
            'summary' => 'Six steps, about a minute: one firm\'s Quarter '.$q.' played twice, once on straight-line depreciation and once on double-declining balance, with every other decision the same.',
            'scenes' => [
                [
                    'title' => 'One choice',
                    'caption' => 'Same firm, same quarter, same decisions. The only difference is how the cost of the plant is spread. Straight-line spreads it evenly; double-declining balance charges more in the early years. Quarter '.$q.'\'s depreciation is '.self::usd($sl['depreciation']).' one way and '.self::usd($db['depreciation']).' the other.',
                    'visual' => 'choice',
                    'bars' => [
                        ['label' => 'Straight-line', 'value' => $sl['depreciation'], 'text' => self::usd($sl['depreciation'])],
                        ['label' => 'Double-declining balance', 'value' => $db['depreciation'], 'text' => self::usd($db['depreciation'])],
                    ],
                    'measure' => 'Depreciation, Quarter '.$q,
                ],
                [
                    'title' => 'The income statement',
                    'caption' => 'The extra depreciation lands in cost of goods sold (the factory\'s share) and in operating expenses (the rest). Net income falls by '.self::usd(-$d('net_income')).'. Income tax falls by '.self::usd(-$d('income_tax')).', because there is less profit to tax.',
                    'visual' => 'compare',
                    'columns' => $columns,
                    'rows' => [
                        self::row('Cost of goods sold', $sl['cost_of_goods_sold'], $db['cost_of_goods_sold']),
                        self::row('Operating expenses', $sl['operating_expenses'], $db['operating_expenses']),
                        self::row('Income before taxes', $sl['income_before_taxes'], $db['income_before_taxes']),
                        self::row('Income tax', $sl['income_tax'], $db['income_tax']),
                        self::row('Net income', $sl['net_income'], $db['net_income']),
                    ],
                ],
                [
                    'title' => 'The balance sheet',
                    'caption' => $balance,
                    'visual' => 'compare',
                    'columns' => $columns,
                    'rows' => $balanceRows,
                ],
                [
                    'title' => 'The cash flow statement',
                    'caption' => 'Depreciation isn\'t paid in cash, so the cash flow statement adds it back to net income. That\'s why lower profit comes with more cash here: the firm pays '.self::usd(-$d('income_tax')).' less tax, and net cash from operating activities is '.self::usd($d('operating_cash_flow')).' higher.',
                    'visual' => 'compare',
                    'columns' => $columns,
                    'rows' => [
                        self::row('Net income', $sl['net_income'], $db['net_income']),
                        self::row('Depreciation added back', $sl['depreciation'], $db['depreciation']),
                        self::row('Net cash from operating activities', $sl['operating_cash_flow'], $db['operating_cash_flow']),
                    ],
                ],
                [
                    'title' => 'The notes',
                    'caption' => 'The notes say which method the firm uses, and when it changed. Without them, a reader would see profit fall and not know why.',
                    'visual' => 'note',
                    'note' => 'Beginning this quarter, the firm changed its depreciation method from Straight-line to Double-declining balance.',
                    'method' => (string) AccountingMethodGuide::effect('depreciation_method', 'double_declining_balance'),
                ],
                [
                    'title' => 'What moved, and what didn\'t',
                    'caption' => 'Reported profit '.self::moved($d('net_income')).' and cash '.self::moved($d('cash')).', through tax. The machines, the prices and the '.self::units($sl['units_sold']).' units sold were the same. When you compare firms, read their methods in the notes first.',
                    'visual' => 'summary',
                    'chips' => [
                        ['label' => 'Net income', 'value' => self::signed($d('net_income')), 'tone' => $d('net_income') < 0 ? 'down' : 'up'],
                        ['label' => 'Cash', 'value' => self::signed($d('cash')), 'tone' => $d('cash') < 0 ? 'down' : 'up'],
                        ['label' => 'Units sold', 'value' => $sl['units_sold'] === $db['units_sold'] ? 'The same' : self::units($db['units_sold'] - $sl['units_sold']), 'tone' => 'same'],
                    ],
                ],
            ],
        ];
    }

    /** @return array{label: string, a: string, b: string, change: string, tone: string, av: float, bv: float} */
    private static function row(string $label, float|int $a, float|int $b): array
    {
        $change = round($b - $a, 2);

        return [
            'label' => $label, 'a' => self::usd($a), 'b' => self::usd($b), 'av' => (float) $a, 'bv' => (float) $b,
            'change' => abs($change) < 0.01 ? 'No change' : ($change > 0 ? '+' : '−').self::usd(abs($change)),
            'tone' => abs($change) < 0.01 ? 'same' : ($change > 0 ? 'up' : 'down'),
        ];
    }

    /** "$14,067,129.96", with brackets for negatives, as the statements show money. */
    public static function usd(float|int $value): string
    {
        $formatted = '$'.number_format(abs($value), 2);

        return $value < 0 ? '('.$formatted.')' : $formatted;
    }

    /** "$14.1M", or "−$1.5M" for a change down. */
    public static function m(float|int $value): string
    {
        return ($value < 0 ? '−' : '').'$'.number_format(abs($value) / 1e6, 1).'M';
    }

    /** "+$0.5M", "−$1.5M" */
    private static function signed(float $change): string
    {
        return ($change > 0 ? '+' : '').self::m($change);
    }

    /** "fell by $1.5M", "rose by $0.5M" */
    private static function moved(float $change): string
    {
        return ($change < 0 ? 'fell by ' : 'rose by ').self::m(abs($change));
    }

    public static function units(float|int $value): string
    {
        return number_format((int) round($value));
    }
}
