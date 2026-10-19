<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * L15: Claude's reading of one firm's quarter, written once after the close.
 *
 * @property array<string, string|null>|null $sections
 * @property list<array{section: string, sentence: string, reason: string}>|null $dropped
 */
class FirmQuarterReading extends Model
{
    use HasUuids;

    public const WRITTEN = 'written';

    public const FAILED = 'failed';

    protected $fillable = [
        'firm_id', 'course_id', 'firm_engine_result_id', 'quarter_number', 'sections', 'dropped', 'source_hash',
        'status', 'attempts', 'model', 'input_tokens', 'output_tokens', 'cost_usd', 'last_error', 'written_at',
    ];

    protected function casts(): array
    {
        return [
            'quarter_number' => 'integer',
            'sections' => 'array',
            'dropped' => 'array',
            'attempts' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_usd' => 'float',
            'written_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Firm, $this> */
    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function isWritten(): bool
    {
        return $this->status === self::WRITTEN;
    }
}
