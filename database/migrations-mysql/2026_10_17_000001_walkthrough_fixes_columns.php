<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Columns for the October 2026 walkthrough PR. Plain column additions only,
 * so the same migration runs on PostgreSQL and MySQL.
 *
 * firm_quarter_decisions.carried_forward  N4: no submission at the deadline,
 *                                         previous quarter's decisions used.
 * firm_quarter_decisions.submitted_by_student_id  item 68: who submitted.
 * firm_accounting_election_requests.justification  N7: the team's reason.
 * firm_accounting_election_requests.faculty_note   item 59: reason given on decline.
 * firm_accounting_election_requests.previous_elections / effective_quarter_number
 *                                         N7: what changed, and from which quarter.
 * enrollments.paid_at / paid_marked_by_user_id     item 45 and N3: paid mark.
 * course_quarters.deadline_extended_at             N5: last deadline extension.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('firm_quarter_decisions', function (Blueprint $table): void {
            $table->boolean('carried_forward')->default(false);
            $table->foreignUuid('submitted_by_student_id')->nullable()->constrained('students')->nullOnDelete();
        });

        Schema::table('firm_accounting_election_requests', function (Blueprint $table): void {
            $table->text('justification')->nullable();
            $table->text('faculty_note')->nullable();
            $table->json('previous_elections')->nullable();
            $table->unsignedSmallInteger('effective_quarter_number')->nullable();
        });

        Schema::table('enrollments', function (Blueprint $table): void {
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_marked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });

        // Everyone already enrolled keeps access; only new enrolments wait for the paid mark.
        DB::table('enrollments')->whereNull('paid_at')->update(['paid_at' => now()]);

        Schema::table('course_quarters', function (Blueprint $table): void {
            $table->timestamp('deadline_extended_at')->nullable();
        });

        // Deadlines were entered as dates and stored at 00:00, the first minute
        // of the day. With automatic close that would close a quarter a day
        // early, so quarters not yet closed move to 23:59 on the same date.
        DB::table('course_quarters')
            ->whereIn('state', ['locked', 'open'])
            ->whereNotNull('decision_deadline_at')
            ->orderBy('id')
            ->get(['id', 'decision_deadline_at'])
            ->each(function ($row): void {
                $deadline = \Carbon\CarbonImmutable::parse($row->decision_deadline_at);
                if ($deadline->format('H:i:s') === '00:00:00') {
                    DB::table('course_quarters')->where('id', $row->id)
                        ->update(['decision_deadline_at' => $deadline->setTime(23, 59)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('course_quarters', function (Blueprint $table): void {
            $table->dropColumn('deadline_extended_at');
        });

        Schema::table('enrollments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('paid_marked_by_user_id');
            $table->dropColumn('paid_at');
        });

        Schema::table('firm_accounting_election_requests', function (Blueprint $table): void {
            $table->dropColumn(['justification', 'faculty_note', 'previous_elections', 'effective_quarter_number']);
        });

        Schema::table('firm_quarter_decisions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('submitted_by_student_id');
            $table->dropColumn('carried_forward');
        });
    }
};
