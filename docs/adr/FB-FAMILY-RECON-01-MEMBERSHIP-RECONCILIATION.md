# FB-FAMILY-RECON-01 — Central Membership Reconciliation

**Status:** D-07, D-08, policies P-1 … P-8 and the decisions on R-03, R-06, R-07 and R-08 **APPROVED
by the project owner (2026-11-01)**. **The workflow is NOT implemented and NOT approved for activation.**
Only the WP-03 schema foundation exists locally (§0). Sections marked **Proposal** are designs for
review, and can change.

**Related:** `docs/03` §12–§15, §67; `docs/05` §28, §54; `docs/06` §98;
`docs/adr/CO-02-COORDINATOR-REQUESTS.md`; `docs/11` FP-ADR-076;
`docs/adr/FB-FAMILY-ADD-01-DIRECT-ADMISSION.md`.

---

## 0. Implementation status

**Implemented locally in WP-03 (uncommitted; not deployed):**

- migration `2026_11_01_090000_add_household_reconciliation_foundation`: type
  `HOUSEHOLD_RECONCILIATION`, channel `STAFF`, `submitted_by_person_id` NULL for STAFF only, the CHECKs of
  §10, and the partial unique index `uq_change_request_open_reconciliation_person`;
- enum cases `ChangeRequestType::HOUSEHOLD_RECONCILIATION` and `SubmissionChannel::STAFF`;
- flag `household_reconciliation.mode` (`HOUSEHOLD_RECONCILIATION_MODE`), read through
  `HouseholdReconciliationPolicy`, default and fail-closed value `OFF`. Nothing reads it yet;
- permissions `household-reconciliation.submit`, `.view`, `.approve` and `.apply`, defined and
  assigned to **no role**. Nothing checks them yet.

The source Family is `target_membership_id`'s Family, so no column was added. `family_id` stays NULL,
which keeps the request out of the source household's history (P-7). A CHECK cannot prove that
`person_id` matches the membership; the application must verify it (WP-04).

**Not implemented:** handler, submission, review or apply rules, P-4 and P-8 enforcement, ending a
membership, creating a household, R-03, R-06, R-07 and R-08 behavior, API routes, UI, PILOT rules.

**Not approved for activation:** every mode other than `OFF`.

**Deferred:** DRAFT (P-2; the schema refuses it for this type), external notification (P-7).

## 1. Business scenario

1. A father adds his son to his household, because the son has no active membership.
2. Later, DATA_ENTRY receives a separate form naming that son as the head of another household.
3. DATA_ENTRY enters the National ID. The Person exists, with an active membership in the
   father's household.
4. The system does not duplicate the Person, create a second active membership, or block the
   registration for good. It offers a reconciliation request.
5. The existing membership stays unchanged during review.
6. After authorized review and application, the old membership closes and the Person becomes
   head of a newly created household, with history preserved.

## 2. Approved decisions

**D-07 — Parentage vs household membership.** Civil parent-child relationships are independent
of household membership. Discovered children remain visible even when married or heads of
independent households. A person must not have two active household memberships. Direct child
admission never transfers a membership automatically.

**D-08 — Central reconciliation.** DATA_ENTRY can submit a reconciliation request when
registering an existing person as head of a new household. A central reviewer must review it.
DATA_ENTRY cannot directly transfer membership. The existing membership remains unchanged until
successful application.

| Policy | Approved rule |
|---|---|
| P-1 | APPROVED and APPLIED are separate workflow states |
| P-2 | DRAFT is deferred in V1 |
| P-3 | RETURNED_FOR_CLARIFICATION permits clarification responses only. Material changes need a rejection and a new request |
| P-4 | Approval needs a dedicated reviewer permission. Approval and application stay separate. The broad `family-membership.transfer` is not granted |
| P-5 | An existing household head cannot be reconciled through this V1 workflow |
| P-6 | The destination is a newly created household, never an existing one |
| P-7 | External notification to the original household is deferred pending privacy approval. Internal audit and activity records are kept |
| P-8 | The original submitter cannot approve their own request, even with administrative privileges |

| Decision | Approved rule |
|---|---|
| R-03 | Application is blocked while the Person has an unfinished individual assistance nomination. Reviewers are warned about open individual needs. Assistance and needs records never move |
| R-06 | After APPLIED, household-head access follows the new active membership. Coordinator privileges are suspended pending review |
| R-07 | One authorized reviewer may approve and then apply, as two separate actions. The original submitter can perform neither |
| R-08 | Approval and application are blocked while another open change request concerns the same Person. A request never blocks itself |

## 3. Scope and exclusions

**In scope:** one existing, living, active Person, with exactly one active non-head membership,
becoming head of one new household, through Staff submission and central review.

**Not in scope, and never automatic:**

- moving a spouse, children or other relatives;
- moving assistance, needs, declarations, notifications, cards or assessments;
- changing a National ID or any `identity_ref` data;
- updating the Person's recorded data from the form (the Person is used as recorded);
- household-head succession, joining an existing household, family split or merge;
- Coordinator or family submission, and import handling;
- any change to FB-FAMILY-ADD-01: D-06, gates G-01 … G-07 and `family_registry_admission.mode`
  stay as recorded there.

## 4. State machine

**Approved by P-1 … P-3; transition details are a proposal.**

```text
(new) → SUBMITTED → UNDER_REVIEW → APPROVED → APPLIED
                      │  │              │
                      │  └→ REJECTED    ├→ APPROVED (APPLY_FAILED event, not a transition)
                      ↓                 └→ REJECTED (NO_LONGER_APPLICABLE, after a refused apply)
       RETURNED_FOR_CLARIFICATION → RESUBMITTED → UNDER_REVIEW
                      └→ REJECTED

SUBMITTED / UNDER_REVIEW / RETURNED_FOR_CLARIFICATION / RESUBMITTED → CANCELLED (submitter)
```

These are the existing states and rules (`ChangeRequestTransitions`). Nothing changes in the
registry before APPLIED. `submitted_data` stays immutable, as for every request.

## 5. Authorization model

**P-4, P-8 and R-07 are approved. The permissions are defined but assigned to no role and checked
nowhere; the mapping below is a proposal for later work packages.**

| Action | Requires |
|---|---|
| Submit, respond, cancel | Staff-side account with `household-reconciliation.submit`; own requests only |
| View | `household-reconciliation.view` |
| Start review, return, reject | Existing `change-request.review` / `.return` / `.reject` |
| Approve | `change-request.approve` **and** `household-reconciliation.approve`; actor ≠ submitter |
| Apply | `change-request.apply` **and** `household-reconciliation.apply`; actor ≠ submitter |

`family-membership.transfer` is granted to no new role. Family and Coordinator accounts are
refused. One reviewer may approve and then apply, as two separate actions (R-07).

## 6. Atomic application requirements

**Requirements approved in substance; mechanism proposed.** One PostgreSQL transaction:

1. lock the request; replay if already APPLIED; refuse any state but APPROVED;
2. shared-lock the proposed Clan and Branch;
3. re-validate: payload, placement, permissions, base fingerprint;
4. lock in the order of §7; re-check that the Person is ALIVE, active, not deleted, holds the
   expected National ID, and that the active membership is the stored one, is non-head, and belongs
   to the same ACTIVE Family;
5. end the old membership (row kept; `is_active`, `ended_at`, `end_reason`);
6. create the Family, the HEAD membership and the residence through existing Domain Actions;
7. write APPLIED, `created_family_id`, the workflow event and activity records.

A failure rolls everything back, leaves the request APPROVED, and records APPLY_FAILED
separately. A handler writes through Domain Actions only.

## 7. Concurrency and idempotency

**Proposal.** The request, then placement, then:

```text
source Family FOR KEY SHARE → National ID advisory lock → Person FOR UPDATE → membership FOR UPDATE
```

This follows `CorrectNationalIdAction`. `KEY SHARE` does not conflict with the `KEY SHARE` that
activity inserts take, so it does not form a cycle with Person → Family writers such as
`RecordPersonDeathAction`. **This reading must be proven by a multi-process deadlock test before
it is relied on** (R-02).

- **Final guards:** `uq_person_active_family_membership`, `uq_family_active_household_head`,
  `uq_persons_national_id`.
- **Idempotency:** `(submitted_by, client_reference)` at submission; APPLIED is a replay at
  application.
- **One open request per Person:** proposed partial unique index on `person_id` for this type and
  the open statuses, across submitters.
- **Stale state:** the base fingerprint covers the Person, membership, Family, head flag, life status
  and active flag.

## 8. Audit and privacy

- `workflow_events` (append-only) records each transition, with Staff notes separate from
  family-visible text.
- The Family Activity Log records MEMBERSHIP_ENDED and FAMILY_CREATED on their own Families, without
  free text or identifiers. CHANGE_REQUEST_APPLIED goes to the created Family.
- No full National ID in logs, events, URLs or errors. The reviewer's typed ID is compared and never
  stored.
- **P-7:** no external notification to the original household. No notification is created by this
  workflow.
- A submitter sees only what the National ID check already shows: Person code, name with
  `person.view`, Family code, head flag and relationship. Other household members are not shown.
- **Portal visibility of the move** is unresolved (R-05): the original head stops seeing the Person,
  and the person's history views follow live membership.

## 9. Integration dependencies

Read-only audit at `4d1d177`. **No subsystem moves data.** Records stay on the Family where they
were created.

| Subsystem | References | On closing the old membership | Action needed |
|---|---|---|---|
| Assistance history and deliveries | `family_id`, nullable `person_id`; deliveries hold person ids | History stays with the old Family | None for history |
| Open person nomination | `assistance_beneficiaries.person_id` | `DeliveryVerification` still finds the Person, under the old Family | **R-03 decided:** block apply |
| Family nomination | `family_id` | Old and new Family can both be nominated | **R-04** |
| Targeting | Live active memberships | Old count falls; new Family starts empty of assessments and needs | Tests only |
| Needs | `family_id`, nullable `person_id` | A person-level need stays on the old Family and can still nominate through `NominateFromNeedsAction` | **R-03 decided:** warn the reviewer |
| Declarations | `family_id` | Unchanged; may differ from registered count, which is allowed | None; new Family has none |
| Health | `person_id` | Follows the Person; portal view follows live membership | **R-05** |
| Family card | `family_id`; shows current head | No effect when a non-head leaves; new Family has no card until issued | None |
| Notifications, follow-ups | `family_id`, publication snapshot | History stays with the old Family; the new Family starts empty | None |
| Family access | Live membership, every request | A linked account may become an eligible head at once | **R-06 decided:** allowed |
| Coordinator access | Requires family context | A dormant Coordinator role may become effective | **R-06 decided:** suspend pending review |
| Statistics, reports | Derived live from `is_active` | Current-state counts move on commit; no stored counter | Regression tests |
| Change Requests | `person_id`, `target_membership_id` | Later refused (NOT_APPLICABLE, PRECONDITION_FAILED or BASE_CHANGED); FK keeps the row | **R-08 decided:** block while one is open |

## 10. Proposed design and conflicts

**Proposal, reviewed against the code.** Extend the Change Request engine, with a new type
`HOUSEHOLD_RECONCILIATION`, channel `STAFF`, implementing `ProposesNewFamily`.

| Item | Finding | Result |
|---|---|---|
| STAFF channel | `chk_change_request_channel` and `SubmissionChannel` have FAMILY and COORDINATOR only | Additive widening |
| Staff transitions | `all()` holds FAMILY submit, respond and cancel; a Staff submitter needs its own list, as `system()` and `coordinator()` do | New list; `all()` unchanged |
| Event recorder | Staff events today mean review actions, and `actor_side` STAFF exists | Must also bind Staff submit, respond and cancel to the STAFF channel |
| Nullable `submitted_by_person_id` | NOT NULL today; a Staff user may have no Person | CHECK: NULL only for STAFF |
| Type and subject CHECKs | `chk_change_request_type`, `family_subject`, `placement`, `created_family` and `channel_type` name REGISTER_FAMILY | Replace each with a strict superset |
| Family-less request | `family_id` NULL works, but `person_id` and `target_membership_id` are the source | No target is resolved through `lockCanonical`; handler must lock the source |
| `ProposesNewFamily` | Its text limits open proposals per submitter and forbids identity answers at submission | **Conflict C-1**: this type needs per-Person limits and knows the holder |
| `handleCoordinatorProposal` | Bound to Coordinator scope and person | New `handleStaffProposal`; shared core worth extracting |
| Applying | `applyProposal` returns a Family checked against the placement | Fits. `RegisterFamilyAction` refuses an existing ID, so a new action is needed |
| Ending membership | `EndFamilyMembershipAction` uses HTTP `abort_if` and owns its transaction | Needs a domain-level building block; Staff behavior unchanged |
| Dedicated permissions | `ChangeRequestActors::staff` checks one transition permission; routes use `can:change-request.*` | **Conflict C-2**: a per-type check is new to the engine |
| P-8 | No submitter check exists in `ApproveChangeRequestAction` | New refusal, for this type only |
| Registration source | `RegistrationSource` and `RegisterFamilyRequest` control values | Keep the form's source; new value only if reports need it (R-09) |
| Boundary test | `ChangeRequestHandlerBoundaryTest` forbids registry writes in handlers | Fits through Domain Actions |

## 11. Acceptance criteria

Not met until implemented and tested. **G** = both databases, **PG** = PostgreSQL only.

| # | Criterion | DB |
|---|---|---|
| A-01 | Submission changes no membership, Person or Family | G |
| A-02 | A second open request for the Person is refused, across submitters | PG |
| A-03 | Replay returns the same request; same key with other data is refused | G |
| A-04 | Head, deceased, unknown, inactive, deleted or ambiguous holders are refused | G |
| A-05 | DATA_ENTRY cannot approve, apply or end a membership | G |
| A-06 | The submitter cannot approve, even as SUPER_ADMIN or ADMINISTRATOR | G |
| A-07 | A reviewer without the dedicated permission cannot approve; `family-membership.transfer` assignment is unchanged | G |
| A-08 | Return accepts text only; `submitted_data` stays identical | G |
| A-09 | APPROVED changes nothing; only APPLIED does | G |
| A-10 | Apply: old row kept and ended, one Person, one active membership, new Family, HEAD, residence | G |
| A-11 | Spouse, children and the old head are unchanged | G |
| A-12 | National ID and Person data unchanged; no `identity_ref` write | PG |
| A-13 | Changed membership, life status or Family blocks approval and apply | G |
| A-14 | A failure mid-apply leaves nothing, keeps APPROVED and records APPLY_FAILED; retry works | G |
| A-15 | Concurrent approvals and concurrent applies give one result | PG |
| A-16 | Two families or requests racing for the Person never give two active memberships | PG |
| A-17 | Apply against death record, National ID correction, membership end and ADD_FAMILY_MEMBER: no deadlock and no bad state | PG, multi-process |
| A-18 | Assistance, needs, declarations, cards, notifications and health rows are unchanged by apply | G |
| A-19 | Member counts, reports and dashboards reflect the new state; the old Family's history stays attributed | G |
| A-20 | Open requests on the Person fail safely after apply | G |
| A-21 | No National ID in logs, events or errors; no notification created | G |
| A-22 | Existing Change Request types behave as before, and old CHECKs reject what they rejected | G, PG |

## 12. Open technical risks

| Id | Risk | Severity | Proposed handling | Owner |
|---|---|---|---|---|
| R-01 | Widening CHECKs on a live `change_requests` table | Medium | Short `lock_timeout`, additive, reviewed `down()` | Engineering |
| R-02 | Locking an active Person is new; the `KEY SHARE` reasoning is unproven | **High** | Multi-process deadlock tests before WP-05 is accepted | Engineering |
| R-03 | Open person nomination or need stays on the old Family after the move | **High** | **Decided** (§2); not implemented. Define "unfinished" against `BeneficiaryStatus` in WP-04 | Engineering |
| R-04 | Old and new Family can both receive family-level aid in one program | Medium | Decide whether the existing per-target rule is enough | Owner |
| R-05 | The new head's health and history views follow live membership; the old head loses visibility | Medium | Privacy decision | Owner, privacy |
| R-06 | A linked account or dormant Coordinator role becomes effective at once on APPLIED | **High** | **Decided** (§2); not implemented. No suspension mechanism exists to reuse yet | Engineering |
| R-07 | Approving and applying by one reviewer | Medium | **Decided** (§2); not implemented | Engineering |
| R-08 | An open DEATH_REPORT or other request on the Person during reconciliation | **High** | **Decided** (§2); not implemented. Family-channel requests must be matched by `person_id` | Engineering |
| R-09 | Reports may need to tell reconciled households apart | Low | Check before adding a source value | Owner |
| R-10 | Form data differing from the Person's record | Medium | Show to the reviewer; correct through existing paths | Engineering |
| C-1 | `ProposesNewFamily` contract text conflicts with this type | Medium | Amend text, or a narrower companion interface | Engineering |
| C-2 | Per-type permissions and P-8 are new to the engine | Medium | Optional handler capability, as `RequiresApprovalAttestation` | Engineering |

## 13. Proposed work packages

**Proposal, except WP-03, which is the foundation in §0.**

1. WP-03: engine foundation behind a closed switch (migration, STAFF channel, type, permissions).
2. WP-04: identity and eligibility, and the atomic Domain Action, with deadlock tests.
3. WP-05: handler, submission, review rules (P-4, P-8) and API.
4. WP-06: Staff UI, in the existing registration and review screens.
5. WP-07: integration regression, security review and a separately approved activation plan.
