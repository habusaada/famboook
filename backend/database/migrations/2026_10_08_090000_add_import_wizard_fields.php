<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Import Wizard (docs/03 §96a, docs/04 §83a). Additive only.
//
// import_batches
//   import_mode            INITIAL | INCREMENTAL — explicit, never inferred.
//   source_size_bytes      shown when a batch is reopened.
//   source_file_path       the uploaded workbook on the PRIVATE disk; needed
//                          because staging now happens after column mapping,
//                          in a later request.
//   worksheet_name         the explicitly selected / confirmed data sheet.
//   inspection             sheet and header STRUCTURE only (names, column
//                          letters/positions, counts) — never cell values.
//   column_mapping         the confirmed canonical-field → column mapping.
//   mapping_confirmed_at   set exactly when a mapping is confirmed.
// import_rows
//   reconciliation_status  reserved for the future incremental reconciliation
//                          phase (NEW, UNCHANGED, CHANGED, DUPLICATE_IN_FILE,
//                          CONFLICT, REVIEW_REQUIRED); NULL = not reconciled.
return new class extends Migration
{
    public function up(): void
    {
        // A batch without an explicit import mode cannot be given one by guessing.
        if (DB::table('import_batches')->exists()) {
            throw new RuntimeException(
                'import_batches already has rows without an import mode; they must be removed or assigned explicitly first.'
            );
        }

        Schema::table('import_batches', function (Blueprint $table) {
            $table->string('import_mode', 20)->after('clan_id');
            $table->unsignedBigInteger('source_size_bytes')->nullable()->after('source_checksum');
            $table->string('source_file_path')->nullable()->after('source_size_bytes');
            $table->string('worksheet_name')->nullable()->after('source_file_path');
            $table->jsonb('inspection')->nullable()->after('worksheet_name');
            $table->jsonb('column_mapping')->nullable()->after('inspection');
            $table->timestamp('mapping_confirmed_at')->nullable()->after('column_mapping');
        });

        Schema::table('import_rows', function (Blueprint $table) {
            $table->string('reconciliation_status', 30)->nullable()->after('status');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE import_batches ADD CONSTRAINT chk_import_batch_mode '
                ."CHECK (import_mode IN ('INITIAL', 'INCREMENTAL'))"
            );
            DB::statement(
                'ALTER TABLE import_batches ADD CONSTRAINT chk_import_batch_mapping_confirmed '
                .'CHECK ((mapping_confirmed_at IS NULL) = (column_mapping IS NULL))'
            );
            DB::statement(
                'ALTER TABLE import_rows ADD CONSTRAINT chk_import_row_reconciliation '
                ."CHECK (reconciliation_status IS NULL OR reconciliation_status IN ('NEW', 'UNCHANGED', 'CHANGED', 'DUPLICATE_IN_FILE', 'CONFLICT', 'REVIEW_REQUIRED'))"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE import_rows DROP CONSTRAINT IF EXISTS chk_import_row_reconciliation');
            DB::statement('ALTER TABLE import_batches DROP CONSTRAINT IF EXISTS chk_import_batch_mapping_confirmed');
            DB::statement('ALTER TABLE import_batches DROP CONSTRAINT IF EXISTS chk_import_batch_mode');
        }

        Schema::table('import_rows', function (Blueprint $table) {
            $table->dropColumn('reconciliation_status');
        });

        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropColumn([
                'import_mode', 'source_size_bytes', 'source_file_path', 'worksheet_name',
                'inspection', 'column_mapping', 'mapping_confirmed_at',
            ]);
        });
    }
};
