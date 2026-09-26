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
  └─ Branch Group   (مجموعة الفروع — organizational; may be unnamed)
       └─ Branch        (الفرع — named)
            └─ Family       (الأسرة — the existing household entity)
                 └─ Person      (via Family Membership)
```

A Clan is not a Family; "Family" remains the household.

Rules:

```text
CB-1  Every Family belongs to exactly one Clan (required at registration).
CB-2  A Family's Branch is optional; NULL = unknown / not yet assigned.
      Branches are never inferred (not from names, forms or members).
CB-3  A Family's Branch must belong to the Family's Clan (enforced in the
      Domain Action and by a composite foreign key).
CB-4  Only an active Clan, and a Branch whose Branch, Group and Clan are all
      active, can be newly selected.
CB-5  An existing Family keeps and displays a since-deactivated Clan/Branch;
      unrelated edits do not clear it. Once changed away, it cannot be
      re-selected while inactive.
CB-6  Changing the Clan never keeps an incompatible Branch: the request must
      name a Branch of the new Clan or clear it. Choosing a Branch never
      moves the Family to another Clan.
CB-7  A Branch Group may be unnamed. Its Branches keep their own names.
CB-8  Codes are immutable after creation. Groups do not move between Clans,
      Branches do not move between Groups (V1).
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
FAMILY_UPDATED          family registration-metadata correction
FAMILY_MEMBER_ADDED     new member (Person + membership)
PERSON_UPDATED          basic Person data correction (current family)
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