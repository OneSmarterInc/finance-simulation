<?php

namespace App\Services\Accounting;

use App\Models\CourseQuarter;
use App\Models\Firm;
use App\Models\FirmAccountingElection;
use App\Services\CfPlus\FirmAccountingElectionService as LegacyFirmAccountingElectionService;
use App\Support\Scenario\CfManufacturingScenarioDefinition;

class FirmAccountingElectionService
{
    public function effective(Firm $firm, CourseQuarter $quarter): array
    {
        $record = FirmAccountingElection::query()
            ->where('firm_id', $firm->id)
            ->where('course_quarter_id', $quarter->id)
            ->first();

        if ($record !== null) {
            return $record->elections;
        }

        return $this->inheritedOrDefault($firm, $quarter);
    }

    public function inheritedOrDefault(
        Firm $firm,
        CourseQuarter $quarter
    ): array {
        $previous = FirmAccountingElection::query()
            ->where('firm_id', $firm->id)
            ->whereHas(
                'courseQuarter',
                fn($query) => $query->where(
                    'quarter_number',
                    '<',
                    $quarter->quarter_number
                )
            )
            ->with('courseQuarter')
            ->get()
            ->sortByDesc(
                fn(FirmAccountingElection $record): int =>
                (int) $record->courseQuarter->quarter_number
            )
            ->first();

        if ($previous !== null) {
            return $previous->elections;
        }

        if (
            is_array($firm->accounting_elections)
            && $firm->accounting_elections !== []
        ) {
            return $firm->accounting_elections;
        }

        $fields = CfManufacturingScenarioDefinition::inputFieldsForCategory(

            'notes_elections'

        )['fields'];

        $defaults = collect($fields)

            ->mapWithKeys(

                fn(array $field): array => [

                    $field['key'] => $field['default'] ?? null,

                ]

            )

            ->all();

        return app(LegacyFirmAccountingElectionService::class)

            ->validate($defaults);
    }

    public function setEffective(

        Firm $firm,

        CourseQuarter $quarter,

        array $elections

    ): FirmAccountingElection {

        $validated = app(

            LegacyFirmAccountingElectionService::class

        )->validate($elections);

        $validated['inventory_method'] = 'fifo';

        return FirmAccountingElection::query()->updateOrCreate(

            [

                'firm_id' => $firm->id,

                'course_quarter_id' => $quarter->id,

            ],

            [

                'course_id' => $firm->course_id,

                'elections' => $validated,

            ]

        );
    }
}
