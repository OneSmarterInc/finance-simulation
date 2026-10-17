<?php

namespace App\Services\CfPlus;

use App\Models\CfPlusAuctionEconomics;
use App\Models\CfPlusAuctionEconomicsSnapshot;
use App\Models\Course;
use App\Models\CourseQuarter;
use App\Models\Firm;
use App\Models\FirmEngineResult;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class CfPlusAuctionEconomicsSnapshotService
{
    public function __construct(
        private readonly CfPlusGate $gate,
        private readonly CfPlusDisclosureService $disclosures,
        private readonly CfPlusConsultantReportService $consultants,
        private readonly CfPlusTrueWorthService $trueWorth,
    ) {}

    public function createAtEvaluationOpening(Course $course, CourseQuarter $quarter): CfPlusAuctionEconomicsSnapshot
    {
        $this->gate->assertEnabled($course);
        $start = $this->gate->evaluationStartQuarter($course);
        if ($quarter->course_id !== $course->id || $quarter->quarter_number !== $start || $quarter->state !== CourseQuarter::OPEN) {
            throw new DomainException('CF PLUS AUCTION SNAPSHOT CANNOT OPEN - INVALID EVALUATION BOUNDARY.');
        }
        $boundaryQuarter = $course->quarters()->where('quarter_number', $start - 1)->first();
        if (! $boundaryQuarter || $boundaryQuarter->state !== CourseQuarter::CLOSED) {
            throw new DomainException('CF PLUS AUCTION SNAPSHOT CANNOT OPEN - FINAL BUILD QUARTER IS NOT CLOSED.');
        }

        return DB::transaction(function () use ($course, $quarter, $start): CfPlusAuctionEconomicsSnapshot {
            $lockedCourse = Course::query()->lockForUpdate()->findOrFail($course->id);
            $existing = CfPlusAuctionEconomicsSnapshot::query()->where('course_id', $course->id)->first();
            if ($existing) {
                $this->assertUniverseUnchanged($lockedCourse, $existing);

                return $existing;
            }

            $firms = $lockedCourse->firms()->orderBy('id')->get();
            if ($firms->isEmpty()) {
                throw new DomainException('CF PLUS AUCTION SNAPSHOT CANNOT OPEN - COURSE HAS NO FIRMS.');
            }
            $firmIds = $firms->pluck('id')->all();
            $studentIds = $lockedCourse->enrollments()->orderBy('student_id')->pluck('student_id')->all();
            $assignments = $lockedCourse->firms()->with('memberships')->get()->flatMap(fn (Firm $firm) => $firm->memberships->mapWithKeys(fn ($membership) => [$membership->student_id => $firm->id]))->sortKeys()->all();
            $valuationDate = $quarter->opened_at ?? now();
            $prepared = [];

            foreach ($firms as $firm) {
                $boundary = $firm->engineResults()->where('quarter_number', $start - 1)->where('immutable', true)->first();
                if (! $boundary || strlen($boundary->payload_hash) !== 64) {
                    throw new DomainException('CF PLUS AUCTION SNAPSHOT CANNOT OPEN - FIRM MISSING CLOSED BOUNDARY RESULT.');
                }
                $fields = $boundary->fields;
                foreach (['bs_cash', 'n5_limit', 'n5_balance'] as $key) {
                    if (Validator::make($fields, [$key => ['required', 'numeric']])->fails()) {
                        throw new DomainException("CF PLUS AUCTION SNAPSHOT CANNOT OPEN - MISSING AUCTION FIGURE [{$key}].");
                    }
                }

                $disclosure = $this->disclosures->openForFirm($lockedCourse, $firm);
                $sourceResults = FirmEngineResult::query()->whereIn('id', $disclosure->source_result_ids)->orderBy('quarter_number')->get();
                if ($sourceResults->count() !== 3 || ! $sourceResults->contains(fn (FirmEngineResult $result): bool => $result->id === $boundary->id) || $sourceResults->contains(fn (FirmEngineResult $result): bool => ! $result->immutable || strlen($result->payload_hash) !== 64)) {
                    throw new DomainException('CF PLUS AUCTION SNAPSHOT CANNOT OPEN - DISCLOSURE SOURCE RESULTS DO NOT MATCH THE EVALUATION BOUNDARY.');
                }
                $consultant = $this->consultants->generateApproved($disclosure);
                $worth = $this->trueWorth->calculate($disclosure);
                foreach (['representative_quarterly_recurring_cash', 'annual_normalized_recurring_cash', 'true_worth'] as $key) {
                    if (! array_key_exists($key, $worth) || ! is_numeric($worth[$key])) {
                        throw new DomainException("CF PLUS AUCTION SNAPSHOT CANNOT OPEN - MISSING AUCTION FIGURE [{$key}].");
                    }
                }

                $rawConsultant = $this->money((float) $consultant->consultant_value);
                $rawTrue = $this->money((float) $worth['true_worth']);
                $annual = $this->money((float) $worth['annual_normalized_recurring_cash']);
                $cash = $this->money((float) $fields['bs_cash']);
                $limit = $this->money((float) $fields['n5_limit']);
                $balance = $this->money((float) $fields['n5_balance']);
                $unused = $this->money(max(0, $limit - $balance));
                $financing = $rawTrue <= 0 ? 0.0 : $this->money(max(0, $annual) * CfPlusAcquisitionFundingService::FINANCING_MULTIPLE);
                $hashes = $sourceResults->mapWithKeys(fn (FirmEngineResult $result): array => [$result->id => $result->payload_hash])->all();
                $prepared[] = compact('firm', 'boundary', 'disclosure', 'rawConsultant', 'rawTrue', 'annual', 'cash', 'limit', 'balance', 'unused', 'financing', 'hashes') + ['quarterly' => $this->money((float) $worth['representative_quarterly_recurring_cash'])];
            }

            $universe = $this->hash(['course_id' => $course->id, 'firm_ids' => $firmIds, 'student_ids' => $studentIds, 'student_assignments' => $assignments, 'sources' => collect($prepared)->mapWithKeys(fn (array $row): array => [$row['firm']->id => $row['hashes']])->all()]);
            $snapshot = CfPlusAuctionEconomicsSnapshot::query()->create(['course_id' => $course->id, 'institution_id' => $course->institution_id, 'evaluation_start_quarter' => $start, 'valuation_date' => $valuationDate, 'firm_ids' => $firmIds, 'student_ids' => $studentIds, 'student_assignments' => $assignments, 'source_universe_hash' => $universe]);

            foreach ($prepared as $row) {
                $displayedConsultant = max(0, $row['rawConsultant']);
                $displayedTrue = max(0, $row['rawTrue']);
                CfPlusAuctionEconomics::query()->create([
                    'snapshot_id' => $snapshot->id, 'course_id' => $course->id, 'firm_id' => $row['firm']->id, 'institution_id' => $course->institution_id,
                    'disclosure_snapshot_id' => $row['disclosure']->id, 'boundary_result_id' => $row['boundary']->id, 'boundary_result_hash' => $row['boundary']->payload_hash,
                    'source_result_ids' => $row['disclosure']->source_result_ids, 'source_result_hashes' => $row['hashes'],
                    'normalized_quarterly_cash' => $row['quarterly'], 'normalized_annual_cash' => $row['annual'],
                    'full_consultant_value' => $displayedConsultant, 'consultant_stake_value' => $this->money($displayedConsultant * CfPlusAcquisitionFundingService::CONTROL_STAKE),
                    'raw_full_true_worth' => $row['rawTrue'], 'raw_true_stake_worth' => $this->money($row['rawTrue'] * CfPlusAcquisitionFundingService::CONTROL_STAKE),
                    'displayed_full_true_worth' => $displayedTrue, 'displayed_true_stake_worth' => $this->money($displayedTrue * CfPlusAcquisitionFundingService::CONTROL_STAKE),
                    'going_concern_status' => $displayedTrue <= 0 ? 'NO POSITIVE GOING-CONCERN VALUE' : 'POSITIVE GOING-CONCERN VALUE',
                    'target_acquisition_financing' => $row['financing'], 'bidder_cash' => $row['cash'], 'committed_credit_limit' => $row['limit'], 'committed_credit_balance' => $row['balance'],
                    'unused_committed_credit' => $row['unused'], 'bidder_own_funds' => $this->money($row['cash'] + $row['unused']),
                ]);
            }

            return $snapshot->load('economics');
        });
    }

    public function forCourse(Course $course): CfPlusAuctionEconomicsSnapshot
    {
        $snapshot = CfPlusAuctionEconomicsSnapshot::query()->where('course_id', $course->id)->first();
        if (! $snapshot) {
            throw new DomainException('CF Plus auction economics snapshot is unavailable.');
        }
        $this->assertUniverseUnchanged($course, $snapshot);

        return $snapshot;
    }

    private function assertUniverseUnchanged(Course $course, CfPlusAuctionEconomicsSnapshot $snapshot): void
    {
        $firmIds = $course->firms()->orderBy('id')->pluck('id')->all();
        if ($firmIds !== $snapshot->firm_ids) {
            throw new DomainException('CF PLUS AUCTION SNAPSHOT UNIVERSE CHANGED AFTER EVALUATION OPENED.');
        }
    }

    private function money(float $value): float
    {
        return round($value, 2);
    }

    /** @param array<string, mixed> $value */
    private function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
}
