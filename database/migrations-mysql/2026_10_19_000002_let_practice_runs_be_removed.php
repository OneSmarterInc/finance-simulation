<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * U9 layer 6 (design note FF6, FF10): practice runs can be removed for good.
 *
 * Two tamper guards stopped that: warm-up results (Quarters 1 to 3) can't be
 * deleted, and the evaluation stage's frozen economics can't be deleted. The
 * delete guards now let a row go when it belongs to a practice run
 * (courses.is_practice). The update guards are unchanged, and nothing
 * changes for real courses.
 *
 * The roster guards on firms and memberships need no change: removal deletes
 * the frozen economics first, after which they no longer apply.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->replace(true);
    }

    public function down(): void
    {
        $this->replace(false);
    }

    private function replace(bool $exemptPractice): void
    {
        $unlessPractice = $exemptPractice
            ? ' AND NOT EXISTS (SELECT 1 FROM courses c WHERE c.id = OLD.course_id AND c.is_practice = 1)'
            : '';

        DB::unprepared('DROP TRIGGER IF EXISTS protect_inherited_firm_engine_results_delete');
        DB::unprepared("
            CREATE TRIGGER protect_inherited_firm_engine_results_delete
            BEFORE DELETE ON firm_engine_results
            FOR EACH ROW
            BEGIN
                IF OLD.quarter_number <= 3{$unlessPractice} THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Inherited Q1-Q3 firm engine results are immutable.';
                END IF;
            END
        ");

        foreach (['cf_plus_auction_snapshot_immutable_delete' => 'cf_plus_auction_economics_snapshots', 'cf_plus_auction_economics_immutable_delete' => 'cf_plus_auction_economics'] as $trigger => $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
            DB::unprepared("
                CREATE TRIGGER {$trigger}
                BEFORE DELETE ON {$table}
                FOR EACH ROW
                BEGIN
                    IF TRUE{$unlessPractice} THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'CF Plus auction economics snapshots are immutable.';
                    END IF;
                END
            ");
        }
    }
};
