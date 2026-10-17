<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Enrollment extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = ['course_id', 'student_id', 'institution_id', 'paid_at', 'paid_marked_by_user_id'];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime'];
    }

    /** N3: students sign in before payment, but the firm pages open once they are marked paid. */
    public function isPaid(): bool
    {
        return $this->paid_at !== null;
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

    /** @return BelongsTo<Institution, $this> */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }
}
