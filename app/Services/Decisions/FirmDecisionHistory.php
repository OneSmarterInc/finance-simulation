<?php

namespace App\Services\Decisions;

use App\Models\CourseQuarter;
use App\Models\Firm;
use App\Models\FirmQuarterDecision;
use App\Services\Warmup\CfManufacturingWarmupService;
use App\Services\Warmup\FirmWarmupInitializationCoordinator;
use Throwable;

/**
 * The decisions behind every closed quarter (walkthrough item 36,
 * Production Spec 4.2).
 *
 * Warm-up Quarters 1 to 3 are not stored as decision rows. They are
 * deterministic, so they are regenerated from the course seed exactly as
 * the warm-up produced them. Student quarters use the resolved payload
 * stored when the quarter closed: the team's own choices for open
 * decision areas, the simulation's choices for the rest.
 */
class FirmDecisionHistory
{
    /** @var array<string, array<int, array<string, array<string, mixed>>>> */
    private array $warmupCache = [];

    public function __construct(
        private readonly CfManufacturingWarmupService $warmup,
        private readonly FirmDecisionService $decisions,
    ) {}

    /**
     * @return array{payload: array<string, array<string, mixed>>|null, chosen_by: array<string, string>, dividend: float|null, carried_forward: bool}
     */
    public function forQuarter(Firm $firm, int $quarterNumber): array
    {
        if ($quarterNumber <= 3) {
            $payload = $this->warmupDecisions($firm)[$quarterNumber] ?? null;

            return [
                'payload' => $payload === null ? null : $payload + ['dividend' => ['dividend' => 0.0]],
                'chosen_by' => $payload === null ? [] : array_fill_keys([...array_keys($payload), 'dividend'], 'Simulation (warm-up)'),
                'dividend' => 0.0,
                'carried_forward' => false,
            ];
        }

        $quarter = CourseQuarter::query()
            ->where('course_id', $firm->course_id)
            ->where('quarter_number', $quarterNumber)
            ->first();
        $record = $quarter
            ? FirmQuarterDecision::query()->where('firm_id', $firm->id)->where('course_quarter_id', $quarter->id)->first()
            : null;

        if (! $record || $record->resolved_payload === null) {
            return ['payload' => null, 'chosen_by' => [], 'dividend' => null, 'carried_forward' => false];
        }

        $enabled = array_keys($this->decisions->enabledCategories($quarter));
        $team = $record->carried_forward ? 'Carried forward, no submission' : 'Team';
        $chosenBy = [];
        foreach (array_keys($record->resolved_payload) as $category) {
            $chosenBy[$category] = match (true) {
                $category === 'notes_elections' && $firm->course->accounting_election_enabled => 'Approved methods',
                in_array($category, $enabled, true) => $team,
                default => 'Simulation',
            };
        }
        $chosenBy['dividend'] = $team;

        return [
            'payload' => $record->resolved_payload + ['dividend' => ['dividend' => (float) $record->dividend]],
            'chosen_by' => $chosenBy,
            'dividend' => (float) $record->dividend,
            'carried_forward' => (bool) $record->carried_forward,
        ];
    }

    /** @return array<int, array<string, array<string, mixed>>> */
    public function warmupDecisions(Firm $firm): array
    {
        if (isset($this->warmupCache[$firm->id])) {
            return $this->warmupCache[$firm->id];
        }

        try {
            $firm->loadMissing('course.scenario');
            $generated = $this->warmup->generateFromSeedHash(
                FirmWarmupInitializationCoordinator::courseSeed($firm->course),
                FirmWarmupInitializationCoordinator::marketIdentifier($firm),
                $firm->course->scenario,
                $firm->course->engineVersion(),
                $firm->course->startingElections(),
            );
            $stored = $firm->engineResults()->where('quarter_number', '<=', 3)->get()->keyBy('quarter_number');
            $decisions = [];
            foreach ($generated->quarters as $number => $quarter) {
                // Only show regenerated decisions that reproduce the stored statements.
                $storedNetIncome = $stored->get($number)?->income_statement['net_income'] ?? null;
                if ($storedNetIncome === null || abs((float) $storedNetIncome - (float) $quarter->statements->incomeStatement['net_income']) > 0.01) {
                    return $this->warmupCache[$firm->id] = [];
                }
                $decisions[$number] = $quarter->decisions;
            }
        } catch (Throwable) {
            $decisions = [];
        }

        return $this->warmupCache[$firm->id] = $decisions;
    }
}
