<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_quarters', function (Blueprint $table): void {
            $table->string('state', 20)->default('locked')->after('decision_deadline_at');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('close_started_at')->nullable();
            $table->timestamp('closed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('course_quarters', fn (Blueprint $table) => $table->dropColumn(['state', 'opened_at', 'close_started_at', 'closed_at']));
    }
};
