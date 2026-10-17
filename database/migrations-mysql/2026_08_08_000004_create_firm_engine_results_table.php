<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firm_engine_results', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('firm_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('course_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('institution_id')->constrained()->restrictOnDelete();
            $table->smallInteger('quarter_number');
            $table->json('fields');
            $table->json('income_statement');
            $table->json('balance_sheet');
            $table->json('cash_flow_statement');
            $table->json('notes');
            $table->json('intermediates');
            $table->string('engine_contract', 100);
            $table->string('provenance', 50);
            $table->boolean('immutable')->default(true);
            $table->string('payload_hash', 64);
            $table->timestamp('validated_at');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['firm_id', 'quarter_number']);
            $table->index(['course_id', 'quarter_number']);
            $table->index(['institution_id', 'course_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firm_engine_results');
    }
};
