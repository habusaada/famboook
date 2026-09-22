# Famboook
## Workflows & State Transitions

**Document:** `05-WORKFLOWS.md`  
**Version:** 1.0  
**Status:** Approved  
**Last Updated:** 2026-09-22  
**Project:** Famboook — Family Registry & Case Management System

---

# 1. Purpose

This document defines the operational workflows and state transitions of Famboook V1.

It translates the approved:

- `01-PRODUCT.md`
- `02-DATA-DICTIONARY.md`
- `03-BUSINESS-RULES.md`
- `04-DATABASE.md`

into controlled operational processes.

This document defines:

- Form submission workflow.
- Family registration workflow.
- Verification workflow.
- Correction workflow.
- Approval workflow.
- Duplicate detection and resolution.
- Household-head changes.
- Person movement between families.
- Residence updates.
- Assessment workflow.
- Needs workflow.
- Assistance workflow.
- Document verification.
- Sensitive-data corrections.
- Archival behavior.

Permissions for performing each action are defined separately in:

`06-PERMISSIONS.md`

---

# 2. Workflow Principles

## WF-P01 — Explicit State

Important operational records MUST have an explicit state where lifecycle management is required.

---

## WF-P02 — Controlled Transitions

Users MUST NOT arbitrarily change workflow status values.

Transitions must occur through approved actions.

Example:

```text
DRAFT
   ↓
SUBMIT
   ↓
DATA_ENTRY_COMPLETED
```

rather than directly editing:

```text
status = APPROVED
```

---

## WF-P03 — Server-Side Enforcement

Critical workflow rules MUST be enforced by the backend.

Frontend restrictions alone are insufficient.

---

## WF-P04 — Auditability

Important transitions MUST generate an audit event.

---

## WF-P05 — Actor

Every user-driven transition MUST identify the acting user.

---

## WF-P06 — Timestamp

Important transitions MUST record when the action occurred.

---

## WF-P07 — Reason

Transitions involving rejection, correction, exceptional modification, or archival SHOULD require a reason.

---

## WF-P08 — Transaction Safety

Transitions that modify multiple related records MUST execute atomically where appropriate.

---

# 3. Primary Form Workflow

The primary registration workflow is:

```text
                     ┌─────────────┐
                     │    DRAFT    │
                     └──────┬──────┘
                            │
                         Submit
                            │
                            ▼
              ┌────────────────────────┐
              │ DATA_ENTRY_COMPLETED   │
              └────────────┬───────────┘
                           │
                      Send to Review
                           │
                           ▼
                 ┌──────────────────┐
                 │  UNDER_REVIEW    │
                 └───────┬──────────┘
                         │
             ┌───────────┴─────────────┐
             │                         │
             │ Return                  │ Verify
             ▼                         ▼
┌──────────────────────────┐    ┌──────────────┐
│ RETURNED_FOR_CORRECTION  │    │   VERIFIED   │
└────────────┬─────────────┘    └──────┬───────┘
             │                         │
          Correct                    Approve
             │                         │
             ▼                         ▼
      ┌─────────────┐          ┌──────────────┐
      │  CORRECTED  │          │   APPROVED   │
      └──────┬──────┘          └──────────────┘
             │
          Resubmit
             │
             ▼
      ┌───────────────┐
      │ UNDER_REVIEW  │
      └───────────────┘
```

---

# 4. Form Statuses

Approved V1 statuses:

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

These codes should remain stable.

Arabic display labels may be localized independently.

---

# 5. DRAFT

## Entry

A new form submission begins as:

```text
DRAFT
```

## Allowed Behavior

The Data Entry user may:

- Enter partial information.
- Save progress.
- Add family members.
- Edit information.
- Resume later.

Submission-level mandatory fields do not all need to be complete yet.

## Exit

Action:

```text
Submit
```

Target:

```text
DATA_ENTRY_COMPLETED
```

Submission validation MUST pass.

---

# 6. DATA_ENTRY_COMPLETED

This state means:

> Data entry has been completed but the record has not yet been independently verified.

The record should normally become read-only to the original data-entry operator except through an authorized correction process.

Next action:

```text
Send to Review
```

Target:

```text
UNDER_REVIEW
```

---

# 7. UNDER_REVIEW

The reviewer examines:

- Family data.
- Household head.
- Members.
- National IDs.
- Relationships.
- Residence.
- Health information.
- Disability information.
- Education.
- Employment.
- Needs.
- Documents.
- Source form.
- Duplicate warnings.

Reviewer actions:

```text
Verify
```

or:

```text
Return for Correction
```

---

# 8. RETURNED_FOR_CORRECTION

A reviewer MUST provide:

```text
return_reason
```

Preferably correction items should identify affected areas.

Example:

```text
Member PER-000125:
National ID differs from source form.

Residence:
Current displacement location is missing.
```

The record becomes editable according to correction permissions.

---

# 9. CORRECTED

After correcting the requested issues, the Data Entry user performs:

```text
Complete Correction
```

Status:

```text
CORRECTED
```

Then:

```text
Resubmit
```

returns it to:

```text
UNDER_REVIEW
```

Previous review and correction history MUST remain available.

---

# 10. VERIFIED

`VERIFIED` means an authorized reviewer has confirmed the record according to the verification process.

It does NOT necessarily mean final organizational approval.

Transition:

```text
VERIFIED
   ↓
Approve
   ↓
APPROVED
```

Only an authorized approver may perform this transition.

---

# 11. APPROVED

`APPROVED` represents the accepted operational record.

Approved records MUST NOT behave like editable drafts.

Important modifications require:

```text
Permission
+
Reason
+
Audit
```

where applicable.

Approval does not make a record permanently immutable.

Real-world family information may change and should be updated through controlled workflows.

---

# 12. ARCHIVED

Archiving removes a record from normal active operational workflows without destroying its history.

Archive reasons may include:

```text
DUPLICATE
CREATED_IN_ERROR
SUPERSEDED
CLOSED
OTHER
```

Archiving MUST NOT be equivalent to physical deletion.

---

# 13. Transition Matrix

| Current | Action | Next |
|---|---|---|
| DRAFT | Submit | DATA_ENTRY_COMPLETED |
| DATA_ENTRY_COMPLETED | Send to Review | UNDER_REVIEW |
| UNDER_REVIEW | Return | RETURNED_FOR_CORRECTION |
| RETURNED_FOR_CORRECTION | Complete Correction | CORRECTED |
| CORRECTED | Resubmit | UNDER_REVIEW |
| UNDER_REVIEW | Verify | VERIFIED |
| VERIFIED | Approve | APPROVED |
| Eligible state | Archive | ARCHIVED |

Invalid transitions MUST be rejected by the backend.

Example:

```text
DRAFT → APPROVED
```

is prohibited.

---

# 14. Family Registration Workflow

Creating a family should be treated as one logical operation.

```text
Start Registration
      ↓
Create Draft Family Context
      ↓
Register Household Head
      ↓
Check Person Duplicate
      ↓
Create / Reuse Person
      ↓
Create Membership
      ↓
Add Family Members
      ↓
Check Duplicates
      ↓
Add Residence
      ↓
Add Assessment/Form Data
      ↓
Review
      ↓
Submit
```

A transaction should be used where creation requires several dependent records to succeed together.

---

# 15. Existing Person During Registration

Before creating a new Person:

```text
Search / Duplicate Check
```

If no match exists:

```text
Create Person
```

If a possible match exists:

```text
Flag for Review
```

If the reviewer confirms an existing Person:

```text
Reuse Existing Person
```

Do NOT create another Person merely because the person appears on a new family form.

---

# 16. Duplicate Detection Workflow

```text
New / Edited Person
        ↓
Duplicate Check
        ↓
┌───────────────────────────────┐
│ Match found?                  │
└──────────────┬────────────────┘
               │
       ┌───────┴───────┐
       │               │
      NO              YES
       │               │
       ▼               ▼
   Continue       Create Alert
                       ↓
                Classify Match
                       ↓
             ┌─────────┼─────────┐
             │         │         │
           EXACT    PROBABLE   POSSIBLE
             │         │         │
             └─────────┼─────────┘
                       ↓
                 Human Review
```

---

# 17. Duplicate Review Outcomes

An authorized reviewer may decide:

```text
NOT_DUPLICATE
```

The records represent different people.

Or:

```text
SAME_PERSON
```

The records represent the same human identity.

Or:

```text
UNRESOLVED
```

Additional evidence is required.

---

# 18. Duplicate Resolution

Famboook MUST NOT automatically merge Person records.

When:

```text
SAME_PERSON
```

is confirmed, an authorized resolution process should determine:

- Canonical Person.
- Conflicting values.
- Memberships.
- Documents.
- Assessments.
- Notes.
- Needs.
- Assistance.
- Historical references.

The merge operation, if implemented in V1, MUST be:

```text
Controlled
Audited
Transactional
Recoverable where practical
```

The duplicate Person should normally be archived/superseded rather than silently deleted.

---

# 19. Exact National ID Conflict

If a newly entered National ID matches an existing verified Person:

```text
National ID
     ↓
Exact Existing Match
     ↓
BLOCK / REVIEW
```

The system SHOULD prevent casual creation of a second verified Person using the same National ID.

An authorized reviewer resolves the conflict.

---

# 20. Household Head Workflow

Initial household-head assignment:

```text
Family
   ↓
Select / Create Person
   ↓
Create Active Membership
   ↓
is_household_head = true
```

The database must enforce that a family cannot normally have multiple active household heads.

---

# 21. Change Household Head

Workflow:

```text
Open Family
    ↓
Change Household Head
    ↓
Select Existing Active Member
    ↓
Confirm Reason
    ↓
Validate
    ↓
Transaction
    ├── Old Head → false
    └── New Head → true
    ↓
Audit
```

The Person records remain unchanged.

---

# 22. Household Head Not Yet a Member

If the selected new household head is not currently a member:

```text
Select Person
     ↓
Validate Person
     ↓
Resolve Existing Active Membership
     ↓
Create / Transfer Membership
     ↓
Assign Household Head
```

This operation may require elevated permissions.

---

# 23. Household Head Death

Workflow:

```text
Mark Person as Deceased
        ↓
Is Household Head?
        │
   ┌────┴────┐
   │         │
  NO        YES
   │         │
   ▼         ▼
Continue   Flag Family
              ↓
       HEAD_REVIEW_REQUIRED
              ↓
        Select New Head
              ↓
          Audit Change
```

The deceased Person remains in historical records.

---

# 24. Move Person Between Families

A Person MUST NOT be recreated.

Workflow:

```text
PER-001825
    ↓
Move Household
    ↓
Select Destination Family
    ↓
Validate
    ↓
Close Current Membership
    ↓
Create New Membership
    ↓
Audit
```

Example:

```text
Old membership:
FAM-000100
ended_at = 2026-09-22
is_active = false

New membership:
FAM-000250
started_at = 2026-09-22
is_active = true
```

---

# 25. Person Move Transaction

The following should execute atomically:

```text
1. Lock relevant active membership.
2. Validate destination family.
3. End old membership.
4. Create new membership.
5. Apply relationship classification.
6. Audit the move.
```

If any critical operation fails:

```text
ROLLBACK
```

---

# 26. Marriage / New Family Workflow

An existing Person may establish a new household.

```text
Existing Person
      ↓
Create New Family
      ↓
Close Old Primary Membership
      ↓
Create Membership in New Family
      ↓
Assign Role
      ↓
Add Spouse / Members
      ↓
Audit
```

Person identity remains unchanged.

---

# 27. Residence Update Workflow

Do NOT overwrite the historical current residence blindly.

Workflow:

```text
Current Residence
       ↓
Update Residence
       ↓
Close Previous Record
is_current = false
to_date = change date
       ↓
Create New Residence
is_current = true
from_date = change date
       ↓
Audit
```

---

# 28. Displacement Workflow

When displacement occurs:

```text
Family
  ↓
Create / Update Residence Event
  ↓
is_displaced = true
  ↓
Record Displacement Information
```

Relevant information may include:

```text
displacement_date
displacement_location
displacement_reason
housing information
```

Returning home does not delete displacement history.

---

# 29. Assessment Workflow

Assessment lifecycle:

```text
DRAFT
  ↓
IN_PROGRESS
  ↓
COMPLETED
  ↓
UNDER_REVIEW
  ↓
VERIFIED
```

Depending on the assessment type, final approval may also be required.

Assessment workflow should remain conceptually separate from permanent Family status.

---

# 30. Assessment Creation

Workflow:

```text
Select Family
    ↓
Select Assessment Type
    ↓
Create Assessment
    ↓
Record Assessment Data
    ↓
Complete
    ↓
Review / Verify
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

# 31. Time-Sensitive Information

Information such as:

```text
Pregnancy
Breastfeeding
Employment status
Needs
Displacement situation
```

may change over time.

When collected through an assessment, historical assessment context SHOULD be preserved.

The current profile may be updated without destroying previous assessment evidence.

---

# 32. Need Workflow

Recommended V1 lifecycle:

```text
IDENTIFIED
    ↓
VERIFIED
    ↓
ACTIVE
    ↓
┌─────────────────┐
│                 │
▼                 ▼
PARTIALLY_MET     MET
│                 │
└────────┬────────┘
         ↓
       CLOSED
```

---

# 33. IDENTIFIED Need

A need has been reported or observed.

It has not necessarily been independently verified.

---

# 34. VERIFIED Need

An authorized user confirms that the need is valid according to operational rules.

---

# 35. ACTIVE Need

The need remains unresolved and operationally relevant.

---

# 36. PARTIALLY_MET Need

Some assistance has addressed the need, but it remains active.

---

# 37. MET Need

The identified need has been fulfilled according to the responsible user's assessment.

---

# 38. CLOSED Need

The need is no longer operationally active.

Closing the need does not delete its history.

---

# 39. Assistance Workflow

Assistance represents an actual assistance event.

Workflow:

```text
Select Family / Person
       ↓
Select Assistance Type
       ↓
Optional: Link Need
       ↓
Enter Provider / Date / Quantity
       ↓
Validate
       ↓
Record Assistance
       ↓
Optional: Update Need Status
       ↓
Audit
```

Creating Assistance MUST NOT automatically close a Need.

The user must explicitly determine whether the Need becomes:

```text
PARTIALLY_MET
```

or:

```text
MET
```

where applicable.

---

# 40. Assistance Without Existing Need

The system MAY record Assistance without an existing Need.

Example:

```text
External distribution received before Famboook assessment.
```

Therefore:

```text
assistance_records.need_id
```

is optional.

---

# 41. Document Workflow

Basic document lifecycle:

```text
MISSING
   ↓
AVAILABLE
   ↓
UPLOADED / RECORDED
   ↓
VERIFIED
```

Document metadata may exist without an uploaded file.

---

# 42. Document Verification

Workflow:

```text
Document
    ↓
Review
    ↓
┌──────────────┐
│ Valid?       │
└──────┬───────┘
       │
 ┌─────┴─────┐
 │           │
YES          NO
 │           │
 ▼           ▼
VERIFIED   REJECTED /
           CORRECTION REQUIRED
```

Document verification must record:

```text
verified_by
verified_at
```

---

# 43. Source Form Workflow

Where registration originates from paper:

```text
Receive Paper Form
      ↓
Assign / Record Form Number
      ↓
Create Form Submission
      ↓
Optional Scan Upload
      ↓
Data Entry
      ↓
Review Against Source
      ↓
Verification
      ↓
Approval
```

The digital record must remain traceable to its source form.

---

# 44. Additional Paper Pages

If the paper form has additional member pages:

```text
Main Form
   +
Additional Page 1
   +
Additional Page 2
```

they belong to the same logical submission where applicable.

The digital system does not inherit the paper row limit.

---

# 45. Sensitive Data Correction

Sensitive fields include at minimum:

```text
National ID
Identity data
Health data
Disability data
Sensitive documents
```

For significant corrections after verification:

```text
Request Change
     ↓
Check Permission
     ↓
Enter Reason
     ↓
Apply Change
     ↓
Audit Old/New Values
```

---

# 46. National ID Correction

For a verified Person:

```text
Current National ID
      ↓
Edit Requested
      ↓
Permission Check
      ↓
Duplicate Check
      ↓
Reason Required
      ↓
Update
      ↓
Audit
```

If the new ID matches another Person:

```text
STOP
↓
Duplicate Review
```

---

# 47. Approved Record Update

Real-world changes after approval are not necessarily errors.

Example:

```text
Family changes residence.
```

This should create a new valid historical event rather than "correcting" the old residence.

Distinguish:

```text
CORRECTION
```

from:

```text
REAL-WORLD UPDATE
```

---

# 48. Correction vs Update

### Correction

The previously entered value was incorrect.

Example:

```text
National ID typed incorrectly.
```

### Update

The previous value was correct at the time, but reality changed.

Example:

```text
Family moved to another location.
```

Corrections may modify a value with audit history.

Updates should normally preserve the previous historical state.

---

# 49. Case Note Workflow

Case notes are append-oriented:

```text
Family / Person
      ↓
Add Note
      ↓
Select Note Type
      ↓
Set Confidentiality
      ↓
Save
      ↓
Timeline
```

Existing notes should not normally be overwritten.

---

# 50. Confidential Notes

Before displaying a confidential note:

```text
User
 ↓
Permission Check
 ↓
Authorized?
 ├── YES → Display
 └── NO  → Hide
```

Search and exports must follow the same rule.

---

# 51. Family Archive Workflow

Archiving a family:

```text
Family
  ↓
Archive Request
  ↓
Check Permission
  ↓
Select Reason
  ↓
Validate Active Processes
  ↓
Archive
  ↓
Audit
```

Archiving MUST NOT delete:

- Persons.
- Membership history.
- Assessments.
- Assistance.
- Notes.
- Documents.
- Audit history.

---

# 52. Person Archive Workflow

A Person may be archived in exceptional circumstances such as confirmed duplicate or erroneous creation.

Workflow:

```text
Person
 ↓
Archive Request
 ↓
Check Dependencies
 ↓
Select Reason
 ↓
Resolve References
 ↓
Archive
 ↓
Audit
```

A Person MUST NOT be archived merely because they:

```text
Move
Marry
Leave a household
Die
```

---

# 53. Deceased Person Workflow

```text
Person
 ↓
Record Death
 ↓
life_status = DECEASED
 ↓
Record Date if Known
 ↓
Review Household Role
 ↓
Preserve History
 ↓
Audit
```

Death is a life-status change, not deletion.

---

# 54. Data Import Workflow

```text
Upload Import File
       ↓
Create Import Batch
       ↓
Validate Structure
       ↓
Validate Rows
       ↓
Duplicate Check
       ↓
Preview Results
       ↓
Authorized Confirmation
       ↓
Import Valid Rows
       ↓
Report Failed Rows
       ↓
Audit
```

No bulk import should silently bypass validation.

---

# 55. Import Batch States

Recommended:

```text
UPLOADED
VALIDATING
VALIDATED
READY
IMPORTING
COMPLETED
COMPLETED_WITH_ERRORS
FAILED
```

This becomes relevant when Excel/CSV import is implemented.

---

# 56. Export Workflow

```text
User Requests Export
       ↓
Permission Check
       ↓
Apply Record Scope
       ↓
Apply Field Restrictions
       ↓
Generate Export
       ↓
Audit Sensitive Export
```

Viewing data does not automatically grant export permission.

---

# 57. Search Workflow

```text
Search Input
    ↓
Normalize Search
    ↓
Apply User Scope
    ↓
Search Authorized Records
    ↓
Mask Restricted Fields
    ↓
Display Results
```

Search MUST NOT be used to bypass permissions.

---

# 58. Family Profile Workflow

The Family Profile is the primary operational workspace.

```text
Family
 │
 ├── Overview
 ├── Members
 ├── Residence
 ├── Health Summary
 ├── Education
 ├── Employment
 ├── Needs
 ├── Assistance
 ├── Assessments
 ├── Documents
 ├── Notes
 └── History
```

Actions available on each section depend on permissions and workflow state.

---

# 59. Review Queue

Reviewers should have a queue containing records such as:

```text
UNDER_REVIEW
```

Potential queue information:

```text
Family Code
Household Head
Form Number
Submitted By
Submitted At
Duplicate Warning
Priority
```

---

# 60. Correction Queue

Data Entry users should be able to see records assigned/returned to them:

```text
RETURNED_FOR_CORRECTION
```

including:

```text
Return Reason
Reviewer
Returned At
Affected Sections
```

---

# 61. Approval Queue

Authorized approvers should see:

```text
VERIFIED
```

records awaiting:

```text
APPROVE
```

The approval process should not require searching manually for eligible records.

---

# 62. Workflow Notifications

V1 MAY provide in-application notifications for:

```text
Record returned for correction
Record awaiting review
Record verified
Record approved
Duplicate requires review
Household head requires review
```

External SMS/email notifications are not required for core V1 unless later approved.

---

# 63. Workflow Event History

Important workflow transitions should produce a timeline.

Example:

```text
2026-09-22 09:10
Draft created by User A

2026-09-22 10:35
Submitted by User A

2026-09-22 11:20
Review started by User B

2026-09-22 11:45
Returned for correction by User B

2026-09-22 13:10
Corrected by User A

2026-09-22 14:00
Verified by User B

2026-09-22 15:30
Approved by User C
```

---

# 64. Workflow Events vs Audit Log

These are related but conceptually different.

### Workflow Event

Explains lifecycle movement:

```text
UNDER_REVIEW → VERIFIED
```

### Audit Log

Records important data/system changes:

```text
national_id:
804000001
→
804000011
```

One user action may create both.

---

# 65. Recommended workflow_events Table

Database V1 should be extended with:

```text
workflow_events
```

Recommended fields:

| Column | Type | Null |
|---|---|---:|
| id | BIGINT | No |
| workflowable_type | VARCHAR | No |
| workflowable_id | BIGINT | No |
| from_status | VARCHAR(30) | Yes |
| to_status | VARCHAR(30) | No |
| action | VARCHAR(50) | No |
| reason | TEXT | Yes |
| metadata | JSONB | Yes |
| performed_by | BIGINT | Yes |
| created_at | TIMESTAMP | No |

This provides a reusable workflow timeline for:

```text
Form Submission
Assessment
Need
```

and potentially other workflow-controlled entities.

---

# 66. Optimistic Concurrency

When a user opens a record and another user changes it before the first user saves:

```text
User A opens Version 5
User B saves Version 6
User A attempts save
```

the application SHOULD detect the stale state for critical operations.

Possible implementation:

```text
updated_at
```

or explicit:

```text
version
```

checking.

The user should not unknowingly overwrite newer data.

---

# 67. Failure Handling

A failed workflow action MUST NOT leave partial critical changes.

Example:

```text
Move Person
```

If old membership is closed but new membership creation fails:

```text
ROLLBACK
```

The Person must remain in the original consistent state.

---

# 68. Idempotency

Critical actions exposed through APIs SHOULD be designed to reduce accidental duplicate execution where appropriate.

Examples:

```text
Approve
Move Person
Create Assistance
```

Repeated requests should not accidentally create duplicate business events.

---

# 69. Validation Layers

Workflow validation occurs at:

```text
UI
 ↓
Application / Service Layer
 ↓
Database Constraints
```

Critical rules must not depend solely on UI behavior.

---

# 70. Workflow Services

Laravel implementation SHOULD avoid placing complex workflow logic directly inside controllers or Filament pages.

Recommended service/action classes:

```text
SubmitFormAction
StartReviewAction
ReturnForCorrectionAction
VerifyFormAction
ApproveFormAction

ChangeHouseholdHeadAction
MovePersonToFamilyAction

ResolveDuplicateAction

UpdateResidenceAction

CreateNeedAction
VerifyNeedAction
RecordAssistanceAction
```

This makes workflow behavior reusable and testable.

---

# 71. Authorization Integration

Every workflow action must perform authorization.

Conceptually:

```text
User
 ↓
Can perform action?
 ↓
Is transition valid?
 ↓
Does record satisfy requirements?
 ↓
Execute
 ↓
Audit
```

Detailed role/action mapping is defined in:

```text
06-PERMISSIONS.md
```

---

# 72. Workflow Tests

Critical workflows MUST have automated tests.

Minimum scenarios:

```text
Draft can be submitted when valid.

Invalid Draft cannot be submitted.

Reviewer can return a submission.

Return reason is required.

Corrected submission can be resubmitted.

Reviewer can verify.

Unauthorized user cannot verify.

Approver can approve.

Draft cannot jump directly to Approved.

Family cannot have two active household heads.

Person cannot have two active primary memberships.

Moving Person preserves Person ID.

Residence update preserves previous residence.

Duplicate detection does not auto-merge.

National ID correction triggers duplicate check.

Assistance does not automatically close Need.

Archive preserves history.
```

---

# 73. Workflow Invariants

The following invariants must always remain true:

```text
WF-INV-001
A DRAFT cannot become APPROVED directly.

WF-INV-002
A returned record must have a correction reason.

WF-INV-003
Verification and approval are distinct actions.

WF-INV-004
Duplicate detection never performs automatic merge.

WF-INV-005
Moving a Person never creates a new Person identity.

WF-INV-006
A Family normally has only one active household head.

WF-INV-007
A Person normally has only one active primary household membership.

WF-INV-008
Residence updates preserve relevant history.

WF-INV-009
Need and Assistance lifecycles remain separate.

WF-INV-010
Critical workflow transitions are auditable.

WF-INV-011
Invalid transitions are rejected server-side.

WF-INV-012
Failed multi-record transitions do not leave partial state.
```

---

# 74. Approved Workflow Decisions V1

### WF-ADR-001

Registration begins as `DRAFT`.

### WF-ADR-002

Data entry and verification are separate stages.

### WF-ADR-003

Verification and final approval are separate stages.

### WF-ADR-004

Returned records require a reason.

### WF-ADR-005

Correction history is preserved.

### WF-ADR-006

Approved records use controlled updates.

### WF-ADR-007

Corrections and real-world updates are treated differently.

### WF-ADR-008

Duplicate Persons are reviewed by humans.

### WF-ADR-009

Household-head changes reuse existing Person identities.

### WF-ADR-010

Moving Persons preserves membership history.

### WF-ADR-011

Residence changes preserve residence history.

### WF-ADR-012

Needs and Assistance use independent workflows.

### WF-ADR-013

Archiving does not mean hard deletion.

### WF-ADR-014

Important transitions produce workflow history and/or audit events.

### WF-ADR-015

Critical multi-record transitions are transactional.

---

# 75. Pending Workflow Decisions

The following may be finalized during implementation planning.

### PWF-001 — Review Assignment

Determine whether reviewers:

```text
Select from shared queue
```

or:

```text
Receive explicitly assigned records
```

or both.

### PWF-002 — Approval Requirement

Determine whether all registrations require final approval after verification or whether this may vary by assessment type.

### PWF-003 — Duplicate Merge

Determine whether V1 includes a complete Person merge tool or only duplicate review and administrator resolution.

### PWF-004 — Correction Scope

Determine whether returned records unlock:

```text
Entire form
```

or only:

```text
Sections flagged by reviewer
```

### PWF-005 — Reverification

Define which changes to an approved record require renewed verification.

### PWF-006 — SLA

Determine whether review/correction queues require deadlines or escalation indicators.

### PWF-007 — Notification Channels

Determine whether V1 requires only in-app notifications or external channels.

---

# 76. Workflow State Ownership

Status fields belong to their respective entities.

Example:

```text
form_submissions.status
assessments.status
family_needs.status
```

Do NOT create one global `status` representing the state of the entire family.

A Family may simultaneously have:

```text
Approved Registration
+
Active Need
+
Draft Follow-up Assessment
+
Verified Document
```

Each lifecycle remains independent.

---

# 77. Documentation Dependencies

This document depends on:

```text
01-PRODUCT.md
02-DATA-DICTIONARY.md
03-BUSINESS-RULES.md
04-DATABASE.md
```

It informs:

```text
06-PERMISSIONS.md
07-ROADMAP.md
Laravel implementation
Automated tests
Filament UI actions
```

---

# 78. Document Status

```text
Project: Famboook
Document: Workflows & State Transitions
Version: 1.0
Status: APPROVED
Date: 2026-09-22
```

---

# 79. Change Log

| Version | Date | Status | Description |
|---|---|---|---|
| 1.0 | 2026-09-22 | Approved | Initial Famboook workflows and state transitions |

---

# 80. Next Step

Current documentation status:

```text
01-PRODUCT.md                 APPROVED
02-DATA-DICTIONARY.md         APPROVED
03-BUSINESS-RULES.md          APPROVED
04-DATABASE.md                APPROVED
05-WORKFLOWS.md               APPROVED
06-PERMISSIONS.md             NEXT
07-ROADMAP.md
```

The next document must define exactly:

```text
WHO
CAN DO WHAT
ON WHICH DATA
AT WHICH WORKFLOW STATE
```

before implementation begins.