# Famboook
## Data Dictionary

**Document:** `02-DATA-DICTIONARY.md`  
**Version:** 1.2.4  
**Status:** Approved  
**Last Updated:** 2026-09-24  
**Project:** Famboook — Family Registry & Case Management System

---

# 1. Purpose

This document defines the logical data structure and terminology used by Famboook.

It establishes the canonical meaning of the main entities, fields, relationships, statuses, and data classifications used throughout the platform.

This document is a logical Data Dictionary.

Physical PostgreSQL implementation details are defined in:

```text
04-DATABASE.md
```

Business constraints are defined in:

```text
03-BUSINESS-RULES.md
```

Workflow transitions are defined in:

```text
05-WORKFLOWS.md
```

Authorization rules are defined in:

```text
06-PERMISSIONS.md
```

---

# 2. Core Data Principle

Famboook models real-world entities rather than paper-form layout.

The fundamental structure is:

```text
Family
  ↓
Family Membership
  ↓
Person
```

A Person exists independently from a Family.

A Family exists independently from its current Household Head.

Paper forms are data sources and must not define the permanent database structure.

---

# 3. Canonical vs Proposed Data

Famboook distinguishes between:

```text
CANONICAL DATA
```

and:

```text
PROPOSED DATA
```

Canonical data represents the currently accepted registry state.

Proposed data represents information submitted for review but not yet applied.

Example:

```text
Current Canonical Residence
        ↓
Family User submits new residence
        ↓
Change Request
        ↓
Proposed Residence
        ↓
Review / Approval
        ↓
Apply Domain Action
        ↓
New Canonical Residence
```

Proposed data must never silently overwrite canonical data.

---

# 4. Main Entity Groups

The logical data model contains the following groups:

```text
Registry
├── Families
├── Persons
├── Family Memberships
├── Person Relationships
└── Residence

Person Information
├── Health
├── Disability
├── Education
└── Employment

Assessment
├── Assessments
├── Form Submissions
└── Workflow Events

Case Management
├── Needs
├── Assistance
├── Person Notes
└── Case Notes

Documents
└── Supporting Documents

Identity & Access
├── Users
└── User-Person Links

Family Self-Service
├── Change Request Types
├── Change Requests
└── Notifications
```

---

# 5. Family

## Entity

```text
families
```

## Purpose

Represents a persistent Family registry entity.

A Family remains the same Family even when:

```text
Household Head changes

Members change

Residence changes

Assessment changes
```

---

# 6. Family Fields

```text
id
family_code
status
registration_date
registration_source
paper_form_no
notes
created_by
updated_by
created_at
updated_at
deleted_at
```

---

# 7. Family Field Definitions

### id

Internal database identifier.

Not intended as the primary user-facing identifier.

---

### family_code

Permanent business identifier.

Example:

```text
FAM-000001
```

Must remain stable after creation.

---

### status

Current Family lifecycle status.

Initial values may include:

```text
ACTIVE
INACTIVE
ARCHIVED
```

Additional statuses require an approved business decision.

---

### registration_date

Date the Family was formally registered in Famboook.

---

### registration_source

Origin of the initial Family registration.

Examples:

```text
PAPER_FORM
MANUAL_ENTRY
IMPORT
VERIFIED_SOURCE
```

---

### paper_form_no

Optional original paper-form reference.

Used for traceability.

It is not the Family primary identity.

---

### notes

General authorized Family-level notes.

Sensitive case notes should use dedicated case-note structures.

---

# 8. Person

## Entity

```text
persons
```

## Purpose

Represents a persistent real-world Person.

A Person is independent from Family membership.

---

# 9. Person Fields

```text
id
person_code
full_name
national_id
gender
birth_date
marital_status_id
life_status
death_date
mobile
alternate_mobile
alternate_mobile_owner_relation
notes
is_active
created_by
updated_by
created_at
updated_at
deleted_at
```

---

# 10. Person Field Definitions

### id

Internal database identifier.

---

### person_code

Permanent business identifier.

Example:

```text
PER-000001
```

Must remain stable.

---

### full_name

Person's registered full name.

Arabic names must be stored in Unicode without destructive normalization.

Search normalization may use separate processing.

---

### national_id

National identity number when available.

Data type conceptually:

```text
VARCHAR
```

It must not be numeric because:

```text
Leading zeros may be meaningful.

Arithmetic is irrelevant.

Formatting rules may change.
```

National ID is sensitive information.

It must not be used as the database primary key.

---

### gender

Initial controlled values:

```text
MALE
FEMALE
```

Any expansion requires an approved data/business decision.

---

### birth_date

Known date of birth.

Must not be a future date.

Age is derived from this field.

Age must not be permanently stored as canonical data.

---

### marital_status_id

Reference to:

```text
marital_statuses
```

---

### life_status

Initial values:

```text
ALIVE
DECEASED
UNKNOWN
```

---

### death_date

Optional canonical date of death.

Rules:

```text
May be NULL.

Must not be in the future.

Must not precede birth_date.

Normally requires life_status = DECEASED.

If life_status = ALIVE, death_date should normally be NULL.
```

A Person may be:

```text
life_status = DECEASED
death_date = NULL
```

when death has been verified but the exact date is unknown.

The system must never invent a death date.

A Family User death report does not directly populate this canonical field.

---

### mobile

Primary mobile number when available.

Sensitive personal data.

---

### alternate_mobile

Optional alternative contact number.

---

### alternate_mobile_owner_relation

Optional short text naming who owns the alternate mobile and how they are
related (paper form: "صاحب الرقم البديل / صلته", e.g. "أحمد محمد – أخ").

Descriptive contact metadata only. It never creates a Person, a Family
Membership or a Person Relationship, and is never used for entity matching.

It only makes sense with `alternate_mobile`: it is rejected without one and
cleared when `alternate_mobile` is removed.

---

### notes

General Person-level authorized notes.

Confidential operational or case notes must use dedicated note entities.

---

### is_active

Indicates whether the Person record remains operationally active in the registry.

It does not mean:

```text
Person is alive.
```

Life status is represented separately.

---

## V1 Field: marital_status

Approved 2026-09-24 (Assistance V1-B). `persons.marital_status`:
`SINGLE` أعزب/عزباء, `MARRIED` متزوج/ة, `DIVORCED` مطلق/ة, `WIDOWED`
أرمل/ة, `UNKNOWN` غير معروف (default). Existing persons were set to
UNKNOWN; SINGLE is never inferred from age or relationship. Edited through
the normal Person create/correct flows. Used by the delegated-receipt rule
(only a SINGLE son/daughter may receive on behalf). V1 decision: stored as
a fixed code set on `persons`, not yet as the `marital_statuses` reference
entity described in §11 (which remains the target model; the V1 codes are
chosen to map onto it one-to-one).

---

# 11. Marital Status

## Entity

```text
marital_statuses
```

Fields:

```text
id
code
name
is_active
sort_order
created_at
updated_at
```

Possible values may include:

```text
SINGLE
MARRIED
DIVORCED
WIDOWED
SEPARATED
UNKNOWN
```

Exact values are managed as reference data.

---

# 12. Family Membership

## Entity

```text
family_memberships
```

## Purpose

Represents the canonical relationship between a Person and a Family.

This is the authoritative Family-Person association.

The system must not use:

```text
persons.family_id
```

as the canonical relationship.

---

# 13. Family Membership Fields

```text
id
family_id
person_id
relationship_type_id
is_household_head
paper_sequence_no
started_at
ended_at
is_active
end_reason
notes
created_by
updated_by
created_at
updated_at
```

---

# 14. Membership Field Definitions

### family_id

Family participating in the membership.

---

### person_id

Person participating in the membership.

---

### relationship_type_id

Relationship of the Person within the Family structure.

References:

```text
relationship_types
```

---

### is_household_head

Boolean indicating whether this active membership currently represents the Household Head.

V1 allows at most one active Household Head per Family.

---

### paper_sequence_no

Optional original row/order reference from a paper form.

This is source metadata only.

It does not determine Person identity or Family relationship.

---

### started_at

Date/time or date from which the membership became effective.

---

### ended_at

Date/time or date when the membership ended.

NULL for active membership.

---

### is_active

Indicates current membership.

V1 supports at most one active primary Family membership per Person.

---

### end_reason

Reason the membership ended.

Examples may include:

```text
TRANSFER
MARRIAGE
HOUSEHOLD_RESTRUCTURE
DATA_CORRECTION
OTHER
```

Death does not require deleting membership history.

---

# 15. Relationship Type

## Entity

```text
relationship_types
```

Fields:

```text
id
code
name
description
is_active
sort_order
created_at
updated_at
```

Examples:

```text
HEAD
SPOUSE
SON
DAUGHTER
FATHER
MOTHER
OTHER
```

Reference values must be centrally managed.

## V1 Operational Baseline

Adopted 2026-09-23 as the seeded V1 values (`RelationshipTypeSeeder`):

| code | name | sort_order |
|---|---|---:|
| HEAD | رب الأسرة | 1 |
| SPOUSE | زوج/زوجة | 2 |
| SON | ابن | 3 |
| DAUGHTER | ابنة | 4 |
| FATHER | أب | 5 |
| MOTHER | أم | 6 |
| OTHER | أخرى | 99 |

Rules:

- `SPOUSE` is the only canonical spouse code. No `HUSBAND`/`WIFE` codes are stored.
  The UI may show زوج or زوجة based on the Person's gender. This is presentation only;
  the stored value is always `SPOUSE`.
- `HEAD` is assigned only to the household-head membership (for example, at Family
  registration). It cannot be chosen when adding a family member.
- Memberships created before relationship types existed may have a NULL
  relationship. The system must not guess a value for them; they display as "غير محدد".

This baseline is operational, not final. PDD-005 stays open for future
review, which may add, rename or deactivate values. Values are deactivated,
never deleted, once memberships use them.

---

# 16. Person Relationship

## Entity

```text
person_relationships
```

## Purpose

Represents a direct relationship between two Persons.

Examples:

```text
Spouse

Parent

Child

Guardian
```

This is distinct from Family Membership.

---

# 17. Person Relationship Fields

```text
id
person_id
related_person_id
relationship_type_id
started_at
ended_at
is_active
notes
created_by
updated_by
created_at
updated_at
```

The same Person must not be related to themselves.

---

# 18. Residence

## Entity

```text
family_residences
```

## Purpose

Stores Family residence history.

Residence is historical rather than a single permanently overwritten address.

---

# 19. Residence Fields

```text
id
family_id
residence_type
governorate
city
area
neighborhood
address_text
original_residence_text
latitude
longitude
displacement_status
displacement_location_text
started_at
ended_at
is_current
source
notes
created_by
updated_by
created_at
updated_at
```

Exact geographic fields may evolve based on operational requirements.

## Displacement Fields (V1)

Adopted 2026-09-23 from the paper form. Displacement is Family residence
data, not household-head Person data, even though the paper form prints it
in the head's section.

### original_residence_text

"مكان السكن الأصلي": the Family's residence **before displacement**. It is
not the head's birthplace. Optional, short free text (e.g. "بني سهيلا – خانيونس").

### displacement_status

"هل الأسرة نازحة حاليًا؟". V1 values:

```text
DISPLACED
NOT_DISPLACED
```

Nullable. `NULL` means the status was not collected (for example, records
created before this field existed). It must not be read or back-filled as
`NOT_DISPLACED`.

### displacement_location_text

"مكان النزوح الحالي". Optional short free text (e.g. "مواصي خانيونس").
It may be set only when `displacement_status = DISPLACED`; otherwise it is
`NULL`.

V1 uses free text for both location fields. There are no governorate/city
reference data for them; see PDD-006 for the geographic hierarchy.

---

# 20. Residence Rules

A Family may have many historical residences.

V1 should allow at most:

```text
One current residence per Family
```

Changing residence should normally:

```text
End current residence
        ↓
Create new residence
```

rather than overwrite historical information.

---

# 21. Health Profile

## Entity

```text
person_health_profiles
```

## Purpose

Stores Person-level health summary information where required.

Health data is classified as restricted.

---

# 22. Person Health Record

Approved 2026-09-24 (Health & Special-Needs Records V1). This replaces the
earlier separate `person_health_conditions` and `person_disabilities`
proposals. DB-ADR-015 still holds: health data uses repeatable relational
records.

## Entity

```text
person_health_records
```

Health information belongs to **Persons**, not Families. The `families` and
`persons` tables carry no health booleans or counts.

Fields:

```text
id
uuid                      public identifier used by the API
person_id
type                      DISABILITY | CHRONIC_DISEASE | PREGNANCY | BREASTFEEDING
disability_type_id        DISABILITY only (required)
condition_name            CHRONIC_DISEASE only (required, short free text)
details                   optional notes
started_at                optional
ended_at                  NULL = active
created_by
updated_by
created_at
updated_at
```

Type rules:

| Type | Required | Must be NULL | Person |
|---|---|---|---|
| DISABILITY | disability_type_id | condition_name | any |
| CHRONIC_DISEASE | condition_name | disability_type_id | any |
| PREGNANCY | — | disability_type_id, condition_name | FEMALE only (no minimum age in V1) |
| BREASTFEEDING | — | disability_type_id, condition_name | FEMALE only (no minimum age in V1) |

A record is **active** while `ended_at` is NULL. Records of every type are
closed (never deleted), so history is kept. Exact start dates are not
required when the source (paper form) does not provide them.

While a Person has an active PREGNANCY or BREASTFEEDING record, their gender
cannot be corrected away from FEMALE (docs/03 §36).

V1 does not include severity, diagnosis status, condition taxonomy,
medications, clinical coding or attachments. PDD-007 and PDD-008 stay open.

`person_health_profiles` (§21) remains documented but is not implemented in V1.

## Disability Types

Reference data (`disability_types`), same shape and conventions as
`relationship_types` (§15). V1 operational baseline:

| code | name |
|---|---|
| MOTOR | حركية |
| VISUAL | بصرية |
| HEARING | سمعية |
| SPEECH_COMMUNICATION | نطق / تواصل |
| INTELLECTUAL | ذهنية / عقلية |
| MULTIPLE | متعددة |
| OTHER | أخرى |

New records may only use active types. A deactivated type stays on the
records that already use it.

## Derived Health Indicators

The family health indicators are **derived on read and never stored**:

| Indicator | Derivation |
|---|---|
| ذوو الإعاقة | DISTINCT persons with an active DISABILITY record |
| الأمراض المزمنة | DISTINCT persons with an active CHRONIC_DISEASE record (two diseases = one person) |
| الحوامل | persons with an active PREGNANCY record |
| المرضعات | persons with an active BREASTFEEDING record |
| أطفال دون سنتين | persons who have not reached their 2nd birthday on the reference date |
| مواليد آخر 12 شهرًا | persons born within the 12 months before the reference date (the 1st birthday itself excluded) |

- Only persons with an **active** membership in the Family count. Deceased
  persons are excluded from the indicators, but their records stay
  visible as history.
- The last two indicators come from `persons.birth_date` only. A missing
  birth date never counts. There are no records for "under two" or
  "recent birth".
- **Reference date:** the Staff App uses **today**. When collection metadata
  exists (PDD-025), a historical paper-form evaluation may use the form's
  **collection date** instead. The derivation stays the same; only the
  reference date changes.

---

# 23. Disability

Superseded in V1 by §22. Disabilities are `person_health_records` with
`type = DISABILITY` and a `disability_type_id` from the `disability_types`
reference data. A Person may still have multiple disability records.

---

# 24. Education

## Entity

```text
person_education
```

Fields may include:

```text
id
person_id
education_level_id
institution_name
specialization
status
started_at
ended_at
notes
created_by
updated_by
created_at
updated_at
```

---

# 25. Employment

## Entity

```text
person_employment
```

Fields may include:

```text
id
person_id
employment_status_id
occupation
employer
started_at
ended_at
is_current
notes
created_by
updated_by
created_at
updated_at
```

---

# 26. Assessment

## Entity

```text
assessments
```

## Purpose

Represents a point-in-time Family or case assessment.

Assessment data does not automatically become canonical registry data.

---

# 27. Assessment Fields

```text
id
assessment_code
family_id
assessment_type_id
status
assessment_date
assigned_to
created_by
updated_by
created_at
updated_at
```

## V1 Implementation — Quick Multi-Domain Family Assessment

Approved 2026-09-24 (docs/03 §40a). V1 implements the smallest clean
subset of the fields above:

| Field | Required | Meaning |
|---|---|---|
| `uuid` | yes | Public identifier (used instead of `assessment_code` in V1) |
| `family_id` | yes | The assessed Family. V1 assessments are **family-level only** (no person-level assessments) |
| `assessment_date` | yes | **Business date**: when the family was assessed. Not in the future. Distinct from `created_at` |
| `status` | yes | `DRAFT` or `COMPLETED` (no other V1 values) |
| `general_notes` | no | Free-text notes about the whole assessment (sensitive) |
| `created_by` | no | Authenticated user who entered the assessment |
| `updated_by` | no | Last user who saved the draft |
| `completed_at` | no | Set on completion only |
| `completed_by` | no | Authenticated user who completed it |
| `created_at` | yes | When the assessment was **entered** into Famboook |
| `updated_at` | yes | Last draft save |

Not implemented in V1: `assessment_code`, `assessment_type_id`,
`assigned_to`, form submissions, questionnaires/templates, scoring.

A Family may have any number of assessments, including several on the
same `assessment_date`; each is independently identifiable.

---

# 27a. Assessment Domains

Approved 2026-09-24 (V1).

## Entity

```text
assessment_domains
```

Reference data with the same shape as `relationship_types` /
`disability_types` (`code`, `name`, `description`, `is_active`,
`sort_order`). Deactivated, never deleted, once in use. Re-running the
seeder never reactivates a deactivated domain.

V1 baseline:

| Code | Arabic |
|---|---|
| `SHELTER` | السكن والمأوى |
| `FOOD` | الغذاء |
| `WASH` | المياه والصرف الصحي والنظافة |
| `HEALTH` | الصحة |
| `EDUCATION` | التعليم |
| `ECONOMIC` | الوضع الاقتصادي |
| `PROTECTION` | الحماية |
| `SPECIAL_NEEDS` | الاحتياجات الخاصة |

There is no domain administration UI in V1.

---

# 27b. Assessment Result

Approved 2026-09-24 (V1).

## Entity

```text
assessment_results
```

The rating of one assessment domain within one Assessment. At most one
result per `(assessment_id, assessment_domain_id)`.

| Field | Required | Meaning |
|---|---|---|
| `assessment_id` | yes | Parent Assessment |
| `assessment_domain_id` | yes | Assessed domain |
| `rating` | yes | `NONE`, `LOW`, `MEDIUM`, `HIGH`, `CRITICAL` |
| `notes` | no | Domain notes (sensitive) |

Rating presentation (Staff App):

| Code | Arabic |
|---|---|
| `NONE` | لا يوجد احتياج |
| `LOW` | منخفض |
| `MEDIUM` | متوسط |
| `HIGH` | مرتفع |
| `CRITICAL` | حرج |

**There is no `NOT_ASSESSED` rating.** The absence of a result for a
domain means "لم يتم تقييم هذا المجال". "غير مقيّم" in the Staff App is UI
state only and is never sent or stored.

No family-level score, count or vulnerability index is stored; counts
such as "assessed domains" are derived on read.

Assessment health-domain notes belong to the Assessment and do not
replace Person Health Records (§22).

---

# 28. Form Submission

## Entity

```text
form_submissions
```

## Purpose

Stores structured form responses related to an Assessment or controlled data-collection process.

Fields may include:

```text
id
assessment_id
form_type
form_version
status
submitted_data
submitted_by
submitted_at
verified_by
verified_at
approved_by
approved_at
created_at
updated_at
```

Exact response storage strategy is defined by the form architecture.

---

# 29. Workflow Event

## Entity

```text
workflow_events
```

## Purpose

Stores workflow state-transition history.

Workflow events are distinct from audit logs.

---

# 30. Workflow Event Fields

```text
id
workflowable_type
workflowable_id
from_status
to_status
event_type
actor_id
comment
metadata
created_at
```

Supported workflow targets may include:

```text
Form Submission

Assessment

Change Request

Document

Other approved workflow entities
```

Workflow events are append-only.

---

# 31. Document

## Entity

```text
documents
```

## Purpose

Represents supporting documentation.

A document may belong to a:

```text
Family

Person

Change Request
```

---

# 32. Document Fields

```text
id
family_id
person_id
change_request_id
document_type_id
document_number
is_available
is_verified
issue_date
expiry_date
file_path
uploaded_by
verified_by
verified_at
notes
created_at
updated_at
```

At least one valid ownership/context relationship must exist.

Exact polymorphic expansion may be considered later if justified.

---

# 33. Document Verification

These concepts are different:

```text
UPLOADED
```

and:

```text
VERIFIED
```

A successfully uploaded file is not automatically verified.

Verification requires an authorized operation.

---

# 34. Family Need

## Entity

```text
family_needs
```

Fields may include:

```text
id
family_id
person_id
need_type_id
priority
status
identified_at
identified_by
closed_at
closed_by
source
notes
created_at
updated_at
```

`person_id` may be NULL for Family-level Needs.

## V1 Implementation — Needs Management

Approved 2026-09-24 (docs/03 §46a). Table `family_needs`:

| Field | Required | Meaning |
|---|---|---|
| `uuid` | yes | Public identifier |
| `family_id` | yes | The Family the Need belongs to (always the primary container) |
| `person_id` | no | Targeted member. NULL = family-level need. A new target must be an active member of the same family |
| `source_assessment_id` | no | Optional source: a COMPLETED Assessment of the same family |
| `need_category_id` | yes | Need Category (§34a); replaces the proposed `need_type_id` |
| `title` | yes | Short concrete need, max 150 chars (e.g. "كرسي متحرك") |
| `description` | no | Free-text notes (sensitive) |
| `priority` | yes | `LOW`, `MEDIUM` (default), `HIGH`, `URGENT` |
| `quantity` | no | Requested quantity, > 0, up to 2 decimals |
| `unit` | no | Free-text unit (max 30), only with a quantity. No units reference data in V1 |
| `status` | yes | `OPEN`, `FULFILLED`, `CLOSED` |
| `resolved_at` | no | Set when FULFILLED/CLOSED |
| `resolved_by` | no | Authenticated user who resolved it |
| `closure_reason` | no | Required for CLOSED, NULL otherwise |
| `created_by`, `updated_by` | — | Authenticated users |
| `created_at`, `updated_at` | yes | System timestamps |

Priority is operational prioritization and is deliberately **not** the
Assessment rating scale (no `CRITICAL`).

Priority presentation: `LOW` منخفضة, `MEDIUM` متوسطة, `HIGH` مرتفعة,
`URGENT` عاجلة.

Quantity is the requested quantity only; delivered quantities and
fulfilment calculation belong to the future Assistance domain.

Not implemented in V1: `identified_at`/`identified_by` (use `created_*`),
`source` string (replaced by the optional `source_assessment_id`),
`closed_at`/`closed_by` (named `resolved_*`), `notes` (named
`description`).

---

# 34a. Need Categories

Approved 2026-09-24 (V1).

```text
need_categories
```

Reference data with the same shape as `assessment_domains`
(`code`, `name`, `description`, `is_active`, `sort_order`); used instead of
the proposed `need_types`. Deactivated, never deleted; the seeder never
reactivates a deactivated category. A deactivated category cannot be
chosen for a new Need (or newly assigned to an open one) but stays
readable on existing Needs. No category administration UI in V1.

| Code | Arabic |
|---|---|
| `SHELTER` | المأوى والسكن |
| `FOOD` | الغذاء |
| `WATER` | المياه |
| `HYGIENE` | النظافة والصرف الصحي |
| `HEALTHCARE` | الرعاية الصحية |
| `MEDICATION` | الأدوية |
| `ASSISTIVE_DEVICE` | الأجهزة والمستلزمات المساعدة |
| `EDUCATION` | التعليم |
| `CASH` | المساعدة النقدية |
| `CLOTHING` | الملابس |
| `CHILDCARE` | احتياجات الأطفال |
| `PROTECTION` | الحماية |
| `LIVELIHOOD` | سبل العيش |
| `OTHER` | أخرى |

---

# 35. Need Status

Possible initial lifecycle:

```text
OPEN
IN_PROGRESS
MET
CLOSED
CANCELLED
```

Exact workflow is defined in `05-WORKFLOWS.md`.

**V1 (2026-09-24):** only `OPEN` (مفتوح), `FULFILLED` (تمت تلبيته) and
`CLOSED` (مغلق) are used. `FULFILLED` corresponds to MET; `CLOSED` covers
"no longer active for another reason" (including what CANCELLED
described). `IN_PROGRESS` and partial fulfilment are deferred to
Assistance tracking.

---

# 36. Assistance Record

## Entity

```text
assistance_records
```

Fields may include:

```text
id
family_id
person_id
need_id
assistance_type_id
provider
quantity
unit
value
currency
provided_at
recorded_by
reference_no
notes
created_at
updated_at
```

Assistance does not automatically imply that a Need has been resolved.

**V1-A note (2026-09-24):** `assistance_records` remains the future
**delivery** record (Assistance V1-B). It is not implemented yet. V1-A
adds the program, planned items and nominations below (§36a–§36c).

```text
NEED         what a family/person needs                       (family_needs)
ASSISTANCE   a defined program/campaign that can provide support (assistances)
NOMINATION   a family/person selected as a POTENTIAL beneficiary (assistance_beneficiaries)
DELIVERY     what was actually received                        (assistance_records — V1-B)
```

A nomination is never proof that assistance was received.

---

# 36a. Assistance (Program / Campaign)

Approved 2026-09-24 (Assistance V1-A, docs/03 §47a).

## Entity

```text
assistances
```

| Field | Required | Meaning |
|---|---|---|
| `uuid` | yes | Public identifier |
| `title` | yes | Program name (max 150), e.g. "حزمة إيواء طارئة" |
| `assistance_category_id` | yes | Assistance Category (§36b) — what it is for |
| `assistance_type` | yes | `IN_KIND` مساعدة عينية, `CASH` مساعدة نقدية, `SERVICE` خدمة — how it is provided |
| `provider_name` | yes | Free text (max 150). No provider/organization management in V1-A |
| `target_beneficiaries` | no | Planned target (positive integer). Nominee/approved/delivered counts are always derived, never stored |
| `start_date`, `end_date` | no | Planned period (`end_date >= start_date`); not delivery dates |
| `description` | no | Free text |
| `status` | yes | `DRAFT` مسودة, `OPEN` مفتوحة, `COMPLETED` مكتملة, `CANCELLED` ملغاة |
| `execution_mode` | yes | `INTERNAL` (approval, identity-verified delivery in Famboook) or `EXTERNAL` (approval and immutable beneficiary lists issued to another organization). Chosen while DRAFT; locked once OPEN. Existing V1-A rows were migrated to INTERNAL |
| `export_fields` | no | EXTERNAL requested list columns (§36f) |
| `completed_at`, `completed_by` | no | Set on OPEN → COMPLETED |
| `targeting_criteria` | no | Validated criteria snapshot (§36d); never preview results |
| `opened_at`, `opened_by` | no | When/by whom DRAFT → OPEN happened |
| `created_by`, `updated_by`, timestamps | — | |

V1-A uses only DRAFT → OPEN; COMPLETED/CANCELLED exist for the schema and
are reached in V1-B.

## Assistance Items

```text
assistance_items
```

One or more planned items per Assistance, per beneficiary:

| Field | Required | Meaning |
|---|---|---|
| `item_name` | yes | e.g. "فرشة", "مساعدة نقدية", "جلسة علاج طبيعي" |
| `quantity_per_beneficiary` | no | > 0, up to 2 decimals |
| `unit` | no | Free text (max 30) |
| `unit_value` | no | > 0, up to 2 decimals |
| `currency` | with `unit_value` | `ILS`, `USD`, `JOD`, `EUR` — required iff `unit_value` is set. Not reference data |
| `sort_order` | yes | Display order |

Items are a planned definition only: never stock, inventory or delivered
quantities.

---

# 36b. Assistance Categories

```text
assistance_categories
```

Reference data (`code`, `name`, `description`, `is_active`,
`sort_order`), technically independent of `need_categories` although the
V1 taxonomy is the same 14 codes: SHELTER المأوى والسكن, FOOD الغذاء,
WATER المياه, HYGIENE النظافة والصرف الصحي, HEALTHCARE الرعاية الصحية,
MEDICATION الأدوية, ASSISTIVE_DEVICE الأجهزة والمستلزمات المساعدة,
EDUCATION التعليم, CASH المساعدة النقدية, CLOTHING الملابس, CHILDCARE
احتياجات الأطفال, PROTECTION الحماية, LIVELIHOOD سبل العيش, OTHER أخرى.
Deactivated, never deleted; the seeder never reactivates one. No
administration UI in V1-A.

---

# 36c. Assistance Beneficiary (V1-A: Nominee)

```text
assistance_beneficiaries
```

| Field | Required | Meaning |
|---|---|---|
| `uuid` | yes | Public identifier |
| `assistance_id` | yes | The Assistance |
| `family_id` | yes | The nominated family (always set) |
| `person_id` | no | NULL = family-level nominee; set = person-level nominee (active member when nominated) |
| `source_need_id` | NEED only | The OPEN Need the nomination came from |
| `nomination_source` | yes | `TARGETING`, `MANUAL`, `NEED` — stored at nomination, never inferred |
| `targeting_criteria` | no | Criteria snapshot for TARGETING nominations (not a match result) |
| `status` | yes | V1-A: `NOMINATED` (مرشح) or `REMOVED` (history-preserving withdrawal) |
| `nominated_at`, `nominated_by` | yes | When/by whom |
| `removed_at`, `removed_by` | no | Set when REMOVED |

**V1-B (implemented 2026-09-24):** `APPROVED` معتمد, `REJECTED` مرفوض,
`NOT_DELIVERED` لم يُسلَّم were added on the same rows, with
`approved_at/by`, `rejected_at/by`, `rejection_reason` (required),
`not_delivered_at/by`, `not_delivered_reason` (required). Transitions:
NOMINATED → APPROVED | REJECTED (both modes); APPROVED → NOT_DELIVERED
(INTERNAL only). REJECTED and NOT_DELIVERED are terminal. `DELIVERED` is
never a nominee status: delivery is a separate record (§36e); inclusion in
an external list is derived from list entries (§36f).

---

# 36e. Assistance Delivery (V1-B, INTERNAL only)

```text
assistance_deliveries
```

| Field | Meaning |
|---|---|
| `uuid` | Public identifier |
| `assistance_beneficiary_id` | The APPROVED beneficiary |
| `receipt_mode` | `PERSONAL` (المستفيد شخصيًا) or `DELEGATE` (الاستلام بالنيابة) |
| `original_beneficiary_person_id` | Nominated person, or current household head for a family-level beneficiary |
| `recipient_person_id` | = original for PERSONAL; an unmarried son/daughter for DELEGATE |
| `delivered_at`, `delivered_by` | When / which authenticated user recorded it |
| `notes` | Optional |
| `reversed_at`, `reversed_by`, `reversal_reason` | Set once by Reverse Delivery |

One row means the **full planned package** was received (no delivery
items, no partial delivery). **No National ID is stored.** At most one
non-reversed delivery per beneficiary. Never deleted.

---

# 36f. Issued Beneficiary List (V1-B, EXTERNAL only)

```text
assistance_beneficiary_lists
assistance_beneficiary_list_entries
```

List: `uuid`, `list_number` (ABL-000001), `recipient_organization`
(defaults to the Assistance `provider_name`), `issued_at`, `issued_by`,
`notes`, `configuration_snapshot` (ordered columns: `field_key`,
`column_label`, `classification`), `contains_sensitive`, `row_count`.

Entry: list, beneficiary, `row_number`, `snapshot_data` — the exact values
issued, keyed by field. **Highly sensitive**, encrypted at rest, readable
only through the authorized external-list endpoints. Lists and entries are
immutable and never deleted; a correction is a new list.

Requested export fields (`assistances.export_fields`, EXTERNAL only) are an
ordered list of `{field_key, column_label, sort_order}` from this
controlled catalog. They are independent of targeting criteria. A column
label is presentation only and never changes a field's meaning.

| Key | Class | Semantics (subject = nominated Person, or CURRENT household head for a family-level beneficiary) |
|---|---|---|
| `family_code` | STANDARD | Beneficiary family's code |
| `person_code` | STANDARD | Subject person's code |
| `beneficiary_name` | STANDARD | Subject person's full name |
| `household_head_name` | STANDARD | Current household head's full name |
| `national_id` | SENSITIVE | Subject person's National ID, as stored |
| `date_of_birth` | STANDARD | Subject person's birth date (YYYY-MM-DD) |
| `gender` | STANDARD | ذكر / أنثى |
| `marital_status` | STANDARD | Subject person's marital status (Arabic label) |
| `primary_mobile` | CONTACT | Subject person's mobile |
| `alternate_mobile` | CONTACT | Subject person's alternate mobile |
| `family_members_count` | STANDARD | Active, non-deceased members of the family |
| `original_residence` | STANDARD | Current residence `original_residence_text` |
| `displacement_status` | STANDARD | نازحة / غير نازحة (empty if never collected) |
| `displacement_location` | STANDARD | Current residence `displacement_location_text` |
| `children_under_2_count` | STANDARD | Active living members under two today (health-indicator rule) |
| `has_disability` / `has_chronic_disease` / `has_pregnancy` / `has_breastfeeding` | SENSITIVE | نعم / لا — active record of that type: family-level = any active living member; person-level = that person only. Never the condition or details |

---

# 36d. Targeting Criteria (V1-A)

Flat, explicitly approved keys (all optional): `min_family_members`,
`max_family_members`, `displacement_status`, `displacement_location_text`,
`has_child_under_two`, `min_children_under_two`, `has_pregnant_member`,
`has_breastfeeding_member`, `has_member_with_disability`,
`has_member_with_chronic_disease`, `need_category_code`,
`need_priorities[]`, `assessment_domain_code`, `assessment_ratings[]`.
Semantics: docs/03 §47b.

# 37. Person Note

## Entity

```text
person_notes
```

Fields:

```text
id
person_id
note_type
visibility
content
created_by
created_at
updated_at
```

---

# 38. Case Note

## Entity

```text
case_notes
```

Fields:

```text
id
family_id
person_id
note_type
visibility
content
created_by
created_at
updated_at
```

Case notes are not automatically Family Portal-visible.

---

# 39. User

## Entity

```text
users
```

## Purpose

Represents application authentication identity.

A User is not the same as a Person.

---

# 40. User Fields

Laravel authentication fields are implementation-dependent but logically include:

```text
id
name
email
mobile
password
status
last_login_at
created_at
updated_at
```

Additional security fields may be added.

Roles are managed separately through the authorization layer.

---

# 41. User-Person Link

## Entity

```text
user_person_links
```

## Purpose

Explicitly connects an authenticated User to a registry Person.

This link is required for Family Portal identity resolution.

---

# 42. User-Person Link Fields

```text
id
user_id
person_id
link_type
status
verified_by
verified_at
activated_at
ended_at
end_reason
created_at
updated_at
```

---

# 43. User-Person Link Type

Initial:

```text
SELF
```

Potential future types:

```text
GUARDIAN
AUTHORIZED_REPRESENTATIVE
```

Future types require explicit authorization rules.

---

# 44. User-Person Link Status

Initial values:

```text
PENDING_VERIFICATION
VERIFIED
ACTIVE
SUSPENDED
ENDED
```

An ACTIVE link alone does not grant unrestricted Family access.

---

# 45. Family User Resolution

Family Portal scope is dynamically resolved:

```text
User
 ↓
Active User-Person Link
 ↓
Person
 ↓
Active Family Membership
 ↓
Family
```

Additional policy checks may include:

```text
Household Head Status

User Status

Person Status

Family Status

Resource Policy
```

The system must not rely on:

```text
users.family_id
```

as the canonical authorization relationship.

---

# 46. Change Request Type

## Entity

```text
change_request_types
```

Fields:

```text
id
code
name
description
risk_level
requires_document
is_active
sort_order
created_at
updated_at
```

---

# 47. Initial Change Request Types

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

Not every type must be enabled for Family Users immediately.

---

# 48. Change Request Risk

Possible values:

```text
LOW
MEDIUM
HIGH
```

Risk level may affect:

```text
Reviewer requirements

Approval requirements

Required documents

Additional verification

Application authorization
```

V1 defaults to Staff review for substantive Family User changes.

---

# 49. Change Request

## Entity

```text
change_requests
```

## Purpose

Stores proposed changes submitted through controlled self-service or authorized workflow.

It must not be treated as the canonical registry record.

---

# 50. Change Request Fields

```text
id
request_code
family_id
person_id
change_request_type_id
status
risk_level
submitted_data
reason
notes
submitted_by
submitted_at
reviewed_by
reviewed_at
review_notes
approved_by
approved_at
rejected_by
rejected_at
rejection_reason
applied_by
applied_at
created_at
updated_at
```

---

# 51. Change Request Code

Example:

```text
CRQ-000001
```

The code is a stable business identifier.

---

# 52. Change Request Family

Every Change Request belongs to one Family.

```text
family_id
```

is mandatory.

---

# 53. Change Request Person

```text
person_id
```

is optional.

It is used when a request targets a specific existing Person.

Examples:

```text
PERSON_CORRECTION

DEATH_REPORT

DOCUMENT_UPDATE
```

Some requests are Family-level.

---

# 54. submitted_data

`submitted_data` contains proposed values.

Logical type:

```text
JSON / JSONB
```

PostgreSQL implementation uses:

```text
JSONB
```

where appropriate.

Example:

```json
{
  "mobile": "0590000000",
  "alternate_mobile": "0560000000"
}
```

This payload is not automatically trusted.

Each Change Request type requires explicit validation.

---

# 55. Change Request Status

Initial statuses:

```text
DRAFT

SUBMITTED

UNDER_REVIEW

RETURNED_FOR_CLARIFICATION

RESUBMITTED

APPROVED

REJECTED

APPLIED
```

---

# 56. Change Request Status Meaning

### DRAFT

Requester has not submitted the request.

---

### SUBMITTED

Request has been formally submitted.

---

### UNDER_REVIEW

Authorized Staff are reviewing the request.

---

### RETURNED_FOR_CLARIFICATION

Additional information is required from the requester.

---

### RESUBMITTED

Requester responded and resubmitted the request.

---

### APPROVED

Request has been approved but the canonical domain operation may not yet have completed.

---

### REJECTED

Request was not approved.

---

### APPLIED

The approved domain operation completed successfully and canonical data was updated.

---

# 57. Change Request Application

Application must map the approved request to an authorized Domain Action.

Examples:

```text
RESIDENCE_UPDATE
        ↓
ChangeFamilyResidenceAction
```

```text
HOUSEHOLD_HEAD_CHANGE
        ↓
ChangeHouseholdHeadAction
```

```text
DEATH_REPORT
        ↓
RecordPersonDeathAction
```

The Change Request itself must not contain arbitrary database mutation logic.

---

# 58. Change Request Supporting Documents

Supporting files are connected through:

```text
documents.change_request_id
```

A document submitted with a Change Request remains unverified until explicitly verified.

---

# 59. Notification

## Logical Entity

Famboook requires notifications.

V1 may use Laravel's standard database notification infrastructure rather than a custom domain table.

Logical notification information includes:

```text
recipient
type
title
message
related_resource
read_at
created_at
```

Sensitive information should be minimized in notification payloads.

---

# 60. Notification Events

Examples:

```text
CHANGE_REQUEST_SUBMITTED

CHANGE_REQUEST_UNDER_REVIEW

CHANGE_REQUEST_CLARIFICATION_REQUIRED

CHANGE_REQUEST_APPROVED

CHANGE_REQUEST_REJECTED

CHANGE_REQUEST_APPLIED

ACCOUNT_ACTIVATED

ACCOUNT_SUSPENDED
```

---

# 61. Audit Data

Audit records must be logically distinct from workflow events.

Audit data should answer:

```text
Who changed data?

What changed?

When?

Previous value?

New value?
```

Audit implementation is defined in the database and technical architecture documents.

---

# 61a. Family Activity

Approved 2026-09-24 (Family Activity Log V1, docs/03 §97a).

## Entity

```text
family_activities
```

## Purpose

A system-generated, immutable, family-scoped timeline entry for one
successful family Domain Action. It is **not** an audit record (§61): it
holds no previous/new values.

## Fields

| Field | Required | Meaning |
|---|---|---|
| `uuid` | yes | Public identifier |
| `family_id` | yes | The Family whose timeline this belongs to |
| `actor_user_id` | no | Authenticated application user who performed the operation. Not the field researcher. NULL reserved for future system/import operations |
| `event_type` | yes | Canonical event code (see docs/03 §97a) |
| `subject_type` | no | `family`, `person`, `residence`, `health_record`, `assessment`, `need` or `assistance_nominee` (also the subject of V1-B approval, delivery and listing events) |
| `subject_id` | no | Internal id of the subject record |
| `metadata` | no | Allow-listed keys only. V1: `health_record_type` (DISABILITY, CHRONIC_DISEASE, PREGNANCY, BREASTFEEDING). Assessment, Need and nomination events carry no metadata (no ratings, notes, descriptions, closure reasons or quantities) |
| `created_at` | yes | When the operation happened |

There is no `updated_at`: entries are never modified.

## Privacy

Activity never stores National IDs, phone numbers, health details, disease
names, disability types or details, notes, previous/new values, request
payloads or serialized models. Person names are resolved from the subject
at read time, not copied. Arabic wording is presentation (Staff App) and is
not stored.

## No Backfill

Records created before the Activity Log was enabled have no activity. No
events or actors are fabricated for them.

---

# 62. Data Classification

Famboook uses data classification to support security decisions.

Initial classifications:

```text
OPERATIONAL

INTERNAL

RESTRICTED
```

---

# 63. Restricted Data

Examples include:

```text
National ID

Health Information

Disability Information

Sensitive Documents

Confidential Notes

Authentication Information
```

Restricted data requires explicit authorization.

---

# 64. Portal Visibility

Separate from data classification, portal visibility may use:

```text
FAMILY_VISIBLE

SELF_ONLY

STAFF_ONLY

RESTRICTED
```

Example:

```text
Person Name
→ FAMILY_VISIBLE

Another adult's National ID
→ RESTRICTED / HIDDEN

Staff Review Notes
→ STAFF_ONLY
```

---

# 65. Field-Level Exposure

The API must not assume that all fields belonging to an authorized resource are automatically visible.

Conceptually:

```text
Can access Person
        ≠
Can view every Person field
```

Different API representations may expose different field sets.

---

# 66. API Representation vs Database Entity

Database entities and API representations are intentionally separate concepts.

For example:

```text
persons
```

may be represented as:

```text
PersonSummaryResource

PersonDetailResource

FamilyMemberResource

FamilyPortalPersonResource
```

The Data Dictionary defines canonical data.

It does not require exposing every canonical field through every API response.

---

# 67. Frontend Data

The Next.js frontend may maintain temporary:

```text
Form State

UI State

Cached Server State

Search Filters

Pagination State
```

These are not canonical registry data.

TanStack Query cache must never be treated as a source of truth.

---

# 68. Frontend Validation Data

Zod schemas may represent frontend validation requirements.

They do not replace Laravel validation or domain rules.

Example:

```text
Zod
→ Improve form UX

Laravel
→ Authoritative validation

PostgreSQL
→ Persistence integrity
```

---

# 69. Reference Data

Reference tables may include:

```text
marital_statuses

relationship_types

document_types

condition_types

disability_types

education_levels

employment_statuses

assessment_types

need_types

assistance_types
```

Reference data should use stable codes where appropriate.

---

# 70. Reference Code Principle

Reference codes should be machine-stable.

Example:

```text
MARRIED
```

Display labels may be localized:

```text
Married

متزوج
```

Application logic must not depend on translated display text.

---

# 71. Business Identifiers

Primary user-facing entities should receive stable business identifiers.

Examples:

```text
Family
FAM-000001

Person
PER-000001

Change Request
CRQ-000001
```

Database IDs and business identifiers are separate concepts.

---

# 72. Source Metadata

Where data originates from paper or imported sources, source metadata should be preserved.

Possible metadata:

```text
source_type

paper_form_no

paper_sequence_no

import_batch

created_by

created_at
```

Source metadata must not determine canonical identity by itself.

---

# 73. Null vs Unknown

The system must distinguish when possible between:

```text
NULL
```

meaning:

```text
Not known / not provided
```

and explicit domain values such as:

```text
UNKNOWN
```

when the business meaning requires an explicit unknown state.

The system must not invent placeholder values merely to satisfy required database fields.

---

# 74. No Fake Values

Examples of prohibited fake values include:

```text
000000000
for unknown National ID

1900-01-01
for unknown birth date

2026-01-01
for unknown death date
```

Unknown data should remain properly unknown.

---

# 75. Derived Data

Derived values should not normally be stored redundantly.

Examples:

```text
Age

Family Size

Number of Children

Number of Adults

Male Count

Female Count
```

They should be calculated from canonical records unless a justified performance strategy requires otherwise.

---

# 76. Historical Data

Historical records should be preserved where the real-world state changes over time.

Examples:

```text
Family Membership

Residence

Employment

Education where applicable

Health Conditions where applicable

Person Relationships

Workflow Events
```

Historical records should not be overwritten simply to represent current state.

---

# 77. Soft Deletion

Selected entities may use:

```text
deleted_at
```

Soft deletion is not equivalent to historical lifecycle state.

Example:

```text
Person transferred from Family
```

should be represented by ending a membership, not deleting it.

---

# 78. Duplicate Detection Data

Duplicate detection may consider:

```text
National ID

Normalized Name

Birth Date

Gender

Mobile

Family Context
```

Potential duplicate classification:

```text
EXACT

PROBABLE

POSSIBLE
```

No automatic merge is allowed.

---

# 79. Search Data

Searchable data may include:

```text
family_code

person_code

full_name

national_id

mobile

paper_form_no
```

Search capability must respect sensitive-field permissions.

Search normalization must not destructively alter canonical values.

---

# 80. Arabic Data

Arabic text must be stored as Unicode.

Canonical Arabic values should not be destructively modified for search convenience.

Search may maintain or calculate normalized forms separately.

Possible search normalization may address:

```text
Diacritics

Tatweel

Alef variants

Whitespace
```

Exact normalization rules require implementation testing.

---

# 81. Date and Time

Business dates such as:

```text
birth_date

death_date

registration_date
```

should use date semantics when time-of-day is irrelevant.

System events such as:

```text
created_at

updated_at

submitted_at

approved_at

applied_at
```

use timestamp semantics.

System timestamps should be stored consistently, preferably UTC.

---

# 82. Money

Where financial values are recorded, such as Assistance value:

```text
value
currency
```

must be separate.

Currency must not be inferred from the numeric amount.

---

# 83. File Metadata

Document database records store metadata.

The actual sensitive file is stored in private storage.

Database records may contain:

```text
file_path
```

but the value must not imply public accessibility.

---

# 84. Authentication Secrets

Authentication secrets are not registry data.

Passwords must never be stored in plaintext.

Primary web authentication uses secure session/cookie mechanisms through Laravel Sanctum.

Browser localStorage must not contain primary authentication secrets.

---

# 85. Data Ownership

Famboook does not interpret Family User submission as ownership of canonical data.

A Family User may be authorized to:

```text
View

Request

Submit

Upload

Track
```

without being authorized to directly mutate canonical records.

---

# 86. Data Trust Levels

Conceptually, information may move through trust levels:

```text
Submitted
   ↓
Reviewed
   ↓
Verified / Approved
   ↓
Canonical
```

Exact workflows vary by entity.

---

# 87. Family User Input

All Family User input is considered untrusted input.

It must undergo:

```text
Authentication

Authorization

Validation

Workflow

Review where required
```

before affecting canonical data.

---

# 88. Import Data

Imported data is also untrusted until validated.

Import processes must support:

```text
Validation

Duplicate Detection

Error Reporting

Source Traceability
```

Imports must not bypass domain constraints.

---

# 89. Export Data

Exports are generated representations of authorized canonical data.

Export files are not separate sources of truth.

Sensitive export generation must respect:

```text
Role

Permission

Data Scope

Field Visibility

Export Permission
```

---

# 90. Data Dictionary Invariants

```text
DD-INV-001
Person identity is independent from Family membership.

DD-INV-002
Family Membership is the canonical Family-Person relationship.

DD-INV-003
Historical Family memberships are preserved.

DD-INV-004
Paper sequence does not define Person identity.

DD-INV-005
National ID is stored as text.

DD-INV-006
Unknown values are not replaced with fake placeholders.

DD-INV-007
Age is derived from birth_date.

DD-INV-008
Residence history is preserved.

DD-INV-009
Health conditions are repeatable records.

DD-INV-010
Disabilities are repeatable records.

DD-INV-011
Assessments do not automatically overwrite canonical registry data.

DD-INV-012
Uploaded documents are not automatically verified.

DD-INV-013
Need and Assistance are separate concepts.

DD-INV-014
User and Person are separate entities.

DD-INV-015
Family User scope is resolved through User-Person Link and Family Membership.

DD-INV-016
Change Request submitted_data is proposed data, not canonical data.

DD-INV-017
APPROVED and APPLIED are distinct.

DD-INV-018
Family User input never silently overwrites canonical data.

DD-INV-019
Workflow Events and Audit records are separate concepts.

DD-INV-020
Restricted fields require explicit authorization.

DD-INV-021
persons.death_date is the optional canonical exact death date.

DD-INV-022
Unknown death dates are never invented.

DD-INV-023
Database entities and API representations are separate concepts.

DD-INV-024
Frontend cached data is not canonical data.

DD-INV-025
Frontend validation is not authoritative business validation.

DD-INV-026
Reference-data codes are independent from translated labels.

DD-INV-027
Sensitive document files are private by default.

DD-INV-028
PostgreSQL is the canonical persistent data store.

DD-INV-029
Frontend applications never directly access PostgreSQL.

DD-INV-030
Canonical Arabic data is not destructively normalized for search.
```

---

# 91. Approved Data Decisions

### DD-ADR-001

Family is a persistent independent entity.

### DD-ADR-002

Person is a persistent independent entity.

### DD-ADR-003

Family Membership is the canonical Family-Person association.

### DD-ADR-004

No canonical `persons.family_id` is used.

### DD-ADR-005

Membership history is preserved.

### DD-ADR-006

Household Head is represented through Family Membership.

### DD-ADR-007

Person Relationships are separate from Family Membership.

### DD-ADR-008

Residence is historical.

### DD-ADR-009

Health conditions are repeatable Person records.

### DD-ADR-010

Disabilities are repeatable Person records.

### DD-ADR-011

Assessments are separate from permanent registry identity.

### DD-ADR-012

Documents may belong to Family, Person, or Change Request contexts.

### DD-ADR-013

Need and Assistance are separate entities.

### DD-ADR-014

Users are separate from Persons.

### DD-ADR-015

User-Person Links connect authentication identity to registry identity.

### DD-ADR-016

FAMILY_USER authorization does not require `users.family_id`.

### DD-ADR-017

Family User proposed changes use Change Requests.

### DD-ADR-018

Change Request variable payloads may use JSONB.

### DD-ADR-019

Change Request application invokes controlled Domain Actions.

### DD-ADR-020

Notifications may use Laravel notification infrastructure.

### DD-ADR-021

`persons.death_date` stores the optional canonical exact death date.

### DD-ADR-022

A deceased Person may have a NULL death date when the exact date is unknown.

### DD-ADR-023

API representations are separated from persistence Models.

### DD-ADR-024

Laravel API Resources control context-specific data exposure.

### DD-ADR-025

PostgreSQL 16+ is the canonical database platform.

### DD-ADR-026

Frontend state does not represent canonical persistence.

### DD-ADR-027

TanStack Query cache is treated only as frontend server-state cache.

### DD-ADR-028

Zod validation supplements but does not replace Laravel validation.

### DD-ADR-029

Sensitive document files use private storage.

### DD-ADR-030

Canonical reference values use stable codes independent from localization.

---

# 92. Pending Data Decisions

The following remain open:

```text
PDD-001
Exact National ID normalization policy.

PDD-002
Whether normalized National ID requires a separate searchable hash/index.

PDD-003
Exact Arabic-name search normalization rules.

PDD-004
Whether marriage history requires a dedicated marriage entity.

PDD-005
Final relationship-type reference values.
(V1 operational baseline adopted 2026-09-23, see §15; final taxonomy still open for review.)

PDD-006
Final residence geographic hierarchy.

PDD-007
Exact health condition taxonomy.
(V1 uses free-text chronic disease names, see §22.)

PDD-008
Exact disability taxonomy.
(V1 operational disability-type baseline adopted 2026-09-24, see §22.)

PDD-009
Exact education reference structure.

PDD-010
Exact employment reference structure.

PDD-011
Exact Need taxonomy.

PDD-012
Exact Assistance taxonomy.

PDD-013
Exact Assessment types and schemas.

PDD-014
Exact document-type taxonomy.

PDD-015
Whether selected Person fields require application-level encryption.

PDD-016
Whether sensitive exact-search fields require HMAC search hashes.

PDD-017
Exact retention rules for documents and sensitive data.

PDD-018
Exact Family User visibility of health information.

PDD-019
Exact Family User visibility of Needs and Assistance.

PDD-020
Exact Guardian / Authorized Representative data requirements.

PDD-021
Whether multilingual reference labels are stored in DB or localization files.

PDD-022
Exact handling of partial/unknown birth dates if required.

PDD-023
Exact data model for multiple mobile/contact methods if required.

PDD-024
Exact geographic coordinate usage and privacy rules.

PDD-025
Researcher / collection metadata schema.
Approved direction (2026-09-23), not yet implemented:
- researcher name comes from the authenticated User's profile;
- researcher branch/area comes from the User's profile;
- collection date is recorded by the system as part of the collection
  process, not typed repeatedly.
Final User Profile schema and field names are still open.
Note (2026-09-24): the Family Activity Log actor (§61a) is the
authenticated entry user only. It is not treated as the researcher;
collection metadata remains deferred.
```

---

# 93. Core Entity Relationship Summary

```text
Family
  │
  ├── Family Membership ───── Person
  │                              │
  │                              ├── Health Conditions
  │                              ├── Disabilities
  │                              ├── Education
  │                              ├── Employment
  │                              ├── Person Notes
  │                              └── User-Person Links ── User
  │
  ├── Residence History
  ├── Assessments
  │      └── Form Submissions
  │
  ├── Needs
  │      └── Assistance
  │
  ├── Case Notes
  │
  ├── Documents
  │
  └── Change Requests
          │
          ├── Proposed Data
          ├── Supporting Documents
          └── Workflow Events
```

---

# 94. Family Portal Data Flow

```text
User
  ↓
User-Person Link
  ↓
Person
  ↓
Family Membership
  ↓
Authorized Family
  ↓
Permitted API Representation
  ↓
Next.js Family Portal
```

For updates:

```text
Family Portal
      ↓
Proposed Input
      ↓
Laravel Validation
      ↓
Change Request
      ↓
Staff Review
      ↓
Approval
      ↓
Domain Action
      ↓
Canonical PostgreSQL Data
```

---

# 95. Canonical Data Boundary

Canonical registry entities include:

```text
Family

Person

Family Membership

Person Relationship

Residence

Verified Person Attributes

Approved Health Information

Approved Documents

Needs

Assistance
```

Change Requests are workflow/proposal records.

Frontend state is presentation state.

Notifications are communication records.

Audit records are evidence of system activity.

These concepts must remain separate.

---

# 96. Document Status

```text
Project: Famboook
Document: Data Dictionary
Version: 1.2.9
Status: APPROVED
Date: 2026-09-24
```

---

# 97. Change Log

| Version | Date | Status | Description |
|---|---|---|---|
| 1.0 | 2026-09-22 | Superseded | Initial Data Dictionary |
| 1.1 | 2026-09-22 | Superseded | Added User-Person Links, Family Portal data concepts, Change Requests, documents, notifications, classification, and controlled self-service |
| 1.2 | 2026-09-22 | Approved | Synchronized `persons.death_date`, clarified canonical vs proposed data, PostgreSQL canonical storage, API representation boundaries, frontend-state boundaries, private documents, and the new Next.js/Laravel API architecture |
| 1.2.9 | 2026-09-24 | Approved | Assistance V1-B: `execution_mode`, beneficiary APPROVED/REJECTED/NOT_DELIVERED, §36e deliveries, §36f issued lists and the export field catalog; `persons.marital_status` (§10) |
| 1.2.8 | 2026-09-24 | Approved | Assistance V1-A: Need/Assistance/Nomination/Delivery distinction, §36a `assistances` + items, §36b `assistance_categories`, §36c `assistance_beneficiaries` (nominees), §36d targeting criteria keys; `assistance_nominee` activity subject |
| 1.2.7 | 2026-09-24 | Approved | Needs Management V1: §34 V1 `family_needs` fields, §34a `need_categories` (14 V1 categories), §35 V1 statuses OPEN/FULFILLED/CLOSED, `need` activity subject in §61a |
| 1.2.6 | 2026-09-24 | Approved | Quick Multi-Domain Family Assessment V1: §27 V1 implementation fields, §27a `assessment_domains` (8 V1 domains), §27b `assessment_results` and rating scale (absence = not assessed), `assessment` activity subject in §61a |
| 1.2.5 | 2026-09-24 | Approved | Added §61a "Family Activity" (Family Activity Log V1): fields, allow-listed metadata, actor ≠ researcher, no backfill; PDD-025 note |
| 1.2.4 | 2026-09-24 | Approved | §22: all health record types closable, gender integrity with active pregnancy/breastfeeding, deceased records kept as history, no minimum maternal age in V1 |
| 1.2.3 | 2026-09-24 | Approved | Health & Special-Needs Records V1: unified person-based `person_health_records` (§22) replacing the separate health-condition/disability proposals, `disability_types` V1 baseline, derived family health indicators (today vs future collection date) |
| 1.2.2 | 2026-09-23 | Approved | Added residence displacement fields (§19: `original_residence_text`, `displacement_status` V1 values, `displacement_location_text`), `persons.alternate_mobile_owner_relation` (§9-10) and PDD-025 (researcher/collection metadata direction) |
| 1.2.1 | 2026-09-23 | Approved | Adopted V1 operational relationship-type baseline (§15). Single canonical SPOUSE code; PDD-005 remains open for final taxonomy review |

---

# 98. Final Data Principle

Famboook data architecture follows:

```text
Real-world Entity
        ↓
Canonical Domain Model
        ↓
Controlled Laravel Domain Rules
        ↓
PostgreSQL
        ↓
Authorized API Representation
        ↓
User Experience
```

not:

```text
Paper Form
   ↓
Database Columns
   ↓
Generic CRUD Screen
```

The database models the domain.

The API controls exposure.

The frontend presents the product.