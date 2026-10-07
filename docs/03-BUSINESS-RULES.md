# Famboook
## Business Rules

**Document:** `03-BUSINESS-RULES.md`  
**Version:** 1.2.10  
**Status:** Approved  
**Last Updated:** 2026-09-25  
**Project:** Famboook — Family Registry & Case Management System

---

# 1. Purpose

This document defines the authoritative business rules governing Famboook.

It describes what the system is allowed to do, what it must prevent, and how important domain operations behave.

This document is independent from user-interface implementation.

The same business rules apply whether an operation originates from:

```text
Next.js Staff Application
Next.js Executive Experience
Next.js Family Portal
Filament System Administration
Import Process
Queue Job
Authorized Internal Process
```

No interface may bypass these rules.

---

# 2. Business Rule Authority

Laravel is the authoritative business-rule layer.

Conceptually:

```text
Next.js
   │
   ▼
Laravel API
   │
   ▼
Authorization
   │
   ▼
Validation
   │
   ▼
Domain Actions
   │
   ▼
Transactions
   │
   ▼
PostgreSQL
```

Frontend behavior is not authoritative business enforcement.

---

# 3. Source of Truth

PostgreSQL contains the canonical persisted state.

However, PostgreSQL alone does not define business behavior.

Business integrity is enforced through:

```text
Laravel Validation
+
Authorization
+
Domain Rules
+
Transactions
+
Database Constraints
```

where appropriate.

---

# 4. Canonical vs Proposed Data

Famboook distinguishes:

```text
Canonical Registry Data
```

from:

```text
Proposed / Submitted Data
```

Canonical data represents accepted registry state.

Proposed data represents information awaiting review, approval, or controlled application.

Family User submissions must not silently overwrite canonical data.

---

# 5. Family Identity

A Family is a persistent entity.

Family identity must not depend solely on:

```text
Current Household Head
Current Residence
Current Members
Paper Form
```

Changing any of these does not create a new Family unless explicitly required by an approved business process.

---

# 6. Family Code

Every Family receives a stable business identifier.

Example:

```text
FAM-000001
```

Once issued, the Family Code must not normally change.

It must not be reused for another Family.

---

# 7. Family Lifecycle

Initial Family lifecycle statuses may include:

```text
ACTIVE
INACTIVE
ARCHIVED
```

Archiving a Family does not delete historical information.

Family lifecycle changes require authorization and audit.

---

# 7a. Clan and Branch (V1)

```text
Clan            (العشيرة / العائلة — e.g. عائلة البريم)
  └─ Branch         (الفرع — named; belongs to the Clan)
  │    ·· optionally classified under a Branch Group
  │       (مجموعة الفروع — organizational; may be unnamed)
  └─ Family         (الأسرة — the existing household entity; optional Branch)
       └─ Person        (via Family Membership)
```

A Clan is not a Family; "Family" remains the household.

**A Branch Group is an optional organizational classification of Branches**
(2026-09-29). Branches may initially be ungrouped ("بدون مجموعة"); an
administrator may later create Branch Groups and assign, move (within the
same Clan) or remove Branches. Family registration and the family import
never depend on Branch Groups.

Rules:

```text
CB-1  Every Family belongs to exactly one Clan (required at registration).
CB-2  A Family's Branch is optional; NULL = unknown / not yet assigned.
      Branches are never inferred (not from names, forms or members).
CB-3  A Family's Branch must belong to the Family's Clan (enforced in the
      Domain Action and by a composite foreign key).
CB-4  Only an active Clan, and an active Branch of an active Clan, can be
      newly selected; a grouped Branch additionally needs an active Group.
      An active ungrouped Branch is selectable.
CB-5  An existing Family keeps and displays a since-deactivated Clan/Branch;
      unrelated edits do not clear it. Once changed away, it cannot be
      re-selected while inactive.
CB-6  Changing the Clan never keeps an incompatible Branch: the request must
      name a Branch of the new Clan or clear it. Choosing a Branch never
      moves the Family to another Clan.
CB-7  A Branch Group may be unnamed. Its Branches keep their own names.
      A Branch needs no Branch Group (branch_group_id NULL = ungrouped).
CB-8  Codes are immutable after creation. Groups do not move between Clans.
      A Branch never changes Clan; it may be assigned to, moved between or
      removed from Groups of its own Clan only (cross-Clan grouping is
      refused by validation and the composite foreign key).
CB-9  Clans, Groups and Branches are deactivated, never hard-deleted; the
      database refuses to delete a referenced structure.
CB-10 A Clan/Branch change is a correction of the Family record and is
      recorded as FAMILY_UPDATED in the Family Activity Log.
```

Migration: every Family existing before V1 (including soft-deleted) was
assigned to `AL_BREEM` with no Branch; no other data changed.

Out of scope for V1: multi-tenancy, genealogy, branch inference, bulk
reassignment, import/export, Clan/Branch dashboards and reports.

---

# 8. Person Identity

A Person is an independent persistent entity.

Person identity must not depend on current Family membership.

A Person retains the same identity when:

```text
Transferring Family
Marrying
Changing Residence
Becoming Household Head
Becoming deceased
```

---

# 9. Person Code

Every Person receives a stable business identifier.

Example:

```text
PER-000001
```

The Person Code must not normally change or be reused.

---

# 10. Person and Family Separation

The canonical Person model must not contain a direct authoritative:

```text
persons.family_id
```

Family association is represented through:

```text
family_memberships
```

---

# 11. Family Membership

A Family Membership represents a Person's relationship with a Family during a period of time.

Membership history must be preserved.

A membership change should normally create/end membership state rather than overwrite historical membership information.

---

# 12. Active Membership

V1 allows at most:

```text
One active primary Family membership per Person
```

A Person may have historical memberships in multiple Families.

Changing Family must be implemented as a controlled transfer operation.

---

# 13. Membership Transfer

A transfer must:

```text
Validate source membership
        ↓
Validate destination Family
        ↓
End current membership
        ↓
Create destination membership
        ↓
Preserve history
        ↓
Audit operation
```

The system must not simply update `family_id`.

---

# 14. Household Head

Household Head is a property/state of Family Membership.

It is not a permanent property of Person.

V1 permits at most:

```text
One active Household Head per active Family
```

---

# 15. Household Head Change

Changing Household Head is a controlled domain operation.

It must:

```text
Validate Family
Validate current head
Validate proposed new head
Validate active membership
Authorize actor
Lock relevant records
End old head status
Assign new head status
Preserve history
Audit change
Trigger access review where applicable
```

It must execute transactionally.

---

# 16. Household Head Death

If the active Household Head becomes officially deceased:

```text
Family requires Household Head review
```

The system must not automatically select a new Household Head unless an explicit future policy allows it.

Family Portal authorization must also be reevaluated.

---

# 17. Person Relationships

Person relationships are distinct from Family Membership.

Examples:

```text
Spouse
Parent
Child
Guardian
```

Relationship data must not be inferred solely from:

```text
Paper row position
Surname
Household membership
```

---

# 18. National ID

National ID is sensitive identity information.

It must:

```text
Be stored as text
Not be used as database primary key
Not be used as an authentication secret
Not be publicly exposed
Be subject to field-level authorization
```

---

# 19. Unknown National ID

If National ID is unknown, the system must not create fake values such as:

```text
000000000
999999999
```

The value should remain NULL or otherwise explicitly unknown according to the approved model.

---

# 20. National ID Normalization

National ID may undergo controlled normalization before comparison.

Normalization must never alter the underlying meaning.

Exact normalization rules remain a pending implementation decision.

---

# 21. National ID Duplicate Handling

An identical or suspicious National ID match must trigger duplicate review according to approved duplicate rules.

The system must not automatically merge Person records.

---

# 22. Duplicate Detection

Potential duplicates may be classified:

```text
EXACT
PROBABLE
POSSIBLE
```

Signals may include:

```text
National ID
Normalized Name
Birth Date
Gender
Mobile
Family Context
```

---

# 23. Duplicate Resolution

Potential duplicates require human review.

Possible outcomes include:

```text
NOT_DUPLICATE
SAME_PERSON
UNRESOLVED
```

No automatic Person merge is permitted.

A future merge process must be separately designed and audited.

---

# 24. Name Handling

Canonical names must preserve their entered Unicode representation.

Search normalization must not destructively rewrite canonical names.

Arabic search normalization may use derived search representations.

---

# 25. Birth Date

Birth Date:

```text
Must not be in the future.
```

Age is calculated from Birth Date.

Age must not normally be stored as canonical persistent data.

---

# 26. Unknown Birth Date

The system must not invent a birth date.

Example prohibited placeholder:

```text
1900-01-01
```

If partial dates are later required, a dedicated policy and model must be approved.

---

# 27. Life Status

Initial life statuses:

```text
ALIVE
DECEASED
UNKNOWN
```

Life status is independent from record operational status.

---

# 28. Death Date

`persons.death_date` is the optional canonical exact date of death.

Rules:

```text
death_date may be NULL.

death_date must not be in the future.

death_date must not precede birth_date.

death_date should normally require life_status = DECEASED.

life_status = ALIVE should normally require death_date = NULL.
```

---

# 29. Unknown Death Date

A Person may be officially recorded as:

```text
life_status = DECEASED
death_date = NULL
```

when death is verified but the exact date is unknown.

The system must not invent an exact death date.

---

# 30. Recording Death

Recording official death is a controlled domain operation.

It must consider:

```text
Current life status
Submitted evidence
Authorization
Verification
Death date validity
Family membership
Household Head status
Family Portal access
Audit
```

## V1 implementation (2026-09-29)

`RecordPersonDeathAction` is the only path that changes an EXISTING Person
to `DECEASED` (permission `person.record-death`, docs/06 §96). Family
registration and member creation always create ALIVE Persons; import
creation may create a Person UNKNOWN or DECEASED (`CreatePersonAction`,
§96b).

```text
life_status   → DECEASED
death_date    → the given date, or NULL when the exact date is unknown (§29)
```

- A supplied date must be a valid calendar date, not in the future and not
  before `birth_date` (§28; also a PostgreSQL CHECK). With an unknown
  birth date only the "not in the future" rule applies.
- From `ALIVE` or `UNKNOWN` only. A Person already `DECEASED` is refused
  (`PERSON_ALREADY_DECEASED`, 409). The Person row is locked and re-read;
  it serializes with `ConfirmPersonAliveAction` on the same row. A
  soft-deleted Person is not found.
- **A recorded death is irreversible in V1** (2026-10-05, docs/11
  FP-ADR-061): no path leads from `DECEASED` back to `ALIVE`. Correcting an
  erroneous death needs a future, dedicated Domain Operation — never
  `UpdatePersonAction`, which never writes life status or death date.
- Nothing else changes: memberships, `is_household_head`, other Persons
  (no spouse is made WIDOWED or household head automatically) and
  `is_active` (§27: life status is independent of record status). A
  deceased active head is surfaced by the Data Quality check
  `HOUSEHOLD_HEAD_DECEASED` for Household Head review (§16).
- **The current household head's death may be recorded** (FP-ADR-061). The
  deceased stays the head — no successor is selected (§16; Head
  Succession, docs/11 FU-01) — and the family has no eligible Family
  Portal user until a new head is assigned (§89b). Head review and access
  re-evaluation are passive today: the Data Quality check above and the
  access resolver on every request; no workflow is triggered.
- The current User-Person Link of the deceased is ended in the same
  transaction (`PERSON_DECEASED`): its Family Auth identity is superseded
  and its sessions are revoked (§89b).
- Transactional; records `PERSON_DEATH_RECORDED` (§97a) on the Person's
  current Family, subject = the Person, with the verification method as
  its only metadata (a code; never the date or free text). A Person
  without a current Family records no activity.
- Callers: the Staff operation and, later, the DEATH_REPORT Change Request
  application (§31). The import never calls it: a Person known to be
  deceased when first imported is **created** `DECEASED` by
  `CreatePersonAction` (§96b).
- Staff: `POST /api/v1/people/{person}/record-death` (`person.record-death`,
  docs/11 FU-10). The request sends `death_date` explicitly — a date, or
  `null` when the exact date is unknown; an omitted field is refused, never
  read as "unknown" — and a required verification method: `IN_PERSON`,
  `STAFF_CALLBACK` or `AUTHORIZED_RECORD_REVIEW` (never `SELF_OTP`). A life
  status, family id or person id in the body is refused.

## Confirming life status: UNKNOWN → ALIVE (2026-10-04)

`ConfirmPersonAliveAction` is the only path from `UNKNOWN` to `ALIVE`
(docs/11 FP-ADR-059, FP-ADR-060; permission `person.record-death`).

- Only from `UNKNOWN`: `ALIVE` is refused (`PERSON_ALREADY_ALIVE`),
  `DECEASED` is refused (`PERSON_DECEASED`) — no path brings a recorded
  death back — and an `UNKNOWN` Person with a `death_date` is refused
  (`INCONSISTENT_LIFE_RECORD`). All answer 409.
- The Person row is locked and re-read; it serializes with
  `RecordPersonDeathAction` on the same row. A soft-deleted Person is not
  found. `is_active` and an active membership are not required (§27).
- Nothing else changes: memberships, `is_household_head`, marital status,
  mobile and mobile trust, the User-Person Link, the Family Auth identity
  and sessions.
- Records `PERSON_ALIVE_CONFIRMED` (§97a) on the current Family with the
  verification method as its only metadata: `IN_PERSON`,
  `STAFF_CALLBACK` or `AUTHORIZED_RECORD_REVIEW`.
- Staff: `POST /api/v1/people/{person}/confirm-alive` (verification method
  required). An UNKNOWN household head is confirmed only this way; it
  creates no account, Link, identity, OTP or session. Later, a family's
  PERSON_CORRECTION / CONFIRM_ALIVE request (non-head member, statement +
  Staff review, no mandatory document) calls the same action on APPLY.
- `UpdatePersonAction` writes only its own field allow-list and never
  life status or death date.

---

# 31. Family User Death Report

A Family User may report a death where permitted.

A report does not directly change:

```text
life_status
death_date
```

Instead:

```text
Family User
    ↓
DEATH_REPORT Change Request
    ↓
Staff Review
    ↓
Approval
    ↓
RecordPersonDeathAction
    ↓
Canonical Registry
```

Direction recorded with FU-10 (2026-10-05); DEATH_REPORT itself is not
implemented yet (PWA-6, docs/11 FP-ADR-059):

- V1 targets an active member who is **not** the household head; head
  death and succession stay with FU-01.
- APPROVED is not APPLIED: approval changes nothing. APPLY calls
  `RecordPersonDeathAction` server-side — never the Staff HTTP route —
  with a Staff verification method and its own controlled provenance next
  to it.
- A Person already `DECEASED` at APPLY time is refused
  (`PERSON_ALREADY_DECEASED`); the request is not applied twice.

---

# 32. Residence History

Family residence is historical.

Changing residence must normally:

```text
End previous current residence
        ↓
Create new current residence
```

rather than overwrite the old record.

---

# 33. Current Residence

V1 permits at most:

```text
One current residence per Family
```

at a given time.

---

# 34. Residence Change

Residence changes require:

```text
Authorization
Validation
History preservation
Audit
```

Family User residence submissions use Change Requests.

---

# 35. Health Information

Health information is restricted sensitive data.

A Person may have multiple health conditions.

The system must not assume:

```text
One Person = One Condition
```

---

# 36. Disability Information

Disability information is restricted sensitive data.

A Person may have multiple disability records.

Access requires explicit authorization.

## Health & Special-Needs Records (V1)

Approved 2026-09-24. See docs/02 §22 for the model.

- Health records belong to **Persons**. Four V1 types: DISABILITY,
  CHRONIC_DISEASE, PREGNANCY, BREASTFEEDING.
- PREGNANCY and BREASTFEEDING are recorded for FEMALE persons only. V1 has
  **no minimum-age rule** (the source paper form defines none).
- **Gender integrity:** a Person with an **active** PREGNANCY or
  BREASTFEEDING record cannot have their gender corrected away from FEMALE
  (HTTP 422). The record is never closed or changed automatically. Close
  it first, then the correction can proceed.
- A record can only be added for an **active** member of the Family it is
  added through.
- There are no duplicate **active** records:
  - one per disability type per Person;
  - one per chronic disease name per Person, compared after trimming,
    case folding and simple Arabic normalization (أ/إ/آ→ا, ى→ي, ة→ه,
    diacritics removed; not medical matching);
  - at most one active PREGNANCY and one active BREASTFEEDING per Person.
- **Correction:** the type-specific field, details and start date can be
  corrected. The Person and the type cannot change.
- **Close, not delete:** records of **all four types** can be closed by
  setting `ended_at` (today by default; never in the future or before
  `started_at`). Closing ends pregnancy/breastfeeding, and marks a
  disability or chronic disease record as no longer current. Closed records
  keep the history. V1 has no hard delete.
- **Deceased persons:** their health records stay visible to authorized
  viewers as history and are never closed or deleted automatically. They
  are excluded from the current family health indicators.
- Family health indicators are derived, never stored. Children under 2 and
  births in the last 12 months come from `birth_date`. The Staff App uses
  today as the reference date; a future historical form evaluation may use
  the collection date.

---

# 37. Family Portal Sensitive Information

Family membership alone does not automatically authorize access to all health, disability, identity, document, or confidential information of other members.

Sensitive Family Portal visibility must follow explicit policy.

Where policy is unresolved:

```text
DENY / HIDE
```

is the default.

---

# 38. Education

Education information belongs to Person.

It may support historical or multiple records where required.

---

# 39. Employment

Employment information belongs to Person.

Historical employment may be preserved.

Current employment must not require deleting previous employment history.

---

# 40. Assessment

An Assessment represents a point-in-time evaluation.

Assessment results must not silently overwrite permanent registry information.

A Family may have multiple Assessments over time.

---

# 41. Assessment Date

Assessment date represents the relevant assessment event date.

It must not be inferred from `updated_at`.

---

# 40a. Quick Multi-Domain Family Assessment (V1)

Approved 2026-09-24.

A V1 assessment is a **dated snapshot of a Family's situation** across
selected assessment domains (docs/02 §26-27b):

```text
Family → Assessment → Assessment Results (one per assessed domain)
```

It is not a questionnaire, not a Need and not Assistance.

Rules:

- Assessments are **family-level** in V1. Person-level assessments are
  out of scope.
- Assessments are **repeatable historical snapshots**. A Family may have
  many assessments over time and **more than one on the same date**.
- `assessment_date` is the business date ("when was the family
  assessed?"), may not be in the future, and is **not** `created_at`
  ("when was it entered into Famboook?").
- Lifecycle: `DRAFT → COMPLETED` only. No reopening, review, approval,
  rejection or cancellation in V1.
- A **DRAFT** may change its date and general notes and add, change or
  remove domain results. Saving the draft with a result set replaces it:
  an omitted domain becomes "not assessed".
- A domain is either rated (`NONE`, `LOW`, `MEDIUM`, `HIGH`, `CRITICAL`)
  or has **no result**. Absence of a result means "not assessed"; there
  is no `NOT_ASSESSED` value.
- **Completion** requires at least one assessed domain. Not all domains
  are mandatory. Every stored result must reference an active domain and
  hold a valid rating. Completion records `completed_at` and
  `completed_by`.
- A **COMPLETED** assessment is immutable: its date, notes and results
  can no longer change, and it cannot return to DRAFT (API: 409). A change
  in family circumstances results in a **new** assessment. There is no
  correction/revision workflow in V1.
- Assessments are never deleted in V1 (no delete endpoint).
- **Deactivated domains:** not selectable and cannot be newly added to a
  draft. Existing results using them stay readable, and completed
  assessments are unchanged. A DRAFT that already holds a result for a
  domain deactivated later keeps it (never silently deleted), but cannot
  be completed until that result is removed or the domain is reactivated.
- No family score, weighting or vulnerability index is computed or
  stored. **Needs are not created automatically** from assessment
  results.
- Assessment results never overwrite canonical Family/Person data (§40).
- **Privacy:** general and domain notes may contain sensitive family,
  health or protection information. They are exposed only through the
  assessment endpoints (`assessment.view`), never in generic Family or
  Person responses, never in the Activity Log, and never to
  REPORTS_VIEWER or FAMILY_USER in V1. Health-domain notes are not a
  replacement for Person Health Records (§36).
- Every write is transactional and records its Activity Log event in the
  same transaction (§97a).

Out of scope for V1: questionnaires/templates, scoring formulas,
weighted scores, approval/rejection, reopening/revisions, person-level
assessments, attachments, signatures, researcher/collection metadata,
charts, reports, export, Family Portal access, Filament management and
domain administration UI.

---

# 42. Form Submission

A Form Submission belongs to an approved data-collection or assessment process.

Submission state must follow controlled workflow.

Editing rules depend on workflow state and permission.

---

# 43. Verification

Verification means an authorized user has checked information according to the applicable process.

Verification must record:

```text
Verifier
Date/time
Relevant object
Workflow transition
```

where required.

---

# 44. Approval

Approval is distinct from verification.

Where maker-checker separation applies, the same actor should not perform incompatible stages unless explicitly permitted by policy.

---

# 45. Maker-Checker Principle

High-impact changes should support separation between:

```text
Creator / Submitter
```

and:

```text
Reviewer / Approver
```

The exact required separation may depend on operation risk.

---

# 46. Need

A Need represents an identified requirement.

A Need has its own lifecycle.

Need creation does not guarantee assistance.

---

# 46a. Needs Management (V1)

Approved 2026-09-24.

A Need is a **concrete** need identified for a whole Family or for one
of its members (docs/02 §34).

- A Need **always belongs to a Family**, which remains its primary
  container.
- **Target:** no person = family-level need; a person = person-specific
  need. A newly chosen person must currently be an **active member of the
  same family**. An open Need keeps its existing target even if that
  member later leaves the family.
- **Optional Assessment source:** a Need can be created directly, or cite
  a **COMPLETED** Assessment of the **same family** as its source.
  Assessment and Need are different concepts: one domain rating may lead
  to several Needs, or none. **Needs are never generated automatically
  from Assessment results**, and categories are never inferred from
  assessment domains.
- **Category:** from `need_categories` (docs/02 §34a). A deactivated
  category cannot be selected for a new Need or newly assigned; existing
  Needs keep it.
- **Priority:** `LOW`, `MEDIUM` (default), `HIGH`, `URGENT` — operational
  prioritization, not assessment severity.
- **Quantity/unit:** optional requested quantity (> 0, up to 2 decimals)
  and free-text unit; a unit requires a quantity. Quantities are not used
  to calculate fulfilment.
- **Lifecycle:** created `OPEN` only (clients cannot create or set a
  resolved state). Then `OPEN → FULFILLED` or `OPEN → CLOSED`, each through
  its own explicit operation. No reopening and no FULFILLED ↔ CLOSED in V1;
  if the same need arises again, a **new** Need is created.
- **Resolution fields:** OPEN has no `resolved_at`, `resolved_by` or
  `closure_reason`. FULFILLED records `resolved_at` and `resolved_by`, with
  no closure reason. CLOSED records `resolved_at`, `resolved_by` **and a
  required `closure_reason`** (free text; no reason categories in V1).
  `resolved_by` is always the authenticated user, never client input.
- **Immutability:** a FULFILLED or CLOSED Need is historical and
  read-only (API: 409). Repeated resolution fails safely without new
  activity.
- **No deletion** of Needs in V1.
- **Fulfilment does not create Assistance.** Assistance is deferred; when
  implemented, the Need–Assistance relationship will be added explicitly
  (§47).
- **Privacy:** descriptions and closure reasons may be sensitive. Need
  responses include only safe identity (family code and household-head
  name, the target's code and name, the source assessment's id/date/
  status) — never National IDs, phone numbers, health-record details or
  assessment notes/results. Need data is not included in generic Family,
  Person or Assessment responses and is not available to REPORTS_VIEWER or
  FAMILY_USER in V1.
- Every write is transactional and records its Activity Log event in the
  same transaction (§97a).
- **Global work queue:** the Staff App `/needs` page lists Needs across
  families (OPEN by default; filters: status, priority, category, target).

Out of scope for V1: Assistance, distributions, aid packages, delivered
quantities, partial fulfilment, automatic Assessment-to-Need generation,
scoring, approval, reopening, deletion, attachments, referrals, service
providers, beneficiaries outside the family, reports/charts, exports,
Family Portal access, Filament Needs management, category administration
and units reference data.

---

# 47. Assistance

Assistance represents support actually recorded/provided.

Assistance does not automatically close a Need.

Need closure requires explicit workflow/business logic.

---

# 47a. Assistance V1-A — Program Definition

Approved 2026-09-24.

```text
NEED         what a family/person needs                       (family_needs)
ASSISTANCE   a defined program/campaign that can provide support (assistances)
NOMINATION   a family/person selected as a POTENTIAL beneficiary (assistance_beneficiaries)
DELIVERY     what was actually received                        (assistance_records — V1-B)
```

V1-A implements ASSISTANCE, TARGETING and NOMINATION. Approval,
rejection, delivery and distribution execution are **V1-B**. A
nomination is never proof that assistance was received.

- An Assistance has a title, an **Assistance Category** (what it is for),
  an **Assistance Type** (`IN_KIND`, `CASH`, `SERVICE` — how it is
  provided; independent of the category), a free-text **provider name**,
  an optional planned **target count** and **planned period**
  (`end_date >= start_date`; not delivery dates) and a description.
- It has one or more planned **items** per beneficiary (quantity, unit,
  optional value + currency ILS/USD/JOD/EUR; currency required iff a value
  is given). Items are never stock or delivered amounts.
- **Lifecycle V1-A:** created as `DRAFT`; `DRAFT → OPEN` requires at least
  one item. DRAFT: everything editable (metadata, items, targeting
  criteria). OPEN: nominees can be managed; only description, target
  count and dates stay editable (409 otherwise). COMPLETED/CANCELLED are
  reserved for V1-B. No deletion.
- Nominee/approved/delivered counts are derived, never stored.

---

# 47b. Assistance Targeting (V1-A)

- Targeting is **family-oriented** and uses only the approved criteria
  (docs/02 §36d). There is no rules engine and no nested AND/OR groups.
- **All supplied criteria combine with AND.** Within a multi-value
  criterion (need priorities, assessment ratings) any value matches (OR).
  Empty criteria = no filter; the Staff App still only previews on an
  explicit request.
- **Population:** ACTIVE, non-deleted families. "Members" are persons
  with an ACTIVE membership who are not DECEASED (same population as the
  health indicators, §36).
- **Family size:** count of those members (`min`/`max`, `max >= min`).
- **Displacement:** the current residence's `displacement_status`;
  location = case-insensitive "contains" match on
  `displacement_location_text` (no GIS).
- **Children under two:** members whose birth date is ≤ today and after
  today − 2 years (the Health indicator rule); `min_children_under_two`
  requires at least N. `false` = no such child.
- **Pregnancy / breastfeeding / disability / chronic disease:** an ACTIVE
  health record of that type on a member. `true` = at least one; `false`
  = none.
- **Open Need:** at least one OPEN Need of the category (if given) with
  one of the priorities (if given). Resolved Needs never qualify.
- **Assessment:** for the given domain, the family's **most recent
  COMPLETED assessment that rated that domain** (latest `assessment_date`,
  then latest `completed_at`, then highest id); its rating must be one of
  the given ratings (any rating if none given). DRAFT assessments are
  never used. A later assessment that did not rate the domain does not
  hide an earlier result for it.
- **Preview** is derived on every request and **never stored** (no match
  flags, no results). It writes no activity. It returns only safe summary
  fields and minimal indicators for the criteria used (e.g. "يوجد حامل",
  children-under-two count, matching open-need category/priority, matching
  assessment domain/rating/date) — never disease names, disability
  details, pregnancy notes, health details, National IDs, phone numbers,
  assessment notes or need descriptions.
- **Snapshot:** the validated criteria used for a targeting nomination are
  stored on the Assistance (`targeting_criteria`) and on each TARGETING
  nominee. While DRAFT the criteria can also be saved directly.
- **Human selection is required.** Families are never nominated
  automatically; there is no "nominate all matches".

---

# 47c. Assistance Nomination (V1-A)

- A nominee is a family (family-level) or one person of that family
  (person-level). Nominations require an OPEN Assistance.
- **Sources** (stored, never inferred): `TARGETING` (selected rows of the
  preview; family-level; each selected family is re-checked against the
  submitted criteria server-side, and a non-matching selection rejects the
  whole request), `MANUAL` (family or an ACTIVE member found by family
  code, person code or name — National IDs are not searchable in V1-A),
  `NEED` (from OPEN Needs: family-level or person-level following the
  Need; `source_need_id` stored; the Need is **not** changed, fulfilled or
  closed and no delivery is created).
- **Duplicates:** within one Assistance a family-level nominee and a given
  person can each be nominated at most once (partial unique indexes on
  current rows). A family-level nomination and a person-level nomination
  of a member of that family may coexist. The same family/person may be
  nominated in different Assistances. Bulk nominations (targeting, needs)
  skip existing nominees and report `created` / `skipped_duplicates`;
  manual duplicates are rejected.
- Nominations **persist** when family data later changes; they are never
  removed automatically because a family no longer matches.
- **Removal (V1-A):** while the Assistance is OPEN, a `NOMINATED` row can
  be withdrawn. It is history-preserving: status `REMOVED` with
  `removed_at`/`removed_by`; the row stays readable and the target may be
  nominated again as a new row. Only NOMINATED rows can be removed, so
  V1-B approval/delivery states will be protected.
- **Activity:** `ASSISTANCE_NOMINEE_ADDED` / `ASSISTANCE_NOMINEE_REMOVED`
  on the nominated family's timeline (§97a). Program-level definition and
  opening are not family events (tracked by `created_by`, `updated_by`,
  `opened_at`, `opened_by`).
- **Privacy:** nominee responses show family code, household-head name,
  person code/name and, for NEED nominations, the need's title, category,
  priority and status — never its description, and never National IDs,
  phone numbers or health/assessment content. Assistance data is not
  included in Family, Person or Need responses and is not available to
  REPORTS_VIEWER or FAMILY_USER in V1-A.

V1-B (below) adds approval and execution.

Out of scope for V1-A: approval, rejection, delivery, distribution,
delivered quantities/value/dates, inventory, partial fulfilment,
automatic Need closure, automatic nomination, scoring/ranking/eligibility
scores, rules engine, provider or currency management, reports, exports,
signatures, attachments, Family Portal and Filament management.

---

# 47d. Assistance V1-B — Execution Mode and Approval

Approved 2026-09-24.

```text
NEED → ASSISTANCE → TARGETING → NOMINATION → APPROVAL → EXECUTION
                                                  ├─ INTERNAL: DELIVERY (verified receipt in Famboook)
                                                  └─ EXTERNAL: ISSUED BENEFICIARY LIST (to another organization)
```

- **Execution mode** (`INTERNAL` / `EXTERNAL`) is chosen while DRAFT and
  locked once OPEN. Existing V1-A Assistances became INTERNAL (they
  assumed execution inside Famboook).
- **Targeting criteria decide WHO is considered; requested export fields
  decide WHAT data is sent about approved beneficiaries.** They are
  separate configurations and never influence each other.
- **Approval:** NOMINATED → APPROVED (individual or bulk; bulk is
  all-or-nothing and only touches NOMINATED rows) with `approved_at/by`.
  **Rejection:** NOMINATED → REJECTED with a mandatory free-text reason
  (`rejected_at/by`); terminal in V1-B. REMOVED rows can never be approved,
  rejected, delivered or listed. Approval/rejection require an OPEN
  Assistance.
- All counts are derived (§47i); none are stored.

# 47e. Assistance V1-B — INTERNAL Delivery

- Applies only to INTERNAL Assistances; EXTERNAL never exposes delivery.
- **Original beneficiary:** the nominated Person (person-level) or the
  CURRENT household head (family-level; may be female). No current head →
  delivery is blocked.
- **Receipt modes:** exactly `PERSONAL` and `DELEGATE`. No anonymous or
  free-text recipient, no automatic Person creation. Every delivery
  requires National ID verification.
- **PERSONAL:** the typed National ID must belong to the exact original
  beneficiary.
- **DELEGATE:** both IDs in the same attempt — the original beneficiary's
  and the recipient's. The recipient must be an ACTIVE member of the same
  family whose relationship is `SON` or `DAUGHTER`, whose marital status is
  `SINGLE`, who is not deceased and not the original beneficiary. SPOUSE,
  FATHER, MOTHER, OTHER, and MARRIED / DIVORCED / WIDOWED / UNKNOWN children
  are refused.
- **Relationship limitation:** SON/DAUGHTER are recorded relative to the
  current household head (docs/02 §15). Parenthood is therefore provable
  only when the original beneficiary IS the current household head. For a
  person-level beneficiary who is not the head (e.g. a spouse), delegation
  is refused and PERSONAL remains available. No genealogy is inferred.
- **National ID handling:** typed IDs are normalized (Arabic-Indic/Persian
  digits → ASCII, spaces/dashes/dots removed, upper-cased) and compared in
  constant time only with the persons this beneficiary context allows —
  there is no global National ID search. IDs are never stored, returned,
  put in URLs, flashed or logged. Delivery permission does not grant
  National ID viewing.
- **Flow:** verify (no write) → minimal summary (names/codes,
  relationship, marital status, package) → explicit confirmation → the API
  re-verifies inside the transaction and records the delivery.
- **Full package only:** one delivery = every item in its planned quantity.
  No delivery items, partial/split delivery, installments or stock.
- **One active delivery** per beneficiary. The beneficiary stays APPROVED;
  "delivered" is the active delivery record.
- **NOT_DELIVERED:** an explicit, final decision on an APPROVED beneficiary
  without an active delivery, with a mandatory reason. Never automatic.
- **Reverse Delivery** (`assistance.reverse`, admins): a delivery is never
  deleted; reversal adds `reversed_at/by/reason`. The beneficiary is
  awaiting delivery again and a fresh, freshly verified delivery may follow
  while OPEN. Reversal remains possible after completion for correction and
  does not reopen the Assistance.

# 47f. Assistance V1-B — Family History

The Family Profile "المساعدات" tab lists the family's nominations with the
Assistance, category, provider, execution mode, target (family/person) and
state. INTERNAL rows show awaiting/delivered (date, receipt mode, recipient
name). EXTERNAL rows show only issued list numbers/dates — never
"delivered". No National ID, list values or reasons appear.

# 47g. Assistance V1-B — EXTERNAL Requested Fields

- Each EXTERNAL Assistance may configure the columns the requesting
  organization needs: an ordered list of fields from a **controlled
  catalog** (docs/02 §36f) with custom Arabic column labels. No arbitrary
  columns, SQL or formulas; each key at most once. Labels are presentation
  only.
- Fields are classified **STANDARD**, **CONTACT** (mobiles) or
  **SENSITIVE** (National ID, health indicators). Configuring, previewing,
  issuing, viewing or downloading a list containing SENSITIVE fields
  requires `assistance.export-sensitive` in addition to
  `assistance.export`. This never grants generic `person.national-id.view`.
- Health indicators are yes/no only — never condition names or details.
  Need descriptions and assessment notes are not in the catalog.

# 47h. Assistance V1-B — Preview and Issued Lists

- **Preview** ("معاينة الكشف") shows the current APPROVED beneficiaries with
  the configured columns, labels and order and the row count; it writes
  nothing (no list, no snapshot, no activity) and warns "يحتوي الكشف على
  بيانات شخصية حساسة." when applicable.
- **Issuance** requires an OPEN EXTERNAL Assistance, a saved configuration
  and at least one explicitly selected APPROVED beneficiary; it is
  confirmed explicitly and runs in one transaction.
- The issued list stores the configuration snapshot and, per row, the
  **exact values sent** (`snapshot_data`). This is a deliberate historical
  snapshot: it is one of the rare places sensitive values are preserved,
  because Famboook must know exactly what was transmitted. It is encrypted
  at rest and only readable through the authorized external-list
  endpoints; never in Assistance, Family, Person or Activity responses.
- Lists are **immutable**: never regenerated from live data, edited or
  deleted. A corrected list is a **new** list; a beneficiary may
  intentionally appear in several lists, and previous list membership is
  shown.
- **XLSX** ("تنزيل XLSX") is generated on request from the snapshot:
  exactly the snapshot's columns, labels and order, right-to-left, with no
  ids, UUIDs, audit fields or unrequested columns. It is served only
  through the authenticated API (no public URL, `Cache-Control:
  no-store`); the filename is `<list_number>-<issue date>.xlsx` and holds
  no personal data.
- **Issuing a list is not delivery.** It means only "تم إصدار الكشف
  للجهة". No AssistanceDelivery is created and nobody is marked delivered;
  the external execution result remains UNKNOWN in V1-B.
- **Future (not implemented):** external execution results
  (DELIVERED_EXTERNALLY / NOT_DELIVERED_EXTERNALLY / UNKNOWN) entered
  manually or imported from XLSX.

# 47i. Assistance V1-B — Statistics and Completion

Statistics are derived on read and differ by mode:

- Common: target, total nominees (non-removed), pending approval,
  approved, rejected, removed.
- INTERNAL: awaiting delivery, delivered (active deliveries), not
  delivered, reversed deliveries, execution % = delivered / target × 100
  (only when target > 0), package totals = quantity per beneficiary ×
  active deliveries, monetary totals = unit value × quantity × active
  deliveries, grouped by currency (never combined).
- EXTERNAL: approved not yet in any list, unique beneficiaries included in
  lists ("تم إصدارهم في كشوف"; a corrected list does not inflate it),
  number of issued lists. **No delivered figure.**

Completion (OPEN → COMPLETED, `completed_at/by`, `assistance.complete`):

- INTERNAL: no NOMINATED and no APPROVED without an active delivery.
- EXTERNAL: no NOMINATED and every APPROVED included in at least one issued
  list. Completion does not imply physical delivery.
- REJECTED, REMOVED and NOT_DELIVERED never block. CANCELLED is not
  implemented.

**Need independence:** neither an internal delivery nor an external list
fulfils, closes or otherwise changes a Need.

---

# 48. Assistance Eligibility

Famboook must not automatically infer aid eligibility solely from stored data unless a separately approved eligibility engine is introduced.

Humanitarian or social indicators may support decision-making but do not automatically constitute entitlement.

---

# 49. Documents

Sensitive documents must use private storage.

Document metadata and document binary content are separate concepts.

---

# 50. Document Upload

Successful upload means:

```text
File received
```

not:

```text
Document verified
```

---

# 51. Document Verification

Document verification requires an authorized operation.

Verification should record:

```text
verified_by
verified_at
```

where applicable.

---

# 52. Document Access

Document access requires server-side authorization.

Knowing a file path or document identifier must not be sufficient to retrieve a restricted document.

---

# 53. Notes

Notes may have different visibility levels.

Examples:

```text
Operational
Internal
Confidential
Family-visible where explicitly supported
```

Confidential Staff notes must not be exposed to Family Users.

---

# 54. Paper Form Traceability

Paper forms remain data sources.

Where available, Famboook should preserve:

```text
Paper Form Number
Paper Sequence
Source
Entry Actor
Entry Date
```

Paper form layout does not determine the canonical digital model.

## V1 Decision: Review / Signature Section

Approved 2026-09-24 (Family Activity Log V1).

The paper form's review/signature section (reviewer confirmation,
signatures, fingerprints) is **not implemented literally** in V1. The
digital system relies instead on authenticated entry and activity
information:

- "Entry Actor" is the authenticated application user who performed the
  operation, recorded by the Family Activity Log (§97a);
- "Entry Date" is the system timestamp of that operation.

The authenticated entry user is **not** automatically the field researcher
or data collector. Researcher/collection metadata remains deferred
(docs/02 PDD-025).

A future explicit review/approval workflow may be added if needed. The
original paper-form field documentation is retained as source
documentation.

---

# 55. Derived Statistics

Statistics such as:

```text
Family Size
Child Count
Adult Count
Male Count
Female Count
```

should normally be derived from canonical data.

Manual duplicated counters should be avoided.

This rule governs **Registered Members** (`registered_member_count`, the
ACTIVE memberships whatever the Person's life status), **Living Members**
(not DECEASED, not soft-deleted — targeting and health summaries) and every
other count of registered Persons: they are always derived. **Declared Household
Statistics** (§55c) are source declarations, not duplicated counters, and
never replace these derived figures.

---

# 55a. Operational Dashboard (V1)

The Staff Operational Dashboard shows aggregates derived on each request
from canonical data. No counter, snapshot, cache, materialized view or
statistics table exists.

## Organizational scope

```text
Clan (required)             all ACTIVE families of the Clan, including
                            families without a Branch
Clan + Branch Group         families whose Branch belongs to the group
Clan + Branch               families assigned to that Branch
```

Families with no Branch count only at Clan scope. A Branch Group or Branch
of another Clan, or a Branch outside the selected group, is rejected.
Organizational scope is unrelated to residence or displacement.

## Current population

One population foundation serves every figure:

```text
Families   status ACTIVE, not soft-deleted, in scope
People     not soft-deleted, not DECEASED, with an ACTIVE membership in
           one of those families (same population as targeting and the
           family health indicators)
```

## Figures

```text
Active Families      scoped families
Declared Household   sum of the scoped families' CURRENT declared household
Population           sizes (docs/02 §20a). A declaration, never derived from
                     memberships or declared sons/daughters; families without
                     a current declaration and NULL sizes contribute nothing.
                     Shown beside Current People ("أفراد الأسر (المعلن)" /
                     "المسجلون تفصيليًا"), never summed with or reconciled
                     against it. Requires family.view
Current People       scoped current people
Displaced Families   scoped families whose current residence is DISPLACED
Open Needs           OPEN Needs of scoped families
Demographics         gender and the approved age bands (docs/02 §75),
                     age from date of birth relative to today
Displacement         DISPLACED / NOT_DISPLACED / unknown (no current
                     residence or not recorded); top locations by exact
                     stored text (no normalization)
Health               distinct current people with an active DISABILITY,
                     CHRONIC_DISEASE, PREGNANCY or BREASTFEEDING record;
                     disability by reference type. No condition names or
                     details
Needs                OPEN only, by priority and top categories
Assessments          per scoped family and domain: the latest COMPLETED
                     assessment that rated the domain (assessment date,
                     then completion time, then id — the targeting rule).
                     Drafts ignored; a domain never rated is "not
                     assessed", never NONE
Assistance           OPEN programs with a current beneficiary in scope;
                     a beneficiary is scoped by its target Family
  INTERNAL           nominated; approved (APPROVED + NOT_DELIVERED);
                     awaiting delivery (APPROVED, no active delivery);
                     delivered (active, non-reversed delivery);
                     not delivered
  EXTERNAL           nominated; approved; approved but not in any issued
                     list; unique beneficiaries in at least one issued
                     list ("تم إصدارهم في كشوف") — never called delivery
Recent Activity      latest 10 Family Activity Log entries of scoped
                     families, with the Family timeline's event-level
                     visibility
```

Aggregates only: no National IDs, phone numbers, health details, Need
descriptions, rejection reasons, delivery identity data or issued-list
snapshot values.

---

# 55b. Reports (V1)

Staff Reports V1 are six fixed operational reports, derived on each request
from canonical data exactly like the Operational Dashboard (§55a). No report
counter, snapshot, cache, materialized view, stored file or background job
exists. There is no report builder, arbitrary column, formula, trend or
time series in V1.

```text
Population & Families   السكان والأسر
Health                  الصحة
Needs                   الاحتياجات
Assessments             التقييمات
Assistance              المساعدات
Data Quality            جودة البيانات
```

## Scope and population

Every report uses the §55a organizational scope and current population
unchanged — the same code serves both, so Reports and the Dashboard cannot
count different populations. Invalid Clan / Branch Group / Branch
combinations are rejected. Age uses the approved bands (docs/02 §75).

## Population & Families

Summary: active families, current people, male / female / unknown gender,
displaced / not displaced / unknown displacement; the six age bands; the
top displacement locations by exact stored text (no normalization).

Organizational breakdown (families and current people):

```text
Clan scope           every Branch Group with its Branches, plus a
                     separate "غير محدد" row for families without a Branch
                     (never attributed to a group)
Branch Group scope   the group's Branches
Branch scope         none
```

## Health

Aggregate only: distinct current people with an active (not ended)
DISABILITY, CHRONIC_DISEASE, PREGNANCY or BREASTFEEDING record, and
disability by reference Disability Type (distinct people per type). No
person-level drill-down, no condition names, details or notes. The XLSX
contains the same aggregate tables only.

## Needs

Needs of scoped families across the whole lifecycle — OPEN, FULFILLED and
CLOSED, each counted separately (CLOSED is never fulfilled). Optional
filters: status, priority, category, target (Family / Person); every
figure and row honours them. Breakdowns by status, priority, category and
target. Paginated detail rows: title, category, priority, status, target
code and name, Family code, created date, resolved date. Never the
description or closure reason.

## Assessments

Analytical table: per scoped family and domain, the latest COMPLETED
assessment that rated the domain (§55a / targeting rule), as NONE … CRITICAL
plus assessed / not assessed families. Drafts are ignored; a domain never
rated is NOT ASSESSED, never NONE. A domain/rating cell drills down to the
families currently in that bucket (Family code, household head, Branch,
assessment date, rating). A separate lifecycle count shows assessment
records (DRAFT / COMPLETED) — records, not families. Never notes.

## Assistance

Programs in the selected status (default OPEN + COMPLETED) with at least one
non-removed beneficiary whose target Family is in scope. Every figure counts
only those scoped beneficiaries, never the program's global counters; the
program's target is shown as defined.

```text
All        nominated, approved, rejected
INTERNAL   awaiting delivery, delivered (active delivery), not delivered,
           reversed deliveries, delivered ÷ approved (%)
EXTERNAL   approved but not in any issued list; unique beneficiaries in at
           least one issued list ("تم إصدارهم في كشوف"); issued lists
           containing a scoped beneficiary
```

A beneficiary appearing in a corrected (re-issued) list is counted once.
EXTERNAL listing is never called or counted as delivery. No National IDs,
verification values or issued-list snapshot data.

## Data Quality

Actionable checks derived only from the canonical schema. Each check is an
exact condition over the scoped families or current people, with a count
and a paginated drill-down of the affected records.

```text
Completeness
  FAMILY_WITHOUT_BRANCH              families.branch_id is null
  FAMILY_WITHOUT_CURRENT_RESIDENCE   no current residence
  DISPLACED_WITHOUT_LOCATION         current residence DISPLACED without a
                                     displacement location
  PERSON_MISSING_NATIONAL_ID         national_id empty
  PERSON_MISSING_BIRTH_DATE          birth_date null
  PERSON_MISSING_MOBILE              primary mobile empty
  PERSON_UNKNOWN_GENDER              gender null (the schema's unknown state)
  PERSON_MARITAL_STATUS_UNKNOWN      marital_status UNKNOWN
Consistency
  ACTIVE_FAMILY_WITHOUT_HEAD         no active household-head membership
  HOUSEHOLD_HEAD_DECEASED            the active head is DECEASED (§16:
                                     requires Household Head review)
```

Family Branch / Clan mismatch is not a check: the composite foreign key
makes it impossible, and the report states it is protected by integrity.
Heuristics (marital status from age, likely duplicates, likely wrong
National IDs) and duplicate detection are out of scope.

The drill-down identifies the record to correct — Family: code, household
head, Clan, Branch; Person: code, name, Family code, Branch — and never the
missing or sensitive value itself. Corrections are made in the existing
Family / Person screens; Reports never edit data.

## XLSX export

Each report has one fixed workbook, generated on request and never stored:
an information sheet (report, exact scope, filters, time) followed by the
report's tables with Arabic headers on right-to-left sheets. Needs exports
the filtered detail rows; Assessments may add the selected domain/rating
families; Data Quality may add the selected issue's affected records;
Health, Population and Assistance export aggregate / program-level rows.
The download is authenticated, `no-store, private`, has no public URL and a
value-free filename (`{report}-report-{date}.xlsx`), and contains no
internal ids, National IDs, phone numbers, health details, notes,
descriptions, reasons, verification values or snapshot data.

---

# 55c. Declared Household Statistics

Approved 2026-09-29 (Initial Family Import foundation, Phase 1). Fields in
docs/02 §20a.

```text
Registered Members          derived from ACTIVE memberships, whatever the
                            Person's life status; never stored (§55)
Living Members              ACTIVE memberships of Persons not DECEASED and
                            not soft-deleted; targeting / health only
Declared Household Size     declared by a source at a point in time; stored
Declared Living Sons        declared by a source; stored
Declared Living Daughters   declared by a source; stored
```

Rules:

- A declaration is a dated historical record. Recording a new one makes it
  the Family's current declaration and keeps the previous one unchanged as
  history (`is_current = false`). At most one current declaration per
  Family (database-enforced). Declarations are never edited in place or
  deleted.
- Declared values are stored as declared: counts are integers `>= 0`,
  `NULL` means "not declared" and at least one value is present. They are
  never invented and never back-filled from registered records.
- A declaration **never creates Persons**. No placeholder or fake children
  are created to make the registered count match a declared size.
- Declared values never replace registered SON/DAUGHTER membership counts,
  never silently override calculated figures in reports, the dashboard,
  targeting or exports, and are never treated as verified individual
  records. Registered ≠ Declared is expected and is not an error.
- Consistency between declared and registered data (e.g. a declared size
  of 0, a size below 1 + registered spouses, sons + daughters above the
  declared size) is a **review finding**, not a refusal.
- Written only through `RecordHouseholdDeclarationAction`, which records
  `HOUSEHOLD_DECLARATION_RECORDED` (§97a) with no metadata — never the
  declared counts.
- **Stale-write protection** (2026-10-05, docs/11 FU-10): every caller
  states which declaration it expects to be current — its id, or none.
  Under the Family row lock the current declaration is re-read; a
  different one is refused (`HOUSEHOLD_DECLARATION_CHANGED`, 409) and
  nothing is written. The partial unique index stays the final guard. The
  import passes "none": it records the declaration of a Family it has just
  created.
- **Staff** (FU-10, docs/11 FP-ADR-061): `POST
  /api/v1/families/{family}/household-declarations`, permission
  `family.update`. Counts each optional (at least one), whole numbers from
  0; `declared_at` optional (NULL = unknown, never in the future);
  `source` required and one of `PAPER_FORM`, `MANUAL_ENTRY`,
  `VERIFIED_SOURCE` — `IMPORT` belongs to the import;
  `expected_current_declaration_id` always sent (null = none). Lifecycle
  and provenance fields and notes are refused. The Staff Family profile
  returns `declared_at`, `declaration_source` and `current_declaration_id`
  (a Staff-only stale-write reference, never sent to the Family Portal).
- A family's request to change its declaration
  (`HOUSEHOLD_DECLARATION_UPDATE`) stays a proposed type (docs/11 PFP-008);
  once approved, its APPLY calls the same action server-side. No source
  value for a family statement exists yet; adding one is part of that
  decision.
- Using declared figures in targeting, nominations, eligibility or reports
  is a later, explicit decision; any such use must label them "Declared".
  Current V1 figures (targeting family size, `member_count`,
  `family_members_count`) remain registered figures.
- **Display (Family list and profile).** The Family API returns the current
  declaration beside the registered figure, never instead of it:
  `declared_household_size` in the list, plus `declared_living_sons` and
  `declared_living_daughters` in the profile; all NULL when nothing was
  declared. `member_count` keeps its meaning (ACTIVE memberships). The UI
  labels the declared figure "المعلن" and the registered one "المسجلون
  تفصيليًا"; no figure is derived from, summed with or reconciled against
  another.

---

# 56. Corrections vs Real-World Changes

Famboook distinguishes:

```text
DATA CORRECTION
```

from:

```text
REAL-WORLD CHANGE
```

Example:

```text
Wrong birth date entered
→ Correction

Family moved
→ Real-world Change
```

Corrections may change erroneous canonical information.

Real-world changes should preserve prior historical state where appropriate.

## Registration Date Independence

Approved 2026-09-24.

`families.registration_date`, `family_memberships.started_at` and
`family_residences.started_at` record separate business facts. Registration
may copy the registration date into the other two as initial values.

After that, correcting `families.registration_date` must **not** change:

```text
family_memberships.started_at
family_residences.started_at
```

There is no cascade in either direction.

---

# 57. User Identity

`users` represent application authentication identities.

Users are not registry Persons.

The system must not infer:

```text
User = Person
```

without an explicit User-Person Link.

---

# 58. User-Person Link

Family Portal identity requires an explicit:

```text
User
 ↓
User-Person Link
 ↓
Person
```

Link creation/verification is a controlled operation.

---

# 59. User-Person Link Verification

A User must not verify their own identity link.

Verification requires an authorized Staff/System process.

**Amended 2026-10-02 (PWA-0, docs/11 §31 A-01).** Self-verification stays
prohibited. The approved **system** verification process is account
activation: eligibility is decided by the registry, the OTP destination is
a mobile trusted through an authorized process, and the proof is the OTP
(§89a). The person activating chooses none of these.

---

# 60. Family User Role

The external authorization role is:

```text
FAMILY_USER
```

This role does not itself grant access to a Family.

Family scope must be dynamically resolved.

---

# 61. Family User V1 Eligibility

The recommended V1 policy is:

```text
Verified current Household Head
```

unless an approved exception model is introduced.

This is a policy decision, not a permanent database coupling.

---

# 62. Family User Access Resolution

Family access is determined conceptually through:

```text
Authenticated User
        ↓
Active User-Person Link
        ↓
Active Person
        ↓
Active Family Membership
        ↓
Family Access Policy
        ↓
Authorized Family
```

Role alone is insufficient.

---

# 63. No Canonical users.family_id

The system must not rely on:

```text
users.family_id
```

as the authoritative Family Portal access mechanism.

Family access derives from verified registry relationships.

---

# 64. Family User Access Re-evaluation

Access must be reevaluated when relevant events occur, including:

```text
Household Head Change
Membership Transfer
Person Death
Family Archive
User Suspension
User-Person Link Suspension
User-Person Link End
```

---

# 65. Public Registration

Anonymous public Family registration is out of V1 scope.

Family Portal account activation is controlled.

Knowing Family information does not authorize account creation.

---

# 66. Family User Canonical Editing

Family Users do not receive unrestricted CRUD access to canonical registry data.

Substantive changes use controlled Change Requests.

---

# 67. Change Request Principle

A Change Request represents:

```text
Proposed Change
```

not:

```text
Canonical Change
```

Submission does not modify official registry data.

---

# 68. Change Request Types

Initial types:

```text
CONTACT_UPDATE
RESIDENCE_UPDATE
PERSON_CORRECTION
ADD_FAMILY_MEMBER
MEMBERSHIP_CHANGE
HOUSEHOLD_HEAD_CHANGE
BIRTH_REPORT
DEATH_REPORT
MARRIAGE_UPDATE
DOCUMENT_UPDATE
OTHER
```

Each type must define its own validation and application rules.

---

# 69. Change Request Payload

`submitted_data` contains proposed values.

The payload must be validated according to:

```text
Request Type
Actor
Target Family
Target Person where applicable
Current Registry State
```

Arbitrary JSON must not be interpreted as arbitrary database updates.

---

# 70. Change Request Draft

A Family User may create/edit their own DRAFT request where permitted.

A DRAFT does not enter Staff review.

---

# 71. Change Request Submission

Submitting a request must:

```text
Authenticate actor
Authorize Family scope
Validate request type
Validate proposed payload
Validate required documents
Record submitted_by
Record submitted_at
Transition state
Create workflow event
```

---

# 72. Submitted Request Mutation

After submission, proposed data must not be silently edited.

Changes after clarification should be traceable through the controlled resubmission process.

---

# 73. Change Request Review

Only authorized Staff may review Family User Change Requests.

The requester cannot review their own request.

---

# 74. Clarification

A reviewer may return a request for clarification.

Family-visible clarification messages must be separated from internal Staff notes.

---

# 75. Resubmission

A requester may respond to clarification and resubmit where permitted.

Resubmission must be traceable.

---

# 76. Change Request Rejection

Rejection must record:

```text
Authorized actor
Timestamp
Reason
Workflow transition
```

The Family User may receive an appropriate Family-visible reason.

Internal notes remain separate.

---

# 77. Change Request Approval

Approval authorizes the proposed operation.

Approval alone does not mean canonical data has changed.

Therefore:

```text
APPROVED ≠ APPLIED
```

---

# 78. Change Request Application

Applying an approved Change Request must:

```text
Authorize actor/process
Lock request where required
Confirm status = APPROVED
Revalidate current registry state
Validate request payload again where required
Invoke appropriate Domain Action
Execute transaction
Write audit information
Write workflow event
Mark APPLIED only after success
```

---

# 79. Application Transaction

Canonical mutation and APPLIED transition must be transactionally coordinated.

If canonical mutation fails:

```text
Rollback
```

and the request must not falsely become APPLIED.

---

# 80. Application Failure

If application fails after approval:

```text
Request remains APPROVED
```

unless a dedicated failure status is later approved.

Failure should be logged/audited appropriately.

An authorized retry may occur.

---

# 81. Application Idempotency

An APPLIED request must not be applied again.

Application operations must protect against:

```text
Double Click
Retry
Concurrent Request
Queue Retry
Duplicate HTTP Request
```

---

# 82. Add Family Member Request

Before creating a new Person from an approved Add Family Member request:

```text
Run duplicate checks
```

If an existing Person is identified, the system should reuse the existing Person where appropriate rather than create a duplicate.

---

# 83. Birth Report

A Birth Report is not automatically a new Person.

Before application:

```text
Validate
Review
Duplicate Check
Approve
```

Then an authorized Domain Action may create the Person and membership.

---

# 84. Death Report

A Death Report is not an official death record until reviewed and applied.

It must not directly alter `life_status` or `death_date`.

---

# 85. Marriage Update

Marriage-related requests may affect:

```text
Marital Status
Person Relationships
Family Membership
```

The application process must explicitly determine which domain changes are required.

It must not assume marriage always implies automatic Family transfer.

---

# 86. Membership Change Request

Membership changes are high-impact operations.

They must preserve history and use controlled membership Domain Actions.

---

# 87. Household Head Change Request

Household Head changes are high-impact.

They require:

```text
Membership validation
Current head validation
Proposed head validation
Authorization
Approval
Transactional application
Family Portal access reevaluation
```

---

# 88. Contact Update

Contact changes may be lower-risk than identity changes.

V1 still routes substantive Family User contact updates through controlled review unless a later policy permits direct low-risk updates.

---

# 89. Risk Levels

Change Requests may be classified:

```text
LOW
MEDIUM
HIGH
```

Risk may determine:

```text
Reviewer
Approver
Evidence
Re-authentication
Application permission
```

---

# 89a. Family Portal Program Rules (PWA-0)

Approved 2026-10-02. Specification: `11-FAMILY-PORTAL.md`. Documentation
only; nothing here is implemented.

## Access

- Family access is resolved on every request: authenticated User →
  verified active User-Person Link → Person → active household-head
  membership → Family (§62, unchanged).
- Family endpoints never accept a Family identifier from the client to
  decide the current Family. The server resolves it.
- Family API authorization defaults to deny.
- Access is re-evaluated on the events of §64, including the death of the
  household head and user deactivation.

## Authentication

```text
Activation   National ID → eligibility → trusted mobile → OTP → password
Login        National ID + password
Reset        National ID → OTP to the trusted mobile → new password
```

- No generated password is ever sent by SMS.
- Responses are generic wherever a specific answer would reveal that a
  National ID exists, is eligible or has an account.
- No name and no Family information is shown before authentication
  succeeds.
- Staff authentication (§116) is unchanged.
- A typed National ID must be exactly nine ASCII digits after the strict
  Family Portal normalization and is matched through a dedicated
  authentication identity (§89b). `persons.national_id` is not made
  unique.

## Mobile trust

- An imported mobile is not trusted merely because it exists. For first
  self-activation an OTP may be sent to the Person's current registered
  mobile; only its successful verification makes it trusted (SELF_OTP).
- During activation a person cannot replace the mobile with a new number
  and receive an OTP on it.
- A change of the authentication mobile is separately authorized and
  verified; a Family User's request to change it is never self-approved.
- A mobile number is never an identity; shared numbers are expected.

## Multi-role

- One person holding FAMILY_USER and COORDINATOR uses one account.
- An action is authorized by the context it is performed in.
- COORDINATOR is permission + organizational scope, never role alone, and
  is not a REVIEWER (docs/06 §22a).
- In V1 a coordinator signs in through the Family Portal identity flow,
  not the Staff Login, and works in a visibly distinct Coordinator Space.

## Family Profile Review and Staff Family Verification

Amended 2026-10-04 (BD-051, BD-052; docs/11 §9–§13).

- **Account Verification** (access as the eligible head), **Family Profile
  Review** (the head reviews and confirms family data) and **Staff Family
  Verification** (a formal Famboook decision) are separate concepts.
- Profile Review completeness and section states are derived from rules,
  never a count of filled columns, never a percentage (docs/11 §9). A head's
  confirmation is an assertion: it changes no canonical data and can never
  grant VERIFIED. Canonical data change only through approved Domain
  Actions after the Change Request workflow.
- Declared household size and registered members are separate facts.
  Missing people are never inferred from their difference, and equality is
  never a condition of completeness, confirmation or verification (§55).
- Staff Family Verification is granted only by an authorized Famboook user
  or process; a Family User cannot self-verify and a coordinator cannot
  grant it by role. It is auditable (who, when, status/version, history).
  Later changes affect it by risk class (docs/11 §12, pending approval).

## No automatic eligibility

```text
VERIFIED ≠ ELIGIBLE      CONFIRMED ≠ ELIGIBLE      INCOMPLETE ≠ INELIGIBLE
```

Neither Account Verification, Family Profile Review nor Staff Family
Verification makes a family eligible or ineligible for any assistance,
nomination or service. Complete and current information helps the
accuracy of assessments and nominations for the programmes that depend on
those data. Each programme keeps its own required data, targeting criteria
and eligibility rules; Staff targeting (§47b) stays separate. Assistance
still requires service criteria, nomination, review and approval (§47).

## Submissions

- V1 registry requests: CONTACT_UPDATE, RESIDENCE_UPDATE,
  PERSON_CORRECTION, ADD_FAMILY_MEMBER, BIRTH_REPORT, DEATH_REPORT (§68).
- Family-data, household-declaration, health and need submissions need
  request types that are **PROPOSED and not approved** (docs/11 §14,
  PFP-008).
- A family health submission never creates an official health record, and
  a family need submission never creates an official Need, without review.
  On approval the existing health and Needs Domain Actions apply them.
- Need ≠ health condition. Family Users never create Assistance records.
- Marriage, divorce, widowhood, member transfer and household-head change
  stay out of V1 until their Domain Actions and request types exist.

## Card and QR

- The Digital Household Head Card carries minimal data and an opaque
  public holder ID that never reuses a Family code, Person code, database
  id or National ID.
- The QR holds only an opaque credential and resolves to live status.
- A QR proves access to the QR, not the identity of the presenter.
- A downloaded PDF is outside system control; only its QR can be revoked.

**Amended 2026-10-07 (docs/11 FP-ADR-070, §31 A-19).** The card is the **Digital
Family Card**: its subject is the Family, not the household head.
- The card's subject is the **Family**: a household-head change never
  revokes, rotates, reissues or renumbers it; the current head is shown live.
- Three distinct things: `family_code` (registry identifier, not a secret),
  the card number `FC-XXXX-XXXX-XX` (random, immutable for the row) and the
  opaque QR token (256-bit, never derived, stored only as a SHA-256 lookup
  hash plus a Crypt-sealed copy decrypted only for the owner's QR).
- Lifecycle ACTIVE → REVOKED only; reissue = revoke (REISSUED) + a NEW row
  with a new number and token; no token-only rotation; rows never deleted.
- Verification needs an ACTIVE credential of an ACTIVE, not deleted Family,
  read live: an INACTIVE / ARCHIVED / deleted Family fails without the
  credential changing, and verifies again once ACTIVE unless revoked.
- A digital verification credential inside Famboook — never an official
  identity document; a QR proves the card, not the presenter.

## Notifications and announcements

- In-app records are the source of truth; Web Push and SMS are channels.
- §90 is extended: system notifications still originate from backend
  events; **manual announcements** are a distinct, audited kind sent by
  Famboook Administration or by a coordinator within scope.
- Audiences are resolved on the server; a client-supplied audience is
  never trusted beyond the sender's scope.
- A coordinator's message never appears as an administrative decision.

## Audit

Activation, OTP security events, password reset, Link verification and
revocation, verification decisions, request transitions and apply, card
issue / revoke / reissue, coordinator scope changes and announcement sends
are auditable. OTP plaintext, passwords and secrets are never logged.

---

# 89b. Family Portal Identity and Access Rules (PWA-1B)

Approved 2026-10-02. Architecture: `11-FAMILY-PORTAL.md` §30a. PWA-1C
implemented the foundation only (schema, the strict normalizers, the keyed
fingerprint service, the COORDINATOR role and the PWA-1 permission names).
PWA-1D implemented the eligibility resolver, the User-Person Link
lifecycle, the National ID correction and death effects below, and the
account-side rules (including the Staff API boundary). PWA-1E implemented
the mobile trust lifecycle with its Staff API, the OTP challenge service,
the SMS abstraction, the OTP throttle and the OTP cleanup. PWA-1F
implemented activation (below) and the first Family Portal screens. PWA-1G
implemented Family login and password reset (below). PWA-1H implemented
coordinator scope and Coordinator Space (below). TweetsMS SMS delivery
is integrated (below); it sends only once configured on the server. Still
not built: coordinator-assisted mobile verification (a future dedicated
workflow).

## Hardening as implemented (PWA-1I)

- **A decoy behaves like a real challenge also under parallel requests.**
  Parallel wrong codes lock it at exactly the attempt a real challenge locks
  at; of parallel resends exactly one is sent, the others see the cooldown
  or the send limit; a decoy replaced by a new start can never come back.
- **At the ceilings too.** When the IP or the global OTP SMS ceiling is
  reached, a decoy resend is refused as a real one is. The ceilings per
  destination cannot apply to a decoy (it has no phone number); this
  residual limit is accepted and documented.
- **One response time for every public OTP step.** Start, code
  verification, resend and completion all wait out the same minimum
  response time, whatever the outcome.
- **Attempts are counted before the work they limit.** A login attempt is
  counted before the password is checked, and refused unchecked beyond a
  ceiling; a successful login clears the count. The same holds for the
  starts per identifier. The approved limits are unchanged.
- **Pasted identifiers.** Invisible formatting characters carried by
  copy-paste (zero-width characters, directional embeddings and isolates,
  byte-order mark) are removed before a National ID is checked; it must
  still be exactly nine digits, and nothing else is removed.
- **Readiness.** An operator command reports, without revealing any value,
  whether Family authentication is configured consistently — in particular
  whether the fingerprint key still matches the existing accounts and
  trusted mobiles.

## SMS delivery as implemented (TweetsMS)

- **Provider.** TweetsMS is the Production SMS provider, selected by
  `FAMILY_SMS_DRIVER=tweetsms`. Its API key and sender exist only in the
  server environment; without them nothing is sent.
- **Only the code and its validity.** An SMS carries one destination — the
  Person's current trusted mobile in the registry's own `05XXXXXXXX`
  format, sent as stored and never converted to an international form —
  and the text. Never a name, National ID, family code or family data.
- **The OTP text** is fixed and fits one SMS part:
  "رمز التحقق في Famboook: {code}" and, on a second line, "صالح {n}
  دقائق. لا تشاركه مع أحد." — `{n}` follows the configured validity
  (5 minutes).
- **What counts as sent.** Only TweetsMS result code 999. Any other answer
  — another code, an HTTP error, an unreadable response, a timeout — is a
  failed delivery, classified as a temporary, permanent, provider
  configuration or unknown failure. Code 999 means TweetsMS accepted the
  SMS, not that the handset received it.
- **After the response.** On activation and password reset the SMS is
  handed to TweetsMS after the answer was sent to the user, by the same
  server process, without a queue. The challenge, its send count, the
  throttles and the cooldown are settled before the answer; only the
  provider call comes later. The answer is the same whether TweetsMS
  accepts, refuses, is slow or is unreachable.
- **No automatic retry.** A failed or uncertain send is never repeated by
  the system; the user may ask for a resend within the usual limits.
- **Failures are recorded, never shown.** A failure is recorded on the
  OTP security event and logged with safe codes and the last two digits of
  the number only. A provider code or reason is never shown to a Family
  Portal user.

## Coordinator scope as implemented (PWA-1H)

- **A Coordinator is family-side, never Staff.** COORDINATOR lives on a
  family-side account beside FAMILY_USER; it never combines with a Staff
  role, and it never opens a Staff route. COORDINATOR ≠ REVIEWER.
- **Coordinator Space needs ALL of:** the account's own Family context
  (eligible household head, ACTIVE Family — V1), the COORDINATOR role,
  `coordinator-space.access`, and at least one effective scope assignment.
  Role without assignment, assignment without role, a lost eligibility, an
  inactive own Family: no Coordinator Space, on the next request. There is
  no Coordinator-only fallback and no Coordinator activation bypass.
- **Scope is a union** of the effective assignments: a CLAN covers the
  Clan's families; a BRANCH_GROUP the families whose Branch is in it; a
  BRANCH that Branch's families. A family without a Branch is reached
  through its Clan only.
- **Current hierarchy.** Authorization follows the family's CURRENT Branch
  and the Branch's CURRENT Group on every request; there is no historical
  scope.
- **Inactive structure fails closed.** An inactive Clan, Group or Branch
  authorizes nothing, and a family whose own Branch — or that Branch's
  Group — is inactive is outside every scope. Only ACTIVE, not deleted
  families are in scope.
- **Scope answers WHICH families; permissions answer WHAT.** In PWA-1H a
  Coordinator may read family SUMMARIES only (code, hierarchy names, head
  name, active member count) — never a National ID, a mobile, residence,
  health, disability, needs, assistance, notes, documents or account
  status. A Coordinator modifies nothing, reviews nothing, exports nothing
  and assigns nobody.
- **The own household is unchanged.** The Family context remains the
  account's own household only; Coordinator Space is a separate mode and
  its families never become part of the household.
- **Administration** (role and scopes) is a Staff act of SUPER_ADMIN and
  ADMINISTRATOR (`coordinator-scope.manage`). Granting the role requires an
  eligible family-side head; an assignment requires the role and an active
  target; removing the role revokes every active assignment in the same
  transaction. An assignment never grants the role.
- `person-mobile-trust.assist` stays withheld from COORDINATOR until a
  dedicated assisted-mobile-trust workflow exists.

## Login and password reset as implemented (PWA-1G)

- **Lookup.** The typed National ID is normalized, fingerprinted and
  matched against an ACTIVE authentication identity. `persons.national_id`
  is never the login or reset lookup.
- **A correct password is not enough.** Login requires the account's full
  Family context at that moment: active family-side User, ACTIVE link,
  Person not deleted, active and ALIVE, identity consistent with the
  Person's current National ID, active household-head membership, Family
  ACTIVE and not deleted. Without it no session is created.
- **One answer for every login failure**: unknown identifier, wrong
  password, inactive, Staff or mixed account, suspended or ended link,
  death, moved headship, inactive Family. The reason is recorded as a
  security event only. A password is verified even for an unknown
  identifier, so the two cases cost the same.
- **Login lockout**, over 15 minutes, counting FAILED attempts only: 5 for
  one identifier from one address, and 20 for one identifier from any
  address; plus 20 attempts of any kind per address. A successful login
  clears the identifier counters. All apply to unknown identifiers alike.
- **Password reset** requires the same Family context as login and a
  TRUSTED current mobile. An account that could not enter the portal gets
  no SMS. Every well-formed identifier gets the same answer; a denied
  request gets a decoy, exactly as in activation.
- **Activation and reset codes are not interchangeable.** A challenge
  reference, real or decoy, exists only for the purpose it was issued for.
- **Reset completion is one transaction**: the verified grant is consumed,
  the Family context is re-checked, the password is replaced and EVERY
  earlier session of the account is ended. A failure leaves the old
  password, the grant and the sessions as they were. Mobile trust is not
  changed.
- **After a reset the account is signed in** on a new session; there is no
  extra login step.
- **Family password policy** (activation and reset): at least 8
  characters, confirmation, no composition rule, passphrases allowed, and
  at most 72 bytes of UTF-8 — the bcrypt input limit. A longer password is
  refused, never truncated.
- **One browser session is either Staff or Family.** A Family login or
  reset replaces whatever was signed in.
- **Not in PWA-1G:** "remember me", a Family-specific session lifetime (the
  shared 120-minute idle lifetime applies; to be reviewed with the
  installable PWA and the account module), and an authenticated "change my
  password" (it belongs to the future account module).
- **Three independent gates**, all off by default: activation, login,
  password reset.

## Activation as implemented (PWA-1F)

- **Who may activate.** A Person found by an exact match of the nine
  normalized digits on `persons.national_id` — exactly one live match; zero
  or several deny — who is an eligible household head (the resolver's rule:
  not deleted, active, ALIVE, active membership, household head, Family
  ACTIVE and not deleted), has a valid current registered mobile — trusted
  or not, but never one whose trust Staff revoked — and has no ACTIVE or
  SUSPENDED User-Person Link. UNKNOWN life status is not eligible.
- **Confirm, then send (first self-activation).** The first answer shows
  the registered number masked — `05*****` and the last three digits only —
  and asks "هل هذا رقمك ويمكنك استقبال رمز التحقق عليه؟". Only after the
  user confirms is the code sent. Confirming is not verification and
  creates no trust.
- **One refusal, no reason (FP-ADR-054, after the Production pilot).** An
  input that cannot start activation — unknown or shared National ID, not
  the household head, inactive, deceased or UNKNOWN Person, no active
  membership, inactive or deleted Family, no valid registered mobile, a
  Staff-revoked mobile, an existing account or link — stays on step 1 with
  ONE generic answer: "تعذّر متابعة التفعيل بهذه البيانات. تأكد من إدخال رقم هوية رب الأسرة المسجل في فامبوك، ثم حاول مرة أخرى." The reason is recorded as a security event only
  and never shown. Nothing is created and no SMS is sent. Accepted
  trade-off: a caller can learn whether an input can start activation, and
  nothing more. (Until 2026-10-04 such an input was shown a fake masked
  number and a decoy code step; the pilot showed this misleads users.)
- **The full mobile number is never shown.** A failed SMS delivery is
  answered like a sent one. A change between the start and the send is
  refused the same way; an SMS ceiling is reported as such.
- **The code goes only to the Person's current registered mobile** — the
  very number whose mask was confirmed. The caller cannot name a
  destination; a number in the request is refused.
- **Only a correct code creates trust.** It makes that number the Person's
  TRUSTED mobile with the method SELF_OTP, and is recorded. A wrong,
  expired or superseded code never does. A number already TRUSTED (e.g. by
  Staff) is used as it is and never replaced.
- **Server state decides the step.** A password can be set only against a
  challenge whose code was verified, inside the 10-minute grant.
- **Completion is one transaction**: the grant is consumed, eligibility and
  "no current link" are re-checked, a NEW User is created (email null,
  active, password hashed, `users.name` a snapshot of the Person's name),
  FAMILY_USER alone is assigned, and the ACTIVE link and ACTIVE identity are
  established. Any failure leaves nothing behind and the grant unconsumed.
- **Never an existing account.** Activation never reuses a User, so a Staff
  account can never receive FAMILY_USER; a staff member who is a household
  head gets a separate family-side account. A Person whose earlier link was
  ENDED activates again with a new account.
- **Password policy**: at least 8 characters, confirmation, no composition
  rule; passphrases allowed.
- **Session.** Activation signs the new account in on the existing
  first-party session. A request that cannot carry a session is refused
  before anything is created. A Staff session in the same browser is
  replaced: one browser session is either Staff or Family. No "remember me".
- **Display name.** The Family Portal always shows the linked Person's name,
  never `users.name`.
- **Account is not access.** A family-side account without a Family context
  (death recorded, headship moved, link suspended) sees a neutral
  "access unavailable" state and no family data; the reason is not sent.
- **Request ceilings** (configurable, in addition to the OTP SMS ceilings):
  start 10 per minute and 30 per hour per IP and 5 per hour per identifier
  (its keyed fingerprint); verify 30 per minute per IP; resend and complete
  10 per minute per IP. A start or resend never answers faster than a
  configured floor (400 ms by default — a development value).
- **Gate.** All four activation endpoints answer "unavailable" and do
  nothing while `FAMILY_ACTIVATION_ENABLED` is false (the default).

## Eligibility

A Family Portal user is eligible only while all hold:

```text
User active
User-Person Link ACTIVE
Person not deleted and active
life_status = ALIVE
Active membership with is_household_head = true
Family ACTIVE and not deleted
```

`UNKNOWN` life status is **not** eligible. A deceased current head is
never eligible; the family then has no eligible user until a new head is
assigned (Head Succession, docs/11 FU-01 — a rollout gate).

## National ID

- The login input is accepted only if, after the strict normalization, it
  is exactly nine ASCII digits. No check-digit rule applies.
- Registry Identity and Authentication Identity are separate.
  `persons.national_id` is not a credential and is not made unique.
- A National ID correction (§93b) of a Person who has a family-side
  account supersedes the authentication identity in the same transaction.
  The old National ID stops authenticating at commit; a corrected value
  that is not nine digits suspends the identity.

## Accounts

- Staff-side and family-side accounts are disjoint: one account never
  combines a Staff role with FAMILY_USER or COORDINATOR. A staff member
  who is also a household head has two accounts.
- FAMILY_USER and COORDINATOR coexist on the same family-side account.
- A family-side account has no email; no synthetic email is created.
- One active or suspended User-Person Link per User and per Person.
- Account state, link state and authentication-identity state are separate
  (PWA-1D). Ending a link supersedes its authentication identity and
  revokes the sessions; it does **not** deactivate the account. Deactivation
  is its own explicit operation. An ended link is terminal; a suspended one
  is resumable.
- A recorded death ends the deceased Person's link in the same transaction.
  The membership and the household-head flag are not changed (Head
  Succession, docs/11 FU-01).
- The Staff API requires a Staff-side account (`AccountSide::STAFF`). A
  family-side, mixed, role-less or custom-role account is refused whatever
  permission it holds (docs/06 §22b, AUTH-ADR-066).

## Coordinator

- In V1 a COORDINATOR is always an eligible Family Portal household head.
  A scope assignment never enables activation.
- A coordinator may hold several active scope assignments (CLAN,
  BRANCH_GROUP, BRANCH); authorization is their union. A client-supplied
  scope never grants access.

## Mobile trust

- Trust binds one Person to one exact normalized number. Presence is not
  trust. Mobile numbers are not unique; shared numbers are valid data.
- Every imported mobile begins UNVERIFIED. There is no bulk trust.
- A shared number is trusted only per Person, by individual verification.
- Methods: `IN_PERSON`, `STAFF_CALLBACK`, `AUTHORIZED_RECORD_REVIEW` (Staff
  grants), and `SELF_OTP` — a successful first-activation code sent to the
  Person's current registered mobile (never a Staff grant method).
- Imported/current registered mobile is not trusted merely because it exists. For first self-activation, possession may be established by successful OTP verification sent exclusively to that stored mobile. Successful OTP creates Person-specific TRUSTED status with verification method SELF_OTP. The user's confirmation of the masked number alone is
  never verification.
- A number whose trust Staff REVOKED is never self-verified back; password
  reset always requires an already TRUSTED mobile.
- Only SUPER_ADMIN and ADMINISTRATOR grant TRUSTED initially. A
  COORDINATOR may assist within scope and never grants.
- A changed number no longer authorizes an OTP.
- A head without a mobile cannot self-activate; an authorized contact
  update establishes the number, which starts UNVERIFIED.
- **One question decides trust** (PWA-1E): does this Person have a TRUSTED
  record for exactly their CURRENT normalized mobile? Everything else
  fails closed, including a missing fingerprint key.
- A grant trusts the Person's stored mobile only; a number is never an
  input. The Person must be not deleted, active and ALIVE. Being a
  household head is **not** required: mobile trust belongs to the Person.
- A grant for a number that is already TRUSTED is a conflict; the verifier,
  method and time of an existing trust are never rewritten.
- When the canonical number changes, the previous trust becomes STALE and
  its open OTP challenges are superseded. A formatting-only edit changes
  nothing.
- **Changing back does not restore trust.** A STALE or REVOKED record never
  becomes TRUSTED again; the number needs a new verification.
- Revoking mobile trust does not deactivate the account, end the link or
  revoke sessions: it is the trust of an OTP destination, not of an
  identity or a session.
- Only the canonical format is accepted: ten ASCII digits beginning `05`.
  `+970…`, `00970…` and numbers without the leading zero are not valid.

## OTP and password

```text
OTP        6 digits · 5-minute TTL · 5 attempts · 60-second resend cooldown
           · 3 sends per challenge · single use · superseded codes invalid
Password   minimum 8 characters · confirmation required · no mandatory
           composition · passphrases allowed
```

OTPs are stored only as keyed hashes. Passwords are stored only through
Laravel hashing. A generated password is never sent by SMS. A password
reset ends every session of the user.

As implemented (PWA-1E):

- A challenge is bound to one purpose, one Person and one trusted mobile.
  Every step re-checks that this is still the Person's current trusted
  mobile, so a code can never prove another number, Person or purpose.
- **Resend** uses the same challenge: a NEW code, the previous one invalid
  at once, the five-minute expiry restarts, the attempts are NOT reset —
  three sends never give more than five attempts. An expired challenge is
  not resurrected; a new one is issued.
- A correct code opens a **10-minute grant**; it does not consume. The
  grant is consumed inside the transaction of the workflow that uses it.
- The SMS is sent after the challenge is committed — on the public
  activation and password reset steps, after the response (TweetsMS, see
  above). A failed delivery still counts as a send and toward the
  throttles; there is no automatic retry.
- Sends are also limited across challenges — configurable security
  settings: per Person 5 per hour and 10 per day; per destination 10 per
  hour and 20 per day; per IP 20 per hour; globally 500 per hour. If the
  limiter cannot be checked, nothing is sent.

## Audit and retention

Authentication and security events are append-only and retained 24
months. Finished OTP challenges may be purged after 90 days. Link and
mobile-trust history are never purged by OTP cleanup.

## Production gates

Activation is not enabled in Production before a Production SMS provider,
delivery-failure handling and secure credential storage exist (docs/08
§16a). TweetsMS and its failure handling are integrated; its credentials
must be configured and validated on the server. Because SMS is sent after
the response without a queue, a queue worker is no longer required for
SMS.

---

# 90. Notifications

Notifications must be generated from authoritative backend events.

The frontend must not independently determine that a business event occurred.

---

# 91. Notification Privacy

Notifications should contain the minimum sensitive information necessary.

Example preferred:

```text
Your request CRQ-000125 requires clarification.
```

rather than exposing sensitive Person details in notification text.

---

# 92. Notification Delivery Failure

Notification delivery failure must not reverse a successfully committed business transaction.

Example:

```text
Change Request APPLIED
+
SMS failed
```

must not revert the applied registry change.

---

# 93. Search Authorization

Search is subject to authorization.

A Staff user's ability to search does not imply permission to view every matching field.

Family Users do not receive global Person/Family search.

---

# 93a. Registry Search and Duplicate Prevention (V1)

Approved 2026-09-26 (Pilot Readiness Slice B).

## Registries

```text
Families   GET /api/v1/families   family.view
           search: Family code, or the name / Person code of any current
           member; optional status; server-side pagination (≤ 100/page);
           `summary` counts the whole registry by status (never a page)
People     GET /api/v1/people     person.view
           search: Person code or name; non-deleted Persons; paginated
```

Search is a plain case-insensitive "contains" match. `%`, `_` and `!` in the
term match literally. There is no Arabic normalization, fuzzy or phonetic
matching (PDD-003 stays open) and no National ID search. Registry rows carry
codes, names, gender, date of birth, life status and current Family context
(Family context only with `family.view`) — never the National ID, contact
details, health data or internal ids. Search state lives in the page URL
(`?q=…&page=…`); a National ID is never put in a URL.

## Exact National ID duplicate prevention

A National ID already held by another non-deleted Person cannot be given to
a new Person:

```text
Registering a household head    refused (422)
Adding a Family member          refused (422)
Changing a Person's National ID refused (422) if another Person holds it
```

(Since Slice C, a National ID is changed only through the administrative
correction of §93b.)

The refusal ("يوجد شخص مسجل مسبقًا بهذه الهوية.") lists safe references to
the existing record — Person code, name (with `person.view`), Family code,
household-head flag and relationship — so staff can inspect it. Nothing is
created; the existing Person is not attached to the new Family, not moved and
not merged (§23: no automatic merge). The National ID value is never echoed.

Matching is exact on the stored value (request whitespace trimmed only; PDD-001
normalization stays open) — no partial, prefix or fuzzy match. A missing
National ID is never a duplicate and may remain NULL (§19).

Staff entering data get the same answer early through an exact pre-check
(`POST /api/v1/people/national-id-check`, AUTH-ADR-058), which is not a
search: exact value only, POST body only, rate limited, National ID never
returned.

National ID is not UNIQUE in the schema (documented duplicates must remain
representable for review). The creation actions enforce the rule inside their
transaction and hold a PostgreSQL advisory lock per National ID, so two
concurrent creations with the same value cannot both succeed. Remaining
limitation: the lock only covers writes through these actions (registration,
add member, Person update); any future import or bulk path must use the same
guard. Duplicates already present are not detected retroactively (see the
Data Quality report roadmap).

## Unknown date of birth

Date of birth is optional when registering a household head or adding a
member. An unknown date of birth is stored as NULL — never a placeholder
(§26) — displays as "غير معروف", produces no age, and counts in the UNKNOWN
age band (docs/02 §75) and the Data Quality "missing date of birth" check.
Partial dates remain deferred (PDD-022).

---

# 93b. Data-Entry Corrections (V1)

Approved 2026-09-27 (Pilot Readiness Slice C, AUTH-ADR-059). These are
**data corrections** (§56) of common entry mistakes. They never delete a
Person or a membership and never merge Persons (§23).

## Relationship correction

`PATCH /api/v1/families/{family}/members/{person}/relationship`
(`family-membership.update`). Corrects the relationship of a **current**
member to the household head, using the canonical relationship types
(active values only). The membership row is corrected in place: the
Person, the membership, its dates and `is_household_head` are unchanged.

The HEAD relationship and `is_household_head` always agree:

```text
household head   → only HEAD is accepted (a head cannot be "corrected" away)
any other member → HEAD is refused
```

So an ordinary correction can never move, add or remove the household head;
that remains the controlled household-head change (§15), not built in V1.
A Person who is not a current member of that Family → 409. Re-saving the
same relationship changes nothing and records no activity.

## Ending an incorrect membership

`POST /api/v1/families/{family}/members/{person}/end`
(`family-membership.end`: SUPER_ADMIN, ADMINISTRATOR — not DATA_ENTRY,
because ending is not reversible in V1), e.g. a Person attached to the wrong Family or no
longer part of it. A short reason is required (3–255 characters).

```text
is_active   → false
ended_at    → today (never before started_at)
end_reason  → the staff member's reason (free text)
```

Nothing is deleted: the membership stays as history (§11, §99, §100), the
Person stays in the registry unchanged and is **not** attached to any other
Family (that would be a transfer, §13). The current household head's
membership cannot be ended (409): a Family must not lose its head through an
ordinary correction. An already-ended membership cannot be ended again.

After ending, current views show the change: the Family profile lists and
counts current members only, the Family registry's member count and member
search use current memberships, and the Person shows no current Family.
Family-level derived figures (health summary, targeting, dashboard,
reports) already count active memberships only.

## National ID correction

`PUT /api/v1/people/{person}/national-id` (`person.national-id.update`:
SUPER_ADMIN, ADMINISTRATOR). The only write path for an existing Person's
National ID:

- the replacement is entered explicitly, twice, and is never pre-filled —
  the stored value is never sent to any client;
- a blank value is refused; V1 has no "clear" action;
- the exact duplicate rule of §93a applies (refused, nothing changes, safe
  references only);
- stored as entered — no normalization (PDD-001 stays open);
- afterwards only the masked value (`*****6789`) is shown, and only to
  holders of `person.national-id.view-masked` (docs/06 §39).

## Activity privacy

Each successful correction records one Family Activity event (§97a) on the
Person's current Family, subject = the Person, **no metadata**:

```text
MEMBERSHIP_RELATIONSHIP_CORRECTED   never the old/new relationship
MEMBERSHIP_ENDED                    never the reason
NATIONAL_ID_CORRECTED               never the old or new National ID
```

A Person without a current membership has no family timeline, so a
National ID correction for such a Person records no activity.

## Residence decision (governorate / city)

`family_residences.governorate` and `.city` are nullable (docs/02 §19,
docs/04 §24); only validation required them. From Slice C they are optional
at registration and in the residence correction: NULL = not recorded,
shown as "غير مسجّل", never filled with a guessed value. A displaced Family
whose form gives only the displacement location keeps it without fabricated
geography. Labels distinguish:

```text
السكن الحالي                 current address (optional governorate / city)
السكن الأصلي (قبل النزوح)    original_residence_text
مكان النزوح الحالي           displacement_location_text (DISPLACED only)
```

No geographic hierarchy or maps (PDD-006 stays open).

## Remaining limitations

- An ended membership cannot be re-activated, and the Person cannot yet be
  attached to another Family (no transfer / attach-existing operation).
  Re-adding the Person as a new member is refused when they have a National
  ID (§93a duplicate rule) — correct, but it leaves such a Person
  Family-less until transfer exists.
- Household-head change is not implemented; a wrong head needs that future
  operation.
- National ID normalization (PDD-001) and the geographic hierarchy
  (PDD-006) stay open.

---

# 94. Reporting

Reports must use authorized canonical data.

Proposed Change Request data must not be included as official registry state unless the report explicitly concerns Change Requests.

---

# 95. Export

Export requires separate authorization.

A user who may view data interactively does not automatically have permission to export it.

---

# 96. Import

Imports must not bypass business rules.

Import workflows must support:

```text
Validation
Duplicate Detection
Error Reporting
Source Traceability
Controlled Application
```

---

# 96a. Import Wizard — Initial and Incremental Family Import

Approved 2026-09-29. Staging foundation, the **Import Wizard (steps 1–5,
step 6 as a shell)**, **family-key resolution (Phase 2B)** and **record
reconciliation (Phase 3)** are **IMPLEMENTED NOW**. **Apply is a FUTURE
PHASE** — an import has not written any Family, Person or Membership; the
only reference data it may produce are Branches explicitly created by an
authorized administrator while resolving keys.

## The Wizard (`/administration/imports`, "الاستيراد الأولي وتحديث بيانات الأسر")

```text
1 العشيرة             explicit target Clan (select or create) + import mode   IMPLEMENTED
2 ملف البيانات        upload, inspect, explicit worksheet                     IMPLEMENTED
3 تعيين الأعمدة       column → canonical field mapping; confirming STAGES     IMPLEMENTED
4 مراجعة مفاتيح الأسر  discovery + one explicit decision per distinct key      IMPLEMENTED
5 مراجعة البيانات     staging review (counts, issues, keys, problem rows,     IMPLEMENTED
                      key-resolution summary) + reconciliation with the
                      registry (read-only on the registry)
6 الاعتماد والاستيراد  shell only (shows reconciliation) — no Apply          FUTURE
```

A step opens only when its prerequisites hold (1: Clan + mode; 3: inspected
workbook with a selected worksheet; 4: confirmed mapping and staged rows;
5: additionally every distinct non-blank family key explicitly resolved;
6: additionally a CURRENT reconciliation).
Warnings never block navigation. The batch lives in the URL (`?batch=`), so
reopening restores its context; its Clan and mode never change — another
Clan or mode needs a new import flow.

## Two import modes (explicit, never inferred)

```text
INITIAL       first controlled population of a Clan's dataset
INCREMENTAL   a later (possibly daily) complete workbook for the same Clan
```

Repeated uploads for a Clan are **expected**. An INCREMENTAL workbook may
repeat previously imported records, add new ones, change some and contain
duplicates or conflicts — the system never assumes "new workbook = new
Families/Persons". Every upload is an **independent Import Batch**; batches
are never merged or replaced, so the history of what each file contained is
kept (future phases record which batch introduced, re-encountered or
proposed changes to a record and which changes were accepted or rejected).

## Staging rules (implemented)

- **Target Clan is mandatory and explicit** (`import_batches.clan_id`); there
  is no default Clan and it is never read from the Excel. A Clan may be
  created inside step 1 through the existing Clan creation (`clan.manage`):
  it creates the Clan only — no Branch Group, Branch or Family.
- **Upload is not staging.** The workbook is stored on the PRIVATE disk and
  inspected: worksheet names, header labels with their column letters and
  positions, row counts — never cell values. One obvious data sheet is
  suggested; several plausible sheets require an explicit choice.
- **Column mapping.** Famboook works on canonical fields
  (`source_family_key`, `national_id`, `full_name`, `birth_date`, `gender`,
  `marital_status`, `original_residence_text`, `life_status_source`,
  `death_date`, `declared_household_size`, `declared_living_sons`,
  `declared_living_daughters`, `mobile`, `wife_1…4_national_id`,
  `wife_1…4_name`). Required: `source_family_key`, `national_id`,
  `full_name`; the rest are optional and stay NULL when absent. Known
  headers get reviewable suggestions (header-only cosmetic normalization);
  source VALUES are never fuzzy-normalized. Columns are identified by
  letter/position, never by header text alone, so the repeated
  "هوية الزوجة" / "الزوجة" headers stay distinct. Every kept column is
  explicitly mapped or ignored ("تجاهل هذا العمود"); a column serves one
  field at most. Samples in the preview are masked (IDs and phones show
  the last 3 digits only).
- **Staging happens only on "اعتماد تعيين الأعمدة".** Replacing the
  workbook or changing the worksheet invalidates the mapping; confirming a
  different mapping re-stages. Only that batch's own staged rows are
  discarded — never another batch, never registry data.
- **Excluded source fields are never persisted or mappable**: `هويتك` and
  `الديانة`. They are listed under "أعمدة مستبعدة" by header only; their
  values never reach `raw_payload`, `normalized_payload`, `issues`, batch
  metadata, previews, API responses or logs (`ImportRawPayload` guard).
- **Family key is explicit source data** (`المفتاح`), whitespace-normalized
  only (trim, collapse spaces; no ة/ه or hamza changes). It is **never
  inferred** from the head's name, surname, gender, marital status or a
  spouse's name. Formula-produced keys keep Excel's cached value (formulas
  are never evaluated), are marked `source_family_key_origin = FORMULA` and
  flagged — never trusted as approved Branch membership.
- **Female and widowed household heads are valid** and stage normally; a
  widow's surname is never used to infer her late husband's Branch.
- **Branch Groups are outside the import.**
- **Row classification is structural only**, with stable issue codes:

```text
TOTAL          every non-blank source row that was staged
READY          PENDING — staged without issues
NEEDS REVIEW   FLAGGED — staged with review flags (NOT rejected):
               MISSING_FAMILY_KEY, FAMILY_KEY_FROM_FORMULA,
               FAMILY_KEY_TOO_LONG, MISSING_FULL_NAME
REJECTED       kept in staging, structurally blocked: CELL_ERROR, EXTRA_CELLS
TOTAL = READY + NEEDS REVIEW + REJECTED
```

- **Staging success is not a domain import.** The UI says "تم تجهيز … صف"
  and "لم يتم تطبيق البيانات على السجل بعد" — never "تم استيراد … أسرة".
- **Step 4 is read-only discovery**: per distinct key its row count,
  formula-row count, example source rows and whether a Branch with EXACTLY
  that name exists in the TARGET Clan (another Clan's Branch is
  irrelevant). No mapping, no Branch creation, no fuzzy matching.

## Duplicate file ≠ existing record ≠ duplicate row

```text
Duplicate FILE         the same SHA-256 for the same Clan while an earlier
                       batch of it is live (not FAILED) → refused (409, link
                       to that batch), whatever the import mode. IMPLEMENTED.
Existing DOMAIN record a source row describing a Person/Family already in
                       Famboook → FUTURE reconciliation.
Duplicate SOURCE row   the same identity twice inside one workbook → FUTURE.
```

A different workbook for the same Clan is always allowed, and a different
checksum never means its rows are new. The same file for another Clan may
be staged (another intended context).

## Record reconciliation (IMPLEMENTED NOW — Phase 3)

**Reconciliation ≠ Apply.** Reconciliation answers "what does each staged
row correspond to in the current permanent registry?" and persists only
staging metadata (`import_rows.reconciliation_status` and an evidence record
per row, docs/02 §88c). It never writes Persons, Families, Memberships,
Residences, declarations or Branches and never changes staged source values.
Endpoint `POST …/{batch}/reconcile` (`import.validate`); it requires staged
rows and every family key resolved. Re-running replaces the result
(deterministic; no duplicates).

**Person matching ≠ Family matching.**

- **HEAD Person**: an **exact** National ID match on the stored value — the
  same rule as `NationalIdGuard` (PDD-001 normalization stays open). A match
  that only appears after normalizing digits/separators is surfaced
  (`FORMAT_VARIANT`, review), never used. A matching National ID is a
  deterministic *candidate* — never permission to update the Person.
- **Family**: only through the matched Person's **active household-head
  membership** in a Family of the batch's Clan. Never by head name, family
  key, Branch, phone, city, declared size or spouse name — a family key or
  Branch is classification, not household identity.
- **Spouses** (up to four): matched separately by exact National ID as
  candidates only; never created or merged here.
- **No National ID**: never deduplicated by name. A no-ID head is `NEW`
  unless the same (whitespace-normalized) name exists in another staged row
  or in the registry, in which case it is `REVIEW_REQUIRED` — the name only
  raises review, it never matches.
- An existing Person who heads no Family gives `NEW` (new Family) with
  `head_match = EXISTING_PERSON`, `family_match = NO_EXISTING_FAMILY`.

States (strongest wins: CONFLICT > DUPLICATE_IN_FILE > REVIEW_REQUIRED >
CHANGED > UNCHANGED > NEW):

```text
NEW                no deterministic existing Family — creation candidate for a
                   future Apply (nothing is created now)
UNCHANGED          deterministic Family match, no meaningful differences
CHANGED            deterministic Family match with differences (shown field by
                   field: registry vs file; nothing is overwritten)
DUPLICATE_IN_FILE  the same head National ID on several rows of the batch
                   (all such rows; none chosen, none collapsed)
CONFLICT           unsafe identity evidence: the ID is on several registry
                   Persons; the Person is a non-head member of another Family;
                   the Person heads a Family of another Clan; head ID equals a
                   spouse ID of the same row
REVIEW_REQUIRED    insufficient or ambiguous evidence: head/spouse cross-role
                   use in the file (except an accepted polygamous household
                   pair, see below), a spouse ID repeated within a
                   row, a spouse
                   ID repeated across rows unless every other head of the group
                   is deceased (see below), a spouse in another Family, a
                   deleted-Person match,
                   a format-variant ID, a birth-date/gender mismatch with the
                   existing Person, no-ID name ambiguity, a death date without
                   "متوفى"/"متوفي", an unknown source value, a rejected row
```

**Repeated spouse across households (widow remarriage)**: the same exact
spouse National ID on several household rows is one Person candidate, and
the whole group of rows is judged together by the heads' mapped life status:

- at most one head ALIVE and every other head DECEASED → legitimate
  (remarriage after the husband's death); informational only, it does not
  by itself require review;
- two or more heads ALIVE → REVIEW_REQUIRED ("ظهرت الزوجة نفسها في أكثر من
  أسرة مع أرباب أسر أحياء");
- otherwise, any head with an unknown/unmapped life status → REVIEW_REQUIRED;
  death is never assumed.

Excel row order is never treated as marriage chronology. This rule does not
change the duplicate-head rule, the same-spouse-in-one-row rule, head/spouse
cross-role use or identity conflicts.

**Polygamous household (approved source semantics)**: the wife slots of a
head's row are in source order (wife_1 first, the last non-empty slot last).
Slot position is kept as source evidence only; it does NOT decide which wife
belongs to which Family. A HEAD ↔ SPOUSE cross-role between a man and a woman
is accepted as two independent source households (informational "أسرة مستقلة
لزوجة رب أسرة متعدد الزوجات", not blocking) only when all hold:

1. his row: MALE and ALIVE;
2. his marital status is exactly "متعدد الزوجات";
3. her exact National ID is in one of his wife slots (any slot, including
   the last);
4. she heads her own source row;
5. her row names him back by his exact National ID;
6. neither row (his, hers) needs review for any other reason.

Several women heading their own rows may name the same polygamous husband;
for such validated pairs that is not a shared spouse (other spouse reuse
follows the normal rules). A DECEASED husband is not handled by this rule
(the ordinary and widow/remarriage rules apply); an unknown life status, an
ordinary married head, a missing reciprocal ID or a name-only relationship
keeps REVIEW_REQUIRED. Polygamy is never inferred from several women naming
the same man, and Excel row order is never used. The same exact ID is one
Person candidate.

**Architecture constraint for a future Apply** (not implemented): each
accepted household row becomes its own Family with that row's head as
Household Head; a woman with an accepted independent row stays head of her
own Family. V1 allows one active membership per Person and one active head
per Family, so Apply must not also make the husband an active member of her
Family, and must not create conflicting active memberships to reproduce
every source spouse reference; those references are preserved as evidence
for future relationship modeling. Wife-slot position never decides
membership. This rule is separate from widow remarriage above.

Differences compare only fields the source provides (an absent source value
is never a change): Person full name (whitespace-collapsed), birth date,
gender, marital status, mobile, life status, death date; Family — only with a
deterministic Family match — resolved Branch (a `NO_BRANCH` decision means
"no information", never "remove the Branch"), original residence and the
declared household statistics. Source text is mapped through fixed spelling
lists (e.g. ذكر/أنثى, متزوج/متعدد الزوجات → MARRIED, حي, متوفى/متوفي →
DECEASED) — no fuzzy Arabic normalization. Forbidden columns never take part.

**Preliminary readiness**: `NEW` and `UNCHANGED` are non-blocking; `CHANGED`,
`DUPLICATE_IN_FILE`, `CONFLICT` and `REVIEW_REQUIRED` need review before any
future Apply. Final Apply eligibility is decided in the Apply phase.

**Staleness**: a run stores a fingerprint of its inputs — the staging
(mapping confirmation), the key decisions and cheap registry markers
(counts / latest updates of persons, families, memberships, residences,
declarations and the Clan's Branches). A different current fingerprint marks
the result `STALE`; a re-confirmed mapping, a new workbook or a new worksheet
discards it (`NOT_RUN`). A future Apply must refuse a result that is not
`CURRENT`. Before Apply, Step 6 opens only for a `CURRENT` reconciliation;
once Apply has started, Step 6 stays reachable as the Apply status screen
(Apply's own writes make the result stale by design — see §96b).

**INITIAL and INCREMENTAL** use the same engine. INCREMENTAL is **not
synchronization**: a Person or Family absent from a later workbook is
**never** deleted, deactivated, marked deceased, stripped of memberships or
spouse links, or otherwise modified — absence is never examined and no
deletion is ever proposed. Each batch has its own result; results are never
reused across batches. Unchanged records will be skipped by a future Apply,
while the batch keeps the record that it encountered them.

## Family-key resolution — "مراجعة مفاتيح الأسر" (IMPLEMENTED NOW)

**`source_family_key` ≠ Branch.** A key from Excel is source data only; an
authorized administrator decides explicitly what it means inside the
batch's Clan. The decision is made **once per (batch, exact source key)** and
applies to every staged row of that batch with exactly that key
(`import_family_key_resolutions`, docs/02 §88b). The staged source key is
**immutable** — resolution is a separate layer and never rewrites it.

```text
MATCH_EXISTING_BRANCH  "ربط بفرع موجود"          a selectable Branch of the batch's Clan
CREATE_NEW_BRANCH      "إنشاء فرع جديد"           creates ONE Branch in the batch's Clan,
                                                  UNGROUPED (branch_group_id NULL), then maps
SAME_BRANCH_AS_KEY     "ربط بنفس فرع مفتاح آخر"   reuses the FINAL Branch of another resolved
                                                  key of the batch (spelling variants)
NO_BRANCH              "بدون فرع"                 explicit: families may later get branch_id NULL
(no decision)          "لم يُحسم"                 UNRESOLVED — NOT the same as NO_BRANCH
```

- **Nothing is inferred**: never from a Person's name or surname, gender,
  marital or widow status, or row frequency. In the per-key tools an exact
  Branch-name match in the target Clan is shown as a **suggestion only** and
  is never saved without the administrator's confirmation; the one exception
  is the explicit automatic action below, which reuses exact matches by
  design. There is no fuzzy
  matching and no automatic merging of spelling variants (أ/ا, ة/ه, ى/ي,
  "ال", …): the administrator decides, e.g. by resolving "ابو سعادة" to the
  same Branch as "أبو سعادة" (SAME_BRANCH_AS_KEY). A key that appears once is
  not invalid by itself; its row count stays visible.
- **Target Clan only.** Only selectable Branches of the batch's Clan
  (`Branch::isSelectable`: Branch and Clan active and, when grouped, its
  Group active) can be chosen — enforced by the Domain Action and by
  composite foreign keys in the database.
- **Branch creation is an explicit reference-data action**, never an
  automatic import effect: it requires `clan.manage` (in addition to
  `import.review`), an explicit name (defaulting to the source key) and an
  explicit code (the domain requires one; it is never derived from Arabic
  text), and a confirmation. It uses the existing Branch creation (ungrouped)
  and refuses a name already used by a Branch of the Clan (link it instead).
  No Branch Group, Family, Person or Membership is created. Bulk creation is
  possible only for keys the administrator selected, after a preview in
  which each name and code is reviewed (a helper can fill blank codes with a
  neutral numbered sequence, still editable); it is all or nothing.
  Explicitly selected keys can also be set to NO_BRANCH in bulk.
- **INITIAL import: Branches from the workbook ("إنشاء الفروع من مفاتيح
  Excel").** For an INITIAL batch the workbook's family keys are the
  authoritative source of the Clan's Branches, and Branches need not exist
  beforehand. One explicit administrator action (`import.review` +
  `clan.manage`, after a confirmation stating the count) resolves EVERY
  still-unresolved distinct key, atomically, in first-appearance (row
  number) order:
  - a Branch of the batch's Clan whose name equals the key exactly (after
    whitespace normalization only) → **MATCH_EXISTING_BRANCH**; it must be
    selectable and unique, otherwise the whole action is refused — never a
    duplicate Branch, never a guess;
  - otherwise → **CREATE_NEW_BRANCH**: one new ungrouped Branch named after
    the key, with the permanent server-generated code `BR_000123` (below).
  Spelling variants stay separate keys (no fuzzy matching). Keys taken from
  an Excel formula's cached value are included; their warning stays visible.
  Rows without a key are untouched and stay blocking (FAMILY_KEY_MISSING).
  Keys already decided are never changed, so running it again is a no-op.
  The per-key and bulk tools remain for exceptions. INCREMENTAL batches and
  batches no longer under review are refused.
- **Permanent Branch code (canonical).** A Branch created by the automatic
  action gets `BR_` + its reserved database id zero-padded to six digits
  (`BR_000001`, `BR_000002`, …) — the BusinessIdentifier strategy of
  `FAM-` / `PER-` codes, with `_` because Branch codes allow only
  `[A-Z0-9_]`. The id is reserved from the `branches` sequence before the
  insert, so the code is unique and never derived from Arabic text; it never
  changes. Branches created by hand keep their administrator-chosen code.
- **Branches are Clan master data immediately.** A Branch created in Step 4
  (manually or automatically) belongs to the Clan from that moment and
  persists independently of the batch: abandoning, failing or deleting the
  batch never deletes it.
- **SAME_BRANCH_AS_KEY stores the final Branch directly** (the other key is
  kept for audit only), so there are no chains or loops; changing the other
  key later does not cascade.
- **Editing before Apply**: a decision can be changed or cleared (back to
  UNRESOLVED). A Branch created by an earlier decision is **never deleted**;
  it simply stays in the Clan's structure.
- **Audit**: each decision records who decided (`resolved_by`) and when
  (`resolved_at`), the exact key, the decision and the target Branch.
- **Progress & readiness** are backend-authoritative (distinct, resolved,
  unresolved, per decision). NO_BRANCH counts as resolved. Rows with a blank
  key need no (and get no) resolution. Step 5 opens only when every distinct
  non-blank key is resolved (both import modes).
- **Staging interplay**: re-confirming the column mapping keeps decisions
  for keys that are still staged and drops only those whose key disappeared;
  replacing the workbook or changing the worksheet clears the batch's
  decisions. Branches are never touched.
- Branch Groups are created and Branches organized manually after import.

**FUTURE PHASE**: decisions belong to their batch. A later (e.g. INCREMENTAL)
batch with the same keys starts UNRESOLVED — prior confirmed decisions are
**not** copied or applied automatically; a future phase may *suggest* them.
No alias intelligence, no fuzzy matching.

## Authorization

`import.upload` (steps 1–2), `import.validate` (mapping and staging),
`import.review` (steps 4–5, including key decisions) — SUPER_ADMIN only
(AUTH-ADR-060). Creating a Clan (step 1) or a Branch (step 4) additionally
requires `clan.manage`; a reviewer without it may still map to existing
Branches or choose NO_BRANCH. Apply (§96b) uses `import.apply` —
SUPER_ADMIN only, and only while the Apply activation gate is open (closed
by default; docs/08 §7a).

## Initial Family Excel import — approved mapping (Phase 2 target)

```text
هويتك              ignored completely (never a National ID, never a Person,
                   never used for duplicate detection, never persisted)
الديانة            ignored completely (outside the approved dataset)
المفتاح            source family key → Phase 2B review → families.branch_id
                   (or NULL); never inferred from any name
رقم الهوية         head persons.national_id (read as text)
الاسم              head persons.full_name (as written); never a branch source
الميلاد            head persons.birth_date (NULL when unknown/partial)
الجنس              head persons.gender
الحالة الاجتماعية  head persons.marital_status (unmapped → flagged)
المدينة            family_residences.original_residence_text — the ORIGINAL
                   city; never city, never current residence, never
                   displacement status or location
حالة الوفاة        حي → ALIVE, متوفي → DECEASED (the Person is CREATED
                   DECEASED by CreatePersonAction — the import never calls
                   RecordPersonDeathAction, §30);
                   the 2026-09-29 source spells it متوفى — Phase 2C decides
                   the exact accepted spellings
الوفاة             head persons.death_date (never invented)
أفراد الأسرة       declared_household_size          (§55c)
أبناء ذكور أحياء   declared_living_sons              (§55c)
أبناء إناث أحياء   declared_living_daughters         (§55c)
الجوال             head persons.mobile
هوية الزوجة/الزوجة  ×4  one Person per non-empty slot + SPOUSE membership
```

- **Family:** `registration_source = IMPORT`; `registration_date` = the
  date the batch is actually APPLIED (never a date invented from unrelated
  source values); the file stays traceable through `import_rows.family_id`.
- **Household head:** Person + active membership with HEAD and
  `is_household_head = true`.
- **Wives:** each non-empty slot becomes an independent Person with an
  active SPOUSE membership (`is_household_head = false`) in the same
  Family — never `wife_1…wife_4` columns. `gender = FEMALE` from the
  column meaning. Birth date, mobile, marital status and life status are
  not inferred; the validator handles the missing wife life status
  explicitly.
- **No fake children:** the declared figures go to a declaration only.
- **Residence:** only `original_residence_text` is populated from this
  source; current-address fields and `displacement_status` stay NULL
  (not collected).
- **Life status:**

```text
ALIVE + no death date              valid
DECEASED + valid death date        valid
DECEASED + no death date           valid (death_date NULL)
ALIVE + death date                 FLAGGED
blank death status                 FLAGGED (never defaulted to ALIVE/UNKNOWN)
unknown / unmapped death status    FLAGGED
death date without DECEASED        FLAGGED
```

- **Branch:** comes only from the explicit `المفتاح` key through the
  Phase 2B administrator decision (above) — **superseding** the earlier
  name-suffix matching idea, which is withdrawn. The system never derives a
  Branch from any name (consistent with CB-2: the key is source data, not an
  inference) and never creates a Branch without that explicit decision.
  Branches are resolved per target Clan only and never depend on Branch
  Groups (§7a).
- **National IDs:** read as strings (never Excel numbers); Arabic/Western
  digits and formatting noise normalized for comparison only; duplicates
  detected inside the batch and against existing Persons; the canonical
  write always goes through `NationalIdGuard`. A missing head National ID
  is allowed by the schema but FLAGGED.
- **Flag, never resolve silently:** the same ID on several rows; the same
  wife ID more than once; a head ID equal to a wife ID; an ID already in
  Famboook; a wife appearing elsewhere as a household head; duplicate
  spouse entries; the same spouse name with missing or different IDs.
  Persons are never merged, and an existing Person is never overwritten or
  attached because an ID matched.
- **Household size flags (not rejections):** declared size 0 or blank;
  declared size below 1 + imported spouses; sons + daughters inconsistent
  with the declared size. Registered ≠ Declared is expected.

---

# 96b. Import Apply — Foundation and Contract (Phase 4B.1)

Approved 2026-09-29 (Phase 4A design, Phase 4B.1 foundation). INITIAL Apply
is implemented in the backend (runner Phase 4B.4c, API Phase 4B.4d) but is
**not activated**: the Apply activation gate is closed by default, so nobody
holds `import.apply`, and there is no Apply button or job. The sections below
record what is IMPLEMENTED and the approved contract (PLANNED) for what is
not yet. INITIAL Apply is implemented before INCREMENTAL.

## Apply lifecycle (IMPLEMENTED — schema and invariants only)

```text
READY_FOR_REVIEW ──(future Dry Run / approval)──► APPLYING ──► APPLIED
                                                     │
                                                     └─► PARTIALLY_APPLIED  (some rows committed, a later row failed; resumable)
FAILED = a PRE-Apply failure (upload / staging) only
```

- `import_batches.apply_started_at` is set exactly when the status is
  APPLYING, PARTIALLY_APPLIED or APPLIED (PostgreSQL CHECK, mirrored by the
  model). A batch whose Apply started can therefore **never become FAILED**.
- The source-checksum index excludes only FAILED, so a batch that is
  APPLYING / PARTIALLY_APPLIED / APPLIED keeps its file protected: the same
  file cannot be uploaded again for the same Clan and applied twice.
- No separate APPLY_FAILED state: a failure before any row committed returns
  the batch to its pre-Apply state (nothing reached the registry); a failure
  after a committed row is PARTIALLY_APPLIED.
- Every Wizard step (worksheet, workbook, mapping, family keys, reconcile)
  accepts only UPLOADED / READY_FOR_REVIEW, so a started batch is frozen.

## Provenance (IMPLEMENTED — table and invariants; nothing writes it yet)

`import_apply_records` is the authoritative, append-only provenance of every
intended effect of an import row. One record per `(import_row_id,
effect_key)` — both NOT NULL — so a retried or resumed Apply can never record
the same effect twice.

```text
effect_key   HEAD_PERSON | FAMILY | HEAD_MEMBERSHIP | HOUSEHOLD_DECLARATION | RESIDENCE
             | SPOUSE_n_PERSON | SPOUSE_n_MEMBERSHIP   (n = source wife slot 1..4)
outcome      CREATED | REUSED  → references the entity (entity_type, entity_id)
             OMITTED | BLOCKED → carries a reason_code (stable code, never a value)
```

It answers: which row / batch produced an entity; created or reused; HEAD or
SPOUSE and which slot; why a spouse membership was omitted; who applied it
and when. A registry entity is CREATED by at most one effect. Records hold
ids and codes only — never a National ID, name or source value. Activity-log
metadata may later carry safe import identifiers (batch / row / outcome)
through its allow-list only.

## Person creation (IMPLEMENTED — CreatePersonAction)

One canonical path creates every Person (staff registration, adding a
member, future Apply): person code, National ID guard (exact value; NULL
allowed, never a fake value), and an **explicit** life status:

```text
ALIVE     no death date
UNKNOWN   no death date
DECEASED  death date optional (NULL = unknown, never invented); when given:
          a real date, not in the future, not before the birth date
```

A Person known to be deceased when first registered (e.g. an imported
deceased household head) is **created DECEASED** — no "death recorded after
registration" event. RecordPersonDeathAction remains the lifecycle change of
an existing Person; both use the same death-date rules. Staff registration
and add-member still always create ALIVE Persons and never accept a client
life status.

## Family creation (IMPLEMENTED — CreateFamilyAction)

One canonical path creates the Family row: family code, required Clan,
optional Branch of that Clan (selectable), status ACTIVE, registration
source and date, FAMILY_CREATED activity. RegisterFamilyAction uses it.

## Relationship types (IMPLEMENTED)

Flows that must record HEAD / SPOUSE resolve them strictly; a missing or
inactive type fails before any write. Family registration requires an
active HEAD type (a missing seed fails explicitly and nothing is written);
the future Apply will require HEAD and SPOUSE. `relationship_type_id` stays
nullable in the schema.

## Approved contract for the future Apply (PLANNED — not implemented)

- One source household row = one Apply unit = one database transaction;
  committed rows stay committed; resume skips rows already linked
  (`import_rows.family_id`) and effects already recorded.
- Family: one per accepted row; Clan from the batch; Branch from the
  resolved key (NO_BRANCH → none); never creates or infers a Branch; status
  ACTIVE; registration source IMPORT; registration date = Apply date.
- Head Person: reuse the single exact-National-ID Person without an active
  membership (no field updates in INITIAL); a soft-deleted or ambiguous match
  blocks the row; never by name, never a merge.
- **Deceased head**: the Family is still created with that Person as its
  HEAD (created DECEASED); the Family carries the non-blocking review
  evidence `HOUSEHOLD_HEAD_DECEASED`; no replacement head is invented.
- Spouse Person ≠ spouse membership: every exact spouse National ID becomes
  (or reuses) ONE Person; an active SPOUSE membership is created only when
  deterministic and allowed by one-active-membership-per-Person.
- **Female head + spouse column**: in this import format a populated spouse
  slot of a FEMALE head is her MALE spouse (importer-specific source
  semantics, not a global rule). A male head's spouse slot is FEMALE.
- A spouse who heads her own accepted row: Person yes, SPOUSE membership in
  the other Family no (OMITTED, evidence kept).
- A spouse referenced by two DECEASED heads with no current household:
  Person once, SPOUSE membership in neither (both relationships kept as
  OMITTED evidence).
- Declared statistics become a household declaration (source IMPORT) — never
  fake Persons, never recalculated; المدينة only fills
  `original_residence_text`.
- Source spouse references are evidence, not Family Membership (§17).
- `import.apply` is SUPER_ADMIN-only and exists only while the Apply
  activation gate is open (see "Apply API and activation gate" below).

## Apply planner and Dry Run (IMPLEMENTED — Phase 4B.2, read only)

One pure planner decides, for every staged row, what a future Apply would
do; the Dry Run (Wizard step 6 "المعاينة قبل الاستيراد") only presents that
plan, and the future Apply must execute the SAME plan — there is no second
set of business rules. Planning performs no write of any kind: no registry
row, no BusinessIdentifier reservation (family / person codes are generated
at execution only), no activity, no provenance, no status change.

**Preconditions** (any failure → no plan, nothing repaired automatically):
INITIAL mode; READY_FOR_REVIEW and Apply not started; mapping confirmed;
rows staged; every family key resolved; reconciliation CURRENT; every row
NEW (INITIAL plans NEW rows only); no row linked to a Family; no provenance
for the batch; Clan active; HEAD and SPOUSE relationship types active;
every resolved target Branch selectable.

**Effect intents** (planning state, never stored): CREATE, REUSE, OMIT,
BLOCK. Execution will turn them into provenance CREATED / REUSED / OMITTED;
a BLOCK makes the row non-executable. Each effect carries a stable reason
code when omitted or blocked; warnings are non-blocking evidence.

- **HEAD_PERSON**: the single exact-ID Person without an active membership
  is REUSED (never updated in INITIAL); no match → CREATE with approved
  values only (name, ID, gender, birth date, marital status, mobile, life
  status, death date); a deceased head is created DECEASED directly
  (warning `HOUSEHOLD_HEAD_DECEASED`, the Family stays executable); a
  missing ID is allowed (warning, never matched by name); a soft-deleted,
  ambiguous, linked or identity-mismatched match BLOCKS.
- **FAMILY / HEAD_MEMBERSHIP**: CREATE; Clan from the batch, Branch from the
  resolved key (NO_BRANCH → none), ACTIVE, source IMPORT, registration date
  = execution date.
- **SPOUSE_n_PERSON / SPOUSE_n_MEMBERSHIP** are separate decisions. Spouse
  gender follows this source format: a MALE head's spouse slot is FEMALE, a
  FEMALE head's is MALE; unknown head gender → BLOCK (never guessed). A new
  spouse Person gets name, ID, gender, life status UNKNOWN and marital
  status UNKNOWN only. Membership: OMIT `INDEPENDENT_HOUSEHOLD_HEAD` when the
  spouse heads her own row; OMIT `PERSON_ALREADY_HAS_ACTIVE_MEMBERSHIP` for
  an existing linked Person; for one spouse in several rows, CREATE only with
  the single living head (all others DECEASED) and OMIT
  `HISTORICAL_RELATIONSHIP_NO_ACTIVE_HOUSEHOLD` elsewhere (all heads
  deceased → no membership at all); otherwise CREATE. A slot with a name but
  no ID is OMITTED (`SPOUSE_WITHOUT_NATIONAL_ID`) — never created or matched
  by name.
- **HOUSEHOLD_DECLARATION**: CREATE with the declared values as stated
  (source IMPORT, no declaration date) — never recalculated, no child
  Persons; no values → OMIT. **RESIDENCE**: CREATE with
  `original_residence_text` only; no source city → OMIT (nothing invented).

**Cross-row Person coordination.** One exact National ID is one Person with
exactly ONE owning effect: (1) an existing registry Person is REUSED by every
occurrence; (2) otherwise, if the ID heads a row of the batch, that row's
HEAD_PERSON owns it; (3) otherwise the occurrence that also gets the SPOUSE
membership owns it; (4) otherwise the first occurrence by source row and
slot. The owner CREATES; every other occurrence REUSES it by reference (owner
row + effect). Consequently no membership CREATE ever depends on another row;
only provenance-level REUSE links do, and a future Apply records those once
both sides have executed — so execution order and resume stay safe, even for
mutually referencing polygamous households.

## Apply execution primitives (IMPLEMENTED — Phase 4B.4a; no Apply yet)

- **Approved plan.** `import_batches.apply_plan_fingerprint` (SHA-256 of the
  plan) is present exactly while Apply has started (APPLYING /
  PARTIALLY_APPLIED / APPLIED) and absent before — PostgreSQL CHECK mirrored
  by the model. It covers the row order, every effect's key, intent and
  reason, existing entity and cross-row owner references, Clan, resolved
  Branch, execution values, execution-semantic warnings and the stored
  reconciliation fingerprint; never presentation fields. Computing it
  reserves no identifier and writes nothing.
- **Structured Apply errors.** `apply_error_code` (a stable upper-case code)
  and `apply_error_row_number` (a source row number, only with a code) —
  never exception text or row data.
- **CreateFamilyMembershipAction** attaches an EXISTING Person to an
  EXISTING Family (never creates a Person): required relationship type by
  code, Family and Person locked and re-checked, optional expected Clan, one
  active membership per Person, one active head per Family; the unique
  indexes remain the backstop. RegisterFamilyAction uses it for the head.
- **CreateFamilyResidenceAction** creates a Family's first current residence
  (one current residence per Family; displacement location only when
  DISPLACED; `source` a RegistrationSource). RegisterFamilyAction uses it
  unchanged; the import form is `original_residence_text` + `source` IMPORT
  + the Apply start date, every current-location / displacement field NULL.
  Changing a residence stays UpdateFamilyResidenceAction.
- **Registration date of imported Families** (approved): the DATE of
  `apply_started_at`, identical for every row, chunk and resume.
- **FAMILY_IMPORTED** (approved, not emitted yet): recorded next to
  FAMILY_CREATED by the future Apply; its metadata is exactly
  `import_batch_id` and `source_row_number` (positive integers) — no other
  key is accepted for it, and those keys are refused on every other event.

## Row executor (IMPLEMENTED — Phase 4B.4b; internal only, no runner or endpoint)

`ApplyImportRowAction` executes ONE planned row. **One source row = one
database transaction**: the Persons, Family, memberships, declaration,
residence, activities (FAMILY_CREATED, FAMILY_IMPORTED, the declaration
event), provenance and the row's `APPLIED` + `family_id` commit together;
on any failure nothing of the row remains (PER / FAM sequence values may be
skipped — codes never repeat).

- **Only an approved plan executes.** It accepts an `ApprovedApplyPlan`,
  which can only be built when the plan's fingerprint equals the batch's
  `apply_plan_fingerprint`; inside the transaction it re-checks the batch
  (APPLYING / PARTIALLY_APPLIED, same fingerprint), locks the row, matches
  it to its row plan, refuses any BLOCK and any missing mandatory effect.
- **The planner decides, the executor executes** — through the canonical
  actions (CreatePersonAction, CreateFamilyAction,
  CreateFamilyMembershipAction, RecordHouseholdDeclarationAction,
  CreateFamilyResidenceAction) with the plan's values only; the Apply start
  date is the registration / membership / residence date; a deceased head
  is created DECEASED (FAMILY provenance reason `HOUSEHOLD_HEAD_DECEASED`, no
  death event). A REUSED registry Person is re-checked (exists, not deleted,
  same person code; a HEAD still unlinked) and never updated.
- **Owner-only Persons.** A Person planned in another row is never created
  here. If its owner already ran, the REUSED record is written with the real
  person_id; otherwise the effect stays pending — allowed only when nothing
  in this row writes to that Person (a pending Person backing a membership
  CREATE fails `CROSS_ROW_PERSON_NOT_MATERIALIZED`). When an owner creates a
  Person, its transaction writes the REUSED records of dependent rows that
  are already APPLIED; they roll back with it.
- **Identity evidence.** Every Person REUSE in the plan carries an internal
  keyed fingerprint (HMAC-SHA256 with the application key) of the exact
  National ID the planner matched on; it is covered by the plan fingerprint.
  Before reusing a registry Person, before an immediate cross-row reuse and
  before an owner completes a dependent link, the executor recomputes it from
  the Person's current National ID; a difference fails
  `PERSON_IDENTITY_CHANGED` (no re-matching). The evidence is never
  presented, stored in provenance or activity, logged or put in a message.
- **Provenance** is written next to each effect by
  `RecordImportApplyEffectAction`: an identical existing record is returned,
  anything else is `PROVENANCE_CONFLICT` — never overwritten.
- **Completeness before APPLIED**: every planned effect has its record except
  allowed pending cross-row reuses. An already APPLIED row is verified
  (Family, records, entities) and reported `ALREADY_APPLIED`, or refused
  `ROW_ALREADY_APPLIED_INCONSISTENT` — never repaired.
- **Structured failures** (`ImportApplyExecutionException`: stable code +
  row number, cause kept only for server logs), e.g. ROW_PLAN_MISMATCH,
  ROW_PLAN_BLOCKED, MANDATORY_EFFECT_MISSING, HEAD_PERSON_MISSING,
  HEAD_PERSON_NOW_LINKED, SPOUSE_PERSON_NOW_LINKED, NATIONAL_ID_TAKEN,
  BRANCH_NOT_SELECTABLE, RELATIONSHIP_TYPE_UNAVAILABLE, PROVENANCE_CONFLICT,
  ROW_EFFECT_INCOMPLETE, PERSON_IDENTITY_CHANGED, UNEXPECTED_ERROR. Batch error state and
  PARTIALLY_APPLIED transitions belong to the future runner.

## Apply runner (IMPLEMENTED — Phase 4B.4c; internal only, no endpoint or UI)

One execution path for future HTTP and CLI callers — orchestration only
(the planner decides, the row executor writes):

- **Start** (`StartImportApplyAction`, one short transaction, no row
  executed): requires `import.apply`, READY_FOR_REVIEW with no started
  Apply, the planner's full preconditions and no blocked row; the operator's
  Dry Run fingerprint must equal a fresh plan (`APPLY_PLAN_CHANGED`
  otherwise). Sets APPLYING, `apply_started_at` (the registration date of
  every Family of the run, also after resume), `applied_by` and
  `apply_plan_fingerprint`; clears old errors.
- **Chunk** (`RunImportApplyChunkAction`): one runner per batch
  (`ApplyRunnerLock` — a non-blocking PostgreSQL advisory lock; an
  in-process equivalent for the SQLite tests; always released) or
  `APPLY_IN_PROGRESS`. The approved plan is reconstructed as of Apply start
  and must match; rows run in source order (row number, then id), each in its
  own transaction, until 100 rows / ~10 s, a failure or the end. Stopping
  between chunks is a **normal pause** (APPLYING, no error).
- **Failure**: by database truth — nothing committed → back to
  READY_FOR_REVIEW (approval cleared, the stable error code and row number
  kept); anything committed → PARTIALLY_APPLIED (start and plan kept), no
  later row runs. Never FAILED. Only codes and row numbers are stored; causes
  are logged by class name only.
- **Resume** (PARTIALLY_APPLIED only): same stored plan and start date, never
  re-reconciled; the plan must still match and the applied rows must be
  consistent (an allowed pending cross-row link is fine; a link whose owner
  already ran is not) — then APPLYING through the same chunk path.
- **Completion** (`CompleteImportApplyAction`, batch locked): APPLIED only
  when every planned row is APPLIED with an existing Family, every planned
  effect has exactly one matching record (outcome, entity), nothing is
  unexpected, no cross-row link is pending and the plan still matches. It
  verifies and never writes missing provenance. An APPLIED batch is
  re-verified (`ALREADY_APPLIED`) or refused (`APPLY_INTEGRITY_INVALID`),
  never re-executed or repaired.
- **Progress** comes from the database (rows total / applied / remaining,
  pending links, safe error code and row) — no personal data.

## Apply API and activation gate (IMPLEMENTED — Phase 4B.4d; gate CLOSED)

Thin endpoints around the runner (no completion endpoint: completion is
internal to a chunk). The actions re-check authorization themselves.

```text
POST /api/v1/imports/initial-families/{batch}/apply/start   import.apply   body {plan_fingerprint} only
POST /api/v1/imports/initial-families/{batch}/apply/run     import.apply   one chunk (server-side budget)
POST /api/v1/imports/initial-families/{batch}/apply/resume  import.apply   PARTIALLY_APPLIED → one chunk
GET  /api/v1/imports/initial-families/{batch}/apply         import.review  progress, read only
```

- **Input**: start takes only the reviewed Dry Run `plan_fingerprint`
  (exactly 64 lowercase hex characters, else 422). No row selection, chunk
  size, date or other execution control is accepted from the client; any
  extra body field is ignored.
- **Responses**: `{data: {batch_id (UUID), outcome, status, total_rows,
  applied_rows, remaining_rows, executed_in_chunk, skipped_already_applied,
  pending_links, completed, error_code, error_row_number}}`. A row failure
  inside a chunk is a normal **200** with `outcome` FAILED and the batch's
  safe code/row. The GET progress omits `error_row_number` (and the
  chunk-only fields) — least exposure for reviewers.
- **Refusals** (`{message, code, row_number}`, fixed Arabic message per code):
  409 `APPLY_IN_PROGRESS`, `APPLY_STATE_INVALID`, `APPLY_PLAN_CHANGED`,
  `APPLY_INTEGRITY_INVALID`; 422 `APPLY_PRECONDITIONS_FAILED`,
  `APPLY_PLAN_BLOCKED`; 500 `UNEXPECTED_ERROR` (any other cause, wrapped);
  403 without authorization; 401 unauthenticated.
- **Privacy**: no response or log carries row payloads, names, National IDs,
  phones, the plan fingerprint, identity fingerprints, provenance internals
  or exception messages. A refused/failed request logs only the code, row
  number, batch UUID and the cause's class name; Laravel's default reporting
  (message, trace, previous-exception chain) is replaced.
- **Activation gate**: `config('import.apply_enabled')`
  (`IMPORT_APPLY_ENABLED`, default false). Only while it is true does
  `RolePermissionSeeder` grant `import.apply` — to SUPER_ADMIN only — and do
  the actions accept it (a stale or direct grant cannot run Apply while the
  gate is closed). `famboook:verify-permissions` enforces both modes and
  forbids direct user grants. Activation is an explicit operational step
  (docs/08 §7a), only after the Apply UI phase and a final end-to-end review.

## Step 6 Apply UI (IMPLEMENTED — Phase 4B.4e; gate CLOSED)

The operator flow of the approved design, in Wizard step 6 ("المعاينة
والتطبيق"). Everything shown is persisted backend state; the browser only
drives the chunk loop and the backend decides everything.

- **When Apply is offered**: the Dry Run's `execution_enabled` is derived on
  the server — true only when the plan is READY (every precondition holds,
  including READY_FOR_REVIEW and no started Apply, and no row is blocked)
  AND `ImportApplyGate::allows(user)` (gate open + `import.apply`). Only then
  does Step 6 show "بدء الاستيراد". With the gate closed it is false for
  everyone, SUPER_ADMIN included.
- **Confirmation** (approved wording): «سيتم إنشاء {families} أسرة و{persons}
  أشخاص في السجل اعتمادًا على هذه المعاينة. بعد بدء الاستيراد قد يتم حفظ
  البيانات على دفعات، ولا يتوفر تراجع تلقائي عن السجلات التي تم إنشاؤها. هل
  تريد بدء الاستيراد؟» — {families} = Families the plan CREATES, {persons} =
  Persons the plan CREATES (reused Persons excluded); always derived, never
  fixed. Confirming sends only that Dry Run's `plan_fingerprint`, held in
  memory (never stored in browser storage, the URL or the page).
- **Refused start**: `APPLY_PLAN_CHANGED`, `APPLY_PRECONDITIONS_FAILED` or
  `APPLY_PLAN_BLOCKED` → nothing started; the same Dry Run cannot be
  confirmed again — the operator must run a fresh Dry Run. Never retried
  automatically.
- **Chunk loop**: after start the page sends `apply/run` while the outcome is
  PAUSED; COMPLETED, ALREADY_APPLIED, FAILED (a 200 with the persisted error)
  or any refusal ends it. Chunk size and time budget stay server-side.
- **Pause**: stops issuing further chunk requests; a chunk already running
  is not cancelled and finishes first (stated in the UI). Closing or leaving
  the page has the same effect. Reopening rebuilds the screen from the
  backend; "متابعة التطبيق" continues an APPLYING batch.
- **Another session** (`APPLY_IN_PROGRESS`): the page stops sending
  mutations, says Apply is active in another session and keeps polling the
  read-only progress; it never retries the mutation by itself.
- **Outcomes**: APPLIED (verified complete); PARTIALLY_APPLIED (Arabic
  label for the stored code, the row number when the page received it, and
  "استئناف التطبيق" — resume re-verifies the approved plan first); a stop
  before any saved row returns to the Dry Run with the persisted code shown.
  Known codes have Arabic labels; the stable code stays visible for
  diagnosis. No names, National IDs, payloads, SQL or fingerprints appear.
- **After Apply starts**: reopening a batch opens Step 6; Steps 4–5 are read
  only (no decision or reconciliation controls) and Steps 1–3 are closed.

## Freshness during Apply (APPROVED rule; primitive IMPLEMENTED)

Before Apply starts, the normal rule applies: reconciliation must be CURRENT
and the Dry Run plan is what gets approved. Once Apply starts, Apply's own
writes move the registry markers and a started batch cannot be re-reconciled,
so freshness becomes **plan equality**: the SAME planner runs in its
**as-of-Apply-start** context — the Persons and memberships this batch's
provenance proves it CREATED are treated as its own expected changes and
ignored; every other registry fact (other Persons with the batch's IDs,
active memberships, Branch selectability, relationship types, the Clan) is
read live — and the resulting fingerprint must equal
`apply_plan_fingerprint` (`ImportApplyPlanner::matchesApprovedPlan`). Any
external change that alters a planning decision changes the fingerprint and
blocks resume; changes that alter no decision do not. Normal Dry Run
planning and reconciliation freshness are unchanged.

Owner-only Person creation (approved): a REUSED provenance record is written
only when the real person_id is known — by the reusing row if the owner
already ran, otherwise by the owner's transaction afterwards. The final
completion pass VERIFIES completeness; it never invents missing provenance,
and a batch cannot become APPLIED while an expected effect lacks its record.

---

# 97. Audit

Critical operations must be audited.

Audit should capture relevant:

```text
Actor
Action
Resource
Timestamp
Previous State
New State
Context
```

according to the audit architecture.

---

# 97a. Family Activity Log (V1)

Approved 2026-09-24.

The Family Activity Log is a family-scoped, read-only timeline of
important successful operations. It answers: what happened, to what, who
did it and when. It is **not** the full audit of §97: it stores no
previous/new values, no snapshots and no restore/rollback information.

Rules:

- Activity is **system-generated**. Users cannot create, edit or delete
  activity events. No application exposes activity write endpoints.
- Activity is **immutable** (append-only).
- Events are written by Domain Actions, **after** the business change and
  **inside the same transaction**. If the operation fails or rolls back,
  no activity remains. A save that changes nothing records no event.
- Only business operations are logged; reads (GET/view), navigation and
  arbitrary technical model saves are not.
- The **actor** is the authenticated application user who performed the
  operation. It is nullable for future system/import operations. The
  actor is **not** automatically the field researcher or data collector;
  researcher/collection metadata remains deferred (docs/02 PDD-025).
- **No fabricated history.** Records that existed before the Activity Log
  was enabled receive no backfilled events and no guessed actors. Their
  timeline starts with the first operation after activation.
- **Metadata is allow-listed.** V1 allows only the broad health record
  category (`health_record_type`). Never stored: National ID, passwords
  or tokens, phone numbers, health details, disease names, disability
  types or details, pregnancy/breastfeeding notes, previous/new values,
  request payloads or serialized models.
- Person names are resolved at read time from the referenced Person, not
  copied into the activity record.

V1 events:

```text
FAMILY_CREATED          family registration
FAMILY_IMPORTED         Family created from an import row (with FAMILY_CREATED;
                        metadata import_batch_id + source_row_number only)
FAMILY_UPDATED          family registration-metadata correction
FAMILY_MEMBER_ADDED     new member (Person + membership)
PERSON_UPDATED          basic Person data correction (current family)
MEMBERSHIP_RELATIONSHIP_CORRECTED  member relationship corrected (§93b)
MEMBERSHIP_ENDED        incorrect membership ended; Person kept (§93b)
NATIONAL_ID_CORRECTED   administrative National ID correction (§93b)
PERSON_DEATH_RECORDED   official death recorded (§30); subject = Person;
                        metadata: verification_method (a code)
PERSON_ALIVE_CONFIRMED  UNKNOWN → ALIVE confirmed (§30); subject = Person;
                        metadata: verification_method (a code)
HOUSEHOLD_DECLARATION_RECORDED  Declared Household Statistics recorded (§55c); subject = Family
RESIDENCE_UPDATED       current-address correction
DISPLACEMENT_UPDATED    displacement-field correction
HEALTH_RECORD_CREATED
HEALTH_RECORD_UPDATED
HEALTH_RECORD_CLOSED
ASSESSMENT_CREATED      new draft assessment (§40a)
ASSESSMENT_UPDATED      draft assessment saved with changes
ASSESSMENT_COMPLETED    assessment completed
NEED_CREATED            new Need (§46a)
NEED_UPDATED            open Need edited with changes
NEED_FULFILLED          Need resolved as fulfilled
NEED_CLOSED             Need closed with a reason
ASSISTANCE_NOMINEE_ADDED    family/person nominated for an Assistance (§47c)
ASSISTANCE_NOMINEE_REMOVED  nomination withdrawn (history kept)
ASSISTANCE_BENEFICIARY_APPROVED   nomination approved (§47d)
ASSISTANCE_BENEFICIARY_REJECTED   nomination rejected
ASSISTANCE_DELIVERED              INTERNAL verified delivery (§47e)
ASSISTANCE_NOT_DELIVERED          INTERNAL final non-delivery
ASSISTANCE_DELIVERY_REVERSED      INTERNAL delivery reversed
ASSISTANCE_BENEFICIARY_LISTED     included in an issued EXTERNAL list (§47h) — not a delivery
```

V1-B events carry no metadata: never National IDs, mobiles, health
details, list values, or rejection / non-delivery / reversal reasons.

Nomination events are recorded on the nominated family's timeline with no
metadata: never targeting criteria, matching reasons, health conditions,
pregnancy, disability details, need descriptions or assessment content.
The Assistance title and nominated person are resolved at read time. They
are shown only to holders of `assistance.view`. Targeting previews and
program-level edits create no family activity.

Need events carry no metadata: never the description, closure reason,
quantity, person medical information or assessment content. The Need
title and target person are resolved at read time from the current
record. Need events are shown only to holders of `need.view`.

Assessment events are recorded once per operation, never per domain
rating, and carry no metadata (no ratings, no notes). They are shown only
to holders of `assessment.view`.

Residence and displacement corrections share one Domain Action. The event
follows which field group changed: address fields → RESIDENCE_UPDATED;
`original_residence_text`, `displacement_status`,
`displacement_location_text` → DISPLACEMENT_UPDATED. A correction touching
both records both. No values are compared or stored beyond this check.

PERSON_UPDATED is recorded on the Person's current (active-membership)
Family. A Person without an active membership has no family timeline.

Out of scope for V1: field-by-field history, snapshots, restore, manual
timeline notes, approval workflow, signatures, login history, view audit,
export, and Family Portal access.

---

# 98. Workflow Events

Workflow events represent process state transitions.

They are separate from audit logs.

Example:

```text
SUBMITTED → UNDER_REVIEW
```

is a workflow event.

Changing a mobile number from one value to another is a data audit event.

An operation may generate both.

---

# 99. Historical Integrity

Historical records must not be deleted simply because they are no longer current.

Examples:

```text
Old Membership
Old Residence
Previous Employment
Workflow Events
```

---

# 100. Hard Deletion

Hard deletion of canonical registry records should be exceptional.

Normal lifecycle changes use:

```text
Status
End Date
Archive
Soft Delete where appropriate
```

according to entity semantics.

---

# 101. Concurrency

Critical operations must protect against concurrent conflicting changes.

Examples:

```text
Two Household Head changes

Two Family transfers

Two Change Request applications

Two current Residence creations
```

Transactions and database locks should be used where required.

---

# 102. Stale State

An operation must not assume that the registry state at screen-load time is still current at submission time.

Critical Domain Actions must revalidate current state.

---

# 103. Domain Action Principle

Important business operations must be implemented as reusable Domain Actions.

Examples:

```text
CreateFamilyAction
CreatePersonAction
AddFamilyMemberAction
TransferFamilyMemberAction
ChangeHouseholdHeadAction
ChangeFamilyResidenceAction
RecordPersonDeathAction
SubmitChangeRequestAction
ApproveChangeRequestAction
RejectChangeRequestAction
ApplyChangeRequestAction
```

---

# 104. Domain Action Responsibilities

A Domain Action may be responsible for:

```text
Authorization context
Business validation
Current-state validation
Concurrency protection
Transaction boundaries
Canonical mutation
Audit
Workflow events
Domain events
```

depending on the operation.

---

# 105. Shared Domain Rules

All authorized entry points must reuse the same business logic.

Correct:

```text
Next.js
   ↓
Laravel API
   ↓
ChangeHouseholdHeadAction
```

and:

```text
Filament
   ↓
ChangeHouseholdHeadAction
```

Incorrect:

```text
Next.js → Business Logic A

Filament → Business Logic B
```

---

# 106. Filament Business Boundary

Filament is a System / High Administration interface.

Filament does not receive permission to bypass:

```text
Domain Rules
Authorization
Transactions
Audit
Workflow
Database Integrity
```

because it is an administrative interface.

Administrative UI does not mean unrestricted database mutation.

---

# 107. Next.js Business Boundary

Next.js is the primary presentation layer.

It may provide:

```text
Form Validation
Conditional UI
Permission-aware Navigation
Loading States
Confirmation Dialogs
Friendly Error Messages
```

but it is not the authoritative business-rule layer.

---

# 108. Frontend Validation

Frontend Zod validation exists for user experience.

Example:

```text
Required Name
Valid Date Format
Required Relationship
```

All submitted data must still be validated by Laravel.

Therefore:

```text
Zod validation
≠
Business authorization
```

---

# 109. Frontend Permissions

The frontend may hide unavailable actions.

Example:

```text
User lacks family.create
→ Hide Create Family button
```

This is UX behavior only.

Laravel must independently reject unauthorized API requests.

---

# 110. API Boundary

The Laravel API is not an unrestricted database gateway.

Endpoints must expose approved business capabilities.

The system must avoid generic endpoints that allow clients to arbitrarily mutate protected domain fields.

---

# 111. API Resource Exposure

Authorization to access a resource does not automatically authorize every field.

Laravel API Resources or equivalent response objects must control exposure.

Examples:

```text
Staff Person Detail
        ≠
Family Portal Person Detail
```

---

# 112. Model Serialization

Laravel Models containing sensitive information must not be automatically returned as unrestricted API responses.

Explicit representations are required.

---

# 113. PostgreSQL Boundary

Next.js must never connect directly to PostgreSQL.

Filament and Laravel use the same authoritative Laravel/domain environment.

Database credentials must remain server-side.

---

# 114. Database Constraints

Where a business invariant can safely be represented at database level, PostgreSQL constraints/indexes should provide an additional protection layer.

Examples:

```text
One active Household Head per Family

One active primary membership per Person

One current residence per Family
```

Business logic must still provide meaningful validation and errors.

---

# 115. Private File Boundary

Next.js must not expose private storage paths directly.

File access must flow through authorized Laravel mechanisms.

---

# 116. Authentication

Primary first-party web authentication uses Laravel Sanctum with secure cookie/session-based authentication.

Authentication tokens for the primary web application must not be stored in browser localStorage.

V1 Staff authentication (2026-09-26, docs/06 §59c, AUTH-ADR-057):

```text
Login        active user with one Staff role; generic failure otherwise
             (wrong password / unknown email / inactive / non-Staff alike);
             rate limited per email + IP and per IP; session regenerated
Logout       session invalidated, CSRF token regenerated
Inactive     cannot log in; an existing session is ended on its next request
             (Staff Portal and Filament)
Staff user   exactly one Staff role; FAMILY_USER is never a Staff Portal user
Filament     Staff user administration only, for active holders of
             system-admin.access; users are deactivated, never deleted
Seeding      reference data only — never a user account or known password
Dev login    /dev-login only when APP_ENV=local
```

---

# 117. Session Authorization

A valid authenticated session does not automatically authorize a resource.

Every protected operation still requires authorization.

Conceptually:

```text
Authenticated
≠
Authorized
```

---

# 118. Server-Side Object Authorization

Every protected Family, Person, Change Request, Document, Assessment, Need, Assistance, or other object request must perform server-side authorization.

Client-provided IDs must never be trusted as authorization proof.

---

# 119. Field-Level Authorization

Sensitive fields may be:

```text
FULL
MASKED
HIDDEN
```

depending on actor/context.

Example:

```text
Staff with permission
→ Full National ID

Limited Staff
→ Masked National ID

Unauthorized Family Member
→ Hidden
```

---

# 120. Sensitive Logging

Sensitive information must not be unnecessarily written into:

```text
Application Logs
Error Logs
Queue Logs
Notification Payloads
Analytics
```

Logging should preserve diagnostic value while minimizing exposure.

---

# 121. Real Data in Source Control

Real Family data must never be committed to Git.

This includes:

```text
National IDs
Real Documents
Paper Form Scans
Production Database Dumps
Real Health Data
Secrets
Passwords
API Keys
```

---

# 122. Seed Data

Development seeders should use:

```text
Synthetic
Fictional
Non-sensitive
```

data.

---

# 123. Business Rule Enforcement Layers

The preferred enforcement model is:

```text
UX Guidance
     ↓
Frontend Validation
     ↓
Laravel Request Validation
     ↓
Authorization
     ↓
Domain Rules
     ↓
Transaction
     ↓
PostgreSQL Constraints
```

Not every rule belongs at every layer.

Critical invariants should have defense in depth where practical.

---

# 124. Business Invariants

```text
INV-001
A Person exists independently from Family membership.

INV-002
Family Membership is the canonical Family-Person relationship.

INV-003
A Person has at most one active primary Family membership in V1.

INV-004
A Family has at most one active Household Head in V1.

INV-005
Membership history is preserved.

INV-006
Household Head changes are controlled domain operations.

INV-007
National ID is sensitive text data.

INV-008
Unknown values are not replaced with fake placeholders.

INV-009
Duplicate Persons are never automatically merged.

INV-010
Birth dates cannot be in the future.

INV-011
Age is derived from birth_date.

INV-012
Death date cannot precede birth date.

INV-013
Unknown exact death dates are never invented.

INV-014
Residence history is preserved.

INV-015
Sensitive health/disability information requires explicit authorization.

INV-016
Uploaded documents are not automatically verified.

INV-017
Need and Assistance are separate concepts.

INV-018
User and Person identities are separate.

INV-019
Family User access requires a verified User-Person relationship.

INV-020
Family User role alone does not authorize a Family.

INV-021
Family User submissions do not directly overwrite canonical data.

INV-022
APPROVED and APPLIED are distinct Change Request states.

INV-023
Change Request application uses controlled Domain Actions.

INV-024
APPLIED requests cannot be applied again.

INV-025
Workflow Events and Audit are separate concepts.

INV-026
Family Users cannot access confidential Staff notes.

INV-027
Family membership does not imply access to all sensitive member data.

INV-028
Critical operations must revalidate current state.

INV-029
Canonical history must not be destroyed by normal state changes.

INV-030
Family User input is untrusted until validated and authorized.

INV-031
Laravel is the authoritative business-rule layer.

INV-032
Next.js cannot authorize canonical mutations independently.

INV-033
Filament cannot bypass Domain Actions or business rules.

INV-034
All authorized interfaces reuse the same domain rules.

INV-035
PostgreSQL is the canonical persistent data store.

INV-036
Frontend applications never directly access PostgreSQL.

INV-037
Frontend validation never replaces Laravel validation.

INV-038
API access to an object does not imply access to every field.

INV-039
Sensitive documents are private by default.

INV-040
Authentication does not imply authorization.

INV-041
Canonical mutations must occur only through authorized backend operations.

INV-042
Database constraints complement rather than replace Domain Rules.

INV-043
Real Family data and secrets must not enter source control.

INV-044
Notification failure must not undo committed business state.

INV-045
Administrative interfaces remain subject to authorization and audit.
```

---

# 125. Approved Business Decisions

### BD-001
Family is a persistent entity independent from its current members.

### BD-002
Person is a persistent entity independent from Family membership.

### BD-003
Family Membership is the canonical Family-Person relationship.

### BD-004
V1 supports one active primary Family membership per Person.

### BD-005
V1 supports one active Household Head per Family.

### BD-006
Household Head is membership state rather than permanent Person identity.

### BD-007
Membership history is preserved.

### BD-008
Person Relationships are separate from Family Membership.

### BD-009
National ID is stored as text.

### BD-010
Unknown identity values are not replaced with fake placeholders.

### BD-011
Duplicate Persons are never automatically merged.

### BD-012
Birth Date is canonical; age is derived.

### BD-013
Residence is historical.

### BD-014
Health and Disability are restricted Person-level domains.

### BD-015
Assessments represent point-in-time information.

### BD-016
Need and Assistance are separate.

### BD-017
Document upload and verification are separate operations.

### BD-018
User identity and Person identity are separate.

### BD-019
Family Portal identity uses explicit User-Person Links.

### BD-020
FAMILY_USER is independent from Household Head domain status.

### BD-021
V1 Family Portal eligibility defaults to verified current Household Head.

### BD-022
Family User substantive changes use Change Requests.

### BD-023
Family User submissions do not directly modify canonical registry data.

### BD-024
APPROVED and APPLIED are distinct.

### BD-025
Change Request application invokes Domain Actions.

### BD-026
Family User uploaded supporting documents remain unverified until Staff verification.

### BD-027
Anonymous public registration is out of V1 scope.

### BD-028
Family User access is reevaluated after relevant membership/head/life-status changes.

### BD-029
Workflow Events are separate from audit logs.

### BD-030
Critical Change Request application is transactional and idempotent.

### BD-031
Death reports require review before canonical life-status change.

### BD-032
Family User access to sensitive member information is deny-by-default until explicitly permitted.

### BD-033
Exports require explicit authorization.

### BD-034
Imports must not bypass business rules.

### BD-035
Real data must not be stored in source control.

### BD-036
`persons.death_date` is the optional canonical exact death date.

### BD-037
PostgreSQL 16+ is the canonical database.

### BD-038
Laravel 12 is the authoritative business and domain layer.

### BD-039
The primary Famboook product communicates with Laravel through a versioned API.

### BD-040
Next.js is a presentation/client layer and does not own canonical business rules.

### BD-041
Frontend Zod validation supplements but never replaces Laravel validation.

### BD-042
Laravel Policies and permission rules provide authoritative server-side authorization.

### BD-043
Important business operations are implemented as reusable Domain Actions.

### BD-044
Next.js and Filament reuse the same Domain Actions.

### BD-045
Filament is restricted to System / High Administration and receives no business-rule bypass.

### BD-046
Laravel API Resources control context-specific data exposure.

### BD-047
The primary first-party web application uses Laravel Sanctum cookie/session authentication.

### BD-048
Primary authentication secrets are not stored in browser localStorage.

### BD-049
Sensitive files are served only through authorized private-storage mechanisms.

### BD-050
Notification delivery occurs outside the canonical transaction where appropriate and cannot invalidate a committed business operation.

### BD-051
Family Profile Review is the household head's review of the family data; it is separate from Account Verification and from Staff Family Verification, and a confirmation never changes canonical data or grants VERIFIED (docs/11 FP-ADR-057).

### BD-052
No review or verification state is an eligibility gate: programmes keep their own required data and criteria (docs/11 FP-ADR-058). Registered Members are ACTIVE memberships whatever the life status; Living Members name the targeting / health population (docs/11 FP-ADR-056).

---

# 126. Pending Business Decisions

```text
PBD-001
Exact National ID normalization rules.

PBD-002
Whether National ID becomes unique after data-cleaning policy is proven.

PBD-003
Exact duplicate scoring algorithm.

PBD-004
Future Person merge workflow.

PBD-005
Exact Family archive/reactivation rules.

PBD-006
Exact Household Head eligibility rules.

PBD-007
Whether marriage requires a dedicated historical Marriage entity.

PBD-008
Exact transfer effective-date rules.

PBD-009
Exact Family User account activation method.

PBD-010
Exact identity-verification method.

PBD-011
Whether multiple Family Users may represent one Family.

PBD-012
Guardian / Authorized Representative rules.

PBD-013
Whether selected LOW-risk Family updates may eventually be applied without manual review.

PBD-014
Exact Family Portal health-data visibility.

PBD-015
Exact Family Portal disability-data visibility.

PBD-016
Exact Needs visibility for Family Users.

PBD-017
Exact Assistance visibility for Family Users.

PBD-018
Exact supporting-document requirements by Change Request type.

PBD-019
Exact Change Request risk classifications.

PBD-020
Whether APPLICATION_FAILED becomes a formal workflow state.

PBD-021
Exact Staff maker-checker separation by operation.

PBD-022
Exact export approval requirements.

PBD-023
Exact data retention and deletion rules.

PBD-024
Exact account recovery process.

PBD-025
Exact Staff/System Administrator 2FA policy.

PBD-026
Exact re-authentication requirements for high-risk actions.

PBD-027
Exact session timeout rules.

PBD-028
Exact login identifier policy.
Decided 2026-10-02: Family Users log in with National ID (strict nine-digit normalization, dedicated authentication identity — §89b); Staff with email.

PBD-029
Exact low-bandwidth/offline behavior permitted for sensitive data.

PBD-030
Whether specific high-risk operations require two separate approvers.
```

---

# 127. Domain Operation Pattern

Important canonical operations should follow:

```text
Request
   ↓
Authentication
   ↓
Authorization
   ↓
Input Validation
   ↓
Current-State Validation
   ↓
Domain Action
   ↓
Transaction / Lock where required
   ↓
Canonical Mutation
   ↓
Audit
   ↓
Workflow / Domain Event
   ↓
Commit
   ↓
After-Commit Notification
   ↓
Controlled API Response
```

---

# 128. Interface Independence

Business rules must remain valid if the presentation technology changes.

For example, replacing:

```text
Next.js
```

with another future client must not require rewriting:

```text
Household Head rules
Membership rules
Death rules
Change Request rules
Authorization rules
```

This is a core architectural requirement.

---

# 129. Final Business Rule Principle

Famboook follows:

```text
User Intent
    ↓
Authorized Interface
    ↓
Laravel
    ↓
Domain Rule
    ↓
Controlled Action
    ↓
PostgreSQL
```

not:

```text
UI Form
    ↓
Direct Table Update
```

and not:

```text
Admin Access
    ↓
Bypass Business Rules
```

The registry must remain trustworthy regardless of which authorized interface initiates an operation.

---

# 130. Document Status

```text
Project: Famboook
Document: Business Rules
Version: 1.2.8
Status: APPROVED
Date: 2026-09-24
```

---

# 131. Change Log

| Version | Date | Status | Description |
|---|---|---|---|
| 1.0 | 2026-09-22 | Superseded | Initial Business Rules |
| 1.1 | 2026-09-22 | Superseded | Added Family Portal, User-Person Links, Change Requests, death-date rules, controlled self-service, workflow/application rules and security invariants |
| 1.2 | 2026-09-22 | Approved | Established Laravel as authoritative domain layer, PostgreSQL as canonical persistence, shared Domain Actions across Next.js and Filament, API/data-exposure boundaries, frontend validation limits, private-file rules, Sanctum authentication boundary and additional defense-in-depth invariants |
| 1.2.39 | 2026-10-02 | Approved | PWA-1E: §89b mobile trust rules as implemented (current-number invariant, grant/revoke, STALE on change, change-back never restores, no head requirement), OTP resend and grant semantics, delivery and throttle ceilings |
| 1.2.40 | 2026-10-02 | Approved | PWA-1F: §89b activation as implemented (lookup, anti-enumeration with decoy references, completion transaction, always a new family-side User, session rules, display name from the Person, account is not access, request ceilings and response floor, activation gate) |
| 1.2.41 | 2026-10-03 | Approved | PWA-1G: §89b login and password reset as implemented (identity lookup, full Family context required, one generic login failure, two-tier lockout, reset eligibility and decoys, purpose-bound references, reset transaction ending all sessions, 72-byte password ceiling, three independent gates) |
| 1.2.42 | 2026-10-03 | Approved | PWA-1H: §89b coordinator rules as implemented (family-side never Staff, four conditions for Coordinator Space, union of scopes, current hierarchy, fail-closed structure, summary-only data, administration by SUPER_ADMIN and ADMINISTRATOR, assist withheld) |
| 1.2.43 | 2026-10-03 | Approved | TweetsMS: §89b SMS delivery as implemented (provider, `05XXXXXXXX` as stored, success only on code 999, fixed one-part OTP text, after-response delivery without a queue, no automatic retry, failures recorded and never shown); OTP delivery timing and the Production gate updated |
| 1.2.44 | 2026-10-03 | Approved | PWA-1I: §89b hardening as implemented — decoy parity under parallel requests and at the IP / global ceilings (destination not mirrorable), one response floor for all four OTP steps, attempts counted before the work, invisible-character removal for pasted identifiers, readiness check |
| 1.2.45 | 2026-10-04 | Approved | First self-activation (FP-ADR-053): masked registered number confirmation, code only to the stored number, SELF_OTP trust only after a correct code, Staff-revoked numbers excluded, password reset unchanged |
| 1.2.46 | 2026-10-04 | Approved | FP-ADR-054 after the Production pilot: an input that cannot start first activation gets one generic refusal on step 1 instead of a fake masked number and decoy code step; reasons stay server-side; nothing created |
| 1.2.47 | 2026-10-04 | Approved | Documentation consolidation: "Verified is not beneficiary" replaced by No automatic eligibility; Profile Completion / Family Verification section replaced by Family Profile Review and Staff Family Verification; §55 / §55c Registered Members and Living Members (BD-051, BD-052; docs/11 FP-ADR-056 … FP-ADR-058) |
| 1.2.48 | 2026-10-04 | Approved | §30: RecordPersonDeathAction is the only path that changes an EXISTING Person to DECEASED (import creation may create UNKNOWN / DECEASED); UNKNOWN → ALIVE through ConfirmPersonAliveAction only; §97a PERSON_ALIVE_CONFIRMED (docs/11 FP-ADR-060) |
| 1.2.49 | 2026-10-05 | Approved | FU-10 (docs/11 FP-ADR-061): §30 Staff death recording with an explicit death date and a required verification method, `PERSON_ALREADY_DECEASED`, death irreversible in V1, head death allowed without succession, the import is not a caller (also §96a); §31 DEATH_REPORT direction; §55c stale-write protection and Staff declaration endpoint (`family.update`, Staff sources only); §97a PERSON_DEATH_RECORDED metadata |
| 1.2.50 | 2026-10-07 | Approved | PWA-8.2 (docs/11 FP-ADR-070): «Card and QR» amended — Family-subject Digital Family Card, head change changes nothing, three distinct identifiers, ACTIVE → REVOKED with reissue as a new credential, live Family validity |
| 1.2.38 | 2026-10-02 | Approved | PWA-1D hardening: §89b — the Staff API requires a Staff-side account; role-less and custom-role accounts are refused too |
| 1.2.37 | 2026-10-02 | Approved | PWA-1D: §89b status (resolver, link lifecycle, correction and death effects, account sides implemented); separation of account, link and authentication-identity state; ended link terminal and never deactivates the account; Staff API boundary |
| 1.2.36 | 2026-10-02 | Approved | PWA-1C: §89b status note — foundation implemented (schema, strict normalizers, keyed fingerprints, role and permission names); no §89b rule is enforced by behaviour yet |
| 1.2.35 | 2026-10-02 | Approved | PWA-1B: §89b Family Portal Identity and Access Rules (eligibility with ALIVE only, National ID rule and correction effect, disjoint Staff/family accounts, coordinator must be an eligible head, mobile trust, OTP and password policy, audit retention, Production gates); PBD-028 decided. Documentation only |
| 1.2.34 | 2026-10-02 | Approved | PWA-0: §89a Family Portal Program Rules (server-resolved Family access, National ID + OTP activation, mobile trust, multi-role, Profile Completion, Family Verification, VERIFIED ≠ assistance, submissions, card/QR, announcements, audit); §59 amended — activation is the approved system verification process; PBD-028 decided in direction. Documentation only |
| 1.2.33 | 2026-10-02 | Approved | §55a: the Operational Dashboard shows the Declared Household Population (sum of current declared household sizes in scope) as a separate KPI beside Current People; no figure derived from another |
| 1.2.32 | 2026-10-01 | Approved | §55: the Family list and profile show the declared household statistics beside the registered `member_count` (labelled "المعلن"); nothing derived or reconciled |
| 1.2.31 | 2026-10-01 | Approved | §96a INITIAL import: one explicit action resolves every unresolved family key from the workbook — exact existing Branch name → MATCH_EXISTING_BRANCH, otherwise a new ungrouped Branch; canonical permanent Branch code `BR_` + reserved id; formula keys included; missing keys stay blocking; re-run is a no-op; Step 4 Branches persist independently of the batch |
| 1.2.30 | 2026-10-01 | Approved | §96b Step 6 Apply UI (Phase 4B.4e): server-derived Dry Run `execution_enabled` (gate + permission + READY plan), approved confirmation wording with derived counts, browser-driven chunk loop with pause / continue / resume, APPLY_IN_PROGRESS read-only behaviour, fresh Dry Run required after a refused start, Steps 4–5 read only after Apply starts; §96a Step 6 reachable after Apply starts; gate still closed |
| 1.2.29 | 2026-10-01 | Approved | §96b Apply API and activation gate (Phase 4B.4d): start / run / resume (`import.apply`) and read-only progress (`import.review`), fixed-message error mapping (409/422/500, in-chunk failure = 200 FAILED), safe logging; Apply gated by `IMPORT_APPLY_ENABLED` (default off, SUPER_ADMIN only) |
| 1.2.28 | 2026-09-30 | Approved | §96b Apply runner (Phase 4B.4c): start against the operator's Dry Run fingerprint, one runner per batch (advisory lock), chunks of 100 rows / ~10 s with row transactions and normal pause, failure by database truth (READY_FOR_REVIEW before any committed row, PARTIALLY_APPLIED after), resume with the same plan and start date, database-backed completion that verifies and never invents provenance; no endpoint or UI |
| 1.2.27 | 2026-09-30 | Approved | §96b Row executor (Phase 4B.4b): one source row = one transaction, approved-plan-only execution through the canonical actions, Apply start date, owner-only Persons with pending cross-row REUSE and owner-side link completion, identical-or-conflict provenance writer, already-applied verification without repair, keyed National ID identity evidence on every Person REUSE (PERSON_IDENTITY_CHANGED), structured execution failure codes; no runner or endpoint |
| 1.2.26 | 2026-09-30 | Approved | §96b Apply execution primitives (Phase 4B.4a): apply_plan_fingerprint and structured apply error fields, CreateFamilyMembershipAction and CreateFamilyResidenceAction (used by registration), registration date = Apply start date, approved plan-equality resume freshness via the planner's as-of-Apply-start context, owner-only Person creation with verified (never invented) provenance; §97a FAMILY_IMPORTED (import_batch_id, source_row_number only; not emitted yet) |
| 1.2.25 | 2026-09-30 | Approved | §96b Apply planner and read-only Dry Run (Phase 4B.2): preconditions, CREATE / REUSE / OMIT / BLOCK intents with reason codes, head / family / membership / declaration / residence rules, source-specific spouse gender, spouse Person ≠ spouse membership, one owning effect per exact National ID (cross-row coordination), no identifier reservation or writes during planning |
| 1.2.24 | 2026-09-29 | Approved | Added §96b Import Apply foundation (Phase 4B.1): Apply lifecycle with PARTIALLY_APPLIED and apply_started_at (a started Apply is never FAILED, so the file stays checksum-protected), append-only import_apply_records provenance, canonical Person creation with explicit ALIVE / DECEASED / UNKNOWN (created-deceased ≠ recorded death), canonical Family creation, strict HEAD/SPOUSE lookup (registration now requires an active HEAD type); approved PLANNED Apply contract (deceased head stays HEAD with HOUSEHOLD_HEAD_DECEASED evidence, female-head spouse slot = male spouse for this importer, spouse Person ≠ spouse membership, two-deceased-heads spouse gets no membership, resume freshness algorithm, import.apply SUPER_ADMIN-only when enabled, INITIAL before INCREMENTAL); Apply itself not implemented |
| 1.2.23 | 2026-09-29 | Approved | §96a polygamous-household correction: wife-slot position is source evidence only and never decides Family membership; the cross-role is accepted for any wife slot (including the last) when the husband is MALE, ALIVE and "متعدد الزوجات" and both rows reference each other by exact National ID with no other review reason; deceased husband → not this rule; unknown life status, ordinary married, missing reciprocal → REVIEW_REQUIRED; future-Apply one-active-membership constraint recorded |
| 1.2.22 | 2026-09-29 | Approved | §96a polygamous-household rule: wife-slot order is marriage order (last non-empty slot = latest wife, stays with the male head); an earlier wife of a "متعدد الزوجات" head heading her own row is accepted (informational) when linked by exact ID and neither row has another review reason; latest-wife HEAD ↔ SPOUSE, ordinary married and unknown statuses stay REVIEW_REQUIRED; no inference from row order or from several women naming one man |
| 1.2.21 | 2026-09-29 | Approved | §96a spouse-reuse correction: a spouse National ID repeated across household rows is accepted automatically only when every other head of the group is DECEASED (widow remarriage); two or more living heads or an unknown head status → REVIEW_REQUIRED; group evaluation, no row-order chronology, one Person candidate per exact ID |
| 1.2.20 | 2026-09-29 | Approved | §96a record reconciliation implemented (Phase 3): reconciliation ≠ Apply; Person (exact National ID) ≠ Family (active household-head membership in the batch's Clan) matching; spouses separate; no name-only dedup; family key / Branch are not household identity; NEW / UNCHANGED / CHANGED / DUPLICATE_IN_FILE / CONFLICT / REVIEW_REQUIRED with precedence; field differences; STALE fingerprint; non-destructive incremental imports; Step 6 gated on a CURRENT reconciliation |
| 1.2.19 | 2026-09-29 | Approved | §96a family-key resolution implemented (Phase 2B): source key ≠ Branch; one decision per batch + exact key (MATCH_EXISTING_BRANCH / CREATE_NEW_BRANCH ungrouped / SAME_BRANCH_AS_KEY / NO_BRANCH); UNRESOLVED ≠ NO_BRANCH; immutable source key; explicit clan.manage Branch creation (single and reviewed bulk); suggestions never auto-saved; Step 5 requires all keys resolved; created Branches never deleted on re-decision; no cross-batch copying |
| 1.2.18 | 2026-09-29 | Approved | §96a rewritten as the Import Wizard: six steps (Clan + mode, workbook, column mapping, family keys, review, apply shell), explicit INITIAL / INCREMENTAL modes, upload ≠ staging (staging on mapping confirmation), canonical fields and required/optional mapping, READY / NEEDS REVIEW / REJECTED definitions, duplicate file vs existing record vs duplicate row, non-destructive incremental imports, National ID as identity candidate only, Person ≠ Family reconciliation, reserved reconciliation statuses; IMPLEMENTED vs FUTURE marked |
| 1.2.17 | 2026-09-29 | Approved | §96a Initial Family Import Phase 2A implemented (mandatory target Clan, explicit `المفتاح` key never inferred from names, female/widowed heads valid, formula keys flagged, discovery only, one live batch per file and Clan); Phase 2B family-key review approved (not implemented); name-suffix branch matching withdrawn |
| 1.2.16 | 2026-09-29 | Approved | §7a: Branch Group is an optional organizational classification (CB-4 selectability for ungrouped Branches, CB-7, CB-8 assign/move/remove within the same Clan); §96a: import never depends on Branch Groups, `مفتاح العائلة` noted as an open Phase 2 decision |
| 1.2.15 | 2026-09-29 | Approved | Initial Family Import foundation (Phase 1): §30 V1 `RecordPersonDeathAction`; §55 Registered Household Size stays derived; §55c Declared Household Statistics; §96a import staging, excluded source fields (هويتك / الديانة) and the approved initial-family Excel mapping for Phase 2; §97a events `PERSON_DEATH_RECORDED`, `HOUSEHOLD_DECLARATION_RECORDED` (no metadata) |
| 1.2.14 | 2026-09-27 | Approved | Added §93b: relationship correction, ending an incorrect membership, National ID correction and masking, correction activity privacy, optional governorate/city residence decision and remaining limitations; three §97a events |
| 1.2.13 | 2026-09-26 | Approved | Added §93a: server-side Family/People registry search, exact National ID duplicate prevention (no merge, advisory-lock concurrency, remaining limitation), optional date of birth and UNKNOWN age |
| 1.2.12 | 2026-09-26 | Approved | §116: V1 Staff authentication rules (login, logout, inactive accounts, one Staff role, Filament scope, safe seeding, local-only dev login) |
| 1.2.11 | 2026-09-26 | Approved | Added §55b "Reports (V1)": six fixed reports over the §55a scope and population, Health aggregate-only, Needs lifecycle, latest domain state, scoped INTERNAL/EXTERNAL assistance, Data Quality checks and drill-down, XLSX privacy rules |
| 1.2.10 | 2026-09-25 | Approved | Added §55a "Operational Dashboard (V1)": organizational scope, current population, figure definitions, latest-completed-assessment-per-domain rule, INTERNAL vs EXTERNAL assistance semantics |
| 1.2.9 | 2026-09-25 | Approved | Added §7a "Clan and Branch (V1)" (rules CB-1…CB-10): required Clan, optional Branch, same-Clan integrity, inactive handling, no inference, no hard delete |
| 1.2.8 | 2026-09-24 | Approved | Added §47d–§47i "Assistance V1-B" (execution mode, approval, INTERNAL delivery with National ID verification and delegated receipt, family history, EXTERNAL requested fields, immutable issued lists, XLSX, statistics, completion) and V1-B activity events |
| 1.2.7 | 2026-09-24 | Approved | Added §47a–§47c "Assistance V1-A" (program definition, targeting semantics, nomination) and nomination events in §97a |
| 1.2.6 | 2026-09-24 | Approved | Added §46a "Needs Management (V1)" and the NEED_* events in §97a |
| 1.2.5 | 2026-09-24 | Approved | Added §40a "Quick Multi-Domain Family Assessment (V1)" and the ASSESSMENT_* events in §97a |
| 1.2.4 | 2026-09-24 | Approved | Added §97a "Family Activity Log (V1)" and the §54 V1 decision that the paper-form review/signature section is not implemented literally |
| 1.2.3 | 2026-09-24 | Approved | §36 V1 health decisions: all record types closable (never deleted), active pregnancy/breastfeeding blocks gender correction away from FEMALE, deceased records stay historical but are excluded from current indicators, no minimum pregnancy/breastfeeding age |
| 1.2.2 | 2026-09-24 | Approved | Added §36 "Health & Special-Needs Records (V1)": person-based types, FEMALE-only maternal records, duplicate-active rules, close-not-delete, derived indicators |
| 1.2.1 | 2026-09-24 | Approved | Added §56 "Registration Date Independence": correcting `families.registration_date` does not cascade to membership or residence `started_at` |