<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ask Claude on faculty pages (U1, design note AC5 to AC7).
 *
 * courses.ai_helper_enabled       the course's switch, on by default (AC6)
 * courses.ai_questions_per_month  an admin's raised allowance; null means the site default (AC5)
 * ai_questions                    every question, its lookups, the answer, any dropped sentence,
 *                                 tokens and cost, kept for 90 days (AC7). No foreign keys, so a
 *                                 removed course doesn't take its record with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->boolean('ai_helper_enabled')->default(true);
            $table->unsignedInteger('ai_questions_per_month')->nullable();
        });
        Schema::create('ai_questions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('course_id')->index();
            $table->uuid('faculty_id')->index();
            $table->string('page', 100);
            $table->json('context');
            $table->text('question');
            $table->text('answer')->nullable();
            $table->json('lookups');
            $table->json('dropped')->nullable();
            $table->string('status', 20);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 10, 4)->default(0);
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_questions');
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropColumn(['ai_helper_enabled', 'ai_questions_per_month']);
        });
    }
};
