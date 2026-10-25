-- FB-CIVIL-01A-P4 — re-assert ownership and privileges of the identity schemas
-- AFTER A RESTORE (REVIEW BEFORE USE).
--
-- Why: deploy/scripts/backup-db.sh dumps and deploy/scripts/restore-test.sh
-- restores with --no-owner --no-privileges. A restored identity_ref is then
-- owned by whoever ran pg_restore (often a superuser) and has NO grants:
--   * SECURITY DEFINER functions would run with the restoring role's rights;
--   * if restored as the application role, famboook_app would OWN the schema
--     and could disable the immutability triggers.
-- This script puts every identity object back under famboook_identity_owner
-- and re-applies exactly the privileges of 01-schema.sql, 02-staging.sql and
-- 03-publication.sql. It changes no data. Idempotent.
--
-- Run by the database ADMINISTRATOR, connected to the restored database,
-- after 00-roles.dba.sql (roles are cluster objects and are not in a dump):
--     psql -v ON_ERROR_STOP=1 -d <restored_db> -f 00-roles.dba.sql
--     psql -v ON_ERROR_STOP=1 -d <restored_db> -f 04-reassert-after-restore.dba.sql
-- Pure SQL, PostgreSQL 16+.
--
-- Note: UNLOGGED staging data is not restored (excluded from backups, and
-- emptied by crash recovery). A run that was RUNNING when the backup was
-- taken cannot be prepared afterwards (IDENTITY_REF_ROW_COUNT_INCONSISTENT):
-- abort it and stage the source again (runbook R-STAGING).

DO $reassert$
DECLARE
    obj record;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'famboook_identity_owner')
       OR NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'famboook_identity_writer')
       OR NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'famboook_identity_reader') THEN
        RAISE EXCEPTION 'IDENTITY_REF_REASSERT_RUN_00_ROLES_FIRST';
    END IF;
    IF to_regnamespace('identity_ref') IS NULL THEN
        RAISE EXCEPTION 'IDENTITY_REF_REASSERT_NO_IDENTITY_SCHEMA';
    END IF;

    -- 1. Ownership: schemas, tables / views (identity sequences follow their table), functions.
    FOR obj IN SELECT nspname FROM pg_namespace WHERE nspname IN ('identity_ref', 'identity_ref_staging') LOOP
        EXECUTE format('ALTER SCHEMA %I OWNER TO famboook_identity_owner', obj.nspname);
    END LOOP;
    FOR obj IN SELECT n.nspname, c.relname, c.relkind FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname IN ('identity_ref', 'identity_ref_staging') AND c.relkind IN ('r', 'v', 'p') LOOP
        EXECUTE format('ALTER %s %I.%I OWNER TO famboook_identity_owner',
                       CASE obj.relkind WHEN 'v' THEN 'VIEW' ELSE 'TABLE' END, obj.nspname, obj.relname);
    END LOOP;
    FOR obj IN SELECT p.oid::regprocedure AS fn FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
                WHERE n.nspname IN ('identity_ref', 'identity_ref_staging') LOOP
        EXECUTE format('ALTER FUNCTION %s OWNER TO famboook_identity_owner', obj.fn);
    END LOOP;

    -- 2. Nothing for anyone but what is re-granted below.
    FOR obj IN SELECT nspname FROM pg_namespace WHERE nspname IN ('identity_ref', 'identity_ref_staging') LOOP
        EXECUTE format('REVOKE ALL ON SCHEMA %I FROM PUBLIC', obj.nspname);
        EXECUTE format('REVOKE ALL ON ALL TABLES IN SCHEMA %I FROM PUBLIC, famboook_identity_writer, famboook_identity_reader', obj.nspname);
        EXECUTE format('REVOKE ALL ON ALL SEQUENCES IN SCHEMA %I FROM PUBLIC, famboook_identity_writer, famboook_identity_reader', obj.nspname);
        EXECUTE format('REVOKE ALL ON ALL FUNCTIONS IN SCHEMA %I FROM PUBLIC, famboook_identity_writer, famboook_identity_reader', obj.nspname);
        EXECUTE format('REVOKE ALL ON SCHEMA %I FROM famboook_identity_writer, famboook_identity_reader', obj.nspname);
        EXECUTE format('ALTER DEFAULT PRIVILEGES FOR ROLE famboook_identity_owner IN SCHEMA %I REVOKE ALL ON TABLES FROM PUBLIC', obj.nspname);
        EXECUTE format('ALTER DEFAULT PRIVILEGES FOR ROLE famboook_identity_owner IN SCHEMA %I REVOKE ALL ON SEQUENCES FROM PUBLIC', obj.nspname);
        EXECUTE format('ALTER DEFAULT PRIVILEGES FOR ROLE famboook_identity_owner IN SCHEMA %I REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC', obj.nspname);
        IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'famboook_app') THEN
            EXECUTE format('REVOKE ALL ON SCHEMA %I FROM famboook_app', obj.nspname);
            EXECUTE format('REVOKE ALL ON ALL TABLES IN SCHEMA %I FROM famboook_app', obj.nspname);
            EXECUTE format('REVOKE ALL ON ALL FUNCTIONS IN SCHEMA %I FROM famboook_app', obj.nspname);
        END IF;
    END LOOP;
END
$reassert$;

-- 3. The grants of 01-schema.sql (granted BY the owner, as in a fresh install).
SET ROLE famboook_identity_owner;
GRANT USAGE ON SCHEMA identity_ref TO famboook_identity_writer;
GRANT SELECT, INSERT ON identity_ref.records, identity_ref.record_versions, identity_ref.release_presence,
                        identity_ref.dataset_releases, identity_ref.import_issues TO famboook_identity_writer;
GRANT SELECT ON identity_ref.active_release TO famboook_identity_writer;
GRANT SELECT, INSERT ON identity_ref.import_runs, identity_ref.import_files TO famboook_identity_writer;
GRANT UPDATE (status, finished_at, error_code, rows_loaded, new_records, new_versions, unchanged, carried_forward, issues)
    ON identity_ref.import_runs TO famboook_identity_writer;
GRANT UPDATE (actual_sha256, loaded_rows, status) ON identity_ref.import_files TO famboook_identity_writer;
GRANT EXECUTE ON FUNCTION identity_ref.source_columns(), identity_ref.is_valid_source_payload(jsonb),
                          identity_ref.carry_forward_missing(bigint), identity_ref.publish_release(bigint),
                          identity_ref.abandon_release(bigint) TO famboook_identity_writer;

-- 02-staging.sql
GRANT USAGE ON SCHEMA identity_ref_staging TO famboook_identity_writer;
GRANT SELECT, INSERT, DELETE ON identity_ref_staging.file_chunks, identity_ref_staging.rows TO famboook_identity_writer;

-- 03-publication.sql
GRANT SELECT ON identity_ref.release_preparations TO famboook_identity_writer;
GRANT SELECT, INSERT ON identity_ref.release_incoming TO famboook_identity_writer;
GRANT SELECT, DELETE ON identity_ref_staging.assessments TO famboook_identity_writer;
GRANT EXECUTE ON FUNCTION identity_ref_staging.assess_run(uuid, bigint, text), identity_ref_staging.assess_warnings(uuid, bigint),
                          identity_ref.begin_preparation(uuid, text, text, text, text, bigint, numeric),
                          identity_ref.complete_preparation(bigint), identity_ref.verify_release(bigint),
                          identity_ref.refresh_statistics()
    TO famboook_identity_writer;
RESET ROLE;
