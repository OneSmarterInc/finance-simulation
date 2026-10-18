<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One switch into or out of viewing a practice run as a reference team (U9, FF5). */
class PracticeViewSwitch extends Model
{
    use HasUuids;

    public const VIEW_AS = 'view_as';

    public const BACK = 'back';

    public const UPDATED_AT = null;

    protected $fillable = ['faculty_id', 'course_id', 'firm_id', 'team', 'action', 'ip_address'];
}
