<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firm_quarter_closes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('firm_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('course_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('course_quarter_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('firm_quarter_decision_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('pending');
            $table->json('safe_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['firm_id', 'course_quarter_id']);
            $table->index(['course_id', 'course_quarter_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firm_quarter_closes');
    }
};
