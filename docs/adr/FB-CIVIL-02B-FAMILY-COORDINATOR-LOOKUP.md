# FB-CIVIL-02B — Civil Registry Lookup for the Family Portal and Coordinator Space

**Status:** PROPOSED. Implemented locally only, 2026-10-28. **Not approved for
Production and not approved for real registry data.** Both family-side contexts
are closed by default and independent of each other. Approving this
implementation plan does not approve access to real civil registry data.

**Technical acceptance (recorded separately from the status above):** the local
implementation was technically accepted on 2026-10-28 (owner-approved technical
closure, local commit only). It covers the code, tests and documentation as
written. It is **not** legal, privacy or Production approval: G1, OD-03, OD-08,
OD-09, OD-10, OD-15 and B-OD-1 … B-OD-4 remain open (B-9), and every
family-side context stays closed (B-2).

**Builds on:** `docs/adr/FB-CIVIL-02A-CIVIL-REGISTRY-LOOKUP.md` (local commit
`fa5de94`), which stays in force unchanged for the Staff side. Approved scope
of this phase: *Extended Citizen Data with Controlled Access* for the Family
Portal (household head) and Coordinator Space.

**Not changed:** the read path (`08-lookup.dba.sql`), the optional indexes
(`09`), the reverse-link gate, the Production approval mechanism of 02A, the
Staff permissions, field levels and quota policies.

---

## B-0. Relation to the baseline (conflicts to decide)

| Baseline | What 02B does | Status |
|---|---|---|
| `docs/03` §47e "there is no global National ID search" (OD-09) | The 02A exception, if approved, would now also cover two family-side contexts | PROPOSED; the exception must name the family side explicitly or 02B stays closed |
| `docs/06` §53 explicit "may receive" list of FAMILY_USER, §22b COORDINATOR baseline | `civil-lookup.search` / `.view` are added to FAMILY_USER and COORDINATOR **only while that side's switch is open** (gated, like `import.apply`) | Baseline change PROPOSED; with the default configuration the role baselines are unchanged |
| `docs/11` §23 home information architecture, §8 Coordinator Space | One conditional entry card on the Family home (after the shortcuts) and one in Coordinator Space; the approved bottom navigation is **not** changed | Amendment PROPOSED; with the default configuration nothing is shown |
| OD-15 no linkage between `identity_ref` and `persons` | Nothing links a registry record to a Person, a Family or a membership | Respected |

None of these is resolved by this ADR. Until they are, the family-side
contexts stay closed (B-2).

## B-1. One service, three mounts

The same controller, action, quota, audit and registry read serve:

| Context | Mount | Side boundary (runs first) |
|---|---|---|
| `STAFF` | `/api/v1/civil-registry/lookup` | `auth:sanctum`, `staff.side` (unchanged) |
| `FAMILY` | `/api/v1/family/civil-registry/lookup` | `auth:sanctum`, `family.side`, `family-portal.access`, `family.context` (an eligible household head: docs/11 §30a) |
| `COORDINATOR` | `/api/v1/family/coordinator/civil-registry/lookup` | the above plus `coordinator.space` (own Family context, COORDINATOR, `coordinator-space.access`, an effective scope assignment) |

The endpoints under each mount are the 02A ones (status, three searches,
page, open record, open link, reverse-link list). Administration stays on the
Staff mount only. The mounts follow the side separation of `docs/06` §22b:
the Staff group refuses every family-side session, so a single shared URL
would have needed a route outside every side boundary.

- `civil-lookup.context:{CONTEXT}` fixes the context per mount. It refuses
  if the side boundary did not run first (fails closed).
- `civil-lookup.enabled:{CONTEXT}` checks the gate of that context before the
  route limiter.
- Every reference (search, record, link, links) is version 2. It binds the
  user, the release, the expiry **and the context**. A reference issued on one
  side is `REFERENCE_INVALID` on any other, even for the same user.
- Every audit row records `lookup_context` (STAFF / FAMILY / COORDINATOR)
  next to `actor_side`. A database CHECK refuses a family-side context for a
  Staff actor.

## B-2. Gates (all server-side)

A family-side lookup needs **all** of the following:

1. **The global gate (02A §L-1).**
   - `CIVIL_LOOKUP_ENABLED`.
   - In production, also `CIVIL_LOOKUP_PRODUCTION_APPROVAL`.
2. **The side switch.** `CIVIL_LOOKUP_FAMILY_ENABLED` or
   `CIVIL_LOOKUP_COORDINATOR_ENABLED`. Both default to `false` and each is
   independent of the other.
3. **The side's own production approval.** In production only,
   `CIVIL_LOOKUP_FAMILY_APPROVAL` or `CIVIL_LOOKUP_COORDINATOR_APPROVAL` in the
   PHP **process** environment. These are read at run time and are never
   taken from `.env` or cached configuration.
4. **The field-profile approval.** In production only,
   `CIVIL_LOOKUP_EXTENDED_PROFILE_APPROVAL` (B-5), also in the process
   environment.

No single variable opens a family-side context. A UI toggle cannot open one,
and neither can a per-user grant. The approval references are issued only
after the decisions in B-9.

## B-3. Authorization of a family-side lookup

`CivilLookupAccess::evaluate($user, $context, $permission)` checks every
condition below, on every call:

| # | Condition | FAMILY | COORDINATOR |
|---|---|---|---|
| 1 | Gate of the context (B-2) | ✓ | ✓ |
| 2 | Account side | family-side | family-side and holds COORDINATOR |
| 3 | Boundary (route) | eligible household head (`family.context`) | effective assignment (`coordinator.space`) |
| 4 | Permission | `civil-lookup.search` / `.view` (gated role permission) | same |
| 5 | Individual | not REVOKED, **and** an explicit GRANTED row naming `FAMILY` | not REVOKED, **and** an explicit GRANTED row naming `COORDINATOR` |
| 6 | Role policy | FAMILY_USER enabled | COORDINATOR enabled |

Consequences:

- Being a household head does not grant a lookup, and neither does a
  Coordinator assignment. Holding the role alone is not enough either.
- A grant for the Family Portal does not open Coordinator Space, and a grant
  for Coordinator Space does not open the Family Portal.
- A user who is both a household head and a coordinator keeps one identity,
  one per-user row and one counter (B-6).
- The family-side permissions come only from the roles, and only while the
  side switch is open (`RolePermissionSeeder::CIVIL_LOOKUP_FAMILY_SIDE_PERMISSIONS`).
- A family-side role never holds `civil-lookup.view-full`, `.usage.view` or
  `.manage`.
- `famboook:verify-permissions` reports as CRITICAL:
  - a family-side role holding search or view while its switch is closed;
  - a family-side role missing search or view while its switch is open;
  - any direct (model) grant of a `civil-lookup.*` permission.

## B-4. Individual access administration

`civil_lookup_user_access` holds one row per user:

- **DEFAULT** (no row): for Staff, the role decides. A family-side context is
  closed.
- **REVOKED**: blocks every side at once. The `sides` value is NULL.
- **GRANTED** with `sides` set to `FAMILY`, `COORDINATOR` or
  `FAMILY,COORDINATOR`. This is the explicit individual grant, and it never
  overrides a gate, a permission, a role policy, the quota or the field
  profile.

Changes go through `SetCivilLookupUserAccessAction`, which re-checks
`civil-lookup.manage`. It also:

- refuses GRANTED for a Staff account;
- refuses a side whose switch is closed, so nothing can be granted by default;
- refuses COORDINATOR for an account without that role;
- refuses sides on REVOKED or DEFAULT.

Every change is recorded append-only in `civil_lookup_policy_changes` with
old and new access and sides. Database CHECKs enforce the shape of `sides`.
A family-side account has no email, so the administration screen addresses it
by **person code** (the account currently linked to that Person). The screen
offers GRANTED only for the sides the server lists as grantable. Roles,
permissions and Coordinator assignments are never touched.

## B-5. Field visibility: `EXTENDED_CONTROLLED`

Field visibility is enforced in the API (`CivilLookupFieldPolicy`). Every
level is an explicit allowlist, so a source field that is not listed is never
returned, including any new source column.

| Level | Used by | Fields |
|---|---|---|
| `FULL` | Staff with `civil-lookup.view-full` | unchanged (02A §L-4) |
| `STANDARD` | other Staff | unchanged |
| `EXTENDED_CONTROLLED` | `FAMILY` and `COORDINATOR` contexts (mapped separately in `CONTEXT_LEVELS`) | `mainid` (always masked), `fullname`, `name1`, `father`, `grandfath`, `family`, `gender`, `datebirth`, `age`, `maritalst`, `govern`, `city`, `birthplace` |

- **Links:**
  - The father, mother and spouse references are the record's links. Their
    identifiers are always masked, and opening one is a new, authorized,
    counted lookup.
  - The household reference is the HOUSEHOLD link. It stays DISABLED, as do
    the children, until 02A's reverse-link conditions are met.
- **Never returned:**
  - `relgion`;
  - the raw payload;
  - any unlisted source field, including `householdid`, `householdrel`,
    `phonenum`, the social and reference sections, `deathdate`, `presonstate`,
    `street` and `prevfamily`;
  - internal identifiers;
  - fingerprints;
  - administrative metadata.
- **Codes:** values are returned raw ("غير متوفر" when empty) and codes are
  never interpreted.
- **Approval:** the allowlist is a **candidate** subject to field-level
  privacy approval (`CIVIL_LOOKUP_EXTENDED_PROFILE_APPROVAL`). The two
  family-side contexts share the profile today but are mapped separately, so
  each can be narrowed if their legal entitlements differ.

## B-5a. Proposed amendment: full National ID at `EXTENDED_CONTROLLED` (2026-10-29, NOT implemented)

**Owner-approved functional direction.** The project owner approves showing
the full National ID (`mainid`) in the authorized person-detail view of the
FAMILY and COORDINATOR contexts, **subject to B-OD-1 and the applicable
privacy controls**.

**Status of the prerequisites:**

| Prerequisite | Status |
|---|---|
| Owner functional direction | Recorded (this section) |
| B-OD-1: field-level privacy approval of `EXTENDED_CONTROLLED`, including the masking format | **OPEN**: no approval is documented |
| G1 / OD-08: lawful basis and purpose for the family-side lookup | **OPEN** (B-9) |
| OD-09: the `docs/03` §47e exception covering these contexts | PROPOSED, not approved (B-9) |

**Implemented behavior (enforced in the API by `CivilLookupFieldPolicy`;
updated by FB-CIVIL-02F, 02A §L-4b and §L-6c):**

- **`FULL`** (Staff with `civil-lookup.view-full`): the record's National ID
  and the linked IDs in clear (unchanged).
- **`STANDARD`** (other Staff): the opened record's own National ID in clear,
  by owner decision (2026-10-29). Its first disclosure each Gaza day is
  charged. Linked IDs and lists stay masked.
- **`EXTENDED_CONTROLLED`** (FAMILY, COORDINATOR): **masked** (`*****NNNN`),
  unchanged.
- **Every result list, at every level:** masked. Searching never discloses a
  full ID.
- **Refusals** (revoked, not allowed, gate closed): no record data at all.
- **The frontend** shows the value exactly as the API returns it and never
  rebuilds an ID from a masked one.

**Intentionally blocked pending approval.** Full `mainid` at
`EXTENDED_CONTROLLED` stays blocked until B-OD-1 is recorded as approved,
together with the legal basis (G1 / OD-08) and OD-09. When approved, the
change is limited to `CivilLookupFieldPolicy`:

- `mainid` of the opened record unmasked for the two family-side levels.
- The linked IDs (father, mother, spouse) stay masked unless the approval
  names them.
- Result lists stay masked.
- Each context can still be mapped separately (`CONTEXT_LEVELS`) if their
  entitlements differ.
- No new permission, gate or migration is needed. Audit and quota are
  unchanged: the audit never stores a National ID, and opening a listed
  record is not counted.

The approval must state whether it also covers a coordinator viewing people
outside their own household. Today no record-level distinction exists (no
link between `identity_ref` and `persons`, OD-15).

## B-5b. Eye-icon reveal of a National ID (FB-CIVIL-02G, owner decision 2026-10-29)

**Owner decision.** Every user holding the Civil Registry Lookup permission
of a side (Staff, Family Portal, Coordinator Space) may reveal a complete
National ID. The reveal reuses the existing interaction of «بياناتي
الشخصية» (Family Portal → my personal information → National ID): the
value is masked, an eye icon reveals it, and pressing again hides it. It
applies to:

- result cards of every search mode, page and reverse (family / children)
  list;
- the opened person's own `mainid`;
- each related person's `fatherid`, `motherid` and `partnerid`, each
  independently.

No family-relationship requirement applies: the side's lookup permission is
the authority.

**Implemented behavior (local):**

- **Never automatic.** Lists and records keep their masking:
  - `summary()` always masks;
  - `EXTENDED_CONTROLLED` and the `STANDARD` links stay masked;
  - 02F is unchanged.

  Nothing is disclosed in bulk. The automatic per-value authorizer first
  drafted for 02G was replaced by this explicit reveal.
- **`POST …/identifiers/reveal {ref}`** on each mount reveals **one** value.
  - It is authorized like opening a record: gates, side, `civil-lookup.view`,
    REVOKED / GRANTED and the role policy, re-checked on every call.
  - The reference names it: a `RECORD` reference (result card, list entry or
    opened record) or a `LINK` reference (a related person present in the
    release). References are bound to the user, context, release and expiry.
    A person not in the release has no reference, so there is no reveal.
- **Quota.** The first disclosure of a record each Gaza day costs one unit.
  It is deduplicated with the 02F disclosures (`civil_lookup_record_disclosures`),
  so revealing again, or opening the same record, is free.
  - Free: `FULL`, and the record of an exact National ID search.
  - Refused at the limit with 429 `QUOTA_EXHAUSTED`, revealing nothing.
  - Rate limits unchanged.
- **Audit.** `ID_REVEALED` (migration `2026_10_31_100000` adds it to the
  PostgreSQL CHECK) records the actor, context, outcome, release and record
  ids, **never the value**.
- **Switches.**
  - Staff may always reveal.
  - Each family side has its own switch, **off by default** and
    independent: `CIVIL_LOOKUP_FAMILY_ID_REVEAL_ENABLED` and
    `CIVIL_LOOKUP_COORDINATOR_ID_REVEAL_ENABLED`.
  - In production each side also needs its own approval reference in the
    process environment: `CIVIL_LOOKUP_FAMILY_ID_REVEAL_APPROVAL` and
    `CIVIL_LOOKUP_COORDINATOR_ID_REVEAL_APPROVAL`.
  - The status API exposes `capabilities.reveal_ids`.
- **Frontend.** `RevealableId` follows `SensitiveValue` exactly: the same
  states, Eye / EyeOff control and accessible names. The value is kept in
  component state only, never cached or stored. The reveal state resets with
  the record, the page or the view.

**Approval status (unchanged).** B-OD-1 (field-level privacy approval,
including the masking format) is OPEN. G1 / OD-08 are OPEN. OD-09 is
PROPOSED. OD-15 is approved as no linkage, and none is introduced.

**Production-enabled behavior.** Staff reveal ships with the code. The
family-side reveal is **off** until its switch, and in production its
approval reference, are set.

**Before enabling the family-side reveal:**

- the owner's record that B-OD-1 / G1 / OD-08 cover a complete National ID
  revealed on explicit request, or their approval;
- the switch and the approval reference, set by the system administrator.

## B-6. Shared daily quota

The 02A quota engine is used unchanged:

- one atomic counter per user per Asia/Gaza day;
- the same check → execute → consume sequence and charging rules;
- the same rate limiter.

The counter is per user, never per context. Searches on the Family Portal and
in Coordinator Space draw on one allowance, and switching sides never yields
a second one. The daily limit follows the existing documented resolution
(02A §L-6): the most generous **enabled** policy among all the user's roles.
Defaults:

- FAMILY_USER: 5, disabled;
- COORDINATOR: 5, disabled;
- Staff: unchanged.

## B-7. Frontend

The shared presentation is reused: `components/civil-lookup/` (the page,
`CitizenDetail`, `QuotaIndicator`). Its hooks take a `space`
(staff / family / coordinator) that selects the mount. Only the header
changes, using the Family Portal heading style with a shared-quota note; the
coordinator header also says the lookup does not concern the scope's
families. Nothing is kept in a URL or in browser storage, and nothing in the
query cache.

| Surface | Route | Shown when |
|---|---|---|
| Family entry card | `/family` (after the shortcuts) | `/family/me` `civil_lookup.family` |
| Family page | `/family/civil-registry` | same flag; otherwise a neutral notice and no request |
| Coordinator entry card | `/family/coordinator` | `/family/me` `civil_lookup.coordinator` |
| Coordinator page | `/family/coordinator/civil-registry` (behind `CoordinatorGate`) | same flag |

The `/family/me` flags are the side boundary plus the same `evaluate()` call
the API runs. They are visibility only. The bottom navigation and the Staff
screens are unchanged. Family-side accounts never reach the administration.

## B-8. Data and migration

`2026_10_29_090000_extend_civil_lookup_for_family_side` makes these changes:

- `civil_lookup_user_access.sides`, with a CHECK that GRANTED always names
  its sides and REVOKED never does;
- `civil_lookup_policy_changes.old_sides` / `new_sides`, with a CHECK on the
  values;
- `civil_lookup_audit_events.lookup_context` (default `STAFF`, indexed), with
  CHECKs on its values and that a family-side context has a family-side
  actor.

No new lookup table, no second quota and no change to `identity_ref`. The
append-only triggers are untouched, because adding columns is DDL, not
UPDATE. `down()` refuses once any family-side grant, history or audit row
exists.

## B-9. Open decisions (preserved; none is approved by this ADR)

| Id | Decision | Status | Blocks |
|---|---|---|---|
| G1 / OD-08 / D-15 | Lawful basis, purpose limitation, retention and Privacy Policy coverage for a lookup **by household heads and coordinators** (a new purpose beyond the Staff one) | OPEN | Any family-side approval reference |
| OD-09 | The `docs/03` §47e exception, explicitly covering the family-side contexts | PROPOSED (02A), not approved | Every lookup |
| B-OD-1 | Field-level privacy approval of `EXTENDED_CONTROLLED` (each field; masking format). The owner direction for a full `mainid` is recorded in B-5a; it is not a privacy approval | OPEN | `CIVIL_LOOKUP_EXTENDED_PROFILE_APPROVAL`; full `mainid` at `EXTENDED_CONTROLLED` (B-5a) |
| B-OD-2 | Eligibility policy: which household heads and coordinators may be granted, by whom, for how long (grants do not expire today; they are revoked explicitly) | OPEN | Granting in production |
| B-OD-3 | `docs/06` §53 / §22b baseline change (gated lookup permissions on FAMILY_USER / COORDINATOR) | PROPOSED | Opening a side switch |
| B-OD-4 | `docs/11` amendment (entry cards on the Family home and in Coordinator Space) | PROPOSED | Showing the entries |
| OD-03 / OD-10 | Meaning of parent and spouse references; genealogy scope | OPEN | Reverse links (stay disabled) |
| OD-15 | No linkage between `identity_ref` and `persons` | Approved: none | Respected; nothing links |
| Privacy | Display of `relgion` | OPEN | Stays excluded |
| Ops | Applying 08 / 09 to Production; the lookup login and credentials | Not approved | Any Production use |

## B-10. Not in scope

The following are not included:

- automatic association of registry records with Famboook persons or
  families;
- a family-side administration screen;
- grant expiry;
- notifications to the looked-up citizen;
- any change to the Production approval mechanism of 02A.
