<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * courses.engine_version: which ManufacturingCFEngine version the course runs.
 * Every existing course is version 1 and stays on it to the end (ED11). New
 * courses take config('flexee.engine_version_for_new_courses').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->unsignedSmallInteger('engine_version')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropColumn('engine_version');
        });
    }
};
