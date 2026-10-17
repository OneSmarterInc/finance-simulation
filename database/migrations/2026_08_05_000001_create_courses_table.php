<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('faculty_id')->constrained('faculty')->restrictOnDelete();
            $table->foreignUuid('scenario_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('payment_mode')->nullable();
            $table->boolean('cf_plus_enabled')->default(false);
            $table->smallInteger('build_quarters')->nullable();
            $table->smallInteger('adversarial_quarters')->nullable();
            $table->smallInteger('post_bid_quarters')->nullable();
            $table->enum('status', ['draft', 'deployed'])->default('draft');
            $table->timestamp('deployed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['institution_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
