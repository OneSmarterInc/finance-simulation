<?php

namespace App\Services\CfPlus;

use App\Models\CfPlusDisclosureSnapshot;
use App\Models\Course;
use App\Models\Firm;
use App\Models\FirmEngineResult;
use DomainException;
use Illuminate\Support\Facades\DB;

class CfPlusDisclosureService
{
    public function __construct(private readonly CfPlusGate $gate) {}

    public function openForFirm(Course $course, Firm $target): CfPlusDisclosureSnapshot
    {
        $this->gate->assertEnabled($course);
        if ($target->course_id !== $course->id || $target->accounting_elections_locked_at === null) {
            throw new DomainException('Disclosure requires a same-course target with locked Quarter 4 accounting elections.');
        }
        $existing = CfPlusDisclosureSnapshot::query()->where('course_id', $course->id)->where('target_firm_id', $target->id)->first();
        if ($existing) {
            return $existing;
        }
        $start = $this->gate->evaluationStartQuarter($course);
        $results = $target->engineResults()->where('quarter_number', '<', $start)->orderByDesc('quarter_number')->limit(3)->get()->sortBy('quarter_number')->values();
        if ($results->count() !== 3 || $results->contains(fn (FirmEngineResult $result) => ! $result->immutable)) {
            throw new DomainException('Disclosure requires three immutable published operating-quarter results.');
        }
        $statements = $results->map(fn (FirmEngineResult $result): array => ['quarter_number' => $result->quarter_number, 'income_statement' => $result->income_statement, 'balance_sheet' => $result->balance_sheet, 'cash_flow_statement' => $result->cash_flow_statement])->all();
        $notes = $results->map(fn (FirmEngineResult $result): array => ['quarter_number' => $result->quarter_number, 'notes' => $result->notes])->all();
        $hashable = ['source_result_ids' => $results->pluck('id')->all(), 'published_statements' => $statements, 'notes_and_disclosures' => $notes, 'accounting_elections' => $target->accounting_elections];

        return DB::transaction(fn () => CfPlusDisclosureSnapshot::query()->create(['course_id' => $course->id, 'target_firm_id' => $target->id, 'institution_id' => $course->institution_id, 'opened_at_quarter' => $start, ...$hashable, 'payload_hash' => hash('sha256', json_encode($hashable, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)), 'disclosed_at' => now()]));
    }

    public function rivalSnapshot(Course $course, Firm $viewer, Firm $target): CfPlusDisclosureSnapshot
    {
        $this->gate->assertEnabled($course);
        if ($viewer->course_id !== $course->id || $target->course_id !== $course->id || $viewer->is($target)) {
            throw new DomainException('CF Plus disclosure is limited to rival firms in the same course.');
        }

        return CfPlusDisclosureSnapshot::query()->where('course_id', $course->id)->where('target_firm_id', $target->id)->firstOrFail();
    }
}
