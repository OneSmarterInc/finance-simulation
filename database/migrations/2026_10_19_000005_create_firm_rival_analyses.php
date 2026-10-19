<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * U11 layer 3 (RA1 to RA3, decided 19 October 2026): on + courses, a team
 * writes an analysis of a rival's statements before it can save a positive
 * offer for that rival. One row per bidding firm per rival.
 *
 * answers                  what matters most, what the consultant's number misses,
 *                          what 51% is worth to the team and why
 * estimated_stake_value    the team's own figure for 51% of the rival
 * state                    draft | submitted
 * faculty_comment          written after settlement only; the team sees it
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firm_rival_analyses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('course_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('bidder_firm_id')->constrained('firms')->restrictOnDelete();
            $table->foreignUuid('target_firm_id')->constrained('firms')->restrictOnDelete();
            $table->json('answers');
            $table->decimal('estimated_stake_value', 18, 2)->nullable();
            $table->string('state', 20)->default('draft');
            $table->foreignUuid('submitted_by_student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->text('faculty_comment')->nullable();
            $table->uuid('commented_by_faculty_id')->nullable();
            $table->timestamp('commented_at')->nullable();
            $table->timestamps();
            $table->unique(['bidder_firm_id', 'target_firm_id']);
            $table->index('course_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firm_rival_analyses');
    }
};
