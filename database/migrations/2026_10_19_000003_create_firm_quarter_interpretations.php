<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * U11 (decided 17 October 2026; IN1 to IN3, 19 October 2026): each quarter,
 * the team writes its explanation of its own statements before it submits
 * the next quarter's decisions. One row per firm per explained quarter.
 *
 * answers                  what changed, why, what the notes show
 * state                    draft | submitted
 * faculty_comment          the instructor's comment, which the team sees
 * courses.interpretations_required   on for courses created from now on (the wizard
 *                                    sets it); off for running courses, so no class
 *                                    meets a new requirement mid-term. Faculty switch it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firm_quarter_interpretations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('firm_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('course_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('course_quarter_id')->constrained()->restrictOnDelete();
            $table->json('answers');
            $table->string('state', 20)->default('draft');
            $table->foreignUuid('submitted_by_student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->text('faculty_comment')->nullable();
            $table->uuid('commented_by_faculty_id')->nullable();
            $table->timestamp('commented_at')->nullable();
            $table->timestamps();
            $table->unique(['firm_id', 'course_quarter_id']);
            $table->index(['course_id', 'course_quarter_id']);
        });
        Schema::table('courses', function (Blueprint $table): void {
            $table->boolean('interpretations_required')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropColumn('interpretations_required');
        });
        Schema::dropIfExists('firm_quarter_interpretations');
    }
};
