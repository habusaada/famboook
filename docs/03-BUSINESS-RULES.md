# Famboook
## Business Rules

**Document:** `03-BUSINESS-RULES.md`  
**Version:** 1.2.3  
**Status:** Approved  
**Last Updated:** 2026-09-24  
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

# 47. Assistance

Assistance represents support actually recorded/provided.

Assistance does not automatically close a Need.

Need closure requires explicit workflow/business logic.

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
Version: 1.2.3
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
| 1.2.3 | 2026-09-24 | Approved | §36 V1 health decisions: all record types closable (never deleted), active pregnancy/breastfeeding blocks gender correction away from FEMALE, deceased records stay historical but are excluded from current indicators, no minimum pregnancy/breastfeeding age |
| 1.2.2 | 2026-09-24 | Approved | Added §36 "Health & Special-Needs Records (V1)": person-based types, FEMALE-only maternal records, duplicate-active rules, close-not-delete, derived indicators |
| 1.2.1 | 2026-09-24 | Approved | Added §56 "Registration Date Independence": correcting `families.registration_date` does not cascade to membership or residence `started_at` |