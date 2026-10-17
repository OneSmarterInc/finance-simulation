<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * @property array<string, array<string, mixed>> $draft_payload
 * @property array<string, array<string, mixed>>|null $submitted_payload
 * @property array<string, array<string, mixed>>|null $resolved_payload
 * @property string $dividend
 */
class FirmQuarterDecision extends Model
{
    use HasUuids;

    public const DRAFT = 'draft';

    public const SUBMITTED = 'submitted';

    public const LOCKED = 'locked';

    protected $fillable = ['firm_id', 'course_id', 'course_quarter_id', 'institution_id', 'draft_payload', 'submitted_payload', 'resolved_payload', 'dividend', 'state', 'submitted_at', 'locked_at'];

    protected function casts(): array
    {
        return ['draft_payload' => 'array', 'submitted_payload' => 'array', 'resolved_payload' => 'array', 'dividend' => 'decimal:2', 'submitted_at' => 'datetime', 'locked_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $decision): void {
            if ($decision->getOriginal('state') === self::LOCKED) {
                throw new LogicException('Locked firm-quarter decisions cannot be changed.');
            }
        });
        static::deleting(fn () => throw new LogicException('Firm-quarter decisions cannot be deleted.'));
    }

    /** @return BelongsTo<Firm, $this> */
    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<CourseQuarter, $this> */
    public function quarter(): BelongsTo
    {
        return $this->belongsTo(CourseQuarter::class, 'course_quarter_id');
    }

    /** @return HasOne<FirmQuarterClose, $this> */
    public function closeAttempt(): HasOne
    {
        return $this->hasOne(FirmQuarterClose::class);
    }
}
