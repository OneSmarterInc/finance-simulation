<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firm_accounting_elections', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('firm_id')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignUuid('course_id')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignUuid('course_quarter_id')
                ->constrained()
                ->restrictOnDelete();

            $table->json('elections');

            $table->timestamps();

            $table->unique([
                'firm_id',
                'course_quarter_id',
            ]);

            $table->index([
                'course_id',
                'course_quarter_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firm_accounting_elections');
    }
};