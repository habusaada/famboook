# Famboook
## Product Definition

**Document:** `01-PRODUCT.md`  
**Version:** 1.1  
**Status:** Approved  
**Last Updated:** 2026-09-22  
**Project:** Famboook — Family Registry & Case Management System

---

# 1. Product Overview

Famboook is a Family Registry & Case Management System designed to create and maintain a structured, verified, historical, and secure digital registry of families and their members.

The system transforms fragmented family information, paper forms, assessments, updates, documents, needs, assistance records, and follow-up activities into a unified digital platform.

Famboook is not merely a paper-form data-entry system.

It is designed as a long-term family information platform supporting:

```text
Registration
+
Verification
+
Family Registry
+
Person Registry
+
Family Self-Service
+
Assessments
+
Needs
+
Assistance
+
Case Management
+
Reporting
+
Historical Tracking
```

---

# 2. Product Vision

The vision of Famboook is to establish a reliable digital source of truth for family information.

The platform should allow authorized users to answer questions such as:

```text
Who are the registered families?

Who are the registered persons?

Which Person belongs to which Family?

Who is the current household head?

Where is the Family currently residing?

What is the Family's residence/displacement history?

What health or disability conditions exist?

What are the education and employment conditions?

What needs have been identified?

What assistance has been received?

What assessments have been performed?

What documents are available?

What changes were requested by the Family?

Who verified or approved a change?

How has the Family record changed over time?
```

---

# 3. Product Goals

Famboook V1 aims to:

1. Establish a structured Family Registry.
2. Establish an independent Person Registry.
3. Digitize existing paper-based family records.
4. Support unlimited Family members.
5. Preserve historical changes.
6. Prevent unnecessary duplicate Person records.
7. Support controlled data verification.
8. Support household membership history.
9. Track residence and displacement.
10. Track health and disability information.
11. Track education and employment information.
12. Track assessments.
13. Track needs and assistance.
14. Store supporting documents securely.
15. Support case-management notes.
16. Provide controlled reporting.
17. Provide role-based access control.
18. Maintain complete auditability.
19. Allow authenticated Families to review permitted information.
20. Allow Families to submit controlled update requests.
21. Prevent Family-submitted changes from silently modifying the official registry.
22. Create a foundation that can scale beyond the initial Family dataset.

---

# 4. Product Philosophy

The system follows several fundamental principles.

## 4.1 Person Is an Independent Entity

A Person is not simply:

```text
Family Member Row #4
```

A Person has a persistent identity in the system.

Example:

```text
PER-001825
```

The same Person may change:

```text
Family
Residence
Marital Status
Household Role
Employment
Education
Health Information
```

without becoming a new Person.

---

## 4.2 Family Is an Independent Entity

A Family has its own persistent identity.

Example:

```text
FAM-000510
```

The Family remains identifiable even when:

```text
Household Head changes
Members leave
Members join
Residence changes
Displacement occurs
```

---

## 4.3 Paper Forms Are Sources, Not the Database Model

Paper forms are treated as:

```text
Source Documents
```

They do not define the permanent structure of the database.

Example:

If a paper form contains space for:

```text
10 children
```

the digital system must not be limited to 10 children.

---

## 4.4 Registry Data Is Different From Assessment Data

Permanent registry information and point-in-time observations must remain conceptually separate.

Example:

```text
Person Name
National ID
Birth Date
```

are identity/registry data.

While:

```text
Current Need
Current Employment Situation
Current Displacement Situation
Pregnancy
Breastfeeding
```

may change over time and may be associated with an assessment or update.

---

## 4.5 Family-Submitted Data Is Not Automatically Official Data

Information submitted through the Family Portal must not automatically overwrite the verified Family Registry.

Instead:

```text
Family Submission
      ↓
Change Request
      ↓
Validation
      ↓
Review
      ↓
Approval / Rejection
      ↓
Apply to Official Registry
```

This distinction is fundamental to Famboook.

---

# 5. Product Actors

Famboook serves two major actor groups:

```text
Internal Operational Users
+
Authenticated Family Users
```

---

# 6. Internal Operational Users

Internal users operate the Staff Portal.

Approved V1 roles include:

```text
SUPER_ADMIN
ADMINISTRATOR
DATA_ENTRY
REVIEWER
SOCIAL_WORKER
REPORTS_VIEWER
```

Detailed permissions are defined in:

```text
06-PERMISSIONS.md
```

---

# 7. Family User

Famboook introduces an authenticated external user type:

```text
FAMILY_USER
```

A Family User represents a verified Person authorized to access permitted information related to a Family.

In the initial V1 implementation, the Family User will normally be:

```text
The Household Head
```

However, the architecture must not permanently assume:

```text
Family User = Household Head
```

because future requirements may allow:

```text
Authorized Family Representative
Guardian
Authorized Adult Member
```

Therefore:

```text
FAMILY_USER
```

is preferred as the authorization concept.

---

# 8. Family User Identity

A Family User account must be linked to a known Person.

Conceptually:

```text
User
 ↓
Person
 ↓
Active Family Membership
 ↓
Family
```

A Family User must not gain access to a Family merely by knowing:

```text
Family Code
National ID
Phone Number
```

Account linking must follow an approved identity-verification and activation process.

---

# 9. Two Product Experiences

Famboook V1 consists conceptually of two application experiences.

```text
FAMBOOOK
│
├── Staff Portal
│
│   ├── Administration
│   ├── Data Entry
│   ├── Verification
│   ├── Family Registry
│   ├── Person Registry
│   ├── Assessments
│   ├── Needs & Assistance
│   ├── Case Management
│   ├── Reports
│   └── Audit
│
└── Family Portal
    │
    ├── My Family
    ├── Family Members
    ├── My Profile
    ├── Update Requests
    ├── Documents
    ├── Needs / Assistance Summary
    └── Notifications
```

The portals may initially share the same Laravel backend.

The exact frontend implementation may evolve independently.

---

# 10. Staff Portal

The Staff Portal is the operational administration environment.

It supports:

```text
Family Registration
Person Registration
Data Entry
Verification
Approval
Assessments
Needs
Assistance
Documents
Case Management
Reporting
Administration
Audit
```

Filament is the recommended V1 implementation platform for the Staff Portal.

---

# 11. Family Portal

The Family Portal provides controlled self-service access for authenticated Family Users.

Its purpose is not to provide unrestricted editing.

It provides:

```text
Read
Review
Request
Upload
Track
```

rather than unrestricted:

```text
Edit Official Registry
```

---

# 12. Family Portal V1 Capabilities

Subject to permissions and verification, a Family User may be able to:

```text
View permitted Family information

View permitted Family members

View their own profile

Review selected household information

Submit an update request

Request addition of a Family member

Request correction of Person information

Report a death

Report marriage-related changes

Report household membership changes

Request residence/address update

Update contact information through an approved process

Upload supporting documents

View submitted requests

Track request status

View selected Needs information

View selected Assistance information

Receive system notifications
```

Not every capability must allow direct registry modification.

---

# 13. Family Portal Restrictions

A Family User must not automatically be able to:

```text
Directly modify verified National ID

Directly delete a Person

Directly change Household Head

Directly transfer a Person between Families

Directly mark a Person as deceased in the official registry

Directly change verified relationships

Directly approve a submitted change

Directly verify documents

Directly close a Need

Directly create an Assistance record

View confidential Case Notes

View internal audit logs

View staff comments

View other Families

Export unrestricted Family Registry data
```

These actions remain controlled by internal workflows.

---

# 14. Family Self-Service Is Not Public Self-Registration

Famboook distinguishes:

```text
Public Self-Registration
```

from:

```text
Authenticated Family Self-Service
```

Public Self-Registration means:

```text
Unknown person
      ↓
Creates a new Family/Person registry record
```

This remains outside V1.

Family Self-Service means:

```text
Known verified Person
      ↓
Authenticated account
      ↓
Linked Family
      ↓
Views permitted data
      ↓
Submits controlled change requests
```

Family Self-Service is part of V1.

---

# 15. Official Registry vs Submitted Changes

Famboook must maintain a strict distinction between:

```text
Official Registry
```

and:

```text
Requested Changes
```

Example:

Official registry:

```text
Residence:
Khan Younis
```

Family User submits:

```text
New Residence:
Al-Mawasi
```

The system must not immediately replace:

```text
Khan Younis
```

with:

```text
Al-Mawasi
```

Instead:

```text
Official Value
Khan Younis

Pending Request
Al-Mawasi
```

until the request is reviewed and applied.

---

# 16. Change Request Concept

A Family-submitted modification is represented as:

```text
Change Request
```

A Change Request describes:

```text
Who submitted the request?

Which Family does it concern?

Which Person does it concern, if applicable?

What type of change is requested?

What data was submitted?

What supporting documents were provided?

Why was the change requested?

What is its current status?

Who reviewed it?

What was the review decision?

Was the approved change applied?
```

---

# 17. Change Request Types

Initial V1 types may include:

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

Final codes must be standardized in the Data Dictionary and Database Architecture.

---

# 18. Change Request Workflow

Recommended V1 workflow:

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

# 19. APPROVED vs APPLIED

These statuses are intentionally separate.

```text
APPROVED
```

means:

```text
The requested change has been accepted.
```

While:

```text
APPLIED
```

means:

```text
The approved change has been successfully written to the official registry.
```

This distinction allows safe transactional application of complex changes.

---

# 20. Example — Contact Update

Family User submits:

```text
Old Mobile:
0590000000

Requested Mobile:
0560000000
```

The request becomes:

```text
SUBMITTED
```

After the approved review process:

```text
APPROVED
↓
Update Person/Family Contact
↓
APPLIED
```

The action is audited.

---

# 21. Example — Add Newborn

Family User selects:

```text
Add Family Member
```

and provides:

```text
Name
Gender
Birth Date
Relationship
National ID if available
Birth Certificate if available
```

The system creates:

```text
Change Request
```

not an official Person immediately.

Staff review:

```text
Validate
↓
Duplicate Check
↓
Review Document
↓
Approve
↓
Create Person
+
Create Family Membership
↓
APPLIED
```

The resulting Person receives a permanent:

```text
PER-XXXXXX
```

identifier.

---

# 22. Example — Death Report

A Family User may report:

```text
Person X has died
```

but the Family Portal must not immediately perform:

```text
persons.life_status = DECEASED
```

Instead:

```text
DEATH_REPORT
      ↓
Supporting Information
      ↓
Review
      ↓
Approval
      ↓
Update life_status
      ↓
Review Household Head if required
      ↓
APPLIED
```

The Person remains in the registry.

---

# 23. Example — Household Head Change

A Family User may request a Household Head change.

They cannot directly perform it.

Flow:

```text
Request Household Head Change
      ↓
Select Proposed Member
      ↓
Provide Reason
      ↓
Submit
      ↓
Review
      ↓
Approve
      ↓
Execute Controlled Head Change
      ↓
Audit
      ↓
APPLIED
```

---

# 24. Example — Person Leaves Household

A Family User may report:

```text
Member married / moved / formed another household
```

This does not delete the Person.

After approval:

```text
Existing Person
      ↓
Close old Family Membership
      ↓
Create/associate new Family Membership where applicable
```

The same:

```text
PER-XXXXXX
```

is preserved.

---

# 25. Low-Risk vs High-Risk Changes

Not all update requests have the same sensitivity.

Conceptually:

```text
LOW RISK
MEDIUM RISK
HIGH RISK
```

Examples of potentially lower-risk changes:

```text
Alternate Mobile
Basic Contact Information
```

Examples of higher-risk changes:

```text
National ID
Person Identity
Birth Date
Household Head
Family Membership
Death
Marriage
Relationship
```

V1 may still require review for all Family-submitted changes.

Future versions may allow approved low-risk changes to use simplified workflows.

No automatic application should be introduced without an explicit approved rule.

---

# 26. Family User Documents

A Family User may upload supporting evidence for a Change Request.

Examples:

```text
Birth Certificate
Identity Document
Death Certificate
Marriage Document
Medical Report
Other Supporting Document
```

Uploaded documents remain:

```text
UNVERIFIED
```

until reviewed by an authorized staff user.

Upload does not imply document verification.

---

# 27. Family User Notifications

The Family Portal should support notification of important request events.

Examples:

```text
Request Submitted

Request Returned for Clarification

Request Resubmitted

Request Approved

Request Rejected

Request Applied
```

V1 may initially provide:

```text
In-App Notifications
```

External channels may be introduced later.

---

# 28. Core Product Flow

The complete Famboook information flow becomes:

```text
SOURCE DATA
│
├── Paper Form
├── Staff Entry
├── Assessment
└── Family Change Request
        ↓
VALIDATION
        ↓
REVIEW / VERIFICATION
        ↓
OFFICIAL REGISTRY
        ↓
ASSESSMENTS & UPDATES
        ↓
NEEDS & ASSISTANCE
        ↓
REPORTING
```

---

# 29. Staff Registration Flow

```text
Paper / Approved Source
      ↓
Data Entry
      ↓
Draft
      ↓
Submit
      ↓
Review
      ↓
Correction if required
      ↓
Verification
      ↓
Approval
      ↓
Official Registry
```

---

# 30. Family Update Flow

```text
Authenticated Family User
      ↓
View Permitted Registry Data
      ↓
Submit Change Request
      ↓
Attach Evidence if applicable
      ↓
Review
      ↓
Clarification if required
      ↓
Approve / Reject
      ↓
Apply Approved Change
      ↓
Official Registry Updated
      ↓
Audit + History
```

---

# 31. Core Modules

Famboook V1 consists of the following core modules:

```text
01 Dashboard
02 Family Registry
03 Person Registry
04 Data Entry
05 Verification
06 Assessments
07 Residence & Displacement
08 Health & Disability
09 Education
10 Employment
11 Needs
12 Assistance
13 Documents
14 Case Notes
15 Search
16 Duplicate Review
17 Reports
18 Users & Permissions
19 Audit Log
20 Family Portal
21 Change Requests
22 Notifications
```

---

# 32. Dashboard

The internal Dashboard provides operational indicators.

Potential indicators include:

```text
Total Families
Total Persons
Pending Data Entry
Under Review
Returned for Correction
Verified
Approved

Pending Family Change Requests
Requests Under Review
Requests Returned
Approved Requests Awaiting Application

Displaced Families
Families with Active Needs
Persons with Disabilities
Persons with Chronic Conditions
Recent Assistance
```

Dashboard visibility must follow permissions and scope.

---

# 33. Family Registry

The Family Registry provides:

```text
Family Code
Household Head
Members
Residence
Assessments
Needs
Assistance
Documents
Notes
History
Change Requests
```

Family Code format:

```text
FAM-000001
```

---

# 34. Person Registry

The Person Registry provides:

```text
Person Code
Identity
National ID
Demographics
Family Membership
Relationships
Health
Disability
Education
Employment
Documents
Notes
History
```

Person Code format:

```text
PER-000001
```

---

# 35. Family Membership

Family membership is not permanently stored as a fixed attribute of Person identity.

Conceptually:

```text
Person
   ↓
Family Membership
   ↓
Family
```

This supports historical movement between Families.

---

# 36. Household Head

A Family should normally have one active Household Head.

The Household Head is:

```text
A Person
+
An Active Family Membership
+
is_household_head = true
```

Household-head changes must preserve history.

---

# 37. Data Entry

Data Entry converts approved sources into structured records.

Recommended registration flow:

```text
1. Family
2. Household Head
3. Members
4. Residence
5. Health & Disability
6. Education & Employment
7. Needs
8. Documents & Notes
9. Review
10. Submit
```

Drafts may remain incomplete.

Submission requires stronger validation.

---

# 38. Verification

Verification is a controlled workflow.

Baseline:

```text
DRAFT
↓
DATA_ENTRY_COMPLETED
↓
UNDER_REVIEW
├── RETURNED_FOR_CORRECTION
│       ↓
│    CORRECTED
│       ↓
└── UNDER_REVIEW
        ↓
     VERIFIED
        ↓
     APPROVED
```

Detailed rules are defined in:

```text
05-WORKFLOWS.md
```

---

# 39. Assessments

Assessments represent point-in-time information collection.

Examples:

```text
Initial Registration
Verification
Follow-Up
Needs Assessment
Emergency Update
```

A Family may have multiple Assessments over time.

---

# 40. Residence & Displacement

The system supports:

```text
Current Residence
Residence History
Displacement Status
Displacement Location
Displacement Date
Housing Type
Tenure
Housing Condition
```

Changing residence must not destroy previous residence history.

---

# 41. Health

Health information belongs to Persons.

The system supports:

```text
Health Profile
Health Conditions
Chronic Conditions
Treatment Requirements
Medication Requirements
Follow-Up Requirements
```

Health information is Restricted data.

---

# 42. Disability

The system supports repeatable disability records.

Possible information includes:

```text
Disability Type
Severity
Assistance Requirement
Assistive Device
Notes
```

Disability information is Restricted data.

---

# 43. Education

The system supports education information and history.

Examples:

```text
Enrollment
Education Level
Grade
Institution
Specialization
Education Status
```

---

# 44. Employment

The system supports employment information and history.

Examples:

```text
Employment Status
Occupation
Employer
Sector
Income Indicator
Income where approved
```

---

# 45. Needs

Needs are stored independently from Assistance.

Examples:

```text
Food
Shelter
Health
Medication
Education
WASH
Protection
Assistive Device
Clothing
Cash
Livelihood
Other
```

Need lifecycle is defined in:

```text
05-WORKFLOWS.md
```

---

# 46. Assistance

Assistance represents an actual assistance event.

Examples:

```text
Food Package
Cash Assistance
Medical Assistance
Medication
Education Support
Shelter Support
Assistive Device
Other
```

Assistance may be linked to a Need.

Assistance does not automatically close a Need.

---

# 47. Documents

Documents may belong to:

```text
Family
Person
Change Request
```

depending on context.

Documents must use private storage.

Document availability does not imply verification.

---

# 48. Case Notes

Authorized operational users may add:

```text
Person Notes
Case Notes
Confidential Notes
```

Notes are append-oriented.

Confidential notes require additional authorization.

Family Users must not have access to internal confidential notes.

---

# 49. Search

Search should support:

```text
Family Code
Person Code
National ID
Full Name
Mobile
```

Search must respect:

```text
Permissions
Data Scope
Sensitive Data Restrictions
```

---

# 50. Duplicate Detection

Duplicate detection is required before creating or applying identity-related changes.

Categories:

```text
EXACT
PROBABLE
POSSIBLE
```

No automatic Person merge is permitted.

Human review is required.

---

# 51. Duplicate Detection in Family Requests

Family Change Requests may trigger duplicate detection.

Example:

```text
ADD_FAMILY_MEMBER
```

must search the existing Person Registry before creating a new Person.

Possible result:

```text
Existing Person Found
      ↓
Review
      ↓
Reuse Person
      ↓
Create Membership
```

instead of:

```text
Create Duplicate Person
```

---

# 52. Reports

Reports should use canonical registry data.

Potential reports include:

```text
Family Registry
Demographics
Residence
Displacement
Health
Disability
Education
Employment
Needs
Assistance
Registration Workflow
Change Requests
```

Reports must respect authorization.

---

# 53. Family Portal Data Visibility

The Family Portal must not simply expose the Staff Portal Family Profile.

A separate presentation model is required.

The Family User sees only approved fields.

Conceptually:

```text
Official Registry
      ↓
Family Portal Visibility Rules
      ↓
Family User
```

---

# 54. Family User Scope

A Family User is limited to:

```text
Their linked Person
+
Their authorized Family
+
Permitted Family members
```

A Family User must never browse:

```text
Other Families
Global Person Registry
Staff Queues
Internal Reports
Audit Logs
```

---

# 55. Family Portal Privacy Between Members

Membership in the same Family does not automatically mean every Family User may view every sensitive field of every Person.

Future multi-user Family access may require additional privacy rules.

V1 should therefore avoid assuming:

```text
Family membership
=
Unlimited access to all member data
```

---

# 56. User Account vs Person

A system User and a Person are different concepts.

```text
users
```

represents authentication identity.

```text
persons
```

represents registry identity.

A Family User account may be linked to a Person.

An internal Staff User does not necessarily require a Person record.

---

# 57. Account Activation

Family User accounts must not be automatically created merely because a Person exists.

Activation should require an approved process.

Conceptually:

```text
Person Identified
      ↓
Identity Verification
      ↓
Account Activation
      ↓
User ↔ Person Link
      ↓
Family Portal Access
```

The exact activation mechanism is defined during security/workflow design.

---

# 58. Account Access After Household Change

Family Portal access must not depend solely on a cached Family ID.

Because a Person may:

```text
Move
Marry
Change Household
Become Household Head
Stop Being Household Head
```

authorization must resolve the current approved relationship.

Historical access must not remain accidentally available after membership changes.

---

# 59. Family User Access After Household Head Change

If V1 grants Family Portal access primarily to Household Heads, changing the Household Head must trigger an account-access review.

Example:

```text
Old Head
      ↓
Head Role Ends
      ↓
Family Portal Authorization Review

New Head
      ↓
Identity Verification
      ↓
Account Activation / Authorization
```

The system must not silently leave former Household Heads with unintended Family-wide access.

---

# 60. Auditability

Critical actions must be auditable.

Examples:

```text
Family Created
Person Created
National ID Changed
Household Head Changed
Person Transferred
Residence Changed
Form Verified
Form Approved
Need Verified
Document Verified

Family User Activated
Family User Access Changed
Change Request Submitted
Change Request Returned
Change Request Approved
Change Request Rejected
Change Request Applied
```

---

# 61. Data Classification

Famboook handles different data sensitivity levels.

## Restricted

Examples:

```text
National ID
Health
Disability
Identity Documents
Confidential Notes
Sensitive Supporting Documents
```

## Internal

Examples:

```text
Mobile
Address
Family Relationships
Employment
Needs
Assistance
```

## Operational

Examples:

```text
Family Code
Person Code
Workflow Status
Dates
System Timestamps
```

Family Portal visibility is independently controlled and does not mean that all Internal data is automatically visible externally.

---

# 62. Security Requirements

Famboook must implement:

```text
Authentication
Authorization
Least Privilege
Deny by Default
Private File Storage
Audit Logging
Workflow History
Secure Sessions
Input Validation
Sensitive Data Controls
Controlled Exports
```

Family Portal authentication requires particular attention because it exposes registry information outside the Staff Portal.

---

# 63. Authorization Model

Authorization uses:

```text
RBAC
+
Data Scope
+
Field-Level Access
+
Workflow State
```

Family User access additionally uses:

```text
User-to-Person Link
+
Authorized Family Relationship
```

Detailed rules belong in:

```text
06-PERMISSIONS.md
```

---

# 64. User Roles

V1 roles:

```text
SUPER_ADMIN
ADMINISTRATOR
DATA_ENTRY
REVIEWER
SOCIAL_WORKER
REPORTS_VIEWER
FAMILY_USER
```

Roles are permission bundles.

Business logic should check permissions and Policies rather than relying only on role names.

---

# 65. Workflow History

Workflow-controlled entities should preserve state-transition history.

Examples:

```text
Form Submission
Assessment
Family Need
Change Request
```

Current status is stored on the entity.

Historical transitions are stored as workflow events.

---

# 66. Change Request History

A Change Request must preserve:

```text
Submission
Return
Clarification
Resubmission
Review
Approval/Rejection
Application
```

The history must not be silently overwritten.

---

# 67. Applying Approved Changes

Applying an approved Change Request must use the same domain rules as staff operations.

Example:

An approved Household Head change must not bypass:

```text
One Active Household Head Rule
Membership Validation
Audit
Transaction Safety
```

Likewise, an approved Person addition must not bypass duplicate detection.

---

# 68. Notifications

V1 should support an internal notification model.

Potential recipients:

```text
Data Entry
Reviewer
Administrator
Social Worker
Family User
```

Events may include:

```text
Form Returned
Form Verified
Request Submitted
Request Returned
Request Approved
Request Rejected
Request Applied
```

Initial delivery may be in-app.

Future channels may include:

```text
Email
SMS
Other approved messaging channels
```

---

# 69. RTL & Localization

Famboook must support Arabic operational use.

Requirements include:

```text
RTL
Arabic labels
Arabic names
Unicode
Readable Arabic forms
```

Technical codes remain language-independent.

---

# 70. Responsive Design

The Staff Portal should support normal desktop operational use.

The Family Portal should be particularly responsive and mobile-friendly because Family Users may primarily access it from phones.

---

# 71. Non-Functional Requirements

## Security

Sensitive data must be protected.

## Auditability

Critical changes must be traceable.

## Data Integrity

Critical rules must be enforced at database/backend level.

## Maintainability

Domain logic should not be trapped inside UI components.

## Performance

Registry searches and profiles should remain responsive at realistic scale.

## Usability

Operational workflows should be understandable to non-technical users.

## RTL

Arabic interface must render correctly.

## Responsive UI

Family Portal must work effectively on mobile devices.

## Backup

Production data and private documents require backups.

## Extensibility

Architecture should support future portals and integrations.

---

# 72. V1 Technical Direction

Recommended:

```text
Backend:
Laravel

Database:
PostgreSQL

Staff Portal:
Filament

Authorization:
Laravel Policies
+
Spatie Laravel Permission

Audit:
Spatie Activity Log
or equivalent

File Storage:
Private Laravel Storage
or approved private object storage
```

---

# 73. Family Portal Technical Direction

The Family Portal should use the same Laravel domain and authorization layer.

Possible implementation approaches include:

```text
Laravel + Blade/Livewire
```

or later:

```text
Next.js / React
```

The architectural requirement is:

```text
Family Portal
      ↓
Same Authorization Rules
      ↓
Same Domain Actions
      ↓
Same Registry
```

The portal must not implement separate uncontrolled business logic.

The final frontend technology can be selected during implementation planning.

---

# 74. Domain Actions

Important business operations should be implemented through reusable Actions/Services.

Examples:

```text
CreateFamilyAction
CreatePersonAction
ChangeHouseholdHeadAction
TransferPersonAction

SubmitFormAction
VerifyFormAction
ApproveFormAction

SubmitChangeRequestAction
ReturnChangeRequestAction
ApproveChangeRequestAction
RejectChangeRequestAction
ApplyChangeRequestAction
```

This allows the same rules to be used from Staff Portal, Family Portal, and future APIs.

---

# 75. Out of Scope — V1

The following remain outside V1 unless later explicitly approved:

```text
Anonymous public self-registration

Unknown users directly creating official Families

Public Family profiles

Native mobile application

Biometric identification

Automated eligibility decisions

AI-controlled eligibility decisions

Automatic Person merging

Accounting system

Payment processing

Complex external-system integrations

Fully offline synchronization
```

---

# 76. Explicitly In Scope — V1

To avoid ambiguity, the following is now explicitly part of V1:

```text
Authenticated Family Portal

Verified Family User accounts

User-to-Person linking

Controlled Family data visibility

Family Change Requests

Supporting document uploads

Change Request review

Change Request approval/rejection

Application of approved changes

Change Request workflow history

Family User notifications
```

---

# 77. Future Capabilities

Future versions may consider:

```text
Multiple authorized users per Family

Delegated Family representatives

Family User account recovery

SMS verification

2FA

Electronic consent

Digital signatures

Public appointment booking

Advanced notifications

External assistance-provider integrations

Offline field collection

Native mobile application
```

These are not automatic V1 commitments.

---

# 78. Product Invariants

The following must remain true.

```text
PROD-INV-001
A Person is not defined by a paper-form row.

PROD-INV-002
A Person may survive Family membership changes.

PROD-INV-003
A Family may survive Household Head changes.

PROD-INV-004
Historical membership must not be destroyed by transfer.

PROD-INV-005
Family-submitted changes do not automatically become official registry data.

PROD-INV-006
A Family User may access only authorized Family data.

PROD-INV-007
A Family User cannot approve their own registry changes.

PROD-INV-008
Adding a member through the Family Portal must not bypass duplicate detection.

PROD-INV-009
Reporting a death must not delete the Person.

PROD-INV-010
Household Head changes must preserve history.

PROD-INV-011
Documents uploaded by Family Users are not automatically verified.

PROD-INV-012
Workflow history is preserved.

PROD-INV-013
Sensitive internal data is not automatically exposed to Family Users.

PROD-INV-014
Staff Portal and Family Portal use the same core domain rules.

PROD-INV-015
Public self-registration is not equivalent to authenticated Family self-service.
```

---

# 79. Approved Product Decisions V1

### PROD-ADR-001

Famboook is a Family Registry & Case Management System, not merely a form-entry application.

### PROD-ADR-002

Family and Person are independent persistent entities.

### PROD-ADR-003

Paper forms are data sources rather than the database model.

### PROD-ADR-004

Family membership must support history.

### PROD-ADR-005

Assessments are separate from permanent registry identity.

### PROD-ADR-006

Needs and Assistance are separate concepts.

### PROD-ADR-007

Sensitive data requires controlled access.

### PROD-ADR-008

Duplicate Persons must not be automatically merged.

### PROD-ADR-009

Famboook V1 includes an authenticated Family Portal.

### PROD-ADR-010

The external portal actor is modeled as `FAMILY_USER`.

### PROD-ADR-011

A Family User account is linked to a known Person.

### PROD-ADR-012

Family-submitted modifications use Change Requests.

### PROD-ADR-013

Family-submitted changes do not directly overwrite verified registry records.

### PROD-ADR-014

Change Request `APPROVED` and `APPLIED` are separate lifecycle states.

### PROD-ADR-015

Family User document uploads remain unverified until staff verification.

### PROD-ADR-016

Family Portal access must be reevaluated when household membership or Household Head status changes.

### PROD-ADR-017

Family Portal uses the same core domain/business rules as Staff operations.

### PROD-ADR-018

Anonymous public self-registration remains outside V1.

---

# 80. Pending Product Decisions

### PPD-001 — Family Account Activation

Define the exact Family User identity-verification and account-activation process.

Potential mechanisms may include:

```text
Staff Activation
National ID Verification
Mobile Verification
OTP
Verification Questions
Combination
```

Security review is required before selection.

---

### PPD-002 — Family User Eligibility

Confirm whether V1 Family Portal access is limited to:

```text
Current Household Head
```

or may also include:

```text
Authorized Family Representative
```

The architecture supports both.

---

### PPD-003 — Multiple Family Users

Determine whether V1 allows more than one Family User account per Family.

---

### PPD-004 — Direct Low-Risk Updates

Determine whether any low-risk fields may eventually be updated directly without staff approval.

Default V1 position:

```text
All Family-submitted registry changes require controlled review.
```

---

### PPD-005 — Family Portal Health Visibility

Determine exactly which health/disability information a Family User may view for:

```text
Self
Children
Other Adult Members
```

---

### PPD-006 — Needs Visibility

Determine which Need details are appropriate for Family Portal display.

---

### PPD-007 — Assistance Visibility

Determine which Assistance details are appropriate for Family Portal display.

---

### PPD-008 — Notifications

Determine V1 delivery channels:

```text
In-App only
SMS
Email
Combination
```

---

### PPD-009 — Family Portal Frontend

Select initial Family Portal implementation:

```text
Laravel / Livewire
```

or:

```text
Separate React / Next.js frontend
```

without changing core domain architecture.

---

### PPD-010 — Account Recovery

Define a secure Family User account-recovery process.

---

# 81. V1 Success Criteria

Famboook V1 is successful when authorized staff can:

```text
Register Families
Register Persons
Verify Data
Manage Membership
Track Residence
Perform Assessments
Record Needs
Record Assistance
Manage Documents
Manage Cases
Search
Report
Audit
```

and authorized Family Users can:

```text
Authenticate
Access their permitted Family profile
Review permitted information
Submit update requests
Upload supporting evidence
Track request status
Receive request outcomes
```

without directly compromising the integrity of the official registry.

---

# 82. Product Completion Flow

The product architecture is now:

```text
Staff Sources ─────────────┐
                           │
Paper Forms ───────────────┤
                           ▼
                    Validation / Review
                           │
                           ▼
                    OFFICIAL REGISTRY
                           ▲
                           │
Family Portal             │
     │                     │
     ▼                     │
Change Request             │
     │                     │
     ▼                     │
Review / Approval ─────────┘
```

---

# 83. Document Dependencies

This Product document must be reflected in:

```text
02-DATA-DICTIONARY.md

03-BUSINESS-RULES.md

04-DATABASE.md

05-WORKFLOWS.md

06-PERMISSIONS.md

07-ROADMAP.md
```

In particular, the following new concepts require downstream definitions:

```text
FAMILY_USER

User ↔ Person Link

Family Portal

Change Requests

Change Request Types

Change Request Workflow

Change Request Documents

Family User Notifications

Family User Data Scope
```

---

# 84. Implementation Warning

Do not implement the Family Portal as:

```text
Family User
      ↓
Direct CRUD
      ↓
families / persons tables
```

The intended architecture is:

```text
Family User
      ↓
Authorized Family Portal
      ↓
Change Request
      ↓
Review
      ↓
Approved Domain Action
      ↓
Official Registry
```

This separation is essential for registry integrity.

---

# 85. Document Status

```text
Project: Famboook
Document: Product Definition
Version: 1.1
Status: APPROVED
Date: 2026-09-22
```

---

# 86. Change Log

| Version | Date | Status | Description |
|---|---|---|---|
| 1.0 | 2026-09-22 | Approved | Initial Famboook product definition |
| 1.1 | 2026-09-22 | Approved | Added authenticated Family Portal, FAMILY_USER, controlled self-service, Change Requests, Family User access model, supporting documents, notifications, and distinction between self-service and public self-registration |

---

# 87. Next Step

After approving this update, synchronize:

```text
01-PRODUCT.md             UPDATED — v1.1
        ↓
02-DATA-DICTIONARY.md     NEXT
        ↓
03-BUSINESS-RULES.md
        ↓
04-DATABASE.md
        ↓
05-WORKFLOWS.md
        ↓
06-PERMISSIONS.md
        ↓
07-ROADMAP.md
```

The next document must formally define the new data entities and fields required for:

```text
Family User accounts
User-to-Person linking
Change Requests
Change Request Types
Supporting Documents
Request Review
Request Application
Notifications
```