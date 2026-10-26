-- FB-CIVIL-01A-UI U2 — the import worker's control-plane login (REVIEW BEFORE USE).
--
-- Run by the database ADMINISTRATOR, connected to the target database, AFTER
-- the application migrations created the civil_import_* tables (decision O-1,
-- docs/adr/FB-CIVIL-01A-UI-IMPORT-MANAGEMENT.md §4). Pure SQL, idempotent,
-- PostgreSQL 16+. Re-run it after a restore or after any migration that
-- recreates one of the tables below. It never names the application role.
--
-- Local first (S-5): applying it to Production is a separate, recorded approval.
--
--   famboook_civil_control        NOLOGIN group role holding exactly:
--       civil_import_sources            SELECT, INSERT, UPDATE   (the catalogue)
--       civil_import_requests           SELECT, UPDATE of the execution columns only
--                                       (never INSERT or DELETE: requests come from the web application)
--       civil_import_worker_heartbeats  SELECT, INSERT, UPDATE
--       civil_import_audit_events       INSERT (+ SELECT of id, for INSERT … RETURNING; + its sequence)
--   famboook_civil_control_login  the worker's control-plane login: NOINHERIT,
--                                 member of the group only, created NOLOGIN. The DBA
--                                 enables it and sets the password interactively:
--                                     ALTER ROLE famboook_civil_control_login LOGIN;
--                                     \password famboook_civil_control_login
--
-- The worker's OTHER login is the FB-CIVIL-01A import login (member of
-- famboook_identity_writer). The two are kept apart: neither control role may
-- be a member of any identity role, nor may any identity role be a member of
-- them. Neither control role gets any privilege on an identity schema.

DO $control$
DECLARE
    app_schema text;
    tables_found int;
    seq text;
BEGIN
    SELECT count(DISTINCT n.nspname), min(n.nspname) INTO tables_found, app_schema
      FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
     WHERE c.relkind = 'r' AND c.relname IN ('civil_import_sources', 'civil_import_requests',
                                             'civil_import_worker_heartbeats', 'civil_import_audit_events')
       AND n.nspname NOT LIKE 'pg\_%' AND n.nspname <> 'information_schema';
    IF tables_found <> 1 OR (SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = app_schema AND c.relkind = 'r' AND c.relname IN ('civil_import_sources', 'civil_import_requests',
                'civil_import_worker_heartbeats', 'civil_import_audit_events')) <> 4 THEN
        RAISE EXCEPTION 'CIVIL_IMPORT_CONTROL_RUN_MIGRATIONS_FIRST';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'famboook_civil_control') THEN
        CREATE ROLE famboook_civil_control NOLOGIN NOINHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'famboook_civil_control_login') THEN
        CREATE ROLE famboook_civil_control_login NOLOGIN NOINHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS
            CONNECTION LIMIT 4;
    END IF;
    ALTER ROLE famboook_civil_control NOLOGIN NOINHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
    ALTER ROLE famboook_civil_control_login NOINHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS CONNECTION LIMIT 4;
    GRANT famboook_civil_control TO famboook_civil_control_login;
    EXECUTE format('GRANT CONNECT ON DATABASE %I TO famboook_civil_control_login', current_database());

    -- Separation from the identity roles, in both directions.
    IF EXISTS (SELECT 1 FROM pg_auth_members m JOIN pg_roles g ON g.oid = m.roleid JOIN pg_roles r ON r.oid = m.member
                WHERE (r.rolname IN ('famboook_civil_control', 'famboook_civil_control_login') AND g.rolname LIKE 'famboook\_identity\_%')
                   OR (g.rolname IN ('famboook_civil_control', 'famboook_civil_control_login') AND r.rolname LIKE 'famboook\_identity\_%')) THEN
        RAISE EXCEPTION 'CIVIL_IMPORT_CONTROL_MUST_NOT_SHARE_IDENTITY_ROLES';
    END IF;

    -- Exactly the privileges listed above (revoke first: re-running converges).
    EXECUTE format('GRANT USAGE ON SCHEMA %I TO famboook_civil_control', app_schema);
    EXECUTE format('REVOKE ALL ON %I.civil_import_sources, %I.civil_import_requests, %I.civil_import_worker_heartbeats, '
        '%I.civil_import_audit_events FROM famboook_civil_control', app_schema, app_schema, app_schema, app_schema);
    EXECUTE format('GRANT SELECT, INSERT, UPDATE ON %I.civil_import_sources TO famboook_civil_control', app_schema);
    EXECUTE format('GRANT SELECT ON %I.civil_import_requests TO famboook_civil_control', app_schema);
    EXECUTE format('GRANT UPDATE (status, attempts, worker_instance, run_id, release_id, reason_code, message, result, '
        'started_at, heartbeat_at, finished_at) ON %I.civil_import_requests TO famboook_civil_control', app_schema);
    EXECUTE format('GRANT SELECT, INSERT, UPDATE ON %I.civil_import_worker_heartbeats TO famboook_civil_control', app_schema);
    EXECUTE format('GRANT INSERT ON %I.civil_import_audit_events TO famboook_civil_control', app_schema);
    EXECUTE format('GRANT SELECT (id) ON %I.civil_import_audit_events TO famboook_civil_control', app_schema);
    seq := pg_get_serial_sequence(format('%I.civil_import_audit_events', app_schema), 'id');
    IF seq IS NOT NULL THEN
        EXECUTE format('GRANT USAGE ON SEQUENCE %s TO famboook_civil_control', seq);
    END IF;
END
$control$;
