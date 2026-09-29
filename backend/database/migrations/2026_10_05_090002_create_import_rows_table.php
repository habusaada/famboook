<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// docs/04-DATABASE.md §83a "Import Staging". One source row of a batch.
// raw_payload holds the sanitized source values (never the excluded
// fields هويتك / الديانة — App\Support\ImportRawPayload) and may contain
// National IDs: RESTRICTED data, same access philosophy as persons.
// Additive only.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained('import_batches')->restrictOnDelete();
            // The row number in the source sheet (1-based).
            $table->unsignedInteger('row_number');
            $table->jsonb('raw_payload');
            // PENDING | VALID | FLAGGED | REJECTED | APPLIED | SKIPPED
            $table->string('status');
            // Validation/review findings; NULL = none recorded.
            $table->jsonb('issues')->nullable();
            // The Family this row created when APPLIED.
            $table->foreignId('family_id')->nullable()->constrained('families')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['import_batch_id', 'row_number']);
            $table->index(['import_batch_id', 'status']);
        });

        // A Family is created by at most one import row.
        DB::statement(
            'CREATE UNIQUE INDEX uq_import_row_family '
            .'ON import_rows (family_id) WHERE family_id IS NOT NULL'
        );

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE import_rows ADD CONSTRAINT chk_import_row_status CHECK (status IN ('
                ."'PENDING', 'VALID', 'FLAGGED', 'REJECTED', 'APPLIED', 'SKIPPED'))"
            );
            DB::statement(
                'ALTER TABLE import_rows ADD CONSTRAINT chk_import_row_number CHECK (row_number >= 1)'
            );
            // A Family link exists exactly when the row was applied.
            DB::statement(
                'ALTER TABLE import_rows ADD CONSTRAINT chk_import_row_applied_family '
                ."CHECK ((status = 'APPLIED') = (family_id IS NOT NULL))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
    }
};
