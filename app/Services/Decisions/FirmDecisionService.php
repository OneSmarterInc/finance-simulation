<?php

namespace App\Services\Decisions;

use App\Models\CourseQuarter;
use App\Models\Firm;
use App\Models\FirmMembership;
use App\Models\FirmQuarterDecision;
use App\Models\ScenarioDecisionCategory;
use App\Models\Student;
use App\Services\Accounting\FirmAccountingElectionService;
use App\Services\CfPlus\FirmAccountingElectionService as LegacyFirmAccountingElectionService;
use App\Services\Engines\CorporateFinance\CfManufacturingSimRulesService;
use App\Services\Engines\CorporateFinance\Contracts\DividendLimit;
use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngineInput;
use App\Services\Engines\CorporateFinance\Contracts\OpeningFinancialPosition;
use App\Services\Engines\CorporateFinance\DividendDecisionValidator;
use App\Services\Engines\CorporateFinance\ManufacturingCFEngine;
use App\Support\Scenario\CfManufacturingScenarioDefinition;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class FirmDecisionService
{
    public function authorize(Student $student, Firm $firm): void
    {
        $active = FirmMembership::query()
            ->where('firm_id', $firm->id)
            ->where('course_id', $firm->course_id)
            ->where('student_id', $student->id)
            ->exists();

        if (! $active || $student->institution_id !== $firm->institution_id) {
            throw new AuthorizationException(
                'This firm workspace is outside your active membership.'
            );
        }
    }

    /** @return array<string, ScenarioDecisionCategory> */
    public function enabledCategories(CourseQuarter $quarter): array
    {
        $enabled = $quarter->enablements()
            ->where('enabled', true)
            ->with('category')
            ->get()
            ->mapWithKeys(
                fn($row) => [$row->category->code => $row->category]
            )
            ->all();

        $quarter->loadMissing('course.scenario');

        /*
        * When the new Accounting Election Control is enabled,
        * accounting elections are managed through the dedicated
        * Accounting Elections workflow and must not appear as a
        * normal student decision category.
        */
         if ( $quarter->course->accounting_election_enabled  ) { 
            unset($enabled['notes_elections']); 
        }

        return $enabled;
    }

    /** @return array<int, array<string, mixed>> */
    public function decisionFields(
        CourseQuarter $quarter,
        ScenarioDecisionCategory $category
    ): array {
        /*
         * Accounting-election fields are student-selectable in Quarter 4
         * only when the course-level accounting-election control is enabled.
         */
          if ( $quarter->course->cf_plus_enabled
            && $quarter->quarter_number === 4
            && $category->code === 'notes_elections'
        ) {
            return app(LegacyFirmAccountingElectionService::class)
                ->studentVisibleFields();
        }

        return $category->input_fields['fields'] ?? [];
    }

    public function decision(
        Firm $firm,
        CourseQuarter $quarter
    ): FirmQuarterDecision {
        if (
            $quarter->course_id !== $firm->course_id
            || $quarter->quarter_number < 4
        ) {
            throw ValidationException::withMessages([
                'quarter' => 'Student decisions begin in Quarter 4.',
            ]);
        }

        return FirmQuarterDecision::query()->firstOrCreate(
            [
                'firm_id' => $firm->id,
                'course_quarter_id' => $quarter->id,
            ],
            [
                'course_id' => $firm->course_id,
                'institution_id' => $firm->institution_id,
                'draft_payload' => [],
                'dividend' => 0,
                'state' => FirmQuarterDecision::DRAFT,
            ]
        );
    }

    /** @param array<string, mixed> $payload */
    public function saveDraft(
        Student $student,
        Firm $firm,
        CourseQuarter $quarter,
        array $payload,
        mixed $dividend
    ): FirmQuarterDecision {
        $this->authorize($student, $firm);

        $decision = $this->editable($firm, $quarter);

        /*
         * When student accounting-election control is on, methods are handled
         * by the Accounting elections page and notes_elections is not an open
         * decision area, so a draft must not require it (walkthrough item 10).
         */
        $validated = $this->validatePayload($quarter, $payload, false);

        $this->numericDividend($dividend);

        $decision->forceFill([
            'draft_payload' => $validated,
            'dividend' => round((float) $dividend, 2),
            'state' => FirmQuarterDecision::DRAFT,
            'submitted_payload' => null,
            'submitted_at' => null,
            'submitted_by_student_id' => null,
        ])->save();

        return $decision->refresh();
    }

    /** @param array<string, mixed> $payload */
    public function submit(
        Student $student,
        Firm $firm,
        CourseQuarter $quarter,
        array $payload,
        mixed $dividend
    ): FirmQuarterDecision {
        $this->authorize($student, $firm);

        $decision = $this->editable($firm, $quarter);
        $validated = $this->validatePayload($quarter, $payload, true);

        $limit = $this->dividendLimit(
            $firm,
            $quarter,
            $validated,
            $dividend
        );

        $decision->forceFill([
            'draft_payload' => $validated,
            'submitted_payload' => $validated,
            'dividend' => $limit->requested,
            'state' => FirmQuarterDecision::SUBMITTED,
            'submitted_at' => now(),
            'submitted_by_student_id' => $student->id,
        ])->save();

        return $decision->refresh();
    }

    public function dividendLimit(
        Firm $firm,
        CourseQuarter $quarter,
        array $payload,
        mixed $requested = 0
    ): DividendLimit {
        $complete = $this->resolveCompleteDecisions(
            $firm,
            $quarter,
            $payload
        );

        $parameters = $this->parameters($firm);
        $opening = $this->opening($firm, $quarter);

        $preview = (new ManufacturingCFEngine)->calculate(
            new ManufacturingEngineInput(
                $quarter->quarter_number,
                $complete,
                $parameters,
                $opening,
                0
            )
        );

        return (new DividendDecisionValidator)->validate(
            $quarter->quarter_number,
            $requested,
            $opening->retainedEarnings,
            (float) $preview->incomeStatement['net_income'],
            (float) $preview->intermediates['pre_dividend_cash'],
            (float) $parameters['minimum_cash_buffer']
        );
    }

    /**
     * @param array<string, array<string, mixed>> $submitted
     * @return array<string, array<string, mixed>>
     */
    public function resolveCompleteDecisions(
        Firm $firm,
        CourseQuarter $quarter,
        array $submitted
    ): array {
        $enabled = $this->enabledCategories($quarter);

        $operational = array_values(
            array_intersect(
                array_keys($enabled),
                [
                    'forecasting',
                    'sourcing',
                    'pricing',
                    'production',
                    'marketing',
                    'quality',
                    'logistics',
                ]
            )
        );

        $studentOperational = array_intersect_key(
            $submitted,
            array_flip($operational)
        );

        $demand = $studentOperational['forecasting']
            ?? throw ValidationException::withMessages([
                'forecasting' =>
                'Forecasting must be submitted to supply the quarter demand context.',
            ]);

        $resolved = (new CfManufacturingSimRulesService)
            ->deriveCompleteDecisions(
                $quarter->quarter_number,
                $demand,
                $this->parameters($firm),
                $operational,
                $studentOperational
            );

        if ($firm->course->accounting_election_enabled) {
            /*
             * When accounting-election control is enabled, the engine uses
             * the effective quarter-specific accounting settings.
             *
             * Pending student requests are never used by the engine.
             */
            $resolved['notes_elections'] = app(
                FirmAccountingElectionService::class
            )->effective($firm, $quarter);
        } elseif ($firm->course->cf_plus_enabled) {
            /*
             * Preserve the existing CF Plus behavior when accounting-election
             * control is disabled.
             *
             * Accounting elections are submitted only in Quarter 4.
             * Later quarters use the locked Quarter 4 elections and must not
             * accept another student election payload.
             */
            if ($quarter->quarter_number === 4) {
                $resolved['notes_elections'] = app(
                    LegacyFirmAccountingElectionService::class
                )->validate(
                    $submitted['notes_elections']
                        ?? throw ValidationException::withMessages([
                            'notes_elections' =>
                            'Quarter 4 requires the firm accounting elections.',
                        ])
                );
            } else {
                if (array_key_exists('notes_elections', $submitted)) {
                    throw ValidationException::withMessages([
                        'notes_elections' =>
                        'Accounting elections were locked in Quarter 4 and cannot be submitted again.',
                    ]);
                }

                $resolved['notes_elections'] =
                    $firm->accounting_elections
                    ?? throw ValidationException::withMessages([
                        'notes_elections' =>
                        'The firm has no locked Quarter 4 accounting elections.',
                    ]);
            }
        } else {
            /*
             * Preserve the existing CF behavior when accounting-election
             * control is disabled.
             */
            $resolved['notes_elections'] = isset($enabled['notes_elections'])
                ? (
                    $submitted['notes_elections']
                    ?? throw ValidationException::withMessages([
                        'notes_elections' =>
                        'notes_elections is enabled and requires a submitted decision.',
                    ])
                )
                : CfManufacturingScenarioDefinition::fixedWarmupPayload(
                    'notes_elections'
                );
        }

        $resolved['certification'] = isset($enabled['certification'])
            ? (
                $submitted['certification']
                ?? throw ValidationException::withMessages([
                    'certification' =>
                    'certification is enabled and requires a submitted decision.',
                ])
            )
            : CfManufacturingScenarioDefinition::fixedWarmupPayload(
                'certification'
            );

        return $resolved;
    }

    /**
     * The previous quarter's complete decisions and dividend: the stored
     * resolved payload for a student quarter, or the regenerated warm-up
     * decisions before Quarter 4. Used to prefill the decision form (item 73)
     * and to carry decisions forward when a firm does not submit (N4).
     *
     * @return array{payload: array<string, array<string, mixed>>, dividend: float}|null
     */
    public function previousDecisions(Firm $firm, CourseQuarter $quarter): ?array
    {
        $previousNumber = $quarter->quarter_number - 1;
        if ($previousNumber < 1) {
            return null;
        }

        if ($previousNumber <= 3) {
            $entry = app(FirmDecisionHistory::class)->forQuarter($firm, $previousNumber);
            if ($entry['payload'] === null) {
                return null;
            }
            $payload = $entry['payload'];
            unset($payload['dividend']);

            return ['payload' => $payload, 'dividend' => 0.0];
        }

        $previous = FirmQuarterDecision::query()
            ->where('firm_id', $firm->id)
            ->whereHas('quarter', fn ($q) => $q->where('course_id', $firm->course_id)->where('quarter_number', $previousNumber))
            ->first();
        $payload = $previous?->resolved_payload ?? $previous?->submitted_payload;

        return $payload === null ? null : ['payload' => $payload, 'dividend' => (float) $previous->dividend];
    }

    /**
     * A complete payload for this quarter's open decision areas, built from
     * the previous quarter's decisions (N4). Fields with no previous value
     * take their default. The dividend is capped at what is permitted now.
     *
     * @return array{0: array<string, array<string, mixed>>, 1: float}
     */
    public function carryForwardPayload(Firm $firm, CourseQuarter $quarter): array
    {
        $previous = $this->previousDecisions($firm, $quarter);
        $payload = [];
        foreach ($this->enabledCategories($quarter) as $code => $category) {
            foreach ($this->decisionFields($quarter, $category) as $field) {
                $payload[$code][$field['key']] = $previous['payload'][$code][$field['key']]
                    ?? $this->fieldDefault($firm, $field);
            }
        }

        $dividend = (float) ($previous['dividend'] ?? 0.0);
        if ($dividend > 0) {
            try {
                $dividend = min($dividend, (float) $this->dividendLimit($firm, $quarter, $payload, 0)->maximum);
            } catch (\Throwable) {
                $dividend = 0.0;
            }
        }

        return [$payload, round(max($dividend, 0.0), 2)];
    }

    /** @param array<string, mixed> $field */
    public function fieldDefault(Firm $firm, array $field): mixed
    {
        if (isset($field['default_from_parameter'])) {
            return $this->parameters($firm)[$field['default_from_parameter']] ?? null;
        }

        return $field['default'] ?? null;
    }

    /** @return array<string, mixed> */
    public function parameters(Firm $firm): array
    {
        $firm->loadMissing('course.scenario');

        return $firm->course->scenario
            ->parameters()
            ->get()
            ->mapWithKeys(
                fn($row) => [
                    $row->parameter_key =>
                    $row->parameter_value['value'],
                ]
            )
            ->all();
    }

    public function opening(
        Firm $firm,
        CourseQuarter $quarter
    ): OpeningFinancialPosition {
        $prior = $firm->engineResults()
            ->where(
                'quarter_number',
                $quarter->quarter_number - 1
            )
            ->firstOrFail();

        return OpeningFinancialPosition::fromPriorEngineResult(
            $prior->fields
        );
    }

    private function editable(
        Firm $firm,
        CourseQuarter $quarter
    ): FirmQuarterDecision {
        if (
            $quarter->state !== CourseQuarter::OPEN
            || $quarter->quarter_number < 4
        ) {
            throw ValidationException::withMessages([
                'quarter' =>
                'This quarter is not open for student decisions.',
            ]);
        }

        $decision = $this->decision($firm, $quarter);

        if ($decision->state === FirmQuarterDecision::LOCKED) {
            throw ValidationException::withMessages([
                'quarter' =>
                'This decision is locked and read-only.',
            ]);
        }

        return $decision;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, array<string, mixed>>
     */
    private function validatePayload(
        CourseQuarter $quarter,
        array $payload,
        bool $complete
    ): array {
        $enabled = $this->enabledCategories($quarter);

        foreach ($payload as $category => $values) {
            if (! isset($enabled[$category])) {
                throw ValidationException::withMessages([
                    $category =>
                    "Decision category [{$category}] is not open.",
                ]);
            }

            if (! is_array($values)) {
                throw ValidationException::withMessages([
                    $category =>
                    'Category payload must be an object.',
                ]);
            }
        }

        if (
            $complete
            && array_diff(
                array_keys($enabled),
                array_keys($payload)
            ) !== []
        ) {
            throw ValidationException::withMessages([
                'decisions' =>
                'Every open category requires a complete decision.',
            ]);
        }

        foreach ($payload as $code => $values) {
            $fields = $this->decisionFields(
                $quarter,
                $enabled[$code]
            );

            $expected = array_column($fields, 'key');

            if (
                array_diff(
                    array_keys($values),
                    $expected
                ) !== []
            ) {
                throw ValidationException::withMessages([
                    $code =>
                    "Unexpected field supplied for [{$code}].",
                ]);
            }

            foreach ($fields as $field) {
                $key = $field['key'];

                if (
                    $complete
                    && ! array_key_exists($key, $values)
                ) {
                    throw ValidationException::withMessages([
                        "{$code}.{$key}" =>
                        'This field is required.',
                    ]);
                }

                if (! array_key_exists($key, $values)) {
                    continue;
                }

                /*
                 * Drafts may contain blank numeric inputs.
                 *
                 * On final submission, however, numeric fields must
                 * always contain a numeric value. This preserves the
                 * distinction between an incomplete draft and a
                 * complete submitted decision.
                 */
                if (($field['type'] ?? null) === 'number') {
                    $value = $values[$key];

                    $mustValidateNumeric =
                        $complete
                        || ($value !== null && $value !== '');

                    if (
                        $mustValidateNumeric
                        && ! is_numeric($value)
                    ) {
                        throw ValidationException::withMessages([
                            "{$code}.{$key}" =>
                            'This field must be numeric.',
                        ]);
                    }
                }

                if (
                    isset($field['allowed_values'])
                    && ! in_array(
                        $values[$key],
                        $field['allowed_values'],
                        true
                    )
                ) {
                    throw ValidationException::withMessages([
                        "{$code}.{$key}" =>
                        'This value is not allowed.',
                    ]);
                }
            }
        }

        return $payload;
    }

    private function numericDividend(mixed $dividend): void
    {
        if (
            ! is_numeric($dividend)
            || (float) $dividend < 0
        ) {
            throw ValidationException::withMessages([
                'dividend' =>
                'Dividend must be a nonnegative monetary value.',
            ]);
        }
    }
}
