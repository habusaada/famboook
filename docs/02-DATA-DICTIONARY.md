# Famboook
## Data Dictionary

**Document:** `02-DATA-DICTIONARY.md`  
**Version:** 1.1  
**Status:** Approved  
**Last Updated:** 2026-09-22  
**Project:** Famboook — Family Registry & Case Management System

---

# 1. Purpose

This document defines the business meaning, structure, classification, and expected behavior of the core data elements used by Famboook.

It translates the approved Product Definition into a normalized data vocabulary before physical database implementation.

This document covers:

```text
Families
Persons
Family Memberships
Person Relationships
Residence & Displacement
Health
Disability
Education
Employment
Assessments
Source Forms
Documents
Needs
Assistance
Notes
Users
Family Users
Change Requests
Notifications
Workflow History
Audit
Reference Data
```

The physical PostgreSQL implementation is defined separately in:

```text
04-DATABASE.md
```

---

# 2. Data Modeling Principles

Famboook follows these principles:

```text
One real-world concept
=
One clear data entity
```

Repeated information must be represented as repeated records rather than fixed columns.

Historical information should be preserved.

Derived values should not normally be stored as canonical fields.

Sensitive information must be classified and permission-controlled.

Family-submitted information must remain distinguishable from verified official registry data until approved and applied.

---

# 3. Canonical Data vs Submitted Data

Famboook distinguishes between:

```text
CANONICAL REGISTRY DATA
```

and:

```text
SUBMITTED / PROPOSED DATA
```

Canonical registry data represents the currently accepted official record.

Examples:

```text
Person Name
Birth Date
National ID
Family Membership
Current Residence
Life Status
```

Submitted data may originate from:

```text
Paper Forms
Data Entry
Assessments
Family Change Requests
Imports
```

Submitted data does not automatically become canonical data.

---

# 4. Core Entity Map

```text
Family
 │
 ├── Family Memberships
 │       │
 │       └── Persons
 │
 ├── Residences
 ├── Assessments
 ├── Form Submissions
 ├── Needs
 ├── Assistance
 ├── Documents
 ├── Case Notes
 └── Change Requests

Person
 │
 ├── Family Memberships
 ├── Person Relationships
 ├── Health Profile
 ├── Health Conditions
 ├── Disabilities
 ├── Education
 ├── Employment
 ├── Documents
 ├── Person Notes
 └── User Account Link

User
 │
 ├── Staff Role(s)
 │
 └── Optional Person Link
          │
          └── Family Portal

Change Request
 │
 ├── Family
 ├── Optional Person
 ├── Request Type
 ├── Proposed Data
 ├── Supporting Documents
 ├── Workflow History
 └── Review / Application Metadata
```

---

# 5. families

## Purpose

Represents one household/family registry entity.

A Family is independent from its current Household Head.

## Fields

| Field | Type | Required | Description |
|---|---|---:|---|
| id | BIGINT | Yes | Internal primary identifier |
| family_code | VARCHAR | Yes | Permanent business identifier |
| status | VARCHAR | Yes | Current Family registry status |
| registration_date | DATE | Yes | Date Family entered registry |
| registration_source | VARCHAR | Yes | Source of initial registration |
| paper_form_no | VARCHAR | No | Original paper form reference |
| notes | TEXT | No | General operational notes |
| created_by | User | No | User who created record |
| updated_by | User | No | Last modifying user |
| created_at | TIMESTAMP | Yes | Creation timestamp |
| updated_at | TIMESTAMP | Yes | Last update timestamp |
| deleted_at | TIMESTAMP | No | Soft-delete/archive support |

## Example

```text
family_code:
FAM-000510
```

## Rules

```text
Family Code is unique.
Family Code is permanent.
Family Code is not the database primary key.
Family size is derived.
Current Household Head is derived from active membership.
```

---

# 6. Family Status

Initial conceptual values:

```text
ACTIVE
INACTIVE
ARCHIVED
```

Family workflow status must not be confused with:

```text
Form Submission Status
Assessment Status
Change Request Status
Need Status
```

Each workflow-controlled entity has its own status.

---

# 7. persons

## Purpose

Represents one human identity.

A Person is independent from a Family.

## Fields

| Field | Type | Required | Description |
|---|---|---:|---|
| id | BIGINT | Yes | Internal identifier |
| person_code | VARCHAR | Yes | Permanent Person business code |
| full_name | VARCHAR | Yes | Person's full name |
| national_id | VARCHAR | No | National/identity number |
| gender | VARCHAR | Yes | Gender |
| birth_date | DATE | No | Date of birth |
| marital_status_id | Reference | No | Current marital status |
| life_status | VARCHAR | Yes | Living/deceased/etc. |
| mobile | VARCHAR | No | Primary mobile |
| alternate_mobile | VARCHAR | No | Secondary mobile |
| notes | TEXT | No | General notes |
| is_active | BOOLEAN | Yes | Operational active state |
| created_by | User | No | Creator |
| updated_by | User | No | Last updater |
| created_at | TIMESTAMP | Yes | Created timestamp |
| updated_at | TIMESTAMP | Yes | Updated timestamp |
| deleted_at | TIMESTAMP | No | Soft deletion |

## Example

```text
person_code:
PER-001825
```

---

# 8. Person Identity Rules

A Person must not be recreated because they:

```text
Move to another Family
Marry
Become Household Head
Stop being Household Head
Change residence
Change marital status
Become deceased
```

The same Person identity should remain.

---

# 9. National ID

## Type

```text
VARCHAR
```

Never:

```text
INTEGER
BIGINT
```

## Reason

National IDs may:

```text
Contain leading zeros
Require formatting
Require masking
Require encryption
```

## Rules

```text
Normalize before comparison.
Do not insert fake placeholder IDs.
Missing National ID may be allowed.
Duplicate National ID triggers review.
Duplicate National ID does not trigger automatic merge.
Changes to National ID are sensitive and audited.
```

## Display

Possible masked representation:

```text
804****32
```

Detailed authorization is defined in:

```text
06-PERMISSIONS.md
```

---

# 10. Gender

Initial conceptual values:

```text
MALE
FEMALE
```

Final implementation must use approved reference values or controlled enumeration.

---

# 11. Life Status

Initial conceptual values:

```text
ALIVE
DECEASED
UNKNOWN
```

A deceased Person remains in the registry.

Death does not mean deletion.

---

# 12. family_memberships

## Purpose

Represents a Person's membership in a Family.

This is the canonical Family ↔ Person relationship.

`persons.family_id` must not be treated as canonical membership.

## Fields

| Field | Type | Required | Description |
|---|---|---:|---|
| id | BIGINT | Yes | Internal ID |
| family_id | Family | Yes | Family |
| person_id | Person | Yes | Person |
| relationship_type_id | Reference | Yes | Relationship to household |
| is_household_head | BOOLEAN | Yes | Current Household Head indicator |
| paper_sequence_no | SMALLINT | No | Row/order on original paper form |
| started_at | DATE | No | Membership start |
| ended_at | DATE | No | Membership end |
| is_active | BOOLEAN | Yes | Active membership |
| end_reason | VARCHAR | No | Reason membership ended |
| notes | TEXT | No | Membership notes |
| created_by | User | No | Creator |
| updated_by | User | No | Last updater |
| created_at | TIMESTAMP | Yes | Created |
| updated_at | TIMESTAMP | Yes | Updated |

---

# 13. Membership Rules

V1 baseline:

```text
One Person
→ maximum one active primary Family membership
```

A Family may have unlimited members.

When a Person moves:

```text
Do not delete old membership.
Close old membership.
Create new membership.
```

---

# 14. Household Head

Household Head is represented through:

```text
family_memberships.is_household_head
```

A Family should normally have:

```text
One active Household Head
```

Changing Household Head must preserve Person identity and membership history.

---

# 15. relationship_types

## Purpose

Defines relationship of a Person to the household structure.

Initial conceptual values:

```text
SELF / HEAD
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

## Fields

```text
id
code
name_ar
name_en
is_active
sort_order
```

Final vocabulary must be approved before production seeding.

---

# 16. person_relationships

## Purpose

Represents direct Person-to-Person relationships independently from Family membership.

Examples:

```text
Spouse
Parent
Child
Sibling
Guardian
```

## Fields

| Field | Type | Required |
|---|---|---:|
| id | BIGINT | Yes |
| person_id | Person | Yes |
| related_person_id | Person | Yes |
| relationship_type_id | Reference | Yes |
| start_date | DATE | No |
| end_date | DATE | No |
| status | VARCHAR | Yes |
| notes | TEXT | No |
| created_at | TIMESTAMP | Yes |
| updated_at | TIMESTAMP | Yes |

A Person cannot be related to themselves.

---

# 17. marital_statuses

Reference entity.

Potential values:

```text
SINGLE
MARRIED
DIVORCED
WIDOWED
SEPARATED
UNKNOWN
```

Final values require operational approval.

---

# 18. family_residences

## Purpose

Stores current and historical Family residence information.

## Fields

| Field | Type | Required |
|---|---|---:|
| id | BIGINT | Yes |
| family_id | Family | Yes |
| governorate_id | Reference | No |
| locality_id | Reference | No |
| neighborhood | VARCHAR | No |
| address_details | TEXT | No |
| housing_type_id | Reference | No |
| tenure_type_id | Reference | No |
| housing_condition_id | Reference | No |
| is_displaced | BOOLEAN | Yes |
| displacement_location | TEXT | No |
| displacement_date | DATE | No |
| displacement_reason | TEXT | No |
| is_current | BOOLEAN | Yes |
| from_date | DATE | No |
| to_date | DATE | No |
| notes | TEXT | No |
| created_at | TIMESTAMP | Yes |
| updated_at | TIMESTAMP | Yes |

---

# 19. Residence Rules

A Family should normally have:

```text
Maximum one current residence.
```

Changing residence must:

```text
Close previous current residence
+
Create new current residence
```

Historical displacement information must not be destroyed.

---

# 20. Geographic Reference Data

Reference entities:

```text
governorates
localities
```

`localities` belongs to a Governorate.

Free-text location information may still be used for detailed addresses where required.

---

# 21. Housing Reference Data

Reference entities:

```text
housing_types
tenure_types
housing_condition_types
```

Final values must be verified against the approved source forms and operational terminology.

---

# 22. person_health_profiles

## Purpose

Stores current high-level health indicators for a Person.

## Fields

```text
id
person_id

has_health_condition
has_chronic_disease
has_disability

is_pregnant
is_breastfeeding

requires_follow_up

notes

created_at
updated_at
```

## Classification

```text
RESTRICTED
```

---

# 23. Health Time Sensitivity

Some health fields are time-sensitive.

Examples:

```text
is_pregnant
is_breastfeeding
```

These should not be interpreted as permanent Person identity attributes.

Assessment-linked historical representation may be required for longitudinal reporting.

---

# 24. health_condition_types

Reference entity.

Examples may include approved medical categories.

Fields:

```text
id
code
name_ar
name_en
is_chronic
is_active
sort_order
```

Do not finalize medical vocabulary from assumptions.

---

# 25. person_health_conditions

## Purpose

Allows multiple health conditions per Person.

## Fields

```text
id
person_id
health_condition_type_id
details
severity
requires_treatment
requires_medication
notes
created_at
updated_at
```

Classification:

```text
RESTRICTED
```

---

# 26. disability_types

Reference entity.

Fields:

```text
id
code
name_ar
name_en
is_active
sort_order
```

Final values require source verification.

---

# 27. person_disabilities

## Purpose

Allows multiple disability records per Person.

## Fields

```text
id
person_id
disability_type_id
severity
requires_assistance
uses_assistive_device
assistive_device
notes
created_at
updated_at
```

Classification:

```text
RESTRICTED
```

---

# 28. person_education

## Purpose

Stores Person education information/history.

## Fields

```text
id
person_id

is_enrolled
education_level_id
current_grade
institution_name
specialization
education_status_id

from_date
to_date
is_current

notes

created_at
updated_at
```

Multiple historical records may exist.

---

# 29. Education Reference Data

```text
education_levels
education_statuses
```

Final values must be approved.

---

# 30. person_employment

## Purpose

Stores Person employment history.

## Fields

```text
id
person_id

employment_status_id
occupation
employer
employment_sector_id

has_income
income_amount
income_frequency

from_date
to_date
is_current

notes

created_at
updated_at
```

Income data should only be collected where operationally justified.

---

# 31. Employment Reference Data

```text
employment_statuses
employment_sectors
```

Final values require approval.

---

# 32. assessments

## Purpose

Represents a point-in-time data collection, verification, or follow-up event.

## Fields

```text
id
assessment_code

family_id
assessment_type_id
assessment_date

collector_id
reviewer_id

status
location
source
notes

submitted_at
verified_at

created_at
updated_at
```

A Family may have multiple Assessments.

---

# 33. assessment_types

Potential types:

```text
INITIAL_REGISTRATION
VERIFICATION
FOLLOW_UP
NEEDS_ASSESSMENT
EMERGENCY_UPDATE
```

Final codes are reference data.

---

# 34. form_submissions

## Purpose

Represents a paper/digital source form and its controlled data-entry lifecycle.

## Fields

```text
id
family_id
assessment_id
form_type_id

paper_form_no
page_count
source_file

entered_by
entered_at

reviewed_by
reviewed_at

status
return_reason

created_at
updated_at
```

---

# 35. Form Submission Status

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

Current status is stored on the submission.

State history is stored separately through workflow events.

---

# 36. form_types

Reference entity.

Purpose:

Allows multiple source/form types without changing core architecture.

Fields:

```text
id
code
name_ar
name_en
is_active
sort_order
```

---

# 37. workflow_events

## Purpose

Stores immutable workflow state-transition history.

Applicable entities include:

```text
Form Submission
Assessment
Family Need
Change Request
```

## Fields

```text
id

workflowable_type
workflowable_id

from_status
to_status
action
reason
metadata

performed_by
created_at
```

## Rule

Workflow events are append-only for normal application users.

---

# 38. Workflow Event vs Audit Log

```text
workflow_events
```

answers:

```text
How did the workflow state change?
```

Example:

```text
UNDER_REVIEW
→
APPROVED
```

`audit_logs` answers:

```text
What data/system action changed?
```

Example:

```text
mobile:
0590000000
→
0560000000
```

One business operation may generate both.

---

# 39. documents

## Purpose

Stores metadata for supporting documents.

Documents may belong to:

```text
Family
Person
Change Request
```

depending on context.

## Fields

```text
id

family_id nullable
person_id nullable
change_request_id nullable

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

At least one valid business context must exist.

---

# 40. Document Ownership

Possible contexts:

```text
Family Document

Person Document

Change Request Supporting Document
```

A Change Request document may later become associated with a canonical Person/Family document after review if operationally appropriate.

This must be an explicit action.

It must not happen merely because a file was uploaded.

---

# 41. document_types

Potential types:

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

Final values require approval.

---

# 42. Document Verification

These concepts are different:

```text
Uploaded
Available
Verified
```

A Family User uploading a document means:

```text
Uploaded
```

not:

```text
Verified
```

Verification requires authorized staff action.

---

# 43. family_needs

## Purpose

Represents identified needs.

## Fields

```text
id

family_id
person_id nullable
assessment_id nullable

need_type_id
priority
description
status

identified_at

is_verified
verified_by
verified_at

created_at
updated_at
```

---

# 44. Need Status

Conceptual lifecycle:

```text
IDENTIFIED
VERIFIED
ACTIVE
PARTIALLY_MET
MET
CLOSED
```

Detailed transitions belong in:

```text
05-WORKFLOWS.md
```

---

# 45. need_types

Potential categories:

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

Final values must be approved.

---

# 46. assistance_records

## Purpose

Represents an assistance event.

## Fields

```text
id

family_id
person_id nullable
need_id nullable

assistance_type_id

provider_name
description

quantity
unit

estimated_value
currency

received_at
distribution_reference

notes

created_at
updated_at
```

Assistance may optionally link to a Need.

---

# 47. Assistance Rules

```text
Need
≠
Assistance
```

Recording Assistance does not automatically mean:

```text
Need Verified
Need Met
Need Closed
```

These are separate controlled decisions.

---

# 48. assistance_types

Reference entity.

Final categories require operational approval.

---

# 49. person_notes

## Purpose

Stores Person-related notes.

## Fields

```text
id
person_id
note_type_id
note
is_confidential
created_by
created_at
updated_at
```

Notes are append-oriented.

---

# 50. case_notes

## Purpose

Stores Family/case-management notes.

## Fields

```text
id
family_id
assessment_id nullable
person_id nullable
note_type_id
note
is_confidential
created_by
created_at
updated_at
```

Confidential notes require additional authorization.

Family Users must not automatically see internal Case Notes.

---

# 51. note_types

Potential categories:

```text
GENERAL
FOLLOW_UP
VERIFICATION
PROTECTION
HEALTH
CORRECTION
OTHER
```

Final values require approval.

---

# 52. users

## Purpose

Represents an authentication identity.

A User is not automatically a Person.

Internal staff may have:

```text
User
without
Person
```

Family Portal users normally have:

```text
User
linked to
Person
```

## Fields

```text
id
name
email nullable
mobile nullable
password
is_active
last_login_at
created_at
updated_at
```

Additional authentication fields may be introduced during implementation.

---

# 53. User vs Person

This distinction is mandatory.

```text
USER
=
Who can authenticate?

PERSON
=
Who exists in the Family Registry?
```

Examples:

```text
Staff Administrator
→ User
→ No Person required
```

```text
Household Head
→ Person
→ May have User account
```

A Person does not automatically receive a User account.

---

# 54. user_person_links

## Purpose

Links an authenticated User to a registry Person.

This is especially important for Family Portal access.

Recommended entity:

```text
user_person_links
```

## Fields

| Field | Type | Required | Description |
|---|---|---:|---|
| id | BIGINT | Yes | Internal identifier |
| user_id | User | Yes | Authentication account |
| person_id | Person | Yes | Registry Person |
| link_type | VARCHAR | Yes | Nature of link |
| status | VARCHAR | Yes | Link status |
| verified_by | User | No | Staff verifier |
| verified_at | TIMESTAMP | No | Verification time |
| activated_at | TIMESTAMP | No | Access activation |
| ended_at | TIMESTAMP | No | Access/link end |
| end_reason | VARCHAR | No | Reason link ended |
| created_at | TIMESTAMP | Yes | Created |
| updated_at | TIMESTAMP | Yes | Updated |

---

# 55. User-Person Link Type

Initial conceptual value:

```text
SELF
```

Future possibilities may include:

```text
GUARDIAN
AUTHORIZED_REPRESENTATIVE
```

These should not be enabled until corresponding business rules are approved.

---

# 56. User-Person Link Status

Recommended values:

```text
PENDING_VERIFICATION
VERIFIED
ACTIVE
SUSPENDED
ENDED
```

A link alone does not necessarily mean current portal access.

Authorization must consider:

```text
User Active?
+
Link Active?
+
Person Relationship?
+
Family Authorization?
```

---

# 57. Family User

`FAMILY_USER` is an authorization role for an authenticated external user.

A Family User is normally represented by:

```text
users
      ↓
user_person_links
      ↓
persons
      ↓
family_memberships
      ↓
families
```

The initial V1 operational policy may limit Family User access to the active Household Head.

The data architecture does not permanently hard-code that assumption.

---

# 58. Family User Account Activation

Account activation must be separate from Person creation.

Conceptual process:

```text
Person Exists
      ↓
Identity Verified
      ↓
User Created / Linked
      ↓
Portal Access Activated
```

The exact verification mechanism remains a pending security/workflow decision.

---

# 59. change_requests

## Purpose

Represents a request to modify official registry information without directly overwriting the canonical record.

This is the core Family Portal update mechanism.

## Fields

| Field | Type | Required | Description |
|---|---|---:|---|
| id | BIGINT | Yes | Internal ID |
| request_code | VARCHAR | Yes | Permanent request business code |
| family_id | Family | Yes | Family concerned |
| person_id | Person | No | Existing Person concerned |
| change_request_type_id | Reference | Yes | Request type |
| status | VARCHAR | Yes | Current workflow state |
| risk_level | VARCHAR | No | LOW/MEDIUM/HIGH if used |
| submitted_data | JSON/JSONB concept | Yes | Proposed data payload |
| reason | TEXT | No | Family-provided reason |
| notes | TEXT | No | General request notes |
| submitted_by | User | Yes | Family User or authorized submitter |
| submitted_at | TIMESTAMP | No | Submission timestamp |
| reviewed_by | User | No | Reviewer |
| reviewed_at | TIMESTAMP | No | Review timestamp |
| review_notes | TEXT | No | Staff review notes |
| approved_by | User | No | Approver |
| approved_at | TIMESTAMP | No | Approval timestamp |
| rejected_by | User | No | Rejecting user |
| rejected_at | TIMESTAMP | No | Rejection timestamp |
| rejection_reason | TEXT | No | Rejection reason |
| applied_by | User | No | User/system actor applying change |
| applied_at | TIMESTAMP | No | Application timestamp |
| created_at | TIMESTAMP | Yes | Created |
| updated_at | TIMESTAMP | Yes | Updated |

---

# 60. Change Request Code

Recommended business identifier:

```text
CRQ-000001
```

Properties:

```text
Unique
Permanent
Human-readable
Not database primary key
```

---

# 61. Change Request Target

Every Change Request belongs to:

```text
One Family
```

It may optionally target:

```text
One existing Person
```

Examples:

```text
CONTACT_UPDATE
→ person_id may identify the Person

PERSON_CORRECTION
→ person_id required

DEATH_REPORT
→ person_id required

RESIDENCE_UPDATE
→ person_id may be null because residence belongs to Family

ADD_FAMILY_MEMBER
→ person_id may initially be null because duplicate review must occur before creating/reusing a Person
```

---

# 62. submitted_data

`submitted_data` stores the proposed values associated with the request.

Conceptually:

```json
{
  "mobile": "0560000000"
}
```

or:

```json
{
  "full_name": "Example Name",
  "gender": "MALE",
  "birth_date": "2026-09-01",
  "relationship_type": "SON"
}
```

This payload is:

```text
Proposed Data
```

not canonical registry data.

---

# 63. submitted_data Rules

`submitted_data` must not become an uncontrolled arbitrary storage mechanism.

Each Change Request Type must define:

```text
Allowed Fields
Required Fields
Validation Rules
Sensitivity
Application Action
```

Example:

```text
CONTACT_UPDATE
```

may allow:

```text
mobile
alternate_mobile
```

but must not allow:

```text
national_id
birth_date
life_status
```

unless explicitly part of that request type.

---

# 64. change_request_types

## Purpose

Defines supported Change Request categories.

## Fields

```text
id
code
name_ar
name_en
description
risk_level
requires_document
is_active
sort_order
created_at
updated_at
```

---

# 65. Initial Change Request Types

Recommended V1 starting set:

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

Final codes must be synchronized with Business Rules and Workflows.

---

# 66. Change Request Risk Level

Conceptual values:

```text
LOW
MEDIUM
HIGH
```

Example classification:

```text
CONTACT_UPDATE
→ potentially LOW

RESIDENCE_UPDATE
→ potentially MEDIUM

NATIONAL ID CORRECTION
→ HIGH

DEATH_REPORT
→ HIGH

HOUSEHOLD_HEAD_CHANGE
→ HIGH

MEMBERSHIP_CHANGE
→ HIGH
```

Risk classification does not automatically authorize direct application.

Default V1:

```text
Family-submitted changes require review.
```

---

# 67. Change Request Status

Approved conceptual lifecycle:

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

Current status is stored on:

```text
change_requests.status
```

Transition history is stored in:

```text
workflow_events
```

---

# 68. APPROVED vs APPLIED

These values must remain distinct.

```text
APPROVED
```

means:

```text
The proposed change was accepted.
```

`APPLIED` means:

```text
The approved domain change was successfully executed against the official registry.
```

Example:

```text
HOUSEHOLD_HEAD_CHANGE
APPROVED
```

does not yet mean:

```text
family_memberships.is_household_head
```

has changed.

Only after successful controlled application:

```text
APPLIED
```

is recorded.

---

# 69. Change Request Application

Each approved request must map to an approved domain action.

Examples:

```text
CONTACT_UPDATE
→ UpdatePersonContactAction

RESIDENCE_UPDATE
→ ChangeFamilyResidenceAction

ADD_FAMILY_MEMBER
→ CreateOrLinkFamilyMemberAction

HOUSEHOLD_HEAD_CHANGE
→ ChangeHouseholdHeadAction

DEATH_REPORT
→ RecordPersonDeathAction
```

The request mechanism must not bypass existing domain rules.

---

# 70. Change Request Documents

Supporting documents should be represented through:

```text
documents.change_request_id
```

Examples:

```text
ADD_FAMILY_MEMBER
→ Birth Certificate

DEATH_REPORT
→ Death Certificate

MARRIAGE_UPDATE
→ Marriage Document
```

Document upload does not imply verification.

---

# 71. Change Request Review Data

Review information includes:

```text
reviewed_by
reviewed_at
review_notes
```

Approval:

```text
approved_by
approved_at
```

Rejection:

```text
rejected_by
rejected_at
rejection_reason
```

Application:

```text
applied_by
applied_at
```

Detailed transition history remains in `workflow_events`.

---

# 72. Change Request Clarification

If additional information is required:

```text
UNDER_REVIEW
      ↓
RETURNED_FOR_CLARIFICATION
```

The Family User may provide clarification and/or additional supporting documents.

Then:

```text
RESUBMITTED
      ↓
UNDER_REVIEW
```

Previous workflow history must remain available.

---

# 73. Change Request Duplicate Detection

Requests affecting Person identity or membership may trigger duplicate detection.

Examples:

```text
ADD_FAMILY_MEMBER
BIRTH_REPORT
PERSON_CORRECTION
MEMBERSHIP_CHANGE
```

Duplicate outcomes:

```text
EXACT
PROBABLE
POSSIBLE
```

No automatic merge is permitted.

---

# 74. Example — Contact Update Request

```text
request_code:
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

The canonical Person record remains unchanged until the request is approved and applied.

---

# 75. Example — Residence Update Request

```text
type:
RESIDENCE_UPDATE

family_id:
510

person_id:
NULL

submitted_data:
{
  "governorate_id": 1,
  "locality_id": 15,
  "neighborhood": "Example",
  "is_displaced": true
}
```

After approval:

```text
Close previous current residence
+
Create new current residence
```

then:

```text
Change Request
→ APPLIED
```

---

# 76. Example — Add Family Member

Before approval:

```text
No new canonical Person is created merely because the Family User submitted the request.
```

Submitted data may include:

```text
full_name
gender
birth_date
national_id
relationship_type
```

Staff review performs:

```text
Validation
Duplicate Search
Document Review
```

Then either:

```text
Reuse Existing Person
```

or:

```text
Create New Person
```

followed by:

```text
Create Family Membership
```

---

# 77. Example — Death Report

Request:

```text
type:
DEATH_REPORT

person_id:
1825
```

Submitted information may include:

```text
death_date
supporting_document
reason/notes
```

After approval, an authorized domain action updates:

```text
persons.life_status
```

and triggers any required:

```text
Household Head Review
Membership Review
```

The Person is not deleted.

---

# 78. family_user_notifications

## Purpose

Stores application notifications relevant to Family Portal users.

Recommended logical entity:

```text
family_user_notifications
```

Alternatively, Laravel's notification infrastructure may provide the physical implementation.

## Data Elements

```text
id
user_id
type
title
message
related_type
related_id
read_at
created_at
```

---

# 79. Notification Types

Potential V1 events:

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

External delivery channels are separate from notification business events.

---

# 80. Notification Privacy

Notifications must not expose sensitive information unnecessarily.

For example, a notification may say:

```text
Your update request CRQ-000101 was approved.
```

rather than including sensitive identity/medical information in the notification body.

---

# 81. Audit Logs

## Purpose

Stores system/data-change audit history.

Logical fields:

```text
id
actor_id
event
entity_type
entity_id
old_values
new_values
ip_address
user_agent
created_at
```

Audit records are append-only to normal users.

---

# 82. Family Portal Audit Events

Important events include:

```text
Family User Account Activated
Family User Account Suspended

User-Person Link Verified
User-Person Link Ended

Change Request Created
Change Request Submitted
Change Request Returned
Change Request Resubmitted
Change Request Approved
Change Request Rejected
Change Request Applied

Supporting Document Uploaded

Sensitive Family Portal Access where required
```

---

# 83. Derived Fields

The following should not normally be stored as canonical values:

```text
age

family_size

children_count

male_count

female_count

under_1_count
under_2_count
under_5_count

elderly_count

disabled_count

chronic_disease_count

pending_change_request_count
```

These should be calculated from canonical records.

---

# 84. Age

Age is derived from:

```text
birth_date
+
reference date
```

Do not store permanent:

```text
age
```

because it becomes stale.

---

# 85. Family Size

Family size is derived from active:

```text
family_memberships
```

not from a manually maintained:

```text
families.family_size
```

field.

---

# 86. Paper Sequence Number

`paper_sequence_no` exists only for source traceability.

It does not represent Person identity.

Example:

```text
Person appears on row 4
```

does not mean:

```text
Person ID = 4
```

---

# 87. Data Classification

Famboook data is classified into:

```text
RESTRICTED
INTERNAL
OPERATIONAL
```

---

# 88. Restricted Data

Examples:

```text
National ID

Health Conditions

Disability Information

Identity Documents

Medical Documents

Confidential Notes

Sensitive Supporting Documents

Sensitive Change Request Payloads
```

Access requires explicit authorization.

---

# 89. Internal Data

Examples:

```text
Mobile

Address

Family Relationships

Employment

Needs

Assistance

Family Change Requests
```

Internal does not mean automatically visible to Family Portal users.

Family Portal visibility is defined separately.

---

# 90. Operational Data

Examples:

```text
Family Code
Person Code
Request Code
Workflow Status
Registration Date
Timestamps
```

Operational classification does not mean publicly accessible.

---

# 91. Family Portal Visibility Classification

A separate visibility concept is required for Family Portal presentation.

Possible conceptual levels:

```text
FAMILY_VISIBLE
SELF_ONLY
STAFF_ONLY
RESTRICTED
```

This is a presentation/authorization concept.

It does not necessarily require a visibility column on every database table.

---

# 92. FAMILY_VISIBLE

Information approved for presentation to the authorized Family User.

Examples may include:

```text
Family Code
Member Names
Basic Relationships
Request Status
Selected Assistance Summary
```

Final visibility rules belong in:

```text
06-PERMISSIONS.md
```

---

# 93. SELF_ONLY

Information visible only to the linked Person rather than every Family User.

This becomes particularly important if multiple Family User accounts are supported later.

---

# 94. STAFF_ONLY

Examples:

```text
Internal Case Notes
Review Notes
Audit Logs
Duplicate Investigation Notes
Internal Verification Comments
```

These must not be exposed through Family Portal APIs or UI.

---

# 95. Restricted Family Portal Data

Examples requiring explicit policy decisions:

```text
Full National IDs
Health Details
Disability Details
Adult Member Documents
Confidential Information
```

Do not assume Household Head status automatically grants access to all such data.

---

# 96. Null, Unknown and Not Applicable

These concepts should remain distinguishable where important.

Example:

```text
is_pregnant = FALSE
```

means:

```text
Known not pregnant
```

while:

```text
is_pregnant = NULL
```

may mean:

```text
Not assessed / unknown
```

Similarly:

```text
UNKNOWN
NOT_APPLICABLE
NOT_RECORDED
```

should not be collapsed where operational meaning differs.

---

# 97. Dates

Use:

```text
DATE
```

for calendar dates such as:

```text
birth_date
assessment_date
received_at
```

Use:

```text
TIMESTAMP
```

for events such as:

```text
submitted_at
verified_at
approved_at
applied_at
created_at
```

---

# 98. Money

Use decimal/numeric values.

Never use floating point for monetary values.

Example:

```text
estimated_value
NUMERIC(12,2)
```

Currency should be explicit where required.

---

# 99. Phone Numbers

Store as text.

Example:

```text
VARCHAR
```

Do not store as numeric values.

Phone normalization belongs in application validation.

---

# 100. Reference Data Standard

Reference entities should normally provide:

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

where appropriate.

Codes should remain stable even if labels change.

---

# 101. Reference Data List

V1 reference data includes:

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

change_request_types
```

---

# 102. Searchable Fields

Initial registry search:

```text
family_code
person_code
national_id
full_name
mobile
```

Change Request search:

```text
request_code
family_code
person_code
status
request_type
submitted_at
```

Search results must respect authorization.

---

# 103. Duplicate Detection Inputs

Potential signals:

```text
Normalized National ID

Normalized Full Name

Birth Date

Gender

Mobile

Existing Relationships

Existing Membership
```

Duplicate detection is advisory/review-based except where exact conflicts must block creation.

---

# 104. Duplicate Classification

```text
EXACT
PROBABLE
POSSIBLE
```

### EXACT

Example:

```text
Same normalized National ID
```

### PROBABLE

Example:

```text
Same full name
+
Same birth date
+
Same gender
```

### POSSIBLE

Example:

```text
Similar name
+
Matching relationship/mobile/context
```

No classification automatically merges records.

---

# 105. Family Portal Duplicate Protection

Family Portal requests that could create a Person must not create that Person before duplicate checking.

Examples:

```text
ADD_FAMILY_MEMBER
BIRTH_REPORT
```

Flow:

```text
Submitted Person Data
      ↓
Duplicate Detection
      ↓
Human Review
      ↓
Reuse Existing / Create New
```

---

# 106. Historical Data

Historical information must be preserved for:

```text
Family Membership
Residence
Education
Employment
Assessments
Needs
Assistance
Workflow
Change Requests
User-Person Access Links
```

History should not be reconstructed only from audit logs where a proper domain-history record exists.

---

# 107. Source Traceability

Data may originate from:

```text
Paper Form
Staff Entry
Assessment
Family Change Request
Import
Approved Correction
```

Where operationally important, source information should remain traceable.

---

# 108. Change Source

When an approved Change Request modifies the official registry, the resulting operation should retain traceability to:

```text
change_request_id
```

either through:

```text
Audit metadata
Workflow metadata
Domain event metadata
```

or an explicit relationship where justified.

The exact physical strategy is defined in Database Architecture.

---

# 109. Data Validation Layers

Validation occurs at multiple levels:

```text
UI Validation

Request Validation

Domain Validation

Workflow Validation

Database Constraints
```

Critical business invariants must not rely only on frontend validation.

---

# 110. Family User Input Validation

Family-submitted input must be treated as untrusted input.

It requires:

```text
Authentication
Authorization
Validation
Normalization
File Validation
Rate Limiting where appropriate
Workflow Review
```

Family User status does not make submitted data automatically trusted.

---

# 111. Supporting File Validation

Uploads should validate:

```text
Allowed file type
Allowed size
Malware/security strategy where available
Private storage destination
Authorization
Ownership context
```

Original filenames should not be trusted as storage paths.

---

# 112. Data Retention

Retention rules must be finalized for:

```text
Paper Source Files
Documents
Change Request Documents
Rejected Requests
Workflow Events
Audit Logs
Exports
Backups
```

Until policy is finalized, historical business records should not be casually destroyed.

---

# 113. Data Dictionary Invariants

```text
DD-INV-001
Person identity is independent from Family membership.

DD-INV-002
Family membership is represented through family_memberships.

DD-INV-003
Family size is derived from active memberships.

DD-INV-004
Age is derived from birth date.

DD-INV-005
National ID is stored as text.

DD-INV-006
A paper row is not a Person identity.

DD-INV-007
Health conditions are repeatable records.

DD-INV-008
Disabilities are repeatable records.

DD-INV-009
Need and Assistance are separate concepts.

DD-INV-010
A User is not the same entity as a Person.

DD-INV-011
A Person does not automatically have a User account.

DD-INV-012
Family Portal access requires an authorized User-Person relationship.

DD-INV-013
Family-submitted proposed data is not canonical registry data.

DD-INV-014
Change Request APPROVED is not the same as APPLIED.

DD-INV-015
Family User document upload does not imply document verification.

DD-INV-016
Change Requests preserve their workflow history.

DD-INV-017
Adding a Person through a Change Request requires duplicate review.

DD-INV-018
Death changes life status; it does not delete Person identity.

DD-INV-019
Internal notes are not automatically Family Portal visible.

DD-INV-020
Family Portal authorization must follow current approved relationships.
```

---

# 114. Approved Data Decisions V1

### DD-ADR-001

Families and Persons use independent persistent identifiers.

### DD-ADR-002

`family_memberships` is the canonical Family ↔ Person relationship.

### DD-ADR-003

Repeatable data uses child records rather than fixed columns.

### DD-ADR-004

National IDs use text representation.

### DD-ADR-005

Derived statistics are not canonical stored fields.

### DD-ADR-006

Residence history is preserved.

### DD-ADR-007

Assessments are separate from permanent registry identity.

### DD-ADR-008

Needs and Assistance are separate.

### DD-ADR-009

Workflow history is represented separately from current status.

### DD-ADR-010

Users and Persons are separate entities.

### DD-ADR-011

Family Portal authentication uses an explicit User ↔ Person link.

### DD-ADR-012

`FAMILY_USER` represents an authenticated external registry user.

### DD-ADR-013

Family-submitted updates are represented as `change_requests`.

### DD-ADR-014

Change Requests use a permanent `request_code`.

### DD-ADR-015

Proposed Change Request values remain separate from canonical registry data until application.

### DD-ADR-016

Change Request types define allowed update contexts.

### DD-ADR-017

Supporting documents may belong to Change Requests.

### DD-ADR-018

Change Requests participate in workflow history.

### DD-ADR-019

Family User notifications are supported as an application concept.

### DD-ADR-020

Family Portal visibility is distinct from internal data classification.

---

# 115. Pending Data Decisions

### PDDICT-001 — National ID Format

Confirm:

```text
Length
Validation rules
Normalization
Exceptional cases
```

---

### PDDICT-002 — National ID Encryption

Determine:

```text
Plain indexed value
Encrypted value
Search hash
Combination
```

before production identity data is loaded.

---

### PDDICT-003 — Lookup Vocabularies

Finalize approved values for:

```text
Housing
Tenure
Housing Condition
Health Conditions
Disability
Education
Employment
Needs
Assistance
Documents
```

---

### PDDICT-004 — Pregnancy/Breastfeeding History

Determine whether these require explicit assessment-linked observation records.

---

### PDDICT-005 — Family User Eligibility

Confirm whether V1 account access is:

```text
Household Head only
```

or also:

```text
Authorized Representative
```

---

### PDDICT-006 — Multiple Family Users

Determine whether one Family may have multiple active Family User accounts in V1.

---

### PDDICT-007 — User-Person Link Cardinality

Baseline recommendation:

```text
One Family User account
→ one Person
```

Confirm whether future requirements require multiple Person links per User.

---

### PDDICT-008 — Change Request Payload Strategy

Confirm physical representation of:

```text
submitted_data
```

Recommended starting direction:

```text
JSONB
+
type-specific validation
```

Do not use JSONB as a replacement for canonical relational registry tables.

---

### PDDICT-009 — Change Request Type Configuration

Determine whether type-specific allowed fields and validation rules are:

```text
Code-defined
Database-configured
Hybrid
```

Recommended V1:

```text
Code-defined validation
+
Reference table for type identity/display
```

---

### PDDICT-010 — Low-Risk Direct Updates

Default V1:

```text
No direct Family User registry modification.
```

Confirm whether any field such as alternate mobile may later bypass full review.

---

### PDDICT-011 — Family Portal Health Visibility

Define visibility for:

```text
Self
Minor children
Adult members
```

---

### PDDICT-012 — Family Portal Documents

Define which verified documents, if any, can be viewed/downloaded by Family Users.

---

### PDDICT-013 — Notification Storage

Determine whether physical implementation uses:

```text
Laravel notifications table
```

or a dedicated:

```text
family_user_notifications
```

table.

---

### PDDICT-014 — Change Request Documents

Determine whether supporting files remain permanently linked to the Change Request after application or may also be promoted/copied to canonical Family/Person document context.

---

### PDDICT-015 — Death Date

The current Person model contains:

```text
life_status
```

but does not yet explicitly define:

```text
death_date
```

Determine whether V1 requires a canonical death date field or a separate life-event model.

This must be resolved before implementing `DEATH_REPORT`.

---

### PDDICT-016 — Marriage History

Determine whether V1 requires only:

```text
Current Marital Status
+
Person Relationships
```

or a dedicated marriage/event history model.

---

### PDDICT-017 — Family User Access Revocation

Define exact data needed to preserve:

```text
Why access ended
Who ended it
When it ended
```

for Family Portal access.

---

# 116. Source Form Verification Notes

Some original paper-form labels and value lists may remain unclear.

Do not invent final reference values merely to complete development.

Use:

```text
PENDING VERIFICATION
```

where the source does not clearly support a value.

Production seeders should contain only approved vocabularies.

---

# 117. Synchronization With Product V1.1

This version incorporates the Product V1.1 concepts:

```text
Authenticated Family Portal

FAMILY_USER

User ↔ Person Link

Controlled Self-Service

Change Requests

Change Request Types

Change Request Workflow

Supporting Documents

Family User Notifications

Family Portal Data Scope
```

These concepts must now be reflected in:

```text
03-BUSINESS-RULES.md
04-DATABASE.md
05-WORKFLOWS.md
06-PERMISSIONS.md
07-ROADMAP.md
```

---

# 118. Required Database Changes

`04-DATABASE.md` must subsequently define physical structures for at least:

```text
user_person_links

change_requests

change_request_types
```

and update:

```text
documents
workflow_events
users
indexes
ERD
migration order
Laravel relationships
transaction boundaries
```

Notification storage must also be finalized or documented as Laravel notification infrastructure.

---

# 119. Required Workflow Changes

`05-WORKFLOWS.md` must subsequently define:

```text
Family User Account Activation

Change Request Creation

Change Request Submission

Change Request Review

Clarification / Return

Resubmission

Approval

Rejection

Application

Failure During Application

Family Portal Access Review

Household Head Change Impact on Portal Access
```

---

# 120. Required Permission Changes

`06-PERMISSIONS.md` must subsequently define:

```text
FAMILY_USER

Family Portal scope

Self / Family visibility

Change Request permissions

Supporting document upload

Request tracking

Family User notifications

Internal review permissions

Approval permissions

Apply permissions

Restricted Family Portal fields
```

---

# 121. Document Status

```text
Project: Famboook
Document: Data Dictionary
Version: 1.1
Status: APPROVED
Date: 2026-09-22
```

---

# 122. Change Log

| Version | Date | Status | Description |
|---|---|---|---|
| 1.0 | 2026-09-22 | Approved | Initial Famboook data dictionary |
| 1.1 | 2026-09-22 | Approved | Added Family Portal data concepts, FAMILY_USER, User-Person linking, Change Requests, supporting documents, workflow integration, Family User notifications, and Family Portal visibility rules; aligned canonical Family membership with family_memberships |

---

# 123. Next Step

Documentation synchronization now proceeds as:

```text
01-PRODUCT.md                 UPDATED — v1.1
02-DATA-DICTIONARY.md         UPDATED — v1.1
        ↓
03-BUSINESS-RULES.md          NEXT
        ↓
04-DATABASE.md
        ↓
05-WORKFLOWS.md
        ↓
06-PERMISSIONS.md
        ↓
07-ROADMAP.md
```

The next document must establish the business rules governing:

```text
Family User identity

Family Portal authorization

Account activation

User-Person linking

Change Request submission

Change Request review

Change Request approval/rejection

Applying approved changes

Supporting documents

Duplicate checks

Household Head changes

Access revocation

Sensitive Family Portal information
```

before physical database implementation is finalized.