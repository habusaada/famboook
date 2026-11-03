# FB-FAMILY-ADD-01 — Direct Admission of Civil-Verified Sons and Daughters

**Status:** decisions D-01 … D-06 **APPROVED by the project owner (2026-11-01)** as
architecture. **NOT implemented, NOT activated, NOT approved for real registry data or
Production.** Every activation gate in §12 is OPEN.

**Implementation status (ADD-WP-04 and ADD-WP-05 committed locally in `a2bfbdb`, ADD-WP-06A in `219e0e2`, ADD-WP-06B-1 in
`3bb64f4`, ADD-WP-06B-2 in `ec8f647`, ADD-WP-06B-3 in `2b2ce02`, ADD-WP-06B-4 in `9a05e30`, not pushed; ADD-WP-07B-1 local,
uncommitted; nothing deployed).**

- **Implemented:** the flag `family_registry_admission.mode` (`FAMILY_REGISTRY_ADMISSION_MODE`, default and fail-closed
  `OFF`, read through `FamilyRegistryAdmissionPolicy`, read by nothing yet); the permission definition
  `family-member.admit-from-registry`, assigned to no role and checked nowhere; the evidence and event tables of §9
  (migration `2026_11_01_100000_create_family_registry_admission_tables`), which nothing reads or writes.
- **Implemented in ADD-WP-05:** an internal strict value adapter, a fail-closed proof-of-life evaluator
  and a read-only eligibility evaluation, in `App\Support\FamilyPortal\RegistryAdmission` (§5a). Nothing calls them yet.
- **Implemented in ADD-WP-06A:** an internal, read-only discovery service over synthetic sources only, with privacy-safe
  classification and bounded, deterministic pagination (§5b). Nothing calls it.
- **Implemented in ADD-WP-06B-1:** the page's registry facts read in one batch (§5b, "Batch facts").
- **Implemented in ADD-WP-06B-2:** the opaque admission reference service (§7a). Nothing issues or reads references yet.
- **Implemented in ADD-WP-06B-3:** the guarded discovery route (§7b). It is unavailable in operation: the
  flag is `OFF`, no role holds the permission, and **no registry source is bound**, so even an open gate answers 503 without
  reading anything.
- **Implemented in ADD-WP-06B-4:** the precise source contract and a synthetic-only adapter under
  `tests/` (§7c). The head's record is now read in the page's release.
- **Implemented in ADD-WP-07B-1 (local, uncommitted):** the admission audit and integrity foundation (§9a): event CHECKs, a
  TRUNCATE guard, the reference fingerprint context and the admission audit helpers. Nothing calls them.
- **Not implemented:** discovery against the real civil registry (no adapter over it exists and none is bound, so the route is
  not operational), issuing `ref` in a response, the Family Portal UI, the admission action and route,
  pilot activation.
- **Unchanged:** D-01 … D-06. Gates G-01 … G-07 remain OPEN, and every mode other than `OFF` stays unapproved.

**Scope of this document:** the household head of an authenticated Family may discover
their sons and daughters in the published civil registry and, after declaring that each
one actually belongs to the household, admit an eligible one directly, without an
`ADD_FAMILY_MEMBER` request.

**How to read it:** each section says whether its content is an **approved decision**, a
**proposal** for later work packages, or an **open gate**. Proposed names, limits and
schemas have no runtime effect and can change in review.

**Builds on, and does not rewrite:**
`docs/adr/FB-CIVIL-02A-CIVIL-REGISTRY-LOOKUP.md`,
`docs/adr/FB-CIVIL-02B-FAMILY-COORDINATOR-LOOKUP.md`, `docs/11` FP-ADR-076.

---

## 1. Approved decisions

| Id | Decision (owner, 2026-11-01) |
|---|---|
| D-01 | A narrowly scoped exception permits direct admission without `ADD_FAMILY_MEMBER` review. Every other Change Request workflow stays as it is |
| D-02 | No permanent civil `record_id`-to-Person linkage is persisted. Only a keyed reference fingerprint and admission evidence are stored, without the source record id |
| D-03 | Parenthood uses `fatherid` / `motherid` matching the authenticated household head's National ID, only after their semantics and their relationship to `mainid` are verified |
| D-04 | An independent feature flag `family_registry_admission.mode` takes `OFF`, `PILOT` or `ON`, default `OFF`. It is not implemented or enabled by this ADR |
| D-05 | Discovery does not consume the general civil lookup quota. It has independent rate limiting and auditing |
| D-06 | An active living membership is never created automatically for a deceased child. Uncertain death status fails closed |

**Business rules approved with them:**

- all sons and daughters are in scope, regardless of age or marital status;
- the household head explicitly declares actual household membership for each child;
- duplicate or conflicting memberships are refused;
- nobody is transferred automatically between households;
- only the published civil registry release is read, and it is never modified;
- National ID integrity and existing RBAC are preserved;
- no Production change.

**Cross-reference (2026-11-01, FB-FAMILY-RECON-01, `docs/adr/FB-FAMILY-RECON-01-MEMBERSHIP-RECONCILIATION.md`).** D-07 there confirms that
parentage is independent of household membership: a discovered child stays visible when married or
head of an independent household, and direct admission never transfers anyone. E-8 and the single
`NOT_ELIGIBLE` answer are unchanged. Conflicts between households go through that separate central
workflow. D-01 … D-06 and gates G-01 … G-07 are unchanged here, and all gates remain OPEN.

## 2. Exact scope of the exception (D-01)

The exception applies only when **all** of these hold:

1. the actor passes `family.context`: an eligible household head of an ACTIVE Family;
2. the target is that actor's own Family, resolved by the server;
3. the person is found through the discovery flow of this ADR, never from a typed value;
4. the verified relationship is son or daughter of the household head;
5. the head makes the explicit declaration of §6 for that person;
6. every eligibility check in §5 passes at admission time;
7. the flag allows it (D-04) and every gate in §12 is closed.

Anything else stays with the existing path. A person who fails any condition may still be
proposed through `ADD_FAMILY_MEMBER`, under its existing rules.

**Not covered:** spouses, parents, siblings and other relatives; newborns not yet in the
published release (`BIRTH_REPORT` keeps its own future type); the Coordinator channel;
Staff flows; any transfer, merge, household-head change or edit of an existing Person.

## 3. Baseline clauses affected

Original text stays in place. Only the clauses below are narrowed, and only for §2.

| Clause | Text kept | Effect |
|---|---|---|
| `docs/03` §66 | "Substantive changes use controlled Change Requests" | §82a is the one exception. The admission is still a controlled Domain Action |
| `docs/03` §4 | "Family User submissions must not silently overwrite canonical data" | **Not superseded.** The admission adds a membership after an explicit declaration, and overwrites no canonical value |
| `docs/11` §14 | "Family Users do not edit canonical records. They use the approved Change Request architecture" | Narrowed by FP-ADR-081 and A-21 |
| `docs/11` §9 | Domain Actions "after the Change Request workflow (§17), remain the only mechanism" | Narrowed by FP-ADR-081 for §2 only. Profile Review itself is unchanged |
| FP-ADR-076 | Reviewer attestations for `ADD_FAMILY_MEMBER` | **Unchanged for that type.** They do not apply to this flow, which has no reviewer. This is the risk the gates in §12 exist to control |
| FP-ADR-076 | No enumeration at submission | **Unchanged for that type.** This flow has its own rule in §8 |

**Not changed by this ADR:**

- `docs/03` §13 (transfers), §21 (National ID uniqueness), §47e;
- `docs/03` §67 (a Change Request is a proposal), which still describes every request;
- the Change Request engine, its types and its states.

**Baseline text aligned in WP-02.1:**

- the "Family Portal changes … must use the Change Request workflow" guardrail in
  `CLAUDE.md`, which keeps the general rule and names this ADR's exception as the only one;
- `docs/05` §54 and §73, annotated under WF-ADR-055. The Change Request workflow and FP-ADR-076
  stay unchanged for ordinary requests.

Both record that gates G-01 … G-07 remain open and that activation is prohibited until they are
resolved and activation is separately approved.

## 4. Relation to FB-CIVIL-02A and 02B

This ADR does not silently override them. Their text and statuses stay as recorded.

| Item | Status there | Position here |
|---|---|---|
| OD-03: do `fatherid` / `motherid` / `partnerid` reference `mainid`? | OPEN | **Still open.** Discharged only by G-03, and only for `fatherid` / `motherid` |
| OD-10: genealogy out of scope for V1 | OPEN | **Still open.** Finding a head's own children to admit them is not a genealogy feature, and the owner must confirm that reading (G-01) |
| OD-15: no linkage between `identity_ref` and `persons` | Approved: none | **Respected, D-02.** No source record id is stored. The evidence fingerprint in §9 is not a join key to `identity_ref` |
| G1 / OD-08: lawful basis, purpose, retention | OPEN | A new purpose. **Not resolved here** (G-01, G-02) |
| OD-09: exception to `docs/03` §47e | PROPOSED | Unaffected: no free National ID search exists here, and the head's ID comes from the server |
| B-OD-1 … B-OD-4 | OPEN | Unaffected. This flow does not use `EXTENDED_CONTROLLED` or the lookup grants |
| §L-8, 02B §B-5: reverse links disabled | In force | **Stay disabled for the lookup.** `civil_lookup.reverse_links_enabled` is neither read nor changed by this flow. The same read function serves a different, separately gated purpose |
| 02A §L-2: read boundary, read-only login | In force | **Reused unchanged** |
| `09-lookup-indexes.dba.sql` on Production | Separate approval | Still needed for an indexed read. Part of G-06 / G-07 |

## 5. Eligibility

**Approved rules, with proposed mechanics.** Checked at discovery for display and again at
admission, inside the locks of §10. Any failure refuses the admission.

| # | Check | Source |
|---|---|---|
| E-1 | Flag allows this Family; `family.context` passes | D-04 |
| E-2 | A published release is active, and it matches the discovery reference | Business rule |
| E-3 | The record's `fatherid` or `motherid` equals the head's server-side National ID, re-read now | D-03, G-03 |
| E-4 | The person is not the head; the ID is nine digits and no placeholder | `docs/03` §19, §21 |
| E-5 | Gender code maps to `SON` / `DAUGHTER` through the verified table. An unknown code refuses | G-04 |
| E-6 | Death status is verified as living. Deceased or uncertain refuses | D-06, G-05 |
| E-7 | No equivalent holder → new Person. Exactly one holder that is ALIVE, active and has no active membership → attach as recorded | FP-ADR-076 rules, reused |
| E-8 | An active membership anywhere, an inactive, UNKNOWN or DECEASED holder, a soft-deleted holder or several holders refuses. No transfer, merge or automatic choice | `docs/03` §13, §21 |
| E-9 | No open request for this National ID in this Family | Proposal |

**D-06 in practice:**

- 02A §L-4 records that death is known only from a non-empty `deathdate`, and that
  `presonstate` is not interpreted;
- an empty `deathdate` is therefore **not yet proof of life**;
- until G-05 defines what proves it, E-6 cannot pass for anyone, and activation stays
  blocked;
- this flow never creates a DECEASED Person.

**Registry values are not interpreted beyond the verified tables:**

- no value is trimmed, guessed or rewritten in `identity_ref`;
- an unparseable birth date is stored as NULL, never invented;
- an unknown marital code becomes `UNKNOWN`;
- an existing Person's recorded data is never updated from the registry.

## 5a. Strict adapter and fail-closed evaluation (ADD-WP-05)

**Implemented locally, internal only, uncommitted; no route, response or action uses it.** Gates G-03, G-04 and G-05 stay OPEN.

- **Provisional mappings (G-04).** `CivilRegistryValues` matches raw values exactly as stored: never trimmed, normalized or
  compared by similarity. The listed values are the ones observed in the 2026-10-25 export (aggregate audit WP-04B), not a
  confirmed code table: gender `ذكر` → MALE and `أنثى` → FEMALE; marital `اعزب`, `متزوج`, `أرمل`, `مطلق` → SINGLE, MARRIED,
  WIDOWED, DIVORCED. `MAPPINGS_APPROVED` is false, so a mapped gender still refuses (GENDER_MAPPING_PROVISIONAL).
- **Listed without a mapping (INDETERMINATE):** gender `غير معروف`; marital `متعدد الزوجات` and `غير مسجل`. No MaritalStatus case is
  guessed for them.
- **Unsupported:** every other value, including coded or near-identical spellings. Missing values are MISSING. All refuse.
- **Parentage (G-03).** Only `fatherid` / `motherid`, compared exactly with the head's server-side National ID. Both naming the
  head is PARENT_LINK_INDETERMINATE. Names are never used.
- **Proof of life (G-05, D-06).** `ProofOfLife` returns only DECEASED (a valid `deathdate`), INDETERMINATE (an invalid one) or
  UNVERIFIED (empty). There is no ALIVE outcome and no override. `presonstate`, age, birth date and marital status are not read.
  E-6 therefore refuses every child (LIFE_UNVERIFIED or DECEASED).
- **Evaluation.** `RegistryChildEligibility::evaluate()` takes the family.context result and one record, collects every internal
  reason (E-3 … E-9, plus the source checks), reads the application registry without locks, and never reads or writes
  `identity_ref`. The admission action must evaluate again under its locks (§10).
- **Public status.** `RegistryChildAssessment::publicStatus()` gives only ELIGIBLE, ALREADY_MEMBER, REQUEST_OPEN or NOT_ELIGIBLE.
  REQUEST_OPEN is shown only when no undisclosable reason applies. Another household, a death, a deleted or unattachable record and
  every internal reason stay internal. While G-04 and G-05 are open, ELIGIBLE cannot occur.
- **No schema change.** Nothing here needed a table.

## 5b. Internal discovery service (ADD-WP-06A)

**Implemented locally, internal only (ADD-WP-06A committed; ADD-WP-06B-1 uncommitted). No route, controller, job, command or
listener uses it; the flag is not read here (E-1 belongs to the entry point).** Gates G-01 … G-07 stay OPEN; D-06 is unchanged.

- **Source.** `SuggestedChildrenDiscovery` reads only through `RegistryChildSource`, a two-method read-only interface
  (`childrenOf`, `recordOf`) with the `identity_ref_lookup.children()` contract. It is **not bound in the container**, so only
  code that passes a source explicitly can run discovery; today that is the tests, with synthetic records. An adapter over
  `CivilRegistrySource` needs its own approved work package (and `09-lookup-indexes.dba.sql`, G-06 / G-07).
- **Parent.** The head's National ID comes from the family.context result, never from a caller. Parentage is exact
  `fatherid` / `motherid` (G-03, provisional). A returned record naming the head as neither parent is dropped. Marriage,
  polygamy or heading another household never hides a child (D-07).
- **Classification.** Each listed child is classified by `RegistryChildEligibility::evaluate()` (§5a), unchanged. Discovery adds
  two internal reasons only it can see: `DUPLICATE_SOURCE_ROWS` (one National ID in several rows: listed once, the lowest record
  id standing for the group) and `PARENTAGE_ANOMALY` (the head's own record names the child as a parent, or the child's other
  parent is the child or another suggested child). Both refuse.
- **Result.** An internal DTO per child: full name, masked National ID (`NationalIdMask`), birth date in the observed shape or
  NULL, relationship and status. The relationship is NULL unless the gender mapping is approved (G-04) and the status is not
  `NOT_ELIGIBLE`, so it is always NULL today. No reason, other household, membership, record id or payload field is carried.
  No `ref` is issued yet. Nothing is stored or cached.
- **Paging.** One source call reads at most `CivilRegistrySource::LINKS_CAP` (100) matches; more than that lists nothing
  (`tooBroad`). Children are ordered by National ID, deduplicated, and only the requested page is evaluated; page size 25 by
  default, at most 100.
- **Batch facts (ADD-WP-06B-1).** `RegistryChildFacts::load()` reads, once per page and for at most 100 valid National IDs, what
  `evaluate()` used to read per child: equivalent holders, a soft-deleted exact holder and an open `ADD_FAMILY_MEMBER` request of
  this Family. `evaluate()` takes the facts as an optional argument; the rules are the same code either way, and without facts it
  reads per record as before (the admission action's path under locks). Two holder strategies, chosen on every build:
  - `INDEXED_EXACT` on PostgreSQL **only when the catalog shows** the validated CHECK `chk_person_national_id_format` and the
    valid unique index `uq_persons_national_id`. Then every stored value is nine ASCII digits, `NationalId::normalize()` is the
    identity on them, and an exact `IN` match equals the equivalent match. The migration file alone is not trusted; a `NOT VALID`
    CHECK or a dropped constraint selects the fallback.
  - `NORMALIZED_SCAN` otherwise (SQLite, legacy data): **one** chunked pass over `persons` per page instead of one per child, with
    the same normalization, the same `MAX_MATCHES + 1` cap and the same early stop. It still grows with the number of Persons.
- **Measured (synthetic, 2,500 bulk Persons plus fixtures; test cluster and SQLite).** Application queries to read the facts:

  | Path | 1 child | 25 children |
  |---|---|---|
  | PostgreSQL, `INDEXED_EXACT` | 7 | 7 |
  | SQLite, `NORMALIZED_SCAN` (3 chunk queries) | 8 | 8 |
  | Per record, before (both drivers) | — | 133 |

  On PostgreSQL `EXPLAIN` of the holder query shows `Index Scan using uq_persons_national_id`, with no sequential scan of
  `persons`. Compatibility: for canonical and legacy formats (Arabic and Persian digits, separators, ambiguity above the cap,
  soft-deleted and historical holders, open, cancelled and other-family requests, malformed IDs and the head), the batched
  reasons equal the per-record reasons on both drivers.
- **Known limits.** The real `children()` orders by normalized name; discovery re-sorts the capped set itself, so paging does not
  depend on the source order. The fallback still reads every Person with a National ID once per page. Whether Production has the
  validated constraints is not verified here. A synthetic source cannot show real-data performance.

## 6. The declaration

**Approved rule; proposed form.** One declaration per child, made by the head, never
preselected, never bulk-accepted. Proposed wording, to be confirmed with G-02:

> أُقرّ بأن هذا الابن / هذه الابنة من أفراد أسرتي ويقيم/تقيم فعليًا ضمن هذه الأسرة، وأن هذا
> الإقرار صحيح على مسؤوليتي.

It is recorded as an **assertion by the head**, never as verification. It grants no
VERIFIED state and no eligibility for any programme (BD-052).

## 7. Proposed API contracts

**Proposal, not implemented.** Under `/api/v1/family`, inside
`auth:sanctum`, `family.side`, `can:family-portal.access` and `family.context`, plus the
flag and the proposed permission of `docs/06` §61d-2. All answers carry
`Cache-Control: no-store, private`. The client never sends a Family, Person, membership or
National ID.

### Discovery

```http
GET /api/v1/family/household/registry-children?page=1
```

```json
{
  "data": {
    "children": [
      {
        "ref": "opaque",
        "full_name": "اسم-تجريبي",
        "national_id_masked": "*****0003",
        "birth_date": "2010-01-15",
        "relationship": "SON",
        "status": "ELIGIBLE"
      }
    ],
    "meta": { "page": 1, "per_page": 25, "total": 1 }
  }
}
```

- `status` is `ELIGIBLE`, `ALREADY_MEMBER`, `REQUEST_OPEN` or `NOT_ELIGIBLE`.
- `ref` is opaque, bound to the user, the purpose, the release and a short expiry.
- `relationship` is NULL when `status` is `NOT_ELIGIBLE` because of G-04.
- Never returned: a full National ID, `fatherid`, `motherid`, `partnerid`, `householdid`,
  `phonenum`, `deathdate`, `presonstate`, `relgion`, or any source record id.

### Admission

```http
POST /api/v1/family/household/registry-children/admissions
```

```json
{
  "ref": "opaque",
  "client_reference": "uuid",
  "declaration": { "accepted": true, "version": "2026-11-v1" }
}
```

One child per request, so that each has its own declaration, locks and outcome.

```json
{ "data": { "member_ref": "opaque", "replayed": false } }
```

### Failure behavior

| HTTP | Code | When | Changed |
|---|---|---|---|
| 201 | none | Admitted | Membership, evidence, activity |
| 200 | none | Same `client_reference` and same request (`replayed: true`) | Nothing |
| 403 | `FAMILY_CONTEXT_UNAVAILABLE` | Not an eligible household head | Nothing |
| 404 | `ADMISSION_UNAVAILABLE` | Flag closed for this Family; the same answer on both routes | Nothing |
| 409 | `ADMISSION_IDEMPOTENCY_CONFLICT` | Same key, different request | Nothing |
| 409 | `RELEASE_CHANGED` | The active release differs from the reference | Nothing |
| 422 | `DECLARATION_REQUIRED` | Declaration missing, false or an old version | Nothing |
| 422 | `REFERENCE_INVALID` | Reference invalid, expired or another user's | Nothing |
| 422 | `NOT_ELIGIBLE` | **One answer** for every failure of E-3 … E-9 | Nothing |
| 429 | `RATE_LIMITED` | Limit in §10 reached | Nothing |
| 503 | `REGISTRY_UNAVAILABLE` | Registry unreadable or not configured | Nothing |

**Fails closed:** a registry failure never falls back to stale data or a default, and a
partial admission cannot exist, because membership, evidence and activity commit in one
transaction. A lost uniqueness race is answered `NOT_ELIGIBLE`, never with the holder's
details. Errors, logs and queues carry no National ID, name or payload.

## 7a. Admission references (ADD-WP-06B-2)

**Implemented locally as an internal service, uncommitted. No route, response, action or job issues or reads a reference.**
Gates G-01 … G-07 stay OPEN.

- **Encryption.** `RegistryAdmissionRefs` uses Laravel `Crypt`, as `CivilLookupRefs` and `CredentialTokens` do: AES-256-CBC
  with a random IV and an HMAC-SHA256 (encrypt-then-MAC) under `APP_KEY`. The same input never gives the same token. The whole
  payload is encrypted; nothing travels in clear.
- **Payload (version 1), exactly these fields:** version, purpose `ADMIT_REGISTRY_CHILD`, user id, Family id, household head's
  Person id, published release id, the child's nine-digit National ID, `FATHER` or `MOTHER`, issued-at, expiry and a 128-bit
  random nonce.
- **Lifetime.** Fixed at 15 minutes (`TTL_SECONDS`); expiry minus issued-at must be exactly that. An issued-at more than 30
  seconds ahead of the server clock (`CLOCK_SKEW_SECONDS`) is refused.
- **Validation, before anything is returned:** at most 4,096 characters and canonical base64, both checked before decryption;
  the MAC; a flat JSON object with exactly the expected keys and types; version and purpose; a positive id for user, Family, head
  and release; a strictly valid National ID; `FATHER` or `MOTHER`; the nonce shape; the lifetime; and the user, Family and head
  Person of the **current** family.context. A change of household head therefore invalidates every reference.
- **Answers.** Every failure is `REFERENCE_INVALID` (proposed HTTP 422), without saying which check failed. Only a reference
  valid in every other respect but issued for another release is `RELEASE_CHANGED` (proposed 409): the head discovers again.
- **Result.** `RegistryAdmissionReference`, internal and typed: release, parent link, times, and the child's National ID through
  `childNationalId()` only. It is redacted in `var_dump` / `print_r`, cannot be JSON-encoded or serialized, and the issuing
  parameter is `#[\SensitiveParameter]`. Exceptions carry only the code.
- **Stateless replay.** Nothing is stored. A reference can be presented again until it expires, so it identifies a child; it does
  not authorize anything. The admission action (ADD-WP-07) must re-read the registry, evaluate again under its locks (§10), and
  rely on its own idempotency and uniqueness guards. Single use would need persistence and is not approved.
- **Key rotation.** A reference sealed under a key listed in `APP_PREVIOUS_KEYS` still opens after `APP_KEY` changes; one sealed
  under any other key is `REFERENCE_INVALID`. With the 15-minute lifetime, removing a previous key soon after rotation is safe.
- **Unchanged:** `CivilLookupRefs` and every Civil Lookup behavior. A lookup reference is refused here.

## 7b. Guarded discovery route (ADD-WP-06B-3)

**Implemented locally, uncommitted; not operational.** Gates G-01 … G-07 stay OPEN; D-06 is unchanged.

- **Route.** `GET /api/v1/family/household/registry-children`, inside `auth:sanctum` → `family.side` →
  `can:family-portal.access` → `family.context`, then `family.registry-discovery`. GET only; the route inventory and the family
  boundary test name it exactly.
- **Gate (`family.registry-discovery`).** `FamilyRegistryAdmissionPolicy::allowsFamily()`: `OFF`, a missing or invalid mode
  refuse; `PILOT` allows only the Family ids in `family_registry_admission.pilot_family_ids`
  (`FAMILY_REGISTRY_ADMISSION_PILOT_FAMILY_IDS`; empty or unreadable allows nobody); `ON` allows every eligible head. The
  account must also hold `family-member.admit-from-registry`, which no role has. Every refusal is the same
  `404 ADMISSION_UNAVAILABLE`, and nothing is read, counted or recorded.
- **Limits.** Per user, 10 per minute and 60 per hour by default (`family_registry_admission.limits.*`), counted in the gate after
  it passes: a route `throttle:` would be moved before `family.context` by the middleware priority list. `429 RATE_LIMITED` with
  `Retry-After`. Independent of the civil lookup limiter and quota (D-05).
- **Request.** `page` only, 1 … 4 (`LINKS_CAP` / 25); the page size is fixed at 25. Any other parameter, in the query or the body,
  is `422`. The head and the household come from `family.context`.
- **Source.** The controller uses the `RegistryChildSource` in the container, and the application binds **none**: every allowed
  request is `503 REGISTRY_UNAVAILABLE` until an approved adapter exists. A `CivilLookupException` from a source is the same 503.
  Tests bind synthetic sources only.
- **Release integrity (ADD-WP-06B-3R).** The release is the one the source's own page reports; the client cannot supply one
  (`page` only). Discovery refuses, before evaluating anything, a page with no release or a release below 1, a row or the head's
  record from another release, rows on a too-broad answer, a total that does not equal the rows, or another cap. The answer is
  the same `503 REGISTRY_UNAVAILABLE`, with no children and no audit row.
- **Source contract (settled in ADD-WP-06B-4, §7c).** A too-broad page must report exactly `total = LINKS_CAP + 1` (101)
  and no rows: the bounded probe never learns a larger count. A source reporting `total > 101` breaks the contract and fails
  closed with 503, not 409.
- **Response (provisional until G-02).** `data.children[]` with exactly `display_name` (the first word of the name),
  `birth_year`, `status` (`ELIGIBLE`, `ALREADY_MEMBER`, `REQUEST_OPEN`, `NOT_ELIGIBLE`) and `ref`, always NULL; `data.meta` with
  `page`, `per_page` and `total`. No National ID (even masked), full birth date, relationship, reason, other household
  or registry field. `Cache-Control: no-store, private` on every answer.
- **More than the cap (ADD-WP-06B-3R).** `409 RESULT_SET_TOO_BROAD`, `{message, code}` only: no children, no count, no reason
  and no source data. Recorded first (outcome `RESULT_SET_TOO_BROAD`, result count 0); if the row cannot be written, 503.
- **Audit.** One `DISCOVERY_LISTED` row per successful page or too-broad refusal, through `FamilyRegistryAdmissionAudit`, before the answer: actor,
  Family, release, number of children shown, outcome `LISTED` or `RESULT_SET_TOO_BROAD`, and a keyed IP hash under the new
  `FingerprintContext::FAMILY_REGISTRY_ADMISSION_IP`. No National ID, name, per-child status or payload. If the row cannot be
  written, the answer is 503 and nothing is shown. Append-only on PostgreSQL; no retention rule (G-02).
- **Open decisions kept open:** O-1 (GET with an audit row), O-3 (the over-cap answer, provisionally 409), O-5 (a missing permission answers 404,
  like a closed flag), and the response fields (G-02).

## 7c. Source contract and synthetic adapter (ADD-WP-06B-4)

**Implemented locally, uncommitted; not operational.** Gates G-01 … G-07 stay OPEN; D-06 is unchanged. Nothing is bound.

- **Contract (`RegistryChildSource`).**
  - `childrenOf(head, 0, 100)`: exact, non-empty `fatherid` / `motherid` matches in **one** published release, named by the page's
    `releaseId` (NULL only when none is published), in a deterministic order, `cap` = `LINKS_CAP` (100).
  - A **bounded probe**: the source reads at most 101 matches. `total` = min(matches, 101), so a larger count is never learnt,
    reported or recorded. More than 100: no rows. Otherwise every match, and `count(rows) = total`. Every row carries the page's
    release.
  - `recordOf(id, releaseId)`: the head's record **in the page's release**, or NULL. If that release is no longer published:
    `CivilLookupException RELEASE_CHANGED`, never a record of another release. Before ADD-WP-06B-4 the head was read in
    "the active release", so a release published between the two reads could pass unnoticed when the head was missing in it.
  - Read failures are `CivilLookupException`.
- **Overflow versus integrity.** Both are decided before any child is evaluated:
  - **Overflow:** a contract-conforming answer with more than 100 matches (`total = 101`, no rows) → `409 RESULT_SET_TOO_BROAD`,
    recorded (`RESULT_SET_TOO_BROAD`, count 0).
  - **Integrity failure:** a breach of the contract (no release, a row or the head from another release, rows on a too-broad
    page, `total ≠ count(rows)`, `total > 101`, another cap) or a read failure, including `RELEASE_CHANGED` → `503
    REGISTRY_UNAVAILABLE`, nothing shown, no audit row.
- **Synthetic adapter.** `Tests\Support\FamilyPortal\SyntheticRegistryChildAdapter`: under `tests/` (autoload-dev, absent from a
  `--no-dev` build), bound by no provider, and it refuses to be built outside the test runner. It reads no table: its records
  come from a deterministic generator (synthetic IDs, two releases per child, interleaved other parents). It scans lazily and
  stops at the 101st match, so it holds at most 100 rows.
- **Limitations.** The adapter is a contract and test aid, not a model of the real registry's cost: its scan is linear in the
  synthetic dataset where a real adapter uses an index. It knows one published release at a time, and duplicate or anomalous
  records exist only when a test makes them. The synthetic measurements (constant queries per page, bounded rows and memory)
  are not Production performance.
- **Not changed:** the API response contract, `RegistryAdmissionRefs`, the permission (no role), the flag (`OFF`).

## 8. Privacy protections

**Approved principle; proposed mechanics. The legal position is open (G-01, G-02).**

- **Purpose limit:** registry data is read for this flow only, for the authenticated
  head's own ID. The head's ID is never accepted from the client.
- **Minimum data:** the fields in §7 only. No address, employment, phone or spouse data is
  shown or copied. `phonenum` is not imported as a Person's mobile.
- **Adult children:** whether a parent may see an adult child's data, and whether that
  child must be told or can object, is **G-02**. Age and marital status never widen what
  is shown.
- **No disclosure of other households:** `NOT_ELIGIBLE` does not tell apart another
  household, death, a deleted record, an ambiguous holder or a code that cannot be mapped.
  A head can still infer something from a child they know exists; G-02 must accept that
  residual disclosure or restrict the display.
- **No persistence of civil data beyond need:** a new Person holds only the fields the
  admission needs. Discovery results are not stored or cached, and the Family service
  worker does not cache them.
- **PWA and browser:** no National ID in a URL, a query key or client storage.

## 9. Admission audit schema

**Implemented as schema only (ADD-WP-04, migration `2026_11_01_100000_create_family_registry_admission_tables`); D-02 and D-05 are approved constraints.** No change to
`identity_ref`, `persons` or `family_memberships`. Nothing writes these tables yet. Both are append-only on PostgreSQL.
`outcome_code` follows the existing format check of `civil_lookup_audit_events`; its code list comes with the work package that
writes it. A CHECK cannot prove that the membership, Person and Family of a row agree; the admission action must.

### `family_registry_admissions` (evidence, one row per successful admission)

| Column | Type | Note |
|---|---|---|
| `id` | bigint | Never exposed |
| `uuid` | uuid, unique | Public identifier |
| `family_id` | FK, restrict | |
| `person_id` | FK, restrict | |
| `family_membership_id` | FK, restrict, unique | |
| `admitted_by` | FK users, restrict | The account |
| `admitted_by_person_id` | FK persons, restrict | The head (User ≠ Person) |
| `client_reference` | uuid | Unique with `admitted_by` |
| `outcome_type` | text, CHECK | `PERSON_CREATED` or `PERSON_ATTACHED` |
| `parent_link` | text, CHECK | `FATHER` or `MOTHER` |
| `reference_fingerprint` | char(64) | Keyed HMAC, see below |
| `reference_key_version` | smallint | |
| `release_id` | bigint, no FK | The release read. Not a record id; `identity_ref` stays outside the application schema |
| `declaration_version` | text | |
| `declared_at` | timestamptz | |
| `created_at` | timestamptz | Immutable: no `updated_at` |

**Never stored:** the source `record_id` or `record_versions` id (D-02), a National ID, the
registry payload, a name, or the request body.

**Reference fingerprint:** computed with `KeyedFingerprint` under
`FingerprintContext::FAMILY_REGISTRY_ADMISSION_REFERENCE`
(`famboook.family-registry-admission.reference.v1:`, ADD-WP-07B-1), over the canonical National ID. The
key is the application's, so `identity_ref` readers cannot derive it, and there is no
stored path from this table to a registry row by id.

### `family_registry_admission_events` (attempts, append-only; D-05)

| Column | Note |
|---|---|
| `event_type` | CHECK: `DISCOVERY_LISTED`, `ADMISSION_SUCCEEDED`, `ADMISSION_REFUSED`, `ADMISSION_REPLAYED` |
| `actor_user_id`, `family_id` | Restrict |
| `outcome_code` | A controlled code, including the **internal** reason for a refusal, which is never sent to a client |
| `release_id` | No FK |
| `reference_fingerprint`, `reference_key_version` | NULL on discovery |
| `result_count` | Discovery only |
| `ip_hash` | Keyed, as in `CivilLookupAudit` |
| `created_at` | Immutable |

Writes to `civil_lookup_audit_events` and the civil lookup quota are not used (D-05).
`FAMILY_MEMBER_ADDED` is recorded in the Family Activity Log as for any new member, with no
metadata. Retention is part of G-02.

## 9a. Audit and integrity foundation (ADD-WP-07B-1)

**Implemented locally, uncommitted; nothing writes admission rows yet** (no action, route or source). Gates G-01 … G-07 stay
OPEN; D-06 is unchanged.

- **Migration `2026_11_02_100000_harden_family_registry_admission_audit`** (additive, PostgreSQL only; validated when added, so
  an existing row that breaks a rule stops the migration). On `family_registry_admission_events`:
  - `chk_registry_admission_event_release`: a release id is NULL or positive;
  - `chk_registry_admission_event_admission_reference`: `ADMISSION_SUCCEEDED` and `ADMISSION_REPLAYED` carry a reference
    fingerprint and a release;
  - outcome codes by type: success `PERSON_CREATED` or `PERSON_ATTACHED`; replay `REPLAYED`; a refusal never uses a success,
    replay or listing code. Discovery rows are not constrained further.
- **TRUNCATE guard (corrected in ADD-WP-07B-1R).** A `BEFORE TRUNCATE` statement trigger on both tables
  (`famboook_family_registry_admission_no_truncate()`, SECURITY INVOKER with a pinned `search_path`) refuses TRUNCATE, direct or through CASCADE,
  **whenever the table holds a row**, for every role and in every database. No database name, session setting or role opens it:
  the first version's `*_test` exception and its `famboook.enforce_truncate_guard` setting are removed. The row triggers only
  cover UPDATE and DELETE.
  - **Why an empty table may be truncated.** It holds no history to destroy. This is what keeps the PostgreSQL test harness
    working: `DatabaseTruncation` (55 test classes) issues `TRUNCATE … RESTART IDENTITY CASCADE` for every table, which reaches
    both tables through `users`, the reason `workflow_events` has no TRUNCATE guard. No test commits an admission row.
  - **Why READ COMMITTED only.** TRUNCATE takes ACCESS EXCLUSIVE before the trigger runs, so under READ COMMITTED the trigger's
    fresh snapshot sees every committed row. Under REPEATABLE READ or SERIALIZABLE an older snapshot could miss rows committed
    since, so TRUNCATE is refused there even on an empty table.
  - **Test cleanup for later work packages.** A test that commits an admission row (for example a cross-process admission test
    in ADD-WP-07B-2) cannot clean up with `DatabaseTruncation`: it must rebuild the schema instead (`DatabaseMigrations`, which
    drops the tables, an owner operation). The guard is not relaxed for tests.
  - `down()` removes these guards only while no admission event or evidence row exists.
- **Security boundary and role assumptions.** The triggers bind every role that is neither a **superuser** nor the **owner** of
  the tables. Those two can always switch triggers off (`session_replication_role = replica`, which needs superuser;
  `ALTER TABLE … DISABLE TRIGGER` or `DROP TRIGGER`, which need ownership). Tested with a role created inside the test
  transaction that is not a superuser, does not own the tables and holds SELECT, INSERT, UPDATE, DELETE and TRUNCATE on them:
  it can read and insert, and cannot truncate, update, delete, change `session_replication_role`, disable or drop the triggers,
  drop the function or drop a constraint. A role with TRUNCATE but without SELECT is refused too, because the check cannot read
  the table. The local PostgreSQL test connection is the superuser `postgres`. The triggers fire for it too, and the other tests
  show the refusals as `postgres`, but as a superuser it could switch them off; that is why the boundary is tested through the
  non-owner role.
- **Search-path hijacking (found in the ADD-WP-07B-1C review, fixed in ADD-WP-07B-1R2).**
  - **Root cause.** The first trigger function ran as SECURITY INVOKER with the caller's `search_path` and called
    `current_setting()`, `format()` and `<>` unqualified. A role that is neither superuser nor owner, holding only SELECT and
    TRUNCATE on the table and owning a schema of its own, created `current_setting(text)`, `format(text, regclass)` and a `<>`
    operator there, put that schema first in its `search_path`, and truncated a table that held a row. Confirmed on the local
    test cluster inside a rolled-back transaction.
  - **Correction.** The function is created with `SET search_path = pg_catalog, pg_temp` and calls
    `pg_catalog.current_setting()`, `pg_catalog.format()`, `OPERATOR(pg_catalog.<>)` and `::pg_catalog.regclass`. The table is
    read through `TG_RELID::regclass`, which prints schema-qualified under that path. It stays SECURITY INVOKER: no SECURITY
    DEFINER, and no role, name or setting exception. The triggers call the function by OID.
  - **Regression coverage.** A non-owner role with only SELECT and TRUNCATE, its own schema and TEMP replaces
    `current_setting()`, `format()` and `<>`, and also creates empty temporary look-alike tables and a temporary `format()`.
    Under a hostile ordinary search path and a hostile temporary-schema search path, direct TRUNCATE, TRUNCATE CASCADE and a
    two-table TRUNCATE are all refused by the guard, and the rows stay. `pg_proc.proconfig` must equal
    `search_path=pg_catalog, pg_temp`, and the source must use the qualified built-ins. Run against the unfixed function body,
    both tests fail.
  - **Other functions.** The ADD-WP-04 append-only function only raises: it resolves no function, operator or table. The CHECK
    constraints bind their operators when they are created and do not depend on a caller's `search_path`.
- **Deployment prerequisites (not done here; part of the Production audit before G-07).**
  - The application's runtime database role is not a superuser and does not own these tables; migrations run as a separate
    owner role.
  - The runtime role holds only SELECT and INSERT on both tables, and USAGE on their sequences: UPDATE, DELETE and TRUNCATE
    are not granted, so the triggers are a second line of defence, not the only one.
  - The runtime role has no CREATE privilege on any schema (`public` included) and owns no schema, so it cannot place objects
    on any `search_path`. The pinned `search_path` keeps the guard safe even if this is missed; the grant is still required.
  - Who may hold the owner role, and how its use is logged, is recorded with that audit.
- **Audit helpers (`FamilyRegistryAdmissionAudit`).**
  - `admissionSucceeded()` refuses to run outside a transaction, so the event commits or rolls back with the membership and
    evidence it describes;
  - `admissionReplayed()` and `admissionRefused()` are written on their own, after the admission transaction;
  - a refusal code must be a `RegistryChildReason` or one of `REFERENCE_INVALID`, `RELEASE_CHANGED`, `IDEMPOTENCY_CONFLICT`,
    `CONTEXT_CHANGED`, `LOST_RACE`, `RESULT_SET_TOO_BROAD`; the child and the release are NULL only when the refusal came before
    they were known;
  - every argument is checked before the insert; a bad argument or a failed insert throws (fail closed). A row holds the actor,
    the Family, the release, the code, the child's keyed fingerprint (`referenceFingerprint()`, also for the evidence row) and a
    keyed IP hash: never a National ID, a name, a token or a payload.
  - **These rows are not anonymous.** `actor_user_id` and `family_id` identify an account and a household directly. The
    reference fingerprint and the IP hash are **pseudonymous**: anyone holding the application's fingerprint key can recompute
    them for a known National ID or address, and the nine-digit ID space is small enough to search exhaustively. The audit is
    therefore personal data under the same protection as the registry; access, retention and key handling stay with G-02.
- **Recorded for later work packages, not decided here:**
  - **O-F needs a stronger publication-consistency design.** Accepting a release published between the registry read and the
    admission transaction (§10) is not settled: ADD-WP-07B-2 must not rely on it until a design that detects or excludes the
    swap is approved.
  - **O-B needs a replay-specific security review before implementation.** Accepting an expired but authentic reference for a
    replay would change `RegistryAdmissionRefs`; nothing here does.
  - **O-K and G-02 stay unresolved:** the declaration wording and version, and retention.

## 10. Idempotency, concurrency and rate limits

**Proposal, following established patterns.**

- **Idempotency:** `(admitted_by, client_reference)` is unique. Under the Family lock, a
  replay of the same request returns the original result; the same key with a different
  request is refused. One key per declaration.
- **Registry read:** outside the database transaction, because it uses a separate
  read-only connection with its own timeouts. No Family lock is held during it. The release
  is compared with the reference, and a swap in the remaining short window is accepted and
  recorded by `release_id`.
- **Lock order:** Family → National ID advisory lock → Person (FP-ADR-076), with the
  refusal for an active member taken before locking that Person's row.
- **Final guards:** `uq_person_active_family_membership` and `uq_persons_national_id`.
- **Rate limits:** independent of civil lookup. Proposed per user: discovery 10 per minute
  and 60 per hour; admission 5 per minute and 30 per hour; each configurable. Values to be
  confirmed with G-06.

## 11. Feature flag (D-04)

**Approved name and values. Implemented in ADD-WP-04 as configuration only: nothing reads it to open anything, and it is not
enabled. The PILOT allowlist below is still a proposal.**

| Mode | Effect |
|---|---|
| `OFF` (default) | No discovery and no admission. Missing or invalid configuration also means OFF |
| `PILOT` | Only Families on a separate allowlist (proposed: `family_registry_admission.pilot_family_ids`) |
| `ON` | Every eligible household head |

The flag is independent of `civil_lookup.*` and `change_requests.*`. Changing it needs G-07.
`ADD_FAMILY_MEMBER` remains available whatever its mode is.

## 12. Activation gates and owners

**All OPEN. None is resolved by this ADR, and any one blocks PILOT and Production.** Owners
are roles; the project owner assigns the named person when each gate starts.

| Gate | Requirement | Evidence needed | Decision owner | Status |
|---|---|---|---|---|
| G-01 | Legal basis and purpose authorization | Recorded decision covering this purpose, including G1 / OD-08 as they apply and the OD-10 reading | Project owner, with legal counsel | **OPEN** |
| G-02 | Privacy policy for adult children's data | Approved position: visibility, notice, objection, retention, residual disclosure, declaration wording | Project owner, with legal and privacy counsel | **OPEN** |
| G-03 | Verified `fatherid` / `motherid` / `mainid` semantics | Written confirmation from the source's owner, plus aggregate-only profiling, including the 12.5 % of unresolved `fatherid` values | Civil registry data owner, with the project owner | **OPEN** |
| G-04 | Verified gender codes for `SON` / `DAUGHTER` | Authoritative code table; profiling shows no unknown code unhandled | Civil registry data owner | **OPEN** |
| G-05 | Verified death-status semantics | Written definition of what proves living and deceased, including `deathdate` and `presonstate` | Civil registry data owner, with the project owner | **OPEN** |
| G-06 | Security, PostgreSQL, concurrency and integrity tests | Passing runs and an independent security review; test list in §13 | Engineering lead, with a security reviewer | **OPEN** |
| G-07 | Separate explicit approval before PILOT or Production activation | Recorded approval naming the commit, mode and allowlist, with `09` index approval where needed | Project owner | **OPEN** |

**Open questions inside these gates (not decided):**

- whether a deceased child is listed as `NOT_ELIGIBLE` or left out of the list;
- what a head can do when a record's gender cannot be mapped (the proposal is `NOT_ELIGIBLE`,
  with `ADD_FAMILY_MEMBER` still available);
- the correction path after a false declaration. The proposal is a Staff review using the
  existing `EndFamilyMembershipAction`, with the evidence kept;
- which notification, if any, tells Staff about an admission.

## 13. Required tests (G-06)

**Proposal.** Synthetic data only, on PostgreSQL where marked:

- parent-link match through father, mother and neither; a child who matches only in an
  older release (PostgreSQL);
- the head's own ID is server-side, and client-supplied IDs are refused;
- each failure in E-3 … E-9 gives the same public `NOT_ELIGIBLE`;
- deceased, uncertain and unmappable values fail closed;
- two Families racing for one child, and two tabs with one key (PostgreSQL concurrency);
- replay, key conflict and rollback leave no partial state;
- lock order against the Staff add-member, National ID correction and Change Request APPLY
  paths (PostgreSQL deadlock);
- no write in `identity_ref`, and no source record id anywhere in the evidence;
- no civil lookup quota or audit row is consumed or written;
- logs, errors and responses carry no full National ID or payload;
- isolation between Families, and every flag mode, including invalid configuration;
- `ADD_FAMILY_MEMBER` regression: unchanged behavior.

## 14. Reversal

**Proposal.** Setting the flag to `OFF` stops new discovery and admission at once, without
touching existing data. An admitted member stays an ordinary member, and is removed only
through existing, authorized Staff Domain Actions, preserving history and evidence. The
schema is additive and forward-only.

## 15. Work packages after this ADR

Implementation order is recorded in `docs/07` RM-ADR-071. WP-02 changes documentation only.
Later work packages may build behind `OFF` locally, but none of them closes a gate by itself.
