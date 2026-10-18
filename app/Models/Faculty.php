<?php

namespace App\Models;

use Database\Factories\FacultyFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Faculty extends Model
{
    /** @use HasFactory<FacultyFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'faculty';

    protected $fillable = [
        'user_id',
        'institution_id',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Institution, $this>
     */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /**
     * @return HasMany<Course, $this>
     */
    public function courses(): HasMany
    {
        return $this->hasMany(Course::class, 'faculty_id');
    }

    /** @return HasMany<CourseFacultyRole, $this> */
    public function courseRoles(): HasMany
    {
        return $this->hasMany(CourseFacultyRole::class);
    }

    /**
     * Courses this faculty member owns or co-teaches, newest first.
     *
     * @return Collection<int, Course>
     */
    public function teachingCourses(): Collection
    {
        return Course::query()->real()
            ->where(function ($query): void {
                $query->where('faculty_id', $this->id)
                    ->orWhereHas('facultyRoles', fn ($roles) => $roles->where('faculty_id', $this->id));
            })
            ->orderByDesc('created_at')
            ->get();
    }
}
