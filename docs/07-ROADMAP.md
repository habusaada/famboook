# Famboook
## Implementation Roadmap

**Document:** `07-ROADMAP.md`  
**Version:** 1.1  
**Status:** Approved  
**Last Updated:** 2026-09-22  
**Project:** Famboook — Family Registry & Case Management System

---

# 1. Purpose

This document defines the implementation roadmap for Famboook.

It converts the approved product, data, business, database, workflow, and authorization architecture into an executable development plan.

This roadmap defines:

```text
Implementation Phases
Phase Dependencies
Phase Deliverables
Definition of Done
Testing Requirements
Security Requirements
Pilot Strategy
Production Readiness
Deferred Features
```

This document does not redefine the architecture.

Implementation must remain aligned with:

```text
00-PROJECT-CONTEXT.md
01-PRODUCT.md
02-DATA-DICTIONARY.md
03-BUSINESS-RULES.md
04-DATABASE.md
05-WORKFLOWS.md
06-PERMISSIONS.md
```

---

# 2. Roadmap Principles

Development follows these principles:

```text
Architecture Before Implementation

Database Integrity Before UI Convenience

Canonical Registry Before Case Management

Staff Operations Before Family Self-Service

Domain Actions Before Change Requests

Authorization Before Exposure

Auditability From the Beginning

Security by Default

Automated Tests During Development

Pilot Before Full Production
```

---

# 3. Implementation Strategy

Famboook will be implemented incrementally.

Each phase must produce a stable, testable result before dependent phases begin.

The general sequence is:

```text
Architecture Baseline
        ↓
Laravel Foundation
        ↓
Authentication & RBAC
        ↓
Reference Data
        ↓
Family & Person Registry
        ↓
Memberships & Relationships
        ↓
Residence & Source Forms
        ↓
Staff Data Entry
        ↓
Verification & Approval
        ↓
Extended Registry Data
        ↓
Assessments
        ↓
Needs & Assistance
        ↓
Documents & Case Notes
        ↓
Family User Identity
        ↓
Family Portal
        ↓
Change Requests
        ↓
Search & Duplicate Management
        ↓
Reports
        ↓
Import / Export
        ↓
Security Hardening
        ↓
Testing & UAT
        ↓
Deployment & Pilot
        ↓
Production Rollout
```

---

# 4. Target Technical Architecture

Initial implementation direction:

```text
Backend:
Laravel 12+

Database:
PostgreSQL 16+

Staff Administration:
Filament

Authentication:
Laravel Authentication

Authorization:
Spatie Laravel Permission
Laravel Policies

Audit:
Activity/Audit Logging
Workflow Events

File Storage:
Private Storage

Queue:
Laravel Queue

Frontend:
Server-rendered / Filament initially
```

Family Portal technology will be selected based on UX requirements.

Possible implementation:

```text
Separate Laravel / Filament Panel

or

Laravel + Livewire

or

Dedicated Frontend
```

A separate Next.js application is not mandatory for V1.

---

# 5. Portal Architecture

Famboook contains two user-facing contexts:

```text
STAFF PORTAL

FAMILY PORTAL
```

They share:

```text
Database
Domain Models
Domain Actions
Authorization Rules
Workflow Engine
Audit Infrastructure
```

but expose different capabilities.

---

# 6. Staff Portal

Primary users:

```text
SUPER_ADMIN

ADMINISTRATOR

DATA_ENTRY

REVIEWER

SOCIAL_WORKER

REPORTS_VIEWER
```

Primary functions:

```text
Registry Management
Data Entry
Verification
Case Management
Change Request Review
Reports
Administration
```

---

# 7. Family Portal

Primary V1 user:

```text
FAMILY_USER
```

Initial recommended eligibility:

```text
Verified Current Household Head
```

Primary functions:

```text
View permitted Family information

View permitted Family members

View current residence

Submit Change Requests

Upload supporting documents

Respond to clarification

Track requests

Receive notifications
```

Family Portal does not directly modify canonical registry records.

---

# 8. Roadmap Phase Model

Each phase contains:

```text
Objective

Dependencies

Deliverables

Testing

Definition of Done
```

A phase is not complete merely because its UI exists.

---

# 9. Phase 0 — Architecture Baseline

## Objective

Freeze the minimum architecture required to safely begin implementation.

## Dependencies

```text
None
```

## Deliverables

Review:

```text
00-PROJECT-CONTEXT.md

01-PRODUCT.md

02-DATA-DICTIONARY.md

03-BUSINESS-RULES.md

04-DATABASE.md

05-WORKFLOWS.md

06-PERMISSIONS.md

07-ROADMAP.md
```

Resolve implementation-blocking pending decisions.

---

# 10. Phase 0 Critical Decisions

Must be resolved before affected implementation begins:

```text
Family Code format

Person Code format

National ID validation policy

Initial reference vocabularies

Family User activation method

Family User authentication identifier

Household Head-only Portal policy

Reviewer / Approver responsibility

Change Request initial types

Sensitive field visibility

Document storage policy
```

Not every future decision must be resolved before coding.

---

# 11. Phase 0 Definition of Done

```text
Core documents approved

No contradictory core architecture

Canonical Family/Person model agreed

Membership model agreed

Workflow model agreed

Authorization model agreed

Family Portal model agreed

Change Request model agreed

Blocking decisions identified/resolved
```

---

# 12. Phase 1 — Laravel Foundation

## Objective

Create the technical application foundation.

## Dependencies

```text
Phase 0
```

## Deliverables

```text
Laravel project

PostgreSQL connection

Environment configuration

Local development setup

Git integration

Base application configuration

Timezone strategy

Locale configuration

Arabic support

RTL preparation

Queue configuration

Logging configuration

Private file storage configuration
```

---

# 13. Development Environments

Recommended:

```text
local

testing

staging

production
```

Real Family data must not be placed in:

```text
Git

public repositories

development fixtures

automated tests
```

---

# 14. Phase 1 Testing

Verify:

```text
Application boots

PostgreSQL connection works

Migrations execute

Queue works

Storage is private

Tests execute

Arabic content renders correctly
```

---

# 15. Phase 1 Definition of Done

```text
Laravel operational

PostgreSQL operational

Base test suite operational

Environment separation established

Private storage established

Repository clean and reproducible
```

---

# 16. Phase 2 — Authentication & RBAC Foundation

## Objective

Implement Staff authentication and authorization foundation before exposing registry data.

## Dependencies

```text
Phase 1
```

## Deliverables

Install/configure:

```text
Authentication

Spatie Laravel Permission

Policies

Roles

Permissions

User activation/deactivation

Login throttling

Password reset
```

Initial roles:

```text
SUPER_ADMIN
ADMINISTRATOR
DATA_ENTRY
REVIEWER
SOCIAL_WORKER
REPORTS_VIEWER
FAMILY_USER
```

---

# 17. Permission Seeder

Create version-controlled definitions for:

```text
Roles

Permissions

Role-Permission mappings
```

Seeder must be:

```text
Repeatable

Predictable

Safe
```

---

# 18. Staff Portal Access

Create initial Staff Panel.

Verify:

```text
Staff login

Role-based navigation

Resource authorization

Inactive user denial
```

---

# 19. Phase 2 Testing

Test:

```text
Authentication

Role assignment

Permission enforcement

Policy enforcement

Inactive users

Unauthorized routes

Staff Portal access
```

---

# 20. Phase 2 Definition of Done

```text
Authentication working

Roles seeded

Permissions seeded

Policies foundation working

Staff Portal protected

Negative authorization tests passing
```

---

# 21. Phase 3 — Reference Data

## Objective

Create stable reference vocabularies before transactional registry features depend on them.

## Dependencies

```text
Phase 2
```

## Deliverables

Initial lookup tables:

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

Only verified source values should be seeded.

---

# 22. Reference Data Rules

Reference records should use:

```text
Stable Code

Arabic Label

English Label where useful

Active Flag

Sort Order
```

Avoid hard-coded display values in application logic.

---

# 23. Phase 3 Definition of Done

```text
Required lookups migrated

Approved values seeded

Reference administration authorized

Inactive values preserved

Tests passing
```

---

# 24. Phase 4 — Family & Person Registry

## Objective

Implement the two primary registry identities.

## Dependencies

```text
Phase 3
```

## Deliverables

Tables:

```text
families

persons
```

Models:

```text
Family

Person
```

Business codes:

```text
FAM-XXXXXX

PER-XXXXXX
```

Final formats follow approved configuration.

---

# 25. Family Registry Features

Implement:

```text
Create Family

View Family

Update permitted Family fields

Archive Family

Search by Family Code

Registration Source

Paper Form Number

Status
```

---

# 26. Person Registry Features

Implement:

```text
Create Person

View Person

Update Person

Archive where valid

Search by Person Code

Search by Name

Search by National ID where authorized
```

---

# 27. National ID Handling

Implement:

```text
VARCHAR storage

Normalization

Validation

Masking

Duplicate warning

Permission-controlled full visibility
```

Do not use National ID as database PK.

---

# 28. Derived Data

Do not store canonical:

```text
Age

Family Size

Child Count

Male Count

Female Count
```

unless future performance requirements justify controlled projections.

---

# 29. Phase 4 Testing

Test:

```text
Family Code uniqueness

Person Code uniqueness

National ID normalization

National ID masking

Soft deletion/archive rules

Permissions

Search scope
```

---

# 30. Phase 4 Definition of Done

```text
Family registry stable

Person registry stable

Permanent business codes working

Sensitive identity controls working

Tests passing
```

---

# 31. Phase 5 — Family Memberships & Relationships

## Objective

Connect independent Persons to Families without destroying Person identity.

## Dependencies

```text
Phase 4
```

## Deliverables

Tables:

```text
family_memberships

person_relationships
```

Implement:

```text
Add Family Member

End Membership

Change Household Head

Transfer Person

Relationship Management

Membership History
```

---

# 32. Membership Invariants

Enforce:

```text
One active primary Family membership per Person in V1

At most one active Household Head per Family

Membership end >= start

Person identity survives transfer

Historical memberships remain preserved
```

---

# 33. Domain Actions

Implement:

```text
AddFamilyMemberAction

ChangeHouseholdHeadAction

TransferFamilyMemberAction

EndFamilyMembershipAction
```

Critical actions use database transactions.

---

# 34. Phase 5 Testing

Test:

```text
One active membership

One active Head

Head change

Person transfer

Historical memberships

Concurrent Head changes

Concurrent transfers
```

---

# 35. Phase 5 Definition of Done

```text
Family membership canonical model operational

Household Head rules enforced

Transfer preserves Person identity

Relationships operational

History preserved

Concurrency tests passing
```

---

# 36. Phase 6 — Residence & Source Forms

## Objective

Implement Family location history and source-form traceability.

## Dependencies

```text
Phase 5
```

## Deliverables

Tables:

```text
family_residences

form_submissions

form_types
```

Implement:

```text
Current Residence

Residence History

Displacement Information

Source Form Number

Source Form Type

Source Document Association
```

---

# 37. Residence Action

Implement:

```text
ChangeFamilyResidenceAction
```

Transaction:

```text
Close current residence
+
Create new current residence
+
Audit
```

---

# 38. Residence Invariant

At most:

```text
One current residence per Family
```

---

# 39. Phase 6 Definition of Done

```text
Residence history operational

Current residence constraint enforced

Source forms traceable

Paper source can be linked

Tests passing
```

---

# 40. Phase 7 — Staff Data Entry

## Objective

Create the operational workflow for digitizing Family data.

## Dependencies

```text
Phase 6
```

## Deliverables

Multi-step Staff data entry:

```text
Family

Household Head

Family Members

Relationships

Residence

Health / Disability

Education / Employment

Needs

Documents

Notes

Review

Submit
```

Extended modules may be progressively enabled as their phases complete.

---

# 41. Draft Behavior

Draft records may be incomplete.

Implement:

```text
Save Draft

Resume Draft

Validation on step

Submission validation
```

---

# 42. Submission Validation

Before completion:

```text
Family exists

Household Head exists

Membership valid

Required data present

Blocking duplicate issues resolved

Source traceability present where required
```

---

# 43. Phase 7 Definition of Done

```text
Data Entry user can create complete Family record

Draft/resume works

Validation works

Submission works

Permissions enforced

Tests passing
```

---

# 44. Phase 8 — Workflow Events, Verification & Approval

## Objective

Implement controlled internal workflow.

## Dependencies

```text
Phase 7
```

## Deliverables

Table:

```text
workflow_events
```

Workflow:

```text
DRAFT
↓
DATA_ENTRY_COMPLETED
↓
UNDER_REVIEW
├── RETURNED_FOR_CORRECTION
│   ↓
│ CORRECTED
│   ↓
└── UNDER_REVIEW
    ↓
 VERIFIED
    ↓
 APPROVED
```

---

# 45. Workflow Actions

Implement:

```text
CompleteFormEntryAction

SubmitFormForReviewAction

ReturnFormForCorrectionAction

ResubmitFormAction

VerifyFormAction

ApproveFormAction
```

---

# 46. Workflow Requirements

Implement:

```text
State validation

Authorization

Maker-checker

Reason capture

Workflow Events

Audit

Transactions

Concurrency protection
```

---

# 47. Phase 8 Definition of Done

```text
All valid transitions work

Invalid transitions fail

Reviewer workflow operational

Approval operational

Workflow history preserved

Maker-checker enforced

Tests passing
```

---

# 48. Phase 9 — Health, Disability, Education & Employment

## Objective

Implement repeatable Person-level profile domains.

## Dependencies

```text
Phase 5
Phase 2 authorization
```

## Deliverables

Tables:

```text
person_health_profiles

person_health_conditions

person_disabilities

person_education

person_employment
```

---

# 49. Sensitive Data Controls

Health and disability require:

```text
Dedicated permissions

Restricted views

Secure serialization

Audit where required
```

---

# 50. Phase 9 Definition of Done

```text
Repeatable records supported

History supported

Sensitive access controlled

No fixed paper-row limits

Tests passing
```

---

# 51. Phase 10 — Assessments

## Objective

Separate point-in-time assessments from permanent registry identity.

## Dependencies

```text
Phase 8
Phase 9 where relevant
```

## Deliverables

Table:

```text
assessments
```

Workflow:

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

---

# 52. Assessment Rule

Assessment answers must not silently overwrite canonical registry fields.

Canonical changes use controlled Domain Actions.

---

# 53. Phase 10 Definition of Done

```text
Multiple assessments per Family supported

Assessment history preserved

Assessment workflow operational

Canonical data remains independent

Tests passing
```

---

# 54. Phase 11 — Needs & Assistance

## Objective

Implement case-management needs and assistance without conflating them.

## Dependencies

```text
Phase 10
```

## Deliverables

Tables:

```text
family_needs

assistance_records
```

Need workflow:

```text
IDENTIFIED
↓
VERIFIED
↓
ACTIVE
├── PARTIALLY_MET
└── MET
    ↓
 CLOSED
```

---

# 55. Assistance Rules

Assistance:

```text
May link to Family

May link to Person

May link to Need

Does not automatically close Need
```

---

# 56. Phase 11 Definition of Done

```text
Needs operational

Assistance operational

Need lifecycle works

Assistance history preserved

No automatic eligibility decisions

Tests passing
```

---

# 57. Phase 12 — Documents & Case Notes

## Objective

Implement secure evidence and case documentation.

## Dependencies

```text
Phase 8
```

## Deliverables

```text
documents

person_notes

case_notes
```

Implement:

```text
Private Upload

Authorized Download

Document Type

Verification Status

Confidential Notes

Case Notes

Document History
```

---

# 58. Document Security

Files must not be publicly accessible.

Flow:

```text
Authenticate
↓
Authorize
↓
Stream / Temporary Authorized Access
```

---

# 59. Phase 12 Definition of Done

```text
Private storage enforced

Unauthorized download denied

Document verification separated from upload

Confidential notes protected

Tests passing
```

---

# 60. Phase 13 — Family User Identity & Access

## Objective

Create the secure identity bridge between system User and registry Person.

## Dependencies

```text
Phase 2
Phase 5
Phase 12
```

## Deliverables

Implement:

```text
FAMILY_USER role

User-Person Links

Identity Verification

Family Eligibility

Activation

Suspension

Ending Access

Family Scope Resolver
```

---

# 61. Family User Resolution

Authorization chain:

```text
Authenticated User
      ↓
Active User-Person Link
      ↓
Person
      ↓
Active Family Membership
      ↓
Household Head Eligibility
      ↓
Authorized Family
```

---

# 62. Initial Family User Policy

Recommended V1:

```text
Current verified Household Head only
```

Architecture must remain extensible for future:

```text
Guardian

Authorized Representative

Other approved adult member
```

---

# 63. Family User Activation

Implement approved activation process.

Conceptually:

```text
Account
↓
Identity Verification
↓
Existing Person Match
↓
User-Person Link Verification
↓
Family Eligibility
↓
Activation
```

---

# 64. Family User Lifecycle

```text
PENDING_VERIFICATION
↓
VERIFIED
↓
ACTIVE
├── SUSPENDED
└── ENDED
```

---

# 65. Automatic Access Reevaluation

Trigger authorization reevaluation after:

```text
Household Head Change

Person Transfer

Membership End

Death

Family Archive

User Suspension
```

---

# 66. Phase 13 Security Tests

Test:

```text
Unlinked User denied

Wrong Person denied

Non-Head denied where Head-only

Old Head loses eligibility

Family Code alone grants nothing

Person Code alone grants nothing

Cross-Family access denied
```

---

# 67. Phase 13 Definition of Done

```text
Family User identity verified

User-Person link operational

Family scope server-side

Head eligibility enforced

Lifecycle operational

IDOR tests passing
```

---

# 68. Phase 14 — Family Portal

## Objective

Provide a simple secure self-service experience for authorized Family Users.

## Dependencies

```text
Phase 13
```

## Deliverables

Family Portal pages:

```text
Login

Dashboard

Family Summary

Family Members

Current Residence

My Requests

Documents where permitted

Notifications

Account
```

---

# 69. Family Portal UX

Family Portal should prioritize:

```text
Arabic

RTL

Mobile-first design

Simple navigation

Low bandwidth

Clear status messages

Accessible forms
```

---

# 70. Family Portal Data Exposure

Initial safe baseline:

```text
Family Summary

Basic member information

Current residence

Own requests

Permitted documents

Notifications
```

Restricted by default:

```text
Full National IDs of other members

Health details

Disability details

Confidential notes

Internal workflow metadata

Audit logs

Staff-only documents
```

---

# 71. Phase 14 Definition of Done

```text
Family User can log in

Authorized Family displayed

Unauthorized Families inaccessible

Sensitive fields filtered

Mobile/RTL tested

No canonical editing exposed

Security tests passing
```

---

# 72. Phase 15 — Change Request Engine

## Objective

Allow Family Users to propose registry changes without directly modifying canonical records.

## Dependencies

```text
Phase 14
Domain Actions from previous phases
Workflow Events
Documents
```

## Deliverables

Tables:

```text
change_requests

change_request_items / structured payload support
```

Final physical design follows `04-DATABASE.md`.

---

# 73. Change Request Workflow

Implement:

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
│  UNDER_REVIEW
│
├── REJECTED
│
└── APPROVED
       ↓
    APPLIED
```

---

# 74. Initial Request Types

Recommended:

```text
CONTACT_UPDATE

RESIDENCE_UPDATE

PERSON_CORRECTION

ADD_FAMILY_MEMBER

BIRTH_REPORT

DEATH_REPORT

MARRIAGE_UPDATE

DOCUMENT_UPDATE
```

Potential later V1 types:

```text
MEMBERSHIP_CHANGE

HOUSEHOLD_HEAD_CHANGE
```

after policy approval.

---

# 75. Change Request Application

Critical rule:

```text
Family Request
      ↓
Approval
      ↓
Existing Domain Action
      ↓
Canonical Registry
```

Do not implement duplicate business logic specifically for Family Portal.

---

# 76. APPROVED vs APPLIED

Maintain distinction:

```text
APPROVED
=
Authorized
```

```text
APPLIED
=
Canonical transaction successfully completed
```

---

# 77. Application Engine

Implement:

```text
ApplyChangeRequestAction
```

Responsibilities:

```text
Authorize

Lock Request

Check APPROVED

Revalidate canonical data

Run type-specific Domain Action

Audit changes

Create Workflow Event

Mark APPLIED

Commit
```

---

# 78. Application Failure

V1 baseline:

```text
Rollback transaction

Remain APPROVED

Log failure

Allow controlled retry
```

Never partially apply a request.

---

# 79. Add Family Member Request

Implementation must:

```text
Run Duplicate Detection

Reuse existing Person if confirmed

Create Person only when required

Create Membership

Preserve identity rules
```

---

# 80. Birth Request

Implementation must:

```text
Validate newborn data

Run Duplicate Detection

Create Person after approval/application

Create Family Membership

Create verified relationships
```

---

# 81. Death Request

Implementation must:

```text
Validate Person

Validate death information

Apply life status

Preserve Person

Trigger Head review if needed

Trigger Portal access reevaluation
```

---

# 82. Phase 15 Testing

Test:

```text
Family User can create allowed request

Cannot directly modify registry

Cannot approve own request

Reviewer can return request

Family User can resubmit

Approval does not falsely imply application

Application modifies canonical data

Double application blocked

Failed application rolls back

Wrong Family denied
```

---

# 83. Phase 15 Definition of Done

```text
Change Request workflow complete

Initial request types operational

Review queue operational

Approval operational

Application engine operational

Audit complete

Idempotency enforced

Security tests passing
```

---

# 84. Phase 16 — Search & Duplicate Management

## Objective

Provide safe registry discovery and duplicate review.

## Dependencies

```text
Phase 4
Phase 5
Phase 15
```

## Deliverables

Search:

```text
Family Code

Person Code

Name

National ID

Mobile

Paper Form Number
```

subject to permissions.

---

# 85. Duplicate Detection

Implement classifications:

```text
EXACT

PROBABLE

POSSIBLE
```

Signals may include:

```text
National ID

Name

Birth Date

Gender

Mobile

Family context
```

---

# 86. Duplicate Review

Outcomes:

```text
NOT_DUPLICATE

SAME_PERSON

UNRESOLVED
```

No automatic Person merge.

---

# 87. Phase 16 Definition of Done

```text
Search permission-aware

Sensitive search protected

Duplicate warnings operational

Human resolution operational

No automatic merge

Tests passing
```

---

# 88. Phase 17 — Reports & Dashboard

## Objective

Provide operational and management reporting from canonical data.

## Dependencies

```text
Stable registry modules
```

## Deliverables

Potential dashboards:

```text
Total Families

Total Persons

Family Size

Gender Distribution

Age Distribution

Children

Elderly

Disability

Chronic Conditions

Residence

Displacement

Education

Employment

Needs

Assistance
```

---

# 89. Derived Statistics

Calculate from canonical data.

Examples:

```text
family_size

children_under_5

elderly_count

disabled_count
```

Avoid manually maintained duplicate counters.

---

# 90. Report Security

Reports must respect:

```text
Permission

Data Scope

Field restrictions

Sensitive-data policy
```

Aggregate access does not automatically grant person-level access.

---

# 91. Phase 17 Definition of Done

```text
Core dashboards accurate

Derived statistics validated

Permissions enforced

Sensitive reporting controlled

Tests passing
```

---

# 92. Phase 18 — Import & Export

## Objective

Support controlled data movement where operationally required.

## Dependencies

```text
Stable registry

Duplicate detection

Authorization
```

## Deliverables

Potential import:

```text
Excel/CSV

Preview

Validation

Duplicate Detection

Error Report

Batch Traceability
```

---

# 93. Import Rule

Imports must use the same domain rules as normal application operations.

No direct database bypass.

---

# 94. Export

Implement:

```text
Basic Export

Personal Data Export

Sensitive Export
```

based on permissions.

---

# 95. Export Audit

Record:

```text
User

Scope

Filters

Fields

Timestamp

Reason where required
```

---

# 96. Phase 18 Definition of Done

```text
Import validated

Import traceable

Duplicate rules respected

Export scoped

Sensitive export protected

Exports audited
```

---

# 97. Phase 19 — Security Hardening

## Objective

Perform dedicated security review before pilot.

## Dependencies

```text
Core V1 features complete
```

## Review Areas

```text
Authentication

Authorization

IDOR

CSRF

XSS

SQL Injection

Mass Assignment

File Uploads

Private Downloads

Sensitive Data

Rate Limiting

Session Security

Password Recovery

OTP if used

Audit

Exports

Logs

Backups
```

---

# 98. Sensitive Data Review

Verify:

```text
National ID

Health

Disability

Documents

Mobile

Residence

Confidential Notes
```

are not exposed through:

```text
API

Logs

Error pages

Exports

Notifications

URLs

Frontend payloads
```

without authorization.

---

# 99. File Upload Security

Implement:

```text
Allowed MIME types

Size limits

Randomized/private filenames

Private storage

Authorization on retrieval

Malicious upload protections where available
```

---

# 100. Family Portal Security

Dedicated testing:

```text
IDOR

Cross-Family access

Member access

Change Request ownership

Document ownership

User-Person link

Head eligibility

Old Head access

Transfer access

Death access

Enumeration

Rate limiting
```

---

# 101. Phase 19 Definition of Done

```text
Critical security findings resolved

IDOR tests passing

Sensitive data review complete

Private documents verified

Rate limiting configured

Production security checklist complete
```

---

# 102. Phase 20 — Comprehensive Testing & UAT

## Objective

Validate system behavior with realistic workflows before deployment.

## Dependencies

```text
Phase 19
```

## Automated Testing

Required categories:

```text
Unit Tests

Feature Tests

Authorization Tests

Workflow Tests

Database Constraint Tests

Concurrency Tests

Security Tests
```

---

# 103. End-to-End Scenarios

Test complete scenarios:

```text
New Family Registration

Existing Person Reuse

Duplicate Candidate

Household Head Change

Person Transfer

Residence Change

Form Correction

Verification

Assessment

Need

Assistance

Family User Activation

Family Portal Access

Add Member Request

Birth Report

Death Report

Change Request Clarification

Change Request Approval

Change Request Application
```

---

# 104. UAT

User Acceptance Testing should include representatives from:

```text
Data Entry

Reviewer

Administrator

Social Worker

Family User
```

where operationally possible.

---

# 105. UAT Data

Use:

```text
Fictional

Synthetic

or properly authorized/anonymized
```

data.

Avoid uncontrolled copies of production Family data.

---

# 106. Phase 20 Definition of Done

```text
Critical automated tests pass

End-to-end scenarios pass

UAT completed

Critical UAT issues resolved

Authorization matrix validated

Data integrity validated
```

---

# 107. Phase 21 — Deployment & Pilot

## Objective

Deploy a controlled production-like pilot before broad adoption.

## Dependencies

```text
Phase 20
```

## Deliverables

```text
Production infrastructure

SSL

Database

Private storage

Queue worker

Scheduler

Backups

Monitoring

Error logging

Deployment procedure

Rollback procedure
```

---

# 108. Pilot Strategy

Do not begin with the entire target population.

Recommended:

```text
Small controlled Family sample
      ↓
Data Entry
      ↓
Verification
      ↓
Family Portal activation
      ↓
Change Request testing
      ↓
Operational feedback
      ↓
Corrections
```

---

# 109. Pilot Sample

Exact sample size is an operational decision.

The pilot should be large enough to include:

```text
Small Family

Large Family

Missing National ID

Possible duplicate

Displaced Family

Health case

Disability case

Family with multiple needs

Household Head change

Family User Portal

Change Request
```

---

# 110. Pilot Monitoring

Monitor:

```text
Data Entry time

Validation errors

Duplicate frequency

Reviewer workload

Change Request volume

Family User usability

Authorization failures

Performance

Support issues

Data quality
```

---

# 111. Phase 21 Definition of Done

```text
Deployment stable

Backups verified

Pilot users trained

Pilot completed

Critical pilot issues resolved

Production readiness approved
```

---

# 112. Phase 22 — Production Rollout

## Objective

Expand from pilot to operational use.

## Dependencies

```text
Successful Pilot
```

## Activities

```text
User onboarding

Staff training

Family User activation

Controlled data migration

Operational support

Monitoring

Backup verification

Security monitoring

Data quality monitoring
```

---

# 113. Production Rollout Strategy

Prefer staged expansion:

```text
Pilot
↓
Limited Production
↓
Expanded Production
↓
Full Operational Use
```

rather than one-time full migration.

---

# 114. Production Definition of Done

```text
System stable

Operational roles assigned

Support process active

Backups tested

Security controls active

Monitoring active

Data quality process active

Change management process active
```

---

# 115. Cross-Phase Audit Requirement

Audit infrastructure must not be postponed until the end.

Introduce audit as soon as critical canonical data begins to exist.

Audit at minimum:

```text
Person identity changes

National ID changes

Membership changes

Household Head changes

Residence changes

Workflow decisions

Change Request application

User-Person link changes

Permission changes

Sensitive exports
```

---

# 116. Cross-Phase Workflow Requirement

Do not implement workflow states as UI labels only.

Every workflow requires:

```text
State

Allowed Transitions

Authorization

Domain Validation

Workflow Event

Audit

Tests
```

---

# 117. Cross-Phase Authorization Requirement

Every new module must answer:

```text
Who can View?

Who can Create?

Who can Update?

Who can Archive?

Which records?

Which fields?

Which workflow states?

Which sensitive values?

What is audited?
```

before the feature is considered complete.

---

# 118. Cross-Phase Database Requirement

Critical business invariants should be protected at the strongest reasonable layer:

```text
Database Constraint

Backend Domain Rule

Authorization

UI Validation
```

Do not rely on UI alone.

---

# 119. Cross-Phase Data Privacy Requirement

Every feature involving:

```text
National ID

Health

Disability

Documents

Residence

Mobile

Case Notes
```

requires explicit privacy review.

---

# 120. Cross-Phase Family Portal Requirement

No Family Portal feature may assume:

```text
FAMILY_USER role
=
access to all Family data
```

Authorization must always resolve:

```text
User

Person Link

Membership

Family

Eligibility

Field Policy
```

---

# 121. Cross-Phase Change Request Requirement

No Change Request type is complete until it defines:

```text
Payload

Validation

Evidence

Risk

Reviewer

Approver

Domain Action

Application behavior

Audit

Notifications

Tests
```

---

# 122. Cross-Phase Testing Requirement

Do not leave tests until Phase 20.

Every phase includes its own automated tests.

Phase 20 is:

```text
Comprehensive Validation
```

not:

```text
First time testing.
```

---

# 123. Cross-Phase Documentation Requirement

When implementation changes an approved architecture decision:

```text
Update documentation
      ↓
Record decision
      ↓
Implement
```

Do not allow documentation and implementation to silently diverge.

---

# 124. Initial MVP Definition

Famboook V1 MVP should provide:

```text
Secure Staff Authentication

RBAC

Family Registry

Person Registry

Membership History

Household Head

Person Relationships

Residence

Paper Form Traceability

Staff Data Entry

Verification

Approval

Health / Disability

Education / Employment

Assessments

Needs

Assistance

Documents

Case Notes

Family User Identity

Family Portal

Change Requests

Search

Duplicate Detection

Core Reports

Audit

Backup

Security Controls
```

---

# 125. V1 Family Portal MVP

Must include:

```text
Authentication

Verified User-Person Link

Family Scope

Family Summary

Member Summary

Residence

Change Request Creation

Request Tracking

Clarification Response

Supporting Documents

Notifications
```

---

# 126. V1 Change Request MVP

Recommended initial types:

```text
CONTACT_UPDATE

RESIDENCE_UPDATE

PERSON_CORRECTION

ADD_FAMILY_MEMBER

BIRTH_REPORT

DEATH_REPORT

MARRIAGE_UPDATE

DOCUMENT_UPDATE
```

---

# 127. V1 Staff Portal MVP

Must support:

```text
Family Management

Person Management

Membership Management

Data Entry

Review

Verification

Approval

Assessments

Needs

Assistance

Documents

Case Notes

Change Request Review

Family User Administration

Reports
```

---

# 128. Explicitly Deferred Features

Unless later prioritized:

```text
Native Mobile App

Full Offline Synchronization

Automatic Person Merge

AI Eligibility Decisions

Automated Assistance Eligibility

Public Family Profiles

Anonymous Public Registration

Accounting

Payment Processing

Biometric Identification

Complex External Integrations

Advanced GIS

Advanced Predictive Analytics
```

---

# 129. Future Candidate — Authorized Representatives

Architecture supports future:

```text
Guardian

Authorized Representative

Adult Family Member
```

without redefining Person identity.

This is not enabled until business and authorization rules are approved.

---

# 130. Future Candidate — Dedicated Frontend

A dedicated frontend may later be justified for:

```text
Advanced Family Portal UX

Public-facing services

PWA

Offline-assisted workflows

Complex dashboards
```

The API/domain architecture should permit this without requiring it for V1.

---

# 131. Future Candidate — Mobile/PWA

Potential:

```text
PWA

Installable Family Portal

Offline read cache

Draft submission support
```

must undergo security review because of sensitive Family data.

---

# 132. Future Candidate — Integrations

Possible future integrations:

```text
SMS

Email

External Identity Verification

External Assistance Systems

Government registries

Humanitarian platforms
```

No integration should bypass Famboook authorization and audit rules.

---

# 133. Future Candidate — Advanced Duplicate Resolution

Potential:

```text
Similarity scoring

Phonetic Arabic matching

Merge candidate queue

Controlled manual merge
```

Automatic merge remains prohibited unless explicitly redesigned and approved.

---

# 134. Development Priority Rules

When choosing between features:

```text
Data Integrity
>
Workflow Correctness
>
Security
>
Operational Necessity
>
UX Enhancement
>
Visual Polish
```

This does not mean UX is unimportant.

It means visual convenience must not weaken registry integrity.

---

# 135. Recommended Development Milestones

For project tracking, phases may be grouped into milestones:

```text
M1 — Foundation

M2 — Core Registry

M3 — Staff Operations

M4 — Case Management

M5 — Family Self-Service

M6 — Reporting & Data Operations

M7 — Security & Quality

M8 — Pilot & Production
```

---

# 136. M1 — Foundation

Includes:

```text
Phase 0
Phase 1
Phase 2
Phase 3
```

Result:

```text
Stable technical and authorization foundation.
```

---

# 137. M2 — Core Registry

Includes:

```text
Phase 4
Phase 5
Phase 6
```

Result:

```text
Canonical Family/Person registry operational.
```

---

# 138. M3 — Staff Operations

Includes:

```text
Phase 7
Phase 8
Phase 9
```

Result:

```text
Staff can digitize, review, verify, and maintain registry data.
```

---

# 139. M4 — Case Management

Includes:

```text
Phase 10
Phase 11
Phase 12
```

Result:

```text
Assessments, Needs, Assistance, Documents, and Notes operational.
```

---

# 140. M5 — Family Self-Service

Includes:

```text
Phase 13
Phase 14
Phase 15
```

Result:

```text
Verified Household Head can securely interact with Family data through controlled Change Requests.
```

---

# 141. M6 — Reporting & Data Operations

Includes:

```text
Phase 16
Phase 17
Phase 18
```

Result:

```text
Search, duplicate review, reports, imports, and exports operational.
```

---

# 142. M7 — Security & Quality

Includes:

```text
Phase 19
Phase 20
```

Result:

```text
System security and operational behavior validated.
```

---

# 143. M8 — Pilot & Production

Includes:

```text
Phase 21
Phase 22
```

Result:

```text
Controlled production adoption.
```

---

# 144. Milestone Dependency

```text
M1
↓
M2
↓
M3
↓
M4
↓
M5
↓
M6
↓
M7
↓
M8
```

Some individual engineering tasks may overlap where dependencies permit.

---

# 145. Issue Tracking Structure

Recommended issue hierarchy:

```text
Milestone
   ↓
Epic
   ↓
Feature
   ↓
User Story
   ↓
Technical Task
   ↓
Test
```

Example:

```text
M5 — Family Self-Service

Epic:
Change Requests

Feature:
Birth Report

User Story:
As a Family User,
I want to report a newborn
so the Family registry can be updated after verification.

Tasks:
Migration
Model
Policy
Action
Form
Review UI
Application Handler
Audit
Tests
```

---

# 146. Branch Strategy

Recommended simple strategy:

```text
main
```

for stable code.

Feature branches:

```text
feature/family-registry

feature/person-registry

feature/family-memberships

feature/change-requests
```

Bug fixes:

```text
fix/<description>
```

Keep strategy simple while team size is small.

---

# 147. Commit Strategy

Use clear commits:

```text
feat: add family registry

feat: implement family memberships

feat: add household head change action

feat: add family portal scope resolver

feat: add change request workflow

fix: prevent duplicate active household head

test: add family portal authorization tests

docs: update family user workflow
```

---

# 148. Database Migration Strategy

Prefer:

```text
Small focused migrations

Foreign keys

Indexes

Constraints

Reversible migrations where practical
```

Do not manually edit production schema outside controlled migration procedures.

---

# 149. Seeder Strategy

Seed:

```text
Roles

Permissions

Approved Reference Data

Development-only fictional fixtures
```

Never seed real Family information into source control.

---

# 150. Backup Strategy

Before production define:

```text
Database backup frequency

File backup frequency

Retention

Encryption

Restore procedure

Restore testing
```

A backup is not considered reliable until restore is tested.

---

# 151. Monitoring Strategy

Production monitoring should cover:

```text
Application errors

Queue failures

Failed logins

Suspicious access

Storage

Database health

Backup status

Performance

Critical workflow failures
```

---

# 152. Logging Strategy

Logs must be useful without leaking sensitive data.

Do not routinely log:

```text
Full National IDs

Passwords

OTP codes

Medical details

Uploaded document content

Authentication secrets
```

---

# 153. Performance Baseline

Initial design should support at least the expected operational population without architectural redesign.

Use:

```text
Indexes

Pagination

Eager loading

Query optimization

Background jobs

Caching where safe
```

based on measured need.

---

# 154. Performance Testing

Test representative:

```text
Family list

Person search

Large Family profile

Reports

Change Request queue

Duplicate search
```

before production rollout.

---

# 155. Large Family Handling

No UI or database design should assume a fixed number of Family members.

Use:

```text
Pagination

Search

Filtering

Repeatable records
```

where needed.

---

# 156. Arabic & RTL Requirement

Staff and Family-facing interfaces should support:

```text
Arabic

RTL

Arabic names

Arabic search behavior

Arabic validation messages
```

from early development, not as a final cosmetic phase.

---

# 157. Accessibility

Family Portal should consider:

```text
Readable typography

Clear contrast

Large touch targets

Clear errors

Keyboard usability where relevant

Simple language
```

---

# 158. Low-Bandwidth Requirement

Family Portal should avoid unnecessary:

```text
Large JavaScript bundles

Large images

Heavy animations

Repeated network calls
```

because self-service should remain usable under constrained connectivity.

---

# 159. Error Handling

User-facing errors should not expose:

```text
Stack traces

SQL

Server paths

Secrets

Internal IDs unnecessarily
```

Production error pages must be safe.

---

# 160. Data Migration Preparation

If historical Family data exists in:

```text
Excel

Paper Forms

Previous databases
```

do not import immediately.

First:

```text
Profile Source Data

Map Fields

Normalize Values

Define Duplicate Strategy

Run Dry Import

Review Errors

Reconcile

Then Import
```

---

# 161. Paper Data Migration

Paper entry should preserve:

```text
Source Form Number

Source Document

Entry Actor

Entry Date

Verification Actor

Verification Date
```

where available.

---

# 162. Production Data Quality

Create operational reports for:

```text
Missing Household Head

Multiple Head anomalies

Missing National IDs where expected

Possible duplicates

Incomplete relationships

Missing current residence

Unresolved Change Requests

Approved but unapplied requests
```

---

# 163. Exception Queues

Recommended:

```text
Duplicate Review

Head Review Required

Returned Forms

Pending Verification

Change Requests

Clarification Required

Approved Awaiting Application

Document Verification

Data Quality Exceptions
```

---

# 164. Deployment Gate

Production deployment requires:

```text
Migrations reviewed

Tests passing

Authorization tests passing

Backup available

Rollback plan

Environment secrets configured

Debug disabled

Storage protected

Queue running

Scheduler running

Monitoring active
```

---

# 165. Release Gate

A feature is releasable only when:

```text
Business rules implemented

Authorization implemented

Validation implemented

Audit implemented where required

Tests pass

Documentation synchronized

No critical security issue
```

---

# 166. V1 Completion Gate

V1 is complete when:

```text
Core Registry operational

Staff workflow operational

Case Management operational

Family Portal operational

Change Requests operational

Sensitive data protected

Reports operational

Audit operational

Backups operational

Security review completed

UAT completed

Pilot completed successfully
```

---

# 167. Post-V1 Review

After initial production use, review:

```text
Actual Family Portal adoption

Change Request types

Review workload

Duplicate patterns

Data quality

Performance

Security events

User feedback

Need for PWA

Need for external integrations

Need for additional Family Users
```

---

# 168. Roadmap Governance

Roadmap changes should be intentional.

When adding a major feature:

```text
Identify Business Need
↓
Review Product Impact
↓
Review Data Impact
↓
Review Database Impact
↓
Review Workflow Impact
↓
Review Permission Impact
↓
Update Roadmap
↓
Implement
```

---

# 169. Architecture Governance

Do not bypass approved architecture because a UI shortcut appears easier.

Examples:

Do not add:

```text
persons.family_id
```

as a new canonical shortcut if membership is canonical.

Do not let:

```text
FAMILY_USER
```

directly update Persons because it is easier than Change Requests.

Do not store:

```text
family_size
```

manually merely because a report needs it.

---

# 170. Roadmap Invariants

```text
RM-INV-001
Person identity is independent from Family membership.

RM-INV-002
Family Membership is the canonical Family-Person association.

RM-INV-003
Historical memberships are preserved.

RM-INV-004
Family Portal never directly modifies canonical registry data.

RM-INV-005
Change Requests apply through approved Domain Actions.

RM-INV-006
Authorization is implemented before sensitive features are exposed.

RM-INV-007
Audit is implemented alongside critical data operations.

RM-INV-008
Automated testing occurs throughout development.

RM-INV-009
Family Portal authorization derives from verified relationships.

RM-INV-010
Sensitive data is denied by default.

RM-INV-011
Documents remain private.

RM-INV-012
Duplicate Persons are never automatically merged.

RM-INV-013
Derived statistics come from canonical data.

RM-INV-014
Paper form structure does not define database structure.

RM-INV-015
No fixed limit exists for digital Family members.

RM-INV-016
Workflow transitions are enforced server-side.

RM-INV-017
APPROVED and APPLIED remain distinct for Change Requests.

RM-INV-018
Family User and Staff authorization remain separated.

RM-INV-019
Production follows successful security validation and UAT.

RM-INV-020
Full rollout follows controlled pilot.
```

---

# 171. Approved Roadmap Decisions

### RM-ADR-001

Implementation is phased rather than built as one monolithic release.

### RM-ADR-002

Laravel and PostgreSQL form the V1 backend foundation.

### RM-ADR-003

Filament is the initial Staff Portal direction.

### RM-ADR-004

A dedicated Next.js frontend is not required for initial V1.

### RM-ADR-005

Core Registry is implemented before Case Management.

### RM-ADR-006

Staff operational workflows are implemented before Family self-service.

### RM-ADR-007

Family Portal is part of V1.

### RM-ADR-008

Change Requests are part of V1.

### RM-ADR-009

User-Person identity linking precedes Family Portal access.

### RM-ADR-010

Family Portal uses existing canonical Domain Actions.

### RM-ADR-011

Security testing is a dedicated phase but security is implemented throughout development.

### RM-ADR-012

Testing occurs during every phase.

### RM-ADR-013

Pilot precedes broad production rollout.

### RM-ADR-014

Real personal data is not committed to Git.

### RM-ADR-015

Private file storage is established from the foundation.

### RM-ADR-016

Reports use canonical data.

### RM-ADR-017

Imports do not bypass domain rules.

### RM-ADR-018

Sensitive exports are permission-controlled and audited.

### RM-ADR-019

Arabic and RTL are first-class requirements.

### RM-ADR-020

Family Portal should be mobile-friendly and low-bandwidth aware.

---

# 172. Remaining Pre-Implementation Decisions

Before or during the relevant phase, finalize:

```text
Family Code exact format

Person Code exact format

National ID exact validation

Reference vocabularies

Family User activation method

Family User login identifier

OTP requirements

Household Head-only policy confirmation

Multiple Family User policy

Sensitive Family Portal field visibility

Change Request risk matrix

Reviewer / Approver separation

Approver / Applier separation

Initial request types

Document evidence requirements

Notification channels

Backup retention

Deployment environment
```

These decisions do not all block Phase 1.

They must be resolved before the affected feature is implemented.

---

# 173. Immediate Next Development Step

After this roadmap is approved:

```text
Documentation Phase
        ↓
COMPLETE
```

Then begin:

```text
PHASE 0
Architecture Baseline Review
```

followed immediately by:

```text
PHASE 1
Laravel Foundation
```

The first implementation work should therefore be:

```text
1. Review project repository

2. Confirm Laravel/PHP/PostgreSQL environment

3. Create backend Laravel application

4. Configure PostgreSQL

5. Configure environment files

6. Configure Arabic/RTL baseline

7. Configure private storage

8. Configure queues

9. Configure testing

10. Commit clean foundation
```

Do not begin with:

```text
Family UI

Dashboard charts

Family Portal screens

Change Request forms
```

before the underlying architecture exists.

---

# 174. Project Status After Roadmap Approval

```text
DISCOVERY
    ✓

PRODUCT DEFINITION
    ✓

DATA DICTIONARY
    ✓

BUSINESS RULES
    ✓

DATABASE ARCHITECTURE
    ✓

WORKFLOWS
    ✓

PERMISSIONS
    ✓

IMPLEMENTATION ROADMAP
    ✓

        ↓

DEVELOPMENT
```

---

# 175. Document Status

```text
Project: Famboook
Document: Implementation Roadmap
Version: 1.1
Status: APPROVED
Date: 2026-09-22
```

---

# 176. Change Log

| Version | Date | Status | Description |
|---|---|---|---|
| 1.0 | 2026-09-22 | Superseded | Initial implementation roadmap before full Family Portal architecture |
| 1.1 | 2026-09-22 | Approved | Integrated Family User identity, Family Portal, Change Requests, authorization, workflow application, security testing, pilot strategy, phase dependencies, milestone structure, and production rollout |

---

# 177. Final Roadmap

```text
PHASE 0
Architecture Baseline
        ↓
PHASE 1
Laravel Foundation
        ↓
PHASE 2
Authentication & RBAC
        ↓
PHASE 3
Reference Data
        ↓
PHASE 4
Family & Person Registry
        ↓
PHASE 5
Memberships & Relationships
        ↓
PHASE 6
Residence & Source Forms
        ↓
PHASE 7
Staff Data Entry
        ↓
PHASE 8
Verification & Approval
        ↓
PHASE 9
Health / Disability / Education / Employment
        ↓
PHASE 10
Assessments
        ↓
PHASE 11
Needs & Assistance
        ↓
PHASE 12
Documents & Case Notes
        ↓
PHASE 13
Family User Identity & Access
        ↓
PHASE 14
Family Portal
        ↓
PHASE 15
Change Requests
        ↓
PHASE 16
Search & Duplicate Management
        ↓
PHASE 17
Reports & Dashboard
        ↓
PHASE 18
Import & Export
        ↓
PHASE 19
Security Hardening
        ↓
PHASE 20
Testing & UAT
        ↓
PHASE 21
Deployment & Pilot
        ↓
PHASE 22
Production Rollout
```

---

# 178. Development Start Point

The documentation baseline is complete.

Development starts from:

```text
Phase 0
→ Architecture Baseline Review
```

then:

```text
Phase 1
→ Laravel Foundation
```

Every subsequent implementation step must reference this roadmap and the relevant architecture document before development begins.