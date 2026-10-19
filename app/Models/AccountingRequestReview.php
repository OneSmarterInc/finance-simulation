<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * U12: Claude's review of one accounting-method request. Faculty only.
 *
 * @property list<string>|null $reasons
 */
class AccountingRequestReview extends Model
{
    use HasUuids;

    public const WRITTEN = 'written';

    public const FAILED = 'failed';

    /** recommendation => what faculty read */
    public const RECOMMENDATIONS = [
        'approve' => 'Approve',
        'decline' => 'Decline',
        'ask' => 'Ask the team for more',
    ];

    protected $fillable = [
        'request_id', 'course_id', 'recommendation', 'reasons', 'reply', 'dropped', 'status', 'attempts',
        'model', 'input_tokens', 'output_tokens', 'cost_usd', 'last_error', 'written_at',
    ];

    protected function casts(): array
    {
        return [
            'reasons' => 'array',
            'dropped' => 'array',
            'attempts' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_usd' => 'float',
            'written_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<FirmAccountingElectionRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(FirmAccountingElectionRequest::class, 'request_id');
    }

    public function isWritten(): bool
    {
        return $this->status === self::WRITTEN;
    }
}
