# Famboook
## Implementation Roadmap

**Document:** `07-ROADMAP.md`  
**Version:** 1.2.6  
**Status:** Approved  
**Last Updated:** 2026-09-25  
**Project:** Famboook — Family Registry & Case Management System

---

# 1. Purpose

This document defines the implementation roadmap for Famboook.

It converts the approved product, data, business-rule, database, workflow, and authorization architecture into an ordered delivery plan.

The roadmap is designed to prevent premature UI development, duplicated business logic, weak authorization boundaries, and database redesign later in the project.

The implementation sequence follows:

```text
Architecture
    ↓
Backend Foundation
    ↓
Authentication & Authorization
    ↓
Frontend Foundation
    ↓
Design System
    ↓
Registry Core
    ↓
Operational Workflows
    ↓
Case Management
    ↓
Family Identity
    ↓
Family Portal
    ↓
Change Requests
    ↓
Reporting
    ↓
Security / UAT
    ↓
Pilot
    ↓
Production
```

---

# 2. Final Technology Stack

## Frontend

```text
Next.js
React
TypeScript
Tailwind CSS
shadcn/ui
Radix UI
Lucide Icons
```

## Data / Forms

```text
TanStack Query
React Hook Form
Zod
```

## Backend

```text
Laravel 12
REST API
Laravel Sanctum
Laravel Policies
Domain Actions / Services
Spatie Permission
Laravel Queue
Audit Logging
```

## Database

```text
PostgreSQL 16+
```

## Staff Application

```text
Custom Next.js
```

## Executive Dashboard

```text
Custom Next.js
```

## Family Portal

```text
Custom Next.js
```

## System Administration

```text
Filament
```

## Files

```text
Laravel Private Storage
```

---

# 3. Architecture Principle

The primary architecture is:

```text
Browser
   ↓
Next.js
   ↓ HTTPS / JSON
Laravel REST API
   ↓
Policies / Validation
   ↓
Domain Actions
   ↓
PostgreSQL
```

System Administration:

```text
Filament
   ↓
Laravel Domain Layer
   ↓
PostgreSQL
```

---

# 4. Responsibility Boundaries

## Next.js

Responsible for:

```text
Presentation

Navigation

UX

Forms

Tables

Interactive workflows

Client-side UX validation

Server-state presentation

Responsive layouts

RTL

Family Portal experience

Executive visualization
```

Next.js is not authoritative for:

```text
Authorization

Business Rules

Canonical Validation

Workflow Transitions

Canonical Data Mutation
```

---

# 5. Laravel

Laravel is authoritative for:

```text
Authentication

Authorization

Validation

Business Rules

Domain Actions

Workflow Transitions

Canonical Mutation

Audit

Notifications

Files

Queues

API Contracts
```

---

# 6. PostgreSQL

PostgreSQL is responsible for:

```text
Canonical Persistence

Referential Integrity

Constraints

Indexes

Transactions

Concurrency Support

Historical Data
```

---

# 7. Filament

Filament is restricted to:

```text
System Administration

High Administration

Users

Roles

Permissions

Reference Data

System Settings

Audit

Jobs

Maintenance
```

Filament is not the primary operational Staff Application.

---

# 8. Implementation Philosophy

Famboook will be implemented:

```text
Domain First

API First

Security First

Workflow Aware

Design-System Driven

Testable

Incremental
```

---

# 9. Development Rule

Do not implement a feature simply because a screen can be drawn.

A feature begins only after its:

```text
Domain Model

Business Rules

Permissions

Workflow

API Contract

Data Exposure Rules
```

are sufficiently defined.

---

# 10. Delivery Strategy

Development should proceed in vertical, testable increments.

Example:

```text
Family List

Backend Query
+
Policy
+
API Resource
+
API Endpoint
+
Next.js Screen
+
Loading State
+
Empty State
+
Error State
+
Tests
```

rather than building all backend code first and all frontend code much later.

---

# 11. Phase Overview

```text
Phase 0   Architecture Baseline

Phase 1   Repository & Development Environment

Phase 2   Laravel Backend Foundation

Phase 3   PostgreSQL & Core Database Foundation

Phase 4   Authentication & RBAC Foundation

Phase 5   Next.js Frontend Foundation

Phase 6   Famboook Design System

Phase 7   Reference Data & System Administration

Phase 8   Family & Person Registry

Phase 9   Memberships, Relationships & Residence

Phase 10  Staff Registry Experience

Phase 11  Workflow, Verification & Approval

Phase 12  Health, Disability, Education & Employment

Phase 13  Assessments

Phase 14  Needs & Assistance

Phase 15  Documents & Case Notes

Phase 16  Family User Identity & Access

Phase 17  Family Portal Foundation

Phase 18  Change Request Engine

Phase 19  Family Portal Self-Service

Phase 20  Search & Duplicate Management

Phase 21  Operational & Executive Dashboards

Phase 22  Reports

Phase 23  Import & Export

Phase 24  Notifications & Background Processing

Phase 25  Security Hardening

Phase 26  Performance & Reliability

Phase 27  Comprehensive Testing & UAT

Phase 28  Deployment Foundation

Phase 29  Pilot

Phase 30  Production Rollout

Phase 31  Post-Launch Improvement
```

---

# 12. Phase 0 — Architecture Baseline

## Objective

Freeze the implementation baseline before coding begins.

## Required Documents

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

## Tasks

```text
Review cross-document consistency

Resolve implementation-blocking decisions

Confirm technology stack

Confirm deployment topology

Confirm authentication model

Confirm API-first architecture

Confirm Staff/Family/Admin boundaries

Confirm repository structure
```

## Exit Criteria

```text
Architecture approved

Core invariants documented

Stack approved

Critical authorization model approved

Core database relationships approved

No major contradiction between documents
```

---

# 13. Phase 1 — Repository & Development Environment

## Objective

Create a clean and reproducible project workspace.

## Structure

```text
Famboook/
├── docs/
├── backend/
├── frontend/
├── .gitignore
└── README.md
```

## Tasks

```text
Initialize Laravel project in backend/

Initialize Next.js project in frontend/

Configure Git

Configure environment examples

Configure formatting/linting

Configure local PostgreSQL

Document local setup

Establish branch/commit conventions
```

## Security

Never commit:

```text
.env

Passwords

API secrets

Production keys

Real Family data

Scanned real forms

Real identity documents
```

## Exit Criteria

```text
Laravel runs locally

Next.js runs locally

PostgreSQL connection works

Repository clean

Environment reproducible
```

---

# 14. Phase 2 — Laravel Backend Foundation

## Objective

Establish the authoritative application core.

## Tasks

```text
Install/configure Laravel 12

Configure API routes

Create /api/v1 namespace

Configure API response conventions

Configure exception handling

Configure logging

Configure validation conventions

Create Domain Action structure

Create DTO conventions

Create API Resource conventions

Configure private storage

Configure queue foundation
```

## Suggested Structure

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

## Exit Criteria

```text
/api/v1 available

Standard API errors defined

Domain Action convention established

API Resource convention established

Private storage configured

Basic backend tests passing
```

---

# 15. Phase 3 — PostgreSQL & Core Database Foundation

## Objective

Implement the first canonical schema safely.

## Initial Migration Groups

```text
users

reference tables

families

persons

family_memberships

person_relationships

family_residences
```

Later migrations follow approved dependency order.

## Tasks

```text
Configure PostgreSQL 16+

Create migrations

Add foreign keys

Add critical indexes

Add partial unique indexes

Add chronological constraints

Create model relationships

Create factories

Create synthetic seed data
```

## Critical Constraints

Implement:

```text
One active Family membership per Person

One active Household Head per Family

One current residence per Family

death_date >= birth_date when both exist
```

## Exit Criteria

```text
Core migrations pass

Rollback works

Constraints tested

Synthetic factories available

No real data committed
```

---

# 16. Phase 4 — Authentication & RBAC Foundation

## Objective

Establish authentication and centralized authorization before operational features.

## Tasks

```text
Configure Laravel Sanctum

Configure first-party cookie/session authentication

Configure CSRF

Configure CORS

Install/configure Spatie Permission

Create initial roles

Create initial permissions

Create Policies

Create /api/v1/me

Create authenticated API tests
```

## Initial Roles

```text
SUPER_ADMIN

ADMINISTRATOR

DATA_ENTRY

REVIEWER

SOCIAL_WORKER

REPORTS_VIEWER

FAMILY_USER
```

## Security Rule

Do not store primary authentication bearer tokens in:

```text
localStorage
```

## Exit Criteria

```text
Login works

Logout works

Authenticated API works

Unauthenticated API denied

Roles work

Permissions work

Policies work

/api/v1/me works
```

## Progress

2026-09-26: **Pilot Readiness Slice A — Real Authentication & Staff Access**
delivered (docs/06 §59c, AUTH-ADR-057): Staff login/logout on the Sanctum
session with generic failures and rate limiting, `/api/v1/me`,
`users.is_active` enforced on every Staff API and Filament request, one
Staff role per user, Filament limited to Staff user administration with
escalation rules, permission-aware Staff Portal navigation and actions,
local-only `/dev-login`, reference-only seeding plus
`famboook:create-super-admin`. **Still open:** Laravel Policies for object
authorization and data scopes (only the Staff user policy exists), audit
storage beyond application-log entries, password recovery and 2FA.

---

# 17. Phase 5 — Next.js Frontend Foundation

## Objective

Create the frontend application architecture before building business screens.

## Tasks

```text
Initialize Next.js

Configure TypeScript

Configure Tailwind CSS

Configure shadcn/ui

Configure Radix UI

Configure Lucide Icons

Configure TanStack Query

Configure React Hook Form

Configure Zod

Create API client

Create authentication integration

Create route groups

Create global error handling

Create loading conventions

Create RTL foundation

Create responsive application shell
```

## Recommended Structure

```text
frontend/
├── app/
│   ├── (auth)/
│   ├── (staff)/
│   └── (family)/
├── components/
│   ├── ui/
│   └── famboook/
├── features/
├── hooks/
├── lib/
└── types/
```

## Exit Criteria

```text
Authentication UI connected

Protected routes work

Staff shell works

Family shell foundation exists

RTL works

API client works

TanStack Query works
```

---

# 18. Phase 6 — Famboook Design System

## Objective

Create a reusable product design language before business screens multiply.

Famboook must not become:

```text
A generic admin template
```

## Foundation

Define:

```text
Colors

Typography

Spacing

Radius

Shadows

Borders

Surfaces

States

Breakpoints

RTL behavior

Dark/light capability
```

## Generic Components

```text
Button

Input

Textarea

Select

Checkbox

Radio

DatePicker

Badge

Avatar

Card

StatCard

DataTable

Pagination

Tabs

Drawer

Dialog

Dropdown

Tooltip

Timeline

EmptyState

LoadingState

ErrorState

Skeleton
```

## Domain Components

```text
FamilyHeader

FamilyProfileHeader

PersonIdentityCard

FamilyMemberRow

FamilyMemberCard

HouseholdHeadBadge

VerificationBadge

StatusBadge

ResidenceHistory

AssessmentSummary

NeedCard

AssistanceCard

AssistanceTimeline

ChangeRequestCard

ChangeRequestTimeline

ReviewPanel

DuplicateCandidateCard

ActivityTimeline
```

## UX States

Every reusable feature must consider:

```text
Loading

Empty

Success

Warning

Error

Disabled

Read-only

Restricted
```

## Exit Criteria

```text
Core design tokens approved

Core components reusable

RTL verified

Responsive behavior verified

Staff shell visually consistent

Family Portal can reuse same system
```

---

# 19. Phase 7 — Reference Data & System Administration

## Objective

Implement high-administration capabilities.

## Filament Scope

Install/configure Filament for:

```text
Users

Roles

Permissions

Reference Data

System Settings

Audit access

Queue/job monitoring where appropriate
```

## Reference Data

Initial candidates:

```text
Marital Statuses

Relationship Types

Document Types

Need Types

Assistance Types

Assessment Types

Education Levels

Employment Statuses
```

## Rules

Reference data must use:

```text
Stable code

Display name

Active state

Sort order
```

## Exit Criteria

```text
Filament secured

Only authorized users can access

Reference data manageable

Role/permission administration functional
```

---

# 20. Phase 8 — Family & Person Registry

## Objective

Implement canonical Family and Person domains.

## Backend

Implement:

```text
Family model

Person model

FamilyPolicy

PersonPolicy

Family API Resources

Person API Resources

CreateFamilyAction

CreatePersonAction

Update permitted fields

Archive behavior
```

## APIs

Initial examples:

```text
GET    /api/v1/families

POST   /api/v1/families

GET    /api/v1/families/{family}

GET    /api/v1/persons

POST   /api/v1/persons

GET    /api/v1/persons/{person}
```

Exact API design remains semantic and permission-aware.

## Frontend

Build:

```text
Family List

Family Create

Family Profile

Person List

Person Create

Person Profile
```

## Exit Criteria

```text
Family CRUD/domain operations controlled

Person operations controlled

Authorization tested

Pagination implemented

Sensitive fields controlled
```

## Progress

2026-09-25: **Clan + Branch Structure V1** delivered (docs/03 §7a): Clan →
Branch Groups → Branches → Families; `AL_BREEM` seeded and all existing
families backfilled to it with no Branch; required Clan and optional Branch
on registration and correction with same-Clan integrity; Staff
Administration screen for the structure; `clan.view` / `clan.manage`
(AUTH-ADR-054). The approved Al-Breem taxonomy (17 Branch Groups, 25
Branches) is seeded; existing families remain without a Branch until
classified. **Still open:** Clan/Branch dashboards, reports, bulk
reassignment and import are later work.

---

# 21. Phase 9 — Memberships, Relationships & Residence

## Objective

Represent actual Family structure rather than paper-form rows.

## Implement

```text
Family Memberships

Relationship Types

Person Relationships

Household Head

Membership History

Family Residence

Residence History
```

## Domain Actions

```text
AddFamilyMemberAction

TransferFamilyMemberAction

ChangeHouseholdHeadAction

ChangeFamilyResidenceAction
```

## Critical Tests

```text
Cannot have two active Family memberships

Cannot have two active Household Heads

Cannot have two current residences

Transfer preserves history

Residence change preserves history
```

## Frontend

Build:

```text
Family Members

Member Details

Household Head indicator

Relationship view

Residence section

Residence history
```

## Exit Criteria

```text
Family structure works

Historical relationships preserved

Critical transactions tested

Concurrency protection works
```

## Progress

2026-09-27: **Pilot Readiness Slice C — Data-Entry Corrections** delivered
(docs/03 §93b, AUTH-ADR-059): relationship correction of current members
(household-head invariant enforced), ending an incorrect non-head membership
with a required reason (history kept, Person kept, no auto-transfer),
administrative National ID correction with central masking (SUPER_ADMIN,
ADMINISTRATOR; full value never exposed; duplicate guard; no clear action),
privacy-safe activity events, and optional current governorate/city with
clearer residence / displacement labels. No schema change. **Still open:**
TransferFamilyMemberAction / attaching an existing Person, ChangeHouseholdHeadAction,
re-activating an ended membership, residence history (`residence.change`),
National ID normalization (PDD-001), geographic hierarchy (PDD-006).

---

# 22. Phase 10 — Staff Registry Experience

## Objective

Turn core registry features into a professional operational experience.

## Screens

```text
Staff Dashboard

Family Search

Family Profile

Person Search

Person Profile

Create Family

Add Member

Change Household Head

Change Residence

Transfer Member
```

## UX Requirements

```text
Fast navigation

Clear hierarchy

Contextual actions

Drawers/dialogs where appropriate

Minimal page reloads

Clear status badges

Responsive tables/cards

RTL-first behavior
```

## Exit Criteria

```text
Staff can complete core registry work

No dependency on Filament for daily registry operations

Domain actions used consistently
```

---

# 23. Phase 11 — Workflow, Verification & Approval

## Objective

Implement controlled Staff processing.

## Backend

Implement:

```text
workflow_events

Form statuses

Review transitions

Correction transitions

Verification

Approval

Maker-checker checks
```

## Frontend

Build queues:

```text
Pending Review

Returned for Correction

Pending Verification

Pending Approval
```

## Exit Criteria

```text
Workflow transitions backend-controlled

History available

Unauthorized transitions rejected

Operational queues functional
```

---

# 24. Phase 12 — Health, Disability, Education & Employment

## Objective

Add repeatable Person profile domains.

## Implement

```text
Health Profiles

Health Conditions

Disabilities

Education

Employment
```

## Security

Health/disability data must use restricted permissions.

## Frontend

Add structured Person Profile sections.

## Exit Criteria

```text
Repeatable records supported

Sensitive access controlled

History preserved where applicable
```

---

# 25. Phase 13 — Assessments

## Objective

Support point-in-time Family assessments.

## Implement

```text
Assessment Types

Assessments

Form Submissions

Assessment Workflow

Versioned form structures where required
```

## Principle

Assessment data:

```text
does not automatically overwrite canonical registry data
```

## Frontend

Build:

```text
Assessment List

Assessment Detail

Assessment Form

Assessment Review
```

## Exit Criteria

```text
Assessments versionable

Workflow controlled

Source/date/actor traceable
```

## Progress

2026-09-24: **Quick Multi-Domain Family Assessment V1** delivered as the
first increment (docs/03 §40a): family-level dated snapshots, eight
reference assessment domains, five-level rating scale, DRAFT → COMPLETED
lifecycle, Activity Log integration and the Family Profile "التقييمات"
tab. Assessment Types, Form Submissions, versioned questionnaires, review
workflow and the cross-family Assessment List remain open for this phase.

2026-09-28: **Assessments Pilot Workspace** delivered: the standalone
cross-family Assessment List at `/assessments` (replacing the placeholder),
backed by the read-only registry API `GET /api/v1/assessments`
(`assessment.view`; server-side status and exact Family-code filters,
pagination, DRAFT-first ordering, whole-registry DRAFT/COMPLETED counts),
plus the redesigned Assessment Detail, Draft Editor and New Assessment
screens. The lifecycle stays DRAFT → COMPLETED (completed assessments are
read-only; no reopen, delete or scoring) and the eight domains remain
independent ratings, a missing result meaning "not assessed". Creation
stays family-scoped. Assessment Types, Form Submissions, versioned
questionnaires and the review workflow remain open for this phase.

---

# 26. Phase 14 — Needs & Assistance

## Objective

Implement case-management support.

## Implement

```text
Family Needs

Need Status

Need Priority

Assistance Records

Need-Assistance linking
```

## Rule

```text
Assistance
≠
Automatic Need Closure
```

## Frontend

Build:

```text
Needs Panel

Need Detail

Assistance Timeline

Record Assistance
```

## Exit Criteria

```text
Needs tracked independently

Assistance history preserved

Permissions tested
```

## Progress

2026-09-24: **Needs Management V1** delivered (docs/03 §46a): family- or
person-targeted Needs with optional completed-Assessment source, 14 need
categories, LOW–URGENT priority, OPEN → FULFILLED / CLOSED lifecycle,
Activity Log integration, the Family Profile "الاحتياجات" tab and the
cross-family `/needs` work queue. **Assistance remains open** for this
phase: records, Need–Assistance linking, delivered quantities and partial
fulfilment.

2026-09-24: **Assistance V1-A** delivered (docs/03 §47a–§47c): assistance
programs/campaigns with categories, types, planned items, target count
and dates; DRAFT → OPEN; family targeting preview (AND criteria on family
size, displacement, derived health indicators, open Needs and latest
completed assessment results); human-selected nominations from
targeting, manual search and open Needs; history-preserving removal;
`/assistances` page and workspace. **V1-B remains open:** approval,
rejection, delivery records, delivered quantities/values/dates,
completion/cancellation and Need–delivery linking.

2026-09-24: **Assistance V1-B** delivered (docs/03 §47d–§47i): execution
mode INTERNAL/EXTERNAL; approval (single/bulk) and rejection; INTERNAL
identity-verified full-package delivery (PERSONAL / DELEGATE by an
unmarried son/daughter), NOT_DELIVERED and Reverse Delivery;
`persons.marital_status`; EXTERNAL requested-field catalog, preview,
immutable issued beneficiary lists with encrypted snapshots and XLSX;
derived statistics per mode; completion; Family Profile "المساعدات".
**Still open:** external execution-result entry/import, cancellation,
partial delivery (not planned), Need–delivery linking beyond the source
Need.

---

# 27. Phase 15 — Documents & Case Notes

## Objective

Implement protected supporting information.

## Documents

Implement:

```text
Private uploads

Document metadata

Verification

Family documents

Person documents

Assessment/request supporting documents
```

## Case Notes

Implement:

```text
Person Notes

Case Notes

Visibility levels

Confidential notes
```

## Security

No public storage URLs for sensitive files.

## Exit Criteria

```text
Authorized download works

Unauthorized download denied

Family Portal cannot see Staff-only notes

Document verification separate from upload
```

---

# 28. Phase 16 — Family User Identity & Access

## Objective

Establish secure Family Portal identity before self-service functionality.

## Implement

```text
user_person_links

FAMILY_USER role

Identity verification

Link verification

Activation

Suspension

End link

FamilyAccessService
```

## Resolution

```text
User
 ↓
User-Person Link
 ↓
Person
 ↓
Family Membership
 ↓
Household Head / Policy
 ↓
Family
```

## Tests

```text
Cross-Family denial

Suspended link denial

Inactive membership denial

Household Head change reevaluation

Person death reevaluation
```

## Exit Criteria

```text
Verified Family User can resolve authorized Family

Unauthorized Family access impossible

No users.family_id shortcut
```

---

# 29. Phase 17 — Family Portal Foundation

## Objective

Create the authenticated Family self-service experience.

## Routes

Possible structure:

```text
(family)/
├── home/
├── family/
├── members/
├── residence/
├── requests/
├── documents/
└── notifications/
```

## Initial Read Capabilities

```text
Family Overview

Permitted Members

Permitted Person Data

Residence

Request History

Notifications
```

## Security

API uses dedicated context-aware Resources.

Example:

```text
FamilyPortalPersonResource
```

## Exit Criteria

```text
Family Portal works

Only authorized Family visible

Sensitive fields filtered server-side

Responsive RTL experience complete
```

---

# 30. Phase 18 — Change Request Engine

## Objective

Implement controlled Family self-service mutation.

## Tables

```text
change_request_types

change_requests
```

## Statuses

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

## Implement Domain Actions

```text
SubmitChangeRequestAction

StartChangeRequestReviewAction

ReturnChangeRequestForClarificationAction

ResubmitChangeRequestAction

ApproveChangeRequestAction

RejectChangeRequestAction

ApplyChangeRequestAction
```

## Critical Rule

```text
APPROVED
≠
APPLIED
```

## Application

```text
APPROVED
   ↓
Lock
   ↓
Revalidate
   ↓
Domain Action
   ↓
Canonical Change
   ↓
Audit
   ↓
APPLIED
```

## Exit Criteria

```text
Workflow complete

Transactions tested

Idempotency tested

Concurrency tested

Failure rollback tested
```

---

# 31. Phase 19 — Family Portal Self-Service

## Objective

Connect Family Portal UX to the Change Request Engine.

## Initial Request Types

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

## UX

Build:

```text
Request Wizard

Proposed Changes Review

Supporting Documents

Submit Confirmation

Request Timeline

Clarification Response

Status Tracking
```

## Exit Criteria

```text
Family User can safely request changes

Canonical registry remains Staff-controlled

Family-visible/internal notes separated
```

---

# 31a. Family PWA Program (PWA-0 … PWA-10)

Approved 2026-10-02 (RM-ADR-041). Specification: `11-FAMILY-PORTAL.md`.

Phases 16–19 are delivered through this program. Their objectives and
exit criteria stand; the PWA phases refine their order and add the scope
approved on 2026-10-02.

```text
Phase 16  Family User Identity & Access   → PWA-1, PWA-2
Phase 17  Family Portal Foundation        → PWA-3
Phase 18  Change Request Engine           → PWA-5
Phase 19  Family Portal Self-Service      → PWA-6, PWA-7
New scope                                 → PWA-4, PWA-8, PWA-9, PWA-10
```

Every phase is local, reviewable and independently testable. No phase
deploys or touches Production by itself.

| Phase | Objective | Main scope | Schema impact | Depends on | Exit criteria |
|---|---|---|---|---|---|
| PWA-0 | Baseline | Architecture, business rules, workflows, permissions, product and design direction | None | — | **DONE** (`80235cf`) |
| PWA-1 | Family identity and access | **Starts with a read-only identity-data discovery** (National ID normalization, null/blank, malformed, duplicates and their cause, login-identifier relation, backfill strategy — docs/11 §4); then User-Person Link, Family access resolver, eligibility, trusted-mobile concept, multi-role fixes, coordinator scope foundation | Link, trust state, scope assignment, login identifier | PWA-0; PFP-003, PFP-004, PFP-016 | Cross-family denial; head change, death, suspended Link and deactivation end access; a dual-role user is handled deterministically; Staff login unchanged |
| PWA-2 | Family authentication | National ID activation, OTP, password setup, login, reset, rate limiting, SMS provider abstraction, security audit | OTP challenge, activation state, security events | PWA-1; PFP-001, PFP-002, PFP-005, PFP-020 | No enumeration; brute force and resend abuse blocked; untrusted mobile cannot activate; destination cannot be chosen; no secret logged |
| PWA-3 | Family shell and read-only portal | Route groups, mobile shell, home, family summary, members, account. **PWA-3A DONE** (household summary, dashboard, members, «أسرتي» profile and residence; docs/11 §30a). **PWA-3B** Full Data Visibility (docs/11 §23a, FP-ADR-062): slices 3B.1 … 3B.7 — My Data, self reveal, complete family / declaration / residence / member views, member reveal, «حسابي», health, needs and received assistance | None expected | PWA-2; PWA-3B before PWA-5 | Only the resolved Family is visible; no client Family id; full sensitive values only through dedicated reveal endpoints (no-store, throttled, security-evented without the value); internal Staff / audit / security / targeting data never sent; responses not cached; Staff URLs unchanged |
| PWA-4 | Family Profile Review (amended 2026-10-04) | Derived section completeness and states, head confirmations with a keyed fingerprint, `/family/verification`, dashboard status; Staff Family Verification deferred (PFP-017) | Confirmations only | PWA-3, PWA-5, first PWA-6 types; PFP-023 | Completeness rule-derived; no percentage; declared ≠ registered respected; confirmation never changes canonical data and is never VERIFIED; no eligibility gate; fully audited |
| PWA-5 | Change Request engine and Staff review workspace | Engine, workflow events, lifecycle actions, Staff queue and detail | Change requests, types, workflow events | PWA-1; PFP-008 | All transitions tested; APPROVED ≠ APPLIED; apply transactional and idempotent; rollback and concurrency tested; internal notes never family-visible |
| PWA-6 | Registry requests / life events | CONTACT_UPDATE, RESIDENCE_UPDATE, PERSON_CORRECTION, ADD_FAMILY_MEMBER, BIRTH_REPORT, DEATH_REPORT; evidence; My Requests | Documents | PWA-3, PWA-5 | Per-type validation; duplicate check on apply; evidence private; authentication-mobile change never self-approved |
| PWA-7 | Health, disability and need submissions | Reviewed submissions applied through the existing health and Needs actions | Possibly new types or reference rows only | PWA-6; PFP-006 (visibility resolved, docs/11 FP-ADR-062), PFP-008, PFP-009 | No direct canonical write; minimum-necessary visibility; temporary conditions end correctly; no Assistance created by a Family User |
| PWA-8 | Digital card, QR, public verification, PDF | Credential, issue / revoke / reissue, public page, server PDF | Card credential and history | PWA-3 (card may be pulled ahead of PWA-5–7); PFP-010, PFP-011, PFP-018 | Opaque identifiers; live revocation; enumeration and rate-limit tests; minimal public fields |
| PWA-9 | Notifications, announcements, Coordinator Space | In-app center, system notifications, announcements, audiences, coordinator dashboard and family summaries | Announcements, recipients | PWA-1, PWA-3, PWA-5; PFP-019 | Server-side audience; scope limits; recipient-only reads; queued fan-out; coordinator sees no health details |
| PWA-10 | Installability and hardening | Manifest scoped to `/family`, service worker, security, performance and accessibility review | None | All | No authenticated response cached; no offline sensitive data; session policy decided (PFP-014) |

## Program status and PWA-1 slices (2026-10-02, RM-ADR-042)

```text
PWA-0    Baseline documentation                          DONE
PWA-1A   Identity data discovery (Production, read-only) DONE
PWA-1B   Identity and access design + documentation      DONE
PWA-1C   Schema / foundation                             DONE
PWA-1D   Identity resolver + links                       DONE
PWA-1E   Mobile trust + OTP / SMS abstraction            DONE
PWA-1F   Activation + first Family Portal UI             DONE
PWA-1G   Login / password reset                          DONE
PWA-1H   Coordinator identity / scope                    DONE
PWA-1I   Security hardening / full regression            DONE
```

The PWA-1 and PWA-2 rows of the table above are delivered together through
these slices: activation, OTP, login and reset (the former PWA-2 scope) are
PWA-1E … PWA-1G. No separate PWA-2 phase remains; PWA-3 follows PWA-1I.

| Slice | Content | Exit |
|---|---|---|
| PWA-1C | **DONE.** Migrations for docs/04 §55b; models, enums and factories; strict normalizers; keyed fingerprint service and `config/family_auth.php`; COORDINATOR role and the ten PWA-1 permissions seeded (coordinator assist deferred) | Schema and unit tests; Staff suite unchanged |
| PWA-1D | **DONE.** Security event recorder and session revocation; authentication identity service; eligibility resolver; Link lifecycle actions (no endpoint, no UI); death and National ID correction hooks; `AccountSide`, role checks without order dependence and the `staff.side` Staff API boundary; one migration (`LINK_ENDED`) | Resolver matrix; Staff regression |
| PWA-1E | **DONE.** Trusted-mobile resolver; grant / revoke actions and Staff API; STALE on a mobile change; `SmsSender` with an unconfigured default and a local-only log driver; OTP challenge service (issue, resend, verify, consume, supersede); OTP throttle; `famboook:purge-otp-challenges` scheduled daily. No migration | Trust and OTP matrices; no real SMS possible |
| PWA-1F | **DONE.** Public activation endpoints (start, verify, resend, complete) behind the activation gate, with cache-backed decoys, request limiters and a response-time floor; the completion transaction (new family-side User, FAMILY_USER, link, identity, grant consumed) and the session after it; `family.side`, `GET /family/me` and logout; the Staff routes moved into a `(staff)` route group; the Family theme, `/family/activate` and the first `/family` shell; Vitest component tests. No migration | Activation matrix; anti-enumeration; Staff URLs unchanged |
| PWA-1G | **DONE.** Family login through the authentication identity, requiring the Family context, with one generic failure, a dummy-hash verification for unknown identifiers and a two-tier lockout; password reset (start, verify, resend, complete) on purpose-aware decoys shared with activation; reset completion ending every earlier session; the 72-byte password ceiling for activation and reset; three independent gates; `/family/login`, `/family/forgot-password`, the login link on activation and the guest redirect to login. No migration. The family context MIDDLEWARE and the cross-family IDOR tests move to the first family-data endpoint: no such route exists yet | Login and reset matrices; activation regression |
| PWA-1H | **DONE.** Coordinator scope resolver (union of effective assignments, current hierarchy, fail-closed structure); grant / revoke role and assign / revoke scope actions with a Staff API; `coordinator.space` boundary; coordinator context and scoped family summary endpoints (`coordinator-family.view-summary` approved, COORDINATOR only); `/family/me.coordinator_space`; Coordinator Space in the Family Portal. No migration. `person-mobile-trust.assist` stays withheld: assisted mobile trust becomes a future dedicated workflow | Scope, dual-role, IDOR and privacy matrices |
| — | **TweetsMS SMS delivery — DONE** (2026-10-03, not a lettered slice). `TweetsSmsSender` behind `SmsSender` (`FAMILY_SMS_DRIVER=tweetsms`), `05XXXXXXXX` as stored, success only on code 999, failure classification, no retry; OTP SMS sent after the response without a queue; shorter one-part OTP text; `famboook:sms-check`. No migration | Production: configure the server `.env` and validate (docs/08 §16a) |
| — | Assisted mobile trust (future, unscheduled): a dedicated workflow in which a Coordinator opens or assists a verification within scope; only then may `person-mobile-trust.assist` be granted to COORDINATOR | Workflow design approved first |
| PWA-1I | **DONE.** Decoy concurrency parity (atomic attempts, write-once supersession, one resend claim per send) and IP / global ceiling parity; response floor on verify and complete; attempts counted before the work (login, start); invisible-character normalization; `famboook:family-auth-check`; regression coverage (references, flags mid-flow, National ID correction, eligibility during a session, corrupted RBAC, leakage sweep) and PostgreSQL concurrency tests. No migration | Whole suite; PostgreSQL suite on `famboook_test` before Production |

PWA-1C delivered foundation only: no endpoint, no activation, no OTP, no
SMS, no resolver, no coordinator authorization and no UI. No account, Link,
identity or trust row was created from existing data.

Gates outside PWA-1:

- **Production activation gate:** SMS provider (TweetsMS — integrated;
  to be configured and validated on the server), delivery-failure handling
  (delivered), secure credentials, the scheduler cron (docs/08 §16a). A
  queue worker is no longer needed for SMS.
- **Rollout gate FU-01 — Head Succession:** must be resolved before
  general Family Portal rollout; it is not part of PWA-1C or any PWA-1
  slice (docs/11 §33a).

Infrastructure prerequisites recorded for later phases: a queue worker in
production, which currently runs `QUEUE_CONNECTION=sync` with no worker
(PWA-9 for fan-out). SMS needs none: TweetsMS is called after the
response, without a queue (docs/11 FP-ADR-051).

The Family PWA lives inside the existing Next.js frontend application; no
separate repository-level application is created (docs/11 §25). The
National ID identity-data discovery that blocked PWA-1 is complete and
PFP-003 is resolved (docs/11 §30a). Other recorded follow-ups: docs/11
§33a.

Not in V1: marriage, divorce, widowhood, member transfer and
household-head change flows (docs/11 §14, PFP-012).

## Program status after PWA-3A (2026-10-04, RM-ADR-047)

```text
PWA-3A  Steps 1–4 household read views            DONE, Production-approved
        (summary, dashboard, members, «أسرتي» profile and residence)
Documentation and ADR consolidation               CURRENT
Change Request prerequisites                      NEXT
        CSRF write smoke test (docs/11 FU-08); UNKNOWN → ALIVE — DONE
        (FU-07, PFP-024, FP-ADR-060); Staff paths for death recording and
        household declarations — DONE (FU-10, FP-ADR-061); FAMILY_USER
        request view permission (FU-04)
PWA-3B  Family Portal Full Data Visibility (docs/11 §23a, FP-ADR-062;
        RM-ADR-048) — read-only, before PWA-5:
        3B.1 My Data read view («حسابي» → «بياناتي الشخصية») — DONE
             (GET /family/self, /family/account/me; masked only)
        3B.2 self sensitive-value reveal (National ID, mobile, alternate)
        3B.3 complete Family / declaration / residence / member read views
        3B.4 family-member identity/contact reveal
        3B.5 account view («حسابي»)
        3B.6 health read visibility
        3B.7 needs and received-assistance visibility
PWA-5   Change Request engine + Staff review workspace
PWA-6   first request types (docs/11 FP-ADR-059): RESIDENCE_UPDATE
        (correction), BIRTH_REPORT, ADD_FAMILY_MEMBER, PERSON_CORRECTION,
        DEATH_REPORT (non-head); «طلباتي»
PWA-4   Family Profile Review (/family/verification; docs/11 FP-ADR-057)
later   contact update (CONTACT_UPDATE); the «حسابي» view itself is PWA-3B.5
PWA-7   health and need submissions (PFP-008, PFP-009; health visibility
        decided for PWA-3B.6 — docs/11 FP-ADR-062)
later   optional Staff Family Verification (PFP-017) / PWA-8 card
```

The approved numbering is kept; the delivery order differs from it. None
of these phases makes a family eligible or ineligible (docs/11 §13).

**PWA-3B before PWA-5 (2026-10-05, RM-ADR-048).** A household head must be
able to SEE the canonical value before being asked to submit a correction
for it, so PWA-3B is completed before the Change Request engine becomes
the primary family self-service correction experience. PWA-3B is read
visibility only: it mutates nothing, approves no proposed request type and
needs no migration for the fields already stored (PWA-3B.4 decides its
member reference without exposing an internal numeric id). Documents are
not part of PWA-3B.

---

# 32. Phase 20 — Search & Duplicate Management

## Objective

Provide safe, high-quality registry discovery.

## Search

Implement:

```text
Family Code

Person Code

Name

National ID where authorized

Mobile where authorized
```

## Duplicate Detection

Classes:

```text
EXACT

PROBABLE

POSSIBLE
```

## Duplicate Review

Build:

```text
Duplicate Candidate List

Side-by-side comparison

Review decision

Existing Person reuse
```

## Rule

No automatic Person merge.

## Exit Criteria

```text
Arabic search usable

Sensitive search permission-aware

Duplicate workflow operational
```

## Progress

2026-09-26: **Pilot Readiness Slice B** delivered (docs/03 §93a,
AUTH-ADR-058): server-side Family registry search/pagination with
whole-registry status counts, a People registry (code / name), exact
National ID duplicate prevention on household-head registration, member
addition and Person update (safe references, no merge, advisory-lock
concurrency, pre-check without echoing the value), optional date of birth
(NULL = unknown, UNKNOWN age band), and measured `pg_trgm` search indexes.
**Still open:** Arabic name normalization (PDD-003), National ID
normalization (PDD-001), probable/possible duplicate detection and the human
duplicate-review workflow, retroactive detection of existing duplicates.

---

# 33. Phase 21 — Operational & Executive Dashboards

## Objective

Build decision-support interfaces without mixing them with System Administration.

## Operational Dashboard

Possible metrics:

```text
Families

Persons

Pending Reviews

Pending Approvals

Change Requests

Duplicate Reviews

Open Needs

Recent Assistance
```

## Executive Dashboard

Possible metrics:

```text
Family Coverage

Population Structure

Residence / Displacement

Needs

Assistance

Assessment Coverage

Data Quality

Workflow Performance
```

## Architecture

```text
Next.js Dashboard
     ↓
Laravel Reporting API
     ↓
Authorized Aggregate Queries
```

## Exit Criteria

```text
Operational dashboard useful

Executive dashboard custom-built

No Filament dependency for executive reporting
```

## Progress

2026-09-25: **Operational Dashboard V1** delivered (docs/03 §55a): Staff
dashboard scoped by Clan → Branch Group → Branch with KPIs (active
families, current people, displaced families, open Needs), demographics
with the approved age bands, displacement, health indicators, open Needs,
latest completed assessment results per domain, INTERNAL/EXTERNAL
assistance figures and recent activity. All figures derived on request;
sections gated by domain permissions (AUTH-ADR-055). **Still open:**
Executive Dashboard, Reports V1 (Phase 22; since delivered), trends/date ranges, exports
and any caching justified by measurement.

---

# 34. Phase 22 — Reports

## Objective

Provide controlled analytical reporting.

## Initial Reports

Potential reports:

```text
Family Registry Summary

Population Demographics

Household Composition

Residence / Displacement

Health / Disability

Education

Employment

Needs

Assistance

Assessment Coverage

Workflow Performance

Data Quality
```

## Security

Every report must enforce:

```text
Permission

Data Scope

Field Visibility
```

## Exit Criteria

```text
Reports authorized

Aggregates validated

Sensitive drill-down controlled
```

## Progress

2026-09-26: **Reports V1** implemented (docs/03 §55b, docs/06 §59b,
AUTH-ADR-056): six fixed Staff reports (Population & Families, Health,
Needs, Assessments, Assistance, Data Quality) over the Dashboard's shared
Clan → Branch Group → Branch scope and current population. Health is
aggregate only. Needs covers OPEN / FULFILLED / CLOSED. Assessments use the
latest completed state per domain with a family drill-down. Assistance
figures are scoped to beneficiaries' target families, with INTERNAL and
EXTERNAL semantics kept apart. Data Quality lists exact completeness and
consistency issues with a record drill-down that never shows the missing
value. Each report exports a fixed Arabic RTL XLSX, generated on request.
Everything is derived on read; nothing is stored. **Still open:** Executive
Dashboard, trends and date ranges, education/employment/household
composition reports (no canonical data yet), workflow performance,
duplicate detection, and any caching justified by measurement.

---

# 35. Phase 23 — Import & Export

## Objective

Support controlled bulk data movement.

## Import Workflow

```text
Upload
 ↓
Parse
 ↓
Validate
 ↓
Duplicate Check
 ↓
Preview
 ↓
Review
 ↓
Apply
```

No uncontrolled direct insert into canonical tables.

## Export

Separate permissions:

```text
export.basic

export.sensitive

export.identity-data

export.health-data
```

## Exit Criteria

```text
Import validation works

Failed rows visible

Canonical apply controlled

Exports audited

Sensitive exports protected
```

## Progress

2026-09-29: **Initial Family Import — Phase 1 (foundation)** delivered
(docs/03 §96a): `import_batches` / `import_rows` staging tables with the
excluded-source-field guard (هويتك / الديانة never persisted), Declared
Household Statistics (`family_household_declarations`, docs/03 §55c) and
`RecordPersonDeathAction` (docs/03 §30). No file is parsed and no registry
data is imported. **Still open (Phase 2):** Excel parser, normalization,
validation, duplicate detection, branch matching, preview/review UI and API,
controlled apply; then exports and import audit.

2026-09-29: **Initial Family Import — Phase 2A** delivered (docs/03 §96a,
AUTH-ADR-060): upload of the source workbook for an explicit target Clan,
positional parsing (OpenSpout; formulas never evaluated, cached values
flagged), staging with sanitized raw and normalized payloads, batch summary
and read-only family-key discovery scoped to the target Clan, minimal staff
UI (`/administration/imports`). No registry record is created. **Still
open:** Phase 2B family-key review ("مراجعة مفاتيح الأسر"), Phase 2C
validation and duplicate detection, Phase 2D apply.

2026-09-29: **Import Wizard** delivered (docs/03 §96a): one six-step RTL
Wizard for INITIAL and INCREMENTAL imports — explicit Clan (select or create)
and import mode, workbook inspection with explicit worksheet, reviewable
column mapping (positional, excluded columns never mappable), staging on
mapping confirmation with READY / NEEDS REVIEW / REJECTED counts and issue
breakdown, read-only family keys, staging review and an Apply shell.
Reconciliation statuses are reserved; nothing is written to the registry.
**Still open:** family-key resolution, incremental reconciliation (National
ID candidates, field comparison, Person ≠ Family), Apply.

2026-09-29: **Family-key resolution (Phase 2B)** delivered (docs/03 §96a):
step 4 "مراجعة مفاتيح الأسر" records one explicit decision per batch and
exact source key (existing Branch, new ungrouped Branch via `clan.manage`,
same Branch as another key, or no Branch), with search, filters, reviewed
bulk actions, backend progress and Step 5 gated until every key is resolved.
Source keys stay immutable; nothing is inferred or copied between batches.
**Still open:** suggesting prior decisions in later batches, incremental
reconciliation, Apply.

2026-09-29: **Record reconciliation (Phase 3)** delivered (docs/03 §96a):
Step 5 compares staged rows with the permanent registry without changing it
— HEAD Person by exact National ID, Family only through that Person's active
household-head membership in the batch's Clan, spouses separately — and
classifies each row NEW / UNCHANGED / CHANGED / DUPLICATE_IN_FILE / CONFLICT
/ REVIEW_REQUIRED with evidence, differences and a staleness fingerprint;
Step 6 is gated on a current result. **Still open:** review decisions for
CHANGED / duplicate / conflict rows, Apply.

2026-09-29: **Import Apply foundation (Phase 4B.1)** delivered (docs/03
§96b): Apply lifecycle (PARTIALLY_APPLIED, apply_started_at; a started
Apply is never FAILED), append-only `import_apply_records` provenance,
canonical Person creation (explicit ALIVE / DECEASED / UNKNOWN) and Family
creation reused by staff registration, strict HEAD/SPOUSE lookup. **Still
open:** Apply planner and Dry Run, INITIAL Apply (row transactions, resume,
progress), enabling `import.apply` (SUPER_ADMIN), then INCREMENTAL Apply.

2026-09-30: **Apply planner + Dry Run (Phase 4B.2)** delivered (docs/03 §96b):
a pure planner decides every effect of every row (CREATE / REUSE / OMIT /
BLOCK with reason codes, one owning effect per exact National ID) and Step 6
"المعاينة قبل الاستيراد" presents it read only. **Still open:** INITIAL Apply
execution of the same plan, enabling `import.apply`, INCREMENTAL Apply.

2026-09-30: **Apply execution primitives (Phase 4B.4a)** delivered (docs/03
§96b): approved-plan and structured-error fields, canonical membership and
residence creation actions, the planner's as-of-Apply-start mode for resume
freshness, and the FAMILY_IMPORTED activity contract. **Still open:** row
executor, chunked runner, Apply API, Step 6 Apply UI, local Batch 21 Apply.

2026-09-30: **Row executor (Phase 4B.4b)** delivered (docs/03 §96b): one
approved import row executed in one transaction with full provenance and
cross-row Person coordination (internal only). **Still open:** chunked
runner and failure/resume states, Apply API (`import.apply`), Step 6 Apply
UI, local Batch 21 Apply.

2026-09-30: **Apply runner (Phase 4B.4c)** delivered (docs/03 §96b): internal
start / chunk / resume / completion with a per-batch runner lock and
database-backed verification. **Still open:** PostgreSQL rehearsal on a
disposable database, Apply API (`import.apply`), Step 6 Apply UI, local
Batch 21 Apply.

2026-10-01: **Apply API and activation gate (Phase 4B.4d)** delivered
(docs/03 §96b, docs/06 §61): start / run / resume / progress endpoints with
safe error mapping and logging; `import.apply` exists only behind the Apply
activation gate (SUPER_ADMIN only, closed by default). **Still open:** Step 6
Apply UI (Phase 4B.4e), final end-to-end review, then activation (docs/08
§7a) and local Batch 21 Apply. **Test cleanup follow-up** (pre-existing, not
PostgreSQL-portable; pass on SQLite): `ImportStagingTest` compares
`raw_payload` with key order (jsonb reorders keys), `PublicIdentifierTest`
uses `sqlite_sequence`, and a `FamilyActivityLogTest` health fixture violates
`chk_health_record_shape`.

2026-10-01: **Step 6 Apply UI (Phase 4B.4e)** delivered (docs/03 §96b):
confirmation with derived counts, persisted progress, pause / continue /
resume, another-session handling and read-only Steps 4–5 after Apply
starts; Dry Run `execution_enabled` is server-derived. Verified in an
isolated walkthrough against the disposable test database only. The Apply
gate stays closed. **Still open:** final end-to-end review, then activation
(docs/08 §7a) and the controlled local Batch 21 Apply (Phase 4B.4f).

---

# 36. Phase 24 — Notifications & Background Processing

## Objective

Move non-critical asynchronous work away from synchronous requests.

## Notification Events

Examples:

```text
Change Request Submitted

Returned for Clarification

Approved

Rejected

Applied

Account Activated
```

## Queue Candidates

```text
Notifications

Large exports

Imports

Report generation

Document processing

Scheduled reminders
```

## Principle

Use:

```text
After Commit
```

for notifications dependent on successful canonical changes.

## Exit Criteria

```text
Queue operational

Failed jobs observable

Retry behavior safe

Notifications do not corrupt workflow state
```

---

# 37. Phase 25 — Security Hardening

## Objective

Prepare Famboook for sensitive real-world data.

## Tasks

```text
Review all Policies

Review all API Resources

Review object-level authorization

Test IDOR protection

Review field-level exposure

Review file authorization

Review CORS

Review CSRF

Review cookie configuration

Review rate limiting

Review session security

Review password policy

Introduce 2FA where approved

Review audit coverage

Review logs

Review secrets handling

Review export security
```

## Security Testing

Explicitly test:

```text
Cross-Family access

Privilege escalation

Unauthorized API access

Unauthorized file access

Sensitive field leakage

Mass assignment

Invalid workflow transitions

Role manipulation

Export abuse
```

## Exit Criteria

```text
Critical findings resolved

Sensitive data exposure reviewed

Authorization test suite passes
```

---

# 38. Phase 26 — Performance & Reliability

## Objective

Ensure Famboook performs reliably with real registry volume.

## Tasks

```text
Profile slow API endpoints

Review PostgreSQL indexes

Eliminate N+1 queries

Test pagination

Test Family Profile queries

Test Person search

Test reporting queries

Test queue behavior

Test file delivery

Introduce caching only where measured
```

## Potential Infrastructure

```text
Redis
```

may be introduced if justified for:

```text
Cache

Sessions

Queues
```

It is not mandatory by architecture.

## Exit Criteria

```text
Critical screens meet acceptable performance

No major N+1 issues

Indexes validated

Queue stable
```

---

# 39. Phase 27 — Comprehensive Testing & UAT

## Objective

Validate the system technically and operationally.

## Automated Tests

```text
Unit Tests

Feature Tests

Policy Tests

API Tests

Workflow Tests

Database Constraint Tests

Transaction Tests

Authorization Tests

Family Scope Tests
```

## Frontend Tests

Focus on:

```text
Critical forms

Authentication

Navigation

Workflow actions

Family Portal

Error states
```

## UAT Actors

Include representatives of:

```text
Data Entry

Reviewer

Administrator

Social Worker

Reports Viewer

Family User
```

## Exit Criteria

```text
Critical defects resolved

UAT approved

Core workflows accepted

Security-critical tests pass
```

---

# 40. Phase 28 — Deployment Foundation

## Objective

Prepare production infrastructure.

## Initial Topology

```text
famboook.com
→ Next.js

api.famboook.com
→ Laravel API

admin.famboook.com
→ Laravel / Filament

PostgreSQL
→ Private / non-public
```

## Infrastructure

Configure:

```text
HTTPS

DNS

Environment variables

Application secrets

PostgreSQL

Private storage

Queue workers

Scheduler

Logs

Backups

Monitoring
```

## Sanctum

Validate:

```text
Cookie domain

Secure cookies

SameSite

Stateful domains

CORS

CSRF
```

## Exit Criteria

```text
Staging environment stable

HTTPS works

Authentication works across approved hosts

Database private

Backup configured

Restore tested
```

---

# 41. Phase 29 — Pilot

## Objective

Validate Famboook with a controlled real-world dataset and limited users.

## Pilot Scope

Use a limited approved Family subset.

## Monitor

```text
Data Entry speed

Duplicate rate

Workflow delays

User errors

Family Portal usability

Data quality

Performance

Security incidents

Support requests
```

## Rule

Do not migrate the entire population before pilot findings are reviewed.

## Exit Criteria

```text
Pilot findings documented

Critical issues resolved

Operational SOPs updated

Production rollout approved
```

## Progress

2026-09-27: **Pilot Readiness Slice D — Production Pilot Environment**
prepared in the repository (docs/08-PILOT-DEPLOYMENT.md, docs/09-PILOT-SOP.md,
`deploy/`): environment templates, `.famboook.com` cookie / Sanctum / CORS
decision, Filament bound to `ADMIN_DOMAIN`, `famboook:verify-permissions` run
on every deployment, frontend `/dev-login` 404 and build-time API URL guard,
Nginx/backup/restore templates, checklist. **Still open (server side):**
provisioning, TLS, database preflight, first SUPER_ADMIN, Staff accounts,
first backup and a passed restore test, smoke test — all required before
real data.


---

# 42. Phase 30 — Production Rollout

## Objective

Deploy Famboook as the official operational registry.

## Activities

```text
Production deployment

Initial user provisioning

Role assignment

Approved data migration

Training

Support procedures

Backup verification

Monitoring

Incident response
```

## Rollout Strategy

Prefer controlled rollout rather than one irreversible bulk launch.

## Exit Criteria

```text
System stable

Users operational

Support process active

Backups validated

Monitoring active
```

---

# 43. Phase 31 — Post-Launch Improvement

Potential future capabilities:

```text
PWA

Native Mobile Application

Advanced Analytics

External Integrations

Advanced Duplicate Matching

Authorized Representatives

Guardian Accounts

Multi-Family Access

Automated Low-Risk Change Requests

Advanced Search

Materialized Reporting Views

SMS Integration

Email Integration

External Identity Verification

Advanced Case Management
```

These are not required for V1 unless separately approved.

---

# 44. MVP Strategy

Famboook should not wait for every future capability before delivering value.

Three delivery milestones are recommended.

---

# 45. MVP 1 — Internal Registry

Includes:

```text
Authentication

RBAC

Family Registry

Person Registry

Memberships

Household Head

Relationships

Residence

Staff Application

Basic Workflow

Basic Search

Audit
```

Outcome:

```text
Staff can operate a structured digital Family Registry.
```

---

# 46. MVP 2 — Case Management

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

Outcome:

```text
Famboook becomes a Family Registry + Case Management platform.
```

---

# 47. MVP 3 — Family Self-Service

Adds:

```text
Family User Identity

Family Portal

Change Requests

Supporting Documents

Notifications

Controlled Self-Service
```

Outcome:

```text
Verified Family representatives can safely interact with the registry.
```

---

# 48. Executive Reporting Milestone

Executive Dashboard may begin once sufficient canonical data exists.

Do not build executive charts over unstable or poorly defined data.

Recommended dependency:

```text
Registry
+
Assessments
+
Needs / Assistance
+
Workflow Data
        ↓
Executive Dashboard
```

---

# 49. Design System Milestone

Design System must begin before broad Staff UI development.

It should evolve during implementation.

Do not wait to create every possible component before feature development.

Use:

```text
Foundation
   ↓
First components
   ↓
Real feature usage
   ↓
Refinement
   ↓
Expansion
```

---

# 50. API Contract Strategy

Frontend and backend should agree on explicit API contracts.

For each endpoint define:

```text
Method

Path

Authentication

Permission

Request Schema

Response Resource

Error Cases

Pagination

Workflow Conditions
```

---

# 51. API Versioning

Initial API:

```text
/api/v1
```

Breaking external contract changes should not be introduced casually.

Internal implementation may evolve without changing the contract.

---

# 52. Frontend Data Strategy

Use:

```text
TanStack Query
```

for:

```text
Server state

Caching

Invalidation

Loading

Refetch

Mutations
```

Do not duplicate canonical server data into unnecessary global client stores.

---

# 53. Form Strategy

Use:

```text
React Hook Form
+
Zod
```

for frontend form experience.

Laravel remains authoritative.

```text
Zod
→ UX validation

Laravel
→ Security/business validation
```

---

# 54. Frontend Component Strategy

Prefer domain-aware composition.

Example:

```text
FamilyProfile
├── FamilyHeader
├── FamilyStats
├── HouseholdHeadCard
├── MembersSection
├── ResidenceSection
├── NeedsSection
└── ActivityTimeline
```

rather than a generic database-admin form.

---

# 55. Responsive Strategy

The product must support:

```text
Desktop

Tablet

Mobile
```

Family Portal requires especially strong mobile usability.

Staff data-heavy interfaces may optimize primarily for desktop/tablet while remaining usable on smaller screens.

---

# 56. RTL Strategy

Arabic RTL is a first-class requirement.

Test:

```text
Navigation

Tables

Forms

Drawers

Dialogs

Icons

Breadcrumbs

Timelines

Charts

Pagination

Date inputs
```

Do not postpone RTL testing until project completion.

---

# 57. Low-Bandwidth Strategy

Optimize for constrained connections.

Guidelines:

```text
Paginate large data

Avoid unnecessary payloads

Compress assets

Lazy-load heavy sections

Avoid excessive client JavaScript

Avoid unnecessary images

Cache appropriate read data

Provide clear loading states
```

---

# 58. Accessibility

Design components should consider:

```text
Keyboard navigation

Focus states

Labels

Contrast

Semantic markup

Screen-reader structure

Touch target size
```

---

# 59. Error Handling

Define standard API errors.

Possible categories:

```text
VALIDATION_ERROR

UNAUTHENTICATED

FORBIDDEN

NOT_FOUND

CONFLICT

INVALID_STATE

DUPLICATE_CANDIDATE

DOMAIN_RULE_VIOLATION

SERVER_ERROR
```

Frontend should map these to understandable UX.

---

# 60. Conflict Handling

Use HTTP conflict semantics where appropriate for:

```text
Stale state

Concurrent update

Invalid current workflow state

Uniqueness conflict
```

Exact status codes should be standardized during API foundation.

---

# 61. Audit Strategy

Audit must be integrated while features are built.

Do not postpone audit implementation until the end.

Critical operations include:

```text
Family changes

Person changes

National ID changes

Membership changes

Household Head changes

Residence changes

Death recording

Document verification

Change Request application

Role/permission changes

Sensitive exports
```

---

# 62. Data Migration Strategy

Existing paper/legacy data requires controlled migration.

Pipeline:

```text
Source
 ↓
Staging
 ↓
Validation
 ↓
Normalization
 ↓
Duplicate Detection
 ↓
Review
 ↓
Canonical Import
```

Do not directly import unverified spreadsheets into canonical tables.

---

# 63. Paper Form Digitization

Paper forms remain source evidence.

Digitization should preserve:

```text
Paper Form Number

Source

Entry Actor

Entry Date

Verification State

Supporting scan where authorized
```

The database model must not reproduce paper rows literally.

---

# 64. Synthetic Development Data

All local/demo/testing environments should use synthetic Family data.

Never use real National IDs or sensitive health information in Git.

---

# 65. Database Migration Discipline

Each schema change requires:

```text
Migration

Review

Test

Rollback consideration
```

Do not rely on manual production SQL changes.

---

# 66. Seeder Strategy

Use deterministic seeders for:

```text
Roles

Permissions

Reference Data

Change Request Types
```

Production seeders must not create uncontrolled demo users or sensitive sample data.

---

# 67. Git Workflow

Recommended normal flow:

```powershell
git status
git add .
git commit -m "..."
git push
```

Commits should represent coherent implementation units.

Examples:

```text
feat: add family registry API

feat: add staff family profile

feat: implement household head workflow

feat: add family portal authentication

test: cover change request application

docs: update deployment architecture
```

---

# 68. Branch Strategy

For an initial small development team, avoid unnecessary Git complexity.

A practical model:

```text
main
+
short-lived feature branches when needed
```

Production release branching may be introduced later if team/release complexity requires it.

---

# 69. Definition of Done — Backend Feature

A backend feature is complete when:

```text
Migration complete if required

Model/domain structure complete

Validation complete

Authorization complete

Domain Action complete

Transaction defined where needed

Audit implemented where required

API Resource complete

API endpoint complete

Tests pass

Error behavior defined
```

---

# 70. Definition of Done — Frontend Feature

A frontend feature is complete when:

```text
API integrated

Loading state implemented

Empty state implemented

Error state implemented

Authorization-aware UX implemented

Responsive behavior tested

RTL tested

Form validation implemented

Success/error feedback implemented

Design System components reused
```

---

# 71. Definition of Done — Workflow Feature

A workflow feature is complete when:

```text
States defined

Transitions defined

Permissions defined

Invalid transitions rejected

Workflow events recorded

Audit recorded

Transaction implemented

Concurrency addressed

Idempotency addressed where needed

UI actions connected

Tests complete
```

---

# 72. Definition of Done — Sensitive Feature

A sensitive feature additionally requires:

```text
Field exposure reviewed

Object authorization tested

Logging reviewed

Export implications reviewed

Family Portal exposure reviewed

File access reviewed where relevant
```

---

# 73. Testing Pyramid

Use a balanced strategy:

```text
Many focused Unit Tests

Many Backend Feature/Policy Tests

Critical Integration Tests

Selected Frontend Tests

Selected End-to-End Tests
```

Do not depend only on manual browser testing.

---

# 74. Critical Automated Test Areas

Priority tests:

```text
Authentication

Authorization

Family Scope

Household Head invariant

Membership invariant

Residence invariant

Person death

Duplicate handling

Change Request workflow

Change Request application

Private documents

Sensitive fields

Exports
```

---

# 75. CI Foundation

Introduce CI early enough to prevent broken main branch.

Initial CI should run:

```text
Backend tests

Frontend type checking

Frontend linting

Frontend build
```

Later:

```text
Security checks

End-to-end tests
```

---

# 76. Environment Strategy

At minimum:

```text
Local

Staging

Production
```

Production data must not be casually copied into development environments.

---

# 77. Staging

Staging should resemble production architecture sufficiently to test:

```text
Subdomains

Sanctum

Cookies

CORS

CSRF

Private storage

Queues

Scheduler

Deployment
```

---

# 78. Secrets

Secrets belong in environment/secret management.

Never Git:

```text
APP_KEY

DB_PASSWORD

SMTP_PASSWORD

Storage Credentials

API Keys
```

---

# 79. Database Backup

Before production rollout define:

```text
Backup schedule

Retention

Storage location

Encryption

Restore procedure

Restore test frequency
```

---

# 80. File Backup

Database backup alone is insufficient if documents are stored separately.

Private file storage requires an appropriate backup/recovery strategy.

---

# 81. Monitoring

Production monitoring should cover:

```text
Application errors

API failures

Queue failures

Disk/storage

Database health

Backup status

Authentication anomalies

Performance
```

---

# 82. Logging

Logs must support troubleshooting without leaking excessive sensitive information.

Never log:

```text
Passwords

Authentication secrets

Full sensitive document contents
```

Sensitive identity data should be minimized.

---

# 83. Deployment

Deployment should be repeatable.

Avoid manual server modifications that cannot be reproduced.

Preferred future process:

```text
Git
 ↓
Build/Test
 ↓
Deploy
 ↓
Migrate
 ↓
Restart Workers
 ↓
Health Check
```

---

# 84. Migration Deployment

Production migration process must include:

```text
Backup consideration

Migration review

Compatibility

Rollback strategy

Worker compatibility
```

High-risk schema changes may require staged deployment.

---

# 85. Release Gate

A production release should not proceed if:

```text
Critical tests fail

Known critical authorization vulnerability exists

Migration is unreviewed

Backup is unavailable for high-risk migration

Required environment configuration is missing
```

---

# 86. Pilot Data Strategy

The first real dataset should be limited.

Start with enough Families to validate:

```text
Data structure

Data-entry process

Duplicate detection

Household structure

Performance

User training
```

before large-scale migration.

---

# 87. Training

Operational training should be role-specific.

Examples:

```text
Data Entry Training

Reviewer Training

Social Worker Training

Administrator Training

Family User Guidance
```

---

# 88. SOPs

Operational SOPs should accompany production.

Potential SOPs:

```text
Family Registration

Duplicate Review

Household Head Change

Person Death

Membership Transfer

Document Verification

Change Request Review

Sensitive Export

Account Recovery
```

---

# 89. Data Quality

Data quality monitoring should include:

```text
Missing National IDs where expected

Potential duplicate National IDs

Potential duplicate Persons

Families without Household Head

Families with invalid membership state

Missing current residence

Invalid date relationships

Unverified records
```

---

# 90. Data Quality Dashboard

A Staff Data Quality view may eventually show:

```text
Missing Required Data

Duplicate Candidates

Unverified Records

Families Requiring Head Review

Incomplete Assessments

Invalid/Expired Documents
```

---

# 91. Technical Debt Rule

Technical debt may be accepted deliberately.

It must not compromise:

```text
Security

Canonical Data Integrity

Authorization

Auditability

Historical Integrity
```

---

# 92. Deferred Features

Unless separately approved, defer:

```text
Native mobile application

Public self-registration

External public API

Automated Person merging

AI-driven eligibility decisions

Complex workflow builder

Multi-tenant SaaS architecture

Advanced analytics warehouse

Real-time WebSocket architecture

Redis dependency without measured need
```

---

# 93. Future PWA

The Next.js Family Portal may later support PWA capabilities.

Possible features:

```text
Installability

Offline shell

Cached safe read views

Background synchronization where appropriate
```

Sensitive offline data requires separate security review.

---

# 94. Future Mobile Application

Because Laravel provides an API-first backend, a future mobile application can reuse authorized domain APIs.

Mobile requirements must not weaken current web security architecture.

---

# 95. External Integrations

Potential future integrations:

```text
SMS

Email

Identity verification

External assistance systems

Data exchange APIs
```

All integrations require explicit security and data-sharing review.

---

# 96. Architecture Decision Discipline

Significant changes should be documented before becoming accidental architecture.

Examples:

```text
Switch authentication strategy

Introduce Redis

Introduce object storage

Add representative accounts

Introduce mobile API tokens

Introduce external API

Change Family membership model
```

---

# 97. Roadmap Dependencies

Important dependencies:

```text
Family Portal
depends on
Family User Identity
+
Authorization
+
Registry

Change Requests
depend on
Workflows
+
Domain Actions
+
Family Portal Identity

Executive Dashboard
depends on
Stable canonical data

Sensitive exports
depend on
Authorization
+
Audit

Pilot
depends on
Security
+
UAT
+
Backup
```

---

# 98. What Must Not Happen

Do not:

```text
Build the whole UI before APIs exist

Use Filament as the Staff Application

Allow frontend direct database access

Duplicate business logic in Next.js

Allow Family Users direct canonical CRUD

Return unrestricted Eloquent Models

Store auth tokens in localStorage

Make sensitive storage public

Auto-merge duplicate Persons

Store derived counts as canonical truth

Skip Policies because buttons are hidden

Skip transactions for multi-record operations

Import real data directly without validation

Commit real Family data to Git
```

---

# 99. Recommended First Implementation Slice

After documentation is synchronized, the first technical slice should be:

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
Authentication
    +
Famboook App Shell
```

Then:

```text
Family
    ↓
Person
    ↓
Membership
    ↓
Household Head
    ↓
Residence
```

This establishes the core registry before secondary domains.

---

# 100. Recommended First User Journey

The first complete Staff journey should be:

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

This journey validates most core architectural decisions early.

---

# 101. Recommended Second User Journey

```text
Staff Login
  ↓
Search Family
  ↓
Open Family
  ↓
Change Residence
  ↓
Old Residence preserved
  ↓
New Residence becomes current
  ↓
Audit visible
```

This validates historical data and Domain Actions.

---

# 102. Recommended Third User Journey

```text
Verified Family User Login
  ↓
Open Family Portal
  ↓
View permitted Family data
  ↓
Submit Contact Update
  ↓
Staff Review
  ↓
Approve
  ↓
Apply
  ↓
Canonical data updated
  ↓
Family User sees final status
```

This validates the complete self-service architecture.

---

# 103. Recommended Fourth User Journey

```text
Family User
  ↓
Submit Death Report
  ↓
Staff Review
  ↓
Approval
  ↓
RecordPersonDeathAction
  ↓
Household Head Review if applicable
  ↓
Family access reevaluated
```

This validates high-impact workflow behavior.

---

# 104. Phase Completion Discipline

Do not interpret the roadmap as:

```text
Finish every detail of Phase N
before touching any code in Phase N+1
```

Some work may overlap.

However, dependencies must remain respected.

Example:

The Design System may continue evolving while Registry screens are built.

But Family Portal self-service must not bypass an unfinished authorization foundation.

---

# 105. Roadmap Invariants

```text
RM-INV-001
Laravel remains the authoritative backend.

RM-INV-002
PostgreSQL remains the canonical persistent database.

RM-INV-003
Next.js is the primary product frontend.

RM-INV-004
Staff operational interfaces are custom Next.js interfaces.

RM-INV-005
Family Portal is a custom Next.js interface.

RM-INV-006
Executive Dashboard is a custom Next.js interface.

RM-INV-007
Filament is restricted to System / High Administration.

RM-INV-008
All interfaces reuse the same Laravel domain layer.

RM-INV-009
Business rules are not duplicated as authoritative frontend logic.

RM-INV-010
Authorization is implemented before sensitive operational functionality.

RM-INV-011
The Design System begins before broad UI implementation.

RM-INV-012
Family and Person core domains precede secondary case-management domains.

RM-INV-013
Family User identity precedes Family Portal mutation functionality.

RM-INV-014
Family Portal canonical changes use Change Requests.

RM-INV-015
Sensitive files remain private.

RM-INV-016
Audit is implemented alongside critical features.

RM-INV-017
Real data is not committed to source control.

RM-INV-018
Critical workflows are tested before production.

RM-INV-019
Security hardening occurs before production rollout.

RM-INV-020
A pilot occurs before full production rollout.

RM-INV-021
Frontend applications never directly access PostgreSQL.

RM-INV-022
API contracts are versioned under /api/v1.

RM-INV-023
Laravel remains authoritative for validation.

RM-INV-024
Redis is introduced only when justified.

RM-INV-025
Executive reporting does not replace operational authorization.

RM-INV-026
Deployment must include backup and restore capability.

RM-INV-027
RTL is tested throughout implementation.

RM-INV-028
Family Portal is designed for mobile usability.

RM-INV-029
Imports do not directly bypass the domain layer.

RM-INV-030
Production releases require security and test gates.
```

---

# 106. Approved Roadmap Decisions

### RM-ADR-001
Famboook uses an API-first implementation strategy.

### RM-ADR-002
Laravel 12 is the authoritative backend.

### RM-ADR-003
PostgreSQL 16+ is the primary database.

### RM-ADR-004
Next.js + React + TypeScript is the primary frontend.

### RM-ADR-005
Tailwind CSS + shadcn/ui + Radix UI form the UI foundation.

### RM-ADR-006
Famboook maintains its own Design System.

### RM-ADR-007
Staff operations use custom Next.js interfaces.

### RM-ADR-008
Executive dashboards use custom Next.js interfaces.

### RM-ADR-009
Family Portal uses custom Next.js interfaces.

### RM-ADR-010
Filament is restricted to System / High Administration.

### RM-ADR-011
Laravel Sanctum provides first-party web authentication.

### RM-ADR-012
Spatie Permission provides RBAC.

### RM-ADR-013
Laravel Policies provide object authorization.

### RM-ADR-014
Domain Actions contain important business operations.

### RM-ADR-015
The API is versioned under `/api/v1`.

### RM-ADR-016
TanStack Query manages frontend server state.

### RM-ADR-017
React Hook Form + Zod provide frontend form UX.

### RM-ADR-018
Laravel remains authoritative for validation.

### RM-ADR-019
Private storage is used for sensitive documents.

### RM-ADR-020
Redis is not required for the initial architecture.

### RM-ADR-021
Reference/system administration may use Filament.

### RM-ADR-022
Core Registry is implemented before Family self-service.

### RM-ADR-023
Family User identity is implemented before Family Portal mutation.

### RM-ADR-024
Change Requests mediate Family User canonical changes.

### RM-ADR-025
Operational and Executive dashboards remain separate from Filament.

### RM-ADR-026
Audit is implemented incrementally with domain features.

### RM-ADR-027
Search and duplicate management are explicit product capabilities.

### RM-ADR-028
Imports use validation/staging before canonical application.

### RM-ADR-029
Exports require dedicated authorization.

### RM-ADR-030
Security hardening occurs before pilot/production.

### RM-ADR-031
A controlled pilot precedes full rollout.

### RM-ADR-032
Initial deployment uses separate app/API/admin hosts.

### RM-ADR-033
Production PostgreSQL is not publicly exposed.

### RM-ADR-034
RTL is a first-class implementation requirement.

### RM-ADR-035
Low-bandwidth performance is a first-class implementation concern.

### RM-ADR-036
Development and demo environments use synthetic data.

### RM-ADR-037
CI should be introduced during foundation work.

### RM-ADR-038
Staging must test the real authentication/subdomain architecture.

### RM-ADR-039
MVP delivery is divided into Registry, Case Management, and Family Self-Service.

### RM-ADR-040
Native mobile and advanced integrations remain post-V1 unless separately approved.

### RM-ADR-041
The Family Portal is delivered as the Family PWA Program, PWA-0 … PWA-10 (§31a), which carries Phases 16–19 and adds Profile Completion, Family Verification, the Digital Household Head Card, announcements, Coordinator Space and installability. Each phase is local, reviewable and independently testable.

### RM-ADR-042
PWA-0, PWA-1A and PWA-1B are done. PWA-1 is implemented as slices PWA-1C … PWA-1I, which also deliver the former PWA-2 authentication scope. Production activation is gated on SMS and queue prerequisites; general rollout is gated on Head Succession (FU-01), which is outside PWA-1.

### RM-ADR-043
TweetsMS SMS delivery is delivered between PWA-1H and PWA-1I without a slice letter. It sends after the response without a queue, so the Production activation gate no longer needs a queue worker for SMS; configuring and validating TweetsMS on the server and the scheduler cron remain Production prerequisites.

### RM-ADR-048
PWA-3B — Family Portal Full Data Visibility (docs/11 §23a, FP-ADR-062) is inserted before PWA-5, in slices 3B.1 My Data, 3B.2 self reveal, 3B.3 complete family / declaration / residence / member views, 3B.4 member identity/contact reveal, 3B.5 account view, 3B.6 health visibility, 3B.7 needs and received assistance. The head sees the canonical value before any correction request; PWA-3B is read-only, approves no proposed request type and adds no Documents phase. Completed historical phases are not renumbered.

### RM-ADR-047
PWA-3A (household read views) is done and Production-approved. Before any update workflow, documentation is consolidated (Family Profile Review separate from Account and Staff Family Verification, no eligibility gate, registered vs living members). Delivery order: Change Request prerequisites → PWA-5 → first PWA-6 types → PWA-4 Family Profile Review → account / contact → PWA-7 → optional Staff Family Verification / PWA-8. Phase numbers are unchanged.

### RM-ADR-046
After the Production pilot (2026-10-04), first activation stops answering refused inputs with a synthetic masked-mobile decoy: one generic refusal on step 1 instead (docs/11 FP-ADR-054). Delivered as a correction of the first self-activation work; no migration.

### RM-ADR-045
First self-activation (FP-ADR-053) is delivered after PWA-1I without a slice letter: activation no longer needs a Staff-granted mobile trust; a correct code to the confirmed registered number creates SELF_OTP trust. Staff trust grants stay available, and a Staff mobile-trust UI is no longer a prerequisite for a pilot cohort (it remains useful for revocation and corrections).

### RM-ADR-044
PWA-1I is done and closes PWA-1. It hardens without expanding: no migration, no API or frontend change. Production activation still needs the server-side validation recorded in docs/08 §16a (readiness check, PHP-FPM settings, response-floor measurement, PostgreSQL suite, TweetsMS test SMS, scheduler cron), the Staff mobile-trust UI before any cohort beyond a test household, a limited-cohort CGNAT review before general rollout, and FU-01 Head Succession for general rollout. PWA-3 follows.
```

---

# 107. Pending Roadmap Decisions

```text
PRM-001
Exact local development environment for PostgreSQL.

PRM-002
Exact production hosting provider.

PRM-003
Exact Next.js hosting/runtime strategy.

PRM-004
Exact Laravel hosting/runtime strategy.

PRM-005
Exact PostgreSQL hosting strategy.

PRM-006
Exact private file storage provider.

PRM-007
Exact queue driver at launch.

PRM-008
Exact session driver at launch.

PRM-009
Whether Redis is required at launch.

PRM-010
Exact CI/CD provider and workflow.

PRM-011
Exact staging domain names.

PRM-012
Exact production backup schedule.

PRM-013
Exact monitoring platform.

PRM-014
Exact error tracking platform.

PRM-015
Exact log retention.

PRM-016
Exact 2FA implementation and rollout.

PRM-017
Exact initial pilot Family count.

PRM-018
Exact legacy/paper migration volume.

PRM-019
Exact initial Staff user count.

PRM-020
Exact training plan.

PRM-021
Exact production rollout batches.

PRM-022
Exact Family Portal launch timing relative to Staff Registry.

PRM-023
Exact notification channels at launch.

PRM-024
Whether PWA capabilities are part of V1 or post-V1.

PRM-025
Exact end-to-end testing framework.

PRM-026
Exact frontend unit/component testing framework.

PRM-027
Exact deployment rollback strategy.

PRM-028
Exact disaster-recovery targets.

PRM-029
Exact accessibility target level.

PRM-030
Exact performance SLAs.
```

---

# 108. Immediate Next Steps

After the documentation set is synchronized:

```text
1. Finalize 00-PROJECT-CONTEXT.md

2. Commit documentation baseline

3. Create Laravel 12 project in backend/

4. Create Next.js project in frontend/

5. Configure PostgreSQL

6. Establish /api/v1

7. Configure Sanctum

8. Configure Spatie Permission

9. Build authentication baseline

10. Establish frontend App Shell

11. Establish Famboook Design System foundation

12. Implement Family / Person core
```

---

# 109. Current Project Position

At the end of this document update:

```text
Requirements
      ✓

Product Definition
      ✓

Data Dictionary
      ✓

Business Rules
      ✓

Database Architecture
      ✓

Workflows
      ✓

Permissions
      ✓

Roadmap
      ✓

Project Context Sync
      ✓

Technical Foundation
      ↓
NEXT
```

Documentation Baseline v1.2 is complete. Project Context synchronization is complete per `00-PROJECT-CONTEXT.md` v1.2, the final synchronized architecture baseline. Phase 1 — Repository & Development Environment (Technical Foundation) is the next implementation stage.

---

# 110. Final Roadmap Principle

Famboook will not be built as:

```text
Database
   ↓
Generic Admin Panel
   ↓
More Screens
```

It will be built as:

```text
Domain
   ↓
Business Rules
   ↓
Secure Laravel Core
   ↓
PostgreSQL
   ↓
Versioned API
   ↓
Famboook Design System
   ↓
Purpose-Built Next.js Experiences
```

The result should feel like a coherent product, not a collection of CRUD pages.

---

# 111. Document Status

```text
Project: Famboook
Document: Implementation Roadmap
Version: 1.2.4
Status: APPROVED
Date: 2026-09-24
```

---

# 112. Change Log

| Version | Date | Status | Description |
|---|---|---|---|
| 1.0 | 2026-09-22 | Superseded | Initial implementation roadmap |
| 1.1 | 2026-09-22 | Superseded | Expanded Family Portal, Change Request, security, deployment, pilot and operational phases |
| 1.2.29 | 2026-10-02 | Approved | PWA-1E done (mobile trust lifecycle and Staff API, SMS abstraction, OTP challenge service, throttle, cleanup; no migration); PWA-1F next |
| 1.2.30 | 2026-10-02 | Approved | PWA-1F done (activation endpoints with decoys, limiters and floor; completion transaction and session; Family API boundary and `/family/me`; `(staff)` route group; Family theme, activation flow and first shell; no migration); PWA-1G next and added to the Production activation gate |
| 1.2.31 | 2026-10-03 | Approved | PWA-1G done (Family login, password reset, purpose-aware decoys, session revocation on reset, 72-byte password ceiling, three independent gates, login and forgot-password screens; no migration); the family context middleware moves to the first family-data endpoint; PWA-1H next |
| 1.2.32 | 2026-10-03 | Approved | PWA-1H done (coordinator resolver, administration actions and Staff API, Coordinator Space boundary, scoped summaries, Family Portal Coordinator Space; no migration); assisted mobile trust moved to a future dedicated workflow; PWA-1I next |
| 1.2.33 | 2026-10-03 | Approved | TweetsMS SMS delivery done (driver, code-999 criterion, failure classes, after-response without a queue, OTP text, `famboook:sms-check`; no migration); Production gate updated — queue worker no longer needed for SMS; RM-ADR-043. PWA-1I next |
| 1.2.34 | 2026-10-03 | Approved | PWA-1I done (decoy concurrency and ceiling parity, response floor on all OTP steps, attempt accounting, invisible-character normalization, readiness command, regression and PostgreSQL concurrency coverage; no migration); RM-ADR-044; PWA-1 complete, PWA-3 next |
| 1.2.35 | 2026-10-04 | Approved | First self-activation done (masked confirmation, SELF_OTP trust on a correct code; one migration); RM-ADR-045 |
| 1.2.36 | 2026-10-04 | Approved | RM-ADR-046: first-activation refusal replaces the masked-mobile decoy after the Production pilot; no migration |
| 1.2.37 | 2026-10-04 | Approved | PWA-3A done; documentation and ADR consolidation; PWA-4 renamed Family Profile Review and sequenced after PWA-5 and the first PWA-6 types; program status after PWA-3A (RM-ADR-047) |
| 1.2.38 | 2026-10-04 | Approved | Change Request prerequisite FU-07 / PFP-024 done: ConfirmPersonAliveAction with a Staff endpoint and action (docs/11 FP-ADR-060) |
| 1.2.39 | 2026-10-05 | Approved | Change Request prerequisite FU-10 done: Staff death recording (verification method, irreversible, head death without succession) and Staff household declarations (stale-write protected); no migration (docs/11 FP-ADR-061) |
| 1.2.40 | 2026-10-05 | Approved | PWA-3B Family Portal Full Data Visibility inserted before PWA-5 with slices 3B.1 … 3B.7 (RM-ADR-048, docs/11 FP-ADR-062); PWA-3 table row and program status updated; documentation only |
| 1.2.41 | 2026-10-05 | Approved | PWA-3B.1 done: «بياناتي الشخصية» read view (GET /api/v1/family/self, /family/account/me), masked National ID and mobiles only; no migration. PWA-3B.2 (reveal) next |
| 1.2.28 | 2026-10-02 | Approved | PWA-1D done (resolver, identity service, link lifecycle actions, correction and death integration, account sides and Staff API boundary); PWA-1E next |
| 1.2.27 | 2026-10-02 | Approved | PWA-1C done (schema, models, normalizers, keyed fingerprints, role and permission seeding; foundation only); PWA-1D next; coordinator assist grant moved to PWA-1H |
| 1.2.26 | 2026-10-02 | Approved | PWA-1B: §31a program status (PWA-0, PWA-1A, PWA-1B done; PWA-1C next), PWA-1 slices C … I absorbing the former PWA-2 scope, Production activation gate and FU-01 rollout gate (RM-ADR-042). Documentation only |
| 1.2.25 | 2026-10-02 | Approved | PWA-0: §31a Family PWA Program (PWA-0 … PWA-10) mapped onto Phases 16–19, with scope, schema impact, dependencies and exit criteria (RM-ADR-041). Documentation only; no phase started |
| 1.2.24 | 2026-10-01 | Approved | Phase 23 progress note: Step 6 Apply UI (Phase 4B.4e) |
| 1.2.23 | 2026-10-01 | Approved | Phase 23 progress note: Apply API and activation gate (Phase 4B.4d) |
| 1.2.22 | 2026-09-30 | Approved | Phase 23 progress note: Apply runner (Phase 4B.4c) |
| 1.2.21 | 2026-09-30 | Approved | Phase 23 progress note: Row executor (Phase 4B.4b) |
| 1.2.20 | 2026-09-30 | Approved | Phase 23 progress note: Apply execution primitives (Phase 4B.4a) |
| 1.2.19 | 2026-09-30 | Approved | Phase 23 progress note: Apply planner + read-only Dry Run (Phase 4B.2) |
| 1.2.18 | 2026-09-29 | Approved | Phase 23 progress note: Import Apply foundation (Phase 4B.1) — lifecycle, provenance, shared Person/Family creation; Apply not yet implemented |
| 1.2.17 | 2026-09-29 | Approved | Phase 23 progress note: record reconciliation (Phase 3) |
| 1.2.16 | 2026-09-29 | Approved | Phase 23 progress note: family-key resolution (Phase 2B) |
| 1.2.15 | 2026-09-29 | Approved | Phase 23 progress note: Import Wizard (INITIAL / INCREMENTAL, mapping-based staging, review; no Apply) |
| 1.2.14 | 2026-09-29 | Approved | Phase 23 progress note: Initial Family Import Phase 2A (upload, parse, stage, family-key discovery) |
| 1.2.13 | 2026-09-29 | Approved | Phase 23 progress note: Initial Family Import Phase 1 foundation (staging tables, Declared Household Statistics, RecordPersonDeathAction) |
| 1.2.12 | 2026-09-28 | Approved | Phase 13 progress note: Assessments Pilot Workspace (cross-family Assessment List and registry API, Assessment Detail, Draft Editor and New Assessment) |
| 1.2.11 | 2026-09-27 | Approved | Phase 29 progress note: Pilot Readiness Slice D (production Pilot environment preparation; server-side steps outstanding) |
| 1.2.10 | 2026-09-27 | Approved | Phase 9 progress note: Pilot Readiness Slice C (data-entry corrections: relationship, membership ending, National ID correction and masking, optional governorate/city) |
| 1.2.9 | 2026-09-26 | Approved | Phase 20 progress note: Pilot Readiness Slice B (registry search, exact National ID duplicate prevention, optional date of birth) |
| 1.2.8 | 2026-09-26 | Approved | Phase 4 progress note: Pilot Readiness Slice A (real Staff authentication and Staff access) |
| 1.2.7 | 2026-09-26 | Approved | Phase 22 progress note: Reports V1 |
| 1.2.6 | 2026-09-25 | Approved | Phase 21 progress note: Operational Dashboard V1 |
| 1.2.5 | 2026-09-25 | Approved | Phase 8 progress note: Clan + Branch Structure V1 |
| 1.2.4 | 2026-09-24 | Approved | Phase 14 progress note: Assistance V1-B (approval and execution) |
| 1.2.3 | 2026-09-24 | Approved | Phase 14 progress note: Assistance V1-A (program, targeting, nomination); V1-B deferred |
| 1.2.2 | 2026-09-24 | Approved | Phase 14 progress note: Needs Management V1 (Assistance deferred) |
| 1.2.1 | 2026-09-24 | Approved | Phase 13 progress note: Quick Multi-Domain Family Assessment V1 |
| 1.2 | 2026-09-22 | Approved | Replaced Filament-first operational architecture with API-first Laravel + custom Next.js Staff/Executive/Family applications, restricted Filament to System Administration, introduced frontend/design-system foundations, revised implementation phases, MVP strategy, deployment topology, security gates and vertical delivery approach |