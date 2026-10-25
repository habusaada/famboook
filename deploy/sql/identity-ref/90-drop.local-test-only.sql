-- FB-CIVIL-01A-P1/P2 — remove schemas identity_ref_staging and identity_ref from a DISPOSABLE TEST database only.
--
-- Refuses unless the current database name ends in "_test" AND the server port
-- is not 5432 (the default development / Production port). Never part of any
-- deployment. Roles are cluster-wide and are NOT dropped here.

DO $drop$
BEGIN
    IF current_database() !~ '_test$' OR inet_server_port() = 5432 THEN
        RAISE EXCEPTION 'IDENTITY_REF_DROP_REFUSED_NOT_A_DISPOSABLE_TEST_DATABASE';
    END IF;
    -- Triggers forbid TRUNCATE/DELETE, not DROP; the owner (or a superuser) drops the schema.
    EXECUTE 'DROP SCHEMA IF EXISTS identity_ref_staging CASCADE';
    EXECUTE 'DROP SCHEMA IF EXISTS identity_ref CASCADE';
END
$drop$;
