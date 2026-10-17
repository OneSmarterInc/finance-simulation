<?php

namespace App\Models;

use Database\Factories\ScenarioFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Scenario extends Model
{
    /** @use HasFactory<ScenarioFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'narrative_text',
        'version',
        'engine_class_name',
        'active',
        'opening_position',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'opening_position' => 'array',
        ];
    }

    /**
     * @return HasMany<ScenarioDecisionCategory, $this>
     */
    public function decisionCategories(): HasMany
    {
        return $this->hasMany(ScenarioDecisionCategory::class);
    }

    /**
     * @return HasMany<ScenarioParameter, $this>
     */
    public function parameters(): HasMany
    {
        return $this->hasMany(ScenarioParameter::class);
    }

    /**
     * @return HasMany<ScenarioQuarterDecision, $this>
     */
    public function quarterDecisions(): HasMany
    {
        return $this->hasMany(ScenarioQuarterDecision::class);
    }

    /** @return HasMany<Course, $this> */
    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }
}
