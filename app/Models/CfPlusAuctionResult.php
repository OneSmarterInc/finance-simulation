<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** @property array<int, string> $tied_top_bidder_ids */
class CfPlusAuctionResult extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = ['course_id', 'target_firm_id', 'institution_id', 'normal_winner_firm_id', 'winning_bidder_firm_id', 'winning_offer_id', 'price_paid', 'stake', 'full_consultant_value', 'consultant_stake_value', 'raw_full_true_worth', 'displayed_full_true_worth', 'true_stake_worth', 'winning_fundable_cap', 'tied_top_bidder_ids', 'faculty_override', 'override_faculty_id', 'override_reason', 'overridden_at', 'settled_at'];

    protected function casts(): array
    {
        return ['price_paid' => 'decimal:2', 'stake' => 'float', 'full_consultant_value' => 'decimal:2', 'consultant_stake_value' => 'decimal:2', 'raw_full_true_worth' => 'decimal:2', 'displayed_full_true_worth' => 'decimal:2', 'true_stake_worth' => 'decimal:2', 'winning_fundable_cap' => 'decimal:2', 'tied_top_bidder_ids' => 'array', 'faculty_override' => 'boolean', 'overridden_at' => 'datetime', 'settled_at' => 'datetime'];
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<Firm, $this> */
    public function targetFirm(): BelongsTo
    {
        return $this->belongsTo(Firm::class, 'target_firm_id');
    }

    /** @return BelongsTo<Firm, $this> */
    public function winner(): BelongsTo
    {
        return $this->belongsTo(Firm::class, 'winning_bidder_firm_id');
    }

    /** @return BelongsTo<CfPlusOffer, $this> */
    public function winningOffer(): BelongsTo
    {
        return $this->belongsTo(CfPlusOffer::class, 'winning_offer_id');
    }
}
