<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CfPlusOffer extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = ['course_id', 'bidder_firm_id', 'target_firm_id', 'institution_id', 'offered_amount', 'stake', 'fundable_cap', 'bidder_cash', 'unused_committed_credit', 'target_acquisition_financing', 'submitted_at', 'sealed_at', 'locked_at'];

    protected function casts(): array
    {
        return ['offered_amount' => 'decimal:2', 'stake' => 'float', 'fundable_cap' => 'decimal:2', 'bidder_cash' => 'decimal:2', 'unused_committed_credit' => 'decimal:2', 'target_acquisition_financing' => 'decimal:2', 'submitted_at' => 'datetime', 'sealed_at' => 'datetime', 'locked_at' => 'datetime'];
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<Firm, $this> */
    public function bidderFirm(): BelongsTo
    {
        return $this->belongsTo(Firm::class, 'bidder_firm_id');
    }

    /** @return BelongsTo<Firm, $this> */
    public function targetFirm(): BelongsTo
    {
        return $this->belongsTo(Firm::class, 'target_firm_id');
    }
}
