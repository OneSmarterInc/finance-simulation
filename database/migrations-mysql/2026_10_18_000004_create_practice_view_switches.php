<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * U9 view-as (design note FF5): every time a faculty member switches to view a
 * practice run as one of its reference teams, or back, a row is written here.
 * No foreign keys, so the record outlives the practice run it describes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('practice_view_switches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('faculty_id')->index();
            $table->uuid('course_id')->index();
            $table->uuid('firm_id')->nullable();
            $table->string('team', 20)->nullable();
            $table->string('action', 20);
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('practice_view_switches');
    }
};
