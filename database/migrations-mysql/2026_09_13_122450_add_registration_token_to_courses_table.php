<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->string('registration_token')->nullable()->unique();
            $table->boolean('registration_enabled')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropUnique(['registration_token']);
            $table->dropColumn([
                'registration_token',
                'registration_enabled',
            ]);
        });
    }
};