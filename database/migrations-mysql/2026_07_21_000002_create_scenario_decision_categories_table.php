<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scenario_decision_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('scenario_id')->constrained('scenarios')->restrictOnDelete();
            $table->string('code');
            $table->string('display_name');
            $table->smallInteger('sort_order');
            $table->jsonb('input_fields');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['scenario_id', 'code']);
            $table->unique(['scenario_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scenario_decision_categories');
    }
};
