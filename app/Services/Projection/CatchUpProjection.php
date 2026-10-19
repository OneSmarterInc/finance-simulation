<?php

namespace App\Services\Projection;

use App\Models\Course;
use App\Models\CourseQuarter;
use App\Models\Firm;
use App\Models\FirmEngineResult;
use App\Models\FirmQuarterDecision;
use App\Services\Accounting\FirmAccountingElectionService;
use App\Services\Ai\ResultMeasures;
use App\Services\CfPlus\CfPlusValuationConfiguration;
use App\Services\CfPlus\ConsultantValuationPrototype;
use App\Services\Courses\CourseStatus;
use App\Services\Decisions\FirmDecisionService;
use App\Services\Engines\CorporateFinance\CfManufacturingSimRulesService;
use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngine;
use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngineInput;
use App\Services\Engines\CorporateFinance\Contracts\OpeningFinancialPosition;
use App\Services\Practice\ReferenceTeams;
use App\Support\Scenario\CfManufacturingScenarioDefinition;
use Illuminate\Support\Collection;

/**
 * U10 (decided 17 October 2026; PJ1 to PJ3, 19 October 2026): can anyone
 * catch the leader? From each firm's real position at the last closed
 * quarter, play the remaining quarters in memory with a behaviour chosen per
 * firm, and compare every firm on one measure, quarter by quarter.
 *
 * - Nothing is saved: the engine and the version 2 rules are pure, and this
 *   class only reads stored rows (results, decisions, quarters, methods).
 * - The course's real market (PJ1): each firm faces the demand the course
 *   will actually have, so a team that repeats its decisions gets exactly
 *   what the projection shows.
 * - Each projected quarter closes as the real close does, including the
 *   dividend check (ED17).
 * - Courses on the earlier engine get "repeat" only (PJ3).
 */
class CatchUpProjection
{
    public const REPEAT = 'repeat';

    /** behaviour => what faculty read */
    public const BEHAVIOURS = [
        self::REPEAT => 'Repeat its latest decisions',
        'careful' => 'Play like the Careful team',
        'average' => 'Play like the Average team',
        'careless' => 'Play like the Careless team',
    ];

    /** measure => [label, higher is better] */
    public const MEASURES = [
        'value_created' => ['Value created', true],
        'total_equity' => ['Total equity', true],
        'cumulative_net_income' => ['Net income since Quarter 4', true],
        'cash' => ['Cash at quarter end', true],
        'consultant_value' => ['Consultant\'s value', true],
    ];

    public function __construct(
        private readonly FirmDecisionService $decisions,
        private readonly ManufacturingEngine $engine,
        private readonly ReferenceTeams $teams,
    ) {}

    /** @return array<string, string> the behaviours this course allows (PJ3) */
    public static function behaviours(Course $course): array
    {
        return $course->engineVersion() === 2 ? self::BEHAVIOURS : [self::REPEAT => self::BEHAVIOURS[self::REPEAT]];
    }

    /** @return array<string, string> the measures this course offers */
    public static function measures(Course $course): array
    {
        $out = [];
        foreach (self::MEASURES as $key => [$label]) {
            if ($key !== 'consultant_value' || $course->cf_plus_enabled) {
                $out[$key] = $label;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $behaviours  firm id => behaviour; firms left out repeat
     * @return array{
     *     measure: string, label: string, from: int|null, last: int,
     *     firms: list<array{id: string, name: string, behaviour: string, values: array<int, float|null>, projected: list<int>}>,
     *     leaders: array<int, string>, gaps: array<string, array<int, float|null>>, answer: string, note: string|null
     * }
     */
    public function project(Course $course, array $behaviours = [], string $measure = 'value_created'): array
    {
        $measure = array_key_exists($measure, self::measures($course)) ? $measure : 'value_created';
        $allowed = self::behaviours($course);
        $status = CourseStatus::for($course);
        $quarters = $course->quarters()->orderBy('quarter_number')->get();
        $last = (int) $quarters->max('quarter_number');
        $from = $status->lastClosed()?->quarter_number;
        $parameters = null;
        $this->lastPlayed = [];

        $firms = [];
        foreach ($course->firms()->orderBy('code')->get() as $firm) {
            $stored = FirmEngineResult::query()->where('firm_id', $firm->id)->orderBy('quarter_number')->get();
            $behaviour = array_key_exists($behaviours[$firm->id] ?? '', $allowed) ? $behaviours[$firm->id] : self::REPEAT;
            $results = $stored->keyBy('quarter_number')->all();
            $projected = [];
            $start = (int) ($stored->max('quarter_number') ?? 0);
            if ($start >= 3) {
                $parameters ??= $this->decisions->parameters($firm);
                foreach ($quarters->where('quarter_number', '>', $start) as $quarter) {
                    $results[$quarter->quarter_number] = $this->playQuarter($firm, $quarter, $behaviour, $results, $parameters);
                    $projected[] = $quarter->quarter_number;
                }
            }
            $series = collect($results)->sortKeys();
            $values = [];
            foreach ($series as $q => $result) {
                $values[$q] = $this->value($measure, $result, $series);
            }
            $firms[] = ['id' => $firm->id, 'name' => $firm->name, 'behaviour' => $behaviour, 'values' => $values, 'projected' => $projected];
        }

        [$leaders, $gaps] = $this->leaders($firms, $measure);

        return [
            'measure' => $measure,
            'label' => self::MEASURES[$measure][0],
            'from' => $from,
            'last' => $last,
            'firms' => $firms,
            'leaders' => $leaders,
            'gaps' => $gaps,
            'answer' => $this->answer($firms, $leaders, $last),
            'note' => $course->engineVersion() === 2 ? null
                : 'This course runs on the earlier engine, where each firm\'s demand follows its own forecast, so the projection can only repeat each team\'s latest decisions.',
        ];
    }

    /**
     * One projected quarter, closed as the real close closes it, in memory.
     *
     * @param  array<int, FirmEngineResult>  $results  quarter => stored or projected result so far
     * @param  array<string, mixed>  $parameters
     */
    private function playQuarter(Firm $firm, CourseQuarter $quarter, string $behaviour, array $results, array $parameters): FirmEngineResult
    {
        $q = $quarter->quarter_number;
        $course = $firm->course;
        $version = $course->engineVersion();
        $prior = (array) $results[$q - 1]->fields;
        $opening = OpeningFinancialPosition::fromPriorEngineResult($prior);
        $enabled = $this->decisions->enabledCategories($quarter);

        // The team's decisions for this quarter: its own (repeat) or a reference team's rules.
        [$payload, $dividend, $notes] = $this->latest($firm, $quarter, $enabled, $q);
        $declared = null;
        if ($behaviour !== self::REPEAT) {
            $decision = $this->teams->decide($behaviour, $q, $prior, $parameters, $course->startingElections());
            foreach ($payload as $category => $fields) {
                $rule = $category === 'notes_elections' ? $decision['notes'] : ($decision['payload'][$category] ?? []);
                foreach (array_keys($fields) as $key) {
                    if (array_key_exists($key, $rule)) {
                        $payload[$category][$key] = $rule[$key];
                    }
                }
            }
            $notes = $decision['notes'];
            $share = ReferenceTeams::DIVIDEND_SHARE[$behaviour];
            $dividend = $share > 0 ? floor($this->dividendMaximum($opening, $parameters, $q) * $share * 100 + 1e-6) / 100 : 0.0;
        } else {
            $declared = $dividend;
            $dividend = min($dividend, $this->dividendMaximum($opening, $parameters, $q));
        }

        $resolved = $this->resolve($firm, $quarter, $enabled, $payload, $prior, $opening, $parameters, $notes, $behaviour);
        // The next quarter carries these forward, as the real close does (N4): the resolved decisions, and
        // under "repeat" the dividend the team declared, which each quarter cuts to what it allows.
        $this->lastPlayed[$firm->id] = ['payload' => $resolved, 'dividend' => $declared ?? $dividend];
        $market = $version === 2 ? $this->decisions->market($firm, $quarter) : null;
        $input = fn (float $d) => new ManufacturingEngineInput($q, $resolved, $parameters, $opening, $d, $version, $market);

        // ED17 a: a dividend the quarter can't fund is cut to what it can, as at the real close.
        $dividend = round(max(0.0, $dividend), 2);
        if ($dividend > 0) {
            $probe = $this->engine->calculate($input(0.0));
            $dividend = min($dividend, round((float) $probe->intermediates['maximum_dividend'], 2));
        }
        $result = $this->engine->calculate($input($dividend));

        $row = new FirmEngineResult;
        $row->forceFill([
            'firm_id' => $firm->id, 'course_id' => $firm->course_id, 'quarter_number' => $q,
            'fields' => $result->fields, 'income_statement' => $result->incomeStatement, 'balance_sheet' => $result->balanceSheet,
            'cash_flow_statement' => $result->cashFlowStatement, 'notes' => $result->notes, 'intermediates' => $result->intermediates,
        ]);
        $row->setAttribute('projected_payload', $payload);
        $row->setAttribute('projected_dividend', $dividend);

        return $row;
    }

    /**
     * The team's latest decisions for every open area of $quarter: the open quarter's
     * submitted decisions if it has them, else the last decisions the team played.
     *
     * @param  array<string, mixed>  $enabled
     * @return array{0: array<string, array<string, mixed>>, 1: float, 2: array<string, mixed>|null}
     */
    private function latest(Firm $firm, CourseQuarter $quarter, array $enabled, int $q): array
    {
        $own = FirmQuarterDecision::query()->where('firm_id', $firm->id)->where('course_quarter_id', $quarter->id)
            ->whereNotNull('submitted_payload')->first();
        if ($own && ! isset($this->lastPlayed[$firm->id])) {
            return [(array) $own->submitted_payload, (float) $own->dividend, null];
        }
        $source = $this->lastPlayed[$firm->id] ?? $this->decisions->previousDecisions($firm, $quarter);
        $payload = [];
        foreach ($enabled as $code => $category) {
            foreach ($this->decisions->decisionFields($quarter, $category) as $field) {
                $payload[$code][$field['key']] = $source['payload'][$code][$field['key']] ?? $this->decisions->fieldDefault($firm, $field);
            }
        }

        return [$payload, (float) ($source['dividend'] ?? 0.0), null];
    }

    /** @var array<string, array{payload: array<string, mixed>, dividend: float}> firm id => the decisions it played last in this projection */
    private array $lastPlayed = [];

    /**
     * @param  array<string, mixed>  $enabled
     * @param  array<string, array<string, mixed>>  $payload
     * @param  array<string, float>  $prior
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>|null  $notes  a reference team's methods
     * @return array<string, array<string, mixed>>
     */
    private function resolve(Firm $firm, CourseQuarter $quarter, array $enabled, array $payload, array $prior, OpeningFinancialPosition $opening, array $parameters, ?array $notes, string $behaviour): array
    {
        $course = $firm->course;
        $q = $quarter->quarter_number;
        $operational = array_values(array_intersect(array_keys($enabled), ['forecasting', 'sourcing', 'pricing', 'production', 'marketing', 'quality', 'logistics']));
        $student = array_intersect_key($payload, array_flip($operational));
        $rules = new CfManufacturingSimRulesService;

        if ($course->engineVersion() === 2) {
            $resolved = $rules->deriveCompleteDecisionsV2($q, $rules->demandContextV2($q, $this->decisions->market($firm, $quarter), $prior, $parameters),
                $parameters, $operational, $student, ['p1' => $opening->unitsOnHandP1, 'p2' => $opening->unitsOnHandP2]);
        } else {
            $resolved = $rules->deriveCompleteDecisions($q, $student['forecasting'] ?? [], $parameters, $operational, $student);
        }

        // Accounting methods: a reference team's own (as in a practice run), else what the firm uses then.
        if ($notes !== null && ($course->accounting_election_enabled || isset($enabled['notes_elections']))) {
            $resolved['notes_elections'] = $notes;
        } elseif ($course->accounting_election_enabled) {
            $resolved['notes_elections'] = app(FirmAccountingElectionService::class)->effective($firm, $quarter);
        } elseif ($course->cf_plus_enabled) {
            $resolved['notes_elections'] = $firm->accounting_elections ?? ($payload['notes_elections'] ?? $course->startingElections());
        } else {
            $resolved['notes_elections'] = $payload['notes_elections'] ?? $course->startingElections();
        }
        if ($course->engineVersion() !== 2) {
            $resolved['certification'] = $payload['certification'] ?? CfManufacturingScenarioDefinition::fixedWarmupPayload('certification');
        }

        return $resolved;
    }

    /** ED17: the most a team may declare, from the position the quarter opens with. */
    private function dividendMaximum(OpeningFinancialPosition $opening, array $parameters, int $q): float
    {
        if ($q < 4) {
            return 0.0;
        }

        return round(min(round(max(0.0, $opening->retainedEarnings), 2), round(max(0.0, $opening->cash - (float) $parameters['minimum_cash_buffer']), 2)), 2);
    }

    /** @param Collection<int, FirmEngineResult> $series */
    private function value(string $measure, FirmEngineResult $result, Collection $series): ?float
    {
        $q = $result->quarter_number;

        return match ($measure) {
            'cumulative_net_income' => $q < 4 ? null : round($series->filter(fn ($r, $k) => $k >= 4 && $k <= $q)
                ->sum(fn ($r) => (float) (((array) $r->income_statement)['net_income'] ?? 0)), 2),
            'consultant_value' => $this->consultantValue($series->filter(fn ($r, $k) => $k <= $q)->take(-3)),
            default => ResultMeasures::value($measure, $result, $series->values()),
        };
    }

    /** On + courses: the consultant's value from the last three quarters' operating income, with the approved settings. */
    private function consultantValue(Collection $three): ?float
    {
        if ($three->count() < 3) {
            return null;
        }
        $config = CfPlusValuationConfiguration::approved();
        $series = $three->map(fn ($r) => (float) (((array) $r->income_statement)['operating_income'] ?? 0))->values()->all();

        return (float) app(ConsultantValuationPrototype::class)->evaluateSeries($series, $config['annual_required_return'], $config['quarterly_growth_cap'], $config['annual_terminal_growth'], $config['forecast_quarters'])['consultant_value'];
    }

    /**
     * @param  list<array{id: string, name: string, values: array<int, float|null>}>  $firms
     * @return array{0: array<int, string>, 1: array<string, array<int, float|null>>}
     */
    private function leaders(array $firms, string $measure): array
    {
        $higher = self::MEASURES[$measure][1];
        $quarters = collect($firms)->flatMap(fn ($f) => array_keys($f['values']))->unique()->sort()->values();
        $leaders = [];
        $gaps = [];
        foreach ($quarters as $q) {
            $values = collect($firms)->mapWithKeys(fn ($f) => [$f['id'] => $f['values'][$q] ?? null])->filter(fn ($v) => $v !== null);
            if ($values->isEmpty()) {
                continue;
            }
            $best = $higher ? $values->max() : $values->min();
            $leaders[$q] = $values->search($best);
            foreach ($firms as $f) {
                $gaps[$f['id']][$q] = isset($f['values'][$q]) ? round(($higher ? $best - $f['values'][$q] : $f['values'][$q] - $best), 2) : null;
            }
        }

        return [$leaders, $gaps];
    }

    /**
     * The one-sentence answer, worked out in code: who leads now, and whether anyone
     * takes the lead before the course ends.
     *
     * @param  list<array{id: string, name: string, behaviour: string, values: array<int, float|null>, projected: list<int>}>  $firms
     * @param  array<int, string>  $leaders
     */
    private function answer(array $firms, array $leaders, int $last): string
    {
        $names = collect($firms)->pluck('name', 'id');
        $projected = collect($firms)->flatMap(fn ($f) => $f['projected'])->unique()->sort()->values();
        if ($projected->isEmpty()) {
            return 'Every quarter has closed, so there is nothing left to project.';
        }
        if (count($firms) < 2) {
            return 'There is only one firm, so there is no one to catch.';
        }
        $now = $leaders[$projected->first() - 1] ?? null;
        if ($now === null) {
            return 'There are no results to compare yet.';
        }
        foreach ($projected as $q) {
            if (($leaders[$q] ?? $now) !== $now) {
                return "{$names[$leaders[$q]]} catches {$names[$now]} in Quarter {$q} on these behaviours.";
            }
        }

        return "No firm catches {$names[$now]} by Quarter {$last} on these behaviours.";
    }
}
