# Famboook
## Family Portal / Family PWA — Program Specification

**Document:** `11-FAMILY-PORTAL.md`
**Version:** 1.0
**Date:** 2026-10-02
**Status:** APPROVED — PWA-0 (documentation only; nothing in this document is implemented)

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
06-PERMISSIONS.md      §14a, §22a, §59c, §123
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

## PWA-1 blocker: identity-data discovery

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
- How a typed National ID is matched to a Person is **not decided**. It
  depends on the identity-data discovery that opens PWA-1 (§4, PFP-003).
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

Whether a shared number may be trusted for more than one household head is
an open decision (PFP-005).

The exact authorized process that makes a mobile trusted is an open
decision (PFP-004). A coordinator may assist operationally but never sees
an OTP or a password.

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
| Family login identifier | Undecided — depends on the PWA-1 identity-data discovery (§4, PFP-003); `users.email` cannot stay mandatory for Family Users | New; not designed |
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

The role is specified in docs/06 §22a. The permission **names** listed
there are PROPOSED and pending product-owner review (PFP-022); none is
canonical or seeded. Summary:

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
PWA-1   Family identity and access
PWA-2   Family authentication
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
| A-11 | docs/03 PBD-028: exact login identifier policy | Family Users type their National ID to log in; Staff stays email | **Decided in direction only.** How the typed value maps to a login identifier, and any uniqueness rule, stay open and block PWA-1 (PFP-003) |

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
```

---

# 33. Open Decisions

These do not block the architecture. Each must be decided before the phase
named.

```text
PFP-001  (PWA-2)
SMS provider.

PFP-002  (PWA-2)
Exact OTP TTL, attempt limit, resend cooldown and daily caps.

PFP-003  (PWA-1 — BLOCKER)
National ID as login identifier. Not decided, and `persons.national_id`
is NOT simply made unique. PWA-1 begins with the identity-data discovery
of §4 (normalization, null/blank, malformed, duplicates and their cause,
relation between the stored value and the login identifier, safe
migration/backfill). Extends docs/02 PDD-001.

PFP-004  (PWA-1 / PWA-2)
Exact trusted-mobile verification process: who may mark a mobile trusted,
on what evidence, and how a coordinator may assist.

PFP-005  (PWA-2)
Shared mobile numbers: whether one number may be trusted for more than one
household head.

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

PFP-021  (PWA-1)
Whether more than one Family User per Family is ever supported (docs/05
PWF-010 stays open; V1 eligibility is the current household head only).

PFP-022  (PWA-1, then per phase)
Approval of the proposed permission names in docs/06 §22a.
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
| FU-01 | `RecordPersonDeathAction` does not handle household-head succession, and no household-head change action exists | PWA-6 (DEATH_REPORT apply); PFP-012 |
| FU-02 | Whether `UpdateFamilyResidenceAction` preserves residence history as docs/03 §32 requires is unverified | PWA-6 (RESIDENCE_UPDATE apply) |
| FU-03 | Production runs `QUEUE_CONNECTION=sync` with no worker, and there is no SMS provider | PWA-2 (SMS, PFP-001); PWA-9 (fan-out, PFP-019) |
| FU-04 | docs/06 §53 gives FAMILY_USER "scoped view access" to Change Requests; the seeder grants no view permission | PWA-5 |
| FU-05 | AUTH-ADR-060 is referenced in docs/03, docs/06 and docs/07 but has no entry in the docs/06 decision list | Next docs/06 maintenance |
| FU-06 | "Document Status" version blocks are stale relative to the change logs (e.g. docs/03) | Next documentation maintenance |

---

# 34. Change Log

| Version | Date | Status | Description |
|---|---|---|---|
| 1.0 | 2026-10-02 | Approved | PWA-0: Family Portal program specification — product definition, modules, identity and authentication architecture, mobile trust, multi-role and COORDINATOR, Profile Completion, Family Verification, requests, health and need submissions, card / QR / PDF, notifications and announcements, information architecture, visual direction, PWA direction, security baseline, auditability, logical schema concepts, phases, amendment register and open decisions. Review follow-up (same day): coordinator sign-in decided (FP-ADR-021), Family Portal palette decided (FP-ADR-022), PWA location decided (FP-ADR-023), National ID kept as the PWA-1 blocker (FP-ADR-024), proposals kept pending (PFP-022), §33a follow-ups. Documentation only |
