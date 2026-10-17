<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $firm_id
 * @property string $course_id
 * @property string $course_quarter_id
 * @property string $firm_quarter_decision_id
 * @property string $status
 */
class FirmQuarterClose extends Model
{
    use HasUuids;

    public const PENDING = 'pending';

    public const PROCESSING = 'processing';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    protected $fillable = [

        'firm_id',

        'course_id',

        'course_quarter_id',

        'firm_quarter_decision_id',

        'status',

        'safe_error',

        'diagnostic_detail',

        'started_at',

        'completed_at',

    ];

    protected function casts(): array

    {

        return [

            'safe_error' => 'array',

            'diagnostic_detail' => 'array',

            'started_at' => 'datetime',

            'completed_at' => 'datetime',

        ];
    }

    /** @return BelongsTo<Firm, $this> */
    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    /** @return BelongsTo<CourseQuarter, $this> */
    public function quarter(): BelongsTo
    {
        return $this->belongsTo(CourseQuarter::class, 'course_quarter_id');
    }

    /** @return BelongsTo<FirmQuarterDecision, $this> */
    public function decision(): BelongsTo
    {
        return $this->belongsTo(FirmQuarterDecision::class, 'firm_quarter_decision_id');
    }
}
