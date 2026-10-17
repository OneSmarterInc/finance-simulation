<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property Carbon $valuation_date
 * @property array<int, string> $firm_ids
 * @property array<int, string> $student_ids
 * @property array<string, string> $student_assignments
 */
class CfPlusAuctionEconomicsSnapshot extends Model
{
    use HasUuids;

    protected $fillable = ['course_id', 'institution_id', 'evaluation_start_quarter', 'valuation_date', 'firm_ids', 'student_ids', 'student_assignments', 'source_universe_hash'];

    protected function casts(): array
    {
        return ['evaluation_start_quarter' => 'integer', 'valuation_date' => 'datetime', 'firm_ids' => 'array', 'student_ids' => 'array', 'student_assignments' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('CF Plus auction economics snapshots are immutable.'));
        static::deleting(fn () => throw new LogicException('CF Plus auction economics snapshots cannot be deleted.'));
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return HasMany<CfPlusAuctionEconomics, $this> */
    public function economics(): HasMany
    {
        return $this->hasMany(CfPlusAuctionEconomics::class, 'snapshot_id');
    }
}
