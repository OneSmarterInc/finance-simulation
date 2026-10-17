<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {

            // ---------------------------------------------------------
            // firm_memberships - UPDATE
            // ---------------------------------------------------------
            DB::unprepared('DROP TRIGGER IF EXISTS phase9_frozen_firm_membership_update');

            DB::unprepared("
                CREATE TRIGGER phase9_frozen_firm_membership_update
                BEFORE UPDATE ON firm_memberships
                FOR EACH ROW
                BEGIN
                    IF EXISTS (
                        SELECT 1
                        FROM cf_plus_auction_economics_snapshots snapshots
                        WHERE snapshots.course_id = OLD.course_id
                          AND JSON_CONTAINS(
                              snapshots.firm_ids,
                              JSON_QUOTE(CAST(OLD.firm_id AS CHAR)),
                              '$'
                          ) = 1
                    ) AND (
                        NOT (NEW.course_id <=> OLD.course_id)
                        OR NOT (NEW.firm_id <=> OLD.firm_id)
                        OR NOT (NEW.student_id <=> OLD.student_id)
                        OR NOT (NEW.deleted_at <=> OLD.deleted_at)
                    ) THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT =
                            'Firm membership is immutable after the CF Plus auction snapshot opens.';
                    END IF;
                END
            ");

            // ---------------------------------------------------------
            // firm_memberships - DELETE
            // ---------------------------------------------------------
            DB::unprepared('DROP TRIGGER IF EXISTS phase9_frozen_firm_membership_delete');

            DB::unprepared("
                CREATE TRIGGER phase9_frozen_firm_membership_delete
                BEFORE DELETE ON firm_memberships
                FOR EACH ROW
                BEGIN
                    IF EXISTS (
                        SELECT 1
                        FROM cf_plus_auction_economics_snapshots snapshots
                        WHERE snapshots.course_id = OLD.course_id
                          AND JSON_CONTAINS(
                              snapshots.firm_ids,
                              JSON_QUOTE(CAST(OLD.firm_id AS CHAR)),
                              '$'
                          ) = 1
                    ) THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT =
                            'Firm membership is immutable after the CF Plus auction snapshot opens.';
                    END IF;
                END
            ");

            // ---------------------------------------------------------
            // firms - INSERT
            // ---------------------------------------------------------
            DB::unprepared('DROP TRIGGER IF EXISTS phase9_frozen_firm_universe_insert');

            DB::unprepared("
                CREATE TRIGGER phase9_frozen_firm_universe_insert
                BEFORE INSERT ON firms
                FOR EACH ROW
                BEGIN
                    IF EXISTS (
                        SELECT 1
                        FROM cf_plus_auction_economics_snapshots snapshots
                        WHERE snapshots.course_id = NEW.course_id
                    ) THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT =
                            'New firms cannot enter a frozen CF Plus auction universe.';
                    END IF;
                END
            ");

            // ---------------------------------------------------------
            // firms - UPDATE
            // ---------------------------------------------------------
            DB::unprepared('DROP TRIGGER IF EXISTS phase9_frozen_firm_universe_update');

            DB::unprepared("
                CREATE TRIGGER phase9_frozen_firm_universe_update
                BEFORE UPDATE ON firms
                FOR EACH ROW
                BEGIN
                    IF EXISTS (
                        SELECT 1
                        FROM cf_plus_auction_economics_snapshots snapshots
                        WHERE snapshots.course_id = OLD.course_id
                          AND JSON_CONTAINS(
                              snapshots.firm_ids,
                              JSON_QUOTE(CAST(OLD.id AS CHAR)),
                              '$'
                          ) = 1
                    ) AND (
                        NOT (NEW.course_id <=> OLD.course_id)
                        OR NOT (NEW.deleted_at <=> OLD.deleted_at)
                    ) THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT =
                            'Firm identity is immutable after the CF Plus auction snapshot opens.';
                    END IF;
                END
            ");

            // ---------------------------------------------------------
            // firms - DELETE
            // ---------------------------------------------------------
            DB::unprepared('DROP TRIGGER IF EXISTS phase9_frozen_firm_universe_delete');

            DB::unprepared("
                CREATE TRIGGER phase9_frozen_firm_universe_delete
                BEFORE DELETE ON firms
                FOR EACH ROW
                BEGIN
                    IF EXISTS (
                        SELECT 1
                        FROM cf_plus_auction_economics_snapshots snapshots
                        WHERE snapshots.course_id = OLD.course_id
                          AND JSON_CONTAINS(
                              snapshots.firm_ids,
                              JSON_QUOTE(CAST(OLD.id AS CHAR)),
                              '$'
                          ) = 1
                    ) THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT =
                            'Firm identity is immutable after the CF Plus auction snapshot opens.';
                    END IF;
                END
            ");

            return;
        }

        // =============================================================
        // PostgreSQL implementation
        // =============================================================
        if (DB::getDriverName() === 'pgsql') {

            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_phase9_frozen_firm_membership()
RETURNS trigger AS $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM cf_plus_auction_economics_snapshots snapshots
        WHERE snapshots.course_id = OLD.course_id
          AND snapshots.firm_ids::jsonb ? OLD.firm_id::text
    ) AND (
        TG_OP = 'DELETE'
        OR NEW.course_id IS DISTINCT FROM OLD.course_id
        OR NEW.firm_id IS DISTINCT FROM OLD.firm_id
        OR NEW.student_id IS DISTINCT FROM OLD.student_id
        OR NEW.deleted_at IS DISTINCT FROM OLD.deleted_at
    ) THEN
        RAISE EXCEPTION
            'Firm membership is immutable after the CF Plus auction snapshot opens.';
    END IF;

    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER phase9_frozen_firm_membership_guard
BEFORE UPDATE OR DELETE ON firm_memberships
FOR EACH ROW
EXECUTE FUNCTION guard_phase9_frozen_firm_membership();


CREATE OR REPLACE FUNCTION guard_phase9_frozen_firm_universe()
RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'INSERT'
       AND EXISTS (
           SELECT 1
           FROM cf_plus_auction_economics_snapshots snapshots
           WHERE snapshots.course_id = NEW.course_id
       )
    THEN
        RAISE EXCEPTION
            'New firms cannot enter a frozen CF Plus auction universe.';
    END IF;

    IF TG_OP IN ('UPDATE', 'DELETE')
       AND EXISTS (
           SELECT 1
           FROM cf_plus_auction_economics_snapshots snapshots
           WHERE snapshots.course_id = OLD.course_id
             AND snapshots.firm_ids::jsonb ? OLD.id::text
       )
       AND (
           TG_OP = 'DELETE'
           OR NEW.course_id IS DISTINCT FROM OLD.course_id
           OR NEW.deleted_at IS DISTINCT FROM OLD.deleted_at
       )
    THEN
        RAISE EXCEPTION
            'Firm identity is immutable after the CF Plus auction snapshot opens.';
    END IF;

    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER phase9_frozen_firm_universe_guard
BEFORE INSERT OR UPDATE OR DELETE ON firms
FOR EACH ROW
EXECUTE FUNCTION guard_phase9_frozen_firm_universe();
SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {

            DB::unprepared(
                'DROP TRIGGER IF EXISTS phase9_frozen_firm_membership_update'
            );

            DB::unprepared(
                'DROP TRIGGER IF EXISTS phase9_frozen_firm_membership_delete'
            );

            DB::unprepared(
                'DROP TRIGGER IF EXISTS phase9_frozen_firm_universe_insert'
            );

            DB::unprepared(
                'DROP TRIGGER IF EXISTS phase9_frozen_firm_universe_update'
            );

            DB::unprepared(
                'DROP TRIGGER IF EXISTS phase9_frozen_firm_universe_delete'
            );

            return;
        }

        if (DB::getDriverName() === 'pgsql') {

            DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS phase9_frozen_firm_membership_guard
ON firm_memberships;

DROP FUNCTION IF EXISTS guard_phase9_frozen_firm_membership();

DROP TRIGGER IF EXISTS phase9_frozen_firm_universe_guard
ON firms;

DROP FUNCTION IF EXISTS guard_phase9_frozen_firm_universe();
SQL);
        }
    }
};