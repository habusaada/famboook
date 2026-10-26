<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// FB-CIVIL-01A-UI U2 (docs/adr/FB-CIVIL-01A-UI-IMPORT-MANAGEMENT.md §3.2, §4,
// §6): the control plane of civil identity import execution, in the
// APPLICATION schema. The web application only inserts requests (S-1/S-2);
// the dedicated worker executes them through its own control-plane login
// (07-control-plane.dba.sql) and its own identity import login.
//
// - civil_import_sources: the catalogue written by the worker. A source is
//   an opaque id and a safe directory label; never a path, a file name or
//   file content.
// - civil_import_requests: execution requests. On PostgreSQL a trigger
//   enforces the state machine (QUEUED → RUNNING | CANCELLED, RUNNING →
//   SUCCEEDED | FAILED | CANCELLED) and keeps the request's identity columns
//   immutable; a partial unique index allows ONE active request at a time.
//   PUBLISH is not an accepted type in U2: publication cannot be queued.
// - civil_import_worker_heartbeats: one row per worker instance.
//
// No identity value, path, credential or SQL is stored: results are
// allow-listed counts and codes (CivilImportResult).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('civil_import_sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // The directory name under the source root (safe pattern); shown to civil-import.stage holders (O-2).
            $table->string('label', 64)->unique();
            $table->string('manifest_status', 10);          // VALID | INVALID
            // Codes only (e.g. {"MANIFEST_COLUMNS_MISMATCH": 1}); never a file name.
            $table->jsonb('manifest_problems')->nullable();
            $table->string('source_table', 64)->nullable();
            $table->unsignedInteger('file_count')->nullable();
            $table->unsignedBigInteger('total_rows')->nullable();
            // Change detection between cataloguing and staging; never returned by the API.
            $table->char('manifest_sha256', 64)->nullable();
            $table->boolean('available')->default(true);
            $table->timestamp('discovered_at')->useCurrent();
            $table->timestamp('last_seen_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
        });

        Schema::create('civil_import_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 20);
            $table->string('status', 12)->default('QUEUED');
            // Restrict: the requester of recorded history is never deleted.
            $table->foreignId('requested_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('source_id')->nullable()->constrained('civil_import_sources')->restrictOnDelete();
            // Identity run and release, by value only (no foreign key into identity_ref).
            $table->uuid('run_id')->nullable();
            $table->unsignedBigInteger('release_id')->nullable();
            // Client idempotency key (Idempotency-Key header), unique per requester.
            $table->string('idempotency_key', 64)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('worker_instance', 64)->nullable();
            $table->string('reason_code', 64)->nullable();
            $table->string('message', 255)->nullable();     // fixed text for reason_code; never source data
            $table->jsonb('result')->nullable();              // allow-listed counts and codes
            $table->timestamp('queued_at')->useCurrent();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->index(['status', 'queued_at']);
            $table->index(['run_id', 'queued_at']);
            $table->unique(['requested_by', 'idempotency_key']);
        });

        Schema::create('civil_import_worker_heartbeats', function (Blueprint $table) {
            $table->string('instance', 64)->primary();
            $table->string('state', 10);                      // IDLE | BUSY | STOPPED
            $table->uuid('current_request_id')->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('last_seen_at')->useCurrent();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $checks = [
            'civil_import_sources' => [
                'chk_civil_import_source_label' => "label ~ '^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$'",
                'chk_civil_import_source_status' => "manifest_status IN ('VALID', 'INVALID')",
                'chk_civil_import_source_problems' => "manifest_problems IS NULL OR jsonb_typeof(manifest_problems) = 'object'",
            ],
            'civil_import_requests' => [
                // PUBLISH arrives with U3 (an approval creates it); U2 cannot queue a publication.
                'chk_civil_import_request_type' => "type IN ('VALIDATE_SOURCE', 'STAGE', 'ABORT', 'ASSESS', 'PREPARE', 'VERIFY')",
                'chk_civil_import_request_status' => "status IN ('QUEUED', 'RUNNING', 'SUCCEEDED', 'FAILED', 'CANCELLED')",
                'chk_civil_import_request_target' => "(type IN ('VALIDATE_SOURCE', 'STAGE') AND source_id IS NOT NULL)
                    OR (type IN ('ABORT', 'ASSESS', 'PREPARE') AND run_id IS NOT NULL)
                    OR (type = 'VERIFY' AND release_id IS NOT NULL)",
                'chk_civil_import_request_reason' => "reason_code IS NULL OR reason_code ~ '^[A-Z][A-Z0-9_]{1,63}$'",
                'chk_civil_import_request_key' => "idempotency_key IS NULL OR idempotency_key ~ '^[A-Za-z0-9_-]{8,64}$'",
                'chk_civil_import_request_result' => "result IS NULL OR jsonb_typeof(result) = 'object'",
            ],
            'civil_import_worker_heartbeats' => [
                'chk_civil_import_worker_state' => "state IN ('IDLE', 'BUSY', 'STOPPED')",
            ],
        ];
        foreach ($checks as $table => $constraints) {
            foreach ($constraints as $name => $check) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$check})");
            }
        }

        // One QUEUED or RUNNING request at a time (ADR §3.2): two concurrent queue operations cannot both succeed.
        DB::statement("CREATE UNIQUE INDEX uq_civil_import_requests_one_active ON civil_import_requests ((true))
            WHERE status IN ('QUEUED', 'RUNNING')");

        // OR REPLACE: migrate:fresh drops tables (and so the triggers) but not functions.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION famboook_civil_import_request_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'civil_import_requests are history: DELETE is not allowed'
                        USING ERRCODE = 'restrict_violation';
                END IF;
                IF TG_OP = 'INSERT' THEN
                    IF NEW.status <> 'QUEUED' OR NEW.started_at IS NOT NULL OR NEW.finished_at IS NOT NULL OR NEW.attempts <> 0 THEN
                        RAISE EXCEPTION 'a civil import request starts QUEUED' USING ERRCODE = 'check_violation';
                    END IF;
                    RETURN NEW;
                END IF;
                IF NEW.id IS DISTINCT FROM OLD.id OR NEW.type IS DISTINCT FROM OLD.type
                    OR NEW.requested_by IS DISTINCT FROM OLD.requested_by OR NEW.source_id IS DISTINCT FROM OLD.source_id
                    OR NEW.idempotency_key IS DISTINCT FROM OLD.idempotency_key OR NEW.queued_at IS DISTINCT FROM OLD.queued_at
                    OR (OLD.run_id IS NOT NULL AND NEW.run_id IS DISTINCT FROM OLD.run_id)
                    OR (OLD.release_id IS NOT NULL AND NEW.release_id IS DISTINCT FROM OLD.release_id) THEN
                    RAISE EXCEPTION 'the identity of a civil import request is immutable' USING ERRCODE = 'restrict_violation';
                END IF;
                IF OLD.status IN ('SUCCEEDED', 'FAILED', 'CANCELLED') THEN
                    RAISE EXCEPTION 'a finished civil import request is immutable' USING ERRCODE = 'restrict_violation';
                END IF;
                IF NEW.status <> OLD.status AND NOT (
                       (OLD.status = 'QUEUED' AND NEW.status IN ('RUNNING', 'CANCELLED'))
                    OR (OLD.status = 'RUNNING' AND NEW.status IN ('SUCCEEDED', 'FAILED'))
                    OR (OLD.status = 'RUNNING' AND NEW.status = 'CANCELLED' AND NEW.cancel_requested_at IS NOT NULL)) THEN
                    RAISE EXCEPTION 'civil import request transition % -> % is not allowed', OLD.status, NEW.status
                        USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.status = 'RUNNING' AND OLD.status = 'QUEUED' AND (NEW.started_at IS NULL OR NEW.attempts <> OLD.attempts + 1) THEN
                    RAISE EXCEPTION 'a started request records its start and attempt' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.status IN ('SUCCEEDED', 'FAILED', 'CANCELLED') AND NEW.finished_at IS NULL THEN
                    RAISE EXCEPTION 'a finished request records its end' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;
            SQL);
        DB::unprepared('CREATE TRIGGER trg_civil_import_request_guard BEFORE INSERT OR UPDATE OR DELETE ON civil_import_requests '
            .'FOR EACH ROW EXECUTE FUNCTION famboook_civil_import_request_guard()');
    }

    public function down(): void
    {
        if (Schema::hasTable('civil_import_requests') && DB::table('civil_import_requests')->exists()) {
            throw new RuntimeException('Cannot drop civil_import_requests: request history exists.');
        }
        Schema::dropIfExists('civil_import_worker_heartbeats');
        Schema::dropIfExists('civil_import_requests');
        Schema::dropIfExists('civil_import_sources');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS famboook_civil_import_request_guard()');
        }
    }
};
