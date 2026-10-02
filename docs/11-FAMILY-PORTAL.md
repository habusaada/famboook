# Famboook
## Family Portal / Family PWA — Program Specification

**Document:** `11-FAMILY-PORTAL.md`
**Version:** 1.3
**Date:** 2026-10-02
**Status:** APPROVED — PWA-0 baseline and PWA-1B identity/access design. Implemented so far: the PWA-1C foundation and the PWA-1D identity domain behaviour (§30a); no activation, login, OTP or UI

---

# 1. Purpose

This document is the canonical specification of the Famboook Family Portal
program ("بوابة رب الأسرة"). It records the product decisions approved on
2026-10-02 (PWA-0) and the architecture to be implemented in later phases.

It does not replace the baseline documents. Canonical rules are reflected
in their own documents and cross-referenced here:

```text
01-PRODUCT.md          §16a, §95
02-DATA-DICTIONARY.md  §45a, §47
03-BUSINESS-RULES.md   §59, §89a
04-DATABASE.md         §55a
05-WORKFLOWS.md        §51, §53a
06-PERMISSIONS.md      §14a, §22a, §22b, §59c, §123
07-ROADMAP.md          §31a
10-DESIGN-SYSTEM.md    §11a
```

Where this document and a baseline document disagree, the amendment
register in §31 states which rule is superseded and how.

## Implementation status at PWA-0

```text
Implemented            FAMILY_USER role (seeded, four change-request
                       permissions, refused by Staff login); the canonical
                       registry, health, needs and assistance domains;
                       Family Activity Log; Staff session authentication.

Documented, not built  User-Person Links, Family access resolution, Change
                       Requests, workflow events, documents, notifications
                       (Roadmap Phases 16–19).

New in this document   COORDINATOR, National ID + OTP activation, trusted
                       mobile, Profile Completion, Family Verification,
                       health and need submissions, Digital Household Head
                       Card, QR verification, PDF card, announcements,
                       Coordinator Space, PWA installability.
```

Nothing in the third group is seeded, migrated or coded.

---

# 2. Product Definition

The Famboook Family Portal is the official, mobile-first, self-service PWA
for household heads.

It is part of the Famboook product: not a separate brand and not a
parallel registry.

```text
Canonical Registry
       ↓ READ
Family Portal
       ↓ SUBMIT
Request / Review Workflow
       ↓ APPROVE + APPLY
Canonical Registry
```

A Family User never mutates a canonical registry record merely because
they are authenticated. Every substantive change is a proposal that an
authorized Famboook user or process reviews, approves and applies through
a Domain Action.

One Next.js application hosts the Staff application, the Family Portal
(under `/family`) and public card verification (§25).

---

# 3. Family Portal V1 Modules

```text
 1. Home
 2. My Family
 3. Family Members
 4. Profile Completion & Verification
 5. Registry Updates / Life Events
 6. Health & Disability submissions
 7. Needs submissions
 8. My Requests
 9. Digital Household Head Card
10. QR Verification
11. Notifications
12. Account & Security
13. Coordinator Space (where applicable)
14. PWA installability
```

The Staff review workspace (request review, verification review, apply)
lives in the Staff application but is part of this program.

---

# 4. Identity and Access Architecture

## Principles

```text
One users table
One session guard (Sanctum first-party session), where safely possible
Explicit User-Person Link
Family access resolver
Separate Family authentication endpoints and flows
No users.family_id shortcut
```

## Family access resolution

```text
Authenticated User
      ↓
Verified, active User-Person Link
      ↓
Person (active, alive)
      ↓
Active household-head membership
      ↓
Family (accessible)
```

Every step is evaluated on every Family Portal request. Nothing in the
chain is cached as a permanent grant.

## Anti-IDOR rule (critical)

Family Portal endpoints never accept a Family identifier from the client
to decide which Family is the current one. The server resolves the Family
from the authenticated identity. Identifiers of child objects (a member, a
request, a notification) are accepted only after the server has confirmed
they belong to the resolved Family or to the authenticated user.

Family API authorization defaults to deny.

## Eligibility re-evaluation

Access is re-evaluated, and ends where no longer valid, on:

```text
Household head change
Death of the household head
User-Person Link suspension, revocation or end
Membership end or transfer of the linked Person
Family archive or deactivation
User deactivation
```

Because the chain is resolved per request, a change in any link takes
effect on the next request without a separate revocation step.

## PWA-1 identity-data discovery — COMPLETED 2026-10-02

**Resolved.** The discovery ran (PWA-1A) and the identity design is
approved (PWA-1B): see §30a. The paragraphs below are kept as the PWA-0
record of why the discovery was required.

PWA-0 does **not** decide that `persons.national_id` becomes unique, and
does not decide how the login identifier is derived. Today the value is
stored as entered, indexed but not unique, and its normalization is an
open decision (docs/02 PDD-001).

PWA-1 must begin with a focused, read-only identity-data discovery
covering:

```text
Normalization (digits, separators, Arabic-Indic numerals)
Null and blank values
Malformed IDs
Duplicates
Whether duplicates are data errors or legitimate historical conditions
The relationship between the stored National ID and the authentication
login identifier
A safe migration / backfill strategy, if a normalized login key is later
introduced
```

No identity schema, migration or login code is written before its findings
are reviewed. This is the blocking prerequisite of PWA-1 (PFP-003). The
other decisions tagged PWA-1 (PFP-004, PFP-016, PFP-021, PFP-022) are
taken during the phase and do not prevent it from starting.

## Staff authentication

Staff authentication (docs/06 §59c) continues to work unchanged. Family
authentication is added beside it with its own endpoints; it does not
relax Staff login rules.

---

# 5. Authentication Model

## First activation

```text
National ID
   ↓
Resolve eligibility (server-side, silent)
   ↓
Trusted registered mobile (§6)
   ↓
OTP sent to that mobile
   ↓
OTP verified
   ↓
User sets a password
   ↓
Account activated
   ↓
Family Portal
```

## Subsequent login

```text
National ID + password
```

## Forgotten password

```text
National ID
   ↓
OTP to the trusted, verified mobile
   ↓
User sets a NEW password
```

## Rules

- A generated replacement password is never sent by SMS.
- Responses are generic wherever a specific answer would reveal whether a
  National ID exists, is eligible, or has an account.
- The person's name and any Family information are never shown before
  authentication succeeds.
- The OTP destination is never chosen by the person activating (§6).
- OTP values and passwords are never logged, stored in plaintext, or
  visible to Staff or coordinators.
- A typed National ID is normalized by the strict Family Portal normalizer
  and must be exactly nine ASCII digits; it is matched through a dedicated
  authentication identity, never by making the registry field a
  credential (§30a).
- All activation, login and reset endpoints are rate limited per National
  ID, per destination mobile and per IP (exact values: PFP-002).

## Relationship to User-Person Link verification

Activation is the approved **system verification process** for the
User-Person Link. See the amendment in §31 (A-01).

```text
Eligibility      decided by the registry (current household head, alive,
                 active membership) — never by the person activating
Destination      a mobile that was trusted through an authorized process
Proof            possession of that mobile (OTP)
Result           Link verified by the system process and activated
```

A person cannot make themselves eligible, cannot choose the destination,
and cannot verify a Link by assertion.

---

# 6. Mobile Trust and Activation

A mobile number that merely exists in imported Person data is **not**
trusted for account activation.

```text
A. TRUSTED / VERIFIED MOBILE
   Eligible for OTP activation.

B. MOBILE EXISTS BUT IS NOT TRUSTED
   Cannot activate until the mobile is verified through an authorized
   process.

C. NO VALID MOBILE
   Requires an authorized contact update and verification before
   activation.
```

## Critical rule

During activation a person must not be able to replace an untrusted or
incorrect mobile with an arbitrary new number and immediately receive an
OTP on it.

A mobile change that changes the authentication destination is a separate,
authorized and verified operation. After activation, a Family User's
request to change that mobile is never self-approved and never becomes the
OTP destination before it is approved and verified.

## Shared mobiles

Several Persons, including several household heads, may share one mobile.
The architecture must therefore:

- never treat a mobile number as an identity;
- key activation on National ID, with the mobile only as the OTP
  destination;
- let the trust decision be made per Person, not per number;
- rate limit per destination so one number cannot be used to probe many
  National IDs.

Decided 2026-10-02: a shared number may be trusted for more than one
household head, but only through an individual verification per Person and
never automatically (§30a).

The authorized process that makes a mobile trusted is decided (§30a):
three verification methods, a final grant by SUPER_ADMIN or ADMINISTRATOR
only, and optional assistance by a coordinator within scope. A coordinator
never grants trust and never sees an OTP or a password.

---

# 7. Multi-Role Model and Contexts

One person may be both:

```text
FAMILY_USER + COORDINATOR
```

They use one account. Separate accounts are never created for the same
person merely because they act in two contexts.

Spatie Permission already supports several roles per user. Code that reads
only the first role (Staff login, the current-user representation, Staff
user administration) must be changed before a second role is assigned
(PWA-1).

## Contexts

```text
Family context        the user's own Family Portal
Coordinator context   مساحة التنسيق
```

A dual-role user enters the Family Portal normally and enters Coordinator
Space separately. The interface always shows clearly when the user is
operating in Coordinator Space. An action is authorized by the context it
is performed in: Family context grants nothing in Coordinator Space, and
the reverse.

---

# 8. Coordinator Role — V1

COORDINATOR is a new role. It is not a mini-administrator.

```text
Permission + Organizational Scope = Allowed Action
```

Never role alone.

## Scope

Scope assignments are designed to support:

```text
CLAN
BRANCH_GROUP
BRANCH
```

Branch-level assignment is the primary V1 case; the data model must not
prevent the other levels. An assignment is an explicit, audited record
made by an authorized administrator.

## Authentication (V1)

- A COORDINATOR does **not** use the Staff Login in Family Portal V1.
- A coordinator authenticates through the Family Portal identity flow
  (§5).
- The same account may hold FAMILY_USER + COORDINATOR (§7).
- The normal family context stays under `/family`; authorized
  coordinators additionally reach `/family/coordinator`.
- Coordinator Space is visibly distinguished from the Family context.
- Access stays permission + organizational scope.
- This does not prevent a separately authorized Staff role or account
  model later, if the product requires one.
- **V1: a COORDINATOR must also be an eligible Family Portal household
  head.** There is no coordinator activation bypass: a coordinator first
  activates as an ordinary FAMILY_USER, and the COORDINATOR role plus
  active scope assignments then enable `/family/coordinator`. A scope
  assignment is an authorization enhancement, never an activation
  identity source.
- A coordinator may hold several active scope assignments; authorization
  is their union. A client-supplied scope never grants access.

## V1 responsibilities

- View a limited dashboard for the authorized scope.
- See the families in scope with deliberately limited data.
- See profile completion and verification status.
- Follow up on incomplete profiles.
- Send notifications and announcements within scope.
- See whether a family account is activated, where authorized.
- Assist operationally with activation, without ever seeing an OTP or a
  password.

## A coordinator must not, by virtue of the role

- Approve Change Requests.
- Act as a REVIEWER.
- See OTP values.
- Set family passwords.
- See health details, disability details, pregnancy or breastfeeding.
- Create assistance decisions.
- Act outside the assigned scope.
- Grant VERIFIED.

```text
COORDINATOR ≠ REVIEWER
```

Review permissions may be granted to a coordinator in a later phase by
explicit decision; they are never implied by the role.

---

# 9. Family Profile Completion

Profile Completion is a formal concept. Completion is **not**
verification.

Completion is calculated by the system from required steps:

```text
1. Identity / account
2. Household head — basic person data
3. Family — basic data
4. Family members review
5. Contact and residence data
6. Final review and declaration
```

## Rules

- The checklist is derived from business rules; it is not a count of
  filled database columns.
- Steps may be conditional. A household without a spouse is not penalized
  for missing spouse data.
- The calculation lives in the backend, in one place. The frontend only
  presents it.
- The Portal shows completed steps, the current step, remaining steps,
  progress and a call to action to continue.

---

# 10. Declared Size vs Registered Members

The existing distinction is preserved (docs/02 §20a, docs/03 §55):

```text
declared_household_size ≠ registered detailed member count
```

- Missing people are never inferred as `declared − registered`.
- The two figures are never required to be equal for completion or for
  verification.
- During the family-members review both figures may be shown to the
  household head as separate facts.
- A household head reports a missing member through the request workflow
  (§14).

---

# 11. Family Verification

Completion and verification are separate concepts.

```text
A. Profile Completion     system-calculated (§9)
B. Verification Review    the review process and its state
C. Verification Result    the outcome and its validity
```

Conceptual lifecycle:

```text
INCOMPLETE
   ↓
COMPLETE / READY_TO_SUBMIT
   ↓
UNDER_REVIEW  ⇄  NEEDS_CLARIFICATION
   ↓
VERIFIED
   ↓ (later critical change, §12)
REVERIFICATION_REQUIRED
```

These need not be one enumeration; the physical design may keep
completion, review and result as separate state (PWA-4).

## Rules

- Only an authorized Famboook user or process grants VERIFIED.
- A Family User cannot self-verify.
- A coordinator cannot grant VERIFIED merely by being a coordinator.
- Verification is auditable. The design must support at least: who
  verified, when, a status or version of the verification, and a history
  of verification events.
- Family-visible clarification text is separate from internal notes, as
  for Change Requests.

Proposed permissions (pending approval, PFP-022): docs/06 §22a.

---

# 12. Verification and Later Changes

Verification is not necessarily permanent. Later changes are classified by
risk, in one backend decision table — never in frontend code.

```text
MINOR      keeps VERIFIED; shows that an update is pending
MATERIAL   requires review; does not necessarily invalidate the previous
           verification immediately
CRITICAL   may trigger REVERIFICATION_REQUIRED
```

## Proposed decision table (for approval — PFP-007)

| Event | Proposed class | Proposed effect |
|---|---|---|
| Alternate mobile or non-critical contact update | MINOR | Stays VERIFIED; update pending |
| Change of the trusted authentication mobile | MATERIAL | Separate authorized verification (§6); verification kept |
| Residence or displacement update | MATERIAL | Review; verification kept until the review decides |
| Family basic data or declared household data update | MATERIAL | Review; verification kept until the review decides |
| Correction of a member's non-identity data | MINOR | Stays VERIFIED |
| Correction of a member's identity data (name, National ID, birth date) | MATERIAL | Review |
| Member added, birth reported | MATERIAL | Review |
| Death of a member other than the head | MATERIAL | Review |
| Death of the household head | CRITICAL | REVERIFICATION_REQUIRED; Portal access re-evaluated |
| Household head change | CRITICAL | REVERIFICATION_REQUIRED; Portal access re-evaluated |
| Major composition or identity change (transfer, merge of records) | CRITICAL | REVERIFICATION_REQUIRED |

The table is PROPOSED. No row is a canonical rule or an implementation
detail until the product owner approves it (PFP-007).

---

# 13. Verified Does Not Mean Beneficiary

```text
VERIFIED ≠ APPROVED FOR ASSISTANCE
```

Verification means the family profile passed the Famboook verification
process. Nothing more.

```text
Profile Complete
   ↓
Verified
   ↓
Eligible for consideration / targeting
   ↓
Service-specific criteria
   ↓
Nomination
   ↓
Review / approval
   ↓
Assistance
```

The Portal never promises assistance because a profile is verified, and
its wording must not imply it. The separation between Needs and Assistance
is preserved (docs/03 §46–§47).

---

# 14. V1 Registry Requests

Family Users do not edit canonical records. They use the approved Change
Request architecture (docs/03 §67–§89, docs/05 §54–§81) and Domain
Actions.

## Planned for V1

| Request | Approved type | Apply target today |
|---|---|---|
| Contact update | CONTACT_UPDATE | `UpdatePersonAction` exists |
| Residence / displacement update | RESIDENCE_UPDATE | `UpdateFamilyResidenceAction` exists; history-preserving change to be confirmed against docs/03 §32 |
| Person correction | PERSON_CORRECTION | `UpdatePersonAction`, `CorrectNationalIdAction` exist |
| Add missing family member | ADD_FAMILY_MEMBER | `AddFamilyMemberAction` exists |
| Birth report | BIRTH_REPORT | `AddFamilyMemberAction` exists |
| Death report | DEATH_REPORT | `RecordPersonDeathAction` exists; head succession is not handled |

## Proposed new types — NOT approved

These are needed by the program but are not among the eleven approved
types. They are proposals awaiting formal approval (PFP-008); none may be
implemented before it.

| Proposed type | Purpose | Apply target |
|---|---|---|
| `FAMILY_DATA_UPDATE` | Family basic data | `UpdateFamilyAction` exists |
| `HOUSEHOLD_DECLARATION_UPDATE` | Declared household information | `RecordHouseholdDeclarationAction` exists |
| `HEALTH_RECORD_SUBMISSION` | Health and disability (§15) | `CreateHealthRecordAction`, `CloseHealthRecordAction` exist |
| `NEED_SUBMISSION` | Needs (§16) | `CreateNeedAction` exists |

Whether health and need submissions are Change Request types or a sibling
submission workflow sharing the same engine is decided with PFP-008. In
either case the lifecycle, review, apply and audit rules of §17 apply.

## Kept in the architecture, not in V1

| Flow | Gap |
|---|---|
| Marriage | `MARRIAGE_UPDATE` is approved, but no Domain Action decides membership effects |
| Divorce, widowhood | No approved request type; marital status values exist |
| Household head transfer | `HOUSEHOLD_HEAD_CHANGE` is approved; no `ChangeHouseholdHeadAction` |
| Member transfer between families | `MEMBERSHIP_CHANGE` is approved; no transfer Domain Action |
| Document update | `DOCUMENT_UPDATE` is approved; the documents domain is not built |

These become implementable only when the Domain Action and, where missing,
the approved request type exist (PFP-012).

---

# 15. Health and Disability

Canonical health record types today:

```text
DISABILITY
CHRONIC_DISEASE
PREGNANCY
BREASTFEEDING
```

- The existing health domain is the eventual apply target.
- A family submission never creates an official health record directly;
  it passes through the submission and review workflow.
- Temporary conditions respect the `started_at` / `ended_at` lifecycle of
  the existing records.
- No generic health-condition schema is introduced by PWA-0.

## Privacy

- A household head does not automatically see every adult member's
  sensitive health data.
- Until detailed visibility is approved (PFP-006), the Portal exposes the
  minimum necessary: a household head sees what they submitted and its
  request status, not the canonical health records of other adults.
- A coordinator sees no health, disability, pregnancy or breastfeeding
  details.

This amends docs/06 §123 (see §31, A-05).

---

# 16. Needs

Family Users may submit needs for review, for example infant formula,
baby diapers, adult diapers, medication, medical supplies, and the other
existing need categories.

```text
Need ≠ Health Condition
```

- A family-submitted need is not an official need until it is reviewed and
  approved.
- On approval the existing Needs domain (`CreateNeedAction`) is the apply
  target.
- Family Users never create Assistance records and never approve
  themselves as beneficiaries.

```text
Need → targeting / nomination → Assistance
```

is preserved.

Whether formula and diapers stay titles under existing categories or
become reference-data categories is a later product decision (PFP-009).
Portal visibility of official needs and of assistance history remains
governed by docs/06 §124–§125.

---

# 17. My Requests and the Staff Review Workspace

The approved lifecycle is preserved where applicable:

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

- Family-visible clarification messages are separate from internal Staff
  notes.
- Family-facing status wording is localized and understandable; machine
  statuses stay stable (docs/05 §96).
- Apply is transactional, idempotent, auditable and performed through
  Domain Actions.
- `APPROVED ≠ APPLIED`.

The Staff review workspace (queue, request detail, clarification,
approve, reject, apply) is delivered in the Staff application as part of
PWA-5.

---

# 18. Digital Household Head Card

The card belongs to the eligible household-head identity and context.

## Content (minimal, official)

```text
Famboook branding
Household-head name
Public holder ID
Family Code
Clan
Branch
Card status
Issue date
QR
```

## Never on the card or the public page

```text
National ID
Mobile number
Health data
Disability
Needs
Detailed family members
```

## Public holder ID

Opaque and non-guessable enough for its purpose. It never reuses
`FAM-xxxxxx`, `PER-xxxxxx`, a database id or the National ID. A grouped
form such as `FH-8K4P-72MX-Q9` is the intended presentation and renders
LTR.

## Lifecycle the architecture must support

```text
Issue
Active
Revoke
Reissue
Public verification credential
Audit of every transition
```

A card is valid only while the holder remains the eligible household head
of the Family; loss of eligibility (§4) invalidates the card.

---

# 19. QR Verification

```text
QR → Public Verification Page → live card status
```

- The QR contains only an opaque verification credential. No family data.
- The public page shows only deliberately approved fields (PFP-010).
- Revocation and validity are checked live on every verification.
- A QR screenshot proves access to the QR, not the physical identity of
  the person presenting it. The interface never claims otherwise.
- An authenticated, authorized Staff user may later open the internal
  family record from the same context, subject to permissions and scope.
- Public verification is rate limited and protected against enumeration;
  unknown, malformed and revoked credentials are indistinguishable from
  one another to an enumerating client where that does not harm a
  legitimate verifier (detail: PWA-8).

---

# 20. PDF Card

The Portal will provide "Download ID Card as PDF".

```text
Server-generated
Formal, light, printable
Arabic RTL
Minimal personal information
QR verification included
No health, needs or contact information
```

Privacy limitation: a downloaded PDF is outside system control. It cannot
be recalled; only its QR can be revoked. The content is therefore kept to
the minimum of §18.

The PDF library is not selected in PWA-0 (PFP-011).

---

# 21. Notifications and Announcements

The Portal contains an in-app Notification Center. In-app records are the
source of truth. Web Push and SMS are possible future delivery channels,
not the canonical record.

## Two kinds

```text
System / workflow notifications   generated by backend events
                                  (docs/03 §90–§92)
Manual announcements              written by a sender for an audience
```

## Two sources shown to the family

```text
إدارة Famboook      Famboook Administration
منسق العائلة        Authorized Family Coordinator
```

## Announcement audiences

```text
All eligible family users (where authorized)
Clan
Branch group
Branch
Selected families
One family
```

## Rules

- Audience resolution happens on the server.
- A client-supplied audience is never trusted beyond the sender's scope.
- A user reads only notifications addressed to their own identity.
- Payloads carry minimal sensitive data and link to an authorized view.
- The design covers: sender, sender context, title, body, optional action,
  audience, recipients, sent time, read/unread, audit.
- Large fan-out never runs inside a synchronous web request in production.
  Production currently runs without a queue worker; announcements require
  a queue and worker before they are enabled (PWA-9).

---

# 22. Coordinator Notifications

- A coordinator sends only within the assigned organizational scope.
- Before sending, the interface shows the resolved recipient count.
- Recipients can tell "إدارة Famboook" from "منسق العائلة".
- A coordinator's message never appears to be an administrative Famboook
  decision.

---

# 23. Family Home Information Architecture

Approved order:

```text
1. Header / identity / unread notifications
2. Family summary
3. Profile Completion / Verification status
4. Quick services
5. Digital Household Head Card / QR
6. Requests requiring attention
7. Recent requests
8. Recent notifications
9. Bottom navigation
```

Primary bottom navigation:

```text
الرئيسية   أسرتي   +   طلباتي   حسابي
```

The central `+` opens a new-action sheet.

Primary quick actions on Home:

```text
Birth
Health / Disability
Need
Update Data
```

Less frequent actions live in the new-action flow. Home does not list
every service.

---

# 24. Visual Direction

The Family Portal uses the Famboook brand. It is not a sub-brand.

```text
FORMAL · TRUSTED · CLEAN · DIGITAL · MODERN · MOBILE-FIRST
```

Reference: formal digital-service, banking and government-service clarity,
with the modern Famboook identity.

It must not look like a playful consumer app, a children's app, a colorful
NGO brochure or a miniature desktop admin dashboard. No excessive
gradients, glassmorphism, illustrations, multicolored cards, heavy shadows
or emojis. Lucide icons throughout.

## Brand colors

```text
kingfisher   50 #f9f5ff   100 #f2e7ff   200 #e7d3ff   300 #d4b1ff
             400 #b97fff  500 #9f4ffd   600 #892cf1   700 #751bd5
             800 #651cad  900 #53188b   950 #42047e

spring       50 #eefff8   100 #d7fff0   200 #b1ffe2   300 #74ffcc
             400 #31f7ae  500 #07f49e   600 #00bb76   700 #03925e
             800 #09724d  900 #095e42   950 #003523
```

```text
Brand Purple     #892CF1
UI Primary       #751BD5
Primary Hover    #651CAD
Deep Purple      #42047E / #53188B where appropriate
Spring           #07F49E
Spring Strong    #00BB76
```

Spring is semantic: verified, success, active, completed. It is not a
competing primary interface color.

Kingfisher Purple is the approved Family Portal visual identity (UI
primary `#751BD5`, brand purple `#892CF1`). The Staff application keeps
its current teal scale (docs/10 §3) for the whole Family PWA program
unless a migration is separately authorized. No Staff palette migration is
started, and the teal Staff palette does not block PWA-1 (FP-ADR-022).

## Typography

IBM Plex Sans Arabic, in the existing Tailwind `sans` family. Arabic UI is
RTL. Codes and identifiers (`FAM-001234`, `FH-8K4P-72MX-Q9`) render LTR.

| Role | Direction |
|---|---|
| Page title | ~24 / 700 |
| Section title | ~18 / 600 |
| Card title | ~16 / 600 |
| Body | ~15–16 / 400 |
| Label | ~14 / 500 |
| Caption | ~12–13 / 400 |

These are design direction, to become tokens in PWA-3.

## Foundation

```text
Background       #F7F7FA
Surface          #FFFFFF
Text Primary     #18181B
Text Secondary   #52525B
Text Muted       #71717A
Border           #E4E4E7
Border Soft      #F0F0F2
```

Balance: about 80% neutral/white, 15% Famboook purple, 5% semantic color.

```text
Page horizontal padding   16px
Section gap               24px
Card padding              16px
Card radius               14–16px
Button / input radius     10–12px
Status badge radius       ~8px
```

Hierarchy comes from borders and spacing, with little or no shadow. One
clear primary action per screen. Long forms use deliberate steps.

## Status visual language

Icon + text + color, never color alone.

| Meaning | Color |
|---|---|
| Verified / Approved / Completed | green |
| Pending / Under Review | amber |
| Needs Attention / Returned | orange |
| Rejected / Critical Failure | red |
| Draft / Neutral | gray |
| Informational | Kingfisher |

This is presentation only. It does not redefine any domain status.

---

# 25. PWA Technical Direction

The Family PWA is **not** a separate repository-level application (no
`/pwa`, no second frontend). It lives inside the existing Next.js frontend
application (`frontend/`).

```text
existing Next.js frontend
├── staff route group / context
├── family route group / context
│   └── /family/...
└── public verification
    └── /verify/{opaque-token}
```

The physical App Router paths follow the repository's current convention:
routes live directly under `frontend/app/` (no `src/`), with no route
groups today. Route groups do not change URLs, so existing Staff URLs are
preserved. No directory is created in PWA-0; the exact layout is fixed in
PWA-3.

URL scope stays `/family`, and the future manifest is scoped to `/family`.

Route groups (conceptual; no routes exist):

```text
(staff)    existing Staff routes, URLs preserved

(family)   /family/login
           /family/activate
           /family/home
           /family/members
           /family/verification
           /family/requests
           /family/health
           /family/needs
           /family/card
           /family/notifications
           /family/account
           /family/coordinator/...   where applicable

public     /verify/{opaque-token}
```

- The Staff authentication gate moves out of the root layout into the
  Staff group, so Family and public routes are not wrapped in the Staff
  shell.
- The PWA manifest is scoped to `/family`.
- V1 provides no offline storage or offline editing of sensitive family
  data.
- The service worker never caches authenticated API responses.
- Authenticated Family API responses are served `no-store`.

---

# 26. Security Baseline

Family API authorization defaults to deny.

| Threat | Control (to be implemented in the named phase) |
|---|---|
| National ID enumeration | Generic responses; identical timing paths where practical; per-IP and per-identifier limits (PWA-2) |
| OTP brute force | Short TTL, attempt cap, challenge invalidated on cap, stored hashed (PWA-2) |
| OTP resend abuse | Resend cooldown and daily cap per destination and per National ID; SMS cost monitoring (PWA-2) |
| Account takeover | OTP only to a trusted mobile; password set only after OTP; sessions ended on reset (PWA-2) |
| Imported / untrusted mobile | Trust is explicit and per Person; untrusted numbers cannot activate (PWA-1, PWA-2) |
| Shared mobile | Mobile is a destination, never an identity; per-destination limits (PWA-2) |
| Unauthorized phone change | Never self-approved; never an OTP destination before approval and verification (PWA-2, PWA-6) |
| Cross-family IDOR | Server-resolved Family; no client Family id; child objects checked against the resolved Family (PWA-1, PWA-3) |
| Coordinator scope leakage | Permission + scope on every coordinator endpoint; server-side audience resolution (PWA-1, PWA-9) |
| Dual-role / context confusion | Explicit context per request; visible Coordinator Space; no single-role code assumptions (PWA-1) |
| Health / disability privacy | Minimum-necessary visibility; coordinator sees none (PWA-7) |
| QR enumeration | Opaque credential; rate limit; non-distinguishing failures (PWA-8) |
| QR screenshot / sharing | Interface states what a QR proves; live status (PWA-8) |
| Card revocation | Live check on every verification (PWA-8) |
| Notification audience leakage | Server-side audience; recipient-only reads (PWA-9) |
| Private evidence / document access | Private storage; delivery through Laravel after object authorization (PWA-6) |
| PWA / API caching | `no-store`; service worker excludes API responses; no offline sensitive data (PWA-3, PWA-10) |
| PDF privacy | Minimal content; limitation documented to the user (PWA-8) |
| Audit of authentication / OTP / card events | Security audit trail separate from the family-scoped Activity Log (§27) |

---

# 27. Auditability

Audit coverage is planned for:

```text
Account activation
OTP security events (issued, failed, locked) where appropriate
Password reset
User-Person Link verification and revocation
Verification submission
Verification approval and rejection
Change Request transitions
Change Request apply
Health and need request apply
Card issue, revoke, reissue
Coordinator scope changes
Announcement send
Notification targeting
Sensitive administrative actions
```

Never logged:

```text
OTP plaintext
Passwords
Sensitive secrets
```

The Family Activity Log (docs/03 §97a) remains the family-scoped business
timeline. Events that happen before a Family is resolved (failed
activation, OTP lockout, public verification) need an authentication and
security audit trail of their own; workflow transitions use the documented
`workflow_events` (docs/04 §33).

---

# 28. Logical Domain Design vs Future Physical Schema

PWA-0 creates no migration. This section separates the concepts from
their eventual tables. Physical names are given only where the baseline
already defines them.

| Logical concept | Future physical schema | Status |
|---|---|---|
| User-Person Link | `user_person_links` — defined in docs/04 §53 | Documented, not built |
| Family login identifier | `family_auth_identities` (keyed fingerprint); `users.email` nullable, unique kept — §30a, docs/04 §55b | Approved design, not built |
| Account activation state | Activation and password-set state on the account or Link | New; names not fixed |
| Trusted / verified contact | Per-Person mobile trust state, who verified, when, how | New; names not fixed |
| OTP challenge | Short-lived, hashed, attempt-counted challenge records | New; names not fixed |
| Profile Completion | Calculated; stored only if a snapshot is needed | New; possibly no table |
| Family Verification | Verification state, version, verifier, timestamps, history | New; names not fixed |
| Coordinator scope assignment | User ↔ Clan / Branch Group / Branch assignment, audited | New; names not fixed |
| Change Request and types | `change_requests`, `change_request_types` — docs/04 §36–§37 | Documented, not built |
| Workflow history | `workflow_events` — docs/04 §33 | Documented, not built |
| Evidence documents | `documents` — docs/04 §44 | Documented, not built |
| Card credential and issuance | Public holder ID, verification credential, status, issue/revoke history | New; names not fixed |
| Announcement | Sender, sender context, audience definition, content | New; names not fixed |
| Notification recipient / read state | Per-recipient record with read state | New; Laravel database notifications remain valid for system events |
| Authentication / security audit | Append-only security events | New; names not fixed |

Details: docs/04 §55a, docs/02 §45a.

---

# 29. Permissions Plan

The role is specified in docs/06 §22a. The ten permission names needed by
PWA-1 are final (docs/06 §22b; documented, not seeded). The names listed
in docs/06 §22a for later phases stay PROPOSED and pending review
(PFP-022). Summary:

```text
FAMILY_USER    preserved as a distinct role and context
COORDINATOR    new role; documented, NOT seeded
FAMILY_USER + COORDINATOR is a valid combination
COORDINATOR ≠ REVIEWER
```

No existing broad Staff permission is granted to COORDINATOR for
convenience.

---

# 30. Program Phases

```text
PWA-0   Architecture, business rules, workflows, permissions, product and
        design baseline (this document)
PWA-1   Family identity and access — delivered as slices PWA-1A … PWA-1I
        (§30a); PWA-1A and PWA-1B are DONE
PWA-2   Family authentication — its scope (activation, OTP, login, reset)
        is delivered inside PWA-1E … PWA-1G; no separate phase remains
PWA-3   Family shell and read-only portal
PWA-4   Profile Completion and Family Verification
PWA-5   Change Request engine and Staff review workspace
PWA-6   Registry requests / life events
PWA-7   Health and disability submissions, Needs submissions
PWA-8   Digital card, QR, public verification, PDF
PWA-9   Notifications, announcements, Coordinator Space
PWA-10  PWA installability; security, performance and accessibility
        hardening
```

Scope, dependencies and exit criteria: docs/07 §31a.

---

# 30a. PWA-1 Identity and Access Architecture (PWA-1B — approved 2026-10-02)

Approved design. **Implemented: the PWA-1C foundation and the PWA-1D
identity domain behaviour** (see the two implementation records below).
Activation, login, password reset, OTP, the mobile trust workflow and
coordinator scope are still to be built. Physical
schema: docs/04 §55b. Entities: docs/02 §45b. Rules: docs/03 §89b.
Workflows: docs/05 §53b. Permissions: docs/06 §22b. Slices: docs/07 §31a.

## Verified Production findings (PWA-1A, aggregate only)

A controlled read-only aggregate analysis of Production returned:

| Area | Finding |
|---|---|
| Population | 3,306 persons; 1,787 families, all active; 3,305 active memberships; 1,787 current household heads |
| Eligible heads | 1,749 living current heads; 38 current heads are deceased; 0 FAMILY_USER accounts |
| National ID | All 1,749 eligible heads have one; all are exactly nine ASCII digits; no duplicates among eligible heads or among all non-deleted persons; no normalization candidate creates a collision |
| Mobile | 1,567 eligible heads have a mobile (all ten ASCII digits beginning `05`); 182 have none; 1,501 distinct numbers; 65 numbers are shared by 131 heads (at most three per number); 1,436 heads have a unique number |
| Integrity | No active family without a head; no family with two heads; no person with two active memberships |

No individual identifier was copied into the documentation.

## National ID decision (PFP-003 — resolved)

- National ID is the Family Portal **login input**. It is not the
  credential and it is not the authentication index.
- Registry Identity and Authentication Identity stay separate.
  `persons.national_id` remains a registry field and receives **no**
  UNIQUE constraint.
- The clean state of the data is a property of the import, not of the
  schema. The design therefore fails closed on anything non-canonical.

## Strict Family Portal normalizer

A dedicated normalizer; `NationalId::normalize()` is left unchanged for its
comparison uses.

```text
1. Reject input that is not a string or is longer than 32 characters.
2. Convert Arabic-Indic (U+0660–0669) and Persian (U+06F0–06F9) digits to
   ASCII digits.
3. Remove only: whitespace (including the non-breaking space), the
   bidirectional marks U+200E, U+200F and U+061C, and the separators
   - . / _
4. The result must be exactly nine ASCII digits.
5. Anything else is INVALID.
```

Letters and other symbols are never stripped, so arbitrary input cannot
become a valid identifier. There is no check-digit rule. INVALID input
receives the same generic external response as an unknown identifier.

## Authentication identity — `family_auth_identities`

- A dedicated table; no login key column on `users`.
- `login_key` = HMAC-SHA256 over the nine normalized digits with a
  dedicated Family Portal secret, separate from `APP_KEY`, and a context
  string separate from the import `NationalIdFingerprint`.
- Statuses: `ACTIVE`, `SUSPENDED`, `SUPERSEDED`. At most one ACTIVE row
  per key and one ACTIVE/SUSPENDED row per user. A key version supports
  secret rotation; the source value stays recoverable through the Link.
- **National ID correction.** `CorrectNationalIdAction`, in its own
  transaction, supersedes the old identity and creates the new one. The
  old National ID stops authenticating at commit. A corrected value that
  is not nine digits suspends the identity instead.
- **Consistency check at login.** The stored key must equal the
  fingerprint of the linked Person's current National ID; a mismatch is
  denied and audited, so an obsolete identifier can never keep working.

## Lookup paths

```text
PRE-ACTIVATION
National ID input → strict normalizer → Person (exact match on the stored
value; zero or several matches deny) → eligibility → TRUSTED mobile → OTP

POST-ACTIVATION
National ID input → strict normalizer → login key → family_auth_identities
→ User → User-Person Link → consistency check → identity validity
→ password → session
```

## User-Person Link

- `user_person_links`, `link_type = SELF` only in V1.
- At most one ACTIVE/SUSPENDED Link per User and per Person (partial
  unique indexes), which preserves future representative models.
- VERIFIED means the identity relation is proven; ACTIVE means proven and
  enabled. V1 activation creates the Link directly as ACTIVE with
  `verification_method = SYSTEM_OTP_ACTIVATION`.
- A head change does not end the Link (the Link says who the user is; the
  resolver denies the family context). A recorded death ends it.

## Eligibility

```text
User active
User-Person Link ACTIVE
Person not deleted
Person active
life_status = ALIVE            (UNKNOWN is NOT eligible)
Active membership
is_household_head = true
Family ACTIVE
Family not deleted
```

One backend resolver evaluates this at activation, login, reset and on
every Family API request. It returns the resolved User, Person, membership
and Family, or one internal denial reason. Externally: the generic failure
at login and activation, 401 with the session destroyed when identity is
lost, 403 with a neutral message when only the family context is lost. The
client never supplies or selects a Family.

## Mobile trust

- Trust binds **Person + exact normalized mobile value**. Presence is not
  trust. Mobile values are not unique and shared numbers are valid
  registry data.
- Mobile normalizer: the same character handling as the National ID
  normalizer; the result must be ten ASCII digits beginning `05`.

```text
NO_MOBILE     derived: no valid mobile on the Person
UNVERIFIED    derived: a valid mobile with no matching TRUSTED record
TRUSTED       stored: verified for this Person and this exact number
STALE         stored: the Person's number no longer matches
REVOKED       stored: withdrawn by authorized Staff
```

- All imported mobiles begin UNVERIFIED. There is no bulk trust;
  verification is staged.
- A shared number receives no automatic trust: each Person needs an
  individual verification before that number is TRUSTED for them.
- Verification methods: `IN_PERSON`, `STAFF_CALLBACK`,
  `AUTHORIZED_RECORD_REVIEW`.
- Only SUPER_ADMIN and ADMINISTRATOR grant TRUSTED initially, through a
  dedicated permission. A COORDINATOR may assist within scope and can
  never grant. `assisted_by` / `assisted_at` are kept separately from
  `verified_by` / `verified_at`.
- If the Person's mobile changes, the previous trust no longer authorizes
  an OTP.
- A head without a mobile has no self-activation path: an authorized
  contact update establishes the number, which starts UNVERIFIED.

## Activation

```text
National ID → generic response → (internally) Person, eligibility, no
existing Link, TRUSTED mobile → OTP challenge → OTP verified → password
set → in ONE transaction: User created (email null), FAMILY_USER assigned,
family_auth_identities row, ACTIVE Link, challenge consumed, audit event
→ session
```

Already activated, no mobile, unverified mobile, ineligible, deceased,
duplicate or non-canonical stored identifier: the external response is
identical and no SMS is sent. Concurrent completions are serialized by a
row lock and the partial unique indexes.

## OTP policy

```text
6 numeric digits
TTL 5 minutes
Maximum 5 verification attempts
Resend cooldown 60 seconds
Maximum 3 sends per challenge
Single use; a superseded code is immediately invalid
Stored only as a keyed hash
```

Per-person, per-destination, per-IP and global SMS ceilings are
configurable security settings, not product constants.

## Password login and reset

- Login: National ID + password. Reset: National ID → OTP to the TRUSTED
  current mobile → new password. A generated password is never sent.
- Family password policy: minimum 8 characters, confirmation required, no
  mandatory character composition, passphrases allowed, stored only
  through Laravel hashing.
- A reset ends every session of the user, does not change mobile trust,
  requires a TRUSTED current mobile and an ACTIVE Link.

## Accounts and roles

- `users.email` becomes nullable and keeps its unique index. Staff account
  creation still requires an email. No synthetic email is ever created.
- **Staff-side and family-side accounts are disjoint.** A Staff account
  holds one Staff role and uses the Staff Login (email + password). A
  family-side account holds FAMILY_USER, optionally COORDINATOR, and uses
  the Family Portal login. One account never combines the two sides; a
  staff member who is also a household head uses two accounts.
- FAMILY_USER and COORDINATOR coexist on the **same** family-side account.
- Role checks never depend on role order.

## Authorization layers

```text
Authentication          who the user is
Family context          which Family the user acts for (server-resolved)
Coordinator scope       permission + union of active scope assignments
Staff authorization     existing permissions, unchanged
```

`OrganizationalScope` remains a reporting filter and is never coordinator
authorization.

## Security audit and retention

- `auth_security_events` is append-only and covers activation, OTP, login,
  reset, mobile trust, Link, identifier rotation and eligibility denials
  at activation, login and reset. It never stores a raw National ID, an
  OTP or a password, and avoids raw mobiles.
- Retention: `auth_security_events` 24 months; consumed, expired,
  superseded or locked `auth_otp_challenges` may be purged after 90 days.
  User-Person Link history and mobile trust history are never purged by
  OTP cleanup.

## Production activation gates

Family Portal activation MUST NOT be enabled in Production until all hold:

```text
A Production SMS provider is configured
A queue worker is operational
Delivery-failure handling exists
Provider credentials are stored securely
```

SMS is an abstraction (`SmsSender`) with a log-only development driver; no
provider is chosen (docs/08 §16a).

**FU-01 — Head Succession** is a rollout gate: the 38 families whose
current head is deceased have no eligible user. It is not part of PWA-1
and must be resolved before general Family Portal rollout.

## Implementation slices

```text
PWA-1A  Identity data discovery                          DONE
PWA-1B  Identity and access design                       DONE
PWA-1C  Schema / foundation                              DONE
PWA-1D  Identity resolver + links                        DONE
PWA-1E  Mobile trust + OTP / SMS abstraction             NEXT
PWA-1F  Activation
PWA-1G  Login / reset / session / family context
PWA-1H  Coordinator identity / scope
PWA-1I  Security hardening / full regression
```

## PWA-1C implementation record

Implemented (foundation only):

```text
config/family_auth.php                 fingerprint key and version, approved OTP
                                       and password values, activation gate (off)
App\Support\FamilyAuth\FamilyNationalId  strict nine-digit normalizer
App\Support\FamilyAuth\FamilyMobile      strict 05######## normalizer
App\Support\FamilyAuth\KeyedFingerprint  HMAC-SHA256, contexts LOGIN_ID / MOBILE /
                                       OTP_CODE, key versions, fails closed
Seven migrations                       docs/04 §55b
Six models, their enums and factories  docs/02 §45b
COORDINATOR role + ten permissions     docs/06 §22b
```

Environment names (empty placeholders in the templates; no real secret is
committed): `FAMILY_AUTH_FINGERPRINT_KEY`,
`FAMILY_AUTH_FINGERPRINT_KEY_VERSION`,
`FAMILY_AUTH_FINGERPRINT_PREVIOUS_KEY`,
`FAMILY_AUTH_FINGERPRINT_PREVIOUS_KEY_VERSION`, `FAMILY_ACTIVATION_ENABLED`.
Without a valid key the fingerprint service throws; there is no fallback to
`APP_KEY` and the Staff application is unaffected.

Refinements approved before implementation (docs/04 DB-ADR-043):

1. Coordinator duplicates are prevented by three scope-specific partial
   unique indexes, not by `NULLS NOT DISTINCT`.
2. `auth_security_events.otp_challenge_uuid` is a plain reference with no
   foreign key (challenges are purged after 90 days, events kept 24 months).
3. "One open OTP challenge" cannot consider expiry; PWA-1E supersedes the
   previous challenge before inserting another.
4. CHECK constraints are PostgreSQL-only, by repository convention.

Staged permission activation (docs/06 AUTH-ADR-064): `person-mobile-trust.assist`
is held by SUPER_ADMIN and ADMINISTRATOR and is withheld from COORDINATOR
until scope authorization is enforced in PWA-1H.

Not implemented by PWA-1C: activation, login, reset, OTP generation or
verification, SMS, mobile trust workflows, the eligibility resolver, the
death and National ID correction hooks, coordinator scope authorization,
Coordinator Space and every UI. No account, Link, identity or trust row
exists, and none was derived from registry data.

## PWA-1D implementation record

Implemented (domain behaviour; no endpoint and no UI):

```text
App\Support\FamilyAuth\FamilyAccessResolver   identity(User), familyContext(User),
                                            headEligibility(Person)
App\Support\FamilyAuth\FamilyAccessResult     read-only result; a denial or the
                                            resolved records
App\Enums\FamilyAccessDenial                  internal reason codes
App\Support\FamilyAuth\FamilyAuthIdentities   create, isConsistent,
                                            syncAfterNationalIdCorrection,
                                            findByNationalIdInput
App\Support\FamilyAuth\AuthSecurityLog        the only writer of auth_security_events
App\Support\FamilyAuth\FamilySessions         session revocation
App\Support\AccountSide                       account-side classification
App\Http\Middleware\EnsureStaffSideAccount    the `staff.side` Staff API boundary
Actions   EstablishFamilyIdentityAction, SuspendUserPersonLinkAction,
          ResumeUserPersonLinkAction, EndUserPersonLinkAction
Migration 2026_10_14_090000 (LINK_ENDED supersede reason)
```

**Resolver.** Identity validity and Family context are separate. The
resolver takes a User (or, for `headEligibility`, a Person) and nothing
else: the Family comes only from the linked Person's membership, so no
client-supplied Family can establish a context. It reads afresh on every
call — no lock, no cache, no audit event — and its denial reasons are
internal. A missing fingerprint key denies (fail closed).

**Link lifecycle.** Suspension is resumable and leaves the authentication
identity and the account untouched. An end is terminal: the identity becomes
SUPERSEDED with the reason `LINK_ENDED`, every session is revoked, and the
User account is **not** deactivated — account state, link state and
authentication-identity state are separate concepts. The Person can later
be activated again as a new link.

**National ID correction.** Synchronized inside `CorrectNationalIdAction`:
a different valid ID rotates the identity (sessions kept); the same key
changes nothing; a value that is not nine digits suspends the identity and
revokes sessions; a key held by another ACTIVE identity rolls the whole
correction back. A registry change that bypasses the action is caught by
the resolver as a mismatch.

**Death.** `RecordPersonDeathAction` ends a current link (PERSON_DECEASED).
The membership and the household-head flag are not changed (FU-01).

**Account sides and Staff boundary.** See docs/06 §22b (AUTH-ADR-065).

**Events recorded.** `LINK_ACTIVATED`, `LINK_SUSPENDED`, `LINK_RESUMED`,
`LINK_ENDED`, `LOGIN_IDENTIFIER_ROTATED`, `SESSIONS_REVOKED` — typed inputs
only, never a raw identifier.

**Sessions.** With the `database` session driver the session rows are on
the application's connection, so a revocation inside a Domain Action's
transaction commits or rolls back with the identity change. With another
driver no row is deleted here; the resolver still denies on the next
request.

Not implemented by PWA-1D: activation, OTP, SMS, Family login, password
reset, the mobile trust workflow, any Family Portal route or middleware,
coordinator scope authorization, Coordinator Space, the key-rotation
command, and any Staff endpoint or UI for link administration.

---

# 31. Amendment Register

Rules of the baseline that this program supersedes or amends. Each is
also annotated at its original location.

| # | Existing rule | Approved decision (2026-10-02) | Effect |
|---|---|---|---|
| A-01 | docs/03 §59, docs/05 §51, docs/06 §100–§101: a User must not verify their own Link; verification needs an authorized Staff/System process; activation mechanism pending | Activation by National ID + OTP to a **trusted** mobile is the approved system verification process | **Amended.** Self-verification stays prohibited: eligibility comes from the registry, the destination from an authorized trust process, the proof from OTP. PAUTH-001/002 and PWF-008 are decided in direction; details in PFP-002/004 |
| A-02 | docs/01 §12: access is never granted merely for knowing a National ID or phone number | Unchanged in substance | **Clarified.** Knowledge of a National ID grants nothing; possession of a trusted mobile plus registry eligibility is required |
| A-03 | docs/01 §95: PWA capabilities may be considered later | The Family Portal is a PWA in this program | **Superseded.** The rule that offline storage of sensitive data needs separate security design is kept and tightened: none in V1 |
| A-04 | docs/06 §59c, AUTH-ADR-057: a Staff user holds exactly one Staff role; FAMILY_USER is never a Staff Portal user | One user may hold FAMILY_USER + COORDINATOR | **Amended for COORDINATOR only.** The six Staff roles keep "exactly one". Staff Portal login rules for those roles are unchanged. Single-role code assumptions are removed in PWA-1 |
| A-05 | docs/06 §123: health data is hidden by default in the Family Portal | Family Users may **submit** health and disability information for review | **Amended.** Reading canonical health data stays minimum-necessary until PFP-006; submission is permitted through review |
| A-06 | docs/03 §68, docs/02 §47: eleven approved Change Request types | Health, need, family-data and declaration submissions are required | **Not amended yet.** New types are proposals (§14, PFP-008) |
| A-07 | docs/03 §90, docs/05 §85: notifications originate from backend workflow/domain events | Manual announcements from Administration and coordinators are added | **Extended.** System notifications keep the rule; announcements are a distinct kind with their own audit |
| A-08 | docs/10 §3: teal brand scale, primary `#176B63` | Family Portal uses the kingfisher / spring palette | **Scoped amendment.** Applies to the Family Portal only. The Staff UI is unchanged during the program unless separately authorized (FP-ADR-022) |
| A-09 | docs/07 Phases 16–19 order; docs/05 §125 | Program sequence PWA-1 … PWA-10 | **Refined.** Phases 16–19 are delivered through the PWA phases (docs/07 §31a) |
| A-10 | docs/01 PPD-015, docs/00 CTX-PENDING-022, docs/01 PPD-019 | One Next.js application hosts Staff, Family and public verification; PWA is in the program | **Decided** |
| A-11 | docs/03 PBD-028: exact login identifier policy | Family Users type their National ID to log in; Staff stays email | **Decided (PWA-1B).** Strict nine-digit normalization and a dedicated `family_auth_identities` key; no UNIQUE on `persons.national_id` (§30a) |
| A-12 | docs/06 §59c as amended by A-04 | Staff-side and family-side accounts are disjoint; FAMILY_USER + COORDINATOR share one family-side account | **Refined.** A Staff role is never combined with FAMILY_USER or COORDINATOR on one account |
| A-13 | docs/04 §51: `users.email` required | `users.email` nullable, unique index kept; Staff creation still requires it | **Amended** (approved design, not yet migrated) |
| A-14 | Program phases PWA-1 and PWA-2 (§30) | Activation, OTP, login and reset are delivered inside PWA-1E … PWA-1G | **Refined.** PWA-2 is absorbed into the PWA-1 slices |

---

# 32. Approved Decisions

```text
FP-ADR-001
The Family Portal is part of Famboook: one product, one brand, one
canonical registry.

FP-ADR-002
The Family Portal reads the canonical registry and changes it only through
reviewed, approved and applied requests.

FP-ADR-003
One Next.js application hosts the Staff application, the Family Portal
under /family and public card verification.

FP-ADR-004
One users table and one session guard where safely possible; Family
authentication has its own endpoints and flows.

FP-ADR-005
Family access is resolved per request from User → verified active
User-Person Link → Person → active household-head membership → Family.
There is no users.family_id.

FP-ADR-006
Family endpoints never take the current Family from the client.

FP-ADR-007
First activation is National ID → eligibility → trusted mobile → OTP →
password. Login is National ID + password. Reset is National ID → OTP →
new password. No generated password is sent by SMS.

FP-ADR-008
An imported mobile is not trusted. Only a trusted mobile receives an
activation OTP. The activating person never chooses the destination.

FP-ADR-009
FAMILY_USER + COORDINATOR is a valid combination on one account, with
visibly separate contexts.

FP-ADR-010
COORDINATOR is a new, conservative, scope-bound role. Permission +
organizational scope = allowed action. COORDINATOR ≠ REVIEWER.

FP-ADR-011
Profile Completion is system-calculated from business-rule steps, not from
a column count.

FP-ADR-012
Declared household size and registered member count are separate facts and
are never reconciled arithmetically.

FP-ADR-013
Family Verification is separate from completion, granted only by an
authorized Famboook user or process, and auditable.

FP-ADR-014
Later changes affect verification according to a backend risk decision
table (MINOR / MATERIAL / CRITICAL).

FP-ADR-015
VERIFIED does not mean approved for assistance.

FP-ADR-016
Health, disability and need submissions are reviewed before they reach the
canonical health and needs domains.

FP-ADR-017
The Digital Household Head Card carries minimal data, an opaque public
holder ID and a QR resolving to live status.

FP-ADR-018
In-app notification records are the source of truth; announcements are
distinguished from system notifications; coordinator audiences are always
within scope and resolved server-side.

FP-ADR-019
V1 stores no sensitive family data offline and never caches authenticated
API responses.

FP-ADR-020
Family API authorization defaults to deny.

FP-ADR-021
In Family Portal V1 a COORDINATOR does not use the Staff Login. A
coordinator authenticates through the Family Portal identity flow and
reaches Coordinator Space at /family/coordinator, visibly distinct from
the Family context. A separately authorized Staff role or account model
remains possible later.

FP-ADR-022
Kingfisher Purple is the Family Portal visual identity (UI primary
#751BD5, brand purple #892CF1). The Staff UI is unchanged during the
Family PWA program unless separately authorized.

FP-ADR-023
The Family PWA lives inside the existing Next.js frontend application, not
in a separate repository-level application. URL scope is /family; public
verification is /verify/{opaque-token}.

FP-ADR-024
persons.national_id is not made unique by this program's baseline. PWA-1
begins with an identity-data discovery before any login-identifier design.

FP-ADR-025
National ID is the Family Portal login input, normalized by a strict
normalizer to exactly nine ASCII digits. Registry Identity and
Authentication Identity are separate; persons.national_id receives no
UNIQUE constraint.

FP-ADR-026
Authentication lookup uses a dedicated family_auth_identities table with a
keyed fingerprint under a dedicated secret, separate from APP_KEY and from
the import fingerprint context. A National ID correction supersedes the
identity transactionally; the old identifier stops authenticating at
commit.

FP-ADR-027
Family Portal eligibility requires life_status = ALIVE. UNKNOWN is not
eligible.

FP-ADR-028
Mobile trust is person-specific and bound to the exact normalized number.
Imported mobiles begin UNVERIFIED with no bulk trust. Shared numbers get
no automatic trust. Methods: IN_PERSON, STAFF_CALLBACK,
AUTHORIZED_RECORD_REVIEW. Only SUPER_ADMIN and ADMINISTRATOR grant TRUSTED
initially; a COORDINATOR may assist and never grants.

FP-ADR-029
OTP: 6 digits, 5-minute TTL, 5 attempts, 60-second resend cooldown, 3
sends per challenge, single use, superseded codes invalid. Rate ceilings
are configurable security settings.

FP-ADR-030
Family password policy: minimum 8 characters, confirmation required, no
mandatory composition, passphrases allowed. Generated passwords are never
sent by SMS.

FP-ADR-031
users.email becomes nullable with its unique index kept. Staff-side and
family-side accounts are disjoint. FAMILY_USER and COORDINATOR coexist on
one family-side account.

FP-ADR-032
In V1 a COORDINATOR must also be an eligible Family Portal household head.
A scope assignment never enables activation. A coordinator may hold
several active assignments (CLAN, BRANCH_GROUP, BRANCH); authorization is
their union.

FP-ADR-033
Authentication, family context, coordinator scope and Staff authorization
are separate layers. OrganizationalScope is a reporting filter only.

FP-ADR-034
auth_security_events are append-only and retained 24 months; finished OTP
challenges may be purged after 90 days. Link and mobile-trust history are
never purged by OTP cleanup.

FP-ADR-035
Family Portal activation is not enabled in Production before an SMS
provider, an operational queue worker, delivery-failure handling and
secure credential storage exist. Head Succession (FU-01) is a gate for
general rollout and is outside PWA-1.

FP-ADR-036
Account state, link state and authentication-identity state are separate.
An ended User-Person Link is terminal, supersedes its identity with
LINK_ENDED and revokes sessions; it never deactivates the account.
Suspension is resumable and changes neither the identity nor the account.

FP-ADR-037
The Staff API refuses every account holding a family-side role through one
boundary middleware on its route group, independently of permissions and of
role order. Link administration exists as Domain Actions only until there
is an operational need for an endpoint or UI.
```

---

# 33. Open Decisions

These do not block the architecture. Each must be decided before the phase
named.

```text
PFP-001  (PWA-2)
SMS provider.
Architecture decided 2026-10-02: an SmsSender abstraction with a log-only
development driver. The Production provider itself stays OPEN and is a
Production activation gate (§30a).

PFP-002  (PWA-2)
Exact OTP TTL, attempt limit, resend cooldown and daily caps.
DECIDED 2026-10-02 (FP-ADR-029). Rate ceilings are configurable settings.

PFP-003  — RESOLVED 2026-10-02 (FP-ADR-025, FP-ADR-026; §30a)
National ID as login identifier. Not decided, and `persons.national_id`
is NOT simply made unique. PWA-1 begins with the identity-data discovery
of §4 (normalization, null/blank, malformed, duplicates and their cause,
relation between the stored value and the login identifier, safe
migration/backfill). Extends docs/02 PDD-001.

PFP-004  (PWA-1 / PWA-2)
Exact trusted-mobile verification process: who may mark a mobile trusted,
on what evidence, and how a coordinator may assist.
DECIDED 2026-10-02 (FP-ADR-028).

PFP-005  (PWA-2)
Shared mobile numbers: whether one number may be trusted for more than one
household head.
DECIDED 2026-10-02: allowed, but only through an individual verification per
Person; no automatic trust (FP-ADR-028).

PFP-006  (PWA-7)
Exact health and disability visibility between adult family members, and
for minors.

PFP-007  (PWA-4)
Approval of the verification invalidation decision table (§12).

PFP-008  (PWA-5)
Approval of the proposed request types (§14) and whether health and need
submissions are Change Request types or a sibling workflow.

PFP-009  (PWA-7)
Need category refinement for formula and diapers.

PFP-010  (PWA-8)
Exact public QR verification fields.

PFP-011  (PWA-8)
PDF generation library.

PFP-012  (after V1)
Marriage, divorce, widowhood, member transfer and household-head change
workflows and their Domain Actions.

PFP-013  — DECIDED 2026-10-02 (FP-ADR-022)
Kingfisher Purple is the Family Portal identity. The Staff UI keeps its
teal scale during the Family PWA program; no migration is started. A
future Staff migration needs separate authorization. Not a blocker.

PFP-014  (PWA-10)
Session lifetime for the installed PWA.

PFP-015  (PWA-10)
Offline behavior beyond the approved no-sensitive-data caching rule.

PFP-016  (PWA-1)
Coordinator scope: exact V1 permission set and whether a coordinator may
hold more than one assignment. (How a coordinator signs in is decided:
FP-ADR-021.)
DECIDED 2026-10-02 (FP-ADR-032): multiple active assignments, union of
scopes; a coordinator must be an eligible household head. The permission
names for PWA-1 are final (docs/06 §22b).

PFP-017  (PWA-4)
Who reviews and who approves Family Verification, and whether the two must
differ.

PFP-018  (PWA-8)
Card issuance: automatic on verification or on request; who may revoke and
reissue.

PFP-019  (PWA-9)
Queue and worker infrastructure for announcement fan-out and SMS.

PFP-020  (PWA-2)
Handling of households whose registered head is deceased, missing a
National ID or missing any mobile.
DECIDED 2026-10-02: deceased heads are ineligible and Head Succession is a
rollout gate (FU-01); no eligible head lacks a National ID; heads without
a mobile need an authorized contact update, then verification (§30a).

PFP-021  (PWA-1)
Whether more than one Family User per Family is ever supported (docs/05
PWF-010 stays open; V1 eligibility is the current household head only).
V1 DECIDED 2026-10-02: one family-side account per Person and one eligible
head per Family, so one Family User per Family. Later models stay open.

PFP-022  (PWA-1, then per phase)
Approval of the proposed permission names in docs/06 §22a.
PARTLY DECIDED 2026-10-02: the PWA-1 permission names are final (docs/06
§22b; documented, not seeded). Names for later phases stay PROPOSED.
PWA-1C seeded the ten PWA-1 names; the coordinator's assist grant is
deferred to PWA-1H (docs/06 AUTH-ADR-064).
```

Proposals that stay PENDING until product-owner review: the request types
of §14 (PFP-008), the decision table of §12 (PFP-007) and the permission
names of docs/06 §22a (PFP-022).

---

# 33a. Recorded Follow-ups

Findings from discovery that are deliberately **not** fixed in PWA-0. Each
is handled in the phase named; none changes code or an unrelated rule now.

| # | Finding | Handle in |
|---|---|---|
| FU-01 | **Head Succession.** `RecordPersonDeathAction` does not handle household-head succession and no household-head change action exists; 38 families currently have a deceased head and therefore no eligible user | **Rollout gate**: must be resolved before general Family Portal rollout; not part of PWA-1 (PFP-012) |
| FU-02 | Whether `UpdateFamilyResidenceAction` preserves residence history as docs/03 §32 requires is unverified | PWA-6 (RESIDENCE_UPDATE apply) |
| FU-03 | Production runs `QUEUE_CONNECTION=sync` with no worker, and there is no SMS provider | **Production activation gate** (§30a, docs/08 §16a); provider choice PFP-001; fan-out PWA-9 (PFP-019) |
| FU-04 | docs/06 §53 gives FAMILY_USER "scoped view access" to Change Requests; the seeder grants no view permission | PWA-5 |
| FU-05 | AUTH-ADR-060 is referenced in docs/03, docs/06 and docs/07 but has no entry in the docs/06 decision list | Next docs/06 maintenance |
| FU-06 | "Document Status" version blocks are stale relative to the change logs (e.g. docs/03) | Next documentation maintenance |

---

# 34. Change Log

| Version | Date | Status | Description |
|---|---|---|---|
| 1.0 | 2026-10-02 | Approved | PWA-0: Family Portal program specification — product definition, modules, identity and authentication architecture, mobile trust, multi-role and COORDINATOR, Profile Completion, Family Verification, requests, health and need submissions, card / QR / PDF, notifications and announcements, information architecture, visual direction, PWA direction, security baseline, auditability, logical schema concepts, phases, amendment register and open decisions. Review follow-up (same day): coordinator sign-in decided (FP-ADR-021), Family Portal palette decided (FP-ADR-022), PWA location decided (FP-ADR-023), National ID kept as the PWA-1 blocker (FP-ADR-024), proposals kept pending (PFP-022), §33a follow-ups. Documentation only |
| 1.1 | 2026-10-02 | Approved | PWA-1A findings and PWA-1B design: §30a identity and access architecture (verified Production aggregates, National ID decision, strict normalizer, `family_auth_identities`, User-Person Link, eligibility with ALIVE only, mobile trust, activation, OTP and password policy, nullable `users.email`, disjoint Staff/family accounts, coordinator must be an eligible head, multiple scopes, security audit, retention, Production gates, slices PWA-1C … PWA-1I); FP-ADR-025 … 035; PFP-002/003/004/005/016/020/021 decided, PFP-001/022 partly; FU-01 made a rollout gate. Documentation only |
| 1.2 | 2026-10-02 | Approved | PWA-1C implementation record in §30a: config, strict normalizers, keyed fingerprint service, seven migrations, six models with enums and factories, COORDINATOR role and ten permissions; the four pre-implementation refinements; staged activation of the coordinator assist permission; PWA-1C done, PWA-1D next. Foundation only |
| 1.3 | 2026-10-02 | Approved | PWA-1D implementation record in §30a: access resolver, authentication identity service, link lifecycle actions, National ID correction and death integration, security event recorder, session revocation, account sides and the Staff API boundary; `LINK_ENDED`; FP-ADR-036 and FP-ADR-037; PWA-1D done, PWA-1E next. No activation, login, OTP or UI |
