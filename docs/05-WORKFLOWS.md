# Famboook
## Workflows & State Transitions

**Document:** `05-WORKFLOWS.md`  
**Version:** 1.2  
**Status:** Approved  
**Last Updated:** 2026-09-22  
**Project:** Famboook — Family Registry & Case Management System

---

# 1. Purpose

This document defines the workflows, state transitions, review processes, and controlled business operations used by Famboook.

A workflow determines:

- Current state
- Allowed transitions
- Authorized actors
- Required validation
- Review requirements
- Approval requirements
- Domain operations
- Workflow history
- Audit requirements
- Failure behavior

The workflow architecture applies consistently across all authorized interfaces.

---

# 2. Workflow Authority

Laravel is the authoritative workflow engine.

Conceptually:

```text
Next.js / Filament
        ↓
Requested Action
        ↓
Laravel
        ↓
Authentication
        ↓
Authorization
        ↓
Current-State Validation
        ↓
Domain Action
        ↓
Transaction
        ↓
State Transition
        ↓
Workflow Event
        ↓
Audit
        ↓
PostgreSQL
```

The frontend must not independently transition canonical workflow state.

---

# 3. Workflow Principles

All Famboook workflows follow these principles:

```text
Explicit State

Controlled Transition

Server-Side Authorization

Current-State Validation

History Preservation

Auditability

Transactional Integrity

Concurrency Protection

Idempotency where required
```

---

# 4. Current State vs History

The current workflow state is stored on the relevant entity.

Example:

```text
change_requests.status = UNDER_REVIEW
```

Historical transitions are stored separately:

```text
workflow_events
```

Example:

```text
DRAFT → SUBMITTED

SUBMITTED → UNDER_REVIEW
```

---

# 5. Workflow Events vs Audit

Workflow history and audit history are separate.

Workflow Event answers:

```text
What process transition happened?
```

Audit answers:

```text
What data changed?
Who changed it?
From what?
To what?
```

A single business operation may create both.

---

# 6. Workflow Event Principle

Workflow events are append-only.

Historical workflow events must not normally be edited or deleted.

Corrections should generate new events rather than rewriting workflow history.

---

# 7. Generic Workflow Transition

A controlled workflow transition should follow:

```text
Receive Action
    ↓
Authenticate
    ↓
Authorize
    ↓
Load Current State
    ↓
Validate Transition
    ↓
Validate Domain Conditions
    ↓
Lock where required
    ↓
Execute Transaction
    ↓
Update State
    ↓
Write Workflow Event
    ↓
Write Audit
    ↓
Commit
    ↓
Notify after commit
```

---

# 8. Invalid Transition

If a requested transition is not valid from the current state:

```text
Reject
```

Example:

```text
REJECTED
   ↓
APPROVED
```

must not occur unless an explicit reopening workflow is later designed.

---

# 9. Frontend Workflow Boundary

Next.js may:

```text
Display current status

Display available actions

Hide unavailable actions

Show confirmation dialogs

Collect comments

Collect supporting information

Submit requested action
```

Next.js must not decide that the transition is valid.

Laravel decides.

---

# 10. Filament Workflow Boundary

Filament is subject to the same workflow rules.

System Administration access does not permit arbitrary workflow transitions.

Important operations must reuse the same Domain Actions used by the API.

---

# 11. Staff Form Workflow

Baseline Staff form workflow:

```text
DRAFT
  ↓
DATA_ENTRY_COMPLETED
  ↓
UNDER_REVIEW
  ├── RETURNED_FOR_CORRECTION
  │            ↓
  │        CORRECTED
  │            ↓
  └────── UNDER_REVIEW
               ↓
            VERIFIED
               ↓
            APPROVED
```

Optional terminal state:

```text
ARCHIVED
```

---

# 12. Staff Form States

Initial form states:

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

---

# 13. DRAFT

A DRAFT may be incomplete.

Authorized Data Entry users may edit it.

DRAFT information must not automatically be interpreted as verified canonical information.

---

# 14. DATA_ENTRY_COMPLETED

Indicates the Data Entry actor considers required entry complete.

Transition requires:

```text
Required fields present

Basic validation passed

Source reference captured where applicable
```

---

# 15. UNDER_REVIEW

The record is awaiting or undergoing review.

The reviewer evaluates:

```text
Completeness

Source consistency

Possible duplicates

Validation issues

Required evidence
```

---

# 16. RETURNED_FOR_CORRECTION

Reviewer identified an issue requiring correction.

A reason/comment should be recorded.

The correction reason must be available to the authorized correction actor.

---

# 17. CORRECTED

The correction actor has responded to the returned item.

It can then return to:

```text
UNDER_REVIEW
```

---

# 18. VERIFIED

An authorized reviewer has verified the record according to the applicable process.

Verification does not necessarily equal final approval.

---

# 19. APPROVED

The record has completed the required approval process.

Approval actor and timestamp must be recorded.

---

# 20. ARCHIVED

ARCHIVED is a terminal/non-operational state unless a future restoration process is explicitly designed.

Archiving must not destroy history.

---

# 21. Maker-Checker

Where required:

```text
Maker
≠
Checker
```

The creator/submitter should not perform incompatible review/approval actions on the same item.

Exact separation depends on workflow risk.

---

# 22. Family Registration Workflow

Baseline:

```text
Create Family Draft
        ↓
Validate Family Data
        ↓
Resolve/Create Household Head Person
        ↓
Duplicate Check
        ↓
Create Membership
        ↓
Assign Household Head
        ↓
Add Residence
        ↓
Complete Entry
        ↓
Review
        ↓
Verify / Approve
```

Where these operations form one initial registration transaction, partial invalid registry state must not be committed.

---

# 23. Person Registration Workflow

Baseline:

```text
Enter Person Information
        ↓
Normalize Search Inputs
        ↓
Duplicate Check
        ↓
No Match?
   ├── Yes → Create Person
   └── No  → Duplicate Review
```

No automatic duplicate merge is allowed.

---

# 24. Duplicate Detection Workflow

```text
Potential Person
      ↓
Duplicate Detection
      ↓
┌───────────────────────┐
│ EXACT                 │
│ PROBABLE              │
│ POSSIBLE              │
└───────────────────────┘
      ↓
Human Review
      ↓
┌───────────────────────┐
│ NOT_DUPLICATE         │
│ SAME_PERSON           │
│ UNRESOLVED            │
└───────────────────────┘
```

---

# 25. Duplicate Resolution

If:

```text
NOT_DUPLICATE
```

creation may continue.

If:

```text
SAME_PERSON
```

the existing Person should normally be reused.

If:

```text
UNRESOLVED
```

the operation may remain pending or require authorized escalation.

---

# 26. Duplicate Merge

Automatic merge is prohibited.

A future Person merge workflow must define:

```text
Surviving Person

Field Conflict Resolution

Membership Handling

Document Handling

Assessment Handling

Audit

Rollback Strategy
```

before implementation.

---

# 27. Add Family Member Workflow

Staff flow:

```text
Select Family
    ↓
Enter / Search Person
    ↓
Duplicate Check
    ↓
Existing Person?
  ├── Yes → Validate Membership Eligibility
  └── No  → Create Person
    ↓
Create Family Membership
    ↓
Create Relationships where required
    ↓
Audit
```

---

# 28. Family Membership Transfer

```text
Person
   ↓
Current Active Membership
   ↓
Request Transfer
   ↓
Validate Destination Family
   ↓
Validate Head / Relationship Impact
   ↓
Lock Memberships
   ↓
End Current Membership
   ↓
Create New Membership
   ↓
Reevaluate Portal Access
   ↓
Audit
```

The operation is transactional.

---

# 29. Household Head Change Workflow

```text
Family
  ↓
Current Household Head
  ↓
Select Proposed Head
  ↓
Validate Active Membership
  ↓
Validate Eligibility
  ↓
Authorize
  ↓
Lock Family Memberships
  ↓
Remove Current Head Flag
  ↓
Assign New Head
  ↓
Reevaluate Family Portal Access
  ↓
Audit
  ↓
Commit
```

---

# 30. Household Head Concurrency

The system must protect against two simultaneous head changes.

Protection includes:

```text
Transaction

Row Locking

Partial Unique Index
```

---

# 31. Household Head Death

When an official Person death operation affects the current Household Head:

```text
Record Death
    ↓
Detect Household Head
    ↓
Create / Flag Household Head Review
    ↓
Reevaluate Family User Access
    ↓
Authorized Staff selects replacement through Head Change Workflow
```

Famboook must not silently choose the next Household Head.

---

# 32. Person Death Workflow

Staff-originated official operation:

```text
Select Person
    ↓
Validate Current Life Status
    ↓
Capture Verified Information
    ↓
Validate death_date if known
    ↓
Authorize
    ↓
RecordPersonDeathAction
    ↓
Update life_status
    ↓
Set death_date if known
    ↓
Handle Head/Access Implications
    ↓
Audit
```

Unknown exact death date remains NULL.

---

# 33. Marriage Workflow

Marriage-related changes may affect:

```text
Marital Status

Person Relationships

Family Membership
```

These effects must be explicitly selected/validated.

Marriage must not automatically transfer a Person to another Family without an authorized membership operation.

---

# 34. Residence Change Workflow

```text
Family
  ↓
Current Residence
  ↓
New Residence Data
  ↓
Validate
  ↓
Authorize
  ↓
Lock Current Residence
  ↓
End Current Residence
  ↓
Create New Current Residence
  ↓
Audit
```

The operation is transactional.

---

# 35. Assessment Workflow

Baseline:

```text
CREATE
  ↓
DRAFT
  ↓
IN_PROGRESS
  ↓
COMPLETED
  ↓
UNDER_REVIEW
  ↓
VERIFIED
  ↓
APPROVED
```

Exact Assessment workflow may vary by Assessment Type.

## V1: Quick Multi-Domain Family Assessment

Approved 2026-09-24 (docs/03 §40a). The V1 family assessment uses only:

```text
CREATE → DRAFT → COMPLETED
```

- `CreateAssessmentAction` creates a DRAFT; `UpdateAssessmentAction`
  saves draft changes; `CompleteAssessmentAction` completes it,
  optionally saving a final draft payload in the same transaction.
- COMPLETED is terminal and immutable in V1: no reopening, review,
  verification or approval. IN_PROGRESS, UNDER_REVIEW, VERIFIED and
  APPROVED remain part of the future baseline above and are not used.
- Completion requires at least one assessed domain and no result on an
  inactive domain.
- Completion never creates Needs and never changes canonical data (§36).

---

# 36. Assessment Independence

Assessment answers represent point-in-time information.

Assessment completion must not automatically overwrite canonical Family/Person data.

Any canonical update must use an explicit controlled operation.

---

# 37. Need Workflow

Suggested baseline:

```text
OPEN
  ↓
IN_PROGRESS
  ↓
MET
  ↓
CLOSED
```

Alternative terminal state:

```text
CANCELLED
```

---

# 38. Need Creation

A Need may originate from:

```text
Assessment

Social Worker

Authorized Staff

Approved Case Process
```

Creation requires appropriate authorization.

---

# 39. Need and Assistance

Recording Assistance does not automatically close a Need.

Need status requires explicit transition.

---

# 40. Assistance Workflow

Baseline:

```text
Record Assistance
      ↓
Validate Family / Person
      ↓
Validate Need if linked
      ↓
Authorize
      ↓
Save Assistance
      ↓
Audit
      ↓
Optionally Review Need Status
```

---

# 41. Document Workflow

Baseline:

```text
UPLOADED
   ↓
UNVERIFIED
   ↓
UNDER_REVIEW
   ↓
VERIFIED
```

Possible alternative:

```text
REJECTED
```

Exact status implementation may be simplified if `is_verified` remains the V1 persistence model.

---

# 42. Document Upload

Upload success must not imply verification.

Family User uploads are always unverified initially.

---

# 43. Document Verification

Verification requires an authorized actor.

Verification records:

```text
verified_by
verified_at
```

and workflow/audit information where applicable.

---

# 44. Paper Source Workflow

Paper forms may follow:

```text
Received
   ↓
Registered
   ↓
Data Entry
   ↓
Review
   ↓
Correction if required
   ↓
Verification
   ↓
Approval
```

Source documents remain traceable.

---

# 45. Correction Workflow

A correction fixes incorrect recorded information.

Example:

```text
Wrong birth date
```

Correction should record:

```text
Previous Value
New Value
Reason
Actor
Timestamp
```

---

# 46. Real-World Change Workflow

A real-world change creates new historical state where appropriate.

Example:

```text
Family moved
```

Correct behavior:

```text
End old residence
Create new residence
```

not:

```text
Overwrite old residence
```

---

# 47. Family Portal Architecture

Family Portal workflow:

```text
Authenticated Family User
          ↓
Resolve Authorized Family
          ↓
View Permitted Canonical Data
          ↓
Submit Proposed Change
          ↓
Change Request
          ↓
Staff Review
          ↓
Approval
          ↓
Domain Action
          ↓
Canonical Registry
```

---

# 48. Family User Account Lifecycle

Baseline:

```text
ACCOUNT CREATED
       ↓
IDENTITY VERIFICATION
       ↓
USER-PERSON LINK
       ↓
FAMILY ELIGIBILITY CHECK
       ↓
ACTIVATED
       ↓
ACTIVE
```

Possible later states:

```text
SUSPENDED

ENDED
```

---

# 49. Public Registration

Anonymous public Family registration is outside V1.

Family User account creation/activation is controlled.

---

# 50. User-Person Link Workflow

```text
PENDING_VERIFICATION
        ↓
     VERIFIED
        ↓
      ACTIVE
```

Possible transitions:

```text
ACTIVE → SUSPENDED

ACTIVE → ENDED

SUSPENDED → ACTIVE
```

Reactivation requires authorization.

---

# 51. Link Verification

A Family User must not verify their own User-Person Link.

Verification requires an authorized internal actor/process.

---

# 52. Dynamic Family Eligibility

An ACTIVE User-Person Link does not permanently guarantee Family access.

Every relevant request must evaluate current eligibility.

Recommended V1:

```text
Active User
+
Active User-Person Link
+
Active Person
+
Active Family Membership
+
Current Household Head
```

unless an approved representative policy applies.

---

# 53. Access-Reevaluation Events

Family Portal eligibility must be reconsidered after:

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

# 54. Change Request Workflow

Canonical lifecycle:

```text
DRAFT
  ↓
SUBMITTED
  ↓
UNDER_REVIEW
  ├── RETURNED_FOR_CLARIFICATION
  │            ↓
  │       RESUBMITTED
  │            ↓
  └──────── UNDER_REVIEW
               │
        ┌──────┴──────┐
        ↓             ↓
     REJECTED      APPROVED
                       ↓
                    APPLIED
```

---

# 55. Change Request DRAFT

The requester may edit their own DRAFT request where authorized.

A DRAFT is not visible in normal Staff review queues unless explicitly designed otherwise.

---

# 56. Change Request Submission

Submission must:

```text
Authenticate

Authorize Family Scope

Validate Request Type

Validate Target

Validate Proposed Data

Validate Required Documents

Set submitted_by

Set submitted_at

Transition to SUBMITTED

Create Workflow Event
```

---

# 57. SUBMITTED

SUBMITTED means:

```text
Requester has formally sent the request for review.
```

Canonical registry data remains unchanged.

---

# 58. UNDER_REVIEW

Authorized Staff accepts/opens the request for review.

The reviewer may:

```text
Inspect canonical data

Inspect proposed data

Inspect supporting documents

Check duplicates

Request clarification

Reject

Approve
```

subject to permission.

---

# 59. RETURNED_FOR_CLARIFICATION

The reviewer requires additional Family User information.

A Family-visible clarification message must be provided.

Internal Staff notes must remain separate.

---

# 60. RESUBMITTED

The Family User has responded to clarification.

The response must be traceable.

The request returns to review.

---

# 61. REJECTED

Rejection is terminal in the baseline workflow.

It must record:

```text
rejected_by

rejected_at

rejection_reason
```

A future reopen/appeal workflow requires explicit design.

---

# 62. APPROVED

APPROVED means:

```text
The proposed operation is authorized for application.
```

It does not mean:

```text
Canonical data has already changed.
```

---

# 63. APPLIED

APPLIED means:

```text
The approved Domain Action completed successfully
and canonical registry data was updated.
```

Therefore:

```text
APPROVED ≠ APPLIED
```

---

# 64. Change Request Application Workflow

```text
APPROVED
   ↓
Acquire Lock
   ↓
Re-read Current Registry
   ↓
Revalidate Request
   ↓
Authorize Application
   ↓
Select Domain Action
   ↓
Begin Transaction
   ↓
Execute Canonical Change
   ↓
Write Audit
   ↓
Write Workflow Event
   ↓
Set applied_by / applied_at
   ↓
Set APPLIED
   ↓
Commit
```

---

# 65. Application Revalidation

Approval may have occurred earlier.

The canonical registry may have changed since then.

Application must therefore revalidate current state.

Example:

```text
Approved Household Head change
        ↓
Before application, proposed head leaves Family
        ↓
Application must fail safely
```

---

# 66. Application Failure

If canonical application fails:

```text
ROLLBACK
```

The request must not become APPLIED.

Baseline behavior:

```text
Remain APPROVED
```

The failure should be logged/audited appropriately.

---

# 67. Application Retry

An authorized retry may occur after the underlying issue is resolved.

Retry must still:

```text
Lock

Revalidate

Authorize

Execute transaction
```

---

# 68. Application Idempotency

If a request is already:

```text
APPLIED
```

another application attempt must not repeat the canonical mutation.

---

# 69. Concurrent Application

Two actors/processes attempting to apply the same request simultaneously must not create duplicate domain changes.

Use:

```text
Transaction

Row Lock

State Check
```

---

# 70. Contact Update Request

```text
DRAFT
 ↓
SUBMITTED
 ↓
REVIEW
 ↓
APPROVED
 ↓
UpdatePersonContactAction
 ↓
APPLIED
```

V1 uses review even if Contact Update is classified LOW risk.

---

# 71. Residence Update Request

```text
Family User proposes residence
        ↓
Review
        ↓
Approval
        ↓
ChangeFamilyResidenceAction
        ↓
End old current residence
        ↓
Create new current residence
        ↓
APPLIED
```

---

# 72. Person Correction Request

```text
Proposed correction
      ↓
Review current canonical value
      ↓
Review supporting evidence
      ↓
Approve / Reject
      ↓
Controlled correction action
      ↓
Audit old/new values
```

---

# 73. Add Family Member Request

```text
Proposed Member
      ↓
Review
      ↓
Duplicate Detection
      ↓
Existing Person?
 ┌─────────────┴──────────────┐
 ↓                            ↓
YES                           NO
 ↓                            ↓
Reuse Person             Create Person
 └─────────────┬──────────────┘
               ↓
        Create Membership
               ↓
             APPLIED
```

---

# 74. Birth Report Request

A Birth Report must not directly create a Person.

```text
BIRTH_REPORT
    ↓
Review
    ↓
Duplicate Check
    ↓
Approval
    ↓
CreatePersonAction
    ↓
AddFamilyMemberAction
    ↓
APPLIED
```

---

# 75. Death Report Request

```text
DEATH_REPORT
    ↓
Review
    ↓
Evidence / Verification
    ↓
Approval
    ↓
RecordPersonDeathAction
    ↓
Head / Access Review if applicable
    ↓
APPLIED
```

The reported date is not canonical until application succeeds.

---

# 76. Marriage Update Request

```text
MARRIAGE_UPDATE
       ↓
Review
       ↓
Determine Required Domain Effects
       ↓
Approve
       ↓
Update Marital Status
and/or
Person Relationship
and/or
Membership Operation
       ↓
APPLIED
```

No automatic Family transfer is assumed.

---

# 77. Membership Change Request

Membership change is high impact.

Application must use approved membership Domain Actions and preserve history.

---

# 78. Household Head Change Request

Recommended risk:

```text
HIGH
```

Workflow:

```text
Request
  ↓
Review
  ↓
Validate Proposed Head
  ↓
Approval
  ↓
ChangeHouseholdHeadAction
  ↓
Reevaluate Portal Access
  ↓
APPLIED
```

---

# 79. Document Update Request

```text
Upload Document
      ↓
Change Request
      ↓
Review
      ↓
Document Verification where required
      ↓
Approval
      ↓
Apply metadata/document change
      ↓
APPLIED
```

Upload alone does not verify the document.

---

# 80. OTHER Request

`OTHER` must not become an unrestricted mutation mechanism.

It may collect a request that requires Staff interpretation.

Application requires an explicitly selected authorized Domain Action.

No arbitrary payload-to-database update is allowed.

---

# 81. Change Request Risk

Possible levels:

```text
LOW
MEDIUM
HIGH
```

Risk may influence:

```text
Evidence

Reviewer

Approver

Maker-Checker

Re-authentication

Application Permission
```

---

# 82. Review Queues

Staff Application should expose operational queues rather than requiring users to manually search every entity.

Recommended queues:

```text
Form Review

Returned for Correction

Verification

Approval

Duplicate Review

Change Requests

Clarification Responses

Approved Awaiting Application

Household Head Review

Document Verification
```

---

# 83. Queue Meaning

An operational queue is a filtered view of authoritative workflow state.

It is not a separate source of truth.

Example:

```text
Approved Awaiting Application
```

is derived from:

```text
change_requests.status = APPROVED
```

---

# 84. Queue Authorization

Users must only see queue items within their:

```text
Role

Permission

Data Scope

Object Scope
```

---

# 85. Notifications

Notifications are triggered by backend workflow events.

Examples:

```text
Request Submitted

Request Returned for Clarification

Request Approved

Request Rejected

Request Applied

Account Activated
```

---

# 86. After-Commit Notifications

Where notification delivery is not part of canonical integrity, it should occur after successful transaction commit.

Conceptually:

```text
Commit Business State
        ↓
Dispatch Notification
```

not:

```text
Send SMS
   ↓
SMS fails
   ↓
Rollback valid registry change
```

---

# 87. Notification Failure

Notification delivery failure does not change committed workflow state.

Delivery may be retried independently.

---

# 88. Domain Actions

Recommended actions include:

```text
CreateFamilyAction

CreatePersonAction

AddFamilyMemberAction

TransferFamilyMemberAction

ChangeHouseholdHeadAction

ChangeFamilyResidenceAction

RecordPersonDeathAction

CreateAssessmentAction

CompleteAssessmentAction

SubmitFormAction

VerifyFormAction

ApproveFormAction

CreateNeedAction

RecordAssistanceAction

VerifyDocumentAction

SubmitChangeRequestAction

StartChangeRequestReviewAction

ReturnChangeRequestForClarificationAction

ResubmitChangeRequestAction

ApproveChangeRequestAction

RejectChangeRequestAction

ApplyChangeRequestAction
```

---

# 89. Domain Action Responsibilities

Depending on operation, an Action should handle:

```text
Authorization context

Current-state validation

Business rules

Concurrency protection

Transaction

Canonical mutation

Workflow transition

Audit

Domain events
```

---

# 90. Thin Controllers

API controllers should remain thin.

Preferred:

```text
HTTP Request
    ↓
Form Request
    ↓
Policy
    ↓
Controller
    ↓
Domain Action
    ↓
Resource Response
```

Controllers should not contain large duplicated workflow logic.

---

# 91. Semantic Endpoints

Important workflow operations should use meaningful endpoints where appropriate.

Examples:

```text
POST /api/v1/change-requests/{request}/submit

POST /api/v1/change-requests/{request}/start-review

POST /api/v1/change-requests/{request}/return

POST /api/v1/change-requests/{request}/approve

POST /api/v1/change-requests/{request}/reject

POST /api/v1/change-requests/{request}/apply
```

Similarly:

```text
POST /api/v1/families/{family}/change-household-head
```

may be preferable to generic field mutation.

---

# 92. HTTP Retry Safety

Clients may retry requests because of network problems.

High-impact endpoints must therefore consider:

```text
Idempotency

Current-state validation

Duplicate submission

Concurrency
```

---

# 93. Workflow Comments

Workflow comments should distinguish:

```text
Internal Staff Comment

Family-visible Clarification

Family-visible Rejection Reason
```

Internal comments must not accidentally be exposed through Family Portal resources.

---

# 94. Workflow Ownership

The workflow belongs to the backend/domain layer.

Correct:

```text
Frontend:
"Approve this request"

Backend:
"Is approval currently allowed?"
```

Incorrect:

```text
Frontend:
"Set status = APPROVED"
```

---

# 95. API Status Exposure

The API may expose:

```text
status

allowed_actions
```

where useful.

`allowed_actions` can improve UX but remains informational.

The backend must still authorize any submitted action independently.

---

# 96. Family Portal Status Presentation

Family Portal should use understandable status labels.

Internal:

```text
RETURNED_FOR_CLARIFICATION
```

may display as:

```text
Needs additional information
```

or localized Arabic equivalent.

The canonical machine state remains stable.

---

# 97. Executive Workflow Metrics

Executive Dashboard may derive metrics such as:

```text
Pending Reviews

Pending Approvals

Average Review Time

Returned Requests

Applied Requests

Duplicate Review Backlog

Data Verification Progress
```

Metrics must not change workflow state.

---

# 98. Workflow Time Metrics

Workflow events may support calculation of:

```text
Submission-to-Review Time

Review-to-Approval Time

Approval-to-Application Time

Clarification Response Time
```

These values should normally be derived rather than manually entered.

---

# 99. Workflow Escalation

Automatic escalation may be added later.

V1 does not require automatic escalation unless operational requirements are approved.

Future examples:

```text
Request pending > N days

High-risk request pending approval

Large duplicate-review backlog
```

---

# 100. Scheduled Jobs

Scheduled jobs may:

```text
Send reminders

Generate queue summaries

Detect stale workflow items
```

but must not automatically perform high-risk canonical decisions unless explicitly authorized by business policy.

---

# 101. Background Jobs

Background jobs must revalidate state when executing.

A queued job must not assume the state remains unchanged since dispatch.

---

# 102. Job Idempotency

Retryable jobs that mutate state must be designed to avoid duplicate effects.

---

# 103. Transaction Failure

If a transaction fails:

```text
No partial canonical mutation

No false final workflow state

No false APPLIED state
```

The client receives a controlled error.

---

# 104. Workflow Error Messages

User-facing errors should be useful without exposing:

```text
Database SQL

Stack Traces

Sensitive Data

Internal Security Details
```

---

# 105. Authorization Failure

Unauthorized transition requests should be rejected server-side.

The frontend hiding the action is not sufficient.

---

# 106. Object-Level Authorization

A valid workflow action on one Family does not authorize the same action on another Family.

Every target object requires authorization.

---

# 107. Family User Workflow Permissions

A Family User may, where authorized:

```text
Create own DRAFT Change Request

Edit own DRAFT

Submit own request

View own authorized requests

Respond to clarification

Resubmit

Upload supporting documents

Track status
```

A Family User may not:

```text
Start Staff Review

Verify

Approve

Reject

Apply

Edit internal review notes
```

---

# 108. Staff Workflow Permissions

Staff permissions are separated by operation.

Examples:

```text
change-request.review

change-request.return

change-request.approve

change-request.reject

change-request.apply
```

Having one permission does not automatically grant all others.

---

# 109. High-Risk Separation

For HIGH-risk workflows, future policy may require:

```text
Reviewer ≠ Approver
```

or:

```text
Approver ≠ Applier
```

Exact requirements remain pending.

---

# 110. Workflow Concurrency

Critical workflows must use locking and state checks.

Candidates include:

```text
Household Head Change

Family Membership Transfer

Residence Change

Change Request Application

Person Death

Duplicate Resolution
```

---

# 111. Stale UI State

Example:

```text
Reviewer opens APPROVED request

Another authorized actor applies it

First reviewer still sees old screen

First reviewer clicks Apply
```

Backend must respond based on current database state and must not apply twice.

---

# 112. Optimistic UI

The frontend may use optimistic UI only for operations where failure can be safely reconciled.

High-risk canonical operations should generally wait for authoritative backend confirmation.

---

# 113. Workflow Audit Context

Where appropriate, audit context may include:

```text
User

Request ID

IP/context metadata

Action

Target

Previous state

New state
```

Sensitive metadata collection must remain proportional.

---

# 114. Workflow Testing

Every important workflow requires automated tests covering:

```text
Happy Path

Invalid Transition

Unauthorized Actor

Wrong Object Scope

Missing Required Data

Concurrency

Rollback

Idempotency

Audit

Workflow Event

Sensitive Field Exposure
```

---

# 115. Change Request Tests

Required scenarios include:

```text
DRAFT → SUBMITTED

SUBMITTED → UNDER_REVIEW

UNDER_REVIEW → RETURNED_FOR_CLARIFICATION

RETURNED_FOR_CLARIFICATION → RESUBMITTED

RESUBMITTED → UNDER_REVIEW

UNDER_REVIEW → APPROVED

UNDER_REVIEW → REJECTED

APPROVED → APPLIED
```

and invalid transitions.

---

# 116. Application Failure Test

Required:

```text
APPROVED request
     ↓
Domain Action fails
     ↓
Transaction rolls back
     ↓
Canonical data unchanged
     ↓
Request remains APPROVED
```

---

# 117. Application Idempotency Test

Required:

```text
Apply APPROVED request
     ↓
APPLIED
     ↓
Apply again
     ↓
No second canonical mutation
```

---

# 118. Family Scope Test

Required:

```text
Family User A
    ↓
Request belonging to Family B
    ↓
Denied
```

even when the request ID is known.

---

# 119. Document Workflow Test

Family User upload:

```text
Upload succeeds
     ↓
Document remains UNVERIFIED
```

until authorized verification occurs.

---

# 120. Household Head Test

Two concurrent attempts must never result in:

```text
Two active Household Heads
```

---

# 121. Workflow Invariants

```text
WF-INV-001
Workflow state transitions are authoritative only when performed by Laravel.

WF-INV-002
Frontend clients cannot directly set protected workflow states.

WF-INV-003
Filament cannot bypass workflow rules.

WF-INV-004
Workflow history is preserved.

WF-INV-005
Workflow Events are append-only.

WF-INV-006
Workflow Events and Audit are distinct.

WF-INV-007
Invalid transitions are rejected.

WF-INV-008
Authorization is checked server-side for every protected transition.

WF-INV-009
Object-level authorization applies to workflow actions.

WF-INV-010
Maker-checker separation applies where required.

WF-INV-011
Duplicate Persons are never automatically merged.

WF-INV-012
Membership transfer preserves history.

WF-INV-013
Household Head changes are transactional.

WF-INV-014
Residence changes preserve history.

WF-INV-015
Unknown death dates are not invented.

WF-INV-016
Assessment completion does not silently overwrite canonical registry data.

WF-INV-017
Assistance does not automatically close a Need.

WF-INV-018
Uploaded documents are not automatically verified.

WF-INV-019
Family User submissions use controlled Change Requests.

WF-INV-020
Family User submissions do not directly mutate canonical registry data.

WF-INV-021
APPROVED and APPLIED are distinct.

WF-INV-022
Change Request application revalidates current state.

WF-INV-023
Change Request application is transactional.

WF-INV-024
An APPLIED Change Request cannot be applied again.

WF-INV-025
Application failure cannot produce partial canonical state.

WF-INV-026
Family Users cannot review/approve/reject/apply their own requests.

WF-INV-027
Internal Staff workflow notes are not exposed to Family Users.

WF-INV-028
Family Portal access is reevaluated after relevant registry changes.

WF-INV-029
Notification failure does not reverse committed business state.

WF-INV-030
Critical workflow operations protect against concurrency.

WF-INV-031
Workflow actions reuse the Laravel Domain Layer regardless of initiating interface.

WF-INV-032
Background jobs revalidate current state before mutation.

WF-INV-033
Operational queues derive from authoritative workflow state.

WF-INV-034
High-risk actions must receive authoritative backend confirmation before the UI treats them as complete.

WF-INV-035
Client-provided status values are not trusted as transition authority.
```

---

# 122. Approved Workflow Decisions

### WF-ADR-001
Workflow state is explicitly stored.

### WF-ADR-002
Workflow history is stored separately in `workflow_events`.

### WF-ADR-003
Workflow Events and Audit are separate concepts.

### WF-ADR-004
Workflow Events are append-only.

### WF-ADR-005
Staff data-entry workflows support review and correction.

### WF-ADR-006
Verification and approval are distinct where required.

### WF-ADR-007
Duplicate detection requires human review.

### WF-ADR-008
Automatic Person merge is prohibited.

### WF-ADR-009
Membership transfers preserve history.

### WF-ADR-010
Household Head change is a controlled transactional operation.

### WF-ADR-011
Household Head death triggers review rather than automatic replacement.

### WF-ADR-012
Residence change preserves history.

### WF-ADR-013
Assessments remain point-in-time records.

### WF-ADR-014
Need and Assistance maintain separate workflows.

### WF-ADR-015
Document upload and verification are separate.

### WF-ADR-016
Family User activation requires identity verification.

### WF-ADR-017
Family User identity uses User-Person Links.

### WF-ADR-018
Family User eligibility is dynamically reevaluated.

### WF-ADR-019
Family User substantive updates use Change Requests.

### WF-ADR-020
Change Requests support clarification and resubmission.

### WF-ADR-021
APPROVED and APPLIED are separate states.

### WF-ADR-022
Application invokes explicit Domain Actions.

### WF-ADR-023
Application revalidates canonical state.

### WF-ADR-024
Application is transactional.

### WF-ADR-025
Application is idempotent.

### WF-ADR-026
Failed application leaves the baseline request APPROVED.

### WF-ADR-027
Family User uploaded documents remain unverified.

### WF-ADR-028
Notifications originate from backend workflow events.

### WF-ADR-029
Notification delivery failure does not invalidate committed state.

### WF-ADR-030
Critical workflows use concurrency protection.

### WF-ADR-031
Family Users cannot perform Staff review/approval/application actions.

### WF-ADR-032
Laravel is the authoritative workflow layer.

### WF-ADR-033
Next.js presents workflows but does not own transition authority.

### WF-ADR-034
Filament reuses the same workflow/domain operations.

### WF-ADR-035
Semantic API actions are preferred for important workflow transitions.

### WF-ADR-036
Operational queues are derived views of canonical workflow state.

### WF-ADR-037
After-commit processing is preferred for non-critical notification delivery.

### WF-ADR-038
Queued mutating operations must revalidate state and be retry-safe.
```

---

# 123. Pending Workflow Decisions

```text
PWF-001
Exact Staff form statuses by form type.

PWF-002
Exact Assessment workflow by Assessment type.

PWF-003
Exact Need workflow and closure rules.

PWF-004
Exact Document rejection/replacement workflow.

PWF-005
Exact Duplicate Resolution escalation process.

PWF-006
Future Person merge workflow.

PWF-007
Exact Household Head eligibility workflow.

PWF-008
Exact Family User activation workflow.

PWF-009
Exact identity-verification evidence.

PWF-010
Whether multiple Family Users are supported in V1.

PWF-011
Guardian / Authorized Representative workflow.

PWF-012
Exact risk classification for every Change Request type.

PWF-013
Exact maker-checker requirements by risk level.

PWF-014
Whether HIGH-risk requests require a second approver.

PWF-015
Whether approver and applier must differ for selected operations.

PWF-016
Whether APPLICATION_FAILED becomes a formal status.

PWF-017
Exact stale-request expiration policy.

PWF-018
Exact notification channels.

PWF-019
Whether selected LOW-risk requests may later auto-apply.

PWF-020
Exact request cancellation/withdrawal workflow.

PWF-021
Whether rejected requests may be appealed or reopened.

PWF-022
Exact automatic reminder/escalation rules.

PWF-023
Exact re-authentication rules for high-risk transitions.

PWF-024
Exact import approval workflow.

PWF-025
Exact export approval workflow for sensitive datasets.
```

---

# 124. Recommended V1 Family Portal Workflow

For initial production, use the security-first model:

```text
Verified Household Head
        ↓
Authenticated FAMILY_USER
        ↓
Authorized Family Scope
        ↓
Read Permitted Data
        ↓
Submit Change Request
        ↓
Staff Review
        ↓
Staff Approval
        ↓
Controlled Domain Action
        ↓
Canonical Registry
```

Avoid direct Family User canonical CRUD in V1.

---

# 125. Workflow Implementation Order

Recommended implementation sequence:

```text
1. Workflow Event infrastructure

2. Domain Action conventions

3. Basic Staff review workflow

4. Family Membership operations

5. Household Head operation

6. Residence operation

7. Person Death operation

8. Assessment workflow

9. Need / Assistance workflows

10. Document verification workflow

11. Family User identity lifecycle

12. Change Request engine

13. Change Request type handlers

14. Operational queues

15. Notifications

16. Workflow metrics
```

---

# 126. Workflow Definition of Done

A workflow is not considered implemented merely because buttons and statuses exist.

It is complete only when:

```text
States are defined

Transitions are defined

Authorized actors are defined

Invalid transitions are rejected

Server-side authorization exists

Current-state validation exists

Transactions exist where required

Concurrency is addressed

Workflow Events are written

Audit is written where required

Failure behavior is defined

Idempotency is addressed where required

API responses are controlled

Frontend handles success/error states

Tests cover valid and invalid paths
```

---

# 127. Final Workflow Principle

Famboook follows:

```text
User Intent
    ↓
Interface
    ↓
Requested Action
    ↓
Laravel Authorization
    ↓
Workflow Rule
    ↓
Domain Action
    ↓
Transaction
    ↓
Canonical State
    ↓
Workflow History
```

not:

```text
Button Click
    ↓
status = "APPROVED"
```

The UI represents workflow.

Laravel controls workflow.

PostgreSQL persists workflow state and history.

---

# 128. Document Status

```text
Project: Famboook
Document: Workflows & State Transitions
Version: 1.2
Status: APPROVED
Date: 2026-09-22
```

---

# 129. Change Log

| Version | Date | Status | Description |
|---|---|---|---|
| 1.0 | 2026-09-22 | Superseded | Initial workflow definition |
| 1.1 | 2026-09-22 | Superseded | Added Family Portal identity lifecycle, Change Request workflow, application rules, concurrency, idempotency, queues and Family self-service workflows |
| 1.2 | 2026-09-22 | Approved | Established Laravel as authoritative workflow layer, clarified Next.js/Filament workflow boundaries, added semantic API actions, after-commit notifications, retry-safe background processing, queue derivation and expanded workflow testing/invariants |
| 1.2.1 | 2026-09-24 | Approved | §35: V1 family assessment workflow DRAFT → COMPLETED only |