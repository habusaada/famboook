# Famboook
## Business Rules

**Document:** `03-BUSINESS-RULES.md`  
**Version:** 1.1  
**Status:** Approved  
**Last Updated:** 2026-09-22  
**Project:** Famboook — Family Registry & Case Management System

---

# 1. Purpose

This document defines the business rules governing Famboook.

It establishes the rules that must remain true regardless of:

```text
User Interface
Staff Portal
Family Portal
API
Import Process
Database Administration Interface
```

The purpose is to ensure that all implementations operate against the same domain rules.

This document covers:

```text
Families
Persons
Family Membership
Household Heads
Relationships
Identity
Residence
Health
Disability
Education
Employment
Documents
Assessments
Needs
Assistance
Case Management
Verification
Duplicates
Family Users
Family Portal
Change Requests
Notifications
Security
Audit
Reporting
```

---

# 2. Business Rule Philosophy

Famboook is a registry and case-management platform.

Therefore:

```text
Data Integrity
>
UI Convenience
```

and:

```text
Registry Integrity
>
Direct Editing Convenience
```

Critical business rules must be enforced in:

```text
Domain Logic
Backend Validation
Database Constraints where appropriate
Authorization
Workflow Rules
```

They must not rely only on frontend validation.

---

# 3. Canonical Registry Principle

The official Famboook Registry represents the currently accepted version of Family and Person information.

Information received from:

```text
Paper Forms
Family Users
Imports
Assessments
Field Updates
```

does not automatically become official registry data.

It must pass the appropriate:

```text
Validation
Verification
Review
Approval
Application
```

rules.

---

# 4. Family Rule

A Family is an independent persistent registry entity.

A Family is not defined by:

```text
Current Household Head
Current Residence
Paper Form
Current Member Count
```

A Family receives a permanent:

```text
family_code
```

Example:

```text
FAM-000510
```

---

# 5. Family Code

A Family Code must be:

```text
Unique
Permanent
System-controlled
Human-readable
```

The Family Code must not normally change after assignment.

It must not be used as the physical database primary key.

---

# 6. Family Deletion

A Family with registry history must not be casually hard-deleted.

Normal lifecycle actions are:

```text
ACTIVE
INACTIVE
ARCHIVED
```

Hard deletion, if technically available for exceptional administration, must not be part of normal operational workflows.

---

# 7. Family Size

Family size is derived from active Family Membership records.

Do not maintain a manually editable canonical:

```text
family_size
```

field.

Conceptually:

```text
Family Size
=
Count of active family_memberships
```

subject to approved membership rules.

---

# 8. Person Rule

A Person is an independent persistent registry entity.

A Person receives a permanent:

```text
person_code
```

Example:

```text
PER-001825
```

A Person must not be recreated merely because their household circumstances change.

---

# 9. Person Persistence

The same Person must normally remain the same registry entity when they:

```text
Move between Families
Marry
Divorce
Become Household Head
Stop being Household Head
Change Residence
Change Employment
Change Education
Develop a Health Condition
Become Deceased
```

These are changes to the Person or their relationships/history.

They are not reasons to create a new Person.

---

# 10. Person Code

Person Code must be:

```text
Unique
Permanent
System-controlled
Human-readable
```

Person Code is not the same as:

```text
National ID
Paper Form Number
Family Code
Paper Row Number
```

---

# 11. Paper Row Rule

A row on a paper form is not a Person identity.

Example:

```text
Child #4
```

does not mean:

```text
Person ID = 4
```

Paper row/order may be retained only for source traceability.

---

# 12. Person Deletion

Persons with registry history must not normally be hard-deleted.

Examples of events that do not justify deletion:

```text
Death
Marriage
Family Transfer
Duplicate Investigation
Leaving Household
```

Appropriate state/history must be preserved instead.

---

# 13. Family Membership

The canonical relationship between Family and Person is:

```text
family_memberships
```

not:

```text
persons.family_id
```

This allows Person identity to survive household changes.

---

# 14. Active Membership

V1 baseline:

```text
One Person
→ Maximum one active primary Family membership
```

Historical Family Memberships may be unlimited.

---

# 15. Membership Transfer

When a Person moves from one Family to another:

```text
Do not delete Person
Do not overwrite old membership
```

Instead:

```text
Close old membership
      ↓
Create new membership
```

The operation must preserve history.

---

# 16. Membership Transfer Transaction

A Person transfer must be transactional.

Conceptually:

```text
BEGIN

Validate Person
Validate Source Membership
Validate Target Family
Close Source Membership
Create Target Membership
Record Audit
Record Relevant Workflow/Domain Metadata

COMMIT
```

If a critical step fails:

```text
ROLLBACK
```

must occur.

---

# 17. Household Head

A Household Head is:

```text
Person
+
Active Family Membership
+
is_household_head = true
```

Household Head is not a separate Person type.

---

# 18. One Active Household Head

An active Family should normally have:

```text
Exactly one active Household Head
```

Temporary exceptions during controlled workflows may be allowed only where implementation requires them inside a database transaction.

The final committed state must satisfy the approved rule.

---

# 19. Household Head Change

Changing Household Head must not:

```text
Create a new Person unnecessarily
Delete old Person
Destroy membership history
```

The controlled operation must:

```text
Validate current Household Head
Validate proposed Household Head
Confirm proposed Head is eligible active member
Remove Head flag from previous membership
Assign Head flag to new membership
Record reason
Audit operation
Review Family Portal access
```

---

# 20. Household Head Death

If the current Household Head is confirmed deceased:

```text
Family
→ HEAD_REVIEW_REQUIRED
```

conceptually.

The system must require a controlled Household Head review/change.

The deceased Person remains in the registry.

---

# 21. Person Relationships

Relationships between Persons must be explicit where required.

Examples:

```text
Parent
Child
Spouse
Sibling
Guardian
```

A Person cannot have a relationship to themselves.

---

# 22. Relationship Consistency

Where inverse relationships are maintained, they must remain logically consistent.

Examples:

```text
Parent ↔ Child
Spouse ↔ Spouse
```

The exact implementation strategy belongs in Database Architecture and domain services.

---

# 23. National ID

National ID is sensitive identity data.

It must be stored as:

```text
Text
```

not numeric data.

---

# 24. National ID Normalization

Before comparison, National ID must be normalized according to the approved identity format.

Normalization may include:

```text
Trim whitespace
Normalize allowed separators
Normalize digit representation where required
Validate allowed characters
```

The exact national format remains configurable until formally approved.

---

# 25. National ID Missing

A missing National ID must be represented as:

```text
NULL
```

or equivalent absence.

Do not create fake values such as:

```text
000000000
UNKNOWN123
NO-ID
```

merely to satisfy uniqueness.

---

# 26. National ID Duplicate

A duplicate normalized National ID must trigger:

```text
Duplicate Review
```

It must not automatically:

```text
Create another Person
Merge Persons
Delete a Person
```

---

# 27. National ID Change

Changing a verified National ID is a high-risk operation.

It requires:

```text
Explicit Permission
Validation
Reason
Audit
Duplicate Check
```

If submitted through Family Portal, it must use a controlled Change Request.

---

# 28. National ID Visibility

National ID visibility depends on authorization.

Users without permission should receive a masked representation where appropriate.

Example:

```text
804****32
```

Family membership alone does not automatically authorize viewing full National IDs of all members.

---

# 29. Duplicate Detection

Famboook uses three conceptual duplicate classifications:

```text
EXACT
PROBABLE
POSSIBLE
```

Duplicate detection supports human decision-making.

It does not replace it.

---

# 30. EXACT Duplicate

An EXACT match may include strong identity evidence such as:

```text
Same normalized National ID
```

An exact identity conflict should normally block creation/application until reviewed.

---

# 31. PROBABLE Duplicate

A PROBABLE match may be based on combinations such as:

```text
Same Full Name
+
Same Birth Date
+
Same Gender
```

or other approved matching rules.

---

# 32. POSSIBLE Duplicate

A POSSIBLE match may use weaker indicators such as:

```text
Similar Name
Mobile
Family Context
Relationships
Birth Information
```

It requires review before identity-sensitive operations proceed.

---

# 33. No Automatic Merge

Famboook must not automatically merge Person records.

A duplicate resolution decision requires authorized human review.

If a future merge feature is introduced, it must be:

```text
Controlled
Audited
Transactional
Recoverable
Permission-protected
```

---

# 34. Birth Date

Birth Date must not be in the future.

Incomplete or unknown birth information must not be replaced by fabricated dates.

---

# 35. Age

Age is derived from:

```text
birth_date
+
reference date
```

Age must not be maintained as a permanent canonical field.

---

# 36. Life Status

Initial conceptual values:

```text
ALIVE
DECEASED
UNKNOWN
```

Changing life status to `DECEASED` is a controlled registry event.

---

# 37. Death Date

Famboook V1 will support:

```text
death_date
```

as an optional canonical Person field.

Rules:

```text
death_date may be NULL.

death_date must not be in the future.

death_date should normally require:
life_status = DECEASED.

If life_status = ALIVE:
death_date must normally be NULL.

Death Date must not precede Birth Date.

A reported Death Date is not canonical until verified/approved according to workflow.
```

Where the exact death date is unknown, the system must not invent one.

---

# 38. Recording Death

Death is not deletion.

A confirmed death operation may:

```text
Update life_status
Set death_date if known
Record evidence/source
Audit change
Trigger Household Head review if applicable
Trigger Family Portal access review
```

The Person remains searchable according to permissions and reporting rules.

---

# 39. Residence

Residence is historical data.

A Family may have multiple historical residence records.

Normally:

```text
Maximum one current residence
```

---

# 40. Residence Change

A residence update should:

```text
Close previous current residence
Create new current residence
Preserve old record
Audit operation
```

It must not simply overwrite historical residence.

---

# 41. Displacement

Displacement information must preserve history where available.

A Family may experience multiple displacement events over time.

Current displacement state must not destroy previous displacement information.

---

# 42. Health Data

Health data belongs primarily to Persons.

Health data is:

```text
RESTRICTED
```

Access must be permission-controlled.

---

# 43. Health Conditions

A Person may have:

```text
Zero
One
Many
```

health conditions.

Health conditions must not be stored as comma-separated values in a single field.

---

# 44. Disability Data

A Person may have multiple disability records.

Disability information is:

```text
RESTRICTED
```

and must be permission-controlled.

---

# 45. Pregnancy and Breastfeeding

Pregnancy and breastfeeding are time-sensitive observations.

They must not be treated as permanent identity characteristics.

Where historical accuracy is required, they should be associated with an assessment/update context.

---

# 46. Education

Education information may change over time.

Historical education records should be preserved where operationally useful.

Only one record should normally be considered current for the same education context unless explicitly supported.

---

# 47. Employment

Employment information may change over time.

Historical employment information should be preserved.

Income data should only be collected where there is an approved operational purpose.

---

# 48. Documents

Documents may belong to:

```text
Family
Person
Change Request
```

A document must have a valid business owner/context.

---

# 49. Document Availability vs Verification

These concepts are different:

```text
Available
Uploaded
Verified
```

A document can be:

```text
Available but not uploaded
Uploaded but not verified
Verified after authorized review
```

---

# 50. Private Document Storage

Sensitive documents must not be stored in a publicly accessible directory.

Access must pass through:

```text
Authentication
Authorization
Document Permission
Business Scope
```

---

# 51. Family-Uploaded Documents

A document uploaded through Family Portal must be considered:

```text
UNVERIFIED
```

until reviewed by authorized staff.

Upload does not establish authenticity.

---

# 52. Assessments

An Assessment represents point-in-time information.

A Family may have multiple Assessments.

An Assessment must not overwrite permanent registry identity merely because it contains newer submitted information.

---

# 53. Assessment History

Historical Assessments must be preserved.

Examples:

```text
Initial Registration
Verification
Follow-Up
Needs Assessment
Emergency Update
```

---

# 54. Paper Forms

Paper forms are source records.

They do not define database limits.

Example:

```text
Paper Form has 10 child rows
```

must not produce:

```text
Maximum 10 children in system
```

---

# 55. Paper Form Traceability

Where paper sources exist, Famboook should preserve sufficient traceability such as:

```text
Paper Form Number
Source File
Page Count
Entered By
Reviewed By
Workflow Status
```

---

# 56. Multiple Paper Pages

A source form may consist of multiple pages/files.

The system must not assume:

```text
One Family
=
One physical page
```

---

# 57. Data Entry Draft

A Draft may be incomplete.

Required submission-level validation is not necessarily required while the user is still drafting.

---

# 58. Data Entry Submission

Moving from Draft to submission requires stronger validation.

Example:

```text
DRAFT
→
DATA_ENTRY_COMPLETED
```

may require:

```text
Family identity
Household Head
Required Person information
Required membership
Required source references
```

according to workflow rules.

---

# 59. Independent Review

Where operational capacity allows, the user who entered data should not be the same user who independently verifies it.

Baseline principle:

```text
Data Entry
≠
Independent Verification
```

Exceptions, if required operationally, must be explicit and auditable.

---

# 60. Returned for Correction

A Reviewer may return a submission.

Return must include:

```text
Reason
Actor
Timestamp
```

The previous workflow history remains preserved.

---

# 61. Correction and Resubmission

After correction:

```text
RETURNED_FOR_CORRECTION
      ↓
CORRECTED
      ↓
UNDER_REVIEW
```

The record is reviewed again.

---

# 62. Verification

Verification means an authorized Reviewer has confirmed the data according to the approved verification procedure.

Verification does not automatically imply final Approval where Approval is separately required.

---

# 63. Approval

Approval is a separate controlled action where required.

Approval authority is defined through permissions.

The system must not assume:

```text
Reviewer
=
Approver
```

unless explicitly configured.

---

# 64. Approved Record Modification

Approved registry records may still change because reality changes.

Examples:

```text
New Residence
Marriage
Death
Newborn
Employment Change
```

These are legitimate updates.

They must not be confused with:

```text
Correction of previously incorrect data
```

---

# 65. Correction vs Real-World Update

Famboook distinguishes:

```text
CORRECTION
```

from:

```text
REAL-WORLD UPDATE
```

Example:

```text
Wrong Birth Date entered
→ CORRECTION
```

```text
Family moved to new residence
→ REAL-WORLD UPDATE
```

This distinction should be preserved in audit/source metadata where relevant.

---

# 66. Needs

A Need represents an identified unmet or partially met requirement.

A Need is not an Assistance record.

---

# 67. Need Verification

A Need may require verification before becoming operationally active.

Example lifecycle:

```text
IDENTIFIED
→
VERIFIED
→
ACTIVE
```

---

# 68. Assistance

Assistance represents something actually provided/received.

Examples:

```text
Cash
Food
Medication
Shelter Support
Education Support
```

---

# 69. Assistance Does Not Automatically Close Need

Recording Assistance must not automatically set:

```text
Need = MET
```

unless an authorized user explicitly determines that the Need has been met according to approved rules.

---

# 70. No Automatic Eligibility Decision

Famboook may store information relevant to assistance decisions.

It must not automatically determine eligibility merely from sensitive Person/Family attributes unless a separately approved policy explicitly defines such rules.

V1 does not include automated eligibility decisions.

---

# 71. Case Notes

Case Notes are append-oriented.

Each note must preserve:

```text
Author
Timestamp
Content
Confidentiality
```

Normal users must not silently overwrite another user's historical note.

---

# 72. Confidential Notes

Confidential notes require explicit permission.

Family Users must not access internal confidential Case Notes.

Household Head status does not override this restriction.

---

# 73. Search

Search results must respect the same authorization rules as direct record access.

A user must not gain access to restricted information simply because it appears in search results.

---

# 74. Search Sensitive Fields

Searching by:

```text
National ID
Mobile
```

may require additional permission.

Results should expose only fields the user is authorized to view.

---

# 75. Reporting

Reports must use canonical data unless the report explicitly targets:

```text
Pending Requests
Draft Data
Workflow Queues
Assessment History
```

Reports must clearly distinguish official registry data from pending proposed changes.

---

# 76. Report Authorization

A user's report access must not bypass:

```text
Data Scope
Sensitive Data Permission
Family Scope
Field Restrictions
```

---

# 77. Derived Statistics

Statistics such as:

```text
Family Size
Children Count
Male/Female Count
Persons Under 5
Persons with Disability
Persons with Chronic Conditions
```

must normally be calculated from canonical records.

---

# 78. Audit

Critical changes must be audited.

Examples:

```text
Person Created
Family Created
National ID Changed
Household Head Changed
Person Transferred
Death Recorded
Residence Changed
Document Verified
Form Verified
Form Approved
Need Verified
Assistance Recorded
Family User Activated
Change Request Applied
```

---

# 79. Audit Immutability

Normal application users must not:

```text
Edit Audit Logs
Delete Audit Logs
```

Administrative technical access must be tightly controlled.

---

# 80. Soft Deletion

Soft deletion/archiving may be used where appropriate.

It must not replace domain history.

Example:

```text
Person moves to another Family
```

must use membership history, not soft-delete Person.

---

# 81. Null vs False

The system must distinguish:

```text
FALSE
```

from:

```text
UNKNOWN / NOT RECORDED
```

where business meaning differs.

Example:

```text
has_disability = FALSE
```

means known not to have a recorded disability.

```text
has_disability = NULL
```

may mean not assessed.

---

# 82. Unknown vs Not Applicable

Where relevant:

```text
UNKNOWN
NOT_APPLICABLE
NOT_RECORDED
```

should not be treated as identical.

---

# 83. Concurrency

Critical operations must protect against concurrent conflicting updates.

Examples:

```text
Two users assigning different Household Heads

Two reviewers applying the same Change Request

Two transfers for the same Person

Two users creating a Person with the same National ID
```

---

# 84. Imports

Imported data is not trusted merely because it comes from a file.

Imports require:

```text
Validation
Normalization
Duplicate Detection
Source Traceability
Error Reporting
Authorization
```

---

# 85. Import Application

Imports that modify canonical data should use the same domain rules as interactive operations.

Import must not bypass:

```text
Membership Rules
Duplicate Rules
Identity Rules
Required Constraints
```

---

# 86. Exports

Exports require explicit authorization.

Sensitive fields must be excluded unless the user has corresponding permission.

---

# 87. Export Minimum Necessary Principle

Exports should contain only the data required for their approved purpose.

A general report permission must not automatically imply unrestricted access to:

```text
National IDs
Health
Disability
Documents
Confidential Notes
```

---

# 88. Reference Data

Reference values should use stable codes.

Example:

```text
code = MARRIED
```

The display label may change without changing historical meaning.

---

# 89. Reference Data Deactivation

Reference values already used in historical data should normally be:

```text
Deactivated
```

rather than deleted.

---

# 90. Family Portal

The Family Portal is an authenticated self-service interface.

It is not a public registration system.

---

# 91. FAMILY_USER

The external authenticated role is:

```text
FAMILY_USER
```

It represents a verified Person authorized to access permitted Family information.

In V1, this user will normally be the current Household Head.

The architecture must not permanently equate:

```text
FAMILY_USER = HOUSEHOLD_HEAD
```

because future authorized representatives may be supported.

---

# 92. User Is Not Person

Authentication identity and registry identity are separate.

```text
User
≠
Person
```

A Family User must have an approved link to a Person.

A Person does not automatically receive an account.

---

# 93. User-Person Link

Family Portal access requires an explicit approved:

```text
User ↔ Person
```

link.

The link must have lifecycle/status information.

Example:

```text
PENDING_VERIFICATION
VERIFIED
ACTIVE
SUSPENDED
ENDED
```

---

# 94. Account Creation

Creating a Person must not automatically create a User account.

Creating a Family must not automatically create a Family Portal account.

Account activation is a separate security process.

---

# 95. Family User Identity Verification

Before Family Portal activation, the system must verify that the account belongs to the intended Person.

The exact mechanism remains subject to security design.

Possible factors may include:

```text
Staff Verification
National ID
Mobile
OTP
Verification Questions
Combination
```

Knowledge of Family Code alone is insufficient.

---

# 96. Family User Authorization

Family Portal authorization must consider:

```text
User status
+
User-Person Link status
+
Person status
+
Current Family Membership
+
Approved Family access policy
+
Requested resource
```

Authentication alone is insufficient.

---

# 97. Family Scope

A Family User may access only the Family/Families explicitly authorized through approved rules.

They must not browse:

```text
Other Families
Global Person Registry
Internal Staff Queues
Audit Logs
Internal Reports
```

---

# 98. Household Head Scope

If V1 access is limited to current Household Heads, Family-wide access must be dynamically tied to the current approved Household Head membership.

It must not depend only on a permanently stored:

```text
user.family_id
```

value.

---

# 99. Household Head Change and Portal Access

A Household Head change must trigger Family Portal authorization review.

The previous Head must not silently retain Family-wide privileges merely because they once held that role.

The new Head must not automatically gain an account without the approved activation process.

---

# 100. Person Transfer and Portal Access

When a Person with a Family User account changes Family membership:

```text
Current Portal Scope
```

must be reevaluated.

Historical membership must not grant unintended current access.

---

# 101. Family User Suspension

A Family User account or User-Person link may be suspended where required.

Suspension must not delete:

```text
Person
Family
Membership History
Change Requests
```

It affects authentication/authorization only.

---

# 102. Family User Access Termination

Ending Family Portal access must preserve:

```text
Who ended access
When
Reason
Historical link
```

The User-Person link should be ended/suspended rather than silently deleted.

---

# 103. Family Portal Data Visibility

A Family User must not automatically see every field stored for a Family or Person.

Visibility must be determined by:

```text
Permission
Relationship
Data Classification
Person Scope
Field Sensitivity
```

---

# 104. Family Portal Member Visibility

V1 may display basic information about Family members.

However:

```text
Same Family
≠
Unlimited access to all member information
```

Particularly sensitive areas include:

```text
National ID
Health
Disability
Documents
Confidential Notes
Adult Member Information
```

Detailed permissions are defined in `06-PERMISSIONS.md`.

---

# 105. Family User Self Data

A Family User may have broader visibility into their own permitted Person profile than into another adult member's restricted information.

This supports future:

```text
SELF_ONLY
```

authorization.

---

# 106. No Direct Registry CRUD

Family Users must not receive unrestricted CRUD access to canonical registry entities.

Prohibited architecture:

```text
Family Portal
      ↓
Direct update
      ↓
persons / families / family_memberships
```

Required architecture:

```text
Family Portal
      ↓
Change Request
      ↓
Review
      ↓
Approval
      ↓
Controlled Domain Action
      ↓
Canonical Registry
```

---

# 107. Change Request

A Family-submitted proposed registry modification must be represented as a:

```text
Change Request
```

until successfully applied.

---

# 108. Change Request Code

Each Change Request receives a unique permanent business identifier.

Recommended format:

```text
CRQ-000001
```

The Request Code must not be reused.

---

# 109. Change Request Family

Every Change Request must belong to a Family.

A request may additionally reference an existing Person.

---

# 110. Change Request Submitter

A Family Change Request must record the authenticated submitting User.

Submission must not be anonymous.

---

# 111. Change Request Ownership Validation

A Family User may submit a Change Request only for a Family they are currently authorized to act for.

Knowing a:

```text
family_id
person_id
request_code
```

must not bypass authorization.

---

# 112. Change Request Types

Initial V1 types include:

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

Each type requires specific validation.

---

# 113. Type-Specific Allowed Fields

A Change Request Type must define which proposed fields are permitted.

Example:

```text
CONTACT_UPDATE
```

may permit:

```text
mobile
alternate_mobile
```

It must not permit arbitrary submission of:

```text
life_status
national_id
household_head
```

through the same payload.

---

# 114. Change Request Validation

Validation must occur before submission.

Validation includes:

```text
User authorization
Family scope
Request type
Allowed fields
Required fields
Data types
Normalization
File validation
Business constraints
```

Some domain validations may also be repeated at application time because registry state may have changed after submission.

---

# 115. Change Request Draft

A Family User may prepare an incomplete:

```text
DRAFT
```

where the UI supports saved drafts.

A Draft does not affect the registry.

---

# 116. Change Request Submission

On submission:

```text
DRAFT
→
SUBMITTED
```

The request becomes part of the operational review queue.

The canonical registry remains unchanged.

---

# 117. Change Request Review

Authorized staff reviews the request.

The Family User must not review/approve their own request as an internal reviewer.

---

# 118. Clarification

If information is insufficient:

```text
UNDER_REVIEW
→
RETURNED_FOR_CLARIFICATION
```

A reason must be provided.

---

# 119. Resubmission

After clarification:

```text
RETURNED_FOR_CLARIFICATION
→
RESUBMITTED
→
UNDER_REVIEW
```

Previous request history must remain intact.

---

# 120. Change Request Rejection

An authorized Reviewer/Approver may reject a request according to permissions.

A rejection requires:

```text
Reason
Actor
Timestamp
```

Rejection must not modify the official registry.

---

# 121. Change Request Approval

Approval means:

```text
The requested change is authorized to proceed to application.
```

Approval does not itself prove that the registry modification has successfully occurred.

---

# 122. APPROVED vs APPLIED

This distinction is mandatory.

```text
APPROVED
=
Business approval completed.
```

```text
APPLIED
=
Approved domain change successfully committed to canonical registry.
```

Therefore:

```text
APPROVED
≠
APPLIED
```

---

# 123. Applying Change Requests

Approved requests must be applied through controlled domain operations.

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

---

# 124. Application Revalidation

Before applying an approved request, the system must revalidate critical assumptions.

Example:

A Household Head Change Request was approved, but before application:

```text
Proposed Head moved to another Family.
```

The application must not blindly execute stale assumptions.

---

# 125. Application Transaction

Applying a Change Request must be transactional.

Conceptually:

```text
BEGIN

Lock/validate Change Request
Confirm APPROVED state
Revalidate current registry
Execute domain action
Record audit
Record workflow event
Mark request APPLIED

COMMIT
```

If the registry change fails:

```text
ROLLBACK
```

The request must not be marked `APPLIED`.

---

# 126. Application Idempotency

The same Change Request must not be applied twice.

The system must protect against:

```text
Double Click
Retry
Concurrent Worker
Repeated API Request
```

An already `APPLIED` request cannot be applied again.

---

# 127. Application Failure

If application fails after approval:

```text
Do not falsely mark APPLIED.
```

The system should preserve:

```text
APPROVED
```

or use an explicit controlled failure/retry mechanism defined in Workflow Architecture.

The exact technical state will be finalized in `05-WORKFLOWS.md`.

---

# 128. Change Request Modification

After submission, a Family User must not silently alter the original submitted request while it is being reviewed.

Clarifications/resubmissions must preserve traceability.

---

# 129. Change Request History

State transitions must be recorded through:

```text
workflow_events
```

Examples:

```text
DRAFT → SUBMITTED

SUBMITTED → UNDER_REVIEW

UNDER_REVIEW → RETURNED_FOR_CLARIFICATION

RESUBMITTED → UNDER_REVIEW

UNDER_REVIEW → APPROVED

UNDER_REVIEW → REJECTED

APPROVED → APPLIED
```

---

# 130. Change Request Audit

Workflow history and Audit Logs serve different purposes.

```text
Workflow Event
=
Lifecycle transition
```

```text
Audit Log
=
Data/system modification
```

Applying a request may create both.

---

# 131. Change Request Supporting Documents

Supporting documents may be attached to a Change Request.

Examples:

```text
Birth Certificate
Death Certificate
Marriage Document
Identity Document
Residence Evidence
```

---

# 132. Supporting Document Verification

A supporting document uploaded by a Family User remains:

```text
UNVERIFIED
```

until an authorized staff user verifies it.

---

# 133. Document Requirement

Some Change Request Types may require supporting documents.

Example:

```text
DEATH_REPORT
```

may require evidence according to approved operational policy.

Whether a document is mandatory for each request type must be configurable or code-defined according to approved rules.

---

# 134. Add Family Member Request

A Family User may request:

```text
ADD_FAMILY_MEMBER
```

The system must not immediately create a canonical Person.

---

# 135. Add Member Duplicate Check

Before creating a new Person from an approved Add Member request:

```text
Search existing Person Registry
Run duplicate detection
Review potential matches
```

If the Person already exists:

```text
Reuse existing Person
+
Create appropriate Family Membership
```

where business rules allow.

---

# 136. Birth Report

A `BIRTH_REPORT` may result in creation of a new Person only after:

```text
Validation
Duplicate Review
Required Evidence Review
Approval
```

The Person receives a normal permanent Person Code.

---

# 137. Death Report

A `DEATH_REPORT` does not immediately modify:

```text
life_status
death_date
```

Submission produces a Change Request.

After approved verification/application:

```text
life_status = DECEASED

death_date = confirmed date if known
```

---

# 138. Death Report for Household Head

If the deceased Person is the current Household Head:

```text
Record Death
+
Trigger Household Head Review
+
Review Family Portal Authorization
```

These operations must not leave the Family indefinitely in an invalid state without operational visibility.

---

# 139. Marriage Update

Marriage-related requests must reuse existing Person identities where possible.

Marriage must not automatically create duplicate spouse Persons.

Duplicate search and Person relationships must be considered.

---

# 140. Membership Change Request

A Family User may request a household membership change.

They may not directly:

```text
Transfer Person
End Membership
Create Membership
```

The approved request invokes controlled membership actions.

---

# 141. Household Head Change Request

A Family User may request a Household Head change.

They cannot directly set:

```text
is_household_head = true
```

The approved operation must enforce all Household Head invariants.

---

# 142. Residence Update Request

A Family-submitted residence update must not overwrite the current residence immediately.

After approval/application:

```text
Close old current residence
Create new current residence
Preserve history
```

---

# 143. Contact Update Request

Contact updates are lower risk than identity changes but remain controlled in V1.

Default V1:

```text
Family User submits request
Staff reviews
Approved change is applied
```

Future simplified processing may be approved separately.

---

# 144. Person Correction Request

A Person correction may include sensitive identity data.

High-risk corrections require stronger review.

Examples:

```text
Full Name
Birth Date
National ID
```

The original value must remain auditable.

---

# 145. Request Risk Levels

Change Request Types may be classified:

```text
LOW
MEDIUM
HIGH
```

Risk level may affect:

```text
Required evidence
Required permission
Review steps
Approval authority
```

It must not by itself bypass workflow.

---

# 146. Family User Cannot Approve

A Family User cannot:

```text
Verify
Approve
Reject
Apply
```

their own Family Change Request through Family Portal privileges.

---

# 147. Staff Separation of Duties

Where practical:

```text
Family Request Submitter
≠
Reviewer
```

and for high-risk operations:

```text
Reviewer
≠
Final Approver
```

where the approved organizational workflow requires separate approval.

---

# 148. Family User Notifications

Family Users may receive notifications about their requests.

Examples:

```text
Submitted
Returned for Clarification
Approved
Rejected
Applied
```

Notifications do not replace workflow records.

---

# 149. Notification Privacy

Notifications must minimize sensitive content.

Preferred:

```text
Your request CRQ-000101 was approved.
```

Avoid unnecessary exposure such as:

```text
Full National ID
Medical Diagnosis
Confidential Case Information
```

---

# 150. Notification Delivery

V1 may begin with:

```text
In-App Notifications
```

Additional channels may later include:

```text
SMS
Email
Approved Messaging Channels
```

Channel availability does not alter the underlying notification event.

---

# 151. Family Portal Security

Family Portal input is external user input.

It must be treated as untrusted.

Required controls include:

```text
Authentication
Authorization
Validation
CSRF protection where applicable
Rate limiting where appropriate
Secure sessions
File validation
Private storage
Audit
```

---

# 152. Object-Level Authorization

Every Family Portal request must verify access to the requested object.

Example:

A logged-in Family User changing URL:

```text
/families/510
```

to:

```text
/families/511
```

must not gain access to Family 511.

Authorization must be enforced server-side.

---

# 153. Field-Level Authorization

Object access does not automatically imply access to every field.

Example:

A Family User may view a Family member's:

```text
Name
Relationship
Age/Birth information as permitted
```

without being allowed to view:

```text
Full National ID
Health Conditions
Confidential Notes
```

---

# 154. Family Portal API

Any API used by Family Portal must enforce the same:

```text
Authorization
Scope
Validation
Business Rules
```

as server-rendered interfaces.

A frontend application must never be considered the security boundary.

---

# 155. Family Portal Documents

Family Users may only upload/download documents within authorized scope.

A file identifier or URL must not grant access by itself.

---

# 156. Family Portal Search

Family Users must not receive global registry search.

Family Portal may provide search/filter only within their authorized Family context where needed.

---

# 157. Family Portal Reports

Family Users do not receive general Staff reporting permissions.

Any Family-facing summary must be designed as a dedicated authorized view.

---

# 158. Family Portal Audit Visibility

Family Users do not receive access to internal Audit Logs.

They may receive user-friendly request history derived from approved workflow information.

---

# 159. Internal Review Notes

Internal review notes are:

```text
STAFF_ONLY
```

unless explicitly written as a message intended for the Family User.

Do not expose internal notes through generic serialization.

---

# 160. Clarification Message

When returning a request, the Reviewer should provide a Family-visible clarification reason separately from confidential internal review notes where needed.

This prevents accidental exposure of internal information.

---

# 161. Sensitive Adult Member Data

Household Head status does not automatically authorize unrestricted access to sensitive data belonging to another adult member.

The exact V1 visibility policy must be explicitly approved in Permissions.

Until approved:

```text
Deny by default
```

for sensitive adult-member fields.

---

# 162. Minor Member Data

Access rules for children's sensitive information must be explicitly defined.

Being Household Head may be relevant to authorization but must not be assumed to grant unrestricted access without policy.

---

# 163. Family Portal Health Data

Health/disability data must remain restricted.

Family Portal exposure requires explicit approved permission rules.

Default:

```text
Do not expose detailed health/disability data merely because the user is Household Head.
```

---

# 164. Family Portal National ID

Default Family Portal policy:

```text
Do not expose full National IDs of other members unless explicitly authorized.
```

Self National ID may be:

```text
Masked
```

or displayed according to the final approved security policy.

---

# 165. Family Portal Assistance Data

Family Users may eventually view selected Assistance information.

This does not imply access to:

```text
Internal eligibility notes
Provider internal notes
Staff comments
Sensitive assessment information
```

---

# 166. Family Portal Needs Data

Selected Need information may be exposed.

Internal verification notes and sensitive classification remain staff-controlled.

---

# 167. Account Recovery

Family User account recovery must not rely only on easily discoverable Family information.

The exact process requires security design.

---

# 168. Credential Security

Passwords must never be stored in plain text.

Use Laravel's supported secure password hashing mechanisms.

Sensitive tokens must follow secure storage/expiration practices.

---

# 169. Session Revocation

Suspending or ending Family User access should invalidate or prevent continued unauthorized sessions according to the authentication implementation.

---

# 170. Real Data in Development

Real Family registry data must not be committed to Git.

Development and automated tests should use:

```text
Fictional
Synthetic
Anonymized
```

data.

---

# 171. Real Documents in Git

Identity documents, health reports, Family forms, or other real sensitive files must not be stored in the Git repository.

---

# 172. Production Exports

Production exports containing sensitive data must be treated as sensitive artifacts.

They require:

```text
Authorization
Controlled storage
Minimum necessary fields
Audit
Appropriate retention/deletion
```

---

# 173. Workflow Events

Workflow-controlled entities must preserve state transitions.

Examples:

```text
FormSubmission
Assessment
FamilyNeed
ChangeRequest
```

Each event should record:

```text
From Status
To Status
Action
Actor
Reason where applicable
Timestamp
Metadata where required
```

---

# 174. Workflow State Validity

Permission alone is not sufficient to perform a workflow transition.

Example:

A user may have:

```text
change_requests.approve
```

but cannot approve a request already:

```text
REJECTED
```

unless a specific reopening workflow exists.

Authorization and workflow validity are separate checks.

---

# 175. Database Constraints

Where feasible, critical invariants should have database-level protection.

Examples:

```text
Unique Family Code
Unique Person Code
Unique Request Code
Maximum one active primary membership per Person
Maximum one active Household Head per Family
Maximum one current Family residence
Valid membership dates
Person cannot relate to self
```

---

# 176. Backend Constraints

Rules difficult to express safely as database constraints must be enforced through domain/backend logic.

Examples:

```text
Family User scope
Change Request allowed fields
Duplicate review
Approval authority
Application revalidation
Document requirements
```

---

# 177. Frontend Validation

Frontend validation exists for usability.

It is not authoritative.

All critical validation must be repeated server-side.

---

# 178. Data Change Source

Where possible, important registry modifications should retain source context.

Examples:

```text
STAFF_ENTRY
PAPER_FORM
ASSESSMENT
CHANGE_REQUEST
IMPORT
CORRECTION
```

An applied Family Change Request should be traceable back to its:

```text
request_code
```

---

# 179. Approved Change Request Traceability

After application, authorized staff should be able to determine:

```text
Which Change Request caused this change?

Who submitted it?

Who reviewed it?

Who approved it?

Who applied it?

When?

What was requested?

What was changed?
```

---

# 180. No Silent Changes

Critical registry changes must not occur without traceability.

Examples:

```text
National ID
Household Head
Membership
Death
Residence
Verified Documents
```

---

# 181. Business Rule Invariants

The following invariants are mandatory.

```text
INV-001
Every Family has a unique permanent Family Code.

INV-002
Every Person has a unique permanent Person Code.

INV-003
Person identity is independent from Family membership.

INV-004
A Person has at most one active primary Family membership in V1.

INV-005
An active Family has at most one active Household Head membership.

INV-006
A Family has at most one current residence.

INV-007
A Person cannot have a relationship to themselves.

INV-008
National ID duplicates never trigger automatic Person merge.

INV-009
Death never deletes Person identity.

INV-010
Derived Family statistics are not canonical manually maintained data.

INV-011
A User is not automatically a Person.

INV-012
A Person is not automatically a User.

INV-013
Family Portal access requires an approved User-Person link.

INV-014
Family Portal access must respect current Family authorization.

INV-015
Family Users cannot directly modify canonical registry records.

INV-016
Family-submitted registry modifications use Change Requests.

INV-017
A submitted Change Request does not modify canonical registry data.

INV-018
APPROVED and APPLIED are distinct Change Request states.

INV-019
Only a successfully committed registry change may mark a request APPLIED.

INV-020
A Change Request cannot be applied twice.

INV-021
Family User uploads are not automatically verified documents.

INV-022
Add Member/Birth requests must not bypass duplicate detection.

INV-023
Household Head changes must trigger Family Portal access review.

INV-024
Person transfers must trigger relevant Family Portal scope review.

INV-025
Family membership does not automatically grant access to all sensitive Person data.

INV-026
Internal Case Notes are not visible to Family Users by default.

INV-027
Workflow history is append-oriented and preserved.

INV-028
Audit history cannot be modified by normal application users.

INV-029
Death Date cannot precede Birth Date.

INV-030
A confirmed canonical Death Date normally requires DECEASED life status.
```

---

# 182. Approved Business Decisions V1

### BD-001

Family is an independent persistent entity.

### BD-002

Person is an independent persistent entity.

### BD-003

Family Membership is historical.

### BD-004

Paper forms are sources, not the database model.

### BD-005

National ID is stored as text.

### BD-006

Missing National IDs do not use fake placeholders.

### BD-007

Duplicate Persons are never automatically merged.

### BD-008

Residence history is preserved.

### BD-009

Health and disability information is Restricted.

### BD-010

Assessments are separate from permanent registry identity.

### BD-011

Needs and Assistance are separate concepts.

### BD-012

Case Notes are append-oriented.

### BD-013

Approved records remain updateable through controlled historical processes.

### BD-014

Critical actions are audited.

### BD-015

Hard deletion is not part of normal registry operations.

### BD-016

Reference data uses stable codes.

### BD-017

Critical rules are enforced server-side and, where appropriate, at database level.

### BD-018

Real sensitive Family data is not stored in Git.

### BD-019

Famboook V1 includes authenticated Family self-service.

### BD-020

The Family Portal external role is `FAMILY_USER`.

### BD-021

User and Person are separate entities.

### BD-022

Family User access requires an explicit verified User-Person relationship.

### BD-023

Creating a Person does not automatically create a Family User account.

### BD-024

Family Users do not receive direct CRUD access to canonical registry data.

### BD-025

Family-submitted registry changes use Change Requests.

### BD-026

Change Request `APPROVED` and `APPLIED` remain separate states.

### BD-027

Approved Change Requests are applied through normal domain actions.

### BD-028

Applying a Change Request is transactional and idempotent.

### BD-029

Family-submitted supporting documents are unverified until staff verification.

### BD-030

Family Portal authorization must be reviewed after relevant Household Head or membership changes.

### BD-031

Family membership does not automatically grant unrestricted access to sensitive member information.

### BD-032

Family Users cannot access internal confidential Case Notes.

### BD-033

Family User notifications minimize sensitive information.

### BD-034

Canonical Person records support an optional `death_date`.

### BD-035

A death report is a Change Request until verified and applied.

### BD-036

Public anonymous self-registration remains outside V1.

---

# 183. Pending Business Decisions

### PBD-001 — National ID Format

Confirm exact:

```text
Length
Character rules
Normalization
Validation
```

---

### PBD-002 — National ID Storage Security

Determine final strategy:

```text
Encryption
Search Hash
Masking
Indexing
```

---

### PBD-003 — Final Reference Vocabularies

Approve values for:

```text
Relationships
Marital Status
Housing
Tenure
Health
Disability
Education
Employment
Needs
Assistance
Documents
```

---

### PBD-004 — Approval Authority

Define exactly which role may perform final Approval for each workflow.

---

### PBD-005 — Review Assignment

Determine whether Reviewers:

```text
Pull from shared queue
```

or:

```text
Receive assigned records
```

or both.

---

### PBD-006 — Reverification

Determine which modifications to previously approved records require formal reverification.

---

### PBD-007 — Data Retention

Define retention rules for:

```text
Source Forms
Documents
Audit Logs
Workflow Events
Change Requests
Rejected Requests
Exports
Backups
```

---

### PBD-008 — Family User Eligibility

Confirm whether V1 Family Portal access is limited to:

```text
Current Household Head
```

or also supports:

```text
Authorized Representative
Guardian
Other Adult Member
```

---

### PBD-009 — Multiple Family Users

Determine whether multiple active Family User accounts may exist for one Family in V1.

---

### PBD-010 — Family User Identity Verification

Select the account activation verification mechanism.

Possible combination:

```text
Staff Verification
National ID
Mobile
OTP
Verification Questions
```

---

### PBD-011 — Low-Risk Direct Updates

Default V1:

```text
All Family-submitted registry changes require review.
```

Determine whether future low-risk updates may use simplified approval.

---

### PBD-012 — Sensitive Adult Member Visibility

Define exactly what a Household Head may see about another adult member.

Especially:

```text
National ID
Health
Disability
Documents
Contact Information
```

---

### PBD-013 — Child Data Visibility

Define Family User visibility into sensitive data of minor Family members.

---

### PBD-014 — Family Portal Health Visibility

Determine whether any health/disability details are exposed through V1 Family Portal.

Default security posture:

```text
Restricted unless explicitly approved.
```

---

### PBD-015 — Family Portal Document Downloads

Define which verified documents, if any, Family Users may download.

---

### PBD-016 — Needs and Assistance Visibility

Define exactly which Need/Assistance information is Family-visible.

---

### PBD-017 — Change Request Risk Matrix

Approve final risk level for each request type.

---

### PBD-018 — Change Request Evidence

Define required evidence/document rules per Change Request Type.

---

### PBD-019 — Application Failure State

Define whether a failed application remains:

```text
APPROVED
```

with failure metadata, or uses an explicit state such as:

```text
APPLICATION_FAILED
```

Recommendation should be finalized in `05-WORKFLOWS.md`.

---

### PBD-020 — Family User Account Recovery

Define secure account-recovery process.

---

### PBD-021 — Notifications

Define V1 notification channels:

```text
In-App
SMS
Email
Combination
```

---

### PBD-022 — Marriage History

Determine whether V1 requires:

```text
Current Marital Status
+
Person Relationships
```

only, or a dedicated marriage/life-event history model.

---

### PBD-023 — Death Evidence

Define the required verification/evidence rules for canonical death registration.

---

### PBD-024 — Unknown Death Date

Approved baseline:

```text
A Person may be DECEASED while death_date is NULL
```

when the exact date is unknown.

Determine whether approximate death dates need a future dedicated representation.

---

# 184. Business Rule Enforcement Priority

Rules should be enforced in this order where appropriate:

```text
1. Database Constraints
2. Domain / Application Services
3. Laravel Policies / Authorization
4. Request Validation
5. UI Validation
```

Not every rule belongs in every layer.

Example:

```text
Maximum one active Household Head
```

should have database protection where feasible.

While:

```text
Can this Family User view this adult member's health data?
```

belongs primarily to authorization/domain policy.

---

# 185. Required Database Synchronization

`04-DATABASE.md` must now incorporate:

```text
persons.death_date

user_person_links

change_request_types

change_requests

Change Request supporting documents

Change Request workflow_events

Family User notification implementation

Indexes

Constraints

ERD updates

Laravel relationships

Transaction boundaries
```

---

# 186. Required Workflow Synchronization

`05-WORKFLOWS.md` must incorporate:

```text
Family User Account Activation

Family User Suspension

Family Portal Authorization Review

Change Request Draft

Submission

Review

Return for Clarification

Resubmission

Approval

Rejection

Application

Application Failure

Idempotent Application

Add Member

Birth Report

Death Report

Membership Change

Household Head Change

Residence Update

Contact Update
```

---

# 187. Required Permissions Synchronization

`06-PERMISSIONS.md` must incorporate:

```text
FAMILY_USER role

User-Person access rules

Self scope

Family scope

Family member visibility

Sensitive fields

Change Request create/view

Change Request review

Change Request approve

Change Request apply

Supporting documents

Notifications

Family Portal Needs/Assistance visibility

Account activation/suspension
```

---

# 188. Architecture Rule

Staff Portal and Family Portal must not implement separate versions of the same business operation.

Example:

```text
Staff changes Household Head
```

and:

```text
Family Change Request approved for Household Head change
```

must ultimately invoke the same core domain operation:

```text
ChangeHouseholdHeadAction
```

This prevents business-rule divergence.

---

# 189. Document Synchronization Rule

The following documents must remain consistent:

```text
01-PRODUCT.md
02-DATA-DICTIONARY.md
03-BUSINESS-RULES.md
04-DATABASE.md
05-WORKFLOWS.md
06-PERMISSIONS.md
07-ROADMAP.md
```

If a core architectural decision changes in one document, dependent documents must be reviewed.

---

# 190. Document Status

```text
Project: Famboook
Document: Business Rules
Version: 1.1
Status: APPROVED
Date: 2026-09-22
```

---

# 191. Change Log

| Version | Date | Status | Description |
|---|---|---|---|
| 1.0 | 2026-09-22 | Approved | Initial Famboook business rules |
| 1.1 | 2026-09-22 | Approved | Added Family Portal, FAMILY_USER, User-Person authorization, Change Request rules, controlled application, supporting documents, Family User privacy/security, access reevaluation, and canonical death date rules |

---

# 192. Next Step

Documentation synchronization now proceeds as:

```text
01-PRODUCT.md                 UPDATED — v1.1
02-DATA-DICTIONARY.md         UPDATED — v1.1
03-BUSINESS-RULES.md          UPDATED — v1.1
        ↓
04-DATABASE.md                NEXT
        ↓
05-WORKFLOWS.md
        ↓
06-PERMISSIONS.md
        ↓
07-ROADMAP.md
```

The next document must translate these rules into physical PostgreSQL architecture for:

```text
persons.death_date

user_person_links

change_request_types

change_requests

documents.change_request_id

workflow_events integration

Family User notifications

Indexes

Constraints

Relationships

Migration Order

Transaction Boundaries
```