<?php

namespace App\Models;

use Database\Factories\ScenarioQuarterDecisionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property array<string, mixed> $payload
 */
class ScenarioQuarterDecision extends Model
{
    /** @use HasFactory<ScenarioQuarterDecisionFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'scenario_id',
        'scenario_decision_category_id',
        'quarter_number',
        'payload',
        'null_fields',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'null_fields' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Scenario, $this>
     */
    public function scenario(): BelongsTo
    {
        return $this->belongsTo(Scenario::class);
    }

    /**
     * @return BelongsTo<ScenarioDecisionCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ScenarioDecisionCategory::class, 'scenario_decision_category_id');
    }
}
