# Famboook
## Roles, Permissions & Access Control

**Document:** `06-PERMISSIONS.md`  
**Version:** 1.0  
**Status:** Approved  
**Last Updated:** 2026-09-22  
**Project:** Famboook — Family Registry & Case Management System  
**Authorization Model:** RBAC + Data Scope + Field-Level Restrictions

---

# 1. Purpose

This document defines the authorization and access-control model for Famboook V1.

It translates the approved:

- `01-PRODUCT.md`
- `02-DATA-DICTIONARY.md`
- `03-BUSINESS-RULES.md`
- `04-DATABASE.md`
- `05-WORKFLOWS.md`

into explicit rules defining:

```text
WHO
   ↓
CAN DO WHAT
   ↓
ON WHICH ENTITY
   ↓
AT WHICH WORKFLOW STATE
   ↓
WITH ACCESS TO WHICH DATA
```

This document defines:

- System roles.
- Permission naming.
- Role-to-permission mapping.
- Workflow permissions.
- Data scopes.
- Sensitive-data access.
- Confidential-note access.
- Document access.
- Export restrictions.
- Audit-log access.
- Administrative permissions.
- Laravel authorization architecture.

---

# 2. Authorization Principles

## AUTH-P01 — Deny by Default

Access is denied unless explicitly granted.

```text
No Permission
=
No Access
```

---

## AUTH-P02 — Least Privilege

Users receive only the permissions required to perform their responsibilities.

---

## AUTH-P03 — Role Is Not Enough

Authorization may depend on:

```text
Role
+
Permission
+
Record Scope
+
Workflow State
+
Data Sensitivity
+
Record Ownership / Assignment
```

---

## AUTH-P04 — Backend Enforcement

Authorization MUST be enforced by the Laravel backend.

Hiding a button in Filament is not sufficient security.

---

## AUTH-P05 — Sensitive Data

Access to sensitive fields must be separately controlled where appropriate.

---

## AUTH-P06 — Export Is Separate

Permission to view data does NOT automatically grant permission to export it.

---

## AUTH-P07 — Workflow Actions Are Permissions

Important workflow actions must have explicit permissions.

Example:

```text
form.verify
form.approve
```

rather than relying only on:

```text
form.update
```

---

## AUTH-P08 — Auditability

Important privileged actions must be auditable.

---

## AUTH-P09 — No Shared Accounts

Each operational user should have an individual account.

Shared credentials are prohibited.

---

## AUTH-P10 — Server-Side Scope

Data scope must be applied to backend queries.

Users must not receive unauthorized records and merely have them hidden in the interface.

---

# 3. Authorization Architecture

Famboook V1 uses:

```text
RBAC
Role-Based Access Control
```

extended by:

```text
Data Scope
Field-Level Access
Workflow State
```

Conceptually:

```text
User
 │
 ├── Role
 │     ↓
 │ Permissions
 │
 ├── Data Scope
 │
 └── Record Context
       │
       ├── Workflow State
       ├── Assignment
       └── Sensitivity
```

Authorization decision:

```text
User
 ↓
Has Permission?
 ↓
Record In Scope?
 ↓
Workflow Action Allowed?
 ↓
Sensitive Field Allowed?
 ↓
ALLOW / DENY
```

---

# 4. Laravel Authorization Stack

Recommended:

```text
spatie/laravel-permission
```

for:

```text
Roles
Permissions
Role assignments
```

Laravel should additionally use:

```text
Policies
Gates
Query Scopes
Service/Action authorization
```

Filament should consume the same backend authorization rules.

---

# 5. V1 Roles

Approved core roles:

```text
SUPER_ADMIN
ADMINISTRATOR
DATA_ENTRY
REVIEWER
SOCIAL_WORKER
REPORTS_VIEWER
```

Display labels may be localized independently.

Example Arabic labels:

```text
SUPER_ADMIN      مدير النظام الأعلى
ADMINISTRATOR    مدير النظام
DATA_ENTRY       مدخل بيانات
REVIEWER         مدقق / مراجع
SOCIAL_WORKER    باحث / أخصائي اجتماعي
REPORTS_VIEWER   مستخدم تقارير
```

---

# 6. SUPER_ADMIN

Purpose:

Technical/system-level administration.

Typical responsibilities:

- Full application administration.
- User management.
- Role management.
- Permission management.
- Reference-data administration.
- System configuration.
- Security administration.
- Exceptional recovery operations.

`SUPER_ADMIN` is not intended for normal daily case-management operations.

The number of Super Admin accounts should be minimal.

---

# 7. ADMINISTRATOR

Purpose:

Operational administration of Famboook.

Typical responsibilities:

- Manage users within approved scope.
- Manage operational records.
- Approve verified registrations.
- Resolve exceptional data issues.
- Archive records.
- Access administrative reports.
- Manage approved reference data where permitted.
- Review audit information where permitted.

Administrator does not automatically mean unrestricted technical access.

---

# 8. DATA_ENTRY

Purpose:

Enter and correct registration information.

Typical responsibilities:

```text
Create Draft
Enter Family
Enter Persons
Enter Memberships
Enter Residence
Enter Assessment Data
Attach Documents
Submit Registration
Correct Returned Registration
```

Data Entry normally does NOT:

```text
Verify
Approve
Manage Users
Manage Permissions
Resolve Confirmed Duplicates
View Full Audit Logs
Export Sensitive Datasets
```

---

# 9. REVIEWER

Purpose:

Independently review and verify submitted information.

Typical responsibilities:

```text
View Review Queue
Review Submitted Forms
Compare Against Source
Return for Correction
Verify
Review Duplicate Warnings
Verify Documents
Verify Needs
```

Reviewer normally does NOT:

```text
Approve Final Registration
Manage Users
Manage Roles
Change System Configuration
```

unless separately authorized.

---

# 10. SOCIAL_WORKER

Purpose:

Operational case management and follow-up.

Typical responsibilities:

```text
View Family Profiles
View Person Profiles
Create Assessments
Update Operational Information
Record Needs
Record Assistance
Add Case Notes
Add Person Notes
Update Residence
Follow Cases
```

Sensitive and confidential information remains subject to additional permissions.

---

# 11. REPORTS_VIEWER

Purpose:

View authorized dashboards and reports without modifying operational records.

Typical responsibilities:

```text
View Dashboard
View Reports
View Aggregated Statistics
View Authorized Record Summaries
```

Reports Viewer does NOT automatically receive:

```text
National IDs
Health Details
Confidential Notes
Documents
Raw Sensitive Exports
```

---

# 12. Permission Naming Convention

Use:

```text
<resource>.<action>
```

Examples:

```text
family.view
family.create
family.update
family.archive

person.view
person.create
person.update

form.submit
form.review
form.return
form.verify
form.approve
```

Use stable machine-readable permission names.

Do not encode display labels into permission identifiers.

---

# 13. Family Permissions

Recommended permissions:

```text
family.view
family.create
family.update
family.archive
family.restore

family.change_head
family.view_history
```

---

# 14. Person Permissions

```text
person.view
person.create
person.update
person.archive
person.restore

person.view_history
person.transfer_family
```

Archiving a Person is a privileged operation.

Moving a Person between Families requires:

```text
person.transfer_family
```

rather than generic update permission.

---

# 15. Membership Permissions

```text
membership.view
membership.create
membership.update
membership.end

membership.change_relationship
membership.view_history
```

Household-head change uses the separate:

```text
family.change_head
```

permission.

---

# 16. Residence Permissions

```text
residence.view
residence.create
residence.update
residence.close
residence.view_history
```

A real-world move should normally create historical residence change rather than overwrite history.

---

# 17. Health Permissions

```text
health.view
health.create
health.update

health_condition.view
health_condition.create
health_condition.update
health_condition.end
```

Health information is classified as:

```text
RESTRICTED
```

Possession of:

```text
person.view
```

does not automatically grant:

```text
health.view
```

---

# 18. Disability Permissions

```text
disability.view
disability.create
disability.update
disability.end
```

Disability information is:

```text
RESTRICTED
```

and requires explicit access.

---

# 19. Education Permissions

```text
education.view
education.create
education.update
education.end
education.view_history
```

---

# 20. Employment Permissions

```text
employment.view
employment.create
employment.update
employment.end
employment.view_history
```

Income-related fields may require additional restrictions if later classified as sensitive.

---

# 21. Assessment Permissions

```text
assessment.view
assessment.create
assessment.update
assessment.complete
assessment.review
assessment.verify
assessment.view_history
```

Assessment editing depends on assessment state.

Example:

```text
DRAFT / IN_PROGRESS
→ editable by authorized collector

VERIFIED
→ controlled modification only
```

---

# 22. Form Submission Permissions

```text
form.view
form.create
form.update

form.submit
form.send_to_review
form.review
form.return
form.correct
form.resubmit
form.verify
form.approve

form.archive
form.view_history
form.view_source
```

Workflow actions MUST NOT be replaced with one broad:

```text
form.update
```

permission.

---

# 23. Workflow Permission Mapping

Primary registration workflow:

```text
DRAFT
  │
  │ form.submit
  ▼
DATA_ENTRY_COMPLETED
  │
  │ form.send_to_review
  ▼
UNDER_REVIEW
  │
  ├── form.return
  │       ↓
  │ RETURNED_FOR_CORRECTION
  │       │
  │       │ form.correct
  │       ▼
  │   CORRECTED
  │       │
  │       │ form.resubmit
  │       └───────────────┐
  │                       │
  └───────────────────────┘
              ↓
        UNDER_REVIEW
              │
              │ form.verify
              ▼
           VERIFIED
              │
              │ form.approve
              ▼
           APPROVED
```

---

# 24. Need Permissions

```text
need.view
need.create
need.update
need.verify
need.activate
need.mark_partially_met
need.mark_met
need.close
need.view_history
```

Generic `need.update` should not silently bypass lifecycle permissions.

---

# 25. Assistance Permissions

```text
assistance.view
assistance.create
assistance.update
assistance.void
assistance.view_history
```

Normal users should not hard-delete Assistance records.

If an Assistance record was entered incorrectly:

```text
VOID
```

or another controlled correction mechanism should be preferred over destructive deletion.

---

# 26. Document Permissions

```text
document.view
document.upload
document.update_metadata
document.verify
document.replace
document.archive
document.download
```

`document.view` does not necessarily imply:

```text
document.download
```

for highly sensitive documents.

---

# 27. Notes Permissions

General Person notes:

```text
person_note.view
person_note.create
```

Case notes:

```text
case_note.view
case_note.create
```

Confidential notes:

```text
confidential_note.view
confidential_note.create
```

These must remain separate.

---

# 28. Confidential Notes

A user with:

```text
case_note.view
```

does NOT automatically receive:

```text
confidential_note.view
```

Example:

```text
Case Note
is_confidential = false
→ case_note.view

Case Note
is_confidential = true
→ confidential_note.view
```

The restriction applies to:

```text
Profile
Search
Reports
Exports
API
Print
```

---

# 29. National ID Permissions

National ID is Restricted data.

Recommended permissions:

```text
national_id.view
national_id.view_full
national_id.update
```

Possible behavior:

### No `national_id.view`

```text
National ID hidden
```

### `national_id.view`

```text
804****32
```

### `national_id.view_full`

```text
804123432
```

Exact masking rules are implementation decisions.

---

# 30. National ID Modification

Changing a National ID after verification requires:

```text
person.update
+
national_id.update
```

and:

```text
Reason
Duplicate Check
Audit
```

For approved/verified records, additional authorization may be required according to workflow rules.

---

# 31. Sensitive Health Access

Recommended:

```text
health.view
disability.view
```

must be granted deliberately.

For example, a Reports Viewer may receive:

```text
Aggregated disability count
```

without receiving:

```text
Person-level disability details
```

---

# 32. Source Form Permissions

```text
form.view_source
```

controls access to scanned/original source forms.

This is separate from:

```text
form.view
```

because source files may expose additional personal information.

---

# 33. Export Permissions

Exports require explicit permissions.

Recommended:

```text
export.basic
export.personal_data
export.sensitive_data
```

Conceptually:

```text
export.basic
```

may include:

```text
Family Code
Person Code
Basic demographics
Non-sensitive operational fields
```

`export.personal_data` may include:

```text
Names
Mobile
Address
```

`export.sensitive_data` may include approved restricted information.

---

# 34. Export Authorization

Export flow:

```text
User
 ↓
Has Export Permission?
 ↓
Determine Scope
 ↓
Determine Allowed Fields
 ↓
Generate
 ↓
Audit
```

The export engine MUST NOT simply export every database column visible to the application.

---

# 35. Report Permissions

Recommended:

```text
report.view_dashboard
report.view_family
report.view_demographics
report.view_health
report.view_needs
report.view_assistance

report.export
```

Sensitive reports may require both:

```text
report.view_health
+
health.view
```

depending on whether person-level data is displayed.

---

# 36. Audit Permissions

```text
audit.view
audit.view_sensitive
```

Normal users must never receive:

```text
audit.create
audit.update
audit.delete
```

Audit events are system-generated.

---

# 37. Workflow History Permissions

Recommended:

```text
workflow_history.view
```

Workflow history may reveal:

```text
Reviewer
Correction reason
Approval action
Operational metadata
```

It should therefore not automatically be public to every authenticated user.

---

# 38. User Administration Permissions

```text
user.view
user.create
user.update
user.activate
user.deactivate
user.reset_access
```

Password management must follow Laravel security mechanisms.

Administrators should never be able to retrieve another user's plaintext password.

---

# 39. Role Administration Permissions

Highly privileged:

```text
role.view
role.create
role.update
role.delete

permission.view
permission.assign
```

These should normally belong only to:

```text
SUPER_ADMIN
```

or tightly controlled administrators.

---

# 40. Reference Data Permissions

```text
reference.view
reference.create
reference.update
reference.deactivate
```

Do not normally delete referenced lookup values.

Example:

```text
Need Type no longer used
→ is_active = false
```

---

# 41. System Configuration Permissions

```text
system.view_settings
system.update_settings
```

Reserved for authorized administrative roles.

---

# 42. Data Scope

Permissions answer:

```text
What may the user do?
```

Data Scope answers:

```text
To which records?
```

These are different concepts.

---

# 43. V1 Data Scope Model

Recommended scopes:

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

but should not be introduced until operationally required.

---

# 44. ALL Scope

```text
ALL
```

means the user may access all records allowed by their permissions.

It does NOT override field-level restrictions.

Example:

```text
scope = ALL
person.view = YES
national_id.view_full = NO
```

The user can see all authorized Persons but still cannot see full National IDs.

---

# 45. ASSIGNED Scope

```text
ASSIGNED
```

means access is limited to records assigned to the user or their operational queue according to approved assignment rules.

Useful for:

```text
Reviewers
Social Workers
```

if explicit assignment is enabled.

---

# 46. CREATED_BY_ME Scope

Useful for limited Data Entry operations.

Example:

```text
User creates Draft A
→ can edit Draft A

Another user creates Draft B
→ cannot edit Draft B
```

unless additional permissions/scope allow it.

---

# 47. Scope Does Not Replace Permissions

Example:

```text
User scope = ALL
```

does not mean:

```text
User can approve
```

Approval still requires:

```text
form.approve
```

---

# 48. Record Ownership

Fields such as:

```text
created_by
entered_by
collector_id
reviewer_id
```

may participate in authorization decisions.

However, ownership must not be treated as the only security mechanism.

---

# 49. Workflow State Restrictions

Even when a user has:

```text
form.update
```

the workflow state may prohibit editing.

Example:

```text
DRAFT
→ editable

RETURNED_FOR_CORRECTION
→ editable according to correction scope

UNDER_REVIEW
→ locked from normal Data Entry modification

VERIFIED
→ controlled

APPROVED
→ controlled
```

---

# 50. Maker-Checker Principle

Where practical:

```text
Person who enters data
≠
Person who verifies data
```

This is the:

```text
Maker-Checker
```

principle.

V1 should support independent review.

---

# 51. Self-Verification

Recommended default:

A user SHOULD NOT verify their own submission.

Conceptually:

```text
form_submissions.entered_by
!=
current_user.id
```

for `form.verify`.

Exceptional override, if ever permitted, must be explicitly authorized and audited.

---

# 52. Self-Approval

A user should not normally both:

```text
Verify
+
Approve
```

the same registration where organizational staffing allows separation.

Final enforcement depends on the approved operational governance model.

---

# 53. Duplicate Review Permissions

Recommended:

```text
duplicate.view
duplicate.review
duplicate.resolve
duplicate.merge
```

Important distinction:

```text
duplicate.review
```

may classify a potential duplicate.

But:

```text
duplicate.merge
```

is significantly more privileged.

No role receives automatic merge capability merely because it can review duplicates.

---

# 54. Person Merge

If implemented:

```text
duplicate.merge
```

should be restricted to:

```text
SUPER_ADMIN
```

or a specifically authorized senior administrator.

Merge must be:

```text
Explicit
Reviewed
Transactional
Audited
```

No automatic Person merge is permitted.

---

# 55. Household Head Change Permission

Requires:

```text
family.change_head
```

This action should:

```text
Validate membership
Update old head
Update new head
Create audit event
```

within a controlled transaction.

---

# 56. Person Transfer Permission

Moving a Person between Families requires:

```text
person.transfer_family
```

It should not be granted through generic:

```text
person.update
```

alone.

---

# 57. Archive Permissions

Separate:

```text
family.archive
person.archive
form.archive
document.archive
```

Archive is a privileged lifecycle action.

Normal Data Entry users should not archive approved canonical records.

---

# 58. Hard Delete

Normal operational roles receive no hard-delete permissions for core records.

Core data includes:

```text
Families
Persons
Memberships
Assessments
Form Submissions
Workflow Events
Needs
Assistance
Case Notes
Audit Logs
```

Exceptional maintenance must follow technical administrative procedures.

---

# 59. Suggested Role Matrix

Legend:

```text
✓  Allowed
—  Not allowed by default
R  Restricted / conditional
```

| Capability | Super Admin | Administrator | Data Entry | Reviewer | Social Worker | Reports Viewer |
|---|:---:|:---:|:---:|:---:|:---:|:---:|
| View Families | ✓ | ✓ | ✓ | ✓ | ✓ | R |
| Create Family Draft | ✓ | ✓ | ✓ | — | R | — |
| Update Draft | ✓ | ✓ | ✓ | — | R | — |
| Submit Form | ✓ | ✓ | ✓ | — | R | — |
| Review Form | ✓ | ✓ | — | ✓ | — | — |
| Return for Correction | ✓ | ✓ | — | ✓ | — | — |
| Verify Form | ✓ | ✓ | — | ✓ | — | — |
| Approve Form | ✓ | ✓ | — | — | — | — |
| Change Household Head | ✓ | ✓ | — | R | R | — |
| Transfer Person | ✓ | ✓ | — | R | R | — |
| Create Assessment | ✓ | ✓ | R | ✓ | ✓ | — |
| Verify Assessment | ✓ | ✓ | — | ✓ | R | — |
| Create Need | ✓ | ✓ | R | R | ✓ | — |
| Verify Need | ✓ | ✓ | — | ✓ | R | — |
| Record Assistance | ✓ | ✓ | — | R | ✓ | — |
| Add Case Note | ✓ | ✓ | — | R | ✓ | — |
| View Confidential Notes | ✓ | R | — | R | R | — |
| View Health Data | ✓ | R | R | R | ✓ | — |
| View Disability Data | ✓ | R | R | R | ✓ | — |
| View Full National ID | ✓ | R | R | R | R | — |
| Verify Document | ✓ | ✓ | — | ✓ | R | — |
| View Audit Log | ✓ | R | — | — | — | — |
| Manage Users | ✓ | ✓ | — | — | — | — |
| Manage Roles | ✓ | R | — | — | — | — |
| Manage Permissions | ✓ | — | — | — | — | — |
| View Reports | ✓ | ✓ | R | R | R | ✓ |
| Export Basic Data | ✓ | ✓ | R | R | R | R |
| Export Sensitive Data | ✓ | R | — | — | R | — |

`R` means the capability is not automatically granted solely because of the role.

It requires explicit permission and/or appropriate scope.

---

# 60. Recommended DATA_ENTRY Permissions

Baseline:

```text
family.view
family.create

person.view
person.create

membership.view
membership.create

residence.view
residence.create

education.view
education.create

employment.view
employment.create

form.view
form.create
form.update
form.submit
form.send_to_review
form.correct
form.resubmit

document.view
document.upload

national_id.view
```

Additional sensitive permissions should be granted only if operationally required.

---

# 61. Recommended REVIEWER Permissions

Baseline:

```text
family.view
person.view
membership.view
residence.view

form.view
form.review
form.return
form.verify
form.view_history
form.view_source

assessment.view
assessment.review
assessment.verify

document.view
document.verify

duplicate.view
duplicate.review

need.view
need.verify

workflow_history.view
```

Sensitive-data permissions should be granted according to review responsibilities.

---

# 62. Recommended SOCIAL_WORKER Permissions

Baseline:

```text
family.view
family.update

person.view
person.update

membership.view

residence.view
residence.create
residence.update
residence.close
residence.view_history

assessment.view
assessment.create
assessment.update
assessment.complete

need.view
need.create
need.update

assistance.view
assistance.create

person_note.view
person_note.create

case_note.view
case_note.create

health.view
health.create
health.update

disability.view
disability.create
disability.update

education.view
education.create
education.update

employment.view
employment.create
employment.update
```

Actual scope may be:

```text
ASSIGNED
```

rather than:

```text
ALL
```

---

# 63. Recommended REPORTS_VIEWER Permissions

Baseline:

```text
report.view_dashboard
report.view_family
report.view_demographics
report.view_needs
report.view_assistance
```

Optional:

```text
export.basic
```

No person-level sensitive permissions are included by default.

---

# 64. Recommended ADMINISTRATOR Permissions

Administrator may receive broad operational permissions including:

```text
family.*
person.*
membership.*
residence.*
assessment.*
form.*
need.*
assistance.*
document.*
report.*
user.*
reference.*
audit.view
workflow_history.view
```

But permissions such as:

```text
permission.assign
duplicate.merge
export.sensitive_data
audit.view_sensitive
```

should remain separately controlled.

Avoid implementing Administrator as an unconditional application bypass.

---

# 65. SUPER_ADMIN Bypass

A technical Super Admin may use:

```php
Gate::before(...)
```

to provide system-wide authorization.

However:

- The role must be tightly controlled.
- Usage must be auditable.
- It must not be assigned to normal operational users.
- Sensitive operations should still generate audit events.

---

# 66. Laravel Policies

Recommended policies:

```text
FamilyPolicy
PersonPolicy
FamilyMembershipPolicy
FamilyResidencePolicy
AssessmentPolicy
FormSubmissionPolicy
FamilyNeedPolicy
AssistanceRecordPolicy
DocumentPolicy
PersonNotePolicy
CaseNotePolicy
UserPolicy
```

Policies should evaluate:

```text
Permission
+
Scope
+
Record State
+
Sensitivity
```

---

# 67. Example Policy Logic

Conceptual:

```php
public function update(User $user, FormSubmission $form): bool
{
    if (! $user->can('form.update')) {
        return false;
    }

    if (! $this->withinScope($user, $form)) {
        return false;
    }

    return in_array($form->status, [
        'DRAFT',
        'RETURNED_FOR_CORRECTION',
    ]);
}
```

Workflow services must still validate transitions independently.

---

# 68. Action Authorization

Critical actions should use dedicated Laravel Action/Service classes.

Example:

```text
VerifyFormAction
```

should perform:

```text
1. Authorize form.verify
2. Check scope
3. Check current status
4. Enforce maker-checker rule
5. Validate record
6. Change status
7. Create workflow event
8. Create audit event
9. Commit transaction
```

---

# 69. Query Authorization

Do not:

```php
Family::all();
```

and then hide unauthorized records in the interface.

Instead:

```text
Apply authorized scope
↓
Execute query
↓
Return authorized records
```

This is especially important for:

```text
Search
Tables
Dashboards
Reports
Exports
APIs
```

---

# 70. Filament Authorization

Filament resources must use Laravel authorization.

Examples:

```text
canViewAny
canView
canCreate
canEdit
canDelete
```

Custom actions:

```text
Verify
Approve
Transfer Person
Change Household Head
Archive
Export
```

must call the same authorization layer used by the backend.

---

# 71. Navigation Visibility

Filament navigation may hide modules users cannot access.

Example:

```text
Reports
```

hidden when the user has no report permissions.

However:

```text
Hidden Navigation
≠
Security
```

Direct URL access must still be denied server-side.

---

# 72. Sensitive Field Rendering

Fields should support states such as:

```text
FULL
MASKED
HIDDEN
```

Example:

```text
National ID

FULL
804123432

MASKED
804****32

HIDDEN
—
```

Authorization determines the rendering mode.

---

# 73. API Authorization

Future REST APIs must apply the same:

```text
Permissions
Scopes
Field Restrictions
Workflow Rules
```

used by Filament.

An API must not become a way to bypass UI authorization.

---

# 74. Search Authorization

Search results must be authorization-aware.

Example:

```text
Search National ID
      ↓
Can Search Restricted Identity Data?
      ↓
Apply Scope
      ↓
Return Authorized Result
```

Unauthorized users must not infer the existence of restricted records through search responses.

---

# 75. Dashboard Authorization

Dashboard statistics should also respect scope.

Example:

A Social Worker assigned 50 families should not necessarily see:

```text
Total Families in Entire Registry
```

unless authorized.

Instead they may see:

```text
My Assigned Families
```

---

# 76. Report Aggregation Privacy

Aggregated reports may expose statistics without exposing individual identities.

Example:

```text
Families with disability cases: 82
```

does not necessarily require permission to view every person's disability record.

However, drill-down to identifiable Persons does.

---

# 77. Sensitive Export Logging

Sensitive exports should record at minimum:

```text
User
Export Type
Filters
Scope
Fields Included
Timestamp
```

Optionally:

```text
Row Count
Generated File Reference
Reason
```

depending on final security requirements.

---

# 78. Session Security

Authentication/session implementation should support:

```text
Secure password hashing
Session invalidation
Account deactivation
CSRF protection
Secure cookies in production
Login throttling
```

Optional later improvements:

```text
2FA
Trusted devices
IP monitoring
```

---

# 79. Account Deactivation

When:

```text
users.is_active = false
```

the user must not be allowed to continue normal authenticated access.

Active sessions should be invalidated where technically supported.

Historical records remain attributed to the deactivated user.

---

# 80. User Deletion

Users referenced by:

```text
created_by
updated_by
entered_by
reviewed_by
collector_id
reviewer_id
performed_by
```

should not normally be physically deleted.

Prefer:

```text
Deactivate Account
```

while preserving attribution history.

---

# 81. Permission Changes

Changes to:

```text
Roles
Permissions
User Role Assignments
Sensitive Access
```

should be audited.

Especially:

```text
permission.assign
role.update
user.activate
user.deactivate
```

---

# 82. Permission Cache

When using:

```text
spatie/laravel-permission
```

permission cache must be correctly invalidated after role/permission changes.

Authorization changes should take effect predictably.

---

# 83. Permission Seeder

Roles and permissions should be seeded from version-controlled definitions.

Example:

```text
RolePermissionSeeder
```

The seeder should:

```text
Create missing permissions
Create approved roles
Assign baseline permissions
```

It should not silently remove operationally assigned permissions without an explicit migration/administrative strategy.

---

# 84. Permission Naming Stability

Once used in production:

```text
form.verify
person.transfer_family
national_id.view_full
```

should be treated as stable identifiers.

Renaming permissions requires a controlled migration.

---

# 85. No Wildcard Dependency

Avoid designing business logic around unrestricted wildcard permissions such as:

```text
*
```

except where a deliberate Super Admin bypass exists.

Explicit permissions improve:

```text
Auditability
Testing
Review
Security
```

---

# 86. Authorization Tests

Minimum automated authorization tests:

```text
Data Entry can create Draft.

Data Entry cannot verify Form.

Reviewer can review submitted Form.

Reviewer can return Form with reason.

Reviewer can verify eligible Form.

Reviewer cannot verify own submission by default.

Reviewer cannot approve Form by default.

Administrator can approve verified Form.

Reports Viewer cannot modify Family.

User without health.view cannot access health details.

User without disability.view cannot access disability details.

Masked National ID is shown without view_full.

User without confidential_note.view cannot access confidential notes.

View permission does not automatically allow export.

User without person.transfer_family cannot transfer Person.

User without family.change_head cannot change household head.

Archived records cannot be casually modified.

Deactivated user cannot access application.

Unauthorized records are excluded from search.

Unauthorized records are excluded from reports.

Unauthorized fields are excluded from exports.

Workflow actions reject invalid state transitions.
```

---

# 87. Security Invariants

```text
AUTH-INV-001
No permission means no access.

AUTH-INV-002
UI visibility never replaces backend authorization.

AUTH-INV-003
View permission does not imply export permission.

AUTH-INV-004
Person view does not imply health/disability access.

AUTH-INV-005
Case-note access does not imply confidential-note access.

AUTH-INV-006
Generic update permission does not imply workflow-transition permission.

AUTH-INV-007
Generic Person update does not imply Person transfer.

AUTH-INV-008
Generic Family update does not imply household-head change.

AUTH-INV-009
Normal users cannot modify workflow history.

AUTH-INV-010
Normal users cannot modify audit history.

AUTH-INV-011
Record scope applies server-side.

AUTH-INV-012
Sensitive exports require explicit permission.

AUTH-INV-013
Deactivated accounts cannot perform application actions.

AUTH-INV-014
No normal operational role receives destructive hard-delete access to core history.

AUTH-INV-015
Critical privileged actions are auditable.
```

---

# 88. Approved Authorization Decisions V1

### AUTH-ADR-001

Famboook uses RBAC with additional data-scope and field-level controls.

### AUTH-ADR-002

Permissions use stable:

```text
resource.action
```

identifiers.

### AUTH-ADR-003

Workflow actions receive dedicated permissions.

### AUTH-ADR-004

Export permissions are separate from view permissions.

### AUTH-ADR-005

Health and disability information require explicit access.

### AUTH-ADR-006

National ID access supports restricted/masked behavior.

### AUTH-ADR-007

Confidential notes require separate permission.

### AUTH-ADR-008

Person transfer requires a dedicated permission.

### AUTH-ADR-009

Household-head change requires a dedicated permission.

### AUTH-ADR-010

Duplicate review and duplicate merge are separate permissions.

### AUTH-ADR-011

Normal operational users cannot hard-delete core historical records.

### AUTH-ADR-012

Authorization is enforced server-side.

### AUTH-ADR-013

Filament and future APIs share the same authorization model.

### AUTH-ADR-014

Super Admin access is tightly controlled.

### AUTH-ADR-015

Permission and role changes are auditable.

---

# 89. Pending Authorization Decisions

### PAUTH-001 — Reviewer Assignment

Determine whether Reviewer scope uses:

```text
ALL
ASSIGNED
```

or both.

---

### PAUTH-002 — Social Worker Scope

Determine whether Social Workers initially access:

```text
ALL Families
```

or only:

```text
Assigned Families
```

---

### PAUTH-003 — Data Entry Sensitive Access

Determine the exact sensitive fields required during paper-form transcription.

---

### PAUTH-004 — Approval Authority

Confirm whether:

```text
ADMINISTRATOR
```

is the normal final approval role or whether a dedicated:

```text
APPROVER
```

role should be introduced.

---

### PAUTH-005 — Sensitive Export

Define which organizational positions may receive:

```text
export.sensitive_data
```

---

### PAUTH-006 — Duplicate Merge

Determine whether V1 implements:

```text
duplicate.merge
```

or defers merge tooling.

---

### PAUTH-007 — Confidential Notes

Define which operational roles receive:

```text
confidential_note.view
```

by default.

---

### PAUTH-008 — Two-Factor Authentication

Determine whether privileged roles require 2FA for V1 production.

---

### PAUTH-009 — Regional Scope

Determine whether organizational deployment requires:

```text
Governorate
Area
Branch
Team
```

scope in V1.

Do not introduce these scopes without an actual operational requirement.

---

# 90. Permission Review Before Production

Before production launch:

```text
[ ] Review all roles
[ ] Review every permission
[ ] Review sensitive fields
[ ] Review National ID visibility
[ ] Review health/disability visibility
[ ] Review confidential notes
[ ] Review document access
[ ] Review export permissions
[ ] Review Reviewer scope
[ ] Review Social Worker scope
[ ] Review approval authority
[ ] Review duplicate-resolution authority
[ ] Review Super Admin accounts
[ ] Test deny-by-default behavior
[ ] Test search authorization
[ ] Test report authorization
[ ] Test export authorization
```

---

# 91. Documentation Synchronization

Authorization implementation must remain consistent with:

```text
01-PRODUCT.md
02-DATA-DICTIONARY.md
03-BUSINESS-RULES.md
04-DATABASE.md
05-WORKFLOWS.md
06-PERMISSIONS.md
```

If a workflow action is introduced, its authorization requirement must also be defined.

Example:

```text
New Workflow Action
      ↓
Define Permission
      ↓
Assign Role(s)
      ↓
Implement Policy
      ↓
Add Tests
```

---

# 92. Implementation Readiness

After this document:

```text
Product       ✓
Data Model    ✓
Rules         ✓
Database      ✓
Workflows     ✓
Permissions   ✓
```

The final planning document before implementation is:

```text
07-ROADMAP.md
```

---

# 93. Document Status

```text
Project: Famboook
Document: Roles, Permissions & Access Control
Version: 1.0
Status: APPROVED
Date: 2026-09-22
```

---

# 94. Change Log

| Version | Date | Status | Description |
|---|---|---|---|
| 1.0 | 2026-09-22 | Approved | Initial Famboook RBAC, data-scope, workflow authorization, sensitive-data, export, and field-level access model |

---

# 95. Next Step

Current documentation status:

```text
00-PROJECT-CONTEXT.md          APPROVED
01-PRODUCT.md                  APPROVED
02-DATA-DICTIONARY.md          APPROVED
03-BUSINESS-RULES.md           APPROVED
04-DATABASE.md                 APPROVED — v1.1
05-WORKFLOWS.md                APPROVED
06-PERMISSIONS.md              APPROVED
07-ROADMAP.md                  NEXT
```

`07-ROADMAP.md` will convert the approved architecture into implementation phases, including:

```text
Foundation
    ↓
Laravel + PostgreSQL
    ↓
Authentication & RBAC
    ↓
Reference Data
    ↓
Family / Person Registry
    ↓
Data Entry
    ↓
Verification Workflow
    ↓
Assessments
    ↓
Needs & Assistance
    ↓
Documents & Notes
    ↓
Reports
    ↓
Security Hardening
    ↓
Testing
    ↓
Deployment
```

No production implementation should bypass the approved architecture baseline.