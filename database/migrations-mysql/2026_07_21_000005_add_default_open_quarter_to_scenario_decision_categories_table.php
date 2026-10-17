<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scenario_decision_categories', function (Blueprint $table) {
            $table->smallInteger('default_open_quarter')->nullable()->after('input_fields');
        });
    }

    public function down(): void
    {
        Schema::table('scenario_decision_categories', function (Blueprint $table) {
            $table->dropColumn('default_open_quarter');
        });
    }
};
