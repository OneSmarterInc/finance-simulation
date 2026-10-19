<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** U3: a call to a reporting tool. The rows it returned are never stored. */
class ReportingCall extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['connection_id', 'tool', 'status', 'rows', 'ms'];

    protected function casts(): array
    {
        return ['rows' => 'integer', 'ms' => 'integer', 'created_at' => 'datetime'];
    }
}
