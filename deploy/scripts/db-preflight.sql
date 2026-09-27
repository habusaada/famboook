-- Famboook Pilot — PostgreSQL preflight (docs/08 §5-§6, §11).
-- Run by the database ADMINISTRATOR (e.g. `sudo -u postgres psql -f db-preflight.sql`)
-- AFTER creating the role and database outside source control:
--
--   CREATE ROLE famboook_app LOGIN PASSWORD '<entered interactively, never committed>'
--       NOSUPERUSER NOCREATEDB NOCREATEROLE;
--   CREATE DATABASE famboook_pilot OWNER famboook_app ENCODING 'UTF8' TEMPLATE template0;
--
-- (Prefer `\password famboook_app` in psql so the password never reaches shell history.)

\connect famboook_pilot

-- 1. Server version: 16 or newer is required.
SELECT current_setting('server_version_num')::int >= 160000 AS postgres_16_or_newer,
       version();

-- 2. pg_trgm must be available (package postgresql-contrib).
SELECT name, default_version, installed_version
FROM pg_available_extensions WHERE name = 'pg_trgm';

-- 3. Install it now as administrator so the application role never needs
--    extension privileges. The migration's CREATE EXTENSION IF NOT EXISTS
--    then becomes a no-op.
CREATE EXTENSION IF NOT EXISTS pg_trgm;
SELECT extname, extversion FROM pg_extension WHERE extname = 'pg_trgm';

-- 4. The application role must not be a superuser.
SELECT rolname, rolsuper, rolcreaterole, rolcreatedb
FROM pg_roles WHERE rolname = 'famboook_app';

-- 5. The database must be new and empty before the first migration.
SELECT count(*) AS existing_tables
FROM information_schema.tables WHERE table_schema = 'public';

-- 6. Listening addresses: expect localhost / private addresses only.
SHOW listen_addresses;
