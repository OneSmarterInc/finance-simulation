<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('firms', function (Blueprint $table): void {
            $table->json('accounting_elections')->nullable();
            $table->timestamp('accounting_elections_locked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('firms', fn (Blueprint $table) => $table->dropColumn(['accounting_elections', 'accounting_elections_locked_at']));
    }
};
