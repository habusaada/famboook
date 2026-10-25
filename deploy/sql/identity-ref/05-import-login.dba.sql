-- FB-CIVIL-01A-P4 — the dedicated import login and its server-log settings (REVIEW BEFORE USE).
--
-- Run by the database ADMINISTRATOR (superuser: the log_* settings below can
-- only be set by one), after 00-roles.dba.sql. Pure SQL, idempotent.
--
-- famboook_identity_import is the login the operator uses for
-- `php artisan famboook:identity-ref-stage`: NOINHERIT, member of
-- famboook_identity_writer only (the command runs SET ROLE itself), never a
-- superuser, never the application role. It is created NOLOGIN; the DBA
-- enables it for the import window and sets the password interactively:
--     ALTER ROLE famboook_identity_import LOGIN;
--     \password famboook_identity_import
-- and disables it again afterwards:
--     ALTER ROLE famboook_identity_import NOLOGIN;
--
-- Server log (P2-OI-02): PostgreSQL error reports can contain record values
-- ("Failing row contains …", "Key (…)=(…)", and COPY's CONTEXT line with the
-- whole source row), and log_statement / log_min_error_statement would log
-- statements. For THIS login only, error and statement logging is switched
-- off; the application logs a redacted form of every error instead
-- (IdentityRefErrorRedactor). Connections and disconnections may still be
-- logged (role name only). Applies to sessions opened after this script.

DO $login$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'famboook_identity_writer') THEN
        RAISE EXCEPTION 'IDENTITY_REF_RUN_00_ROLES_FIRST';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'famboook_identity_import') THEN
        CREATE ROLE famboook_identity_import NOLOGIN NOINHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
    END IF;
    ALTER ROLE famboook_identity_import NOINHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
    GRANT famboook_identity_writer TO famboook_identity_import;
    EXECUTE format('GRANT CONNECT ON DATABASE %I TO famboook_identity_import', current_database());

    ALTER ROLE famboook_identity_import SET log_min_messages = 'panic';
    ALTER ROLE famboook_identity_import SET log_min_error_statement = 'panic';
    ALTER ROLE famboook_identity_import SET log_statement = 'none';
    ALTER ROLE famboook_identity_import SET log_min_duration_statement = -1;
    ALTER ROLE famboook_identity_import SET log_min_duration_sample = -1;
    ALTER ROLE famboook_identity_import SET log_parameter_max_length_on_error = 0;
    ALTER ROLE famboook_identity_import SET log_parameter_max_length = 0;
END
$login$;
