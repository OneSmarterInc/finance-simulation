<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scenario_quarter_decisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('scenario_id')->constrained('scenarios')->restrictOnDelete();
            $table->foreignUuid('scenario_decision_category_id')->constrained('scenario_decision_categories')->restrictOnDelete();
            $table->smallInteger('quarter_number');
            $table->jsonb('payload');
            $table->jsonb('null_fields')->default('[]');
            $table->string('source');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['scenario_id', 'quarter_number', 'scenario_decision_category_id'], 'scenario_quarter_decisions_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scenario_quarter_decisions');
    }
};
