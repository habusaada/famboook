# Famboook
## Database Architecture

**Document:** `04-DATABASE.md`  
**Version:** 1.1  
**Status:** Approved  
**Last Updated:** 2026-09-22  
**Project:** Famboook — Family Registry & Case Management System  
**Database:** PostgreSQL

---

# 1. Purpose

This document defines the logical and physical database architecture for Famboook V1.

It translates the approved:

- `01-PRODUCT.md`
- `02-DATA-DICTIONARY.md`
- `03-BUSINESS-RULES.md`
- `05-WORKFLOWS.md`

into a PostgreSQL-oriented relational model.

This document defines:

- Core tables.
- Primary keys.
- Foreign keys.
- Unique constraints.
- Indexes.
- Nullability.
- Historical-data strategy.
- Reference tables.
- Workflow history.
- Delete behavior.
- Sensitive-data considerations.
- Laravel implementation conventions.

Actual Laravel migrations must follow this document.

---

# 2. Database Technology

Famboook V1 uses:

```text
PostgreSQL
```

Recommended production version:

```text
PostgreSQL 16+
```

The application layer is expected to use:

```text
Laravel
```

with:

```text
Eloquent ORM
```

---

# 3. Core Database Principles

## DB-P01 — Internal Primary Keys

Core tables use internal primary keys.

Recommended:

```text
BIGINT
```

Laravel:

```php
$table->id();
```

Public identifiers such as:

```text
FAM-000510
PER-001825
```

are business identifiers, not primary keys.

---

## DB-P02 — Foreign Keys

Relationships must use database foreign keys wherever appropriate.

Example:

```text
family_memberships.family_id
    → families.id
```

---

## DB-P03 — Referential Integrity

Critical relationships must be enforced by PostgreSQL.

Application validation alone is not sufficient.

---

## DB-P04 — Historical Data

Historical information should normally be preserved rather than overwritten.

---

## DB-P05 — Sensitive Data

Sensitive fields require application-level authorization and, where approved, encryption.

---

## DB-P06 — No Production Data in Git

Production data, dumps, forms, documents, and exports must never be committed to the repository.

---

# 4. Core Architecture

The V1 relational model is organized into the following domains:

```text
IDENTITY
├── families
├── persons
├── family_memberships
└── person_relationships

LOCATION
└── family_residences

HEALTH
├── person_health_profiles
├── person_health_conditions
└── person_disabilities

SOCIOECONOMIC
├── person_education
└── person_employment

ASSESSMENT & WORKFLOW
├── assessments
├── form_submissions
└── workflow_events

CASE MANAGEMENT
├── family_needs
├── assistance_records
├── person_notes
└── case_notes

DOCUMENTS
└── documents

SECURITY
├── users
├── roles
├── permissions
└── audit_logs

REFERENCE DATA
└── lookup tables
```

---

# 5. Important Architecture Decision — Family Membership

Data Dictionary V1 originally represented current family membership using:

```text
persons.family_id
```

Database V1 replaces that as the canonical membership model with:

```text
family_memberships
```

Therefore:

```text
persons
```

represents human identity.

And:

```text
family_memberships
```

represents household membership.

This allows:

```text
Person
  ↓
Family A
2025 → 2026
  ↓
Family B
2026 → Present
```

without creating another Person record.

`persons.family_id` SHOULD NOT be the canonical family relationship.

---

# 6. families

Purpose:

Represents a household/family identity.

```text
families
```

| Column | Type | Null | Constraint |
|---|---|---:|---|
| id | BIGINT | No | PK |
| family_code | VARCHAR(20) | No | UNIQUE |
| status | VARCHAR(30) | No | |
| registration_date | DATE | No | |
| registration_source | VARCHAR(30) | No | |
| paper_form_no | VARCHAR(50) | Yes | |
| notes | TEXT | Yes | |
| created_by | BIGINT | Yes | FK users |
| updated_by | BIGINT | Yes | FK users |
| created_at | TIMESTAMP | No | |
| updated_at | TIMESTAMP | No | |
| deleted_at | TIMESTAMP | Yes | Soft delete |

### Indexes

```text
UNIQUE family_code

INDEX status
INDEX registration_date
INDEX created_at
```

### Example

```text
id: 510
family_code: FAM-000510
status: APPROVED
```

---

# 7. persons

Purpose:

Represents one human identity.

```text
persons
```

| Column | Type | Null | Constraint |
|---|---|---:|---|
| id | BIGINT | No | PK |
| person_code | VARCHAR(20) | No | UNIQUE |
| full_name | VARCHAR(200) | No | |
| national_id | VARCHAR(20) | Yes | See ID rules |
| gender | VARCHAR(20) | No | |
| birth_date | DATE | Yes | |
| marital_status_id | BIGINT | Yes | FK |
| life_status | VARCHAR(20) | No | |
| mobile | VARCHAR(20) | Yes | |
| alternate_mobile | VARCHAR(20) | Yes | |
| notes | TEXT | Yes | |
| is_active | BOOLEAN | No | DEFAULT TRUE |
| created_by | BIGINT | Yes | FK users |
| updated_by | BIGINT | Yes | FK users |
| created_at | TIMESTAMP | No | |
| updated_at | TIMESTAMP | No | |
| deleted_at | TIMESTAMP | Yes | |

### Indexes

```text
UNIQUE person_code

INDEX national_id
INDEX full_name
INDEX mobile
INDEX birth_date
INDEX life_status
```

National ID uniqueness requires special handling because:

- IDs may be missing.
- Legacy duplicates may require review.
- Duplicate-resolution workflows must remain possible.

The final production constraint will be decided after confirming the official National ID rules.

---

# 8. family_memberships

Purpose:

Represents the relationship between a Person and a Family over time.

This is a core Famboook table.

```text
family_memberships
```

| Column | Type | Null | Constraint |
|---|---|---:|---|
| id | BIGINT | No | PK |
| family_id | BIGINT | No | FK families |
| person_id | BIGINT | No | FK persons |
| relationship_type_id | BIGINT | No | FK |
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

### Relationships

```text
families 1 ─── N family_memberships
persons  1 ─── N family_memberships
```

### Important Rule

A Person should normally have only one active primary family membership.

Recommended PostgreSQL partial unique index:

```sql
CREATE UNIQUE INDEX uq_person_active_family_membership
ON family_memberships (person_id)
WHERE is_active = TRUE;
```

---

# 9. One Household Head Per Family

Only one active membership should normally be marked:

```text
is_household_head = true
```

Recommended PostgreSQL partial unique index:

```sql
CREATE UNIQUE INDEX uq_family_active_household_head
ON family_memberships (family_id)
WHERE is_active = TRUE
AND is_household_head = TRUE;
```

This enforces the business rule at database level.

---

# 10. Membership Date Integrity

Recommended constraint:

```sql
CHECK (
    ended_at IS NULL
    OR started_at IS NULL
    OR ended_at >= started_at
)
```

When membership ends:

```text
is_active = false
ended_at = <date>
```

Historical membership remains available.

---

# 11. relationship_types

Reference table:

```text
relationship_types
```

| Column | Type |
|---|---|
| id | BIGINT PK |
| code | VARCHAR(20) UNIQUE |
| name_ar | VARCHAR(100) |
| name_en | VARCHAR(100) |
| is_active | BOOLEAN |
| sort_order | INTEGER |
| created_at | TIMESTAMP |
| updated_at | TIMESTAMP |

Examples:

```text
REL-01 SELF
REL-02 SPOUSE
REL-03 SON
REL-04 DAUGHTER
REL-05 FATHER
REL-06 MOTHER
...
```

---

# 12. person_relationships

Purpose:

Represents direct relationships between Persons.

```text
person_relationships
```

| Column | Type | Null |
|---|---|---:|
| id | BIGINT | No |
| person_id | BIGINT | No |
| related_person_id | BIGINT | No |
| relationship_type_id | BIGINT | No |
| start_date | DATE | Yes |
| end_date | DATE | Yes |
| status | VARCHAR(20) | No |
| notes | TEXT | Yes |
| created_at | TIMESTAMP | No |
| updated_at | TIMESTAMP | No |

### Foreign Keys

```text
person_id → persons.id
related_person_id → persons.id
relationship_type_id → relationship_types.id
```

### Constraint

```sql
CHECK (person_id <> related_person_id)
```

### Recommended Indexes

```text
INDEX person_id
INDEX related_person_id
```

---

# 13. marital_statuses

```text
marital_statuses
```

| Column | Type |
|---|---|
| id | BIGINT PK |
| code | VARCHAR(20) UNIQUE |
| name_ar | VARCHAR(100) |
| name_en | VARCHAR(100) |
| is_active | BOOLEAN |
| sort_order | INTEGER |

---

# 14. family_residences

Purpose:

Stores residence and displacement history.

```text
family_residences
```

| Column | Type | Null |
|---|---|---:|
| id | BIGINT | No |
| family_id | BIGINT | No |
| governorate_id | BIGINT | Yes |
| locality_id | BIGINT | Yes |
| neighborhood | VARCHAR(150) | Yes |
| address_details | TEXT | Yes |
| housing_type_id | BIGINT | Yes |
| tenure_type_id | BIGINT | Yes |
| housing_condition_id | BIGINT | Yes |
| is_displaced | BOOLEAN | No |
| displacement_location | TEXT | Yes |
| displacement_date | DATE | Yes |
| displacement_reason | TEXT | Yes |
| is_current | BOOLEAN | No |
| from_date | DATE | Yes |
| to_date | DATE | Yes |
| notes | TEXT | Yes |
| created_at | TIMESTAMP | No |
| updated_at | TIMESTAMP | No |

### Foreign Key

```text
family_id → families.id
```

### Current Residence Constraint

Recommended:

```sql
CREATE UNIQUE INDEX uq_family_current_residence
ON family_residences (family_id)
WHERE is_current = TRUE;
```

### Date Constraint

```sql
CHECK (
    to_date IS NULL
    OR from_date IS NULL
    OR to_date >= from_date
)
```

---

# 15. Geographic Reference Tables

Recommended:

```text
governorates
localities
```

Relationship:

```text
governorates
      │
      └── localities
```

### governorates

```text
id
code
name_ar
name_en
is_active
```

### localities

```text
id
governorate_id
code
name_ar
name_en
is_active
```

Do not store every geographic value as uncontrolled text when reference data is available.

---

# 16. Housing Reference Tables

```text
housing_types
tenure_types
housing_condition_types
```

Standard structure:

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

# 17. person_health_profiles

Purpose:

Current summary of Person health information.

```text
person_health_profiles
```

| Column | Type | Null |
|---|---|---:|
| id | BIGINT | No |
| person_id | BIGINT | No |
| has_health_condition | BOOLEAN | No |
| has_chronic_disease | BOOLEAN | No |
| has_disability | BOOLEAN | No |
| is_pregnant | BOOLEAN | No |
| is_breastfeeding | BOOLEAN | No |
| requires_follow_up | BOOLEAN | No |
| notes | TEXT | Yes |
| updated_at | TIMESTAMP | No |
| created_at | TIMESTAMP | No |

### Constraint

```text
UNIQUE person_id
```

Relationship:

```text
persons 1 ─── 0..1 person_health_profiles
```

---

# 18. health_condition_types

```text
health_condition_types
```

Standard reference structure:

```text
id
code
name_ar
name_en
is_chronic
is_active
sort_order
```

---

# 19. person_health_conditions

```text
person_health_conditions
```

| Column | Type | Null |
|---|---|---:|
| id | BIGINT | No |
| person_id | BIGINT | No |
| health_condition_type_id | BIGINT | No |
| details | TEXT | Yes |
| severity | VARCHAR(20) | Yes |
| requires_treatment | BOOLEAN | Yes |
| requires_medication | BOOLEAN | Yes |
| notes | TEXT | Yes |
| created_at | TIMESTAMP | No |
| updated_at | TIMESTAMP | No |

### Indexes

```text
INDEX person_id
INDEX health_condition_type_id
```

---

# 20. disability_types

```text
disability_types
```

Standard reference structure:

```text
id
code
name_ar
name_en
is_active
sort_order
```

---

# 21. person_disabilities

```text
person_disabilities
```

| Column | Type | Null |
|---|---|---:|
| id | BIGINT | No |
| person_id | BIGINT | No |
| disability_type_id | BIGINT | No |
| severity | VARCHAR(20) | Yes |
| requires_assistance | BOOLEAN | Yes |
| uses_assistive_device | BOOLEAN | Yes |
| assistive_device | VARCHAR(200) | Yes |
| notes | TEXT | Yes |
| created_at | TIMESTAMP | No |
| updated_at | TIMESTAMP | No |

### Indexes

```text
INDEX person_id
INDEX disability_type_id
```

---

# 22. person_education

Purpose:

Stores education history.

```text
person_education
```

| Column | Type | Null |
|---|---|---:|
| id | BIGINT | No |
| person_id | BIGINT | No |
| is_enrolled | BOOLEAN | Yes |
| education_level_id | BIGINT | Yes |
| current_grade | VARCHAR(50) | Yes |
| institution_name | VARCHAR(200) | Yes |
| specialization | VARCHAR(200) | Yes |
| education_status_id | BIGINT | Yes |
| from_date | DATE | Yes |
| to_date | DATE | Yes |
| is_current | BOOLEAN | No |
| notes | TEXT | Yes |
| created_at | TIMESTAMP | No |
| updated_at | TIMESTAMP | No |

### Index

```text
INDEX person_id
```

---

# 23. Education Reference Tables

```text
education_levels
education_statuses
```

Use the standard reference-table structure.

---

# 24. person_employment

Purpose:

Stores employment history.

```text
person_employment
```

| Column | Type | Null |
|---|---|---:|
| id | BIGINT | No |
| person_id | BIGINT | No |
| employment_status_id | BIGINT | Yes |
| occupation | VARCHAR(200) | Yes |
| employer | VARCHAR(200) | Yes |
| employment_sector_id | BIGINT | Yes |
| has_income | BOOLEAN | Yes |
| income_amount | NUMERIC(12,2) | Yes |
| income_frequency | VARCHAR(30) | Yes |
| from_date | DATE | Yes |
| to_date | DATE | Yes |
| is_current | BOOLEAN | No |
| notes | TEXT | Yes |
| created_at | TIMESTAMP | No |
| updated_at | TIMESTAMP | No |

---

# 25. Employment Reference Tables

```text
employment_statuses
employment_sectors
```

Standard reference-table structure applies.

---

# 26. assessments

Purpose:

Represents a data collection, verification, or follow-up event.

```text
assessments
```

| Column | Type | Null |
|---|---|---:|
| id | BIGINT | No |
| assessment_code | VARCHAR(30) | No |
| family_id | BIGINT | No |
| assessment_type_id | BIGINT | No |
| assessment_date | DATE | No |
| collector_id | BIGINT | No |
| reviewer_id | BIGINT | Yes |
| status | VARCHAR(30) | No |
| location | VARCHAR(255) | Yes |
| source | VARCHAR(30) | No |
| notes | TEXT | Yes |
| submitted_at | TIMESTAMP | Yes |
| verified_at | TIMESTAMP | Yes |
| created_at | TIMESTAMP | No |
| updated_at | TIMESTAMP | No |

### Constraints

```text
UNIQUE assessment_code
```

### Indexes

```text
INDEX family_id
INDEX assessment_type_id
INDEX assessment_date
INDEX status
```

---

# 27. assessment_types

Reference table:

```text
assessment_types
```

Examples:

```text
INITIAL_REGISTRATION
VERIFICATION
FOLLOW_UP
NEEDS_ASSESSMENT
EMERGENCY_UPDATE
```

---

# 28. form_submissions

Purpose:

Represents the original source form and its data-entry/review lifecycle.

```text
form_submissions
```

| Column | Type | Null |
|---|---|---:|
| id | BIGINT | No |
| family_id | BIGINT | No |
| assessment_id | BIGINT | Yes |
| form_type_id | BIGINT | No |
| paper_form_no | VARCHAR(50) | Yes |
| page_count | SMALLINT | Yes |
| source_file | VARCHAR(500) | Yes |
| entered_by | BIGINT | No |
| entered_at | TIMESTAMP | No |
| reviewed_by | BIGINT | Yes |
| reviewed_at | TIMESTAMP | Yes |
| status | VARCHAR(30) | No |
| return_reason | TEXT | Yes |
| created_at | TIMESTAMP | No |
| updated_at | TIMESTAMP | No |

### Indexes

```text
INDEX family_id
INDEX assessment_id
INDEX status
INDEX paper_form_no
```

### Approved V1 Workflow States

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

`status` represents the current workflow state.

Historical transitions are stored separately in:

```text
workflow_events
```

---

# 29. workflow_events

Purpose:

Stores the immutable history of workflow state transitions for workflow-controlled entities.

```text
workflow_events
```

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

### Purpose

Examples of workflow events:

```text
DRAFT → DATA_ENTRY_COMPLETED
DATA_ENTRY_COMPLETED → UNDER_REVIEW
UNDER_REVIEW → RETURNED_FOR_CORRECTION
RETURNED_FOR_CORRECTION → CORRECTED
CORRECTED → UNDER_REVIEW
UNDER_REVIEW → VERIFIED
VERIFIED → APPROVED
```

Need workflow events may include:

```text
IDENTIFIED → VERIFIED
VERIFIED → ACTIVE
ACTIVE → PARTIALLY_MET
PARTIALLY_MET → MET
MET → CLOSED
```

### Polymorphic Relationship

`workflow_events` may track different workflow-controlled entities.

```text
workflow_events
      │
      ├── FormSubmission
      ├── Assessment
      └── FamilyNeed
```

Laravel conceptual relationship:

```php
public function workflowable()
{
    return $this->morphTo();
}
```

Workflow-controlled models may expose:

```php
public function workflowEvents()
{
    return $this->morphMany(
        WorkflowEvent::class,
        'workflowable'
    );
}
```

### Foreign Key

```text
performed_by → users.id
```

`performed_by` may be nullable for system-generated events where no direct user actor exists.

### Indexes

Recommended:

```text
INDEX (workflowable_type, workflowable_id)
INDEX performed_by
INDEX created_at
INDEX to_status
INDEX action
```

The primary retrieval pattern is:

```text
workflowable_type
+
workflowable_id
+
created_at
```

to construct the chronological workflow timeline of a record.

### Immutability

Workflow events are historical records.

Normal application users MUST NOT:

```text
UPDATE workflow_events
DELETE workflow_events
```

A new workflow transition creates a new event.

Existing workflow history must not be overwritten.

### Example

```text
workflowable_type: FormSubmission
workflowable_id: 510

from_status: UNDER_REVIEW
to_status: VERIFIED

action: VERIFY

performed_by: 25
created_at: 2026-09-22 14:00:00
```

### Relationship to Current Status

`workflow_events` does not replace the current status stored on the business entity.

For example:

```text
form_submissions.status
```

may contain:

```text
APPROVED
```

while:

```text
workflow_events
```

stores the history that led to that state:

```text
DRAFT
→ DATA_ENTRY_COMPLETED
→ UNDER_REVIEW
→ RETURNED_FOR_CORRECTION
→ CORRECTED
→ UNDER_REVIEW
→ VERIFIED
→ APPROVED
```

This provides efficient access to the current state while preserving lifecycle history.

### Relationship to Audit Logs

`workflow_events` and `audit_logs` serve different purposes.

```text
workflow_events
= lifecycle / state transition history

audit_logs
= data modification and system activity history
```

Example workflow event:

```text
UNDER_REVIEW
→
VERIFIED
```

Example audit event:

```text
national_id

804000001
→
804000011
```

One business operation may create both:

```text
workflow_event
+
audit_log
```

### Delete Strategy

Workflow events are historical records.

Use:

```text
RESTRICT / NO ACTION
```

where appropriate.

Do not cascade-delete workflow history through normal application operations.

### Soft Delete

`workflow_events` SHOULD NOT use soft deletes.

The table is append-only for normal application operations.

### Security

Workflow history may reveal:

```text
User actions
Correction reasons
Review decisions
Operational metadata
```

Access must therefore follow the authorization rules defined in:

```text
06-PERMISSIONS.md
```

---

# 30. form_types

Reference table:

```text
form_types
```

Allows future forms to be added without changing the core database.

---

# 31. documents

Purpose:

Stores document metadata.

Actual binary files are stored in private file storage.

```text
documents
```

| Column | Type | Null |
|---|---|---:|
| id | BIGINT | No |
| family_id | BIGINT | Yes |
| person_id | BIGINT | Yes |
| document_type_id | BIGINT | No |
| document_number | VARCHAR(100) | Yes |
| is_available | BOOLEAN | No |
| is_verified | BOOLEAN | No |
| issue_date | DATE | Yes |
| expiry_date | DATE | Yes |
| file_path | VARCHAR(500) | Yes |
| verified_by | BIGINT | Yes |
| verified_at | TIMESTAMP | Yes |
| notes | TEXT | Yes |
| created_at | TIMESTAMP | No |
| updated_at | TIMESTAMP | No |

At least one owner should normally exist:

```text
family_id
OR
person_id
```

Recommended constraint:

```sql
CHECK (
    family_id IS NOT NULL
    OR person_id IS NOT NULL
)
```

---

# 32. document_types

Reference table:

```text
document_types
```

Examples:

```text
NATIONAL_ID
BIRTH_CERTIFICATE
MARRIAGE_CERTIFICATE
DEATH_CERTIFICATE
MEDICAL_REPORT
DISABILITY_REPORT
OTHER
```

---

# 33. family_needs

Purpose:

Stores identified needs.

```text
family_needs
```

| Column | Type | Null |
|---|---|---:|
| id | BIGINT | No |
| family_id | BIGINT | No |
| person_id | BIGINT | Yes |
| assessment_id | BIGINT | Yes |
| need_type_id | BIGINT | No |
| priority | VARCHAR(20) | Yes |
| description | TEXT | Yes |
| status | VARCHAR(30) | No |
| identified_at | DATE | No |
| is_verified | BOOLEAN | No |
| verified_by | BIGINT | Yes |
| verified_at | TIMESTAMP | Yes |
| created_at | TIMESTAMP | No |
| updated_at | TIMESTAMP | No |

### Indexes

```text
INDEX family_id
INDEX person_id
INDEX need_type_id
INDEX status
INDEX priority
```

### Approved Lifecycle

```text
IDENTIFIED
    ↓
VERIFIED
    ↓
ACTIVE
    ↓
PARTIALLY_MET
    ↓
MET
    ↓
CLOSED
```

The lifecycle may branch according to the workflow rules defined in:

```text
05-WORKFLOWS.md
```

---

# 34. need_types

Reference table:

```text
need_types
```

Examples:

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

# 35. assistance_records

Purpose:

Stores actual assistance events.

```text
assistance_records
```

| Column | Type | Null |
|---|---|---:|
| id | BIGINT | No |
| family_id | BIGINT | No |
| person_id | BIGINT | Yes |
| need_id | BIGINT | Yes |
| assistance_type_id | BIGINT | No |
| provider_name | VARCHAR(200) | Yes |
| description | TEXT | Yes |
| quantity | NUMERIC(12,2) | Yes |
| unit | VARCHAR(50) | Yes |
| estimated_value | NUMERIC(12,2) | Yes |
| currency | VARCHAR(10) | Yes |
| received_at | DATE | Yes |
| distribution_reference | VARCHAR(100) | Yes |
| notes | TEXT | Yes |
| created_at | TIMESTAMP | No |
| updated_at | TIMESTAMP | No |

The optional:

```text
need_id
```

allows assistance to be associated with a documented Need.

Recording Assistance MUST NOT automatically close a Need.

Need status changes must follow the approved Need workflow.

---

# 36. assistance_types

Reference table:

```text
assistance_types
```

Separate from:

```text
need_types
```

because Need and Assistance are different business concepts.

---

# 37. person_notes

```text
person_notes
```

| Column | Type | Null |
|---|---|---:|
| id | BIGINT | No |
| person_id | BIGINT | No |
| note_type_id | BIGINT | Yes |
| note | TEXT | No |
| is_confidential | BOOLEAN | No |
| created_by | BIGINT | No |
| created_at | TIMESTAMP | No |
| updated_at | TIMESTAMP | No |

Person notes are append-oriented.

---

# 38. case_notes

```text
case_notes
```

| Column | Type | Null |
|---|---|---:|
| id | BIGINT | No |
| family_id | BIGINT | No |
| assessment_id | BIGINT | Yes |
| person_id | BIGINT | Yes |
| note_type_id | BIGINT | Yes |
| note | TEXT | No |
| is_confidential | BOOLEAN | No |
| created_by | BIGINT | No |
| created_at | TIMESTAMP | No |
| updated_at | TIMESTAMP | No |

Case notes are append-oriented.

Hard deletion should not be available to normal users.

---

# 39. note_types

Reference table:

```text
note_types
```

Allows categorization such as:

```text
GENERAL
FOLLOW_UP
VERIFICATION
PROTECTION
HEALTH
CORRECTION
OTHER
```

---

# 40. users

Laravel authentication table.

Core design:

```text
users
```

| Column | Type | Null |
|---|---|---:|
| id | BIGINT | No |
| name | VARCHAR(200) | No |
| email | VARCHAR(255) | Yes |
| mobile | VARCHAR(20) | Yes |
| password | VARCHAR(255) | No |
| is_active | BOOLEAN | No |
| last_login_at | TIMESTAMP | Yes |
| remember_token | VARCHAR(100) | Yes |
| created_at | TIMESTAMP | No |
| updated_at | TIMESTAMP | No |

At least one supported authentication identifier should be unique.

Final authentication policy is defined during implementation.

---

# 41. Roles and Permissions

Recommended Laravel package:

```text
spatie/laravel-permission
```

Expected tables include:

```text
roles
permissions
model_has_roles
model_has_permissions
role_has_permissions
```

Detailed authorization design belongs in:

```text
06-PERMISSIONS.md
```

---

# 42. audit_logs

Recommended package:

```text
spatie/laravel-activitylog
```

or an equivalent audit implementation.

Logical fields:

```text
id
actor_id
event
entity_type
entity_id
old_values JSONB
new_values JSONB
ip_address
user_agent
created_at
```

PostgreSQL:

```text
JSONB
```

is preferred for structured before/after values.

Audit records should be append-only for normal application users.

---

# 43. Reference Table Standard

Most lookup tables should follow:

```text
id BIGINT PK
code VARCHAR UNIQUE
name_ar VARCHAR
name_en VARCHAR
description TEXT NULL
is_active BOOLEAN DEFAULT TRUE
sort_order INTEGER DEFAULT 0
created_at TIMESTAMP
updated_at TIMESTAMP
```

This applies where appropriate to:

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
document_types
need_types
assistance_types
note_types
assessment_types
form_types
```

---

# 44. Foreign Key Delete Strategy

Deletion behavior must be deliberate.

## Core Identity

```text
families
persons
```

Use:

```text
RESTRICT / NO ACTION
```

for historical dependent records.

Do NOT cascade-delete an entire person's history.

## Reference Tables

Use:

```text
RESTRICT
```

when values are already referenced.

Deactivate reference values instead of deleting them.

## Historical Records

For:

```text
assessments
form_submissions
workflow_events
assistance_records
case_notes
documents
audit_logs
```

avoid destructive cascade behavior.

---

# 45. Soft Deletes

Recommended for:

```text
families
persons
```

Potentially:

```text
documents
```

depending on retention requirements.

Not recommended for:

```text
workflow_events
audit_logs
```

because these represent append-only historical records.

Soft deletion must not replace proper business statuses.

---

# 46. Timestamps

Application timestamps should use timezone-aware handling.

Laravel/PostgreSQL implementation should consistently store application timestamps in a defined timezone strategy, preferably UTC internally.

Display conversion belongs to the application layer.

---

# 47. Monetary Fields

Use:

```text
NUMERIC
```

not floating-point types for money.

Example:

```text
NUMERIC(12,2)
```

Currency must be explicit when monetary values can use different currencies.

---

# 48. Phone Numbers

Phone numbers must be stored as text.

Use:

```text
VARCHAR
```

not numeric types.

Reason:

```text
+
leading zero
country code
formatting
```

may be significant.

Canonical normalization rules will be implemented at application level.

---

# 49. National ID Storage

National IDs must use:

```text
VARCHAR
```

not numeric types.

Index:

```text
INDEX national_id
```

A final uniqueness strategy will be approved after confirming identity rules and legacy-data behavior.

---

# 50. Sensitive Field Encryption

Candidate fields for application-level encryption include:

```text
national_id
sensitive document identifiers
selected confidential information
```

However, encryption strategy must consider search requirements.

For searchable sensitive fields, a pattern such as:

```text
encrypted value
+
search hash
```

may be evaluated.

Example conceptual design:

```text
national_id_encrypted
national_id_hash
```

This decision must be finalized during the security implementation phase before production data is loaded.

---

# 51. Search Strategy

Initial indexed search fields:

```text
families.family_code
persons.person_code
persons.national_id
persons.full_name
persons.mobile
```

PostgreSQL search capabilities may later be extended with:

```text
pg_trgm
```

for fuzzy name search.

Example future extension:

```sql
CREATE EXTENSION IF NOT EXISTS pg_trgm;
```

Then:

```text
GIN / GiST trigram index
```

may support duplicate detection and name search.

This optimization should be introduced only when required.

---

# 52. Duplicate Detection Data

Duplicate detection logic primarily belongs in the application layer.

The database provides indexed canonical fields such as:

```text
national_id
full_name
birth_date
gender
mobile
```

The system MUST NOT enforce automatic Person merging at database level.

---

# 53. Derived Data

Do NOT create canonical columns such as:

```text
age
family_size
children_count
male_count
female_count
disabled_count
chronic_disease_count
```

These should be queried/calculated.

If performance later requires cached aggregates, they must remain derived data.

---

# 54. Assessment Snapshot Consideration

Some Person and Family attributes are time-sensitive.

Examples:

```text
pregnancy
breastfeeding
employment
residence
needs
displacement
```

V1 maintains current domain records plus assessment history.

Where historical reporting requires the exact state at assessment time, snapshot tables or assessment-linked observations may be introduced.

This must be considered before building advanced longitudinal reporting.

---

# 55. Database Indexing Strategy

Indexes should support actual query patterns.

Priority indexes include:

```text
family_code
person_code
national_id
full_name
mobile

family_memberships.family_id
family_memberships.person_id

family_residences.family_id

assessments.family_id
assessments.status
assessments.assessment_date

form_submissions.family_id
form_submissions.status

workflow_events.workflowable_type + workflowable_id

family_needs.family_id
family_needs.status

assistance_records.family_id
assistance_records.received_at
```

Avoid indexing every column without demonstrated need.

---

# 56. Composite Indexes

Potential composite indexes include:

```text
family_memberships
(family_id, is_active)

assessments
(family_id, assessment_date)

form_submissions
(family_id, status)

workflow_events
(workflowable_type, workflowable_id)

family_needs
(family_id, status)

assistance_records
(family_id, received_at)
```

Final indexes should be verified using production query patterns.

---

# 57. Unique Constraints

V1 requires at minimum:

```text
families.family_code
persons.person_code
relationship_types.code
marital_statuses.code
assessment_types.code
form_types.code
```

and other reference-table codes.

Partial unique indexes enforce:

```text
One active household membership per person
One active household head per family
One current residence per family
```

---

# 58. Check Constraints

Recommended PostgreSQL checks include:

### Person Relationship

```sql
CHECK (person_id <> related_person_id)
```

### Membership Dates

```sql
CHECK (
    ended_at IS NULL
    OR started_at IS NULL
    OR ended_at >= started_at
)
```

### Residence Dates

```sql
CHECK (
    to_date IS NULL
    OR from_date IS NULL
    OR to_date >= from_date
)
```

### Document Owner

```sql
CHECK (
    family_id IS NOT NULL
    OR person_id IS NOT NULL
)
```

Additional constraints should be introduced where they improve integrity without preventing valid operational exceptions.

---

# 59. Null vs Unknown

Database `NULL` generally means:

```text
Not recorded / unknown
```

It should not automatically mean:

```text
No
```

For example:

```text
has_income = NULL
```

may mean:

```text
Not assessed
```

while:

```text
has_income = FALSE
```

means:

```text
Assessed and no income reported
```

This distinction must be preserved where operationally important.

---

# 60. ERD — Core

```text
┌─────────────────┐
│    families     │
└────────┬────────┘
         │ 1
         │
         │ N
┌────────▼──────────────┐
│ family_memberships   │
└────────┬──────────────┘
         │ N
         │
         │ 1
┌────────▼────────┐
│     persons     │
└────────┬────────┘
         │
         ├────────────── person_relationships
         │
         ├────────────── person_health_profiles
         │
         ├────────────── person_health_conditions
         │
         ├────────────── person_disabilities
         │
         ├────────────── person_education
         │
         ├────────────── person_employment
         │
         ├────────────── person_notes
         │
         └────────────── documents


families
   │
   ├────────────── family_residences
   │
   ├────────────── assessments
   │                    │
   │                    └──── form_submissions
   │                              │
   │                              └──── workflow_events
   │
   ├────────────── family_needs
   │                    │
   │                    ├──── workflow_events
   │                    └──── assistance_records
   │
   ├────────────── case_notes
   └────────────── documents
```

Note:

`workflow_events` uses a polymorphic relationship and may therefore reference multiple workflow-controlled entities rather than having a direct foreign key to only one table.

---

# 61. ERD — Identity Model

```text
                ┌───────────────┐
                │   families    │
                └───────┬───────┘
                        │
                        │ 1:N
                        ▼
              ┌─────────────────────┐
              │ family_memberships  │
              └──────────┬──────────┘
                         │
                         │ N:1
                         ▼
                   ┌───────────┐
                   │  persons  │
                   └─────┬─────┘
                         │
                         │ 1:N
                         ▼
              ┌──────────────────────┐
              │ person_relationships │
              └──────────────────────┘
```

This model allows Person identity to survive household changes.

---

# 62. Example — Person Moves Household

Initial state:

```text
PER-000100
    ↓
FAM-000020
2025-01-01 → 2026-06-30
```

Membership:

```text
family_id: 20
person_id: 100
started_at: 2025-01-01
ended_at: 2026-06-30
is_active: false
```

New membership:

```text
family_id: 85
person_id: 100
started_at: 2026-07-01
ended_at: NULL
is_active: true
```

The Person remains:

```text
PER-000100
```

No duplicate Person is created.

---

# 63. Example — Household Head Change

Before:

```text
FAM-000510

PER-001001
is_household_head = true

PER-001002
is_household_head = false
```

After:

```text
PER-001001
is_household_head = false

PER-001002
is_household_head = true
```

Membership history and audit logs preserve the change.

The operation should execute transactionally to prevent a Family from temporarily ending in an inconsistent household-head state.

---

# 64. Laravel Migration Order

Recommended migration sequence:

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

16 documents

17 family_needs
18 assistance_records

19 person_notes
20 case_notes

21 roles / permissions
22 audit infrastructure

23 indexes / specialized constraints
```

Exact Laravel timestamps will determine actual execution order.

---

# 65. Laravel Model Relationships

Expected conceptual relationships:

```text
Family
  hasMany Memberships
  hasMany Persons through Memberships
  hasMany Residences
  hasMany Assessments
  hasMany Needs
  hasMany AssistanceRecords
  hasMany CaseNotes
  hasMany Documents
```

```text
Person
  hasMany Memberships
  hasMany Relationships
  hasOne HealthProfile
  hasMany HealthConditions
  hasMany Disabilities
  hasMany EducationRecords
  hasMany EmploymentRecords
  hasMany Notes
  hasMany Documents
```

```text
Assessment
  belongsTo Family
  belongsTo Collector
  belongsTo Reviewer
  hasMany FormSubmissions
  morphMany WorkflowEvents
```

```text
FormSubmission
  belongsTo Family
  belongsTo Assessment
  belongsTo FormType
  morphMany WorkflowEvents
```

```text
FamilyNeed
  belongsTo Family
  belongsTo Person optionally
  belongsTo Assessment optionally
  hasMany AssistanceRecords
  morphMany WorkflowEvents
```

```text
WorkflowEvent
  morphTo Workflowable
  belongsTo User through performed_by
```

---

# 66. Transaction Boundaries

Operations involving multiple dependent records should use database transactions.

Examples:

```text
Create Family
+
Create Household Head
+
Create Initial Membership
```

must either all succeed or all fail.

Likewise:

```text
Change Household Head
```

should be atomic.

And:

```text
Move Person Between Families
```

should be atomic.

Workflow transitions that update the entity state and create a corresponding `workflow_events` record should also execute transactionally.

Example:

```text
Update form_submissions.status
+
Insert workflow_events
```

must either both succeed or both fail.

---

# 67. Concurrency

Critical updates should guard against race conditions.

Examples:

```text
Two users assigning different household heads simultaneously.
```

or:

```text
Two users moving the same Person between families.
```

or:

```text
Two reviewers attempting to verify/return the same submission.
```

Database constraints plus application transactions should protect these operations.

Optimistic concurrency using:

```text
updated_at
```

or an explicit:

```text
version
```

field may be introduced for critical records.

---

# 68. Data Seeding

Seeders should initially provide:

```text
relationship_types
marital_statuses
housing_types
tenure_types
housing_condition_types
health_condition_types
disability_types
education_levels
education_statuses
employment_statuses
employment_sectors
document_types
need_types
assistance_types
note_types
assessment_types
form_types
roles
permissions
```

Only approved lookup values should be treated as production seed data.

---

# 69. Development Data

Development environments may use factories and seeders containing fictional data.

Development data MUST NOT contain real family information.

Example:

```text
FAM-000001
Test Family

PER-000001
Test Person
```

or generated fictional records.

---

# 70. Backup Requirements

Production architecture must support:

```text
Database backup
Document backup
Restore testing
Retention policy
Access control
```

Backups containing personal data are sensitive and require protection equivalent to production data.

---

# 71. Database Access

Production database credentials:

- MUST NOT be committed to Git.
- MUST be stored in environment configuration.
- SHOULD use least-privilege database accounts.

Example:

```text
.env
```

must remain ignored by Git.

---

# 72. Laravel Environment

Example development configuration:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=famboook
DB_USERNAME=famboook_app
DB_PASSWORD=
```

Actual credentials must never be committed.

Use:

```text
.env.example
```

for non-secret configuration examples.

---

# 73. Database Naming

Use:

```text
snake_case
```

Tables:

```text
family_memberships
person_health_conditions
assistance_records
workflow_events
```

Columns:

```text
family_id
created_at
is_active
```

Foreign keys:

```text
<entity>_id
```

Boolean fields:

```text
is_*
has_*
requires_*
uses_*
```

Dates:

```text
*_date
```

Timestamps:

```text
*_at
```

Polymorphic fields:

```text
workflowable_type
workflowable_id
```

---

# 74. Database Anti-Patterns

The following designs are prohibited unless a later architecture decision explicitly changes them.

## Do Not

```text
child1_name
child2_name
child3_name
```

## Do Not

```text
wife1_id
wife2_id
wife3_id
```

## Do Not

store comma-separated conditions:

```text
"diabetes,hypertension,kidney"
```

## Do Not

use National ID as Person primary key.

## Do Not

use Family Code as Family primary key.

## Do Not

hard-delete a Person merely because they leave a household.

## Do Not

store age as the canonical value.

## Do Not

store manually maintained family-member counts.

## Do Not

store sensitive uploaded documents in public web directories.

## Do Not

store only the latest workflow status without preserving important transition history.

## Do Not

allow users to manually overwrite workflow history.

---

# 75. Pending Database Decisions

The following must be finalized before production deployment.

### PDD-001 — National ID Strategy

Finalize:

```text
Uniqueness
Normalization
Encryption
Search hash
Exceptional duplicate handling
```

---

### PDD-002 — Approved Lookup Values

Finalize lookup values from the approved operational source/form.

---

### PDD-003 — Time-Sensitive Health Data

Determine whether:

```text
pregnancy
breastfeeding
```

remain in the current health profile only or also require assessment-linked historical observations.

---

### PDD-004 — Retention Policy

Define retention and archival rules for:

```text
Forms
Documents
Workflow events
Audit logs
Exports
Archived records
Backups
```

---

### PDD-005 — Confidential Note Encryption

Determine whether confidential notes require field-level encryption in addition to authorization controls.

---

### PDD-006 — Backup Policy

Finalize:

```text
Frequency
Retention
Encryption
Storage
Restore testing
Access
```

---

### PDD-007 — Advanced Search

Determine whether V1 requires:

```text
pg_trgm
GIN indexes
Fuzzy Arabic name matching
```

or whether indexed standard search is sufficient initially.

---

### PDD-008 — Membership Type

Determine whether `family_memberships` requires an explicit:

```text
membership_type
```

in addition to:

```text
relationship_type_id
```

for future household models.

---

### PDD-009 — Workflow Polymorphic Type Storage

Before implementation, define a stable morph map for workflow-controlled entities.

Recommended conceptual aliases:

```text
form_submission
assessment
family_need
```

rather than storing full PHP class names in `workflowable_type`.

This avoids coupling persistent database data to PHP namespaces.

---

### PDD-010 — Duplicate Merge Tool

Determine whether V1 requires a full database-backed Person merge process or only duplicate review and administrator resolution.

---

# 76. Approved Database Decisions V1

The following architecture decisions are approved:

### DB-ADR-001

PostgreSQL is the primary relational database.

### DB-ADR-002

Internal BIGINT primary keys are used for core tables.

### DB-ADR-003

`family_code` and `person_code` are unique business identifiers.

### DB-ADR-004

Person identity is independent of Family identity.

### DB-ADR-005

`family_memberships` is the canonical relationship between Persons and Families.

### DB-ADR-006

Historical membership is preserved.

### DB-ADR-007

One Person normally has one active primary family membership.

### DB-ADR-008

One Family normally has one active household head.

### DB-ADR-009

Residence history is represented using multiple records.

### DB-ADR-010

Health conditions and disabilities are repeatable relational records.

### DB-ADR-011

Needs and Assistance use separate tables.

### DB-ADR-012

Assessments and Form Submissions are separate from permanent Family records.

### DB-ADR-013

Core identity/history records are protected against destructive cascading deletes.

### DB-ADR-014

Reference data uses stable codes.

### DB-ADR-015

Derived statistics are not canonical database columns.

### DB-ADR-016

Critical multi-record operations use transactions.

### DB-ADR-017

Sensitive documents use private storage.

### DB-ADR-018

Audit history is append-oriented and protected from normal modification.

### DB-ADR-019

`workflow_events` preserves workflow state-transition history.

### DB-ADR-020

Current workflow state remains on the workflow-controlled business entity for efficient access.

### DB-ADR-021

Workflow history is append-only for normal application users.

### DB-ADR-022

Workflow state changes and their corresponding workflow events should be written atomically.

### DB-ADR-023

Polymorphic workflow relationships should use stable morph aliases rather than PHP class names.

---

# 77. Documentation Synchronization

The database implementation MUST remain consistent with:

```text
01-PRODUCT.md
02-DATA-DICTIONARY.md
03-BUSINESS-RULES.md
04-DATABASE.md
05-WORKFLOWS.md
```

If implementation requires a structural change, documentation must be updated intentionally rather than allowing code and documentation to diverge.

The precedence for implementation-specific database structure is:

```text
Product Intent
      ↓
Data Definitions
      ↓
Business Rules
      ↓
Database Architecture
      ↓
Workflow Requirements
      ↓
Permissions
      ↓
Implementation
```

Where a later approved architecture decision intentionally refines an earlier document, the affected earlier document should be updated or clearly marked as superseded for that specific decision.

---

# 78. Implementation Readiness Checklist

Before generating production Laravel migrations, confirm:

```text
[ ] Product V1 approved
[ ] Data Dictionary approved
[ ] Business Rules approved
[ ] Database Architecture approved
[ ] Workflows approved
[ ] Permissions approved
[ ] Lookup vocabularies reviewed
[ ] National ID strategy confirmed
[ ] Sensitive-data strategy confirmed
[ ] Workflow morph aliases confirmed
[ ] Backup/security baseline defined
```

Migrations may be prototyped before every production decision is finalized, but production data MUST NOT be loaded until critical identity, security, and retention decisions are resolved.

---

# 79. Document Status

```text
Project: Famboook
Document: Database Architecture
Version: 1.1
Status: APPROVED
Database: PostgreSQL
Date: 2026-09-22
```

---

# 80. Change Log

| Version | Date | Status | Description |
|---|---|---|---|
| 1.0 | 2026-09-22 | Approved | Initial PostgreSQL database architecture |
| 1.1 | 2026-09-22 | Approved | Added workflow_events architecture, workflow indexes, migration ordering, workflow relationships, transaction rules, and synchronization with 05-WORKFLOWS.md |

---

# 81. Next Step

Current documentation status:

```text
01-PRODUCT.md                 APPROVED
02-DATA-DICTIONARY.md         APPROVED
03-BUSINESS-RULES.md          APPROVED
04-DATABASE.md                APPROVED — v1.1
05-WORKFLOWS.md               APPROVED
06-PERMISSIONS.md             NEXT
07-ROADMAP.md
```

The next document must define:

```text
WHO
   ↓
CAN PERFORM WHICH ACTION
   ↓
ON WHICH ENTITY
   ↓
AT WHICH WORKFLOW STATE
   ↓
WITH ACCESS TO WHICH FIELDS
```

before production implementation begins.