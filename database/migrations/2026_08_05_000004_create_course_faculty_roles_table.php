<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_faculty_roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('course_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('faculty_id')->constrained('faculty')->restrictOnDelete();
            $table->enum('role', ['owner', 'co_faculty']);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['course_id', 'faculty_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_faculty_roles');
    }
};
