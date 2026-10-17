<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cf_plus_disclosure_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('course_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('target_firm_id')->constrained('firms')->restrictOnDelete();
            $table->foreignUuid('institution_id')->constrained()->restrictOnDelete();
            $table->smallInteger('opened_at_quarter');
            $table->json('source_result_ids');
            $table->json('published_statements');
            $table->json('notes_and_disclosures');
            $table->json('accounting_elections');
            $table->string('payload_hash', 64);
            $table->timestamp('disclosed_at');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['course_id', 'target_firm_id']);
            $table->index(['institution_id', 'course_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cf_plus_disclosure_snapshots');
    }
};
