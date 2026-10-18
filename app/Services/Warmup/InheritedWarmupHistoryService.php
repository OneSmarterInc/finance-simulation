<?php

namespace App\Services\Warmup;

use App\Models\Firm;
use App\Models\FirmEngineResult;
use App\Services\Engines\CorporateFinance\AccountingIdentityValidator;
use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngineResult;
use App\Services\Engines\CorporateFinance\FinancialPlausibilityValidator;
use App\Services\Warmup\Contracts\WarmupQuarterResult;
use App\Services\Warmup\Exceptions\WarmupGenerationException;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Throwable;

class InheritedWarmupHistoryService
{
    public function __construct(
        private readonly CfManufacturingWarmupService $warmup,
        private readonly AccountingIdentityValidator $accountingValidator,
        private readonly FinancialPlausibilityValidator $plausibilityValidator,
    ) {}

    public function initialize(Firm $firm): void
    {
        $firm->loadMissing('course.scenario');
        $existing = $firm->engineResults()->where('provenance', FirmEngineResult::PROVENANCE_WARMUP)
            ->pluck('quarter_number')->sort()->values()->all();
        if ($existing === [1, 2, 3]) {
            return;
        }
        if ($existing !== []) {
            throw new RuntimeException('Partial inherited warm-up history requires manual investigation.');
        }

        try {
            $generated = $this->warmup->generateFromSeedHash(
                FirmWarmupInitializationCoordinator::courseSeed($firm->course),
                $firm->id,
                $firm->course->scenario,
                $firm->course->engineVersion(),
                $firm->course->startingElections(),
            );
            if (array_keys($generated->quarters) !== [1, 2, 3]) {
                throw new RuntimeException('Warm-up must return exactly Quarters 1 through 3.');
            }

            $prior = null;
            foreach ($generated->quarters as $quarter) {
                try {
                    $this->validateQuarter($quarter, $prior);
                    $prior = $quarter->statements;
                } catch (WarmupGenerationException $exception) {
                    throw $exception;
                } catch (Throwable $exception) {
                    throw new WarmupGenerationException($quarter->quarter, [
                        'firm_hash' => hash('sha256', $firm->id),
                        'validation_stage' => 'persistence_boundary',
                        'failure_type' => $exception::class,
                    ], $exception);
                }
            }

            DB::transaction(function () use ($firm, $generated): void {
                $locked = Firm::query()->lockForUpdate()->findOrFail($firm->id);
                $existing = $locked->engineResults()->where('provenance', FirmEngineResult::PROVENANCE_WARMUP)
                    ->pluck('quarter_number')->sort()->values()->all();
                if ($existing === [1, 2, 3]) {
                    return;
                }
                if ($existing !== []) {
                    throw new RuntimeException('Partial inherited warm-up history requires manual investigation.');
                }

                foreach ($generated->quarters as $quarter) {
                    $payload = $this->payload($locked, $quarter);
                    FirmEngineResult::query()->create($payload + [
                        'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)),
                    ]);
                }

                $locked->forceFill([
                    'warmup_status' => Firm::WARMUP_INITIALIZED,
                    'initialized_at' => now(),
                    'initialization_error' => null,
                    'initialization_failed_at' => null,
                ])->save();
            });
        } catch (Throwable $exception) {
            Firm::query()->find($firm->id)?->forceFill([
                'warmup_status' => Firm::WARMUP_FAILED,
                'initialization_failed_at' => now(),
                'initialization_error' => [
                    'type' => $exception::class,
                    'message_hash' => hash('sha256', $exception->getMessage()),
                ],
            ])->save();

            throw $exception;
        }
    }

    /** @param array<string, mixed> $payload */
    public function replaceInherited(FirmEngineResult $result, array $payload): never
    {
        throw new LogicException('Inherited warm-up history is write-once and cannot be replaced.');
    }

    public function toEngineResult(FirmEngineResult $stored): ManufacturingEngineResult
    {
        return new ManufacturingEngineResult(
            $stored->fields,
            $stored->income_statement,
            $stored->balance_sheet,
            $stored->cash_flow_statement,
            $stored->notes,
            $stored->intermediates,
        );
    }

    private function validateQuarter(WarmupQuarterResult $quarter, ?ManufacturingEngineResult $prior): void
    {
        $this->accountingValidator->validate($quarter->statements->fields, $quarter->opening);
        $this->plausibilityValidator->validate($quarter->statements, $quarter->opening, $prior);
    }

    /** @return array<string, mixed> */
    private function payload(Firm $firm, WarmupQuarterResult $quarter): array
    {
        return [
            'firm_id' => $firm->id,
            'course_id' => $firm->course_id,
            'institution_id' => $firm->institution_id,
            'quarter_number' => $quarter->quarter,
            'fields' => $quarter->statements->fields,
            'income_statement' => $quarter->statements->incomeStatement,
            'balance_sheet' => $quarter->statements->balanceSheet,
            'cash_flow_statement' => $quarter->statements->cashFlowStatement,
            'notes' => $quarter->statements->notes,
            'intermediates' => $quarter->statements->intermediates,
            'engine_contract' => FirmEngineResult::contractFor($firm->course->engineVersion()),
            'provenance' => FirmEngineResult::PROVENANCE_WARMUP,
            'immutable' => true,
            'validated_at' => now(),
        ];
    }
}
