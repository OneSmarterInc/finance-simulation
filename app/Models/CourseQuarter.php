<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CourseQuarter extends Model
{
    use HasUuids, SoftDeletes;

    public const LOCKED = 'locked';

    public const OPEN = 'open';

    public const PROCESSING = 'processing';

    public const CLOSED = 'closed';

    protected $fillable = ['course_id', 'quarter_number', 'starts_on', 'ends_on', 'decision_deadline_at', 'state', 'opened_at', 'close_started_at', 'closed_at'];

    protected function casts(): array
    {
        return ['quarter_number' => 'integer', 'starts_on' => 'date', 'ends_on' => 'date', 'decision_deadline_at' => 'datetime', 'opened_at' => 'datetime', 'close_started_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return HasMany<CourseDecisionEnablement, $this> */
    public function enablements(): HasMany
    {
        return $this->hasMany(CourseDecisionEnablement::class);
    }

    /** @return HasMany<FirmQuarterDecision, $this> */
    public function decisions(): HasMany
    {
        return $this->hasMany(FirmQuarterDecision::class);
    }

    /** @return HasMany<FirmQuarterClose, $this> */
    public function closeAttempts(): HasMany
    {
        return $this->hasMany(FirmQuarterClose::class);
    }
}
