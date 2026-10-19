<?php

namespace App\Support\Tour;

/**
 * Live walkthrough L8: the public tour for faculty thinking of adopting
 * Flexee. Nine steps, narrated in plain words, following one course from
 * start to finish and switching between what students see and what the
 * instructor sees. The wording is the approved "Flexee Adopter Tour"
 * prototype (18 October 2026); every figure comes from the stored tour
 * figures (App\Services\Tour\TourFigures).
 */
final class AdopterTour
{
    public const CONTACT = 'chuck@guruofbiz.com';

    public const CONTACT_NAME = 'Chuck Nemer';

    /**
     * @param  array<string, mixed>  $x  the tour figures
     * @return list<array{short: string, who: string, title: string, story: list<string>, lesson: string}>
     */
    public static function steps(array $x): array
    {
        $c = $x['careful'];
        $q4 = $c['4'];
        $notFilled = $q4['ordered'] - $q4['sold'];
        $value = $x['value_created'];
        $last = (string) $x['quarters'];
        $eval = collect($x['evaluation']['results'])->keyBy('target');
        $carelessWon = $eval['careful'] ?? null;
        $carefulWon = $eval['careless'] ?? null;

        return [
            [
                'short' => 'The course', 'who' => 'both', 'title' => 'A course in reading financial statements',
                'story' => [
                    'Each team of students runs a manufacturing firm for a term, eleven quarters in a typical course. Every quarter produces a fresh <b>income statement, balance sheet, cash flow statement and notes</b>, and the team\'s job is to read them, explain them and act on what they find.',
                    'The business decisions are deliberately quick, and they arrive prefilled. They exist to give students a new, different set of statements to interpret each quarter.',
                ],
                'lesson' => 'Students practise what accountants and analysts actually do: explain why the numbers moved.',
            ],
            [
                'short' => 'Take over a firm', 'who' => 'student', 'title' => 'Every team starts with three quarters of history',
                'story' => [
                    'Before students touch anything, the simulation runs the firm for three quarters. Each team inherits a going concern, with statements to read on day one, not a blank page.',
                    'Here\'s Alpha\'s history, read the way an analyst reads it, quarter beside quarter. Revenue grew from <b>'.self::m($c['1']['revenue']).'</b> to <b>'.self::m($c['3']['revenue']).'</b>, and cash built up from <b>'.self::m($c['1']['cash']).'</b> to <b>'.self::m($c['3']['cash']).'</b>.',
                ],
                'lesson' => 'First assignment, before any decision: what kind of business is this, and where does its cash come from?',
            ],
            [
                'short' => 'Decide, quickly', 'who' => 'student', 'title' => 'Decisions arrive prefilled',
                'story' => [
                    'Each field starts from last quarter\'s decision, with last quarter\'s figure shown underneath. A team that\'s happy can submit in minutes, and a team that wants to dig in has the full controls.',
                    'The instructor chooses which decisions open when. In this course, Quarter 4 opens only <b>forecasting and sourcing</b>. Pricing, marketing, production, quality and logistics open later, so students meet one new lever at a time.',
                ],
                'lesson' => 'The operating side is simple on purpose. The effort goes into reading what each decision did to the statements.',
            ],
            [
                'short' => 'Read the results', 'who' => 'student', 'title' => 'The statements tell a story worth explaining',
                'story' => [
                    'At the deadline, the quarter closes on its own and the new statements arrive. Alpha\'s net income jumped to <b>'.self::m($q4['net_income']).'</b>. Cash rose by only <b>'.self::m($q4['cash'] - $c['3']['cash']).'</b>.',
                    'The cash flow statement and the notes explain the gap. <b>'.self::m($q4['dividend']).'</b> went out as dividends, <b>'.self::m($q4['inventory_change']).'</b> more went into inventory, with materials still on their way from the supplier counted as the firm\'s own under FOB shipping point, and <b>'.self::m($q4['receivables_change']).'</b> more is owed by customers. It\'s partly offset by <b>'.self::m($q4['payables_change']).'</b> more owed to suppliers.'
                        .($notFilled > 0 ? ' And customers ordered '.self::units($notFilled).' more units than Alpha could deliver.' : ''),
                ],
                'lesson' => 'Profit is not cash. Students find out why from their own firm, not a textbook example.',
            ],
            [
                'short' => 'Choose methods', 'who' => 'both', 'title' => 'Accounting choices have consequences',
                'story' => [
                    'Teams start with the methods the instructor picked at setup, and can ask to change one, <b>with a written reason</b>. The instructor approves or declines, and an approved change is disclosed in the notes, as the standards require.',
                    'The Careless team switched depreciation to declining balance in Quarter 6. Depreciation rose from <b>'.self::m($x['careless_depreciation']['before']).'</b> to <b>'.self::m($x['careless_depreciation']['after']).'</b> a quarter, which on its own takes about '.self::m($x['careless_depreciation']['after_tax_effect']).' off after-tax profit. The machines, the orders and the sales are exactly the same. Only the reported expense moved, and the tax on it.',
                ],
                'lesson' => 'A change in method moves reported profit, not the business. Students see it happen to a firm they know.',
            ],
            [
                'short' => 'Run the course', 'who' => 'faculty', 'title' => 'Set it up once, and it runs itself',
                'story' => [
                    'Setup is six short steps: dates, which decisions open in which quarter, the starting accounting methods, co-instructors, and whether to add the evaluation stage.',
                    'Each quarter then <b>closes on its own at its deadline</b>. Results are calculated, the next quarter opens, and a team that didn\'t submit keeps last quarter\'s decisions. Your pages show who has submitted, what\'s waiting for you, and every firm\'s statements side by side, with a spreadsheet download for your own analysis.',
                ],
                'lesson' => 'You spend class time on the statements, not on running software.',
            ],
            [
                'short' => 'Rehearse first', 'who' => 'faculty', 'title' => 'See your course play out before you teach it',
                'story' => [
                    'Before the term starts, press <b>Rehearse this course</b>. Three reference teams play your exact setup in seconds, with the same engine your class will use.',
                    'Careful plans from real orders. Average repeats last quarter. Careless overproduces, discounts and pays out every dollar it can. By Quarter '.$last.', Careful has created <b>'.self::m($value['careful'][$last]).'</b> of value, Average <b>'.self::m($value['average'][$last]).'</b>, and Careless <b>'.self::m($value['careless'][$last]).'</b>. You can open any team\'s screens and see exactly what your students will see, and change how each team decides.',
                ],
                'lesson' => 'You walk in knowing what good, average and poor look like in your course, quarter by quarter.',
            ],
            [
                'short' => 'The + version', 'who' => 'both', 'title' => 'Flexee Financial Accounting+: teams value each other',
                'story' => [
                    'Add the evaluation stage at setup, and the last quarters change. Teams study their rivals\' published statements, receive a consultant\'s valuation of each rival, and place sealed offers for them.',
                    'The consultant\'s figure is built from reported income, so it rewards aggressive reporting. A team that reads the notes bids closer to what a firm is truly worth. The reveal shows who overpaid.'
                        .($carelessWon && $carefulWon && $carelessWon['winner'] === 'careless' && $carefulWon['winner'] === 'careful'
                            ? ' In this run, Careless bid a quarter above the consultant\'s figure and paid <b>'.self::m($carelessWon['difference']).'</b> too much for Careful, while Careful bought Careless for <b>'.self::m(-$carefulWon['difference']).'</b> less than its true worth.'
                            : ''),
                ],
                'lesson' => 'Reading past reported income is worth real money. The reveal makes that impossible to miss.',
            ],
            [
                'short' => 'Try it', 'who' => 'both', 'title' => 'What your students take away, and how to start',
                'story' => [
                    'Over a term, students read a dozen sets of statements for a firm they run, explain every quarter\'s movements, defend an accounting choice in writing, and, in the + version, value a rival from its published reports.',
                    'We set up a trial course for you with your dates. You can rehearse it with the reference teams before a single student signs up.',
                ],
                'lesson' => 'For accounting students and MBAs. No supply-chain background needed.',
            ],
        ];
    }

    /** "$14.2M" */
    public static function m(float|int $value): string
    {
        return ($value < 0 ? '−' : '').'$'.number_format(abs($value) / 1e6, 1).'M';
    }

    /** "$14,242,033.21", with brackets for negatives. */
    public static function usd(float|int $value): string
    {
        $formatted = '$'.number_format(abs($value), 2);

        return $value < 0 ? '('.$formatted.')' : $formatted;
    }

    public static function units(float|int $value): string
    {
        return number_format($value);
    }

    public static function who(string $who): string
    {
        return ['student' => 'What your students see', 'faculty' => 'What you see', 'both' => 'Students and instructor'][$who] ?? '';
    }
}
