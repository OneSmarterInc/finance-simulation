<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property array<int, string> $source_result_ids
 * @property array<string, string> $source_result_hashes
 */
class CfPlusAuctionEconomics extends Model
{
    use HasUuids;

    protected $table = 'cf_plus_auction_economics';

    protected $fillable = ['snapshot_id', 'course_id', 'firm_id', 'institution_id', 'disclosure_snapshot_id', 'boundary_result_id', 'boundary_result_hash', 'source_result_ids', 'source_result_hashes', 'normalized_quarterly_cash', 'normalized_annual_cash', 'full_consultant_value', 'consultant_stake_value', 'raw_full_true_worth', 'raw_true_stake_worth', 'displayed_full_true_worth', 'displayed_true_stake_worth', 'going_concern_status', 'target_acquisition_financing', 'bidder_cash', 'committed_credit_limit', 'committed_credit_balance', 'unused_committed_credit', 'bidder_own_funds'];

    protected function casts(): array
    {
        return ['source_result_ids' => 'array', 'source_result_hashes' => 'array', 'normalized_quarterly_cash' => 'decimal:2', 'normalized_annual_cash' => 'decimal:2', 'full_consultant_value' => 'decimal:2', 'consultant_stake_value' => 'decimal:2', 'raw_full_true_worth' => 'decimal:2', 'raw_true_stake_worth' => 'decimal:2', 'displayed_full_true_worth' => 'decimal:2', 'displayed_true_stake_worth' => 'decimal:2', 'target_acquisition_financing' => 'decimal:2', 'bidder_cash' => 'decimal:2', 'committed_credit_limit' => 'decimal:2', 'committed_credit_balance' => 'decimal:2', 'unused_committed_credit' => 'decimal:2', 'bidder_own_funds' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('CF Plus auction economics are immutable.'));
        static::deleting(fn () => throw new LogicException('CF Plus auction economics cannot be deleted.'));
    }

    /** @return BelongsTo<CfPlusAuctionEconomicsSnapshot, $this> */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(CfPlusAuctionEconomicsSnapshot::class, 'snapshot_id');
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<Firm, $this> */
    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    /** @return BelongsTo<CfPlusDisclosureSnapshot, $this> */
    public function disclosureSnapshot(): BelongsTo
    {
        return $this->belongsTo(CfPlusDisclosureSnapshot::class);
    }

    /** @return BelongsTo<FirmEngineResult, $this> */
    public function boundaryResult(): BelongsTo
    {
        return $this->belongsTo(FirmEngineResult::class, 'boundary_result_id');
    }
}
