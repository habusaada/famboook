# Famboook
## Permissions & Access Control

**Document:** `06-PERMISSIONS.md`  
**Version:** 1.1  
**Status:** Approved  
**Last Updated:** 2026-09-22  
**Project:** Famboook — Family Registry & Case Management System  
**Authorization Model:** RBAC + Data Scope + Field-Level Access + Workflow State

---

# 1. Purpose

This document defines the authorization and access-control architecture for Famboook.

It specifies:

```text
Who may access the system

What each role may do

Which records each user may access

Which fields each user may see

Which workflow transitions each user may perform

How Family Users access their Family

How sensitive data is protected

How Change Requests are authorized

How Staff and Family Portal permissions remain separated
```

This document applies to:

```text
Staff Portal
Family Portal
Filament
Future API
Future Frontend
Reports
Exports
Documents
Workflow Actions
```

---

# 2. Authorization Principles

Famboook follows these authorization principles:

```text
Deny by Default

Least Privilege

Explicit Permissions

Server-Side Enforcement

Data Scope Enforcement

Field-Level Protection

Workflow-Aware Authorization

Sensitive Data Protection

Separation of Duties

No Shared Accounts

Audit Critical Actions

Family Users Never Receive Staff Privileges

Family Portal Access Is Relationship-Based
```

---

# 3. Authorization Architecture

Authorization is not determined by Role alone.

The effective authorization model is:

```text
ROLE
  +
PERMISSION
  +
DATA SCOPE
  +
FIELD ACCESS
  +
WORKFLOW STATE
  +
DOMAIN RULES
```

For Family Portal:

```text
FAMILY_USER
  +
ACTIVE USER-PERSON LINK
  +
ACTIVE FAMILY MEMBERSHIP
  +
FAMILY ACCESS POLICY
  +
FIELD ACCESS
  +
WORKFLOW STATE
```

---

# 4. Authentication vs Authorization

Authentication answers:

```text
Who is this User?
```

Authorization answers:

```text
What may this User do?
```

Registry identity answers:

```text
Which Person does this User represent?
```

These concepts must remain separate.

---

# 5. User vs Person

Famboook intentionally separates:

```text
users
```

from:

```text
persons
```

Meaning:

```text
User
=
Authentication Identity

Person
=
Registry Identity
```

A Staff User may exist without a Person record.

A Family User normally requires an approved link to a Person.

---

# 6. Authorization Technology

Recommended Laravel authorization stack:

```text
spatie/laravel-permission

Laravel Policies

Laravel Gates

Query Scopes

Domain Actions / Services

API Resources / DTOs
```

Filament must use the same authorization layer.

---

# 7. Role-Based Access Control

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

# 8. Role Labels

Suggested Arabic labels:

```text
SUPER_ADMIN
مدير النظام الأعلى

ADMINISTRATOR
مدير النظام

DATA_ENTRY
مدخل بيانات

REVIEWER
مدقق / مراجع

SOCIAL_WORKER
باحث / أخصائي اجتماعي

REPORTS_VIEWER
مستخدم التقارير

FAMILY_USER
مستخدم الأسرة / رب الأسرة
```

The Arabic label for `FAMILY_USER` may later be adjusted according to the final policy if authorized representatives are supported.

---

# 9. SUPER_ADMIN

Purpose:

```text
Technical/system-level administration.
```

May include:

```text
System configuration
Role management
Permission management
User administration
Reference data administration
Emergency troubleshooting
Full technical access where required
```

Use very few accounts.

---

# 10. SUPER_ADMIN Rule

`SUPER_ADMIN` must not become a routine operational role.

Recommended:

```text
1–2 controlled accounts
```

with stronger security requirements.

---

# 11. ADMINISTRATOR

Purpose:

```text
Operational administration and high-level business control.
```

Typical responsibilities:

```text
Approve records
Manage operational users
Manage selected reference data
Handle exceptional workflow actions
Review high-risk Change Requests
Apply high-risk approved changes
Review audit information
```

Administrator is not automatically equivalent to unrestricted technical access.

---

# 12. DATA_ENTRY

Purpose:

```text
Enter and correct registry data.
```

Typical capabilities:

```text
Create Family
Create Person
Add Family Member
Create Residence
Enter assessments
Upload source documents
Submit forms
Correct returned forms
```

Normally cannot:

```text
Verify own submission
Approve
Manage users
Manage roles
Manage permissions
Merge Persons
Access unrestricted audit
Perform unrestricted sensitive exports
```

---

# 13. REVIEWER

Purpose:

```text
Review and verify submitted records.
```

Typical capabilities:

```text
View submitted records
Review source forms
Review duplicate warnings
Return for correction
Verify forms
Review Change Requests
Return Change Requests for clarification
Verify supporting documents
```

Normally cannot:

```text
Final approve high-risk operations
Manage users
Manage roles
Manage permissions
```

---

# 14. SOCIAL_WORKER

Purpose:

```text
Case management and follow-up.
```

Typical capabilities:

```text
View assigned/authorized Families
View relevant Persons
Create assessments
Manage needs
Record assistance
Create case notes
Create person notes
Review relevant documents
```

Sensitive access depends on approved operational need.

---

# 15. REPORTS_VIEWER

Purpose:

```text
Reporting and aggregate analysis.
```

Typical capabilities:

```text
Dashboard
Aggregate reports
Demographic reports
Needs reports
Assistance reports
Approved exports
```

By default:

```text
No unrestricted National IDs

No unrestricted confidential notes

No unrestricted identity documents

No unrestricted person-level health details
```

---

# 16. FAMILY_USER

Purpose:

```text
Allow an authorized Family representative to securely access Family Portal.
```

Typical V1 capabilities:

```text
View own authorized Family

View permitted Family members

View permitted Family information

View permitted current residence

View own Change Requests

Create Change Requests

Submit Change Requests

Respond to clarification

Resubmit Change Requests

Upload supporting documents

View Family-visible request decisions

Receive notifications
```

Family User does not receive direct Staff Portal access.

---

# 17. FAMILY_USER Is Not a Staff Role

`FAMILY_USER` must never implicitly inherit:

```text
DATA_ENTRY
REVIEWER
SOCIAL_WORKER
ADMINISTRATOR
```

permissions.

Family Portal permissions are explicitly assigned.

---

# 18. Family User Canonical Update Rule

Family User must not directly modify canonical registry tables such as:

```text
families

persons

family_memberships

family_residences

person_health_conditions

person_disabilities

person_education

person_employment
```

Canonical changes are proposed through:

```text
change_requests
```

and applied through controlled domain actions after approval.

---

# 19. Permission Naming Convention

Use:

```text
<resource>.<action>
```

Examples:

```text
family.view

person.update

form.verify

change_request.submit

document.verify
```

Permission names should remain stable.

---

# 20. Family Permissions

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

# 21. Person Permissions

```text
person.view

person.create

person.update

person.archive

person.restore

person.view_history

person.transfer_family
```

---

# 22. Membership Permissions

```text
membership.view

membership.create

membership.update

membership.end

membership.change_relationship

membership.view_history
```

---

# 23. Residence Permissions

```text
residence.view

residence.create

residence.update

residence.close

residence.view_history
```

---

# 24. Health Permissions

```text
health.view

health.create

health.update

health_condition.view

health_condition.create

health_condition.update

health_condition.end
```

Health data is:

```text
RESTRICTED
```

---

# 25. Disability Permissions

```text
disability.view

disability.create

disability.update

disability.end
```

Disability data is:

```text
RESTRICTED
```

---

# 26. Education Permissions

```text
education.view

education.create

education.update

education.end

education.view_history
```

---

# 27. Employment Permissions

```text
employment.view

employment.create

employment.update

employment.end

employment.view_history
```

---

# 28. Assessment Permissions

```text
assessment.view

assessment.create

assessment.update

assessment.complete

assessment.review

assessment.verify

assessment.view_history
```

---

# 29. Form Permissions

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

---

# 30. Need Permissions

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

---

# 31. Assistance Permissions

```text
assistance.view

assistance.create

assistance.update

assistance.void

assistance.view_history
```

Assistance records should not normally be physically deleted.

---

# 32. Document Permissions

```text
document.view

document.upload

document.update_metadata

document.verify

document.replace

document.archive

document.download
```

---

# 33. Note Permissions

```text
person_note.view

person_note.create

case_note.view

case_note.create

confidential_note.view

confidential_note.create
```

---

# 34. National ID Permissions

National ID receives dedicated permissions:

```text
national_id.view

national_id.view_full

national_id.update
```

---

# 35. National ID Visibility Modes

Possible field-level modes:

```text
HIDDEN

MASKED

FULL
```

Example:

```text
804****32
```

---

# 36. Export Permissions

```text
export.basic

export.personal_data

export.sensitive_data
```

Export permission is separate from normal view permission.

---

# 37. Report Permissions

```text
report.dashboard

report.family

report.demographics

report.health

report.needs

report.assistance

report.export
```

---

# 38. Audit Permissions

```text
audit.view

audit.view_sensitive
```

Normal Family Users have neither.

---

# 39. Workflow History Permission

```text
workflow_history.view
```

This refers to internal workflow history.

Family User request history is exposed through dedicated Family Portal resources, not unrestricted workflow history.

---

# 40. User Administration Permissions

```text
user.view

user.create

user.update

user.activate

user.deactivate

user.reset_access
```

---

# 41. Role Permissions

```text
role.view

role.create

role.update

role.delete
```

---

# 42. Permission Administration

```text
permission.view

permission.assign
```

This is highly privileged.

---

# 43. Reference Data Permissions

```text
reference.view

reference.create

reference.update

reference.deactivate
```

---

# 44. System Settings Permissions

```text
system_settings.view

system_settings.update
```

---

# 45. User-Person Link Permissions

New V1 permissions:

```text
user_person_link.view

user_person_link.create

user_person_link.verify

user_person_link.activate

user_person_link.suspend

user_person_link.end

user_person_link.view_history
```

These are staff permissions.

Family User cannot verify their own identity link.

---

# 46. Family Portal Permissions

Recommended Family Portal permissions:

```text
family_portal.access

family_portal.family.view

family_portal.member.view

family_portal.residence.view

family_portal.document.view

family_portal.request.view

family_portal.notification.view
```

These permissions are always subject to Family scope.

---

# 47. Change Request Permissions

Recommended:

```text
change_request.view

change_request.create

change_request.update_draft

change_request.submit

change_request.review

change_request.return

change_request.resubmit

change_request.approve

change_request.reject

change_request.apply

change_request.view_history

change_request.view_internal_notes
```

---

# 48. Family User Change Request Permissions

Recommended Family User bundle:

```text
change_request.view

change_request.create

change_request.update_draft

change_request.submit

change_request.resubmit
```

But these permissions are constrained to:

```text
Authorized Family
+
Allowed Request Types
+
Allowed Workflow States
```

---

# 49. Family User Cannot Review Own Request

Family User never receives:

```text
change_request.review

change_request.approve

change_request.reject

change_request.apply
```

---

# 50. Notification Permissions

```text
notification.view

notification.mark_read
```

Staff notification administration, if later needed, should use separate permissions.

---

# 51. Data Scope

Role and Permission answer:

```text
What action may the User perform?
```

Data Scope answers:

```text
On which records?
```

---

# 52. Staff Data Scopes

Recommended V1:

```text
ALL

ASSIGNED

CREATED_BY_ME
```

Future:

```text
REGION

BRANCH

TEAM

CASELOAD
```

only when operational requirements justify them.

---

# 53. Family Portal Data Scopes

Family Portal introduces:

```text
SELF

FAMILY
```

---

# 54. SELF Scope

`SELF` means:

```text
The Person linked to the authenticated User.
```

Resolution:

```text
User
  ↓
Active User-Person Link
  ↓
Person
```

---

# 55. FAMILY Scope

`FAMILY` means:

```text
The Family reached through the linked Person's active Family Membership,
subject to Family access policy.
```

Resolution:

```text
User
  ↓
Active User-Person Link
  ↓
Person
  ↓
Active Family Membership
  ↓
Family
```

---

# 56. Family Scope Is Dynamic

Do not store canonical authorization as:

```text
users.family_id
```

Family scope must be resolved from current relationships.

This allows:

```text
Person transfer

Household Head change

Membership ending

Death

Access suspension
```

to affect authorization correctly.

---

# 57. FAMILY_USER Scope

Recommended V1:

```text
FAMILY_USER
→ SELF + authorized FAMILY
```

but field visibility differs between:

```text
SELF
```

and:

```text
OTHER FAMILY MEMBER
```

---

# 58. Family Portal Household Head Rule

Security-first V1 recommendation:

Family-wide access requires:

```text
Active User

Active User-Person Link

Active Person

Active Family Membership

is_household_head = TRUE
```

unless a future approved representative model is enabled.

---

# 59. Role Does Not Establish Family Scope

This is invalid:

```text
User has FAMILY_USER role
→ therefore can access Family 510
```

Correct:

```text
FAMILY_USER role
+
family_portal.access
+
active User-Person link
+
active Membership
+
Household Head eligibility
=
authorized Family scope
```

---

# 60. ALL Scope

`ALL` means:

```text
All records allowed by the permission and field rules.
```

It does not mean:

```text
All fields
All sensitive information
All workflow actions
```

---

# 61. ASSIGNED Scope

Useful for:

```text
REVIEWER
SOCIAL_WORKER
```

Examples:

```text
Assigned review requests

Assigned cases

Assigned Families
```

---

# 62. CREATED_BY_ME Scope

Useful for:

```text
DATA_ENTRY
```

particularly for Draft records before submission.

---

# 63. Scope Enforcement

Scope must be enforced in database queries.

Do not:

```text
Fetch all records
then hide unauthorized records in UI.
```

---

# 64. Family Portal Object-Level Authorization

Every Family Portal request must validate the target resource.

Example:

```text
/family-portal/families/510
```

Server must verify:

```text
Family 510 belongs to authenticated Family scope.
```

Never trust route IDs.

---

# 65. Person Object-Level Authorization

Family User requesting:

```text
/persons/1825
```

must pass:

```text
Is SELF?
or
Is Person an authorized active member of FAMILY?
```

and then field-level restrictions are applied.

---

# 66. Change Request Object Authorization

Family User may view a Change Request only when:

```text
request.family_id
=
authorized Family
```

and request visibility policy allows it.

---

# 67. Document Object Authorization

Document access must check:

```text
Document Context

Family Scope

Person Scope

Change Request Scope

Document Type

Sensitivity

Download Permission
```

Possession of a file URL is never authorization.

---

# 68. Field-Level Access

Record access does not imply access to every field.

Example:

```text
Family User may view Person
```

does not imply:

```text
Family User may view Person National ID
```

---

# 69. Field Visibility Levels

Recommended:

```text
FULL

MASKED

HIDDEN
```

---

# 70. Family User — Own Person Fields

Recommended baseline for `SELF`:

```text
Person Code           FULL
Full Name             FULL
Gender                FULL
Birth Date            FULL
Life Status           FULL
Mobile                FULL
Alternate Mobile      FULL where applicable
Marital Status        FULL
National ID           MASKED by default
Family Relationship   FULL
```

Full National ID may be shown only if explicitly approved.

---

# 71. Family User — Other Member Fields

Recommended baseline:

```text
Person Code           FULL
Full Name             FULL
Gender                FULL
Birth Date            FULL or policy-controlled
Life Status           FULL
Relationship          FULL
Mobile                HIDDEN by default
National ID           HIDDEN by default
Alternate Mobile      HIDDEN
```

This protects adult members from unnecessary disclosure.

---

# 72. Minor Member Fields

A Household Head may operationally need more information about dependent minors.

However, V1 must not assume unrestricted access to every sensitive field.

Specific minor/dependent visibility should be policy-controlled.

---

# 73. Spouse Data

Being Household Head does not automatically justify unrestricted access to all spouse-sensitive data.

Default:

```text
Identity summary       Allowed

National ID            Hidden/Masked

Health                  Hidden unless explicitly approved

Confidential Notes      Hidden
```

---

# 74. Health Data in Family Portal

Default V1:

```text
Family User
→ no unrestricted person-level health data
```

Possible future controlled views may include:

```text
SELF health data

Dependent minor health data

Selected household-level indicators
```

only after explicit policy approval.

---

# 75. Disability Data in Family Portal

Default:

```text
Restricted
```

Family User should not automatically receive unrestricted disability details for all members.

---

# 76. Confidential Notes

Always hidden from Family Portal:

```text
person_notes where confidential

case_notes where confidential

internal reviewer notes

internal audit notes
```

unless a future explicit disclosure workflow is created.

---

# 77. Internal Audit

Family Users never receive:

```text
audit.view
audit.view_sensitive
```

Family-facing history must use a dedicated safe representation.

---

# 78. Internal Workflow Metadata

Family Users do not receive unrestricted:

```text
workflow_events.metadata
```

They may receive simplified status history such as:

```text
Submitted
Under Review
Returned
Approved
Applied
```

---

# 79. Change Request Proposed Data

Family User may view proposed data for their authorized request.

Staff visibility depends on:

```text
Role
Permission
Request Type
Sensitive Field Rules
```

---

# 80. Sensitive Change Request Payloads

Examples:

```text
National ID correction

Health-related correction

Disability-related update
```

must receive field-level restrictions.

Generic:

```text
change_request.view
```

must not automatically expose all sensitive JSON payload content.

---

# 81. Request Type Authorization

Not every Family User necessarily receives every Change Request type.

Recommended type-specific authorization:

```text
change_request.contact.create

change_request.residence.create

change_request.person_correction.create

change_request.add_member.create

change_request.membership_change.create

change_request.head_change.create

change_request.birth.create

change_request.death.create

change_request.marriage.create

change_request.document.create
```

---

# 82. Why Type-Specific Permissions

This allows future policy such as:

```text
All Family Users
→ Contact Update

Verified Household Heads
→ Add Member

Verified Household Heads
→ Birth Report

Higher assurance
→ Household Head Change
```

without redesigning the authorization system.

---

# 83. Recommended Family User Request Permissions

Initial V1 recommendation:

```text
change_request.contact.create

change_request.residence.create

change_request.person_correction.create

change_request.add_member.create

change_request.birth.create

change_request.death.create

change_request.marriage.create

change_request.document.create
```

Potentially restricted initially:

```text
change_request.membership_change.create

change_request.head_change.create
```

until workflow policy is finalized.

---

# 84. Workflow-State Authorization

Permission alone is not enough.

Example:

```text
change_request.update_draft
```

works only when:

```text
status = DRAFT
```

---

# 85. Family User State Rules

Family User may:

```text
DRAFT
→ edit

DRAFT
→ submit

RETURNED_FOR_CLARIFICATION
→ provide clarification

RETURNED_FOR_CLARIFICATION
→ resubmit
```

Family User may not modify:

```text
UNDER_REVIEW

APPROVED

REJECTED

APPLIED
```

except through explicitly permitted response workflows.

---

# 86. Staff Change Request State Rules

Reviewer:

```text
SUBMITTED
→ UNDER_REVIEW

UNDER_REVIEW
→ RETURNED_FOR_CLARIFICATION

UNDER_REVIEW
→ recommended/authorized outcome
```

Approver:

```text
UNDER_REVIEW
→ APPROVED

UNDER_REVIEW
→ REJECTED
```

Applier:

```text
APPROVED
→ APPLIED
```

Exact separation depends on risk level and role policy.

---

# 87. Maker-Checker

For internal form workflow:

```text
Data Entry User
≠
Reviewer
```

where practical.

For Family Change Requests:

```text
Family User
≠
Reviewer

Family User
≠
Approver

Family User
≠
Applier
```

always.

---

# 88. Reviewer vs Approver

For high-risk requests, recommended:

```text
Reviewer
≠
Approver
```

Examples:

```text
National ID correction

Household Head change

Membership transfer

Death report
```

subject to staffing capacity.

---

# 89. Approver vs Applier

V1 may allow:

```text
Approver = Applier
```

for lower-risk requests.

High-risk operations may later require separation.

The system architecture must support both.

---

# 90. Risk-Level Authorization

Potential request levels:

```text
LOW
MEDIUM
HIGH
```

Risk may determine:

```text
Required evidence

Reviewer role

Approver role

Application method

Additional confirmation
```

---

# 91. Low-Risk Example

Possible:

```text
Contact Update
```

may require:

```text
Review
Approval
Apply
```

with a simpler authorization path.

---

# 92. High-Risk Example

Possible:

```text
Household Head Change
```

may require:

```text
Review

Evidence

Independent Approval

Controlled Application

Portal Access Reevaluation
```

---

# 93. User-Person Link Authorization

Creating or activating a User-Person link is security-sensitive.

Required permissions are separated:

```text
user_person_link.create

user_person_link.verify

user_person_link.activate
```

One action must not automatically imply the others.

---

# 94. Self-Link Prohibition

Family User cannot execute:

```text
user_person_link.verify
```

on their own proposed identity link.

Verification requires an authorized process.

---

# 95. Family User Activation

Recommended authorization:

```text
Authorized Staff
      ↓
Verify Identity
      ↓
Verify User-Person Link
      ↓
Validate Family Eligibility
      ↓
Activate Link
      ↓
Assign/Confirm FAMILY_USER Role
      ↓
Audit
```

---

# 96. Family User Suspension

Permission:

```text
user_person_link.suspend
```

or account-level:

```text
user.deactivate
```

depending on whether suspension applies to:

```text
One identity relationship
```

or:

```text
Entire User account
```

---

# 97. Family User Access Ending

Permission:

```text
user_person_link.end
```

Required:

```text
Reason
Actor
Timestamp
Audit
```

---

# 98. Household Head Change Authorization Impact

After Head change:

```text
Old Head Family-wide authorization
→ reevaluate immediately

New Head
→ does not automatically receive account access
```

New Head requires:

```text
User
+
Verified User-Person Link
+
Activation
```

---

# 99. Person Transfer Authorization Impact

If linked Person moves to another Family:

```text
Old Family authorization must end/recalculate.
```

Access to the target Family is not automatically granted unless the new membership and Family Portal policy allow it.

---

# 100. Death Authorization Impact

If linked Person is confirmed deceased:

```text
Family Portal access must be suspended/ended according to policy.
```

Historical account attribution remains preserved.

---

# 101. Family Archive Authorization Impact

Archived Family must not remain normally accessible through Family Portal.

Any exception requires explicit policy.

---

# 102. Document Upload by Family User

Family User may receive:

```text
document.upload
```

only through authorized Change Request/document workflows.

Upload does not grant:

```text
document.verify
```

---

# 103. Document Verification

Family User never verifies their own uploaded supporting document.

Verification requires authorized Staff.

---

# 104. Document Download

Family User may download only documents explicitly visible to Family Portal.

They cannot download documents merely because:

```text
document.family_id
=
their Family
```

Document Type and sensitivity must also permit it.

---

# 105. Document Storage Authorization

File storage must remain private.

Flow:

```text
Request File
      ↓
Authenticate
      ↓
Authorize Resource
      ↓
Authorize Document
      ↓
Stream / Temporary Signed Access
```

---

# 106. Notifications

Family User permissions:

```text
notification.view

notification.mark_read
```

only for their own User account.

---

# 107. Notification Ownership

Query must enforce:

```text
notifiable_id
=
authenticated user
```

and correct notifiable type.

---

# 108. Notification Privacy

Notification payload must not expose sensitive information that the user could not otherwise view.

---

# 109. Search Permissions

Staff search may include:

```text
family.search

person.search
```

or be incorporated into view permissions.

Family User must not receive global:

```text
person.search
family.search
```

---

# 110. Family Portal Member Search

If Family Portal needs search/filter:

```text
Search only within authorized Family members.
```

This is not a global registry search permission.

---

# 111. Reporting Permissions

Staff reporting follows:

```text
Permission
+
Data Scope
+
Field Restrictions
```

Family User does not receive general reporting access.

---

# 112. Family Summary

Family Portal may expose a dedicated:

```text
Family Summary
```

without granting:

```text
report.family
```

This distinction keeps Staff Reports separate from Family-facing views.

---

# 113. Aggregate Health Reports

A Staff user may be allowed to view:

```text
Aggregate chronic disease count
```

without receiving:

```text
Person-level diagnosis list
```

Aggregate reporting permissions may differ from record-level permissions.

---

# 114. Export Authorization

Export flow:

```text
Check Export Permission
      ↓
Resolve Scope
      ↓
Resolve Fields
      ↓
Apply Masking
      ↓
Generate
      ↓
Audit
```

---

# 115. Family User Export

Default V1:

```text
No generic export permissions.
```

If later required:

```text
family_portal.summary.download
```

should generate a controlled Family-facing document.

---

# 116. Sensitive Export Audit

Record:

```text
User

Export Type

Filters

Data Scope

Fields

Timestamp

Row Count where practical

Reason where required
```

---

# 117. API Authorization

Future APIs must use the same:

```text
Policies

Permissions

Scopes

Field Rules

Workflow Rules
```

Do not build separate authorization semantics for API clients.

---

# 118. API Serialization

Use:

```text
Laravel API Resources

DTOs

Transformers
```

to ensure unauthorized fields never reach the client.

---

# 119. Filament Authorization

Filament Resources must use:

```text
Policies
```

for:

```text
ViewAny

View

Create

Update

Delete / Archive

Restore
```

Custom Filament actions must also authorize through the backend.

---

# 120. Navigation Is Not Security

Hiding a Filament navigation item is not authorization.

A user manually entering a route must still be denied if unauthorized.

---

# 121. Family Portal Frontend Is Not Security

Hiding a button in React/Blade/Livewire does not prevent the request.

All Family Portal operations require server-side authorization.

---

# 122. Policy Classes

Recommended:

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

ChangeRequestPolicy

UserPersonLinkPolicy

UserPolicy
```

---

# 123. FamilyPolicy

Conceptually:

```text
Staff:
permission + staff scope

Family User:
family_portal.family.view
+
resolved Family scope
```

Do not implement one unrestricted `family.view` path for both contexts without scope differentiation.

---

# 124. PersonPolicy

Conceptually:

```text
Staff
→ person.view + staff scope

Family User
→ family_portal.member.view
   +
   SELF/FAMILY relationship
   +
   field restrictions
```

---

# 125. ChangeRequestPolicy

Example conceptual checks:

```text
View:
permission
+
authorized Family

Create:
FAMILY_USER
+
active Family scope
+
allowed request type

Update:
request owned/authorized
+
status = DRAFT

Submit:
authorized Family
+
status = DRAFT
+
validation complete

Review:
staff permission
+
scope
+
status allows review

Approve:
staff permission
+
scope
+
state
+
separation rules

Apply:
staff permission
+
status = APPROVED
```

---

# 126. UserPersonLinkPolicy

Sensitive operations include:

```text
Verify
Activate
Suspend
End
```

These must not be accessible to Family Users themselves unless a future secure automated verification service is explicitly designed.

---

# 127. Domain Action Authorization

Critical operations must reauthorize inside the application/domain layer.

Example:

```text
ApplyChangeRequestAction
```

must not assume authorization simply because a Controller already checked it.

---

# 128. ApplyChangeRequestAction

Conceptual authorization:

```text
Check change_request.apply

Check Staff scope

Check status = APPROVED

Check separation-of-duty rules

Check current Family state

Check request type

Check domain authorization

Execute transaction
```

---

# 129. Change Household Head Action

Authorization requires:

```text
family.change_head
```

for direct Staff action.

If initiated through approved Change Request:

```text
change_request.apply
+
domain authorization
```

ultimately invokes the same controlled domain operation.

---

# 130. Person Transfer Action

Requires:

```text
person.transfer_family
```

for direct Staff operation.

Family User cannot directly transfer a Person.

---

# 131. National ID Update

Requires:

```text
person.update

national_id.update
```

plus:

```text
Reason

Duplicate Check

Audit

Potential evidence
```

If initiated from Family Portal, the Family User only submits:

```text
PERSON_CORRECTION
```

request.

---

# 132. National ID View

Recommended baseline:

```text
SUPER_ADMIN
→ FULL where operationally required

ADMINISTRATOR
→ FULL where operationally required

DATA_ENTRY
→ FULL or MASKED based on task

REVIEWER
→ FULL where identity verification requires it

SOCIAL_WORKER
→ MASKED by default

REPORTS_VIEWER
→ HIDDEN by default

FAMILY_USER SELF
→ MASKED by default

FAMILY_USER OTHER MEMBER
→ HIDDEN by default
```

Final values require operational approval.

---

# 133. Health Access

Recommended baseline:

```text
SUPER_ADMIN
→ permission-controlled

ADMINISTRATOR
→ permission-controlled

DATA_ENTRY
→ where required for data entry

REVIEWER
→ where required for verification

SOCIAL_WORKER
→ where required for case work

REPORTS_VIEWER
→ aggregate only by default

FAMILY_USER
→ hidden by default
```

---

# 134. Disability Access

Same security classification as Health unless explicitly separated.

---

# 135. Confidential Note Access

Recommended:

```text
ADMINISTRATOR
SOCIAL_WORKER
```

where required.

Potential Reviewer access depends on operational policy.

Never Family User.

---

# 136. Role Permission Matrix Legend

```text
✓
Allowed baseline

—
Not allowed baseline

R
Restricted / additional field or scope rule

S
Self/Family scope only
```

---

# 137. High-Level Role Matrix

| Capability | Super Admin | Administrator | Data Entry | Reviewer | Social Worker | Reports Viewer | Family User |
|---|---:|---:|---:|---:|---:|---:|---:|
| Staff Portal | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — |
| Family Portal | R | R | — | — | — | — | ✓ |
| View Families | ✓ | ✓ | ✓ | ✓ | ✓ | R | S |
| Create Family | ✓ | ✓ | ✓ | — | R | — | — |
| Update Canonical Family | ✓ | ✓ | ✓ | — | R | — | — |
| View Persons | ✓ | ✓ | ✓ | ✓ | ✓ | R | S |
| Create Person | ✓ | ✓ | ✓ | — | R | — | — |
| Direct Person Update | ✓ | ✓ | ✓ | — | R | — | — |
| Submit Internal Form | ✓ | ✓ | ✓ | — | R | — | — |
| Verify Internal Form | ✓ | ✓ | — | ✓ | — | — | — |
| Approve Internal Form | ✓ | ✓ | — | — | — | — | — |
| Create Change Request | R | R | — | — | — | — | ✓ |
| Review Change Request | ✓ | ✓ | — | ✓ | R | — | — |
| Approve Change Request | ✓ | ✓ | — | R | — | — | — |
| Apply Change Request | ✓ | ✓ | — | R | — | — | — |
| Return Request | ✓ | ✓ | — | ✓ | R | — | — |
| Reject Request | ✓ | ✓ | — | R | — | — | — |
| Upload Support Document | ✓ | ✓ | ✓ | R | ✓ | — | S |
| Verify Document | ✓ | ✓ | — | ✓ | R | — | — |
| View Needs | ✓ | ✓ | ✓ | ✓ | ✓ | R | R |
| Manage Needs | ✓ | ✓ | R | R | ✓ | — | — |
| Record Assistance | ✓ | ✓ | R | — | ✓ | — | — |
| View Confidential Notes | ✓ | R | — | R | ✓ | — | — |
| View Full National ID | ✓ | R | R | R | — | — | — |
| View Audit | ✓ | ✓ | — | R | — | — | — |
| Manage Users | ✓ | ✓ | — | — | — | — | — |
| Manage Roles | ✓ | R | — | — | — | — | — |
| Manage Permissions | ✓ | R | — | — | — | — | — |
| Verify User-Person Link | ✓ | ✓ | — | R | R | — | — |
| Activate Family User | ✓ | ✓ | — | — | R | — | — |
| Sensitive Export | ✓ | R | — | — | R | — | — |

---

# 138. FAMILY_USER Baseline Permission Bundle

Recommended:

```text
family_portal.access

family_portal.family.view

family_portal.member.view

family_portal.residence.view

family_portal.request.view

family_portal.notification.view

change_request.view

change_request.create

change_request.update_draft

change_request.submit

change_request.resubmit

change_request.contact.create

change_request.residence.create

change_request.person_correction.create

change_request.add_member.create

change_request.birth.create

change_request.death.create

change_request.marriage.create

change_request.document.create

document.upload

notification.view

notification.mark_read
```

Potential later permissions:

```text
family_portal.document.view

change_request.membership_change.create

change_request.head_change.create
```

after policy approval.

---

# 139. DATA_ENTRY Baseline Bundle

Typical:

```text
family.view
family.create
family.update

person.view
person.create
person.update

membership.view
membership.create
membership.update

residence.view
residence.create
residence.update

assessment.view
assessment.create
assessment.update

form.view
form.create
form.update
form.submit
form.send_to_review
form.correct
form.resubmit

document.view
document.upload

reference.view
```

Sensitive permissions are assigned separately.

---

# 140. REVIEWER Baseline Bundle

Typical:

```text
family.view

person.view

membership.view

residence.view

assessment.view
assessment.review
assessment.verify

form.view
form.review
form.return
form.verify
form.view_history
form.view_source

document.view
document.verify

change_request.view
change_request.review
change_request.return
change_request.view_history

workflow_history.view
```

High-risk approval is not baseline.

---

# 141. SOCIAL_WORKER Baseline Bundle

Typical:

```text
family.view

person.view

membership.view

residence.view

assessment.view
assessment.create
assessment.update

need.view
need.create
need.update
need.verify
need.activate
need.mark_partially_met
need.mark_met
need.close

assistance.view
assistance.create
assistance.update

person_note.view
person_note.create

case_note.view
case_note.create

document.view
document.upload
```

Confidential and health permissions are separately controlled.

---

# 142. REPORTS_VIEWER Baseline Bundle

Typical:

```text
report.dashboard

report.family

report.demographics

report.needs

report.assistance

export.basic
```

No sensitive person-level data by default.

---

# 143. ADMINISTRATOR Baseline Bundle

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

change_request.*

user_person_link.*

user.*

reference.*

audit.view

workflow_history.view
```

But:

```text
role.*
permission.*
system_settings.*
audit.view_sensitive
export.sensitive_data
```

should remain explicitly controlled.

---

# 144. SUPER_ADMIN Bypass

Spatie/Laravel may use:

```php
Gate::before(...)
```

for `SUPER_ADMIN`.

If used:

```text
Keep accounts minimal

Audit critical actions

Use stronger authentication

Do not use Super Admin for normal daily work
```

---

# 145. No Wildcard Assumption

Do not rely on:

```text
family.*
```

unless wildcard permission support is deliberately configured.

Seed explicit permissions.

---

# 146. User Deactivation

When:

```text
users.is_active = false
```

the User must be denied access.

Historical attribution remains.

Do not delete the User merely to remove access.

---

# 147. Family User Account vs Link Suspension

Two different actions:

```text
User Account Deactivation
```

blocks all access.

```text
User-Person Link Suspension
```

blocks access through that identity relationship.

Both should remain available conceptually.

---

# 148. Session Security

Recommended:

```text
Secure password hashing

CSRF protection

Secure cookies

Session invalidation

Login throttling

Account deactivation enforcement

Password reset protection
```

---

# 149. Privileged Account Security

Recommended for:

```text
SUPER_ADMIN
ADMINISTRATOR
```

and potentially:

```text
REVIEWER
```

to support:

```text
2FA
```

before production if operationally feasible.

---

# 150. Family User Authentication Security

Because Family Portal exposes personal data, authentication must include appropriate:

```text
Identity verification

Secure credential setup

Rate limiting

OTP protection where used

Session protection

Account recovery controls
```

---

# 151. Account Recovery

Family User recovery must not rely solely on publicly knowable Family information.

Examples of unsafe recovery factors alone:

```text
Family Code

Head Name

Family Size
```

Recovery must use an approved secure process.

---

# 152. Enumeration Protection

Authentication and Family User activation endpoints should avoid exposing whether a particular:

```text
National ID

Mobile

Person

Family
```

exists unnecessarily.

Example generic response:

```text
If the provided information is eligible, the next verification step will be sent.
```

where appropriate.

---

# 153. Rate Limiting

Apply throttling to:

```text
Login

OTP

Password Reset

Identity Verification

Family User Activation

Change Request Submission where abuse is possible
```

---

# 154. Family Code Security

Family Code is an identifier, not a secret.

Possession of:

```text
FAM-000510
```

must never grant Family access.

---

# 155. Person Code Security

Likewise:

```text
PER-001825
```

is an identifier, not authentication.

---

# 156. Direct Object Reference Protection

Every resource must resist IDOR attacks.

Example:

```text
/family-portal/change-requests/101
```

changing:

```text
101
```

to:

```text
102
```

must not expose another Family's request.

---

# 157. Sensitive Download Audit

Recommended audit for:

```text
Identity document downloads

Medical document downloads

Sensitive exports
```

Record:

```text
User
Document
Timestamp
Action
```

---

# 158. Change Request Application Audit

Always audit:

```text
Request Code

Request Type

Family

Affected Person

Approver

Applier

Canonical entities changed

Timestamp
```

without unnecessarily duplicating sensitive values.

---

# 159. Permission Change Audit

Changes to:

```text
Roles

Permissions

User Roles

User-Person Links

Family User Activation

Family User Suspension
```

must be audited.

---

# 160. Permission Cache

When using Spatie Permission:

```text
Permission cache must be invalidated correctly after permission changes.
```

Deployment and seed procedures must account for this.

---

# 161. Seeder Strategy

Roles and permissions should be defined in version-controlled seed/config definitions.

Seeder behavior should be:

```text
Repeatable

Predictable

Safe
```

Do not silently remove existing assignments without an explicit migration/administrative decision.

---

# 162. Family User Role Seeder

Create:

```text
FAMILY_USER
```

with only the approved Family Portal permissions.

Do not copy permissions from a Staff role.

---

# 163. Authorization Tests

Every sensitive feature requires authorization tests.

Test:

```text
Allowed User succeeds

Unauthorized Role fails

Wrong Scope fails

Wrong Family fails

Wrong Workflow State fails

Restricted Field remains hidden

Direct URL access fails

API access fails
```

---

# 164. FAMILY_USER Tests

Must test:

```text
Can access Family Portal when active.

Cannot access Staff Portal.

Can view authorized Family.

Cannot view another Family.

Can view permitted members.

Cannot view hidden sensitive member fields.

Can create allowed Change Request.

Cannot create disallowed request type.

Can edit own Draft.

Cannot edit Under Review request.

Can respond to Returned request.

Cannot approve own request.

Cannot apply own request.

Cannot verify own document.

Cannot verify own User-Person link.

Cannot view internal audit.

Cannot view confidential notes.
```

---

# 165. Head Change Authorization Tests

Test:

```text
Old Head loses Family-wide eligibility.

New Head does not automatically receive account.

New Head with verified active account may gain access.

Unrelated User cannot gain access by knowing Family Code.
```

---

# 166. Person Transfer Authorization Tests

Test:

```text
Old Family scope removed after transfer.

Target Family scope requires current eligibility.

Historical requests remain historically attributed.

No cross-Family leakage occurs.
```

---

# 167. Death Authorization Tests

Test:

```text
Deceased linked Person no longer retains active Family Portal eligibility.

Historical User/Person relationship remains.

Household Head death triggers access review.
```

---

# 168. Change Request Authorization Tests

Test:

```text
Family User A cannot view Family User B request.

Family User cannot modify SUBMITTED request.

Reviewer cannot review outside scope.

Unauthorized Reviewer cannot see sensitive payload.

Approver cannot approve invalid state.

Applier cannot apply REJECTED request.

APPLIED request cannot be reapplied.
```

---

# 169. Sensitive Field Tests

Test:

```text
National ID FULL
National ID MASKED
National ID HIDDEN

Health record allowed
Health record denied

Confidential note allowed
Confidential note denied
```

Tests should verify API payloads, not only UI visibility.

---

# 170. Export Tests

Test:

```text
Basic export excludes sensitive fields.

Personal-data export requires permission.

Sensitive export requires permission.

Scope applies to export.

Export is audited.
```

---

# 171. Authorization Invariants

```text
AUTH-INV-001
All access is denied unless explicitly authorized.

AUTH-INV-002
Role alone does not determine record access.

AUTH-INV-003
Data scope is enforced server-side.

AUTH-INV-004
Field-level restrictions apply independently of record access.

AUTH-INV-005
Workflow permission does not bypass invalid workflow state.

AUTH-INV-006
Family User cannot directly modify canonical registry data.

AUTH-INV-007
Family User cannot review, approve, reject, or apply their own Change Request.

AUTH-INV-008
Family User Family scope derives from verified relationships.

AUTH-INV-009
Family Code is not an authorization credential.

AUTH-INV-010
Person Code is not an authorization credential.

AUTH-INV-011
Family User cannot access another Family by modifying resource IDs.

AUTH-INV-012
Family User does not receive unrestricted National IDs.

AUTH-INV-013
Family User does not receive confidential internal notes.

AUTH-INV-014
Family User does not receive internal audit logs.

AUTH-INV-015
Family-uploaded documents are not self-verified.

AUTH-INV-016
Sensitive exports require dedicated permission.

AUTH-INV-017
Permission changes are audited.

AUTH-INV-018
User deactivation preserves historical attribution.

AUTH-INV-019
User and Person remain separate identities.

AUTH-INV-020
User-Person verification cannot be self-approved by Family User.

AUTH-INV-021
Household Head changes trigger Family Portal authorization reevaluation.

AUTH-INV-022
Person transfers trigger scope reevaluation.

AUTH-INV-023
Death triggers relevant Portal access reevaluation.

AUTH-INV-024
Authorization applies equally to UI and API.

AUTH-INV-025
Navigation visibility is not security.

AUTH-INV-026
Sensitive fields must not be delivered to unauthorized clients.

AUTH-INV-027
Document authorization is checked before file delivery.

AUTH-INV-028
Change Request type authorization is explicit.

AUTH-INV-029
Family Portal search is limited to authorized Family scope.

AUTH-INV-030
Canonical domain actions reauthorize critical operations.
```

---

# 172. Approved Authorization Decisions

### AUTH-ADR-001

Famboook uses RBAC plus Data Scope.

### AUTH-ADR-002

Field-level restrictions are required for sensitive information.

### AUTH-ADR-003

Workflow state participates in authorization.

### AUTH-ADR-004

Spatie Laravel Permission is the recommended RBAC package.

### AUTH-ADR-005

Laravel Policies are the primary record-level authorization mechanism.

### AUTH-ADR-006

Query scope is enforced server-side.

### AUTH-ADR-007

Sensitive export uses dedicated permissions.

### AUTH-ADR-008

Audit access is separate from normal record access.

### AUTH-ADR-009

National ID has separate view/full/update permissions.

### AUTH-ADR-010

Confidential notes have separate permissions.

### AUTH-ADR-011

User accounts are deactivated rather than deleted for access removal.

### AUTH-ADR-012

Permission changes are auditable.

### AUTH-ADR-013

Staff workflow actions use explicit permissions.

### AUTH-ADR-014

Maker-checker separation is supported.

### AUTH-ADR-015

Super Admin access is exceptional rather than routine.

### AUTH-ADR-016

`FAMILY_USER` is a dedicated non-staff role.

### AUTH-ADR-017

Family User authorization uses User-Person links.

### AUTH-ADR-018

Family-wide authorization derives from current Family Membership.

### AUTH-ADR-019

Family User canonical modifications use Change Requests.

### AUTH-ADR-020

Family User does not receive direct canonical CRUD.

### AUTH-ADR-021

Family Portal introduces SELF and FAMILY scopes.

### AUTH-ADR-022

Family User field access differs between SELF and other Family members.

### AUTH-ADR-023

Family User cannot verify their own User-Person link.

### AUTH-ADR-024

Family User cannot verify their own uploaded supporting documents.

### AUTH-ADR-025

Change Request types may have dedicated create permissions.

### AUTH-ADR-026

Family User cannot access internal audit logs.

### AUTH-ADR-027

Family User cannot access confidential internal notes.

### AUTH-ADR-028

Family User does not receive global Person/Family search.

### AUTH-ADR-029

Family Code and Person Code are identifiers, not credentials.

### AUTH-ADR-030

Family Portal file access uses server-side document authorization.

### AUTH-ADR-031

Household Head, membership, and death changes trigger Portal scope reevaluation.

### AUTH-ADR-032

API and UI use the same authorization rules.

### AUTH-ADR-033

Critical Domain Actions perform authorization in addition to controller/UI checks.

---

# 173. Pending Authorization Decisions

### PAUTH-001 — Reviewer Scope

Choose:

```text
ALL

ASSIGNED

Hybrid
```

---

### PAUTH-002 — Social Worker Scope

Choose:

```text
ALL

ASSIGNED

CASELOAD
```

---

### PAUTH-003 — Data Entry National ID Access

Choose:

```text
FULL

MASKED except while editing

FULL only for assigned records
```

---

### PAUTH-004 — Final Approval Authority

Choose:

```text
ADMINISTRATOR
```

or introduce:

```text
APPROVER
```

---

### PAUTH-005 — Sensitive Export Roles

Define exactly which roles may receive:

```text
export.sensitive_data
```

---

### PAUTH-006 — Duplicate Merge Permission

If merge is implemented, define:

```text
person.merge
```

and authorized roles.

Recommendation:

```text
Defer merge tool from initial V1.
```

---

### PAUTH-007 — Confidential Note Roles

Finalize whether Reviewer receives:

```text
confidential_note.view
```

---

### PAUTH-008 — Privileged 2FA

Determine whether 2FA is mandatory for:

```text
SUPER_ADMIN

ADMINISTRATOR

REVIEWER
```

before production.

---

### PAUTH-009 — Regional Scope

Determine whether V1 requires:

```text
REGION
BRANCH
```

scope.

Do not introduce without operational need.

---

### PAUTH-010 — Family User Eligibility

Finalize whether V1 Family-wide access is limited to:

```text
Current Household Head
```

Recommendation:

```text
YES for initial V1.
```

---

### PAUTH-011 — Multiple Family Users

Determine whether a Family may have:

```text
One Family User

Multiple authorized Family Users
```

Recommendation:

Start with:

```text
Current Household Head
```

while keeping architecture extensible.

---

### PAUTH-012 — Authorized Representative

Determine future support for:

```text
GUARDIAN

AUTHORIZED_REPRESENTATIVE
```

Do not enable until business rules are defined.

---

### PAUTH-013 — Full Self National ID

Determine whether Family User may view their own:

```text
FULL National ID
```

Recommendation:

```text
MASKED by default.
```

---

### PAUTH-014 — Other Adult Member Birth Date

Determine whether Household Head may see full Birth Date of adult Family members.

---

### PAUTH-015 — Dependent Minor Data

Define enhanced Household Head visibility for dependent minors.

---

### PAUTH-016 — Family Health Visibility

Determine whether Family User may access:

```text
SELF health information

Minor dependent health information

Household health indicators
```

Default:

```text
Hidden.
```

---

### PAUTH-017 — Needs Visibility

Determine which:

```text
Needs
```

are visible in Family Portal.

---

### PAUTH-018 — Assistance Visibility

Determine which:

```text
Assistance Records
```

are visible in Family Portal.

---

### PAUTH-019 — Canonical Documents

Determine which verified Person/Family documents Family User may download.

---

### PAUTH-020 — Household Head Change Request

Determine whether Family User receives:

```text
change_request.head_change.create
```

in initial V1.

---

### PAUTH-021 — Membership Change Request

Determine whether Family User receives:

```text
change_request.membership_change.create
```

in initial V1.

---

### PAUTH-022 — Change Request Reviewer vs Approver

Define which risk levels require:

```text
Reviewer ≠ Approver
```

---

### PAUTH-023 — Approver vs Applier

Define whether high-risk requests require:

```text
Approver ≠ Applier
```

---

### PAUTH-024 — Sensitive Request Payload

Finalize field-level access rules for sensitive JSON payloads.

---

### PAUTH-025 — Sensitive Read Audit

Determine whether merely viewing:

```text
National ID

Health

Medical Documents
```

must create an access audit event.

---

### PAUTH-026 — Family User Account Creation

Finalize whether accounts are created through:

```text
Invitation

Staff-assisted activation

Verified self-registration

Combination
```

---

### PAUTH-027 — Authentication Identifier

Finalize:

```text
Mobile

Email

Username

Combination
```

for Family Users.

---

# 174. Recommended V1 Family User Policy

Until pending decisions are resolved, recommended secure baseline:

```text
FAMILY_USER
=
Current verified Household Head only
```

Requirements:

```text
Active User

FAMILY_USER role

Active User-Person Link

Active Person

Active Family Membership

Current Household Head
```

---

# 175. Recommended Family User Access

Allow:

```text
Family summary

Basic member information

Current residence

Own request history

Submit approved Change Request types

Upload supporting evidence

Notifications
```

Restrict:

```text
Full National IDs of other members

Health details

Disability details

Confidential notes

Internal workflow metadata

Audit logs

Staff-only documents

Global search

Generic exports
```

---

# 176. Recommended Change Request Separation

Low/medium-risk:

```text
Family User
→ Reviewer
→ Administrator Approval/Application
```

High-risk:

```text
Family User
→ Reviewer
→ Independent Approver
→ Controlled Application
```

The architecture supports both without redesign.

---

# 177. Permission Review Checklist

Before granting a permission ask:

```text
Does this role need the action?

On which records?

On which Family?

On which Person?

Which fields?

Which workflow states?

Does it expose Restricted data?

Can it modify canonical data?

Does it require audit?

Does it require separation of duties?

Can the same result be achieved with less access?
```

---

# 178. Family Portal Security Checklist

Before production:

```text
User-Person link verified

Family scope server-side

Head eligibility enforced

IDOR tests passing

Sensitive fields masked/hidden

No internal notes exposed

No audit data exposed

No direct canonical CRUD

Change Requests authorized by type

Documents private

Downloads authorized

OTP/login rate limiting

Account recovery secured

Sessions secured

User suspension enforced

Head changes revoke/recalculate access

Person transfer recalculates access

Death recalculates access
```

---

# 179. Implementation Order

Recommended authorization implementation:

```text
1. Install Spatie Permission

2. Create Staff Roles

3. Create FAMILY_USER

4. Seed Permissions

5. Implement Policies

6. Implement Staff Data Scopes

7. Implement User-Person Link authorization

8. Implement SELF/FAMILY scope resolver

9. Implement Family Portal Policies

10. Implement Field-Level Transformers

11. Implement Change Request permissions

12. Implement Request-Type permissions

13. Implement Workflow-state authorization

14. Implement Document authorization

15. Implement Sensitive field masking

16. Implement Export authorization

17. Implement Audit permissions

18. Implement authorization tests

19. Implement IDOR/security tests
```

---

# 180. Authorization Definition of Done

A feature is not authorization-complete until it has:

```text
Permission

Policy

Data Scope

Field Rules

Workflow-State Rules

Domain Authorization

API/UI Enforcement

Negative Tests

Sensitive Data Review

Audit Requirements
```

---

# 181. Documentation Synchronization

The authorization model now aligns with:

```text
01-PRODUCT.md v1.1

02-DATA-DICTIONARY.md v1.1

03-BUSINESS-RULES.md v1.1

04-DATABASE.md v1.1

05-WORKFLOWS.md v1.1
```

`07-ROADMAP.md` must include implementation phases for:

```text
RBAC

Staff Authorization

Family User Authentication

User-Person Linking

Family Scope Resolver

Family Portal

Change Request Engine

Sensitive Field Masking

Document Security

Authorization Testing

IDOR Testing

Workflow Security

Pilot Family User Testing
```

---

# 182. Document Status

```text
Project: Famboook
Document: Permissions & Access Control
Version: 1.1
Status: APPROVED
Date: 2026-09-22
```

---

# 183. Change Log

| Version | Date | Status | Description |
|---|---|---|---|
| 1.0 | 2026-09-22 | Approved | Initial Staff RBAC, scopes, field-level restrictions, workflow authorization, sensitive data and export controls |
| 1.1 | 2026-09-22 | Approved | Added FAMILY_USER, SELF/FAMILY scopes, User-Person Link permissions, Family Portal authorization, Change Request permissions, request-type permissions, member field restrictions, document authorization, IDOR protection, Family User security rules, and Portal authorization tests |

---

# 184. Next Step

Documentation synchronization is now:

```text
00-PROJECT-CONTEXT.md          APPROVED

01-PRODUCT.md                  UPDATED — v1.1

02-DATA-DICTIONARY.md          UPDATED — v1.1

03-BUSINESS-RULES.md           UPDATED — v1.1

04-DATABASE.md                 UPDATED — v1.1

05-WORKFLOWS.md                UPDATED — v1.1

06-PERMISSIONS.md              UPDATED — v1.1

        ↓

07-ROADMAP.md                  NEXT
```

The Roadmap must convert the approved architecture into implementation phases without weakening:

```text
Person identity independence

Historical Family Membership

Family User separation

Change Request architecture

Workflow history

Sensitive data protection

Server-side authorization

Auditability

Testing
```