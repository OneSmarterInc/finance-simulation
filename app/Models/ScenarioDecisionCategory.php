<?php

namespace App\Models;

use Database\Factories\ScenarioDecisionCategoryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** @property array{fields: array<int, array<string, mixed>>, version?: string, source?: string} $input_fields */
class ScenarioDecisionCategory extends Model
{
    /** @use HasFactory<ScenarioDecisionCategoryFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'scenario_id',
        'code',
        'display_name',
        'sort_order',
        'input_fields',
        'default_open_quarter',
    ];

    protected function casts(): array
    {
        return [
            'input_fields' => 'array',
            'default_open_quarter' => 'integer',
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
     * @return HasMany<ScenarioQuarterDecision, $this>
     */
    public function quarterDecisions(): HasMany
    {
        return $this->hasMany(ScenarioQuarterDecision::class);
    }

    /** @return HasMany<CourseDecisionEnablement, $this> */
    public function courseEnablements(): HasMany
    {
        return $this->hasMany(CourseDecisionEnablement::class);
    }
}
