-- FB-FAMILY-ADD-01 — ROLLBACK of 10-registry-admission-pin.dba.sql (REVIEW BEFORE USE).
-- docs/deployment/FB-FAMILY-ADD-01-PILOT-READINESS-CHECKLIST.md §5.
--
-- Leaving DBA-1 … DBA-3 in place is safe; remove them only by a recorded DBA decision, and
-- only AFTER the feature is unbound (FAMILY_REGISTRY_ADMISSION_CIVIL_REGISTRY_BINDING=false,
-- config cache cleared): without DBA-3 every pin fails closed and admissions answer 503.
--
-- Run by the database ADMINISTRATOR, in one transaction:
--     psql -X -1 -v ON_ERROR_STOP=1 -d <database> -f 13-registry-admission-pin-rollback.dba.sql
-- Idempotent, pure SQL, PostgreSQL 16+. It restores identity_ref.publish_release(bigint)
-- exactly as 01-schema.sql defines it (its grants are kept by CREATE OR REPLACE), drops the
-- DBA-2 trigger and its function and the DBA-3 function. It changes no data and no other object.

DO $checks$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'famboook_identity_owner') THEN
        RAISE EXCEPTION 'IDENTITY_REF_RUN_00_ROLES_FIRST';
    END IF;
    IF to_regclass('identity_ref.active_release') IS NULL THEN
        RAISE EXCEPTION 'IDENTITY_REF_PIN_NEEDS_01_SCHEMA';
    END IF;
    -- Not while a publication runs ('FBCIV01A') or an admission holds the pin ('FBCIV01P').
    IF EXISTS (SELECT 1 FROM pg_catalog.pg_locks
                WHERE locktype = 'advisory' AND classid = 1178747721 AND objid IN (1445998913, 1445998928) AND objsubid = 1) THEN
        RAISE EXCEPTION 'IDENTITY_REF_PIN_ROLLBACK_LOCK_HELD' USING ERRCODE = 'lock_not_available';
    END IF;
END
$checks$;

SET ROLE famboook_identity_owner;

-- ---------------------------------------------------------------- DBA-3

DROP FUNCTION IF EXISTS identity_ref_lookup.release_pin_held(integer, integer);

-- ---------------------------------------------------------------- DBA-2

DROP TRIGGER IF EXISTS active_release_pinned_forward ON identity_ref.active_release;
DROP FUNCTION IF EXISTS identity_ref.check_active_release_pinned();

-- ---------------------------------------------------------------- DBA-1 (01-schema.sql, verbatim)

CREATE OR REPLACE FUNCTION identity_ref.publish_release(p_release bigint) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp
AS $$
DECLARE
    rel        identity_ref.dataset_releases%ROWTYPE;
    current_id bigint;
    total      bigint;
BEGIN
    IF NOT pg_try_advisory_xact_lock(x'4642434956303141'::bigint) THEN      -- 'FBCIV01A'
        RAISE EXCEPTION 'IDENTITY_REF_PUBLICATION_IN_PROGRESS' USING ERRCODE = 'lock_not_available';
    END IF;
    SELECT * INTO rel FROM identity_ref.dataset_releases WHERE id = p_release FOR UPDATE;
    IF NOT FOUND OR rel.status <> 'BUILDING' THEN
        RAISE EXCEPTION 'IDENTITY_REF_RELEASE_NOT_BUILDING' USING ERRCODE = 'check_violation';
    END IF;
    SELECT a.release_id INTO current_id FROM identity_ref.active_release a WHERE a.id = 1 FOR UPDATE;
    IF rel.base_release_id IS DISTINCT FROM current_id THEN
        RAISE EXCEPTION 'IDENTITY_REF_RELEASE_STALE_BASE' USING ERRCODE = 'serialization_failure';
    END IF;
    SELECT count(*) INTO total FROM identity_ref.release_presence p WHERE p.release_id = p_release;
    IF total = 0 THEN
        RAISE EXCEPTION 'IDENTITY_REF_RELEASE_EMPTY' USING ERRCODE = 'check_violation';
    END IF;
    IF current_id IS NOT NULL AND EXISTS (
        SELECT 1 FROM identity_ref.release_presence b
         WHERE b.release_id = current_id
           AND NOT EXISTS (SELECT 1 FROM identity_ref.release_presence n WHERE n.release_id = p_release AND n.record_id = b.record_id)
    ) THEN
        RAISE EXCEPTION 'IDENTITY_REF_RELEASE_DROPS_IDENTITIES' USING ERRCODE = 'check_violation';
    END IF;
    UPDATE identity_ref.dataset_releases SET status = 'PUBLISHED', published_at = now(), record_count = total WHERE id = p_release;
    INSERT INTO identity_ref.active_release (id, release_id, activated_at) VALUES (1, p_release, now())
        ON CONFLICT (id) DO UPDATE SET release_id = EXCLUDED.release_id, activated_at = EXCLUDED.activated_at;
    RETURN total;
END
$$;

RESET ROLE;
