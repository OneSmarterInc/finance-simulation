<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cf_plus_auction_economics_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('course_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignUuid('institution_id')->constrained()->restrictOnDelete();
            $table->smallInteger('evaluation_start_quarter');
            $table->timestamp('valuation_date');
            $table->json('firm_ids');
            $table->json('student_ids');
            $table->json('student_assignments');
            $table->string('source_universe_hash', 64);
            $table->timestamps();
        });

        Schema::create('cf_plus_auction_economics', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('snapshot_id')->constrained('cf_plus_auction_economics_snapshots')->restrictOnDelete();
            $table->foreignUuid('course_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('firm_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('institution_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('disclosure_snapshot_id')->constrained('cf_plus_disclosure_snapshots')->restrictOnDelete();
            $table->foreignUuid('boundary_result_id')->constrained('firm_engine_results')->restrictOnDelete();
            $table->string('boundary_result_hash', 64);
            $table->json('source_result_ids');
            $table->json('source_result_hashes');
            $table->decimal('normalized_quarterly_cash', 20, 2);
            $table->decimal('normalized_annual_cash', 20, 2);
            $table->decimal('full_consultant_value', 20, 2);
            $table->decimal('consultant_stake_value', 20, 2);
            $table->decimal('raw_full_true_worth', 20, 2);
            $table->decimal('raw_true_stake_worth', 20, 2);
            $table->decimal('displayed_full_true_worth', 20, 2);
            $table->decimal('displayed_true_stake_worth', 20, 2);
            $table->string('going_concern_status', 80);
            $table->decimal('target_acquisition_financing', 20, 2);
            $table->decimal('bidder_cash', 20, 2);
            $table->decimal('committed_credit_limit', 20, 2);
            $table->decimal('committed_credit_balance', 20, 2);
            $table->decimal('unused_committed_credit', 20, 2);
            $table->decimal('bidder_own_funds', 20, 2);
            $table->timestamps();
            $table->unique(['course_id', 'firm_id']);
            $table->index(['snapshot_id', 'firm_id']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('
        DROP TRIGGER IF EXISTS cf_plus_auction_snapshot_immutable_update
    ');

            DB::unprepared('
        DROP TRIGGER IF EXISTS cf_plus_auction_snapshot_immutable_delete
    ');

            DB::unprepared('
        DROP TRIGGER IF EXISTS cf_plus_auction_economics_immutable_update
    ');

            DB::unprepared('
        DROP TRIGGER IF EXISTS cf_plus_auction_economics_immutable_delete
    ');

            DB::unprepared("
        CREATE TRIGGER cf_plus_auction_snapshot_immutable_update
        BEFORE UPDATE ON cf_plus_auction_economics_snapshots
        FOR EACH ROW
        BEGIN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'CF Plus auction economics snapshots are immutable.';
        END
    ");

            DB::unprepared("
        CREATE TRIGGER cf_plus_auction_snapshot_immutable_delete
        BEFORE DELETE ON cf_plus_auction_economics_snapshots
        FOR EACH ROW
        BEGIN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'CF Plus auction economics snapshots are immutable.';
        END
    ");

            DB::unprepared("
        CREATE TRIGGER cf_plus_auction_economics_immutable_update
        BEFORE UPDATE ON cf_plus_auction_economics
        FOR EACH ROW
        BEGIN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'CF Plus auction economics snapshots are immutable.';
        END
    ");

            DB::unprepared("
        CREATE TRIGGER cf_plus_auction_economics_immutable_delete
        BEFORE DELETE ON cf_plus_auction_economics
        FOR EACH ROW
        BEGIN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'CF Plus auction economics snapshots are immutable.';
        END
    ");
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION reject_cf_plus_auction_economics_mutation() RETURNS trigger AS $$
        BEGIN
            RAISE EXCEPTION 'CF Plus auction economics snapshots are immutable.';
        END;
        $$ LANGUAGE plpgsql;

        CREATE TRIGGER cf_plus_auction_snapshot_immutable
        BEFORE UPDATE OR DELETE ON cf_plus_auction_economics_snapshots
        FOR EACH ROW
        EXECUTE FUNCTION reject_cf_plus_auction_economics_mutation();

        CREATE TRIGGER cf_plus_auction_economics_immutable
        BEFORE UPDATE OR DELETE ON cf_plus_auction_economics
        FOR EACH ROW
        EXECUTE FUNCTION reject_cf_plus_auction_economics_mutation();
    SQL);
        }
    }

    public function down(): void
    {

        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('
        DROP TRIGGER IF EXISTS cf_plus_auction_economics_immutable_update
    ');

            DB::unprepared('
        DROP TRIGGER IF EXISTS cf_plus_auction_economics_immutable_delete
    ');

            DB::unprepared('
        DROP TRIGGER IF EXISTS cf_plus_auction_snapshot_immutable_update
    ');

            DB::unprepared('
        DROP TRIGGER IF EXISTS cf_plus_auction_snapshot_immutable_delete
    ');
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared('
        DROP TRIGGER IF EXISTS cf_plus_auction_economics_immutable
        ON cf_plus_auction_economics
    ');

            DB::unprepared('
        DROP TRIGGER IF EXISTS cf_plus_auction_snapshot_immutable
        ON cf_plus_auction_economics_snapshots
    ');

            DB::unprepared('
        DROP FUNCTION IF EXISTS reject_cf_plus_auction_economics_mutation()
    ');
        }

        Schema::dropIfExists('cf_plus_auction_economics');
        Schema::dropIfExists('cf_plus_auction_economics_snapshots');
    }
};
