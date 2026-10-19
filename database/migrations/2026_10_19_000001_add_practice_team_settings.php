<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * U9 layer 5 (design note FF3, FF7, FF8): the reference teams' settings.
 *
 * Kept on the course being rehearsed, so starting again keeps them, and
 * copied onto each practice run, so a run shows the settings it was played
 * with. Null means all three teams play their standard rules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->json('practice_team_settings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropColumn('practice_team_settings');
        });
    }
};
