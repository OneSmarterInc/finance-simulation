<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

/**
 * @property array<string, float> $fields
 * @property array<string, mixed> $income_statement
 * @property array<string, mixed> $balance_sheet
 * @property array<string, mixed> $cash_flow_statement
 * @property array<string, mixed> $notes
 * @property array<string, mixed> $intermediates
 */ class FirmEngineResult extends Model
{
    use HasUuids, SoftDeletes;

    public const PROVENANCE_WARMUP = 'inherited_warmup';

    public const PROVENANCE_STUDENT_CLOSE = 'student_quarter_close';

    public const ENGINE_CONTRACT = 'manufacturing-cf-public-v1';

    public const ENGINE_CONTRACT_V2 = 'manufacturing-cf-public-v2';

    public static function contractFor(int $engineVersion): string
    {
        return $engineVersion === 2 ? self::ENGINE_CONTRACT_V2 : self::ENGINE_CONTRACT;
    }

    protected $fillable = [
        'firm_id', 'course_id', 'institution_id', 'quarter_number', 'fields',
        'income_statement', 'balance_sheet', 'cash_flow_statement', 'notes',
        'intermediates', 'engine_contract', 'provenance', 'immutable',
        'payload_hash', 'validated_at',
    ];

    protected function casts(): array
    {
        return [
            'quarter_number' => 'integer',
            'fields' => 'array',
            'income_statement' => 'array',
            'balance_sheet' => 'array',
            'cash_flow_statement' => 'array',
            'notes' => 'array',
            'intermediates' => 'array',
            'immutable' => 'boolean',
            'validated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        $reject = static function (FirmEngineResult $result): void {
            if ($result->exists && $result->immutable) {
                throw new LogicException('Validated firm engine results are write-once and cannot be changed.');
            }
        };

        static::updating($reject);
        static::deleting($reject);
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
}
