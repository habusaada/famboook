-- FB-FAMILY-ADD-01 — prerequisites of 10-registry-admission-pin.dba.sql (READ-ONLY).
-- docs/deployment/FB-FAMILY-ADD-01-PILOT-READINESS-CHECKLIST.md §3, step 3.
--
-- Run by the database ADMINISTRATOR, connected to the database that is to receive
-- 10-registry-admission-pin.dba.sql, BEFORE applying it:
--     psql -X -v ON_ERROR_STOP=1 -d <database> -f 11-registry-admission-pin-precheck.sql
-- Changes nothing. Raises IDENTITY_REF_PIN_PRECHECK_<reason> at the first failed
-- prerequisite; otherwise prints NOTICE lines (no registry record, no identifier) to be
-- kept as evidence. Pure SQL, PostgreSQL 16+.
--
--   DBA-4 (this moment): the application tables of FB-FAMILY-ADD-01 and identity_ref are in
--   THIS database, on a primary, and the lookup login may connect to it. Routing, poolers
--   and failover stay an operations guarantee (not checkable from SQL).

DO $precheck$
DECLARE
    applied boolean;
    active  bigint;
    rel_status text;
    sysid   text;
BEGIN
    IF pg_catalog.pg_is_in_recovery() THEN
        RAISE EXCEPTION 'IDENTITY_REF_PIN_PRECHECK_NOT_A_PRIMARY';
    END IF;
    IF pg_catalog.current_setting('server_version_num')::integer < 160000 THEN
        RAISE EXCEPTION 'IDENTITY_REF_PIN_PRECHECK_NEEDS_POSTGRESQL_16';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_catalog.pg_roles WHERE rolname = 'famboook_identity_owner')
       OR NOT EXISTS (SELECT 1 FROM pg_catalog.pg_roles WHERE rolname = 'famboook_identity_writer')
       OR NOT EXISTS (SELECT 1 FROM pg_catalog.pg_roles WHERE rolname = 'famboook_identity_reader')
       OR NOT EXISTS (SELECT 1 FROM pg_catalog.pg_roles WHERE rolname = 'famboook_identity_lookup_login') THEN
        RAISE EXCEPTION 'IDENTITY_REF_PIN_PRECHECK_ROLES_MISSING';
    END IF;
    -- DBA-4: the application side of the feature lives in this same database.
    IF pg_catalog.to_regclass('public.family_registry_admissions') IS NULL
       OR pg_catalog.to_regclass('public.family_registry_admission_events') IS NULL THEN
        RAISE EXCEPTION 'IDENTITY_REF_PIN_PRECHECK_APPLICATION_TABLES_NOT_IN_THIS_DATABASE';
    END IF;
    IF pg_catalog.to_regclass('identity_ref.active_release') IS NULL
       OR pg_catalog.to_regclass('identity_ref.dataset_releases') IS NULL THEN
        RAISE EXCEPTION 'IDENTITY_REF_PIN_PRECHECK_IDENTITY_REF_NOT_IN_THIS_DATABASE';
    END IF;
    IF pg_catalog.to_regnamespace('identity_ref_lookup') IS NULL
       OR pg_catalog.to_regprocedure('identity_ref_lookup.children(text, integer, integer)') IS NULL THEN
        RAISE EXCEPTION 'IDENTITY_REF_PIN_PRECHECK_NEEDS_08_LOOKUP';
    END IF;
    IF NOT pg_catalog.has_database_privilege('famboook_identity_lookup_login', pg_catalog.current_database(), 'CONNECT') THEN
        RAISE EXCEPTION 'IDENTITY_REF_PIN_PRECHECK_LOOKUP_LOGIN_CANNOT_CONNECT';
    END IF;
    IF pg_catalog.to_regprocedure('identity_ref.publish_release(bigint)') IS NULL
       OR NOT EXISTS (SELECT 1 FROM pg_catalog.pg_proc
                       WHERE oid = 'identity_ref.publish_release(bigint)'::pg_catalog.regprocedure AND prosecdef
                         AND proowner = (SELECT oid FROM pg_catalog.pg_roles WHERE rolname = 'famboook_identity_owner')) THEN
        RAISE EXCEPTION 'IDENTITY_REF_PIN_PRECHECK_PUBLISH_RELEASE_NOT_AS_INSTALLED';
    END IF;
    -- No publication may be running while publish_release() is replaced ('FBCIV01A').
    IF EXISTS (SELECT 1 FROM pg_catalog.pg_locks
                WHERE locktype = 'advisory' AND classid = 1178747721 AND objid = 1445998913 AND objsubid = 1) THEN
        RAISE EXCEPTION 'IDENTITY_REF_PIN_PRECHECK_PUBLICATION_IN_PROGRESS';
    END IF;
    SELECT a.release_id, d.status INTO active, rel_status
      FROM identity_ref.active_release a JOIN identity_ref.dataset_releases d ON d.id = a.release_id WHERE a.id = 1;
    IF active IS NULL OR rel_status IS DISTINCT FROM 'PUBLISHED' THEN
        RAISE EXCEPTION 'IDENTITY_REF_PIN_PRECHECK_NO_PUBLISHED_ACTIVE_RELEASE';
    END IF;

    SELECT p.prosrc LIKE '%4642434956303150%' INTO applied FROM pg_catalog.pg_proc p
     WHERE p.oid = 'identity_ref.publish_release(bigint)'::pg_catalog.regprocedure;
    BEGIN
        SELECT s.system_identifier::text INTO sysid FROM pg_catalog.pg_control_system() s;
    EXCEPTION WHEN insufficient_privilege THEN
        sysid := 'unavailable (needs pg_control_system)';
    END;
    RAISE NOTICE 'PRECHECK PASS: database %, cluster system identifier %', pg_catalog.current_database(), sysid;
    RAISE NOTICE 'PRECHECK PASS: active release id %, status PUBLISHED', active;
    RAISE NOTICE 'PRECHECK: DBA-1 already applied: %; DBA-2 trigger present: %; DBA-3 function present: %',
        CASE WHEN applied THEN 'YES' ELSE 'NO' END,
        CASE WHEN EXISTS (SELECT 1 FROM pg_catalog.pg_trigger WHERE tgname = 'active_release_pinned_forward'
                           AND tgrelid = 'identity_ref.active_release'::pg_catalog.regclass) THEN 'YES' ELSE 'NO' END,
        CASE WHEN pg_catalog.to_regprocedure('identity_ref_lookup.release_pin_held(integer, integer)') IS NOT NULL THEN 'YES' ELSE 'NO' END;
END
$precheck$;
