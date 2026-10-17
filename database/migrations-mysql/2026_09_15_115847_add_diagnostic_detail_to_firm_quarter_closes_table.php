<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('firm_quarter_closes', function (Blueprint $table): void {
            $table->json('diagnostic_detail')->nullable()->after('safe_error');
        });
    }

    public function down(): void
    {
        Schema::table('firm_quarter_closes', function (Blueprint $table): void {
            $table->dropColumn('diagnostic_detail');
        });
    }
};