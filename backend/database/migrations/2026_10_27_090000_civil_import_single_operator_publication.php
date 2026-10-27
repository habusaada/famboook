<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

// FB-CIVIL-01A-UI D-UI-12a (supersedes the two-person rule D-UI-12): a
// SUPER_ADMIN requests AND confirms the publication of a VERIFIED release
// themselves, after re-entering their password. The worker still publishes.
//
// - The request's own requester is now the ONLY user who may confirm it
//   (PENDING_APPROVAL → APPROVED with decided_by = requested_by), in the
//   publication guard; a decision by anybody else, and REJECTED, are no
//   longer possible. The PUBLISH request must be queued by that same user.
// - Pending requests opened under the two-person rule EXPIRE (POLICY_CHANGED,
//   audited): never confirmed or published automatically. Closed requests
//   and the audit trail stay as they are (history).
// - civil-import.publish.approve is replaced by civil-import.publish.confirm
//   (the seeder grants it, gated as before). The old permission is removed;
//   a direct grant to a user stops the migration (it may only come from the role).
// - Two new audit events: PUBLICATION_CONFIRMED, PUBLICATION_REAUTH_FAILED.
return new class extends Migration
{
    private const OBSOLETE_PERMISSION = 'civil-import.publish.approve';

    private const EVENT_TYPES = "'SOURCE_CATALOGUED', 'SOURCE_UNAVAILABLE', 'REQUEST_QUEUED', 'REQUEST_CANCELLED', 'REQUEST_STARTED', "
        ."'REQUEST_SUCCEEDED', 'REQUEST_FAILED', 'PUBLICATION_REQUESTED', 'PUBLICATION_WITHDRAWN', 'PUBLICATION_APPROVED', "
        ."'PUBLICATION_REJECTED', 'PUBLICATION_EXPIRED', 'PUBLICATION_REFUSED', 'RELEASE_PUBLISHED', 'LOCATIONS_VIEWED'";

    private const NEW_EVENT_TYPES = "'PUBLICATION_CONFIRMED', 'PUBLICATION_REAUTH_FAILED'";

    public function up(): void
    {
        $this->removeObsoletePermission();
        $this->expireTwoPersonRequests();

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE civil_import_publication_requests DROP CONSTRAINT IF EXISTS chk_civil_import_publication_two_person');
        DB::statement('ALTER TABLE civil_import_audit_events DROP CONSTRAINT IF EXISTS chk_civil_import_audit_event_type');
        DB::statement('ALTER TABLE civil_import_audit_events ADD CONSTRAINT chk_civil_import_audit_event_type CHECK (event_type IN ('
            .self::EVENT_TYPES.', '.self::NEW_EVENT_TYPES.'))');

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
                -- D-UI-12a: no REJECTED any more (history keeps the old rows).
                IF NEW.status <> OLD.status AND NOT (
                       (OLD.status = 'PENDING_APPROVAL' AND NEW.status IN ('APPROVED', 'WITHDRAWN', 'EXPIRED'))
                    OR (OLD.status = 'APPROVED' AND NEW.status IN ('EXECUTED', 'FAILED'))) THEN
                    RAISE EXCEPTION 'publication request transition % -> % is not allowed', OLD.status, NEW.status
                        USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.status = 'APPROVED' AND OLD.status = 'PENDING_APPROVAL' THEN
                    IF NEW.decided_by IS NULL OR NEW.decided_at IS NULL THEN
                        RAISE EXCEPTION 'a confirmation records its user' USING ERRCODE = 'check_violation';
                    END IF;
                    -- D-UI-12a: the requester confirms their own request; nobody else decides it.
                    IF NEW.decided_by <> OLD.requested_by THEN
                        RAISE EXCEPTION 'only the requester confirms a publication request' USING ERRCODE = 'check_violation';
                    END IF;
                    -- The real clock, in UTC like expires_at (now() is frozen at the start of the transaction).
                    IF (clock_timestamp() AT TIME ZONE 'UTC') >= OLD.expires_at THEN
                        RAISE EXCEPTION 'the publication request expired' USING ERRCODE = 'check_violation';
                    END IF;
                END IF;
                IF NEW.status IN ('WITHDRAWN', 'EXPIRED') AND NEW.decided_by IS NOT NULL THEN
                    RAISE EXCEPTION 'only a confirmation has a decider' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.status IN ('WITHDRAWN', 'EXPIRED', 'EXECUTED', 'FAILED') AND NEW.finished_at IS NULL THEN
                    RAISE EXCEPTION 'a closed publication request records its end' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION famboook_civil_import_publish_request_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'UPDATE' AND NEW.publication_request_id IS DISTINCT FROM OLD.publication_request_id THEN
                    RAISE EXCEPTION 'the publication of a request is immutable' USING ERRCODE = 'restrict_violation';
                END IF;
                -- D-UI-12a: only a request its own requester confirmed, queued by that user.
                IF TG_OP = 'INSERT' AND NEW.type = 'PUBLISH' AND NOT EXISTS (
                        SELECT 1 FROM civil_import_publication_requests p
                         WHERE p.id = NEW.publication_request_id AND p.status = 'APPROVED' AND p.release_id = NEW.release_id
                           AND p.decided_by IS NOT NULL AND p.decided_by = p.requested_by AND NEW.requested_by = p.decided_by) THEN
                    RAISE EXCEPTION 'a PUBLISH request needs a publication request confirmed by its requester, queued by that user'
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;
            SQL);
    }

    /**
     * Restores the two-person rules only while nothing depends on the new
     * ones: no request confirmed by its requester and no event of the new
     * types (audit is append-only). The removed permission is re-created by
     * the previous seeder; expired requests stay expired (history).
     */
    public function down(): void
    {
        $selfConfirmed = DB::table('civil_import_publication_requests')->whereNotNull('decided_by')
            ->whereColumn('decided_by', 'requested_by')->exists();
        $newEvents = DB::table('civil_import_audit_events')->whereIn('event_type', ['PUBLICATION_CONFIRMED', 'PUBLICATION_REAUTH_FAILED'])->exists();
        if ($selfConfirmed || $newEvents) {
            throw new RuntimeException('Cannot restore the two-person publication rule: single-operator confirmations are recorded. Roll the code back and keep this migration.');
        }
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE civil_import_audit_events DROP CONSTRAINT IF EXISTS chk_civil_import_audit_event_type');
        DB::statement('ALTER TABLE civil_import_audit_events ADD CONSTRAINT chk_civil_import_audit_event_type CHECK (event_type IN ('.self::EVENT_TYPES.'))');
        DB::statement('ALTER TABLE civil_import_publication_requests ADD CONSTRAINT chk_civil_import_publication_two_person
            CHECK (decided_by IS NULL OR decided_by <> requested_by)');

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
    }

    /** Removes civil-import.publish.approve (role grants with it); a direct user grant stops the migration. */
    private function removeObsoletePermission(): void
    {
        $tables = config('permission.table_names');
        $pivotKey = config('permission.column_names.permission_pivot_key') ?? 'permission_id';
        $ids = DB::table($tables['permissions'])->where('name', self::OBSOLETE_PERMISSION)->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }
        if (DB::table($tables['model_has_permissions'])->whereIn($pivotKey, $ids)->exists()) {
            throw new RuntimeException(self::OBSOLETE_PERMISSION.' is granted directly to a user; remove that grant explicitly before running this migration.');
        }
        DB::transaction(function () use ($tables, $pivotKey, $ids) {
            DB::table($tables['role_has_permissions'])->whereIn($pivotKey, $ids)->delete();
            DB::table($tables['permissions'])->whereIn('id', $ids)->delete();
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Two-person requests still pending: EXPIRED (POLICY_CHANGED), audited. Never confirmed or published. */
    private function expireTwoPersonRequests(): void
    {
        DB::transaction(function () {
            $pending = DB::table('civil_import_publication_requests')->where('status', 'PENDING_APPROVAL')->lockForUpdate()->get();
            $now = Carbon::now();
            foreach ($pending as $request) {
                DB::table('civil_import_publication_requests')->where('id', $request->id)
                    ->update(['status' => 'EXPIRED', 'outcome_code' => 'POLICY_CHANGED', 'finished_at' => $now]);
                DB::table('civil_import_audit_events')->insert([
                    'event_type' => 'PUBLICATION_EXPIRED', 'actor_user_id' => null, 'actor_side' => 'SYSTEM',
                    'run_id' => $request->run_id, 'release_id' => $request->release_id, 'request_id' => null,
                    'publication_request_id' => $request->id, 'outcome_code' => 'POLICY_CHANGED', 'details' => null, 'occurred_at' => $now,
                ]);
            }
        });
    }
};
