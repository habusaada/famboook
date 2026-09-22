# Famboook
## Project Context & Architecture Baseline

**Document:** `00-PROJECT-CONTEXT.md`  
**Version:** 1.2  
**Status:** Approved  
**Last Updated:** 2026-09-22  
**Project:** Famboook — Family Registry & Case Management System

---

# 1. Purpose

This document is the primary orientation document for Famboook.

It provides a concise but authoritative overview of:

- Product purpose
- Product boundaries
- Architecture
- Technology stack
- Domain model
- Security model
- User experiences
- Workflow model
- Repository structure
- Deployment topology
- Implementation strategy
- Core invariants
- Approved architecture decisions
- Current project status
- Immediate next implementation steps

Detailed rules remain defined in the specialized project documents.

This document must not replace those documents.

It acts as the entry point to them.

---

# 2. Project Name

```text
Famboook
```

Famboook is a:

```text
Family Registry
+
Family Data Management Platform
+
Case Management System
+
Controlled Family Self-Service Platform
```

---

# 3. Product Vision

Famboook provides a trusted digital registry for managing Family and Person information while preserving:

```text
Identity

Family structure

Relationships

History

Residence

Assessments

Needs

Assistance

Documents

Verification

Workflow

Auditability
```

The system is designed to replace fragmented paper/spreadsheet processes with a structured, secure, historically traceable platform.

---

# 4. Product Objective

The core objective is to create a reliable source of truth for Family information.

Famboook should answer questions such as:

```text
Who is this Person?

Which Family do they currently belong to?

What is their relationship to other members?

Who is the current Household Head?

Where does the Family currently reside?

What was their previous residence?

What verified documents exist?

What assessments were conducted?

What needs were identified?

What assistance was provided?

What changes were requested?

Who verified or approved a change?

What changed and when?
```

---

# 5. Product Philosophy

Famboook is not designed as:

```text
Paper Form
    ↓
Database Columns
    ↓
Generic CRUD
```

It is designed as:

```text
Real-World Entity
        ↓
Canonical Domain Model
        ↓
Business Rules
        ↓
Controlled Domain Actions
        ↓
Canonical Database
        ↓
Authorized API Representation
        ↓
Purpose-Built User Experience
```

---

# 6. Core Domain Principle

The Person is an independent entity.

The Family is an independent entity.

Their relationship is represented through:

```text
Family Membership
```

Therefore:

```text
Family
  ↓
Family Membership
  ↓
Person
```

A Person is not stored as:

```text
child_1

child_2

wife_1

wife_2
```

inside a Family record.

---

# 7. Paper Forms

Paper forms are:

```text
Data Sources
```

not:

```text
Database Models
```

Famboook preserves source traceability without forcing the database to reproduce the physical paper layout.

---

# 8. Canonical Registry

Canonical data represents the official current/historical registry state.

Examples:

```text
Person identity

Family membership

Household Head

Residence

Life status

Verified documents
```

Canonical data may only be changed through authorized domain operations.

---

# 9. Proposed Data

Proposed data represents information that has not yet become official registry truth.

Examples:

```text
Family User Change Request

Imported unverified record

Reported death

Proposed residence

Proposed new Family member
```

Proposed data must remain separate from canonical data until approved and applied.

---

# 10. Assessments

Assessments represent:

```text
Point-in-Time Observations
```

They are not automatically canonical Person/Family data.

An Assessment may identify information that later leads to an authorized canonical update.

---

# 11. Repeatable Information

Repeatable information must use child entities.

Examples:

```text
Health Conditions

Disabilities

Education

Employment

Needs

Assistance

Documents

Residences

Assessments

Relationships
```

Do not add fixed numbered columns for repeatable information.

---

# 12. History Principle

Famboook preserves meaningful historical state.

Examples:

```text
Family membership history

Residence history

Household Head changes

Assessment history

Need history

Assistance history

Workflow history

Audit history
```

Real-world changes should not normally destroy previous valid historical information.

---

# 13. Derived Data

Derived values should normally be calculated.

Examples:

```text
Age

Family Member Count

Children Count

Open Need Count

Assessment Coverage
```

Avoid storing redundant derived values unless performance requirements justify a controlled derived representation.

---

# 14. Permanent Identifiers

Families and Persons have independent permanent business identifiers.

Examples:

```text
Family:
FAM-000001

Person:
PER-000001

Change Request:
CRQ-000001
```

Internal database primary keys remain separate from these business identifiers.

---

# 15. National ID

National ID is treated as sensitive identity information.

Rules include:

```text
Stored as text

Normalized

Never fabricated

Never replaced with fake placeholders

Permission-controlled

Masked where appropriate

Used in duplicate detection where authorized
```

Uniqueness policy must not be assumed blindly without approved data-quality rules.

---

# 16. Person Life Status

Person life status supports:

```text
ALIVE

DECEASED
```

and any future explicitly approved values.

`death_date` is optional.

Rules:

```text
death_date cannot be in the future

death_date cannot precede birth_date

ALIVE normally has death_date = NULL

DECEASED may have death_date = NULL if exact date is unknown
```

Unknown dates must never be invented.

---

# 17. Household Head

Household Head is not a User role.

It is domain state on Family Membership.

Conceptually:

```text
family_memberships.is_household_head
```

A Family must have no more than one active Household Head.

Household Head changes are controlled domain operations.

---

# 18. Family Membership

Family Membership represents:

```text
Person ↔ Family
```

including historical membership.

V1 baseline:

```text
One active primary Family membership per Person
```

Membership changes preserve history.

---

# 19. Person Relationships

Person-to-Person relationships are modeled independently from Family Membership.

Examples:

```text
Parent

Child

Spouse

Sibling

Guardian
```

Exact relationship reference data remains configurable.

---

# 20. Residence

Family residence is historical.

The system supports:

```text
Previous Residence
+
Current Residence
```

Baseline invariant:

```text
Maximum one current residence per Family
```

A move should:

```text
End old residence
+
Create new residence
```

not overwrite history.

---

# 21. Health & Disability

Health and disability information is sensitive.

It requires:

```text
Explicit permission

Controlled API exposure

Restricted Family Portal visibility

Audit where appropriate
```

Family membership alone does not authorize unrestricted health access.

---

# 22. Needs

Needs represent identified unmet or tracked requirements.

Needs may originate from:

```text
Assessment

Social Worker

Authorized Staff

Approved case process
```

Needs have their own lifecycle.

---

# 23. Assistance

Assistance represents actual assistance delivered or recorded.

It is separate from Needs.

Therefore:

```text
Need
≠
Assistance
```

and:

```text
Recording Assistance
≠
Automatically closing a Need
```

---

# 24. Documents

Documents may belong to:

```text
Family

Person

Change Request
```

Sensitive files use private storage.

Upload and verification are separate operations.

```text
UPLOADED
≠
VERIFIED
```

---

# 25. Notes

Internal Staff notes and case notes may contain sensitive information.

They are not automatically visible to Family Users.

Internal notes must remain separate from Family-visible messages.

---

# 26. Users vs Persons

Authentication identities are stored as:

```text
User
```

Registry identities are stored as:

```text
Person
```

They are separate.

A User may be linked to a Person through:

```text
User-Person Link
```

---

# 27. User-Person Links

Family Portal identity resolution uses:

```text
User
  ↓
User-Person Link
  ↓
Person
```

Initial link type:

```text
SELF
```

Potential future types:

```text
GUARDIAN

AUTHORIZED_REPRESENTATIVE
```

---

# 28. Family User

External self-service users receive:

```text
FAMILY_USER
```

This is an authorization role.

It is not the same as Household Head.

Recommended V1 Family User:

```text
Verified current Household Head
```

---

# 29. Family Portal Authorization

Family access is dynamically resolved.

```text
Authenticated User
        ↓
FAMILY_USER
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

Do not use:

```text
users.family_id
```

as the canonical authorization shortcut.

---

# 30. Family Portal

Family Portal is an authenticated controlled self-service experience.

It is not:

```text
Public self-registration

Unrestricted Family editing

Direct canonical CRUD
```

---

# 31. Family Portal Capabilities

Initial capabilities may include:

```text
View Family overview

View permitted Family members

View permitted personal information

View current residence

View own authorized requests

Submit Change Requests

Respond to clarification

Upload supporting documents

Track request status

View notifications

Manage account
```

---

# 32. Family Portal Restrictions

Family Users must not directly:

```text
Delete Persons

Change official National IDs

Change canonical Household Head

Transfer Family Membership

Record official death

Verify documents

Approve requests

Apply requests

Create official Assistance

Close official Needs

View Staff notes

View unrestricted Audit

Access other Families

Perform unrestricted exports
```

Such changes use controlled workflows.

---

# 33. Change Requests

Family User substantive changes use:

```text
Change Requests
```

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

---

# 34. Change Request Workflow

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

# 35. Approved vs Applied

A critical invariant:

```text
APPROVED
≠
APPLIED
```

APPROVED means:

```text
The requested operation is authorized.
```

APPLIED means:

```text
The canonical domain change completed successfully.
```

---

# 36. Change Request Application

Application follows:

```text
APPROVED
   ↓
Lock
   ↓
Re-read canonical state
   ↓
Revalidate
   ↓
Authorize
   ↓
Domain Action
   ↓
Transaction
   ↓
Canonical Mutation
   ↓
Audit
   ↓
Workflow Event
   ↓
APPLIED
```

If application fails:

```text
ROLLBACK
```

Baseline request state remains:

```text
APPROVED
```

until safely retried or otherwise handled.

---

# 37. Duplicate Detection

Potential duplicate Persons may be classified:

```text
EXACT

PROBABLE

POSSIBLE
```

All meaningful duplicate resolution requires human review.

---

# 38. Duplicate Merge

Famboook does not automatically merge Persons.

```text
Automatic Person Merge
=
PROHIBITED
```

A future merge process requires explicit workflow and audit design.

---

# 39. Corrections vs Real-World Changes

A correction fixes incorrect recorded information.

Example:

```text
Wrong birth date
```

A real-world change creates new state/history.

Example:

```text
Family moved
```

These operations must not be treated as equivalent.

---

# 40. Product Experiences

Famboook has four primary experiences:

```text
1. Staff Application

2. Executive Dashboard

3. Family Portal

4. System Administration
```

---

# 41. Staff Application

The Staff Application is:

```text
Custom Next.js
```

It supports daily operational work.

Examples:

```text
Dashboard

Families

Persons

Assessments

Needs

Assistance

Documents

Reviews

Change Requests

Reports
```

It is not built as a generic Filament admin panel.

---

# 42. Executive Dashboard

The Executive Dashboard is:

```text
Custom Next.js
```

It provides:

```text
KPIs

Trends

Coverage

Needs indicators

Assistance indicators

Workflow indicators

Data-quality indicators
```

Executive access does not automatically grant unrestricted sensitive record access.

---

# 43. Family Portal Experience

The Family Portal is:

```text
Custom Next.js
```

It uses the same design system as the wider Famboook product while maintaining a simpler self-service experience.

Mobile usability is especially important.

---

# 44. System Administration

System / High Administration uses:

```text
Filament
```

Typical scope:

```text
Users

Roles

Permissions

Reference Data

System Settings

Audit

Jobs

Maintenance
```

Filament is not the daily Staff operational application.

---

# 45. Final Technology Stack

```text
FRONTEND
Next.js
React
TypeScript
Tailwind CSS
shadcn/ui
Radix UI
Lucide Icons

DATA / FORMS
TanStack Query
React Hook Form
Zod

BACKEND
Laravel 12
REST API
Laravel Sanctum
Domain Actions / Services
Laravel Policies
Spatie Permission

DATABASE
PostgreSQL 16+

STAFF APP
Custom Next.js

EXECUTIVE DASHBOARD
Custom Next.js

FAMILY PORTAL
Custom Next.js

SYSTEM ADMINISTRATION
Filament

FILES
Laravel Private Storage

BACKGROUND PROCESSING
Laravel Queue

AUDIT / WORKFLOW
Laravel Domain Layer + Audit Logging
```

---

# 46. Architecture Summary

```text
Browser
   ↓
Next.js
   ↓
HTTPS / JSON
   ↓
Laravel REST API
   ↓
Authentication
   ↓
Authorization
   ↓
Validation
   ↓
Domain Actions
   ↓
Workflow / Audit
   ↓
PostgreSQL
```

---

# 47. Filament Architecture

```text
Filament
   ↓
Laravel Core
   ↓
Policies
   ↓
Domain Actions
   ↓
PostgreSQL
```

Filament must not create an alternate business-rule implementation.

---

# 48. Architecture Responsibilities

```text
Next.js
=
Presentation

Laravel
=
Authority

PostgreSQL
=
Source of Truth
```

---

# 49. API-First Architecture

Famboook uses a versioned REST API.

Initial version:

```text
/api/v1
```

The API serves:

```text
Staff Application

Executive Dashboard

Family Portal

Future approved clients
```

---

# 50. API Design

Famboook does not expose unrestricted database CRUD.

Important operations should use domain-aware endpoints.

Examples:

```text
POST /api/v1/families/{family}/change-household-head

POST /api/v1/change-requests/{request}/submit

POST /api/v1/change-requests/{request}/approve

POST /api/v1/change-requests/{request}/apply
```

---

# 51. Controllers

Controllers remain thin.

Preferred flow:

```text
Request
   ↓
Form Request
   ↓
Policy
   ↓
Controller
   ↓
Domain Action
   ↓
API Resource
```

---

# 52. Domain Actions

Important business operations are implemented as reusable Domain Actions.

Examples:

```text
CreateFamilyAction

CreatePersonAction

AddFamilyMemberAction

TransferFamilyMemberAction

ChangeHouseholdHeadAction

ChangeFamilyResidenceAction

RecordPersonDeathAction

CreateAssessmentAction

RecordAssistanceAction

VerifyDocumentAction

SubmitChangeRequestAction

ApproveChangeRequestAction

RejectChangeRequestAction

ApplyChangeRequestAction
```

---

# 53. Domain Action Reuse

The same domain layer is reused by:

```text
Next.js API requests

Filament

Approved Change Request application

Authorized background jobs

Controlled imports
```

Business rules must not be duplicated for each interface.

---

# 54. API Resources

Laravel API Resources control data exposure.

Possible resources:

```text
PersonSummaryResource

PersonDetailResource

FamilyMemberResource

FamilyPortalPersonResource

FamilyDetailResource

ChangeRequestResource
```

Different contexts may expose different fields from the same canonical entity.

---

# 55. No Unrestricted Serialization

Avoid:

```text
return $person;
```

for protected domain models.

Use controlled API representations.

---

# 56. Authentication

First-party web authentication uses:

```text
Laravel Sanctum
```

Preferred model:

```text
Secure cookie/session authentication
```

---

# 57. Authentication Secrets

Do not store primary authentication tokens in:

```text
localStorage
```

Production cookies must be appropriately configured for:

```text
HTTPS

Secure

HttpOnly where applicable

SameSite

Domain
```

---

# 58. Authorization

Authorization uses:

```text
Laravel Policies
+
Spatie Permission
+
Data Scope
+
Object Scope
+
Field Access
+
Workflow State
+
Domain Rules
```

---

# 59. Staff Authorization

Staff authorization is:

```text
ROLE
+
PERMISSION
+
DATA SCOPE
+
OBJECT ACCESS
+
FIELD ACCESS
+
WORKFLOW STATE
+
DOMAIN RULES
```

---

# 60. Family Authorization

Family authorization is:

```text
FAMILY_USER
+
ACTIVE USER-PERSON LINK
+
ACTIVE FAMILY MEMBERSHIP
+
FAMILY ACCESS POLICY
+
OBJECT ACCESS
+
FIELD ACCESS
+
WORKFLOW STATE
+
DOMAIN RULES
```

---

# 61. Initial Roles

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

# 62. Default Deny

Authorization follows:

```text
Default Deny
```

If access cannot be positively established:

```text
DENY
```

---

# 63. Frontend Permission Checks

Next.js may hide/disable actions for UX.

Example:

```text
No permission
→
Hide Approve button
```

But this is not security.

Laravel independently authorizes every protected operation.

---

# 64. Field-Level Security

Sensitive fields may be:

```text
FULL

MASKED

HIDDEN
```

Unauthorized sensitive values must not be sent to the browser and merely hidden using frontend code.

---

# 65. Workflow Authority

Laravel is the authoritative workflow layer.

Correct:

```text
Frontend:
"Approve request"

Laravel:
"Is this user allowed to approve this request in its current state?"
```

Incorrect:

```text
Frontend:
status = "APPROVED"
```

---

# 66. Workflow Events

Workflow transitions are stored as historical events.

Workflow history answers:

```text
What process transition occurred?
```

Audit history answers:

```text
What data changed?
```

These concepts remain separate.

---

# 67. Audit

Critical operations require audit.

Examples:

```text
Identity changes

National ID changes

Family membership changes

Household Head changes

Residence changes

Death recording

Document verification

Change Request application

Role changes

Permission changes

Sensitive exports
```

---

# 68. Database

Primary database:

```text
PostgreSQL 16+
```

The frontend never connects directly to PostgreSQL.

---

# 69. Database Integrity

PostgreSQL constraints complement Laravel domain rules.

Important examples:

```text
Foreign Keys

Unique Indexes

Partial Unique Indexes

Date Constraints

Transactions
```

Database constraints do not replace domain authorization or workflow rules.

---

# 70. Critical Database Invariants

Examples:

```text
Maximum one active primary Family membership per Person

Maximum one active Household Head per Family

Maximum one current residence per Family

death_date cannot precede birth_date
```

---

# 71. Concurrency

Critical operations must protect against concurrent modification.

Examples:

```text
Household Head change

Membership transfer

Residence change

Change Request application

Person death

Duplicate resolution
```

Use:

```text
Transactions

Row Locking

Database Constraints

Current-State Revalidation
```

---

# 72. Files

Sensitive files use:

```text
Laravel Private Storage
```

Access flow:

```text
Browser
   ↓
Laravel Authorized Endpoint
   ↓
Document Policy
   ↓
Private Storage
```

Do not expose sensitive documents through public storage URLs.

---

# 73. Frontend Server State

Use:

```text
TanStack Query
```

for:

```text
Server state

Caching

Loading

Refetching

Invalidation

Mutations
```

Frontend cache is not canonical data.

---

# 74. Forms

Use:

```text
React Hook Form
+
Zod
```

for frontend forms.

Responsibility:

```text
Zod
→ UX validation

Laravel
→ Authoritative validation
```

---

# 75. Server vs Client Components

Use Next.js Server Components where appropriate.

Use Client Components for interactive features such as:

```text
Forms

Dialogs

Drawers

Interactive tables

Client mutations

Complex filters
```

Do not mark the entire application `"use client"` unnecessarily.

---

# 76. Design System

Famboook has its own Design System.

The UI must not appear as a simple reskinned admin template.

Design principles:

```text
Clean

Modern

Professional

Enterprise-ready

Data-rich without clutter

Strong visual hierarchy

Generous spacing

Purposeful color

Consistent states

Excellent Arabic RTL

Responsive
```

---

# 77. UI Foundation

```text
Tailwind CSS

shadcn/ui

Radix UI

Lucide Icons
```

These are implementation tools.

They do not define Famboook's visual identity by themselves.

---

# 78. Generic Components

Examples:

```text
Button

Input

Select

DatePicker

Badge

Avatar

Card

StatCard

DataTable

Drawer

Dialog

Timeline

EmptyState

LoadingState

ErrorState
```

---

# 79. Domain Components

Examples:

```text
FamilyHeader

FamilyProfileHeader

PersonIdentityCard

FamilyMemberRow

FamilyMemberCard

HouseholdHeadBadge

VerificationBadge

NeedCard

AssistanceTimeline

ChangeRequestTimeline

ReviewPanel

DuplicateCandidateCard

ResidenceHistory

AssessmentSummary

ActivityTimeline
```

---

# 80. Metronic

Metronic may be used as:

```text
UX Inspiration

Layout Reference

Interaction Reference
```

It must not become a hard architectural dependency or define the Famboook product structure.

---

# 81. Arabic & RTL

Arabic RTL is a first-class requirement.

Test continuously:

```text
Navigation

Forms

Tables

Cards

Drawers

Dialogs

Breadcrumbs

Timelines

Pagination

Charts
```

RTL must not be postponed until the end.

---

# 82. Responsive Design

Support:

```text
Desktop

Tablet

Mobile
```

Staff data-heavy screens may prioritize desktop/tablet.

Family Portal must provide excellent mobile usability.

---

# 83. Low-Bandwidth Design

Famboook must remain usable under constrained connectivity.

Guidelines:

```text
Paginate large datasets

Avoid oversized payloads

Lazy-load heavy sections

Avoid unnecessary images

Avoid unnecessary JavaScript

Use efficient caching

Provide clear loading states
```

---

# 84. Accessibility

UI components should consider:

```text
Keyboard navigation

Focus states

Labels

Contrast

Semantic markup

Screen-reader structure

Touch targets
```

---

# 85. Deployment Topology

Initial production topology:

```text
famboook.com
    ↓
Next.js
Staff + Family Portal

api.famboook.com
    ↓
Laravel REST API

admin.famboook.com
    ↓
Laravel + Filament

PostgreSQL
    ↓
Private / Not exposed to Internet
```

---

# 86. Cross-Subdomain Authentication

Sanctum deployment requires careful configuration of:

```text
Cookie Domain

Secure Cookies

SameSite

Stateful Domains

CORS

CSRF
```

This must be tested in Staging before Production.

---

# 87. Redis

Redis is:

```text
Optional
```

not mandatory in the initial architecture.

It may later be introduced for:

```text
Cache

Sessions

Queues
```

when justified by measured requirements.

---

# 88. Queues

Laravel Queue supports asynchronous work.

Candidates:

```text
Notifications

Large exports

Imports

Report generation

Document processing

Scheduled reminders
```

Queued mutations must remain retry-safe and state-aware.

---

# 89. Notifications

Notifications originate from backend workflow/domain events.

Examples:

```text
Request Submitted

Returned for Clarification

Approved

Rejected

Applied

Account Activated
```

Where appropriate, notifications are dispatched after successful transaction commit.

---

# 90. Search

Search must respect authorization.

Potential Staff search fields:

```text
Family Code

Person Code

Name

National ID where authorized

Mobile where authorized
```

Family Users do not receive unrestricted global Person/Family search.

---

# 91. Reports

Reports must respect:

```text
Permission

Data Scope

Field Visibility
```

Report access must not become an authorization bypass.

---

# 92. Exports

Export permissions are separate from screen viewing.

Potential permissions:

```text
export.basic

export.sensitive

export.identity-data

export.health-data
```

Sensitive exports should be audited.

---

# 93. Imports

Import process:

```text
Upload
 ↓
Parse
 ↓
Validate
 ↓
Normalize
 ↓
Duplicate Check
 ↓
Preview
 ↓
Review
 ↓
Apply through controlled process
```

Imports must not directly bypass the domain layer.

---

# 94. Data Classification

Conceptual classifications:

```text
OPERATIONAL

INTERNAL

RESTRICTED
```

Sensitive examples include:

```text
National ID

Health

Disability

Identity documents

Confidential notes
```

---

# 95. Portal Visibility

Conceptual visibility levels include:

```text
FAMILY_VISIBLE

SELF_ONLY

STAFF_ONLY

RESTRICTED
```

Final exposure is still determined by authorization policies.

---

# 96. Security Principles

Famboook follows:

```text
Default Deny

Least Privilege

Server-Side Authorization

Object-Level Authorization

Field-Level Authorization

Private Sensitive Storage

Auditability

Historical Integrity

Secure Authentication

Defense in Depth
```

---

# 97. IDOR Protection

Knowing an object ID never grants access.

Every protected resource requires object authorization.

Examples:

```text
Family

Person

Assessment

Change Request

Document

Need

Assistance
```

---

# 98. Sensitive Data in Git

Never commit:

```text
Real Family registry data

Real National IDs

Real medical data

Real identity documents

Paper form scans

Passwords

API keys

Production environment files
```

---

# 99. Development Data

Development/testing should use:

```text
Synthetic data
```

Real production data should not be casually copied into development environments.

---

# 100. Repository Structure

```text
Famboook/
├── docs/
│   ├── 00-PROJECT-CONTEXT.md
│   ├── 01-PRODUCT.md
│   ├── 02-DATA-DICTIONARY.md
│   ├── 03-BUSINESS-RULES.md
│   ├── 04-DATABASE.md
│   ├── 05-WORKFLOWS.md
│   ├── 06-PERMISSIONS.md
│   └── 07-ROADMAP.md
├── backend/
├── frontend/
├── .gitignore
└── README.md
```

---

# 101. Backend Structure

Recommended baseline:

```text
backend/app/
├── Actions/
├── DTOs/
├── Enums/
├── Http/
│   ├── Controllers/
│   │   └── Api/
│   │       └── V1/
│   ├── Requests/
│   └── Resources/
├── Models/
├── Policies/
├── Services/
└── Support/
```

This may evolve as domains grow.

---

# 102. Frontend Structure

Recommended baseline:

```text
frontend/
├── app/
│   ├── (auth)/
│   ├── (staff)/
│   │   ├── dashboard/
│   │   ├── families/
│   │   ├── persons/
│   │   ├── reviews/
│   │   ├── assessments/
│   │   ├── needs/
│   │   ├── assistance/
│   │   ├── requests/
│   │   └── reports/
│   └── (family)/
│       ├── home/
│       ├── family/
│       ├── members/
│       ├── residence/
│       ├── requests/
│       └── notifications/
├── components/
│   ├── ui/
│   └── famboook/
├── features/
├── hooks/
├── lib/
└── types/
```

---

# 103. Documentation Set

The architecture baseline consists of:

```text
00-PROJECT-CONTEXT.md
→ Project orientation and architecture baseline

01-PRODUCT.md
→ Product definition and boundaries

02-DATA-DICTIONARY.md
→ Logical data model and field semantics

03-BUSINESS-RULES.md
→ Domain rules and invariants

04-DATABASE.md
→ PostgreSQL schema architecture

05-WORKFLOWS.md
→ State transitions and process rules

06-PERMISSIONS.md
→ Authentication/authorization model

07-ROADMAP.md
→ Implementation sequence
```

---

# 104. Documentation Precedence

When implementation questions arise, use the specialized document responsible for that concern.

Examples:

```text
"What does this field mean?"
→ DATA-DICTIONARY

"Can a Person have two active Families?"
→ BUSINESS-RULES / DATABASE

"What happens after approval?"
→ WORKFLOWS

"Can this role access the record?"
→ PERMISSIONS

"When should we implement it?"
→ ROADMAP
```

If documents conflict, resolve the contradiction explicitly before implementation.

Do not silently choose one interpretation.

---

# 105. Implementation Strategy

The implementation order is:

```text
Architecture Baseline
        ↓
Repository / Environment
        ↓
Laravel Foundation
        ↓
PostgreSQL Foundation
        ↓
Authentication / RBAC
        ↓
Next.js Foundation
        ↓
Famboook Design System
        ↓
Reference Data / System Admin
        ↓
Family / Person Registry
        ↓
Membership / Relationships / Residence
        ↓
Staff Experience
        ↓
Workflows
        ↓
Case Management
        ↓
Family User Identity
        ↓
Family Portal
        ↓
Change Requests
        ↓
Search / Duplicates
        ↓
Dashboards / Reports
        ↓
Security / Performance / UAT
        ↓
Pilot
        ↓
Production
```

---

# 106. MVP 1 — Internal Registry

Includes:

```text
Authentication

RBAC

Family Registry

Person Registry

Memberships

Relationships

Household Head

Residence

Staff Application

Basic Workflow

Search

Audit
```

---

# 107. MVP 2 — Case Management

Adds:

```text
Health

Disability

Education

Employment

Assessments

Needs

Assistance

Documents

Case Notes

Reports
```

---

# 108. MVP 3 — Family Self-Service

Adds:

```text
Family User Identity

Family Portal

Change Requests

Supporting Documents

Notifications

Controlled Self-Service
```

---

# 109. First Technical Slice

After Documentation Baseline v1.2 is committed:

```text
Laravel 12
+
PostgreSQL
+
/api/v1
+
Sanctum
+
Spatie Permission
+
Next.js
+
Authentication Integration
+
Famboook App Shell
```

---

# 110. First Domain Slice

Then implement:

```text
Family
  ↓
Person
  ↓
Family Membership
  ↓
Household Head
  ↓
Residence
```

This is the core of the registry.

---

# 111. First Complete Staff Journey

The first end-to-end Staff journey should be:

```text
Login
  ↓
Dashboard
  ↓
Create Family
  ↓
Create / Find Household Head
  ↓
Assign Household Head
  ↓
Add Family Members
  ↓
Set Relationships
  ↓
Add Current Residence
  ↓
Open Family Profile
```

This journey validates the core architecture.

---

# 112. First Historical-State Journey

The next important journey:

```text
Login
  ↓
Find Family
  ↓
Open Family
  ↓
Change Residence
  ↓
Old Residence ends
  ↓
New Residence becomes current
  ↓
History preserved
  ↓
Audit recorded
```

---

# 113. First Family Portal Journey

Later:

```text
Verified Family User
  ↓
Login
  ↓
Family Portal
  ↓
View permitted data
  ↓
Submit Contact Update
  ↓
Staff Review
  ↓
Approval
  ↓
Application
  ↓
Canonical data updated
  ↓
Family User sees final status
```

---

# 114. Testing Strategy

Priority automated tests:

```text
Authentication

Authorization

Policies

Family Scope

Database Constraints

Household Head

Membership

Residence

Person Death

Workflow Transitions

Change Request Application

Duplicate Handling

Sensitive Fields

Private Documents

Exports
```

---

# 115. Environments

Minimum:

```text
Local

Staging

Production
```

Staging should reproduce enough of the real deployment topology to test:

```text
Subdomains

Sanctum

Cookies

CORS

CSRF

Private Files

Queues

Deployment
```

---

# 116. Backups

Production requires backup strategy for:

```text
PostgreSQL

Private Files
```

A backup strategy is incomplete until restoration has been tested.

---

# 117. Pilot

Full production rollout is preceded by a controlled pilot.

Pilot validates:

```text
Data Entry

Data Quality

Duplicate Handling

Workflow

Performance

Staff UX

Family Portal UX

Training

Operational Procedures
```

---

# 118. Deferred V1 Features

Unless separately approved:

```text
Public self-registration

Native mobile application

External public API

Automatic Person merge

Advanced workflow builder

Multi-tenant SaaS

Advanced analytics warehouse

AI-driven eligibility decisions

Real-time architecture

Mandatory Redis dependency
```

are deferred.

---

# 119. Architecture Invariants

```text
CTX-INV-001
Person is an independent canonical entity.

CTX-INV-002
Family is an independent canonical entity.

CTX-INV-003
Family-Person association is represented through Family Membership.

CTX-INV-004
Paper forms are sources, not database structure.

CTX-INV-005
Repeatable information uses child records.

CTX-INV-006
Historical state is preserved where meaningful.

CTX-INV-007
Derived statistics are not duplicated as canonical truth without justification.

CTX-INV-008
Duplicate Persons are never automatically merged.

CTX-INV-009
Unknown values are not fabricated.

CTX-INV-010
Unknown death dates are not invented.

CTX-INV-011
One Person has at most one active primary Family membership in V1.

CTX-INV-012
One Family has at most one active Household Head.

CTX-INV-013
One Family has at most one current residence.

CTX-INV-014
Household Head is domain state, not an authorization role.

CTX-INV-015
User and Person are separate entities.

CTX-INV-016
Family Portal access requires explicit identity linking.

CTX-INV-017
FAMILY_USER role alone does not authorize a Family.

CTX-INV-018
Family User canonical changes use controlled Change Requests.

CTX-INV-019
APPROVED and APPLIED are distinct states.

CTX-INV-020
Laravel is the authoritative backend/domain layer.

CTX-INV-021
PostgreSQL is the canonical source of persisted truth.

CTX-INV-022
Next.js is the primary product frontend.

CTX-INV-023
Staff operations use custom Next.js interfaces.

CTX-INV-024
Executive Dashboard uses custom Next.js interfaces.

CTX-INV-025
Family Portal uses custom Next.js interfaces.

CTX-INV-026
Filament is restricted to System / High Administration.

CTX-INV-027
All authorized entry points reuse the Laravel domain layer.

CTX-INV-028
The frontend never directly accesses PostgreSQL.

CTX-INV-029
The API is versioned under /api/v1.

CTX-INV-030
Laravel is authoritative for validation.

CTX-INV-031
Laravel is authoritative for authorization.

CTX-INV-032
Laravel is authoritative for workflow transitions.

CTX-INV-033
Frontend permission checks are UX only.

CTX-INV-034
Sensitive field exposure is controlled server-side.

CTX-INV-035
Sensitive documents use private storage.

CTX-INV-036
Authentication secrets are not stored in browser localStorage.

CTX-INV-037
Critical multi-record operations are transactional.

CTX-INV-038
Critical concurrent operations use appropriate locking/constraints.

CTX-INV-039
Audit and Workflow Events are separate concepts.

CTX-INV-040
Real sensitive Family data is never committed to Git.

CTX-INV-041
Family Portal does not provide unrestricted canonical CRUD.

CTX-INV-042
Assessment data does not automatically overwrite canonical registry data.

CTX-INV-043
Assistance does not automatically close a Need.

CTX-INV-044
Uploaded documents are not automatically verified.

CTX-INV-045
Imports do not bypass validation/domain controls.

CTX-INV-046
View permission does not automatically grant export permission.

CTX-INV-047
RTL is a first-class product requirement.

CTX-INV-048
Family Portal is designed for mobile usability.

CTX-INV-049
Security hardening and UAT precede production rollout.

CTX-INV-050
A controlled pilot precedes full production rollout.
```

---

# 120. Architecture Decisions

### CTX-ADR-001
PostgreSQL 16+ is the primary database.

### CTX-ADR-002
Laravel 12 is the authoritative backend and domain layer.

### CTX-ADR-003
Famboook follows an API-first architecture.

### CTX-ADR-004
The primary web frontend uses Next.js + React + TypeScript.

### CTX-ADR-005
Tailwind CSS + shadcn/ui + Radix UI form the UI foundation.

### CTX-ADR-006
Lucide Icons is the default application icon library.

### CTX-ADR-007
Famboook maintains its own Design System.

### CTX-ADR-008
Staff operational interfaces are custom Next.js interfaces.

### CTX-ADR-009
Executive dashboards are custom Next.js interfaces.

### CTX-ADR-010
Family Portal is a custom Next.js experience.

### CTX-ADR-011
Filament is restricted to System / High Administration.

### CTX-ADR-012
Laravel Sanctum provides first-party web authentication.

### CTX-ADR-013
First-party web authentication uses secure cookie/session-based authentication.

### CTX-ADR-014
Authentication secrets are not stored in browser localStorage.

### CTX-ADR-015
Laravel Policies + Spatie Permission provide the authorization foundation.

### CTX-ADR-016
Important business operations use reusable Domain Actions.

### CTX-ADR-017
Next.js, Filament, approved jobs, imports, and Change Request application reuse the Laravel domain layer.

### CTX-ADR-018
Laravel API Resources control data exposure.

### CTX-ADR-019
The API is versioned under `/api/v1`.

### CTX-ADR-020
TanStack Query manages frontend server state.

### CTX-ADR-021
React Hook Form + Zod provide frontend form handling and UX validation.

### CTX-ADR-022
Laravel remains authoritative for validation.

### CTX-ADR-023
Sensitive documents use private storage.

### CTX-ADR-024
Initial deployment uses separate application, API, and administration hosts.

### CTX-ADR-025
PostgreSQL is not directly exposed to frontend applications.

### CTX-ADR-026
Redis is optional and introduced only when justified.

### CTX-ADR-027
Metronic may inspire UX but is not an architectural dependency.

### CTX-ADR-028
Family User self-service uses Change Requests rather than direct canonical CRUD.

### CTX-ADR-029
User and Person identities remain separate.

### CTX-ADR-030
Family Portal authorization derives dynamically from User-Person Link and canonical Family Membership.

### CTX-ADR-031
Recommended V1 Family User is the verified current Household Head.

### CTX-ADR-032
Automatic Person merge is prohibited.

### CTX-ADR-033
Assessments remain point-in-time records.

### CTX-ADR-034
Needs and Assistance remain separate domains.

### CTX-ADR-035
Document upload and verification remain separate.

### CTX-ADR-036
Workflow Events and Audit remain separate.

### CTX-ADR-037
Critical Domain Actions use transactions and concurrency protection where required.

### CTX-ADR-038
Operational queues derive from authoritative workflow state.

### CTX-ADR-039
Executive reporting remains separate from System Administration.

### CTX-ADR-040
A controlled pilot precedes full production rollout.
```

---

# 121. Stack Decisions

For quick reference:

```text
ADR-STACK-001
PostgreSQL is the primary database.

ADR-STACK-002
Laravel 12 is the authoritative backend and domain layer.

ADR-STACK-003
Famboook follows an API-first architecture.

ADR-STACK-004
The primary web application uses Next.js + React + TypeScript.

ADR-STACK-005
Tailwind CSS + shadcn/ui + Radix UI form the UI foundation.

ADR-STACK-006
Famboook has its own Design System.

ADR-STACK-007
Staff operational interfaces are custom Next.js interfaces.

ADR-STACK-008
Executive dashboards are custom Next.js interfaces.

ADR-STACK-009
Family Portal is a custom Next.js experience.

ADR-STACK-010
Filament is restricted to System / High Administration.

ADR-STACK-011
Laravel Sanctum provides first-party web authentication.

ADR-STACK-012
Authentication uses secure cookie/session-based authentication.

ADR-STACK-013
Authentication tokens are not stored in browser localStorage.

ADR-STACK-014
Laravel Policies + Spatie Permission provide authorization.

ADR-STACK-015
Business operations are implemented as reusable Domain Actions.

ADR-STACK-016
Next.js, Filament and other authorized entry points reuse the same domain layer.

ADR-STACK-017
Laravel API Resources control data exposure.

ADR-STACK-018
The API is versioned under /api/v1.

ADR-STACK-019
TanStack Query manages frontend server state.

ADR-STACK-020
React Hook Form + Zod provide frontend form handling and UX validation.

ADR-STACK-021
Laravel remains the authoritative validation layer.

ADR-STACK-022
Sensitive documents use private storage.

ADR-STACK-023
Initial deployment topology uses separate application/API/admin hosts.

ADR-STACK-024
PostgreSQL is never directly exposed to frontend applications.
```

---

# 122. Pending High-Level Decisions

The following do not block the architecture baseline unless they become required by the implementation phase:

```text
CTX-PENDING-001
Exact hosting provider.

CTX-PENDING-002
Exact Next.js runtime/deployment provider.

CTX-PENDING-003
Exact Laravel runtime/deployment provider.

CTX-PENDING-004
Exact managed/self-hosted PostgreSQL strategy.

CTX-PENDING-005
Exact private file storage provider.

CTX-PENDING-006
Exact queue driver at launch.

CTX-PENDING-007
Whether Redis is required at launch.

CTX-PENDING-008
Exact CI/CD implementation.

CTX-PENDING-009
Exact monitoring/error-tracking tools.

CTX-PENDING-010
Exact 2FA policy.

CTX-PENDING-011
Exact session timeout policy.

CTX-PENDING-012
Exact Family User identity-verification procedure.

CTX-PENDING-013
Exact Family User account recovery procedure.

CTX-PENDING-014
Whether V1 supports multiple Family Users per Family.

CTX-PENDING-015
Guardian / Authorized Representative policy.

CTX-PENDING-016
Exact adult Family member privacy rules.

CTX-PENDING-017
Exact Family Portal health/disability visibility.

CTX-PENDING-018
Exact sensitive export approval policy.

CTX-PENDING-019
Exact initial pilot size.

CTX-PENDING-020
Exact legacy/paper data migration volume.

CTX-PENDING-021
Exact notification channels.

CTX-PENDING-022
Whether PWA functionality is V1 or post-V1.

CTX-PENDING-023
Exact production backup/retention targets.

CTX-PENDING-024
Exact performance targets.

CTX-PENDING-025
Exact accessibility target.
```

---

# 123. Documentation Baseline Status

As of version 1.2:

```text
00-PROJECT-CONTEXT.md     v1.2 ✓

01-PRODUCT.md             v1.2 ✓

02-DATA-DICTIONARY.md     v1.2 ✓

03-BUSINESS-RULES.md      v1.2 ✓

04-DATABASE.md            v1.2 ✓

05-WORKFLOWS.md           v1.2 ✓

06-PERMISSIONS.md         v1.2 ✓

07-ROADMAP.md             v1.2 ✓
```

Architecture Documentation Baseline:

```text
COMPLETE
```

---

# 124. Current Project Stage

The project is now at:

```text
Product Definition
        ✓

Domain Modeling
        ✓

Business Rules
        ✓

Database Architecture
        ✓

Workflow Architecture
        ✓

Authorization Architecture
        ✓

Technology Stack
        ✓

Implementation Roadmap
        ✓

Documentation Baseline v1.2
        ✓

Technical Foundation
        ↓
NEXT
```

---

# 125. Immediate Implementation Sequence

The next work should be:

```text
Step 1
Commit Documentation Baseline

Step 2
Initialize Laravel 12 in backend/

Step 3
Initialize Next.js in frontend/

Step 4
Configure PostgreSQL

Step 5
Establish /api/v1

Step 6
Configure Laravel Sanctum

Step 7
Configure Spatie Permission

Step 8
Create initial Roles / Permissions

Step 9
Create Next.js authentication integration

Step 10
Create Famboook App Shell

Step 11
Create Design System foundation

Step 12
Implement Family + Person core
```

---

# 126. Implementation Guardrail

Before introducing a new architectural dependency, ask:

```text
Does the existing stack already solve this problem?
```

Before adding a new canonical field, ask:

```text
Is this permanent domain data,
historical data,
repeatable data,
assessment data,
proposed data,
or derived data?
```

Before adding a new frontend mutation, ask:

```text
Which Laravel Domain Action owns this operation?
```

Before exposing a field, ask:

```text
Who is allowed to see it,
in which context,
and at what visibility level?
```

Before changing a workflow state, ask:

```text
Is this transition valid,
who may perform it,
and what must be audited?
```

---

# 127. Final Architecture Principle

The Famboook architecture can be summarized as:

```text
Real-World Family Data
          ↓
Canonical Domain Model
          ↓
Laravel Business Rules
          ↓
PostgreSQL Source of Truth
          ↓
Authorized Versioned API
          ↓
Famboook Design System
          ↓
Purpose-Built Next.js Experiences
```

with:

```text
Filament
=
System / High Administration
```

and:

```text
Family Self-Service
=
Controlled Change Requests
```

The goal is not merely to digitize forms.

The goal is to create a secure, historically accurate, extensible Family Registry and Case Management platform.

---

# 128. Document Status

```text
Project: Famboook
Document: Project Context & Architecture Baseline
Version: 1.2
Status: APPROVED
Date: 2026-09-22
```

---

# 129. Change Log

| Version | Date | Status | Description |
|---|---|---|---|
| 1.0 | 2026-09-22 | Superseded | Initial project context |
| 1.1 | 2026-09-22 | Superseded | Expanded registry, Family Portal, workflow, security and domain architecture |
| 1.2 | 2026-09-22 | Approved | Final synchronized architecture baseline establishing API-first Laravel/PostgreSQL core, custom Next.js Staff/Executive/Family experiences, Filament System Administration boundary, Sanctum authorization architecture, Famboook Design System, Change Request self-service model, deployment topology and implementation starting point |