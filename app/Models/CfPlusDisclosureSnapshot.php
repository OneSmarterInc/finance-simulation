<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

/**
 * @property array<int, string> $source_result_ids
 * @property array<int, array<string, mixed>> $published_statements
 * @property array<int, array<string, mixed>> $notes_and_disclosures
 * @property array<string, mixed> $accounting_elections
 */
class CfPlusDisclosureSnapshot extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = ['course_id', 'target_firm_id', 'institution_id', 'opened_at_quarter', 'source_result_ids', 'published_statements', 'notes_and_disclosures', 'accounting_elections', 'payload_hash', 'disclosed_at'];

    protected function casts(): array
    {
        return ['opened_at_quarter' => 'integer', 'source_result_ids' => 'array', 'published_statements' => 'array', 'notes_and_disclosures' => 'array', 'accounting_elections' => 'array', 'disclosed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('CF Plus disclosure snapshots are immutable.'));
        static::deleting(fn () => throw new LogicException('CF Plus disclosure snapshots cannot be deleted.'));
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

    /** @return HasOne<CfPlusConsultantReport, $this> */
    public function consultantReport(): HasOne
    {
        return $this->hasOne(CfPlusConsultantReport::class, 'disclosure_snapshot_id');
    }
}
