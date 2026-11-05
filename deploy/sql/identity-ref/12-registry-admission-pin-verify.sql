-- FB-FAMILY-ADD-01 — post-execution verification of 10-registry-admission-pin.dba.sql (READ-ONLY).
-- docs/deployment/FB-FAMILY-ADD-01-PILOT-READINESS-CHECKLIST.md §3, step 3.
--
-- Run by the database ADMINISTRATOR, in the same database, AFTER applying the file:
--     psql -X -v ON_ERROR_STOP=1 -d <database> -f 12-registry-admission-pin-verify.sql
-- Changes nothing. Raises IDENTITY_REF_PIN_VERIFY_<object>_<reason> at the first defect;
-- otherwise prints NOTICE PASS lines to keep as G-07 evidence. The live end-to-end proof
-- (the application's session takes the pin and the lookup login sees it) is
-- `php artisan famboook:registry-admission-readiness --probe-pin`. Pure SQL, PostgreSQL 16+.

DO $verify$
DECLARE
    owner_oid oid := (SELECT oid FROM pg_catalog.pg_roles WHERE rolname = 'famboook_identity_owner');
    fn        pg_catalog.pg_proc%ROWTYPE;
    sysid     text;
BEGIN
    IF owner_oid IS NULL THEN
        RAISE EXCEPTION 'IDENTITY_REF_PIN_VERIFY_ROLES_MISSING';
    END IF;

    -- DBA-1: publish_release() takes the pin exclusively, bounded, and is otherwise as installed.
    IF pg_catalog.to_regprocedure('identity_ref.publish_release(bigint)') IS NULL THEN
        RAISE EXCEPTION 'IDENTITY_REF_PIN_VERIFY_DBA1_MISSING';
    END IF;
    SELECT * INTO fn FROM pg_catalog.pg_proc WHERE oid = 'identity_ref.publish_release(bigint)'::pg_catalog.regprocedure;
    IF fn.prosrc NOT LIKE '%pg_advisory_xact_lock(x''4642434956303150''::bigint)%'
       OR fn.prosrc NOT LIKE '%IDENTITY_REF_PUBLICATION_PINNED%'
       OR fn.prosrc NOT LIKE '%pg_try_advisory_xact_lock(x''4642434956303141''::bigint)%' THEN
        RAISE EXCEPTION 'IDENTITY_REF_PIN_VERIFY_DBA1_NOT_APPLIED';
    END IF;
    IF NOT fn.prosecdef OR fn.proowner <> owner_oid
       OR NOT (fn.proconfig @> ARRAY['search_path=pg_catalog, pg_temp']) THEN
        RAISE EXCEPTION 'IDENTITY_REF_PIN_VERIFY_DBA1_DEFINITION';
    END IF;
    IF fn.proacl IS NULL OR EXISTS (SELECT 1 FROM pg_catalog.aclexplode(fn.proacl) a WHERE a.grantee = 0) THEN
        RAISE EXCEPTION 'IDENTITY_REF_PIN_VERIFY_DBA1_PUBLIC_EXECUTE';
    END IF;
    IF NOT pg_catalog.has_function_privilege('famboook_identity_writer', fn.oid, 'EXECUTE') THEN
        RAISE EXCEPTION 'IDENTITY_REF_PIN_VERIFY_DBA1_WRITER_LOST_EXECUTE';
    END IF;
    RAISE NOTICE 'VERIFY PASS DBA-1: publish_release() waits bounded for FBCIV01P and holds it exclusively';

    -- DBA-2: the forward-only pointer trigger, enabled, BEFORE INSERT OR UPDATE, FOR EACH ROW (tgtype 23).
    IF NOT EXISTS (SELECT 1 FROM pg_catalog.pg_trigger t
                    WHERE t.tgrelid = 'identity_ref.active_release'::pg_catalog.regclass
                      AND t.tgname = 'active_release_pinned_forward' AND NOT t.tgisinternal
                      AND t.tgenabled = 'O' AND t.tgtype = 23
                      AND t.tgfoid = pg_catalog.to_regprocedure('identity_ref.check_active_release_pinned()')) THEN
        RAISE EXCEPTION 'IDENTITY_REF_PIN_VERIFY_DBA2_TRIGGER';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_catalog.pg_proc
                    WHERE oid = 'identity_ref.check_active_release_pinned()'::pg_catalog.regprocedure AND proowner = owner_oid
                      AND prosrc LIKE '%IDENTITY_REF_ACTIVE_RELEASE_PIN_NOT_HELD%' AND prosrc LIKE '%IDENTITY_REF_ACTIVE_RELEASE_NOT_FORWARD%') THEN
        RAISE EXCEPTION 'IDENTITY_REF_PIN_VERIFY_DBA2_FUNCTION';
    END IF;
    RAISE NOTICE 'VERIFY PASS DBA-2: identity_ref.active_release moves only forward, only under FBCIV01P';

    -- DBA-3: release_pin_held(), SECURITY DEFINER, EXECUTE for famboook_identity_reader only.
    IF pg_catalog.to_regprocedure('identity_ref_lookup.release_pin_held(integer, integer)') IS NULL THEN
        RAISE EXCEPTION 'IDENTITY_REF_PIN_VERIFY_DBA3_MISSING';
    END IF;
    SELECT * INTO fn FROM pg_catalog.pg_proc WHERE oid = 'identity_ref_lookup.release_pin_held(integer, integer)'::pg_catalog.regprocedure;
    IF NOT fn.prosecdef OR fn.proowner <> owner_oid OR fn.provolatile <> 's'
       OR NOT (fn.proconfig @> ARRAY['search_path=pg_catalog, pg_temp'])
       OR fn.prosrc NOT LIKE '%1178747721%' OR fn.prosrc NOT LIKE '%1178751054%' THEN
        RAISE EXCEPTION 'IDENTITY_REF_PIN_VERIFY_DBA3_DEFINITION';
    END IF;
    IF fn.proacl IS NULL OR EXISTS (SELECT 1 FROM pg_catalog.aclexplode(fn.proacl) a WHERE a.grantee = 0)
       OR EXISTS (SELECT 1 FROM pg_catalog.aclexplode(fn.proacl) a
                   WHERE a.privilege_type = 'EXECUTE' AND a.grantee NOT IN (owner_oid, (SELECT oid FROM pg_catalog.pg_roles WHERE rolname = 'famboook_identity_reader'))) THEN
        RAISE EXCEPTION 'IDENTITY_REF_PIN_VERIFY_DBA3_PRIVILEGES';
    END IF;
    IF NOT pg_catalog.has_function_privilege('famboook_identity_reader', fn.oid, 'EXECUTE') THEN
        RAISE EXCEPTION 'IDENTITY_REF_PIN_VERIFY_DBA3_READER_CANNOT_EXECUTE';
    END IF;
    IF EXISTS (SELECT 1 FROM pg_catalog.pg_roles WHERE rolname = 'famboook_app')
       AND pg_catalog.has_function_privilege('famboook_app', fn.oid, 'EXECUTE') THEN
        RAISE EXCEPTION 'IDENTITY_REF_PIN_VERIFY_DBA3_APPLICATION_ROLE_CAN_EXECUTE';
    END IF;
    RAISE NOTICE 'VERIFY PASS DBA-3: release_pin_held() exists, EXECUTE for famboook_identity_reader only';

    -- DBA-4 (this moment): both sides in this database, on a primary; the lookup login may connect.
    IF pg_catalog.pg_is_in_recovery()
       OR pg_catalog.to_regclass('public.family_registry_admissions') IS NULL
       OR NOT pg_catalog.has_database_privilege('famboook_identity_lookup_login', pg_catalog.current_database(), 'CONNECT') THEN
        RAISE EXCEPTION 'IDENTITY_REF_PIN_VERIFY_DBA4_NOT_ONE_DATABASE';
    END IF;
    BEGIN
        SELECT s.system_identifier::text INTO sysid FROM pg_catalog.pg_control_system() s;
    EXCEPTION WHEN insufficient_privilege THEN
        sysid := 'unavailable (needs pg_control_system)';
    END;
    RAISE NOTICE 'VERIFY PASS DBA-4 (now): database %, cluster system identifier %; routing and failover remain an operations guarantee',
        pg_catalog.current_database(), sysid;
END
$verify$;
