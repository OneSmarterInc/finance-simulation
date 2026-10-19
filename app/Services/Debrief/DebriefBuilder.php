<?php

namespace App\Services\Debrief;

use App\Models\CfPlusAuctionResult;
use App\Models\Course;
use App\Models\Firm;
use App\Models\FirmAccountingElectionRequest;
use App\Models\FirmEngineResult;
use App\Models\FirmRivalAnalysis;
use App\Services\Accounting\AccountingChangeDisclosures;
use App\Services\Ai\ResultMeasures;
use App\Services\Courses\CourseStatus;
use App\Support\Presentation\StatementPresenter;
use Illuminate\Support\Collection;
use Throwable;

/**
 * U4 layer 1 (DB1 to DB4, decided 19 October 2026): the debrief's slides,
 * built from the class's stored results. The engine and this class pick the
 * firms, quarters and figures; Claude only words them (DebriefCaptions),
 * and each slide carries a plain sentence built here as the fallback.
 *
 * A slide with nothing to show is left out.
 */
class DebriefBuilder
{
    /** slide key => title, in the order they're shown */
    public const SLIDES = [
        'race' => 'The race, and where it turned',
        'profit_cash' => 'Profit is not cash',
        'methods' => 'Methods that moved the numbers',
        'reveal' => 'The reveal: price against worth',
    ];

    public function __construct(private readonly QuarterReplay $replay) {}

    /**
     * @return array{finished: bool, firms: list<array{id: string, name: string, slot: int}>, slides: array<string, array{key: string, title: string, data: array<string, mixed>, facts: string, fallback: string}>}
     */
    public function build(Course $course): array
    {
        $firms = $course->firms()->orderBy('code')->get();
        $results = FirmEngineResult::query()->where('course_id', $course->id)->orderBy('quarter_number')->get()->groupBy('firm_id');
        $status = CourseStatus::for($course);

        $slides = [];
        foreach ([
            'race' => fn () => $this->race($firms, $results),
            'profit_cash' => fn () => $this->profitCash($firms, $results),
            'methods' => fn () => $this->methods($course, $firms, $results),
            'reveal' => fn () => $course->cf_plus_enabled ? $this->reveal($course, $firms) : null,
        ] as $key => $make) {
            $slide = $make();
            if ($slide !== null) {
                $slides[$key] = ['key' => $key, 'title' => self::SLIDES[$key]] + $slide;
            }
        }

        return [
            'finished' => self::finished($course),
            'last_closed' => $status->lastClosed()?->quarter_number,
            'firms' => $firms->values()->map(fn (Firm $f, int $i) => ['id' => $f->id, 'name' => $f->name, 'slot' => $i])->all(),
            'slides' => $slides,
        ];
    }

    /** The course is over: every quarter closed and, on + courses, the stage settled (DB4). */
    public static function finished(Course $course): bool
    {
        if (! CourseStatus::for($course)->isFinished()) {
            return false;
        }

        return ! $course->cf_plus_enabled || CfPlusAuctionResult::query()->where('course_id', $course->id)->exists();
    }

    private static function money(?float $v): string
    {
        return ResultMeasures::format('cash', $v);
    }

    /**
     * @param  Collection<int, Firm>  $firms
     * @param  Collection<string, Collection<int, FirmEngineResult>>  $results
     * @return array<string, mixed>|null
     */
    private function race(Collection $firms, Collection $results): ?array
    {
        $quarters = $results->flatten()->pluck('quarter_number')->unique()->filter(fn ($q) => $q >= 3)->sort()->values();
        if ($firms->count() < 2 || ! $quarters->contains(4)) {
            return null;
        }
        $values = [];
        foreach ($firms as $firm) {
            $own = $results->get($firm->id, collect());
            foreach ($own as $r) {
                if ($r->quarter_number >= 3) {
                    $values[$firm->id][$r->quarter_number] = round((float) ResultMeasures::value('value_created', $r, $own), 2);
                }
            }
        }
        $names = $firms->pluck('name', 'id');
        $leaders = [];
        foreach ($quarters->filter(fn ($q) => $q >= 4) as $q) {
            $row = collect($values)->map(fn ($v) => $v[$q] ?? null)->filter(fn ($v) => $v !== null);
            if ($row->isNotEmpty()) {
                $leaders[$q] = $row->search($row->max());
            }
        }
        $changes = [];
        $previous = null;
        foreach ($leaders as $q => $id) {
            if ($previous !== null && $id !== $previous) {
                $changes[] = ['quarter' => $q, 'from' => $names[$previous], 'to' => $names[$id]];
            }
            $previous = $id;
        }
        $counts = collect($leaders)->countBy();
        $longest = $counts->sortDesc()->keys()->first();
        $lastQ = array_key_last($leaders);
        $final = $leaders[$lastQ];

        $facts = ['Value created (equity plus dividends paid from Quarter 4), by firm and quarter:'];
        foreach ($firms as $firm) {
            $facts[] = '  '.$firm->name.': '.collect($values[$firm->id] ?? [])->map(fn ($v, $q) => "Quarter {$q} ".self::money($v))->join(' | ');
        }
        $facts[] = 'Leader each quarter from Quarter 4: '.collect($leaders)->map(fn ($id, $q) => "Quarter {$q} {$names[$id]}")->join(', ').'.';
        $facts[] = $changes === [] ? 'The lead never changed hands.' : 'The lead changed hands '.count($changes).' '.(count($changes) === 1 ? 'time' : 'times').': '.collect($changes)->map(fn ($c) => "Quarter {$c['quarter']}, {$c['from']} to {$c['to']}")->join('; ').'.';

        $fallback = "{$names[$final]} ended Quarter {$lastQ} in front, with value created of ".self::money($values[$final][$lastQ]).'. '
            .($changes === []
                ? 'The lead never changed hands after the warm-up.'
                : 'The lead changed hands '.count($changes).' '.(count($changes) === 1 ? 'time' : 'times').", and {$names[$longest]} led for the most quarters ({$counts[$longest]}).");

        return [
            'data' => ['quarters' => $quarters->all(), 'values' => $values, 'leaders' => $leaders, 'changes' => $changes],
            'facts' => implode("\n", $facts),
            'fallback' => $fallback,
        ];
    }

    /**
     * @param  Collection<int, Firm>  $firms
     * @param  Collection<string, Collection<int, FirmEngineResult>>  $results
     * @return array<string, mixed>|null
     */
    private function profitCash(Collection $firms, Collection $results): ?array
    {
        $best = null;
        foreach ($firms as $firm) {
            foreach ($results->get($firm->id, collect()) as $r) {
                if ($r->quarter_number < 4) {
                    continue;
                }
                $ni = (float) (((array) $r->income_statement)['net_income'] ?? 0);
                $ocf = (float) (((array) $r->cash_flow_statement)['operating_cash_flow'] ?? 0);
                if ($best === null || abs($ni - $ocf) > abs($best['gap'])) {
                    $best = ['firm' => $firm, 'result' => $r, 'ni' => $ni, 'ocf' => $ocf, 'gap' => $ni - $ocf];
                }
            }
        }
        if ($best === null) {
            return null;
        }
        // The operating lines between net income and cash from operations, largest first.
        $lines = collect(StatementPresenter::rows('cash_flow_statement', ['q' => $best['result']]))
            ->skipUntil(fn ($row) => $row['type'] === 'heading')->takeUntil(fn ($row, $i) => $row['type'] === 'subtotal')
            ->filter(fn ($row) => $row['type'] === 'line' && ! in_array($row['label'], ['Net income'], true) && abs((float) ($row['values'][0] ?? 0)) >= 0.005)
            ->map(fn ($row) => ['label' => $row['label'], 'value' => round((float) $row['values'][0], 2)])
            ->sortByDesc(fn ($l) => abs($l['value']))->take(5)->values()->all();
        $q = $best['result']->quarter_number;
        $name = $best['firm']->name;
        $facts = "{$name}, Quarter {$q}: net income ".self::money($best['ni']).'; net cash from operating activities '.self::money($best['ocf']).".\nThe lines between them on the cash flow statement: "
            .collect($lines)->map(fn ($l) => $l['label'].' '.self::money($l['value']))->join('; ').'.';
        $top = $lines[0] ?? null;
        $fallback = "In Quarter {$q}, {$name} reported net income of ".self::money($best['ni']).' but brought in '.self::money($best['ocf']).' from operations.'
            .($top ? " The biggest line between the two was {$top['label']}: ".self::money($top['value']).'.' : '');

        return [
            'data' => ['firm' => $name, 'quarter' => $q, 'net_income' => round($best['ni'], 2), 'operating_cash_flow' => round($best['ocf'], 2), 'lines' => $lines],
            'facts' => $facts,
            'fallback' => $fallback,
        ];
    }

    /**
     * Each approved change, its first quarter played again with the old methods (QuarterReplay).
     *
     * @param  Collection<int, Firm>  $firms
     * @param  Collection<string, Collection<int, FirmEngineResult>>  $results
     * @return array<string, mixed>|null
     */
    private function methods(Course $course, Collection $firms, Collection $results): ?array
    {
        $requests = FirmAccountingElectionRequest::query()->where('course_id', $course->id)->where('status', 'approved')
            ->whereNotNull('effective_quarter_number')->with('firm')->orderBy('effective_quarter_number')->get();
        $rows = [];
        foreach ($requests as $request) {
            $q = (int) $request->effective_quarter_number;
            $stored = $results->get($request->firm_id, collect())->firstWhere('quarter_number', $q);
            if (! $stored || ! $request->firm) {
                continue;
            }
            $changes = AccountingChangeDisclosures::changes((array) $request->previous_elections, (array) $request->proposed_elections);
            if ($changes === []) {
                continue;
            }
            $old = array_intersect_key((array) $request->previous_elections, array_flip(array_column($changes, 'key')));
            try {
                $before = $this->replay->replay($request->firm, $q, $old);
            } catch (Throwable) {
                $before = null;
            }
            if (! $before) {
                continue;
            }
            $rows[] = [
                'firm' => $request->firm->name,
                'quarter' => $q,
                'change' => collect($changes)->map(fn ($c) => "{$c['label']}: {$c['from']} to {$c['to']}")->join('; '),
                'net_income' => round((float) (((array) $stored->income_statement)['net_income'] ?? 0), 2),
                'net_income_old' => round((float) ($before->incomeStatement['net_income'] ?? 0), 2),
                'operating_cash_flow' => round((float) (((array) $stored->cash_flow_statement)['operating_cash_flow'] ?? 0), 2),
                'operating_cash_flow_old' => round((float) ($before->cashFlowStatement['operating_cash_flow'] ?? 0), 2),
            ];
        }
        if ($rows === []) {
            return null;
        }
        $facts = collect($rows)->map(fn ($r) => "{$r['firm']}, from Quarter {$r['quarter']} ({$r['change']}): net income ".self::money($r['net_income'])
            .' with the new methods, '.self::money($r['net_income_old']).' with the old ones; net cash from operating activities '.self::money($r['operating_cash_flow'])
            .' with the new methods, '.self::money($r['operating_cash_flow_old']).' with the old ones.')->join("\n");
        $biggest = collect($rows)->sortByDesc(fn ($r) => abs($r['net_income'] - $r['net_income_old']))->first();
        $fallback = "In Quarter {$biggest['quarter']}, {$biggest['firm']} reported net income of ".self::money($biggest['net_income'])
            .' with its new methods; with the old ones it would have been '.self::money($biggest['net_income_old'])
            .'. Cash from operations was '.self::money($biggest['operating_cash_flow']).($biggest['operating_cash_flow'] === $biggest['operating_cash_flow_old'] ? ' either way.' : ' with the new methods and '.self::money($biggest['operating_cash_flow_old']).' with the old.');

        return ['data' => ['rows' => $rows], 'facts' => $facts, 'fallback' => $fallback];
    }

    /**
     * @param  Collection<int, Firm>  $firms
     * @return array<string, mixed>|null
     */
    private function reveal(Course $course, Collection $firms): ?array
    {
        $settled = CfPlusAuctionResult::query()->where('course_id', $course->id)->with(['targetFirm', 'winner'])->get();
        if ($settled->isEmpty()) {
            return null;
        }
        $estimates = FirmRivalAnalysis::query()->where('course_id', $course->id)->where('state', FirmRivalAnalysis::SUBMITTED)
            ->whereNotNull('estimated_stake_value')->with('bidder')->get()->groupBy('target_firm_id');
        $rows = $settled->sortBy(fn ($r) => $r->targetFirm?->name)->map(function (CfPlusAuctionResult $r) use ($estimates): array {
            $true = round((float) $r->true_stake_worth, 2);
            $own = $estimates->get($r->target_firm_id, collect());
            $closest = $own->sortBy(fn ($e) => abs((float) $e->estimated_stake_value - $true))->first();

            return [
                'target' => $r->targetFirm?->name,
                'consultant' => round((float) $r->consultant_stake_value, 2),
                'price' => $r->price_paid === null ? null : round((float) $r->price_paid, 2),
                'winner' => $r->winner?->name,
                'true' => $true,
                'difference' => $r->price_paid === null ? null : round((float) $r->price_paid - $true, 2),
                'closest_team' => $closest?->bidder?->name,
                'closest_figure' => $closest ? round((float) $closest->estimated_stake_value, 2) : null,
                'average_miss' => $own->isEmpty() ? null : round($own->avg(fn ($e) => abs((float) $e->estimated_stake_value - $true)), 2),
            ];
        })->values()->all();

        $facts = collect($rows)->map(fn ($r) => "{$r['target']}: consultant's value of 51% ".self::money($r['consultant']).'; '
            .($r['price'] === null ? 'not sold' : "bought by {$r['winner']} for ".self::money($r['price']))
            .'; true worth of 51% '.self::money($r['true'])
            .($r['difference'] === null ? '' : '; paid '.($r['difference'] >= 0 ? self::money($r['difference']).' above' : self::money(-$r['difference']).' below').' true worth')
            .($r['closest_team'] ? "; closest team figure: {$r['closest_team']}'s ".self::money($r['closest_figure']) : '')
            .'.')->join("\n");
        $over = collect($rows)->filter(fn ($r) => $r['difference'] !== null)->sortByDesc('difference')->first();
        $fallback = $over
            ? "{$over['winner']} paid ".self::money($over['price'])." for 51% of {$over['target']}, against a true worth of ".self::money($over['true'])
                .', '.($over['difference'] >= 0 ? self::money($over['difference']).' too much' : self::money(-$over['difference']).' less than it was worth').'.'
                .($over['closest_team'] ? " The closest team figure for {$over['target']} was {$over['closest_team']}'s ".self::money($over['closest_figure']).'.' : '')
            : 'No firm was sold in the evaluation stage.';

        return ['data' => ['rows' => $rows], 'facts' => $facts, 'fallback' => $fallback];
    }
}
