<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CourseDecisionEnablement extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = ['course_quarter_id', 'scenario_decision_category_id', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    /** @return BelongsTo<CourseQuarter, $this> */
    public function quarter(): BelongsTo
    {
        return $this->belongsTo(CourseQuarter::class, 'course_quarter_id');
    }

    /** @return BelongsTo<ScenarioDecisionCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ScenarioDecisionCategory::class, 'scenario_decision_category_id');
    }
}
