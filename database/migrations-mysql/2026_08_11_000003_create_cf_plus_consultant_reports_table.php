<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cf_plus_consultant_reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('course_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('target_firm_id')->constrained('firms')->restrictOnDelete();
            $table->foreignUuid('disclosure_snapshot_id')->constrained('cf_plus_disclosure_snapshots')->restrictOnDelete();
            $table->foreignUuid('institution_id')->constrained()->restrictOnDelete();
            $table->json('reported_quarters');
            $table->decimal('median_reported_base', 20, 2);
            $table->decimal('observed_growth', 12, 8);
            $table->decimal('growth_cap_candidate', 12, 8);
            $table->decimal('required_return_candidate', 12, 8);
            $table->string('terminal_method', 40);
            $table->decimal('terminal_growth_candidate', 12, 8);
            $table->smallInteger('projected_periods');
            $table->json('valuation_detail');
            $table->decimal('consultant_value', 20, 2);
            $table->json('template_sections');
            $table->timestamp('generated_at');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['course_id', 'target_firm_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cf_plus_consultant_reports');
    }
};
