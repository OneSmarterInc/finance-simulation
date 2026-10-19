<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * U4: a course's debrief settings and Claude's stored captions.
 *
 * @property list<string>|null $hidden
 * @property array<string, string>|null $captions
 */
class CourseDebrief extends Model
{
    use HasUuids;

    protected $fillable = ['course_id', 'hidden', 'shared', 'captions', 'dropped', 'data_hash', 'model', 'input_tokens', 'output_tokens', 'cost_usd', 'last_error', 'written_at'];

    protected function casts(): array
    {
        return [
            'hidden' => 'array',
            'shared' => 'boolean',
            'captions' => 'array',
            'dropped' => 'array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_usd' => 'float',
            'written_at' => 'datetime',
        ];
    }
}
