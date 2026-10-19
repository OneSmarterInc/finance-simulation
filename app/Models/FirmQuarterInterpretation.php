<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * U11: a team's explanation of one quarter's statements.
 *
 * @property array{changed?: string, why?: string, notes?: string} $answers
 */
class FirmQuarterInterpretation extends Model
{
    use HasUuids;

    public const DRAFT = 'draft';

    public const SUBMITTED = 'submitted';

    /** The three prompts (IN2), in the order the screen asks them. */
    public const PROMPTS = ['changed', 'why', 'notes'];

    protected $fillable = ['firm_id', 'course_id', 'course_quarter_id', 'answers', 'state', 'submitted_by_student_id', 'submitted_at', 'faculty_comment', 'commented_by_faculty_id', 'commented_at'];

    protected function casts(): array
    {
        return ['answers' => 'array', 'submitted_at' => 'datetime', 'commented_at' => 'datetime'];
    }

    /** @return BelongsTo<Firm, $this> */
    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    /** @return BelongsTo<CourseQuarter, $this> */
    public function courseQuarter(): BelongsTo
    {
        return $this->belongsTo(CourseQuarter::class);
    }

    /** @return BelongsTo<Student, $this> */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'submitted_by_student_id');
    }

    public function isSubmitted(): bool
    {
        return $this->state === self::SUBMITTED;
    }
}
