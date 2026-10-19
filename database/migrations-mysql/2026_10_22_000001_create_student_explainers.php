<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * U4 layer 2 (MO3, decided 19 October 2026): the "New here? See how a
 * quarter works" card on a student's firm dashboard stays until the student
 * watches the explainer or dismisses it, remembered per student per course.
 *
 * explainer        App\Support\Explainers\Explainers key
 * watched_at       when the student reached the explainer's last step
 * dismissed_at     when the student closed the card without watching
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_explainers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('student_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('course_id')->constrained()->cascadeOnDelete();
            $table->string('explainer', 64);
            $table->timestamp('watched_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();
            $table->unique(['student_id', 'course_id', 'explainer']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_explainers');
    }
};
