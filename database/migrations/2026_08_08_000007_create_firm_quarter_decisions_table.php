<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firm_quarter_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('firm_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('course_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('course_quarter_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('institution_id')->constrained()->restrictOnDelete();
            $table->json('draft_payload')->default('{}');
            $table->json('submitted_payload')->nullable();
            $table->json('resolved_payload')->nullable();
            $table->decimal('dividend', 18, 2)->default(0);
            $table->string('state', 20)->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();
            $table->unique(['firm_id', 'course_quarter_id']);
            $table->index(['course_id', 'course_quarter_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firm_quarter_decisions');
    }
};
