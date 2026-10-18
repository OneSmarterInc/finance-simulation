<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/** One question to Ask Claude, kept for 90 days (U1, AC7). Students appear only as "Student 1", "Student 2". */
class AiQuestion extends Model
{
    use HasUuids, MassPrunable;

    public const ANSWERED = 'answered';

    public const FAILED = 'failed';

    public const UPDATED_AT = null;

    protected $fillable = ['course_id', 'faculty_id', 'page', 'context', 'question', 'answer', 'lookups', 'dropped', 'status', 'input_tokens', 'output_tokens', 'cost_usd'];

    protected function casts(): array
    {
        return ['context' => 'array', 'lookups' => 'array', 'dropped' => 'array', 'input_tokens' => 'integer', 'output_tokens' => 'integer', 'cost_usd' => 'float', 'created_at' => 'datetime'];
    }

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays((int) config('flexee.ai.keep_days', 90)));
    }
}
