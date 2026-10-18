<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * courses.starting_elections (engine design note, ED9): the accounting methods
 * every firm in the course starts with, chosen by faculty in the wizard. The
 * warm-up uses them, and firms keep them until a change is approved. Null
 * means the standard starting methods.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->json('starting_elections')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropColumn('starting_elections');
        });
    }
};
