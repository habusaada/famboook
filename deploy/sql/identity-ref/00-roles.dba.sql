-- FB-CIVIL-01A-P1 — identity reference roles (DBA script, REVIEW BEFORE USE).
--
-- Run ONCE per database by a PostgreSQL administrator (superuser or a role
-- with CREATEROLE), connected to the TARGET database. Pure SQL: runs with
-- psql (-v ON_ERROR_STOP=1 -f) or a single PDO exec. Idempotent.
--
-- Roles (least privilege; none is a superuser):
--   famboook_identity_owner   NOLOGIN  owns schema identity_ref and its objects;
--                                      used only through SET ROLE by the DBA to
--                                      apply 01-schema.sql
--   famboook_identity_writer  NOLOGIN  the dedicated import path (P2). It gets no
--                                      DDL, no ownership and no UPDATE/DELETE on
--                                      reference data. To enable it, the DBA sets a
--                                      password interactively — never in Git:
--                                        ALTER ROLE famboook_identity_writer LOGIN PASSWORD '<interactive>';
--   famboook_identity_reader  NOLOGIN  reserved for a future, separately approved
--                                      phase (FB-CIVIL-01B); granted NOTHING in P1
--
-- The existing application role (famboook_app) is not changed here and gets no
-- privilege on identity_ref (01-schema.sql revokes PUBLIC and any explicit grant).
--
-- NOT for Production without an explicit, recorded approval.

DO $roles$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'famboook_identity_owner') THEN
        CREATE ROLE famboook_identity_owner NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'famboook_identity_writer') THEN
        CREATE ROLE famboook_identity_writer NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'famboook_identity_reader') THEN
        CREATE ROLE famboook_identity_reader NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
    END IF;

    -- Re-assert the attributes in case a role pre-existed with broader ones.
    ALTER ROLE famboook_identity_owner  NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
    ALTER ROLE famboook_identity_writer NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
    ALTER ROLE famboook_identity_reader NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;

    -- Database-level privileges for THIS database only.
    EXECUTE format('GRANT CONNECT, CREATE ON DATABASE %I TO famboook_identity_owner', current_database());
    EXECUTE format('GRANT CONNECT ON DATABASE %I TO famboook_identity_writer', current_database());
    -- The writer gets no CREATE on the database. (TEMPORARY is granted to PUBLIC by
    -- default in PostgreSQL; staging needs for P2 are an open item — P1-OI-04.)
END
$roles$;
