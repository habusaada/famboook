<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// FB-GEO-01 Stage 1C.1 (ADR-FB-GEO-01 §4.4, docs/04 §24a, DB-ADR-065):
// persistent import history (Stage 1B R5), separate from the official
// geographic records — no foreign key to gaza_locations.
//
// - One row per executed or rejected import attempt (a missing file and a
//   dry run are not recorded).
// - At most one RUNNING row: partial unique index on both drivers, the
//   database backstop of the import's lock.
// - Additive only: no existing table is touched.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gaza_geography_import_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('source_authority', 150);
            // File name only — never a local path.
            $table->string('source_filename', 255);
            // SHA-256 of the file actually read.
            $table->string('source_sha256', 64);
            // NULL = not stated by the source (never invented).
            $table->string('source_version', 50)->nullable();
            $table->string('status', 20);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            // Source counts (NULL when the source was rejected before parsing).
            $table->unsignedInteger('governorates_count')->nullable();
            $table->unsignedInteger('cities_count')->nullable();
            $table->unsignedInteger('areas_count')->nullable();
            $table->unsignedInteger('neighborhoods_count')->nullable();
            $table->unsignedInteger('landmarks_count')->nullable();
            // Outcome counts (set when SUCCEEDED).
            $table->unsignedInteger('locations_created')->nullable();
            $table->unsignedInteger('locations_unchanged')->nullable();
            $table->unsignedInteger('locations_missing_from_source')->nullable();
            $table->unsignedInteger('flags_created')->nullable();
            $table->unsignedInteger('flags_unchanged')->nullable();
            $table->text('error_summary')->nullable();
            $table->timestamps();

            $table->index(['status', 'started_at']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX uq_gaza_import_single_running '
            ."ON gaza_geography_import_runs (status) WHERE status = 'RUNNING'"
        );

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE gaza_geography_import_runs ADD CONSTRAINT chk_gaza_import_status '
                ."CHECK (status IN ('RUNNING', 'SUCCEEDED', 'FAILED', 'REJECTED'))"
            );
            DB::statement(
                'ALTER TABLE gaza_geography_import_runs ADD CONSTRAINT chk_gaza_import_finished '
                ."CHECK ((status = 'RUNNING') = (finished_at IS NULL))"
            );
            DB::statement(
                'ALTER TABLE gaza_geography_import_runs ADD CONSTRAINT chk_gaza_import_sha256 '
                ."CHECK (source_sha256 ~ '^[0-9a-f]{64}$')"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gaza_geography_import_runs');
    }
};
