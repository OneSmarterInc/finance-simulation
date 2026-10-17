<?php

namespace App\Models;

use Database\Factories\ScenarioParameterFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $parameter_key
 * @property array{value: mixed} $parameter_value
 */
class ScenarioParameter extends Model
{
    /** @use HasFactory<ScenarioParameterFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'scenario_id',
        'parameter_key',
        'parameter_value',
        'data_type',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'parameter_value' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Scenario, $this>
     */
    public function scenario(): BelongsTo
    {
        return $this->belongsTo(Scenario::class);
    }
}
