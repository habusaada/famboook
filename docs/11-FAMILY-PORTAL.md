# Famboook
## Family Portal / Family PWA — Program Specification

**Document:** `11-FAMILY-PORTAL.md`
**Version:** 1.21
**Date:** 2026-10-05
**Status:** APPROVED — PWA-0 baseline and PWA-1B identity/access design. Implemented so far: the PWA-1C foundation, the PWA-1D identity domain behaviour, the PWA-1E mobile trust and OTP foundation, PWA-1F activation with the first Family Portal screens, PWA-1G Family login and password reset and PWA-1H coordinator scope and Coordinator Space (§30a); TweetsMS SMS delivery and the PWA-1I security hardening (§30a); the PWA-3A household read views (§30a); activation, login and password reset are each disabled by default, and SMS sends nothing until the server is configured

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
                       mobile, Family Profile Review, Staff Family
                       Verification,
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
 4. Family Profile Review (Staff Family Verification is separate, §11)
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
- See a family's Profile Review summary status and Staff Family
  Verification status, where authorized — never section contents or
  missing-item details (exact visibility decided before PWA-9).
- Follow up on profiles that need review.
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

# 9. Family Profile Review (مراجعة ملف الأسرة)

**Amended 2026-10-04 (FP-ADR-057).** "Profile Completion" is now part of
**Family Profile Review**. Three concepts are distinct and are never
interchangeable:

```text
ACCOUNT VERIFICATION         Is this user the eligible household head allowed
                             into the Family Portal? Family Auth identity,
                             User-Person Link, trusted mobile, OTP,
                             activation, password, FamilyAccessResolver /
                             family.context (§4–§6, §30a). Never called
                             "profile verification".

FAMILY PROFILE REVIEW        The household head reviews the family data
مراجعة ملف الأسرة            Famboook holds, sees what is missing, confirms
                             correct sections and requests corrections or
                             additions (§9 below).

STAFF FAMILY VERIFICATION    A separate, formal Famboook decision (§11).
                             VERIFIED / موثّقة belongs to this concept only.
```

A household head **never** changes canonical registry data through Profile
Review. Approved Domain Actions, after the Change Request workflow (§17),
remain the only mechanism that changes canonical data. A head's
confirmation is an assertion, recorded as such; it can never grant
VERIFIED.

## V1 sections

| Section | Arabic | Content |
|---|---|---|
| FAMILY | معلومات الأسرة | family identity for context (code, clan, branch) plus the household declaration under review |
| HEAD | رب الأسرة | the head's own name, gender, birth date and marital status; the National ID masked with reveal (amended 2026-10-05, FP-ADR-062 — formerly "only as recorded / not recorded, never its value"), outside completeness and the staleness fingerprint |
| MEMBERS | أفراد الأسرة | the active members, with the visibility of FP-ADR-056 (name, relationship, gender, birth date, life status) |
| RESIDENCE | السكن | original residence, displacement status and location, current address (the approved Step 4 fields) |

Later: CONTACT / التواصل (with the CONTACT_UPDATE workflow and its mobile
re-verification). Not part of Profile Review: health and disability (a
later, dedicated sensitive submission design — §15, PFP-006) and needs (an
operational matter, never a profile completeness fact — §16).

## Derived completeness

Completeness is **derived** on every read from the rules below. No
completeness value, percentage or score is stored, and none is shown.
Completeness never requires coordinates, notes, source or provenance,
registration metadata, internal ids or any Staff-only field.

```text
FAMILY      INCOMPLETE when there is no current household declaration, or
            declared_household_size is null. declared_at is not required.

HEAD        INCOMPLETE when gender is null or birth_date is null.
            Marital status UNKNOWN is recommended only (not incomplete in
            V1). The National ID value is never part of completeness or
            of the fingerprint; it may be displayed masked with reveal
            (FP-ADR-062).

MEMBERS     INCOMPLETE when any AVAILABLE active member has no relationship,
            no birth_date, or life_status UNKNOWN. An unavailable
            (soft-deleted) Person placeholder is a Staff data-quality
            concern, not family-fixable incompleteness. A difference between
            declared_household_size and registered_member_count never makes
            MEMBERS incomplete (§10).

RESIDENCE   INCOMPLETE when there is no current residence; or
            displacement_status is null; or DISPLACED without
            displacement_location_text; or NOT_DISPLACED with every current
            address part (governorate, city, area, neighborhood) missing.
            When DISPLACED, the displacement location is sufficient — no
            current-address part is required. original_residence_text is
            recommended, not required.
```

The rules are named rule keys (for example `residence.displacement_status`,
`members.birth_date`) in one backend evaluator, so a future programme can
reuse them (§13).

FAMILY completeness depends on a current household declaration, which a
family cannot create itself. The Staff declaration path (FU-10,
FP-ADR-061: `POST /api/v1/families/{family}/household-declarations`) is
therefore a prerequisite of Profile Review: before it, only the import
could record one. A family's own correction stays the proposed
`HOUSEHOLD_DECLARATION_UPDATE` (§14, PFP-008).

## Section states

States are **derived**; only confirmations are persisted. Precedence — the
first that applies wins:

| State | Arabic | Meaning |
|---|---|---|
| PENDING | طلب قيد المراجعة | an open Change Request touches the section (SUBMITTED, UNDER_REVIEW, RETURNED_FOR_CLARIFICATION, RESUBMITTED, or APPROVED and not yet APPLIED) |
| INCOMPLETE | بيانات ناقصة | the section fails its completeness rule |
| NEEDS_REVIEW | تحتاج إلى مراجعة | the section was confirmed, but its data changed since (stale confirmation), or a request for it was rejected after the last confirmation |
| NOT_REVIEWED | لم تتم المراجعة | complete, never confirmed |
| CONFIRMED | تمت المراجعة | complete, the latest confirmation is current and nothing is pending |

Overall profile, derived: every V1 section CONFIRMED → «ملف الأسرة محدّث»;
pending requests with no immediate missing action → «طلبات قيد المراجعة»;
otherwise → «يحتاج إلى مراجعة». Section status is shown as a checklist with
icon + text (never colour alone). No percentage is ever used.

## Confirmation

The head confirms one section at a time: «راجعتُ هذه البيانات وهي صحيحة».
A confirmation:

- changes no canonical registry data;
- is allowed only for a COMPLETE section with no pending request;
- is append-only and auditable (who, as which Person, when, which section);
- becomes stale when the section's portal-visible canonical data change.

Future persistence (concept, not built): `family_profile_confirmations` —
id, uuid, family_id, section, confirmed_by_user_id, confirmed_by_person_id,
confirmed_at, fingerprint, fingerprint_version, key_version, and optional
acknowledgement codes (codes only). No copy or snapshot of registry values
is stored.

## Staleness fingerprint

Staleness is derived: the server recomputes a keyed fingerprint of exactly
the section's portal-visible values and compares it with the latest
confirmation. A change to a Staff-only field outside the section's values
does not make a confirmation stale. The fingerprint uses a **dedicated,
independently rotatable key** (configuration concept
`FAMILY_PROFILE_FINGERPRINT_KEY`, with `key_version`), its own domain
separation, and a `fingerprint_version` for the field set — never the
application key, so rotating the application key never invalidates
confirmations. The secret itself is never documented. Fingerprints are
never returned to a client.

## Relationship with Change Requests

```text
Correct section             → confirm
Incorrect / missing data    → the appropriate Change Request (§14, §17)
Request pending             → section PENDING
APPROVED, not yet APPLIED   → still PENDING        (APPROVED ≠ APPLIED)
APPLIED                     → canonical data change → fingerprint changes
                              → NEEDS_REVIEW, or INCOMPLETE if still missing
REJECTED                    → NEEDS_REVIEW, with the family-visible reason
                              where policy allows
```

The mapping from request type to section(s) lives in one backend place.
Profile Review never writes canonical registry data.

## Pages

`/family/verification` hosts Profile Review (checklist, section review,
confirm, requests). `/family/household` stays primarily read-only and may
later show small status chips linking to the matching review section.

---

# 10. Declared Size vs Registered Members

The distinction is preserved (docs/02 §20a, docs/03 §55):

```text
declared_household_size ≠ registered_member_count
```

`registered_member_count` is the number of ACTIVE family memberships —
whatever the members' life status, activity or soft-deleted state
(FP-ADR-056). Where a domain needs living persons (targeting, health
summaries) it uses **living members**, never "registered members".

- The two figures are separate registry facts. Neither is presumed
  authoritative.
- Missing people are never inferred: declared 5 and registered 2 never
  means "3 members are missing".
- Equality is never a condition of completeness, confirmation or
  verification.
- When the figures differ, Profile Review may explain, without calculating:
  «يختلف عدد أفراد الأسرة المعلن (5) عن عدد الأفراد المسجّلين بالتفصيل (2).
  يمكنك إضافة أفراد غير مسجلين، أو طلب تحديث العدد المعلن إذا لم يعد
  صحيحًا.» — with the actions ADD_FAMILY_MEMBER / BIRTH_REPORT and
  HOUSEHOLD_DECLARATION_UPDATE (proposed type, PFP-008).
- The MEMBERS section may be confirmed while the figures differ. No
  "other members are non-resident" acknowledgement exists in V1.

---

# 11. Staff Family Verification

**Amended 2026-10-04 (FP-ADR-057).** Staff Family Verification is the
separate, formal Famboook verification concept. **VERIFIED / موثّقة is
reserved for it.** It is distinct from:

```text
Family Profile Review       the head's assertion (§9)
Change Request approval     Staff review of one requested registry mutation (§17)
Staff Family Verification   a formal Famboook decision about the family (this section)
```

Family Profile Review CONFIRMED is never VERIFIED, and no self-confirmation
can grant it. Whether, when and by whom Staff Family Verification is
performed stays open (PFP-017); it may use "profile current" as a
precondition, but is its own audited decision. Conceptual lifecycle, kept
for that later design:

```text
INCOMPLETE → COMPLETE / READY_TO_SUBMIT → UNDER_REVIEW ⇄ NEEDS_CLARIFICATION
→ VERIFIED → (later critical change) REVERIFICATION_REQUIRED
```

## Rules

- Only an authorized Famboook user or process grants VERIFIED.
- A Family User cannot self-verify; a coordinator cannot grant VERIFIED by
  role.
- Verification is auditable: who verified, when, status or version, and a
  history of verification events.
- Family-visible clarification text is separate from internal notes.
- If the Digital Household Head Card (§18, PWA-8) requires VERIFIED, it
  refers to Staff Family Verification — never to Profile Review CONFIRMED.

Proposed permissions (pending approval, PFP-022): docs/06 §22a.

---

# 12. Staff Verification and Later Changes

This section applies to **Staff Family Verification** (§11). Profile
Review confirmations become stale through their fingerprint (§9), not
through this table.

Staff Verification is not necessarily permanent. Later changes are
classified by risk, in one backend decision table — never in frontend code.

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

# 13. No Automatic Eligibility

**Amended 2026-10-04 (FP-ADR-058).** The former chain "Profile Complete →
Verified → Eligible for consideration" is withdrawn.

Neither Account Verification, Family Profile Review nor Staff Family
Verification automatically makes a family eligible — or ineligible — for
any assistance, nomination or service.

```text
VERIFIED ≠ ELIGIBLE        CONFIRMED ≠ ELIGIBLE        INCOMPLETE ≠ INELIGIBLE
```

- Complete and current family information helps the accuracy of
  assessments and nominations for the services and programmes that depend
  on those data. Nothing more.
- Each assistance programme has its own required data, targeting criteria
  and eligibility rules. Staff targeting (`FamilyTargeting`, docs/03 §47b)
  stays separate.
- There is no hidden universal eligibility score.
- Future programme-specific readiness may report «بيانات مطلوبة لهذا
  البرنامج غير مكتملة» by evaluating that programme's required rule keys
  (§9) — without changing the family's Profile Review state and without
  declaring the family ineligible.
- The separation between Needs and Assistance is preserved (docs/03
  §46–§47).

Approved wording direction (Arabic):

```text
Dashboard     «مراجعة بيانات أسرتك وتحديثها تساعد على دقة التقييمات
              والترشيحات للخدمات التي تعتمد على هذه البيانات.»
Review page   «تعرض هذه الصفحة البيانات المسجّلة لأسرتك في فامبوك. راجع كل
              قسم، وأكّد صحته أو اطلب تصحيح ما يلزم. لا تُعدَّل البيانات
              الرسمية إلا بعد مراجعة الطلب من فريق فامبوك.»
Governance    «مراجعة الملف لا تعني الاستفادة من مساعدة بعينها، ولكل برنامج
              معاييره الخاصة.»
```

Never used: «أكمل ملفك لتحصل على مساعدة», «لن تحصل على مساعدة حتى…»,
«الأسرة المراجعة مؤهلة», «الأسرة غير المراجعة غير مؤهلة», or any wording
that presents review, confirmation or verification as eligibility.

---

# 14. V1 Registry Requests

Family Users do not edit canonical records. They use the approved Change
Request architecture (docs/03 §67–§89, docs/05 §54–§81) and Domain
Actions.

## Planned for V1

| Request | Approved type | Apply target today |
|---|---|---|
| Contact update | CONTACT_UPDATE | `UpdatePersonAction` exists |
| Residence / displacement update | RESIDENCE_UPDATE | **Built (PWA-6.1):** in-place correction through `UpdateFamilyResidenceAction` (§30a); a move with history still needs `residence.change` (FU-02) |
| Person correction | PERSON_CORRECTION | `UpdatePersonAction`, `CorrectNationalIdAction` exist; CONFIRM_ALIVE → `ConfirmPersonAliveAction` (exists) |
| Add missing family member | ADD_FAMILY_MEMBER | `AddFamilyMemberAction` exists |
| Birth report | BIRTH_REPORT | `AddFamilyMemberAction` exists |
| Death report | DEATH_REPORT | `RecordPersonDeathAction` exists, with a Staff route and action (FU-10, FP-ADR-061); head succession is not handled |

## First-release direction (approved 2026-10-04, FP-ADR-059)

The first Change Request types, in order:

```text
1. RESIDENCE_UPDATE        correction of the CURRENT residence data
                           (UpdateFamilyResidenceAction, in place)
2. BIRTH_REPORT            AddFamilyMemberAction
3. ADD_FAMILY_MEMBER       AddFamilyMemberAction
4. PERSON_CORRECTION       UpdatePersonAction / CorrectNationalIdAction;
                           CONFIRM_ALIVE, an explicit operation (never a
                           generic life_status field), for a non-head
                           member: the family's statement + Staff review,
                           no mandatory document; APPLY calls
                           ConfirmPersonAliveAction (FP-ADR-060)
5. DEATH_REPORT            for a member who is NOT the household head
                           (RecordPersonDeathAction)
```

Deferred: head death and head succession (FU-01), household-head change,
member transfer, marital events (PFP-012), CONTACT_UPDATE (mobile
re-verification), health submissions and need submissions (PFP-008).

RESIDENCE_UPDATE in V1 is a correction of the current residence. A real
move that preserves residence history needs a future `residence.change`
Domain Action (end the current residence, create a new one — docs/03 §32);
no such action exists today (§33a FU-02).

A family never changes UNKNOWN → ALIVE directly. DECEASED is handled by the
death-report workflow and `RecordPersonDeathAction`.

## Proposed new types — NOT approved

These are needed by the program but are not among the eleven approved
types. They are proposals awaiting formal approval (PFP-008); none may be
implemented before it.

| Proposed type | Purpose | Apply target |
|---|---|---|
| `FAMILY_DATA_UPDATE` | Family basic data | `UpdateFamilyAction` exists |
| `HOUSEHOLD_DECLARATION_UPDATE` | Declared household information | `RecordHouseholdDeclarationAction` exists, with a Staff route and stale-write protection (FU-10); a source value for a family statement is part of this decision |
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

**Amended 2026-10-05 (FP-ADR-062, PFP-006 resolved, §31 A-18).** The two
reading rules above are superseded for PWA-3B.6: the household head may
review the structured health facts held about members of their household
— type, disability type, condition_name, started_at, ended_at, active
state — with no adult / minor distinction in V1. `details` (free text)
stays internal. Visibility is not editing: family submissions remain the
Change Request / health workflow (PWA-7). The coordinator rule is
unchanged.

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
governed by docs/06 §124–§125. **Decided 2026-10-05 (FP-ADR-062, §23a):**
PWA-3B.7 shows recorded needs (category, title, quantity, unit, status,
created_at, resolved_at, related person) — never priority, description,
closure reason or source assessment — and actual non-reversed received
assistance, never targeting, nomination or approval state.

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

**Amended 2026-10-07 (FP-ADR-072, PWA-5a).** The lifecycle adds CANCELLED
(the requester withdraws before approval); no V1 request starts as DRAFT.
The foundation (tables, state machine, permissions) exists; requests become
submittable with PWA-6.1.

---

# 18. Digital Household Head Card

The card belongs to the eligible household-head identity and context.

**Amended 2026-10-07 (FP-ADR-070, §31 A-19).** Superseded in part: the card is
now the **Digital Family Card** («بطاقة الأسرة الرقمية») and its subject is
the **Family**, not the household-head identity. The statements above and
below that the card belongs to the head's identity, that it is valid only
while the holder remains the eligible head and that loss of eligibility
invalidates it, and the "FH-…" holder-ID example, no longer apply. Still
valid: minimal official content (the head's name is the CURRENT head,
resolved live), the "never on the card" list, an opaque public number that
never reuses a family / person code, database id or National ID — now the
card number `FC-XXXX-XXXX-XX` — and issue, active, revoke, reissue with
every transition audited. Implementation: §30a PWA-8.2 record.

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

**As implemented 2026-10-07 (PWA-8.2, FP-ADR-070).** `/verify/{opaque-token}` is a
public page outside `/family` (no gate, outside the service worker scope,
no-store, noindex, no-referrer). The browser posts the token in the body of
`POST /api/v1/credentials/verify` — never in an API URL — so the per-IP
limiter sees the real client. One generic failure «تعذّر التحقق من هذه
البطاقة.» for malformed, unknown, revoked and inactive / archived / deleted
Family alike. Public fields (PFP-010): validity, card number, family_code,
issue date, Clan, Branch and the current head shortened to first + last
word (omitted without a current head). Staff open-record: PWA-8.4.

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

**As implemented 2026-10-07 (PWA-8.3, FP-ADR-071).** `GET /api/v1/family/card/pdf`
— the printable Digital Family Card of the Family's EXISTING ACTIVE
credential, for the eligible household head only. A presentation / export,
not a second lifecycle: it never issues, rotates, renumbers, revokes or
reissues anything (POST /api/v1/family/card stays the only lazy issuance).
Server-generated by mPDF in memory, A4 portrait, Arabic RTL, IBM Plex Sans
Arabic (bundled, OFL); the QR — the existing /verify/{token} capability —
is its only carrier of the token (never printed as text, never stored).
Staff PDF / reprint: PWA-8.4.

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
5. Digital Household Head Card / QR — implemented as «بطاقة الأسرة الرقمية»
   (/family/card, FP-ADR-070)
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

## Family Portal structure (approved direction 2026-10-04)

```text
الرئيسية
أسرتي                 /family/household — معلومات الأسرة · السكن ·
                      أفراد الأسرة (/family/members); read-only
مراجعة ملف الأسرة      /family/verification — FAMILY · HEAD · MEMBERS ·
                      RESIDENCE (§9)
+ طلب جديد            request types as they become available (§14)
طلباتي                submitted requests, statuses, returned clarifications,
                      rejected / applied results (§17)
حسابي                 later: the account / security subset
```

No separate Health, Needs, Assistance or Documents areas are planned yet:
health and need submissions arrive through «+» and are followed in
«طلباتي»; documents are request evidence, not a standalone area.

**Amended 2026-10-05 (FP-ADR-062, §23a).** «حسابي» is the account view
(PWA-3B.5) and holds «بياناتي الشخصية» (/family/account/me). Health facts,
recorded needs and received assistance become READ views in PWA-3B (§23a);
health and need *submissions* still arrive through «+» and «طلباتي».
Documents stay request evidence, not an area.

---

# 23a. Full Data Visibility (PWA-3B — approved 2026-10-05)

## Principle (FP-ADR-062)

The Family Portal is intended to replace, as much as reasonably possible,
a household head's visit to a data-update center. An authenticated
household head must be able to review, from their phone, the family
registry information needed to verify that their own and their
household's records are correct.

```text
VIEW     the head reviews family-facing canonical registry data
UPDATE   never directly: corrections and new events are submitted as
         Change Requests, reviewed by Staff and APPLIED through canonical
         Domain Actions (APPROVED ≠ APPLIED)
```

- Personally identifying data is **not** hidden merely because it is
  personally identifying. Authorization, household isolation and the
  authenticated Family context (`family.context`) are the protection
  boundaries.
- Internal Staff, audit, security, targeting, workflow and implementation
  metadata stay hidden (below).
- The head sees the current canonical value before being asked to submit a
  correction for it; every family-facing correctable field should
  eventually have an explicit request path (§14). Documenting visibility
  approves no proposed request type.
- PWA-3B is read visibility only and is delivered before PWA-5 (docs/07
  RM-ADR-048).

## Structure

```text
الرئيسية                summary only (identity card, declared vs registered,
                        quick actions, Coordinator entry)
أسرتي                   /family/household — the full family record: family,
                        current declaration, current residence, members
                        entry, household health summary (3B.6)
أفراد الأسرة            /family/members — list + in-page member detail sheet
الاحتياجات والمساعدات   /family/household/support — recorded needs and
                        received assistance (3B.7), reached from «أسرتي»
طلباتي                  /family/requests — the Family's requests (always
                        linked); «+» → /family/requests/new only while a
                        request can be started (FP-ADR-074)
حسابي                   /family/account — account state (3B.5)
  بياناتي الشخصية        /family/account/me — the head's own Person and
                        membership data (3B.1); also linked from the head's
                        own member card
```

«بياناتي الشخصية» is canonical Person / membership registry data; «حسابي»
is authentication / account state, activation and trust status, roles and
capabilities, the Coordinator entry and logout. A route-addressed member
detail screen is not required: the initial direction is an in-page sheet
fed by the member list, unless PWA-3B.4 proves a safe public member
identifier is needed.

## Family-facing data

```text
FAMILY        family_code; clan; branch group (when present); branch;
              household head; registration_date; paper_form_no (when
              present). Never registration_source or import machinery.

DECLARATION   the CURRENT declaration only: declared_household_size,
              declared_living_sons, declared_living_daughters, declared_at,
              source. declared_household_size is the source-declared total:
              never compared with, derived from or subtracted from the
              registered members (§10). Historical declarations stay
              internal. Source labels: PAPER_FORM «استمارة ورقية»,
              MANUAL_ENTRY «إدخال يدوي», IMPORT «مستورد من السجل السابق»,
              VERIFIED_SOURCE «مصدر موثّق».

HEAD (self)   full_name; national_id, mobile, alternate_mobile (masked with
              reveal); alternate_mobile_owner_relation; gender; birth_date
              and age; marital_status; relationship; membership started_at.
              life_status stays implicit while the head is eligible (ALIVE).

MEMBERS       every ACTIVE membership, deceased included: full name;
              relationship; household-head status; gender; birth date and
              age; marital status; life status; death date (DECEASED with a
              null date: «تاريخ الوفاة غير معروف», never inferred);
              membership started_at; national_id, mobile, alternate_mobile
              (masked with reveal, PWA-3B.4); structured health facts
              (PWA-3B.6).

RESIDENCE     the CURRENT residence: original_residence_text,
              displacement_status, displacement_location_text, governorate,
              city, area, neighborhood, address_text, residence_type,
              started_at (when present). No residence history is invented:
              UpdateFamilyResidenceAction corrects in place (FU-02).

HEALTH        (PWA-3B.6, PFP-006 resolved) structured facts of household
              members, no adult / minor distinction in V1: type
              (DISABILITY, CHRONIC_DISEASE, PREGNANCY, BREASTFEEDING),
              disability type, condition_name, started_at, ended_at,
              active / ended. No infant or milk fields are invented;
              formula and diapers are needs. As implemented (FP-ADR-068):
              read-only; the household endpoint takes no client
              identifier; active = ended_at IS NULL; closed records stay
              as history; no record means "nothing registered", never
              "healthy"; details and Staff metadata stay hidden.

NEEDS         (PWA-3B.7) recorded family needs: category, title, quantity,
              unit, status, created_at, resolved_at, related person name.
              As implemented (FP-ADR-069): every status as history;
              FULFILLED is a registry status, never proof of a delivery.

ASSISTANCE    (PWA-3B.7) actual, non-reversed deliveries only: assistance
              title, category, type, provider_name, delivered_at, item
              name, quantity, unit, unit_value, currency, receipt_mode,
              recipient / delegate name. Never presented as an eligibility
              signal. As implemented (FP-ADR-069): a receipt is only a
              non-reversed INTERNAL delivery; one delivery = the full
              package; history stays with the recorded Family.

ACCOUNT       (PWA-3B.5) account active state, activation date, login
              mobile trust summary, roles and capabilities, Coordinator
              capability, scope summary and entry, entry to «بياناتي
              الشخصية», logout. Coordinator scope never mixes with
              household registry data. Account Recovery (FU-14, the
              public password reset) belongs to the login experience and
              is not an account setting; authenticated password change is
              a separate future item (FU-16). As implemented: FP-ADR-067.
```

Not in PWA-3B: assessments (INTERNAL_ONLY; a neutral "last assessment
date" may be considered later), family activity history (after PWA-5:
human-readable request and registry events, never actor ids, raw
metadata, security events or implementation details), Documents (not
implemented; `document.*` permissions do not mean a module exists).

## Internal-only data

Never sent to the Family Portal:

```text
identifiers     numeric database ids; person_code (Family Portal
                person_code = INTERNAL_ONLY, revisited only if a safe
                public member identifier is needed); the declaration id;
                paper_sequence_no
lifecycle       created_by / updated_by and every *_by; deleted_at;
                is_current; persons.is_active; ended memberships, end_reason
Staff text      families / persons / memberships / residences /
                declarations notes; person_health_records.details (free
                text, may hold clinical notes); family_needs.description
                and closure_reason; delivery notes
Staff data      registration_source; residence source, latitude /
                longitude (unless separately approved); need priority;
                source_assessment_id; resolved_by
targeting       nomination_source, targeting_criteria, beneficiary
                statuses, approval / rejection / not-delivered decisions
                and reasons, export_fields, beneficiary-list snapshots,
                reversal internals
assessments     ratings, results, notes, general_notes
audit           family_activities actor ids and raw metadata; import
                batches, rows and apply records
security        passwords, remember tokens, sessions, login_key, HMAC
                fingerprints, key versions, OTP data, security events,
                revoked / stale trust history
```

## Sensitive values: masked by default, revealed on request

The mask is **screen privacy**, not authorization to withhold a value from
the person entitled to review it.

- Ordinary payloads carry masked values only: National ID by the existing
  `NationalIdMask` (last 4 visible), mobiles by the existing mobile mask
  (`05` + last 3 visible).
- Each value has its own Eye control («إظهار» / «إخفاء»), accessible and
  RTL-correct. A reveal is temporary: never persisted (no
  localStorage / sessionStorage), reset on navigation or reload, no
  automatic clipboard copy.

**Self reveal (PWA-3B.2).** `POST /api/v1/family/self/reveal`, body: the
field only — `NATIONAL_ID`, `MOBILE` or `ALTERNATE_MOBILE`; response: only
that value. No person id, `person_code`, family id / code or any other
target is accepted. The owner is resolved exclusively from the
authenticated Family context's Person. Behind `auth:sanctum` →
`family.side` → `can:family-portal.access` → `family.context`, a dedicated
throttle, CSRF under the stateful Sanctum session,
`Cache-Control: no-store, private`. Each successful reveal records a
security event carrying the field code, never the value; no Family
Activity event. No password / OTP re-authentication in V1.

**Member reveal (PWA-3B.4).** For ACTIVE household members the head may
reveal National ID, mobile and alternate mobile when present — reviewing
the household registry. Never through the self endpoint and never in
ordinary list payloads. Its own boundary, designed in PWA-3B.4:
`family.context`; the target must belong to the authenticated household
through the canonical membership rules; Coordinator scope never widens
access; no cross-family target; no raw numeric database id as the member
reference (the safe reference is a PWA-3B.4 design issue, §33a FU-13); no
caching; throttle; a security event without the value.

## Display vocabulary

| State | Arabic |
|---|---|
| Value absent (null) | «غير مسجّل» — e.g. birth_date null: «تاريخ الميلاد غير مسجّل» |
| Explicit UNKNOWN enum | «غير معروف» |
| Life status UNKNOWN | «الحالة غير مؤكدة» |
| Declaration value not declared | «غير مُعلن» |
| No current entity / record | a specific phrase: «لا يوجد إقرار مسجّل», «لم يُسجَّل عنوان السكن الحالي» |
| Not applicable | the row is omitted — never "-" |
| Zero | `0` — never «غير مسجّل» |
| Person unavailable | «بيانات هذا الفرد غير متاحة حاليًا» |
| DECEASED, death date null | «تاريخ الوفاة غير معروف» |

## Relationship with Profile Review and Change Requests

- Full Data Visibility and Family Profile Review (§9) are separate. The
  review keeps FAMILY, HEAD, MEMBERS, RESIDENCE and its completeness rules
  unchanged; showing more data adds nothing to completeness or to the
  staleness fingerprint. The HEAD National ID may be shown masked with
  reveal in the review, outside completeness and fingerprint. Health is
  not a V1 review section.
- Visibility never means direct editing. Correction paths (§14):
  PERSON_CORRECTION, RESIDENCE_UPDATE, ADD_FAMILY_MEMBER, BIRTH_REPORT,
  DEATH_REPORT, CONTACT_UPDATE (approved); HOUSEHOLD_DECLARATION_UPDATE,
  HEALTH_RECORD_SUBMISSION, NEED_SUBMISSION, FAMILY_DATA_UPDATE (still
  proposed, PFP-008).

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

As implemented by PWA-1F: the Staff routes live in `frontend/app/(staff)/`
with the Staff gate and shell in that group's layout; the Family Portal is
`frontend/app/family/` with its own layout (the Family theme), the public
`/family/activate`, and the authenticated `(portal)` group behind
`FamilyGate`. The home is `/family` (not `/family/home`). No Staff URL
changed. No manifest and no service worker exist yet: installability
belongs to the later Family Portal shell phase. PWA-1G added the public
`/family/login` and `/family/forgot-password`; the shared pieces of the
three public screens live in `frontend/components/family/auth/`.

URL scope stays `/family`, and the future manifest is scoped to `/family`.

PWA-3A added `/family/household` («أسرتي», read-only) and `/family/members`;
Family Profile Review will live at `/family/verification` (§9).

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
| Family Profile Review — completeness and states | Derived on read; never stored (§9) | Approved concept (FP-ADR-057) |
| Family Profile Review — confirmations | `family_profile_confirmations` (append-only; keyed fingerprint, no registry copy) | Approved concept, not built |
| Staff Family Verification | Verification state, version, verifier, timestamps, history | Deferred (PFP-017); names not fixed |
| Coordinator scope assignment | User ↔ Clan / Branch Group / Branch assignment, audited | New; names not fixed |
| Change Request and types | `change_requests` (types are a code registry, no type table) — docs/04 §37, DB-ADR-058 | Built (PWA-5a), no request type yet |
| Workflow history | `workflow_events` — docs/04 §33, append-only by trigger | Built (PWA-5a) |
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
PWA-3   Family shell and read-only portal — delivered as PWA-3A
        (household read views; DONE) and PWA-3B (Full Data Visibility,
        §23a, slices 3B.1 … 3B.7)
PWA-4   Family Profile Review (Staff Family Verification deferred) —
        delivered AFTER PWA-5 and the first PWA-6 request types
PWA-5   Change Request engine and Staff review workspace
PWA-6   Registry requests / life events
PWA-7   Health and disability submissions, Needs submissions
PWA-8   Digital card, QR, public verification, PDF
PWA-9   Notifications, announcements, Coordinator Space
PWA-10  PWA installability; security, performance and accessibility
        hardening
```

Delivery order differs from the numbers (approved 2026-10-04; PWA-3B
inserted 2026-10-05, docs/07 RM-ADR-048): PWA-3A →
documentation and ADR consolidation → Change Request prerequisites →
PWA-3B Full Data Visibility → PWA-5
→ first PWA-6 request types (FP-ADR-059) → PWA-4 Family Profile Review →
later account / contact → PWA-7 health and need submissions → optional
Staff Family Verification and PWA-8 card.

Scope, dependencies and exit criteria: docs/07 §31a.

---

# 30a. PWA-1 Identity and Access Architecture (PWA-1B — approved 2026-10-02)

Approved design. **Implemented: the PWA-1C foundation, the PWA-1D
identity domain behaviour, the PWA-1E mobile trust and OTP foundation,
PWA-1F activation, PWA-1G login and password reset and PWA-1H coordinator
scope and Coordinator Space**, **TweetsMS SMS delivery** and the **PWA-1I
security hardening** (see the eight implementation records below). The provider is configured and
enabled only in the server environment (docs/08 §16a).
Physical
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

Family self-activation MUST NOT be considered Production-ready until all
hold (PWA-1E, PWA-1F and PWA-1G do not satisfy this gate; PWA-1G delivers
the code of item 7; the TweetsMS integration delivers the code of item 1,
decides item 4 — after the response, no queue, no retry — and makes item 5
not applicable):

```text
1. A real SMS provider is selected and integrated
2. Its credentials are securely configured
3. Delivery-failure behaviour is validated
4. The Production queue / retry architecture is decided and implemented as
   the provider requires
5. Worker process supervision exists if queued delivery is used
6. The scheduler cron is configured for scheduled maintenance
7. PWA-1G Family login is delivered and validated
8. The response-time floor is set against the real provider
9. The Family Auth flags are enabled deliberately, never by a deployment
```

SMS is an abstraction (`SmsSender`) with a log-only development driver and
the TweetsMS Production driver (`FAMILY_SMS_DRIVER=tweetsms`; docs/08 §16a).

**FU-01 — Head Succession** is a rollout gate: the 38 families whose
current head is deceased have no eligible user. It is not part of PWA-1
and must be resolved before general Family Portal rollout.

## Implementation slices

```text
PWA-1A  Identity data discovery                          DONE
PWA-1B  Identity and access design                       DONE
PWA-1C  Schema / foundation                              DONE
PWA-1D  Identity resolver + links                        DONE
PWA-1E  Mobile trust + OTP / SMS abstraction             DONE
PWA-1F  Activation + first Family Portal UI              DONE
PWA-1G  Login / password reset                           DONE
PWA-1H  Coordinator identity / scope                     DONE
PWA-1I  Security hardening / full regression             DONE
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

**Account sides and Staff boundary.** The Staff API requires
`AccountSide::STAFF`; the boundary fails closed for FAMILY, INVALID and NONE
accounts. See docs/06 §22b (AUTH-ADR-065, AUTH-ADR-066).

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

## PWA-1E implementation record

Implemented (infrastructure and a Staff API; no migration):

```text
App\Support\FamilyAuth\CurrentTrustedMobile   for(Person): TrustedMobileResult
App\Support\FamilyAuth\MobileTrusts           the STALE transition; authorization
Actions   GrantPersonMobileTrustAction, RevokePersonMobileTrustAction
Staff API GET / POST /people/{person}/mobile-trust, POST …/mobile-trust/revoke
App\Contracts\SmsSender                        send(SmsMessage)
App\Support\Sms\UnconfiguredSmsSender          the default: always refuses
App\Support\Sms\LogSmsSender                   local / testing only
App\Support\FamilyAuth\OtpChallenges          issue, resend, verify, consume, supersede
App\Support\FamilyAuth\OtpThrottle            cross-challenge ceilings
famboook:purge-otp-challenges                  scheduled daily
```

**CurrentTrustedMobile invariant.** A Person has a trusted mobile only when
their current stored mobile normalizes (`FamilyMobile`: ten ASCII digits
beginning `05`, nothing else) and the latest trust record is TRUSTED with a
fingerprint — under its own key version — equal to that number's. Everything
else fails closed. OTP issuing, and later activation and reset, ask this
service and never query the trust table themselves.

**Trust lifecycle.** A grant (SUPER_ADMIN or ADMINISTRATOR, through
`person-mobile-trust.grant`) trusts the stored number and takes no number as
input; the Person must be not deleted, active and ALIVE, and need not be a
household head. A grant of an already trusted number is a conflict. A
revoke records the actor, the time and a reason code. Neither changes the
account, the link or the sessions.

**STALE.** When the canonical number changes — on any Eloquent write path —
the previous trust becomes STALE and its open OTP challenges are superseded.
A formatting-only edit changes nothing. **Changing back does not restore
trust:** STALE and REVOKED records never become TRUSTED again, and a new
verification creates a new record.

**Shared numbers.** Valid data, never unique. Each Person on a shared number
is verified, revoked and challenged independently; the destination
throttle is the only thing they share.

**OTP.** Six digits from a cryptographically secure source; a keyed hash
bound to the challenge uuid (`OTP_CODE` context of the Family Auth key) is
stored, never the code. Five-minute expiry, five attempts, a 60-second
resend cooldown, three sends per challenge, single use. A resend keeps the
row, replaces the code, restarts the expiry and keeps the attempts. A
correct code opens a **10-minute verified grant**; consumption happens
inside the transaction of the workflow that uses it. One open challenge per
Person and purpose; a new issue, a revoked trust or a changed number
supersede it. Purposes: `ACTIVATION`, `PASSWORD_RESET` (no workflow uses
them yet).

**Delivery.** Synchronous, after the challenge is committed, through
`SmsSender`. No provider exists: the default sender always refuses, and the
log driver runs only in `local` and `testing`, writing to its own file with
the destination masked. A failed delivery leaves a counted challenge, an
`OTP_ISSUED` event with a FAILURE outcome, and no retry. Nothing is queued,
so no plaintext code is written to a queue table.

**Throttle** (configurable security settings; no raw identifier in a key;
fails closed):

```text
Person        5 per hour · 10 per day
Destination   10 per hour · 20 per day
IP            20 per hour
Global        500 per hour
```

**Events recorded.** `MOBILE_TRUST_GRANTED`, `MOBILE_TRUST_REVOKED`,
`MOBILE_TRUST_STALE`, `OTP_ISSUED` (a resend is the same event with a
higher send count), `OTP_FAILED`, `OTP_LOCKED`, `OTP_VERIFIED` (new),
`OTP_CONSUMED`. Never a code, a number or a National ID.

**Retention.** Finished OTP challenges are purged after 90 days by a daily
scheduled command; the schedule only runs where the scheduler cron exists.
Security events keep their 24-month policy; that purge is not implemented.

Not implemented by PWA-1E: Family self-activation, any public National ID
endpoint, Family login, password setup or reset, the Family Portal
frontend, coordinator-assisted verification (PWA-1H), a real SMS provider,
Production SMS credentials, queued delivery and provider retries.

## PWA-1F implementation record

Implemented 2026-10-02. No migration. Activation stays **disabled by
default** (`FAMILY_ACTIVATION_ENABLED=false`).

**Backend.**

- `family.side` (`EnsureFamilySideAccount`): fail closed, only
  `AccountSide::FAMILY`. It classifies the account and authorizes no family
  data.
- `GET /api/v1/family/me` and `POST /api/v1/family/auth/logout`. `/me`
  returns the Person's name, the family-side roles, the coordinator flag
  and `context` (`available`, `family.code`, `family.name`); without a
  Family context it returns `available: false`, `family: null` and no
  reason.
- `family.activation` (`EnsureActivationEnabled`): the gate on the four
  public activation routes; 503 `ACTIVATION_UNAVAILABLE` and nothing else
  while it is off.
- `FamilyActivation` — start, verify, resend:
  - exact lookup on `persons.national_id`; zero or several live matches
    deny;
  - `FamilyAccessResolver::headEligibility`, "no current link", then
    `OtpChallenges::issue` (purpose ACTIVATION) to the current trusted
    mobile;
  - every well-formed identifier gets the same answer; a denied start
    returns a decoy;
  - a per-identifier ceiling keyed by the LOGIN_ID fingerprint.
- `ActivationDecoys`: cache-only decoy challenges that model sends,
  cooldown, expiry, attempts, lock and supersession with the OTP policy
  values. No Person, User or OTP row and no SMS. A reference — real or
  decoy — is recognised for one hour.
- `ResponseFloor`: start and resend never answer faster than
  `family_auth.activation.min_response_ms` (400 by default, a development
  value).
- Named route limiters per IP (start, verify, resend, complete), keyed by a
  digest of the address. `OtpThrottle` remains the only SMS ceiling.
- `ActivateFamilyAccountAction` — the completion transaction: lock the
  Person, consume the verified grant, re-check eligibility and "no current
  link", create a NEW User (email null, name snapshot, hashed password,
  active), assign FAMILY_USER only, call `EstablishFamilyIdentityAction`,
  record `ACTIVATION_COMPLETED`. A failure rolls everything back, the grant
  included, and is recorded after the rollback.
- Session: the existing Sanctum first-party session. A request without a
  session is refused before the transaction; a Staff session in the same
  browser is replaced; the session id is regenerated; no "remember me".
- Security events reuse `ACTIVATION_REQUESTED`, `ACTIVATION_COMPLETED`,
  `ELIGIBILITY_DENIED` and `AMBIGUOUS_IDENTITY`; reasons are internal codes
  (`ActivationDenial`, `FamilyAccessDenial`, `OtpFailure`). An unknown
  identifier is recorded only as its keyed fingerprint.
- Public errors are the `ActivationError` codes (docs/06 §22b).

**Frontend.**

- Staff routes moved into the `(staff)` route group; URLs unchanged. The
  Family Portal never mounts or queries the Staff gate.
- Family theme: the approved palette as scoped CSS variables under
  `[data-portal="family"]`; the Staff teal tokens are untouched.
- `/family/activate`: one route, three internal steps (National ID, code,
  password). State lives in React memory only — nothing in the URL,
  `localStorage`, `sessionStorage` or a cookie — so a refresh restarts.
- The code step shows no part of the mobile number and words the delivery
  conditionally. One real input (`one-time-code`) behind six visual slots.
- No login link is shown: `/family/login` does not exist until PWA-1G.
- `/family`: `FamilyGate` (401 → `/family/activate`, 403 → neutral notice),
  the shell with the greeting, the Family name and code, logout, and the
  approved bottom navigation with only the home enabled.
- When `context.available` is false the shell renders a neutral
  "access unavailable" state instead of the home and the navigation.
- Vitest and Testing Library were added for component and state tests.

**Known limits.**

- No Family login: after the session ends an activated user cannot return
  until PWA-1G. This is why PWA-1G is part of the Production gate.
- The response floor does not cover an SMS provider slower than the floor;
  its Production value is set with the provider. (Superseded by the
  TweetsMS record: the SMS is now sent after the response.)
- A decoy follows the per-Person hourly and daily send ceilings by
  counting sends per identifier. The destination, IP and global SMS
  ceilings are not modelled for decoys. (PWA-1I: the IP and global
  ceilings now are; the destination ceilings cannot be.)
- True parallel completion is serialized by the Person row lock on
  PostgreSQL; the automated suite runs on SQLite and covers the sequential
  outcomes only.

Not implemented by PWA-1F: Family login, password reset, family-data
endpoints, the family context middleware, the installable PWA (manifest,
service worker), coordinator scope, a real SMS provider.

## PWA-1G implementation record

Implemented 2026-10-03. No migration. Login and password reset are each
**disabled by default** (`FAMILY_LOGIN_ENABLED=false`,
`FAMILY_PASSWORD_RESET_ENABLED=false`), independently of activation.

**Backend.**

- `FamilyLogin`: National ID → strict normalizer → keyed LOGIN_ID
  fingerprint → ACTIVE identity (`findByNationalIdInput`) → User →
  password → `FamilyAccessResolver::familyContext()`, and the identity that
  was looked up must be the one the resolver holds. Never
  `persons.national_id`.
- Every failure after format validation is one 401 `INVALID_CREDENTIALS`.
  The reason is a security event (`LOGIN_FAILED`) with the keyed
  fingerprint.
- Timing: the password is verified in every case — against a cached dummy
  bcrypt hash of the configured cost when no identity exists — and the
  context is evaluated only after a correct password. No response floor on
  login.
- Lockout, failures only, over 900 seconds: 5 per identifier and address,
  20 per identifier across addresses; a success clears both. A route
  limiter allows 20 attempts per address. Keys hold the fingerprint and a
  digest of the address.
- A request without a session is refused before any password is checked.
  A successful login replaces any session in that browser and regenerates
  the session id and CSRF token. No token, no "remember me".
- `ChallengeDecoys` (was `ActivationDecoys`) is purpose-aware: the purpose
  is part of the state and of the "open" key, so activation and reset
  references never interchange. `FamilyOtpFlow` holds everything a caller
  can observe in start, verify and resend, for one purpose at a time;
  `FamilyActivation` and `FamilyPasswordReset` hold only who gets a real
  challenge.
- `FamilyPasswordReset`: the login lookup, the full Family context, then
  `OtpChallenges::issue(PASSWORD_RESET, person, user)`. Denied requests get
  a decoy.
- `ResetFamilyPasswordAction` — the completion transaction: lock the
  Person, lock the User, consume the verified grant for both, re-check the
  Family context for the same Person, replace the password,
  `FamilySessions::revoke`, record `PASSWORD_RESET_COMPLETED`. A failure
  rolls everything back and is recorded after the rollback. The session of
  the completing browser is established after the commit.
- Sanctum's password-hash session check remains the second layer: a
  session that outlived the deletion is refused on its next request.
- Family password policy, for activation and reset: at least 8 characters,
  confirmation, no composition rule, at most 72 bytes of UTF-8. A longer
  password is refused with a plain message; nothing is truncated.
- Gates: `family.login` and `family.password-reset`, beside the existing
  `family.activation`.
- Security events reuse `LOGIN_SUCCEEDED`, `LOGIN_FAILED`,
  `PASSWORD_RESET_REQUESTED`, `PASSWORD_RESET_COMPLETED` and
  `SESSIONS_REVOKED`.

**Frontend.**

- `/family/login`: National ID and password, one generic sentence for a
  credential failure, links to the password reset and to activation. The
  page does not query `/family/me`.
- `/family/forgot-password`: one route, three in-memory steps (identifier,
  code, new password), built from the same steps as activation. A
  successful reset signs in and opens `/family`.
- `/family/activate` now shows "لديك حساب بالفعل؟ تسجيل الدخول".
- `FamilyGate` sends a guest to `/family/login`; logout goes there too. A
  Staff or other non-family session still sees the neutral notice, now
  with a link to the Family login.
- Shared pieces under `components/family/auth/`: the auth card, the
  identifier step, the OTP step and input, the password step and field,
  the error reader.

**Known limits.**

- The shared 120-minute idle session lifetime applies; no Family-specific
  lifetime and no "remember me". To be reviewed with the installable PWA
  and the account module.
- No authenticated "change my password"; it belongs to the account module.
- A head who lost eligibility sees the generic credential failure with a
  correct password, and a reset sends nothing. That is deliberate; the
  login page cannot explain it.
- The family context MIDDLEWARE listed for this slice was not built: no
  family-data route exists yet. It arrives with the first one, with the
  cross-family IDOR tests.
- The identifier lockout tiers and the decoy send ceiling are cache
  counters; under heavy concurrency they are approximate.

Not implemented by PWA-1G: family-data endpoints, the family context
middleware, the installable PWA, coordinator scope, a real SMS provider,
"change my password".

## PWA-1H implementation record

Implemented 2026-10-03. No migration.

**Binding decisions.** `coordinator-family.view-summary` approved in PWA-1H,
summary projection only, COORDINATOR only. No assignment expiry.
`person-mobile-trust.assist` stays withheld from COORDINATOR (a future
dedicated assisted-mobile-trust workflow). SUPER_ADMIN and ADMINISTRATOR
manage coordinators through `coordinator-scope.manage`.

**Backend.**

- `CoordinatorScopes` — the one authority on Coordinator access:
  - `context(user)`: own Family context (`familyContext`, unchanged),
    COORDINATOR, `coordinator-space.access`, at least one effective
    assignment; otherwise an internal denial;
  - effective assignment = not revoked, target active (Clan; Group; Branch
    and its Group when grouped);
  - `families(context)`: ACTIVE, not deleted families covered by the UNION
    of the assignments — one correlated EXISTS over the assignments, never
    a list of ids — over the CURRENT hierarchy; a family whose Branch or
    Group is inactive is outside every scope;
  - no cache, no lock, no event. `OrganizationalScope` is not used.
- Domain Actions `GrantCoordinatorRoleAction`, `RevokeCoordinatorRoleAction`,
  `AssignCoordinatorScopeAction`, `RevokeCoordinatorScopeAction`: Staff-side
  holders of `coordinator-scope.manage` only; the role needs an eligible
  family-side head and never lands on a Staff or mixed account; a scope
  needs the role and an active target; removing the role revokes every
  active scope (`ROLE_REMOVED`) in the same transaction. Events
  `COORDINATOR_ROLE_GRANTED` / `_REVOKED` (new codes, no migration) and
  `COORDINATOR_SCOPE_ASSIGNED` / `_REVOKED`.
- Staff API under `/api/v1/people/{person}/coordinator…` and
  `/api/v1/coordinator-scopes/{uuid}/revoke`, behind `staff.side`.
- `coordinator.space` boundary after `family.side`; `GET
  /family/coordinator/context` (scope summaries, family count); `GET
  /family/coordinator/families` (25 per page, search by code prefix or head
  name, narrowing filters) and `GET /family/coordinator/families/{code}` —
  both the six-field summary, both only through the authorized query, the
  same 404 for out of scope and nonexistent.
- `/family/me.coordinator_space` from the same resolver as the boundary.

**Frontend.**

- `/family`: a "مساحة التنسيق" entry card only when
  `coordinator_space` is true.
- `/family/coordinator` (inside the Family Portal, no separate login): a
  mode strip "أنت في مساحة التنسيق" with "العودة إلى أسرتي"; the household
  bottom navigation is hidden. Title "مساحة التنسيق"; "نطاق التنسيق" with
  scope chips ("عشيرة: …", "مجموعة فروع: …", "فرع: …"); "عدد الأسر في
  النطاق: N"; a searchable, server-paginated list "الأسر في النطاق".
- `/family/coordinator/families/{code}`: the same six fields as a summary
  page; "الأسرة غير متاحة." for out of scope and nonexistent alike.
- The space asks the server every time (`/family/coordinator/context`); a
  403 shows a neutral notice with the way back to the household.

**Known limits.**

- Assignments do not expire; they must be revoked explicitly.
- No Staff screen for coordinator administration yet (API only).
- A Coordinator whose own household is inside the scope sees it in the
  list like any other family, with the summary projection.
- The PostgreSQL CHECKs on the assignment table are still proved only by
  the PostgreSQL-only test class, which needs a `famboook_test` database;
  the actions validate the same shape.

Not implemented by PWA-1H: coordinator notifications and announcements,
profile-completion follow-up, account-status visibility
(`coordinator-family.view-account-status` stays PROPOSED), assisted mobile
trust, any coordinator write.

## TweetsMS SMS delivery implementation record

Implemented 2026-10-03. No migration, no new flag, no frontend change.
Production SMS stays off until the server `.env` sets
`FAMILY_SMS_DRIVER=tweetsms`, `TWEETSMS_API_KEY` and `TWEETSMS_SENDER`
(docs/08 §16a — validation procedure and rollback).

**Driver.** `TweetsSmsSender` behind `SmsSender`, selected by the existing
`FAMILY_SMS_DRIVER` (no other driver variable). One request per SMS:

```text
POST https://www.tweetsms.ps/api.php/office/sendsms     (JSON)
{"api_key", "sender", "message", "to": "05XXXXXXXX"}
```

One destination in the registry's own local format, sent as stored — never
converted to `970…` / `+970…`. Nothing else: no groups, date, time,
National ID, name, family code or family data. With a missing key or
sender it fails closed: nothing is sent and the application still boots.

**Success criterion.** A send succeeds only when the JSON result `code`
is `999` (number or digit string, normalized then compared strictly). HTTP
2xx, `"status": "success"` or `"msg": "send success"` prove nothing alone;
malformed JSON, a missing or null code, an unexpected structure or an
unknown code are never a success. `999` means TweetsMS accepted the SMS,
not that the handset received it.

**Failure classification** (`SmsFailureOutcome` + `SmsFailureReason`):

```text
-126              TEMPORARY_FAILURE               PROVIDER_BUSY
-124              PROVIDER_CONFIGURATION_FAILURE  INSUFFICIENT_CREDIT
-110              PROVIDER_CONFIGURATION_FAILURE  INVALID_CREDENTIALS
-111              PROVIDER_CONFIGURATION_FAILURE  ACCOUNT_INACTIVE
-112              PROVIDER_CONFIGURATION_FAILURE  ACCOUNT_BLOCKED
-114              PROVIDER_CONFIGURATION_FAILURE  SENDING_STOPPED
-115 / -116       PROVIDER_CONFIGURATION_FAILURE  INVALID_SENDER
-100              PERMANENT_FAILURE               MISSING_PARAMETERS
-120              PERMANENT_FAILURE               INVALID_DESTINATION
other code        UNKNOWN                         UNRECOGNIZED_RESULT
no valid code     UNKNOWN                         MALFORMED_RESPONSE
HTTP 5xx / 4xx    TEMPORARY / PROVIDER_CONFIGURATION (HTTP_*_ERROR)
redirect, other   UNKNOWN                         UNEXPECTED_HTTP_STATUS
DNS / connect     TEMPORARY_FAILURE               CONNECTION_FAILED
timeout, other    UNKNOWN                         TRANSPORT_UNCERTAIN
```

**Transport.** Laravel HTTP client; JSON in and out; TLS verification on;
redirects refused; connect timeout 3 s, total 8 s (configurable). **No
automatic retry** of any kind: TweetsMS has no idempotency key, so a retry
could send a second code; the user's resend is the retry.

**Timing (A′).** On the public activation and password reset routes
(middleware `sms.after-response`) `SmsDispatcher` keeps the message in
memory and sends it when the application terminates — after the response
was sent (PHP-FPM finishes the request first) — in the same PHP process,
with no queue. The plaintext code is never written to a queue, the cache,
the database or a log. Outside a request (a command, a direct service
call) the send is immediate. The challenge, `send_count`, throttle
accounting, expiry, resend cooldown, send limit and supersession happen
before the response exactly as before; only the provider call moved.

**Anti-enumeration.** The provider's latency or failure can no longer
change the public answer or its timing: a real challenge whose SMS fails
answers exactly like a successful one and like a decoy. Provider codes
are never shown to Family Portal users.

**Failure recording.** A failure — also one after the response — is
logged with `provider`, `outcome`, `reason`, `purpose` and `mobile_last2`
only (never the key, the code, the text or the number), and recorded on the
`OTP_ISSUED` FAILURE event as `delivery_outcome` / `delivery_reason`
(allow-listed metadata). A PROVIDER_CONFIGURATION_FAILURE is logged
CRITICAL at most once per reason per 10 minutes.

**OTP message** (one UCS-2 part, 62 of 70 units; ASCII digits; the minutes
follow the configured TTL, 5 by default):

```text
رمز التحقق في Famboook: 123456
صالح 5 دقائق. لا تشاركه مع أحد.
```

**Operations.** `php artisan famboook:sms-check` prints the driver and
API key / sender as YES/NO — never their values — and sends nothing.
`--send-test=05XXXXXXXX` sends one fixed non-OTP text after an
interactive confirmation and prints `SENT` or the outcome and reason; the
full number is never printed and no OTP challenge is created.

**Tests.** All HTTP is faked; every test fails on a stray outgoing request
(`Http::preventStrayRequests()` in the base test case).

Not implemented: delivery reports (handset receipt), balance monitoring,
provider failover, a Staff screen for SMS status. The scheduler cron
remains a separate Production prerequisite.

## PWA-1I implementation record — security hardening

Implemented 2026-10-03. No migration, no API shape change, no frontend
change, no new flag; nothing enabled. Every finding of the PWA-1I preflight
classified HIGH or MEDIUM in code is closed; the operational ones are
Production validation items (docs/08 §16a).

**Decoy concurrency parity (A-1, HIGH).** A real challenge is row-locked,
so its parallel requests behave serialized; a decoy is cache state and used
to read-modify-write it, so parallel requests could tell the two apart
(e.g. eight parallel wrong codes: exactly four INVALID then LOCKED on a real
challenge, more INVALID on a decoy; two parallel resends: one winner on a
real challenge, two on a decoy). Now nothing parallel requests change is a
read-modify-write of the decoy record:

```text
attempts     atomic counter (Cache::increment); each attempt is judged by
             its own count — below the limit CODE_MISMATCH, at and after it
             LOCKED — never by a later re-read
superseded   a flag of its own, only ever set (a racing resend cannot
             revive a superseded decoy)
resend       one atomic claim per send (Cache::add): one winner, the others
             see the cooldown (or the send limit) like a real loser
```

A real resend that loses the race now reports its cooldown from the row as
read under its lock (it used the stale pre-read).

**Decoy ceiling parity (A-3).** A decoy resend is refused once the IP or
the global OTP SMS ceiling is reached, exactly as a real resend is
(`OtpThrottle::sharedCeilingReached`, read-only — a decoy counts nothing on
the real SMS counters). The Person ceilings are mirrored per identifier as
before. The DESTINATION ceilings cannot be mirrored: a decoy has no
destination and none is invented — a residual, documented limit (an
attacker would need two eligible heads sharing one trusted phone).

**Response floor (A-2).** Verify and complete now wait out the same floor
as start and resend, for every outcome (real or decoy, right or wrong code,
locked, expired, consumed, refused, success). One configured value
(`FAMILY_ACTIVATION_MIN_RESPONSE_MS`, still 400 ms by default); the
Production value is set from measurements. Login is unchanged: its single
bcrypt verification already equalizes it.

**Attempt accounting (L-1, A-4).** Login counts every attempt atomically
BEFORE the password check (identifier + IP and identifier tiers) and refuses
an attempt beyond a ceiling without any verification; a success clears both,
so in effect they count failures. Of parallel attempts at most the ceiling
reach bcrypt; known and unknown identifiers are accounted identically. The
per-identifier start ceiling counts the same way. Limits unchanged (5 / 20
failures per 15 minutes, 20 attempts per IP per 15 minutes, 5 starts per
identifier per hour).

**Identifier input (N-1).** Before normalization the copy-paste invisibles
are removed too: U+200B–200D (zero-width space and joiners), U+202A–202E
(directional embeddings and overrides), U+2066–2069 (directional isolates)
and U+FEFF (BOM). The result must still be exactly nine ASCII digits; no
other character is stripped. (Shared by the mobile normalizer; no stored
trust can depend on the old behaviour, since such a value never normalized.)

**Readiness check.** `php artisan famboook:family-auth-check` — read-only,
counts and YES/NO only: the fingerprint key (configured, valid, version,
previous key), ACTIVE identities that no longer match their Person's
current National ID under the configured key, TRUSTED mobiles that no longer
match the current mobile, the three flags, the SMS driver and TweetsMS
configuration, the session, cache and debug settings — with warnings and
exit code 1. It detects a WRONG key (finding N-2), which otherwise fails
silently: every login invalid, every trust stale, every reset a decoy.

**Regression coverage.** UUID v4 for real and decoy references (a real one
silently becoming a time-ordered UUIDv7 would be an oracle), equal lifetime
and cross-purpose behaviour; flags switched off mid-flow (503, nothing
changed, the grant left unconsumed); National ID corrected during a reset
(the obsolete identifier fails at commit, the in-flight grant follows the
account — Person and User — while it keeps its Family context, and dies
with a suspended identity; no persons.national_id fallback); death, lost
headship, ended membership, deactivated Family or User, suspended or ended
link during a session (context and Coordinator Space gone on the next
request; sessions revoked where designed); corrupted RBAC (direct
permissions never change the side; mixed roles refused on both sides); one
leakage sweep through the whole flow with the real TweetsMS driver.

**PostgreSQL concurrency.** `PostgresConcurrencyTest` (PostgreSQL only;
skipped elsewhere) proves with a second database session and
`lock_timeout` that parallel verify and resend wait for the challenge row,
parallel starts for the Person row, completions for the Person / User row,
that a grant consumed by a parallel completion is refused under the lock,
and that one open challenge per Person and purpose is enforced by the
database. Run on the dedicated `famboook_test` database (docs/08 §16a).

**Data note.** `auth_otp_challenges.ip` holds the raw client IP of the
request that issued the challenge, for operations and abuse review, at most
for the 90-day retention of finished challenges (security events keep only
a digest).

## PWA-3A implementation record — household read views

Implemented 2026-10-04 (FP-ADR-056), Production-verified. Read-only; no
migration. Every endpoint sits behind `auth:sanctum` → `family.side` →
`can:family-portal.access` → `family.context` (the resolver's Family on the
request; any 403 from these routes means "family data unavailable"), takes
no identifier from the client and answers `Cache-Control: no-store`.

```text
GET /api/v1/family/household           summary: family code, clan, branch,
                                       head name, declared size and date,
                                       registered_member_count
GET /api/v1/family/household/members   one row per ACTIVE membership
GET /api/v1/family/household/profile   family facts + the CURRENT residence
```

- `registered_member_count` = ACTIVE memberships, whatever the Person's
  life status, activity or soft-deleted state; one shared population for
  the summary, the members list and the profile (the members list length
  always equals the count).
- Members: name, relationship (from the membership), head flag, gender,
  birth date, life status. A soft-deleted Person keeps its row as a
  placeholder (`available: false`) with the membership relationship and no
  Person data. Order: head; spouses; sons and daughters together; fathers
  and mothers together; OTHER and future codes; no relationship — then
  birth date (unknown last), paper sequence, membership id (none exposed).
  Never person_code, National ID, mobile, marital status, notes or ids.
- Profile residence: original residence, displacement status (null = not
  collected, never NOT_DISPLACED) and location, current address parts.
  Never address_text, residence_type, coordinates, residence dates/flags,
  source or notes; never declared sons/daughters, status or registration
  data.
- Frontend: `/family` dashboard (identity card, household summary with the
  declared / registered explanation, disabled quick actions),
  `/family/household` («أسرتي»: معلومات الأسرة · السكن · أفراد الأسرة) and
  `/family/members`. «أسرتي» in the bottom navigation is current on both.
  Labels: «السكن الأصلي», «الحالة غير مؤكدة», «متوفى/متوفاة», «بيانات هذا
  الفرد غير متاحة حاليًا».

*Superseded in part on 2026-10-05 by FP-ADR-062 (§23a); this record stays
as the history of what PWA-3A delivered.* The PWA-3A exclusions of member
National ID, mobile and marital status, of `address_text` and
`residence_type`, of declared sons / daughters and declaration source,
and of registration date no longer describe the target: PWA-3B shows them
(sensitive values masked, full values only through reveal endpoints).
The exclusions of `person_code`, notes, ids, coordinates, residence
source / flags and status stay. The UI wording «تاريخ الميلاد غير معروف»
for a null birth date is corrected to «تاريخ الميلاد غير مسجّل» (§23a
vocabulary).

## Installable Family app implementation record — manifest, icon, service worker

Implemented 2026-10-04 (FP-ADR-055). Frontend only; no backend change.

- **Identity:** `public/manifest.webmanifest` — id, start_url and scope
  `/family`, `display: standalone`, Arabic RTL, theme `#751BD5`; the
  official Famboook icon (`public/icons/famboook-icon.svg`, kept
  byte-for-byte) and PNGs rendered from it (192, 512, 512 maskable, 180
  Apple). Linked from the Family Portal layout only.
- **Service worker** (`public/family-sw.js`), registered from the Family
  Portal only, production builds only, scope `/family` — so the start page
  `/family` itself is controlled; the worker handles only `/family` and
  `/family/...`, never Staff paths such as `/families`, `/login` or `/`.
  - precaches ONE file: the static offline page `/family/offline.html`
    ("لا يوجد اتصال بالإنترنت" / "تحقق من اتصالك بالإنترنت ثم حاول مرة
    أخرى.");
  - handles only top-level GET navigations under `/family`, network first;
    a successful response is never stored; only a network failure returns
    the offline page — a server error page is shown as is;
  - touches nothing else: the API (`api.famboook.com`), Family Auth, the
    CSRF cookie, scripts, styles, images and other origins go to the network
    as if there were no worker. Nothing private can enter Cache Storage or be
    shown offline;
  - versioned cache (`famboook-family-v1`); obsolete `famboook-family-*`
    caches removed on activate; `skipWaiting` and `clients.claim`.
- **"تثبيت فامبوك"** on `/family/login` and the portal home: the browser's
  native install prompt (Chromium `beforeinstallprompt`) when pressed —
  never automatically; otherwise menu instructions (Firefox Android, iPhone /
  iPad Safari, others). Hidden in the installed app, after a confirmed
  install, or once dismissed on that device (a localStorage UI convenience
  with no account or security meaning). It never claims an install it did
  not see.

## First-activation refusal implementation record — no masked-mobile decoy

Implemented 2026-10-04 (FP-ADR-054), after the Production pilot.

**Why.** In the pilot, the National ID of a household member who was NOT the
head was entered on `/family/activate`. As designed (FP-ADR-053), the start
answered with a decoy: a synthetic masked number, presented as "the
registered number of the household head", followed by an OTP step that could
never receive a code. Technically secure, it read as wrong registry data and
led people into a dead end. The product owner decided to refuse instead.

**Behaviour.**

```text
eligible head      POST activation/start → 200 {confirmation, masked_mobile}   (unchanged)
any other input    POST activation/start → 422 {code: ACTIVATION_REFUSED, message}
malformed ID       POST activation/start → 422 validation error                 (unchanged)
```

- **One refusal for every reason** — National ID not found or shared by two
  Persons, not the household head, inactive / deceased / UNKNOWN Person, no
  active membership, inactive or deleted Family, no valid registered mobile,
  a Staff-revoked mobile, an existing account or link: the same status
  (422), code (`ACTIVATION_REFUSED`) and message:
  "تعذّر متابعة التفعيل بهذه البيانات. تأكد من إدخال رقم هوية رب الأسرة المسجل في فامبوك، ثم حاول مرة أخرى."
- **The reason stays server-side:** the same `ELIGIBILITY_DENIED` /
  `ACTIVATION_REQUESTED` / `AMBIGUOUS_IDENTITY` security events with their
  safe reason codes; never in the response.
- **Nothing behind a refusal:** no confirmation, no masked number, no
  decoy, no SMS, no OTP challenge, no pending or trusted mobile row, no
  User, link or identity. The refused start still waits out the response
  floor and counts toward the per-identifier and per-IP start ceilings.
- **At send** (the start was eligible; everything is re-checked): a change
  since the start (eligibility, identifier, the confirmed number) answers
  the same `ACTIVATION_REFUSED`; an SMS ceiling answers `OTP_SEND_LIMIT` —
  never a fake OTP step.
- **Removed:** the decoy masked mobile (`ActivationConfirmations::decoyMask`
  and the `DECOY_MASK` fingerprint context, never stored), decoy
  confirmations and activation decoy challenges. Password reset keeps its
  decoys (FP-ADR-041 / -052) unchanged.
- **Accepted trade-off:** a caller can now learn whether an input can START
  family activation — nothing more: not why, and no Person, Family or
  mobile data. Every other guarantee is unchanged (SELF_OTP trust only after
  a correct code, re-checks at send / verify / complete, Staff-revoked
  numbers excluded, existing TRUSTED reused, password reset and login
  unchanged).

## First self-activation implementation record — SELF_OTP mobile trust

Implemented 2026-10-04 (FP-ADR-053). Approved product decision:

> Imported/current registered mobile is not trusted merely because it exists. For first self-activation, possession may be established by successful OTP verification sent exclusively to that stored mobile. Successful OTP creates Person-specific TRUSTED status with verification method SELF_OTP.

**User confirmation alone is not verification.** Clicking "نعم، أرسل رمز
التحقق" only asks for the code; it creates no trust. Only a correct,
unexpired, unsuperseded code establishes it.

**Flow** (`/family/activate`, four steps and the success screen):

```text
1  POST activation/start {national_id}      → {confirmation, masked_mobile}   no SMS
2  user confirms 05*****123                  "هل هذا رقمك ويمكنك استقبال رمز التحقق عليه؟"
   POST activation/send  {confirmation}      → {challenge, timers}            the code is sent
3  POST activation/verify {challenge, code}  correct code → TRUSTED, SELF_OTP
4  POST activation/complete                  password; account; session
```

- **Who gets a real confirmation:** the existing eligibility (exact
  National ID match, eligible living head, active membership, ACTIVE
  Family, no current link) plus a VALID current registered mobile, trusted
  or not. A number whose trust Staff REVOKED is never self-verified back.
- **Masking:** `05*****` + the last three digits, shown only after the
  eligibility checks admit the flow. The full number is never returned,
  logged or stored in the confirmation.
- **Anti-enumeration kept** — *superseded on 2026-10-04 by FP-ADR-054
  (see the next record); kept here as history:* a denied identifier
  (unknown, ineligible, no valid mobile, revoked trust, ambiguous) got the
  same response with a FAKE mask derived from its keyed fingerprint — stable
  for that identifier — and its send yielded a decoy challenge (no SMS, no
  row). The start and the send wait out the response floor like every other
  step.
- **Destination:** always the Person's stored number, re-checked at send
  (still eligible, same identifier, the very number whose mask was
  confirmed). The API refuses any `mobile` or `phone` field. A confirmation
  is used once and lives 10 minutes.
- **Trust mechanics:** an already TRUSTED current number (a Staff grant,
  e.g. the first pilot's IN_PERSON record) is used as it is — never
  replaced, duplicated or re-labelled. Otherwise the send binds the
  challenge to a PENDING_VERIFICATION row for the current number
  (`MobileTrusts::pendingFor`); pending is not trust. A correct code
  promotes it inside the verify transaction, Person row locked first
  (`MobileTrusts::confirmSelfVerified`): status TRUSTED, method SELF_OTP,
  no Staff verifier, a MOBILE_TRUST_GRANTED event (reason SELF_OTP). A Staff
  grant made meanwhile is kept and the pending row retired; the partial
  unique index keeps one TRUSTED row per Person. A later number change
  makes the trust STALE as for any trust.
- **Unchanged:** the OTP service, challenge, resend, expiry, attempts and
  all ceilings and TweetsMS delivery; password reset (still requires an
  already TRUSTED mobile); login; Staff grant and revoke (a Staff grant can
  never use SELF_OTP); account sides.

## PWA-3B.1 implementation record — «بياناتي الشخصية» read view

Implemented 2026-10-05 (FP-ADR-062, §23a). Read-only; no migration; no
reveal (PWA-3B.2), no edit, no request path.

```text
GET /api/v1/family/self   auth:sanctum → family.side →
                          can:family-portal.access → family.context;
                          no route parameter; Cache-Control: no-store, private
```

- **SELF only.** The Person and membership are the ones the resolver put
  in the Family context (`HouseholdReadModel::self`); query strings or any
  other input are ignored. Coordinator scope never changes the Person.
- **Allow-list** (`FamilySelfResource`): `full_name`,
  `national_id_masked`, `gender`, `birth_date`, `marital_status` (as
  stored: UNKNOWN stays UNKNOWN), `mobile_masked`,
  `alternate_mobile_masked`, `alternate_mobile_owner_relation`,
  `relationship` (`code`, `name`), `is_household_head`,
  `membership_started_at`. NULL stays NULL; the age is derived by the
  client. Never the full National ID or mobiles, `person_code`, ids, notes,
  `is_active`, life status internals, audit, account, trust or auth data.
- **Masks.** National ID: `NationalIdMask` (`*****` + last 4). Mobiles:
  the shared `App\Support\MobileMask` — a valid mobile (FamilyMobile) is
  `05*****` + last 3 (the activation confirmation now uses the same
  helper); a stored value that is not a valid mobile gets no invented `05`
  prefix: `*****` + at most its last 3 characters, never more than half.
- **Frontend:** `/family/account/me` — البيانات الأساسية · بيانات الاتصال ·
  بيانات العضوية, masked values as received, no Eye control; the §23a
  vocabulary (null «غير مسجّل», marital UNKNOWN «غير معروف»; the age row
  and the alternate-mobile owner row are omitted when not applicable).
  Reached from the head's own member card («بياناتي الشخصية» on
  `/family/members`); the full «حسابي» screen stays PWA-3B.5, so the bottom
  navigation entry stays «قريبًا» (until PWA-3B.5: «حسابي» is now a link to
  `/family/account`, current on `/family/account/me` too). Query cache only (cleared on logout);
  the service worker never touches API responses.
- The member list's null birth-date wording («تاريخ الميلاد غير معروف»)
  is corrected with the member detail work of PWA-3B.3.

## PWA-3B.2 implementation record — self sensitive-value reveal

Implemented 2026-10-05 (FP-ADR-062, §23a, docs/06 AUTH-ADR-078). No
migration. The member reveal stays PWA-3B.4 (§33a FU-13).

```text
POST /api/v1/family/self/reveal
  auth:sanctum → family.side → can:family-portal.access → family.context
  → throttle:family-self-reveal; no route parameter
  body      {"field": "NATIONAL_ID" | "MOBILE" | "ALTERNATE_MOBILE"}
  response  {"data": {"field": "…", "value": "…" | null}}
            Cache-Control: no-store, private
```

- **SELF only.** The value is read from the Family context's Person
  (`SelfSensitiveReveal`); the persons column comes from the fixed
  `SelfRevealField` mapping (NATIONAL_ID → `national_id`, MOBILE →
  `mobile`, ALTERNATE_MOBILE → `alternate_mobile`), never from the input.
  `FamilySelfRevealRequest` refuses (422) an unknown field and any
  `person_id`, `person_code`, `family_id`, `family_code`, `user_id`,
  `membership_id`, `national_id`, `mobile`, `alternate_mobile`, `value` or
  `target`, in the body or the query string. Coordinator scope never
  changes the Person. Only the requested value is returned; a value that is
  not recorded is `null`, never fabricated.
- **Throttle** `family-self-reveal`, per authenticated user:
  `family_auth.self_reveal.limits.user_minute` (default 10) and
  `user_hour` (default 60); 429 `TOO_MANY_REQUESTS` with `no-store`.
- **Security event.** Every authorized reveal — also one whose value is
  null — records one `SELF_SENSITIVE_REVEALED` (outcome SUCCESS; person,
  user, actor, link) with `metadata.field` = the field code, BEFORE the
  value is returned. Never the value. `field` is an allow-listed
  `auth_security_events` metadata key. No Family Activity event. Refused
  requests (validation, boundary, throttle) record nothing.
- **Unchanged:** `GET /family/self` stays masked only; `/family/me` and
  the household endpoints carry no sensitive value.
- **Frontend.** «بياناتي الشخصية» gives each recorded sensitive value its
  own Eye control («إظهار / إخفاء رقم الهوية · رقم الجوال · الجوال
  البديل», `aria-pressed`); none for a value that is not recorded. A show
  POSTs only that field code through a plain request — not a query or
  mutation — and keeps the full value in that field's component state
  only, as `<bdi dir="ltr">` text. Hide discards it at once; showing again
  asks again; leaving the screen discards it; a late answer after hide or
  unmount is dropped. Busy state is per field and repeated clicks are
  ignored; a failure keeps the mask and shows a field-level message (429:
  «طلبات إظهار كثيرة. حاول مجددًا بعد قليل.»); 401 / 403 go to the usual
  access flow. No clipboard, no browser storage, no attribute.

## PWA-3B.3 implementation record — complete family, declaration, residence and member read views

Implemented 2026-10-05 (FP-ADR-062, §23a). Read-only; no migration; no
new endpoint. The member reveal stays PWA-3B.4 (§33a FU-13).

```text
GET /api/v1/family/household/profile   «أسرتي»: family · declaration · residence
GET /api/v1/family/household/members   one row per ACTIVE membership
GET /api/v1/family/household           the dashboard summary (unchanged shape)
  all three: family.context; Cache-Control: no-store, private
```

- **Family** (`data.family`): `family_code`, `clan_name`,
  `branch_group_name` (NULL when the branch has no named group),
  `branch_name`, `head.full_name`, `registration_date`, `paper_form_no`,
  `registered_member_count` (ACTIVE memberships — unchanged semantics).
- **Declaration** (`data.declaration`, NULL when there is no current
  declaration): `declared_household_size`, `declared_living_sons`,
  `declared_living_daughters`, `declared_at`, `source`. The current row
  only; values as stored (NULL «غير مُعلن», 0 is 0); nothing derived from,
  or compared with, the registered members. The former
  `data.family.declared_household_size` / `declared_at` of the profile moved
  here; the dashboard summary keeps its own two fields.
- **Residence** (`data.residence`, NULL when none): adds `residence_type`,
  `started_at` and `current_address.address_text` to the PWA-3A fields.
  Never coordinates, source, notes or lifecycle flags; no history.
- **Members**: each row adds `marital_status`, `death_date`,
  `national_id_masked`, `mobile_masked`, `alternate_mobile_masked`,
  `alternate_mobile_owner_relation` and `membership_started_at` (a
  membership fact, kept on a placeholder row). Sensitive values MASKED only
  (`NationalIdMask`, `MobileMask`); never `person_code`, ids,
  `paper_sequence_no`, notes or audit data. A soft-deleted Person stays a
  placeholder with every Person field NULL. Deceased ACTIVE members stay.
  All selects are explicit; the query count stays constant (tested).
- **Frontend.** «أسرتي»: بيانات الأسرة · الإقرار الأسري الحالي (or «لا يوجد
  إقرار مسجّل») · السكن (السكن الأصلي · حالة النزوح · عنوان السكن الحالي) ·
  أفراد الأسرة. Declaration sources use the approved family-facing labels
  (`lib/utils/family-portal-labels.ts`); a null displacement status is now
  «غير مسجّل». «أفراد الأسرة»: each available member's card opens an
  in-page detail sheet («تفاصيل الفرد») fed by the list — no identifier,
  URL or extra request — with البيانات الأساسية · بيانات الهوية والاتصال ·
  بيانات العضوية; masked values only, no reveal control; DECEASED shows the
  death date or «تاريخ الوفاة غير معروف». No details for an unavailable
  member. The head keeps «بياناتي الشخصية» (self reveal stays there only).
- **Vocabulary fix done:** a null birth date reads «تاريخ الميلاد غير
  مسجّل» (no longer «غير معروف»).

## FU-13 implementation record — safe household-member reference

Implemented 2026-10-05 (FP-ADR-063). No migration; no new endpoint; no
reveal (PWA-3B.4).

- `App\Support\FamilyPortal\HouseholdMemberReference`:
  `of(familyId, membershipId)` and `resolve(FamilyAccessResult, ref)`;
  `FingerprintContext::MEMBER_REF = famboook.family-portal.member-ref.v1:`.
- `GET /api/v1/family/household/members`: every row starts with
  `member_ref`, placeholders included, computed from the already-selected
  membership `id` / `family_id` — no extra query (the query-count test is
  unchanged).
- Frontend: `FamilyMember.member_ref`; the list is keyed by it and the
  detail sheet is chosen by it (the member read from the loaded list). It
  is never put in the DOM, a URL or a query key.

## PWA-3B.4 implementation record — household-member sensitive reveal

Implemented 2026-10-05 (FP-ADR-064). No migration; no registry write.

```text
POST /api/v1/family/household/members/{memberRef}/reveal
  auth:sanctum → family.side → can:family-portal.access → family.context
  → throttle:family-member-reveal; memberRef is a plain string
  body      {"field": "NATIONAL_ID" | "MOBILE" | "ALTERNATE_MOBILE"}
  200       {"data": {"field": "…", "value": "…" | null}}   no-store, private
  404       {"message": "بيانات هذا الفرد غير متاحة.", "code": "HOUSEHOLD_MEMBER_UNAVAILABLE"}
```

- `HouseholdMemberSensitiveReveal` resolves the target (FU-13 resolver →
  not the head's membership → Person not soft-deleted), records the event,
  then returns the value; `HouseholdMemberUnavailableException` is the one
  404 for every failure; `FamilyMemberRevealRequest` validates the field
  and the prohibited keys; `FamilyMemberRevealController` answers no-store,
  private.
- Throttle `family-member-reveal`: `family_auth.member_reveal.limits`
  `user_minute` (default 20) and `user_hour` (default 120), env
  `FAMILY_MEMBER_REVEAL_LIMIT_USER_MINUTE` / `_HOUR` (examples only).
- Security event `HOUSEHOLD_MEMBER_SENSITIVE_REVEALED` within the existing
  `auth_security_events` constraints (DB-ADR-056).
- Frontend: `SensitiveValue` takes a `reveal` callback (the self reveal on
  «بياناتي الشخصية», `revealMemberValue(member_ref, field)` in the member
  sheet). Another member's recorded values get their own Eye; the head's
  row shows masks and a link to «بياناتي الشخصية». Field controls are keyed
  by member_ref and the sheet's details by member, so closing the sheet or
  switching member discards every revealed value; late answers after hide,
  close, switch or unmount are dropped. Plain request only — never the
  query or mutation cache, storage, a URL, an attribute or the clipboard.

## FU-14 implementation record — password reset Production readiness

Implemented 2026-10-06. No migration. Password reset itself is the PWA-1G /
PWA-1I implementation above and is **not changed**: the four routes, the
`PASSWORD_RESET` purpose, decoys, the response floor, the TRUSTED current
mobile, the server-side grant (`verified_at` / `grant_expires_at`, 600 s,
consumed once), the password policy, session revocation, security events,
limiters and SMS ceilings. `FAMILY_PASSWORD_RESET_ENABLED` stays `false`;
enabling it is a separate decision (docs/08 §16a checklist).

**Decisions preserved (confirmed for FU-14).**

- A successful reset ends every earlier session and signs the completing
  browser in on a new session, which continues to `/family` (FP-ADR-045).
  There is no return-to-login step.
- Reset requires the same Family context as login, household head
  included. An account that lost headship gets the ordinary decoy; there
  is no self-service reset for it.
- The reset never returns or shows a masked mobile: every well-formed
  National ID gets the same start answer.
- Reset never creates, confirms, revives or overrides a mobile trust. A
  changed, STALE, revoked or missing current mobile means a decoy until
  Staff establish trust (an operational process; no Staff screen yet).
- No `tokens()->delete()`: no Personal Access Token is ever issued, and
  `FamilySessions::revoke` is unchanged.

**Backend.**

- `GET /api/v1/family/auth/capabilities`
  (`FamilyAuthCapabilitiesController`, route `family.auth.capabilities`):
  public, no authentication, outside the three Family Auth gates, no
  input, no account lookup, no database read. `200 {"data": {"activation",
  "login", "password_reset"}}` — each exactly what the matching gate
  middleware reads (`=== true`), `Cache-Control: no-store, private`. No
  throttle, SMS, provider, environment or account information. Records no
  event.

**Frontend.**

- `useFamilyAuthCapabilitiesQuery()` / `usePasswordResetAvailability()` in
  `lib/api/family-auth.ts`: runtime, not `NEXT_PUBLIC_*`; no refetch on
  focus; a surface is open only on an explicit server `true`.
- `/family/login`: «نسيت كلمة المرور؟» appears only when
  `password_reset === true` — hidden while loading and after any failure.
  Login and the activation link never depend on the request.
- `/family/forgot-password`: loading shows the restrained spinner and no
  form; off, error or no connection show one notice («استعادة كلمة المرور
  غير متاحة حاليًا. يمكنك تسجيل الدخول أو مراجعة الإدارة.») with «العودة
  إلى تسجيل الدخول», never a reason. `true` renders the unchanged flow,
  which then stays mounted (the cache cleared at sign-in does not tear it
  down); the server still gates every step.

**Tests.** `FamilyAuthCapabilitiesTest` (every flag combination, strict
`true`, no-store / private, no authentication, outside the gates, no
input, fields only). `FamilyPasswordResetJourneyTest`, on accounts made by
the real first self-activation (TRUSTED, SELF_OTP): activation → logout →
reset → old password refused, new password accepted, completing browser
signed in, other sessions ended; FAMILY_USER + COORDINATOR keeps both
roles, its scope assignments and Coordinator Space; a Staff account beside
a matching Person, and a Staff-only account tied to the matching identity,
get decoys (no SMS, no Staff password change, no role, link, identity or
trust created); a Staff mobile change makes the trust STALE and reset a
decoy, changing back revives nothing and sends nothing to the old number;
a Staff-revoked SELF_OTP trust stays revoked; a change between verify and
complete stops it; persons, families and family_memberships rows are
byte-identical and no Family Activity is written. Frontend: login link and
forgot-password route, loading / false / error / no connection / true.

## PWA-3B.5 implementation record — «حسابي» account view

Implemented 2026-10-06 (FP-ADR-067). No migration; no role, permission,
activation, login, password reset (FU-14), Staff mobile trust (FU-15) or
SMS change.

- **Backend:** `GET /api/v1/family/account` (`FamilyAccountController`,
  `FamilyAccountResource`) behind `auth:sanctum`, `family.side`,
  `can:family-portal.access`, `family.context`; no parameter, nothing read
  from the request; `Cache-Control: no-store, private`; no write and no
  security event. Allow-list: `activated_at` (the current User-Person
  Link's `activated_at`, ISO 8601 or null) and `mobile` `{state, masked}` —
  `state` from `CurrentTrustedMobile` (TRUSTED, STALE, REVOKED,
  UNVERIFIED, NO_MOBILE, UNAVAILABLE), `masked` from the Family
  `MobileMask` (the value «بياناتي الشخصية» shows). `/family/me` is
  unchanged. UNAVAILABLE cannot normally reach the page: without a usable
  Family Auth key `family.context` already refuses the identity.
- **Reachable states:** a deactivated account is 401
  (`EnsureUserIsActive`); a Staff or mixed account is 403 (`family.side`);
  without a Family context the shell shows its neutral notice instead of
  the page. «الحساب مفعّل» is therefore the only account state the page
  can show; no suspended / inactive state is invented.
- **Frontend `/family/account`:** «حسابي» — the signed-in name, «الحساب
  مفعّل», «تاريخ التفعيل» (null «غير مسجّل»), «رمز الأسرة»; «نوع الدخول»
  («رب الأسرة», plus «صلاحية التنسيق» for COORDINATOR — never a role or
  permission string); «توثيق رقم الجوال» (owner labels TRUSTED «موثّق»,
  STALE «يحتاج إعادة توثيق», REVOKED «غير موثّق حاليًا», UNVERIFIED «غير
  موثّق», NO_MOBILE «لا يوجد رقم جوال صالح», UNAVAILABLE «تعذّر عرض حالة
  التوثيق حاليًا»; the masked number; that the verified number receives
  verification codes, including account recovery; «يُرجى مراجعة إدارة
  السجل» where the registry must act; no history, reason, verifier,
  control, reveal or edit); «مساحة التنسيق» for COORDINATOR only — the
  effective scopes (`scopeLabel`) and family count from `GET
  /family/coordinator/context` and «فتح مساحة التنسيق» when
  `coordinator_space` is true, otherwise «لا يوجد نطاق تنسيق فعّال
  حاليًا» with no entry; the «بياناتي الشخصية» entry (`/family/account/me`);
  «تسجيل الخروج».
- **Password:** no «نسيت / إعادة تعيين كلمة المرور» action and no link to
  `/family/forgot-password` from «حسابي»; Account Recovery stays on the
  login screen, unchanged. No disabled or placeholder «تغيير كلمة المرور»
  control: authenticated change password is FU-16.
- **Logout:** one shared `useFamilySignOut` for the header and «حسابي» —
  `POST /api/v1/family/auth/logout`, the query cache cleared, `/family/me`
  set to null, `/family/login`. Behavior unchanged.
- **Navigation:** «حسابي» is a link to `/family/account`, current on
  `/family/account` and `/family/account/*`; «طلباتي» and «+» stay
  disabled. Shell and width unchanged.
- **Tests:** backend `FamilyAccountTest` (allow-list, cache headers, mask
  parity with /family/self, every state including REVOKED then a new Staff
  grant, no internals, no write, no target, coordinator payload without
  scope, family.side, lost context, deactivated account); the
  `FamilySideBoundaryTest` route inventory. Frontend
  `family-account.test.tsx` (header, null wording, access words, owner
  labels, no «ملغى», no history or controls, no reset link or password
  control, coordinator open / closed / 403, logout, navigation, leak and
  storage checks); the navigation assertions of the gate, home, household
  and members tests.

## PWA-3B.6 implementation record — household health read visibility

Implemented 2026-10-06 (FP-ADR-068). No migration; no role, permission,
authentication, SMS, FU-14, FU-15 or PWA-3B.5 change; no Staff health
change.

- **Canonical model (unchanged):** `person_health_records` (DISABILITY,
  CHRONIC_DISEASE, PREGNANCY, BREASTFEEDING; DISABILITY →
  `disability_types`, CHRONIC_DISEASE → `condition_name`) is the only
  health source; no flags on persons or families, no import or assessment
  representation. A record is active while `ended_at` IS NULL; closed
  records are history. There are no negative ("none") records.
- **Backend:** `GET /api/v1/family/household/health`
  (`FamilyHouseholdController@health`, `HouseholdReadModel::health`,
  `FamilyHouseholdHealthResource`) behind `auth:sanctum`, `family.side`,
  `can:family-portal.access`, `family.context`; no parameter; `no-store,
  private`; no write, activity or security event. `{data: {members:
  [{member_ref, records: [{type, disability_type: {code, name} | null,
  condition_name, started_at, ended_at, is_active}]}]}}` — only members
  with at least one record. Population: the resolved Family's ACTIVE
  memberships — the head included, DECEASED members included, soft-deleted
  Persons and ended memberships excluded; health is Person-based, so a
  member's earlier records stay theirs. Order: active first; DISABILITY,
  CHRONIC_DISEASE, PREGNANCY, BREASTFEEDING; started_at newest first,
  unknown last; then the internal id (never serialized). A deactivated
  disability type is still named. The Staff `HealthRecordResource` is not
  reused.
- **Member sheet:** «الحالة الصحية» after «البيانات الأساسية», read only by
  the member's `member_ref` from the household query (`["family",
  "household", "health"]`, staleTime 0, query cache only). Sub-groups as
  applicable: «الإعاقة» («إعاقة {type}»), «الأمراض المزمنة» (the registered
  name), «الحمل والرضاعة» («حمل» / «رضاعة»); per record «حالية» /
  «منتهية», «تاريخ البداية» (or «غير مسجّل»), «تاريخ الانتهاء» for ended
  records only; the note «هذه هي البيانات الصحية المسجّلة حاليًا في سجل
  الأسرة.»; no record: «لا توجد بيانات صحية مسجّلة لهذا الفرد.» — never
  «سليم», «لا توجد أمراض» or «لا توجد إعاقة». Its own skeleton and error
  («تعذّر تحميل البيانات الصحية» with retry): the member's other data stays.
  No edit, add, close, delete, reveal or request control.
- **Discovery:** the member card shows the neutral chip «بيانات صحية مسجّلة»
  when the member has records of any status, nothing otherwise; «أسرتي»
  shows «بيانات صحية مسجّلة لـ N من الأفراد» inside the members card (which
  links to the list) when N > 0, nothing while loading, on a failure or for
  0. The Staff `FamilyHealthSummary` is not used.
- **Tests:** backend `FamilyHouseholdHealthTest` (head and members,
  another household, coordinator scope, account sides, guest, lost context,
  `family-portal.access`, deceased included, soft-deleted and ended
  excluded, several disabilities and chronic diseases, pregnancy and
  breastfeeding, closed history, null start, deactivated type, order, empty
  household, allow-list, no details / ids / uuid / person_code / Staff
  fields, no writes or events, cache headers, route without parameters);
  the `FamilySideBoundaryTest` inventory. Frontend `family-health.test.tsx`
  and the member-detail groups / requests assertions.

## PWA-3B.7 implementation record — needs and received assistance

Implemented 2026-10-07 (FP-ADR-069). No migration; no role, permission,
authentication, SMS, FU-14, FU-15 or PWA-3B.1–3B.6 change; no Staff need
or assistance change.

- **Canonical sources (unchanged):** `family_needs` + `need_categories`; a
  Need always belongs to a Family and optionally one Person of it (NULL =
  family-wide); statuses OPEN → FULFILLED | CLOSED, resolved needs are
  history, nothing is deleted. Assistance: `assistances` (program,
  category, type, provider_name), `assistance_items` (the package, locked
  once the Assistance leaves DRAFT), `assistance_beneficiaries`
  (nominations — never a receipt) and `assistance_deliveries` (the
  receipt; reversal adds `reversed_*`, rows are never deleted). Needs and
  assistance are independent: `FulfillNeedAction` creates no delivery and
  `RecordDeliveryAction` never changes a need.
- **`GET /api/v1/family/household/needs`:** `{data: {needs: [{category:
  {code, name}, title, quantity, unit, status, created_at, resolved_at,
  person}]}}` — every need of the context Family; order OPEN first, then
  created_at newest first, then the internal id (never serialized);
  quantity a trimmed decimal.
- **`GET /api/v1/family/household/assistance`:** `{data: {deliveries:
  [{delivered_at, assistance: {title, category: {code, name}, type,
  provider_name, items: [{item_name, quantity, unit, unit_value,
  currency}]}, beneficiary, receipt_mode, recipient: {full_name,
  available}}]}}` — only `assistance_deliveries` with `reversed_at IS
  NULL`, through a beneficiary of the context Family, of an INTERNAL
  Assistance; never filtered by the program's status; items in sort_order
  as the delivered package (docs/03 §47e); order delivered_at newest first,
  then the internal id. No pagination in V1 (object envelope kept for
  later meta).
- **Persons on historical records:** `person` / `beneficiary` null = the
  whole family; otherwise `{member_ref | null, full_name | null,
  available}` — member_ref only while the Person still has an ACTIVE
  membership in the Family; a former or deceased Person keeps the name; a
  soft-deleted Person is `available: false` with no name. History never
  moves on a head change, a member leaving or dying.
- **Both endpoints:** `auth:sanctum`, `family.side`,
  `can:family-portal.access`, `family.context`; no parameter; `no-store,
  private`; no write, activity or security event; coordinator scope never
  widens them. Family-specific allow-lists; the Staff `NeedResource` and
  assistance payloads are not reused.
- **Frontend `/family/household/support` «الاحتياجات والمساعدات»:** reached
  from an entry card on «أسرتي» (no counts); «أسرتي» stays the current
  navigation entry; the note «يعرض هذا القسم ما هو مسجّل في Famboook فقط.».
  «الاحتياجات المسجّلة»: «قائمة» (OPEN) and «منتهية» (FULFILLED / CLOSED);
  per need the title, status («قائم» / «تمت تلبيته» / «مغلق»), category,
  quantity and unit (or «غير مسجّل»), «لمن» («الأسرة», the name, or
  «بيانات هذا الفرد غير متاحة حاليًا»), «تاريخ التسجيل» and «تاريخ التلبية»
  / «تاريخ الإغلاق»; the note «تُحدَّث حالة الاحتياج من إدارة السجل، وهي
  منفصلة عن سجل المساعدات المستلمة.»; empty «لا توجد احتياجات مسجّلة.».
  «المساعدات المستلمة»: newest first; the title, category, type («عينية» /
  «نقدية» / «خدمة»), «الجهة المقدّمة», «تاريخ الاستلام», «المستفيد», «استلمها»
  with «شخصيًا» / «بالنيابة», the items «{item} — {quantity} {unit}» and
  «قيمة الوحدة: {value} {currency}» (شيكل / دولار / دينار / يورو), «لا توجد
  تفاصيل عناصر مسجّلة»; empty «لا توجد مساعدات مستلمة مسجّلة.». Each section
  has its own query (`["family", "household", "needs" | "assistance"]`,
  staleTime 0), skeleton and error with retry. No add, edit, resolve,
  reverse or request control.
- **Tests:** backend `FamilyHouseholdNeedsTest` and
  `FamilyHouseholdAssistanceTest` (including the real delivery and reversal
  actions), the `FamilySideBoundaryTest` inventory; frontend
  `family-support.test.tsx` and the «أسرتي» heading list.

## PWA-8.2 implementation record — Digital Family Card

Implemented 2026-10-07 (FP-ADR-070). One additive migration
(`digital_credentials`); no Person credential, no assistance, Family Auth,
OTP or SMS change; no PDF (PWA-8.3); no Staff open-record or card-number
search (PWA-8.4).

- The card's subject is the **Family**: a household-head change never
  revokes, rotates, reissues or renumbers it; the current head is shown live.
- Three distinct things: `family_code` (registry identifier, not a secret),
  the card number `FC-XXXX-XXXX-XX` (random, immutable for the row) and the
  opaque QR token (256-bit, never derived, stored only as a SHA-256 lookup
  hash plus a Crypt-sealed copy decrypted only for the owner's QR).
- Lifecycle ACTIVE → REVOKED only; reissue = revoke (REISSUED) + a NEW row
  with a new number and token; no token-only rotation; rows never deleted.
- Verification needs an ACTIVE credential of an ACTIVE, not deleted Family,
  read live: an INACTIVE / ARCHIVED / deleted Family fails without the
  credential changing, and verifies again once ACTIVE unless revoked.
- A digital verification credential inside Famboook — never an official
  identity document; a QR proves the card, not the presenter.
- **Schema:** `digital_credentials` — subject_type, family_id (FK,
  restrict), credential_number (unique), token_hash (char 64, unique),
  token_encrypted, token_version, status, issued_at / issued_by (NULL =
  system), revoked_at / revoked_by / revoke_reason; one ACTIVE per Family
  (partial unique index); PostgreSQL CHECKs for subject, status, revocation,
  number and hash formats. Model guard: only ACTIVE → REVOKED once; never
  deleted.
- **Actions:** `IssueFamilyCredentialAction` (families row lock, ACTIVE
  Family, returnExisting for lazy issuance), `RevokeFamilyCredentialAction`
  (ADMINISTRATIVE / COMPROMISED only), `ReissueFamilyCredentialAction`
  (revoke REISSUED + new); activity FAMILY_CARD_ISSUED (issue_channel),
  FAMILY_CARD_REVOKED (revoke_reason), FAMILY_CARD_REISSUED — controlled
  codes only, never a token or number; visible with family-card.view.
- **API:** public `POST /api/v1/credentials/verify` (throttle per IP 30 /
  minute, 300 / hour; generic 404 / 429; no-store); `POST
  /api/v1/family/card` (family.context, idempotent ensure, QR as an SVG data
  URI — chillerlan/php-qrcode — no-store, private; the only decrypting
  path); Staff `GET|POST /api/v1/families/{family}/card`, `…/revoke`,
  `…/reissue` (family-card.view / issue / revoke / reissue; never a token or
  QR).
- **Frontend:** `/verify/[token]` (public; headers no-store, noindex,
  no-referrer), `/family/card` and its home entry, the Staff overview panel
  «بطاقة الأسرة الرقمية».
- **Tests:** backend `DigitalCredentialTest`, `PublicCredentialVerifyTest`,
  `FamilyCardTest`, `StaffFamilyCardTest`, the route inventories and the
  permission seeder counts; frontend `credential-verification`,
  `family-card` and `family-card-panel` tests.

## PWA-8.3 implementation record — Digital Family Card PDF

Implemented 2026-10-07 (FP-ADR-071). No migration; no credential lifecycle, Staff,
Family Auth, OTP or SMS change; no Staff PDF (PWA-8.4); no wallet-size
layout (deferred).

- **Endpoint:** `GET /api/v1/family/card/pdf` (`FamilyCardPdfController`)
  behind `auth:sanctum`, `family.side`, `can:family-portal.access`,
  `family.context`, `throttle:family-card-pdf` (5 / minute per user). 200
  `application/pdf` with `Content-Disposition: attachment;
  filename="famboook-family-card-{credential_number}.pdf"`,
  `Cache-Control: no-store, private`, `X-Content-Type-Options: nosniff`;
  404 `CARD_NOT_ISSUED`; 409 `CARD_QR_UNAVAILABLE`; boundary failures as
  every Family route.
- **Rendering:** `FamilyCardView` (the shared owner view; the JSON card
  endpoint now uses it with an identical contract), `FamilyCardPdf` (mPDF:
  UTF-8, RTL, `default_font` IBM Plex Sans Arabic with `useOTL` 0xFF and
  `useKashida` 75, DejaVu Sans Condensed substitution, stream wrappers
  limited to `file`, no debug), the Blade view `pdf.family-card` (tables
  only; identifiers in `<bdo dir="ltr">`), `QrCodes::pngDataUri()`, the
  PDF-safe logo copy `resources/pdf/famboook-logo.svg`.
- **Assets:** `backend/resources/fonts/ibm-plex-sans-arabic/`
  (`IBMPlexSansArabic-Regular.ttf`, `IBMPlexSansArabic-SemiBold.ttf`,
  `OFL.txt`, `SOURCE.md` with the official release and checksums).
- **Frontend:** «تنزيل البطاقة PDF» on `/family/card` only, shown only with a
  QR; a GET Blob (never the query cache or browser storage), a temporary
  object URL revoked after the click; busy / disabled state; inline
  failures; a 404 reloads the card. No control on `/verify`.
- **Tests:** backend `FamilyCardPdfTest`, `FamilyCardPdfViewTest`, the route
  inventory; frontend `family-card` and `credential-verification` tests.
  A manual Arabic rendering check (devices, viewers, printing, QR scan)
  is part of the Production verification (docs/08).

## PWA-5a implementation record — Change Request foundation

Implemented 2026-10-07 (FP-ADR-072; docs/05 WF-ADR-049, docs/04 DB-ADR-058,
docs/06 AUTH-ADR-087). Foundation only: no controller, route, UI, Domain
Action or request type; Family Auth, PWA-3B and the Digital Card unchanged.

- **Schema:** `change_requests` and `workflow_events` (two additive
  migrations); PostgreSQL CHECKs per status, the `change_request_code_seq`
  sequence and the append-only trigger on `workflow_events`.
- **Code:** `ChangeRequest` and `WorkflowEvent` models (proposal immutable
  after submission, finished requests immutable, never deleted; events
  never updated or deleted); enums `ChangeRequestStatus`,
  `ChangeRequestType`, `ChangeRequestRejectionReason`, `WorkflowEventType`,
  `WorkflowActorSide`; `ChangeRequestTransitions` (the one state machine),
  `WorkflowEventRecorder` (the only event writer), `ChangeRequestCodes`;
  `FingerprintContext::CHANGE_REQUEST_BASE` on the existing Family Auth key.
- **Permissions:** `change-request.cancel` (FAMILY_USER); the Staff review
  set for SUPER_ADMIN, ADMINISTRATOR and REVIEWER (AUTH-ADR-087).
- **Tests:** transitions, enums, model, recorder and permissions on every
  driver; CHECKs, sequence, indexes and the trigger on PostgreSQL.

## PWA-5b implementation record — Change Request engine

Implemented 2026-10-07 (docs/05 WF-ADR-050, docs/04 DB-ADR-059, docs/06
AUTH-ADR-088, docs/07 RM-ADR-056). No migration, controller, route, UI or
request type; the Family Portal is unchanged («+» and «طلباتي» stay disabled).

- **Domain Actions** (`app/Actions/ChangeRequests`): Submit, StartReview,
  ReturnForClarification, Resubmit, Approve, Reject, Cancel, Apply — each
  returns a ChangeRequestOutcome (`replayed` when nothing new was written).
- **Family side:** Submit, Resubmit and Cancel take the trusted
  FamilyAccessResult; the Family, the submitting User and Person come only
  from it; members are named by member_ref and resolved inside that Family;
  a request of another Family does not exist for the caller
  (CHANGE_REQUEST_NOT_FOUND).
- **Handler contract** (`ChangeRequestHandler`): familySubmittable,
  payloadVersion, inputRules / dataRules, resolveTarget, normalize,
  baseValues, preconditions, apply (canonical Domain Actions only),
  present (FAMILY / STAFF audience), profileSections (the PWA-4 PENDING
  mapping: FAMILY, HEAD, MEMBERS, RESIDENCE) and openConflictKey.
  `ChangeRequestTypes` is the one type → handler registry — EMPTY in
  Production; `ChangeRequestTypes::fake()` works only in tests.
- **Submission:** input bounded (16 KB, 4 levels), client_reference replay
  (same material → the existing request; different → refused), open
  conflicts refused under the Family lock, base fingerprint on the existing
  key (FingerprintContext::CHANGE_REQUEST_BASE).
- **Activity:** CHANGE_REQUEST_SUBMITTED, CHANGE_REQUEST_REJECTED,
  CHANGE_REQUEST_APPLIED (metadata: request_type only), visible only with
  change-request.view. The Staff activity timeline needs labels for them
  before any request can exist in Production (PWA-5d).
- **Tests:** the engine through a test-only handler (submission, review loop,
  approval, rejection incl. the refused-apply rule, apply success / rollback /
  retry / replay, security and privacy, handler boundary) on SQLite, and the
  row-lock races on PostgreSQL.

## PWA-5c implementation record — Staff Change Request API

Implemented 2026-10-08 (docs/06 AUTH-ADR-089, docs/05 WF-ADR-051, docs/07
RM-ADR-057). Staff side only — no Family endpoint, no UI, no request type,
no migration; the Family Portal is unchanged.

| Route (`/api/v1`, `auth:sanctum` + `staff.side`) | Permission | Domain Action |
|---|---|---|
| `GET /change-requests` | change-request.view | — (queue) |
| `GET /change-requests/{uuid}` | change-request.view | — (review view) |
| `POST /change-requests/{uuid}/start-review` | change-request.review | StartChangeRequestReviewAction |
| `POST /change-requests/{uuid}/return` | change-request.return | ReturnChangeRequestForClarificationAction |
| `POST /change-requests/{uuid}/approve` | change-request.approve | ApproveChangeRequestAction |
| `POST /change-requests/{uuid}/reject` | change-request.reject | RejectChangeRequestAction |
| `POST /change-requests/{uuid}/apply` | change-request.apply | ApplyChangeRequestAction (no outer transaction) |

- **Queue:** filters `status`, `type`, `family` (family_code), `request_code`
  (CRQ-…), `submitted_from` / `submitted_to` (Y-m-d), `per_page` ≤ 50;
  newest submission first, then id; rows carry identity, family code and
  head name, requester name, milestones, apply_failure_count,
  type_available and available_actions — never the proposal, the reason or
  a message. No per-row queries.
- **Review view:** the proposal only through the type handler's STAFF
  presentation (`presentation`), the requester's reason, review milestones,
  rejection, apply-failure summary and the chronological timeline (event,
  statuses, actor name, actor side, reason code, public_message;
  internal_note only with change-request.view-internal-notes). Without a
  registered handler: `type_available: false`, `presentation: null`.
  Never: submitted_data raw, base fingerprint / key version, client
  reference, internal ids, event metadata.
- **Mutations:** bodies `return {public_message, internal_note?}`, `reject
  {rejection_reason_code, public_message? (required for OTHER),
  internal_note?}` — text validated as stored (control and bidi characters
  removed, ≤ 2000, blank = missing). Response `{id, request_code, status,
  replayed}`; every response `Cache-Control: no-store, private`.
- **Errors** (`{message, code}`): 401 guest or deactivated; 403 wrong side,
  missing permission or ACTOR_NOT_ALLOWED; 404 unknown / malformed uuid or
  NOT_FOUND; 422 validation, TYPE_UNAVAILABLE, PRECONDITION_FAILED,
  NOT_APPLICABLE; 409 INVALID_TRANSITION, BASE_CHANGED, ALREADY_OPEN,
  IDEMPOTENCY_CONFLICT; 500 APPLY_FAILED (generic message only).
- **Before PWA-6.1:** the Staff activity timeline needs labels for the three
  CHANGE_REQUEST_* activity types (PWA-5d).

## PWA-5d implementation record — Staff Change Request review workspace

Implemented 2026-10-08 (docs/05 WF-ADR-052, docs/06 AUTH-ADR-090, docs/07
RM-ADR-058). Staff application only; no backend change, migration or
request type; the Family Portal is unchanged.

- **Screens:** `/change-requests` — «طلبات تحديث البيانات» («مراجعة طلبات
  الأسر ومتابعة إجراءات اعتمادها وتطبيقها.»): search (a full CRQ code →
  `request_code`, otherwise `family`), status, type and submission-date
  filters, all in the URL; server pagination (20); rows with request code,
  type, family code and head, requester, submission date, status and the
  latest milestone. No global counts (the API has none) — only `meta.total`
  of the current filters. `/change-requests/{uuid}` — identity, family and
  requester, the requester's reason, the proposal, the timeline, review
  milestones, rejection, apply failures and the actions.
- **Statuses:** مقدّم, قيد المراجعة, مُعاد للاستكمال, أُعيد تقديمه, معتمد بانتظار
  التطبيق, مطبّق, مرفوض, ملغى (مسودة unused); APPROVED never reads as complete.
- **Proposal:** only a handler-labelled `{rows: [{label, current,
  proposed}]}` presentation is rendered — «البيانات الحالية | التعديل
  المطلوب» on wide screens, stacked on phones, changes marked by text; any
  other shape shows «عرض تفاصيل هذا النوع من الطلبات غير مدعوم في هذه
  الواجهة بعد.», and an unregistered type «تفاصيل هذا النوع من الطلبات غير
  متاحة حاليًا.» — never raw data. This shape is a PWA-6.1 contract proposal.
- **Timeline:** server order; event label, time, actor and side, status
  change, reason (rejection reason or apply-failure label), the family-visible
  message, and the internal note only when the API returned it.
- **Actions:** `available_actions` ∩ permissions; start review (direct), return
  (public message required, internal note only with view-internal-notes),
  approve (confirmation: «اعتماد الطلب لا يغيّر بيانات السجل مباشرة. يجب تطبيق
  التعديل بعد الاعتماد.»), reject (reason; message required for OTHER; an
  approved request only as NO_LONGER_APPLICABLE), apply (explicit confirmation;
  «إعادة محاولة التطبيق» after a failure). Mutations never retry; success and
  failure re-read the request and the queue; a successful apply also refreshes
  the Family's data. Refusals show the server's fixed message; an unexpected
  apply failure a generic one.
- **Activity labels:** CHANGE_REQUEST_SUBMITTED «قدّمت الأسرة طلب تحديث
  بيانات», CHANGE_REQUEST_REJECTED «رُفض طلب تحديث بيانات»,
  CHANGE_REQUEST_APPLIED «طُبّق طلب تحديث بيانات على سجل الأسرة», with the
  request type as the subject; every timeline uses a safe fallback for an
  unknown code.
- **Tests:** `tests/change-requests.test.tsx`,
  `tests/change-request-activity.test.tsx`.

## PWA-5e implementation record — Family Change Request API

Implemented 2026-10-08 (FP-ADR-073; docs/06 AUTH-ADR-091, docs/07
RM-ADR-059). Backend only — no migration, frontend or request type; the
Production registry stays empty and the submission switch is off.

| Route (`/api/v1/family`, `auth:sanctum` + `family.side` + `family-portal.access` + `family.context`) | Extra | Domain Action |
|---|---|---|
| `GET /change-requests?status=&type=&per_page=` | — | — (history, ≤ 50 per page, newest first) |
| `GET /change-requests/types` | — | — (discovery) |
| `GET /change-requests/{uuid}` | — | — (family view) |
| `POST /change-requests` `{type, client_reference, reason?, data}` | change-request.submit, throttle family-change-request-submit (3 / minute, 20 / hour) | SubmitChangeRequestAction (201; replay 200) |
| `POST /change-requests/{uuid}/resubmit` `{response}` | change-request.resubmit, throttle family-change-request-action (10 / minute) | ResubmitChangeRequestAction |
| `POST /change-requests/{uuid}/cancel` | change-request.cancel, same throttle | CancelChangeRequestAction |

- **Outcome:** `{id, request_code, status, replayed}`; every response
  `Cache-Control: no-store, private`.
- **Errors:** 401 guest / deactivated; 403 wrong side, no family context or
  missing permission; 404 unknown or another Family's uuid
  (CHANGE_REQUEST_NOT_FOUND), unknown member; 409 INVALID_TRANSITION,
  ALREADY_OPEN, IDEMPOTENCY_CONFLICT; 422 validation, TYPE_UNAVAILABLE,
  PRECONDITION_FAILED; 429 TOO_MANY_REQUESTS; 503 SUBMISSION_DISABLED.
- **Available actions:** `resubmit` / `cancel`, from the transition table
  and the account's permissions — a hint only.
- **Type labels:** the API returns codes; Arabic wording stays in the
  client (as for the Staff workspace).

## PWA-5f implementation record — Family request views

Implemented 2026-10-08 on the PWA-5e Family API (FP-ADR-073). Frontend
only — no backend change, migration or request type. The views are built
but NOT linked: «+», «طلباتي» and the quick actions stay disabled until
PWA-6.1 (FP-ADR-072; docs/07 RM-ADR-055).

| Route | Screen |
|---|---|
| `/family/requests` | «طلباتي» — the whole Family's history (Family-subject); status and type filters and the page in the URL; server pagination |
| `/family/requests/{uuid}` | Request detail — status and its meaning, request code, reason, the proposal, the family-visible timeline, the action required, the outcome |
| `/family/requests/new` | «طلب جديد» — type discovery only; a type is startable only when the server lists it AND a Family form exists in the client (none before PWA-6.1) |

- **Status vocabulary:** APPROVED reads «معتمد بانتظار التطبيق», never as
  an update; only APPLIED («تم تطبيق التعديل») uses the success tone.
- **Proposal:** rendered only in the approved `{rows: [{label, current,
  proposed}]}` shape; null or any other shape shows a neutral note and is
  never interpreted.
- **Timeline:** server order; no names or internal notes are received;
  APPLY_FAILED is skipped even if one arrived; public messages are labelled
  «ردّك» (RESUBMITTED) or as the review team's.
- **Actions:** «إرسال الاستكمال» (response required, length-limited) and
  «إلغاء الطلب» (confirmation only) are offered only from
  `available_actions`; one send in flight, never retried, no optimistic
  status; after any result the request and history are re-read, so a 409
  shows the current state.
- **Discovery:** switch off → «غير متاحة حاليًا»; empty list → no types
  message; no invented options, free-form JSON or form builder.
- **Privacy:** no family, person, member or submitter identifier is sent;
  data lives only in the query cache (cleared on sign-out), never in
  browser storage; access failures (401 / 403 family-context) go through
  the shared Family access handling.

## PWA-6.1 implementation record — RESIDENCE_UPDATE

Implemented 2026-10-08: the first real Change Request type, end to end.
No migration, permission or role. The submission switch stays OFF by
default and the Family navigation stays disabled (PFP-025): nothing is
enabled in Production by this slice.

| Item | Decision |
|---|---|
| Semantics | A CORRECTION of the Family's CURRENT residence, in place (FP-ADR-059). Never a move, never a new residence row, never another Family's residence |
| Domain Action | `UpdateFamilyResidenceAction` (existing, unchanged) — the only writer; it records RESIDENCE_UPDATED / DISPLACEMENT_UPDATED itself |
| Handler | `App\Support\ChangeRequests\Handlers\ResidenceUpdateHandler`, registered in `ChangeRequestTypes::PRODUCTION`; family-submittable; payload version 1 |
| V1 field set | `governorate`, `city`, `area`, `neighborhood`, `address_text`, `original_residence_text`, `displacement_status`, `displacement_location_text` — exactly what the Domain Action corrects. Never `residence_type`, dates, coordinates, source, notes, `is_current` or any id |
| Input | The whole proposed residence: every V1 key present (null = not recorded); any other key is refused by name (422), never dropped; strings ≤ 255 (address_text ≤ 1000), trimmed, blank → null; status DISPLACED / NOT_DISPLACED / null, and a family cannot clear a KNOWN status back to null; a location only with DISPLACED (`prohibited_unless`), and a non-displaced proposal stores the location as null (as the Domain Action does) |
| Proposal (`submitted_data`) | Only the fields that differ from the current residence — APPLY never rewrites a field the family did not change. An unchanged proposal is refused (422 on `data`) |
| Target | Always the family.context Family (`ChangeRequestTarget::family`); no `member_ref`, id or Family is read from the input |
| Preconditions | A current residence exists (else 422 PRECONDITION_FAILED, at submit, approve and apply); the location ↔ status rule against the residence as it will be after the change |
| Base fingerprint | The current residence id and all eight V1 fields, read with the residence row locked (`FOR UPDATE`) — any change to them after submission blocks approve / apply (409 BASE_CHANGED). No timestamps |
| Open conflict | One open RESIDENCE_UPDATE per Family (409 ALREADY_OPEN) |
| APPLY | Inside the engine's transaction: re-read under lock, re-validate, re-fingerprint, then the Domain Action with the stored proposal; rollback, failure recording, retry and replay are the PWA-5b semantics unchanged |
| Presentation | `{rows: [{label, current, proposed}]}` built with `ChangeRequestPresentation`: one row per proposed field, in V1 order, Arabic labels, status shown as «نازحة» / «غير نازحة», current = the LIVE registry value. Same rows for Staff and family (residence is family-visible data); no identity value |
| Profile section | RESIDENCE |

**Presentation contract and viewer context (FP-ADR-073 prerequisites, now
met).** `ChangeRequestHandler::present()` takes a server-computed
`ChangeRequestPresentationContext` (audience STAFF / FAMILY,
`canViewSensitiveIdentity`, `canViewInternalNotes`) instead of the bare
audience. It is built in the resources from the authenticated user — never
from input. `canViewSensitiveIdentity` is false for every viewer (no
permission grants it; being Staff is not enough); `canViewInternalNotes` is
`change-request.view-internal-notes` for Staff, false for a family.

**Frontend.** `/family/requests/new/residence-update`: shown only while
type discovery lists RESIDENCE_UPDATE (switch on, registered, permitted);
prefilled from `/family/household/profile`; React Hook Form + Zod for
length and the known-status rule (Laravel stays the authority); the review
notice «سيُرسل طلبك للمراجعة، ولن تتغير بيانات السجل الرسمي إلا بعد
اعتماد الطلب وتطبيقه.»; one UUID `client_reference` per proposal, re-sent
unchanged on a retry of the same proposal and replaced after any change or
an idempotency conflict; server field errors on their fields; refusals
(ALREADY_OPEN, PRECONDITION_FAILED, SUBMISSION_DISABLED, 429) as fixed
Arabic messages; success opens the new request. «طلب جديد» links
RESIDENCE_UPDATE to this form and still lists any other type as not yet
available. The Staff review screen and the family detail render the same
rows; once APPLIED the comparison drops its change markers and labels the
current column «السجل الآن», because current then equals the proposal.

**Tests.** Backend `tests/Feature/ChangeRequests/Residence/`: submission,
validation, unknown fields, no-change, missing residence, switch, discovery,
isolation, inactive Family, head change, idempotency, open conflict,
approve / apply through the Domain Action (call spied), untouched
unrelated fields, base change before approve and before apply, rollback and
retry, presentation and privacy, family timeline; PostgreSQL concurrency
(approve and apply wait for a parallel residence write, then detect the
stale base; a second submission waits for the Family lock). Frontend
`tests/family-residence-update.test.tsx`.

**Rollout prerequisites (not done here).** An explicit decision to enable
the navigation (PFP-025); then `CHANGE_REQUESTS_FAMILY_SUBMISSION_ENABLED`
on the server; Staff briefed on the residence review; the pilot SOP
(docs/09) updated for residence requests.

## PWA-6.1a implementation record — residence pilot readiness

Implemented 2026-10-08. No migration, permission, role or backend change;
nothing deployed or enabled.

- **Navigation (FP-ADR-074, PFP-025 closed):** `FamilyBottomNav` links
  «طلباتي» always and «+» from `canStartFamilyRequest(types)` over the
  shared type-discovery query (`staleTime` 60 s). With Production's switch
  off, «+» stays disabled and «طلباتي» opens the (empty) history.
- **Documentation reconciled:** RESIDENCE_UPDATE maps to
  `UpdateFamilyResidenceAction` (in-place correction) in docs/01 §99,
  docs/02 §57, docs/04 and docs/05 §71; a real move with history stays a
  separate, future use case (`ChangeFamilyResidenceAction`,
  `residence.change`, FU-02). Earlier records and change-log rows are kept.
- **Release readiness:** docs/08 §16b (release content since PWA-5a,
  gates, deployment, feature shutdown, rollback) and docs/09 «طلبات تحديث
  السكن — التجربة المضبوطة» (controlled pilot runbook). The Production
  env template now carries `CHANGE_REQUESTS_FAMILY_SUBMISSION_ENABLED=false`.
- **Tests:** frontend navigation matrix (switch off / empty / no form /
  available / loading / error / capability change / cache cleared /
  identifiers / current entry / order and names) and the existing Family
  navigation tests updated for the linked «طلباتي».
- **Not done (pilot prerequisites):** the deployment itself, the restore
  test, the pilot authorization, and the switch — see docs/08 §16b.

## PWA-6.1b implementation record — controlled pilot submission gate

Implemented 2026-10-08 (FP-ADR-075). No migration, permission, route or
frontend change; nothing deployed or enabled.

- `App\Enums\FamilySubmissionMode` (OFF / PILOT / GENERAL) and
  `App\Support\ChangeRequests\FamilySubmissionPolicy` (`mode()`,
  `pilotFamilyIds()`, `allows(FamilyAccessResult)`), read by
  `SubmitChangeRequestAction`, `SubmitFamilyChangeRequestRequest` and
  `FamilyChangeRequestController::types`; `config/change_requests.php` keys
  `family_submission_mode`, `pilot_family_ids`,
  `legacy_family_submission_enabled` (warning only).
- `php artisan famboook:change-requests-check`: effective mode, allowlist
  state as counts (ids, active Families), legacy key present YES / NO;
  exit 1 on warnings. Never prints an id.
- Tests: `tests/Feature/ChangeRequests/Api/FamilySubmissionGateTest.php`
  (modes, normalization, malformed allowlists, legacy key, Domain Action
  enforcement, missing Family context, client-chosen Family, disclosure,
  history / resubmit / cancel, Staff workflow, idempotency and open
  conflict, readiness command) and a PostgreSQL test (a non-allowlisted
  Family is refused without waiting on its locked row; the allowlisted one
  waits). Existing tests now set the mode instead of the boolean.
- Operations: docs/08 §16b (modes, allowlist format, opening, changing,
  shutdown, configuration cache), docs/09 (pilot uses PILOT, never
  GENERAL).

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
| A-15 | docs/03 "Verified is not beneficiary": verification makes a family eligible to be considered by targeting and nomination; docs/11 §13 chain Complete → Verified → Eligible | No review or verification state is an eligibility gate | **Amended (FP-ADR-058).** Neither Account Verification, Family Profile Review nor Staff Family Verification makes a family eligible or ineligible; programmes keep their own criteria |
| A-16 | docs/02 §20a / §75, docs/03 §55 / §55c, docs/04: Registered Household Size excludes DECEASED | registered_member_count counts every ACTIVE membership | **Amended (FP-ADR-056).** "Registered members" = active memberships; the living population is named "living members" (targeting, health summaries) |
| A-17 | docs/11 §9–§11, docs/02 §45a, docs/03, docs/05: Profile Completion and Family Verification, VERIFIED after the family submits | Family Profile Review by the head, separate from Staff verification | **Amended (FP-ADR-057).** Profile Review (CONFIRMED, never VERIFIED) is distinct from Account Verification and Staff Family Verification |
| A-18 | docs/06 §38 (Family self National ID "MASKED by default"; other members' mobile / National ID HIDDEN; Person Code FULL), §121 (adult member sensitive fields HIDDEN), §123 (health hidden), §124–§125 (needs / assistance pending); docs/11 FP-ADR-056 allow-list, §9 HEAD, §15, §23 | Full Data Visibility (§23a, 2026-10-05) | **Amended (FP-ADR-062).** For the Family Portal: own and members' National ID / mobiles masked with reveal through dedicated endpoints; structured health facts, recorded needs and received assistance visible; person_code INTERNAL_ONLY; internal Staff, audit, security and targeting data stay hidden |
| A-19 | docs/11 §18, FP-ADR-017, docs/02 §45a, docs/03 «Card and QR», docs/05 §53a: the **Digital Household Head Card** belongs to the eligible household-head identity; loss of head eligibility invalidates it; ISSUED / REISSUED states; "FH-…" holder ID | (2026-10-07, FP-ADR-070) The **Digital Family Card**: subject = the Family; the current head is shown live; a head change changes nothing; ACTIVE → REVOKED with REISSUED as a reason; card number FC-XXXX-XXXX-XX | **Superseded in part.** Minimal content, opaque non-derived public number, live-status QR, possession ≠ identity, enumeration protection and audited lifecycle are unchanged |

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
a column count. [Amended 2026-10-04: completeness is part of Family Profile
Review — derived section rules, no score (FP-ADR-057).]

FP-ADR-012
Declared household size and registered member count are separate facts and
are never reconciled arithmetically.

FP-ADR-013
Family Verification is separate from completion, granted only by an
authorized Famboook user or process, and auditable. [Amended 2026-10-04:
this is Staff Family Verification; Family Profile Review CONFIRMED is never
VERIFIED (FP-ADR-057).]

FP-ADR-014
Later changes affect verification according to a backend risk decision
table (MINOR / MATERIAL / CRITICAL).

FP-ADR-015
VERIFIED does not mean approved for assistance. [Extended 2026-10-04: no
review or verification state makes a family eligible or ineligible
(FP-ADR-058).]

FP-ADR-016
Health, disability and need submissions are reviewed before they reach the
canonical health and needs domains.

FP-ADR-017
The Digital Household Head Card carries minimal data, an opaque public
holder ID and a QR resolving to live status.
*Superseded in part on 2026-10-07 by FP-ADR-070 (the card subject — the Family,
not the household head — and its validity); this record stays as
history. Minimal data, an opaque public number and a live-status QR remain.*

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
The Staff API requires AccountSide::STAFF, enforced by one fail-closed
boundary middleware on its route group, independently of permissions and of
role order: family-side, mixed, role-less and custom-role accounts are all
refused. Link administration exists as Domain Actions only until there
is an operational need for an endpoint or UI.

FP-ADR-038
One resolver decides mobile trust against the Person's CURRENT normalized
number. A trust record only ever leaves TRUSTED (to STALE or REVOKED) and
never returns; changing a number back needs a new verification. Revoking
mobile trust never touches the account, the link or the sessions.

FP-ADR-039
An OTP resend keeps the challenge, replaces the code, restarts the
five-minute expiry and keeps the attempts. A correct code opens a 10-minute
grant, consumed inside the consuming workflow's transaction.

FP-ADR-040
OTP SMS is sent synchronously after commit through a provider-neutral
contract whose default refuses. A failed send counts and is not retried.
Sends are throttled per Person, destination, IP and globally, failing
closed. PWA-1E does not satisfy the Production gate for self-activation.

FP-ADR-041
Activation never reveals whether a National ID exists, is eligible, has a
trusted mobile or has an account. A denied start returns a cache-backed
decoy reference that follows the same public state machine; no part of the
mobile is shown; start and resend wait out a minimum response time.

FP-ADR-042
Activation always creates a NEW family-side User in one transaction with
its role, link, identity and the consumed grant. `users.name` is a
non-displayed snapshot; the portal shows the Person's name. The session is
established after the commit, and only for a request that can carry one.

FP-ADR-043
A family-side account is not access. `family.side` only classifies the
account; Family context is resolved separately on every request, and
without it the portal shows a neutral unavailable state. PWA-1G Family
login is part of the Production activation gate.

FP-ADR-044
Family login resolves the account through the authentication identity,
never the registry field, and creates a session only when the full Family
context exists. Every failure is one generic answer; an unknown identifier
costs a password verification like a known one. Lockout counts failures in
two tiers — per identifier and address, and per identifier overall — so
that knowing a National ID is not enough to lock its owner out from one
address.

FP-ADR-045
Password reset follows activation's public contract on purpose-bound
references: the same generic start, decoys, timers and errors, with no
reference valid for the other purpose. It requires the same Family context
as login, ends every earlier session of the account, and signs the
completing browser in on a new session.

FP-ADR-046
A Family password is 8 characters to 72 bytes of UTF-8. The upper bound is
the bcrypt input limit: a longer password is refused, never truncated.
Activation, login and password reset are three independent gates, all off
by default.

FP-ADR-047
A Coordinator is family-side, never Staff, and COORDINATOR ≠ REVIEWER.
Coordinator Space requires the account's own Family context, the role, the
space permission and at least one effective assignment; the scope is the
union of the assignments over the current hierarchy, inactive structure
fails closed, and assignments do not expire.

FP-ADR-048
A Coordinator sees family SUMMARIES only (code, hierarchy names, head name,
active member count) through `coordinator-family.view-summary`. A family is
reached only through the authorized query; out of scope and nonexistent
look the same. Coordinator Space is a visibly separate mode and its
families never join the Coordinator's own household.

FP-ADR-049
Coordinators are administered by SUPER_ADMIN and ADMINISTRATOR only, as
Staff acts with recorded events; role and scope are separate layers.
`person-mobile-trust.assist` stays withheld until a dedicated
assisted-mobile-trust workflow exists.

FP-ADR-050
The Production SMS provider is TweetsMS, selected by FAMILY_SMS_DRIVER.
The destination is sent in the registry's local 05XXXXXXXX format, as
stored. A send succeeds only on result code 999; anything else is a
classified failure. Credentials live only in the server environment.

FP-ADR-051
OTP SMS is handed to the provider after the HTTP response, in the same PHP
process, with no queue and no automatic retry; the code exists only in
memory until then. This replaces the "synchronously after commit" timing of
FP-ADR-040 on the public routes, and makes the queue worker of FP-ADR-035
unnecessary for SMS (it remains needed for fan-out). The public answer
never depends on the provider.

FP-ADR-052
Real and decoy challenges are indistinguishable also under parallel
requests and at the ceilings: decoy state that parallel requests change is
atomic (counter, write-once flag, one claim per send); decoy resends honour
the IP and global SMS ceilings (never the destination ones, which a decoy
cannot have); every public OTP step — start, verify, resend, complete —
waits out one response floor. Authentication attempts are counted before
the work they limit.

FP-ADR-053
Imported/current registered mobile is not trusted merely because it exists. For first self-activation, possession may be established by successful OTP verification sent exclusively to that stored mobile. Successful OTP creates Person-specific TRUSTED status with verification method SELF_OTP. User confirmation of the masked number alone is never
verification. First self-activation shows the masked registered number
(05*****123, last three digits only) after the eligibility checks, sends the
code only to that stored number after the user confirms it, and creates the
trust only on a correct code; a Staff-revoked number is never self-verified;
password reset still requires an already TRUSTED mobile. This supersedes,
for first activation only, FP-ADR-041's "no part of the mobile is shown"
and the TRUSTED-mobile prerequisite of activation; decoys show a stable fake
mask so the response stays identical. [Its decoy clause — the fake mask and
the decoy OTP journey for a refused identifier — is superseded by
FP-ADR-054 (2026-10-04); the rest stands.]

FP-ADR-054
Decided 2026-10-04 after the Production pilot, superseding the decoy clause
of FP-ADR-053 for first activation only. A synthetic masked mobile shown to
an identifier that cannot activate (e.g. a household member who is not the
head) read as wrong registry data and led into an OTP step that could never
succeed. First activation therefore refuses such an input outright, on step
1, with ONE generic answer — the same status, code (ACTIVATION_REFUSED) and
message for every reason — and creates nothing: no confirmation, number,
decoy, SMS, challenge, trust, account, link or identity. The reasons remain
server-side security events only. Accepted trade-off: a caller can learn
whether an input can start family activation, and nothing else. Password
reset keeps its decoys.

FP-ADR-055
The Family Portal is an installable app: one manifest (id/start/scope
/family, standalone), the official Famboook icon, and a minimal service
worker scoped to /family that caches only a static offline page, handles
only Family page navigations network-first, never stores a response, and
never touches the API, authentication or any other request. The install
action uses the browser's own prompt when pressed, or instructions. The
Staff application is neither installable nor controlled by the worker.

FP-ADR-056
PWA-3A household read views (implementation record §30a): summary,
members and profile endpoints behind family.context with an explicit
allow-list and no-store. registered_member_count is the count of ACTIVE
family memberships whatever the Person's life status, activity or
soft-deleted state; "living members" names the targeting / health
population. One row per active membership; a soft-deleted Person is a
placeholder that keeps the membership relationship. The profile shows the
current residence only.
(Its explicit allow-list — the exclusions listed in the §30a PWA-3A
record — is superseded in part by FP-ADR-062; the counting, placeholder,
no-store and current-residence-only rules stay.)

FP-ADR-057
Family Profile Review (مراجعة ملف الأسرة) is separate from Account
Verification and from Staff Family Verification (§9, §11).
Context: the portal must help the head keep family data complete and
current, while VERIFIED stays a formal Famboook decision.
Decision: the head reviews four V1 sections — FAMILY, HEAD, MEMBERS,
RESIDENCE (CONTACT later; health and needs excluded) — with derived
completeness (§9 rules), derived section states (PENDING → INCOMPLETE →
NEEDS_REVIEW → NOT_REVIEWED → CONFIRMED) and an overall state; no
percentage or score. A confirmation («راجعتُ هذه البيانات وهي صحيحة»)
changes no registry data, is allowed only for a complete section with no
pending request, is append-only (family_profile_confirmations, concept)
and becomes stale through a keyed fingerprint of the section's
portal-visible values. The fingerprint key is dedicated and independently
rotatable (FAMILY_PROFILE_FINGERPRINT_KEY concept, key_version,
fingerprint_version, domain separation), never the application key; no
registry snapshot is stored. Change Requests drive PENDING; APPROVED ≠
APPLIED; an applied change makes the section NEEDS_REVIEW or INCOMPLETE; a
rejection makes it NEEDS_REVIEW. Terminology: CONFIRMED = تمت المراجعة;
«ملف الأسرة محدّث»; never VERIFIED / موثّقة.
Security: server-resolved Family only; sections expose P1 data only;
other members' National ID / mobile, health and trust internals never
appear; coordinator scope grants nothing.
Consequences: a first family-side write (confirmation) after a CSRF write
smoke test; the Change Request engine and first request types come first.
Deferred: CONTACT section, health, programme readiness, Staff Family
Verification (PFP-017), the confirm permission name (PFP-023).

FP-ADR-058
No automatic eligibility (§13). Neither Account Verification, Family
Profile Review nor Staff Family Verification makes a family eligible or
ineligible for assistance, nomination or service. Programmes keep their
own required data, criteria and rules; programme readiness may report
missing required data without changing the Profile Review state.
Supersedes the former "Profile Complete → Verified → Eligible for
consideration" chain (§13) and docs/03's "verification makes a family
eligible to be considered".

FP-ADR-059
Change Request first-release direction (§14): RESIDENCE_UPDATE (current
residence correction), BIRTH_REPORT, ADD_FAMILY_MEMBER, PERSON_CORRECTION
(including CONFIRM_ALIVE, applied through ConfirmPersonAliveAction —
FP-ADR-060), DEATH_REPORT for a non-head member. Deferred:
head death / succession, head change, transfer, marital events,
CONTACT_UPDATE, health and need submissions. A residence move with history
needs a future residence.change action.

FP-ADR-060
UNKNOWN → ALIVE (FU-07, PFP-024 — resolved 2026-10-04). A dedicated
ConfirmPersonAliveAction performs exactly UNKNOWN → ALIVE: row lock and
re-read; ALIVE refused (PERSON_ALREADY_ALIVE), DECEASED refused
(PERSON_DECEASED — never brought back), UNKNOWN with a death date refused
(INCONSISTENT_LIFE_RECORD); is_active and an active membership are not
required; nothing else changes (memberships, head flag, mobile and trust,
Link, identity, sessions). PERSON_ALIVE_CONFIRMED records the verification
method (IN_PERSON, STAFF_CALLBACK, AUTHORIZED_RECORD_REVIEW — the Staff
verification bases of mobile trust) as its only metadata. Staff use
POST /api/v1/people/{person}/confirm-alive (person.record-death). An
UNKNOWN household head cannot reach the Family Portal and is confirmed
only through this Staff path; confirming creates no account, Link,
identity, OTP or session — the normal activation flow may then succeed.
A family's CONFIRM_ALIVE request (non-head members) needs its statement
plus Staff review, no mandatory document, and calls the same action on
APPLY. UpdatePersonAction never writes life status or death date.

FP-ADR-061
Staff paths for the DEATH_REPORT and HOUSEHOLD_DECLARATION_UPDATE apply
targets (FU-10 — resolved 2026-10-05). Product decisions:
D1  A household declaration is recorded with the existing family.update
    (no new permission). POST /api/v1/families/{family}/household-
    declarations creates a NEW current declaration (history kept, values as
    declared, no arithmetic); Staff sources PAPER_FORM, MANUAL_ENTRY,
    VERIFIED_SOURCE — never IMPORT; no notes. The caller always states the
    current declaration it expects (or none); a different one is refused
    (HOUSEHOLD_DECLARATION_CHANGED, 409) under the Family lock.
D2  Staff death recording requires a verification method (IN_PERSON,
    STAFF_CALLBACK, AUTHORIZED_RECORD_REVIEW; never SELF_OTP), recorded as
    the only metadata of PERSON_DEATH_RECORDED. POST /api/v1/people/
    {person}/record-death (person.record-death) sends the death date
    explicitly: a date, or null when unknown.
D3  A recorded death is irreversible in V1: no DECEASED → ALIVE path
    (PERSON_ALREADY_DECEASED, 409, on a second recording). Correcting an
    erroneous death needs a future dedicated Domain Operation, never
    UpdatePersonAction. The Staff UI warns before recording.
D4  Staff may record the current household head's death. No successor is
    selected; membership and is_household_head do not change; the family
    may have no eligible Family Portal user until Head Succession (FU-01,
    still open). The Staff UI shows a stronger warning for a head.
DEATH_REPORT (non-head, PWA-6) will APPLY through RecordPersonDeathAction
server-side; HOUSEHOLD_DECLARATION_UPDATE stays proposed (PFP-008). Change
Request APPLY never calls the Staff routes.

FP-ADR-062
Family Portal Full Data Visibility (§23a, PWA-3B — approved 2026-10-05).
Principle: the portal replaces, as far as reasonable, a visit to a
data-update center; the household head reviews the family-facing
canonical registry data of themselves and their household. VIEW is not
UPDATE: changes are Change Requests applied through Domain Actions.
Personal data is not hidden merely for being personal; authorization,
household isolation and family.context are the boundaries; internal Staff,
audit, security, targeting, workflow and implementation data stay hidden.
Decisions:
- «بياناتي الشخصية» lives under «حسابي» (/family/account/me) and is also
  linked from the head's own member card; «حسابي» is the account view.
- The head sees their own full_name, national_id, mobile, alternate_mobile
  (masked by default, each with its own Eye reveal), alternate-mobile
  owner relation, gender, birth date / age, marital status, relationship
  and membership start.
- Self reveal: POST /api/v1/family/self/reveal, field NATIONAL_ID | MOBILE
  | ALTERNATE_MOBILE only; owner from the Family context's Person; no
  target input; no-store, private; throttle; CSRF; a security event with
  the field code, never the value; no Family Activity event; no
  re-authentication in V1. Masks: NationalIdMask (last 4), mobile mask
  (05 + last 3). Reveal state never persisted, no clipboard copy.
- ACTIVE household members: National ID, mobile and alternate mobile are
  masked with reveal, through a separate member-reveal boundary designed
  in PWA-3B.4 (family.context, canonical membership check, no Coordinator
  widening, no cross-family target, no raw numeric id, no caching,
  throttle, security event without the value). Never in list payloads.
- Member detail: name, relationship, head status, gender, birth date, age,
  marital status, life status, death date («تاريخ الوفاة غير معروف» when
  null), membership start, masked identity / contact, health facts.
  Deceased members stay visible. An in-page sheet; no route-addressed
  member screen unless PWA-3B.4 needs one.
- «أسرتي» is the full family record: family code, clan, branch group,
  branch, head, registration date, paper form number; the CURRENT
  declaration (size, sons, daughters, date, source — never arithmetic
  against the registered members); the CURRENT residence including
  address_text, residence_type and started_at.
- PFP-006 resolved: structured health facts of household members (type,
  disability type, condition_name, dates, active state), no adult / minor
  distinction in V1; `details` internal.
- Recorded needs (category, title, quantity, unit, status, created_at,
  resolved_at, person) and actual non-reversed received assistance
  (title, category, type, provider, delivered_at, item, quantity, unit,
  unit_value, currency, receipt mode, recipient / delegate) are visible;
  priority, description, closure reason, targeting, nomination, approval
  and reversal internals are not.
- Assessments stay internal; family activity history waits for PWA-5;
  Documents are not implemented and are not part of PWA-3B.
- Family Portal person_code = INTERNAL_ONLY (resolves the docs/06 §38
  "FULL" vs §30a "never" conflict; revisited only if a safe public member
  identifier is needed).
- Profile Review sections and completeness are unchanged; the HEAD
  National ID may be shown masked with reveal, outside completeness and
  fingerprint; health is not a V1 review section.
- Documenting visibility approves no proposed request type.
Supersedes in part: FP-ADR-056 (its allow-list exclusions), the §9 HEAD
"never its value" rule, the §15 health reading rules, the §23 "no Health /
Needs / Assistance areas" note, docs/06 §38 / §121 / §123–§125 as they
apply to the Family Portal (§31 A-18).

FP-ADR-063
Safe household-member reference (FU-13 — resolved 2026-10-05). The Family
Portal references a household member by `member_ref`:
- it identifies the FAMILY MEMBERSHIP ("this person as part of this
  household"), never the Person: an ended membership stops resolving, a
  transfer (a new membership) has a new reference, and an active
  membership whose Person is unavailable keeps its reference (the safe
  placeholder);
- HMAC-SHA256 over the domain-separated context
  `FingerprintContext::MEMBER_REF` and `family_id:membership_id`, through
  the existing KeyedFingerprint service, Family Auth key and key versions —
  no new secret; the FULL digest, 64 lowercase hex characters (256 bits),
  never truncated;
- never stored and never logged: recomputed on every request; it exposes no
  Person or membership id, person_code, National ID or mobile;
- NOT authorization: `HouseholdMemberReference::resolve` compares it (with
  hash_equals, current and configured previous key version) only against
  the ACTIVE memberships of the Family the family.context boundary
  resolved — never a global lookup followed by a family check, never route
  model binding; every failure (malformed, uppercase, random, foreign,
  ended) is the same NULL, so nothing reveals why;
- key rotation changes every reference; old ones resolve during the
  configured overlap only;
- no migration;
- a Change Request (and any other server-side record) stores the internal
  membership id, never `member_ref`.

FP-ADR-064
Household-member sensitive-value reveal (PWA-3B.4 — approved 2026-10-05).
The household head reveals, field by field, the National ID, mobile and
alternate mobile of ANOTHER member of their household:
- POST /api/v1/family/household/members/{memberRef}/reveal, body
  {"field": NATIONAL_ID | MOBILE | ALTERNATE_MOBILE} (SelfRevealField, the
  fixed column mapping); any family, person, membership or user id, any
  target, member_ref or value in the body or query is refused (422);
- resolution order: family.context Family → HouseholdMemberReference::resolve
  (ACTIVE memberships of that Family only) → never the head's own
  membership → an available (not soft-deleted) Person → the one field;
  member_ref grants nothing and is never route-model bound;
- every unavailable target — malformed, random, foreign, ended, unavailable
  or soft-deleted Person, the head's own reference — is ONE identical 404
  HOUSEHOLD_MEMBER_UNAVAILABLE «بيانات هذا الفرد غير متاحة.», no-store;
- the head's own values stay on the self path only (/family/account/me,
  POST /family/self/reveal): one path, one event type for self data;
- 200 {"data": {"field", "value" | null}}, Cache-Control: no-store,
  private; a null stored value is still an authorized reveal;
- one HOUSEHOLD_MEMBER_SENSITIVE_REVEALED security event per authorized
  reveal (null included): person = the TARGET Person, user / actor = the
  head, link = the head's link, metadata = {field} only; nothing for 404,
  422, 429 or boundary refusals; no Family Activity;
- its own throttle family-member-reveal, per authenticated user (never per
  member_ref): 20 / minute and 120 / hour by default, independent of the
  self reveal; 429 TOO_MANY_REQUESTS, no-store;
- no adult / minor rule in V1; no re-authentication; no migration; the
  masked GET payloads are unchanged.

FP-ADR-065
Runtime Family Auth capabilities and fail-closed entry points (FU-14 —
approved 2026-10-06).
- GET /api/v1/family/auth/capabilities: public, unauthenticated, outside
  the activation, login and password reset gates; no input, no account
  lookup; 200 {"data": {"activation", "login", "password_reset"}}, each the
  strict `true` its gate reads; Cache-Control: no-store, private. Only
  these three global booleans — never throttle, SMS / provider, security
  or environment settings, never anything about an account;
- the Family Portal reads them at RUNTIME (never NEXT_PUBLIC build flags),
  so a flag change needs a config refresh on the server only;
- fail closed: an entry point to a surface ("نسيت كلمة المرور؟", the
  /family/forgot-password form) appears only on an explicit `true`; while
  loading, after an error, without a connection or on `false` it is not
  offered, and the route shows a plain unavailable notice with the way
  back to the login, never a reason. Login itself never depends on the
  capabilities request;
- presentation only: the gates remain the authority, and a closed surface
  still answers 503;
- password reset is otherwise unchanged: automatic sign-in after a reset
  (FP-ADR-045), the full Family context including household head, no
  masked mobile at any step, no trust created or revived by a reset.

FP-ADR-066
Staff Mobile Trust Management UI (FU-15 — approved 2026-10-06).
- Staff-side only: the card «توثيق رقم الجوال» on the Staff Person profile,
  on GET / POST /api/v1/people/{person}/mobile-trust and POST …/revoke
  (person-mobile-trust.view / .grant / .revoke, checked as permissions);
  nothing in the Family Portal;
- the state is the backend's (TRUSTED, STALE, REVOKED, UNVERIFIED,
  NO_MOBILE, UNAVAILABLE), never derived in the frontend; only
  mobile_masked is displayed — no reveal, no mobile editing, no
  fingerprint or key version;
- grant: one of IN_PERSON, STAFF_CALLBACK, AUTHORIZED_RECORD_REVIEW with an
  operator attestation; never SELF_OTP (history-readable only), never a
  number; always a NEW TRUSTED row for the Person's CURRENT registered
  mobile; offered for UNVERIFIED, STALE and REVOKED, shown disabled for
  NO_MOBILE, absent for TRUSTED and UNAVAILABLE;
- revoke: TRUSTED only, with a MobileTrustRevokeReason code; the row stays
  as history; REVOKED and STALE rows are never revived;
- Family login and mobile trust have separate lifecycles: revoking trust
  ends its use for OTP flows such as password recovery, and does not by
  itself deactivate the account or end sessions;
- no migration, no backend behavior change; FU-14 password reset,
  activation, login and SMS are unchanged.

FP-ADR-067
Family account view «حسابي» (PWA-3B.5 — approved 2026-10-06).
- /family/account is the ACCOUNT view, not a registry view: who is signed
  in, «الحساب مفعّل», the activation date, the Family code, the access
  types in words, the current mobile trust summary, the Coordinator
  section (COORDINATOR only), the «بياناتي الشخصية» entry and logout;
- GET /api/v1/family/account: self only through family.context, no target,
  no-store, private; only activated_at and mobile {state, masked}. The
  state is the server's CURRENT state; the Family sees no trust history,
  verifier, revoker or reason, and REVOKED reads «غير موثّق حاليًا» (the
  Staff word «ملغى» stays Staff-side). No self-trust, reveal or edit;
- /family/me keeps its minimal contract; the Coordinator scope comes only
  from /family/coordinator/context;
- Account Recovery (FU-14) and Authenticated Change Password are separate
  product / security flows. «حسابي» does not offer the Forgot Password
  flow as an account setting and has no disabled or placeholder password
  control; Authenticated Change Password is FU-16;
- logout is the existing Family logout; no migration, no role or
  permission change; FU-14 and FU-15 unchanged.

FP-ADR-068
Household health read visibility (PWA-3B.6 — approved 2026-10-06).
- read-only visibility of the canonical person_health_records of the
  household's ACTIVE members (head and deceased members included,
  soft-deleted Persons and ended memberships excluded): type, disability
  type, condition_name, started_at, ended_at, active state;
- one household endpoint, GET /api/v1/family/household/health, that takes
  no client identifier; records grouped by the opaque member_ref and read
  by it in the member sheet; coordinator scope never grants it;
- health is Person-based; active = ended_at IS NULL; closed records stay
  visible as history; no record is "nothing registered", never "healthy";
- details, ids, uuids, person_code and all Staff / audit / assessment /
  targeting metadata stay hidden; no security event for this read;
- discovery by a neutral member-card chip and a «أسرتي» count of members
  with registered data — never a health status, never Staff indicators;
- no edit or request control: corrections remain PWA-5 (Change Request
  engine); health submissions remain PWA-7.

FP-ADR-069
Needs and received assistance read visibility (PWA-3B.7 — approved
2026-10-07).
- two separate, read-only household endpoints —
  GET /api/v1/family/household/needs and /assistance — that take no client
  Family / Person identifier; Family-specific allow-lists; coordinator
  scope never grants them;
- needs and received assistance are independent domains: a FULFILLED need
  is a registry status and never proof that assistance was delivered;
- only a non-reversed delivery of an INTERNAL Assistance is a receipt (one
  delivery = the full package); targeting, nominations, approvals,
  NOT_DELIVERED, rejections, removals and issued EXTERNAL lists stay
  internal and are never presented as receipts or eligibility;
- history stays with the recorded Family; former or deceased Persons keep
  their names; soft-deleted Persons are unavailable;
- missing records mean "nothing registered", never that the family needs
  nothing or received nothing;
- no pagination in V1 (revisit near about 100 deliveries per household);
  no edit or request control — corrections remain PWA-5;
- with PWA-3B.7 verified, PWA-3B (Family Portal Full Data Visibility) is
  complete.

FP-ADR-070
Digital Family Card — a Family-subject digital credential (PWA-8,
pulled forward; PWA-8.2 approved 2026-10-07). Supersedes in part FP-ADR-017 and
§18 (§31 A-19).
- the subject is the Family; the current eligible head is its
  representative, resolved live; a head change never revokes, rotates,
  reissues or renumbers the card; Family Portal access keeps its own
  head-eligibility rules, independently of the card;
- generic `digital_credentials` infrastructure with explicit relational
  integrity (subject FAMILY, family_id foreign key, no polymorphic subject,
  no person_id yet); a future Person credential is additive and never
  requires reissuing Family QRs;
- family_code ≠ card number (FC-XXXX-XXXX-XX, random Crockford, immutable)
  ≠ QR token (32 random bytes, base64url, SHA-256 lookup hash, Crypt-sealed
  copy for the owner's QR only; never logged, never in activity or API URLs);
- issuance: lazily for the eligible head on first /family/card (system
  process) and by Staff; ACTIVE Family only; a switch
  (credentials.family_card_issuance_enabled) blocks new credentials only;
- one public route /verify/{token}, a browser POST of the token, a
  type-specific allow-listed presenter, one generic failure;
- a digital verification credential inside Famboook — «وسيلة تحقق رقمية
  ضمن نظام Famboook» — never an official identity document.

FP-ADR-071
Digital Family Card PDF (PWA-8.3 — approved 2026-10-07).
- the PDF is a presentation / export of the Family's EXISTING ACTIVE
  credential (FP-ADR-070), not a new credential or lifecycle: it never
  issues, reissues, revokes, rotates or renumbers, and changes no Family
  data; GET /api/v1/family/card/pdf has zero domain side effects;
- owner only: the eligible household head through family-side
  authorization and family.context; no client Family / credential
  identifier; no ACTIVE card → 404 CARD_NOT_ISSUED; a token that cannot be
  decrypted → 409 CARD_QR_UNAVAILABLE (never a card without its QR);
- content: Famboook branding, «بطاقة الأسرة الرقمية», card number, family
  code, the current head's full registered name, Clan, Branch, issue date
  (Arabic month, Latin digits), the QR, the instruction and the fixed
  disclaimer — nothing else; the verification URL exists only inside the QR;
- one owner view (FamilyCardView) feeds both the card JSON (unchanged
  contract) and the PDF; CredentialTokens::reveal() stays the one
  decryption point; QR via chillerlan (GD PNG, ECC M, quiet zone 4);
- mPDF ^8.3 (GPL-2.0-only, server-side use), IBM Plex Sans Arabic bundled
  (OFL), A4 portrait, 15 mm margins, one card panel; generated in memory,
  never stored; mPDF's temp / font-metric cache only in
  storage/framework/cache/mpdf;
- no FamilyActivity, security event or log entry for an ordinary download;
  Cache-Control no-store, private; filename
  famboook-family-card-{credential_number}.pdf;
- no Staff PDF / reprint (Staff never receive a token, QR or verification
  URL): PWA-8.4.

FP-ADR-072
Change Request foundation for the Family Portal (PWA-5a — approved
2026-10-07).
- the approved order is kept: PWA-5 → first PWA-6 types → PWA-4; nothing of
  PWA-4 is built, and PWA-5 keeps it possible (ChangeRequestStatus::open()
  is the PENDING population: SUBMITTED, UNDER_REVIEW,
  RETURNED_FOR_CLARIFICATION, RESUBMITTED and APPROVED — DRAFT excluded);
- family-side transitions: submit (a request is created SUBMITTED, never
  DRAFT), resubmit with a text response only, and cancel until approval
  (CANCELLED); the family never reviews, returns, approves, rejects or
  applies (WF-INV-026);
- visibility is Family-subject: the current eligible household head sees
  the Family's whole request history, including requests of a previous
  head; reads use family.context and ownership, never change-request.view
  (FU-04 resolved);
- a request names a household member by member_ref (FP-ADR-063); the
  server stores the internal membership id; requests are addressed by uuid
  and shown with the CRQ-000001 reference, never the internal id;
- family-visible text (clarification request, family response, rejection
  message) is separate from Staff-only notes; payloads are never copied
  into events or logs and are masked in presentation (no application-level
  payload encryption in V1);
- «+», «طلباتي» and the quick actions stay disabled until PWA-6.1;
  coordinator-submitted requests are deferred.

FP-ADR-073
Family Change Request API (PWA-5e — approved 2026-10-08).
- routes under /api/v1/family/change-requests behind auth:sanctum,
  family.side, family-portal.access and family.context: history (GET),
  type discovery (GET /types), detail (GET /{uuid}), submit (POST,
  change-request.submit), resubmit (POST /{uuid}/resubmit,
  change-request.resubmit), cancel (POST /{uuid}/cancel,
  change-request.cancel); no review, approval, rejection or apply route;
- the Family is ONLY the family.context Family; history is Family-subject
  (every request of the Family, whoever submitted it — a new head sees and
  may cancel the previous head's open request); a request is looked up
  inside that Family only, so another Family's uuid is 404;
- the submission switch CHANGE_REQUESTS_FAMILY_SUBMISSION_ENABLED (default
  false) blocks NEW requests only (503 CHANGE_REQUEST_SUBMISSION_DISABLED,
  before any validation); history, detail, resubmission and cancellation
  continue; type discovery answers `{data: [], meta: {submission_enabled}}`
  and lists only registered, family-submittable types while the switch is
  on and the account holds change-request.submit — empty in Production;
- family-safe views: the proposal only through the handler's FAMILY
  presentation; a timeline without internal notes, actor names, metadata or
  APPLY_FAILED diagnostics; a rejection's reason code and family message
  only; never submitted_data raw, the base fingerprint, the client
  reference, apply-failure counts or internal ids;
- presentation contract (approved for the first handler, PWA-6.1):
  `{rows: [{label, current, proposed}]}` — Arabic labels and display
  strings formatted by the handler, ordered, no nested objects, no database
  keys, null without a registered handler. A server-computed presentation
  context (audience, canViewSensitiveIdentity, canViewInternalNotes) is a
  PWA-6.1 prerequisite: the handler contract does not receive the viewer yet,
  and no permission yet grants full-identity viewing.

FP-ADR-074
Family navigation activation (PWA-6.1a — approved 2026-10-08; resolves
PFP-025).
- «طلباتي» is a link to /family/requests for every household head WITH a
  Family context (the only users who see the bottom navigation). It does
  not depend on CHANGE_REQUESTS_FAMILY_SUBMISSION_ENABLED: history,
  clarification replies and cancellation stay available while new
  submissions are closed;
- «+» links to /family/requests/new only when the server's type discovery
  (GET /api/v1/family/change-requests/types) answers successfully with
  `submission_enabled: true` AND lists at least one type that has an
  implemented Family form in the client (PWA-6.1: RESIDENCE_UPDATE). The
  server lists a type only when it is registered, family-submittable and
  the account holds change-request.submit. Otherwise — switch off, empty
  list, no form, loading, any error — «+» keeps the established disabled
  state («إجراء جديد (قريبًا)»);
- never derived from a frontend environment variable; one shared TanStack
  Query read (stale after a minute, refreshed on focus), cleared with the
  whole cache at sign-in and sign-out;
- the home quick actions are not part of this decision and stay disabled;
- the server remains the authority: a visible «+» grants nothing, and every
  submission is re-checked (switch, eligibility, type, validation).

FP-ADR-075
Controlled pilot submission gate (PWA-6.1b — approved 2026-10-08;
supersedes the boolean switch of FP-ADR-073).
- NEW family submissions follow one mode, CHANGE_REQUESTS_FAMILY_SUBMISSION_MODE:
  OFF (default) — nobody; PILOT — only the Families on the allowlist;
  GENERAL — every otherwise eligible household head. Trimmed and
  upper-cased; missing, empty or any other value is OFF;
- the PILOT allowlist, CHANGE_REQUESTS_PILOT_FAMILY_IDS, is a comma-separated
  list of canonical Family database ids (positive integers); empty means no
  Family and ONE malformed entry invalidates the whole list (no Family). It
  is server configuration only: no table, no Staff screen, no endpoint; it is
  never logged, returned, shown or committed with real ids;
- FamilySubmissionPolicy is the one decision, from trusted inputs only (the
  family.context Family and the configuration — never a client Family id).
  SubmitChangeRequestAction enforces it (authoritative) after the actor
  check, before any input is validated, read or written;
  SubmitFamilyChangeRequestRequest asks it early (same 503
  CHANGE_REQUEST_SUBMISSION_DISABLED after the 401 / 403 boundary); type
  discovery reports it per Family: `submission_enabled` is THIS Family's
  channel state and the list is empty when it is closed — the response
  shape is unchanged, so FP-ADR-074's «+» needs no client change;
- the replaced CHANGE_REQUESTS_FAMILY_SUBMISSION_ENABLED is never read as a
  switch: a leftover `true` can never open GENERAL;
  famboook:change-requests-check (read-only, counts only) warns while it
  is set;
- only NEW submissions are gated: history, detail, resubmission,
  cancellation and the whole Staff workflow are unaffected in every mode;
- OFF → PILOT → GENERAL is always an explicit administrator change; the
  pilot uses PILOT only; GENERAL needs its own decision after the pilot.
```

---

# 33. Open Decisions

These do not block the architecture. Each must be decided before the phase
named.

```text
PFP-001  (PWA-2)
SMS provider.
Architecture decided 2026-10-02: an SmsSender abstraction with a log-only
development driver. DECIDED 2026-10-03: TweetsMS (FP-ADR-050), sent after
the response without a queue (FP-ADR-051). Configuring it on the server
remains a Production activation gate item (§30a).

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

PFP-006  — RESOLVED 2026-10-05 (FP-ADR-062)
Exact health and disability visibility between adult family members, and
for minors. Decided for V1: the household head reviews the structured
health facts of all household members (no adult / minor distinction);
free-text details stay internal.

PFP-007  (Staff Family Verification)
Approval of the verification invalidation decision table (§12). Applies to
Staff Family Verification only; Profile Review staleness is fingerprint-
based (FP-ADR-057).

PFP-008  (PWA-5)
Approval of the proposed request types (§14) and whether health and need
submissions are Change Request types or a sibling workflow.

PFP-009  (PWA-7)
Need category refinement for formula and diapers.

PFP-010  (PWA-8)
Exact public QR verification fields. **Resolved 2026-10-07 (FP-ADR-070):**
validity, card number, family_code, issue date, Clan, Branch, current head
as first + last word.

PFP-011  (PWA-8)
PDF generation library. **Resolved 2026-10-07 (FP-ADR-071):** mPDF ^8.3
(GPL-2.0-only — acceptable for Famboook's server-side web / SaaS operation;
revisit before any distribution of the backend code) with the bundled IBM
Plex Sans Arabic TTF (Regular, SemiBold; SIL OFL 1.1, unmodified official
IBM release).

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

PFP-017  (later)
Whether Staff Family Verification is performed at all, when, who reviews
and who approves it, and whether the two must differ. Profile Review
(FP-ADR-057) does not depend on it.

PFP-018  (PWA-8)
Card issuance: automatic on verification or on request; who may revoke and
reissue. "Verification" here means Staff Family Verification, never
Profile Review CONFIRMED. **Resolved 2026-10-07 (FP-ADR-070):** lazy automatic
issuance on the eligible head's first /family/card plus Staff issue;
revoke and reissue by Staff holding family-card.revoke / .reissue.

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
UPDATED 2026-10-03 (PWA-1H): `coordinator-family.view-summary` approved and
seeded (COORDINATOR only, summary only). `person-mobile-trust.assist` stays
withheld from COORDINATOR until a dedicated assisted-mobile-trust workflow
is approved. `coordinator-family.view-account-status` stays PROPOSED.

PFP-023  (PWA-4)
The permission name for a Profile Review confirmation (e.g. the proposed
family-verification.submit, or a new family-profile.confirm).

PFP-024  — RESOLVED 2026-10-04 (FP-ADR-060)
The Domain Action path for confirming an UNKNOWN member as ALIVE:
ConfirmPersonAliveAction, Staff endpoint with a verification method, and
PERSON_CORRECTION / CONFIRM_ALIVE (statement + Staff review) later.

PFP-025  — RESOLVED 2026-10-08 (FP-ADR-074)
Activating «+», «طلباتي» and the quick actions in the Family bottom
navigation. Decided: «طلباتي» always; «+» from server type discovery; the
quick actions stay disabled. The original question: FP-ADR-072 keeps them disabled UNTIL PWA-6.1; PWA-6.1 is built
but keeps them disabled pending this decision. Proposal: enable them only
while the server reports `submission_enabled` (type discovery) — so they
follow the existing CHANGE_REQUESTS_FAMILY_SUBMISSION_ENABLED switch and
no new flag is added — or enable «طلباتي» (read-only history) always and
«+» with the switch. The request screens remain reachable by URL.
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
| FU-02 | **Answered 2026-10-04:** `UpdateFamilyResidenceAction` corrects the current residence in place; no Domain Action records a move with history (`residence.change`). RESIDENCE_UPDATE V1 is a correction (FP-ADR-059) | A move with history needs a future `residence.change` action |
| FU-03 | Production runs `QUEUE_CONNECTION=sync` with no worker. SMS no longer needs one (TweetsMS, after the response — FP-ADR-051); the provider is integrated but must be configured and validated on the server | **Production activation gate** (§30a, docs/08 §16a); fan-out PWA-9 (PFP-019) |
| FU-04 | **Resolved 2026-10-07 (FP-ADR-072, docs/06 AUTH-ADR-087).** docs/06 §53 "scoped view access" is the Family boundary (`family-portal.access`, `family.context`, Family ownership), not `change-request.view`, which stays Staff-only | Done |
| FU-05 | AUTH-ADR-060 is referenced in docs/03, docs/06 and docs/07 but has no entry in the docs/06 decision list | Next docs/06 maintenance |
| FU-06 | "Document Status" version blocks are stale relative to the change logs (e.g. docs/03) | Next documentation maintenance |
| FU-07 | **Resolved 2026-10-04 (FP-ADR-060).** UNKNOWN life status comes from import: spouses are always created UNKNOWN, and a household head is UNKNOWN when the source life-status cell is empty. An UNKNOWN head is not eligible (ALIVE only) and cannot reach the Family Portal, so it is confirmed only by Staff (`POST /api/v1/people/{person}/confirm-alive`, `person.record-death`, verification method required). `ConfirmPersonAliveAction` is the only UNKNOWN → ALIVE path; `UpdatePersonAction` never writes life status | Done; family CONFIRM_ALIVE requests with the Change Request engine (PWA-6) |
| FU-08 | **CSRF write smoke test.** A past Production 419 on one browser was a stale / duplicate-cookie incident (API, `csrf-cookie` and CORS preflight succeeded; clearing site data resolved it) — not a reproduced Sanctum / CORS defect | Before the first new Family Portal write endpoint: a production-like CSRF write smoke test; no proactive Sanctum / CORS change without a reproduced defect (docs/08) |
| FU-09 | Family lifecycle: no Domain Action archives or restores a Family; a Family soft-deleted outside the application keeps active memberships, `NationalIdGuard::describe()` then answers 500 instead of 422, and import reconciliation and Apply planning disagree about such memberships | Family lifecycle task (PBD-005); the two defects are small fixes |
| FU-10 | **Resolved 2026-10-05 (FP-ADR-061).** Staff paths exist for both apply targets: `POST /api/v1/people/{person}/record-death` (`person.record-death`, verification method required, death date explicit, irreversible, head death allowed without succession) and `POST /api/v1/families/{family}/household-declarations` (`family.update`, Staff sources only, stale-write protected); Staff UI actions «تسجيل وفاة» and «تسجيل إقرار أسرة». No migration | Done; DEATH_REPORT (PWA-6) and HOUSEHOLD_DECLARATION_UPDATE (PFP-008) apply through the same actions server-side |
| FU-11 | Seeded permissions not read by any code: `change-request.*`, `document.*`, `residence.change`, `family.archive` / `.restore` / `.change-household-head` / `.view-history`, `person.archive` / `.view-history`, `family-membership.transfer`. (`person.record-death` is now read by `confirm-alive` and `record-death`.) | Reserved for their phases (docs/06) |
| FU-12 | Seven legacy PostgreSQL test-fixture failures beyond the three recorded in docs/07 (hard-coded reference ids, a check constraint) | Test cleanup; not a Family Portal defect |
| FU-13 | **Resolved 2026-10-05 (FP-ADR-063).** The Family Portal member reference is `member_ref`: the full keyed HMAC-SHA256 (64 lowercase hex characters) of a FAMILY MEMBERSHIP (`FingerprintContext::MEMBER_REF`, Family Auth key and versions), resolved only among the ACTIVE memberships of the `family.context` Family. Never an id, never stored, never authorization. No migration | Done; the member reveal (PWA-3B.4) and member-specific Change Requests use it |
| FU-14 | **Implemented 2026-10-06 (FP-ADR-065); enablement pending.** Password reset Production readiness: the PWA-1G / PWA-1I reset is kept unchanged (automatic sign-in, head eligibility, no masked mobile, no trust created); runtime `GET /api/v1/family/auth/capabilities` and a fail-closed «نسيت كلمة المرور؟» link and `/family/forgot-password` route; realistic coverage (self-OTP account journey, coordinator, Staff boundary, changed / revoked mobile, no registry mutation). No migration | `FAMILY_PASSWORD_RESET_ENABLED=true` only through the docs/08 §16a enablement checklist, as its own decision |
| FU-15 | **Resolved 2026-10-06 (FP-ADR-066).** Staff Mobile Trust Management UI: the Staff Person profile card «توثيق رقم الجوال» (state, masked current mobile, trust history; grant with IN_PERSON / STAFF_CALLBACK / AUTHORIZED_RECORD_REVIEW, revoke with a reason code) on the existing PWA-1E API and Domain Actions. REVOKED never revived; a grant is a new row for the current `Person.mobile`; SELF_OTP never a Staff method; revoke does not deactivate the account or end sessions. Staff-side only — no Family Portal change. No migration | Done |
| FU-16 | **Authenticated Change Password** (recorded 2026-10-06, FP-ADR-067). There is no authenticated Family password-change endpoint; Account Recovery (FU-14) is a login-side flow and is not offered from «حسابي». The future phase must define: current-password verification; new-password validation; throttling; the session revocation policy and whether the completing browser stays signed in; security event / audit semantics; CSRF / session behavior | A future Family Auth phase, its own decision |

---

# 34. Change Log

| Version | Date | Status | Description |
|---|---|---|---|
| 1.0 | 2026-10-02 | Approved | PWA-0: Family Portal program specification — product definition, modules, identity and authentication architecture, mobile trust, multi-role and COORDINATOR, Profile Completion, Family Verification, requests, health and need submissions, card / QR / PDF, notifications and announcements, information architecture, visual direction, PWA direction, security baseline, auditability, logical schema concepts, phases, amendment register and open decisions. Review follow-up (same day): coordinator sign-in decided (FP-ADR-021), Family Portal palette decided (FP-ADR-022), PWA location decided (FP-ADR-023), National ID kept as the PWA-1 blocker (FP-ADR-024), proposals kept pending (PFP-022), §33a follow-ups. Documentation only |
| 1.1 | 2026-10-02 | Approved | PWA-1A findings and PWA-1B design: §30a identity and access architecture (verified Production aggregates, National ID decision, strict normalizer, `family_auth_identities`, User-Person Link, eligibility with ALIVE only, mobile trust, activation, OTP and password policy, nullable `users.email`, disjoint Staff/family accounts, coordinator must be an eligible head, multiple scopes, security audit, retention, Production gates, slices PWA-1C … PWA-1I); FP-ADR-025 … 035; PFP-002/003/004/005/016/020/021 decided, PFP-001/022 partly; FU-01 made a rollout gate. Documentation only |
| 1.2 | 2026-10-02 | Approved | PWA-1C implementation record in §30a: config, strict normalizers, keyed fingerprint service, seven migrations, six models with enums and factories, COORDINATOR role and ten permissions; the four pre-implementation refinements; staged activation of the coordinator assist permission; PWA-1C done, PWA-1D next. Foundation only |
| 1.3 | 2026-10-02 | Approved | PWA-1D implementation record in §30a: access resolver, authentication identity service, link lifecycle actions, National ID correction and death integration, security event recorder, session revocation, account sides and the Staff API boundary; `LINK_ENDED`; FP-ADR-036 and FP-ADR-037; PWA-1D done, PWA-1E next. No activation, login, OTP or UI |
| 1.3.1 | 2026-10-02 | Approved | PWA-1D hardening: the Staff API boundary fails closed — `AccountSide::STAFF` is required (FP-ADR-037 wording, docs/06 AUTH-ADR-066) |
| 1.4 | 2026-10-02 | Approved | PWA-1E implementation record in §30a: trusted-mobile resolver, grant / revoke and the Staff API, STALE semantics, SMS abstraction and drivers, OTP challenge service (resend, 10-minute grant), throttle ceilings, cleanup; the six-point Production gate; FP-ADR-038 … 040; PWA-1E done, PWA-1F next. No migration; no activation, login, reset or provider |
| 1.5 | 2026-10-02 | Approved | PWA-1F implementation record in §30a: Family API boundary and `/family/me`, public activation steps with decoys, limiters and the response floor, the completion transaction and session, the `(staff)` route group, the Family theme, the activation flow and the first shell; §25 layout as implemented; Production gate extended with PWA-1G (seven points); FP-ADR-041 … 043; PWA-1F done, PWA-1G next. No migration; activation disabled by default |
| 1.6 | 2026-10-03 | Approved | PWA-1G implementation record in §30a: Family login (identity lookup, Family context, generic failure, dummy hash, two-tier lockout), password reset on purpose-aware decoys, the reset transaction and session revocation, the 72-byte password ceiling, three independent gates, the login and forgot-password screens; Production gate items 8–9; FP-ADR-044 … 046; PWA-1G done, PWA-1H next. No migration; nothing enabled |
| 1.7 | 2026-10-03 | Approved | PWA-1H implementation record in §30a: coordinator resolver, administration actions and Staff API, `coordinator.space`, coordinator context and scoped summaries (`coordinator-family.view-summary` approved), `/family/me.coordinator_space`, Coordinator Space in the portal; PFP-022 updated; FP-ADR-047 … 049; PWA-1H done, PWA-1I next. No migration; assist withheld |
| 1.8 | 2026-10-03 | Approved | TweetsMS SMS delivery record in §30a: `FAMILY_SMS_DRIVER=tweetsms`, `05XXXXXXXX` as stored, success only on code 999 (accepted, not handset delivery), failure classification, no retry, after-response delivery without a queue, safe failure logging and event metadata, shorter one-part OTP message, `famboook:sms-check`; gate items 1/4/5 updated; PFP-001 decided; FU-03 updated; FP-ADR-050, 051. No migration, nothing enabled |
| 1.9 | 2026-10-03 | Approved | PWA-1I implementation record in §30a: decoy concurrency parity (atomic attempts, write-once supersession, one resend claim per send), IP / global ceiling parity for decoy resends (destination not mirrorable), response floor on verify and complete, attempts counted before the work (login, start), invisible-character normalization, `famboook:family-auth-check`, regression and PostgreSQL concurrency coverage, raw challenge IP retention noted; FP-ADR-052; PWA-1I done. No migration, nothing enabled |
| 1.10 | 2026-10-04 | Approved | First self-activation (FP-ADR-053): masked registered number confirmation (`start` → `send`), code sent only to the stored number, SELF_OTP trust created only by a correct code (pending row promoted under the Person lock), revoked numbers excluded, existing TRUSTED reused, decoys with a stable fake mask; supersedes FP-ADR-041 for the mask and the trust prerequisite. Migration: `SELF_OTP` allowed in the trust CHECKs |
| 1.11 | 2026-10-04 | Approved | FP-ADR-054 after the Production pilot: first activation refuses an input that cannot activate with one generic ACTIVATION_REFUSED answer (422) instead of a synthetic masked-mobile decoy; nothing is created; reasons stay server-side; the decoy clause of FP-ADR-053 is superseded (kept as history); password reset decoys unchanged |
| 1.12 | 2026-10-04 | Approved | FP-ADR-055: installable Family app — manifest and official icon, minimal service worker (scope /family, offline page only, no response or API caching), "تثبيت فامبوك" with native prompt or instructions; Staff unaffected |
| 1.13 | 2026-10-04 | Approved | Documentation and ADR consolidation: §9 Family Profile Review (V1 sections, derived completeness and states, confirmation, dedicated fingerprint key, Change Request relationship) and §11 Staff Family Verification kept separate (FP-ADR-057); §10 registered members = active memberships, living members named (FP-ADR-056); §13 no automatic eligibility (FP-ADR-058); §14 first-release request direction, RESIDENCE_UPDATE = correction, UNKNOWN → ALIVE prerequisite (FP-ADR-059); §23 portal structure; §25 routes; §28 concepts; §30 delivery order; §30a PWA-3A record; §31 A-15 … A-17; PFP-007 / 017 / 018 clarified, PFP-023 / 024; §33a FU-02 answered, FU-07 … FU-12. Documentation only |
| 1.14 | 2026-10-04 | Approved | FU-07 / PFP-024 resolved (FP-ADR-060): ConfirmPersonAliveAction (UNKNOWN → ALIVE only), Staff endpoint and action with a recorded verification method, UNKNOWN heads confirmed only by Staff; §14 PERSON_CORRECTION / CONFIRM_ALIVE apply target |
| 1.15 | 2026-10-05 | Approved | FU-10 resolved (FP-ADR-061): Staff death recording (verification method, explicit death date, irreversible in V1, head death allowed without succession — FU-01 still open) and Staff household declarations (`family.update`, Staff sources, stale-write protection); §9 Staff declaration path is a Profile Review prerequisite; §14 apply targets; FU-11 updated. No migration |
| 1.16 | 2026-10-05 | Approved | Full Data Visibility (FP-ADR-062): §23a principle, structure («حسابي» → «بياناتي الشخصية»), family-facing data per domain, internal-only data, self reveal (POST /api/v1/family/self/reveal) and member-reveal boundary (PWA-3B.4), display vocabulary, Profile Review and Change Request relationship; PFP-006 resolved; FP-ADR-056, §9 HEAD, §15, §23 and the §30a PWA-3A exclusions superseded in part (kept as history); §31 A-18; FU-13; PWA-3B in the phases. Documentation only |
| 1.17 | 2026-10-05 | Approved | PWA-3B.1 implementation record in §30a: GET /api/v1/family/self (SELF only, masked National ID and mobiles, no-store, private), shared MobileMask, «بياناتي الشخصية» at /family/account/me reached from the head's own member card; no reveal, no migration |
| 1.18 | 2026-10-05 | Approved | PWA-3B.2 implementation record in §30a: POST /api/v1/family/self/reveal (SELF only, one field, strict field mapping, prohibited targets, per-user throttle, no-store, private), SELF_SENSITIVE_REVEALED security event with the field code only (null reveals recorded too), per-field Eye on «بياناتي الشخصية» with transient component state only; no migration |
| 1.19 | 2026-10-05 | Approved | PWA-3B.3 implementation record in §30a: complete «أسرتي» (family registration, branch group, current declaration with sons / daughters / source, full current residence), member rows with marital status, death date, masked National ID and mobiles and membership start, in-page member detail sheet; null birth date wording fixed; no member reveal, no migration |
| 1.20 | 2026-10-05 | Approved | FU-13 resolved (FP-ADR-063): `member_ref`, the full 64-hex keyed HMAC-SHA256 of a family membership (MEMBER_REF domain separation, Family Auth key and versions), resolved only among the ACTIVE memberships of the family.context Family; never an id, never stored, never authorization; Change Requests store the internal membership id; implementation record in §30a; no migration |
| 1.21 | 2026-10-05 | Approved | PWA-3B.4 (FP-ADR-064): household-member sensitive reveal — POST /api/v1/family/household/members/{memberRef}/reveal resolved only inside the family.context Family, one generic 404 HOUSEHOLD_MEMBER_UNAVAILABLE, head's own values on the self path only, HOUSEHOLD_MEMBER_SENSITIVE_REVEALED event (target Person, field only), per-user family-member-reveal throttle 20 / minute and 120 / hour; implementation record in §30a; no migration |
| 1.22 | 2026-10-06 | Approved | FU-14 password reset Production readiness (FP-ADR-065): public runtime `GET /api/v1/family/auth/capabilities` (three global booleans, outside the gates, no-store, private); fail-closed «نسيت كلمة المرور؟» link and `/family/forgot-password` route; FP-ADR-045 automatic sign-in, head eligibility, no masked mobile and no trust creation explicitly preserved; realistic reset coverage; §33a FU-14; implementation record in §30a. No migration; password reset still disabled |
| 1.23 | 2026-10-06 | Approved | FU-15 Staff Mobile Trust Management UI (FP-ADR-066): Staff Person profile card on the existing PWA-1E API — backend state (six states incl. UNAVAILABLE), masked current mobile only, history, grant with the three Staff methods (never SELF_OTP, never a number, always a new row), revoke with a reason code; REVOKED never revived; trust and login lifecycles separate; §33a FU-15. Staff-side only, no Family Portal change, no migration |
| 1.24 | 2026-10-06 | Approved | PWA-3B.5 «حسابي» (FP-ADR-067): GET /api/v1/family/account (self only, no-store, private; activation date and current mobile trust state with the Family mask); /family/account with identity, access types, owner mobile trust labels (REVOKED «غير موثّق حاليًا»), Coordinator section from the coordinator context, «بياناتي الشخصية» entry and logout; «حسابي» in the navigation; Account Recovery not an account setting; §33a FU-16 Authenticated Change Password; implementation record in §30a. No migration |
| 1.25 | 2026-10-06 | Approved | PWA-3B.6 household health read visibility (FP-ADR-068): GET /api/v1/family/household/health (no client identifier, active memberships, allow-listed facts by member_ref, no-store, private); «الحالة الصحية» in the member sheet, member-card chip, «أسرتي» count line; empty data never means healthy; history visible; details and Staff metadata hidden; §23a HEALTH as implemented; implementation record in §30a. No migration |
| 1.26 | 2026-10-07 | Approved | PWA-3B.7 needs and received assistance read visibility (FP-ADR-069): GET /api/v1/family/household/needs and /assistance (no client identifier, allow-lists, no-store, private); /family/household/support from «أسرتي» with independent sections; FULFILLED ≠ delivery; only non-reversed INTERNAL deliveries are receipts; history stays with the Family; empty means nothing registered; §23a NEEDS / ASSISTANCE as implemented; implementation record in §30a. No migration |
| 1.27 | 2026-10-07 | Approved | PWA-8.2 Digital Family Card (FP-ADR-070, supersedes FP-ADR-017 in part; §31 A-19): Family-subject credential, `digital_credentials`, FC- card number ≠ family_code ≠ QR token, lazy + Staff issuance, revoke / reissue, public /verify/{token} with browser POST and one generic failure, /family/card, Staff panel; §18 and §19 annotated; PFP-010 and PFP-018 resolved; implementation record in §30a. PDF (PFP-011) is PWA-8.3 |
| 1.28 | 2026-10-07 | Approved | PWA-8.3 Digital Family Card PDF (FP-ADR-071): GET /api/v1/family/card/pdf for the existing ACTIVE credential only (no issuance, owner only, 404 / 409, no-store), mPDF ^8.3 (GPL-2.0-only, server-side) with bundled IBM Plex Sans Arabic (OFL), shared FamilyCardView, in-memory generation, no download audit; PFP-011 resolved; §20 annotated; implementation record in §30a |
| 1.29 | 2026-10-07 | Approved | PWA-5a Change Request foundation (FP-ADR-072): approved order kept (PWA-4 after PWA-6); family-side transitions and CANCELLED; Family-subject request visibility; member_ref targets; separate family / Staff text; «+» and «طلباتي» stay disabled until PWA-6.1; §17, §28 and FU-04 updated; implementation record in §30a |
| 1.30 | 2026-10-07 | Approved | PWA-5b Change Request engine: implementation record in §30a — Domain Actions, family-context submissions and Family-subject ownership, handler contract and empty Production registry, replays, activity boundary; Family Portal unchanged |
| 1.31 | 2026-10-08 | Approved | PWA-5c Staff Change Request API: implementation record in §30a — routes and permissions, queue filters, review view and timeline, internal-note visibility, mutation bodies and outcome, error mapping, empty-registry behaviour, PWA-5d activity-label dependency; Family Portal unchanged |
| 1.32 | 2026-10-08 | Approved | PWA-5d Staff review workspace: implementation record in §30a — queue and review screens, status vocabulary, proposal rendering and the PWA-6.1 presentation-contract proposal, timeline, permission-aware actions, Change Request activity labels; Family Portal unchanged |
| 1.33 | 2026-10-08 | Approved | PWA-5e Family Change Request API (FP-ADR-073): routes, Family-subject history and isolation, submission switch (default off), type discovery, family-safe views and timeline, approved presentation shape and the PWA-6.1 presentation-context prerequisite; implementation record in §30a |
| 1.34 | 2026-10-08 | Approved | PWA-5f Family request views: implementation record in §30a — «طلباتي», request detail and «طلب جديد» discovery on the PWA-5e API, built but not linked («+» and «طلباتي» stay disabled until PWA-6.1); no backend change |
| 1.35 | 2026-10-08 | Approved | PWA-6.1 RESIDENCE_UPDATE: implementation record in §30a — V1 field set, strict input, changed-fields proposal, base fingerprint, APPLY through UpdateFamilyResidenceAction, `{rows}` presentation and the server-computed presentation context; §14 status; PFP-025 navigation activation left open (navigation stays disabled, switch off) |
| 1.36 | 2026-10-08 | Approved | PWA-6.1a: FP-ADR-074 Family navigation activation («طلباتي» always, «+» from server type discovery); PFP-025 resolved; §23 navigation map; implementation record in §30a; residence documentation reconciled in docs/01, 02, 04, 05 |
| 1.37 | 2026-10-08 | Approved | PWA-6.1b: FP-ADR-075 controlled pilot submission gate — modes OFF / PILOT / GENERAL, server-only Family allowlist, FamilySubmissionPolicy enforced in SubmitChangeRequestAction, per-Family type discovery, legacy boolean never opens; implementation record in §30a |
