<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * U9 practice runs (design note FF1, FF6). A practice run is a private copy of
 * a faculty member's course, played by three reference teams. It is kept out
 * of class lists, reports, the scheduler and student counts.
 *
 * courses.is_practice                 the course is a practice run
 * courses.practice_source_course_id   the course it rehearses
 * courses.practice_last_played_at     for tidying up after 30 days
 * students.is_practice                a reference team's practice student
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->boolean('is_practice')->default(false)->index();
            $table->uuid('practice_source_course_id')->nullable()->index();
            $table->timestamp('practice_last_played_at')->nullable();
        });
        Schema::table('students', function (Blueprint $table): void {
            $table->boolean('is_practice')->default(false)->index();
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropColumn(['is_practice', 'practice_source_course_id', 'practice_last_played_at']);
        });
        Schema::table('students', function (Blueprint $table): void {
            $table->dropColumn('is_practice');
        });
    }
};
