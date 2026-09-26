# Famboook
## Permissions & Authorization

**Document:** `06-PERMISSIONS.md`  
**Version:** 1.2.11  
**Status:** Approved  
**Last Updated:** 2026-09-25  
**Project:** Famboook — Family Registry & Case Management System

---

# 1. Purpose

This document defines the authorization and access-control architecture for Famboook.

It defines:

- Authentication vs authorization
- Roles
- Permissions
- Data scopes
- Object-level authorization
- Field-level authorization
- Workflow-aware authorization
- Family Portal authorization
- User-Person linking
- Staff authorization
- System Administration authorization
- Sensitive-data access
- API authorization
- File authorization
- Export authorization
- Testing requirements

The goal is to ensure that every actor sees and performs only what they are explicitly authorized to access.

---

# 2. Authorization Authority

Laravel is the authoritative authorization layer.

All application interfaces must use the same authorization model.

```text
Next.js Staff Application
        │
        ▼
Laravel API
        │
        ▼
Policies + Permissions + Data Scope
        │
        ▼
Domain Actions
```

```text
Next.js Family Portal
        │
        ▼
Laravel API
        │
        ▼
Policies + Family Access Policy
        │
        ▼
Domain Actions
```

```text
Filament System Administration
        │
        ▼
Laravel
        │
        ▼
Policies + Permissions
        │
        ▼
Domain Actions
```

No interface defines an independent authorization system.

---

# 3. Authorization Model

Famboook authorization is not based on roles alone.

For Staff:

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

For Family Users:

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

# 4. Authentication vs Authorization

Authentication answers:

```text
Who are you?
```

Authorization answers:

```text
What are you allowed to do?
```

A successfully authenticated user is not automatically authorized to access a Family, Person, Document, or workflow action.

Therefore:

```text
Authenticated
≠
Authorized
```

---

# 5. Authentication Identity vs Registry Identity

Famboook separates:

```text
User
```

from:

```text
Person
```

`users` represent authentication identities.

`persons` represent people in the Family Registry.

They are not the same entity.

---

# 6. User-Person Link

Family Portal access requires an explicit relationship:

```text
User
 ↓
User-Person Link
 ↓
Person
```

A User-Person Link must be verified according to approved identity-verification rules.

---

# 7. Family Authorization Resolution

Family Portal authorization is dynamically resolved:

```text
Authenticated User
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

The system must not rely on:

```text
users.family_id
```

as canonical Family authorization.

---

# 8. Authorization Technologies

The primary authorization implementation uses:

```text
Laravel Policies

Laravel Gates where appropriate

Spatie Laravel Permission

Query/Data Scopes

Domain Actions

Laravel API Resources
```

Each mechanism has a different responsibility.

---

# 9. Spatie Permission Responsibility

Spatie Permission manages:

```text
Roles

Permissions

Role-Permission Assignment

User-Role Assignment

Direct Permission Assignment where explicitly needed
```

It does not replace:

```text
Object-Level Authorization

Family Scope Resolution

Field-Level Authorization

Workflow Rules

Domain Rules
```

---

# 10. Laravel Policies

Policies are the primary object-level authorization mechanism.

Recommended policies include:

```text
FamilyPolicy

PersonPolicy

FamilyMembershipPolicy

FamilyResidencePolicy

AssessmentPolicy

FormSubmissionPolicy

ChangeRequestPolicy

DocumentPolicy

FamilyNeedPolicy

AssistanceRecordPolicy

UserPersonLinkPolicy

UserPolicy

ReportPolicy

ExportPolicy
```

---

# 11. Domain Action Authorization

Critical Domain Actions must not assume that controller authorization is sufficient.

Where appropriate, actions should receive authorization context or independently verify required permission/domain conditions.

This provides defense in depth.

---

# 12. Frontend Authorization

Next.js may use permission information to improve UX.

Examples:

```text
Hide Create Family button

Disable unavailable workflow action

Hide System Administration link

Display read-only view
```

However:

```text
Frontend permission checks
≠
Security enforcement
```

Laravel remains authoritative.

---

# 13. `/api/v1/me`

The API may expose an authenticated-user context endpoint:

```text
GET /api/v1/me
```

Possible response concepts:

```text
User

Roles

Permissions

Portal Type

Selected UX capabilities
```

This information supports frontend rendering.

It must not be treated as permanent authorization proof.

---

# 14. Staff Roles

Initial internal roles:

```text
SUPER_ADMIN

ADMINISTRATOR

DATA_ENTRY

REVIEWER

SOCIAL_WORKER

REPORTS_VIEWER
```

External role:

```text
FAMILY_USER
```

---

# 15. SUPER_ADMIN

`SUPER_ADMIN` is the highest technical/system role.

Typical responsibilities may include:

```text
System configuration

User administration

Role administration

Permission administration

Reference data administration

Technical monitoring

System maintenance
```

SUPER_ADMIN does not mean business rules should be bypassed.

---

# 16. ADMINISTRATOR

`ADMINISTRATOR` represents high-level operational administration.

Potential responsibilities:

```text
Manage authorized registry operations

Manage workflows

Manage selected users

Review operational queues

Access broad reports

Perform approved high-impact operations
```

Exact permissions are explicitly assigned.

---

# 17. DATA_ENTRY

Typical responsibilities:

```text
Create Family drafts

Create Person drafts

Enter source forms

Edit permitted draft data

Submit completed data for review

Respond to correction requests
```

DATA_ENTRY does not automatically receive:

```text
Approval

Sensitive exports

Role administration

System settings
```

---

# 18. REVIEWER

Typical responsibilities:

```text
Review submitted data

Return for correction

Verify permitted records

Review Change Requests

Review documents

Review duplicates
```

Approval permissions remain separately assignable.

---

# 19. SOCIAL_WORKER

Typical responsibilities:

```text
View authorized Family profiles

Conduct Assessments

Identify Needs

Record permitted Case Notes

Record/track Assistance

View permitted health/social information
```

Sensitive access remains explicitly controlled.

---

# 20. REPORTS_VIEWER

Typical responsibilities:

```text
View approved dashboards

View approved reports

Access aggregate data
```

This role does not automatically receive:

```text
Canonical edit permissions

Sensitive row-level exports

System administration
```

---

# 21. FAMILY_USER

`FAMILY_USER` is an external self-service role.

It may provide access to:

```text
Family Portal

Permitted Family information

Permitted member information

Own Change Requests

Supporting document uploads

Notifications
```

It does not provide Staff access.

---

# 22. Role vs Domain Status

`FAMILY_USER` must not be replaced with:

```text
HOUSEHOLD_HEAD
```

as an authorization role.

Household Head is a domain state.

FAMILY_USER is an authorization role.

Conceptually:

```text
User Role
FAMILY_USER

Person Membership State
is_household_head = TRUE
```

These remain separate.

---

# 23. V1 Family User Eligibility

Recommended V1 eligibility:

```text
Verified current Household Head
```

Therefore Family-wide access may require:

```text
User status = ACTIVE

User has FAMILY_USER role

User-Person Link = ACTIVE

Person = ACTIVE

Family Membership = ACTIVE

is_household_head = TRUE

Family = accessible
```

Future policies may support authorized representatives or guardians.

---

# 24. Role Does Not Grant Family Scope

This is prohibited:

```text
User has FAMILY_USER
        ↓
Can access every Family
```

Correct:

```text
FAMILY_USER
    +
Verified User-Person Link
    +
Current Membership
    +
Family Access Policy
    ↓
Specific authorized Family
```

---

# 25. Staff Data Scopes

Initial Staff data scopes may include:

```text
ALL

ASSIGNED

CREATED_BY_ME
```

Future scopes may include:

```text
REGION

BRANCH

TEAM

CASELOAD
```

---

# 26. ALL Scope

`ALL` means the user may access all records of the permitted resource type subject to:

```text
Permission

Field restrictions

Workflow restrictions

Domain restrictions
```

It does not mean unrestricted system access.

---

# 27. ASSIGNED Scope

`ASSIGNED` limits access to records assigned to the user or their authorized work queue.

Exact assignment model may differ by module.

---

# 28. CREATED_BY_ME Scope

`CREATED_BY_ME` limits relevant operations to records created by the authenticated user.

This may be useful for draft/data-entry workflows.

---

# 29. Family Portal Data Scope

Family Portal uses:

```text
SELF

FAMILY
```

rather than Staff scopes.

`SELF` means data belonging to the linked Person.

`FAMILY` means permitted information within the currently authorized Family.

---

# 30. Object-Level Authorization

Every protected object requires authorization.

Example:

```text
GET /api/v1/families/100
```

must verify access to Family `100`.

Knowing the ID is not authorization.

---

# 31. IDOR Protection

The application must explicitly protect against Insecure Direct Object Reference attacks.

Example:

```text
Family User belongs to Family 100

User changes URL:

/families/100
→
/families/101
```

Laravel must reject unauthorized access to Family 101.

---

# 32. Query Scoping

Authorization should begin at query level where practical.

Example concept:

```text
Family::query()
    ->visibleTo($user)
```

This reduces accidental retrieval of unauthorized objects.

Policies must still protect direct object operations.

---

# 33. Field-Level Authorization

Famboook supports three conceptual visibility levels:

```text
FULL

MASKED

HIDDEN
```

---

# 34. FULL

The authorized user receives the full value.

Example:

```text
National ID:
123456789
```

---

# 35. MASKED

Only a protected representation is exposed.

Example:

```text
National ID:
*****6789
```

Exact masking policy remains configurable.

---

# 36. HIDDEN

The field is not exposed.

Prefer:

```text
Field omitted
```

rather than sending the sensitive value and hiding it with CSS.

---

# 37. API Field Security

Sensitive fields must be filtered server-side.

Prohibited:

```text
Laravel sends national_id
        ↓
React hides it
```

Correct:

```text
Laravel authorization
        ↓
API Resource decides exposure
        ↓
Unauthorized field never leaves server
```

---

# 38. Person Field Classification

Example baseline:

| Field | Staff Authorized | Family User Self | Other Family Member |
|---|---|---|---|
| Person Code | FULL | FULL | FULL |
| Full Name | FULL | FULL | FULL |
| Gender | FULL | FULL | FULL |
| Birth Date | FULL | FULL | FULL |
| Life Status | FULL | FULL | FULL |
| Relationship | FULL | FULL | FULL |
| Mobile | Permission-based | FULL | HIDDEN by default |
| Alternate Mobile | Permission-based | FULL | HIDDEN by default |
| National ID | Permission-based | MASKED by default | HIDDEN |
| Health | Restricted | Policy-based | HIDDEN by default |
| Disability | Restricted | Policy-based | HIDDEN by default |
| Staff Notes | Permission-based | HIDDEN | HIDDEN |
| Audit Metadata | Permission-based | HIDDEN | HIDDEN |

Final visibility may be further restricted.

---

# 39. National ID Permission

Recommended permissions:

```text
person.national-id.view

person.national-id.view-masked

person.national-id.update
```

A general:

```text
person.view
```

must not automatically expose full National ID.

---

# 40. Health Record Permissions

Approved 2026-09-24 (AUTH-ADR-048). These replace the former `health.*` and
`disability.*` names, which were never assigned. They cover all V1 person
health records (disability, chronic disease, pregnancy, breastfeeding;
docs/02 §22):

```text
health-record.view

health-record.create

health-record.update     correct an existing record

health-record.close      end an active record (sets ended_at)
```

There is no delete permission. V1 has no hard delete of health records.

## V1 Role Assignment

```text
health-record.view / .create / .update / .close
  SUPER_ADMIN
  ADMINISTRATOR
  DATA_ENTRY

health-record.view only
  REVIEWER
  SOCIAL_WORKER

no person-level health-record access
  REPORTS_VIEWER   (may later receive aggregate-only reporting)
  FAMILY_USER      (no health access in V1)
```

Health access never comes from `person.view` or `person.update`. Health data
is returned only by the health-record endpoints, never by the generic family,
person or search responses. It never includes the National ID.

Family Portal health visibility is controlled separately.

---

# 41. Disability Permissions

Superseded in V1 by §40. Disability records are health records, governed by
`health-record.*`.

---

# 42. Confidential Notes

Recommended:

```text
case-note.view

case-note.create

case-note.update

case-note.delete

confidential-note.view
```

Family Users must not receive `confidential-note.view`.

---

# 43. Family Permissions

Recommended:

```text
family.view

family.create

family.update

family.archive

family.restore

family.change-household-head

family.view-history
```

---

# 44. Person Permissions

Recommended:

```text
person.view

person.create

person.update

person.archive

person.view-history

person.record-death

person.correct
```

## V1 Role Assignment

Approved 2026-09-24 (AUTH-ADR-047):

```text
person.update
  SUPER_ADMIN
  ADMINISTRATOR
  DATA_ENTRY
```

`person.update` covers ordinary correction of basic Person data, including
the current household head's basic information: name, gender, birth date,
mobile, alternate mobile and its owner/relation.

It does **not** grant:

- National ID viewing or editing. These need the dedicated
  `person.national-id.*` permissions (§39), which stay unassigned.
- Life status or death recording. These need `person.record-death` and the
  controlled death operation (§96; docs/03 §30).
- Household-head or membership changes (§97, §98).

REVIEWER, SOCIAL_WORKER, REPORTS_VIEWER and FAMILY_USER do not receive it.

---

# 45. Membership Permissions

Recommended:

```text
family-membership.view

family-membership.create

family-membership.update

family-membership.end

family-membership.transfer
```

---

# 46. Residence Permissions

Recommended:

```text
residence.view

residence.create

residence.update

residence.change

residence.view-history
```

## V1 Role Assignment

Approved 2026-09-23 (Edit Family Residence slice, AUTH-ADR-046):

```text
residence.update
  SUPER_ADMIN
  ADMINISTRATOR
  DATA_ENTRY
  SOCIAL_WORKER
```

These are the roles the §140 matrix already lets "Update Canonical Family".
The residence is part of the canonical Family record.

`residence.update` covers **data correction** of the current residence in
place (docs/03 §56): address fields, original residence and displacement
status/location. It does not create, end or delete residence records.

`residence.change` covers a **real-world move** (end the current residence,
create a new one, preserve history). It stays unassigned until that
operation is built.

---

# 47. Assessment Permissions

Recommended:

```text
assessment.view

assessment.create

assessment.update

assessment.complete

assessment.review

assessment.verify

assessment.approve
```

## V1 Role Assignment

Approved 2026-09-24 (Quick Multi-Domain Family Assessment V1,
AUTH-ADR-050):

```text
assessment.view
  SUPER_ADMIN, ADMINISTRATOR, DATA_ENTRY, REVIEWER, SOCIAL_WORKER

assessment.create / assessment.update / assessment.complete
  SUPER_ADMIN, ADMINISTRATOR, DATA_ENTRY, SOCIAL_WORKER
```

- SOCIAL_WORKER is an operational assessment role and performs family
  assessments.
- REVIEWER has view only.
- REPORTS_VIEWER receives no family-level assessment detail in V1.
- FAMILY_USER has no assessment access in V1 (no Family Portal
  assessments).
- `assessment.review`, `assessment.verify` and `assessment.approve` stay
  unassigned: the V1 lifecycle is DRAFT → COMPLETED only.
- There is no assessment delete permission.
- `family.update` is **not** a substitute for any assessment permission.
- Completing with a final draft payload requires both
  `assessment.complete` and `assessment.update`.
- `GET /reference/assessment-domains` accepts `reference-data.view` **or**
  `assessment.view`, because roles that assess families (e.g.
  SOCIAL_WORKER, REVIEWER) do not hold `reference-data.view`. This grants
  no other reference data.
- Assessment Activity Log events are shown only to holders of
  `assessment.view` (in addition to `activity-log.view`).

---

# 48. Form Permissions

Recommended:

```text
form.view

form.create

form.update

form.submit

form.review

form.return

form.verify

form.approve
```

---

# 49. Need Permissions

Recommended:

```text
need.view

need.create

need.update

need.close

need.cancel
```

## V1 Role Assignment

Approved 2026-09-24 (Needs Management V1, AUTH-ADR-051):

```text
need.view
  SUPER_ADMIN, ADMINISTRATOR, DATA_ENTRY, REVIEWER, SOCIAL_WORKER

need.create / need.update / need.close
  SUPER_ADMIN, ADMINISTRATOR, DATA_ENTRY, SOCIAL_WORKER
```

- **`need.close` governs both resolution operations** in V1 — marking a
  Need FULFILLED and CLOSING it with a reason. No separate `need.resolve`
  permission is introduced, to avoid overlapping the canonical catalog.
- `need.cancel` remains unassigned (V1 has no separate cancelled state;
  CLOSED covers it).
- REVIEWER has view only. REPORTS_VIEWER receives no family-level Need
  detail in V1. FAMILY_USER has no Needs access in V1 (see §124).
- There is no Need delete permission.
- `family.update` is **not** a substitute for any Need permission.
- `GET /reference/need-categories` accepts `reference-data.view` **or**
  `need.view`.
- Need Activity Log events are shown only to holders of `need.view`.
- Choosing an Assessment as a Need's source uses the assessment list and
  therefore also requires `assessment.view`; the Need response exposes only
  the source's id, date and status.

---

# 50. Assistance Permissions

Recommended:

```text
assistance.view

assistance.create

assistance.update

assistance.open

assistance.nominate

assistance.reverse
```

Deletion of Assistance should generally be avoided if it represents an actual historical event.

## V1-A Role Assignment

Approved 2026-09-24 (Assistance V1-A, AUTH-ADR-052).

`assistance.open` (DRAFT → OPEN) and `assistance.nominate` (targeting
preview, candidate search, all nominations and nominee removal) were
added: the catalog had no equivalent, and nominating must be grantable
without the right to edit program definitions. `assistance.reverse`
remains for V1-B delivery corrections and is unassigned.

```text
assistance.view       SUPER_ADMIN, ADMINISTRATOR, DATA_ENTRY, SOCIAL_WORKER, REVIEWER
assistance.create     SUPER_ADMIN, ADMINISTRATOR, DATA_ENTRY
assistance.update     SUPER_ADMIN, ADMINISTRATOR, DATA_ENTRY
assistance.open       SUPER_ADMIN, ADMINISTRATOR
assistance.nominate   SUPER_ADMIN, ADMINISTRATOR, DATA_ENTRY, SOCIAL_WORKER
```

- SOCIAL_WORKER: view, targeting preview and nomination; no definition
  editing.
- DATA_ENTRY: definitions and nomination; opening a program is left to
  administrators.
- REVIEWER: view only (including nominees).
- REPORTS_VIEWER and FAMILY_USER: no Assistance access in V1-A.
- No assistance delete permission.
- `GET /reference/assistance-categories` accepts `reference-data.view` or
  `assistance.view`.
- Nomination Activity Log events require `assistance.view` in addition to
  `activity-log.view`.
- Targeting evaluates sensitive data server-side; the preview exposes only
  minimal indicators (docs/03 §47b).

## V1-B Role Assignment

Approved 2026-09-24 (Assistance V1-B, AUTH-ADR-053). Added only what was
missing: `assistance.approve`, `assistance.deliver`,
`assistance.complete`, `assistance.export`, `assistance.export-sensitive`.
The existing `assistance.reverse` is now assigned.

```text
assistance.approve            SUPER_ADMIN, ADMINISTRATOR, SOCIAL_WORKER     (approve + reject)
assistance.deliver            SUPER_ADMIN, ADMINISTRATOR, SOCIAL_WORKER, DATA_ENTRY
                              (verify identity, record delivery, NOT_DELIVERED; INTERNAL only)
assistance.reverse            SUPER_ADMIN, ADMINISTRATOR
assistance.complete           SUPER_ADMIN, ADMINISTRATOR
assistance.export             SUPER_ADMIN, ADMINISTRATOR                    (configure, preview, issue, view, download)
assistance.export-sensitive   SUPER_ADMIN, ADMINISTRATOR                    (additionally required for SENSITIVE fields)
```

- SOCIAL_WORKER: operational approval and delivery; no export.
- DATA_ENTRY: records deliveries (a data-entry act performed with identity
  verification) but does not approve, export or complete.
- REVIEWER: view only (including issued-list metadata, not their values).
- REPORTS_VIEWER / FAMILY_USER: none.
- `assistance.deliver` never implies `person.national-id.view`: typed IDs
  are verified against the expected persons only. Likewise
  `assistance.export-sensitive` exports National IDs only inside an
  authorized issued list; it grants no general National ID access.

---

# 51. Document Permissions

Recommended:

```text
document.view

document.upload

document.update

document.verify

document.reject

document.download

document.delete
```

Download is a distinct permission from metadata viewing where appropriate.

---

# 52. Change Request Permissions

Recommended:

```text
change-request.view

change-request.create

change-request.update-own-draft

change-request.submit

change-request.review

change-request.return

change-request.resubmit

change-request.approve

change-request.reject

change-request.apply

change-request.view-internal-notes
```

---

# 53. Family User Change Request Permissions

FAMILY_USER may receive:

```text
change-request.create

change-request.update-own-draft

change-request.submit

change-request.resubmit
```

and scoped view access.

It must not receive:

```text
change-request.review

change-request.approve

change-request.reject

change-request.apply

change-request.view-internal-notes
```

---

# 54. User Administration Permissions

Recommended:

```text
user.view

user.create

user.update

user.activate

user.suspend

user.reset-access

user.link-person

user.verify-person-link
```

---

# 55. Role Administration Permissions

Recommended:

```text
role.view

role.create

role.update

role.delete

role.assign

permission.view

permission.assign
```

These permissions should be restricted to System/High Administration.

---

# 56. Reference Data Permissions

Recommended:

```text
reference-data.view

reference-data.create

reference-data.update

reference-data.deactivate
```

Reference data should generally be deactivated rather than destructively deleted when already used.

## V1 Role Assignment

Approved 2026-09-23 (Family Relationships slice, AUTH-ADR-045):

```text
reference-data.view
  SUPER_ADMIN
  ADMINISTRATOR
  DATA_ENTRY

reference-data.create
reference-data.update
reference-data.deactivate
  SUPER_ADMIN
```

`reference-data.view` grants read access to active reference values only
(for example, the relationship-type options in Add Family Member). It exposes
no personal or sensitive data. Roles that create or edit canonical records
need it to select valid coded values.

Other roles (REVIEWER, SOCIAL_WORKER, REPORTS_VIEWER, FAMILY_USER) do not
receive it by default in V1. A later decision can grant it when one of those
roles gets a workflow that selects reference values.

---

# 56a. Clan / Branch Structure Permissions

Approved 2026-09-25 (Clan + Branch Structure V1, AUTH-ADR-054):

```text
clan.view
  SUPER_ADMIN
  ADMINISTRATOR
  DATA_ENTRY
  SOCIAL_WORKER

clan.manage
  SUPER_ADMIN
  ADMINISTRATOR
```

One pair covers the whole Clan → Branch Group → Branch structure.

`clan.view` reads the active structure so staff who register or correct
Families (`family.create` / `family.update`) can select a Clan and Branch. It
exposes no personal data. `reference-data.view` was not reused because
SOCIAL_WORKER, who holds `family.update`, does not have it.

`clan.manage` creates, renames, reorders and activates/deactivates Clans,
Branch Groups and Branches, and reads the full tree including inactive items
(`include_inactive=1`). `reference-data.create/update` was not reused because
it is SUPER_ADMIN-only while ADMINISTRATOR must manage this structure.

No permission deletes a structure: none can be hard-deleted.

A Family's own Clan/Branch is shown to anyone who may view the Family
(`family.view`); `clan.view` is needed only for the selectors.

---

# 57. Audit Permissions

Recommended:

```text
audit.view

audit.view-sensitive
```

Audit access is highly restricted.

---

# 57a. Family Activity Log Permission

Approved 2026-09-24 (Family Activity Log V1, AUTH-ADR-049):

```text
activity-log.view
```

Grants read access to the family-scoped Family Activity Log
(docs/03 §97a): the system-generated timeline of successful family Domain
Actions shown in the Staff App's Family Profile "السجل" tab.

It is deliberately separate from:

- `audit.view` / `audit.view-sensitive` (§57): the restricted full audit
  (previous/new values, context). The activity log carries no values, so
  operational staff can read it without being granted audit access.
- `family.view-history` / `person.view-history` / `residence.view-history`
  (§43, §44, §46): viewing historical canonical state (ended memberships,
  past residences). These remain unassigned.

There are no `activity-log.create/update/delete` permissions. Activity is
written only by Domain Actions and is never edited or deleted through any
application.

Health events are additionally filtered by `health-record.view` (§40): a
holder of `activity-log.view` without it does not see HEALTH_RECORD_*
entries at all.

## V1 Role Assignment

```text
activity-log.view
  SUPER_ADMIN
  ADMINISTRATOR
  DATA_ENTRY
  REVIEWER
  SOCIAL_WORKER
```

REPORTS_VIEWER receives no person/family activity detail in V1.
FAMILY_USER has no access in V1 (no Family Portal activity).

---

# 58. Workflow History Permission

Recommended:

```text
workflow-history.view
```

Family Users should not receive unrestricted internal workflow history.

They receive a simplified request timeline instead.

---

# 59. Report Permissions

Recommended:

```text
report.view

report.view-sensitive

dashboard.view-operational

dashboard.view-executive
```

---

# 59a. Operational Dashboard Permission

Approved 2026-09-25 (Operational Dashboard V1, AUTH-ADR-055). The existing
catalog permission is reused; no new permission was added.

```text
dashboard.view-operational
  SUPER_ADMIN
  ADMINISTRATOR
  DATA_ENTRY
  REVIEWER
  SOCIAL_WORKER
  REPORTS_VIEWER
```

FAMILY_USER does not receive it. It also covers the dashboard's own
organizational scope list, so viewers need no `clan.view`.

The dashboard is not a permission bypass. A section is computed only when
the user holds the underlying domain permission; otherwise the API returns
it as `null` (never computed, not merely hidden in the frontend):

```text
Active Families, Displaced Families, Displacement   family.view
Current People, Demographics                        family.view + person.view
Health                                              + health-record.view
Open Needs KPI, Needs                               need.view
Assessments                                         family.view + assessment.view
Assistance                                          assistance.view
Recent Activity                                     activity-log.view, with the
                                                    same event-level visibility as
                                                    the Family timeline (health,
                                                    assessment, Need and
                                                    assistance events need their
                                                    domain view permission)
```

---

# 59b. Reports Permission

Approved 2026-09-26 (Reports V1, AUTH-ADR-056). Existing catalog
permissions are reused; no new permission was added.

```text
report.view
  SUPER_ADMIN
  ADMINISTRATOR
  DATA_ENTRY
  REVIEWER
  SOCIAL_WORKER
  REPORTS_VIEWER

export.basic (existing assignment, unchanged)
  SUPER_ADMIN
  ADMINISTRATOR
  REPORTS_VIEWER
```

FAMILY_USER receives neither. `report.view` opens the Reports area and
its organizational scope list; it grants no domain access by itself. Each
report additionally requires ALL of its domain view permissions, enforced
by the API (403), not only hidden in the frontend:

```text
Population & Families   family.view + person.view
Health                  person.view + health-record.view
Needs                   family.view + need.view
Assessments             family.view + assessment.view
Assistance              assistance.view
Data Quality            family.view + person.view
```

XLSX export requires the report's permissions plus `export.basic`.
`report.view-sensitive` and `export.sensitive` / `export.identity-data` /
`export.health-data` remain unassigned: no report exposes National IDs,
phone numbers, health details or other sensitive values, so none is
needed.

REPORTS_VIEWER therefore sees Population & Families and Data Quality (and
may export them). It keeps no Need, Assessment, Assistance or health-record
access (AUTH-ADR-048, -050, -051, -052), so those reports stay locked for
it; Reports do not widen that decision.

---

# 60. Export Permissions

Recommended:

```text
export.basic

export.sensitive

export.identity-data

export.health-data
```

Export authorization is separate from ordinary screen viewing.

---

# 61. Import Permissions

Recommended:

```text
import.upload

import.validate

import.review

import.apply
```

Uploading a file must not imply permission to apply data to the canonical registry.

---

# 62. System Administration Permissions

Recommended:

```text
system.settings.view

system.settings.update

system.jobs.view

system.jobs.manage

system.health.view

system.reference-data.manage
```

---

# 63. Filament Access

Filament is restricted to:

```text
System Administration

High Administration
```

It is not the normal Staff operational interface.

Users require explicit permission to access Filament.

Example:

```text
system-admin.access
```

---

# 64. Filament Does Not Bypass Authorization

Filament access does not imply unrestricted access to all Models.

Resources/pages/actions must still use:

```text
Policies

Permissions

Domain Actions
```

where appropriate.

---

# 65. SUPER_ADMIN Boundary

SUPER_ADMIN may receive broad technical privileges.

However, even SUPER_ADMIN should not silently bypass:

```text
Audit

Critical transactions

Workflow history

Database constraints

Domain invariants
```

Technical power does not erase data integrity.

---

# 66. Workflow-Aware Authorization

Permission alone does not guarantee an action is currently allowed.

Example:

```text
User has change-request.approve
```

but request status is:

```text
DRAFT
```

Approval must be denied.

Authorization therefore includes:

```text
Permission
+
Workflow State
```

---

# 67. Maker-Checker Authorization

Where maker-checker applies:

```text
Actor A creates/submits
```

and:

```text
Actor A attempts incompatible review/approval
```

the operation must be rejected even if Actor A holds the general permission.

---

# 68. Family User Ownership

Family User Change Request access requires both:

```text
Authorized Family Scope
```

and where applicable:

```text
Request ownership / allowed relationship
```

A Family User must not access another Family's requests.

---

# 69. Family User DRAFT Access

Family User may edit:

```text
Own authorized DRAFT
```

but not another user's draft unless future shared-family-user policy explicitly permits it.

---

# 70. Submitted Request Editing

Once submitted, unrestricted editing is prohibited.

The workflow determines whether the user may respond through clarification/resubmission.

---

# 71. Change Request Staff Notes

Internal fields such as:

```text
review_notes
```

must be STAFF_ONLY unless explicitly separated into Family-visible content.

---

# 72. Family-Visible Clarification

Clarification text intended for Family Users should have a dedicated safe representation.

Do not reuse confidential internal notes as Family-visible messages.

---

# 73. Document Object Authorization

Document access must consider:

```text
Actor

Document Context

Family

Person

Change Request

Document Type

Field Sensitivity

Workflow State
```

A valid document ID is not sufficient.

---

# 74. Private File Download

Recommended flow:

```text
Browser
  ↓
Laravel authorized endpoint
  ↓
DocumentPolicy
  ↓
Private Storage
  ↓
Controlled response / temporary delivery
```

Not:

```text
Browser
  ↓
Public storage path
```

---

# 75. Signed URLs

If temporary signed storage URLs are later used:

```text
Authorization must occur before URL issuance.
```

URLs should:

```text
Expire

Be scoped

Avoid unnecessary exposure
```

---

# 76. Search Authorization

Search results must obey authorization.

Example:

A user without access to Family B must not discover Family B through search suggestions.

Search authorization includes both:

```text
Row filtering
+
Field filtering
```

---

# 77. Family User Search

FAMILY_USER must not receive global:

```text
Person Search

Family Search

National ID Search
```

Family Portal search is restricted to the authorized Family context where needed.

---

# 78. Reports Authorization

Reports must apply:

```text
Permission

Data Scope

Sensitive Field Rules
```

A report must not become an authorization bypass.

---

# 79. Executive Dashboard

Executive Dashboard is a custom Next.js interface.

It uses Laravel APIs and authorization like every other frontend.

Executive visualization does not imply unrestricted underlying record access.

---

# 80. Aggregate Data

Aggregate reporting may expose statistics without exposing underlying restricted identities.

Example:

```text
Number of families with identified need
```

may be permitted while the viewer cannot access each Family's confidential case details.

---

# 81. Drill-Down

Dashboard drill-down requires separate authorization.

Permission to see:

```text
125 Families
```

does not automatically grant permission to open all 125 Family records.

---

# 82. Export Authorization

Exports are high-risk because they allow bulk extraction.

Therefore:

```text
View Permission
≠
Export Permission
```

Sensitive exports may require additional approval or audit.

---

# 83. Export Audit

Exports should record:

```text
Actor

Export Type

Scope

Timestamp

Relevant filters

Record count where appropriate
```

Sensitive values should not be duplicated unnecessarily into logs.

---

# 84. Import Authorization

Import workflow separates:

```text
Upload

Validate

Review

Apply
```

Different permissions may be assigned to each stage.

---

# 85. API Authorization

Every protected API endpoint requires server-side authorization.

Routes must not rely solely on frontend route guards.

---

# 86. API Route Guards

Authentication middleware verifies the session.

Policies/permissions verify the requested operation.

Conceptually:

```text
Sanctum Authentication
       ↓
Permission / Policy
       ↓
Domain Rules
```

---

# 87. Sanctum Boundary

Laravel Sanctum provides first-party web authentication.

A valid Sanctum session means:

```text
User identity is authenticated
```

It does not mean:

```text
User may access every API endpoint
```

---

# 88. No localStorage Authorization Token

The primary web architecture does not store authentication bearer tokens in:

```text
localStorage
```

Authorization is still performed server-side for every protected request.

---

# 89. CSRF

State-changing first-party cookie-authenticated requests must follow Laravel/Sanctum CSRF protections.

The frontend must not bypass CSRF protection.

---

# 90. CORS

CORS configuration must explicitly allow only approved application origins.

Initial topology:

```text
famboook.com

api.famboook.com

admin.famboook.com
```

CORS is not authorization.

---

# 91. Subdomain Authentication

If cookie/session authentication spans approved Famboook subdomains, configuration must deliberately define:

```text
Cookie Domain

Secure Cookies

SameSite behavior

Sanctum Stateful Domains

CORS

CSRF
```

Production configuration must use HTTPS.

---

# 92. API Resource Authorization

API Resources control field exposure after object authorization.

Examples:

```text
PersonDetailResource

FamilyMemberResource

FamilyPortalPersonResource
```

These may expose different fields for the same Person.

---

# 93. No Unrestricted Serialization

Prohibited:

```text
return $person;
```

when the Model contains fields not authorized for every caller.

Prefer:

```text
return new PersonDetailResource($person);
```

with controlled exposure.

---

# 94. Mass Assignment Security

Authorization does not make unrestricted input safe.

Prohibited:

```text
$person->update($request->all());
```

for sensitive canonical operations.

Use:

```text
Validated DTO
   ↓
Domain Action
   ↓
Explicit allowed mutation
```

---

# 95. Sensitive Field Updates

Sensitive fields require dedicated permission and validation.

Examples:

```text
National ID

Life Status

Death Date

Household Head

Family Membership

Verified Document State
```

Generic `person.update` must not necessarily authorize all sensitive changes.

---

# 96. Death Authorization

Recording official death should require:

```text
person.record-death
```

and appropriate data scope/domain conditions.

A Family User does not receive this permission.

They submit:

```text
DEATH_REPORT
```

instead.

---

# 97. Household Head Authorization

Direct Staff operation requires:

```text
family.change-household-head
```

plus relevant scope and domain validation.

Family User submits:

```text
HOUSEHOLD_HEAD_CHANGE
```

Change Request instead.

---

# 98. Membership Transfer Authorization

Direct transfer requires:

```text
family-membership.transfer
```

plus authorization to affected Family context(s).

Family User cannot directly transfer canonical membership.

---

# 99. Document Verification Authorization

Uploading a document does not grant:

```text
document.verify
```

A Family User can upload permitted supporting documents but cannot verify them.

---

# 100. User-Person Link Authorization

Creating/verifying Family User identity links is sensitive.

Recommended permissions:

```text
user.link-person

user.verify-person-link

user.activate
```

The target Family User cannot self-verify the link.

---

# 101. Account Activation

Account activation requires:

```text
Verified identity

Eligible Person relationship

Authorized Staff/System action
```

Exact activation mechanism remains pending.

---

# 102. Account Suspension

Authorized administrators may suspend an account.

Suspension should immediately prevent authenticated protected access according to session/security design.

---

# 103. Link Suspension

A User-Person Link may be suspended independently from the User account.

Example:

```text
Identity relationship requires investigation
```

The account may exist while Family Portal access is denied.

---

# 104. Access Re-evaluation

Family Portal access is dynamic.

Relevant events include:

```text
Household Head Change

Membership Transfer

Person Death

Family Archive

Account Suspension

Link Suspension

Link End
```

The next authorization check must reflect current canonical state.

---

# 105. No Permanent Frontend Scope Cache

The frontend may cache server state for UX through TanStack Query.

However, cached authorization context must not be considered permanent authority.

Laravel reauthorizes requests.

---

# 106. Permission Caching

Spatie permission caching may be used according to package architecture.

Permission changes must be reflected safely and promptly according to security requirements.

---

# 107. Navigation Authorization

Next.js navigation should reflect user capabilities.

Example Staff navigation:

```text
Dashboard

Families

Persons

Assessments

Needs

Assistance

Requests

Reports
```

Only relevant sections should be displayed.

But hidden navigation is not a security boundary.

---

# 108. Family Portal Navigation

Possible navigation:

```text
Home

My Family

Members

Residence

Requests

Documents

Notifications
```

Visibility depends on approved Family Portal capabilities.

---

# 109. Filament Navigation

Filament navigation should focus on:

```text
Users

Roles

Permissions

Reference Data

System Settings

Audit

Jobs

Technical Administration
```

Operational Staff workflows should remain in the custom Next.js Staff Application.

---

# 110. Permission Naming Convention

Use:

```text
resource.action
```

Examples:

```text
family.view

person.create

assessment.approve

change-request.apply

document.verify

report.view
```

This convention must remain consistent.

---

# 111. Permission Granularity

Avoid both extremes:

Too broad:

```text
admin
```

Too fragmented:

```text
person-first-name-view
person-last-name-view
person-birth-day-view
...
```

Field-level rules should handle sensitive-field differences without creating unusable permission catalogs.

---

# 112. Default Deny

Authorization follows:

```text
Default Deny
```

If no explicit rule grants an operation:

```text
DENY
```

This is especially important for sensitive Family Portal fields.

---

# 113. Least Privilege

Users should receive the minimum permissions required for their responsibilities.

Roles should not accumulate unrelated capabilities merely for convenience.

---

# 114. Separation of Duties

High-risk functions may require separation among:

```text
Creator

Reviewer

Approver

Applier

System Administrator
```

Exact separation remains operation-specific.

---

# 115. Emergency Access

If emergency/break-glass access is introduced later, it must require:

```text
Explicit activation

Reason

Strong authentication

Time limitation

Enhanced audit

Review
```

No silent emergency bypass is permitted.

---

# 116. Audit Authorization

Audit records are sensitive.

Only explicitly authorized roles may access them.

Audit access itself may be auditable.

---

# 117. Audit Integrity

Ordinary operational users must not be able to modify/delete audit history.

---

# 118. Security Logging

Authorization failures may be logged where useful.

Logs should avoid exposing unnecessary sensitive data.

Repeated suspicious object-access failures may support future security monitoring.

---

# 119. Error Responses

Authorization failures should not unnecessarily reveal whether a restricted object exists.

Depending on endpoint/context, responses may use:

```text
403 Forbidden
```

or:

```text
404 Not Found
```

according to the security strategy.

---

# 120. Family Portal Privacy

Family access does not imply unrestricted access to every adult member's sensitive information.

Sensitive categories require explicit policy.

Examples:

```text
Health

Disability

National ID

Private Mobile

Confidential Notes

Sensitive Documents
```

---

# 121. Adult Member Privacy

Exact policy for adult Family members remains pending.

Until resolved, restricted sensitive fields should default to:

```text
HIDDEN
```

for other Family members.

---

# 122. Minor Privacy

Guardian/parent access rules for minors require explicit policy.

Family membership alone must not be used as the only sensitive-data authorization rule.

---

# 123. Health Portal Visibility

Health data is:

```text
HIDDEN BY DEFAULT
```

in Family Portal until explicit visibility rules are approved.

---

# 124. Needs Portal Visibility

Needs may later be selectively visible.

Exact rules remain pending.

Internal scoring, confidential notes, or prioritization logic should not automatically be exposed.

---

# 125. Assistance Portal Visibility

Assistance records may later be selectively visible.

Internal operational information remains separate from Family-visible assistance history.

---

# 126. Notifications Authorization

Users may only retrieve notifications addressed to their own authenticated identity.

A user cannot request another user's notifications by ID.

---

# 127. Notification Content

Notification payloads should contain minimal sensitive data.

The notification should normally link the user to an authorized application view rather than embedding full confidential content.

---

# 128. Family Portal Change Request Visibility

Family Users may view requests within their authorized Family scope according to ownership/shared-family policy.

Internal Staff-only fields are excluded.

---

# 129. Executive Authorization

Executive access should normally emphasize:

```text
Aggregate KPIs

Operational trends

Program indicators

High-level drill-down where authorized
```

Executive role does not automatically mean access to every sensitive identity/health field.

---

# 130. System Administration vs Executive Access

These concepts are distinct.

```text
Executive Dashboard
→ Business / operational insight

Filament System Administration
→ Technical / configuration control
```

An executive user does not automatically need Filament.

A technical System Administrator does not automatically need confidential case details.

---

# 131. Authorization Testing

Every major resource requires authorization tests.

Test categories:

```text
Unauthenticated

Correct Permission

Missing Permission

Wrong Data Scope

Wrong Object Scope

Wrong Workflow State

Sensitive Field Exposure

Cross-Family Access

Family User Ownership

System Administration Access
```

---

# 132. IDOR Tests

Required examples:

```text
Family User A → Family B
DENY

Family User A → Person in Family B
DENY

Family User A → Change Request in Family B
DENY

Family User A → Document in Family B
DENY
```

---

# 133. Field Exposure Tests

Required:

```text
Unauthorized National ID
→ absent or masked

Unauthorized Health Data
→ absent

Staff Notes in Family Portal
→ absent

Internal Workflow Metadata
→ absent
```

---

# 134. Workflow Authorization Tests

Required:

```text
Family User attempts approve
→ DENY

Reviewer without approve permission attempts approval
→ DENY

Approver attempts invalid-state approval
→ DENY

Unauthorized Staff attempts apply
→ DENY
```

---

# 135. Filament Authorization Tests

Required:

```text
Ordinary Staff accesses admin.famboook.com
→ DENY

Authorized System Administrator
→ ALLOW

Authorized Filament user attempts prohibited domain operation
→ Domain Policy still applies
```

---

# 136. Export Tests

Required:

```text
Can View Report
+
Cannot Export
→ Export denied
```

and:

```text
Can Export Basic
+
Cannot Export Identity Data
→ Sensitive identity fields excluded/denied
```

---

# 137. Authorization Invariants

```text
AUTH-INV-001
Authentication and authorization are separate.

AUTH-INV-002
Laravel is the authoritative authorization layer.

AUTH-INV-003
All interfaces use the same authorization model.

AUTH-INV-004
Roles alone do not determine access.

AUTH-INV-005
Permissions alone do not override data scope.

AUTH-INV-006
Permissions alone do not override workflow state.

AUTH-INV-007
User and Person are separate identities.

AUTH-INV-008
Family Portal requires an explicit active User-Person Link.

AUTH-INV-009
Family Portal access is dynamically resolved through canonical membership.

AUTH-INV-010
FAMILY_USER role alone does not authorize any Family.

AUTH-INV-011
No canonical users.family_id shortcut is used.

AUTH-INV-012
Object IDs never constitute authorization.

AUTH-INV-013
Object-level authorization is enforced server-side.

AUTH-INV-014
Sensitive field authorization is enforced server-side.

AUTH-INV-015
Unauthorized sensitive fields must not be sent to the browser.

AUTH-INV-016
Family membership does not grant unrestricted sensitive-data access.

AUTH-INV-017
Health data is restricted by default.

AUTH-INV-018
Confidential Staff notes are never exposed to Family Users.

AUTH-INV-019
Family Users cannot directly mutate protected canonical registry state.

AUTH-INV-020
Family Users cannot review, approve, reject, or apply Change Requests.

AUTH-INV-021
Document upload does not imply document verification permission.

AUTH-INV-022
Private document access requires authorization.

AUTH-INV-023
View permission does not automatically grant export permission.

AUTH-INV-024
Filament access does not bypass Policies or Domain Actions.

AUTH-INV-025
SUPER_ADMIN does not bypass database/domain integrity.

AUTH-INV-026
Frontend navigation visibility is not a security boundary.

AUTH-INV-027
Frontend permission state is not authoritative.

AUTH-INV-028
API Resources control sensitive field exposure.

AUTH-INV-029
High-risk actions may require separation of duties.

AUTH-INV-030
Authorization follows default-deny and least-privilege principles.

AUTH-INV-031
Executive access and System Administration access are distinct.

AUTH-INV-032
A valid Sanctum session does not imply resource authorization.

AUTH-INV-033
Family Portal access must reflect current canonical state.

AUTH-INV-034
Search and reports must respect authorization.

AUTH-INV-035
Imports and exports require explicit permissions.

AUTH-INV-036
Background jobs performing protected actions must operate under authorized system/domain rules.

AUTH-INV-037
Sensitive information must not be protected only by client-side hiding.

AUTH-INV-038
Cross-Family access must be explicitly denied unless authorized.

AUTH-INV-039
Family User identity verification cannot be self-approved.

AUTH-INV-040
System Administration permissions do not automatically grant confidential case-data access.
```

---

# 138. Approved Authorization Decisions

### AUTH-ADR-001
Laravel is the authoritative authorization layer.

### AUTH-ADR-002
Spatie Laravel Permission provides RBAC.

### AUTH-ADR-003
Laravel Policies provide object-level authorization.

### AUTH-ADR-004
Query/Data Scopes restrict accessible record sets.

### AUTH-ADR-005
Field-level authorization is required for sensitive information.

### AUTH-ADR-006
Roles do not replace object/data-scope authorization.

### AUTH-ADR-007
Users and Persons remain separate identities.

### AUTH-ADR-008
Family Users require explicit User-Person Links.

### AUTH-ADR-009
User-Person Links require verification.

### AUTH-ADR-010
Family access derives from current canonical membership.

### AUTH-ADR-011
No canonical `users.family_id` authorization shortcut is used.

### AUTH-ADR-012
FAMILY_USER is separate from Household Head domain status.

### AUTH-ADR-013
Recommended V1 Family User eligibility is verified current Household Head.

### AUTH-ADR-014
Family User sensitive access follows default-deny.

### AUTH-ADR-015
National ID supports field-specific authorization.

### AUTH-ADR-016
Health and disability information are restricted.

### AUTH-ADR-017
Confidential Staff notes are not Family-visible.

### AUTH-ADR-018
Family Users use Change Requests rather than unrestricted canonical CRUD.

### AUTH-ADR-019
Family Users cannot perform Staff workflow actions.

### AUTH-ADR-020
Document download requires authorization.

### AUTH-ADR-021
Upload and verification permissions are separate.

### AUTH-ADR-022
Search respects row and field authorization.

### AUTH-ADR-023
Family Users do not receive global Family/Person search.

### AUTH-ADR-024
Report and export permissions are separate.

### AUTH-ADR-025
Sensitive exports may use dedicated permissions.

### AUTH-ADR-026
Audit access is restricted.

### AUTH-ADR-027
Frontend authorization checks exist for UX only.

### AUTH-ADR-028
API Resources enforce context-specific field exposure.

### AUTH-ADR-029
Next.js Staff Application uses Laravel authorization.

### AUTH-ADR-030
Next.js Family Portal uses Laravel authorization.

### AUTH-ADR-031
Executive Dashboard uses Laravel authorization.

### AUTH-ADR-032
Filament is restricted to System / High Administration.

### AUTH-ADR-033
Filament does not bypass Policies or Domain Actions.

### AUTH-ADR-034
System Administration and Executive access are distinct.

### AUTH-ADR-035
Laravel Sanctum provides first-party authentication.

### AUTH-ADR-036
Primary web authentication does not rely on localStorage bearer tokens.

### AUTH-ADR-037
CORS is not treated as authorization.

### AUTH-ADR-038
Object-level IDOR protection is mandatory.

### AUTH-ADR-039
Authorization uses default-deny.

### AUTH-ADR-040
Authorization follows least privilege.

### AUTH-ADR-041
Critical Domain Actions may reauthorize for defense in depth.

### AUTH-ADR-042
Permission naming follows `resource.action`.

### AUTH-ADR-043
Family Portal authorization is reevaluated after relevant canonical changes.

### AUTH-ADR-044
View access does not automatically grant drill-down, export, or sensitive-field access.

### AUTH-ADR-045
`reference-data.view` is granted to SUPER_ADMIN, ADMINISTRATOR and DATA_ENTRY in V1 (see §56). Reference-data create/update/deactivate remain SUPER_ADMIN-only.

### AUTH-ADR-046
`residence.update` (in-place correction of the current residence) is granted to SUPER_ADMIN, ADMINISTRATOR, DATA_ENTRY and SOCIAL_WORKER in V1, mirroring "Update Canonical Family" (see §46). `residence.change` (moves with history) remains unassigned.

### AUTH-ADR-047
`person.update` (basic Person data correction) is granted to SUPER_ADMIN, ADMINISTRATOR and DATA_ENTRY in V1 (see §44). It never authorizes National ID viewing or editing, which require the separate, still-unassigned `person.national-id.*` permissions (§39).

### AUTH-ADR-048
Person health records are governed by `health-record.view/create/update/close` (§40), which replace the unassigned `health.*` / `disability.*` names. View: SUPER_ADMIN, ADMINISTRATOR, DATA_ENTRY, REVIEWER, SOCIAL_WORKER. Create/update/close: SUPER_ADMIN, ADMINISTRATOR, DATA_ENTRY. REPORTS_VIEWER and FAMILY_USER have no person-level health access. There is no delete permission.

### AUTH-ADR-049
`activity-log.view` (read-only, family-scoped Family Activity Log, §57a) is granted to SUPER_ADMIN, ADMINISTRATOR, DATA_ENTRY, REVIEWER and SOCIAL_WORKER in V1. It is distinct from `audit.view` and from the `*.view-history` permissions. No activity write permissions exist. Health events additionally require `health-record.view`. REPORTS_VIEWER and FAMILY_USER have no access in V1.

### AUTH-ADR-050
Family assessments V1 (§47): `assessment.view` for SUPER_ADMIN, ADMINISTRATOR, DATA_ENTRY, REVIEWER and SOCIAL_WORKER; `assessment.create/update/complete` for SUPER_ADMIN, ADMINISTRATOR, DATA_ENTRY and SOCIAL_WORKER. REPORTS_VIEWER and FAMILY_USER have no assessment access in V1. `assessment.review/verify/approve` remain unassigned; no delete permission exists. The assessment-domain reference endpoint accepts `reference-data.view` or `assessment.view`.

### AUTH-ADR-051
Needs V1 (§49): `need.view` for SUPER_ADMIN, ADMINISTRATOR, DATA_ENTRY, REVIEWER and SOCIAL_WORKER; `need.create/update/close` for SUPER_ADMIN, ADMINISTRATOR, DATA_ENTRY and SOCIAL_WORKER. `need.close` authorizes both fulfil and close; `need.cancel` stays unassigned and no `need.resolve` is added. REPORTS_VIEWER and FAMILY_USER have no Need access in V1. No delete permission exists. The need-category reference endpoint accepts `reference-data.view` or `need.view`.

### AUTH-ADR-052
Assistance V1-A (§50) adds `assistance.open` and `assistance.nominate` (no equivalent existed; nominating must be separable from editing definitions). View: SUPER_ADMIN, ADMINISTRATOR, DATA_ENTRY, SOCIAL_WORKER, REVIEWER. Create/update: SUPER_ADMIN, ADMINISTRATOR, DATA_ENTRY. Open: SUPER_ADMIN, ADMINISTRATOR. Nominate (incl. targeting preview and removal): SUPER_ADMIN, ADMINISTRATOR, DATA_ENTRY, SOCIAL_WORKER. `assistance.reverse` stays unassigned for V1-B. REPORTS_VIEWER and FAMILY_USER have no access. No delete permission.

### AUTH-ADR-053
Assistance V1-B (§50) adds `assistance.approve`, `assistance.deliver`, `assistance.complete`, `assistance.export` and `assistance.export-sensitive`, and assigns the existing `assistance.reverse`. Approve: SUPER_ADMIN, ADMINISTRATOR, SOCIAL_WORKER. Deliver (incl. verification and NOT_DELIVERED): SUPER_ADMIN, ADMINISTRATOR, SOCIAL_WORKER, DATA_ENTRY. Reverse, complete, export, export-sensitive: SUPER_ADMIN, ADMINISTRATOR. Sensitive export fields (National ID, health indicators) require export-sensitive in addition to export. None of these grant `person.national-id.view`.

### AUTH-ADR-054
Clan + Branch Structure V1 (§56a) adds `clan.view` (SUPER_ADMIN, ADMINISTRATOR, DATA_ENTRY, SOCIAL_WORKER) and `clan.manage` (SUPER_ADMIN, ADMINISTRATOR), covering Clans, Branch Groups and Branches. Existing reference-data permissions were not reused: SOCIAL_WORKER needs the selectors for `family.update` but lacks `reference-data.view`, and ADMINISTRATOR must manage the structure while `reference-data.create/update` is SUPER_ADMIN-only. No delete capability exists.

### AUTH-ADR-055
Operational Dashboard V1 (§59a) reuses the existing `dashboard.view-operational` for SUPER_ADMIN, ADMINISTRATOR, DATA_ENTRY, REVIEWER, SOCIAL_WORKER and REPORTS_VIEWER; FAMILY_USER is excluded. Each dashboard section additionally requires its domain view permission and is returned as null without it; recent activity applies the Family timeline's event-level visibility.

### AUTH-ADR-056
Reports V1 (§59b) assigns the existing `report.view` to SUPER_ADMIN, ADMINISTRATOR, DATA_ENTRY, REVIEWER, SOCIAL_WORKER and REPORTS_VIEWER; FAMILY_USER is excluded. Each report additionally requires all of its domain view permissions (403 otherwise). XLSX export reuses the existing `export.basic` (SUPER_ADMIN, ADMINISTRATOR, REPORTS_VIEWER; unchanged). REPORTS_VIEWER is deliberately not given health-record, Need, Assessment or Assistance access to fill the remaining tabs. `report.view-sensitive` and the sensitive export permissions stay unassigned because no report exposes sensitive values.
```

---

# 139. Pending Authorization Decisions

```text
PAUTH-001
Exact Family User identity-verification mechanism.

PAUTH-002
Exact Family User account activation mechanism.

PAUTH-003
Whether Family Portal V1 supports more than one Family User per Family.

PAUTH-004
Guardian authorization rules.

PAUTH-005
Authorized Representative rules.

PAUTH-006
Exact adult-member privacy policy.

PAUTH-007
Exact minor/guardian privacy policy.

PAUTH-008
Exact Family Portal National ID masking format.

PAUTH-009
Whether Family User may view their own full National ID.

PAUTH-010
Exact Family Portal health visibility.

PAUTH-011
Exact Family Portal disability visibility.

PAUTH-012
Exact Needs visibility.

PAUTH-013
Exact Assistance visibility.

PAUTH-014
Exact Document types visible to Family Users.

PAUTH-015
Exact Staff Data Scope assignment model.

PAUTH-016
Whether REGION is required in V1.

PAUTH-017
Whether BRANCH is required in V1.

PAUTH-018
Exact maker-checker rules by operation.

PAUTH-019
Exact HIGH-risk approval separation.

PAUTH-020
Whether selected actions require re-authentication.

PAUTH-021
Exact Staff/System Administrator 2FA policy.

PAUTH-022
Exact session timeout policy.

PAUTH-023
Exact account recovery authorization.

PAUTH-024
Exact export approval requirements.

PAUTH-025
Exact sensitive-report permissions.

PAUTH-026
Exact audit-sensitive permission scope.

PAUTH-027
Exact emergency/break-glass policy.

PAUTH-028
Exact Filament role eligibility.

PAUTH-029
Whether executive users receive record drill-down by default or separate permission.

PAUTH-030
Exact handling of permissions after role changes during an active session.

PAUTH-031
Exact API rate-limit policies by actor type.

PAUTH-032
Exact signed-document URL expiry policy.

PAUTH-033
Whether Family User request visibility is requester-only or shared among authorized Family Users when multiple users are later supported.
```

---

# 140. Initial Role Matrix

This is a baseline, not a substitute for explicit permissions.

| Capability | Super Admin | Administrator | Data Entry | Reviewer | Social Worker | Reports Viewer | Family User |
|---|---:|---:|---:|---:|---:|---:|---:|
| Staff App | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — |
| Family Portal | Policy | Policy | Policy | Policy | Policy | Policy | ✓ |
| Filament | ✓ | Limited/Policy | — | — | — | — | — |
| View Family | ✓ | ✓ | Scope | Scope | Scope | Report scope | Own authorized |
| Create Family | ✓ | ✓ | ✓ | Policy | Policy | — | — |
| Update Canonical Family | ✓ | ✓ | Draft/limited | Policy | Limited | — | — |
| View Person | ✓ | ✓ | Scope | Scope | Scope | Report scope | Authorized fields |
| Create Person | ✓ | ✓ | ✓ | Policy | Policy | — | — |
| Correct Basic Person Data | ✓ | ✓ | ✓ | — | — | — | — |
| View Health Records | ✓ | ✓ | ✓ | ✓ | ✓ | — | — |
| Create/Correct/Close Health Records | ✓ | ✓ | ✓ | — | — | — | — |
| View Reference Data | ✓ | ✓ | ✓ | — | — | — | — |
| View / Select Clan & Branch (V1) | ✓ | ✓ | ✓ | — | ✓ | — | — |
| Manage Clan & Branch Structure (V1) | ✓ | ✓ | — | — | — | — | — |
| Correct Current Residence | ✓ | ✓ | Draft/limited | — | Limited | — | — |
| Record Official Death | Permission | Permission | — | Policy | — | — | — |
| Change Household Head | Permission | Permission | — | Policy | — | — | Request only |
| Transfer Membership | Permission | Permission | — | Policy | — | — | Request only |
| Assessments | ✓ | ✓ | Limited | Review | ✓ | Read/report | Policy |
| View Family Assessments (V1) | ✓ | ✓ | ✓ | ✓ | ✓ | — | — |
| Create/Update/Complete Family Assessments (V1) | ✓ | ✓ | ✓ | — | ✓ | — | — |
| Needs | ✓ | ✓ | Limited | Review | ✓ | Read/report | Policy |
| View Needs (V1) | ✓ | ✓ | ✓ | ✓ | ✓ | — | — |
| Create/Update/Fulfil/Close Needs (V1) | ✓ | ✓ | ✓ | — | ✓ | — | — |
| Assistance | ✓ | ✓ | Limited | Review | ✓ | Read/report | Policy |
| View Assistance Programs & Nominees (V1-A) | ✓ | ✓ | ✓ | ✓ | ✓ | — | — |
| Create/Update Assistance Definition (V1-A) | ✓ | ✓ | ✓ | — | — | — | — |
| Open Assistance (V1-A) | ✓ | ✓ | — | — | — | — | — |
| Targeting Preview & Nomination (V1-A) | ✓ | ✓ | ✓ | — | ✓ | — | — |
| Approve/Reject Beneficiaries (V1-B) | ✓ | ✓ | — | — | ✓ | — | — |
| Record Delivery / Not Delivered (V1-B) | ✓ | ✓ | ✓ | — | ✓ | — | — |
| Reverse Delivery / Complete Assistance (V1-B) | ✓ | ✓ | — | — | — | — | — |
| Issue External Lists incl. Sensitive Fields (V1-B) | ✓ | ✓ | — | — | — | — | — |
| Review Change Request | Permission | ✓ | — | ✓ | Policy | — | — |
| Approve Change Request | Permission | Permission | — | Permission | Policy | — | — |
| Apply Change Request | Permission | Permission | — | Permission | Policy | — | — |
| Submit Change Request | — | — | — | — | — | — | ✓ |
| View Audit | ✓ | Permission | — | Limited | — | — | — |
| View Family Activity Log | ✓ | ✓ | ✓ | ✓ | ✓ | — | — |
| View Executive Dashboard | Permission | Permission | — | — | Policy | ✓ | — |
| View Operational Dashboard (V1; sections need domain permissions) | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — |
| View Reports (V1; each report needs its domain permissions) | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — |
| Export Reports XLSX (V1; `export.basic` + report permissions) | ✓ | ✓ | — | — | — | ✓ | — |
| Export Basic | Permission | Permission | Policy | Policy | Policy | Permission | — |
| Export Sensitive | Permission | Permission | — | Policy | Policy | Policy | — |
| Manage Users/Roles | ✓ | Permission | — | — | — | — | — |
| System Settings | ✓ | Permission | — | — | — | — | — |

`Policy`, `Scope`, and `Permission` deliberately indicate that role membership alone is insufficient.

---

# 141. Authorization Request Pattern

Typical Staff request:

```text
User
 ↓
Sanctum Authentication
 ↓
Route Middleware
 ↓
Permission
 ↓
Policy
 ↓
Data/Object Scope
 ↓
Workflow State
 ↓
Domain Rule
 ↓
Domain Action
```

Typical Family Portal request:

```text
FAMILY_USER
 ↓
Sanctum Authentication
 ↓
Active User-Person Link
 ↓
Active Membership
 ↓
Family Access Policy
 ↓
Object Authorization
 ↓
Field Authorization
 ↓
Workflow Rule
 ↓
Domain Action
```

---

# 142. Authorization Failure Principle

Authorization must fail safely.

If Famboook cannot establish that the actor is permitted:

```text
DENY
```

The system must never infer authorization from:

```text
Visible button

Known URL

Known object ID

Frontend role state

Family surname

Family code

Paper form number

National ID knowledge
```

---

# 143. Definition of Authorization Readiness

Authorization is ready for implementation when:

```text
Initial roles are defined

Permission naming convention is approved

Core permission catalog is defined

Family User identity model is approved

Family access resolution is approved

Sensitive field categories are identified

Policies are mapped to core resources

Workflow actions are permission-aware

Filament boundary is defined

Staff/Executive/Family interfaces share Laravel authorization

Default-deny is accepted

Authorization test requirements are defined
```

Not every future privacy exception must be resolved before the foundation is implemented.

Unresolved sensitive visibility defaults to denial.

---

# 144. Final Authorization Principle

Famboook does not ask only:

```text
"What role does this user have?"
```

It asks:

```text
Who is the user?

What role do they have?

What permission do they have?

Which records are in their scope?

Are they authorized for this exact object?

Which fields may they see?

Is this action valid in the current workflow state?

Do domain rules allow it?
```

Therefore:

```text
Role
  ↓
Permission
  ↓
Scope
  ↓
Object
  ↓
Field
  ↓
Workflow
  ↓
Domain Rule
  ↓
Authorized Action
```

Laravel answers these questions.

Next.js presents the resulting experience.

Filament remains subject to the same rules.

---

# 145. Document Status

```text
Project: Famboook
Document: Permissions & Authorization
Version: 1.2.9
Status: APPROVED
Date: 2026-09-24
```

---

# 146. Change Log

| Version | Date | Status | Description |
|---|---|---|---|
| 1.0 | 2026-09-22 | Superseded | Initial permissions model |
| 1.1 | 2026-09-22 | Superseded | Added FAMILY_USER, User-Person Links, Family scope, field-level visibility, Change Request permissions, object authorization and Family Portal privacy |
| 1.2 | 2026-09-22 | Approved | Centralized authorization in Laravel, aligned Staff/Executive/Family Next.js applications and Filament with shared Policies and Spatie Permission, formalized object/data/field/workflow authorization, Filament boundaries, API security, Sanctum boundary, private file authorization, export controls and expanded authorization testing |
| 1.2.12 | 2026-09-26 | Approved | §59b: `report.view` V1 role assignment, per-report domain-permission rule, `export.basic` for XLSX, two rows in §140, AUTH-ADR-056 |
| 1.2.11 | 2026-09-25 | Approved | §59a: `dashboard.view-operational` V1 role assignment and per-section domain-permission rule, row in §140, AUTH-ADR-055 |
| 1.2.10 | 2026-09-25 | Approved | §56a: `clan.view` / `clan.manage` with V1 role assignment, two rows in §140, AUTH-ADR-054 |
| 1.2.9 | 2026-09-24 | Approved | §50: V1-B permissions (approve, deliver, complete, export, export-sensitive; reverse assigned), V1-B rows in §140, AUTH-ADR-053 |
| 1.2.8 | 2026-09-24 | Approved | §50: added `assistance.open` / `assistance.nominate` and the V1-A role assignment, V1-A rows in §140, AUTH-ADR-052 |
| 1.2.7 | 2026-09-24 | Approved | §49 V1 Role Assignment for `need.view/create/update/close` (`need.close` covers fulfil and close), V1 Need rows in §140, AUTH-ADR-051 |
| 1.2.6 | 2026-09-24 | Approved | §47 V1 Role Assignment for `assessment.view/create/update/complete`, V1 assessment rows in §140, AUTH-ADR-050 |
| 1.2.5 | 2026-09-24 | Approved | Added `activity-log.view` (§57a) with V1 role assignment, the "View Family Activity Log" row in §140, and AUTH-ADR-049 |
| 1.2.4 | 2026-09-24 | Approved | Replaced `health.*` / `disability.*` with `health-record.view/create/update/close` (§40-41), health rows in §140, AUTH-ADR-048 |
| 1.2.3 | 2026-09-24 | Approved | Added V1 role assignment for `person.update` (SUPER_ADMIN, ADMINISTRATOR, DATA_ENTRY) in §44, the "Correct Basic Person Data" row in §140, and AUTH-ADR-047. National ID permissions unchanged |
| 1.2.2 | 2026-09-23 | Approved | Added V1 role assignment for `residence.update` (SUPER_ADMIN, ADMINISTRATOR, DATA_ENTRY, SOCIAL_WORKER) in §46, the "Correct Current Residence" row in §140, and AUTH-ADR-046 |
| 1.2.1 | 2026-09-23 | Approved | Added V1 role assignment for `reference-data.view` (SUPER_ADMIN, ADMINISTRATOR, DATA_ENTRY) in §56, the "View Reference Data" row in §140, and AUTH-ADR-045 |