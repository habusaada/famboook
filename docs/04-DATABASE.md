# Famboook
## Database Architecture

**Document:** `04-DATABASE.md`  
**Version:** 1.2.8  
**Status:** Approved  
**Last Updated:** 2026-09-25  
**Project:** Famboook — Family Registry & Case Management System  
**Database:** PostgreSQL 16+  
**Backend:** Laravel 12

---

# 1. Purpose

This document defines the physical database architecture for Famboook.

It translates the Product Definition, Data Dictionary, and Business Rules into an implementation-oriented PostgreSQL design.

This document defines:

- Core tables
- Primary and foreign keys
- Important columns
- Constraints
- Partial unique indexes
- JSONB usage
- Historical data strategy
- Transaction boundaries
- Concurrency requirements
- Migration order
- Laravel database responsibilities
- Database security boundaries

Business meaning remains defined primarily in:

```text
01-PRODUCT.md
02-DATA-DICTIONARY.md
03-BUSINESS-RULES.md
```

---

# 2. Database Platform

Famboook uses:

```text
PostgreSQL 16+
```

as the canonical persistent database.

PostgreSQL was selected because Famboook is strongly relational and requires:

- Referential integrity
- Transactional consistency
- Partial unique indexes
- Strong constraints
- JSONB for controlled variable payloads
- Advanced indexing
- Reliable concurrency
- Potential advanced search capabilities

---

# 3. Database Boundary

PostgreSQL is not exposed directly to frontend applications.

Required architecture:

```text
Next.js
   ↓
Laravel API
   ↓
Domain Actions
   ↓
Eloquent / Query Layer
   ↓
PostgreSQL
```

Prohibited architecture:

```text
Next.js
   ↓
PostgreSQL
```

Database credentials must remain server-side.

---

# 4. Canonical Source of Truth

PostgreSQL stores the canonical persistent state of Famboook.

However:

```text
Database
≠
Business Logic Layer
```

Business operations remain controlled by Laravel.

The preferred protection model is:

```text
Frontend UX
    ↓
Laravel Validation
    ↓
Laravel Authorization
    ↓
Domain Rules
    ↓
Transaction
    ↓
PostgreSQL Constraints
```

---

# 5. Core Database Domains

```text
Identity & Access
├── users
├── user_person_links
├── roles / permissions
└── notifications

Registry
├── families
├── persons
├── family_memberships
├── relationship_types
├── person_relationships
└── family_residences

Person Information
├── marital_statuses
├── person_health_profiles
├── person_health_records
├── disability_types
├── person_education
└── person_employment

Assessment
├── assessments
├── form_submissions
└── workflow_events

Self-Service
├── change_request_types
└── change_requests

Documents
└── documents

Case Management
├── family_needs
├── assistance_records
├── person_notes
└── case_notes

Platform
├── audit infrastructure
├── jobs
├── failed_jobs
└── cache/session infrastructure where applicable
```

---

# 6. Primary Keys

Core relational tables use:

```text
BIGINT
```

primary keys through Laravel's standard big integer identity strategy.

Example:

```text
id BIGINT PRIMARY KEY
```

Internal database IDs are separate from user-facing business identifiers.

---

# 7. Business Identifiers

Important entities receive stable business codes.

Examples:

```text
Family
FAM-000001

Person
PER-000001

Change Request
CRQ-000001
```

Business codes should have unique indexes.

They must not be reused.

---

# 8. Timestamps

System timestamps use:

```text
created_at
updated_at
```

where appropriate.

Operational event timestamps may include:

```text
submitted_at
reviewed_at
approved_at
rejected_at
applied_at
verified_at
```

Application/system timestamps should be stored consistently in UTC.

---

# 9. Dates vs Timestamps

Real-world dates where time-of-day is irrelevant use PostgreSQL:

```text
DATE
```

Examples:

```text
birth_date
death_date
registration_date
issue_date
expiry_date
```

System events use timestamps.

---

# 10. Soft Deletes

Selected entities may use:

```text
deleted_at
```

Soft deletion must not be used as a substitute for domain history.

Example:

```text
Person leaves Family
```

must end the membership.

It must not delete the membership.

---

# 11. Families Table

```text
families
```

Recommended structure:

```text
id BIGINT PK

family_code VARCHAR UNIQUE NOT NULL

clan_id BIGINT NOT NULL FK clans.id          (V1, §12a)
branch_id BIGINT NULL                       (V1, §12a)

status VARCHAR NOT NULL

registration_date DATE NULL

registration_source VARCHAR NULL

paper_form_no VARCHAR NULL

notes TEXT NULL

created_by BIGINT NULL FK users.id
updated_by BIGINT NULL FK users.id

created_at TIMESTAMP
updated_at TIMESTAMP
deleted_at TIMESTAMP NULL
```

---

# 12. Family Indexes

Recommended:

```text
UNIQUE family_code

INDEX status

INDEX paper_form_no

INDEX registration_date
```

Additional indexes should be based on actual query patterns.

---

# 12a. Clan Structure Tables (V1, 2026-09-25)

Clan → Branch Groups → Branches → Families (docs/02 §7a–§7c; Clan ≠ Family).

```text
clans
  id BIGINT PK
  uuid UUID UNIQUE
  code VARCHAR(50) UNIQUE NOT NULL
  name VARCHAR(150) NOT NULL
  is_active BOOLEAN NOT NULL DEFAULT true
  created_at, updated_at

branch_groups
  id BIGINT PK
  uuid UUID UNIQUE
  clan_id BIGINT NOT NULL FK clans.id ON DELETE RESTRICT
  code VARCHAR(50) NOT NULL
  name VARCHAR(150) NULL                     -- unnamed container allowed
  sort_order INT NOT NULL DEFAULT 0
  is_active BOOLEAN NOT NULL DEFAULT true
  created_at, updated_at
  UNIQUE (clan_id, code)
  UNIQUE (id, clan_id)                       -- composite FK target

branches
  id BIGINT PK
  uuid UUID UNIQUE
  branch_group_id BIGINT NOT NULL
  clan_id BIGINT NOT NULL FK clans.id ON DELETE RESTRICT
  code VARCHAR(50) NOT NULL
  name VARCHAR(150) NOT NULL
  sort_order INT NOT NULL DEFAULT 0
  is_active BOOLEAN NOT NULL DEFAULT true
  created_at, updated_at
  FK fk_branches_group_clan (branch_group_id, clan_id)
     → branch_groups (id, clan_id) ON DELETE RESTRICT
  UNIQUE (clan_id, code)
  UNIQUE (id, clan_id)                       -- composite FK target

families (added)
  clan_id BIGINT NOT NULL FK clans.id ON DELETE RESTRICT
  branch_id BIGINT NULL
  FK fk_families_branch_clan (branch_id, clan_id)
     → branches (id, clan_id) ON DELETE RESTRICT   -- MATCH SIMPLE: NULL branch not checked
  INDEX (clan_id, branch_id)
```

The composite foreign keys make "a Branch belongs to its Group's Clan" and
"a Family's Branch belongs to the Family's Clan" database invariants.
`branch_group_id` is intentionally **not** stored on `families`.

Migration `2026_10_01_090001` inserts `AL_BREEM` / عائلة البريم if missing,
backfills `clan_id` on every existing family (soft-deleted included), then
makes it NOT NULL. `branch_id` stays NULL; nothing else changes.

API (docs/06 §56a):

```text
GET   /api/v1/reference/clans                      clan.view  (active tree; ?include_inactive=1 needs clan.manage)
GET   /api/v1/clans/{clan}/branch-groups           clan.view
GET   /api/v1/clans/{clan}/branches?group={uuid}   clan.view
POST  /api/v1/clans                                clan.manage
PATCH /api/v1/clans/{clan}                         clan.manage
POST  /api/v1/clans/{clan}/branch-groups           clan.manage
PATCH /api/v1/branch-groups/{group}                clan.manage
POST  /api/v1/branch-groups/{group}/branches       clan.manage
PATCH /api/v1/branches/{branch}                    clan.manage
```

Route keys are UUIDs. Updates change name, sort_order and is_active only;
codes are immutable. There is no DELETE endpoint. Family registration/update
accept `clan_code` (required on create) and `branch_code` (nullable).

---

# 13. Persons Table

```text
persons
```

Recommended structure:

```text
id BIGINT PK

person_code VARCHAR UNIQUE NOT NULL

full_name VARCHAR NOT NULL

national_id VARCHAR NULL

gender VARCHAR NULL

birth_date DATE NULL

marital_status_id BIGINT NULL FK marital_statuses.id

life_status VARCHAR NOT NULL

death_date DATE NULL

mobile VARCHAR NULL

alternate_mobile VARCHAR NULL
alternate_mobile_owner_relation VARCHAR NULL

notes TEXT NULL

is_active BOOLEAN NOT NULL DEFAULT TRUE

created_by BIGINT NULL FK users.id
updated_by BIGINT NULL FK users.id

created_at TIMESTAMP
updated_at TIMESTAMP
deleted_at TIMESTAMP NULL
```

---

# 14. Person Death Constraint

PostgreSQL should protect the basic chronological invariant:

```sql
CHECK (
    death_date IS NULL
    OR birth_date IS NULL
    OR death_date >= birth_date
)
```

Laravel additionally enforces:

```text
death_date cannot be future

death_date normally requires DECEASED

ALIVE normally requires death_date NULL
```

These application rules are intentionally not all encoded as rigid database constraints until lifecycle requirements are fully proven.

---

# 15. National ID Index

Initially:

```text
INDEX national_id
```

An unconditional unique constraint should not be introduced until:

```text
Data quality
Normalization
Duplicate policy
Legacy records
```

have been validated.

A future unique normalized/hash index may be introduced through an approved migration.

---

# 16. Person Search Indexes

Initial candidates:

```text
INDEX person_code
INDEX national_id
INDEX mobile
INDEX birth_date
INDEX life_status
```

Name-search indexing should be designed after Arabic search behavior is tested.

Potential future PostgreSQL capability:

```text
pg_trgm
```

but it is not required for the first migration.

---

# 17. Marital Statuses Table

```text
marital_statuses
```

Recommended:

```text
id BIGINT PK
code VARCHAR UNIQUE NOT NULL
name VARCHAR NOT NULL
is_active BOOLEAN DEFAULT TRUE
sort_order INTEGER DEFAULT 0
created_at
updated_at
```

Application logic should depend on:

```text
code
```

not translated `name`.

---

# 18. Relationship Types Table

```text
relationship_types
```

Recommended:

```text
id BIGINT PK
code VARCHAR UNIQUE NOT NULL
name VARCHAR NOT NULL
description TEXT NULL
is_active BOOLEAN DEFAULT TRUE
sort_order INTEGER DEFAULT 0
created_at
updated_at
```

---

# 19. Family Memberships Table

```text
family_memberships
```

Recommended:

```text
id BIGINT PK

family_id BIGINT NOT NULL FK families.id

person_id BIGINT NOT NULL FK persons.id

relationship_type_id BIGINT NULL FK relationship_types.id

is_household_head BOOLEAN NOT NULL DEFAULT FALSE

paper_sequence_no INTEGER NULL

started_at DATE NULL

ended_at DATE NULL

is_active BOOLEAN NOT NULL DEFAULT TRUE

end_reason VARCHAR NULL

notes TEXT NULL

created_by BIGINT NULL FK users.id
updated_by BIGINT NULL FK users.id

created_at
updated_at
```

---

# 20. One Active Family Membership

V1 requires at most one active primary Family membership per Person.

PostgreSQL partial unique index:

```sql
CREATE UNIQUE INDEX uq_person_active_family_membership
ON family_memberships (person_id)
WHERE is_active = TRUE;
```

This protects against concurrent creation of multiple active memberships.

---

# 21. One Active Household Head

V1 permits at most one active Household Head per Family.

```sql
CREATE UNIQUE INDEX uq_family_active_household_head
ON family_memberships (family_id)
WHERE is_active = TRUE
AND is_household_head = TRUE;
```

Laravel must still provide meaningful validation errors.

---

# 22. Membership Date Integrity

Recommended basic constraint:

```sql
CHECK (
    ended_at IS NULL
    OR started_at IS NULL
    OR ended_at >= started_at
)
```

Application logic additionally ensures active/inactive state consistency.

---

# 23. Person Relationships Table

```text
person_relationships
```

Recommended:

```text
id BIGINT PK

person_id BIGINT NOT NULL FK persons.id

related_person_id BIGINT NOT NULL FK persons.id

relationship_type_id BIGINT NOT NULL FK relationship_types.id

started_at DATE NULL
ended_at DATE NULL
is_active BOOLEAN DEFAULT TRUE

notes TEXT NULL

created_by BIGINT NULL FK users.id
updated_by BIGINT NULL FK users.id

created_at
updated_at
```

Constraint:

```sql
CHECK (person_id <> related_person_id)
```

---

# 24. Family Residences Table

```text
family_residences
```

Recommended:

```text
id BIGINT PK

family_id BIGINT NOT NULL FK families.id

residence_type VARCHAR NULL

governorate VARCHAR NULL
city VARCHAR NULL
area VARCHAR NULL
neighborhood VARCHAR NULL
address_text TEXT NULL
original_residence_text VARCHAR NULL

latitude NUMERIC NULL
longitude NUMERIC NULL

displacement_status VARCHAR NULL
displacement_location_text VARCHAR NULL

started_at DATE NULL
ended_at DATE NULL

is_current BOOLEAN NOT NULL DEFAULT TRUE

source VARCHAR NULL
notes TEXT NULL

created_by BIGINT NULL FK users.id
updated_by BIGINT NULL FK users.id

created_at
updated_at
```

---

Displacement constraints (V1, see docs/02 §19):

```sql
CHECK (displacement_status IS NULL
       OR displacement_status IN ('DISPLACED', 'NOT_DISPLACED'));

CHECK (displacement_location_text IS NULL
       OR displacement_status = 'DISPLACED');
```

`displacement_status` stays nullable: `NULL` = not collected.

---

# 25. One Current Residence

```sql
CREATE UNIQUE INDEX uq_family_current_residence
ON family_residences (family_id)
WHERE is_current = TRUE;
```

Changing residence should execute transactionally.

---

# 26. Health Profiles

```text
person_health_profiles
```

Recommended structure may include:

```text
id
person_id
summary
notes
created_by
updated_by
created_at
updated_at
```

Exact fields remain subject to health-domain requirements.

---

# 27. Person Health Records

Approved 2026-09-24 (see docs/02 §22). This replaces the separate
`person_health_conditions` / `person_disabilities` proposals.

```text
person_health_records
```

```text
id BIGINT PK
uuid UUID NOT NULL UNIQUE             public API identifier
person_id BIGINT NOT NULL FK persons.id (RESTRICT)
type VARCHAR NOT NULL                 DISABILITY | CHRONIC_DISEASE | PREGNANCY | BREASTFEEDING
disability_type_id BIGINT NULL FK disability_types.id (RESTRICT)
condition_name VARCHAR NULL
details TEXT NULL
started_at DATE NULL
ended_at DATE NULL                    NULL = active
created_by BIGINT NULL FK users.id
updated_by BIGINT NULL FK users.id
created_at
updated_at
```

Multiple records per Person are allowed. There is no hard delete in V1:
records are closed by setting `ended_at`.

Duplicate-active guards (partial unique indexes):

```sql
CREATE UNIQUE INDEX uq_health_active_maternal
ON person_health_records (person_id, type)
WHERE ended_at IS NULL AND type IN ('PREGNANCY', 'BREASTFEEDING');

CREATE UNIQUE INDEX uq_health_active_disability
ON person_health_records (person_id, disability_type_id)
WHERE ended_at IS NULL AND type = 'DISABILITY';

CREATE UNIQUE INDEX uq_health_active_chronic
ON person_health_records (person_id, lower(condition_name))
WHERE ended_at IS NULL AND type = 'CHRONIC_DISEASE';
```

PostgreSQL CHECK constraints:

- `chk_health_record_type`: `type` is one of the four values.
- `chk_health_record_shape`: DISABILITY has a `disability_type_id` and no
  `condition_name`; CHRONIC_DISEASE has a `condition_name` and no
  `disability_type_id`; PREGNANCY/BREASTFEEDING have neither.
- `chk_health_record_dates`: `ended_at` is not before `started_at`.

The application enforces the rules the database cannot:

- pregnancy/breastfeeding are FEMALE-only;
- the Person must be an active member of the Family (on create);
- chronic-disease duplicates are compared after Arabic normalization.

Family health indicators are derived, not stored (docs/02 §22).

---

# 28. Disability Types

```text
disability_types
```

Same shape as `relationship_types` (§18): `code` (unique), `name`,
`description`, `is_active`, `sort_order`, timestamps. V1 values are listed in
docs/02 §22. Types are deactivated, never deleted, once used.

---

# 29. Education

```text
person_education
```

Recommended:

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

# 30. Employment

```text
person_employment
```

Recommended:

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

# 31. Assessments

```text
assessments
```

Recommended:

```text
id BIGINT PK

assessment_code VARCHAR UNIQUE NOT NULL

family_id BIGINT NOT NULL FK families.id

assessment_type_id BIGINT NULL

status VARCHAR NOT NULL

assessment_date DATE NULL

assigned_to BIGINT NULL FK users.id

created_by BIGINT NULL FK users.id
updated_by BIGINT NULL FK users.id

created_at
updated_at
```

## V1 Implementation (2026-09-24)

Quick Multi-Domain Family Assessment (docs/03 §40a):

```text
assessments
id BIGINT PK
uuid UUID NOT NULL UNIQUE             public API identifier / route key
family_id BIGINT NOT NULL FK families.id (RESTRICT)
assessment_date DATE NOT NULL         business date, not created_at
status VARCHAR NOT NULL               DRAFT | COMPLETED
general_notes TEXT NULL
created_by BIGINT NULL FK users.id (SET NULL)
updated_by BIGINT NULL FK users.id (SET NULL)
completed_at TIMESTAMP NULL
completed_by BIGINT NULL FK users.id (SET NULL)
created_at, updated_at

INDEX (family_id, assessment_date, created_at, id)
CHECK status IN ('DRAFT', 'COMPLETED')                          (PostgreSQL)
CHECK (DRAFT ∧ completed_at IS NULL) ∨ (COMPLETED ∧ completed_at IS NOT NULL)
```

There is deliberately **no** unique constraint on
`(family_id, assessment_date)`.

```text
assessment_results
id BIGINT PK
assessment_id BIGINT NOT NULL FK assessments.id (RESTRICT)
assessment_domain_id BIGINT NOT NULL FK assessment_domains.id (RESTRICT)
rating VARCHAR NOT NULL               NONE | LOW | MEDIUM | HIGH | CRITICAL
notes TEXT NULL
created_at, updated_at

UNIQUE (assessment_id, assessment_domain_id)
CHECK rating IN (...)                                            (PostgreSQL)
```

- No `NOT_ASSESSED` value: a missing row means the domain was not assessed.
- No stored scores or counts.
- The models refuse to update a COMPLETED assessment, to create/change/
  delete results of a COMPLETED assessment, and to delete any assessment.
- Not implemented in V1: `assessment_code`, `assessment_type_id`,
  `assigned_to`, `form_submissions`.

API (`/api/v1`):

```text
GET   /families/{family}/assessments       assessment.view   newest assessment_date, then newest entry; paginated (per_page 20, max 50)
POST  /families/{family}/assessments       assessment.create creates a DRAFT
GET   /assessments/{uuid}                  assessment.view
PATCH /assessments/{uuid}                  assessment.update DRAFT only (409 once COMPLETED)
POST  /assessments/{uuid}/complete         assessment.complete (+ assessment.update if a final draft payload is sent)
GET   /reference/assessment-domains        reference-data.view OR assessment.view; active domains only
```

Draft payload: `assessment_date`, `general_notes`, and `results` as
`[{domain_code, rating, notes}]`. When `results` is sent it is the full
result set, synchronized transactionally; duplicate domains are rejected.
There is no delete endpoint.

---

# 31a. Assessment Domains

```text
assessment_domains
id BIGINT PK
code VARCHAR UNIQUE NOT NULL
name VARCHAR NOT NULL
description TEXT NULL
is_active BOOLEAN NOT NULL DEFAULT TRUE
sort_order INTEGER NOT NULL DEFAULT 0
created_at, updated_at
```

Same shape as `relationship_types` (§18) and `disability_types` (§28).
Seeded idempotently with the eight V1 domains (docs/02 §27a); the seeder
never reactivates a deactivated domain.

---

# 32. Form Submissions

```text
form_submissions
```

Recommended:

```text
id BIGINT PK

assessment_id BIGINT NULL FK assessments.id

form_type VARCHAR NOT NULL
form_version VARCHAR NULL

status VARCHAR NOT NULL

submitted_data JSONB NULL

submitted_by BIGINT NULL FK users.id
submitted_at TIMESTAMP NULL

verified_by BIGINT NULL FK users.id
verified_at TIMESTAMP NULL

approved_by BIGINT NULL FK users.id
approved_at TIMESTAMP NULL

created_at
updated_at
```

JSONB is appropriate for versioned form answers when the form architecture requires flexible schemas.

It must not replace normalized canonical Person/Family data.

---

# 33. Workflow Events

```text
workflow_events
```

Recommended:

```text
id BIGINT PK

workflowable_type VARCHAR NOT NULL
workflowable_id BIGINT NOT NULL

from_status VARCHAR NULL
to_status VARCHAR NOT NULL

event_type VARCHAR NOT NULL

actor_id BIGINT NULL FK users.id

comment TEXT NULL

metadata JSONB NULL

created_at TIMESTAMP NOT NULL
```

Workflow events are append-only.

---

# 34. Workflow Indexes

Recommended:

```text
INDEX (workflowable_type, workflowable_id)

INDEX actor_id

INDEX created_at

INDEX to_status
```

---

# 35. Workflow Events vs Audit

`workflow_events` records:

```text
Process transitions
```

Audit records:

```text
Data/system changes
```

These must remain conceptually separate.

An operation may generate both.

---

# 36. Change Request Types

```text
change_request_types
```

Recommended:

```text
id BIGINT PK

code VARCHAR UNIQUE NOT NULL

name VARCHAR NOT NULL

description TEXT NULL

risk_level VARCHAR NOT NULL

requires_document BOOLEAN NOT NULL DEFAULT FALSE

is_active BOOLEAN NOT NULL DEFAULT TRUE

sort_order INTEGER NOT NULL DEFAULT 0

created_at
updated_at
```

---

# 37. Change Requests

```text
change_requests
```

Recommended:

```text
id BIGINT PK

request_code VARCHAR UNIQUE NOT NULL

family_id BIGINT NOT NULL FK families.id

person_id BIGINT NULL FK persons.id

change_request_type_id BIGINT NOT NULL
    FK change_request_types.id

status VARCHAR NOT NULL

risk_level VARCHAR NULL

submitted_data JSONB NOT NULL

reason TEXT NULL

notes TEXT NULL

submitted_by BIGINT NOT NULL FK users.id
submitted_at TIMESTAMP NULL

reviewed_by BIGINT NULL FK users.id
reviewed_at TIMESTAMP NULL

review_notes TEXT NULL

approved_by BIGINT NULL FK users.id
approved_at TIMESTAMP NULL

rejected_by BIGINT NULL FK users.id
rejected_at TIMESTAMP NULL

rejection_reason TEXT NULL

applied_by BIGINT NULL FK users.id
applied_at TIMESTAMP NULL

created_at
updated_at
```

---

# 38. Change Request JSONB

`submitted_data` contains only proposed data.

Example:

```json
{
  "mobile": "0590000000",
  "alternate_mobile": "0560000000"
}
```

It must not contain executable database instructions.

Laravel selects validation rules and Domain Action according to:

```text
change_request_type
```

---

# 39. Change Request Indexes

Recommended:

```text
UNIQUE request_code

INDEX family_id

INDEX person_id

INDEX status

INDEX change_request_type_id

INDEX submitted_by

INDEX submitted_at

INDEX (family_id, status)
```

Additional indexes should follow measured query needs.

---

# 40. Change Request Application

Application architecture:

```text
Approved Change Request
        ↓
Lock Request
        ↓
Revalidate
        ↓
Domain Action
        ↓
Canonical Mutation
        ↓
Audit
        ↓
Workflow Event
        ↓
APPLIED
```

This operation must be transactional.

---

# 41. Change Request Locking

Application should use row locking where required.

Laravel example concept:

```php
ChangeRequest::query()
    ->whereKey($id)
    ->lockForUpdate()
    ->firstOrFail();
```

The exact implementation belongs in the Domain Action.

---

# 42. Change Request Idempotency

Before applying:

```text
status must equal APPROVED
```

If:

```text
status = APPLIED
```

the canonical mutation must not execute again.

---

# 43. Application Failure

If the Domain Action fails:

```text
ROLLBACK
```

The request must not become APPLIED.

Default V1 behavior:

```text
Remain APPROVED
```

An authorized retry may occur.

A future `APPLICATION_FAILED` status remains optional.

---

# 44. Documents Table

```text
documents
```

Recommended:

```text
id BIGINT PK

family_id BIGINT NULL FK families.id

person_id BIGINT NULL FK persons.id

change_request_id BIGINT NULL FK change_requests.id

document_type_id BIGINT NOT NULL

document_number VARCHAR NULL

is_available BOOLEAN NOT NULL DEFAULT TRUE

is_verified BOOLEAN NOT NULL DEFAULT FALSE

issue_date DATE NULL
expiry_date DATE NULL

file_path TEXT NULL

uploaded_by BIGINT NULL FK users.id

verified_by BIGINT NULL FK users.id
verified_at TIMESTAMP NULL

notes TEXT NULL

created_at
updated_at
```

---

# 45. Document Context Constraint

At least one document context should exist:

```sql
CHECK (
    family_id IS NOT NULL
    OR person_id IS NOT NULL
    OR change_request_id IS NOT NULL
)
```

Application rules determine valid combinations.

---

# 46. Private Document Storage

`file_path` represents a private storage reference.

It must not imply public browser accessibility.

Prohibited assumption:

```text
file_path
=
public URL
```

Authorized file delivery must occur through Laravel.

---

# 47. Family Needs

```text
family_needs
```

Recommended:

```text
id BIGINT PK

family_id BIGINT NOT NULL FK families.id

person_id BIGINT NULL FK persons.id

need_type_id BIGINT NOT NULL

priority VARCHAR NULL

status VARCHAR NOT NULL

identified_at TIMESTAMP NULL
identified_by BIGINT NULL FK users.id

closed_at TIMESTAMP NULL
closed_by BIGINT NULL FK users.id

source VARCHAR NULL
notes TEXT NULL

created_at
updated_at
```

## V1 Implementation (2026-09-24)

Needs Management (docs/03 §46a):

```text
family_needs
id BIGINT PK
uuid UUID NOT NULL UNIQUE               public API identifier / route key
family_id BIGINT NOT NULL FK families.id (RESTRICT)
person_id BIGINT NULL FK persons.id (RESTRICT)          NULL = family-level
source_assessment_id BIGINT NULL FK assessments.id (RESTRICT)
need_category_id BIGINT NOT NULL FK need_categories.id (RESTRICT)
title VARCHAR(150) NOT NULL
description TEXT NULL
priority VARCHAR NOT NULL               LOW | MEDIUM | HIGH | URGENT
quantity DECIMAL(12,2) NULL
unit VARCHAR(30) NULL
status VARCHAR NOT NULL                 OPEN | FULFILLED | CLOSED
resolved_at TIMESTAMP NULL
resolved_by BIGINT NULL FK users.id (SET NULL)
closure_reason TEXT NULL
created_by BIGINT NULL FK users.id (SET NULL)
updated_by BIGINT NULL FK users.id (SET NULL)
created_at, updated_at

INDEX (family_id, status)
INDEX (status, priority, created_at)
CHECK status / priority values                                  (PostgreSQL)
CHECK (quantity IS NULL OR quantity > 0) AND (unit IS NULL OR quantity IS NOT NULL)
CHECK OPEN: resolved_at, resolved_by, closure_reason all NULL;
      FULFILLED: resolved_at NOT NULL, closure_reason NULL;
      CLOSED: resolved_at NOT NULL, closure_reason NOT NULL
```

- The model refuses to update a resolved Need or to delete any Need.
- Same-family person/assessment rules are enforced by the Domain Actions
  (`CreateNeedAction`, `UpdateNeedAction`, `FulfillNeedAction`,
  `CloseNeedAction`).

API (`/api/v1`):

```text
GET   /families/{family}/needs          need.view    filters status, priority, category, target=family|person; derived summary
POST  /families/{family}/needs          need.create  creates OPEN
GET   /needs                            need.view    cross-family work queue, same filters
GET   /needs/{uuid}                     need.view
PATCH /needs/{uuid}                     need.update  OPEN only (409 otherwise); status/resolution fields prohibited
POST  /needs/{uuid}/fulfill             need.close   OPEN → FULFILLED
POST  /needs/{uuid}/close               need.close   OPEN → CLOSED, closure_reason required
GET   /reference/need-categories        reference-data.view OR need.view; active only
```

Default order: OPEN first, then URGENT → HIGH → MEDIUM → LOW, then newest
`created_at`. Paginated (per_page 20, max 50). Payload identifiers:
`person_code`, `source_assessment_id` (assessment UUID), `category_code`.
No DELETE endpoint.

---

# 47a. Need Categories

```text
need_categories
id BIGINT PK
code VARCHAR UNIQUE NOT NULL
name VARCHAR NOT NULL
description TEXT NULL
is_active BOOLEAN NOT NULL DEFAULT TRUE
sort_order INTEGER NOT NULL DEFAULT 0
created_at, updated_at
```

Same shape as the other reference tables. Seeded idempotently with the
14 V1 categories (docs/02 §34a); never reactivates a deactivated one.

---

# 48. Assistance Records

```text
assistance_records
```

Recommended:

```text
id BIGINT PK

family_id BIGINT NOT NULL FK families.id

person_id BIGINT NULL FK persons.id

need_id BIGINT NULL FK family_needs.id

assistance_type_id BIGINT NOT NULL

provider VARCHAR NULL

quantity NUMERIC NULL
unit VARCHAR NULL

value NUMERIC NULL
currency VARCHAR NULL

provided_at TIMESTAMP NULL

recorded_by BIGINT NULL FK users.id

reference_no VARCHAR NULL

notes TEXT NULL

created_at
updated_at
```

`assistance_records` is the future **delivery** record (Assistance V1-B)
and is not implemented yet.

---

# 48a. Assistances (V1-A)

Approved 2026-09-24 (docs/03 §47a).

```text
assistances
id BIGINT PK
uuid UUID NOT NULL UNIQUE                 public API identifier / route key
title VARCHAR(150) NOT NULL
assistance_category_id BIGINT NOT NULL FK assistance_categories.id (RESTRICT)
assistance_type VARCHAR NOT NULL          IN_KIND | CASH | SERVICE
provider_name VARCHAR(150) NOT NULL
target_beneficiaries INTEGER NULL         > 0
start_date DATE NULL
end_date DATE NULL                        >= start_date
description TEXT NULL
status VARCHAR NOT NULL                   DRAFT | OPEN | COMPLETED | CANCELLED
targeting_criteria JSON NULL              validated criteria snapshot
opened_at TIMESTAMP NULL
opened_by BIGINT NULL FK users.id (SET NULL)
created_by / updated_by BIGINT NULL FK users.id (SET NULL)
created_at, updated_at
INDEX (status, created_at)

assistance_items
id BIGINT PK
assistance_id BIGINT NOT NULL FK assistances.id (CASCADE)
item_name VARCHAR(150) NOT NULL
quantity_per_beneficiary DECIMAL(12,2) NULL  > 0
unit VARCHAR(30) NULL
unit_value DECIMAL(12,2) NULL                > 0
currency VARCHAR(3) NULL                     ILS | USD | JOD | EUR, iff unit_value
sort_order INTEGER NOT NULL
created_at, updated_at
```

PostgreSQL CHECK constraints enforce the status/type values, the date
order, the positive target and the item value/currency rules. Items are
replaced as a set while DRAFT and locked once OPEN. The Assistance model
refuses deletion.

---

# 48b. Assistance Categories

```text
assistance_categories   (id, code UNIQUE, name, description, is_active, sort_order, timestamps)
```

Same shape as the other reference tables; 14 V1 codes seeded idempotently
(docs/02 §36b); never reactivates a deactivated one.

---

# 48c. Assistance Beneficiaries (V1-A: nominees)

```text
assistance_beneficiaries
id BIGINT PK
uuid UUID NOT NULL UNIQUE
assistance_id BIGINT NOT NULL FK assistances.id (RESTRICT)
family_id BIGINT NOT NULL FK families.id (RESTRICT)
person_id BIGINT NULL FK persons.id (RESTRICT)          NULL = family-level
source_need_id BIGINT NULL FK family_needs.id (RESTRICT)
nomination_source VARCHAR NOT NULL                     TARGETING | MANUAL | NEED
targeting_criteria JSON NULL                           TARGETING snapshot
status VARCHAR NOT NULL                                NOMINATED | REMOVED (V1-B: APPROVED, REJECTED, NOT_DELIVERED)
nominated_at TIMESTAMP NOT NULL
nominated_by BIGINT NULL FK users.id (SET NULL)
removed_at TIMESTAMP NULL
removed_by BIGINT NULL FK users.id (SET NULL)
created_at, updated_at

UNIQUE (assistance_id, family_id) WHERE person_id IS NULL AND status <> 'REMOVED'
UNIQUE (assistance_id, person_id) WHERE person_id IS NOT NULL AND status <> 'REMOVED'
CHECK source values; (status = 'NOMINATED') ⇔ removed_at IS NULL;
      (nomination_source = 'NEED') ⇔ source_need_id IS NOT NULL      (PostgreSQL)
```

Rows are never deleted (the model refuses); removal sets REMOVED.

API (`/api/v1`):

```text
GET   /assistances                                   assistance.view      filters status, category, type
POST  /assistances                                   assistance.create    DRAFT with items
GET   /assistances/{uuid}                            assistance.view
PATCH /assistances/{uuid}                            assistance.update    DRAFT: all; OPEN: description/target/dates
POST  /assistances/{uuid}/open                       assistance.open      DRAFT → OPEN (≥ 1 item)
POST  /assistances/{uuid}/targeting-preview          assistance.nominate  read-only, paginated
GET   /assistances/{uuid}/nominees                   assistance.view      include_removed; derived summary
GET   /assistances/{uuid}/nominee-candidates         assistance.nominate  search by family/person code or name
POST  /assistances/{uuid}/nominees/manual            assistance.nominate
POST  /assistances/{uuid}/nominees/from-needs        assistance.nominate
POST  /assistances/{uuid}/nominees/from-targeting    assistance.nominate
POST  /assistances/{uuid}/nominees/{uuid}/remove     assistance.nominate  history-preserving
GET   /reference/assistance-categories               reference-data.view OR assistance.view
```

No DELETE endpoints. The global Needs queue (`GET /needs`) also accepts
an exact `family` code filter (used when nominating from Needs).

# 48d. Assistance V1-B schema

Approved 2026-09-24 (docs/03 §47d–§47i).

```text
persons
+ marital_status VARCHAR NOT NULL DEFAULT 'UNKNOWN'   SINGLE|MARRIED|DIVORCED|WIDOWED|UNKNOWN (CHECK, PostgreSQL)

assistances
+ execution_mode VARCHAR NOT NULL DEFAULT 'INTERNAL'  INTERNAL|EXTERNAL; existing rows → INTERNAL
+ export_fields JSON NULL                             [{field_key, column_label, sort_order}]
+ completed_at TIMESTAMP NULL, completed_by BIGINT NULL FK users (SET NULL)
  CHECK (status = 'COMPLETED') = (completed_at IS NOT NULL)

assistance_beneficiaries
+ approved_at/approved_by, rejected_at/rejected_by/rejection_reason,
  not_delivered_at/not_delivered_by/not_delivered_reason
  CHECK status IN (NOMINATED, REMOVED, APPROVED, REJECTED, NOT_DELIVERED) with
        matching timestamp/reason columns (PostgreSQL)
  (the V1-A partial unique indexes are recreated: SQLite rebuilds the table)

assistance_deliveries
id, uuid UNIQUE, assistance_beneficiary_id FK (RESTRICT), receipt_mode PERSONAL|DELEGATE,
original_beneficiary_person_id FK persons, recipient_person_id FK persons,
delivered_at, delivered_by FK users, notes,
reversed_at, reversed_by FK users, reversal_reason, timestamps
UNIQUE (assistance_beneficiary_id) WHERE reversed_at IS NULL
CHECK PERSONAL ⇔ recipient = original; DELEGATE ⇔ recipient ≠ original;
      (reversed_at IS NULL) = (reversal_reason IS NULL)             (PostgreSQL)
— no National ID column; model refuses delete and any change except one reversal

assistance_beneficiary_lists
id, uuid UNIQUE, assistance_id FK (RESTRICT), list_number UNIQUE (ABL-000001),
recipient_organization, issued_at, issued_by FK users, notes,
configuration_snapshot JSON, contains_sensitive BOOLEAN, row_count, created_at

assistance_beneficiary_list_entries
id, assistance_beneficiary_list_id FK (fk_abl_entries_list), assistance_beneficiary_id FK,
row_number, snapshot_data TEXT (encrypted JSON), created_at
UNIQUE (list, row_number), UNIQUE (list, beneficiary)
— both immutable (models refuse update/delete)
```

`snapshot_data` uses Laravel's `encrypted:array` cast (application key).
Rotating `APP_KEY` requires keeping the previous key in
`APP_PREVIOUS_KEYS`, otherwise issued lists become unreadable.

API additions (`/api/v1`):

```text
POST /assistances/{uuid}/complete                                   assistance.complete
POST /assistances/{uuid}/nominees/bulk-approve                      assistance.approve
POST /assistances/{uuid}/nominees/{uuid}/approve | /reject          assistance.approve
POST /assistances/{uuid}/nominees/{uuid}/delivery/verify            assistance.deliver   (no write)
POST /assistances/{uuid}/nominees/{uuid}/delivery                   assistance.deliver
POST /assistances/{uuid}/nominees/{uuid}/not-delivered              assistance.deliver
POST /assistance-deliveries/{uuid}/reverse                          assistance.reverse
GET  /assistances/{uuid}/export-fields                              assistance.view
PUT  /assistances/{uuid}/export-configuration                       assistance.export (+ export-sensitive for SENSITIVE fields)
POST /assistances/{uuid}/beneficiary-lists/preview                  assistance.export (+ sensitive)
POST /assistances/{uuid}/beneficiary-lists                          assistance.export (+ sensitive)
GET  /assistances/{uuid}/beneficiary-lists                          assistance.view      metadata only
GET  /assistance-beneficiary-lists/{uuid}                           assistance.export (+ sensitive)  snapshot rows
GET  /assistance-beneficiary-lists/{uuid}/download                  assistance.export (+ sensitive)  XLSX, no-store
GET  /families/{family}/assistances                                 assistance.view
```

National IDs travel only in the JSON bodies of the delivery endpoints.

---

# 49. Person Notes

```text
person_notes
```

Recommended:

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

# 50. Case Notes

```text
case_notes
```

Recommended:

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

Confidential notes must not be included in Family Portal API Resources.

---

# 51. Users

Laravel owns authentication identity through:

```text
users
```

Recommended logical fields:

```text
id
name
email
mobile
password
status
last_login_at
remember_token
created_at
updated_at
```

Exact authentication fields may evolve before implementation.

---

# 52. User and Person Separation

No database design should assume:

```text
users.id = persons.id
```

or:

```text
User always represents a Person
```

Staff users may have no Person record.

Family Users require explicit Person linkage.

---

# 53. User-Person Links

```text
user_person_links
```

Recommended:

```text
id BIGINT PK

user_id BIGINT NOT NULL FK users.id

person_id BIGINT NOT NULL FK persons.id

link_type VARCHAR NOT NULL

status VARCHAR NOT NULL

verified_by BIGINT NULL FK users.id
verified_at TIMESTAMP NULL

activated_at TIMESTAMP NULL

ended_at TIMESTAMP NULL
end_reason TEXT NULL

created_at
updated_at
```

---

# 54. User-Person Link Indexes

Recommended:

```text
INDEX user_id

INDEX person_id

INDEX status

INDEX (user_id, status)

INDEX (person_id, status)
```

Exact uniqueness depends on future representative/account policy.

Do not prematurely prevent future valid relationship models.

---

# 55. Family Portal Scope

The database supports dynamic authorization:

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

The system must not use:

```text
users.family_id
```

as the canonical authorization shortcut.

---

# 56. Notifications

V1 should use Laravel's standard database notification infrastructure unless implementation requirements justify a custom model.

Logical data includes:

```text
id
type
notifiable_type
notifiable_id
data
read_at
created_at
updated_at
```

Notification payloads should contain minimal sensitive data.

---

# 57. Roles and Permissions

Authorization uses:

```text
Spatie Laravel Permission
```

which introduces standard tables such as:

```text
roles
permissions
model_has_roles
model_has_permissions
role_has_permissions
```

Exact migration names follow the package-supported schema used at implementation time.

---

# 58. Role Is Not Data Scope

Database role assignment alone does not determine record access.

Example:

```text
FAMILY_USER
```

must additionally resolve:

```text
User-Person Link
Family Membership
Family Policy
Field Policy
```

---

# 59. Audit Infrastructure

Audit implementation should support:

```text
Actor

Event

Entity Type

Entity ID

Previous Values

New Values

Timestamp

Request / Context Metadata where appropriate
```

Exact package or custom implementation should be selected during implementation without weakening the logical audit requirements.

---

# 60. Audit Sensitive Data

Audit must not blindly duplicate secrets or unnecessarily replicate restricted data.

Sensitive values may require:

```text
Masking
Selective capture
Exclusion
```

depending on field classification.

---

# 59a. Family Activity Log (V1)

Approved 2026-09-24 (docs/02 §61a, docs/03 §97a). A lightweight,
family-scoped activity timeline. It does **not** satisfy the full audit
requirements of §59 (no previous/new values), which remain to be
implemented separately.

```text
family_activities
```

```text
id BIGINT PK
uuid UUID NOT NULL UNIQUE             public API identifier
family_id BIGINT NOT NULL FK families.id (RESTRICT)
actor_user_id BIGINT NULL FK users.id (SET NULL)
event_type VARCHAR NOT NULL
subject_type VARCHAR NULL             morph map: family | person | residence | health_record | assessment | need | assistance_nominee
subject_id BIGINT NULL
metadata JSON NULL                    allow-listed keys only
created_at TIMESTAMP NOT NULL

INDEX (family_id, created_at, id)
INDEX (subject_type, subject_id)
```

- Append-only: no `updated_at`. The model refuses update and delete.
- Written only by `App\Support\FamilyActivityLog`, called by Domain
  Actions after the business write and inside their transaction. It
  refuses to run outside a transaction and rejects non-allow-listed
  metadata keys.
- No backfill migration: pre-existing records have no activity.
- Read endpoint: `GET /api/v1/families/{family}/activities` (newest first,
  standard Laravel pagination, `per_page` default 20, max 50).

---

# 61. Laravel Models

Eloquent Models represent persistence entities.

Models must not become the primary location for complex business workflows.

Complex operations belong in:

```text
Domain Actions
```

---

# 62. Mass Assignment

Sensitive canonical Models must not allow uncontrolled mass assignment from API payloads.

Client input must pass through:

```text
Form Request / DTO
        ↓
Domain Action
        ↓
Explicit mutation
```

---

# 63. API Resources

Database Models must not be returned directly as unrestricted API payloads.

Laravel API Resources should provide context-specific representations.

Examples:

```text
FamilySummaryResource

FamilyDetailResource

PersonSummaryResource

PersonDetailResource

FamilyMemberResource

FamilyPortalPersonResource

ChangeRequestResource
```

---

# 64. Database Transaction Principle

Multi-record business operations must use database transactions.

Examples:

```text
Family creation with first membership/head

Membership transfer

Household Head change

Residence change

Record Person death where dependent changes occur

Apply Change Request
```

---

# 65. Family Creation Transaction

A Family creation flow may require:

```text
Create Family
    ↓
Create / Resolve Person
    ↓
Create Membership
    ↓
Assign Household Head
    ↓
Create Residence
```

If these are part of one atomic operation, failure must rollback the transaction.

---

# 66. Household Head Transaction

Conceptually:

```text
BEGIN

Lock relevant Family memberships

Validate current head

Validate new head

Unset old head

Set new head

Audit

COMMIT
```

The partial unique index provides additional protection.

---

# 67. Residence Transaction

Conceptually:

```text
BEGIN

Lock current Family residence

End old residence

Create new current residence

Audit

COMMIT
```

---

# 68. Membership Transfer Transaction

Conceptually:

```text
BEGIN

Lock Person memberships

Validate source

Validate destination

End source membership

Create destination membership

Reevaluate head/access state

Audit

COMMIT
```

---

# 69. Person Death Transaction

`RecordPersonDeathAction` may:

```text
Lock Person

Validate current state

Set life_status

Set death_date if known

Create audit record

Trigger Household Head review if applicable

Trigger Family User access reevaluation if applicable

COMMIT
```

The exact membership lifecycle effect of death must follow Business Rules rather than destructive deletion.

---

# 70. Concurrency

Concurrency protection is required where two valid requests could violate a domain invariant.

Tools may include:

```text
PostgreSQL transactions

SELECT ... FOR UPDATE

Laravel lockForUpdate()

Partial unique indexes

Unique constraints

Optimistic stale-state checks
```

---

# 71. Database Locks

Locks should be scoped narrowly to relevant rows.

Do not lock entire tables for ordinary business operations.

Examples of candidates:

```text
Family memberships during head change

Person memberships during transfer

Current residence during residence change

Change Request during application
```

---

# 72. Foreign Key Delete Strategy

Destructive cascade deletes should generally be avoided for canonical registry/history.

Preferred strategies include:

```text
RESTRICT

NO ACTION

SET NULL
```

depending on relationship semantics.

`CASCADE DELETE` should only be used where child data has no valid independent historical meaning.

---

# 73. Status Fields

Domain status fields should generally use:

```text
VARCHAR
```

combined with PHP enums / controlled constants.

This provides controlled application behavior without requiring database enum migrations for every workflow evolution.

---

# 74. JSONB Usage

JSONB is appropriate for controlled flexible payloads such as:

```text
Change Request proposed data

Versioned form responses

Workflow metadata

Notification payloads
```

JSONB must not be used as a shortcut to avoid relational modeling of core registry data.

Prohibited pattern:

```text
persons.data JSONB
```

containing the entire Person domain.

---

# 75. JSONB Validation

JSONB payload structure must be validated by Laravel according to context.

Example:

```text
CONTACT_UPDATE
```

and:

```text
DEATH_REPORT
```

must not accept the same arbitrary fields.

---

# 76. Sensitive Data Encryption

Selected sensitive fields may require application-level encryption.

Candidates may include:

```text
National ID

Selected health information

Selected document metadata
```

Exact encryption strategy remains pending until search/reporting requirements are finalized.

---

# 77. Searchable Encryption

If encrypted exact-match fields require searching, a separate keyed hash/HMAC search representation may be considered.

Conceptually:

```text
encrypted_national_id
+
national_id_search_hash
```

This must be deliberately designed.

It is not required in the initial schema until the security/search policy is approved.

---

# 78. Arabic Search

Canonical Arabic names must remain unchanged.

Future search support may use:

```text
Normalized search field

PostgreSQL generated/search representation

pg_trgm

Application-side normalization
```

after testing.

Do not prematurely modify canonical Arabic data.

---

# 79. Pagination

List APIs must use pagination.

The database should not assume entire tables will be loaded into memory.

Examples:

```text
Families

Persons

Change Requests

Assessments

Audit

Reports
```

---

# 80. N+1 Prevention

Laravel queries must explicitly load required relationships.

API Resources should not accidentally trigger uncontrolled N+1 query patterns.

Performance testing should include data-rich Family profile screens.

---

# 81. Reporting

Operational reports should query canonical data.

For complex reporting, future strategies may include:

```text
Optimized SQL

Materialized Views

Reporting Tables

Analytics Store
```

only when justified.

V1 should not prematurely duplicate canonical data into a separate warehouse.

---

# 82. Derived Counts

Values such as:

```text
family_size
child_count
adult_count
```

should normally be derived.

If later cached/materialized for performance, the cache is not the canonical source of truth.

---

# 83. Import Architecture

Imports should use staging/validation rather than direct uncontrolled inserts into canonical tables.

Conceptually:

```text
Upload
  ↓
Parse
  ↓
Validate
  ↓
Duplicate Check
  ↓
Preview / Errors
  ↓
Authorized Apply
  ↓
Domain Layer
  ↓
Canonical Tables
```

---

# 84. Export Architecture

Exports should be generated from authorized queries.

Export jobs may run asynchronously for large datasets.

Export generation must preserve:

```text
Data Scope
Field Visibility
Permission
Audit
```

---

# 85. Private Storage Metadata

The database stores only file metadata/references.

Binary documents should remain in configured private storage.

Storage implementation may later use:

```text
Local private disk

S3-compatible private storage

Other approved object storage
```

without changing core document semantics.

---

# 86. Laravel Sanctum Data

Laravel Sanctum is used for first-party web authentication.

For the primary cookie/session-based web architecture, authentication does not require storing bearer tokens in browser localStorage.

Standard Laravel session infrastructure may be used according to deployment configuration.

---

# 87. Session Storage

Session storage is an infrastructure choice.

Possible implementations include:

```text
Database

Redis

Other supported Laravel session driver
```

Redis is not required solely because Laravel supports it.

It should be introduced when justified operationally.

---

# 88. Queue Storage

Laravel Queue is used for asynchronous work.

Initial driver may be selected according to deployment requirements.

Potential future infrastructure:

```text
Database Queue
Redis Queue
```

The business architecture must not depend on Redis being present from day one.

---

# 89. Cache

Caching may be introduced for measured performance needs.

Cache must never become the canonical registry source.

Invalid cache must not corrupt canonical business state.

---

# 90. Database Access Accounts

Production should use least-privilege database accounts where operationally practical.

PostgreSQL should not be exposed publicly.

Network access should be restricted to approved backend/infrastructure services.

---

# 91. Backups

Production PostgreSQL requires scheduled backups.

Backup strategy must include:

```text
Regular backups

Retention

Secure storage

Restore testing

Documented recovery procedure
```

A backup that has never been restore-tested is not considered sufficient disaster recovery.

---

# 92. Migration Discipline

Schema changes must be represented by Laravel migrations.

Production database structure must not depend on undocumented manual SQL changes.

If specialized PostgreSQL SQL is required, it should be included through controlled migrations.

---

# 93. Migration Immutability

After a migration has been deployed to shared/production environments, schema evolution should generally use a new migration rather than rewriting historical migrations.

---

# 94. Recommended Migration Order

Initial order:

```text
01 users

02 reference tables

03 families

04 persons

05 family_memberships

06 person_relationships

07 family_residences

08 person_health_profiles

09 disability_types

10 person_health_records

11 person_education

12 person_employment

13 assessments

14 form_submissions

15 workflow_events

16 change_request_types

17 change_requests

18 documents

19 family_needs

20 assistance_records

21 person_notes

22 case_notes

23 user_person_links

24 notifications

25 roles / permissions

26 audit infrastructure

27 indexes / specialized constraints
```

Actual migration dependencies may require minor ordering adjustments.

---

# 95. Reference Data Seeders

Reference tables should use deterministic seeders.

Examples:

```text
marital_statuses

relationship_types

change_request_types

document_types

need_types

assistance_types
```

Seeders must use stable machine codes.

---

# 96. Development Seed Data

Development/demo data must be synthetic.

Never seed repositories with real Family information.

---

# 97. Domain Action Database Pattern

Preferred pattern:

```text
Controller
    ↓
Form Request / DTO
    ↓
Policy
    ↓
Domain Action
    ↓
DB::transaction(...)
    ↓
Eloquent / PostgreSQL
```

Not:

```text
Controller
    ↓
Model::update($request->all())
```

for sensitive domain operations.

---

# 98. Family Access Service

A dedicated authorization/domain service is recommended for Family Portal scope resolution.

Concept:

```text
FamilyAccessService
```

Responsibilities may include:

```text
Resolve active User-Person Link

Resolve active Family Membership

Check Household Head requirement

Check Family status

Return authorized Family scope
```

It does not replace Policies.

It supports them.

---

# 99. Change Request Type Handlers

Each Change Request type should have an explicit validation/application mapping.

Example:

```text
CONTACT_UPDATE
    ↓
ContactUpdateData
    ↓
UpdatePersonContactAction
```

```text
RESIDENCE_UPDATE
    ↓
ResidenceUpdateData
    ↓
ChangeFamilyResidenceAction
```

```text
DEATH_REPORT
    ↓
DeathReportData
    ↓
RecordPersonDeathAction
```

This is safer than interpreting arbitrary JSON dynamically.

---

# 100. Application-Level DTOs

Structured DTOs/value objects are recommended between API validation and Domain Actions.

They help prevent passing unrestricted HTTP request arrays deep into the domain layer.

---

# 101. Frontend Database Independence

The Next.js application should know API contracts.

It should not depend on physical PostgreSQL table structure.

Example:

Frontend concept:

```text
FamilyMember
```

does not need to know that canonical association is physically stored in:

```text
family_memberships
```

unless that detail is part of an explicit API contract.

---

# 102. Filament Database Boundary

Filament operates inside Laravel but should still use Domain Actions for important canonical operations.

Filament may perform simpler administrative CRUD for safe reference/system data where appropriate.

Examples:

```text
Reference Data
System Settings
```

High-impact registry operations remain domain-controlled.

---

# 103. Direct Database Editing

Direct manual production database edits are prohibited as normal operational practice.

Emergency corrections must follow an approved technical procedure with:

```text
Authorization

Backup

Recorded reason

Audit/incident record

Validation
```

---

# 104. Testing Database Constraints

Automated tests must verify critical PostgreSQL constraints.

Examples:

```text
Cannot create two active Household Heads

Cannot create two active Family memberships for one Person

Cannot create two current residences for one Family

Cannot set death_date before birth_date
```

---

# 105. Transaction Tests

Automated tests should verify rollback behavior.

Example:

```text
Apply Change Request
    ↓
Domain Action fails
    ↓
No partial canonical changes
    ↓
Request remains non-APPLIED
```

---

# 106. Concurrency Tests

Critical operations should include concurrency-oriented tests where practical.

Examples:

```text
Simultaneous Household Head change

Simultaneous Change Request application

Simultaneous membership transfer
```

---

# 107. Authorization Database Tests

Tests should verify that record identifiers alone cannot bypass scope.

Example:

```text
Family User A
requests
/api/v1/families/{Family-B}

→ 403 / denied
```

even if the ID is valid.

---

# 108. API Exposure Tests

Tests should verify sensitive fields are absent when unauthorized.

Example:

```text
FamilyPortalPersonResource
```

must not accidentally expose:

```text
national_id
health
staff_notes
internal metadata
```

unless explicitly authorized.

---

# 109. Database Invariants

```text
DB-INV-001
PostgreSQL is the canonical persistent data store.

DB-INV-002
Frontend applications do not directly access PostgreSQL.

DB-INV-003
Family and Person are independent entities.

DB-INV-004
Family Membership is the canonical Family-Person association.

DB-INV-005
There is at most one active primary Family membership per Person in V1.

DB-INV-006
There is at most one active Household Head per Family in V1.

DB-INV-007
There is at most one current residence per Family in V1.

DB-INV-008
Membership and residence history are preserved.

DB-INV-009
National ID is stored as text.

DB-INV-010
death_date cannot precede birth_date.

DB-INV-011
Unknown death dates remain NULL.

DB-INV-012
User and Person are separate entities.

DB-INV-013
Family Portal authorization does not depend on users.family_id.

DB-INV-014
Change Request submitted_data is proposed data.

DB-INV-015
APPLIED Change Requests cannot be applied again.

DB-INV-016
Sensitive document files are private.

DB-INV-017
Workflow Events and Audit remain separate.

DB-INV-018
Critical multi-record operations are transactional.

DB-INV-019
Database constraints complement Laravel Domain Rules.

DB-INV-020
JSONB does not replace normalized core registry entities.

DB-INV-021
API Resources rather than unrestricted Models control external data exposure.

DB-INV-022
Filament does not bypass canonical Domain Actions for high-impact operations.

DB-INV-023
Cache and frontend state are never canonical registry state.

DB-INV-024
Real production data is not stored in source control.

DB-INV-025
Canonical historical records are not destructively cascaded without explicit domain justification.
```

---

# 110. Approved Database Decisions

### DB-ADR-001
PostgreSQL 16+ is the primary database.

### DB-ADR-002
BIGINT is used for primary relational identifiers.

### DB-ADR-003
Business codes are separate from database IDs.

### DB-ADR-004
Family and Person are independent tables.

### DB-ADR-005
`family_memberships` is the canonical Family-Person association.

### DB-ADR-006
No canonical `persons.family_id` is used.

### DB-ADR-007
Partial unique indexes enforce critical current-state uniqueness.

### DB-ADR-008
V1 allows one active primary membership per Person.

### DB-ADR-009
V1 allows one active Household Head per Family.

### DB-ADR-010
V1 allows one current residence per Family.

### DB-ADR-011
National ID uses VARCHAR.

### DB-ADR-012
National ID is not initially given an unconditional unique constraint.

### DB-ADR-013
`persons.death_date` is an optional DATE field.

### DB-ADR-014
The database prevents death_date preceding birth_date.

### DB-ADR-015
Health and disability use repeatable relational records.

### DB-ADR-016
Assessment/form data remains separate from canonical Person/Family identity.

### DB-ADR-017
Workflow Events are append-only process history.

### DB-ADR-018
Change Request proposed payloads may use PostgreSQL JSONB.

### DB-ADR-019
JSONB does not replace relational canonical registry modeling.

### DB-ADR-020
Documents may reference Family, Person, and/or Change Request contexts.

### DB-ADR-021
Sensitive files use private storage.

### DB-ADR-022
Users and Persons remain separate.

### DB-ADR-023
User-Person Links connect authentication identity to registry identity.

### DB-ADR-024
No canonical `users.family_id` is used for Family Portal authorization.

### DB-ADR-025
Spatie Permission provides RBAC persistence.

### DB-ADR-026
Laravel standard database notifications are acceptable for V1.

### DB-ADR-027
Critical Domain Actions use database transactions.

### DB-ADR-028
Critical concurrency paths use locking where required.

### DB-ADR-029
Change Request application is transactional and idempotent.

### DB-ADR-030
An application failure does not mark a Change Request APPLIED.

### DB-ADR-031
Laravel API Resources separate persistence Models from API exposure.

### DB-ADR-032
Frontend applications never receive direct database access.

### DB-ADR-033
Filament and the API share the same Laravel domain/database layer.

### DB-ADR-034
Redis is optional infrastructure and is not required for initial architecture.

### DB-ADR-035
Database schema changes are managed through Laravel migrations.

### DB-ADR-036
Direct production database editing is not a normal operational workflow.

### DB-ADR-037
Status fields generally use VARCHAR plus controlled PHP enums rather than PostgreSQL enums.

### DB-ADR-038
Canonical Arabic data remains unchanged for search purposes.

### DB-ADR-039
Specialized Arabic/fuzzy search indexes are introduced only after search testing.

### DB-ADR-040
Database backups and restore testing are production requirements.
```

### 111. Pending Database Decisions

```text
PDB-001
Exact National ID normalization and indexing strategy.

PDB-002
Whether National ID should later receive a normalized unique index.

PDB-003
Exact application-level encryption strategy.

PDB-004
Exact HMAC/search-hash strategy for encrypted searchable values.

PDB-005
Final Arabic name search architecture.

PDB-006
Whether pg_trgm is enabled in V1.

PDB-007
Final geographic structure for residences.

PDB-008
Whether marriage history requires a dedicated table.

PDB-009
Final health/disability reference tables.
(V1: `disability_types` only, see §28. A condition taxonomy is still open.)

PDB-010
Final Assessment/form schema strategy.

PDB-011
Exact audit package/custom implementation.

PDB-012
Exact queue driver for production.

PDB-013
Exact session driver for production.

PDB-014
Whether Redis is introduced at initial deployment.

PDB-015
Final private/object storage provider.

PDB-016
Exact production backup retention schedule.

PDB-017
Exact database encryption-at-rest infrastructure.

PDB-018
Whether reporting later requires materialized views.

PDB-019
Exact handling of partial unknown birth dates if required.

PDB-020
Exact uniqueness rules for active User-Person Links.

PDB-021
Whether notification data requires additional domain-specific tables.

PDB-022
Exact database-level handling of Family archive effects.

PDB-023
Exact document ownership/context constraint if future polymorphic architecture is adopted.
```

---

# 112. Simplified ERD

```text
users
  │
  ├───────────────┐
  │               │
  ▼               │
user_person_links │
  │               │
  ▼               │
persons ◄─────────┘
  │
  │
  ▼
family_memberships
  │
  ▼
families
  │
  ├── family_residences
  │
  ├── assessments
  │      └── form_submissions
  │
  ├── family_needs
  │      └── assistance_records
  │
  ├── change_requests
  │      ├── documents
  │      └── workflow_events
  │
  └── case_notes


persons
  ├── person_relationships
  ├── person_health_profiles
  ├── person_health_records
  ├── person_education
  ├── person_employment
  ├── person_notes
  └── documents
```

---

# 113. Application Architecture

```text
                         Browser
                            │
                            ▼
                         Next.js
                            │
                       HTTPS / JSON
                            │
                            ▼
                       Laravel API
                            │
             ┌──────────────┼──────────────┐
             │              │              │
         Policies       Validation      Resources
             │              │              │
             └──────────────┼──────────────┘
                            ▼
                      Domain Actions
                            │
                      Transactions
                            │
                            ▼
                       PostgreSQL


                    System Administration
                            │
                         Filament
                            │
                      Domain Actions
                            │
                            ▼
                       PostgreSQL
```

---

# 114. Database Security Boundary

The only normal application paths to canonical registry data are authorized server-side paths.

```text
Next.js
   ↓
Laravel

Filament
   ↓
Laravel

Authorized Jobs
   ↓
Laravel

Authorized Imports
   ↓
Laravel

Laravel
   ↓
PostgreSQL
```

The following is prohibited:

```text
Browser
   ↓
PostgreSQL
```

---

# 115. Definition of Database Readiness

The database architecture is ready for implementation when:

```text
Core entities are approved

Family-Person relationship is approved

Household Head invariant is approved

Residence history is approved

death_date rules are approved

User-Person Link is approved

Change Request structure is approved

Workflow history strategy is approved

Document privacy strategy is approved

Migration dependencies are understood

Critical indexes are identified

Critical transactions are identified

Authorization boundary is understood

Pending decisions required by the first migrations are resolved
```

Not every future reference taxonomy must be finalized before Laravel foundation work begins.

---

# 116. Document Status

```text
Project: Famboook
Document: Database Architecture
Version: 1.2.7
Status: APPROVED
Database: PostgreSQL 16+
Date: 2026-09-24
```

---

# 117. Change Log

| Version | Date | Status | Description |
|---|---|---|---|
| 1.0 | 2026-09-22 | Superseded | Initial database architecture |
| 1.1 | 2026-09-22 | Superseded | Added death_date, User-Person Links, Change Requests, documents, workflows, notifications, transactions, locking, domain actions and Family Portal architecture |
| 1.2 | 2026-09-22 | Approved | Established PostgreSQL as canonical database, formalized Next.js → Laravel API → Domain Actions → PostgreSQL boundary, restricted Filament to shared Laravel domain operations, expanded constraints/indexes, private storage, API Resources, transaction/concurrency strategy, migration discipline, testing and infrastructure boundaries |
| 1.2.8 | 2026-09-25 | Approved | §12a Clan structure tables (`clans`, `branch_groups`, `branches`), `families.clan_id` / `branch_id` with composite same-Clan foreign keys, Al-Breem backfill, API |
| 1.2.7 | 2026-09-24 | Approved | Assistance V1-B schema and API (§48d): marital status, execution mode, approval columns, deliveries, issued lists and encrypted snapshots |
| 1.2.6 | 2026-09-24 | Approved | Assistance V1-A: `assistances`, `assistance_items` (§48a), `assistance_categories` (§48b), `assistance_beneficiaries` (§48c) and API; `assistance_nominee` activity subject |
| 1.2.5 | 2026-09-24 | Approved | Added the V1 `family_needs` implementation (§47) and `need_categories` (§47a); `need` activity subject (§59a) |
| 1.2.4 | 2026-09-24 | Approved | Added the V1 `assessments` / `assessment_results` implementation (§31) and `assessment_domains` (§31a); `assessment` activity subject (§59a) |
| 1.2.3 | 2026-09-24 | Approved | Added `family_activities` (§59a, Family Activity Log V1): append-only, allow-listed metadata, transactional with Domain Actions, no backfill |
| 1.2.2 | 2026-09-24 | Approved | Replaced the proposed `person_health_conditions` / `person_disabilities` with `person_health_records` (§27) and added `disability_types` (§28), with partial unique indexes and CHECK constraints |
| 1.2.1 | 2026-09-23 | Approved | Added `persons.alternate_mobile_owner_relation`, `family_residences.original_residence_text` / `displacement_location_text` and displacement CHECK constraints (§24) |

---

# 118. Final Database Principle

Famboook database architecture follows:

```text
Real-world Domain
       ↓
Laravel Domain Rules
       ↓
Controlled Transaction
       ↓
PostgreSQL
       ↓
Canonical Registry
```

The database is not:

```text
A collection of tables directly editable by every interface
```

and the frontend is not:

```text
A database client
```

PostgreSQL protects persistent integrity.

Laravel protects business integrity.

The API protects the application boundary.

Next.js presents the product.