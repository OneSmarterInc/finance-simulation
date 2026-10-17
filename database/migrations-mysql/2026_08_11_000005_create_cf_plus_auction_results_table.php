<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cf_plus_auction_results', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('course_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('target_firm_id')->constrained('firms')->restrictOnDelete();
            $table->foreignUuid('institution_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('normal_winner_firm_id')->nullable()->constrained('firms')->restrictOnDelete();
            $table->foreignUuid('winning_bidder_firm_id')->nullable()->constrained('firms')->restrictOnDelete();
            $table->foreignUuid('winning_offer_id')->nullable()->constrained('cf_plus_offers')->restrictOnDelete();
            $table->decimal('price_paid', 20, 2)->nullable();
            $table->decimal('stake', 5, 4)->default(0.51);
            $table->decimal('full_consultant_value', 20, 2);
            $table->decimal('consultant_stake_value', 20, 2);
            $table->decimal('raw_full_true_worth', 20, 2);
            $table->decimal('displayed_full_true_worth', 20, 2);
            $table->decimal('true_stake_worth', 20, 2);
            $table->decimal('winning_fundable_cap', 20, 2)->nullable();
            $table->json('tied_top_bidder_ids');
            $table->boolean('faculty_override')->default(false);
            $table->foreignUuid('override_faculty_id')->nullable()->constrained('faculty')->restrictOnDelete();
            $table->text('override_reason')->nullable();
            $table->timestamp('overridden_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['course_id', 'target_firm_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cf_plus_auction_results');
    }
};
