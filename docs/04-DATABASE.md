# Famboook
## Database Architecture

**Document:** `04-DATABASE.md`  
**Version:** 1.2.9  
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
├── family_residences
└── family_household_declarations

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

Import Staging
├── import_batches
└── import_rows

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

Clan → Branches → Families, with Branch Groups as an optional classification
of Branches (docs/02 §7a–§7c; Clan ≠ Family).

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
  branch_group_id BIGINT NULL                -- optional (migration 2026_10_06_090000); NULL = ungrouped
  clan_id BIGINT NOT NULL FK clans.id ON DELETE RESTRICT
  code VARCHAR(50) NOT NULL
  name VARCHAR(150) NOT NULL
  sort_order INT NOT NULL DEFAULT 0
  is_active BOOLEAN NOT NULL DEFAULT true
  created_at, updated_at
  FK fk_branches_group_clan (branch_group_id, clan_id)
     → branch_groups (id, clan_id) ON DELETE RESTRICT   -- MATCH SIMPLE: NULL group not checked
  UNIQUE (clan_id, code)
  UNIQUE (id, clan_id)                       -- composite FK target

families (added)
  clan_id BIGINT NOT NULL FK clans.id ON DELETE RESTRICT
  branch_id BIGINT NULL
  FK fk_families_branch_clan (branch_id, clan_id)
     → branches (id, clan_id) ON DELETE RESTRICT   -- MATCH SIMPLE: NULL branch not checked
  INDEX (clan_id, branch_id)
```

The composite foreign keys make "a grouped Branch's Group belongs to the
Branch's Clan" and "a Family's Branch belongs to the Family's Clan" database
invariants. Both are MATCH SIMPLE: a NULL `branch_group_id` (ungrouped
Branch) or a NULL `branch_id` (Family without Branch) is not checked, while
any non-NULL value must match within the same Clan — cross-Clan grouping is
impossible. `branch_group_id` is intentionally **not** stored on `families`.

Migration `2026_10_06_090000` (Branch Group optional) only drops the
NOT NULL on `branches.branch_group_id`; `fk_branches_group_clan`,
`fk_families_branch_clan` and `branches.clan_id NOT NULL` are unchanged and
no row is modified. Its rollback restores NOT NULL and refuses (without
changing data) while ungrouped Branches exist.

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
POST  /api/v1/clans/{clan}/branches                clan.manage (branch_group_id optional)
PATCH /api/v1/branches/{branch}                    clan.manage
```

Route keys are UUIDs. Updates change name, sort_order and is_active; a
Branch update may also set `branch_group_id` (a Group's public UUID of the
same Clan to assign/move, or null to ungroup; omitted = unchanged). Codes are
immutable and a Branch never changes Clan. The Clan tree includes each
Clan's `ungrouped_branches`. There is no DELETE endpoint. Family registration/update
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

V1 index (2026-09-25, Operational Dashboard): `ix_family_memberships_family_active
(family_id, is_active)`. Every current-population aggregate selects active
memberships by scoped family; the only other family_id index is the partial
household-head index.

Operational Dashboard API (docs/03 §55a, docs/06 §59a):

```text
GET /api/v1/dashboard/scope-options                               dashboard.view-operational
GET /api/v1/dashboard?clan={code}&branch_group={code}&branch={code}  dashboard.view-operational
```

Aggregates are grouped SQL over the scoped family/person subqueries (no
per-family or per-domain queries); nothing is stored.

Reports V1 API (docs/03 §55b, docs/06 §59b). Every endpoint takes the same
scope (`clan` required, `branch_group`, `branch` optional; codes only) and
requires `report.view` plus the report's domain permissions:

```text
GET /api/v1/reports/meta                    reports this user may open, can_export
GET /api/v1/reports/scope-options
GET /api/v1/reports/population
GET /api/v1/reports/health                  aggregate only
GET /api/v1/reports/needs                   status, priority, category, target, page, per_page
GET /api/v1/reports/assessments
GET /api/v1/reports/assessments/families    domain, rating (… | NOT_ASSESSED), page, per_page
GET /api/v1/reports/assistance              status (default OPEN + COMPLETED), page, per_page
GET /api/v1/reports/data-quality
GET /api/v1/reports/data-quality/records    issue, page, per_page
GET /api/v1/reports/{report}/export         same filters; + export.basic
```

Detail rows are paginated server-side (`per_page` ≤ 100, default 25) and
exports stream rows in chunks of 1,000 into temp files; nothing is stored.
Rows carry codes and UUIDs only, never internal numeric ids. No index was
added: every report query shape is served by existing indexes
(`families (clan_id, branch_id)`, `ix_family_memberships_family_active`,
`family_needs (family_id, status)`, `assessments (family_id, …)`,
`assistance_beneficiaries (family_id)`, delivery and list-entry beneficiary
indexes, `person_health_records (person_id, type)`).

Registry search API (docs/03 §93a, AUTH-ADR-058):

```text
GET  /api/v1/families?search=&status=&page=&per_page=   family.view (per_page ≤ 100)
GET  /api/v1/people?search=&page=&per_page=             person.view (per_page ≤ 100)
POST /api/v1/people/national-id-check  {national_id}    person.create | family.create; 30/min/user
```

Search is `ILIKE '%term%'` (escape character `!`). Family search matches
`families.family_code`, or `families.id IN (SELECT family_id FROM active
memberships JOIN persons WHERE name/code match)` — one pass, no per-row
queries. Indexes (2026-10-04 migration, PostgreSQL only): extension
`pg_trgm` and trigram GIN indexes `ix_persons_full_name_trgm`,
`ix_persons_person_code_trgm`, `ix_families_family_code_trgm`. Justified by
measurement on 100,000 synthetic names: a selective search went from
~400–600 ms (sequential scan) to ~5–8 ms; very broad terms (≤ 2 characters,
or text every name contains) still scan. The exact National ID lookup uses the
existing `persons.national_id` B-tree index. National ID stays non-UNIQUE;
creation serializes per value with `pg_advisory_xact_lock(hashtext(…))`.

Pilot Readiness Slice C (docs/03 §93b) adds **no schema change**. Ending a
membership is an UPDATE (`is_active = false`, `ended_at`, `end_reason`,
`updated_by`) — never a DELETE; `ended_at` is never before `started_at`
(`chk_membership_dates`). Relationship correction updates
`relationship_type_id` in place. Both lock the membership row
(`SELECT … FOR UPDATE`). The partial unique indexes (one active membership
per Person, one active head per Family) remain the database guarantee.

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

# 25a. Family Household Declarations Table

Approved 2026-09-29. Declared Household Statistics (docs/02 §20a, docs/03
§55c): source-declared, dated, historical — **not** the Registered
Household Size, which stays derived (§82).

```text
family_household_declarations
```

```text
id BIGINT PK

family_id BIGINT NOT NULL FK families.id ON DELETE RESTRICT

declared_household_size   SMALLINT NULL
declared_living_sons      SMALLINT NULL
declared_living_daughters SMALLINT NULL

declared_at DATE NULL
source VARCHAR NOT NULL          -- PAPER_FORM | MANUAL_ENTRY | IMPORT | VERIFIED_SOURCE
is_current BOOLEAN DEFAULT TRUE
notes TEXT NULL

created_by BIGINT NULL FK users.id ON DELETE SET NULL
updated_by BIGINT NULL FK users.id ON DELETE SET NULL

created_at
updated_at
```

Constraints and indexes:

```sql
CREATE UNIQUE INDEX uq_family_current_household_declaration
ON family_household_declarations (family_id)
WHERE is_current = TRUE;

-- PostgreSQL CHECKs (mirrored by RecordHouseholdDeclarationAction):
chk_household_declaration_counts     each declared count IS NULL OR >= 0
chk_household_declaration_not_empty  at least one declared count IS NOT NULL
chk_household_declaration_source     source IN (the four values above)

INDEX (family_id)
```

The values are deliberately **not** columns on `families`: a new
declaration closes the current one (`is_current = false`) and inserts a
new row in one transaction, so earlier declarations survive for past
eligibility and assistance decisions.

Concurrency (FU-10, DB-ADR-053): the action locks the `families` row,
re-reads the current declaration and compares it with the one the caller
expected (optimistic stale-state check, §70); a mismatch writes nothing.
The partial unique index above remains the final guard.

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
GET   /assessments                         assessment.view   cross-family registry (Assessments workspace); filters status, family (exact Family code); DRAFT first, then newest assessment_date, newest entry; paginated (per_page 20, max 50)
GET   /assessments/{uuid}                  assessment.view
PATCH /assessments/{uuid}                  assessment.update DRAFT only (409 once COMPLETED)
POST  /assessments/{uuid}/complete         assessment.complete (+ assessment.update if a final draft payload is sent)
GET   /reference/assessment-domains        reference-data.view OR assessment.view; active domains only
```

Draft payload: `assessment_date`, `general_notes`, and `results` as
`[{domain_code, rating, notes}]`. When `results` is sent it is the full
result set, synchronized transactionally; duplicate domains are rejected.
There is no delete endpoint.

The cross-family registry (`GET /assessments`) is read-only. Each row
carries the assessment id, `assessment_date`, `status`, the derived
`assessed_domain_count`, `created_by`, `created_at`, `updated_at`,
`completed_at` and the family context (`family_code`, household-head name,
branch name). It never returns general or domain notes, individual
ratings, National IDs, contact or health data. The response adds a
`summary` (`total`, `draft`, `completed`) counted over the whole registry
regardless of filters — derived on read, never stored — and an
`abilities.update` UX hint. Creation stays family-scoped.

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

**Implemented 2026-10-07 (PWA-5a, DB-ADR-058).** `workflow_events` exists as
above with `comment` split into `public_message` (family-visible) and
`internal_note` (Staff-only), plus `actor_side` (FAMILY / STAFF / SYSTEM) and
`reason_code` (a code, never free text); `actor_user_id` restricts deletion;
no `updated_at`. Only the morph alias `change_request` is allowed. On
PostgreSQL the trigger `trg_workflow_events_append_only` (function
`famboook_workflow_events_append_only()`) rejects every UPDATE and DELETE;
INSERT stays allowed. TRUNCATE, a table-owner operation no application path
issues, is not trigger-blocked (the PostgreSQL test harness truncates with
CASCADE).

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

**Superseded 2026-10-07 (PWA-5a, DB-ADR-058, owner decision AE-1).** No
`change_request_types` table is created: request types are a code registry
(the `ChangeRequestType` enum, a checked `change_requests.type` varchar);
behaviour, risk and evidence rules live with each type's handler in code.

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

**Implemented 2026-10-07 (PWA-5a, DB-ADR-058)** with these differences:
`uuid` (public identifier) beside `request_code` (CRQ-000001, from the
PostgreSQL sequence `change_request_code_seq`); `type` varchar instead of
`change_request_type_id`; `payload_version`; `target_membership_id`
(docs/11 FP-ADR-063); `base_fingerprint` + `base_key_version` (stale-write
protection); `submitted_by_person_id`; `client_reference` (unique per
submitting user); `rejection_reason_code` (controlled) beside the
family-visible `rejection_reason`; `cancelled_by` / `cancelled_at`;
`apply_failure_count` / `last_apply_failed_at`. Not created: `risk_level`
(derived from the type), `notes` and `review_notes` (on `workflow_events`).
PostgreSQL CHECKs keep each status consistent with its actor / timestamp
columns. Actor foreign keys restrict deletion.

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

V1 (2026-09-26, docs/06 §59c): `users.is_active BOOLEAN NOT NULL DEFAULT TRUE`
was added (existing users stay active). Sessions use the `sessions` table
(database driver); setting a temporary password deletes the user's rows
there. Roles/permissions stay in the Spatie tables.

Staff authentication API (Sanctum first-party session):

```text
GET  /sanctum/csrf-cookie
POST /api/v1/auth/login    throttle: per IP; failures per email + IP
POST /api/v1/auth/logout   auth:sanctum
GET  /api/v1/me            auth:sanctum — name, email, role, role_label, permissions
```

Filament Staff user administration is served under `/admin` (same `users`
table and session guard).

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

# 55a. Family Portal Program — Future Schema Concepts (PWA-0)

Approved 2026-10-02 (DB-ADR-041). **No migration exists for anything in
this section.** It separates the logical design from the future physical
schema; physical names are given only where this document already defines
them. Specification: `11-FAMILY-PORTAL.md` §28.

## Already defined here, not yet built

```text
user_person_links        §53–§54
change_request_types     §36
change_requests          §37–§42
workflow_events          §33–§35
documents                §44–§46
```

## New concepts — physical design deferred to their phase

| Concept | Needs | Phase |
|---|---|---|
| Family login identifier | Decided: `family_auth_identities` (§55b); `users.email` nullable | PWA-1C |
| Activation state | Whether the account is activated and a password set | PWA-2 |
| Trusted mobile | Per-Person trust state with verifier, time and method | PWA-1 / PWA-2 |
| OTP challenge | Hashed, short-lived, attempt-counted, purpose-bound | PWA-2 |
| Coordinator scope assignment | User ↔ Clan / Branch Group / Branch; audited | PWA-1 |
| Family Profile Review — completeness and states | Derived on read; never stored | PWA-4 |
| Family Profile Review — confirmations | `family_profile_confirmations` (concept): id, uuid, family_id, section (FAMILY / HEAD / MEMBERS / RESIDENCE), confirmed_by_user_id, confirmed_by_person_id, confirmed_at, fingerprint, fingerprint_version, key_version, optional acknowledgement codes; append-only; no registry copy | PWA-4 |
| Staff Family Verification | State, version, verifier, timestamps, history | Deferred (PFP-017) |
| Card credential and issuance | Opaque public holder ID, verification credential, status, history — **implemented as `digital_credentials` (§55c, DB-ADR-057)** | PWA-8 |
| Announcement | Sender, sender context, audience definition, content | PWA-9 |
| Notification recipient / read state | Per-recipient row with read state | PWA-9 |
| Authentication / security audit | Append-only events | PWA-2 |

## Constraints the physical design must honour

- No `users.family_id` (§55).
- `persons.national_id` is indexed but not unique and stored as entered
  (PDD-001). It receives **no** UNIQUE constraint. Authentication uses the
  separate `family_auth_identities` key (§55b); no backfill is needed
  because identities are created only at activation.
- Opaque identifiers (public holder ID, verification credential) must be
  unique and must not be derivable from sequential ids or business codes.
- OTP secrets are stored only as hashes.
- Verification and card history are append-only.
- Coordinator scope references the existing `clans`, `branch_groups` and
  `branches` tables.

---

# 55b. Family Portal Identity Schema (PWA-1B — approved design)

Approved 2026-10-02 (DB-ADR-042). **Implemented by PWA-1C (DB-ADR-043):
schema, models and factories only** — no row is created or derived, and no
lifecycle, activation, OTP or authorization behaviour exists yet. Entities:
docs/02 §45b. Implementation notes are at the end of this section.

Conventions: `id BIGINT PK`, `uuid UUID UNIQUE` as the public reference,
`created_at` / `updated_at`; user references are `BIGINT NULL FK users.id`
unless stated. `persons`, `families` and `family_memberships` are not
changed.

## `users` (change)

```text
email   VARCHAR NULL   (was NOT NULL); the unique index is kept
```

## `user_person_links`

```text
user_id               BIGINT NOT NULL FK users.id      (restrict)
person_id             BIGINT NOT NULL FK persons.id    (restrict)
link_type             VARCHAR NOT NULL                 SELF
status                VARCHAR NOT NULL                 PENDING_VERIFICATION | VERIFIED
                                                       | ACTIVE | SUSPENDED | ENDED
verification_method   VARCHAR NULL                     SYSTEM_OTP_ACTIVATION | STAFF
verified_by, verified_at, activated_at
suspended_at, suspended_by, suspension_reason
ended_at, ended_by, end_reason
```

```text
UNIQUE (person_id) WHERE status IN ('ACTIVE','SUSPENDED')
UNIQUE (user_id)   WHERE status IN ('ACTIVE','SUSPENDED')
INDEX  (user_id, status), (person_id, status)
CHECK  status IN ('ACTIVE','SUSPENDED') requires verified_at
CHECK  status = 'ENDED' requires ended_at and end_reason
```

This resolves PDB-020.

## `family_auth_identities`

```text
user_id            BIGINT NOT NULL FK users.id (restrict)
login_key          CHAR(64) NOT NULL             sensitive
key_version        SMALLINT NOT NULL
status             VARCHAR NOT NULL              ACTIVE | SUSPENDED | SUPERSEDED
superseded_at      TIMESTAMP NULL
supersede_reason   VARCHAR NULL
```

```text
UNIQUE (login_key) WHERE status = 'ACTIVE'
UNIQUE (user_id)   WHERE status IN ('ACTIVE','SUSPENDED')
```

## `person_mobile_trusts`

```text
person_id             BIGINT NOT NULL FK persons.id (restrict)
mobile_fingerprint    CHAR(64) NOT NULL            sensitive
mobile_last2          CHAR(2) NOT NULL
key_version           SMALLINT NOT NULL
status                VARCHAR NOT NULL             PENDING_VERIFICATION | TRUSTED
                                                   | STALE | REVOKED
verification_method   VARCHAR NULL                 IN_PERSON | STAFF_CALLBACK
                                                   | AUTHORIZED_RECORD_REVIEW
assisted_by, assisted_at
verified_by, verified_at
stale_at
revoked_by, revoked_at, revoke_reason
```

```text
UNIQUE (person_id) WHERE status = 'TRUSTED'
UNIQUE (person_id) WHERE status = 'PENDING_VERIFICATION'
INDEX  (mobile_fingerprint)          -- NOT unique: shared numbers are valid
CHECK  status = 'TRUSTED' requires verified_by and verified_at
```

## `auth_otp_challenges`

```text
purpose            VARCHAR NOT NULL               ACTIVATION | PASSWORD_RESET
person_id          BIGINT NOT NULL FK persons.id
user_id            BIGINT NULL FK users.id
mobile_trust_id    BIGINT NOT NULL FK person_mobile_trusts.id
code_hash          CHAR(64) NOT NULL              sensitive; never plaintext
expires_at         TIMESTAMP NOT NULL
attempts           SMALLINT NOT NULL DEFAULT 0
send_count         SMALLINT NOT NULL
last_sent_at       TIMESTAMP NOT NULL
verified_at, grant_expires_at
consumed_at, superseded_at, locked_at
ip                 INET NULL
```

```text
UNIQUE (person_id, purpose)
       WHERE consumed_at IS NULL AND superseded_at IS NULL AND locked_at IS NULL
```

## `auth_security_events`

```text
event_type, outcome, reason_code    VARCHAR
person_id, user_id, actor_user_id   BIGINT NULL
user_person_link_id, mobile_trust_id   BIGINT NULL
otp_challenge_uuid   UUID NULL   plain reference value — NO foreign key
login_key          CHAR(64) NULL    fingerprint only
ip                 INET NULL
user_agent_hash    CHAR(64) NULL
metadata           JSONB NULL       allow-listed keys only
created_at         TIMESTAMP NOT NULL      (no updated_at: append-only)
```

Indexes on `(person_id, created_at)`, `(user_id, created_at)`,
`(event_type, created_at)` and `login_key`.

`otp_challenge_uuid` carries no foreign key on purpose: challenges are
purged after 90 days while events are retained 24 months, so a foreign key
would either block the purge or force an update of an append-only row.
The table has no free-text column.

## `coordinator_scope_assignments`

```text
user_id            BIGINT NOT NULL FK users.id (restrict)
scope_type         VARCHAR NOT NULL            CLAN | BRANCH_GROUP | BRANCH
clan_id            BIGINT NOT NULL FK clans.id
branch_group_id    BIGINT NULL    FK (branch_group_id, clan_id) → branch_groups (id, clan_id)
branch_id          BIGINT NULL    FK (branch_id, clan_id) → branches (id, clan_id)
assigned_by, assigned_at
revoked_by, revoked_at, revoke_reason
```

```text
CHECK  CLAN         → branch_group_id IS NULL AND branch_id IS NULL
CHECK  BRANCH_GROUP → branch_group_id IS NOT NULL AND branch_id IS NULL
CHECK  BRANCH       → branch_id IS NOT NULL AND branch_group_id IS NULL
UNIQUE (user_id, clan_id)         WHERE scope_type = 'CLAN'         AND revoked_at IS NULL
UNIQUE (user_id, branch_group_id) WHERE scope_type = 'BRANCH_GROUP' AND revoked_at IS NULL
UNIQUE (user_id, branch_id)       WHERE scope_type = 'BRANCH'       AND revoked_at IS NULL
```

Three scope-specific partial unique indexes prevent the same active scope
twice. They replace the earlier "NULLs treated as equal" wording: the
semantics are identical, they behave the same on PostgreSQL and SQLite, and
they do not rely on `NULLS NOT DISTINCT`.

## Implementation notes (PWA-1C)

Migrations, in order:

```text
2026_10_13_090000_make_users_email_nullable
2026_10_13_090001_create_user_person_links_table
2026_10_13_090002_create_family_auth_identities_table
2026_10_13_090003_create_person_mobile_trusts_table
2026_10_13_090004_create_auth_otp_challenges_table
2026_10_13_090005_create_auth_security_events_table
2026_10_13_090006_create_coordinator_scope_assignments_table
2026_10_14_090000_allow_link_ended_auth_identity_supersede_reason   (PWA-1D)
```

- **No data migration.** No account, Link, authentication identity, mobile
  trust or assignment is created from existing data. Imported mobiles stay
  UNVERIFIED by the absence of a TRUSTED row. `persons.national_id`
  receives no constraint.
- `users.email`: only the NOT NULL is dropped; the unique index is kept.
  The rollback refuses while an account without an email exists.
- **Foreign keys are all RESTRICT**, including the actor columns
  (`verified_by`, `assisted_by`, `suspended_by`, `ended_by`, `revoked_by`,
  `assigned_by`): identity and security history is never cascaded or
  nulled.
- **CHECK constraints are PostgreSQL-only** (the repository convention);
  on SQLite the enums and, later, the lifecycle actions carry the same
  rules. Besides the constraints listed above they also require: fingerprints
  and hashes to be 64 lowercase hex characters (so a raw National ID, mobile
  or OTP cannot be stored), reasons to be codes, a `PASSWORD_RESET`
  challenge to name its user, and `SUPERSEDED` / `REVOKED` / `STALE` rows to
  carry their timestamps.
- **Open OTP challenge.** The partial unique index covers rows that are not
  consumed, superseded or locked. Expiry is time-dependent and cannot be
  part of an index, so an expired challenge still occupies the slot: PWA-1E
  must supersede the previous challenge before inserting another.
- Partial unique indexes are created with raw SQL and are exercised by the
  SQLite suite; the PostgreSQL-only checks are covered by
  `IdentitySchemaConstraintsTest`, which runs only on a PostgreSQL `_test`
  database.

## Retention

`auth_security_events`: 24 months. `auth_otp_challenges`: finished rows
purgeable after 90 days. `user_person_links` and `person_mobile_trusts`
history is never purged by OTP cleanup.

---

# 55c. Digital Credentials (PWA-8.2)

Implemented 2026-10-07 (migration `2026_10_16_090000_create_digital_credentials_table`,
docs/11 FP-ADR-070). Additive; no backfill.

```text
digital_credentials
id                bigint PK
subject_type      string(20)        CHECK = 'FAMILY' (PWA-8)
family_id         FK families       restrict on delete; nullable column,
                                    required by CHECK for FAMILY
credential_number string(15) UNIQUE CHECK ^FC-[Crockford]{4}-{4}-{2}$
token_hash        char(64) UNIQUE   CHECK lowercase hex
token_encrypted   text              Laravel Crypt (APP_KEY)
token_version     smallint          default 1, CHECK >= 1
status            string(20)        CHECK ACTIVE | REVOKED
issued_at         timestamp
issued_by         FK users NULL     null on delete; NULL = system
revoked_at        timestamp NULL
revoked_by        FK users NULL     null on delete
revoke_reason     string(30) NULL   CHECK REISSUED | ADMINISTRATIVE | COMPROMISED;
                                    set ⇔ status REVOKED
timestamps
uq_digital_credentials_active_family  UNIQUE (family_id)
                  WHERE status = 'ACTIVE' AND subject_type = 'FAMILY'
index (family_id, status)
```

CHECK constraints are PostgreSQL-only (repository convention); the model
guard (ACTIVE → REVOKED once, never deleted, number / token / subject
immutable) and the Domain Actions carry the same rules on SQLite. A future
subject is additive (a nullable FK, an extended CHECK, its own partial
index) and never touches existing rows.

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

As implemented (docs/03 §30, FU-10): the Person row is locked and
re-read; a DECEASED Person is refused; memberships and
`is_household_head` are not changed; a current User-Person Link is ended
in the same transaction (identity superseded, sessions revoked); the
Family Activity Log entry carries the verification method. "Household
Head review" and "Family User access reevaluation" are **passive** today,
not explicit workflow triggers: the Data Quality check
`HOUSEHOLD_HEAD_DECEASED` surfaces a deceased head, and
`FamilyAccessResolver` re-evaluates access on every request (a deceased
head is never eligible). Head Succession is docs/11 FU-01.

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

In particular **Registered Members** (active memberships, any life status)
and **Living Members** are never stored.
`family_household_declarations` (§25a) stores **Declared** Household
Statistics — separate source facts, not a cached count — and never feeds
or replaces a derived count.

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

# 83a. Import Staging Tables

Approved 2026-09-29 (Initial Family Import foundation, Phase 1). Field
meanings in docs/02 §88a; rules in docs/03 §96a.

```text
import_batches
```

```text
id BIGINT PK
uuid UUID UNIQUE
clan_id BIGINT NOT NULL FK clans.id ON DELETE RESTRICT   -- explicit target Clan (Phase 2A), indexed
import_mode VARCHAR(20) NOT NULL     -- INITIAL | INCREMENTAL (explicit)

source_filename VARCHAR NOT NULL
source_checksum CHAR(64) NOT NULL   -- lower-case hex SHA-256; indexed, not unique
source_size_bytes BIGINT NULL
source_file_path VARCHAR NULL        -- private disk; never exposed
worksheet_name VARCHAR NULL          -- selected data worksheet
inspection JSONB NULL                -- sheet/header STRUCTURE only, no cell values
column_mapping JSONB NULL            -- confirmed {fields, ignored}
mapping_confirmed_at TIMESTAMP NULL
status VARCHAR NOT NULL
row_count INTEGER NOT NULL DEFAULT 0
failure_reason TEXT NULL

uploaded_by BIGINT NULL FK users.id ON DELETE SET NULL
applied_by  BIGINT NULL FK users.id ON DELETE SET NULL
applied_at  TIMESTAMP NULL

created_at
updated_at
```

```sql
-- PostgreSQL CHECKs
chk_import_batch_status    status IN ('UPLOADED','VALIDATING','READY_FOR_REVIEW',
                           'READY_TO_APPLY','APPLYING','APPLIED','FAILED')
chk_import_batch_row_count row_count >= 0
chk_import_batch_checksum  source_checksum ~ '^[0-9a-f]{64}$'
chk_import_batch_applied   status <> 'APPLIED' OR applied_at IS NOT NULL
chk_import_batch_mode      import_mode IN ('INITIAL','INCREMENTAL')
chk_import_batch_mapping_confirmed (mapping_confirmed_at IS NULL) = (column_mapping IS NULL)

INDEX (status), INDEX (source_checksum), INDEX (clan_id)

CREATE UNIQUE INDEX uq_import_batch_clan_checksum
ON import_batches (clan_id, source_checksum) WHERE status <> 'FAILED';   -- one live batch per file and Clan
```

```text
import_rows
```

```text
id BIGINT PK

import_batch_id BIGINT NOT NULL FK import_batches.id ON DELETE RESTRICT
row_number INTEGER NOT NULL           -- 1-based source row
raw_payload JSONB NOT NULL            -- sanitized; RESTRICTED (may hold National IDs)
normalized_payload JSONB NULL         -- Phase 2A normalized representation; RESTRICTED
source_family_key VARCHAR(150) NULL   -- المفتاح, whitespace-normalized only; NULL = missing
status VARCHAR NOT NULL
reconciliation_status VARCHAR(30) NULL  -- RESERVED for future reconciliation; NULL = not reconciled
issues JSONB NULL
family_id BIGINT NULL FK families.id ON DELETE RESTRICT

created_at
updated_at
```

```sql
UNIQUE (import_batch_id, row_number)
INDEX (import_batch_id, status)
INDEX (import_batch_id, source_family_key)   -- family-key discovery

CREATE UNIQUE INDEX uq_import_row_family
ON import_rows (family_id) WHERE family_id IS NOT NULL;

-- PostgreSQL CHECKs
chk_import_row_status         status IN ('PENDING','VALID','FLAGGED','REJECTED',
                              'APPLIED','SKIPPED')
chk_import_row_number         row_number >= 1
chk_import_row_applied_family (status = 'APPLIED') = (family_id IS NOT NULL)
chk_import_row_reconciliation reconciliation_status IS NULL OR IN ('NEW','UNCHANGED',
                              'CHANGED','DUPLICATE_IN_FILE','CONFLICT','REVIEW_REQUIRED')
```

As with the existing CHECKs, they are PostgreSQL-only (SQLite cannot add
them after creation); the model enum casts, the `ImportRow` excluded-field
guard and the Domain Actions enforce the same rules on every driver. The
uploaded file itself is not stored in these tables. No National ID is
copied anywhere outside `raw_payload`. Retention/purging of staging data
follows PDD-017 (open).

---

# 83b. Import Family-Key Resolutions Table

Approved 2026-09-29 (migration `2026_10_09_090000`; docs/02 §88b, docs/03 §96a).

```text
import_family_key_resolutions
  id BIGINT PK
  import_batch_id BIGINT NOT NULL
  clan_id BIGINT NOT NULL FK clans.id ON DELETE RESTRICT
  source_family_key VARCHAR(150) NOT NULL
  decision VARCHAR(30) NOT NULL
  branch_id BIGINT NULL
  reference_source_key VARCHAR(150) NULL
  resolved_by BIGINT NULL FK users.id ON DELETE SET NULL
  resolved_at TIMESTAMP NOT NULL
  created_at, updated_at
  FK fk_key_resolution_batch_clan (import_batch_id, clan_id)
     → import_batches (id, clan_id) ON DELETE RESTRICT
  FK fk_key_resolution_branch_clan (branch_id, clan_id)
     → branches (id, clan_id) ON DELETE RESTRICT      -- MATCH SIMPLE: NULL (NO_BRANCH) not checked
  UNIQUE uq_key_resolution_batch_key (import_batch_id, source_family_key)
  INDEX (branch_id)

import_batches (added)
  UNIQUE (id, clan_id)                                -- composite FK target
```

```sql
-- PostgreSQL CHECKs
chk_key_resolution_decision  decision IN ('MATCH_EXISTING_BRANCH','CREATE_NEW_BRANCH',
                             'SAME_BRANCH_AS_KEY','NO_BRANCH')
chk_key_resolution_branch    (decision = 'NO_BRANCH') = (branch_id IS NULL)
chk_key_resolution_reference (decision = 'SAME_BRANCH_AS_KEY') = (reference_source_key IS NOT NULL)
```

The composite foreign keys make "a resolved Branch belongs to the batch's
Clan" a database invariant. A Branch referenced by a decision cannot be
deleted, and neither can a batch that has decisions. No row = UNRESOLVED.
Additive; `import_rows` is not changed.

---

# 83c. Import Row Reconciliations Table

Approved 2026-09-29 (migration `2026_10_10_090000`; docs/02 §88c, docs/03 §96a).

```text
import_row_reconciliations
  id BIGINT PK
  import_row_id BIGINT NOT NULL UNIQUE FK import_rows.id ON DELETE CASCADE
  import_batch_id BIGINT NOT NULL FK import_batches.id ON DELETE RESTRICT
  head_match VARCHAR(30) NOT NULL
  head_person_id BIGINT NULL FK persons.id ON DELETE SET NULL
  family_match VARCHAR(30) NOT NULL
  family_id BIGINT NULL FK families.id ON DELETE SET NULL
  spouse_matches JSONB NULL
  differences JSONB NULL
  issues JSONB NULL
  created_at, updated_at
  INDEX (import_batch_id, head_match)

import_batches (added)
  reconciled_at TIMESTAMP NULL
  reconciled_by BIGINT NULL FK users.id ON DELETE SET NULL
  reconciliation_fingerprint CHAR(64) NULL
```

```sql
-- PostgreSQL CHECKs
chk_row_reconciliation_head_match    head_match IN (…six values…)
chk_row_reconciliation_family_match  family_match IN (…five values…)
chk_row_reconciliation_family        EXISTING_FAMILY ⇔ family_id IS NOT NULL
                                     (except OTHER_CLAN_FAMILY / PERSON_NOT_HEAD)
```

Evidence is staging data: it disappears with its staged row. The migration
recreates `uq_import_batch_clan_checksum` with its `WHERE status <> 'FAILED'`
predicate after altering `import_batches`, because SQLite rebuilds the table
for a foreign-key change and would otherwise drop the predicate (PostgreSQL
is unaffected). Additive.

---

# 83d. Import Apply Foundation (Lifecycle and Provenance)

Approved 2026-09-29 (migration `2026_10_11_090000`; docs/02 §88d, docs/03
§96b). Schema only — no Apply exists and nothing writes these rows yet.

```text
import_batches (changed)
  status adds PARTIALLY_APPLIED
  apply_started_at TIMESTAMP NULL

import_rows (added)
  UNIQUE INDEX uq_import_rows_id_batch (id, import_batch_id)

import_apply_records            -- append-only, no updated_at
  id BIGINT PK
  import_batch_id BIGINT NOT NULL FK import_batches.id ON DELETE RESTRICT
  import_row_id BIGINT NOT NULL
  effect_key VARCHAR(40) NOT NULL
  entity_type VARCHAR(40) NOT NULL        -- PERSON | FAMILY | MEMBERSHIP | HOUSEHOLD_DECLARATION | RESIDENCE
  entity_id BIGINT NULL                   -- no FK (see below)
  role VARCHAR(10) NULL                   -- HEAD | SPOUSE
  spouse_slot SMALLINT NULL               -- 1..4
  outcome VARCHAR(10) NOT NULL
  reason_code VARCHAR(60) NULL
  applied_by BIGINT NOT NULL FK users.id ON DELETE RESTRICT
  created_at TIMESTAMP NOT NULL
  FK (import_row_id, import_batch_id) → import_rows (id, import_batch_id) ON DELETE RESTRICT
  UNIQUE (import_row_id, effect_key)
  UNIQUE (entity_type, entity_id) WHERE outcome = 'CREATED'
  INDEX (import_batch_id, outcome), INDEX (entity_type, entity_id)
```

```sql
-- PostgreSQL CHECKs
chk_import_batch_status          status IN (… 8 values incl. PARTIALLY_APPLIED …)
chk_import_batch_apply_started   (apply_started_at IS NOT NULL) = (status IN ('APPLYING','PARTIALLY_APPLIED','APPLIED'))
chk_import_apply_shape           effect_key ⇒ exact entity_type, role, spouse_slot (13 combinations)
chk_import_apply_outcome         outcome IN ('CREATED','REUSED','OMITTED','BLOCKED')
chk_import_apply_entity          CREATED/REUSED ⇒ entity_id NOT NULL; OMITTED ⇒ entity_id NULL
chk_import_apply_reason          reason_code ~ '^[A-Z][A-Z0-9_]{1,59}$'; OMITTED/BLOCKED ⇒ reason_code NOT NULL
```

- **Idempotency key**: `(import_row_id, effect_key)`, both NOT NULL — unique
  semantics never depend on NULLs. A spouse slot's Person and membership
  are different effects (`SPOUSE_n_PERSON`, `SPOUSE_n_MEMBERSHIP`).
- **Entity references**: stable `entity_type` codes plus `entity_id`, with
  no foreign key: one column cannot reference five tables, and provenance
  must never be cascade-deleted. Registry rows are soft-deleted or protected
  by RESTRICT foreign keys, so the id stays resolvable.
- **Checksum protection**: `uq_import_batch_clan_checksum` (`WHERE status <>
  'FAILED'`) is unchanged; because a started Apply can never be FAILED, a
  partly applied file stays protected.
- The model mirrors every CHECK so the invariants also hold on SQLite
  (tests). `down()` recreates the checksum index predicate after SQLite
  rebuilds `import_batches`. Additive; no existing row is transitioned.

Phase 4B.4a (migration `2026_10_12_090000`):

```text
import_batches (added)
  apply_plan_fingerprint CHAR(64) NULL
  apply_error_code VARCHAR(60) NULL
  apply_error_row_number INTEGER NULL
```

```sql
chk_import_batch_apply_plan    (apply_plan_fingerprint IS NOT NULL) = (apply_started_at IS NOT NULL)
                               AND apply_plan_fingerprint ~ '^[0-9a-f]{64}$' when present
chk_import_batch_apply_error   apply_error_code ~ '^[A-Z][A-Z0-9_]{1,59}$' when present;
                               apply_error_row_number only with a code, >= 1
```

Together with `chk_import_batch_apply_started`, the approved plan exists
exactly while Apply has started. Errors are structured codes, never
exception text. Mirrored by the model; `down()` restores the checksum index
predicate after the SQLite rebuild. Additive.

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
ResidenceUpdateHandler (submitted_data, payload version 1)
    ↓
UpdateFamilyResidenceAction
```

RESIDENCE_UPDATE corrects the current residence in place (docs/11
FP-ADR-059, built in PWA-6.1). A real move with history would use a future
`ChangeFamilyResidenceAction` under its own approval (docs/11 FU-02).

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

### DB-ADR-041
Family Portal future schema concepts are documented logically (§55a) with physical design deferred to each PWA phase; no `users.family_id`; opaque card identifiers never reuse sequential ids or business codes; OTP secrets stored only hashed.

### DB-ADR-042
Family Portal identity schema (§55b, approved design, no migration): `users.email` nullable with its unique index kept; `user_person_links` with partial unique indexes on active/suspended links; dedicated `family_auth_identities`; person-specific `person_mobile_trusts` with a non-unique fingerprint; `auth_otp_challenges`; append-only `auth_security_events`; `coordinator_scope_assignments`. No UNIQUE constraint on `persons.national_id`.

### DB-ADR-043
PWA-1C implemented §55b as schema, models and factories only. Refinements approved before implementation: coordinator duplicates are prevented by three scope-specific partial unique indexes (no `NULLS NOT DISTINCT`); `auth_security_events.otp_challenge_uuid` is a plain value with no foreign key; "one open OTP challenge" ignores expiry, which PWA-1E handles by superseding; CHECK constraints are PostgreSQL-only. All new foreign keys are RESTRICT. No data was backfilled.

### DB-ADR-044
PWA-1D added one migration (`2026_10_14_090000`): the PostgreSQL CHECK on `family_auth_identities.supersede_reason` now also allows `LINK_ENDED`, so an ended User-Person Link retires its identity as SUPERSEDED instead of leaving it SUSPENDED. No row changes; the rollback refuses while such an identity exists. No other schema change was needed for the resolver, the link lifecycle or the account-side rules.

### DB-ADR-045
PWA-1E added **no migration**: the mobile trust lifecycle, the OTP challenge service, the throttle and the cleanup run on the PWA-1C schema unchanged. `auth_otp_challenges.uuid` is set by the service before the insert, because `code_hash` is bound to it. `famboook:purge-otp-challenges` deletes only finished challenges older than the retention period and no other table.

### DB-ADR-046
PWA-1F added **no migration**. Activation runs on the PWA-1C schema: the pre-activation lookup is an exact match on the indexed, deliberately non-unique `persons.national_id` (zero or several live matches deny; no UNIQUE constraint is added); completion locks the Person row, then the challenge row — the same order `OtpChallenges::issue` uses — and relies on the existing partial unique indexes (`uq_user_person_links_current_person`, `uq_user_person_links_current_user`, `uq_family_auth_identities_active_key`, `uq_family_auth_identities_current_user`) as the backstop against a duplicate link or identity. Decoy activation challenges are cache entries only; no Person, User or OTP row is ever created for them.

### DB-ADR-047
PWA-1G added **no migration**. Login and password reset read `family_auth_identities` through the partial unique index on the ACTIVE login key and never look an account up by `persons.national_id`. A `PASSWORD_RESET` challenge carries `user_id` (already required by `chk_auth_otp_challenge_reset_user`) and is consumed for that User and Person together. Reset completion locks the Person row, the User row, then the challenge row, and deletes the User's `sessions` rows inside the same transaction when the session driver is `database`. Decoy challenges of both purposes are cache entries only. `users.password` holds a bcrypt hash; the application refuses inputs longer than 72 bytes rather than letting bcrypt ignore the excess.

### DB-ADR-048
PWA-1H added **no migration**. Coordinator authorization runs on the PWA-1C `coordinator_scope_assignments` unchanged: the typed columns (`clan_id` always, plus `branch_group_id` or `branch_id`, each a composite foreign key with `clan_id`) and the PostgreSQL shape CHECK mean a scope id can never resolve against the wrong table, so no polymorphic reference is introduced. No `expires_at`: an assignment is effective while `revoked_at IS NULL` and its target is active. The scoped family query is one correlated EXISTS over the user's active assignments joined to `clans`, with the family's own Branch and Group required active; it never materializes family ids, uses the existing `(user_id, revoked_at)`, `families (clan_id, branch_id)` and `branches (branch_group_id)` indexes, and no index was added (none is justified at the current volume). The partial unique indexes keep one active assignment per user and target; a revoked scope may be assigned again as a new row.

### DB-ADR-049
The TweetsMS SMS integration added **no migration**. The OTP plaintext is never persisted: it lives only in process memory until the SMS is handed to TweetsMS after the response, and `auth_otp_challenges` keeps only `code_hash`. Nothing is written to `jobs` or any queue table. A delivery failure is recorded on the `OTP_ISSUED` row of `auth_security_events` through two additional allow-listed `metadata` keys, `delivery_outcome` and `delivery_reason` (safe classification codes, never a provider body, number, text or key). `send_count` and `last_sent_at` keep their meaning: they record the issue or resend, not the provider's acceptance.

### DB-ADR-059
PWA-5b Change Request engine (2026-10-07) adds **no migration**. The PWA-5a schema carries every engine invariant: `apply_failure_count` / `last_apply_failed_at` and the APPLY_FAILED event's `reason_code` (a ChangeRequestApplyFailure code that `chk_workflow_event_reason_code` accepts) record refused and failed applies; `chk_change_request_rejected_after_approval` still guards APPROVED → REJECTED; the open-conflict key is computed by the type handler under the Family row lock and not stored; the client_reference replay compares against the stored proposal (no extra copy). Test-only handlers use the existing OTHER code — no fake value is added to `chk_change_request_type`.

### DB-ADR-058
PWA-5a Change Request foundation (2026-10-07) adds two additive tables, `change_requests` and `workflow_events` (§33, §37 as implemented), and no backfill. The type is a checked varchar (no `change_request_types`, AE-1); `uuid` is the public key and `request_code` a sequence-generated human reference; the sequence is OWNED BY the column so it goes with the table. PostgreSQL CHECKs enforce: allowed status / type / reason codes, DRAFT ⇔ no `submitted_at`, review / approval / rejection / apply / cancellation actor-and-time pairs per status, APPROVED → REJECTED only as NO_LONGER_APPLICABLE after a recorded failed apply, the base-fingerprint pair (64 lowercase hex, key version ≥ 1), the apply-failure pair and an object `submitted_data`. `workflow_events` is append-only by trigger (every UPDATE and DELETE refused) as well as in the model. Both `down()` methods refuse while rows exist; after Production use the rollback is a code rollback that keeps the tables. `submitted_data` is not application-encrypted (AE-8): authorization, masking in presentation and no payload logging protect it.

### DB-ADR-057
PWA-8.2 Digital Family Card (2026-10-07) adds one additive table, `digital_credentials` (§55c): generic subject infrastructure with explicit relational integrity — `subject_type` plus a typed `family_id` foreign key (restrict), no polymorphic subject and no `person_id` until a Person credential is approved. The QR token is never stored in plaintext: a unique SHA-256 `token_hash` (a 256-bit random token needs no key) and a Crypt-sealed copy for the owner's QR. One ACTIVE credential per Family by a partial unique index; append-only history (ACTIVE → REVOKED once; reissue is a new row). No backfill.

### DB-ADR-056
PWA-3B.4 household-member sensitive reveal (2026-10-05) adds **no migration**: the new `auth_security_events.event_type` `HOUSEHOLD_MEMBER_SENSITIVE_REVEALED` (35 characters) fits `string(40)` and `chk_auth_security_event_codes`; the target Person is the existing `person_id`; the metadata key `field` is already allow-listed. The revealed value is never stored.

### DB-ADR-055
FU-13 (2026-10-05) adds **no migration**: the Family Portal household-member reference `member_ref` is the keyed HMAC-SHA256 of `family_id:membership_id` (Family Auth key, `MEMBER_REF` context), recomputed on every request and never stored, so `family_memberships` gains no column. A persisted UUID was considered and not needed (docs/11 FP-ADR-063). Server-side records store the internal membership id.

### DB-ADR-054
PWA-3B.2 self sensitive-value reveal (2026-10-05) adds **no migration**: the new `auth_security_events.event_type` `SELF_SENSITIVE_REVEALED` already satisfies `chk_auth_security_event_codes` (code format only), and the new metadata key `field` (a field code) is enforced by the model's allow-list. The revealed value is never stored.

### DB-ADR-053
FU-10 (2026-10-05) adds **no migration**. Household declarations gain an optimistic stale-state check under the existing `families` row lock (expected current declaration, `HOUSEHOLD_DECLARATION_CHANGED`); `uq_family_current_household_declaration` stays the final guard. The Staff stale-write reference is the declaration's internal id, returned only by the Staff Family profile (the table has no public identifier; adding one would need a migration and is not required). Death recording keeps its `persons` row lock; no `RegistrationSource` value is added.

### DB-ADR-052
Documentation consolidation (2026-10-04) adds **no migration**. Family Profile Review stores only append-only confirmations — a future `family_profile_confirmations` table with a keyed fingerprint (dedicated key, `key_version`, `fingerprint_version`) and no copy of registry values; completeness and states are derived. Registered Members (active memberships) and Living Members stay derived (docs/11 FP-ADR-056, FP-ADR-057).

### DB-ADR-051
First self-activation (FP-ADR-053) adds ONE forward-only migration, `2026_10_15_090000_allow_self_otp_mobile_trust`: on PostgreSQL `chk_person_mobile_trust_method` also allows `SELF_OTP`, and `chk_person_mobile_trust_granted` requires `verified_by` for every TRUSTED row except a `SELF_OTP` one (no Staff verifier exists). No row changes; down refuses while a SELF_OTP trust exists. The existing PENDING_VERIFICATION status and its partial unique index (one per Person) hold the not-yet-proven number an activation challenge is bound to; a correct code promotes that row to TRUSTED, and `uq_person_mobile_trusts_trusted` keeps one TRUSTED row per Person. The confirmation step is cache state only (masked number, keyed fingerprint, Person id).

### DB-ADR-050
PWA-1I added **no migration**. Decoy challenges remain cache state; what parallel requests change is now held in separate cache keys — an atomic attempts counter, a write-once superseded flag and one `Cache::add` claim per resend — so their behaviour matches the row-locked real challenges. The guarantees SQLite cannot prove are covered by PostgreSQL-only tests on the dedicated `famboook_test` database: verify and resend wait for the `auth_otp_challenges` row lock, issue for the `persons` row lock, completions for the Person (and, for reset, the `users`) row lock; a grant consumed by a parallel completion is refused under the lock; `uq_auth_otp_challenges_open` refuses a second open challenge. `auth_otp_challenges.ip` holds the raw client IP of the issuing request, kept at most for the 90-day retention of finished challenges; security events keep only a digest.
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
Resolved 2026-10-02 (§55b): at most one ACTIVE/SUSPENDED Link per User and per Person, as partial unique indexes.

PDB-021
Whether notification data requires additional domain-specific tables.
Direction 2026-10-02: manual announcements need their own audience/recipient records; Laravel database notifications stay valid for system events (§55a).

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
| 1.2.27 | 2026-10-02 | Approved | PWA-1E: no schema change (DB-ADR-045); OTP purge deletes finished `auth_otp_challenges` rows only |
| 1.2.28 | 2026-10-02 | Approved | PWA-1F: no schema change (DB-ADR-046); activation lookup, lock order and unique-index backstops; decoys are cache state only |
| 1.2.29 | 2026-10-03 | Approved | PWA-1G: no schema change (DB-ADR-047); identity-based lookup, reset lock order and session deletion inside the transaction |
| 1.2.30 | 2026-10-03 | Approved | PWA-1H: no schema change (DB-ADR-048); typed scope columns kept, no expiry, the scoped EXISTS query and its indexes |
| 1.2.31 | 2026-10-03 | Approved | TweetsMS: no schema change (DB-ADR-049); plaintext OTP never persisted or queued; `delivery_outcome` / `delivery_reason` allow-listed in `auth_security_events.metadata` |
| 1.2.32 | 2026-10-03 | Approved | PWA-1I: no schema change (DB-ADR-050); atomic decoy cache keys, PostgreSQL concurrency validation of the row locks and the open-challenge index, raw challenge IP retention recorded |
| 1.2.33 | 2026-10-04 | Approved | First self-activation: migration allowing `SELF_OTP` in the trust CHECKs (DB-ADR-051); pending row promoted on a correct code |
| 1.2.34 | 2026-10-04 | Approved | Documentation consolidation: no schema change (DB-ADR-052); Registered Members / Living Members wording; `family_profile_confirmations` concept and Staff Family Verification deferred in the phase-concept table |
| 1.2.35 | 2026-10-05 | Approved | FU-10: no schema change (DB-ADR-053); §25a stale-state check for household declarations; §69 death transaction as implemented (head review and access re-evaluation passive) |
| 1.2.36 | 2026-10-05 | Approved | PWA-3B.2: no schema change (DB-ADR-054); `SELF_SENSITIVE_REVEALED` event type and `field` metadata key within the existing constraints |
| 1.2.37 | 2026-10-05 | Approved | FU-13: no schema change (DB-ADR-055); `member_ref` is computed, never stored |
| 1.2.38 | 2026-10-05 | Approved | PWA-3B.4: no schema change (DB-ADR-056); `HOUSEHOLD_MEMBER_SENSITIVE_REVEALED` within the existing constraints |
| 1.2.39 | 2026-10-07 | Approved | PWA-8.2: §55c `digital_credentials` (DB-ADR-057) — Family-subject credential, unique card number and token hash, Crypt-sealed token, one ACTIVE per Family, append-only; §55a row marked implemented |
| 1.2.40 | 2026-10-07 | Approved | PWA-5a: `change_requests` and `workflow_events` implemented (§33, §37 annotated; §36 type table superseded by the code registry) with PostgreSQL CHECKs, the CRQ code sequence and the append-only trigger; safe down() (DB-ADR-058) |
| 1.2.41 | 2026-10-07 | Approved | PWA-5b: no migration — the PWA-5a schema carries the engine (failure counters and codes, AE-4 CHECK, uncommitted conflict keys, replay against the stored proposal) (DB-ADR-059) |
| 1.2.42 | 2026-10-08 | Approved | PWA-6.1a: §99 RESIDENCE_UPDATE mapping corrected to ResidenceUpdateHandler → UpdateFamilyResidenceAction (no schema change) |
| 1.2.26 | 2026-10-02 | Approved | PWA-1D: migration `2026_10_14_090000` — `family_auth_identities.supersede_reason` CHECK allows `LINK_ENDED` (DB-ADR-044). No other schema change |
| 1.2.25 | 2026-10-02 | Approved | PWA-1C: §55b implemented as schema, models and factories (seven migrations `2026_10_13_090000`–`090006`); coordinator uniqueness as three partial unique indexes, `otp_challenge_uuid` without a foreign key, open-challenge and CHECK-constraint notes, RESTRICT foreign keys, no backfill (DB-ADR-043) |
| 1.2.24 | 2026-10-02 | Approved | PWA-1B: §55b Family Portal identity schema (approved design, no migration); §55a login identifier decided; PDB-020 resolved (DB-ADR-042). Documentation only |
| 1.2.23 | 2026-10-02 | Approved | PWA-0: §55a Family Portal future schema concepts (logical design vs deferred physical schema; constraints) — no migration (DB-ADR-041). Documentation only |
| 1.2.22 | 2026-09-30 | Approved | §83d Apply execution fields: import_batches.apply_plan_fingerprint (present exactly while Apply has started) and structured apply_error_code / apply_error_row_number, with CHECKs |
| 1.2.21 | 2026-09-29 | Approved | §83d Import Apply foundation: import_batches PARTIALLY_APPLIED + apply_started_at with CHECK (started Apply never FAILED), import_apply_records (append-only provenance, unique (import_row_id, effect_key), composite row/batch FK, created-entity uniqueness, shape/outcome/entity/reason CHECKs, no polymorphic FK) |
| 1.2.20 | 2026-09-29 | Approved | §83c `import_row_reconciliations` (migration `2026_10_10_090000`), batch reconciliation columns, CHECKs; partial checksum index preserved on SQLite rebuild |
| 1.2.19 | 2026-09-29 | Approved | §83b `import_family_key_resolutions` (migration `2026_10_09_090000`): composite FKs to `import_batches (id, clan_id)` and `branches (id, clan_id)`, unique (batch, key), decision/branch/reference CHECKs; `import_batches` UNIQUE (id, clan_id) |
| 1.2.18 | 2026-09-29 | Approved | §83a Import Wizard (migration `2026_10_08_090000`): `import_batches.import_mode` NOT NULL + `chk_import_batch_mode`, `source_size_bytes`, private `source_file_path`, `worksheet_name`, structure-only `inspection`, `column_mapping` + `mapping_confirmed_at` (`chk_import_batch_mapping_confirmed`); reserved `import_rows.reconciliation_status` (`chk_import_row_reconciliation`); additive; up() refuses if mode-less batches exist |
| 1.2.17 | 2026-09-29 | Approved | §83a Initial Family Import Phase 2A (migration `2026_10_07_090000`): `import_batches.clan_id` NOT NULL FK RESTRICT + index, `uq_import_batch_clan_checksum` (one live batch per file and Clan), `import_rows.normalized_payload` JSONB and `source_family_key` VARCHAR(150) with `(import_batch_id, source_family_key)` index; additive; up() refuses if clan-less batches exist |
| 1.2.16 | 2026-09-29 | Approved | §12a: Branch Group optional — `branches.branch_group_id` nullable (migration `2026_10_06_090000`), composite FKs unchanged (MATCH SIMPLE), branch create moves to `POST /clans/{clan}/branches`, `branch_group_id` assign/move/ungroup on update, `ungrouped_branches` in the tree |
| 1.2.15 | 2026-09-29 | Approved | Initial Family Import foundation (Phase 1): §25a `family_household_declarations` (one current per Family, count/source CHECKs), §82 Registered Household Size never stored, §83a `import_batches` / `import_rows` staging tables; additive migrations only, no existing column or row changed |
| 1.2.14 | 2026-09-28 | Approved | §31: cross-family assessment registry API (`GET /assessments`, status and exact-family filters, whole-registry summary); read-only, no schema change |
| 1.2.13 | 2026-09-27 | Approved | §19: membership ending / relationship correction are in-place UPDATEs with row locks; no schema change (Pilot Readiness Slice C) |
| 1.2.12 | 2026-09-26 | Approved | §19: registry search API, `pg_trgm` trigram indexes (measured), National ID advisory-lock duplicate enforcement |
| 1.2.11 | 2026-09-26 | Approved | §51: `users.is_active`, session storage note, Staff authentication API and Filament `/admin` |
| 1.2.10 | 2026-09-26 | Approved | §19: Reports V1 API (derived on request, paginated, streamed XLSX, no storage); index review — no new index |
| 1.2.9 | 2026-09-25 | Approved | §19: `ix_family_memberships_family_active` index and the Operational Dashboard API (derived aggregates, no storage) |
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