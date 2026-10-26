<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// FB-CIVIL-01A-UI U4 acceptance fix: the approval-window check of
// famboook_civil_import_publication_guard compared the database clock
// (timestamptz) with expires_at, a naive timestamp the application writes in
// UTC (config app.timezone). PostgreSQL read expires_at in the SESSION time
// zone, so on a non-UTC server the window was shifted by the UTC offset
// (found on a cluster in Asia/Jerusalem: requests "expired" 2 hours early).
// The function now compares in UTC. Only the function body changes; the
// trigger keeps pointing at it. For the same reason the civil import
// timestamp DEFAULTs (CURRENT_TIMESTAMP, session time) become UTC: the
// application always sets these columns itself; raw inserts now agree with it.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
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
                    -- The real clock (now() is frozen at the start of the transaction), in UTC:
                    -- the application writes these naive timestamps in UTC (config app.timezone).
                    IF NEW.status = 'APPROVED' AND (clock_timestamp() AT TIME ZONE 'UTC') >= OLD.expires_at THEN
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

        $defaults = [
            'civil_import_sources' => ['discovered_at', 'last_seen_at', 'updated_at'],
            'civil_import_requests' => ['queued_at'],
            'civil_import_worker_heartbeats' => ['started_at', 'last_seen_at'],
            'civil_import_publication_requests' => ['requested_at'],
            'civil_import_audit_events' => ['occurred_at'],
        ];
        foreach ($defaults as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} SET DEFAULT (now() AT TIME ZONE 'UTC')");
            }
        }
    }

    public function down(): void
    {
        // Intentionally no rollback to the time-zone-dependent comparison.
    }
};
