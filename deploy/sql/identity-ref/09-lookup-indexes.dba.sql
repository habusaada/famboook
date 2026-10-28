-- FB-CIVIL-02A — OPTIONAL search indexes for the Civil Registry Lookup (REVIEW BEFORE USE).
-- docs/adr/FB-CIVIL-02A-CIVIL-REGISTRY-LOOKUP.md §L-2, §L-5, §L-8.
--
-- NOT APPROVED FOR PRODUCTION. Local benchmarking only until a separate, recorded approval.
-- Run by the DBA with psql in AUTOCOMMIT mode (CREATE INDEX CONCURRENTLY refuses a transaction block),
-- after 08-lookup.dba.sql, with pg_trgm installed in schema public (db-preflight.sql):
--     SET ROLE famboook_identity_owner;
--     \i 09-lookup-indexes.dba.sql
--     RESET ROLE;
-- CONCURRENTLY: readers and publication keep working while each index builds. A failed build leaves an
-- INVALID index: DROP INDEX CONCURRENTLY it and run the statement again.
--
-- Without these indexes the lookup is still correct: the National ID search uses the existing UNIQUE
-- index on records.mainid; the name searches scan the active release (slow on 2.4 M records); the
-- reverse links (household, children) stay disabled in the application.
--
-- PRECONDITION — the database LC_CTYPE must classify Arabic letters as alphanumeric (a UTF-8 libc
-- locale such as en_US.UTF-8). Under LC_CTYPE 'C' pg_trgm extracts NO trigrams from Arabic text: the
-- trigram indexes build (tiny) but cannot narrow anything, and name searches stay full scans
-- (measured locally on 2.4 M synthetic records: 18-39 s with ctype C, 24-660 ms with a UTF-8 ctype).
-- The guard below refuses to continue in that case.

DO $$
BEGIN
    -- U+0633 U+0627 U+0645 U+064A (an Arabic word) must yield trigrams.
    IF cardinality(public.show_trgm(chr(1587) || chr(1575) || chr(1605) || chr(1610))) = 0 THEN
        RAISE EXCEPTION 'IDENTITY_REF_LOOKUP_CTYPE_UNSUITABLE: LC_CTYPE % yields no Arabic trigrams',
            (SELECT datctype FROM pg_database WHERE datname = current_database())
            USING ERRCODE = 'feature_not_supported';
    END IF;
END
$$;

-- Full-name search: every normalized token is a LIKE '%token%' condition on this expression.
CREATE INDEX CONCURRENTLY IF NOT EXISTS record_versions_lookup_fullname_trgm ON identity_ref.record_versions
    USING gin (identity_ref_lookup.normalize_name(source_payload ->> 'fullname') public.gin_trgm_ops);

-- Detailed search: candidates by the four parts together; each part is then rechecked in its own field.
CREATE INDEX CONCURRENTLY IF NOT EXISTS record_versions_lookup_parts_trgm ON identity_ref.record_versions
    USING gin (identity_ref_lookup.name_parts(source_payload) public.gin_trgm_ops);

-- Reverse links: children (fatherid / motherid) and household members (householdid).
CREATE INDEX CONCURRENTLY IF NOT EXISTS record_versions_lookup_fatherid ON identity_ref.record_versions
    ((source_payload ->> 'fatherid')) WHERE (source_payload ->> 'fatherid') <> '';
CREATE INDEX CONCURRENTLY IF NOT EXISTS record_versions_lookup_motherid ON identity_ref.record_versions
    ((source_payload ->> 'motherid')) WHERE (source_payload ->> 'motherid') <> '';
CREATE INDEX CONCURRENTLY IF NOT EXISTS record_versions_lookup_householdid ON identity_ref.record_versions
    ((source_payload ->> 'householdid')) WHERE (source_payload ->> 'householdid') <> '';

ANALYZE identity_ref.record_versions;
