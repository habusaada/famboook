<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// FB-CIVIL-01A-UI (docs/adr/FB-CIVIL-01A-UI-IMPORT-MANAGEMENT.md §6, §8): the
// audit trail of civil identity import management, in the APPLICATION schema.
// It references identity runs and releases by id only (no foreign key into
// identity_ref, which the application role cannot see) and never holds an
// identity value, a file name, a path or SQL: details are allow-listed codes
// and counts (CivilImportAudit). Append-only: the model refuses update and
// delete and, on PostgreSQL, a row trigger rejects every UPDATE and DELETE.
// down() refuses once an event exists.
return new class extends Migration
{
    private const EVENT_TYPES = "'SOURCE_CATALOGUED', 'SOURCE_UNAVAILABLE', 'REQUEST_QUEUED', 'REQUEST_CANCELLED', 'REQUEST_STARTED', "
        ."'REQUEST_SUCCEEDED', 'REQUEST_FAILED', 'PUBLICATION_REQUESTED', 'PUBLICATION_WITHDRAWN', 'PUBLICATION_APPROVED', "
        ."'PUBLICATION_REJECTED', 'PUBLICATION_EXPIRED', 'PUBLICATION_REFUSED', 'RELEASE_PUBLISHED', 'LOCATIONS_VIEWED'";

    public function up(): void
    {
        Schema::create('civil_import_audit_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 40);
            // Restrict: an actor of recorded history is never deleted.
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('actor_side', 10);
            // Identity run (uuid) and release ids, by value only.
            $table->uuid('run_id')->nullable();
            $table->unsignedBigInteger('release_id')->nullable();
            $table->uuid('request_id')->nullable();
            $table->uuid('publication_request_id')->nullable();
            $table->string('outcome_code', 64)->nullable();
            // Allow-listed codes and counts only (CivilImportAudit::DETAIL_KEYS).
            $table->jsonb('details')->nullable();
            // Immutable: no updated_at.
            $table->timestamp('occurred_at')->useCurrent();

            $table->index(['run_id', 'id']);
            $table->index('event_type');
            $table->index('occurred_at');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $checks = [
            'chk_civil_import_audit_event_type' => 'event_type IN ('.self::EVENT_TYPES.')',
            'chk_civil_import_audit_actor_side' => "actor_side IN ('STAFF', 'SYSTEM')",
            'chk_civil_import_audit_actor' => "actor_side = 'SYSTEM' OR actor_user_id IS NOT NULL",
            'chk_civil_import_audit_outcome' => "outcome_code IS NULL OR outcome_code ~ '^[A-Z][A-Z0-9_]{1,63}$'",
            'chk_civil_import_audit_details' => "details IS NULL OR jsonb_typeof(details) = 'object'",
        ];
        foreach ($checks as $name => $check) {
            DB::statement("ALTER TABLE civil_import_audit_events ADD CONSTRAINT {$name} CHECK ({$check})");
        }

        // OR REPLACE: migrate:fresh drops tables (and so the triggers) but not functions.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION famboook_civil_import_audit_append_only() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'civil_import_audit_events is append-only: % is not allowed', TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$;
            SQL);
        DB::unprepared(
            'CREATE TRIGGER trg_civil_import_audit_append_only BEFORE UPDATE OR DELETE ON civil_import_audit_events '
            .'FOR EACH ROW EXECUTE FUNCTION famboook_civil_import_audit_append_only()'
        );
    }

    public function down(): void
    {
        if (Schema::hasTable('civil_import_audit_events') && DB::table('civil_import_audit_events')->exists()) {
            throw new RuntimeException('Cannot drop civil_import_audit_events: audit history exists.');
        }
        Schema::dropIfExists('civil_import_audit_events');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS famboook_civil_import_audit_append_only()');
        }
    }
};
