<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scenario_parameters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('scenario_id')->constrained('scenarios')->restrictOnDelete();
            $table->string('parameter_key');
            $table->jsonb('parameter_value');
            $table->string('data_type');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['scenario_id', 'parameter_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scenario_parameters');
    }
};
