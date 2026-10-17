<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FirmAccountingElection extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'firm_id',
        'course_id',
        'course_quarter_id',
        'elections',
    ];

    protected function casts(): array
    {
        return [
            'elections' => 'array',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function courseQuarter(): BelongsTo
    {
        return $this->belongsTo(CourseQuarter::class);
    }
}