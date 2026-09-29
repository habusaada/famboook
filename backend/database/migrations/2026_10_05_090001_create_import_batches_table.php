<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// docs/04-DATABASE.md §83 "Import Architecture" / §83a "Import Staging".
// One uploaded source file. Rows are staged in import_rows and reach the
// canonical tables only through Domain Actions after review. The file
// itself is not stored here; only its name and SHA-256 checksum, for
// traceability. Additive only.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('source_filename');
            // Lower-case hex SHA-256 of the uploaded file.
            $table->char('source_checksum', 64);
            // UPLOADED | VALIDATING | READY_FOR_REVIEW | READY_TO_APPLY |
            // APPLYING | APPLIED | FAILED
            $table->string('status');
            $table->unsignedInteger('row_count')->default(0);
            // Why the batch FAILED (technical/operational, never row data).
            $table->text('failure_reason')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index('status');
            // Not unique: a corrected re-upload of the same file is legitimate.
            $table->index('source_checksum');
        });

        // Postgres-only (see 2026_09_23_153941); enforced by the model enum
        // cast and the future import Domain Actions on SQLite.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE import_batches ADD CONSTRAINT chk_import_batch_status CHECK (status IN ('
                ."'UPLOADED', 'VALIDATING', 'READY_FOR_REVIEW', 'READY_TO_APPLY', 'APPLYING', 'APPLIED', 'FAILED'))"
            );
            DB::statement(
                'ALTER TABLE import_batches ADD CONSTRAINT chk_import_batch_row_count CHECK (row_count >= 0)'
            );
            DB::statement(
                'ALTER TABLE import_batches ADD CONSTRAINT chk_import_batch_checksum '
                ."CHECK (source_checksum ~ '^[0-9a-f]{64}$')"
            );
            DB::statement(
                'ALTER TABLE import_batches ADD CONSTRAINT chk_import_batch_applied '
                ."CHECK (status <> 'APPLIED' OR applied_at IS NOT NULL)"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
