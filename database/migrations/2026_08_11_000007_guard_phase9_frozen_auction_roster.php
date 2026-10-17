<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_phase9_frozen_firm_membership() RETURNS trigger AS $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM cf_plus_auction_economics_snapshots snapshots
        WHERE snapshots.course_id = OLD.course_id
          AND snapshots.firm_ids::jsonb ? OLD.firm_id::text
    ) AND (
        TG_OP = 'DELETE'
        OR NEW.course_id IS DISTINCT FROM OLD.course_id
        OR NEW.firm_id IS DISTINCT FROM OLD.firm_id
        OR NEW.student_id IS DISTINCT FROM OLD.student_id
        OR NEW.deleted_at IS DISTINCT FROM OLD.deleted_at
    ) THEN
        RAISE EXCEPTION 'Firm membership is immutable after the CF Plus auction snapshot opens.';
    END IF;

    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER phase9_frozen_firm_membership_guard
BEFORE UPDATE OR DELETE ON firm_memberships
FOR EACH ROW EXECUTE FUNCTION guard_phase9_frozen_firm_membership();

CREATE OR REPLACE FUNCTION guard_phase9_frozen_firm_universe() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'INSERT' AND EXISTS (
        SELECT 1 FROM cf_plus_auction_economics_snapshots snapshots
        WHERE snapshots.course_id = NEW.course_id
    ) THEN
        RAISE EXCEPTION 'New firms cannot enter a frozen CF Plus auction universe.';
    END IF;

    IF TG_OP IN ('UPDATE', 'DELETE') AND EXISTS (
        SELECT 1 FROM cf_plus_auction_economics_snapshots snapshots
        WHERE snapshots.course_id = OLD.course_id
          AND snapshots.firm_ids::jsonb ? OLD.id::text
    ) AND (
        TG_OP = 'DELETE'
        OR NEW.course_id IS DISTINCT FROM OLD.course_id
        OR NEW.deleted_at IS DISTINCT FROM OLD.deleted_at
    ) THEN
        RAISE EXCEPTION 'Firm identity is immutable after the CF Plus auction snapshot opens.';
    END IF;

    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER phase9_frozen_firm_universe_guard
BEFORE INSERT OR UPDATE OR DELETE ON firms
FOR EACH ROW EXECUTE FUNCTION guard_phase9_frozen_firm_universe();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS phase9_frozen_firm_membership_guard ON firm_memberships;
DROP FUNCTION IF EXISTS guard_phase9_frozen_firm_membership();
DROP TRIGGER IF EXISTS phase9_frozen_firm_universe_guard ON firms;
DROP FUNCTION IF EXISTS guard_phase9_frozen_firm_universe();
SQL);
    }
};
