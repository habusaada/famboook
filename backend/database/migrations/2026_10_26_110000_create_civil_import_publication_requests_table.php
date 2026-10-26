<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// FB-CIVIL-01A-UI U3 (docs/adr/FB-CIVIL-01A-UI-IMPORT-MANAGEMENT.md §3.3,
// D-UI-6/11/12, S-3): two-person publication approval, in the APPLICATION
// schema. A publication request names a VERIFIED identity release by id, the
// active release it was compared with (the baseline), a justification CODE
// and a counts snapshot — never an identity value or free text.
//
//   PENDING_APPROVAL → APPROVED (another user) | REJECTED (another user)
//                    | WITHDRAWN (requester) | EXPIRED (24 h, baseline or release changed)
//   APPROVED         → EXECUTED | FAILED (the worker's PUBLISH request)
//
// On PostgreSQL: a trigger enforces the transitions, immutability and the
// approval window; CHECK decided_by <> requested_by; one open (pending or
// approved) request at a time. civil_import_requests now accepts PUBLISH,
// but ONLY for an APPROVED publication request, at most once per request:
// the HTTP application can never queue a publication on its own.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('civil_import_publication_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('release_id');
            $table->uuid('run_id');
            // The active release when requested (null: first release); a change invalidates the request.
            $table->unsignedBigInteger('base_release_id')->nullable();
            $table->string('status', 20)->default('PENDING_APPROVAL');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('justification_code', 40);
            $table->string('decision_reason_code', 40)->nullable();
            $table->string('outcome_code', 64)->nullable();
            // Counts only (expected presence, new, changed, …) at request time.
            $table->jsonb('snapshot');
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('expires_at');
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->index(['status', 'requested_at']);
            $table->index('release_id');
        });

        Schema::table('civil_import_requests', function (Blueprint $table) {
            $table->uuid('publication_request_id')->nullable()->after('release_id');
            $table->foreign('publication_request_id')->references('id')->on('civil_import_publication_requests')->restrictOnDelete();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $checks = [
            'chk_civil_import_publication_status' => "status IN ('PENDING_APPROVAL', 'APPROVED', 'REJECTED', 'WITHDRAWN', 'EXPIRED', 'EXECUTED', 'FAILED')",
            // D-UI-12: the requester never decides their own request.
            'chk_civil_import_publication_two_person' => 'decided_by IS NULL OR decided_by <> requested_by',
            'chk_civil_import_publication_justification' => "justification_code IN ('ROUTINE_REFRESH', 'INITIAL_LOAD', 'CORRECTION')",
            'chk_civil_import_publication_codes' => "(decision_reason_code IS NULL OR decision_reason_code ~ '^[A-Z][A-Z0-9_]{1,39}$')
                AND (outcome_code IS NULL OR outcome_code ~ '^[A-Z][A-Z0-9_]{1,63}$')",
            'chk_civil_import_publication_snapshot' => "jsonb_typeof(snapshot) = 'object'",
            'chk_civil_import_publication_window' => "expires_at > requested_at AND expires_at <= requested_at + interval '24 hours'",
        ];
        foreach ($checks as $name => $check) {
            DB::statement("ALTER TABLE civil_import_publication_requests ADD CONSTRAINT {$name} CHECK ({$check})");
        }
        DB::statement("CREATE UNIQUE INDEX uq_civil_import_publication_one_open ON civil_import_publication_requests ((true))
            WHERE status IN ('PENDING_APPROVAL', 'APPROVED')");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION famboook_civil_import_publication_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'civil_import_publication_requests are history: DELETE is not allowed'
                        USING ERRCODE = 'restrict_violation';
                END IF;
                IF TG_OP = 'INSERT' THEN
                    IF NEW.status <> 'PENDING_APPROVAL' OR NEW.decided_by IS NOT NULL OR NEW.decided_at IS NOT NULL OR NEW.finished_at IS NOT NULL THEN
                        RAISE EXCEPTION 'a publication request starts PENDING_APPROVAL' USING ERRCODE = 'check_violation';
                    END IF;
                    RETURN NEW;
                END IF;
                IF NEW.id IS DISTINCT FROM OLD.id OR NEW.release_id IS DISTINCT FROM OLD.release_id OR NEW.run_id IS DISTINCT FROM OLD.run_id
                    OR NEW.base_release_id IS DISTINCT FROM OLD.base_release_id OR NEW.requested_by IS DISTINCT FROM OLD.requested_by
                    OR NEW.justification_code IS DISTINCT FROM OLD.justification_code OR NEW.snapshot IS DISTINCT FROM OLD.snapshot
                    OR NEW.requested_at IS DISTINCT FROM OLD.requested_at OR NEW.expires_at IS DISTINCT FROM OLD.expires_at
                    OR (OLD.decided_by IS NOT NULL AND NEW.decided_by IS DISTINCT FROM OLD.decided_by) THEN
                    RAISE EXCEPTION 'the identity of a publication request is immutable' USING ERRCODE = 'restrict_violation';
                END IF;
                IF OLD.status IN ('REJECTED', 'WITHDRAWN', 'EXPIRED', 'EXECUTED', 'FAILED') THEN
                    RAISE EXCEPTION 'a closed publication request is immutable' USING ERRCODE = 'restrict_violation';
                END IF;
                IF NEW.status <> OLD.status AND NOT (
                       (OLD.status = 'PENDING_APPROVAL' AND NEW.status IN ('APPROVED', 'REJECTED', 'WITHDRAWN', 'EXPIRED'))
                    OR (OLD.status = 'APPROVED' AND NEW.status IN ('EXECUTED', 'FAILED'))) THEN
                    RAISE EXCEPTION 'publication request transition % -> % is not allowed', OLD.status, NEW.status
                        USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.status IN ('APPROVED', 'REJECTED') AND OLD.status = 'PENDING_APPROVAL' THEN
                    IF NEW.decided_by IS NULL OR NEW.decided_at IS NULL THEN
                        RAISE EXCEPTION 'a decision records its decider' USING ERRCODE = 'check_violation';
                    END IF;
                    -- The real clock (now() is frozen at the start of the transaction).
                    IF NEW.status = 'APPROVED' AND clock_timestamp() >= OLD.expires_at THEN
                        RAISE EXCEPTION 'the publication request expired' USING ERRCODE = 'check_violation';
                    END IF;
                END IF;
                IF NEW.status IN ('WITHDRAWN', 'EXPIRED') AND NEW.decided_by IS NOT NULL THEN
                    RAISE EXCEPTION 'only an approval or a rejection has a decider' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.status IN ('REJECTED', 'WITHDRAWN', 'EXPIRED', 'EXECUTED', 'FAILED') AND NEW.finished_at IS NULL THEN
                    RAISE EXCEPTION 'a closed publication request records its end' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;
            SQL);
        DB::unprepared('CREATE TRIGGER trg_civil_import_publication_guard BEFORE INSERT OR UPDATE OR DELETE ON civil_import_publication_requests '
            .'FOR EACH ROW EXECUTE FUNCTION famboook_civil_import_publication_guard()');

        // civil_import_requests: PUBLISH joins the accepted types (U3), only for an approved publication request.
        DB::statement('ALTER TABLE civil_import_requests DROP CONSTRAINT chk_civil_import_request_type');
        DB::statement("ALTER TABLE civil_import_requests ADD CONSTRAINT chk_civil_import_request_type
            CHECK (type IN ('VALIDATE_SOURCE', 'STAGE', 'ABORT', 'ASSESS', 'PREPARE', 'VERIFY', 'PUBLISH'))");
        DB::statement('ALTER TABLE civil_import_requests DROP CONSTRAINT chk_civil_import_request_target');
        DB::statement("ALTER TABLE civil_import_requests ADD CONSTRAINT chk_civil_import_request_target CHECK (
               (type IN ('VALIDATE_SOURCE', 'STAGE') AND source_id IS NOT NULL)
            OR (type IN ('ABORT', 'ASSESS', 'PREPARE') AND run_id IS NOT NULL)
            OR (type = 'VERIFY' AND release_id IS NOT NULL)
            OR (type = 'PUBLISH' AND release_id IS NOT NULL AND publication_request_id IS NOT NULL))");
        DB::statement("ALTER TABLE civil_import_requests ADD CONSTRAINT chk_civil_import_request_publication
            CHECK ((type = 'PUBLISH') = (publication_request_id IS NOT NULL))");
        // At most one PUBLISH per publication request: concurrent approvals cannot publish twice.
        DB::statement('CREATE UNIQUE INDEX uq_civil_import_requests_one_publish ON civil_import_requests (publication_request_id)
            WHERE publication_request_id IS NOT NULL');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION famboook_civil_import_publish_request_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'UPDATE' AND NEW.publication_request_id IS DISTINCT FROM OLD.publication_request_id THEN
                    RAISE EXCEPTION 'the publication of a request is immutable' USING ERRCODE = 'restrict_violation';
                END IF;
                IF TG_OP = 'INSERT' AND NEW.type = 'PUBLISH' AND NOT EXISTS (
                        SELECT 1 FROM civil_import_publication_requests p
                         WHERE p.id = NEW.publication_request_id AND p.status = 'APPROVED' AND p.release_id = NEW.release_id
                           AND p.decided_by IS NOT NULL AND p.decided_by <> p.requested_by AND NEW.requested_by = p.decided_by) THEN
                    RAISE EXCEPTION 'a PUBLISH request needs an approved publication request, queued by its approver'
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;
            SQL);
        DB::unprepared('CREATE TRIGGER trg_civil_import_publish_request_guard BEFORE INSERT OR UPDATE ON civil_import_requests '
            .'FOR EACH ROW EXECUTE FUNCTION famboook_civil_import_publish_request_guard()');
    }

    public function down(): void
    {
        if (Schema::hasTable('civil_import_publication_requests') && DB::table('civil_import_publication_requests')->exists()) {
            throw new RuntimeException('Cannot drop civil_import_publication_requests: publication history exists.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS trg_civil_import_publish_request_guard ON civil_import_requests');
            DB::statement('DROP FUNCTION IF EXISTS famboook_civil_import_publish_request_guard()');
            DB::statement('DROP INDEX IF EXISTS uq_civil_import_requests_one_publish');
            DB::statement('ALTER TABLE civil_import_requests DROP CONSTRAINT IF EXISTS chk_civil_import_request_publication');
        }
        Schema::table('civil_import_requests', function (Blueprint $table) {
            $table->dropForeign(['publication_request_id']);
            $table->dropColumn('publication_request_id');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE civil_import_requests DROP CONSTRAINT IF EXISTS chk_civil_import_request_type');
            DB::statement("ALTER TABLE civil_import_requests ADD CONSTRAINT chk_civil_import_request_type
                CHECK (type IN ('VALIDATE_SOURCE', 'STAGE', 'ABORT', 'ASSESS', 'PREPARE', 'VERIFY'))");
            DB::statement('ALTER TABLE civil_import_requests DROP CONSTRAINT IF EXISTS chk_civil_import_request_target');
            DB::statement("ALTER TABLE civil_import_requests ADD CONSTRAINT chk_civil_import_request_target CHECK (
                   (type IN ('VALIDATE_SOURCE', 'STAGE') AND source_id IS NOT NULL)
                OR (type IN ('ABORT', 'ASSESS', 'PREPARE') AND run_id IS NOT NULL)
                OR (type = 'VERIFY' AND release_id IS NOT NULL))");
        }
        Schema::dropIfExists('civil_import_publication_requests');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS famboook_civil_import_publication_guard()');
        }
    }
};
