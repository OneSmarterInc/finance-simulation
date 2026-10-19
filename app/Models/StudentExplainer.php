<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * U4 layer 2 (MO3): whether a student has watched or dismissed an explainer
 * in a course, so its dashboard card shows only until then.
 *
 * @property string $student_id
 * @property string $course_id
 * @property string $explainer
 * @property Carbon|null $watched_at
 * @property Carbon|null $dismissed_at
 */
class StudentExplainer extends Model
{
    use HasUuids;

    protected $fillable = ['student_id', 'course_id', 'explainer', 'watched_at', 'dismissed_at'];

    protected function casts(): array
    {
        return ['watched_at' => 'datetime', 'dismissed_at' => 'datetime'];
    }

    public static function settled(string $studentId, string $courseId, string $explainer): bool
    {
        return self::query()->where(compact('explainer') + ['student_id' => $studentId, 'course_id' => $courseId])
            ->where(fn ($q) => $q->whereNotNull('watched_at')->orWhereNotNull('dismissed_at'))->exists();
    }

    public static function mark(string $studentId, string $courseId, string $explainer, string $column): void
    {
        $row = self::query()->firstOrCreate(['student_id' => $studentId, 'course_id' => $courseId, 'explainer' => $explainer]);
        if ($row->{$column} === null) {
            $row->forceFill([$column => now()])->save();
        }
    }
}
