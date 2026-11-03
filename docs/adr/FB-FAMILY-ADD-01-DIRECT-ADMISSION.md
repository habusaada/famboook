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
- **Implemented in ADD-WP-07B-1 (committed `5fe7174`):** the admission audit and integrity foundation (§9a): event CHECKs, a
  TRUNCATE guard, the reference fingerprint context and the admission audit helpers. Nothing calls them.
- **Implemented in ADD-WP-07B-2a (committed `e3012aa`):** replay claims and the replay, conflict and unavailable-registry audit
  helpers (§7a, "Replay claims"). Nothing calls them; no action or route exists.
- **Implemented in ADD-WP-07B-2b (committed `7ca1d6e`):** the application side of the registry release pin (§10a). Nothing calls
  it and nothing binds it; without the DBA package every pin fails closed.
- **Implemented in ADD-WP-07B-2c-0 (committed `6e3935c`):** `RegistryAdmissionReference` hardened against ordinary exposure of
  the child's National ID (§7a, "Reference exposure").
- **Implemented in ADD-WP-07B-2c (local, uncommitted):** the internal atomic admission action `AdmitRegistryChildAction` (§10b).
  No route, controller, job or command calls it; nothing binds its source or verifier. In operation it admits no one: the flag
  is `OFF`, no role holds the permission, no source is bound, the release pin fails closed until DBA-1 … DBA-4, and the
  operational eligibility refuses every child until G-04 and G-05 close (D-06).
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
- **Replay claims (ADD-WP-07B-2a; ADD-WP-07B-2R D-B2, D-B3, D-B5, D-B6). Local, uncommitted; nothing calls them.**
  - `RegistryAdmissionRefs::openForReplay()` applies every check of `read()` except two: the expiry is replaced by
    `REPLAY_WINDOW_SECONDS` (24 hours from issued-at), and the release is not compared. Every refusal is `REFERENCE_INVALID`.
    `read()` and its 15-minute lifetime are unchanged.
  - The result is a `RegistryAdmissionReplayClaim`, a separate type that authorizes nothing. It is not a
    `RegistryAdmissionReference` and cannot become one. Its only operation is `matchesEvidence()` against a committed evidence
    row (`RegistryAdmissionEvidence`, validated from the row, fail closed).
  - It keeps no National ID: the child's keyed fingerprint is computed under each usable key version when it is built. A row is
    compared under its stored `reference_key_version`, in constant time; a retired version does not match (D-B6, answered as a
    conflict). Matched: actor, Family, household head, child and declaration version; not the release, parent link or outcome.
  - Audit: `replayedFromEvidence()` records the stored fingerprint, key version and release; `idempotencyConflict()` and
    `registryUnavailable()` (new procedural code `REGISTRY_UNAVAILABLE`) record no child. No schema change.
  - ADD-WP-07B-2a-F: `replayedFromEvidence()` is the only replay audit path; the earlier `admissionReplayed()`, which
    recomputed the fingerprint under the current key and was called only by tests, is removed.
- **Failure contract for the admission action (ADD-WP-07B-2a-F; D-E2). Local, uncommitted; nothing calls it.**
  - `RegistryAdmissionFailure::run()` wraps the whole admission, its transaction included. Reference refusals, the
    caller's listed refusal classes and an already sanitized failure pass through unchanged. Anything else fails closed.
  - Failing closed means one `Log::error` line with exactly four fields: a correlation id (UUID), a controlled failure code
    (`LOCK_TIMEOUT`, `DEADLOCK`, `SERIALIZATION`, `DATABASE_ERROR`, `INTERNAL_ERROR`), the exception class and a validated
    five-character SQLSTATE. Never the message, the SQL, the bound values or the trace. Then
    `RegistryAdmissionUnavailableException`.
  - That exception answers `503 {message, code: REGISTRY_UNAVAILABLE, correlation_id}` with `no-store, private`, plus
    `Retry-After: 5` for 55P03, 40P01 and 40001. It chains no previous exception, and its `report()` stops the default
    handler from logging it.
  - It always rethrows, so an enclosing transaction rolls back. It writes no audit row: recording `REGISTRY_UNAVAILABLE` is
    the action's choice, outside the failed transaction. No global exception handler, route or action was added.
  - **Why.** A `QueryException` message carries the SQL and its bound values. For the audit helpers those are keyed
    fingerprints and IP hashes, never a National ID. Still, none of it may reach a response or a log.
- **Reference exposure (hardened in ADD-WP-07B-2c-0; was the open `var_export` prerequisite).**
  - **Threat model.**
    - **In scope:** ordinary, accidental exposure through code and tools that inspect, dump, log or serialize objects:
      `var_export()`, `(array)`, `get_object_vars()`, `print_r()`, `var_dump()`, `debug_zval_dump()`, `json_encode()`,
      `serialize()`, cloning, exception traces and diagnostic logging.
    - **Out of scope:** code running in the process on purpose, such as Reflection, memory inspection, core dumps, a debugger
      or a privileged operator. PHP cannot keep a value secret from these, and this design does not claim to.
  - **Measured first, then implemented** (synthetic probe, PHP 8.3).
    - **The old private property** leaked through `var_export()`, `(array)` and `json_encode((array) …)`.
    - **A private closure** leaked through `(array)` followed by calling it, so it was rejected.
    - **A private static `WeakMap` keyed by the object** leaked through none of these, and was chosen. The National ID is no
      longer a property: `childNationalId()` reads the map, and the entry disappears with the object.
    - `jsonSerialize()`, `__serialize()`, `__unserialize()` and `__clone()` refuse. A clone would carry no child.
    - `__debugInfo()` stays redacted.
  - **Traces.**
    - PHP prints string arguments in full in exception traces unless the parameter is `#[\SensitiveParameter]`, and the
      local PHP configuration does not set `zend.exception_ignore_args`.
    - Every parameter of the `RegistryAdmission` namespace that receives a National ID, or a decrypted payload carrying one,
      is now marked; a test enforces this for the whole namespace.
    - A first edit wrote the attribute without its leading backslash, which PHP silently resolves to a non-existent class;
      the same test caught it.
  - **Unchanged:** the encrypted reference format, the 15-minute `read()`, replay claims, fingerprints, the actor, Family and
    head binding, and the discovery API.
  - **Residual limitations.**
    - Reflection on the private static map still returns the value; a test documents this as a residual, not a guarantee.
    - **No secure erasure.** The value is a plain PHP string in memory while the reference lives. When the object is freed,
      the `WeakMap` entry is removed and the string's memory is released to PHP's allocator. It is not overwritten, and it may
      remain readable in process memory, a core dump or swap until reused. PHP offers no secure memory wipe for strings.
      The same holds for the decrypted payload inside `RegistryAdmissionRefs::read()` and for every copy a caller makes.
    - **Paths to review in ADD-WP-07B-2c** (addressed there, §10b: the parameters are marked, the action runs inside
      `RegistryAdmissionFailure::run()` and refuses to run while queries can be captured). Code that calls `childNationalId()`
      and passes the value on carries it into its own frames.
      - **Unmarked parameters.** `NationalIdGuard::matches()` and `assertAvailable()`, `CreatePersonAction::handle()` (its
        `$data` array holds `national_id`) and the `RegistryChildSource` implementations.
      - **Bound values.** `CreatePersonAction` rethrows the `QueryException` of the `persons` insert, and Laravel puts the
        bound values, the National ID included, in that message. Only `RegistryAdmissionFailure::run()` around the whole
        admission keeps it out of logs and responses; nothing may catch and log it on the way.
      - **Query capture.** The query log, Telescope, Debugbar and any SQL logging record bindings. They must stay off on the
        admission path, and off in Production.
      - **Validation and dumps.** Validation messages, flashed input (`dontFlash` already lists `national_id`), `dd()`, `dump()`
        and `Log::*` context arrays must never receive the value. Symfony VarDumper reads properties, so the reference itself
        shows nothing there.
    - `zend.exception_ignore_args=On` remains recommended as a Production PHP setting (`docs/08`, part of G-07 evidence).
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
  - `admissionReplayed()` and `admissionRefused()` are written on their own, after the admission transaction
    (`admissionReplayed()` was replaced by `replayedFromEvidence()` in ADD-WP-07B-2a-F);
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

## 10a. Registry release pin (ADD-WP-07B-2b)

**Implemented locally, application side only; uncommitted. Nothing calls or binds it.** It follows the ADD-WP-07B-2R decisions
D-F1, D-F3 and D-F5. `identity_ref`, the publication lifecycle and Production configuration are unchanged. DBA-1 … DBA-3 are not
applied, so in operation every pin fails closed and no admission could pass it. Gates G-01 … G-07 stay OPEN.

- **Contract.** `RegistryReleasePin::pin(int $releaseId)` runs inside the admission transaction, before its row locks. It
  returns only if publication cannot replace the release until that transaction ends. Its outcomes:
  - `RELEASE_CHANGED` (409) when the release is no longer active;
  - otherwise `RegistryReleasePinUnavailableException`, which carries one controlled code and no pid, nonce, SQL or message.
- **`PostgresRegistryReleasePin`, on the admission connection:**
  1. A transaction is required (`PIN_NOT_IN_TRANSACTION`), and so is READ COMMITTED (`PIN_ISOLATION_UNSUPPORTED`).
  2. In ONE statement (ADD-WP-07B-2b-F), so that all three come from the same backend whatever a
     pool does between statements:
     - `pg_try_advisory_xact_lock_shared` on `'FBCIV01P'` (`0x4642434956303150`). It never waits: `PIN_BUSY`, retryable,
       while a publication holds the key or waits for it;
     - an exclusive transaction-level nonce lock (`'FBPN'`, random 1 … 2^31 − 1). `PIN_NONCE_BUSY`, retryable, on a
       collision; the rollback then releases the shared pin taken in the same statement;
     - `pg_backend_pid()`.
  3. The verifier, on the registry's own session, answers in one READ COMMITTED statement: is session `pid` in a LIVE
     transaction that holds the shared pin AND this nonce, in the verifier's database, without being the caller; and which
     release is active. `PIN_VERIFIER_UNAVAILABLE` if it cannot be called. `PIN_NOT_PROVEN` if it does not see the pin.
- **Why not a pid alone.** Every running admission holds the shared pin, so another admission's pid would pass a pid-only check.
  A pid is also reused after a backend ends. The nonce lock exists only while this one transaction is open, and must share its
  `virtualtransaction` with the pin. The mutation test shows the difference: with a pid-only verifier, the pid test fails.
- **Same database.** Advisory locks of different databases never conflict. The verifier therefore compares `pg_locks.database`
  with its own database. A registry in another database of the same cluster cannot see the pin and answers `PIN_NOT_PROVEN`;
  this is tested with a second database on the test cluster.
  - **Another cluster is NOT excluded by the proof.** Nothing in the protocol is cryptographic: a pid, a 31-bit nonce and a
    database oid only mean something inside one cluster. A lookup connection that reached another cluster would answer
    `held = true` if a backend there had the same pid and held the pin and the same nonce at that moment. That is improbable,
    not impossible, and it cannot be ruled out from inside the database. Excluding it is an operational guarantee, DBA-4.
- **A caller cannot forge a proof.** `pin()` takes no pid: it reads its own, in the statement that takes the locks. A
  verifier called with another session's pid answers `held` only if that session really holds both locks in a live
  transaction, and even then it confirms that session's pin, never the caller's. A nonce collision only refuses
  (`PIN_NONCE_BUSY`): a false positive needs the caller's own pid. The admission action must build the pin on the very
  connection of its transaction; a pin on another connection protects nothing.
- **Live transaction, fresh snapshot (ADD-WP-07B-2b-F).**
  - The verifier also requires the `virtualxid` lock of the holder's virtual transaction, so the session-level locks of an
    idle session are not a pin.
  - It proves nothing unless its own statement runs under READ COMMITTED. In an older REPEATABLE READ or SERIALIZABLE
    snapshot, the active release it reads could be stale and would confirm a replaced release.
  - Mutation tests: removing either condition makes its test fail.
- **Pooling.** The pid and both locks are transaction-scoped and read in one statement, so transaction-mode pooling, which
  keeps one backend per transaction, is compatible. Statement-mode pooling breaks transactions and is unsupported. The lookup
  session already depends on session state (`SET ROLE`, FB-CIVIL-02A) and must not be transaction-pooled either.
- **Mandatory lock order of the admission action (ADD-WP-07B-2b-F).**
  - The order: the pin, then Family `FOR UPDATE`, then the head's active membership `FOR SHARE`, then the child's National
    ID advisory lock, then Person `FOR UPDATE`. A Person with an active membership anywhere is refused before that Person is
    locked.
  - No cycle with the existing writers, checked against each:
    - `EndFamilyMembershipAction` and `CorrectMembershipRelationshipAction` lock a membership first. For the head's
      membership they refuse, or change nothing, without waiting for the Family.
    - Recording the head's death locks the head's Person and link, then waits for the Family (its activity row). The
      admission does not lock the head's Person or link: the Family lock alone orders the two.
    - The Change Request APPLY, `AddFamilyMemberAction`, `AttachFamilyMemberAction`, `CorrectNationalIdAction` and
      `RecordPersonDeathAction` (for the child) keep the same Family → National ID → Person order, as in ADD-WP-07B-2R §3.
    - An import row locks an existing Person before a Family it creates itself, which the admission cannot hold.
  - Any residual deadlock is detected by PostgreSQL (40P01). It rolls back, and the failure contract answers it as a
    retryable 503.
- **Privileges.**
  - The application role needs nothing new: advisory locks and `pg_locks` are available to every role.
  - The lookup login needs EXECUTE on the new function only (DBA-3). `pg_locks` is not filtered by role, so the function needs
    no monitoring privilege.
- **Lock order.** The pin is taken first in the transaction, before the Family lock (ADD-WP-07B-2R §4). It never waits, so it
  cannot join a deadlock cycle, and the order is not safety-critical. Database errors on the admission connection propagate
  unchanged, and `RegistryAdmissionFailure::run()` sanitizes them; a pin failure is logged under its own code.
- **Operational verifier.** `CivilRegistryReader::releasePinHeld()`, the only user of the lookup connection, calls
  `identity_ref_lookup.release_pin_held(pid, nonce)`. That function does not exist (DBA-3), so the call fails and the pin
  fails closed.
- **DBA-3 contract (revised; proposal only, not applied).** It replaces the `(int, bigint)` of ADD-WP-07B-2R: a nonce is added
  and the release argument is dropped. To be applied by the DBA as `famboook_identity_owner`, in the database that holds both
  the application tables and `identity_ref`:

  ```sql
  CREATE OR REPLACE FUNCTION identity_ref_lookup.release_pin_held(p_holder_pid integer, p_nonce integer)
      RETURNS TABLE (held boolean, active_release_id bigint)
      LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
      SELECT EXISTS (
                 SELECT 1 FROM pg_catalog.pg_locks pin
                   JOIN pg_catalog.pg_locks nonce ON nonce.pid = pin.pid AND nonce.virtualtransaction = pin.virtualtransaction
                  WHERE pin.locktype = 'advisory' AND nonce.locktype = 'advisory'
                    AND pin.database = (SELECT d.oid FROM pg_catalog.pg_database d WHERE d.datname = pg_catalog.current_database())
                    AND nonce.database = pin.database
                    AND pin.classid = 1178747721::oid AND pin.objid = 1445998928::oid AND pin.objsubid = 1      -- 'FBCIV01P'
                    AND pin.mode = 'ShareLock' AND pin.granted
                    AND nonce.classid = 1178751054::oid AND nonce.objid = p_nonce::oid AND nonce.objsubid = 2  -- 'FBPN', nonce
                    AND nonce.mode = 'ExclusiveLock' AND nonce.granted
                    AND pin.pid = p_holder_pid AND pin.pid <> pg_catalog.pg_backend_pid() AND p_nonce > 0
                    AND EXISTS (SELECT 1 FROM pg_catalog.pg_locks live
                                 WHERE live.locktype = 'virtualxid' AND live.pid = pin.pid
                                   AND live.virtualxid = pin.virtualtransaction AND live.mode = 'ExclusiveLock' AND live.granted))
             AND pg_catalog.current_setting('transaction_isolation') = 'read committed',
             (SELECT a.release_id FROM identity_ref.active_release a WHERE a.id = 1)
  $$;
  REVOKE ALL ON FUNCTION identity_ref_lookup.release_pin_held(integer, integer) FROM PUBLIC;
  GRANT EXECUTE ON FUNCTION identity_ref_lookup.release_pin_held(integer, integer) TO famboook_identity_reader;
  ```

  - The fixture's function is this body. It differs only in its schema, in being SECURITY INVOKER and in writing its operators
    as `OPERATOR(pg_catalog.…)`; the DBA file should qualify them the same way.
  - Comparing `pg_locks.database` with the database of `current_database()` is what makes a registry in another database
    answer `held = false`.
  - The function grants nothing new to the application role.
- **Synthetic fixture (tests only).** `SyntheticReleasePinFixture` is one committed schema in the test database, dropped after
  each test. It models DBA-1 (publication: fail fast on `'FBCIV01A'`, then wait, bounded, for `'FBCIV01P'`), DBA-2 (the pointer
  only moves forward, and only under the exclusive pin) and DBA-3. `SyntheticReleasePinVerifier` sends the operational query
  to it. Both refuse to run outside the test runner, and neither is bound.
- **Limits that need the DBA package.**
  - Without DBA-1, a real publication still fails fast whenever an admission holds the pin, which fails an approved
    publication.
  - Without DBA-2, the owner can still rewind the pointer by hand.
  - Without DBA-3, nothing can be verified.
  - A superuser or the identity owner can bypass all of these.
  - `pg_locks` cannot tell transaction-level from session-level advisory locks. The live-transaction condition refuses an idle
    session. A session that holds session-level locks AND is in a transaction would still be accepted; this is tested and
    documented. Only this code takes the nonce, and only at transaction level.
  - A session-level lock on `'FBCIV01P'` outlives transactions and blocks publication (tested). Any database role can cause
    this, as on `'FBCIV01A'` today; it needs monitoring.
  - A lookup connection on another cluster could answer a false positive only if a backend with the same pid held the pin
    and the same 31-bit nonce there at that moment. The protocol does not exclude this; only DBA-4 does.
- **Mandatory prerequisites before any admission activation (ADD-WP-07B-2b-C).** None is implemented. Each needs its own approved
  DBA or operations work package, and evidence for G-06 and G-07.
  - **DBA-1.** `publish_release()` takes `'FBCIV01P'` exclusively, with a bounded wait, after `'FBCIV01A'`.
  - **DBA-2.** A trigger on `identity_ref.active_release`: the release only moves forward, and only while the writer holds the
    pin exclusively.
  - **DBA-3.** `identity_ref_lookup.release_pin_held(integer, integer)` as specified above, with EXECUTE for
    `famboook_identity_reader` only.
  - **DBA-4: one cluster and one database, under all routing and failover.** An operational guarantee: no code can prove it.
    - The admission connection (`DB_*`) and the lookup connection (`IDENTITY_REF_LOOKUP_DB_*`) reach the same PostgreSQL cluster
      and the same database. Host, port and database name resolve to the same server.
    - **Routing.** No proxy, load balancer, DNS name or pooler may send the lookup connection to another node, another cluster or
      a read replica. A standby does not see the primary's advisory locks, so a replica fails closed (`PIN_NOT_PROVEN`), but it
      is still a misconfiguration.
    - **Failover.** Both connections move together, to the same new primary. While they do not, every pin fails closed. A
      split-brain, with two writable primaries, breaks the guarantee and must be excluded by the cluster manager.
    - **Evidence.** It is recorded in the Production audit before G-07: the configured endpoints, the routing and failover
      design, and a check run from both connections that they report the same server and database.

## 10b. Atomic admission (ADD-WP-07B-2c)

**Implemented locally, internal only; uncommitted.** It follows plan ADD-WP-07B-2c-P and the ADD-WP-07B-2R decisions. Gates
G-01 … G-07 stay OPEN, D-06 is unchanged and the flag is `OFF`. No migration.

- **Entry.** `AdmitRegistryChildAction::handle(FamilyAccessResult, RegistryAdmissionRequest): RegistryAdmissionOutcome`, entirely
  inside `RegistryAdmissionFailure::run()`.
  - The request carries the reference, a lowercase UUID `client_reference`, the declaration version and its acceptance.
  - The outcome carries only `member_ref` and `replayed`.
  - Refusals are `RegistryAdmissionRefusedException`: one public code, `{message, code}`, `no-store`, with the internal reason
    kept for the audit only. The codes are 403 `FAMILY_CONTEXT_UNAVAILABLE`, 404 `ADMISSION_UNAVAILABLE`, 422
    `DECLARATION_REQUIRED`, 422 `NOT_ELIGIBLE`, 409 `ADMISSION_IDEMPOTENCY_CONFLICT` and 422 `REFERENCE_INVALID` (the context
    changed under the lock).
  - A reference failure is `REFERENCE_INVALID` (422) or `RELEASE_CHANGED` (409); everything else is the sanitized 503.
- **Order.**
  - **A. Pre-checks.** Household context, flag and permission, then the declaration. The declaration must equal
    `family_registry_admission.declaration_version`, which has no default, so while it is unset every admission is
    `DECLARATION_REQUIRED` (O-K). None of these is recorded.
  - **B. Replay.** A committed evidence row for the actor's key is replayed before any registry read (D-B1 … D-B6).
  - **C. Reference.** `RegistryAdmissionRefs::readForAdmission()` applies `read()`'s 15 minutes and every check except the
    release; the action compares the release itself. An authentic but expired reference (24 hours, `openForReplay()`) can
    only find a committed admission of the key, after waiting for the Family lock; otherwise it is `REFERENCE_INVALID`.
  - **D. Registry.** `SuggestedChildrenDiscovery::candidates()` reads the source outside the transaction, with the same read,
    release checks and cross-record reasons as `discover()`, whose API is unchanged.
  - **E. Transaction.** One READ COMMITTED transaction with `lock_timeout` 3 s and `statement_timeout` 15 s. The locks are taken
    in order: the release pin on the same connection, Family `FOR UPDATE`, the head's membership `FOR SHARE` with the context
    resolved again, the key looked up again, the child's National ID advisory lock, eligibility again under the locks (fresh
    reads), then `FamilyMemberIdentity::resolve()`, which refuses an active member before Person `FOR UPDATE`. Then
    `AddFamilyMemberAction` (new Person) or `AttachFamilyMemberAction` (existing Person, unchanged), the evidence row and
    `ADMISSION_SUCCEEDED`, all committed together.
  - **F. Refusals.** A refusal rolls everything back and is recorded in its own short transaction, with the internal reason, the
    release and the child's fingerprint when known. It does not consume the key. A unique violation, or a duplicate or
    validation refusal from the existing actions, is answered as a replay if the key committed meanwhile, otherwise
    `LOST_RACE`. Registry and pin failures are recorded as `REGISTRY_UNAVAILABLE`.
- **Eligibility.** `GatedRegistryChildAdmissibility` is `RegistryChildEligibility::evaluate()` plus discovery's reasons, and
  never proves a life (G-05, D-06), so it admits no one. The action refuses any other `RegistryChildAdmissibility` outside the
  test runner. The only other implementation, `tests/Support/FamilyPortal/PendingGatesLiftedAdmissibility`, lifts only the
  pending gates (`GENDER_MAPPING_PROVISIONAL` when the gender maps, `LIFE_UNVERIFIED` only for an empty death date) and never
  `DECEASED` or `INDETERMINATE`.
- **Privacy.**
  - **Query capture.** The action refuses to run (503) while the query log is enabled or any `QueryExecuted` listener exists.
    This includes Laravel's own debug-mode exception renderer, which keeps up to 101 queries WITH their bindings when
    `APP_DEBUG=true`. `APP_DEBUG=false` in Production is therefore a hard prerequisite.
  - **Parameters.** National ID parameters along the path are `#[\SensitiveParameter]`: `AddFamilyMemberAction`,
    `CreatePersonAction`, `NationalIdGuard::matches()` and `assertAvailable()`, and `RegistryChildSource`.
  - **Bound values.** `CreatePersonAction`'s rethrown `QueryException`, which carries the bound National ID, reaches only
    `RegistryAdmissionFailure::run()`.
- **Tests.**
  - `AdmitRegistryChildActionTest`, one session (15 on PostgreSQL; the context, flag, declaration, reference, replay and failure
    paths also on SQLite), covers:
    - success for NEW_PERSON and ATTACHABLE;
    - every eligibility refusal;
    - D-06;
    - the replay scenarios;
    - pin and verifier failures;
    - evidence and audit failures;
    - a unique violation;
    - query capture;
    - the test-only guard.
  - `AdmitRegistryChildConcurrencyTest` (PostgreSQL) runs real processes:
    - one key twice;
    - two families and one child;
    - a publication waiting for an admission while another admission gets `PIN_BUSY` and a stale one `RELEASE_CHANGED`;
    - a lock timeout;
    - a Staff add of the same child elsewhere and in the same Family.
  - Committed admission rows make the tables refuse TRUNCATE, so that class rebuilds the schema with `migrate:fresh`.
- **Review ADD-WP-07B-2c-C.**
  - **Bounded wait on the replay path.** The expired-reference replay path waits for the Family lock under the same 3 s
    `lock_timeout` as the admission: a long-held Family lock is a retryable 503, never an endless wait.
  - **Query-capture guard compatibility.**
    - Checked with the application booted under `APP_DEBUG=false`: no `QueryExecuted` listener and no query log, so the
      guard does not interfere.
    - Under `APP_DEBUG=true`, Laravel's renderer listener is present and every admission is refused, as intended.
    - Any future APM or diagnostics package that listens to queries (Telescope, Pulse, Nightwatch, Sentry or similar) will
      stop admissions until it excludes this path. That is deliberate, and must be weighed when one is added.
  - **Deadlock (40P01).**
    - The admission's transaction makes one attempt, so a 40P01 is rethrown as a `QueryException`, not retried.
    - `RegistryAdmissionFailure::run()` maps 40P01 to `DEADLOCK`, a retryable 503 with `Retry-After`. This is tested with a
      synthetic `QueryException` (`RegistryAdmissionFailureTest`).
    - Nothing is committed, because PostgreSQL rolls the victim back.
    - No test produces a real deadlock: the lock order (§10a) has no cycle with the existing writers, so none could be
      constructed. That handling therefore rests on the contract test and the lock-order review.
  - **Audit coverage of the pre-checks (for ADD-WP-07C).**
    - No household context, a closed flag or a missing permission, and a missing or wrong declaration are NOT recorded:
      - the first has no Family to record against;
      - the flag and permission refusals are deliberately silent, as for discovery (§7b);
      - the declaration has no procedural code.
    - Everything after these checks is recorded: reference, release, eligibility, idempotency, lost race and registry or pin
      unavailability.
    - ADD-WP-07C must therefore put its rate limit before the action, and decide whether repeated declaration refusals need
      their own code. One row per refused attempt also means the audit grows with abuse until that limit exists.
- **Not covered by a test:** an actual deadlock (40P01), as explained above.

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
| G-07 | Separate explicit approval before PILOT or Production activation | Recorded approval naming the commit, mode and allowlist, with `09` index approval where needed; DBA-1 … DBA-4 applied and verified (§10a) | Project owner | **OPEN** |

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
