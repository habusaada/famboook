# Famboook
## Product Definition

**Document:** `01-PRODUCT.md`  
**Version:** 1.2  
**Status:** Approved  
**Last Updated:** 2026-09-22  
**Project:** Famboook — Family Registry & Case Management System

---

# 1. Product Overview

Famboook is a secure Family Registry and Case Management platform designed to create, maintain, verify, and use structured Family and Person information.

The system transforms paper-based and fragmented Family information into a controlled digital registry while preserving:

- Person identity
- Family membership history
- Household structure
- Residence history
- Assessments
- Needs
- Assistance
- Supporting documents
- Verification history
- Change history
- Workflow history

Famboook is not merely a digital copy of a paper form.

The paper form is considered a data source.

The digital platform represents the real-world domain independently from the structure and limitations of paper forms.

---

# 2. Product Vision

Famboook should become a trusted digital Family Registry platform capable of supporting:

- Family registration
- Person registration
- Household structure
- Data verification
- Family data maintenance
- Social assessments
- Needs identification
- Assistance tracking
- Case management
- Family self-service
- Controlled data correction
- Reporting
- Decision support

The product should be designed as a modern, extensible, secure digital platform rather than a traditional administrative CRUD panel.

---

# 3. Product Positioning

Famboook is designed as a:

```text
Family Registry
+
Case Management System
+
Family Self-Service Platform
+
Operational Data Platform
```

The system must support both operational staff and authorized Family users without compromising registry integrity.

---

# 4. Product Design Philosophy

Famboook should feel like a modern SaaS / Enterprise product.

The product experience should prioritize:

```text
Clarity

Professional Design

Modern UX

Arabic-first Experience

RTL Excellence

Responsive Design

Mobile Usability

Fast Navigation

Data Density without Clutter

Accessibility

Consistency

Security

Trust
```

Visual design is considered part of the product architecture, not a final cosmetic phase.

---

# 5. Product Experience Principle

Famboook must not expose the database structure directly as the user experience.

Users should interact with meaningful domain concepts such as:

```text
Family Profile

Person Profile

Household Members

Residence

Assessment

Need

Assistance

Change Request

Verification

Review Queue

Family Timeline
```

rather than raw database tables.

---

# 6. Primary Product Experiences

Famboook contains four primary experiences:

```text
1. Staff Application

2. Executive Dashboard

3. Family Portal

4. System Administration
```

These experiences may use different interfaces while sharing the same backend, domain rules, authorization model, and canonical database.

---

# 7. Staff Application

The Staff Application is the main operational interface.

It is a custom modern web application.

Primary users include:

```text
ADMINISTRATOR

DATA_ENTRY

REVIEWER

SOCIAL_WORKER

REPORTS_VIEWER
```

The Staff Application is not implemented as a generic administration panel.

It is designed specifically around Famboook operational workflows.

---

# 8. Staff Application Capabilities

The Staff Application may include:

```text
Dashboard

Families

Persons

Data Entry

Membership Management

Relationship Management

Residence Management

Review Center

Verification

Assessments

Health Information

Disability Information

Education

Employment

Needs

Assistance

Documents

Case Notes

Change Requests

Duplicate Review

Reports

Notifications
```

Exact capabilities depend on role and permission.

---

# 9. Executive Dashboard

Management and decision-makers should use a dedicated product experience rather than the technical administration interface.

The Executive Dashboard may provide:

```text
Registry KPIs

Family Statistics

Population Statistics

Residence Statistics

Displacement Statistics

Needs Analysis

Assistance Analysis

Operational Workload

Verification Progress

Change Request Metrics

Data Quality Indicators

Trends

Management Reports
```

The Executive Dashboard is part of the primary Famboook web application.

---

# 10. Family Portal

Famboook includes an authenticated Family Portal.

The Family Portal provides controlled self-service access to authorized Family users.

It is not public self-registration.

It is not unrestricted registry editing.

---

# 11. Family User

The external authorization role is:

```text
FAMILY_USER
```

`FAMILY_USER` is preferred over using `HOUSEHOLD_HEAD` as an authorization role.

Household Head is a domain relationship/state.

FAMILY_USER is an application authorization concept.

This separation allows future support for:

```text
Guardian

Authorized Representative

Approved Adult Family Member
```

without redesigning the role model.

---

# 12. Recommended V1 Family User

The recommended V1 policy is:

```text
Verified Current Household Head
```

A user must not receive Family Portal access merely because they know:

```text
Family Code

Person Code

National ID

Phone Number
```

Identity and relationship verification are required.

---

# 13. User Identity vs Person Identity

A system User and a registry Person are separate entities.

Conceptually:

```text
User
 ↓
User-Person Link
 ↓
Person
 ↓
Active Family Membership
 ↓
Family
```

A User represents authentication identity.

A Person represents real-world registry identity.

These concepts must not be merged.

---

# 14. Family Portal Scope

An authenticated Family User may access only the Family scope authorized through their verified identity relationship.

Authorization must consider:

```text
User Status

User Role

User-Person Link

Person Status

Current Family Membership

Household Head Status where required

Resource Policy

Field Visibility

Workflow State
```

---

# 15. Family Portal Capabilities

V1 Family Portal may provide:

```text
Family Overview

Permitted Family Information

Permitted Family Members

Current Residence

Own Change Requests

Submit Update Requests

Respond to Clarification

Upload Supporting Documents

Track Request Status

Notifications

Account Information
```

Selected Needs and Assistance information may be exposed only after explicit policy approval.

---

# 16. Family Portal Restrictions

Family Users must not directly:

```text
Delete Persons

Change National ID without controlled review

Change Household Head

Transfer Persons between Families

Mark a Person officially deceased

Modify official Family relationships

Approve Change Requests

Verify Documents

Create Assistance Records

Close Needs

View confidential Staff Notes

View Audit Logs

View Internal Workflow Notes

Access other Families

Perform unrestricted exports
```

---

# 17. Canonical Registry

Famboook maintains a canonical registry.

Canonical data represents the system's currently accepted official state.

Examples:

```text
Family

Person

Family Membership

Household Head

Person Relationship

Current Residence

Life Status

Verified Documents
```

Canonical registry data must not be silently overwritten by unverified submissions.

---

# 18. Proposed Data

Data submitted by Family Users is considered proposed data until reviewed and applied.

Conceptually:

```text
Family User Submission
        ↓
Proposed Data
        ↓
Change Request
        ↓
Review
        ↓
Approval
        ↓
Controlled Application
        ↓
Canonical Registry
```

---

# 19. Change Requests

Substantive Family User changes are represented as Change Requests.

Initial Change Request types include:

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

Not every type must be enabled for Family Users in the initial release.

---

# 20. Change Request Lifecycle

The baseline lifecycle is:

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
  ├──────── UNDER_REVIEW
  │
  ├── REJECTED
  │
  └── APPROVED
          ↓
       APPLIED
```

---

# 21. Approved vs Applied

The system must distinguish:

```text
APPROVED
```

from:

```text
APPLIED
```

APPROVED means the proposed change has been authorized.

APPLIED means the canonical registry transaction has successfully completed.

An approved request must not be presented as applied before the domain operation succeeds.

---

# 22. Change Request Application

Approved Change Requests must be applied through the same controlled Domain Actions used by authorized Staff operations.

Example:

```text
RESIDENCE_UPDATE
      ↓
Approved Change Request
      ↓
ChangeFamilyResidenceAction
      ↓
Canonical Registry
```

This prevents duplicate business logic.

---

# 23. Supporting Documents

Family Users may upload supporting documents with Change Requests.

Uploaded documents are initially:

```text
UNVERIFIED
```

Upload does not imply authenticity or verification.

Verification is a separate authorized Staff operation.

---

# 24. Notifications

Famboook should notify users about meaningful workflow events.

Examples:

```text
Request Submitted

Request Under Review

Clarification Requested

Request Approved

Request Rejected

Request Applied

Account Activated

Account Suspended
```

Notifications must avoid unnecessary sensitive information.

---

# 25. Family

A Family is a persistent domain entity.

A Family is not defined only by:

```text
Current Household Head

Current Address

Current Members

Paper Form
```

Those values may change while Family identity remains.

Each Family receives a permanent internal identity and a stable business code.

---

# 26. Person

A Person is an independent persistent entity.

A Person must not be structurally owned by a Family.

A Person may:

```text
Join a Family

Leave a Family

Transfer to another Family

Become Household Head

Marry

Become deceased

Change residence context
```

without losing their Person identity.

---

# 27. Family Membership

The canonical relationship between Family and Person is represented through Family Membership.

Conceptually:

```text
Family
  ↓
Family Membership
  ↓
Person
```

The system must not use a canonical:

```text
persons.family_id
```

relationship.

---

# 28. Membership History

Family membership history must be preserved.

The system should know:

```text
Which Family

Which Person

Relationship Type

Whether Household Head

Membership Start

Membership End

Membership Status

End Reason
```

Historical memberships must not be overwritten.

---

# 29. Household Head

Household Head is a state of Family Membership.

It is not a permanent Person attribute.

A Family may change Household Head over time.

V1 allows at most:

```text
One active Household Head per Family
```

---

# 30. Person Relationships

Person-to-Person relationships are separate from Family membership.

Examples:

```text
Spouse

Parent

Child

Guardian

Sibling
```

These relationships must not be inferred solely from paper row position.

---

# 31. Residence

Residence is historical.

The system must preserve:

```text
Previous Residence

Current Residence

Residence Change

Displacement Context
```

rather than overwriting a single address indefinitely.

---

# 32. Health and Disability

Health and disability data is sensitive.

It should be modeled as repeatable Person-level information.

The system must not assume:

```text
One condition per Person

One disability per Person
```

Sensitive access requires explicit authorization.

---

# 33. Education and Employment

Education and employment are Person-level domains.

The architecture should permit historical or repeated records where operationally required.

---

# 34. Assessments

Assessments represent point-in-time evaluations.

They must be separate from permanent registry identity.

A Family may have:

```text
Assessment A

Assessment B

Assessment C
```

over time.

Assessment answers must not silently overwrite canonical registry data.

---

# 35. Needs

Needs represent identified Family or Person requirements.

Examples may include:

```text
Food

Shelter

Health

Education

Protection

Livelihood

Cash Assistance
```

Needs have their own lifecycle.

---

# 36. Assistance

Assistance represents support that was actually delivered or recorded.

Need and Assistance are separate concepts.

A Need does not automatically become resolved because an Assistance record exists.

---

# 37. Documents

Documents may relate to:

```text
Family

Person

Change Request
```

Documents require:

```text
Private Storage

Authorization

Verification Status

Audit where appropriate
```

Document files must not be publicly exposed by default.

---

# 38. Notes

Famboook may contain:

```text
Person Notes

Case Notes

Operational Notes

Confidential Notes
```

Visibility depends on note type and authorization.

Family Users must not automatically see Staff notes.

---

# 39. Paper Forms

Paper forms remain traceable data sources.

The system should preserve where appropriate:

```text
Paper Form Number

Source Type

Source Document

Data Entry Actor

Data Entry Date

Verification Actor

Verification Date
```

The database must not replicate fixed paper rows or columns.

---

# 40. Duplicate Management

Famboook must support duplicate detection.

Possible duplicate classes:

```text
EXACT

PROBABLE

POSSIBLE
```

Possible signals include:

```text
National ID

Name

Birth Date

Gender

Mobile

Family Context
```

No Person records are automatically merged.

Human review is required.

---

# 41. Search

Authorized Staff search may include:

```text
Family Code

Person Code

Name

National ID

Mobile

Paper Form Number
```

Search must respect authorization and sensitive-field policies.

Family Users do not receive global registry search.

---

# 42. Reporting

Famboook provides operational and management reporting.

Reports must derive from canonical data.

Examples:

```text
Family Count

Population

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

# 43. Derived Statistics

Values such as:

```text
Family Size

Child Count

Adult Count

Male Count

Female Count
```

should normally be derived from canonical records rather than manually maintained.

---

# 44. Auditability

Critical changes must be traceable.

Audit should answer:

```text
Who?

What?

When?

From what?

To what?
```

Examples include:

```text
National ID changes

Membership changes

Household Head changes

Residence changes

Life Status changes

User-Person Link changes

Permission changes

Change Request application
```

---

# 45. Workflow History

Workflow history is separate from data audit history.

Workflow events describe process transitions such as:

```text
SUBMITTED

UNDER_REVIEW

RETURNED

VERIFIED

APPROVED

REJECTED

APPLIED
```

Audit and Workflow Events serve different purposes.

---

# 46. Product Authorization Model

Authorization is not based on Role alone.

Internal authorization is:

```text
Role
+
Permission
+
Data Scope
+
Field Access
+
Workflow State
+
Domain Rules
```

Family Portal authorization additionally requires:

```text
User-Person Link
+
Current Family Relationship
+
Family Access Policy
```

---

# 47. Product Roles

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

Roles do not replace record-level authorization.

---

# 48. Technical Product Architecture

Famboook uses a separated frontend/backend architecture.

Conceptually:

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
              Domain / Security
                       │
                       ▼
                  PostgreSQL
```

---

# 49. Primary Frontend

The primary Famboook web application uses:

```text
Next.js

React

TypeScript

Tailwind CSS

shadcn/ui

Radix UI

Lucide Icons
```

The frontend must support:

```text
Arabic

RTL

Responsive Design

Mobile

Modern UX

Accessibility

Low-bandwidth considerations
```

---

# 50. Famboook Design System

Famboook must establish its own reusable Design System.

The Design System should include:

```text
Design Tokens

Typography

Colors

Spacing

Radius

Shadows

Status Colors

Interaction States

Responsive Rules
```

and reusable UI components.

---

# 51. UI Foundation

`shadcn/ui` and Radix UI provide foundational UI primitives.

They do not define the final Famboook visual identity.

Famboook owns and customizes the resulting component code.

The product must not appear as a generic template with only logo and color replacement.

---

# 52. Domain UI Components

Famboook should develop reusable domain-specific components such as:

```text
FamilyCard

FamilyProfileHeader

PersonCard

PersonIdentityCard

FamilyMemberRow

HouseholdHeadBadge

VerificationBadge

StatusBadge

ResidenceCard

ResidenceHistory

AssessmentSummary

NeedCard

AssistanceCard

ChangeRequestCard

RequestTimeline

ReviewPanel

DuplicateMatchCard

ActivityTimeline

StatCard
```

These components form part of the product identity.

---

# 53. Frontend Server State

Frontend server state is managed using:

```text
TanStack Query
```

It may handle:

```text
Fetching

Caching

Pagination

Refetching

Mutations

Invalidation

Loading States

Error States
```

The frontend cache is not a source of truth.

---

# 54. Frontend Forms

Frontend forms use:

```text
React Hook Form

Zod
```

for:

```text
Form State

UX Validation

Field Errors

Conditional Forms
```

Frontend validation improves user experience.

It is not authoritative business validation.

---

# 55. Backend Authority

Laravel is the authoritative application backend.

Laravel is responsible for:

```text
Authentication

Authorization

Validation

Business Rules

Domain Actions

Transactions

Workflow

Audit

Notifications

Queues

File Authorization

Canonical Data Changes
```

Frontend logic must not replace backend authority.

---

# 56. API Architecture

Famboook follows an API-first architecture for the primary web product.

The API is versioned:

```text
/api/v1
```

Examples:

```text
GET    /api/v1/me

GET    /api/v1/families

POST   /api/v1/families

GET    /api/v1/families/{family}

GET    /api/v1/persons/{person}

GET    /api/v1/change-requests

POST   /api/v1/change-requests
```

Domain operations may use action-oriented endpoints where appropriate.

---

# 57. API Design Principle

The API must not be treated as unrestricted CRUD over database tables.

Important business operations should express domain intent.

Examples:

```text
Change Household Head

Transfer Family Member

Record Person Death

Change Family Residence

Apply Change Request
```

These operations must invoke controlled backend Domain Actions.

---

# 58. Domain Actions

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

SubmitChangeRequestAction

ApproveChangeRequestAction

ApplyChangeRequestAction
```

The same actions may be invoked by authorized entry points.

---

# 59. Shared Domain Layer

Conceptually:

```text
Next.js
    │
    ├──────────────┐
    │              │
Laravel API        │
    │              │
    ▼              │
Domain Actions ◄── Filament
    │
    ▼
PostgreSQL
```

Filament must not implement an independent copy of business logic.

---

# 60. Authentication

The primary first-party web application uses:

```text
Laravel Sanctum
```

with secure cookie/session-based authentication.

Authentication secrets or bearer tokens must not be stored in browser `localStorage` for the primary web application.

---

# 61. Authentication Responsibilities

Laravel controls:

```text
Login

Logout

Session

User Status

Authentication Failure

Rate Limiting

Password Recovery

Account Suspension
```

Exact login identifier and activation mechanism remain implementation decisions until finalized.

---

# 62. Authorization Technologies

Backend authorization uses:

```text
Laravel Policies

Spatie Laravel Permission

Query Scopes

Domain Authorization

Field-Level Exposure Rules
```

Frontend permission information may be used to improve UX, such as hiding unavailable actions.

Frontend hiding is never considered security enforcement.

---

# 63. API Resources

Laravel API Resources or equivalent controlled response objects must be used to control data exposure.

The system must not automatically serialize unrestricted Models.

Different contexts may require different representations.

Examples:

```text
PersonSummaryResource

PersonDetailResource

FamilyMemberResource

FamilyPortalPersonResource
```

---

# 64. System Administration

Filament is retained as a restricted System / High Administration interface.

Filament is not the primary Staff Application.

Filament is not the Family Portal.

Filament is not the Executive Dashboard.

---

# 65. Filament Scope

Filament may be used for:

```text
System Users

Roles

Permissions

Family User Accounts

User-Person Links

Reference Data

System Settings

Audit Administration

Failed Jobs

Queue Administration

Notification Administration

Import Administration

Technical Maintenance Tools
```

Exact modules remain permission-controlled.

---

# 66. High Administration Principle

Operational and executive users should not be forced into a generic technical administration panel when a purpose-built Famboook experience is appropriate.

Management dashboards and operational workflows belong in the custom Next.js product.

Filament acts as a restricted control room for authorized high-level/system administration.

---

# 67. Private Storage

Sensitive files are stored privately.

Conceptually:

```text
Browser
 ↓
Next.js
 ↓
Laravel
 ↓
Authenticate
 ↓
Authorize
 ↓
Private Storage
 ↓
Authorized File Response
```

Direct public file paths must not be used for sensitive documents.

---

# 68. Background Processing

Laravel Queue is used for suitable background operations.

Examples:

```text
Notifications

Email

SMS when enabled

Large Imports

Large Exports

Document Processing

Long-running Reports
```

Business-critical state changes must not depend on an unconfirmed asynchronous operation unless explicitly designed that way.

---

# 69. Notifications Architecture

Notifications are generated by the Laravel backend.

Delivery channels may include:

```text
Database Notifications

Email

SMS
```

depending on approved implementation.

V1 begins with database notifications unless changed by an approved decision.

---

# 70. Database

The primary database is:

```text
PostgreSQL 16+
```

PostgreSQL is the canonical persistent data store.

The frontend never connects directly to PostgreSQL.

---

# 71. Database Integrity

Critical invariants should be protected at multiple levels:

```text
Frontend UX Validation
        ↓
Laravel Validation
        ↓
Domain Rules
        ↓
Database Constraints
```

Database constraints should be used where they safely represent domain invariants.

---

# 72. PostgreSQL Capabilities

Famboook may benefit from PostgreSQL features including:

```text
Partial Unique Indexes

JSONB

Strong Constraints

Advanced Indexing

Transactional Integrity

Search Extensions when required
```

JSONB should not replace proper relational modeling for canonical registry entities.

---

# 73. Change Request Payloads

Change Request proposed data may use structured JSONB where appropriate because payload shape varies by request type.

Example:

```text
CONTACT_UPDATE
```

has different proposed fields from:

```text
DEATH_REPORT
```

Each request type must have explicit backend validation and application rules.

---

# 74. Deployment Topology

The initial preferred deployment topology is:

```text
famboook.com
    ↓
Next.js
Primary Web Product


api.famboook.com
    ↓
Laravel API


admin.famboook.com
    ↓
Filament / Laravel
System Administration
```

PostgreSQL and private infrastructure are not directly exposed to browser applications.

Final hostnames may change without changing this architectural separation.

---

# 75. First-Party Application Model

The Next.js application and Laravel backend are both controlled parts of Famboook.

They should be treated as a first-party application environment.

Authentication, cookies, CORS, CSRF, and session configuration must reflect the final deployment topology.

---

# 76. Staff Experience

The Staff Application should optimize for:

```text
Fast Data Entry

Efficient Search

Data-rich Views

Keyboard-friendly Workflows

Review Queues

Bulk Operational Context

Clear Statuses

Minimal Repetition

Fast Navigation
```

---

# 77. Family Experience

The Family Portal should optimize for:

```text
Simplicity

Arabic-first UX

Mobile-first Layout

Low Bandwidth

Large Touch Targets

Clear Instructions

Simple Forms

Request Status Clarity

Minimal Technical Language
```

---

# 78. Executive Experience

Executive and management interfaces should optimize for:

```text
KPIs

Trends

Exceptions

Operational Performance

Data Quality

Decision Support

Drill-down where authorized
```

without exposing unnecessary sensitive person-level information.

---

# 79. Responsive Design

The primary Famboook web application must be responsive.

The system should support:

```text
Desktop

Laptop

Tablet

Mobile
```

Staff workflows may be desktop-optimized where appropriate.

Family Portal workflows must be mobile-friendly.

---

# 80. Arabic and RTL

Arabic and RTL are first-class requirements.

They must be considered in:

```text
Layout

Navigation

Tables

Forms

Icons

Charts

Typography

Dialogs

Drawers

Validation

Dates

Numbers

Search
```

RTL must not be treated as a final translation task.

---

# 81. Low-Bandwidth Design

The Family Portal should avoid unnecessary:

```text
Large JavaScript Payloads

Large Images

Heavy Animation

Repeated Requests

Unnecessary Background Fetching
```

Performance is part of accessibility.

---

# 82. Product Security

Famboook handles sensitive personal information.

Security requirements include:

```text
Authentication

Authorization

Object-Level Access Control

Field-Level Access Control

Private Files

Rate Limiting

Session Security

Audit

Input Validation

Secure Uploads

Secure Exports

Sensitive Logging Controls
```

---

# 83. Sensitive Data

Sensitive data includes, but is not limited to:

```text
National ID

Health Data

Disability Data

Documents

Mobile Numbers

Residence

Confidential Notes
```

Sensitive information should follow least-privilege access.

---

# 84. Product Data Classification

Suggested classifications:

```text
OPERATIONAL

INTERNAL

RESTRICTED
```

Portal visibility may additionally use:

```text
FAMILY_VISIBLE

SELF_ONLY

STAFF_ONLY

RESTRICTED
```

Classification does not replace authorization policies.

---

# 85. National ID

National ID is stored as text rather than numeric data.

It must not be used as:

```text
Primary Database Key

Public Resource Identifier

Authentication Secret
```

Visibility and search are permission-controlled.

---

# 86. Death Information

A Person may have:

```text
life_status = DECEASED
```

with an optional canonical:

```text
death_date
```

If the exact death date is unknown, the system must not invent one.

A reported death does not become canonical merely because a Family User submitted it.

---

# 87. Real-World Events vs Corrections

The system must distinguish between:

```text
Correction
```

and:

```text
Real-world Change
```

Example:

```text
Wrong birth date entered
= Correction

Family moved to a new residence
= Real-world Change
```

This distinction affects history and audit.

---

# 88. Imports

Imports must not bypass domain rules.

Import processing should support:

```text
Validation

Preview

Duplicate Detection

Error Reporting

Traceability

Controlled Application
```

---

# 89. Exports

Exports require explicit permission.

Sensitive exports may require:

```text
Additional Permission

Reason

Audit

Masking

Scope Restrictions
```

Family Users do not receive unrestricted registry export capabilities.

---

# 90. Product Performance

Famboook should support the expected registry population without architectural redesign.

The application should use appropriate:

```text
Indexes

Pagination

Query Optimization

Caching when justified

Background Jobs

Efficient API Payloads
```

Performance optimizations must be based on actual needs and measurement.

---

# 91. Product Scalability

The architecture should permit future expansion to:

```text
Larger Population

Multiple Family Users

Additional Case Programs

Additional Assessments

PWA

Mobile Application

External Integrations

Regional Scope

Additional Organizations
```

without compromising the core Person/Family model.

---

# 92. API Extensibility

The API-first architecture should allow future authorized clients such as:

```text
Mobile Application

PWA

External Integration

Partner System
```

without moving business rules out of Laravel.

---

# 93. Public Self-Registration

Anonymous public self-registration is out of scope for V1.

Family Portal access requires controlled account creation/activation and identity verification.

---

# 94. Native Mobile Application

A native mobile application is not required for V1.

The responsive Family Portal should satisfy initial mobile usage.

Native applications may be considered later if justified.

---

# 95. PWA

PWA capabilities may be considered later.

Offline storage of sensitive Family data requires separate security design.

PWA capability must not be enabled merely for technical novelty.

---

# 96. External Integrations

Potential future integrations may include:

```text
SMS Providers

Email Providers

Identity Verification Services

Humanitarian Platforms

External Registries

Data Exchange APIs
```

External systems must not bypass Famboook authorization and audit rules.

---

# 97. Product Modules

Initial product modules include:

```text
Authentication

User Administration

Family Registry

Person Registry

Family Memberships

Person Relationships

Residence

Health

Disability

Education

Employment

Assessments

Needs

Assistance

Documents

Notes

Source Forms

Workflow

Verification

Change Requests

Family Portal

Notifications

Duplicate Management

Search

Reports

Executive Dashboard

Audit

System Administration
```

---

# 98. Product Boundary

Famboook V1 is not intended to be:

```text
Accounting Software

Payment Gateway

ERP

Public Social Network

Public Family Directory

Medical Record System

Biometric Identity Platform

Automated Aid Eligibility Engine
```

Future integrations may connect Famboook to specialized systems.

---

# 99. V1 Product Priorities

Priority order:

```text
1. Data Integrity

2. Security

3. Correct Domain Model

4. Workflow Correctness

5. Operational Usability

6. Modern UX

7. Family Self-Service

8. Reporting

9. Extensibility
```

Modern UX is a core requirement, but must not weaken registry integrity or security.

---

# 100. V1 Staff MVP

The Staff Application should eventually support:

```text
Family Management

Person Management

Membership Management

Relationship Management

Residence

Data Entry

Review

Verification

Assessments

Needs

Assistance

Documents

Case Notes

Change Request Review

Duplicate Review

Reports
```

---

# 101. V1 Family Portal MVP

The Family Portal should support:

```text
Authentication

Verified User-Person Link

Authorized Family Scope

Family Overview

Permitted Member Information

Residence

Change Request Creation

Request Tracking

Clarification Response

Supporting Documents

Notifications

Account
```

---

# 102. V1 System Administration

Restricted Filament administration may support:

```text
Users

Roles

Permissions

Family User Accounts

User-Person Links

Reference Data

System Settings

Audit Administration

Technical Operations
```

---

# 103. Product Invariants

```text
PROD-INV-001
A Person is independent from Family membership.

PROD-INV-002
Family Membership is the canonical Family-Person association.

PROD-INV-003
Historical memberships are preserved.

PROD-INV-004
At most one active Household Head exists per Family in V1.

PROD-INV-005
Paper forms are sources, not the digital domain model.

PROD-INV-006
Duplicate Persons are never automatically merged.

PROD-INV-007
Family User submissions do not directly overwrite canonical registry data.

PROD-INV-008
APPROVED and APPLIED are distinct Change Request states.

PROD-INV-009
User identity and Person identity remain separate.

PROD-INV-010
Family Portal access requires verified authorization scope.

PROD-INV-011
Sensitive data is permission-controlled.

PROD-INV-012
Uploaded documents are not automatically verified.

PROD-INV-013
Critical history is preserved.

PROD-INV-014
Derived statistics should come from canonical data.

PROD-INV-015
Family Portal does not expose internal Staff audit or confidential notes.

PROD-INV-016
Laravel is the authoritative business and security layer.

PROD-INV-017
Next.js does not directly access PostgreSQL.

PROD-INV-018
Frontend permission visibility does not replace backend authorization.

PROD-INV-019
Filament does not own independent business logic.

PROD-INV-020
All authorized entry points reuse the same domain rules.

PROD-INV-021
The primary Staff experience is a purpose-built Famboook interface.

PROD-INV-022
Filament is restricted to System / High Administration.

PROD-INV-023
Sensitive documents are private by default.

PROD-INV-024
Frontend validation never replaces Laravel validation.

PROD-INV-025
The API does not expose unrestricted Models.

PROD-INV-026
Arabic and RTL are first-class product requirements.

PROD-INV-027
The Family Portal is mobile-friendly.

PROD-INV-028
Visual design must not compromise authorization or data integrity.

PROD-INV-029
The canonical database is PostgreSQL.

PROD-INV-030
The Famboook Design System remains independent from generic template identity.
```

---

# 104. Approved Product Decisions

### PROD-ADR-001

Family and Person are separate persistent entities.

### PROD-ADR-002

Family Membership is the canonical Family-Person association.

### PROD-ADR-003

Paper forms do not define the database structure.

### PROD-ADR-004

Assessments are separated from permanent registry records.

### PROD-ADR-005

Repeatable information is modeled as repeatable records.

### PROD-ADR-006

Historical state is preserved where operationally meaningful.

### PROD-ADR-007

Duplicate Persons are never automatically merged.

### PROD-ADR-008

Sensitive data requires explicit authorization.

### PROD-ADR-009

Family self-service uses authenticated Family Users.

### PROD-ADR-010

FAMILY_USER is an authorization role independent from Household Head domain status.

### PROD-ADR-011

User and Person identities are separate.

### PROD-ADR-012

User-Person Links provide the identity bridge.

### PROD-ADR-013

Substantive Family User updates use Change Requests.

### PROD-ADR-014

Family User submissions do not directly mutate canonical registry data.

### PROD-ADR-015

APPROVED and APPLIED are distinct.

### PROD-ADR-016

Supporting documents uploaded by Family Users remain unverified until Staff verification.

### PROD-ADR-017

Anonymous public self-registration is out of V1 scope.

### PROD-ADR-018

Family Portal authorization depends on verified identity and current Family relationship.

### PROD-ADR-019

PostgreSQL 16+ is the primary database.

### PROD-ADR-020

Laravel 12 is the authoritative backend and domain layer.

### PROD-ADR-021

Famboook uses an API-first architecture for its primary web product.

### PROD-ADR-022

The primary web frontend uses Next.js, React, and TypeScript.

### PROD-ADR-023

Tailwind CSS is the primary styling foundation.

### PROD-ADR-024

shadcn/ui and Radix UI provide the initial component primitives.

### PROD-ADR-025

Famboook maintains its own Design System.

### PROD-ADR-026

The Staff Application is a custom Next.js experience.

### PROD-ADR-027

The Executive Dashboard is a custom Next.js experience.

### PROD-ADR-028

The Family Portal is a custom Next.js experience.

### PROD-ADR-029

Filament is restricted to System / High Administration.

### PROD-ADR-030

Filament does not replace the operational Staff Application.

### PROD-ADR-031

Laravel Sanctum is the initial first-party web authentication mechanism.

### PROD-ADR-032

Primary web authentication uses secure cookie/session-based authentication.

### PROD-ADR-033

Primary web authentication secrets are not stored in browser localStorage.

### PROD-ADR-034

Laravel Policies and Spatie Permission form the authorization foundation.

### PROD-ADR-035

Important business operations are implemented as reusable Domain Actions.

### PROD-ADR-036

Next.js and Filament reuse the same Laravel domain layer.

### PROD-ADR-037

Laravel API Resources control API data exposure.

### PROD-ADR-038

The initial API is versioned under `/api/v1`.

### PROD-ADR-039

TanStack Query is the preferred frontend server-state layer.

### PROD-ADR-040

React Hook Form and Zod are the preferred frontend form and UX-validation foundation.

### PROD-ADR-041

Laravel remains authoritative for validation and business rules.

### PROD-ADR-042

Sensitive documents use private storage.

### PROD-ADR-043

The preferred initial topology separates primary web, API, and system administration hosts.

### PROD-ADR-044

PostgreSQL is not directly exposed to frontend applications.

### PROD-ADR-045

Arabic and RTL are architectural product requirements.

### PROD-ADR-046

Modern custom UX is a core product requirement rather than post-development visual polish.

---

# 105. Preferred Technology Stack

```text
PRIMARY FRONTEND
────────────────────────────
Next.js
React
TypeScript
Tailwind CSS
shadcn/ui
Radix UI
Lucide Icons


FRONTEND DATA / FORMS
────────────────────────────
TanStack Query
React Hook Form
Zod


BACKEND
────────────────────────────
Laravel 12
REST API
Laravel Sanctum
Laravel Policies
Spatie Permission
Domain Actions
Laravel Queue
Notifications
Audit / Workflow


DATABASE
────────────────────────────
PostgreSQL 16+


STAFF EXPERIENCE
────────────────────────────
Custom Next.js Application


EXECUTIVE EXPERIENCE
────────────────────────────
Custom Next.js Dashboard


FAMILY EXPERIENCE
────────────────────────────
Custom Next.js Family Portal


SYSTEM ADMINISTRATION
────────────────────────────
Filament


FILES
────────────────────────────
Private Storage
```

---

# 106. Preferred Deployment Model

```text
famboook.com
│
└── Next.js
    ├── Staff Application
    ├── Executive Dashboard
    └── Family Portal


api.famboook.com
│
└── Laravel 12
    ├── REST API v1
    ├── Authentication
    ├── Authorization
    ├── Domain Actions
    ├── Workflows
    ├── Audit
    ├── Queue
    └── Private Files


admin.famboook.com
│
└── Laravel + Filament
    └── System / High Administration


Private Infrastructure
│
├── PostgreSQL
├── Private File Storage
└── Queue Infrastructure
```

Final hostnames remain deployment configuration rather than permanent domain-model decisions.

---

# 107. Pending Product Decisions

The following remain intentionally open:

```text
PPD-001
Exact Family User account activation mechanism.

PPD-002
Exact login identifier:
email, mobile, username, or approved combination.

PPD-003
Exact identity verification mechanism.

PPD-004
Whether V1 Family Portal eligibility remains strictly Household Head-only.

PPD-005
Whether multiple Family Users may be active for one Family.

PPD-006
Future Guardian / Authorized Representative policy.

PPD-007
Whether any low-risk Family User updates may eventually bypass full Change Request review.

PPD-008
Exact Family Portal health-data visibility.

PPD-009
Exact Needs and Assistance visibility for Family Users.

PPD-010
Exact Family Portal document visibility.

PPD-011
Notification channels beyond database notifications.

PPD-012
2FA requirement for Staff and/or System Administrators.

PPD-013
Exact session lifetime and re-authentication requirements.

PPD-014
Final production hostnames and infrastructure topology.

PPD-015
Whether Staff and Family routes remain in one Next.js application or are separated later.

PPD-016
Final Famboook visual identity, typography, and design tokens.

PPD-017
Exact Executive Dashboard KPI set.

PPD-018
Whether Redis is required for initial production or introduced only when operationally justified.

PPD-019
PWA requirements after V1.

PPD-020
Future external API/integration authentication strategy.
```

---

# 108. Product Definition of Done

The product architecture is considered ready for implementation when:

```text
Family and Person model is stable

Membership architecture is stable

Canonical vs proposed data is clear

Family User architecture is clear

Change Request model is clear

Authorization model is clear

Primary frontend architecture is clear

Backend authority is clear

Database choice is clear

Staff experience architecture is clear

Family Portal architecture is clear

System Administration boundary is clear

API architecture is clear

Authentication direction is clear

Design System direction is clear

Sensitive file strategy is clear

Implementation-blocking pending decisions are resolved before their affected phase
```

---

# 109. Document Status

```text
Project: Famboook
Document: Product Definition
Version: 1.2
Status: APPROVED
Date: 2026-09-22
```

---

# 110. Change Log

| Version | Date | Status | Description |
|---|---|---|---|
| 1.0 | 2026-09-22 | Superseded | Initial Product Definition |
| 1.1 | 2026-09-22 | Superseded | Added Family User, Family Portal, User-Person Links, Change Requests, notifications, and controlled self-service |
| 1.2 | 2026-09-22 | Approved | Established PostgreSQL, Laravel API-first backend, custom Next.js Staff/Executive/Family experiences, Famboook Design System, Sanctum authentication, Domain Actions, controlled API Resources, and restricted Filament System Administration |

---

# 111. Product Architecture Summary

```text
                           FAMBOOOK
                              │
            ┌─────────────────┼──────────────────┐
            │                 │                  │
      STAFF APPLICATION   EXECUTIVE          FAMILY PORTAL
            │             DASHBOARD               │
            │                 │                  │
            └─────────── NEXT.JS ────────────────┘
                              │
                    Famboook Design System
                              │
                         REST API v1
                              │
                         Laravel 12
                              │
             ┌────────────────┼────────────────┐
             │                │                │
          Domain          Authorization      Workflow
          Actions         Policies/RBAC       Audit
             │                │                │
             └────────────────┼────────────────┘
                              │
                         PostgreSQL
                              │
                       Canonical Registry


                    SYSTEM ADMINISTRATION
                              │
                           Filament
                              │
                       Laravel Core
```

---

# 112. Final Product Principle

Famboook is not:

```text
A paper form converted into a database
```

and it is not:

```text
A generic administration template connected to Family records
```

Famboook is:

```text
A modern, secure, domain-driven Family Registry
and Case Management digital product
with controlled Family self-service.
```