-- FB-CIVIL-01A-P1 — identity reference schema (REVIEW BEFORE USE).
--
-- Apply AS THE OWNER ROLE, after 00-roles.dba.sql:
--     SET ROLE famboook_identity_owner;   -- by the DBA
--     \i 01-schema.sql                    -- or one PDO exec of this file
--     RESET ROLE;
-- It refuses to run as any other role, so every object is owned by
-- famboook_identity_owner — never by the application role (an owner bypasses
-- grants and could disable triggers). Deliberately NOT a Laravel migration:
-- deploy-backend.sh runs `migrate` as the application role.
-- PostgreSQL 16+ compatible. Pure SQL (no psql meta-commands).
--
-- Design (docs/audits/FB-CIVIL-01A-P1-DATABASE-IMPLEMENTATION.md):
--   records            identity anchor, one row per mainid (TEXT) — insert-only
--   record_versions    immutable source versions (36 raw fields as jsonb) — insert-only
--   dataset_releases   BUILDING -> PUBLISHED | ABANDONED, then immutable
--   release_presence   immutable (release, record) -> version set of a release;
--                      missing identities are CARRIED_FORWARD, never dropped
--   active_release     single-row pointer, switched only by publish_release()
--   import_runs / import_files / import_issues   run metadata, sanitized issues
-- Reads always go through the active release, so a reader sees one complete
-- release (MVCC), never a partially built one.

DO $guard$
BEGIN
    IF current_user <> 'famboook_identity_owner' THEN
        RAISE EXCEPTION 'IDENTITY_REF_SCHEMA_MUST_BE_APPLIED_AS_OWNER';
    END IF;
END
$guard$;

CREATE SCHEMA identity_ref;
REVOKE ALL ON SCHEMA identity_ref FROM PUBLIC;
ALTER DEFAULT PRIVILEGES IN SCHEMA identity_ref REVOKE ALL ON TABLES FROM PUBLIC;
ALTER DEFAULT PRIVILEGES IN SCHEMA identity_ref REVOKE ALL ON SEQUENCES FROM PUBLIC;
ALTER DEFAULT PRIVILEGES IN SCHEMA identity_ref REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC;

-- ---------------------------------------------------------------- source columns and payload validation

CREATE FUNCTION identity_ref.source_columns() RETURNS text[]
    LANGUAGE sql IMMUTABLE PARALLEL SAFE
    SET search_path = pg_catalog, pg_temp
AS $$
    SELECT ARRAY['sert1','mainid','name1','father','grandfath','family','gender','govern','city','street',
                 'fatherid','motherid','partnerid','datebirth','age','maritalst','deathdate','presonstate',
                 'relgion','birthplace','householdid','householdrel','prevfamily','phonenum','worker','att',
                 'worktype','source','civilg','cwloc','mgrade','mstartw','msalary','select','job','fullname']::text[]
$$;

-- A raw source payload: a JSON object with EXACTLY the 36 source keys, each value a
-- string or JSON null. Values are never trimmed, normalized or interpreted, so
-- leading zeros, unknown codes and NULL vs '' are preserved as given.
CREATE FUNCTION identity_ref.is_valid_source_payload(p jsonb) RETURNS boolean
    LANGUAGE sql IMMUTABLE PARALLEL SAFE
    SET search_path = pg_catalog, pg_temp
AS $$
    SELECT jsonb_typeof(p) = 'object'
       AND (SELECT array_agg(k ORDER BY k) FROM jsonb_object_keys(p) AS k)
           = (SELECT array_agg(c ORDER BY c) FROM unnest(identity_ref.source_columns()) AS c)
       AND NOT EXISTS (SELECT 1 FROM jsonb_each(p) AS e WHERE jsonb_typeof(e.value) NOT IN ('string', 'null'))
$$;

-- ---------------------------------------------------------------- run metadata (sanitized: no free text, no values)

CREATE TABLE identity_ref.import_runs (
    id              uuid        PRIMARY KEY DEFAULT gen_random_uuid(),
    status          text        NOT NULL DEFAULT 'RUNNING'
                                CHECK (status IN ('RUNNING', 'SUCCEEDED', 'FAILED', 'REJECTED', 'NOTHING_TO_DO')),
    dry_run         boolean     NOT NULL DEFAULT false,
    manifest_sha256 text        NOT NULL CHECK (manifest_sha256 ~ '^[0-9a-f]{64}$'),
    source_table    text        NOT NULL CHECK (source_table ~ '^[A-Za-z0-9_]{1,64}$'),
    expected_rows   bigint      NOT NULL CHECK (expected_rows >= 0),
    started_at      timestamptz NOT NULL DEFAULT now(),
    finished_at     timestamptz,
    error_code      text        CHECK (error_code ~ '^[A-Z][A-Z0-9_]{0,63}$'),
    rows_loaded     bigint      NOT NULL DEFAULT 0 CHECK (rows_loaded >= 0),
    new_records     bigint      NOT NULL DEFAULT 0 CHECK (new_records >= 0),
    new_versions    bigint      NOT NULL DEFAULT 0 CHECK (new_versions >= 0),
    unchanged       bigint      NOT NULL DEFAULT 0 CHECK (unchanged >= 0),
    carried_forward bigint      NOT NULL DEFAULT 0 CHECK (carried_forward >= 0),
    issues          bigint      NOT NULL DEFAULT 0 CHECK (issues >= 0),
    CHECK ((status = 'RUNNING') = (finished_at IS NULL))
);
CREATE UNIQUE INDEX import_runs_single_running ON identity_ref.import_runs ((true)) WHERE status = 'RUNNING';

CREATE TABLE identity_ref.import_files (
    id              bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    run_id          uuid        NOT NULL REFERENCES identity_ref.import_runs (id) ON DELETE RESTRICT,
    file_name       text        NOT NULL CHECK (file_name ~ '^[A-Za-z0-9._-]{1,128}$'),
    expected_sha256 text        NOT NULL CHECK (expected_sha256 ~ '^[0-9a-f]{64}$'),
    actual_sha256   text        CHECK (actual_sha256 ~ '^[0-9a-f]{64}$'),
    expected_rows   bigint      NOT NULL CHECK (expected_rows >= 0),
    loaded_rows     bigint      NOT NULL DEFAULT 0 CHECK (loaded_rows >= 0),
    status          text        NOT NULL DEFAULT 'PENDING' CHECK (status IN ('PENDING', 'VERIFIED', 'STAGED', 'FAILED')),
    UNIQUE (run_id, file_name),
    -- NULL-safe (P2 finding): a NULL comparison would let a CHECK pass.
    CHECK (status NOT IN ('VERIFIED', 'STAGED') OR (actual_sha256 IS NOT NULL AND actual_sha256 = expected_sha256)),
    CHECK (status <> 'STAGED' OR loaded_rows = expected_rows)
);

CREATE TABLE identity_ref.import_issues (
    id              bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    run_id          uuid        NOT NULL REFERENCES identity_ref.import_runs (id) ON DELETE RESTRICT,
    file_name       text        CHECK (file_name ~ '^[A-Za-z0-9._-]{1,128}$'),
    line_number     bigint      CHECK (line_number > 0),
    code            text        NOT NULL CHECK (code ~ '^[A-Z][A-Z0-9_]{0,63}$'),
    column_name     text        CHECK (column_name IS NULL OR column_name = ANY (identity_ref.source_columns())),
    key_fingerprint bytea       CHECK (key_fingerprint IS NULL OR octet_length(key_fingerprint) = 32),
    created_at      timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX import_issues_run ON identity_ref.import_issues (run_id, code);

-- ---------------------------------------------------------------- reference data (insert-only)

CREATE TABLE identity_ref.records (
    id                bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    mainid            text        NOT NULL UNIQUE
                                  CHECK (mainid <> '' AND length(mainid) <= 64 AND mainid !~ '^\s' AND mainid !~ '\s$'),
    first_seen_run_id uuid        NOT NULL REFERENCES identity_ref.import_runs (id) ON DELETE RESTRICT,
    created_at        timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE identity_ref.record_versions (
    id              bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    record_id       bigint      NOT NULL REFERENCES identity_ref.records (id) ON DELETE RESTRICT,
    version_no      integer     NOT NULL CHECK (version_no > 0),
    content_sha256  bytea       NOT NULL CHECK (octet_length(content_sha256) = 32),
    source_payload  jsonb       NOT NULL CHECK (identity_ref.is_valid_source_payload(source_payload)),
    created_run_id  uuid        NOT NULL REFERENCES identity_ref.import_runs (id) ON DELETE RESTRICT,
    created_at      timestamptz NOT NULL DEFAULT now(),
    UNIQUE (record_id, version_no),
    UNIQUE (record_id, content_sha256),
    UNIQUE (id, record_id)
);

CREATE TABLE identity_ref.dataset_releases (
    id              bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    run_id          uuid        NOT NULL UNIQUE REFERENCES identity_ref.import_runs (id) ON DELETE RESTRICT,
    base_release_id bigint      REFERENCES identity_ref.dataset_releases (id) ON DELETE RESTRICT,
    status          text        NOT NULL DEFAULT 'BUILDING' CHECK (status IN ('BUILDING', 'PUBLISHED', 'ABANDONED')),
    created_at      timestamptz NOT NULL DEFAULT now(),
    published_at    timestamptz,
    abandoned_at    timestamptz,
    record_count    bigint      CHECK (record_count >= 0),
    CHECK ((status = 'PUBLISHED') = (published_at IS NOT NULL)),
    CHECK ((status = 'ABANDONED') = (abandoned_at IS NOT NULL)),
    CHECK (status <> 'PUBLISHED' OR record_count IS NOT NULL)
);
CREATE UNIQUE INDEX dataset_releases_single_building ON identity_ref.dataset_releases ((true)) WHERE status = 'BUILDING';

CREATE TABLE identity_ref.release_presence (
    release_id        bigint NOT NULL REFERENCES identity_ref.dataset_releases (id) ON DELETE RESTRICT,
    record_id         bigint NOT NULL REFERENCES identity_ref.records (id) ON DELETE RESTRICT,
    record_version_id bigint NOT NULL,
    source_state      text   NOT NULL CHECK (source_state IN ('PRESENT', 'CARRIED_FORWARD')),
    PRIMARY KEY (release_id, record_id),                                   -- one version per identity per release
    FOREIGN KEY (record_version_id, record_id)
        REFERENCES identity_ref.record_versions (id, record_id) ON DELETE RESTRICT   -- version belongs to the record
);

CREATE TABLE identity_ref.active_release (
    id           smallint    PRIMARY KEY CHECK (id = 1),
    release_id   bigint      NOT NULL REFERENCES identity_ref.dataset_releases (id) ON DELETE RESTRICT,
    activated_at timestamptz NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------- integrity triggers (fire for every role)

CREATE FUNCTION identity_ref.forbid_change() RETURNS trigger
    LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp
AS $$
BEGIN
    RAISE EXCEPTION 'IDENTITY_REF_IMMUTABLE: % on %.% is not allowed', TG_OP, TG_TABLE_SCHEMA, TG_TABLE_NAME
        USING ERRCODE = 'insufficient_privilege';
END
$$;

-- records, record_versions, release_presence, import_issues: insert-only.
CREATE TRIGGER records_immutable BEFORE UPDATE OR DELETE ON identity_ref.records
    FOR EACH ROW EXECUTE FUNCTION identity_ref.forbid_change();
CREATE TRIGGER records_no_truncate BEFORE TRUNCATE ON identity_ref.records
    FOR EACH STATEMENT EXECUTE FUNCTION identity_ref.forbid_change();
CREATE TRIGGER record_versions_immutable BEFORE UPDATE OR DELETE ON identity_ref.record_versions
    FOR EACH ROW EXECUTE FUNCTION identity_ref.forbid_change();
CREATE TRIGGER record_versions_no_truncate BEFORE TRUNCATE ON identity_ref.record_versions
    FOR EACH STATEMENT EXECUTE FUNCTION identity_ref.forbid_change();
CREATE TRIGGER release_presence_immutable BEFORE UPDATE OR DELETE ON identity_ref.release_presence
    FOR EACH ROW EXECUTE FUNCTION identity_ref.forbid_change();
CREATE TRIGGER release_presence_no_truncate BEFORE TRUNCATE ON identity_ref.release_presence
    FOR EACH STATEMENT EXECUTE FUNCTION identity_ref.forbid_change();
CREATE TRIGGER import_issues_immutable BEFORE UPDATE OR DELETE ON identity_ref.import_issues
    FOR EACH ROW EXECUTE FUNCTION identity_ref.forbid_change();
CREATE TRIGGER import_issues_no_truncate BEFORE TRUNCATE ON identity_ref.import_issues
    FOR EACH STATEMENT EXECUTE FUNCTION identity_ref.forbid_change();
CREATE TRIGGER dataset_releases_no_delete BEFORE DELETE ON identity_ref.dataset_releases
    FOR EACH ROW EXECUTE FUNCTION identity_ref.forbid_change();
CREATE TRIGGER dataset_releases_no_truncate BEFORE TRUNCATE ON identity_ref.dataset_releases
    FOR EACH STATEMENT EXECUTE FUNCTION identity_ref.forbid_change();
CREATE TRIGGER active_release_no_delete BEFORE DELETE ON identity_ref.active_release
    FOR EACH ROW EXECUTE FUNCTION identity_ref.forbid_change();
CREATE TRIGGER active_release_no_truncate BEFORE TRUNCATE ON identity_ref.active_release
    FOR EACH STATEMENT EXECUTE FUNCTION identity_ref.forbid_change();
CREATE TRIGGER import_runs_no_delete BEFORE DELETE ON identity_ref.import_runs
    FOR EACH ROW EXECUTE FUNCTION identity_ref.forbid_change();
CREATE TRIGGER import_runs_no_truncate BEFORE TRUNCATE ON identity_ref.import_runs
    FOR EACH STATEMENT EXECUTE FUNCTION identity_ref.forbid_change();
CREATE TRIGGER import_files_no_delete BEFORE DELETE ON identity_ref.import_files
    FOR EACH ROW EXECUTE FUNCTION identity_ref.forbid_change();
CREATE TRIGGER import_files_no_truncate BEFORE TRUNCATE ON identity_ref.import_files
    FOR EACH STATEMENT EXECUTE FUNCTION identity_ref.forbid_change();

-- Versions: deterministic version_no, content hash computed by the database, and
-- the payload's mainid must equal the anchor's (no silent re-keying).
CREATE FUNCTION identity_ref.prepare_version() RETURNS trigger
    LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp
AS $$
DECLARE
    anchor text;
BEGIN
    SELECT r.mainid INTO anchor FROM identity_ref.records r WHERE r.id = NEW.record_id;
    IF anchor IS NULL OR NOT identity_ref.is_valid_source_payload(NEW.source_payload)
       OR NEW.source_payload ->> 'mainid' IS DISTINCT FROM anchor THEN
        RAISE EXCEPTION 'IDENTITY_REF_VERSION_REJECTED' USING ERRCODE = 'check_violation';
    END IF;
    NEW.content_sha256 := sha256(convert_to(NEW.source_payload::text, 'UTF8'));
    NEW.version_no := COALESCE((SELECT max(v.version_no) FROM identity_ref.record_versions v
                                WHERE v.record_id = NEW.record_id), 0) + 1;
    RETURN NEW;
END
$$;
CREATE TRIGGER record_versions_prepare BEFORE INSERT ON identity_ref.record_versions
    FOR EACH ROW EXECUTE FUNCTION identity_ref.prepare_version();

-- Presence rows only while their release is BUILDING.
CREATE FUNCTION identity_ref.check_presence_insert() RETURNS trigger
    LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp
AS $$
BEGIN
    IF (SELECT d.status FROM identity_ref.dataset_releases d WHERE d.id = NEW.release_id) IS DISTINCT FROM 'BUILDING' THEN
        RAISE EXCEPTION 'IDENTITY_REF_RELEASE_NOT_BUILDING' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END
$$;
CREATE TRIGGER release_presence_building_only BEFORE INSERT ON identity_ref.release_presence
    FOR EACH ROW EXECUTE FUNCTION identity_ref.check_presence_insert();

-- Releases: created BUILDING; only BUILDING -> PUBLISHED | ABANDONED; then immutable.
CREATE FUNCTION identity_ref.check_release_change() RETURNS trigger
    LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp
AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.status <> 'BUILDING' THEN
            RAISE EXCEPTION 'IDENTITY_REF_RELEASE_MUST_START_BUILDING' USING ERRCODE = 'check_violation';
        END IF;
        RETURN NEW;
    END IF;
    IF OLD.status <> 'BUILDING' OR NEW.status NOT IN ('PUBLISHED', 'ABANDONED')
       OR NEW.id <> OLD.id OR NEW.run_id <> OLD.run_id
       OR NEW.base_release_id IS DISTINCT FROM OLD.base_release_id OR NEW.created_at <> OLD.created_at THEN
        RAISE EXCEPTION 'IDENTITY_REF_IMMUTABLE: release transition not allowed' USING ERRCODE = 'insufficient_privilege';
    END IF;
    RETURN NEW;
END
$$;
CREATE TRIGGER dataset_releases_transition BEFORE INSERT OR UPDATE ON identity_ref.dataset_releases
    FOR EACH ROW EXECUTE FUNCTION identity_ref.check_release_change();

-- The pointer only ever names a PUBLISHED release.
CREATE FUNCTION identity_ref.check_active_release() RETURNS trigger
    LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp
AS $$
BEGIN
    IF (SELECT d.status FROM identity_ref.dataset_releases d WHERE d.id = NEW.release_id) IS DISTINCT FROM 'PUBLISHED' THEN
        RAISE EXCEPTION 'IDENTITY_REF_ACTIVE_RELEASE_NOT_PUBLISHED' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END
$$;
CREATE TRIGGER active_release_published_only BEFORE INSERT OR UPDATE ON identity_ref.active_release
    FOR EACH ROW EXECUTE FUNCTION identity_ref.check_active_release();

-- Runs and files: RUNNING -> terminal once; identity fields fixed; terminal rows frozen.
CREATE FUNCTION identity_ref.check_run_change() RETURNS trigger
    LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp
AS $$
BEGIN
    IF OLD.status <> 'RUNNING' OR NEW.id <> OLD.id OR NEW.manifest_sha256 <> OLD.manifest_sha256
       OR NEW.source_table <> OLD.source_table OR NEW.started_at <> OLD.started_at OR NEW.dry_run <> OLD.dry_run
       OR NEW.expected_rows <> OLD.expected_rows THEN
        RAISE EXCEPTION 'IDENTITY_REF_IMMUTABLE: run is finished or field is fixed' USING ERRCODE = 'insufficient_privilege';
    END IF;
    RETURN NEW;
END
$$;
CREATE TRIGGER import_runs_transition BEFORE UPDATE ON identity_ref.import_runs
    FOR EACH ROW EXECUTE FUNCTION identity_ref.check_run_change();

CREATE FUNCTION identity_ref.check_file_change() RETURNS trigger
    LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp
AS $$
BEGIN
    IF (SELECT r.status FROM identity_ref.import_runs r WHERE r.id = OLD.run_id) <> 'RUNNING'
       OR NEW.id <> OLD.id OR NEW.run_id <> OLD.run_id OR NEW.file_name <> OLD.file_name
       OR NEW.expected_sha256 <> OLD.expected_sha256 OR NEW.expected_rows <> OLD.expected_rows THEN
        RAISE EXCEPTION 'IDENTITY_REF_IMMUTABLE: file row is fixed' USING ERRCODE = 'insufficient_privilege';
    END IF;
    RETURN NEW;
END
$$;
CREATE TRIGGER import_files_transition BEFORE UPDATE ON identity_ref.import_files
    FOR EACH ROW EXECUTE FUNCTION identity_ref.check_file_change();

-- ---------------------------------------------------------------- controlled publication (SECURITY DEFINER, pinned search_path)

-- Copies the base release's identities that are absent from this release, with
-- their current version, as CARRIED_FORWARD: a missing identity is never dropped.
CREATE FUNCTION identity_ref.carry_forward_missing(p_release bigint) RETURNS bigint
    LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, pg_temp
AS $$
DECLARE
    base bigint;
    copied bigint;
BEGIN
    SELECT d.base_release_id INTO base FROM identity_ref.dataset_releases d WHERE d.id = p_release AND d.status = 'BUILDING';
    IF NOT FOUND THEN
        RAISE EXCEPTION 'IDENTITY_REF_RELEASE_NOT_BUILDING' USING ERRCODE = 'check_violation';
    END IF;
    IF base IS NULL THEN
        RETURN 0;
    END IF;
    INSERT INTO identity_ref.release_presence (release_id, record_id, record_version_id, source_state)
    SELECT p_release, b.record_id, b.record_version_id, 'CARRIED_FORWARD'
      FROM identity_ref.release_presence b
     WHERE b.release_id = base
       AND NOT EXISTS (SELECT 1 FROM identity_ref.release_presence n WHERE n.release_id = p_release AND n.record_id = b.record_id);
    GET DIAGNOSTICS copied = ROW_COUNT;
    RETURN copied;
END
$$;

-- Atomic publication: one transaction, one global publication lock (fails fast,
-- never waits), the release must be BUILDING, built on the CURRENT active release
-- (no lost update between concurrent builders), non-empty, and must contain every
-- identity of its base. Then the release is frozen and the pointer switched.
CREATE FUNCTION identity_ref.publish_release(p_release bigint) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp
AS $$
DECLARE
    rel        identity_ref.dataset_releases%ROWTYPE;
    current_id bigint;
    total      bigint;
BEGIN
    IF NOT pg_try_advisory_xact_lock(x'4642434956303141'::bigint) THEN      -- 'FBCIV01A'
        RAISE EXCEPTION 'IDENTITY_REF_PUBLICATION_IN_PROGRESS' USING ERRCODE = 'lock_not_available';
    END IF;
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

-- A BUILDING release that will not be published (failed or interrupted run). Its
-- immutable presence rows stay, but it can never become active.
CREATE FUNCTION identity_ref.abandon_release(p_release bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp
AS $$
BEGIN
    UPDATE identity_ref.dataset_releases SET status = 'ABANDONED', abandoned_at = now()
     WHERE id = p_release AND status = 'BUILDING';
    IF NOT FOUND THEN
        RAISE EXCEPTION 'IDENTITY_REF_RELEASE_NOT_BUILDING' USING ERRCODE = 'check_violation';
    END IF;
END
$$;

-- The published dataset (future consumers; NO grant in P1).
CREATE VIEW identity_ref.active_records AS
SELECT a.release_id, r.id AS record_id, r.mainid, v.version_no, v.source_payload, p.source_state
  FROM identity_ref.active_release a
  JOIN identity_ref.release_presence p ON p.release_id = a.release_id
  JOIN identity_ref.records r          ON r.id = p.record_id
  JOIN identity_ref.record_versions v  ON v.id = p.record_version_id
 WHERE a.id = 1;

-- ---------------------------------------------------------------- privileges (least privilege)

REVOKE ALL ON ALL TABLES    IN SCHEMA identity_ref FROM PUBLIC;
REVOKE ALL ON ALL SEQUENCES IN SCHEMA identity_ref FROM PUBLIC;
REVOKE ALL ON ALL FUNCTIONS IN SCHEMA identity_ref FROM PUBLIC;

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

-- The application role and the reserved reader get nothing in P1.
DO $app$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'famboook_app') THEN
        REVOKE ALL ON SCHEMA identity_ref FROM famboook_app;
        REVOKE ALL ON ALL TABLES IN SCHEMA identity_ref FROM famboook_app;
        REVOKE ALL ON ALL FUNCTIONS IN SCHEMA identity_ref FROM famboook_app;
    END IF;
END
$app$;
