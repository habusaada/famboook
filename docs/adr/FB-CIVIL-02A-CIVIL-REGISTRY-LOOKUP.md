# FB-CIVIL-02A — Civil Registry Smart Lookup (Staff Portal)

**Status:** PROPOSED. Implemented locally only. **Not approved for Production.**
The lookup is OFF by default; Production additionally needs a recorded approval
reference (§L-1). The baseline exception (§L-0) is proposed, not approved.

**Technical acceptance (recorded separately from the status above):** the local
implementation was technically accepted on 2026-10-28 (owner-approved
technical closure, local commit only). It covers the code, tests and DBA
scripts as written. It is **not** legal, privacy or Production approval.
G1, OD-03, OD-08, OD-09, OD-10 and OD-15 remain open, and Production activation
stays blocked (§L-2 performance gate, §L-1 approval reference).

**Scope:** Staff Portal only. Family Portal and Coordinator Space (FB-CIVIL-02B)
are out of scope; the backend is shared and family-side access stays closed.

**Inputs:** FB-CIVIL-02 Phase 1 Discovery; `docs/adr/FB-CIVIL-01A-*`;
`docs/audits/FB-CIVIL-01A-OPEN-DECISIONS.md`; `deploy/sql/identity-ref/00–07`.

---

## L-0. The baseline exception (OD-09) — PROPOSED

`docs/03` §47e states: *"there is no global National ID search. IDs are never
stored, returned, put in URLs, flashed or logged."* OD-09 records that a
lookup (then called FB-CIVIL-01B) needs an explicit baseline change with
anti-enumeration and audit design. This ADR is that design. Until the owner
approves the exception and `docs/03` §47e is amended:

- the rule continues to apply to every other feature (Assistance delivery,
  Family Portal, Coordinator Space); nothing here weakens it;
- the lookup ships disabled (`CIVIL_LOOKUP_ENABLED=false`).

Even when enabled, the lookup keeps the rest of the §47e intent: National IDs
are never put in URLs, never logged, and never stored in clear (audit keeps a
keyed fingerprint only, §L-10).

## L-1. Gates

| Gate | Default | Effect |
|---|---|---|
| `civil_lookup.enabled` (`CIVIL_LOOKUP_ENABLED`) | `false` | Every lookup endpoint answers 503 `LOOKUP_DISABLED`; no registry read happens |
| Production approval (`CIVIL_LOOKUP_PRODUCTION_APPROVAL`, process environment) | unset | In `production`, the lookup stays disabled without a well-formed reference, issued only after G1 / OD-08 and OD-09 are recorded |
| `civil_lookup.reverse_links_enabled` | `false` | Household members and children (reverse lookups) answer `LINKS_DISABLED` (§L-8) |
| `civil_lookup.family_side_enabled` | `false` | FAMILY_USER / COORDINATOR are never eligible in 02A (§L-3) |
| Lookup database login | not configured | 503 `LOOKUP_NOT_CONFIGURED`; never a fallback to the application role |

## L-2. Database access path

- `deploy/sql/identity-ref/08-lookup.dba.sql` (DBA; local only until approved):
  - login `famboook_identity_lookup_login` (created NOLOGIN, NOINHERIT,
    CONNECTION LIMIT 10, `statement_timeout = 5s`), member of the reserved
    group `famboook_identity_reader` (00-roles: "reserved for FB-CIVIL-01B");
  - schema `identity_ref_lookup`, owned by `famboook_identity_owner`, holding
    `SECURITY DEFINER` functions with a fixed `search_path`;
  - the reader gets **EXECUTE on those functions only**: no SELECT on
    `records`, `record_versions`, `release_presence` or `active_records`, no
    write privilege of any kind, nothing on staging, monitor or import objects.
- Every function reads **through `identity_ref.active_release`**, joins
  `release_presence` on its primary key `(release_id, record_id)` and takes the
  version named by `release_presence.record_version_id` — never the highest
  `version_no`. A BUILDING or ABANDONED release is never visible.
- Result limits are enforced **inside** the functions (page ≤ 25, match cap
  500, ≤ 6 name tokens), so a compromised application session still cannot
  enumerate the registry in bulk.
- The application connects as `identity_ref_lookup` (credentials from a
  protected file, `civil_lookup.credentials_file`, never `.env` or cached
  configuration), runs `SET ROLE famboook_identity_reader`, and refuses a
  superuser login or one that can act as the identity owner, writer or monitor
  (the `IdentityImportMonitor` pattern). Silent reconnects are refused.
- `09-lookup-indexes.dba.sql` (DBA, `CREATE INDEX CONCURRENTLY`; **not
  approved for Production**): trigram GIN indexes for name search and btree
  expression indexes for reverse links (§L-5, §L-8). Without them the name
  search is correct but slow; reverse links stay disabled.
- **Measured locally (PG 18, 2.4 M synthetic records, warm runs):** National ID
  1–2 ms. Without 09, name and parts searches take 18–24 s, so they exceed the
  login's 5 s `statement_timeout` and fail as `UNAVAILABLE`. With 09 **and a
  UTF-8 libc `LC_CTYPE`** they take 24 ms warm and 250–660 ms cold, household
  takes 25 ms and children 7 ms.
- **Precondition:** under `LC_CTYPE 'C'`, `pg_trgm` extracts no trigrams from
  Arabic. The indexes then build at a trivial size, help nothing, and the
  timings match the no-index case. `09` refuses to run in that case
  (`IDENTITY_REF_LOOKUP_CTYPE_UNSUITABLE`).
- **Gate:** name search in Production requires the DBA to confirm the
  production `datctype` (read-only) and `09` to be approved and applied.
  National ID search does not depend on either.

## L-3. Authorization

Permissions (`RolePermissionSeeder`; checked on every endpoint, never only in
the UI):

| Permission | Roles | Meaning |
|---|---|---|
| `civil-lookup.search` | all six Staff roles | may search (results list) |
| `civil-lookup.view` | all six Staff roles | may open a record (standard fields) |
| `civil-lookup.view-full` | SUPER_ADMIN, ADMINISTRATOR | full field set (§L-4) |
| `civil-lookup.usage.view` | SUPER_ADMIN, ADMINISTRATOR | quota usage statistics |
| `civil-lookup.manage` | SUPER_ADMIN | role policies and per-user access |

A user may look up only if **all** hold:

1. the lookup is enabled (§L-1);
2. the user is active and Staff-side (`staff.side`); family side is closed in 02A;
3. the user holds `civil-lookup.search` (and `civil-lookup.view` to open a record);
4. the user's **role policy** is enabled (`civil_lookup_role_policies`);
5. the user is not individually **revoked** (`civil_lookup_user_access`).

Holding a permission is the capability; the role policy is the operational
switch; the per-user row is the individual override. A per-user `GRANTED` row
is reserved for 02B (family side) and has no effect in 02A. Being a
Coordinator never implies lookup access, and changing lookup access never
touches any other permission or a Coordinator assignment.

## L-4. Field visibility (API-enforced)

The source has 36 raw fields (`identity_ref.source_columns()`). Values are shown
raw: undocumented codes (`gender`, `maritalst`, `householdrel`, `govern`,
`city`, `worker`, `select`, …) are never interpreted. Empty or NULL →
"غير متوفر".

| Level | Who | Fields |
|---|---|---|
| RESULT (list) | `search` | `fullname`, `gender`, `datebirth`, `govern`, `city`; National ID masked |
| STANDARD | `view` | the names (`name1`, `father`, `grandfath`, `family`, `fullname`), `gender`, `datebirth`, `age`, `birthplace`, `maritalst`, `govern`, `city`, `householdrel`; National ID and linked IDs masked |
| FULL | `view-full` | every field **except `relgion`**; National ID and linked IDs in clear |

`relgion` is special-category data (P0): **excluded at every level** until a
separate privacy decision (OD-08). It is never read into a response.

## L-5. Search rules

- **By National ID:** the existing validator (`isValidNationalId` /
  canonical 9 digits, Arabic-Indic digits normalized), kept as a string;
  exact `records.mainid` match (UNIQUE btree); at most one result. POST body
  only.
- **Normalization** (identical in PHP `ArabicNameNormalizer` and SQL
  `identity_ref_lookup.normalize_name`): remove diacritics (U+064B–U+065F,
  U+0670) and tatweel (U+0640); أ إ آ ٱ → ا; collapse whitespace; trim.
  **ة/ه and ى/ي are NOT merged** in this version. Display always uses the
  original source value.
- **Full name:** 2–6 tokens after normalization; each ≥ 2 letters; at least
  one ≥ 3. Every token must occur in the normalized `fullname` (AND, any
  order), which also matches compound names written with or without a space
  ("عبد الله" / "عبدالله").
- **Detailed:** 2–4 of `name1`, `father`, `grandfath`, `family`; each given
  field must occur in its own normalized field (partial match), combined with
  AND; same length rules.
- **Breadth cap:** a search matching more than **500** records returns
  `TOO_BROAD` with no rows ("refine the search"). It still counts as a lookup
  (anti-enumeration, §L-6). Otherwise results are ordered by normalized name,
  25 per page, server-side.

## L-6. Daily quota

- Policies live in `civil_lookup_role_policies` (one row per supported role):
  `enabled`, `daily_limit` (NULL = unlimited, allowed only where
  `unlimited_allowed`; SUPER_ADMIN is always unlimited). Defaults:
  SUPER_ADMIN unlimited; ADMINISTRATOR 200; DATA_ENTRY 50; REPORTS_VIEWER,
  REVIEWER, SOCIAL_WORKER disabled; COORDINATOR 5 and FAMILY_USER 5, both
  **disabled** (02B). No `HOUSEHOLD_HEAD` role (docs/06 §22).
- **One counter per user per day** (`civil_lookup_daily_usage`, UNIQUE
  `(user_id, usage_date)`), whatever their roles; `usage_date` is the
  **Asia/Gaza** calendar date (DST-aware), reset at local midnight.
- **Multiple eligible roles (02B):** the most generous enabled policy applies
  (unlimited > higher limit), deterministically; still one shared counter.
- **Charged (1 each):** an executed search by ID, full name or parts,
  including `NOT_FOUND` and `TOO_BROAD`; opening a linked citizen; a reverse
  link list.
- **Not charged:** validation failures (separately rate limited, §L-11);
  further pages of the same search (search reference); opening a record that
  was in an authorized result list (record reference); returning to a record
  already displayed (client memory, no request); internal failures.
- **Atomicity:** check → execute → consume. The consume is one statement
  (`INSERT … ON CONFLICT (user_id, usage_date) DO UPDATE SET used = used + 1
  WHERE used < limit RETURNING used`); when it returns no row the result is
  withheld and `QUOTA_EXHAUSTED` (429) is answered. A failed execution
  consumes nothing. Two concurrent requests can never exceed the limit.

## L-7. Opaque references

Search, record and link references are Laravel-encrypted (authenticated,
`APP_KEY`) payloads bound to the user, the release and an expiry (30
minutes). They travel in POST bodies only, never in URLs. A reference of
another user, an expired one or one from a release that is no longer active
is refused (`REFERENCE_INVALID` / `RELEASE_CHANGED`). The frontend keeps
results and the breadcrumb in memory only (no URL parameter, no browser
storage) and clears them on sign-out.

## L-8. Linked citizens

| Relation | Source | 02A |
|---|---|---|
| Father / Mother | `fatherid` / `motherid` → `mainid` | **enabled** |
| Spouse | `partnerid` → `mainid` | enabled; empty in every assessed row (P0), so normally "غير متوفر" |
| Household members | same `householdid` (reverse) | **disabled** (`reverse_links_enabled`) |
| Children | records whose `fatherid`/`motherid` = this `mainid` (reverse) | **disabled** |

- Whether a referenced ID exists in the active release is resolved when the
  record is shown (no charge, no data beyond existence). A missing reference
  is shown as "غير موجود في الإصدار المنشور" and is not clickable
  (12.5 % of `fatherid` values do not resolve, P0; OD-03 OPEN).
- Opening a linked citizen is a new lookup: authorization and quota are
  checked again, and it is audited.
- A shared `householdid` is never presented as parenthood or marriage.
- Reverse links stay disabled until (a) `09-lookup-indexes.dba.sql` is
  applied and benchmarked and (b) OD-03 / OD-10 (genealogy out of scope for
  V1) are decided.

## L-9. Release consistency

Every response carries `release_id`. References are bound to it. When the
active release changes, an open reference answers `RELEASE_CHANGED` and the
user starts a new search; records of two releases are never mixed in one
navigation.

## L-10. Audit

`civil_lookup_audit_events`: append-only (model guard + PostgreSQL trigger),
no foreign key into `identity_ref`. Columns: actor, side, event type, outcome,
`release_id`, `record_id` (identity_ref internal id, not a National ID),
`query_fingerprint` (HMAC via `KeyedFingerprint`, context
`civil-lookup.query.v1`), result count, quota used after, hashed IP, time.
Never stored: a National ID, a name, a search term or any payload. Policy and
per-user access changes are recorded in `civil_lookup_policy_changes`
(append-only; old and new values, actor).

## L-11. Rate limits (independent of the quota)

`civil-lookup` limiter: per user per minute and per hour (config). Invalid
requests (validation failures) count against a separate per-user limiter
and are refused at the door once it is exceeded.

## L-12. Responses and errors

`Cache-Control: no-store, private` on every lookup response and error. Errors
are fixed codes (`LOOKUP_DISABLED`, `LOOKUP_NOT_ALLOWED`, `QUOTA_EXHAUSTED`,
`TOO_BROAD`, `REFERENCE_INVALID`, `RELEASE_CHANGED`, `LINKS_DISABLED`, …)
with no database text. The application log never receives a payload, a name,
a National ID or a search term.

## L-13. Future (FB-CIVIL-02B)

The same service, quota and audit serve the Family Portal and Coordinator
Space. Family-side lookup needs: the access policy and field scope for those
users, the legal basis (G1 / OD-08), `family_side_enabled`, an explicit
per-user GRANTED row, and an enabled role policy. FAMILY_USER and COORDINATOR
are never granted lookup automatically.

**2026-10-28:** proposed and implemented locally (closed by default) in
`docs/adr/FB-CIVIL-02B-FAMILY-COORDINATOR-LOOKUP.md`. The Staff side described
here is unchanged.

## Open decisions (preserved; none is approved by this ADR)

| Id | Decision | Status | Blocks |
|---|---|---|---|
| G1 / OD-08 / D-15 | Lawful basis, purpose limitation (a lookup is a new purpose), retention, Privacy Policy | OPEN in the decision registers | Enabling the lookup on real data |
| OD-09 | Exception to `docs/03` §47e (no global National ID search) | PROPOSED here | Enabling the lookup |
| OD-03 | Do `fatherid` / `motherid` / `partnerid` reference `mainid`? | OPEN | Reverse links; meaning of parent links |
| OD-10 | Genealogy out of scope for V1 | OPEN | Reverse links (children, household) |
| OD-15 | Linkage between `identity_ref` and `persons` | Approved: none | Nothing here links to `persons` |
| Privacy | Display of `relgion` | OPEN | `relgion` stays excluded |
| Ops | Applying 08 / 09 DBA scripts to Production | Not approved | Any Production use |
