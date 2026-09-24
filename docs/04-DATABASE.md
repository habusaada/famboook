# Famboook
## Database Architecture

**Document:** `04-DATABASE.md`  
**Version:** 1.2.2  
**Status:** Approved  
**Last Updated:** 2026-09-24  
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
Version: 1.2.2
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