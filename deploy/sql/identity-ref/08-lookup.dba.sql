-- FB-CIVIL-02A — Civil Registry Lookup read boundary (REVIEW BEFORE USE).
-- docs/adr/FB-CIVIL-02A-CIVIL-REGISTRY-LOOKUP.md §L-2.
--
-- Run by the database ADMINISTRATOR, connected to the target database, after
-- 00-roles.dba.sql and 01–03. It prepares the lookup login, then (as
-- famboook_identity_owner) the schema identity_ref_lookup with SECURITY DEFINER
-- functions that read ONLY the active published release. Pure SQL, idempotent,
-- PostgreSQL 16+. Changes no existing object or privilege; never names the
-- application role.
--
-- LOCAL ONLY. Applying it to Production is a separate, recorded approval
-- (G1 / OD-08, OD-09 — see the ADR).
--
--   famboook_identity_reader        reserved group role (00-roles.dba.sql, "FB-CIVIL-01B");
--                                   EXECUTE on the identity_ref_lookup functions below, nothing else
--   famboook_identity_lookup_login  the web application's lookup login: NOINHERIT, member of the
--                                   reader only, created NOLOGIN, read-only sessions, 5 s statement
--                                   timeout. The DBA enables it and sets the password interactively:
--                                       ALTER ROLE famboook_identity_lookup_login LOGIN;
--                                       \password famboook_identity_lookup_login
--
-- Never granted: SELECT on records, record_versions, release_presence, active_records or any staging,
-- monitor or import object; any write. Limits (page <= 25, match cap 500, <= 6 tokens) are enforced
-- inside the functions. The `relgion` field (special category, OD-08) is removed from every payload.

DO $roles$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'famboook_identity_owner')
       OR NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'famboook_identity_reader') THEN
        RAISE EXCEPTION 'IDENTITY_REF_RUN_00_ROLES_FIRST';
    END IF;
    IF to_regclass('identity_ref.release_presence') IS NULL THEN
        RAISE EXCEPTION 'IDENTITY_REF_LOOKUP_NEEDS_01_SCHEMA';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'famboook_identity_lookup_login') THEN
        CREATE ROLE famboook_identity_lookup_login NOLOGIN NOINHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS
            CONNECTION LIMIT 10;
    END IF;
    ALTER ROLE famboook_identity_reader NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
    ALTER ROLE famboook_identity_lookup_login NOINHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS CONNECTION LIMIT 10;
    -- Read-only sessions with a hard statement timeout, whatever the application sets.
    ALTER ROLE famboook_identity_lookup_login SET default_transaction_read_only = on;
    ALTER ROLE famboook_identity_lookup_login SET statement_timeout = '5s';
    ALTER ROLE famboook_identity_lookup_login SET lock_timeout = '2s';
    GRANT famboook_identity_reader TO famboook_identity_lookup_login;
    EXECUTE format('GRANT CONNECT ON DATABASE %I TO famboook_identity_lookup_login', current_database());
END
$roles$;

SET ROLE famboook_identity_owner;

CREATE SCHEMA IF NOT EXISTS identity_ref_lookup;
REVOKE ALL ON SCHEMA identity_ref_lookup FROM PUBLIC;

-- ---------------------------------------------------------------- normalization (ADR §L-5)
-- Diacritics U+064B–U+065F and U+0670, tatweel U+0640 removed; أ إ آ ٱ → ا; whitespace collapsed and
-- trimmed. ة/ه and ى/ي are NOT merged. Identical to App\Support\CivilLookup\ArabicNameNormalizer.
CREATE OR REPLACE FUNCTION identity_ref_lookup.normalize_name(p text) RETURNS text
    LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
    SET search_path = pg_catalog, pg_temp
AS $$
    SELECT btrim(regexp_replace(
               regexp_replace(translate(p, 'أإآٱ', 'اااا'), '[ً-ٰٟـ]', '', 'g'),
               '\s+', ' ', 'g'))
$$;

-- The four name parts as one normalized string (the parts trigram index, 09-lookup-indexes.dba.sql).
CREATE OR REPLACE FUNCTION identity_ref_lookup.name_parts(p jsonb) RETURNS text
    LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
    SET search_path = pg_catalog, pg_temp
AS $$
    SELECT identity_ref_lookup.normalize_name(coalesce(p ->> 'name1', '') || ' ' || coalesce(p ->> 'father', '') || ' '
        || coalesce(p ->> 'grandfath', '') || ' ' || coalesce(p ->> 'family', ''))
$$;

-- A LIKE pattern "contains <token>" with %, _ and \ escaped.
CREATE OR REPLACE FUNCTION identity_ref_lookup.contains_pattern(p text) RETURNS text
    LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
    SET search_path = pg_catalog, pg_temp
AS $$
    SELECT '%' || replace(replace(replace(p, '\', '\\'), '%', '\%'), '_', '\_') || '%'
$$;

-- Name-token rules (ADR §L-5): 1..6 tokens, each >= 2 characters, at least one >= 3.
CREATE OR REPLACE FUNCTION identity_ref_lookup.assert_tokens(p_tokens text[], p_min integer) RETURNS void
    LANGUAGE plpgsql IMMUTABLE
    SET search_path = pg_catalog, pg_temp
AS $$
BEGIN
    IF p_tokens IS NULL OR cardinality(p_tokens) < p_min OR cardinality(p_tokens) > 6
       OR EXISTS (SELECT 1 FROM unnest(p_tokens) t WHERE t IS NULL OR char_length(t) < 2)
       OR NOT EXISTS (SELECT 1 FROM unnest(p_tokens) t WHERE char_length(t) >= 3) THEN
        RAISE EXCEPTION 'IDENTITY_REF_LOOKUP_CRITERIA_INVALID' USING ERRCODE = 'check_violation';
    END IF;
END
$$;

CREATE OR REPLACE FUNCTION identity_ref_lookup.assert_page(p_offset integer, p_limit integer, p_cap integer) RETURNS void
    LANGUAGE plpgsql IMMUTABLE
    SET search_path = pg_catalog, pg_temp
AS $$
BEGIN
    IF p_limit IS NULL OR p_limit < 1 OR p_limit > 25 OR p_offset IS NULL OR p_offset < 0 OR p_offset >= p_cap THEN
        RAISE EXCEPTION 'IDENTITY_REF_LOOKUP_PAGE_INVALID' USING ERRCODE = 'check_violation';
    END IF;
END
$$;

-- ---------------------------------------------------------------- reads of the ACTIVE release only

CREATE OR REPLACE FUNCTION identity_ref_lookup.active_release_id() RETURNS bigint
    LANGUAGE sql STABLE SECURITY DEFINER
    SET search_path = pg_catalog, pg_temp
AS $$
    SELECT a.release_id FROM identity_ref.active_release a WHERE a.id = 1
$$;

-- Exact National ID (records.mainid, UNIQUE btree). At most one row.
CREATE OR REPLACE FUNCTION identity_ref_lookup.by_mainid(p_mainid text)
    RETURNS TABLE (release_id bigint, record_id bigint, mainid text, source_payload jsonb)
    LANGUAGE plpgsql STABLE SECURITY DEFINER
    SET search_path = pg_catalog, pg_temp
AS $$
BEGIN
    IF p_mainid IS NULL OR p_mainid !~ '^[0-9]{9}$' THEN
        RAISE EXCEPTION 'IDENTITY_REF_LOOKUP_ID_INVALID' USING ERRCODE = 'check_violation';
    END IF;
    RETURN QUERY
    SELECT a.release_id, r.id, r.mainid, v.source_payload - 'relgion'
      FROM identity_ref.active_release a
      JOIN identity_ref.records r          ON r.mainid = p_mainid
      JOIN identity_ref.release_presence p ON p.release_id = a.release_id AND p.record_id = r.id
      JOIN identity_ref.record_versions v  ON v.id = p.record_version_id AND v.record_id = r.id
     WHERE a.id = 1;
END
$$;

-- One record of the active release by its internal id (references issued from a result list).
CREATE OR REPLACE FUNCTION identity_ref_lookup.by_record(p_record bigint)
    RETURNS TABLE (release_id bigint, record_id bigint, mainid text, source_payload jsonb)
    LANGUAGE sql STABLE SECURITY DEFINER
    SET search_path = pg_catalog, pg_temp
AS $$
    SELECT a.release_id, r.id, r.mainid, v.source_payload - 'relgion'
      FROM identity_ref.active_release a
      JOIN identity_ref.release_presence p ON p.release_id = a.release_id AND p.record_id = p_record
      JOIN identity_ref.records r          ON r.id = p.record_id
      JOIN identity_ref.record_versions v  ON v.id = p.record_version_id AND v.record_id = r.id
     WHERE a.id = 1
$$;

-- Which of up to 10 referenced National IDs exist in the active release (existence only, no data).
CREATE OR REPLACE FUNCTION identity_ref_lookup.existing_mainids(p_mainids text[])
    RETURNS TABLE (release_id bigint, mainid text)
    LANGUAGE plpgsql STABLE SECURITY DEFINER
    SET search_path = pg_catalog, pg_temp
AS $$
BEGIN
    IF p_mainids IS NULL OR cardinality(p_mainids) > 10 THEN
        RAISE EXCEPTION 'IDENTITY_REF_LOOKUP_CRITERIA_INVALID' USING ERRCODE = 'check_violation';
    END IF;
    RETURN QUERY
    SELECT a.release_id, r.mainid
      FROM identity_ref.active_release a
      JOIN identity_ref.records r          ON r.mainid = ANY (p_mainids)
      JOIN identity_ref.release_presence p ON p.release_id = a.release_id AND p.record_id = r.id
     WHERE a.id = 1;
END
$$;

-- Shared tail of the list searches: the first row is the meta row (record_id NULL, total = matches,
-- capped at p_cap + 1); page rows follow only when total <= p_cap. Ordered by normalized name, id.
CREATE OR REPLACE FUNCTION identity_ref_lookup.list_page(p_where text, p_args text[], p_order text, p_offset integer, p_limit integer, p_cap integer)
    RETURNS TABLE (release_id bigint, record_id bigint, mainid text, source_payload jsonb, total bigint)
    LANGUAGE plpgsql STABLE
    SET search_path = pg_catalog, pg_temp
AS $$
BEGIN
    RETURN QUERY EXECUTE format($q$
        WITH a AS (SELECT ar.release_id FROM identity_ref.active_release ar WHERE ar.id = 1),
        m AS MATERIALIZED (
            SELECT v.record_id, v.id AS version_id, %2$s AS sort_key
              FROM a
              JOIN identity_ref.release_presence p ON p.release_id = a.release_id
              JOIN identity_ref.record_versions v  ON v.id = p.record_version_id AND v.record_id = p.record_id
             WHERE %1$s
             LIMIT %3$s + 1),
        t AS (SELECT count(*) AS total FROM m)
        SELECT a.release_id, NULL::bigint, NULL::text, NULL::jsonb, t.total FROM a, t
        UNION ALL
        SELECT * FROM (
            SELECT a.release_id, r.id, r.mainid, v.source_payload - 'relgion', t.total
              FROM m, t, a, identity_ref.records r, identity_ref.record_versions v
             WHERE t.total <= %3$s AND r.id = m.record_id AND v.id = m.version_id
             ORDER BY m.sort_key, m.record_id OFFSET %4$s LIMIT %5$s) page
        $q$, p_where, p_order, p_cap, p_offset, p_limit)
    USING p_args;
END
$$;

-- Full name: every token (normalized, 2..6) occurs in the normalized fullname, any order (§L-5).
CREATE OR REPLACE FUNCTION identity_ref_lookup.search_fullname(p_tokens text[], p_offset integer, p_limit integer)
    RETURNS TABLE (release_id bigint, record_id bigint, mainid text, source_payload jsonb, total bigint)
    LANGUAGE plpgsql STABLE SECURITY DEFINER
    SET search_path = pg_catalog, pg_temp
AS $$
DECLARE
    tokens text[] := ARRAY(SELECT identity_ref_lookup.normalize_name(t) FROM unnest(p_tokens) t);
    expr constant text := $e$identity_ref_lookup.normalize_name(v.source_payload ->> 'fullname')$e$;
BEGIN
    PERFORM identity_ref_lookup.assert_tokens(tokens, 2);
    PERFORM identity_ref_lookup.assert_page(p_offset, p_limit, 500);
    RETURN QUERY SELECT * FROM identity_ref_lookup.list_page(
        (SELECT string_agg(format('%s LIKE identity_ref_lookup.contains_pattern(($1)[%s])', expr, i), ' AND ')
           FROM generate_subscripts(tokens, 1) i),
        tokens, expr, p_offset, p_limit, 500);
END
$$;

-- Detailed: 2..4 of name1 / father / grandfath / family (NULL = not given), each a partial match in its
-- own field, AND-combined. The parts expression lets the parts trigram index narrow the candidates.
CREATE OR REPLACE FUNCTION identity_ref_lookup.search_parts(p_name1 text, p_father text, p_grandfath text, p_family text,
    p_offset integer, p_limit integer)
    RETURNS TABLE (release_id bigint, record_id bigint, mainid text, source_payload jsonb, total bigint)
    LANGUAGE plpgsql STABLE SECURITY DEFINER
    SET search_path = pg_catalog, pg_temp
AS $$
DECLARE
    keys constant text[] := ARRAY['name1', 'father', 'grandfath', 'family'];
    vals text[] := ARRAY[identity_ref_lookup.normalize_name(p_name1), identity_ref_lookup.normalize_name(p_father),
                         identity_ref_lookup.normalize_name(p_grandfath), identity_ref_lookup.normalize_name(p_family)];
    given text[];
    conds text[] := '{}';
    i integer;
BEGIN
    given := ARRAY(SELECT x FROM unnest(vals) x WHERE x IS NOT NULL AND x <> '');
    PERFORM identity_ref_lookup.assert_tokens(given, 2);
    IF cardinality(given) > 4 THEN
        RAISE EXCEPTION 'IDENTITY_REF_LOOKUP_CRITERIA_INVALID' USING ERRCODE = 'check_violation';
    END IF;
    PERFORM identity_ref_lookup.assert_page(p_offset, p_limit, 500);
    FOR i IN 1..4 LOOP
        IF vals[i] IS NOT NULL AND vals[i] <> '' THEN
            conds := conds
                || format('identity_ref_lookup.name_parts(v.source_payload) LIKE identity_ref_lookup.contains_pattern(($1)[%s])', i)
                || format('identity_ref_lookup.normalize_name(v.source_payload ->> %L) LIKE identity_ref_lookup.contains_pattern(($1)[%s])', keys[i], i);
        END IF;
    END LOOP;
    RETURN QUERY SELECT * FROM identity_ref_lookup.list_page(array_to_string(conds, ' AND '), vals,
        $e$identity_ref_lookup.normalize_name(v.source_payload ->> 'fullname')$e$, p_offset, p_limit, 500);
END
$$;

-- Reverse links (ADR §L-8; disabled in the application until 09 is applied and OD-03 / OD-10 decided).
-- Household: the other records sharing a non-empty householdid. Never implies parenthood or marriage.
CREATE OR REPLACE FUNCTION identity_ref_lookup.household_members(p_householdid text, p_exclude_mainid text, p_offset integer, p_limit integer)
    RETURNS TABLE (release_id bigint, record_id bigint, mainid text, source_payload jsonb, total bigint)
    LANGUAGE plpgsql STABLE SECURITY DEFINER
    SET search_path = pg_catalog, pg_temp
AS $$
BEGIN
    IF p_householdid IS NULL OR p_householdid = '' OR char_length(p_householdid) > 64 THEN
        RAISE EXCEPTION 'IDENTITY_REF_LOOKUP_CRITERIA_INVALID' USING ERRCODE = 'check_violation';
    END IF;
    PERFORM identity_ref_lookup.assert_page(p_offset, p_limit, 100);
    RETURN QUERY SELECT * FROM identity_ref_lookup.list_page(
        $w$(v.source_payload ->> 'householdid') <> '' AND (v.source_payload ->> 'householdid') = ($1)[1]
           AND (v.source_payload ->> 'mainid') IS DISTINCT FROM ($1)[2]$w$,
        ARRAY[p_householdid, p_exclude_mainid],
        $e$identity_ref_lookup.normalize_name(v.source_payload ->> 'fullname')$e$, p_offset, p_limit, 100);
END
$$;

-- Children: records whose fatherid or motherid equals the given National ID.
CREATE OR REPLACE FUNCTION identity_ref_lookup.children(p_mainid text, p_offset integer, p_limit integer)
    RETURNS TABLE (release_id bigint, record_id bigint, mainid text, source_payload jsonb, total bigint)
    LANGUAGE plpgsql STABLE SECURITY DEFINER
    SET search_path = pg_catalog, pg_temp
AS $$
BEGIN
    IF p_mainid IS NULL OR p_mainid !~ '^[0-9]{9}$' THEN
        RAISE EXCEPTION 'IDENTITY_REF_LOOKUP_ID_INVALID' USING ERRCODE = 'check_violation';
    END IF;
    PERFORM identity_ref_lookup.assert_page(p_offset, p_limit, 100);
    RETURN QUERY SELECT * FROM identity_ref_lookup.list_page(
        $w$(((v.source_payload ->> 'fatherid') <> '' AND (v.source_payload ->> 'fatherid') = ($1)[1])
            OR ((v.source_payload ->> 'motherid') <> '' AND (v.source_payload ->> 'motherid') = ($1)[1]))$w$,
        ARRAY[p_mainid],
        $e$identity_ref_lookup.normalize_name(v.source_payload ->> 'fullname')$e$, p_offset, p_limit, 100);
END
$$;

-- ---------------------------------------------------------------- privileges (least privilege)

REVOKE ALL ON ALL FUNCTIONS IN SCHEMA identity_ref_lookup FROM PUBLIC;
GRANT USAGE ON SCHEMA identity_ref_lookup TO famboook_identity_reader;
GRANT EXECUTE ON FUNCTION
    identity_ref_lookup.active_release_id(),
    identity_ref_lookup.by_mainid(text),
    identity_ref_lookup.by_record(bigint),
    identity_ref_lookup.existing_mainids(text[]),
    identity_ref_lookup.search_fullname(text[], integer, integer),
    identity_ref_lookup.search_parts(text, text, text, text, integer, integer),
    identity_ref_lookup.household_members(text, text, integer, integer),
    identity_ref_lookup.children(text, integer, integer)
TO famboook_identity_reader;

RESET ROLE;
