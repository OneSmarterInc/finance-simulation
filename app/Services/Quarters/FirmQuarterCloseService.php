<?php

namespace App\Services\Quarters;

use App\Models\CourseQuarter;
use App\Models\Firm;
use App\Models\FirmEngineResult;
use App\Models\FirmQuarterClose;
use App\Models\FirmQuarterDecision;
use App\Services\CfPlus\FirmAccountingElectionService;
use App\Services\Decisions\FirmDecisionService;
use App\Services\Engines\CorporateFinance\AccountingIdentityValidator;
use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngine;
use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngineInput;
use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngineResult;
use App\Services\Engines\CorporateFinance\Exceptions\EngineCalculationException;
use App\Services\Engines\CorporateFinance\Exceptions\PlausibilityValidationException;
use App\Services\Engines\CorporateFinance\FinancialPlausibilityValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class FirmQuarterCloseService
{
    public function __construct(
        private readonly FirmDecisionService $decisions,
        private readonly ManufacturingEngine $engine,
        private readonly AccountingIdentityValidator $accounting,
        private readonly FinancialPlausibilityValidator $plausibility,
    ) {}

    public function close(FirmQuarterClose $attempt): void
    {
        if ($attempt->status === FirmQuarterClose::SUCCEEDED) {
            return;
        }
        $firm = Firm::query()->with('course.scenario')->findOrFail($attempt->firm_id);
        $quarter = CourseQuarter::query()->findOrFail($attempt->course_quarter_id);
        $decision = FirmQuarterDecision::query()->findOrFail($attempt->firm_quarter_decision_id);
        if (FirmEngineResult::query()->where('firm_id', $attempt->firm_id)->where('quarter_number', $quarter->quarter_number)->exists()) {
            $attempt->forceFill(['status' => FirmQuarterClose::SUCCEEDED, 'completed_at' => now(), 'safe_error' => null])->save();

            return;
        }
        $attempt->forceFill(['status' => FirmQuarterClose::PROCESSING, 'started_at' => now(), 'safe_error' => null])->save();
        try {
            $submitted = $decision->submitted_payload ?? [];
            $resolved = $this->decisions->resolveCompleteDecisions($firm, $quarter, $submitted);
            $parameters = $this->decisions->parameters($firm);
            $opening = $this->decisions->opening($firm, $quarter);
            $this->decisions->dividendLimit($firm, $quarter, $submitted, $decision->dividend);
            $result = $this->engine->calculate(new ManufacturingEngineInput($quarter->quarter_number, $resolved, $parameters, $opening, $decision->dividend));

            $this->accounting->validate($result->fields, $opening);

            $priorStored = $firm->engineResults()
                ->where('quarter_number', $quarter->quarter_number - 1)
                ->firstOrFail();

            $prior = new ManufacturingEngineResult(
                $priorStored->fields,
                $priorStored->income_statement,
                $priorStored->balance_sheet,
                $priorStored->cash_flow_statement,
                $priorStored->notes,
                $priorStored->intermediates
            );

            $plausibilityEvaluation = $this->plausibility->evaluate(
                $result,
                $opening,
                $prior
            );

            $operatingOutcomeRules = [

                'receivables_to_revenue_ratio',

                'payables_to_purchases_ratio',

                'inventory_to_cogs_ratio',

                'receivables_turnover',

                'payables_turnover',

                'inventory_turnover',

                'cash_movement_to_net_revenue',

                'bounded_qoq_bs_ar',

                'bounded_qoq_bs_ap',

            ];

            $operatingOutcomeDiagnostics = collect($plausibilityEvaluation)

                ->only($operatingOutcomeRules)

                ->filter(

                    fn(array $evaluation): bool => ($evaluation['evaluated'] ?? false) === true

                        && ($evaluation['passed'] ?? true) === false

                )

                ->map(

                    fn(array $evaluation, string $rule): array => [

                        'rule' => $rule,

                        'calculated' => $evaluation['calculated'],

                        'allowed_range' => $evaluation['permitted'],

                        'skip_reason' => $evaluation['skip_reason'],

                    ]

                )

                ->values()

                ->all();

            $this->plausibility->validate($result, $opening, $prior);

            DB::transaction(function () use ($attempt, $firm, $quarter, $resolved, $result, $operatingOutcomeDiagnostics): void {
                $payload = $this->payload($attempt, $firm, $quarter, $result);
                FirmEngineResult::query()->create($payload + ['payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION))]);
                if ($firm->course->cf_plus_enabled && $quarter->quarter_number === 4) {
                    app(FirmAccountingElectionService::class)->lock($firm, $resolved['notes_elections']);
                }
                FirmQuarterDecision::query()->whereKey($attempt->firm_quarter_decision_id)->update(['resolved_payload' => json_encode($resolved, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
                $attempt->forceFill([
                    'status' => FirmQuarterClose::SUCCEEDED,
                    'completed_at' => now(),
                    'safe_error' => null,
                    'diagnostic_detail' => [
                        'type' => 'plausibility',
                        'operating_outcome_violations' => $operatingOutcomeDiagnostics,
                    ],

                ])->save();
            });
        } catch (Throwable $e) {

            \Illuminate\Support\Facades\Log::warning('firm_close_failed', [

                'firm_id' => $attempt->firm_id,

                'quarter' => $quarter->quarter_number,

                'detail' => $e->getMessage(),

            ]);

            $attempt->forceFill([

                'status' => FirmQuarterClose::FAILED,

                'safe_error' => $this->safeError($e),

                'diagnostic_detail' => [

                    'exception' => get_class($e),

                    'message' => $e->getMessage(),

                ],

                'completed_at' => now(),

            ])->save();
        }
    }

    /** @return array<string, mixed> */
    private function payload(FirmQuarterClose $attempt, Firm $firm, CourseQuarter $quarter, ManufacturingEngineResult $result): array
    {
        return [
            'firm_id' => $attempt->firm_id,
            'course_id' => $attempt->course_id,
            'institution_id' => $firm->institution_id,
            'quarter_number' => $quarter->quarter_number,
            'fields' => $result->fields,
            'income_statement' => $result->incomeStatement,
            'balance_sheet' => $result->balanceSheet,
            'cash_flow_statement' => $result->cashFlowStatement,
            'notes' => $result->notes,
            'intermediates' => $result->intermediates,
            'engine_contract' => FirmEngineResult::ENGINE_CONTRACT,
            'provenance' => FirmEngineResult::PROVENANCE_STUDENT_CLOSE,
            'immutable' => true,
            'validated_at' => now()
        ];
    }

    /** @return array{category: string, message: string} */
    private function safeError(Throwable $exception): array
    {
        if ($exception instanceof PlausibilityValidationException) {
            return ['category' => 'plausibility', 'message' => 'Financial plausibility validation failed. Retry after support reviews the firm calculation.'];
        }
        if ($exception instanceof ValidationException) {
            return ['category' => 'decision', 'message' => 'The locked firm decision is missing or invalid. Review the submitted decision before retrying.'];
        }
        if ($exception instanceof EngineCalculationException) {
            $category = str_contains(strtolower($exception->getMessage()), 'dividend') ? 'dividend' : 'accounting';
            $message = $category === 'dividend'
                ? 'The locked dividend exceeds the current permitted maximum. Review the dividend before retrying.'
                : 'Accounting validation failed. Retry after support reviews the firm calculation.';

            return ['category' => $category, 'message' => $message];
        }

        return ['category' => 'system', 'message' => 'A system or worker error interrupted this firm close. It is safe to retry.'];
    }
}
