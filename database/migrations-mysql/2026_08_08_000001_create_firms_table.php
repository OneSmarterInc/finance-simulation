<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firms', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('course_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('institution_id')->constrained()->restrictOnDelete();
            $table->string('code', 50);
            $table->string('name');
            $table->string('warmup_status', 20)->default('pending');
            $table->timestamp('initialized_at')->nullable();
            $table->timestamp('initialization_failed_at')->nullable();
            $table->json('initialization_error')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['course_id', 'code']);
            $table->index(['course_id', 'warmup_status']);
            $table->index(['institution_id', 'course_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firms');
    }
};
