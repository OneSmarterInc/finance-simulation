<?php

namespace App\Models;

use Database\Factories\FirmMembershipFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FirmMembership extends Model
{
    /** @use HasFactory<FirmMembershipFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'firm_id', 'course_id', 'student_id', 'institution_id',
        'assigned_by_faculty_id', 'assigned_at',
    ];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime'];
    }

    /** @return BelongsTo<Firm, $this> */
    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<Faculty, $this> */
    public function assignedByFaculty(): BelongsTo
    {
        return $this->belongsTo(Faculty::class, 'assigned_by_faculty_id');
    }
}
