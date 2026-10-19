<?php

namespace App\Services\Debrief;

use App\Models\CourseQuarter;
use App\Models\Firm;
use App\Models\FirmEngineResult;
use App\Models\FirmQuarterDecision;
use App\Services\Decisions\FirmDecisionService;
use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngine;
use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngineInput;
use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngineResult;
use App\Services\Engines\CorporateFinance\Contracts\OpeningFinancialPosition;

/**
 * U4: play a closed quarter again in memory, with the decisions the firm
 * actually played and a different set of accounting methods. Nothing is
 * saved. "Methods that moved the numbers" uses it to show a quarter's
 * reported profit both ways: the engine works it out, nothing is estimated.
 */
class QuarterReplay
{
    public function __construct(
        private readonly FirmDecisionService $decisions,
        private readonly ManufacturingEngine $engine,
    ) {}

    /** @param array<string, mixed>|null $elections the methods to play with; null keeps the ones the quarter used */
    public function replay(Firm $firm, int $quarterNumber, ?array $elections = null): ?ManufacturingEngineResult
    {
        $quarter = CourseQuarter::query()->where('course_id', $firm->course_id)->where('quarter_number', $quarterNumber)->first();
        $prior = FirmEngineResult::query()->where('firm_id', $firm->id)->where('quarter_number', $quarterNumber - 1)->first();
        $decision = $quarter ? FirmQuarterDecision::query()->where('firm_id', $firm->id)->where('course_quarter_id', $quarter->id)->first() : null;
        if (! $quarter || ! $prior || ! $decision?->resolved_payload) {
            return null;
        }

        $resolved = (array) $decision->resolved_payload;
        if ($elections !== null) {
            $resolved['notes_elections'] = array_replace((array) ($resolved['notes_elections'] ?? []), $elections);
        }
        $version = $firm->course->engineVersion();
        $opening = OpeningFinancialPosition::fromPriorEngineResult((array) $prior->fields);
        $parameters = $this->decisions->parameters($firm);
        $market = $version === 2 ? $this->decisions->market($firm, $quarter) : null;
        $input = fn (float $d) => new ManufacturingEngineInput($quarterNumber, $resolved, $parameters, $opening, $d, $version, $market);

        // The dividend as the close settled it (ED17): declared, cut to what the quarter could fund.
        $dividend = round(max(0.0, (float) $decision->dividend), 2);
        if ($dividend > 0) {
            $dividend = min($dividend, round((float) $this->engine->calculate($input(0.0))->intermediates['maximum_dividend'], 2));
        }

        return $this->engine->calculate($input($dividend));
    }
}
