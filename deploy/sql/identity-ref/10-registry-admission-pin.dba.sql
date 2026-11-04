-- FB-FAMILY-ADD-01 — registry release pin, DBA-1 … DBA-3 (REVIEW BEFORE USE).
-- docs/adr/FB-FAMILY-ADD-01-DIRECT-ADMISSION.md §10a ("Mandatory prerequisites").
--
-- Run by the database ADMINISTRATOR, connected to the database that holds BOTH the
-- application tables and identity_ref (DBA-4), after 00-roles.dba.sql, 01–03 and
-- 08-lookup.dba.sql. Pure SQL, idempotent, PostgreSQL 16+. Re-run it after a restore.
--
-- LOCAL ONLY. Applying it to Production is part of gate G-07 and needs its own recorded
-- approval. Applying it activates nothing: admission stays behind the flag (OFF), the
-- permission (held by no role), the declaration, the life and gender gates and binding.
--
--   DBA-1  identity_ref.publish_release(bigint): unchanged from 01-schema.sql except that,
--          after 'FBCIV01A' (fail fast), it waits BOUNDED for the pin 'FBCIV01P' and holds
--          it exclusively. Over the limit: IDENTITY_REF_PUBLICATION_PINNED, nothing changed.
--   DBA-2  a trigger on identity_ref.active_release: the pointer only moves FORWARD, and
--          only while the writing session holds 'FBCIV01P' exclusively (for every role,
--          the owner included; a superuser can still disable triggers).
--   DBA-3  identity_ref_lookup.release_pin_held(integer, integer), SECURITY DEFINER,
--          EXECUTE for famboook_identity_reader only (the lookup login).
--
-- 'FBCIV01P' = x'4642434956303150' (pg_locks classid 1178747721, objid 1445998928,
-- objsubid 1). The nonce lock is (x'4642504E' = 1178751054, nonce), objsubid 2.

DO $checks$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'famboook_identity_owner')
       OR NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'famboook_identity_reader') THEN
        RAISE EXCEPTION 'IDENTITY_REF_RUN_00_ROLES_FIRST';
    END IF;
    IF to_regclass('identity_ref.active_release') IS NULL THEN
        RAISE EXCEPTION 'IDENTITY_REF_PIN_NEEDS_01_SCHEMA';
    END IF;
    IF to_regnamespace('identity_ref_lookup') IS NULL THEN
        RAISE EXCEPTION 'IDENTITY_REF_PIN_NEEDS_08_LOOKUP';
    END IF;
END
$checks$;

SET ROLE famboook_identity_owner;

-- ---------------------------------------------------------------- DBA-1

CREATE OR REPLACE FUNCTION identity_ref.publish_release(p_release bigint) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp
AS $$
DECLARE
    rel        identity_ref.dataset_releases%ROWTYPE;
    current_id bigint;
    total      bigint;
    previous_timeout text;
BEGIN
    IF NOT pg_try_advisory_xact_lock(x'4642434956303141'::bigint) THEN      -- 'FBCIV01A'
        RAISE EXCEPTION 'IDENTITY_REF_PUBLICATION_IN_PROGRESS' USING ERRCODE = 'lock_not_available';
    END IF;
    -- DBA-1 (FB-FAMILY-ADD-01 §10a): wait, BOUNDED, until no admission holds the release pin
    -- 'FBCIV01P' (shared), then hold it exclusively until commit, so no admission can pin the
    -- release being replaced and a new admission fails fast meanwhile. The wait is
    -- famboook.publication_pin_wait (default 30s); the caller's lock_timeout is restored.
    previous_timeout := pg_catalog.current_setting('lock_timeout');
    PERFORM pg_catalog.set_config('lock_timeout',
        COALESCE(NULLIF(pg_catalog.current_setting('famboook.publication_pin_wait', true), ''), '30s'), true);
    BEGIN
        PERFORM pg_catalog.pg_advisory_xact_lock(x'4642434956303150'::bigint);   -- 'FBCIV01P'
    EXCEPTION WHEN lock_not_available THEN
        RAISE EXCEPTION 'IDENTITY_REF_PUBLICATION_PINNED' USING ERRCODE = 'lock_not_available';
    END;
    PERFORM pg_catalog.set_config('lock_timeout', previous_timeout, true);
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

-- ---------------------------------------------------------------- DBA-2

CREATE OR REPLACE FUNCTION identity_ref.check_active_release_pinned() RETURNS trigger
    LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp
AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_catalog.pg_locks l
         WHERE l.locktype OPERATOR(pg_catalog.=) 'advisory' AND l.pid OPERATOR(pg_catalog.=) pg_catalog.pg_backend_pid()
           AND l.database OPERATOR(pg_catalog.=) (SELECT d.oid FROM pg_catalog.pg_database d WHERE d.datname OPERATOR(pg_catalog.=) pg_catalog.current_database())
           AND l.classid OPERATOR(pg_catalog.=) 1178747721::pg_catalog.oid AND l.objid OPERATOR(pg_catalog.=) 1445998928::pg_catalog.oid
           AND l.objsubid OPERATOR(pg_catalog.=) 1 AND l.mode OPERATOR(pg_catalog.=) 'ExclusiveLock' AND l.granted) THEN
        RAISE EXCEPTION 'IDENTITY_REF_ACTIVE_RELEASE_PIN_NOT_HELD' USING ERRCODE = 'check_violation';
    END IF;
    IF TG_OP OPERATOR(pg_catalog.=) 'UPDATE' AND NEW.release_id OPERATOR(pg_catalog.<=) OLD.release_id THEN
        RAISE EXCEPTION 'IDENTITY_REF_ACTIVE_RELEASE_NOT_FORWARD' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END
$$;
DROP TRIGGER IF EXISTS active_release_pinned_forward ON identity_ref.active_release;
CREATE TRIGGER active_release_pinned_forward BEFORE INSERT OR UPDATE ON identity_ref.active_release
    FOR EACH ROW EXECUTE FUNCTION identity_ref.check_active_release_pinned();

-- ---------------------------------------------------------------- DBA-3 (the ADR contract, verbatim in substance)

CREATE OR REPLACE FUNCTION identity_ref_lookup.release_pin_held(p_holder_pid integer, p_nonce integer)
    RETURNS TABLE (held boolean, active_release_id bigint)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
    SELECT EXISTS (
               SELECT 1 FROM pg_catalog.pg_locks pin
                 JOIN pg_catalog.pg_locks nonce
                   ON nonce.pid OPERATOR(pg_catalog.=) pin.pid AND nonce.virtualtransaction OPERATOR(pg_catalog.=) pin.virtualtransaction
                WHERE pin.locktype OPERATOR(pg_catalog.=) 'advisory' AND nonce.locktype OPERATOR(pg_catalog.=) 'advisory'
                  AND pin.database OPERATOR(pg_catalog.=) (SELECT d.oid FROM pg_catalog.pg_database d WHERE d.datname OPERATOR(pg_catalog.=) pg_catalog.current_database())
                  AND nonce.database OPERATOR(pg_catalog.=) pin.database
                  AND pin.classid OPERATOR(pg_catalog.=) 1178747721::pg_catalog.oid AND pin.objid OPERATOR(pg_catalog.=) 1445998928::pg_catalog.oid
                  AND pin.objsubid OPERATOR(pg_catalog.=) 1 AND pin.mode OPERATOR(pg_catalog.=) 'ShareLock' AND pin.granted
                  AND nonce.classid OPERATOR(pg_catalog.=) 1178751054::pg_catalog.oid AND nonce.objid OPERATOR(pg_catalog.=) p_nonce::pg_catalog.oid
                  AND nonce.objsubid OPERATOR(pg_catalog.=) 2 AND nonce.mode OPERATOR(pg_catalog.=) 'ExclusiveLock' AND nonce.granted
                  AND pin.pid OPERATOR(pg_catalog.=) p_holder_pid AND pin.pid OPERATOR(pg_catalog.<>) pg_catalog.pg_backend_pid()
                  AND p_nonce OPERATOR(pg_catalog.>) 0
                  -- The holder is INSIDE a live transaction with that virtual id.
                  AND EXISTS (SELECT 1 FROM pg_catalog.pg_locks live
                       WHERE live.locktype OPERATOR(pg_catalog.=) 'virtualxid' AND live.pid OPERATOR(pg_catalog.=) pin.pid
                         AND live.virtualxid OPERATOR(pg_catalog.=) pin.virtualtransaction
                         AND live.mode OPERATOR(pg_catalog.=) 'ExclusiveLock' AND live.granted))
           -- A fresh snapshot only.
           AND pg_catalog.current_setting('transaction_isolation') OPERATOR(pg_catalog.=) 'read committed',
           (SELECT a.release_id FROM identity_ref.active_release a WHERE a.id OPERATOR(pg_catalog.=) 1)
$$;
REVOKE ALL ON FUNCTION identity_ref_lookup.release_pin_held(integer, integer) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION identity_ref_lookup.release_pin_held(integer, integer) TO famboook_identity_reader;

RESET ROLE;
