<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('
                DROP TRIGGER IF EXISTS protect_inherited_firm_engine_results_update
            ');

            DB::unprepared('
                DROP TRIGGER IF EXISTS protect_inherited_firm_engine_results_delete
            ');

            DB::unprepared("
                CREATE TRIGGER protect_inherited_firm_engine_results_update
                BEFORE UPDATE ON firm_engine_results
                FOR EACH ROW
                BEGIN
                    IF OLD.quarter_number <= 3 THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Inherited Q1-Q3 firm engine results are immutable.';
                    END IF;
                END
            ");

            DB::unprepared("
                CREATE TRIGGER protect_inherited_firm_engine_results_delete
                BEFORE DELETE ON firm_engine_results
                FOR EACH ROW
                BEGIN
                    IF OLD.quarter_number <= 3 THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Inherited Q1-Q3 firm engine results are immutable.';
                    END IF;
                END
            ");

            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION reject_inherited_firm_engine_result_mutation()
                RETURNS trigger AS $$
                BEGIN
                    IF OLD.quarter_number <= 3 THEN
                        RAISE EXCEPTION 'Inherited Q1-Q3 firm engine results are immutable.';
                    END IF;

                    IF TG_OP = 'UPDATE' THEN
                        RETURN NEW;
                    END IF;

                    RETURN OLD;
                END;
                $$ LANGUAGE plpgsql;

                DROP TRIGGER IF EXISTS protect_inherited_firm_engine_results
                ON firm_engine_results;

                CREATE TRIGGER protect_inherited_firm_engine_results
                BEFORE UPDATE OR DELETE ON firm_engine_results
                FOR EACH ROW
                EXECUTE FUNCTION reject_inherited_firm_engine_result_mutation();
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared(
                'DROP TRIGGER IF EXISTS protect_inherited_firm_engine_results_update'
            );

            DB::unprepared(
                'DROP TRIGGER IF EXISTS protect_inherited_firm_engine_results_delete'
            );

            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(
                'DROP TRIGGER IF EXISTS protect_inherited_firm_engine_results
                 ON firm_engine_results'
            );

            DB::unprepared(
                'DROP FUNCTION IF EXISTS reject_inherited_firm_engine_result_mutation()'
            );
        }
    }
};