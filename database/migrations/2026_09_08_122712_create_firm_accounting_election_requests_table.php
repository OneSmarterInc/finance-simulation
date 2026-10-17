<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firm_accounting_election_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('firm_id')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignUuid('course_id')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignUuid('course_quarter_id')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignUuid('student_id')
                ->constrained('students')
                ->restrictOnDelete();

            $table->json('proposed_elections');

            $table->string('status', 20)->default('pending');

            $table->foreignUuid('reviewed_by_faculty_id')
                ->nullable()
                ->constrained('faculty')
                ->restrictOnDelete();

            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            /*
             * Only one active accounting-election request is allowed
             * for a firm and Quarter 4.
             *
             * The application will prevent another request while the
             * existing request is pending.
             */
            $table->index([
                'firm_id',
                'course_quarter_id',
                'status',
            ]);

            $table->index([
                'course_id',
                'course_quarter_id',
                'status',
            ]);

            $table->index([
                'student_id',
                'course_quarter_id',
                'status',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firm_accounting_election_requests');
    }
};