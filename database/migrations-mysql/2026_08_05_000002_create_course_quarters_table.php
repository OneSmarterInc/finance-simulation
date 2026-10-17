<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_quarters', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('course_id')->constrained()->restrictOnDelete();
            $table->smallInteger('quarter_number');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamp('decision_deadline_at');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['course_id', 'quarter_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_quarters');
    }
};
