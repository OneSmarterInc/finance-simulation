<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live walkthrough L15 (item 53, N6, AI1 a; U11 layer 2, AR1 and AR2):
 * Claude's reading of a firm's quarter, written once after the quarter
 * closes and stored, so everyone sees the same words and nothing is paid
 * for per view. One row per firm per quarter.
 *
 * sections       summary, income_statement, balance_sheet, cash_flow_statement, notes:
 *                each a short paragraph that passed the figure and advice checks
 * dropped        sentences the checks took out, with the reason
 * source_hash    the hash of everything Claude was given, so a reading can be traced
 *                to the exact figures it was written from
 * status         written | failed (retried until attempts reaches 3)
 * courses.ai_reading_faculty    "Show to me", on by default
 * courses.ai_reading_students   "Show to students", off by default
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firm_quarter_readings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('firm_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('course_id')->constrained()->restrictOnDelete();
            $table->uuid('firm_engine_result_id');
            $table->unsignedSmallInteger('quarter_number');
            $table->json('sections')->nullable();
            $table->json('dropped')->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->string('status', 20);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('model', 80)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 10, 4)->default(0);
            $table->string('last_error', 500)->nullable();
            $table->timestamp('written_at')->nullable();
            $table->timestamps();
            $table->unique(['firm_id', 'quarter_number']);
            $table->index(['course_id', 'quarter_number']);
        });
        Schema::table('courses', function (Blueprint $table): void {
            $table->boolean('ai_reading_faculty')->default(true);
            $table->boolean('ai_reading_students')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropColumn(['ai_reading_faculty', 'ai_reading_students']);
        });
        Schema::dropIfExists('firm_quarter_readings');
    }
};
