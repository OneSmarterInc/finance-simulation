<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FirmAccountingElectionRequest extends Model
{
    use HasUuids;

    protected $fillable = [
        'firm_id',
        'course_id',
        'course_quarter_id',
        'student_id',
        'proposed_elections',
        'justification',
        'faculty_note',
        'previous_elections',
        'effective_quarter_number',
        'status',
        'reviewed_by_faculty_id',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'proposed_elections' => 'array',
            'previous_elections' => 'array',
            'effective_quarter_number' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Firm, $this>
     */
    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    /**
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * @return BelongsTo<CourseQuarter, $this>
     */
    public function courseQuarter(): BelongsTo
    {
        return $this->belongsTo(CourseQuarter::class);
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return BelongsTo<Faculty, $this>
     */
    public function reviewedByFaculty(): BelongsTo
    {
        return $this->belongsTo(
            Faculty::class,
            'reviewed_by_faculty_id'
        );
    }
}