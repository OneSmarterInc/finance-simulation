<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * U4 layer 1 (DB1 to DB4, decided 19 October 2026): one debrief per course.
 *
 * hidden           slide keys the instructor has hidden
 * shared           "Share with students", only once the course has finished
 * captions         slide key => Claude's sentence, written from the figures with hash data_hash
 * dropped          sentences the figure and advice checks took out
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_debriefs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('course_id')->unique()->constrained()->restrictOnDelete();
            $table->json('hidden')->nullable();
            $table->boolean('shared')->default(false);
            $table->json('captions')->nullable();
            $table->json('dropped')->nullable();
            $table->string('data_hash', 64)->nullable();
            $table->string('model', 80)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 10, 4)->default(0);
            $table->string('last_error', 500)->nullable();
            $table->timestamp('written_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_debriefs');
    }
};
