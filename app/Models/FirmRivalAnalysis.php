<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * U11 layer 3: a team's written analysis of a rival, before it bids on it (+ courses).
 *
 * @property array{matters?: string, misses?: string, worth?: string} $answers
 */
class FirmRivalAnalysis extends Model
{
    use HasUuids;

    public const DRAFT = 'draft';

    public const SUBMITTED = 'submitted';

    /** The three prompts (RA2), in the order the screen asks them. */
    public const PROMPTS = [
        'matters' => 'What matters most?',
        'misses' => 'What does the consultant\'s number miss?',
        'worth' => 'What is 51% worth to you, and why?',
    ];

    protected $fillable = ['course_id', 'bidder_firm_id', 'target_firm_id', 'answers', 'estimated_stake_value', 'state', 'submitted_by_student_id', 'submitted_at', 'faculty_comment', 'commented_by_faculty_id', 'commented_at'];

    protected function casts(): array
    {
        return ['answers' => 'array', 'estimated_stake_value' => 'float', 'submitted_at' => 'datetime', 'commented_at' => 'datetime'];
    }

    /** @return BelongsTo<Firm, $this> */
    public function bidder(): BelongsTo
    {
        return $this->belongsTo(Firm::class, 'bidder_firm_id');
    }

    /** @return BelongsTo<Firm, $this> */
    public function target(): BelongsTo
    {
        return $this->belongsTo(Firm::class, 'target_firm_id');
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
