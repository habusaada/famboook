-- FB-CIVIL-01A-P2 — identity reference STAGING area (REVIEW BEFORE USE).
--
-- Apply AS THE OWNER ROLE, after 01-schema.sql:
--     SET ROLE famboook_identity_owner;
--     \i 02-staging.sql
--     RESET ROLE;
-- Pure SQL, PostgreSQL 16+.
--
-- Why a separate schema: identity_ref holds immutable reference data. Staging
-- is scratch space — rows are deleted when a run is rejected, aborted or (in
-- P3) consumed — so it lives apart, with its own narrow grants, and never
-- weakens the immutability of identity_ref.
--
--   file_chunks   one checkpoint per committed chunk of a source file:
--                 contiguous record range, byte range and SHA-256 of the raw
--                 bytes, so a resumed run can verify what was loaded
--   rows          the staged records: the 36 raw fields exactly as parsed
--
-- Integrity rules (enforced here, for every role, not by the loader):
--   * chunks only for a VERIFIED file of a RUNNING run, contiguous (record
--     and byte ranges continue the previous chunk);
--   * a chunk's rows are written in the SAME transaction as its checkpoint,
--     and at COMMIT the chunk holds exactly row_count rows covering its range
--     (deferred check) — a checkpoint never describes a partial chunk;
--   * no UPDATE, no TRUNCATE; DELETE (cleanup) is refused for a STAGED file
--     of a RUNNING run (completed staging awaiting P3).
-- UNLOGGED: no WAL for bulk data. After a server crash PostgreSQL empties
-- both tables together; the loader then finds no checkpoint and restarts the
-- affected files (verified on resume, never assumed).

DO $guard$
BEGIN
    IF current_user <> 'famboook_identity_owner' THEN
        RAISE EXCEPTION 'IDENTITY_REF_SCHEMA_MUST_BE_APPLIED_AS_OWNER';
    END IF;
    IF to_regclass('identity_ref.import_files') IS NULL THEN
        RAISE EXCEPTION 'IDENTITY_REF_STAGING_NEEDS_01_SCHEMA';
    END IF;
END
$guard$;

CREATE SCHEMA identity_ref_staging;
REVOKE ALL ON SCHEMA identity_ref_staging FROM PUBLIC;
ALTER DEFAULT PRIVILEGES IN SCHEMA identity_ref_staging REVOKE ALL ON TABLES FROM PUBLIC;
ALTER DEFAULT PRIVILEGES IN SCHEMA identity_ref_staging REVOKE ALL ON SEQUENCES FROM PUBLIC;
ALTER DEFAULT PRIVILEGES IN SCHEMA identity_ref_staging REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC;

CREATE UNLOGGED TABLE identity_ref_staging.file_chunks (
    file_id      bigint      NOT NULL REFERENCES identity_ref.import_files (id) ON DELETE RESTRICT,
    chunk_no     integer     NOT NULL CHECK (chunk_no > 0),
    first_record bigint      NOT NULL CHECK (first_record > 0),
    last_record  bigint      NOT NULL,
    row_count    integer     NOT NULL CHECK (row_count > 0),
    byte_start   bigint      NOT NULL CHECK (byte_start >= 0),
    byte_end     bigint      NOT NULL,
    chunk_sha256 bytea       NOT NULL CHECK (octet_length(chunk_sha256) = 32),
    created_at   timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (file_id, chunk_no),
    CHECK (last_record - first_record + 1 = row_count),
    CHECK (byte_end > byte_start)
);

CREATE UNLOGGED TABLE identity_ref_staging.rows (
    file_id        bigint  NOT NULL,
    chunk_no       integer NOT NULL,
    record_no      bigint  NOT NULL CHECK (record_no > 0),   -- logical CSV record (1 = first after the header)
    line_no        bigint  NOT NULL CHECK (line_no > 1),     -- physical line where the record starts (1 = header)
    source_payload jsonb   NOT NULL CHECK (identity_ref.is_valid_source_payload(source_payload)),
    mainid         text    GENERATED ALWAYS AS (source_payload ->> 'mainid') STORED,
    PRIMARY KEY (file_id, record_no),
    FOREIGN KEY (file_id, chunk_no) REFERENCES identity_ref_staging.file_chunks (file_id, chunk_no) ON DELETE RESTRICT
);

-- ---------------------------------------------------------------- integrity triggers

-- A checkpoint: only for a VERIFIED file of a RUNNING run, contiguous with the previous one.
CREATE FUNCTION identity_ref_staging.check_chunk_insert() RETURNS trigger
    LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp
AS $$
DECLARE
    prev identity_ref_staging.file_chunks%ROWTYPE;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM identity_ref.import_files f JOIN identity_ref.import_runs r ON r.id = f.run_id
                    WHERE f.id = NEW.file_id AND f.status = 'VERIFIED' AND r.status = 'RUNNING') THEN
        RAISE EXCEPTION 'IDENTITY_REF_STAGING_FILE_NOT_LOADABLE' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.chunk_no = 1 THEN
        IF NEW.first_record <> 1 THEN
            RAISE EXCEPTION 'IDENTITY_REF_STAGING_CHUNK_NOT_CONTIGUOUS' USING ERRCODE = 'check_violation';
        END IF;
    ELSE
        SELECT * INTO prev FROM identity_ref_staging.file_chunks c WHERE c.file_id = NEW.file_id AND c.chunk_no = NEW.chunk_no - 1;
        IF NOT FOUND OR prev.last_record + 1 <> NEW.first_record OR prev.byte_end <> NEW.byte_start THEN
            RAISE EXCEPTION 'IDENTITY_REF_STAGING_CHUNK_NOT_CONTIGUOUS' USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    RETURN NEW;
END
$$;
CREATE TRIGGER file_chunks_insert BEFORE INSERT ON identity_ref_staging.file_chunks
    FOR EACH ROW EXECUTE FUNCTION identity_ref_staging.check_chunk_insert();

-- At COMMIT: the chunk holds exactly its declared rows (no partial checkpoint).
CREATE FUNCTION identity_ref_staging.check_chunk_complete() RETURNS trigger
    LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp
AS $$
DECLARE
    n bigint;
    lo bigint;
    hi bigint;
BEGIN
    SELECT count(*), min(r.record_no), max(r.record_no) INTO n, lo, hi
      FROM identity_ref_staging.rows r WHERE r.file_id = NEW.file_id AND r.chunk_no = NEW.chunk_no;
    IF n <> NEW.row_count OR lo <> NEW.first_record OR hi <> NEW.last_record THEN
        RAISE EXCEPTION 'IDENTITY_REF_STAGING_CHUNK_INCOMPLETE' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NULL;
END
$$;
CREATE CONSTRAINT TRIGGER file_chunks_complete AFTER INSERT ON identity_ref_staging.file_chunks
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION identity_ref_staging.check_chunk_complete();

-- Rows only into a chunk created in the SAME transaction, inside its record range.
CREATE FUNCTION identity_ref_staging.check_rows_insert() RETURNS trigger
    LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp
AS $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM new_rows n
          JOIN identity_ref_staging.file_chunks c ON c.file_id = n.file_id AND c.chunk_no = n.chunk_no
         WHERE c.xmin <> pg_current_xact_id()::xid
            OR n.record_no NOT BETWEEN c.first_record AND c.last_record
    ) THEN
        RAISE EXCEPTION 'IDENTITY_REF_STAGING_ROW_OUTSIDE_ITS_CHUNK' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NULL;
END
$$;
CREATE TRIGGER rows_insert AFTER INSERT ON identity_ref_staging.rows
    REFERENCING NEW TABLE AS new_rows
    FOR EACH STATEMENT EXECUTE FUNCTION identity_ref_staging.check_rows_insert();

-- Cleanup never removes completed staging of a RUNNING run (awaiting P3).
CREATE FUNCTION identity_ref_staging.check_delete() RETURNS trigger
    LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp
AS $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM (SELECT DISTINCT o.file_id FROM old_rows o) d
          JOIN identity_ref.import_files f ON f.id = d.file_id
          JOIN identity_ref.import_runs r  ON r.id = f.run_id
         WHERE f.status = 'STAGED' AND r.status = 'RUNNING'
    ) THEN
        RAISE EXCEPTION 'IDENTITY_REF_STAGING_COMPLETED_FILE_PROTECTED' USING ERRCODE = 'insufficient_privilege';
    END IF;
    RETURN NULL;
END
$$;
CREATE TRIGGER rows_delete AFTER DELETE ON identity_ref_staging.rows
    REFERENCING OLD TABLE AS old_rows
    FOR EACH STATEMENT EXECUTE FUNCTION identity_ref_staging.check_delete();
CREATE TRIGGER file_chunks_delete AFTER DELETE ON identity_ref_staging.file_chunks
    REFERENCING OLD TABLE AS old_rows
    FOR EACH STATEMENT EXECUTE FUNCTION identity_ref_staging.check_delete();

-- No UPDATE and no TRUNCATE, for any role (identity_ref.forbid_change raises IDENTITY_REF_IMMUTABLE).
CREATE TRIGGER file_chunks_no_update BEFORE UPDATE ON identity_ref_staging.file_chunks
    FOR EACH ROW EXECUTE FUNCTION identity_ref.forbid_change();
CREATE TRIGGER file_chunks_no_truncate BEFORE TRUNCATE ON identity_ref_staging.file_chunks
    FOR EACH STATEMENT EXECUTE FUNCTION identity_ref.forbid_change();
CREATE TRIGGER rows_no_update BEFORE UPDATE ON identity_ref_staging.rows
    FOR EACH ROW EXECUTE FUNCTION identity_ref.forbid_change();
CREATE TRIGGER rows_no_truncate BEFORE TRUNCATE ON identity_ref_staging.rows
    FOR EACH STATEMENT EXECUTE FUNCTION identity_ref.forbid_change();

-- ---------------------------------------------------------------- privileges (least privilege)

REVOKE ALL ON ALL TABLES    IN SCHEMA identity_ref_staging FROM PUBLIC;
REVOKE ALL ON ALL FUNCTIONS IN SCHEMA identity_ref_staging FROM PUBLIC;

-- The import writer: load (COPY needs only INSERT), verify (SELECT), clean up (DELETE, guarded above).
GRANT USAGE ON SCHEMA identity_ref_staging TO famboook_identity_writer;
GRANT SELECT, INSERT, DELETE ON identity_ref_staging.file_chunks, identity_ref_staging.rows TO famboook_identity_writer;

-- The application role and the reserved reader get nothing.
DO $app$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'famboook_app') THEN
        REVOKE ALL ON SCHEMA identity_ref_staging FROM famboook_app;
        REVOKE ALL ON ALL TABLES IN SCHEMA identity_ref_staging FROM famboook_app;
    END IF;
END
$app$;
