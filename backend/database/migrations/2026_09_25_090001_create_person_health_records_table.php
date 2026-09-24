<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// docs/04-DATABASE.md §27 "Person Health Records" — one person-based table
// for the V1 types DISABILITY, CHRONIC_DISEASE, PREGNANCY, BREASTFEEDING.
// A record is active while ended_at IS NULL. No hard delete in V1.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_health_records', function (Blueprint $table) {
            $table->id();
            // Public route key; internal ids are never exposed by the API.
            $table->uuid('uuid')->unique();
            $table->foreignId('person_id')->constrained('persons')->restrictOnDelete();
            $table->string('type');
            // Reference values are deactivated, never deleted (docs/06 §56).
            $table->foreignId('disability_type_id')->nullable()->constrained('disability_types')->restrictOnDelete();
            $table->string('condition_name')->nullable();
            $table->text('details')->nullable();
            $table->date('started_at')->nullable();
            $table->date('ended_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['person_id', 'type']);
        });

        // Duplicate-active-record guards (docs/03 §36). Partial indexes are
        // supported by both PostgreSQL and SQLite (test suite).
        DB::statement(
            'CREATE UNIQUE INDEX uq_health_active_maternal '
            .'ON person_health_records (person_id, type) '
            ."WHERE ended_at IS NULL AND type IN ('PREGNANCY', 'BREASTFEEDING')"
        );
        DB::statement(
            'CREATE UNIQUE INDEX uq_health_active_disability '
            .'ON person_health_records (person_id, disability_type_id) '
            ."WHERE ended_at IS NULL AND type = 'DISABILITY'"
        );
        // Case-insensitive backstop only; the application also compares
        // names after simple Arabic/whitespace normalization.
        DB::statement(
            'CREATE UNIQUE INDEX uq_health_active_chronic '
            .'ON person_health_records (person_id, lower(condition_name)) '
            ."WHERE ended_at IS NULL AND type = 'CHRONIC_DISEASE'"
        );

        // Type-shape and date integrity. Postgres-only, same reason as the
        // existing CHECK constraints (SQLite cannot add them post-creation);
        // enforced by the health-record requests/actions on SQLite.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE person_health_records ADD CONSTRAINT chk_health_record_type '
                ."CHECK (type IN ('DISABILITY', 'CHRONIC_DISEASE', 'PREGNANCY', 'BREASTFEEDING'))"
            );
            DB::statement(
                'ALTER TABLE person_health_records ADD CONSTRAINT chk_health_record_shape CHECK ('
                ."(type = 'DISABILITY' AND disability_type_id IS NOT NULL AND condition_name IS NULL) OR "
                ."(type = 'CHRONIC_DISEASE' AND condition_name IS NOT NULL AND disability_type_id IS NULL) OR "
                ."(type IN ('PREGNANCY', 'BREASTFEEDING') AND disability_type_id IS NULL AND condition_name IS NULL))"
            );
            DB::statement(
                'ALTER TABLE person_health_records ADD CONSTRAINT chk_health_record_dates '
                .'CHECK (ended_at IS NULL OR started_at IS NULL OR ended_at >= started_at)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('person_health_records');
    }
};
