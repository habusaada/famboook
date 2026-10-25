-- FB-CIVIL-01A-P3 — validation, comparison, preparation and the publication gate (REVIEW BEFORE USE).
--
-- Apply AS THE OWNER ROLE, after 02-staging.sql:
--     SET ROLE famboook_identity_owner;
--     \i 03-publication.sql
--     RESET ROLE;
-- Pure SQL, PostgreSQL 16+. Extends P1/P2; changes none of their objects.
--
-- The database — not the application — decides what may become active:
--   * identity_ref_staging.assess_run() is THE classification (comparison
--     policy IDREF-CMP-1): every staged row is NEW, CHANGED, UNCHANGED or
--     CONFLICT against the release it is built on. Preview, preparation and
--     verification all use this one function.
--   * begin_preparation() creates the BUILDING release, records the policy
--     and the counts, and FAILS CLOSED when the policy is unresolved for
--     what the data contains (quarantined rows, blank-over-value changes).
--   * verify_release() recomputes the classification independently and
--     proves the prepared release equals it exactly.
--   * A trigger refuses PUBLISHED for any release that is not VERIFIED — so
--     even a direct publish_release() call cannot activate an unvalidated
--     release.
-- The import writer cannot write release_preparations or assessments
-- directly: only through these SECURITY DEFINER functions, which pin their
-- search_path and re-check every precondition.

DO $guard$
BEGIN
    IF current_user <> 'famboook_identity_owner' THEN
        RAISE EXCEPTION 'IDENTITY_REF_SCHEMA_MUST_BE_APPLIED_AS_OWNER';
    END IF;
    IF to_regclass('identity_ref_staging.rows') IS NULL THEN
        RAISE EXCEPTION 'IDENTITY_REF_PUBLICATION_NEEDS_02_STAGING';
    END IF;
END
$guard$;

-- ---------------------------------------------------------------- preparation record (one per release)

CREATE TABLE identity_ref.release_preparations (
    release_id             bigint      PRIMARY KEY REFERENCES identity_ref.dataset_releases (id) ON DELETE RESTRICT,
    run_id                 uuid        NOT NULL UNIQUE REFERENCES identity_ref.import_runs (id) ON DELETE RESTRICT,
    status                 text        NOT NULL DEFAULT 'PREPARING' CHECK (status IN ('PREPARING', 'PREPARED', 'VERIFIED')),
    policy_id              text        NOT NULL CHECK (policy_id ~ '^[A-Z][A-Z0-9_-]{0,63}$'),
    policy_fingerprint     text        NOT NULL CHECK (policy_fingerprint ~ '^[0-9a-f]{64}$'),
    comparison_policy      text        NOT NULL CHECK (comparison_policy IN ('IDREF-CMP-1')),
    blank_field_policy     text        NOT NULL CHECK (blank_field_policy IN ('UNRESOLVED', 'ACCEPT_SOURCE', 'QUARANTINE_ROW', 'PRESERVE_EXISTING_ON_BLANK')),
    quarantine_max_rows    bigint      CHECK (quarantine_max_rows >= 0),                       -- NULL: UNRESOLVED (no row may be quarantined)
    quarantine_max_ratio   numeric     CHECK (quarantine_max_ratio >= 0 AND quarantine_max_ratio <= 1),
    staged_rows            bigint      NOT NULL CHECK (staged_rows >= 0),
    new_count              bigint      NOT NULL CHECK (new_count >= 0),
    changed_count          bigint      NOT NULL CHECK (changed_count >= 0),
    unchanged_count        bigint      NOT NULL CHECK (unchanged_count >= 0),
    conflict_count         bigint      NOT NULL CHECK (conflict_count >= 0),
    carried_forward_count  bigint      NOT NULL CHECK (carried_forward_count >= 0),
    blank_over_value_count bigint      NOT NULL CHECK (blank_over_value_count >= 0),
    expected_presence      bigint      NOT NULL CHECK (expected_presence >= 0),
    warnings               jsonb       NOT NULL CHECK (jsonb_typeof(warnings) = 'object'),
    created_at             timestamptz NOT NULL DEFAULT now(),
    prepared_at            timestamptz,
    verified_at            timestamptz,
    CHECK ((quarantine_max_rows IS NULL) = (quarantine_max_ratio IS NULL)),
    CHECK (new_count + changed_count + unchanged_count + conflict_count = staged_rows),
    CHECK (expected_presence = new_count + changed_count + unchanged_count + carried_forward_count),
    CHECK ((status IN ('PREPARED', 'VERIFIED')) = (prepared_at IS NOT NULL)),
    CHECK ((status = 'VERIFIED') = (verified_at IS NOT NULL))
);

-- PREPARING -> PREPARED -> VERIFIED; every other field is frozen at creation. No DELETE, no TRUNCATE.
CREATE FUNCTION identity_ref.check_preparation_change() RETURNS trigger
    LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp
AS $$
BEGIN
    IF NOT ((OLD.status = 'PREPARING' AND NEW.status = 'PREPARED') OR (OLD.status = 'PREPARED' AND NEW.status = 'VERIFIED'))
       OR (to_jsonb(NEW) - 'status' - 'prepared_at' - 'verified_at') IS DISTINCT FROM (to_jsonb(OLD) - 'status' - 'prepared_at' - 'verified_at')
       OR (OLD.prepared_at IS NOT NULL AND NEW.prepared_at IS DISTINCT FROM OLD.prepared_at) THEN
        RAISE EXCEPTION 'IDENTITY_REF_IMMUTABLE: preparation transition not allowed' USING ERRCODE = 'insufficient_privilege';
    END IF;
    RETURN NEW;
END
$$;
CREATE TRIGGER release_preparations_transition BEFORE UPDATE ON identity_ref.release_preparations
    FOR EACH ROW EXECUTE FUNCTION identity_ref.check_preparation_change();
CREATE TRIGGER release_preparations_no_delete BEFORE DELETE ON identity_ref.release_preparations
    FOR EACH ROW EXECUTE FUNCTION identity_ref.forbid_change();
CREATE TRIGGER release_preparations_no_truncate BEFORE TRUNCATE ON identity_ref.release_preparations
    FOR EACH STATEMENT EXECUTE FUNCTION identity_ref.forbid_change();

-- ---------------------------------------------------------------- per-row assessment (work list of a preparation)

CREATE UNLOGGED TABLE identity_ref_staging.assessments (
    release_id       bigint  NOT NULL REFERENCES identity_ref.dataset_releases (id) ON DELETE RESTRICT,
    file_id          bigint  NOT NULL,
    record_no        bigint  NOT NULL,
    mainid           text,
    content_sha256   bytea   NOT NULL CHECK (octet_length(content_sha256) = 32),
    classification   text    NOT NULL CHECK (classification IN ('NEW', 'CHANGED', 'UNCHANGED', 'CONFLICT')),
    conflict_code    text    CHECK (conflict_code ~ '^[A-Z][A-Z0-9_]{0,63}$'),
    blank_over_value boolean NOT NULL,
    effective_payload jsonb  CHECK (effective_payload IS NULL OR identity_ref.is_valid_source_payload(effective_payload)),
    PRIMARY KEY (release_id, file_id, record_no),
    FOREIGN KEY (file_id, record_no) REFERENCES identity_ref_staging.rows (file_id, record_no) ON DELETE RESTRICT,
    CHECK ((classification = 'CONFLICT') = (conflict_code IS NOT NULL))
);
CREATE TRIGGER assessments_no_update BEFORE UPDATE ON identity_ref_staging.assessments
    FOR EACH ROW EXECUTE FUNCTION identity_ref.forbid_change();
CREATE TRIGGER assessments_no_truncate BEFORE TRUNCATE ON identity_ref_staging.assessments
    FOR EACH STATEMENT EXECUTE FUNCTION identity_ref.forbid_change();
CREATE TRIGGER assessments_delete AFTER DELETE ON identity_ref_staging.assessments
    REFERENCING OLD TABLE AS old_rows
    FOR EACH STATEMENT EXECUTE FUNCTION identity_ref_staging.check_delete();

-- ---------------------------------------------------------------- original incoming rows (D-01 PRESERVE_EXISTING_ON_BLANK)

-- When blanks were filled from the existing version, the published version is
-- the effective payload; the source row exactly as received is kept here, so
-- the original incoming data is never lost. Insert-only, immutable, only into
-- a BUILDING release (P1 check_presence_insert).
CREATE TABLE identity_ref.release_incoming (
    release_id     bigint      NOT NULL REFERENCES identity_ref.dataset_releases (id) ON DELETE RESTRICT,
    record_id      bigint      NOT NULL REFERENCES identity_ref.records (id) ON DELETE RESTRICT,
    source_payload jsonb       NOT NULL CHECK (identity_ref.is_valid_source_payload(source_payload)),
    created_at     timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (release_id, record_id)
);
CREATE TRIGGER release_incoming_building_only BEFORE INSERT ON identity_ref.release_incoming
    FOR EACH ROW EXECUTE FUNCTION identity_ref.check_presence_insert();
CREATE TRIGGER release_incoming_immutable BEFORE UPDATE OR DELETE ON identity_ref.release_incoming
    FOR EACH ROW EXECUTE FUNCTION identity_ref.forbid_change();
CREATE TRIGGER release_incoming_no_truncate BEFORE TRUNCATE ON identity_ref.release_incoming
    FOR EACH STATEMENT EXECUTE FUNCTION identity_ref.forbid_change();

-- ---------------------------------------------------------------- comparison policy IDREF-CMP-1

-- Classifies every staged row of a run against base release p_base (NULL = first release).
--   Identity key: mainid as opaque text (no cast, no padding, no 9-digit rule).
--   Canonical form: the stored jsonb of the 36 raw values (PostgreSQL's
--   deterministic jsonb text: keys ordered by length then bytes, ": " and
--   ", " separators), encoded UTF-8, hashed with SHA-256 — the same
--   content_sha256 the P1 trigger stores on record_versions. Values are
--   compared exactly: no trimming, NULL and "" are different values, dates
--   are opaque text.
--   CONFLICT (quarantine): missing key, duplicated key (every row of it),
--   key with surrounding whitespace or longer than 64 characters (refused by
--   identity_ref.records), a C0 control character in any value, and —
--   only under blank-field policy QUARANTINE_ROW — a blank-over-value change.
--   blank_over_value: a row of an existing identity where a non-blank base
--   value is blank (NULL or "") in the source. Its handling is the blank-field
--   policy (OD-04 / D-01). Under PRESERVE_EXISTING_ON_BLANK the comparison and
--   the published version use the EFFECTIVE payload (content_sha256 is its hash).
CREATE FUNCTION identity_ref_staging.assess_run(p_run uuid, p_base bigint, p_blank_policy text)
    RETURNS TABLE (file_id bigint, record_no bigint, mainid text, content_sha256 bytea, classification text,
                   conflict_code text, blank_over_value boolean, base_record_id bigint, effective_payload jsonb)
    LANGUAGE sql STABLE SET search_path = pg_catalog, pg_temp
    SET work_mem = '128MB'                 -- one pass over millions of rows: hash in memory, not in temp files
AS $$
    WITH s AS (
        SELECT r.file_id, r.record_no, r.mainid, r.source_payload AS payload,
               sha256(convert_to(r.source_payload::text, 'UTF8')) AS h
          FROM identity_ref_staging.rows r
          JOIN identity_ref.import_files f ON f.id = r.file_id
         WHERE f.run_id = p_run
    ), dup AS (
        SELECT s.mainid FROM s WHERE s.mainid IS NOT NULL AND s.mainid <> '' GROUP BY s.mainid HAVING count(*) > 1
    ), b AS (
        -- ids and hashes only: the base payload is fetched by version id, and only for changed rows
        -- (carrying millions of payloads through a hash join spills gigabytes of temporary files).
        SELECT p.record_id, rec.mainid, p.record_version_id AS version_id, v.content_sha256 AS h
          FROM identity_ref.release_presence p
          JOIN identity_ref.records rec ON rec.id = p.record_id
          JOIN identity_ref.record_versions v ON v.id = p.record_version_id
         WHERE p.release_id = p_base
    ), k AS (
        SELECT s.file_id, s.record_no, s.mainid, s.h, s.payload, b.record_id AS base_record_id, b.version_id AS base_version_id, b.h AS base_h,
               CASE
                   WHEN s.mainid IS NULL OR s.mainid = ''      THEN 'MAINID_MISSING'
                   WHEN s.mainid IN (SELECT dup.mainid FROM dup) THEN 'MAINID_DUPLICATE'
                   WHEN s.mainid ~ '^\s|\s$'                     THEN 'MAINID_SURROUNDING_SPACE'
                   WHEN length(s.mainid) > 64                    THEN 'MAINID_TOO_LONG'
                   WHEN EXISTS (SELECT 1 FROM jsonb_each_text(s.payload) e
                                 WHERE e.value ~ '[\x01-\x08\x0B\x0C\x0E-\x1F\x7F]') THEN 'VALUE_CONTROL_CHARACTER'
               END AS hard_conflict,
               (b.h IS NOT NULL AND b.h <> s.h AND EXISTS (
                    SELECT 1 FROM identity_ref.record_versions bv, jsonb_each_text(bv.source_payload) o
                     WHERE bv.id = b.version_id
                       AND coalesce(o.value, '') <> '' AND coalesce(s.payload ->> o.key, '') = '')) AS bov
          FROM s LEFT JOIN b ON b.mainid = s.mainid
    ), m AS (
        -- PRESERVE_EXISTING_ON_BLANK (D-01, approved): where the incoming value is blank (NULL or "")
        -- and the base value is not, the base value is kept. eff is that effective payload, or NULL
        -- when it equals the incoming row. The raw incoming row is preserved in release_incoming.
        SELECT k.*,
               CASE WHEN p_blank_policy = 'PRESERVE_EXISTING_ON_BLANK' AND k.bov AND k.hard_conflict IS NULL THEN
                   (SELECT jsonb_object_agg(e.key, CASE WHEN coalesce(e.value #>> '{}', '') = '' AND coalesce(bv.source_payload ->> e.key, '') <> ''
                                                        THEN bv.source_payload -> e.key ELSE e.value END)
                      FROM identity_ref.record_versions bv, jsonb_each(k.payload) e WHERE bv.id = k.base_version_id)
               END AS eff
          FROM k
    ), f AS (
        SELECT m.*, coalesce(sha256(convert_to(m.eff::text, 'UTF8')), m.h) AS eh FROM m
    )
    SELECT f.file_id, f.record_no, f.mainid, f.eh,
           CASE WHEN f.hard_conflict IS NOT NULL OR (f.bov AND p_blank_policy = 'QUARANTINE_ROW') THEN 'CONFLICT'
                WHEN f.base_h IS NULL THEN 'NEW'
                WHEN f.base_h = f.eh  THEN 'UNCHANGED'
                ELSE 'CHANGED' END,
           coalesce(f.hard_conflict, CASE WHEN f.bov AND p_blank_policy = 'QUARANTINE_ROW' THEN 'BLANK_OVER_VALUE' END),
           f.bov AND f.hard_conflict IS NULL,
           f.base_record_id,
           f.eff
      FROM f
$$;

-- Informational data-quality counts (never quarantine, never block). Parent
-- references are candidate identifiers only: "unresolved" means not a key of
-- this run nor of the base release — no relationship is inferred or stored.
CREATE FUNCTION identity_ref_staging.assess_warnings(p_run uuid, p_base bigint) RETURNS jsonb
    LANGUAGE sql STABLE SET search_path = pg_catalog, pg_temp
    SET work_mem = '128MB'
AS $$
    WITH s AS (
        SELECT r.mainid, r.source_payload AS p
          FROM identity_ref_staging.rows r JOIN identity_ref.import_files f ON f.id = r.file_id
         WHERE f.run_id = p_run
    ), keys AS (
        SELECT s.mainid FROM s WHERE s.mainid IS NOT NULL AND s.mainid <> ''
        UNION
        SELECT rec.mainid FROM identity_ref.release_presence pr JOIN identity_ref.records rec ON rec.id = pr.record_id
         WHERE pr.release_id = p_base
    )
    SELECT jsonb_build_object(
        'MAINID_NOT_9_DIGITS',         (SELECT count(*) FROM s WHERE s.mainid <> '' AND s.mainid !~ '^[0-9]{9}$'),
        'FATHERID_UNRESOLVED',         (SELECT count(*) FROM s WHERE coalesce(s.p ->> 'fatherid', '') <> ''
                                           AND NOT EXISTS (SELECT 1 FROM keys WHERE keys.mainid = s.p ->> 'fatherid')),
        'MOTHERID_UNRESOLVED',         (SELECT count(*) FROM s WHERE coalesce(s.p ->> 'motherid', '') <> ''
                                           AND NOT EXISTS (SELECT 1 FROM keys WHERE keys.mainid = s.p ->> 'motherid')),
        'PARENT_IS_SELF',              (SELECT count(*) FROM s WHERE s.mainid <> ''
                                           AND (s.p ->> 'fatherid' = s.mainid OR s.p ->> 'motherid' = s.mainid)),
        'PARTNERID_POPULATED',         (SELECT count(*) FROM s WHERE coalesce(s.p ->> 'partnerid', '') <> ''),
        'VALUE_REPLACEMENT_CHARACTER', (SELECT count(*) FROM s WHERE strpos(s.p::text, chr(65533)) > 0),
        'P0_EMPTY_COLUMN_POPULATED',   (SELECT count(*) FROM s WHERE EXISTS (
                                           SELECT 1 FROM unnest(ARRAY['partnerid', 'deathdate', 'presonstate', 'prevfamily', 'phonenum',
                                                   'att', 'worktype', 'source', 'civilg', 'cwloc', 'mgrade', 'mstartw', 'msalary', 'job']) c
                                            WHERE coalesce(s.p ->> c, '') <> ''))
    )
$$;

-- ---------------------------------------------------------------- shared preconditions (internal; not granted)

-- The run is RUNNING, every file is STAGED with a verified checksum and a
-- complete load, and every count agrees: staged rows = checkpoints = files = run = manifest.
CREATE FUNCTION identity_ref.assert_run_complete(p_run uuid) RETURNS void
    LANGUAGE plpgsql STABLE SET search_path = pg_catalog, pg_temp
AS $$
DECLARE
    run identity_ref.import_runs%ROWTYPE;
    files bigint;
BEGIN
    SELECT * INTO run FROM identity_ref.import_runs WHERE id = p_run;
    IF NOT FOUND OR run.status <> 'RUNNING' THEN
        RAISE EXCEPTION 'IDENTITY_REF_RUN_NOT_RUNNING' USING ERRCODE = 'check_violation';
    END IF;
    IF run.source_table <> 't03maindata' THEN
        RAISE EXCEPTION 'IDENTITY_REF_SOURCE_TABLE_UNSUPPORTED' USING ERRCODE = 'check_violation';
    END IF;
    SELECT count(*) INTO files FROM identity_ref.import_files f WHERE f.run_id = p_run;
    IF files = 0 OR EXISTS (SELECT 1 FROM identity_ref.import_files f WHERE f.run_id = p_run AND f.status <> 'STAGED') THEN
        RAISE EXCEPTION 'IDENTITY_REF_STAGING_INCOMPLETE' USING ERRCODE = 'check_violation';
    END IF;
    IF EXISTS (SELECT 1 FROM identity_ref.import_files f WHERE f.run_id = p_run
                AND (f.actual_sha256 IS NULL OR f.actual_sha256 <> f.expected_sha256)) THEN
        RAISE EXCEPTION 'IDENTITY_REF_CHECKSUM_NOT_VERIFIED' USING ERRCODE = 'check_violation';
    END IF;
    IF (SELECT sum(f.loaded_rows) FROM identity_ref.import_files f WHERE f.run_id = p_run) <> run.expected_rows
       OR run.rows_loaded <> run.expected_rows
       OR (SELECT count(*) FROM identity_ref_staging.rows r JOIN identity_ref.import_files f ON f.id = r.file_id WHERE f.run_id = p_run) <> run.expected_rows
       OR (SELECT coalesce(sum(c.row_count), 0) FROM identity_ref_staging.file_chunks c JOIN identity_ref.import_files f ON f.id = c.file_id WHERE f.run_id = p_run) <> run.expected_rows THEN
        RAISE EXCEPTION 'IDENTITY_REF_ROW_COUNT_INCONSISTENT' USING ERRCODE = 'check_violation';
    END IF;
END
$$;

-- Fails closed when the policy does not cover what the data contains.
CREATE FUNCTION identity_ref.assert_policy_allows(p_staged bigint, p_conflicts bigint, p_bov bigint, p_blank text,
                                                  p_max_rows bigint, p_max_ratio numeric) RETURNS void
    LANGUAGE plpgsql IMMUTABLE SET search_path = pg_catalog, pg_temp
AS $$
BEGIN
    IF p_conflicts > 0 AND p_max_rows IS NULL THEN
        RAISE EXCEPTION 'IDENTITY_REF_POLICY_QUARANTINE_UNRESOLVED' USING ERRCODE = 'check_violation';
    END IF;
    IF p_conflicts > 0 AND p_max_rows = 0 THEN            -- ZERO_UNRESOLVED_QUARANTINE (D-02, approved)
        RAISE EXCEPTION 'IDENTITY_REF_QUARANTINE_NOT_ALLOWED' USING ERRCODE = 'check_violation';
    END IF;
    IF p_conflicts > 0 AND (p_conflicts > p_max_rows OR p_conflicts::numeric > p_max_ratio * p_staged) THEN
        RAISE EXCEPTION 'IDENTITY_REF_QUARANTINE_THRESHOLD_EXCEEDED' USING ERRCODE = 'check_violation';
    END IF;
    IF p_bov > 0 AND p_blank = 'UNRESOLVED' THEN
        RAISE EXCEPTION 'IDENTITY_REF_POLICY_BLANK_FIELD_UNRESOLVED' USING ERRCODE = 'check_violation';
    END IF;
END
$$;

-- ---------------------------------------------------------------- preparation (SECURITY DEFINER)

-- Creates the BUILDING release on top of the ACTIVE release, records policy,
-- assessments and counts — or returns the existing BUILDING release of the
-- run when the same policy resumes an interrupted preparation. Raises (and
-- writes nothing) on any failed precondition or policy gate.
CREATE FUNCTION identity_ref.begin_preparation(p_run uuid, p_policy_id text, p_policy_fingerprint text, p_comparison text,
                                               p_blank text, p_max_rows bigint, p_max_ratio numeric) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp
    SET work_mem = '128MB'
AS $$
DECLARE
    existing record;
    base     bigint;
    rel      bigint;
    c        record;
    carried  bigint;
BEGIN
    PERFORM identity_ref.assert_run_complete(p_run);
    SELECT d.id, d.status, p.policy_fingerprint INTO existing
      FROM identity_ref.dataset_releases d LEFT JOIN identity_ref.release_preparations p ON p.release_id = d.id
     WHERE d.run_id = p_run;
    IF FOUND THEN
        IF existing.status = 'BUILDING' AND existing.policy_fingerprint = p_policy_fingerprint THEN
            RETURN existing.id;
        END IF;
        RAISE EXCEPTION 'IDENTITY_REF_RELEASE_STATE_INVALID' USING ERRCODE = 'check_violation';
    END IF;
    IF p_comparison IS DISTINCT FROM 'IDREF-CMP-1' THEN
        RAISE EXCEPTION 'IDENTITY_REF_COMPARISON_POLICY_UNSUPPORTED' USING ERRCODE = 'check_violation';
    END IF;

    SELECT a.release_id INTO base FROM identity_ref.active_release a WHERE a.id = 1;
    INSERT INTO identity_ref.dataset_releases (run_id, base_release_id) VALUES (p_run, base) RETURNING id INTO rel;
    INSERT INTO identity_ref_staging.assessments (release_id, file_id, record_no, mainid, content_sha256, classification, conflict_code,
                                                  blank_over_value, effective_payload)
    SELECT rel, a.file_id, a.record_no, a.mainid, a.content_sha256, a.classification, a.conflict_code, a.blank_over_value, a.effective_payload
      FROM identity_ref_staging.assess_run(p_run, base, p_blank) a;

    SELECT count(*) AS staged,
           count(*) FILTER (WHERE a.classification = 'NEW')       AS new_n,
           count(*) FILTER (WHERE a.classification = 'CHANGED')   AS changed_n,
           count(*) FILTER (WHERE a.classification = 'UNCHANGED') AS unchanged_n,
           count(*) FILTER (WHERE a.classification = 'CONFLICT')  AS conflict_n,
           count(*) FILTER (WHERE a.blank_over_value)             AS bov_n
      INTO c FROM identity_ref_staging.assessments a WHERE a.release_id = rel;
    PERFORM identity_ref.assert_policy_allows(c.staged, c.conflict_n, c.bov_n, p_blank, p_max_rows, p_max_ratio);
    IF c.staged = 0 THEN
        RAISE EXCEPTION 'IDENTITY_REF_STAGING_EMPTY' USING ERRCODE = 'check_violation';
    END IF;

    SELECT count(*) INTO carried
      FROM identity_ref.release_presence b JOIN identity_ref.records rec ON rec.id = b.record_id
     WHERE b.release_id = base
       AND NOT EXISTS (SELECT 1 FROM identity_ref_staging.assessments a
                        WHERE a.release_id = rel AND a.classification <> 'CONFLICT' AND a.mainid = rec.mainid);

    -- Quarantined rows the P2 key analysis has not already recorded.
    INSERT INTO identity_ref.import_issues (run_id, file_name, line_number, code, column_name)
    SELECT p_run, f.file_name, r.line_no, a.conflict_code, NULL
      FROM identity_ref_staging.assessments a
      JOIN identity_ref_staging.rows r ON r.file_id = a.file_id AND r.record_no = a.record_no
      JOIN identity_ref.import_files f ON f.id = a.file_id
     WHERE a.release_id = rel AND a.conflict_code IN ('VALUE_CONTROL_CHARACTER', 'BLANK_OVER_VALUE')
     ORDER BY f.file_name, r.line_no;

    INSERT INTO identity_ref.release_preparations
        (release_id, run_id, policy_id, policy_fingerprint, comparison_policy, blank_field_policy, quarantine_max_rows,
         quarantine_max_ratio, staged_rows, new_count, changed_count, unchanged_count, conflict_count, carried_forward_count,
         blank_over_value_count, expected_presence, warnings)
    VALUES (rel, p_run, p_policy_id, p_policy_fingerprint, p_comparison, p_blank, p_max_rows, p_max_ratio, c.staged,
            c.new_n, c.changed_n, c.unchanged_n, c.conflict_n, carried, c.bov_n,
            c.new_n + c.changed_n + c.unchanged_n + carried,
            identity_ref_staging.assess_warnings(p_run, base));
    RETURN rel;
END
$$;

-- PREPARING -> PREPARED once the release holds exactly the expected presence rows.
CREATE FUNCTION identity_ref.complete_preparation(p_release bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp
AS $$
DECLARE
    prep identity_ref.release_preparations%ROWTYPE;
BEGIN
    SELECT * INTO prep FROM identity_ref.release_preparations WHERE release_id = p_release FOR UPDATE;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'IDENTITY_REF_RELEASE_NOT_PREPARED' USING ERRCODE = 'check_violation';
    END IF;
    IF prep.status <> 'PREPARING' THEN
        RETURN;
    END IF;
    IF (SELECT d.status FROM identity_ref.dataset_releases d WHERE d.id = p_release) <> 'BUILDING' THEN
        RAISE EXCEPTION 'IDENTITY_REF_RELEASE_NOT_BUILDING' USING ERRCODE = 'check_violation';
    END IF;
    IF (SELECT count(*) FROM identity_ref.release_presence p WHERE p.release_id = p_release) <> prep.expected_presence THEN
        RAISE EXCEPTION 'IDENTITY_REF_PREPARATION_INCOMPLETE' USING ERRCODE = 'check_violation';
    END IF;
    UPDATE identity_ref.release_preparations SET status = 'PREPARED', prepared_at = now() WHERE release_id = p_release;
END
$$;

-- PREPARED -> VERIFIED: recomputes the classification from staging and the
-- base release, and proves the release equals it exactly — every accepted
-- staged row PRESENT with exactly its content, every other base identity
-- CARRIED_FORWARD with its unchanged base version, nothing else — and that
-- the recorded counts and policy gates still hold. Idempotent for VERIFIED.
CREATE FUNCTION identity_ref.verify_release(p_release bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp
    SET work_mem = '128MB'
AS $$
DECLARE
    rel    identity_ref.dataset_releases%ROWTYPE;
    prep   identity_ref.release_preparations%ROWTYPE;
    active bigint;
    c      record;
    n      bigint;
BEGIN
    SELECT * INTO rel FROM identity_ref.dataset_releases WHERE id = p_release FOR UPDATE;
    IF NOT FOUND OR rel.status <> 'BUILDING' THEN
        RAISE EXCEPTION 'IDENTITY_REF_RELEASE_NOT_BUILDING' USING ERRCODE = 'check_violation';
    END IF;
    SELECT * INTO prep FROM identity_ref.release_preparations WHERE release_id = p_release FOR UPDATE;
    IF NOT FOUND OR prep.status = 'PREPARING' THEN
        RAISE EXCEPTION 'IDENTITY_REF_RELEASE_NOT_PREPARED' USING ERRCODE = 'check_violation';
    END IF;
    IF prep.status = 'VERIFIED' THEN
        RETURN;
    END IF;
    PERFORM identity_ref.assert_run_complete(prep.run_id);
    SELECT a.release_id INTO active FROM identity_ref.active_release a WHERE a.id = 1;
    IF rel.base_release_id IS DISTINCT FROM active THEN
        RAISE EXCEPTION 'IDENTITY_REF_RELEASE_STALE_BASE' USING ERRCODE = 'serialization_failure';
    END IF;

    CREATE TEMP TABLE identity_ref_verify ON COMMIT DROP AS
        SELECT * FROM identity_ref_staging.assess_run(prep.run_id, rel.base_release_id, prep.blank_field_policy);
    SELECT count(*) AS staged,
           count(*) FILTER (WHERE x.classification = 'NEW')       AS new_n,
           count(*) FILTER (WHERE x.classification = 'CHANGED')   AS changed_n,
           count(*) FILTER (WHERE x.classification = 'UNCHANGED') AS unchanged_n,
           count(*) FILTER (WHERE x.classification = 'CONFLICT')  AS conflict_n,
           count(*) FILTER (WHERE x.blank_over_value)             AS bov_n
      INTO c FROM pg_temp.identity_ref_verify x;
    IF (c.staged, c.new_n, c.changed_n, c.unchanged_n, c.conflict_n, c.bov_n)
       IS DISTINCT FROM (prep.staged_rows, prep.new_count, prep.changed_count, prep.unchanged_count, prep.conflict_count, prep.blank_over_value_count) THEN
        DROP TABLE pg_temp.identity_ref_verify;
        RAISE EXCEPTION 'IDENTITY_REF_VERIFY_COUNTS_MISMATCH' USING ERRCODE = 'check_violation';
    END IF;
    PERFORM identity_ref.assert_policy_allows(c.staged, c.conflict_n, c.bov_n, prep.blank_field_policy,
                                              prep.quarantine_max_rows, prep.quarantine_max_ratio);

    -- Every accepted staged row is PRESENT with exactly its content ...
    SELECT count(*) INTO n FROM pg_temp.identity_ref_verify x
     WHERE x.classification <> 'CONFLICT'
       AND NOT EXISTS (SELECT 1 FROM identity_ref.release_presence p
                         JOIN identity_ref.records r ON r.id = p.record_id
                         JOIN identity_ref.record_versions v ON v.id = p.record_version_id
                        WHERE p.release_id = p_release AND p.source_state = 'PRESENT'
                          AND r.mainid = x.mainid AND v.content_sha256 = x.content_sha256);
    IF n <> 0 OR (SELECT count(*) FROM identity_ref.release_presence p WHERE p.release_id = p_release AND p.source_state = 'PRESENT')
                 <> c.new_n + c.changed_n + c.unchanged_n THEN
        DROP TABLE pg_temp.identity_ref_verify;
        RAISE EXCEPTION 'IDENTITY_REF_VERIFY_PRESENCE_MISMATCH' USING ERRCODE = 'check_violation';
    END IF;
    -- ... and every carried identity is a base identity not accepted in this run, with its base version unchanged.
    SELECT count(*) INTO n FROM identity_ref.release_presence p
      JOIN identity_ref.records r ON r.id = p.record_id
     WHERE p.release_id = p_release AND p.source_state = 'CARRIED_FORWARD'
       AND (NOT EXISTS (SELECT 1 FROM identity_ref.release_presence b
                         WHERE b.release_id = rel.base_release_id AND b.record_id = p.record_id AND b.record_version_id = p.record_version_id)
            OR EXISTS (SELECT 1 FROM pg_temp.identity_ref_verify x WHERE x.classification <> 'CONFLICT' AND x.mainid = r.mainid));
    IF n = 0 THEN
        -- Raw incoming rows (PRESERVE_EXISTING_ON_BLANK): one per accepted row whose effective payload differs, equal to the staged row.
        SELECT count(*) INTO n FROM pg_temp.identity_ref_verify x
          JOIN identity_ref_staging.rows sr ON sr.file_id = x.file_id AND sr.record_no = x.record_no
         WHERE x.classification <> 'CONFLICT' AND x.effective_payload IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM identity_ref.release_incoming i JOIN identity_ref.records r ON r.id = i.record_id
                            WHERE i.release_id = p_release AND r.mainid = x.mainid AND i.source_payload = sr.source_payload);
        IF n <> 0 OR (SELECT count(*) FROM identity_ref.release_incoming i WHERE i.release_id = p_release)
                     <> (SELECT count(*) FROM pg_temp.identity_ref_verify x WHERE x.classification <> 'CONFLICT' AND x.effective_payload IS NOT NULL) THEN
            DROP TABLE pg_temp.identity_ref_verify;
            RAISE EXCEPTION 'IDENTITY_REF_VERIFY_INCOMING_MISMATCH' USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    DROP TABLE pg_temp.identity_ref_verify;
    IF n <> 0 OR (SELECT count(*) FROM identity_ref.release_presence p WHERE p.release_id = p_release AND p.source_state = 'CARRIED_FORWARD')
                 <> prep.carried_forward_count
       OR (SELECT count(*) FROM identity_ref.release_presence p WHERE p.release_id = p_release) <> prep.expected_presence THEN
        RAISE EXCEPTION 'IDENTITY_REF_VERIFY_CARRY_FORWARD_MISMATCH' USING ERRCODE = 'check_violation';
    END IF;

    UPDATE identity_ref.release_preparations SET status = 'VERIFIED', verified_at = now() WHERE release_id = p_release;
END
$$;

-- Planner statistics for the tables a preparation grows (records can go from
-- empty to millions within one preparation). Only the owner may ANALYZE; this
-- narrow definer function lets the import writer refresh statistics of these
-- five tables and nothing else. It reads no data for the caller.
CREATE FUNCTION identity_ref.refresh_statistics() RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp
AS $$
BEGIN
    ANALYZE identity_ref.records, identity_ref.record_versions, identity_ref.release_presence,
            identity_ref_staging.rows, identity_ref_staging.assessments;
END
$$;

-- ---------------------------------------------------------------- the publication gate (fires inside publish_release)

CREATE FUNCTION identity_ref.check_publication_gate() RETURNS trigger
    LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp
AS $$
DECLARE
    prep identity_ref.release_preparations%ROWTYPE;
BEGIN
    IF NEW.status = 'PUBLISHED' AND OLD.status <> 'PUBLISHED' THEN
        SELECT * INTO prep FROM identity_ref.release_preparations WHERE release_id = NEW.id;
        IF NOT FOUND OR prep.status <> 'VERIFIED' OR NEW.record_count IS DISTINCT FROM prep.expected_presence THEN
            RAISE EXCEPTION 'IDENTITY_REF_RELEASE_NOT_VERIFIED' USING ERRCODE = 'check_violation';
        END IF;
        IF NOT EXISTS (SELECT 1 FROM identity_ref.import_runs r WHERE r.id = NEW.run_id AND r.status = 'RUNNING')
           OR EXISTS (SELECT 1 FROM identity_ref.import_files f WHERE f.run_id = NEW.run_id AND f.status <> 'STAGED') THEN
            RAISE EXCEPTION 'IDENTITY_REF_RUN_NOT_RUNNING' USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    RETURN NEW;
END
$$;
CREATE TRIGGER dataset_releases_publication_gate BEFORE UPDATE ON identity_ref.dataset_releases
    FOR EACH ROW EXECUTE FUNCTION identity_ref.check_publication_gate();

-- ---------------------------------------------------------------- privileges (least privilege)

REVOKE ALL ON identity_ref.release_preparations, identity_ref_staging.assessments, identity_ref.release_incoming FROM PUBLIC;
REVOKE ALL ON FUNCTION identity_ref.check_preparation_change(), identity_ref_staging.assess_run(uuid, bigint, text),
                       identity_ref_staging.assess_warnings(uuid, bigint), identity_ref.assert_run_complete(uuid),
                       identity_ref.assert_policy_allows(bigint, bigint, bigint, text, bigint, numeric),
                       identity_ref.begin_preparation(uuid, text, text, text, text, bigint, numeric),
                       identity_ref.complete_preparation(bigint), identity_ref.verify_release(bigint),
                       identity_ref.refresh_statistics(), identity_ref.check_publication_gate() FROM PUBLIC;

GRANT SELECT ON identity_ref.release_preparations TO famboook_identity_writer;
GRANT SELECT, INSERT ON identity_ref.release_incoming TO famboook_identity_writer;              -- insert-only (triggers)
GRANT SELECT, DELETE ON identity_ref_staging.assessments TO famboook_identity_writer;   -- DELETE: cleanup, guarded
GRANT EXECUTE ON FUNCTION identity_ref_staging.assess_run(uuid, bigint, text), identity_ref_staging.assess_warnings(uuid, bigint),
                          identity_ref.begin_preparation(uuid, text, text, text, text, bigint, numeric),
                          identity_ref.complete_preparation(bigint), identity_ref.verify_release(bigint),
                          identity_ref.refresh_statistics()
    TO famboook_identity_writer;

DO $app$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'famboook_app') THEN
        REVOKE ALL ON identity_ref.release_preparations, identity_ref_staging.assessments, identity_ref.release_incoming FROM famboook_app;
    END IF;
END
$app$;
