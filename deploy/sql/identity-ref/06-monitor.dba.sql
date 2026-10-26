-- FB-CIVIL-01A-UI U1 — metadata-only monitoring boundary (REVIEW BEFORE USE).
--
-- Run by the database ADMINISTRATOR, connected to the target database, after
-- 00-roles.dba.sql and 01–03. It creates the monitor roles, then (as
-- famboook_identity_owner) the schema identity_ref_monitor with views over
-- run, file, chunk, issue, release and preparation METADATA. Pure SQL,
-- idempotent, PostgreSQL 16+. Changes no existing object or privilege and
-- never names the application role (docs/adr/FB-CIVIL-01A-UI-IMPORT-MANAGEMENT.md §5).
--
-- Local first (S-5): applying it to Production is a separate, recorded approval.
--
--   famboook_identity_monitor        NOLOGIN group role; SELECT on the views only
--   famboook_identity_monitor_login  the web application's monitoring login: NOINHERIT,
--                                    member of the group only, created NOLOGIN. The DBA
--                                    enables it and sets the password interactively:
--                                        ALTER ROLE famboook_identity_monitor_login LOGIN;
--                                        \password famboook_identity_monitor_login
--
-- Never exposed: records, record_versions (payloads), release_presence,
-- release_incoming, staging rows and assessments, key fingerprints, manifest and
-- file SHA-256 values, internal file ids. File names appear only in
-- file_locations and issue_locations, which the application reads solely for
-- holders of civil-import.locations.view (D-UI-8).

DO $roles$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'famboook_identity_owner') THEN
        RAISE EXCEPTION 'IDENTITY_REF_RUN_00_ROLES_FIRST';
    END IF;
    IF to_regclass('identity_ref.release_preparations') IS NULL THEN
        RAISE EXCEPTION 'IDENTITY_REF_MONITOR_NEEDS_03_PUBLICATION';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'famboook_identity_monitor') THEN
        CREATE ROLE famboook_identity_monitor NOLOGIN NOINHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'famboook_identity_monitor_login') THEN
        CREATE ROLE famboook_identity_monitor_login NOLOGIN NOINHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS
            CONNECTION LIMIT 10;
    END IF;
    ALTER ROLE famboook_identity_monitor NOLOGIN NOINHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
    ALTER ROLE famboook_identity_monitor_login NOINHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS CONNECTION LIMIT 10;
    GRANT famboook_identity_monitor TO famboook_identity_monitor_login;
    EXECUTE format('GRANT CONNECT ON DATABASE %I TO famboook_identity_monitor_login', current_database());
    -- (00-roles.dba.sql already lets the owner create the schema below.)
END
$roles$;

SET ROLE famboook_identity_owner;

CREATE SCHEMA IF NOT EXISTS identity_ref_monitor;
REVOKE ALL ON SCHEMA identity_ref_monitor FROM PUBLIC;

-- Owner's rights (security_invoker = false): the monitor roles need no privilege on any base table.
CREATE OR REPLACE VIEW identity_ref_monitor.runs WITH (security_barrier = true, security_invoker = false) AS
    SELECT id, status, error_code, source_table, expected_rows, rows_loaded, new_records, new_versions,
           unchanged, carried_forward, issues, started_at, finished_at
      FROM identity_ref.import_runs;

-- Files by ordinal within their run; no name (D-UI-8), no id, no SHA value.
CREATE OR REPLACE VIEW identity_ref_monitor.files WITH (security_barrier = true, security_invoker = false) AS
    SELECT f.run_id,
           row_number() OVER (PARTITION BY f.run_id ORDER BY f.file_name)::integer AS file_no,
           f.expected_rows, f.loaded_rows, f.status,
           (f.actual_sha256 IS NOT DISTINCT FROM f.expected_sha256) AS checksum_verified,
           coalesce(p.chunks, 0)::integer AS chunks, coalesce(p.chunk_rows, 0)::bigint AS chunk_rows, p.last_chunk_at
      FROM identity_ref.import_files f
      LEFT JOIN (SELECT c.file_id, count(*) AS chunks, sum(c.row_count) AS chunk_rows, max(c.created_at) AS last_chunk_at
                   FROM identity_ref_staging.file_chunks c GROUP BY c.file_id) p ON p.file_id = f.id;

-- civil-import.locations.view only: the file name behind each ordinal.
CREATE OR REPLACE VIEW identity_ref_monitor.file_locations WITH (security_barrier = true, security_invoker = false) AS
    SELECT run_id, row_number() OVER (PARTITION BY run_id ORDER BY file_name)::integer AS file_no, file_name
      FROM identity_ref.import_files;

CREATE OR REPLACE VIEW identity_ref_monitor.issue_counts WITH (security_barrier = true, security_invoker = false) AS
    SELECT run_id, code, column_name, count(*)::bigint AS n
      FROM identity_ref.import_issues GROUP BY run_id, code, column_name;

-- civil-import.locations.view only.
CREATE OR REPLACE VIEW identity_ref_monitor.issue_locations WITH (security_barrier = true, security_invoker = false) AS
    SELECT id AS issue_no, run_id, file_name, line_number, code, column_name
      FROM identity_ref.import_issues;

CREATE OR REPLACE VIEW identity_ref_monitor.releases WITH (security_barrier = true, security_invoker = false) AS
    SELECT id, run_id, status, base_release_id, record_count, created_at, published_at, abandoned_at
      FROM identity_ref.dataset_releases;

CREATE OR REPLACE VIEW identity_ref_monitor.preparations WITH (security_barrier = true, security_invoker = false) AS
    SELECT release_id, run_id, status, policy_id, comparison_policy, blank_field_policy, quarantine_max_rows, quarantine_max_ratio,
           staged_rows, new_count, changed_count, unchanged_count, conflict_count, carried_forward_count,
           blank_over_value_count, expected_presence, warnings, created_at, prepared_at, verified_at
      FROM identity_ref.release_preparations;

CREATE OR REPLACE VIEW identity_ref_monitor.active_release WITH (security_barrier = true, security_invoker = false) AS
    SELECT release_id, activated_at FROM identity_ref.active_release;

REVOKE ALL ON ALL TABLES IN SCHEMA identity_ref_monitor FROM PUBLIC;
GRANT USAGE ON SCHEMA identity_ref_monitor TO famboook_identity_monitor;
GRANT SELECT ON ALL TABLES IN SCHEMA identity_ref_monitor TO famboook_identity_monitor;

RESET ROLE;
