<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_decision_enablements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('course_quarter_id') 
                ->constrained('course_quarters', 'id', 'cde_course_quarter_fk') 
                ->restrictOnDelete();

            $table->foreignUuid('scenario_decision_category_id')
                ->constrained('scenario_decision_categories', 'id', 'cde_decision_category_fk') 
                ->restrictOnDelete();
                
            $table->boolean('enabled')->default(false);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['course_quarter_id', 'scenario_decision_category_id'], 'course_quarter_category_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_decision_enablements');
    }
};
