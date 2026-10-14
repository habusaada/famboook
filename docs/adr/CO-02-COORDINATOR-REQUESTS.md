# CO-02 — Coordinator-Submitted Requests

**Status:** Approved architecture (CO-02.2, 2026-10-13). Schema foundation
(CO-02.3a), engine integration (CO-02.3b), the PERSON_ADD API (CO-02.4),
FAMILY_ADD / REGISTER_FAMILY (CO-02.5), the Coordinator and Staff UI
(CO-02.6–7a) and hardening (CO-02.7b) implemented locally, not deployed;
gate OFF by default. See §14–§20 (as built) and §21 (approved attestation
meaning); release runbook docs/08 §16d, pilot procedure docs/09.
**Implementation:** CO-02.3 onwards. Feature-gated, OFF by default; no
Production activation without explicit approval.

**Registered ADRs:** docs/06 AUTH-ADR-093 (permissions and authorization),
docs/04 DB-ADR-062 (schema), docs/05 WF-ADR-054 (workflow), docs/11
FP-ADR-080 (Coordinator Space). This file is the complete design record the
four entries point to. It builds on CO-01A/B/C (coordinator foundation,
administration, members directory — AUTH-ADR-070, AUTH-ADR-092) and
CO-01D.4/5 (National ID integrity — DB-ADR-061).

> Repository note: Famboook records ADRs inside the numbered documents,
> each in its own register. This dedicated file holds the full design
> because it spans four registers; the registers stay authoritative for
> their own decisions.

---

## 1. Context (verified against `main` at b2f504d)

| Fact | Where |
|---|---|
| Eight canonical roles; COORDINATOR is family-side | `RolePermissionSeeder::ROLES` |
| COORDINATOR holds exactly `coordinator-space.access`, `coordinator-family.view-summary`, `coordinator-member.view`, `coordinator-member.view-sensitive` | `RolePermissionSeeder` (COORDINATOR) |
| A coordinator is an eligible household head with ≥ 1 effective scope | `CoordinatorScopes::context()` |
| Scope = union of CLAN / BRANCH_GROUP / BRANCH assignments, current hierarchy, re-resolved on every request | `CoordinatorScopes::families()`, `EnsureCoordinatorSpace` |
| The Change Request engine accepts only a family actor submitting for its own household | `SubmitChangeRequestAction::handle(FamilyAccessResult …)`, `ChangeRequestActors::family()` |
| A request always belongs to an existing ACTIVE Family | `change_requests.family_id` NOT NULL; `ChangeRequestWorkflow::lockCanonical()` |
| Approved type codes are a database CHECK | `chk_change_request_type` |
| Workflow actors: FAMILY, STAFF, SYSTEM | `chk_workflow_event_actor_side`, `WorkflowActorSide` |
| Family cancel / resubmit check only Family ownership | `CancelChangeRequestAction`, `ResubmitChangeRequestAction` → `ChangeRequestActors::ownedBy()` |
| The submitter's display name is already returned | `ChangeRequestResource` / `ChangeRequestSummaryResource` `submitted_by.name` (submitter Person) |
| ADD_FAMILY_MEMBER: required nine-digit ID, no identity answer at submit, identity resolved at approve/APPLY, two reviewer attestations | `AddFamilyMemberHandler` |
| Registration source is an application enum without a DB CHECK | `RegistrationSource` (PAPER_FORM, MANUAL_ENTRY, IMPORT, VERIFIED_SOURCE); residence `source` uses the same enum |
| Branches and groups carry `UNIQUE (id, clan_id)` for composite keys | `create_clan_structure_tables` |
| A coordinator account also holds FAMILY_USER, hence `change-request.submit` | `RolePermissionSeeder` (FAMILY_USER) |

---

## 2. Decisions

| # | Decision |
|---|---|
| D1 | The eight roles are unchanged; no role is created. |
| D2 | New COORDINATOR-only permissions for coordinator requests; every existing permission and role mapping is unchanged. Coordinator paths never authorize through `change-request.*` (a coordinator account already holds them through FAMILY_USER). |
| D3 | A coordinator may submit **PERSON_ADD** for a Family in scope and **FAMILY_ADD** for a new Family placed in scope. |
| D4 | One engine, one central Staff review queue. No parallel workflow. Reviewing, approving, rejecting and applying stay Staff acts (`change-request.review` / `.approve` / `.reject` / `.apply`). |
| D5 | Coordinators never modify registry records directly: canonical data changes only at the approved APPLY, through the existing Domain Actions. |
| D6 | A household head sees coordinator-submitted requests about their own Family in the Family Portal history. |
| D7 | Only the submitting actor may cancel, subject to the existing transitions: a coordinator-channel request only by the submitting coordinator user; a family-channel request as today. Clarification responses (resubmit) follow the same rule. |
| D8 | Request details show the submitting coordinator's identity (display name) and the channel to Staff and to the household head. |
| D9 | Scope revocation removes the coordinator's operational access to out-of-scope requests; Staff keep the full request and audit history; nothing is deleted. |
| D10 | FAMILY_ADD V1 captures the head's identity, the Clan and Branch, and the ORIGINAL residence only (never the current residence). New `RegistrationSource` value **`COORDINATOR_REQUEST`** (§6). |
| D11 | A coordinator who is a household head keeps their own household through the Family Portal, independently of Coordinator Space; Coordinator Space never shows another Family's health, assessment, assistance or current residence. |
| D12 | Coordinators remain eligible household heads in V1. |
| D13 | Feature gate: OFF by default, PILOT allowlist, GENERAL; no Production activation without approval. |

---

## 3. Request types

| Product name | Type code | Handler | Target | Family exists at submit |
|---|---|---|---|---|
| PERSON_ADD | `ADD_FAMILY_MEMBER` (existing) | `AddFamilyMemberHandler` (unchanged at approve / APPLY) | a Family in the coordinator's scope | yes |
| FAMILY_ADD | **`REGISTER_FAMILY`** (new) | new `RegisterFamilyHandler`, APPLY calls `RegisterFamilyAction` | none until APPLY | no |

The code `REGISTER_FAMILY` follows the existing verb-first convention
(`ADD_FAMILY_MEMBER`) and the Domain Action it applies. "PERSON_ADD" and
"FAMILY_ADD" are product names only.

### PERSON_ADD (`ADD_FAMILY_MEMBER` through the coordinator channel)

- The Family comes from the PATH (`family_code`), resolved only inside
  `CoordinatorScopes::families()`; out of scope = 404, the same as
  nonexistent.
- Same payload, validation and normalization as the family form
  (`AddFamilyMemberHandler::inputRules` / `normalize`): full name, nine-digit
  National ID (canonical), gender, relationship (never HEAD), optional birth
  date, marital status, mobile.
- Approve / APPLY are byte-for-byte the existing path: two attestations,
  re-typed ID, `resolve()` under the National ID lock → new Person
  (`AddFamilyMemberAction`) or attach an existing unattached Person
  (`AttachFamilyMemberAction`); every unsafe case PRECONDITION_FAILED.
- Open-conflict key unchanged: one open request per Family and National ID
  (whatever the channel).

### FAMILY_ADD (`REGISTER_FAMILY`)

Proposal (`submitted_data`, payload version 1):

```text
head        full_name, national_id (nine digits, canonical), gender,
            marital_status?, birth_date?, mobile?
placement   clan (code), branch (code)?        — also stored as columns (§5)
residence   original_residence_text            — ORIGINAL residence only
```

- Submit: placement must be covered by the coordinator's effective scope
  (§4); nothing identity-dependent is checked or answered (no National ID
  existence oracle).
- Approve: reviewer attestation `IDENTITY_VERIFIED` and the re-typed
  National ID (as ADD_FAMILY_MEMBER); the ID must be free: no Person holds
  it, soft-deleted included (`NationalIdGuard`, CO-01D.4).
- APPLY (one transaction, §8): `RegisterFamilyAction` with
  `registration_source = COORDINATOR_REQUEST`, registration date = APPLY
  date, the head as HEAD member, a residence carrying
  `original_residence_text` only (source `COORDINATOR_REQUEST`), and
  `created_family_id` recorded on the request.
- Additional members are later PERSON_ADD requests on the created Family.

---

## 4. Scope rules (backend only, never frontend filtering)

| Operation | Rule |
|---|---|
| PERSON_ADD submit | Family resolved through `CoordinatorScopes::families()` |
| FAMILY_ADD submit | new `CoordinatorScopes::coversPlacement(context, clan, branch)`: a CLAN assignment covers its Clan with or without a Branch; BRANCH_GROUP covers a Branch of that group; BRANCH covers that Branch. A placement without a Branch is covered only by a CLAN assignment (as for existing Families). Clan, Group and Branch must be ACTIVE. |
| List / detail / resubmit / cancel | The request was submitted by THIS user **and** is still in the CURRENT scope: PERSON_ADD → its Family is in `families()`; FAMILY_ADD before APPLY → its placement is covered; after APPLY → the created Family is in `families()`. Otherwise 404. |
| Staff | Unaffected by coordinator scope; Staff see every request (existing `change-request.view`). |

Scope is re-checked on every request and inside every Domain Action, not
only at the HTTP layer. Approval and APPLY never depend on the
coordinator's scope.

---

## 5. Database migration design (DB-ADR-062)

One additive migration `2026_10_21_090000_add_coordinator_change_requests.php`,
PostgreSQL CHECKs as in every migration of the repository.

### `change_requests`

| Change | Detail |
|---|---|
| `family_id` | DROP NOT NULL |
| `submission_channel` | `VARCHAR(20) NOT NULL DEFAULT 'FAMILY'`; CHECK `IN ('FAMILY', 'COORDINATOR')`; existing rows become FAMILY by the default (no backfill statement) |
| `proposed_clan_id` | `BIGINT NULL`, FK `clans(id)` restrict |
| `proposed_branch_id` | `BIGINT NULL`; composite FK `(proposed_branch_id, proposed_clan_id)` → `branches(id, clan_id)` restrict |
| `created_family_id` | `BIGINT NULL`, FK `families(id)` restrict |
| Type CHECK | `chk_change_request_type` re-created with `'REGISTER_FAMILY'` added |
| New CHECK `chk_change_request_family_subject` | `(type = 'REGISTER_FAMILY') = (family_id IS NULL)` — every other type keeps a Family |
| New CHECK `chk_change_request_placement` | `(type = 'REGISTER_FAMILY') = (proposed_clan_id IS NOT NULL)` and `proposed_branch_id IS NULL OR proposed_clan_id IS NOT NULL` |
| New CHECK `chk_change_request_created_family` | `created_family_id IS NOT NULL` exactly when `type = 'REGISTER_FAMILY' AND status = 'APPLIED'` |
| New CHECK `chk_change_request_channel_type` | `type <> 'REGISTER_FAMILY' OR submission_channel = 'COORDINATOR'` (V1: only coordinators register families by request) |
| Indexes | `(submission_channel, status, submitted_at)`; `(submitted_by, status)`; `(proposed_clan_id, proposed_branch_id)`; `created_family_id` |

`submitted_by` / `submitted_by_person_id` keep their meaning: the
coordinator's account and the coordinator's own Person (always present,
D12). The CONTACT_UPDATE actor relaxations are untouched: coordinator
requests always carry a named actor.

### `workflow_events`

- `chk_workflow_event_actor_side` re-created: `IN ('FAMILY', 'STAFF', 'SYSTEM', 'COORDINATOR')`.
- `actor_side` widens from `varchar(10)` to `varchar(20)`: `COORDINATOR` has
  eleven characters (found in CO-02.3a by inspecting the live schema).
- `chk_workflow_event_actor` unchanged (a COORDINATOR event has an actor user).
- `chk_workflow_event_internal_note` unchanged (STAFF only).
- The append-only trigger is untouched.

### Rollback

`down()` refuses (RuntimeException, nothing changed) while any
`REGISTER_FAMILY` or `COORDINATOR`-channel row exists; otherwise it restores
the previous CHECKs, drops the columns and restores `family_id NOT NULL`.
History is never deleted (the PWA-5a pattern).

### Not changed

No column on `families` or `persons`; `registration_source` and residence
`source` gain the value in the application enum only (no DB CHECK exists).
`family_activities.family_id` stays NOT NULL: a REGISTER_FAMILY request
writes Family activity only from APPLY, on the created Family.

---

## 6. Registration source

New `RegistrationSource::COORDINATOR_REQUEST`, following the existing
convention that a value names the channel a record entered by (PAPER_FORM,
MANUAL_ENTRY, IMPORT, VERIFIED_SOURCE). It is set only by
`RegisterFamilyHandler` at APPLY, never selectable in the Staff
registration form or the household-declaration form (both keep their
explicit allow-lists), and labelled in the frontend. Rejected alternative:
MANUAL_ENTRY (hides the origin; provenance would rest on the request link
only).

---

## 7. Permissions and authorization matrix (AUTH-ADR-093)

New permissions, seeded to **COORDINATOR only**:

| Permission | Allows |
|---|---|
| `coordinator-request.view` | the coordinator's own requests in current scope (list, detail) |
| `coordinator-request.submit` | submit PERSON_ADD / FAMILY_ADD |
| `coordinator-request.resubmit` | answer a clarification on an own request in scope |
| `coordinator-request.cancel` | cancel an own request in scope |

Every coordinator Domain Action re-checks: family-side account,
`coordinator.space` context (eligible head + role + effective scope), the
permission, the gate, ownership (`submitted_by` = user, channel
COORDINATOR) and scope.

| Actor | Submit | View | Resubmit | Cancel | Review / approve / reject / apply |
|---|---|---|---|---|---|
| Coordinator (own request, in scope) | PERSON_ADD in scope, FAMILY_ADD placed in scope | yes (masked identifiers) | yes | yes | **no** |
| Coordinator (other coordinator's request, or out of scope) | — | 404 | no | no | no |
| Household head of the target Family | family types as today | yes, family history, submitter and channel shown | **no** for a COORDINATOR-channel request | **no** for a COORDINATOR-channel request | no |
| REVIEWER / ADMINISTRATOR / SUPER_ADMIN | — | yes (`change-request.view`) | — | — | yes (existing permissions) |
| Other Staff, FAMILY_USER of another Family, guest | — | no | no | no | no |

Engine changes (CO-02.3): `ChangeRequestActors::coordinator()`; transitions
for COORDINATOR (`null → SUBMITTED`, `RETURNED → RESUBMITTED`,
`SUBMITTED / UNDER_REVIEW / RETURNED / RESUBMITTED → CANCELLED`) with the
`coordinator-request.*` permissions; the family `cancel` / `resubmit`
actions additionally require `submission_channel = FAMILY`.

---

## 8. Privacy, concurrency, idempotency and audit

**Privacy.**
- Coordinator Space never returns health, disability, assessment,
  assistance, needs, notes or the CURRENT residence of any Family; FAMILY_ADD
  captures the ORIGINAL residence only.
- No National ID existence answer at submit (both types): identity is
  decided at approve / APPLY by Staff. FAMILY_ADD has **no global
  open-conflict check across coordinators** (that would reveal another
  coordinator's pending ID); its open-conflict key is per submitting user
  and National ID. PERSON_ADD keeps its per-Family key.
- Coordinator views show identifiers MASKED (`NationalIdMask`,
  `MobileMask`); full values only through the existing CO-01C reveal, which
  covers registry members, never proposals.
- Staff see a safe registry-match summary and other open requests for the
  same ID (Staff only), as ADD_FAMILY_MEMBER does today.

**Concurrency.**
- Submit: PERSON_ADD locks the Family row (existing engine); FAMILY_ADD
  takes the National ID advisory lock on the canonical value (serializes
  the per-user conflict check and the replay check).
- Approve / APPLY: request row lock (existing); PERSON_ADD as today
  (Family → National ID lock → Person); REGISTER_FAMILY: request → National
  ID lock → proposed Clan / Branch rows `FOR KEY SHARE` → `RegisterFamilyAction`
  in the same transaction; `uq_persons_national_id` is the final guard (a
  lost race is the existing duplicate refusal, mapped to PRECONDITION_FAILED
  / APPLY_FAILED).

**Idempotency.** `client_reference` unique per submitting user (existing
index). Replay compares type, Family or placement, proposal and reason;
anything different is `IDEMPOTENCY_CONFLICT`.

**Audit.**
- Every transition is a `workflow_events` row; coordinator acts are
  `actor_side = COORDINATOR` with `actor_user_id`.
- PERSON_ADD writes `CHANGE_REQUEST_SUBMITTED` Family activity on the
  target Family; REGISTER_FAMILY writes Family activity only at APPLY
  (`FAMILY_CREATED` by `CreateFamilyAction`, plus the request link).
- No National ID or mobile value in events, activity or logs.

---

## 9. Behaviour in edge cases

| Situation | Behaviour |
|---|---|
| Scope revoked or narrowed | The coordinator's list, detail, resubmit and cancel answer 404 for requests now out of scope. Staff review continues normally; nothing is deleted or reassigned. |
| Coordinator role removed / head eligibility lost | No Coordinator Space at all; open requests stay in the Staff queue. |
| Target Family becomes inactive or deleted | Out of every scope (404 to the coordinator); approve / APPLY refuse at `lockCanonical` → Staff reject `NO_LONGER_APPLICABLE` (existing). |
| Proposed Clan / Branch deactivated | Out of scope for the coordinator; approve / APPLY refuse (PRECONDITION_FAILED). |
| Proposed head ID taken meanwhile (any Person, soft-deleted included) | Approve refuses; APPLY refuses (`NationalIdGuard`, unique index); Staff reject. |
| Duplicate PERSON_ADD (same Family + ID) | `ALREADY_OPEN` (existing per-Family key, whatever the channel). |
| Duplicate FAMILY_ADD by the same coordinator | `ALREADY_OPEN` (per-user key). By different coordinators: both accepted; Staff see both; the first APPLY wins, the second is refused at approve / APPLY. |
| Concurrent approval / application | Existing request row lock and status machine; a second actor gets `INVALID_TRANSITION` or `BASE_CHANGED`. |
| Cancellation | Only the submitting actor; COORDINATOR channel: the submitting coordinator in scope; never after APPROVED (existing rule). |
| Failed APPLY | The request stays APPROVED with `apply_failure_count` / `APPLY_FAILED` event (existing); retry or reject `NO_LONGER_APPLICABLE` by Staff. Nothing partial is written (one transaction). |

---

## 10. API contract proposal

> The contract as built for PERSON_ADD (CO-02.4) is §15; this proposal is
> kept as approved history.

All under `/api/v1/family/coordinator`, behind `auth:sanctum`,
`family.side`, `coordinator.space`; JSON; `Cache-Control: no-store`;
requests addressed by `uuid`, shown by `request_code`; identifiers masked.

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/requests/types` | `coordinator-request.submit` | Types available now (gate + registered handlers) |
| GET | `/requests` | `coordinator-request.view` | Own, in-scope requests; filters `status`, `type`; paginated |
| GET | `/requests/{uuid}` | `coordinator-request.view` | Detail: status, timeline, family-visible messages, proposal masked |
| POST | `/families/{familyCode}/requests` | `coordinator-request.submit` | PERSON_ADD body `{type: "ADD_FAMILY_MEMBER", data: {...}, reason?, client_reference}`; 201 / 200 replayed |
| POST | `/requests` | `coordinator-request.submit` | FAMILY_ADD body `{type: "REGISTER_FAMILY", data: {head, placement, residence}, reason?, client_reference}` |
| POST | `/requests/{uuid}/resubmit` | `coordinator-request.resubmit` | `{response}` |
| POST | `/requests/{uuid}/cancel` | `coordinator-request.cancel` | — |

Errors: 401; 403 `COORDINATOR_SPACE_UNAVAILABLE` / permission / gate
(`SUBMISSION_DISABLED`, 503 as the family channel); 404 out of scope or
nonexistent (one answer); 409 `ALREADY_OPEN`, `IDEMPOTENCY_CONFLICT`,
`INVALID_TRANSITION`; 422 validation; 429 per-user throttles
`coordinator-request-submit` and `coordinator-request-action`.

Staff API unchanged except: `GET /api/v1/change-requests` gains
`channel` and `submitter` filters; the resource adds `submission_channel`,
`placement` (Clan / Branch names) and `created_family_code`.

Family API unchanged except the resource adds `submission_channel` and
the `can_cancel` / `can_resubmit` actions reflect D7.

---

## 11. Feature gate

`change_requests.coordinator_submission_mode` = OFF (default) | PILOT |
GENERAL, env `CHANGE_REQUESTS_COORDINATOR_SUBMISSION_MODE`; PILOT reads
`change_requests.coordinator_pilot_user_ids` (canonical user ids; any
malformed entry → nobody), as `FamilySubmissionPolicy` does. Fail closed.
`REGISTER_FAMILY` is registered in `ChangeRequestTypes::PRODUCTION` only by
an approved release. A read-only `famboook:change-requests-check` extension
reports the mode and allowlist counts.

---

## 12. CO-02.3 implementation plan (engine foundation)

1. **Migration** (§5) with PostgreSQL schema tests: CHECKs, composite FK,
   rollback refusal, defaults for existing rows.
2. **Enums:** `ChangeRequestType::REGISTER_FAMILY`,
   `WorkflowActorSide::COORDINATOR`, `SubmissionChannel`,
   `RegistrationSource::COORDINATOR_REQUEST` (excluded from Staff-selectable
   lists).
3. **Submission context:** a `ChangeRequestSubmissionContext` (channel,
   user, Person, Family or placement) built from the family context or from
   the coordinator context; handlers' `resolveTarget()` take it. Refactor
   the five handlers mechanically; the boundary test stays.
4. **Engine:** `ChangeRequestActors::coordinator()`; COORDINATOR
   transitions; family cancel / resubmit limited to the FAMILY channel;
   `ChangeRequestWorkflow` supports a request without a Family (placement
   locks) and records `created_family_id` at APPLY.
5. **Scope:** `CoordinatorScopes::coversPlacement()` and the
   "request in current scope" query.
6. **Permissions (seeder):** the four `coordinator-request.*` permissions,
   COORDINATOR only; `verify-permissions`, catalogue counts.
7. **Gate:** `CoordinatorSubmissionPolicy` (OFF / PILOT / GENERAL).
8. **Tests:** engine security (actor, channel, ownership, scope), D7
   cancel matrix, scope revocation, inactive Family, idempotency, PostgreSQL
   concurrency. No API or UI in CO-02.3.

Later phases: CO-02.4 PERSON_ADD API; CO-02.5 `RegisterFamilyHandler` and
FAMILY_ADD API; CO-02.6 Coordinator Space UI; CO-02.7 Staff queue channel
filter / badge, family-history display, throttles on the existing
`/coordinator/context` and `/families` routes; CO-02.8 pilot runbook.

---

## 13. Remaining technical risks

1. **Handler refactor** (`resolveTarget` signature) touches all five live
   handlers; mitigated by the boundary test and the unchanged handler tests.
2. **`family_id` nullable** affects every query assuming a Family
   (family history, Staff queue family filter, activity). Each must handle
   NULL; covered by the `chk_change_request_family_subject` CHECK.
3. **Two coordinators proposing the same new head** cannot be detected at
   submit without an existence oracle; resolved by Staff at review.
4. **Unthrottled existing routes** `/coordinator/context`, `/families`,
   `/families/{code}` (pre-existing, CO-02.1 audit) — add throttles in
   CO-02.7.
5. **A coordinator who is also the head of the target Family** submits
   through the coordinator channel; their own-household view shows it with
   the coordinator as submitter (expected); cancel follows the channel.
6. **Registration source** gains a value read by reports and labels;
   reports must label it.

---

## 14. CO-02.3b implementation record (local, not deployed)

Engine integration, verified against the code. Differences from §12 are
deliberate and listed.

- **Handler contract preserved (differs from §12 step 3).** No handler's
  `resolveTarget()` signature changed. Coordinator submission is opt-in
  through `CoordinatorSubmittable::resolveCoordinatorTarget()` (the
  existing `RequiresApprovalAttestation` idiom); only
  `AddFamilyMemberHandler` implements it (PERSON_ADD). The three handlers
  that resolve a household `member_ref` cannot be submitted by a
  Coordinator: there is no family.context for the target Family.
- **Submission context.** `ChangeRequestSubmissionContext` (channel, user,
  requester Person, Family) feeds one shared core in
  `SubmitChangeRequestAction`; `handle()` (family) keeps its exact check
  order; `handleCoordinator(User, Family, submission)` checks actor →
  `CoordinatorSubmissionPolicy` → scope (404) before reading input, and the
  scope again under the Family lock.
- **Transitions.** `ChangeRequestTransitions::coordinator()` is a separate
  table (like `system()`), so `find()` / `from()` for the family and Staff
  are unchanged. `WorkflowEventRecorder` accepts COORDINATOR events only on
  COORDINATOR-channel requests and FAMILY events only on FAMILY-channel
  requests.
- **Actors.** `ChangeRequestActors::coordinator()` (permission +
  re-resolved Coordinator context), `submittedByCoordinator()` (own,
  COORDINATOR channel, current scope; otherwise NOT_FOUND) and
  `familyChannel()` (the household cancels / answers FAMILY-channel
  requests only; otherwise ACTOR_NOT_ALLOWED).
- **Scope.** `CoordinatorScopes::coversPlacement()` and
  `CoordinatorScopes::requests()` (own COORDINATOR-channel requests whose
  Family, created Family or placement is in the current scope).
- **Gate.** `CHANGE_REQUESTS_COORDINATOR_SUBMISSION_MODE` OFF / PILOT /
  GENERAL and `CHANGE_REQUESTS_COORDINATOR_PILOT_USER_IDS`, fail closed,
  independent of the family switch, new submissions only.
- **Proposed Family (no family_id).** `ProposesNewFamily` handler
  interface; approve / APPLY lock request → Clan → Branch (shared),
  re-validate through the handler, and APPLY writes `created_family_id`
  with APPLIED in one row update; activity on the created Family; a
  rejected proposal writes no Family activity. Proven with a test-only
  handler; the real REGISTER_FAMILY handler is CO-02.5.
- **Resources.** Staff queue / detail: `submission_channel`, nullable
  `family`, `placement`, `created_family_code`. Family history / detail:
  `submission_channel`; `available_actions` is empty for a Coordinator's
  request.
- **Permissions.** The four `coordinator-request.*` seeded to COORDINATOR
  only; `famboook:verify-permissions` checks them.

**Open finding.** A COORDINATOR-channel request in
RETURNED_FOR_CLARIFICATION can be moved only by its submitting Coordinator
(the existing rule: Staff have no transition out of that status). If that
Coordinator loses the scope or the role, the request stays returned
(visible to Staff, nothing deleted) with no way to close it. The same holds
today for a FAMILY request whose household loses eligibility. Closing it
needs an approved Staff transition (for example RETURNED →
REJECTED) — a workflow decision, not made here.

---

## 15. CO-02.4 API as built (local, not deployed)

`CoordinatorChangeRequestController`, under `/api/v1/family/coordinator`,
behind `auth:sanctum`, `family.side` and `coordinator.space`. Every
response is JSON with `Cache-Control: no-store, private`. No route-model
binding: a Family is looked up only inside `CoordinatorScopes::families()`,
a request only inside `CoordinatorScopes::requests()`; out of scope,
another Coordinator's request, a family-channel request and a nonexistent
one are the same `404 CHANGE_REQUEST_NOT_FOUND`.

| Method | Path | Middleware (besides the boundary) | Action |
|---|---|---|---|
| GET | `/requests/types` | `can:coordinator-request.view`, `throttle:coordinator-request-read` | — |
| GET | `/requests` | `can:coordinator-request.view`, `throttle:coordinator-request-read` | `CoordinatorScopes::requests()` |
| GET | `/requests/{uuid}` | `can:coordinator-request.view`, `throttle:coordinator-request-read` | `CoordinatorScopes::requests()` |
| POST | `/families/{familyCode}/requests` | `can:coordinator-family.view-summary`, `can:coordinator-request.submit`, `throttle:coordinator-request-submit` | `SubmitChangeRequestAction::handleCoordinator` |
| POST | `/requests/{uuid}/resubmit` | `can:coordinator-request.resubmit`, `throttle:coordinator-request-action` | `ResubmitChangeRequestAction::handleCoordinator` |
| POST | `/requests/{uuid}/cancel` | `can:coordinator-request.cancel`, `throttle:coordinator-request-action` | `CancelChangeRequestAction::handleCoordinator` |

**Requests and responses.**

```text
GET /requests/types
  200 {data: [{type: "ADD_FAMILY_MEMBER"}] | [], meta: {submission_enabled: bool}}
      (empty while the switch is closed for this account or without
       coordinator-request.submit)

GET /requests?status=&type=&per_page=(1..50, default 20)&page=
  200 {data: [Row], links, meta}            newest submission first
  Row = {id (uuid), request_code, type, type_available, status, family_code,
         placement (null for PERSON_ADD), submitted_at, approved_at,
         rejected_at, applied_at, cancelled_at, available_actions}

GET /requests/{uuid}
  200 {data: Row - dates + {submitted_at, reason, presentation {rows:
       [{label, current, proposed}]}, approved_at, applied_at, cancelled_at,
       rejection {reason_code, message, rejected_at} | null,
       timeline [{event_type, from_status, to_status, actor_side,
                  reason_code, public_message, created_at}],
       available_actions: ["resubmit"?, "cancel"?]}}

POST /families/{familyCode}/requests
  body {type: "ADD_FAMILY_MEMBER", client_reference (uuid), reason?,
        data: {full_name, national_id, gender, relationship, birth_date?,
               marital_status?, mobile?}}
  201 {data: {id, request_code, status: "SUBMITTED", replayed: false}}
  200 the same request, replayed: true (same client_reference and material)

POST /requests/{uuid}/resubmit   body {response}   200 {data: Outcome}
POST /requests/{uuid}/cancel                       200 {data: Outcome}
```

**Errors.** 401 unauthenticated; 403 not a family-side account, no
Coordinator Space, or a missing permission; 503
`CHANGE_REQUEST_SUBMISSION_DISABLED` (switch closed for this account —
answered before validation; never for resubmit / cancel); 404
`CHANGE_REQUEST_NOT_FOUND`; 422 validation (only ADD_FAMILY_MEMBER is
accepted; the handler's rules, National ID nine digits, never HEAD); 409
`CHANGE_REQUEST_ALREADY_OPEN`, `_IDEMPOTENCY_CONFLICT`,
`_INVALID_TRANSITION`; 429 `TOO_MANY_REQUESTS`.

**Privacy.** The presentation is the handler's COORDINATOR audience
(`ChangeRequestPresentationContext::coordinator()`): National ID and
mobile masked, never the Staff-only registry match, never another Person's
name. Submission answers identically whether or not the National ID
exists in the registry. The timeline is the family-visible one (no
internal note, actor name or id, metadata, APPLY_FAILED). Never the base
fingerprint, the client reference, apply diagnostics or an internal id.

**Throttles (per user).** `coordinator-request-submit` 5 / minute and 40 /
hour; `coordinator-request-action` 10 / minute; `coordinator-request-read`
60 / minute (`CHANGE_REQUESTS_COORDINATOR_*_LIMIT_*`).

**Differences from §10.** Type discovery needs `coordinator-request.view`
(the list is empty without `.submit`), not `.submit`; the submission route
also needs `coordinator-family.view-summary` (every
`/coordinator/families/...` route does: a Coordinator proposes only for a
Family they may see); reads are throttled too; the closed switch is 503,
not 403; the family resource expresses D7 through `available_actions`, not
`can_cancel` / `can_resubmit`. The Staff `channel` / `submitter` filters
and the FAMILY_ADD route (`POST /requests`) are not built (CO-02.5 /
CO-02.7). The add-member relationship options for the Coordinator form
(the family route `/change-requests/relationship-types` is family-context
only) are not built yet (CO-02.6).

---

## 16. CO-02.5 REGISTER_FAMILY as built (local, not deployed)

No migration (the CO-02.3a schema carries it). `RegisterFamilyHandler` is
registered in `ChangeRequestTypes::PRODUCTION`; it is never
family-submittable (the family route also refuses the code), and a
Coordinator reaches it only through the coordinator switch.

**Domain.**

- `ProposesNewFamily` gains the submission contract: `proposalRules()`,
  `normalizeProposal()`, `proposalOpenConflictKey()`. It is the opt-in for
  the proposal path (V1: only Coordinators propose a Family —
  `chk_change_request_channel_type`); the family-targeted
  `CoordinatorSubmittable` stays PERSON_ADD's, and
  `SubmitChangeRequestAction::handleCoordinator()` refuses a proposal
  handler.
- `SubmitChangeRequestAction::handleCoordinatorProposal(User, Clan, ?Branch,
  submission)`: actor → switch → `coversPlacement()` → validate and
  normalize → one transaction: base (the handler takes the head's National
  ID advisory lock) → replay → placement re-checked with a freshly resolved
  context → open-conflict per submitting user → request (no family_id,
  COORDINATOR channel, proposed Clan / Branch) → COORDINATOR SUBMITTED
  event. No Family activity, no Family, Person or identifier.
- Every unusable placement — unknown code, inactive Clan / Branch / Group,
  Branch of another Clan, outside the scope — is the SAME
  `422 {errors: {placement: [...]}}` (`placementUnavailable()`).
- Proposal: `household_head` {full_name, national_id (required, nine
  digits, never a placeholder, stored canonically), gender,
  marital_status?, birth_date?, mobile? (stored 05XXXXXXXX)} and
  `residence` {original_residence_text (required)}; anything else is
  dropped.
- Base: who holds the ID (Person ids, and whether a soft-deleted Person
  does), read under the National ID lock.
- Approval (Staff): both attestation codes — IDENTITY_VERIFIED (the typed
  National ID must match) and RELATIONSHIP_VERIFIED (here: the reviewer
  verified the head's household and its Clan / Branch placement — meaning
  approved in §21; the recorder keeps requiring the two codes together) —
  and the ID must be
  free, soft-deleted holders included; else PRECONDITION_FAILED.
- APPLY (Staff): request → Clan → Branch (shared) → National ID → base →
  `RegisterFamilyAction` (one optional new argument, the residence source;
  Staff registration still passes none) with registration_source and
  residence source COORDINATOR_REQUEST, registration date = APPLY date,
  the original residence only. The engine then requires the created Family
  in EXACTLY the proposed Clan and Branch, and writes `created_family_id`
  with APPLIED in one update. A duplicate ID, a missing HEAD type or any
  failure rolls everything back and is recorded (APPLY_FAILED).
- The Staff presentation adds, while open, whether the ID is free (person
  code, name with person.view); never to a Coordinator or a family.

**API.** `POST /api/v1/family/coordinator/requests` —
`can:coordinator-request.submit`, `throttle:coordinator-request-submit`,
closed switch → 503 before validation.

```text
body {type: "REGISTER_FAMILY", client_reference (uuid), reason?,
      placement: {clan_code, branch_code?},
      data: {household_head: {full_name, national_id, gender,
                              marital_status?, birth_date?, mobile?},
             residence: {original_residence_text}}}
201 {data: {id, request_code, status: "SUBMITTED", replayed: false}}
200 replayed: true                     same client_reference and material
409 CHANGE_REQUEST_ALREADY_OPEN        same Coordinator, same head ID, open
409 CHANGE_REQUEST_IDEMPOTENCY_CONFLICT
422 placement                          one answer for every unusable placement
422 data.*                             the proposal's own rules
```

`GET /requests/types` now lists REGISTER_FAMILY too. List rows and the
detail add `created_family_code` (after APPLY) and `summary` (the proposed
Person's name, as typed); `placement` is codes and names only.

**Remaining.** A FAMILY_ADD request returned for clarification whose
Coordinator lost the scope still cannot be closed (§14 open finding).
Additional members of the new Family are later PERSON_ADD requests.

---

## 17. CO-02.6a form reference APIs (local, not deployed)

Two read-only routes under `/api/v1/family/coordinator`, behind
`auth:sanctum`, `family.side`, `coordinator.space`,
`can:coordinator-request.submit` (the data exists to submit) and
`throttle:coordinator-request-read`; `Cache-Control: no-store, private`;
no route-model binding, no parameter.

```text
GET /requests/relationship-types
  200 {data: [{code, name}]}
      RelationshipTypes::memberOptions(): the ACTIVE types except HEAD, in
      the registry's order — the same definition the household's add-member
      form now uses (/family/change-requests/relationship-types).

GET /requests/placements
  200 {data: [{clan: {code, name},
               branchless_allowed: bool,
               branches: [{code, name, group: {code, name} | null}]}]}
      CoordinatorScopes::placementOptions(): per Clan of the CURRENT scope,
      only active structure — an inactive Clan, Branch, or a grouped Branch
      whose Group is inactive is never offered; a revoked assignment offers
      nothing; a Clan with nothing to offer is left out.
        CLAN          branchless_allowed true, every active Branch
        BRANCH_GROUP  that Group's active Branches, never branchless
        BRANCH        that Branch, never branchless
      Assignments combine (union per Clan). Built from the same per-
      assignment rule as coversPlacement(), so every offer is exactly a
      placement the FAMILY_ADD submission accepts (tested both ways).
```

Never: an internal id, a uuid, an assignment, a scope level, an active flag,
a sort order, or any Clan / Branch outside the scope.

---

## 18. CO-02.6b Coordinator request workspace UI (local, not deployed)

Inside Coordinator Space (`/family/coordinator`, behind `CoordinatorGate`),
Arabic RTL, the Family Portal's tokens and shadcn/ui components; no
backend change.

| Route | Screen |
|---|---|
| `/family/coordinator/requests` | Dashboard: counts (`meta.total` of one-row pages per status — never derived from a page of rows), status / type filters and page in the URL, table (≥ md) / cards (mobile), «طلب جديد» only when discovery allows |
| `/family/coordinator/requests/new` | Discovery: the types the server lists for which a client form exists |
| `/family/coordinator/requests/new/add-family-member` | PERSON_ADD: the Family chosen only from `GET /coordinator/families` (optional `?family=` pre-selection, answered or refused by the server); relationship options from the API; the household form's schema (nine-digit, non-placeholder National ID) |
| `/family/coordinator/requests/new/register-family` | FAMILY_ADD: Clan / Branch only from `GET /requests/placements`; «دون فرع» only where `branchless_allowed`; the head; the ORIGINAL residence only |
| `/family/coordinator/requests/{uuid}` | Detail: status, subject (Family, or placement and — after APPLY — the created Family's code), the masked presentation, the family-visible timeline with reviewers' messages, the outcome, and the server's `available_actions` |

**Client rules.** Every mutation `retry: false`; one `client_reference`
per proposal (a manual resend replays on the server, a changed proposal
gets a new one); the submit button is disabled while in flight; failures
map to fixed Arabic messages per status (401, 403, 404, 409, 422 with
field errors, 429, 503) — never the server's raw text, never anything
about whether a National ID exists. Data stays in the query cache only.
Status labels, the timeline, the comparison table and the resubmit /
cancel dialogs are the Family Portal's components (the dialogs gained an
optional wording prop); the Family Portal's own behaviour is unchanged.

**Resubmission (gap, not invented).** The API's resubmit takes a text
`response` only and the proposal is immutable after submission (engine
rule AE-5); the UI therefore offers a clarification reply, not an
editable proposal. Correcting a proposal means cancelling it and
submitting a new one.

---

## 19. CO-02.7a Staff workspace compatibility (local, not deployed)

Closes readiness blocker B2: the Staff «طلبات تحديث البيانات» workspace
read `request.family.family_code` unconditionally and would have crashed
the whole queue on one REGISTER_FAMILY row. Frontend only; the Staff API
contract (§15–§16), permissions and workflow are unchanged.

- **Types.** `ChangeRequestSummary` / `ChangeRequestDetail`: `family` is
  `ChangeRequestFamily | null`; new `submission_channel`, `placement`,
  `created_family_code` — TypeScript now refuses any unguarded `family`
  access.
- **Queue.** A proposed Family shows «أسرة مقترحة» and «العائلة · الفرع»
  (or «دون فرع»), then the created Family's code once APPLIED; a
  Coordinator's request carries «عبر منسّق»; household rows are unchanged.
- **Detail.** For a proposed Family: the proposed Clan and Branch, «تُنشأ
  عند تطبيق الطلب» until APPLY, then a link to the created Family; the
  submission channel under the submitter; the proposal titled «الأسرة
  المقترحة».
- **Wording by channel.** Messages, return / reject dialogs, validation
  texts and the timeline address «المنسّق» for a Coordinator's request and
  «الأسرة» for the household's (byte-identical to before); a Coordinator's
  own acts read «قدّم / ردّ / ألغى المنسّق».
- **Approval.** The existing two attestation codes and typed National ID.
  For a proposed Family the checkboxes read: identity — «تحققتُ من هوية رب
  الأسرة المقترح من وثيقة رسمية»; relationship — «تحققتُ من أن الشخص
  المقترح هو رب هذه الأسرة فعلًا، ومن انتماء الأسرة إلى العائلة والفرع
  المقترحين» (§16; for a Family without a Branch and the scope line, §21).
  PERSON_ADD keeps the FP-ADR-076 wording.
- **APPLY.** For a proposed Family the dialog reads «تسجيل الأسرة في
  السجل» and explains the exact placement; after success the Family lists
  are refreshed (its code was unknown before APPLY).

---

## 20. CO-02.7b workflow and security hardening (local, not deployed)

No migration (the existing CHECKs already admit the new transition), no
role or permission.

- **RETURNED_FOR_CLARIFICATION → REJECTED (approved decision; closes the §14
  open finding).** A Staff transition with `change-request.reject` and any
  controlled reason, both channels. `rejection_reason_code` is required
  (request validation, Domain Action signature, `chk_change_request_rejected`);
  the family-visible message is required for OTHER, as for every rejection.
  `RejectChangeRequestAction` is unchanged: it finds the transition under
  the request row lock, so a reply committed meanwhile wins and the
  rejection is refused (INVALID_TRANSITION). Audit: REJECTED event (STAFF,
  actor, reason code, optional internal note), `rejected_by` /
  `rejected_at`, and CHANGE_REQUEST_REJECTED Family activity when a Family
  exists. The household and the Coordinator gain nothing: their tables have
  no rejection. The Staff `available_actions` offers «رفض» on a returned
  request.
- **D8 — the Coordinator in the household history.** The Family API list
  and detail add `coordinator: {name} | null` — the submitting
  Coordinator's Person name on a COORDINATOR-channel request, NULL on the
  household's own. Never a National ID, mobile, email, account or Person id
  or scope. The household still has no action on it.
- **Coordinator Space read throttle.** `throttle:coordinator-read`
  (`COORDINATOR_READ_LIMIT_USER_MINUTE`, 60) on `/coordinator/context`,
  `/families` and `/families/{code}`; the members directory, reveal and
  request routes keep their own limits.
- **Staff queue filters.** `GET /api/v1/change-requests` adds `channel`
  (FAMILY / COORDINATOR) and `submitter` (2–100 characters of the
  submitter's name as the queue shows it; LIKE wildcards are literal); the
  `family` filter also matches the Family an applied REGISTER_FAMILY request
  created. The Staff UI adds a «جهة التقديم» select and a «مقدّم الطلب»
  field (applied on Enter / blur); channel badges since CO-02.7a.

---

## 21. REGISTER_FAMILY approval attestation — approved meaning (CO-02.7d)

Approved decision (2026-10-14). It fixes what a Staff reviewer certifies
when approving a REGISTER_FAMILY (FAMILY_ADD) request with the two existing
codes; no code, schema, permission or API change (the codes and the typed
National ID are unchanged, §16).

- **Identity (`IDENTITY_VERIFIED`).** The reviewer verified the proposed
  household head's identity against an official document, and typed the
  National ID from it (it must match the proposal).
- **Relationship and placement (`RELATIONSHIP_VERIFIED`).** The reviewer
  verified that the proposed person is genuinely the head of this
  household, and that the proposed Family belongs to the selected Clan and
  Branch. For a Family without a Branch (Clan-level placement) the
  reviewer verifies Clan membership.
- **Not certified.** The attestation does not certify any unrelated social,
  health or assistance information.

Staff approval dialog (wording only): identity — «تحققتُ من هوية رب الأسرة
المقترح من وثيقة رسمية (بطاقة الهوية).»; relationship and placement —
«تحققتُ من أن الشخص المقترح هو رب هذه الأسرة فعلًا، ومن انتماء الأسرة إلى
العائلة والفرع المقترحين.», or for a Family without a Branch «… إلى
العائلة المقترحة (دون فرع).»; and the line «لا يشمل هذا الإقرار أي معلومات
اجتماعية أو صحية أو معلومات مساعدات.». The APPLY dialog names the
placement the same way. PERSON_ADD keeps the FP-ADR-076 wording.
