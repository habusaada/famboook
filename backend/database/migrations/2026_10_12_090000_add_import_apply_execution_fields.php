<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Phase 4B.4a — Apply execution fields (docs/03 §96b, docs/04 §83d). Schema
// only: nothing here starts or runs an Apply.
//
// - apply_plan_fingerprint: the approved plan (ImportBatchApplyPlan
//   fingerprint) captured when Apply starts; resume must re-derive the same
//   plan (planner as-of-Apply-start mode). Present exactly while Apply has
//   started — the same rows as chk_import_batch_apply_started.
// - apply_error_code / apply_error_row_number: structured operational state
//   of the last Apply failure — a stable code and a source row number, never
//   exception text or row data.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->char('apply_plan_fingerprint', 64)->nullable()->after('apply_started_at');
            $table->string('apply_error_code', 60)->nullable()->after('apply_plan_fingerprint');
            $table->unsignedInteger('apply_error_row_number')->nullable()->after('apply_error_code');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE import_batches ADD CONSTRAINT chk_import_batch_apply_plan '
                .'CHECK ((apply_plan_fingerprint IS NOT NULL) = (apply_started_at IS NOT NULL) AND '
                ."(apply_plan_fingerprint IS NULL OR apply_plan_fingerprint ~ '^[0-9a-f]{64}$'))"
            );
            DB::statement(
                'ALTER TABLE import_batches ADD CONSTRAINT chk_import_batch_apply_error '
                ."CHECK ((apply_error_code IS NULL OR apply_error_code ~ '^[A-Z][A-Z0-9_]{1,59}$') AND "
                .'(apply_error_row_number IS NULL OR (apply_error_code IS NOT NULL AND apply_error_row_number >= 1)))'
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE import_batches DROP CONSTRAINT IF EXISTS chk_import_batch_apply_error');
            DB::statement('ALTER TABLE import_batches DROP CONSTRAINT IF EXISTS chk_import_batch_apply_plan');
        }
        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropColumn(['apply_plan_fingerprint', 'apply_error_code', 'apply_error_row_number']);
        });
        // SQLite rebuilt import_batches for the drop and lost the predicate of
        // uq_import_batch_clan_checksum (see 2026_10_10); recreate it exactly.
        DB::statement('DROP INDEX IF EXISTS uq_import_batch_clan_checksum');
        DB::statement(
            'CREATE UNIQUE INDEX uq_import_batch_clan_checksum '
            ."ON import_batches (clan_id, source_checksum) WHERE status <> 'FAILED'"
        );
    }
};
