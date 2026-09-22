# Famboook
## Database Architecture

**Document:** `04-DATABASE.md`  
**Version:** 1.1  
**Status:** Approved  
**Last Updated:** 2026-09-22  
**Project:** Famboook — Family Registry & Case Management System  
**Database:** PostgreSQL 16+  
**Backend:** Laravel

---

# 1. Purpose

This document defines the physical database architecture for Famboook.

It translates the approved:

```text
Product Definition
Data Dictionary
Business Rules
```

into a relational PostgreSQL model suitable for Laravel implementation.

The architecture supports:

```text
Family Registry
Person Registry
Historical Family Membership
Household Head Management
Residence & Displacement
Health
Disability
Education
Employment
Assessments
Paper/Digital Source Forms
Needs
Assistance
Documents
Case Notes
Family Portal
Family User Accounts
Change Requests
Workflow History
Notifications
Permissions
Audit
Reporting
```

---

# 2. Database Principles

Famboook follows these database principles:

```text
Normalize core registry data.

Preserve history.

Do not model paper-form limits as database limits.

Separate authentication identity from registry identity.

Separate Person identity from Family membership.

Separate proposed changes from canonical registry data.

Use relational tables for canonical domain data.

Use JSONB only where flexible structured metadata is justified.

Use database constraints for critical invariants where feasible.

Use application/domain logic for complex business rules.

Avoid destructive cascades on historical data.

Audit critical operations.

Use private storage for sensitive files.
```

---

# 3. Database Engine

Recommended:

```text
PostgreSQL 16+
```

Reasons include:

```text
Strong relational constraints
Partial unique indexes
JSONB
Transactional integrity
Advanced indexing
Reliable concurrency
Laravel support
```

---

# 4. Core Architecture

```text
IDENTITY & REGISTRY

families
persons
family_memberships
relationship_types
person_relationships
marital_statuses


LOCATION

governorates
localities
housing_types
tenure_types
housing_condition_types
family_residences


HEALTH & DISABILITY

person_health_profiles
health_condition_types
person_health_conditions
disability_types
person_disabilities


EDUCATION & EMPLOYMENT

education_levels
education_statuses
person_education

employment_statuses
employment_sectors
person_employment


ASSESSMENT & WORKFLOW

assessment_types
assessments

form_types
form_submissions

workflow_events


DOCUMENTS

document_types
documents


NEEDS & ASSISTANCE

need_types
family_needs

assistance_types
assistance_records


CASE MANAGEMENT

note_types
person_notes
case_notes


FAMILY PORTAL & SELF-SERVICE

user_person_links

change_request_types
change_requests

notifications


SECURITY & ACCESS

users
roles
permissions
model_has_roles
model_has_permissions
role_has_permissions


AUDIT

audit_logs
```

---

# 5. Identifier Strategy

Internal primary keys:

```text
BIGINT
```

Examples:

```text
families.id
persons.id
change_requests.id
```

Business identifiers are stored separately.

Examples:

```text
FAM-000510
PER-001825
CRQ-000101
ASM-000250
```

Business identifiers must not be used as physical primary keys.

---

# 6. Timestamp Standard

Standard Laravel timestamps:

```text
created_at
updated_at
```

Historical/domain timestamps are stored separately.

Examples:

```text
submitted_at
verified_at
approved_at
applied_at
started_at
ended_at
```

Recommended internal timestamp policy:

```text
UTC
```

Application UI may convert timestamps to the required local timezone.

---

# 7. Soft Deletion Strategy

Soft deletes may be used for selected primary entities such as:

```text
families
persons
```

using:

```text
deleted_at
```

However, soft deletion must not replace proper domain history.

Example:

```text
Person moves Family
```

must use:

```text
family_memberships
```

not:

```text
persons.deleted_at
```

---

# 8. families

Purpose:

Represents one persistent Family/household registry entity.

```text
families
```

Recommended columns:

| Column | Type | Null | Constraint |
|---|---|---:|---|
| id | BIGINT | No | PK |
| family_code | VARCHAR(30) | No | UNIQUE |
| status | VARCHAR(30) | No | |
| registration_date | DATE | No | |
| registration_source | VARCHAR(50) | No | |
| paper_form_no | VARCHAR(100) | Yes | |
| notes | TEXT | Yes | |
| created_by | BIGINT | Yes | FK users |
| updated_by | BIGINT | Yes | FK users |
| created_at | TIMESTAMP | No | |
| updated_at | TIMESTAMP | No | |
| deleted_at | TIMESTAMP | Yes | |

Example:

```text
FAM-000510
```

---

# 9. Family Code Constraint

```sql
UNIQUE (family_code)
```

The application generates Family Codes.

The code must remain immutable during normal operations.

---

# 10. persons

Purpose:

Represents one persistent human identity.

Recommended columns:

| Column | Type | Null | Constraint |
|---|---|---:|---|
| id | BIGINT | No | PK |
| person_code | VARCHAR(30) | No | UNIQUE |
| full_name | VARCHAR(255) | No | |
| national_id | VARCHAR(20) | Yes | |
| gender | VARCHAR(20) | No | |
| birth_date | DATE | Yes | |
| death_date | DATE | Yes | |
| marital_status_id | BIGINT | Yes | FK marital_statuses |
| life_status | VARCHAR(30) | No | |
| mobile | VARCHAR(30) | Yes | |
| alternate_mobile | VARCHAR(30) | Yes | |
| notes | TEXT | Yes | |
| is_active | BOOLEAN | No | DEFAULT TRUE |
| created_by | BIGINT | Yes | FK users |
| updated_by | BIGINT | Yes | FK users |
| created_at | TIMESTAMP | No | |
| updated_at | TIMESTAMP | No | |
| deleted_at | TIMESTAMP | Yes | |

Important:

```text
persons does NOT contain canonical family_id.
```

Family membership is represented through:

```text
family_memberships
```

---

# 11. Person Code Constraint

```sql
UNIQUE (person_code)
```

Example:

```text
PER-001825
```

---

# 12. Death Date Constraint

Recommended database check:

```sql
CHECK (
    death_date IS NULL
    OR birth_date IS NULL
    OR death_date >= birth_date
)
```

Application/domain validation additionally enforces:

```text
death_date must not be future date

life_status = ALIVE
→ death_date normally NULL

death_date present
→ life_status normally DECEASED
```

Complex lifecycle rules remain in domain logic.

---

# 13. National ID

Use:

```text
VARCHAR
```

not:

```text
INTEGER
BIGINT
```

This preserves:

```text
Leading zeros
Formatting
Future format flexibility
```

The exact encryption/search strategy remains a pending security decision.

---

# 14. National ID Index

Initial index:

```sql
CREATE INDEX idx_persons_national_id
ON persons (national_id);
```

If a normalized/search-hash architecture is introduced, this index should be replaced accordingly.

Do not create an unconditional uniqueness rule until exceptional identity cases and the approved National ID policy are finalized.

Exact duplicate conflicts are handled through duplicate review.

---

# 15. family_memberships

Purpose:

Canonical historical relationship between Person and Family.

Recommended columns:

| Column | Type | Null | Constraint |
|---|---|---:|---|
| id | BIGINT | No | PK |
| family_id | BIGINT | No | FK families |
| person_id | BIGINT | No | FK persons |
| relationship_type_id | BIGINT | No | FK relationship_types |
| is_household_head | BOOLEAN | No | DEFAULT FALSE |
| paper_sequence_no | SMALLINT | Yes | |
| started_at | DATE | Yes | |
| ended_at | DATE | Yes | |
| is_active | BOOLEAN | No | DEFAULT TRUE |
| end_reason | VARCHAR(100) | Yes | |
| notes | TEXT | Yes | |
| created_by | BIGINT | Yes | FK users |
| updated_by | BIGINT | Yes | FK users |
| created_at | TIMESTAMP | No | |
| updated_at | TIMESTAMP | No | |

---

# 16. One Active Family per Person

V1 rule:

```text
One Person
→ maximum one active primary Family membership
```

PostgreSQL partial unique index:

```sql
CREATE UNIQUE INDEX uq_person_active_family_membership
ON family_memberships (person_id)
WHERE is_active = TRUE;
```

---

# 17. One Active Household Head

Recommended PostgreSQL partial unique index:

```sql
CREATE UNIQUE INDEX uq_family_active_household_head
ON family_memberships (family_id)
WHERE is_active = TRUE
AND is_household_head = TRUE;
```

This protects:

```text
Maximum one active Household Head per Family
```

Existence of exactly one Head is enforced through domain/workflow logic because transitional states may temporarily exist within transactions.

---

# 18. Membership Date Constraint

```sql
CHECK (
    ended_at IS NULL
    OR started_at IS NULL
    OR ended_at >= started_at
)
```

---

# 19. relationship_types

Recommended columns:

```text
id BIGINT PK
code VARCHAR(50) UNIQUE
name_ar VARCHAR(150)
name_en VARCHAR(150)
description TEXT NULL
is_active BOOLEAN DEFAULT TRUE
sort_order INTEGER DEFAULT 0
created_at
updated_at
```

Example codes:

```text
HEAD
SPOUSE
SON
DAUGHTER
FATHER
MOTHER
BROTHER
SISTER
GRANDCHILD
OTHER_RELATIVE
OTHER
```

Final values require approval.

---

# 20. person_relationships

Recommended columns:

```text
id BIGINT PK

person_id BIGINT FK persons
related_person_id BIGINT FK persons

relationship_type_id BIGINT FK relationship_types

start_date DATE NULL
end_date DATE NULL

status VARCHAR(30)

notes TEXT NULL

created_at
updated_at
```

Constraint:

```sql
CHECK (person_id <> related_person_id)
```

---

# 21. marital_statuses

Standard reference table:

```text
id
code
name_ar
name_en
description
is_active
sort_order
created_at
updated_at
```

Potential codes:

```text
SINGLE
MARRIED
DIVORCED
WIDOWED
SEPARATED
UNKNOWN
```

---

# 22. governorates

Recommended columns:

```text
id
code
name_ar
name_en
is_active
sort_order
created_at
updated_at
```

---

# 23. localities

Recommended columns:

```text
id
governorate_id
code
name_ar
name_en
is_active
sort_order
created_at
updated_at
```

Relationship:

```text
Governorate
    │
    └──< Localities
```

---

# 24. housing_types

Standard reference table.

---

# 25. tenure_types

Standard reference table.

---

# 26. housing_condition_types

Standard reference table.

---

# 27. family_residences

Purpose:

Stores historical Family residence information.

Recommended columns:

```text
id BIGINT PK

family_id BIGINT FK families

governorate_id BIGINT NULL FK governorates
locality_id BIGINT NULL FK localities

neighborhood VARCHAR(255) NULL
address_details TEXT NULL

housing_type_id BIGINT NULL FK housing_types
tenure_type_id BIGINT NULL FK tenure_types
housing_condition_id BIGINT NULL FK housing_condition_types

is_displaced BOOLEAN DEFAULT FALSE

displacement_location TEXT NULL
displacement_date DATE NULL
displacement_reason TEXT NULL

is_current BOOLEAN DEFAULT TRUE

from_date DATE NULL
to_date DATE NULL

notes TEXT NULL

created_by BIGINT NULL FK users
updated_by BIGINT NULL FK users

created_at
updated_at
```

---

# 28. One Current Residence

Recommended partial unique index:

```sql
CREATE UNIQUE INDEX uq_family_current_residence
ON family_residences (family_id)
WHERE is_current = TRUE;
```

---

# 29. Residence Date Constraint

```sql
CHECK (
    to_date IS NULL
    OR from_date IS NULL
    OR to_date >= from_date
)
```

---

# 30. person_health_profiles

Purpose:

Current high-level health summary.

Recommended columns:

```text
id BIGINT PK
person_id BIGINT UNIQUE FK persons

has_health_condition BOOLEAN NULL
has_chronic_disease BOOLEAN NULL
has_disability BOOLEAN NULL

is_pregnant BOOLEAN NULL
is_breastfeeding BOOLEAN NULL

requires_follow_up BOOLEAN NULL

notes TEXT NULL

created_by BIGINT NULL FK users
updated_by BIGINT NULL FK users

created_at
updated_at
```

Classification:

```text
RESTRICTED
```

---

# 31. health_condition_types

Standard reference table.

Possible additional field:

```text
is_chronic BOOLEAN
```

---

# 32. person_health_conditions

Recommended columns:

```text
id BIGINT PK

person_id BIGINT FK persons
health_condition_type_id BIGINT FK health_condition_types

details TEXT NULL
severity VARCHAR(50) NULL

requires_treatment BOOLEAN NULL
requires_medication BOOLEAN NULL

notes TEXT NULL

created_by BIGINT NULL FK users
updated_by BIGINT NULL FK users

created_at
updated_at
```

Classification:

```text
RESTRICTED
```

---

# 33. disability_types

Standard reference table.

---

# 34. person_disabilities

Recommended columns:

```text
id BIGINT PK

person_id BIGINT FK persons
disability_type_id BIGINT FK disability_types

severity VARCHAR(50) NULL
requires_assistance BOOLEAN NULL
uses_assistive_device BOOLEAN NULL
assistive_device VARCHAR(255) NULL

notes TEXT NULL

created_by BIGINT NULL FK users
updated_by BIGINT NULL FK users

created_at
updated_at
```

Classification:

```text
RESTRICTED
```

---

# 35. education_levels

Standard reference table.

---

# 36. education_statuses

Standard reference table.

---

# 37. person_education

Recommended columns:

```text
id BIGINT PK

person_id BIGINT FK persons

is_enrolled BOOLEAN NULL
education_level_id BIGINT NULL FK education_levels
current_grade VARCHAR(100) NULL
institution_name VARCHAR(255) NULL
specialization VARCHAR(255) NULL
education_status_id BIGINT NULL FK education_statuses

from_date DATE NULL
to_date DATE NULL
is_current BOOLEAN DEFAULT FALSE

notes TEXT NULL

created_by BIGINT NULL FK users
updated_by BIGINT NULL FK users

created_at
updated_at
```

---

# 38. employment_statuses

Standard reference table.

---

# 39. employment_sectors

Standard reference table.

---

# 40. person_employment

Recommended columns:

```text
id BIGINT PK

person_id BIGINT FK persons

employment_status_id BIGINT NULL FK employment_statuses

occupation VARCHAR(255) NULL
employer VARCHAR(255) NULL

employment_sector_id BIGINT NULL FK employment_sectors

has_income BOOLEAN NULL
income_amount NUMERIC(12,2) NULL
income_frequency VARCHAR(50) NULL

from_date DATE NULL
to_date DATE NULL

is_current BOOLEAN DEFAULT FALSE

notes TEXT NULL

created_by BIGINT NULL FK users
updated_by BIGINT NULL FK users

created_at
updated_at
```

Never use floating point for monetary values.

---

# 41. assessment_types

Standard reference table.

Potential codes:

```text
INITIAL_REGISTRATION
VERIFICATION
FOLLOW_UP
NEEDS_ASSESSMENT
EMERGENCY_UPDATE
```

---

# 42. assessments

Recommended columns:

```text
id BIGINT PK

assessment_code VARCHAR(30) UNIQUE

family_id BIGINT FK families
assessment_type_id BIGINT FK assessment_types

assessment_date DATE

collector_id BIGINT NULL FK users
reviewer_id BIGINT NULL FK users

status VARCHAR(30)

location TEXT NULL
source VARCHAR(50) NULL
notes TEXT NULL

submitted_at TIMESTAMP NULL
verified_at TIMESTAMP NULL

created_at
updated_at
```

---

# 43. form_types

Standard reference table.

---

# 44. form_submissions

Recommended columns:

```text
id BIGINT PK

family_id BIGINT FK families
assessment_id BIGINT NULL FK assessments
form_type_id BIGINT FK form_types

paper_form_no VARCHAR(100) NULL
page_count INTEGER NULL
source_file VARCHAR(500) NULL

entered_by BIGINT NULL FK users
entered_at TIMESTAMP NULL

reviewed_by BIGINT NULL FK users
reviewed_at TIMESTAMP NULL

status VARCHAR(30)

return_reason TEXT NULL

created_at
updated_at
```

---

# 45. Form Submission Status

Approved baseline:

```text
DRAFT
DATA_ENTRY_COMPLETED
UNDER_REVIEW
RETURNED_FOR_CORRECTION
CORRECTED
VERIFIED
APPROVED
ARCHIVED
```

Current state is stored in:

```text
form_submissions.status
```

State-transition history is stored in:

```text
workflow_events
```

---

# 46. workflow_events

Purpose:

Stores immutable workflow state-transition history.

Recommended columns:

| Column | Type | Null | Constraint |
|---|---|---:|---|
| id | BIGINT | No | PK |
| workflowable_type | VARCHAR(100) | No | Polymorphic type |
| workflowable_id | BIGINT | No | Polymorphic ID |
| from_status | VARCHAR(30) | Yes | |
| to_status | VARCHAR(30) | No | |
| action | VARCHAR(50) | No | |
| reason | TEXT | Yes | |
| metadata | JSONB | Yes | |
| performed_by | BIGINT | Yes | FK users |
| created_at | TIMESTAMP | No | |

Examples:

```text
DRAFT → DATA_ENTRY_COMPLETED

UNDER_REVIEW → RETURNED_FOR_CORRECTION

UNDER_REVIEW → VERIFIED

VERIFIED → APPROVED

DRAFT → SUBMITTED

UNDER_REVIEW → APPROVED

APPROVED → APPLIED
```

---

# 47. Workflow Polymorphism

Conceptually:

```text
workflow_events
      │
      ├── FormSubmission
      ├── Assessment
      ├── FamilyNeed
      └── ChangeRequest
```

Laravel relationship:

```php
public function workflowable()
{
    return $this->morphTo();
}
```

Workflow-controlled models may use:

```php
public function workflowEvents()
{
    return $this->morphMany(WorkflowEvent::class, 'workflowable');
}
```

---

# 48. Workflow Event Indexes

Recommended:

```sql
CREATE INDEX idx_workflow_events_entity
ON workflow_events (workflowable_type, workflowable_id);
```

```sql
CREATE INDEX idx_workflow_events_performed_by
ON workflow_events (performed_by);
```

```sql
CREATE INDEX idx_workflow_events_created_at
ON workflow_events (created_at);
```

```sql
CREATE INDEX idx_workflow_events_to_status
ON workflow_events (to_status);
```

---

# 49. Workflow Event Immutability

Normal application users must not:

```text
UPDATE workflow_events
DELETE workflow_events
```

Workflow events are append-only historical records.

---

# 50. document_types

Standard reference table.

Potential codes:

```text
NATIONAL_ID
BIRTH_CERTIFICATE
MARRIAGE_CERTIFICATE
DEATH_CERTIFICATE
MEDICAL_REPORT
DISABILITY_REPORT
RESIDENCE_EVIDENCE
OTHER
```

---

# 51. documents

Recommended columns:

```text
id BIGINT PK

family_id BIGINT NULL FK families
person_id BIGINT NULL FK persons
change_request_id BIGINT NULL FK change_requests

document_type_id BIGINT FK document_types

document_number VARCHAR(100) NULL

is_available BOOLEAN DEFAULT FALSE
is_verified BOOLEAN DEFAULT FALSE

issue_date DATE NULL
expiry_date DATE NULL

file_path VARCHAR(500) NULL

uploaded_by BIGINT NULL FK users

verified_by BIGINT NULL FK users
verified_at TIMESTAMP NULL

notes TEXT NULL

created_at
updated_at
```

---

# 52. Document Context Constraint

A document must belong to at least one valid business context.

Conceptually:

```sql
CHECK (
    family_id IS NOT NULL
    OR person_id IS NOT NULL
    OR change_request_id IS NOT NULL
)
```

Multiple contexts may exist where justified.

Example:

```text
Change Request supporting document
+
Person context
```

However, context promotion must be explicit.

---

# 53. Document Storage

`file_path` must point to private storage.

Do not expose sensitive files through:

```text
/public
```

or permanently public URLs.

Downloads should pass through an authorized endpoint or short-lived signed mechanism.

---

# 54. need_types

Standard reference table.

Potential codes:

```text
FOOD
SHELTER
HEALTH
MEDICATION
EDUCATION
WASH
PROTECTION
ASSISTIVE_DEVICE
CLOTHING
CASH
LIVELIHOOD
OTHER
```

---

# 55. family_needs

Recommended columns:

```text
id BIGINT PK

family_id BIGINT FK families
person_id BIGINT NULL FK persons
assessment_id BIGINT NULL FK assessments

need_type_id BIGINT FK need_types

priority VARCHAR(30) NULL
description TEXT NULL
status VARCHAR(30)

identified_at DATE NULL

is_verified BOOLEAN DEFAULT FALSE
verified_by BIGINT NULL FK users
verified_at TIMESTAMP NULL

created_by BIGINT NULL FK users
updated_by BIGINT NULL FK users

created_at
updated_at
```

---

# 56. Need Status

Conceptual lifecycle:

```text
IDENTIFIED
VERIFIED
ACTIVE
PARTIALLY_MET
MET
CLOSED
```

---

# 57. assistance_types

Standard reference table.

---

# 58. assistance_records

Recommended columns:

```text
id BIGINT PK

family_id BIGINT FK families
person_id BIGINT NULL FK persons
need_id BIGINT NULL FK family_needs

assistance_type_id BIGINT FK assistance_types

provider_name VARCHAR(255) NULL
description TEXT NULL

quantity NUMERIC(12,2) NULL
unit VARCHAR(50) NULL

estimated_value NUMERIC(12,2) NULL
currency VARCHAR(10) NULL

received_at DATE NULL
distribution_reference VARCHAR(100) NULL

notes TEXT NULL

created_by BIGINT NULL FK users
updated_by BIGINT NULL FK users

created_at
updated_at
```

---

# 59. note_types

Standard reference table.

---

# 60. person_notes

Recommended columns:

```text
id BIGINT PK

person_id BIGINT FK persons
note_type_id BIGINT NULL FK note_types

note TEXT

is_confidential BOOLEAN DEFAULT FALSE

created_by BIGINT FK users

created_at
updated_at
```

---

# 61. case_notes

Recommended columns:

```text
id BIGINT PK

family_id BIGINT FK families
assessment_id BIGINT NULL FK assessments
person_id BIGINT NULL FK persons

note_type_id BIGINT NULL FK note_types

note TEXT

is_confidential BOOLEAN DEFAULT FALSE

created_by BIGINT FK users

created_at
updated_at
```

Internal notes must never be automatically exposed to Family Portal users.

---

# 62. users

Laravel authentication table.

Recommended core columns:

```text
id BIGINT PK

name VARCHAR(255)

email VARCHAR(255) NULL
mobile VARCHAR(30) NULL

email_verified_at TIMESTAMP NULL
mobile_verified_at TIMESTAMP NULL

password VARCHAR(255)

is_active BOOLEAN DEFAULT TRUE

last_login_at TIMESTAMP NULL

remember_token VARCHAR(100) NULL

created_at
updated_at
```

Possible unique constraints depend on the final authentication method.

---

# 63. User vs Person

The architecture intentionally separates:

```text
users
```

from:

```text
persons
```

Meaning:

```text
User
=
Authentication identity

Person
=
Registry identity
```

Staff users do not require a Person record.

Family Users normally require an approved User-Person link.

---

# 64. user_person_links

Purpose:

Links an authenticated User account to a registry Person.

This supports Family Portal authorization without coupling authentication identity directly to Person.

Recommended columns:

| Column | Type | Null | Constraint |
|---|---|---:|---|
| id | BIGINT | No | PK |
| user_id | BIGINT | No | FK users |
| person_id | BIGINT | No | FK persons |
| link_type | VARCHAR(30) | No | |
| status | VARCHAR(30) | No | |
| verified_by | BIGINT | Yes | FK users |
| verified_at | TIMESTAMP | Yes | |
| activated_at | TIMESTAMP | Yes | |
| ended_at | TIMESTAMP | Yes | |
| end_reason | VARCHAR(255) | Yes | |
| created_at | TIMESTAMP | No | |
| updated_at | TIMESTAMP | No | |

---

# 65. User-Person Link Types

Initial V1:

```text
SELF
```

Future possibilities:

```text
GUARDIAN
AUTHORIZED_REPRESENTATIVE
```

must not be enabled until business rules are approved.

---

# 66. User-Person Link Status

Recommended:

```text
PENDING_VERIFICATION
VERIFIED
ACTIVE
SUSPENDED
ENDED
```

---

# 67. User-Person Uniqueness

Baseline recommendation:

```sql
CREATE UNIQUE INDEX uq_active_user_person_link
ON user_person_links (user_id, person_id)
WHERE status IN ('VERIFIED', 'ACTIVE');
```

Application logic must prevent conflicting active identity links.

Whether one User may eventually link to multiple Persons remains a pending decision.

---

# 68. Family Portal Scope Resolution

Family Portal scope must be derived dynamically.

Conceptually:

```text
Authenticated User
      ↓
Active User-Person Link
      ↓
Person
      ↓
Active Family Membership
      ↓
Family
```

Do not rely on:

```text
users.family_id
```

as canonical Family authorization.

---

# 69. change_request_types

Purpose:

Defines supported Family Portal update request categories.

Recommended columns:

```text
id BIGINT PK

code VARCHAR(50) UNIQUE

name_ar VARCHAR(150)
name_en VARCHAR(150)

description TEXT NULL

risk_level VARCHAR(20) NULL

requires_document BOOLEAN DEFAULT FALSE

is_active BOOLEAN DEFAULT TRUE
sort_order INTEGER DEFAULT 0

created_at
updated_at
```

---

# 70. Initial Change Request Types

Recommended seed codes:

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

Allowed payload fields remain code/domain-defined in V1.

---

# 71. change_requests

Purpose:

Stores proposed Family/User changes before they are applied to canonical registry data.

Recommended columns:

| Column | Type | Null | Constraint |
|---|---|---:|---|
| id | BIGINT | No | PK |
| request_code | VARCHAR(30) | No | UNIQUE |
| family_id | BIGINT | No | FK families |
| person_id | BIGINT | Yes | FK persons |
| change_request_type_id | BIGINT | No | FK change_request_types |
| status | VARCHAR(30) | No | |
| risk_level | VARCHAR(20) | Yes | |
| submitted_data | JSONB | No | |
| reason | TEXT | Yes | |
| notes | TEXT | Yes | |
| submitted_by | BIGINT | No | FK users |
| submitted_at | TIMESTAMP | Yes | |
| reviewed_by | BIGINT | Yes | FK users |
| reviewed_at | TIMESTAMP | Yes | |
| review_notes | TEXT | Yes | |
| approved_by | BIGINT | Yes | FK users |
| approved_at | TIMESTAMP | Yes | |
| rejected_by | BIGINT | Yes | FK users |
| rejected_at | TIMESTAMP | Yes | |
| rejection_reason | TEXT | Yes | |
| applied_by | BIGINT | Yes | FK users |
| applied_at | TIMESTAMP | Yes | |
| created_at | TIMESTAMP | No | |
| updated_at | TIMESTAMP | No | |

---

# 72. Change Request Code

Recommended format:

```text
CRQ-000001
```

Constraint:

```sql
UNIQUE (request_code)
```

---

# 73. Change Request Status

Approved baseline:

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

Current state:

```text
change_requests.status
```

Historical transitions:

```text
workflow_events
```

---

# 74. Change Request Proposed Data

`submitted_data` uses:

```text
JSONB
```

Example:

```json
{
    "mobile": "0560000000"
}
```

The JSON payload is not canonical registry storage.

It represents:

```text
Proposed Data
```

Each Change Request Type must define allowed payload fields through application/domain validation.

---

# 75. JSONB Rule

Do not use:

```text
change_requests.submitted_data
```

as a replacement for normalized domain tables.

Example:

Approved residence data ultimately belongs in:

```text
family_residences
```

not permanently only in:

```text
submitted_data
```

---

# 76. Change Request Indexes

Recommended:

```sql
CREATE INDEX idx_change_requests_family
ON change_requests (family_id);
```

```sql
CREATE INDEX idx_change_requests_person
ON change_requests (person_id);
```

```sql
CREATE INDEX idx_change_requests_type
ON change_requests (change_request_type_id);
```

```sql
CREATE INDEX idx_change_requests_status
ON change_requests (status);
```

```sql
CREATE INDEX idx_change_requests_submitted_by
ON change_requests (submitted_by);
```

```sql
CREATE INDEX idx_change_requests_submitted_at
ON change_requests (submitted_at);
```

Operational queue index may later use:

```text
(status, created_at)
```

or:

```text
(status, submitted_at)
```

based on query patterns.

---

# 77. Change Request Workflow

Conceptually:

```text
DRAFT
  ↓
SUBMITTED
  ↓
UNDER_REVIEW
  ├── RETURNED_FOR_CLARIFICATION
  │       ↓
  │   RESUBMITTED
  │       ↓
  │   UNDER_REVIEW
  │
  ├── REJECTED
  │
  └── APPROVED
          ↓
       APPLIED
```

The exact transition implementation is defined in:

```text
05-WORKFLOWS.md
```

---

# 78. Change Request APPROVED vs APPLIED

These states are deliberately separate.

```text
APPROVED
```

means business authorization has been granted.

```text
APPLIED
```

means the canonical registry operation completed successfully.

---

# 79. Change Request Application

Approved Change Requests must be executed through normal domain actions.

Examples:

```text
CONTACT_UPDATE
→ UpdatePersonContactAction

RESIDENCE_UPDATE
→ ChangeFamilyResidenceAction

HOUSEHOLD_HEAD_CHANGE
→ ChangeHouseholdHeadAction

DEATH_REPORT
→ RecordPersonDeathAction

ADD_FAMILY_MEMBER
→ CreateOrLinkFamilyMemberAction
```

Do not directly apply arbitrary JSON fields to Eloquent models.

Prohibited:

```php
$person->update($changeRequest->submitted_data);
```

The request type must map to a controlled domain operation.

---

# 80. Change Request Application Transaction

Application must be transactional.

Conceptually:

```text
BEGIN

Lock Change Request

Confirm status = APPROVED

Revalidate current registry state

Execute domain operation

Write audit log

Write workflow event

Set:
status = APPLIED
applied_by
applied_at

COMMIT
```

Failure:

```text
ROLLBACK
```

The request must not be marked `APPLIED`.

---

# 81. Change Request Concurrency

Recommended application pattern:

```text
SELECT ... FOR UPDATE
```

or Laravel equivalent:

```php
lockForUpdate()
```

for critical application flows.

This prevents concurrent double application.

---

# 82. Change Request Idempotency

Application logic must check:

```text
status === APPROVED
```

before execution.

If:

```text
status === APPLIED
```

the domain operation must not execute again.

---

# 83. Supporting Documents

Change Request documents use:

```text
documents.change_request_id
```

Examples:

```text
Birth Certificate
Death Certificate
Marriage Certificate
Residence Evidence
```

They remain unverified until staff verification.

---

# 84. notifications

Recommended V1 implementation:

Use Laravel's standard database notification infrastructure where practical.

Conceptual table:

```text
notifications
```

Laravel commonly provides:

```text
id UUID
type VARCHAR
notifiable_type VARCHAR
notifiable_id BIGINT
data TEXT/JSON
read_at TIMESTAMP NULL
created_at
updated_at
```

This avoids creating a separate notification architecture unless additional domain requirements emerge.

---

# 85. Notification Data

Notification payload should contain only the minimum necessary information.

Example:

```json
{
    "event": "CHANGE_REQUEST_APPROVED",
    "request_code": "CRQ-000101"
}
```

Avoid storing unnecessary:

```text
National IDs
Health Details
Confidential Notes
```

inside notification payloads.

---

# 86. Family User Notifications

Potential events:

```text
CHANGE_REQUEST_SUBMITTED
CHANGE_REQUEST_UNDER_REVIEW
CHANGE_REQUEST_RETURNED
CHANGE_REQUEST_RESUBMITTED
CHANGE_REQUEST_APPROVED
CHANGE_REQUEST_REJECTED
CHANGE_REQUEST_APPLIED

ACCOUNT_ACTIVATED
ACCOUNT_SUSPENDED
```

---

# 87. Roles and Permissions

Recommended Laravel package:

```text
spatie/laravel-permission
```

Standard tables:

```text
roles
permissions
model_has_roles
model_has_permissions
role_has_permissions
```

The exact permission matrix is defined in:

```text
06-PERMISSIONS.md
```

---

# 88. FAMILY_USER Role

`FAMILY_USER` is an authorization role.

It does not replace:

```text
user_person_links
family_memberships
```

A valid role alone does not establish Family scope.

Required authorization concept:

```text
Role
+
Active User-Person Link
+
Current Membership
+
Resource Policy
```

---

# 89. Audit Logs

Recommended logical fields:

```text
id BIGINT PK

actor_id BIGINT NULL FK users

event VARCHAR(100)

entity_type VARCHAR(150)
entity_id BIGINT

old_values JSONB NULL
new_values JSONB NULL

metadata JSONB NULL

ip_address VARCHAR(45) NULL
user_agent TEXT NULL

created_at TIMESTAMP
```

The physical schema may be aligned with the selected Laravel activity/audit package.

---

# 90. Audit vs Workflow

```text
workflow_events
```

answers:

```text
How did this business process move through states?
```

`audit_logs` answers:

```text
What system/data change occurred?
```

Example Change Request application may create:

```text
Workflow Event:
APPROVED → APPLIED
```

and:

```text
Audit Log:
persons.mobile changed
```

---

# 91. Audit Immutability

Normal application roles must not have:

```text
audit.update
audit.delete
```

Audit data is append-only.

---

# 92. Delete Strategy

Use:

```text
RESTRICT
NO ACTION
```

for core historical relationships where deletion could destroy registry integrity.

Avoid destructive cascade deletes for:

```text
families
persons
family_memberships
assessments
form_submissions
workflow_events
change_requests
documents
family_needs
assistance_records
case_notes
audit_logs
```

Reference values should normally be deactivated rather than deleted.

---

# 93. Historical Tables

The following are historical/append-oriented:

```text
family_memberships
family_residences
assessments
form_submissions
workflow_events
change_requests
assistance_records
person_notes
case_notes
audit_logs
user_person_links
```

The exact mutability varies by entity, but history must not be silently destroyed.

---

# 94. Derived Data

Do not create canonical columns such as:

```text
persons.age

families.family_size

families.children_count

families.disabled_count

families.chronic_count

families.pending_requests_count
```

These values should be derived from source records.

---

# 95. Money Types

Use:

```text
NUMERIC
```

Example:

```text
NUMERIC(12,2)
```

Never use:

```text
FLOAT
DOUBLE
```

for monetary values.

---

# 96. Phone Types

Use:

```text
VARCHAR
```

Phone numbers are identifiers/contact strings, not numeric quantities.

---

# 97. Reference Table Pattern

Recommended:

```text
id BIGINT PK

code VARCHAR(50) UNIQUE

name_ar VARCHAR(150)
name_en VARCHAR(150)

description TEXT NULL

is_active BOOLEAN DEFAULT TRUE

sort_order INTEGER DEFAULT 0

created_at
updated_at
```

---

# 98. Reference Tables

V1 includes:

```text
relationship_types
marital_statuses

governorates
localities

housing_types
tenure_types
housing_condition_types

health_condition_types
disability_types

education_levels
education_statuses

employment_statuses
employment_sectors

assessment_types
form_types

document_types

need_types
assistance_types

note_types

change_request_types
```

---

# 99. Sensitive Data Classification

Restricted data includes:

```text
National ID
Health
Disability
Identity Documents
Medical Documents
Confidential Notes
Sensitive Change Request Payloads
```

Database design must support application-level authorization and masking.

---

# 100. Encryption Candidates

Potential application-level encrypted fields include:

```text
national_id
document_number
selected confidential note content
selected sensitive Change Request fields
```

However, searchable encrypted fields require careful design.

Potential pattern:

```text
encrypted value
+
normalized search hash
```

Final decision remains pending.

---

# 101. National ID Search Hash

If encryption is adopted, possible architecture:

```text
national_id_encrypted
national_id_hash
national_id_last4
```

where:

```text
national_id_hash
=
HMAC(normalized_national_id)
```

rather than a plain unsalted general-purpose hash.

This must be finalized before production identity data migration.

---

# 102. Search Indexes

Recommended initial indexes:

```text
families.family_code

persons.person_code
persons.national_id
persons.full_name
persons.mobile

family_memberships.family_id
family_memberships.person_id

family_residences.family_id

assessments.family_id

form_submissions.family_id
form_submissions.status

change_requests.request_code
change_requests.family_id
change_requests.person_id
change_requests.status

documents.family_id
documents.person_id
documents.change_request_id
```

---

# 103. Name Search

Initial implementation may use standard PostgreSQL text search/indexing.

If fuzzy Arabic/English name search becomes important, consider:

```text
pg_trgm
```

with appropriate indexes.

This is not required for the first migration set.

---

# 104. Assessment Snapshots

Some information is time-sensitive.

Examples:

```text
Pregnancy
Breastfeeding
Employment
Residence
Needs
```

Current tables may represent canonical/current state plus history.

If exact point-in-time longitudinal reporting is required, assessment-linked observation/snapshot tables may be introduced.

Do not duplicate all registry tables prematurely.

---

# 105. ERD — Registry Core

```text
families
   │
   ├──< family_memberships >── persons
   │                               │
   │                               ├── person_health_profiles
   │                               ├── person_health_conditions
   │                               ├── person_disabilities
   │                               ├── person_education
   │                               ├── person_employment
   │                               ├── person_notes
   │                               └── documents
   │
   ├── family_residences
   ├── assessments
   ├── form_submissions
   ├── family_needs
   ├── assistance_records
   ├── case_notes
   ├── documents
   └── change_requests
```

---

# 106. ERD — Person Relationships

```text
persons
   │
   └──< person_relationships >── persons
```

---

# 107. ERD — Family Portal

```text
users
  │
  └──< user_person_links >── persons
                              │
                              └── family_memberships
                                      │
                                      └── families
                                              │
                                              └── change_requests
```

This is the authorization path for Family scope.

---

# 108. ERD — Change Requests

```text
change_request_types
        │
        └──< change_requests
                  │
                  ├── family
                  ├── optional person
                  ├── submitted_by user
                  ├── reviewer
                  ├── approver
                  ├── applier
                  │
                  ├──< documents
                  │
                  └──< workflow_events
```

---

# 109. ERD — Workflow

Conceptually:

```text
form_submissions ─┐
assessments      ─┤
family_needs     ─┼──< workflow_events
change_requests  ─┘
```

This is polymorphic.

There is no direct relational FK from:

```text
workflowable_id
```

to every target table.

Referential validity is enforced by application/domain logic.

---

# 110. ERD — Documents

```text
document_types
      │
      └──< documents
               │
               ├── Family
               ├── Person
               └── Change Request
```

---

# 111. Laravel Model Relationships — Family

Conceptual:

```php
class Family extends Model
{
    public function memberships()
    {
        return $this->hasMany(FamilyMembership::class);
    }

    public function residences()
    {
        return $this->hasMany(FamilyResidence::class);
    }

    public function assessments()
    {
        return $this->hasMany(Assessment::class);
    }

    public function formSubmissions()
    {
        return $this->hasMany(FormSubmission::class);
    }

    public function needs()
    {
        return $this->hasMany(FamilyNeed::class);
    }

    public function assistanceRecords()
    {
        return $this->hasMany(AssistanceRecord::class);
    }

    public function changeRequests()
    {
        return $this->hasMany(ChangeRequest::class);
    }
}
```

---

# 112. Laravel Model Relationships — Person

Conceptual:

```php
class Person extends Model
{
    public function memberships()
    {
        return $this->hasMany(FamilyMembership::class);
    }

    public function userLinks()
    {
        return $this->hasMany(UserPersonLink::class);
    }

    public function changeRequests()
    {
        return $this->hasMany(ChangeRequest::class);
    }

    public function healthProfile()
    {
        return $this->hasOne(PersonHealthProfile::class);
    }

    public function healthConditions()
    {
        return $this->hasMany(PersonHealthCondition::class);
    }

    public function disabilities()
    {
        return $this->hasMany(PersonDisability::class);
    }

    public function educationRecords()
    {
        return $this->hasMany(PersonEducation::class);
    }

    public function employmentRecords()
    {
        return $this->hasMany(PersonEmployment::class);
    }
}
```

---

# 113. Laravel Model Relationships — User

Conceptual:

```php
class User extends Authenticatable
{
    public function personLinks()
    {
        return $this->hasMany(UserPersonLink::class);
    }

    public function submittedChangeRequests()
    {
        return $this->hasMany(
            ChangeRequest::class,
            'submitted_by'
        );
    }
}
```

Roles and permissions are provided by Spatie Permission.

---

# 114. Laravel Model Relationships — UserPersonLink

```php
class UserPersonLink extends Model
{
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function person()
    {
        return $this->belongsTo(Person::class);
    }
}
```

---

# 115. Laravel Model Relationships — ChangeRequest

Conceptual:

```php
class ChangeRequest extends Model
{
    protected $casts = [
        'submitted_data' => 'array',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'applied_at' => 'datetime',
    ];

    public function family()
    {
        return $this->belongsTo(Family::class);
    }

    public function person()
    {
        return $this->belongsTo(Person::class);
    }

    public function type()
    {
        return $this->belongsTo(ChangeRequestType::class);
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function documents()
    {
        return $this->hasMany(Document::class);
    }

    public function workflowEvents()
    {
        return $this->morphMany(
            WorkflowEvent::class,
            'workflowable'
        );
    }
}
```

---

# 116. Laravel Domain Actions

Complex operations should not live directly inside:

```text
Controllers
Filament Resources
Livewire Components
API Controllers
```

Recommended action/service classes include:

```text
CreateFamilyAction

CreatePersonAction

AddFamilyMemberAction

TransferFamilyMemberAction

ChangeHouseholdHeadAction

ChangeFamilyResidenceAction

RecordPersonDeathAction

SubmitFormAction

VerifyFormAction

ApproveFormAction

SubmitChangeRequestAction

ReturnChangeRequestAction

ApproveChangeRequestAction

RejectChangeRequestAction

ApplyChangeRequestAction

CreateOrLinkFamilyMemberAction
```

---

# 117. Shared Domain Operations

Staff Portal and Family Portal workflows must ultimately use the same domain operations.

Example:

```text
Staff directly performs approved Head change
```

and:

```text
Approved Family Change Request
```

should both ultimately invoke:

```text
ChangeHouseholdHeadAction
```

This prevents duplicated business logic.

---

# 118. Transaction Boundaries

Transactions are required for operations such as:

```text
Create Family + Head + Membership

Change Household Head

Transfer Person between Families

Change Current Residence

Record Death with dependent changes

Apply Change Request

Create Assistance with linked updates

Workflow transition + workflow_events insert
```

---

# 119. Create Family Transaction

Conceptually:

```text
BEGIN

Create Family

Create or identify Person

Create Family Membership

Assign Household Head

Create initial Residence if available

Create Audit entries

COMMIT
```

---

# 120. Household Head Transaction

Conceptually:

```text
BEGIN

Lock Family relevant memberships

Validate current Head

Validate proposed Head

Clear previous Head flag

Set new Head flag

Record Audit

Reevaluate Family Portal authorization

COMMIT
```

---

# 121. Person Transfer Transaction

Conceptually:

```text
BEGIN

Lock active Person membership

Validate target Family

Close old membership

Create new membership

Review Household Head implications

Review Family Portal authorization

Record Audit

COMMIT
```

---

# 122. Residence Change Transaction

Conceptually:

```text
BEGIN

Lock current Family residence

Close previous current residence

Create new current residence

Record Audit

COMMIT
```

---

# 123. Record Death Transaction

Conceptually:

```text
BEGIN

Lock Person

Validate death information

Update:
life_status = DECEASED

Set death_date if known

Record Audit

If Person is Household Head:
    trigger/record Head review requirement

Review active Family Portal authorization

COMMIT
```

The exact Head-review workflow is defined separately.

---

# 124. Apply Change Request Transaction

Conceptually:

```text
BEGIN

Lock Change Request

Validate status = APPROVED

Validate submitter/family/request integrity

Revalidate current canonical registry state

Execute mapped domain action

Write domain audit records

Write:
workflow_event APPROVED → APPLIED

Set:
status = APPLIED
applied_by
applied_at

COMMIT
```

If any step fails:

```text
ROLLBACK
```

---

# 125. Workflow Transition Transaction

Every controlled workflow transition should update:

```text
Current entity status
+
workflow_events
```

inside the same database transaction.

This prevents:

```text
Status changed
but
history missing
```

or the reverse.

---

# 126. Concurrency Guards

Critical operations should use appropriate:

```text
Row locking
Unique constraints
Transactions
Optimistic locking where appropriate
```

Examples:

```text
Household Head Change
Person Transfer
Change Request Application
Duplicate-sensitive Person Creation
Current Residence Change
```

---

# 127. Optimistic Concurrency

For selected forms/edit screens, an application-level version or `updated_at` comparison may prevent overwriting another user's changes.

Example:

```text
Record loaded at T1
Another user updates at T2
Original user submits at T3
```

The system should detect stale data where overwriting would be unsafe.

---

# 128. Change Request Stale Data

Approval does not guarantee that the underlying registry remains unchanged until application.

Therefore:

```text
ApplyChangeRequestAction
```

must revalidate the current registry.

Example:

```text
Request proposes Person as Household Head
```

but before application:

```text
Person membership ended
```

Application must fail safely rather than violate invariants.

---

# 129. Migration Strategy

Migrations should be small and ordered according to dependencies.

Recommended order:

```text
01 users

02 reference tables

03 families

04 persons

05 family_memberships

06 person_relationships

07 family_residences

08 person_health_profiles

09 person_health_conditions

10 person_disabilities

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

---

# 130. Migration Dependency Note

`workflow_events` is polymorphic.

Therefore it does not require physical foreign keys to:

```text
form_submissions
assessments
family_needs
change_requests
```

and may be created before some of those tables.

`documents.change_request_id`, however, requires:

```text
change_requests
```

to exist first.

---

# 131. Users Migration

If Laravel's default users migration already exists, modify/extend it carefully rather than creating incompatible duplicate structures.

Possible additions:

```text
mobile
mobile_verified_at
is_active
last_login_at
```

---

# 132. Reference Data Seeders

Seed only approved reference data.

Examples:

```text
Relationship Types
Marital Statuses
Assessment Types
Form Types
Document Types
Need Types
Change Request Types
Roles
Permissions
```

Seeders should be idempotent.

---

# 133. No Assumed Reference Data

If a paper-form label is unclear:

```text
Do not invent production seed values.
```

Keep the item:

```text
PENDING VERIFICATION
```

until approved.

---

# 134. Role Seeder

Initial roles may include:

```text
SUPER_ADMIN
ADMINISTRATOR
DATA_ENTRY
REVIEWER
SOCIAL_WORKER
REPORTS_VIEWER
FAMILY_USER
```

Exact permissions are defined in:

```text
06-PERMISSIONS.md
```

---

# 135. Permission Seeder

Permissions should use stable machine-readable codes.

Examples:

```text
families.view
families.create
families.update
families.archive

persons.view
persons.create
persons.update

change_requests.create
change_requests.view
change_requests.review
change_requests.approve
change_requests.reject
change_requests.apply

documents.upload
documents.verify

audit.view
```

Do not hard-code role names throughout domain logic.

Prefer:

```text
Permission checks
Policies
Domain authorization
```

---

# 136. Family Portal Authorization Query

Conceptually, Family scope resolution may use:

```sql
SELECT f.*
FROM families f
JOIN family_memberships fm
    ON fm.family_id = f.id
JOIN persons p
    ON p.id = fm.person_id
JOIN user_person_links upl
    ON upl.person_id = p.id
WHERE upl.user_id = ?
  AND upl.status = 'ACTIVE'
  AND fm.is_active = TRUE;
```

Additional policy rules may require:

```text
fm.is_household_head = TRUE
```

for V1.

This must be implemented through authorization/domain services rather than copied throughout controllers.

---

# 137. Family Portal Scope Service

Recommended application abstraction:

```text
FamilyAccessService
```

or equivalent.

Responsibilities:

```text
Resolve User → Person
Resolve Person → Active Membership
Resolve authorized Family scope
Check Household Head policy
Check sensitive member scope
```

This prevents inconsistent authorization queries.

---

# 138. Change Request Payload Validation

Recommended implementation:

```text
ChangeRequestType
      ↓
Dedicated Validator / DTO
```

Examples:

```text
ContactUpdateData

ResidenceUpdateData

PersonCorrectionData

AddFamilyMemberData

HouseholdHeadChangeData

DeathReportData
```

Avoid one unrestricted generic JSON validator.

---

# 139. Change Request Action Mapping

Recommended architecture:

```text
CONTACT_UPDATE
→ ApplyContactUpdateAction

RESIDENCE_UPDATE
→ ChangeFamilyResidenceAction

PERSON_CORRECTION
→ ApplyPersonCorrectionAction

ADD_FAMILY_MEMBER
→ CreateOrLinkFamilyMemberAction

HOUSEHOLD_HEAD_CHANGE
→ ChangeHouseholdHeadAction

DEATH_REPORT
→ RecordPersonDeathAction
```

Mapping should be explicit.

---

# 140. File Upload Architecture

Recommended:

```text
Laravel Storage
+
Private disk
```

Metadata remains in:

```text
documents
```

Physical file names should be generated by the system.

Do not trust uploaded filenames as storage paths.

---

# 141. File Download Architecture

Recommended:

```text
Authenticated request
      ↓
Policy check
      ↓
Document authorization
      ↓
Stream file
```

or a short-lived signed private-storage URL where supported.

Never expose permanent public document URLs.

---

# 142. Backup Strategy

Production database backups must be:

```text
Automated
Encrypted where possible
Access-controlled
Tested for restoration
Retained according to policy
```

Sensitive file storage must have a corresponding backup/recovery strategy.

---

# 143. Development Data

Use:

```text
Factories
Seeders
Synthetic data
```

Never copy real Family Registry records into Git.

---

# 144. Test Data

Automated tests should cover:

```text
Family creation

Person creation

Duplicate National ID detection

Membership transfer

Household Head change

Current Residence uniqueness

Death recording

Family User account link

Family scope resolution

Unauthorized Family access

Change Request submission

Change Request return

Change Request approval

Change Request rejection

Change Request application

Double application prevention

Supporting document authorization

Sensitive field restrictions
```

---

# 145. Database Testing

Important constraints should have direct tests.

Examples:

```text
Cannot create two active memberships for one Person.

Cannot create two active Heads for one Family.

Cannot create two current residences for one Family.

Cannot relate Person to self.

Cannot duplicate family_code.

Cannot duplicate person_code.

Cannot duplicate request_code.

Cannot apply Change Request twice.
```

---

# 146. Security Testing

Family Portal tests must include object-level authorization.

Example:

```text
Family User A
→ authorized Family 510
```

Attempt:

```text
GET /family-portal/families/511
```

Expected:

```text
403
```

or appropriate non-disclosing response.

---

# 147. Data Masking

Masking should occur before sensitive values reach unauthorized UI/API responses.

Do not rely on CSS/JavaScript to hide values already delivered to the client.

Example:

```text
National ID
```

must be transformed server-side according to permission.

---

# 148. API Serialization

Family Portal and Staff APIs should use:

```text
API Resources
DTOs
Transformers
```

rather than blindly serializing complete Eloquent models.

This is especially important for:

```text
persons
documents
change_requests
health data
case notes
```

---

# 149. Mass Assignment

Sensitive models must not accept unrestricted request payloads.

Avoid patterns equivalent to:

```php
$model->update($request->all());
```

especially for:

```text
Person
FamilyMembership
ChangeRequest
Document
UserPersonLink
```

Use validated DTOs/actions.

---

# 150. Change Request JSON Security

`submitted_data` may contain sensitive proposed data.

Therefore:

```text
Do not expose it generically.

Do not log it indiscriminately.

Do not include it in notifications.

Do not include it in exception messages unnecessarily.

Authorize access by request type and role.
```

---

# 151. Database Foreign Key Strategy

Core FK behavior should generally favor:

```text
RESTRICT
NO ACTION
```

over destructive cascades.

Example:

Deleting a Family must not cascade-delete:

```text
Persons
Membership History
Assessments
Change Requests
Documents
Assistance
```

---

# 152. Nullable Foreign Keys

Use nullable foreign keys where the business relationship is genuinely optional.

Examples:

```text
change_requests.person_id

documents.person_id

documents.change_request_id

family_needs.person_id

assistance_records.need_id
```

Do not use nullable merely to avoid modeling decisions.

---

# 153. Metadata JSONB

JSONB is acceptable for:

```text
Workflow metadata
Audit old/new values
Change Request proposed payload
Technical metadata
```

JSONB should not replace core normalized registry relationships.

---

# 154. Status Columns

Status values should be represented consistently.

Possible implementation:

```text
VARCHAR
+
PHP Enum
```

This provides:

```text
Readable database values
Domain type safety
Migration flexibility
```

Examples:

```text
FamilyStatus
PersonLifeStatus
FormSubmissionStatus
ChangeRequestStatus
UserPersonLinkStatus
```

---

# 155. Laravel Enums

Recommended examples:

```php
enum ChangeRequestStatus: string
{
    case Draft = 'DRAFT';
    case Submitted = 'SUBMITTED';
    case UnderReview = 'UNDER_REVIEW';
    case ReturnedForClarification = 'RETURNED_FOR_CLARIFICATION';
    case Resubmitted = 'RESUBMITTED';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
    case Applied = 'APPLIED';
}
```

Enums must remain synchronized with database/business rules.

---

# 156. Change Request Application Failure

V1 database does not require a dedicated:

```text
APPLICATION_FAILED
```

state yet.

Initial strategy:

```text
If application fails:
transaction rolls back
request remains APPROVED
failure is logged/audited
authorized retry may occur
```

This avoids marking a request applied when canonical data was not committed.

If operational queues require explicit failure tracking, `APPLICATION_FAILED` may be introduced in Workflow V1.1 before implementation.

---

# 157. Notification Read State

Laravel notifications provide:

```text
read_at
```

A notification may be marked read without changing the underlying Change Request state.

---

# 158. Notification Delivery Failure

External delivery failure must not alter the business workflow.

Example:

```text
Change Request APPROVED
+
SMS failed
```

The request remains:

```text
APPROVED
```

Notification delivery is a secondary process.

---

# 159. Family User Deactivation

Deactivating:

```text
users.is_active
```

blocks authentication/access but does not delete:

```text
Person
Membership
Change Requests
Audit
```

---

# 160. User-Person Link End

When authorization ends:

```text
status = ENDED
ended_at = timestamp
end_reason = ...
```

Do not delete the historical link.

---

# 161. Household Head Access Review

When Household Head changes, the transaction/domain workflow must trigger or perform review of:

```text
Previous Head Family User access
New Head eligibility
Existing active User-Person links
```

The exact automated/manual process belongs in Workflow Architecture.

---

# 162. Death Access Review

When a Family User-linked Person becomes deceased:

```text
Family Portal access must be suspended/ended according to policy.
```

The Person and User history remain preserved.

---

# 163. Audit of Sensitive Access

At minimum, audit:

```text
Sensitive exports
Sensitive document downloads
National ID changes
Health changes
User-Person link activation
User-Person link termination
Change Request application
```

Whether every sensitive read is logged is a pending security/performance decision.

---

# 164. Reporting Architecture

Reports should query canonical tables.

Examples:

```text
families
persons
family_memberships
family_residences
person_health_conditions
person_disabilities
family_needs
assistance_records
```

Pending Change Requests should only appear in reports specifically designed for pending/proposed data.

---

# 165. Dashboard Counts

Operational dashboards may calculate:

```text
Total Active Families
Total Active Persons
Pending Form Reviews
Pending Change Requests
Returned Requests
Approved Awaiting Application
Active Needs
Recent Assistance
```

These are derived values.

---

# 166. Performance Strategy

Start with:

```text
Correct normalization
Essential indexes
Efficient Eloquent queries
Pagination
Eager loading
```

Only introduce:

```text
Materialized views
Caching
Denormalized counters
Search engines
```

after measured performance requirements justify them.

---

# 167. Query Pagination

Large lists must be paginated.

Examples:

```text
Persons
Families
Change Requests
Audit Logs
Workflow Events
Assistance
```

Avoid loading entire registry tables into application memory.

---

# 168. N+1 Prevention

Laravel queries should use appropriate:

```text
with()
load()
withCount()
```

where relationships are displayed.

This is particularly important for:

```text
Family Profile
Person Profile
Change Request Review
```

---

# 169. Database Naming Conventions

Use:

```text
snake_case
```

Table names:

```text
plural
```

Examples:

```text
family_memberships
change_requests
user_person_links
```

Foreign keys:

```text
singular_entity_id
```

Examples:

```text
family_id
person_id
submitted_by
```

---

# 170. Business Identifier Naming

Use:

```text
family_code
person_code
assessment_code
request_code
```

Do not use ambiguous:

```text
code
number
reference
```

when a domain-specific name is clearer.

---

# 171. Anti-Patterns

Do not create:

```text
child1_name
child2_name
child3_name

wife1_id
wife2_id

condition1
condition2

family_member_1

current_age

family_size editable column

persons.family_id as canonical membership

comma-separated health conditions

comma-separated disabilities

public document URLs

raw Family Portal CRUD over Person

automatic duplicate merging

unrestricted submitted_data mass assignment
```

---

# 172. Example — Person Moves Family

Before:

```text
PER-001825
→ active membership
→ FAM-000510
```

After transfer:

```text
PER-001825
→ old membership FAM-000510 = inactive
→ new membership FAM-000800 = active
```

Person Code remains:

```text
PER-001825
```

---

# 173. Example — Household Head Change

Before:

```text
FAM-000510

PER-001825
is_household_head = true

PER-001900
is_household_head = false
```

After:

```text
PER-001825
is_household_head = false

PER-001900
is_household_head = true
```

No Person is recreated.

---

# 174. Example — Family User

```text
users.id = 40

user_person_links:
user_id = 40
person_id = 1825
status = ACTIVE

family_memberships:
person_id = 1825
family_id = 510
is_active = TRUE
is_household_head = TRUE
```

Result:

```text
User 40
→ may receive approved Family Portal scope for FAM-000510
```

subject to permission policy.

---

# 175. Example — Contact Change Request

```text
CRQ-000101

family_id:
510

person_id:
1825

type:
CONTACT_UPDATE

submitted_data:
{
    "mobile": "0560000000"
}

status:
SUBMITTED
```

Canonical:

```text
persons.mobile
```

remains unchanged until the request is approved and applied.

---

# 176. Example — Applied Contact Request

Before:

```text
persons.mobile = 0590000000

CRQ-000101 = APPROVED
```

Application:

```text
UpdatePersonContactAction
```

After successful transaction:

```text
persons.mobile = 0560000000

CRQ-000101 = APPLIED
```

Workflow event:

```text
APPROVED → APPLIED
```

Audit:

```text
0590000000 → 0560000000
```

---

# 177. Example — Death Report

Submitted:

```text
CRQ-000200
type = DEATH_REPORT

person_id = 1825

submitted_data:
{
    "death_date": "2026-09-10"
}
```

Before application:

```text
persons.life_status = ALIVE
persons.death_date = NULL
```

After verified approval/application:

```text
persons.life_status = DECEASED
persons.death_date = 2026-09-10
```

Person remains in registry.

---

# 178. Example — Add Member

Request payload:

```json
{
    "full_name": "Example Person",
    "gender": "MALE",
    "birth_date": "2026-01-01",
    "relationship_type": "SON"
}
```

Before canonical creation:

```text
Duplicate Detection
```

If existing Person found:

```text
Reuse Person
```

Otherwise:

```text
Create Person
```

Then:

```text
Create Family Membership
```

inside a controlled transaction.

---

# 179. Database Invariants

```text
DB-INV-001
family_code is unique.

DB-INV-002
person_code is unique.

DB-INV-003
request_code is unique.

DB-INV-004
Person has at most one active Family membership in V1.

DB-INV-005
Family has at most one active Household Head.

DB-INV-006
Family has at most one current residence.

DB-INV-007
Person cannot relate to self.

DB-INV-008
Membership end date cannot precede start date.

DB-INV-009
Residence end date cannot precede start date.

DB-INV-010
Death Date cannot precede Birth Date.

DB-INV-011
Family membership is not stored canonically on persons.

DB-INV-012
Family User scope is not stored canonically as users.family_id.

DB-INV-013
Change Request submitted_data is not canonical registry data.

DB-INV-014
Workflow history is separate from current status.

DB-INV-015
Document upload does not imply verification.

DB-INV-016
Historical Person identity survives Family transfer.

DB-INV-017
Audit records are append-only for normal users.

DB-INV-018
Workflow events are append-only for normal users.
```

---

# 180. Approved Database Decisions

### DB-ADR-001

PostgreSQL is the recommended database.

### DB-ADR-002

Internal identifiers use BIGINT primary keys.

### DB-ADR-003

Business codes are separate from primary keys.

### DB-ADR-004

Family and Person identities are independent.

### DB-ADR-005

`family_memberships` is the canonical Family-Person relationship.

### DB-ADR-006

Historical memberships are preserved.

### DB-ADR-007

Partial unique indexes protect active membership and Household Head constraints.

### DB-ADR-008

Residence history is preserved.

### DB-ADR-009

National ID is stored as text.

### DB-ADR-010

Health and disability use normalized repeatable records.

### DB-ADR-011

Assessments are separate from canonical identity.

### DB-ADR-012

Needs and Assistance are separate tables.

### DB-ADR-013

Workflow events are stored separately from current status.

### DB-ADR-014

Workflow events use a polymorphic architecture.

### DB-ADR-015

Documents use private storage.

### DB-ADR-016

Core historical relationships avoid destructive cascades.

### DB-ADR-017

Derived statistics are not canonical columns.

### DB-ADR-018

Complex business operations use transactions.

### DB-ADR-019

`persons.death_date` is supported as an optional canonical field.

### DB-ADR-020

Authentication Users and registry Persons are separate entities.

### DB-ADR-021

Family Portal identity linking uses `user_person_links`.

### DB-ADR-022

Family Portal Family scope is derived from User-Person link and active Family Membership.

### DB-ADR-023

Family Users do not modify canonical registry tables directly.

### DB-ADR-024

Family-submitted changes are stored in `change_requests`.

### DB-ADR-025

Change Request proposed data uses JSONB with type-specific validation.

### DB-ADR-026

JSONB does not replace normalized canonical registry tables.

### DB-ADR-027

Change Request `APPROVED` and `APPLIED` are distinct states.

### DB-ADR-028

Change Request application uses controlled domain actions.

### DB-ADR-029

Change Request application is transactional and idempotent.

### DB-ADR-030

Change Requests participate in `workflow_events`.

### DB-ADR-031

Documents may be linked to Change Requests.

### DB-ADR-032

Laravel database notifications are the initial Family User notification implementation.

### DB-ADR-033

Spatie Laravel Permission is the recommended RBAC implementation.

### DB-ADR-034

Family Portal APIs must use explicit authorized serialization rather than unrestricted model serialization.

---

# 181. Pending Database Decisions

### PDB-001 — National ID Security

Finalize:

```text
Plain indexed National ID
Encrypted National ID
Search HMAC
Last-four representation
```

before production identity data is loaded.

---

### PDB-002 — National ID Uniqueness

Confirm whether National ID may be enforced as database unique after handling:

```text
Missing IDs
Exceptional cases
Historical duplicate cleanup
```

---

### PDB-003 — Final Reference Values

Approve production values for all lookup tables.

---

### PDB-004 — Pregnancy/Breastfeeding History

Determine whether dedicated assessment observation tables are required.

---

### PDB-005 — Marriage History

Determine whether Person Relationships + Marital Status are sufficient or whether a dedicated marriage/life-event table is required.

---

### PDB-006 — Family User Cardinality

Determine whether one User may link to multiple Persons and whether one Family may have multiple Family Users in V1.

---

### PDB-007 — Household Head Requirement

Confirm whether Family Portal access in V1 requires:

```text
is_household_head = TRUE
```

or supports approved representatives.

---

### PDB-008 — User Authentication Identifier

Finalize whether Family Users authenticate primarily by:

```text
Mobile
Email
Username
Combination
```

---

### PDB-009 — User-Person Link Uniqueness

Finalize whether:

```text
one active User
→ one active Person link
```

must be database-enforced.

---

### PDB-010 — Change Request Application Failure

Determine whether operational requirements justify adding:

```text
APPLICATION_FAILED
```

to Change Request status.

Initial recommendation:

```text
Remain APPROVED
+
record failure
+
allow controlled retry
```

---

### PDB-011 — Change Request Payload Encryption

Determine whether selected sensitive `submitted_data` payloads require field-level encryption beyond database/storage encryption.

---

### PDB-012 — Supporting Document Promotion

Determine whether a verified Change Request document can be promoted/relinked to canonical Person/Family document context.

---

### PDB-013 — Notification Channels

Database notification is approved as baseline.

Determine whether V1 additionally requires:

```text
SMS
Email
Other channel
```

---

### PDB-014 — Sensitive Read Audit

Determine whether all sensitive record views must be audited or only critical actions such as:

```text
Download
Export
Change
```

---

### PDB-015 — Assessment Snapshots

Determine the exact longitudinal reporting requirements before adding snapshot tables.

---

### PDB-016 — PostgreSQL Trigram Search

Introduce:

```text
pg_trgm
```

only if required by measured Arabic/name-search needs.

---

### PDB-017 — Backup Retention

Define:

```text
Backup frequency
Retention
Encryption
Off-site strategy
Restore testing
```

---

# 182. Implementation Preconditions

Do not begin production migrations until these documents are synchronized:

```text
01-PRODUCT.md
02-DATA-DICTIONARY.md
03-BUSINESS-RULES.md
04-DATABASE.md
05-WORKFLOWS.md
06-PERMISSIONS.md
07-ROADMAP.md
```

Critical unresolved decisions must either:

```text
Be resolved
```

or:

```text
Be explicitly deferred without blocking V1 implementation
```

---

# 183. Implementation Sequence

After documentation approval:

```text
Laravel Project Setup
        ↓
PostgreSQL Connection
        ↓
Authentication
        ↓
Reference Migrations
        ↓
Registry Core Migrations
        ↓
Family Membership
        ↓
Residence
        ↓
Health / Education / Employment
        ↓
Assessments
        ↓
Workflow Infrastructure
        ↓
Change Requests
        ↓
Documents
        ↓
Needs / Assistance
        ↓
Family User Identity Links
        ↓
RBAC
        ↓
Audit
        ↓
Staff Portal
        ↓
Family Portal
        ↓
Testing
```

---

# 184. Migration Development Rule

Before each migration:

```text
Check Data Dictionary
Check Business Rule
Check Database Architecture
```

Do not generate database fields merely because they appear convenient in a UI.

---

# 185. Schema Review Rule

Every schema change must answer:

```text
What business concept does this field represent?

Is it canonical or derived?

Is it current state or historical data?

Is it sensitive?

Does it require audit?

Does it require workflow?

Does it require Family Portal visibility rules?

Can it be normalized?

What happens when it changes?
```

---

# 186. Documentation Synchronization

The next documents must now align with Database V1.1.

`05-WORKFLOWS.md` must define:

```text
Family User Activation
User-Person Verification
Family Access Review
Change Request Workflow
Application Retry/Failure
Death Report
Birth Report
Add Member
Household Head Change
Membership Change
Residence Update
Contact Update
```

`06-PERMISSIONS.md` must define:

```text
FAMILY_USER
Family Scope
Self Scope
Sensitive Fields
Change Request permissions
Documents
Notifications
Approval
Application
User-Person link administration
```

`07-ROADMAP.md` must include:

```text
Family Portal
Change Request Engine
Family User Authentication
Self-Service Testing
Security Testing
```

---

# 187. Document Status

```text
Project: Famboook
Document: Database Architecture
Version: 1.1
Status: APPROVED
Date: 2026-09-22
Database: PostgreSQL
Backend: Laravel
```

---

# 188. Change Log

| Version | Date | Status | Description |
|---|---|---|---|
| 1.0 | 2026-09-22 | Approved | Initial normalized PostgreSQL database architecture including canonical Family Membership and workflow history |
| 1.1 | 2026-09-22 | Approved | Added death_date, Family Portal identity architecture, user_person_links, Change Requests, supporting document integration, workflow integration, notifications, Family scope resolution, application transactions, and self-service security architecture |

---

# 189. Next Step

Documentation synchronization:

```text
01-PRODUCT.md                 UPDATED — v1.1
02-DATA-DICTIONARY.md         UPDATED — v1.1
03-BUSINESS-RULES.md          UPDATED — v1.1
04-DATABASE.md                UPDATED — v1.1
        ↓
05-WORKFLOWS.md               NEXT
        ↓
06-PERMISSIONS.md
        ↓
07-ROADMAP.md
```

The next document must define exactly how the new architecture moves through states:

```text
Family User Account Activation

User-Person Verification

Change Request:
DRAFT
→ SUBMITTED
→ UNDER_REVIEW
→ RETURNED / RESUBMITTED
→ APPROVED / REJECTED
→ APPLIED

Application Failure / Retry

Birth Report

Death Report

Add Family Member

Membership Change

Household Head Change

Residence Update

Contact Update

Family Portal Access Re-Evaluation
```

before implementation begins.