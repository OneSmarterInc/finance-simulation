<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cf_plus_offers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('course_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('bidder_firm_id')->constrained('firms')->restrictOnDelete();
            $table->foreignUuid('target_firm_id')->constrained('firms')->restrictOnDelete();
            $table->foreignUuid('institution_id')->constrained()->restrictOnDelete();
            $table->decimal('offered_amount', 20, 2);
            $table->decimal('stake', 5, 4)->default(0.51);
            $table->decimal('fundable_cap', 20, 2);
            $table->decimal('bidder_cash', 20, 2);
            $table->decimal('unused_committed_credit', 20, 2);
            $table->decimal('target_acquisition_financing', 20, 2);
            $table->timestamp('submitted_at');
            $table->timestamp('sealed_at');
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['bidder_firm_id', 'target_firm_id']);
            $table->index(['course_id', 'target_firm_id', 'offered_amount']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cf_plus_offers');
    }
};
