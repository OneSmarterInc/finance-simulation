<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * U9 layer 6 (design note FF6, FF10): practice runs can be removed for good.
 *
 * Two tamper guards stopped that: warm-up results (Quarters 1 to 3) can't be
 * deleted, and the evaluation stage's frozen economics can't be deleted. Both
 * now let a row go when it belongs to a practice run (courses.is_practice).
 * Updates stay refused everywhere, and nothing changes for real courses.
 *
 * The roster guards on firms and memberships need no change: removal deletes
 * the frozen economics first, after which they no longer apply.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION reject_inherited_firm_engine_result_mutation()
            RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' AND EXISTS (SELECT 1 FROM courses c WHERE c.id = OLD.course_id AND c.is_practice) THEN
                    RETURN OLD;
                END IF;
                IF OLD.quarter_number <= 3 THEN
                    RAISE EXCEPTION 'Inherited Q1-Q3 firm engine results are immutable.';
                END IF;
                IF TG_OP = 'UPDATE' THEN
                    RETURN NEW;
                END IF;
                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION reject_cf_plus_auction_economics_mutation() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' AND EXISTS (SELECT 1 FROM courses c WHERE c.id = OLD.course_id AND c.is_practice) THEN
                    RETURN OLD;
                END IF;
                RAISE EXCEPTION 'CF Plus auction economics snapshots are immutable.';
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
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

            CREATE OR REPLACE FUNCTION reject_cf_plus_auction_economics_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'CF Plus auction economics snapshots are immutable.';
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }
};
