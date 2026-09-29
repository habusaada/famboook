<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Record reconciliation (Import Wizard step 5, docs/03 §96a, docs/04 §83c).
//
// Compares staged rows with the PERMANENT registry without changing it.
// The authoritative per-row state is import_rows.reconciliation_status
// (reserved earlier: NEW, UNCHANGED, CHANGED, DUPLICATE_IN_FILE, CONFLICT,
// REVIEW_REQUIRED); import_row_reconciliations holds the evidence: the HEAD
// Person match and the Family match (kept separate), spouse candidates,
// differences and issue codes. One row per staged row; re-running replaces it.
//
// import_batches.reconciled_at / reconciled_by / reconciliation_fingerprint
// record the last run; the fingerprint captures the inputs (staging, key
// decisions, registry markers) so stale results can be detected. Additive.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->timestamp('reconciled_at')->nullable()->after('mapping_confirmed_at');
            $table->foreignId('reconciled_by')->nullable()->after('reconciled_at')->constrained('users')->nullOnDelete();
            $table->char('reconciliation_fingerprint', 64)->nullable()->after('reconciled_by');
        });
        $this->restorePartialChecksumIndex();

        Schema::create('import_row_reconciliations', function (Blueprint $table) {
            $table->id();
            // Staging data: removed with its row when a batch is re-staged.
            $table->foreignId('import_row_id')->unique()->constrained('import_rows')->cascadeOnDelete();
            $table->foreignId('import_batch_id')->constrained('import_batches')->restrictOnDelete();
            // HEAD Person evidence: NO_NATIONAL_ID | NO_EXISTING_PERSON | EXISTING_PERSON |
            // MULTIPLE_PERSONS | DELETED_PERSON | FORMAT_VARIANT
            $table->string('head_match', 30);
            $table->foreignId('head_person_id')->nullable()->constrained('persons')->nullOnDelete();
            // Family evidence (separate): NO_EXISTING_FAMILY | EXISTING_FAMILY |
            // OTHER_CLAN_FAMILY | PERSON_NOT_HEAD | NOT_DETERMINED
            $table->string('family_match', 30);
            $table->foreignId('family_id')->nullable()->constrained('families')->nullOnDelete();
            // Per wife slot: candidate status + existing Person id (no values).
            $table->jsonb('spouse_matches')->nullable();
            // [{field, scope (person|family), registry, source}] — deterministic matches only.
            $table->jsonb('differences')->nullable();
            // [{code, severity, context}] — codes, row numbers, masked ids; never values of
            // forbidden columns.
            $table->jsonb('issues')->nullable();
            $table->timestamps();

            $table->index(['import_batch_id', 'head_match']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE import_row_reconciliations ADD CONSTRAINT chk_row_reconciliation_head_match CHECK (head_match IN ('
                ."'NO_NATIONAL_ID', 'NO_EXISTING_PERSON', 'EXISTING_PERSON', 'MULTIPLE_PERSONS', 'DELETED_PERSON', 'FORMAT_VARIANT'))"
            );
            DB::statement(
                'ALTER TABLE import_row_reconciliations ADD CONSTRAINT chk_row_reconciliation_family_match CHECK (family_match IN ('
                ."'NO_EXISTING_FAMILY', 'EXISTING_FAMILY', 'OTHER_CLAN_FAMILY', 'PERSON_NOT_HEAD', 'NOT_DETERMINED'))"
            );
            DB::statement(
                'ALTER TABLE import_row_reconciliations ADD CONSTRAINT chk_row_reconciliation_family '
                ."CHECK ((family_match = 'EXISTING_FAMILY') = (family_id IS NOT NULL) OR family_match IN ('OTHER_CLAN_FAMILY', 'PERSON_NOT_HEAD'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('import_row_reconciliations');

        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reconciled_by');
            $table->dropColumn(['reconciled_at', 'reconciliation_fingerprint']);
        });
        $this->restorePartialChecksumIndex();
    }

    /**
     * SQLite rebuilds import_batches for a foreign-key change and recreates
     * uq_import_batch_clan_checksum WITHOUT its predicate. Recreate it exactly
     * (one live batch per file and Clan; FAILED batches excluded). Harmless on
     * PostgreSQL, where the index is never lost.
     */
    private function restorePartialChecksumIndex(): void
    {
        DB::statement('DROP INDEX IF EXISTS uq_import_batch_clan_checksum');
        DB::statement(
            'CREATE UNIQUE INDEX uq_import_batch_clan_checksum '
            ."ON import_batches (clan_id, source_checksum) WHERE status <> 'FAILED'"
        );
    }
};
