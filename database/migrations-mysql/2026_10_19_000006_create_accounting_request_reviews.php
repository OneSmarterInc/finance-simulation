<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * U12 (decided 17 October 2026; RV1, 19 October 2026): Claude's review of
 * each accounting-method request, written once after the request is filed
 * and stored. Faculty only; faculty always decide.
 *
 * recommendation           approve | decline | ask (ask the team for more)
 * reasons                  two to four short reasons that passed the figure check
 * reply                    a draft reply to the team, which faculty edit before sending
 * status                   written | failed (retried until attempts reaches 3)
 * courses.ai_request_review   the instructor's switch, on by default
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_request_reviews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('request_id')->unique()->constrained('firm_accounting_election_requests')->restrictOnDelete();
            $table->foreignUuid('course_id')->constrained()->restrictOnDelete();
            $table->string('recommendation', 20)->nullable();
            $table->json('reasons')->nullable();
            $table->text('reply')->nullable();
            $table->json('dropped')->nullable();
            $table->string('status', 20);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('model', 80)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 10, 4)->default(0);
            $table->string('last_error', 500)->nullable();
            $table->timestamp('written_at')->nullable();
            $table->timestamps();
            $table->index('course_id');
        });
        Schema::table('courses', function (Blueprint $table): void {
            $table->boolean('ai_request_review')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropColumn('ai_request_review');
        });
        Schema::dropIfExists('accounting_request_reviews');
    }
};
