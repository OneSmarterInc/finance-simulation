<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CfPlusConsultantReport extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = ['course_id', 'target_firm_id', 'disclosure_snapshot_id', 'institution_id', 'reported_quarters', 'median_reported_base', 'observed_growth', 'growth_cap_candidate', 'required_return_candidate', 'terminal_method', 'terminal_growth_candidate', 'projected_periods', 'valuation_detail', 'consultant_value', 'template_sections', 'generated_at'];

    protected function casts(): array
    {
        return ['reported_quarters' => 'array', 'median_reported_base' => 'decimal:2', 'observed_growth' => 'float', 'growth_cap_candidate' => 'float', 'required_return_candidate' => 'float', 'terminal_growth_candidate' => 'float', 'projected_periods' => 'integer', 'valuation_detail' => 'array', 'consultant_value' => 'decimal:2', 'template_sections' => 'array', 'generated_at' => 'datetime'];
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<Firm, $this> */
    public function targetFirm(): BelongsTo
    {
        return $this->belongsTo(Firm::class, 'target_firm_id');
    }

    /** @return BelongsTo<CfPlusDisclosureSnapshot, $this> */
    public function disclosureSnapshot(): BelongsTo
    {
        return $this->belongsTo(CfPlusDisclosureSnapshot::class, 'disclosure_snapshot_id');
    }
}
