<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// FB-GEO-01 Stage 1C (Stage 1B decision register Q2, DB-ADR-064): review
// findings on gaza_locations, kept apart from the official source data. A
// flag never changes its location. One flag per (location, issue): a
// re-import never duplicates a finding nor resets its review status.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gaza_location_review_flags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('gaza_location_id')->constrained('gaza_locations')->restrictOnDelete();
            $table->string('issue_code', 50);
            $table->string('severity', 10);
            // Where the finding is documented (e.g. "FB-GEO-01 Stage 1A W2 (§6.2)").
            $table->string('source_reference', 100);
            $table->string('review_status', 30)->default('OPEN');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['gaza_location_id', 'issue_code']);
            $table->index(['issue_code', 'review_status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE gaza_location_review_flags ADD CONSTRAINT chk_gaza_review_flag_severity '
                ."CHECK (severity IN ('WARNING', 'INFO'))"
            );
            DB::statement(
                'ALTER TABLE gaza_location_review_flags ADD CONSTRAINT chk_gaza_review_flag_status '
                ."CHECK (review_status IN ('OPEN', 'CONFIRMED', 'CORRECTION_REQUIRED', 'DISMISSED'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gaza_location_review_flags');
    }
};
