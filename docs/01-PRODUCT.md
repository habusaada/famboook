# Famboook
## Product Definition

**Document:** `01-PRODUCT.md`  
**Version:** 1.0  
**Status:** Approved  
**Last Updated:** 2026-09-22  
**Project:** Famboook — Family Registry & Case Management System

---

# 1. Product Overview

**Famboook** is a Family Registry & Case Management System designed to create and maintain a structured, verified, and continuously updatable registry of families and their members.

The system transforms paper-based family registration and assessment forms into structured digital records that can be searched, verified, updated, analyzed, and reported on.

Famboook is not intended to be only a form-entry application.

It is designed as a long-term family information platform where:

```text
Paper / Digital Form
        ↓
Data Entry
        ↓
Verification
        ↓
Family Registry
        ↓
Assessments & Updates
        ↓
Needs & Assistance
        ↓
Reporting & Decision Support
```

---

# 2. Product Vision

Create a reliable and maintainable family information system that provides a single, structured source of truth for family and individual records.

Famboook should allow authorized users to answer questions such as:

- How many registered families exist?
- How many individuals are registered?
- Who belongs to each family?
- Who is the current household head?
- Where does the family currently reside?
- Has the family been displaced?
- What is the demographic composition of the family?
- Are there children, elderly persons, pregnant women, nursing mothers, persons with disabilities, or persons with chronic diseases?
- What are the family's identified needs?
- What assistance has the family received?
- When was the family information last verified?
- Are there possible duplicate records?
- What changes have occurred to the family over time?

---

# 3. Problem Statement

Family information collected through paper forms presents several operational challenges.

## 3.1 Fragmented Records

Information may exist across multiple forms, files, spreadsheets, or individual records without a unified family profile.

## 3.2 Difficult Searching

Paper records make it difficult to search using:

- National ID.
- Name.
- Phone number.
- Family ID.
- Location.
- Demographic characteristics.
- Health or disability conditions.
- Identified needs.

## 3.3 Duplicate Records

The same person or family may be entered more than once without an effective mechanism for detecting possible duplicates.

## 3.4 Data Verification

Data entered from paper forms requires review and verification before being considered authoritative.

## 3.5 Historical Changes

Family information changes over time.

Examples include:

- Births.
- Deaths.
- Marriage.
- Separation.
- Household changes.
- Displacement.
- Residence changes.
- Education changes.
- Employment changes.
- Health changes.
- New needs.

A static form cannot adequately represent this history.

## 3.6 Reporting Limitations

Manual records make it difficult to produce accurate and timely statistics.

---

# 4. Product Objectives

Famboook V1 aims to:

1. Create a unique digital record for every registered family.
2. Create an independent record for every registered person.
3. Digitize existing paper family-registration forms.
4. Support data-entry review and verification.
5. Detect potential duplicate individuals.
6. Maintain household and family relationships.
7. Record residence and displacement information.
8. Record relevant health and disability information.
9. Record education and employment information where applicable.
10. Record family and individual needs.
11. Maintain assistance history.
12. Maintain researcher and case notes.
13. Preserve important historical changes.
14. Provide controlled access based on user roles.
15. Maintain an audit trail of sensitive changes.
16. Provide search, filters, dashboards, and reports.
17. Support future integrations and additional modules.

---

# 5. Product Scope

Famboook V1 consists of the following major modules.

```text
Famboook
│
├── Dashboard
├── Family Registry
├── Person Registry
├── Data Entry
├── Verification
├── Assessments
├── Residence & Displacement
├── Health & Disability
├── Education
├── Employment
├── Needs
├── Assistance
├── Documents
├── Case Notes
├── Search
├── Reports
├── Users & Permissions
└── Audit Log
```

---

# 6. Family Registry

The Family Registry is the central module of Famboook.

Every family receives a permanent identifier.

Example:

```text
FAM-000510
```

A Family Profile should provide a consolidated view of:

```text
Overview
Members
Residence
Health
Education
Employment
Needs
Assistance
Documents
Assessments
Notes
History
```

The Family Profile is the main operational entry point for working with a registered family.

---

# 7. Person Registry

Every individual receives an independent permanent Person ID.

Example:

```text
PER-001825
```

A person is not merely a row inside a family form.

The person's record may contain:

- Full name.
- National ID.
- Gender.
- Date of birth.
- Contact information.
- Household relationship.
- Marital status.
- Health information.
- Disability information.
- Education.
- Employment.
- Documents.
- Notes.
- Historical relationships.

This allows a person's history to remain identifiable even if household circumstances change.

---

# 8. Household Membership

A family may contain an unrestricted number of members.

The number of rows available on a paper form does not define a system limit.

Typical relationships include:

```text
Household Head
Spouse
Son
Daughter
Father
Mother
Brother
Sister
Grandchild
Other Relative
Other
```

The exact relationship vocabulary is controlled through reference data.

---

# 9. Data Entry

Famboook must provide a structured data-entry workflow for digitizing paper forms.

The preferred interface is a multi-step process rather than one large form.

Example:

```text
Step 1 — Family
Step 2 — Household Head
Step 3 — Family Members
Step 4 — Residence
Step 5 — Health & Disability
Step 6 — Education & Employment
Step 7 — Needs
Step 8 — Documents / Notes
Step 9 — Review
Step 10 — Submit
```

The exact steps may evolve during UI design.

Data entry should support:

- Save as draft.
- Resume later.
- Validation.
- Conditional fields.
- Duplicate warnings.
- Review before submission.

---

# 10. Verification

Data entered into the system should not automatically become verified data.

The initial workflow is:

```text
DRAFT
   ↓
DATA_ENTRY_COMPLETED
   ↓
UNDER_REVIEW
   ├── RETURNED_FOR_CORRECTION
   │          ↓
   │       CORRECTED
   │          ↓
   └──── UNDER_REVIEW
              ↓
           VERIFIED
              ↓
           APPROVED
```

The detailed workflow is maintained in:

`05-WORKFLOWS.md`

---

# 11. Paper Form Traceability

Where family information originates from a paper form, Famboook should preserve the relationship between the digital data and its source.

The system should be able to identify:

- Source form.
- Paper form number.
- Number of pages.
- Data-entry user.
- Data-entry date.
- Reviewer.
- Review date.
- Verification status.

Where permitted, a scanned copy of the source form may also be stored securely.

---

# 12. Assessments

A family record and an assessment are different concepts.

The family is a long-lived entity.

An assessment represents information collected at a particular point in time.

Examples:

```text
Initial Registration
Verification
Follow-up
Needs Assessment
Emergency Update
```

A family may therefore have multiple assessments over time.

---

# 13. Residence and Displacement

Famboook should support current and historical residence information.

The system should be able to represent:

```text
Original Residence
       ↓
Displacement Location 1
       ↓
Displacement Location 2
       ↓
Current Residence
```

Updating the current residence should not unnecessarily destroy historical residence information.

---

# 14. Health and Disability

Authorized users may record relevant health information including:

- Chronic conditions.
- Health conditions.
- Disability.
- Pregnancy.
- Breastfeeding.
- Need for medical follow-up.
- Assistive devices.

Health and disability information is classified as sensitive and requires restricted access.

---

# 15. Children and Age Groups

Famboook should calculate age groups dynamically from date of birth.

The system should not permanently store values such as:

```text
is_under_1
is_under_2
is_under_5
age
```

when those values can be calculated.

This allows reports to remain accurate as people age.

---

# 16. Education

Where collected, the system should support:

- Enrollment status.
- Education level.
- Current grade.
- Institution.
- Specialization.
- Education status.

The system should allow education information to evolve over time.

---

# 17. Employment

Where collected, the system should support:

- Employment status.
- Occupation.
- Employer.
- Employment sector.
- Income availability.
- Income information where explicitly required.

Income fields are not mandatory unless approved as part of the operational questionnaire.

---

# 18. Needs Management

Famboook should distinguish between:

```text
Need
```

and:

```text
Assistance
```

A need represents an identified requirement.

Examples may include:

- Food.
- Shelter.
- Health.
- Medication.
- Education.
- WASH.
- Protection.
- Assistive devices.
- Clothing.
- Cash.
- Livelihood.

A need may belong to:

```text
Family
```

or:

```text
Specific Person
```

---

# 19. Assistance History

The system should maintain assistance history separately from needs.

An assistance record may contain:

- Beneficiary family.
- Beneficiary person where applicable.
- Assistance type.
- Provider.
- Date.
- Quantity.
- Estimated value where applicable.
- Distribution reference.
- Notes.

This enables future analysis of assistance coverage and possible duplicate assistance.

---

# 20. Documents

Authorized users may record and, where permitted, upload supporting documents.

Examples:

```text
National ID
Birth Certificate
Marriage Certificate
Death Certificate
Medical Report
Disability Report
Other
```

Documents must be stored securely and access must be permission-controlled.

---

# 21. Case Notes

Researchers and authorized staff should be able to create chronological notes related to families or individuals.

Case notes should behave as a timeline.

Example:

```text
22 Sep 2026
Initial family verification.

29 Sep 2026
Residence information updated.

15 Oct 2026
Follow-up assessment completed.
```

Sensitive notes may require additional access restrictions.

---

# 22. Search

Search is a core Famboook capability.

Users with appropriate permissions should be able to search by:

- Family ID.
- Person ID.
- National ID.
- Name.
- Phone number.
- Location.

The system should also support structured filtering.

Examples:

```text
Families currently displaced
```

```text
Families containing children under 2
```

```text
Persons with disabilities
```

```text
Persons with chronic health conditions
```

```text
Persons within a specified age range
```

Search results must respect user permissions.

---

# 23. Duplicate Detection

Famboook should detect possible duplicate person records.

Possible matching signals include:

```text
National ID
Full Name
Date of Birth
Gender
Phone
Family Context
```

Matches may be classified as:

```text
EXACT
PROBABLE
POSSIBLE
```

The system must not automatically merge persons.

An authorized reviewer makes the final decision.

---

# 24. Dashboard

The dashboard should provide an operational overview.

Potential indicators include:

- Registered families.
- Registered persons.
- Male/female distribution.
- Age groups.
- Children.
- Elderly persons.
- Persons with disabilities.
- Persons with chronic conditions.
- Displaced families.
- Forms awaiting review.
- Verified forms.
- Identified needs.

Dashboard values must be derived from canonical data whenever possible.

---

# 25. Reports

Famboook should support reports based on authorized data.

Examples:

```text
Families by location
Population by age group
Population by gender
Displaced families
Persons with disabilities
Chronic health conditions
Education status
Employment status
Needs by category
Assistance by category
```

Exports must respect data-access permissions.

Sensitive information must not automatically appear in every report.

---

# 26. User Roles

Initial system roles are:

```text
Super Admin
Administrator
Data Entry
Reviewer
Social Worker / Researcher
Reports Viewer
```

Detailed permissions are maintained in:

`06-PERMISSIONS.md`

---

# 27. Auditability

Sensitive and important actions must be traceable.

Examples:

```text
Person created
National ID changed
Family member added
Family member moved
Assessment submitted
Assessment verified
Document verified
Family approved
```

The system should record:

```text
Who
What
When
Affected Record
Previous Value
New Value
```

where technically and operationally appropriate.

---

# 28. Data Protection

Famboook will contain personally identifiable and sensitive information.

The product must therefore follow the principles of:

- Least privilege.
- Role-based access.
- Secure authentication.
- Controlled exports.
- Secure document storage.
- Audit logging.
- Data minimization.
- Secure backups.
- Protection of credentials and secrets.

Real family data must never be committed to the source-code repository.

---

# 29. Product Users

Famboook V1 is designed for internal authorized users.

Primary user groups include:

### Data Entry Operator

Digitizes paper forms and corrects returned records.

### Reviewer

Checks entered information and identifies errors or possible duplicates.

### Social Worker / Researcher

Views assigned family information and records assessments or follow-up information.

### Administrator

Manages operational configuration and authorized records.

### Reports Viewer

Accesses permitted dashboards and reports.

### Super Admin

Manages system-level configuration and access.

---

# 30. V1 Functional Requirements

The initial release should provide at minimum:

```text
FR-001 User authentication
FR-002 Role-based permissions
FR-003 Create family
FR-004 Create household head
FR-005 Add/edit family members
FR-006 Family profile
FR-007 Person profile
FR-008 Residence information
FR-009 Displacement information
FR-010 Health information
FR-011 Disability information
FR-012 Education information
FR-013 Employment information
FR-014 Paper form registration
FR-015 Data-entry workflow
FR-016 Review and verification
FR-017 Duplicate detection
FR-018 Family/person search
FR-019 Needs recording
FR-020 Assistance recording
FR-021 Case notes
FR-022 Document metadata
FR-023 Audit logging
FR-024 Dashboard
FR-025 Reports
FR-026 Controlled data export
```

---

# 31. Non-Functional Requirements

## NFR-001 — Security

Sensitive data must only be accessible to authorized users.

## NFR-002 — Auditability

Important changes must be traceable.

## NFR-003 — Maintainability

The architecture should support future modules without requiring redesign of the core family/person model.

## NFR-004 — Data Integrity

Database constraints and application validation should prevent invalid relationships and inconsistent records.

## NFR-005 — Performance

Common family, person and National ID searches should remain responsive as the dataset grows.

## NFR-006 — Usability

Data-entry screens should be optimized for repeated form entry.

## NFR-007 — RTL

The primary operational interface should support Arabic and RTL correctly.

## NFR-008 — Responsive UI

The system should support common desktop and tablet screen sizes.

## NFR-009 — Backup

The system must support regular secure database and document backups.

## NFR-010 — Extensibility

The system should allow additional assessments, reports and case-management capabilities to be introduced later.

---

# 32. V1 Target Scale

The architecture should not assume that the initial dataset represents the maximum future size.

Initial operational use may begin with a relatively small number of families while the architecture should remain suitable for growth to a significantly larger population.

No database structure should depend on fixed paper-form row counts.

---

# 33. Out of Scope for Initial V1

Unless later approved, the following are not required for the first operational release:

- Public self-registration portal.
- Public family profiles.
- Native Android application.
- Native iOS application.
- Automated beneficiary eligibility decisions.
- Automatic merging of duplicate people.
- AI-generated eligibility decisions.
- Financial accounting.
- Payment processing.
- Biometric identification.
- External organization integrations.
- Fully offline synchronization.

These capabilities may be evaluated in later releases.

---

# 34. Proposed Technical Direction

The initial technical direction is:

```text
Backend:
Laravel

Administration / Internal Application:
Filament

Database:
PostgreSQL

Authentication:
Laravel-based authentication

Authorization:
Role-Based Access Control (RBAC)

API:
Laravel REST API where required

Frontend:
May be introduced separately when justified

File Storage:
Private / controlled storage
```

Technology decisions remain subject to the architecture document and implementation review.

---

# 35. Product Architecture Principle

Famboook must be designed as:

```text
Family Registry
      +
Person Registry
      +
Assessment System
      +
Case Management
      +
Reporting
```

not merely:

```text
Digital Form
```

The paper form is the initial data acquisition mechanism.

The registry is the long-term product.

---

# 36. Source of Truth

Product intent:

```text
01-PRODUCT.md
```

Data definitions:

```text
02-DATA-DICTIONARY.md
```

Business logic:

```text
03-BUSINESS-RULES.md
```

Database architecture:

```text
04-DATABASE.md
```

Workflow:

```text
05-WORKFLOWS.md
```

Authorization:

```text
06-PERMISSIONS.md
```

Delivery plan:

```text
07-ROADMAP.md
```

Application code must follow the approved documentation rather than silently redefining product behavior.

---

# 37. V1 Success Criteria

Famboook V1 will be considered operationally successful when authorized users can:

1. Register a family.
2. Register all family members without a fixed member limit.
3. Search families and persons reliably.
4. Digitize the approved paper form.
5. Review and verify entered information.
6. Detect potential duplicate persons.
7. Maintain residence and displacement information.
8. Maintain relevant health and vulnerability information.
9. Record needs and assistance.
10. Record assessments and follow-up notes.
11. View a complete family profile.
12. Produce authorized aggregate reports.
13. Track important changes through audit logs.
14. Protect sensitive information according to user permissions.

---

# 38. Future Direction

Possible future capabilities include:

```text
Mobile data collection
Offline data collection
PWA
Family self-service
QR family cards
Advanced case management
Advanced analytics
GIS mapping
External API integrations
Data synchronization
Notification services
Program eligibility workflows
Assistance distribution management
```

Future capabilities must build on the canonical Family and Person identities established in V1.

---

# 39. Product Decision Summary

The following decisions are approved for Product V1:

**PD-001**  
Famboook is a registry and case-management platform, not merely a digital questionnaire.

**PD-002**  
Family and Person are independent core entities.

**PD-003**  
The system supports unlimited family members independent of paper-form capacity.

**PD-004**  
Paper forms remain traceable to their digital records.

**PD-005**  
Entered information passes through a verification workflow.

**PD-006**  
Potential duplicates require human review.

**PD-007**  
Historical information should be preserved where relevant.

**PD-008**  
Sensitive information is permission-controlled.

**PD-009**  
Needs and assistance are separate concepts.

**PD-010**  
The core architecture must support future expansion without redesigning Family and Person identity.

---

# 40. Document Status

```text
Project: Famboook
Document: Product Definition
Version: 1.0
Status: APPROVED
Date: 2026-09-22
```

---

# 41. Change Log

| Version | Date | Status | Description |
|---|---|---|---|
| 1.0 | 2026-09-22 | Approved | Initial Famboook product definition |

---

# 42. Next Document

After approval of this document:

```text
01-PRODUCT.md                 APPROVED
        ↓
02-DATA-DICTIONARY.md         APPROVED
        ↓
03-BUSINESS-RULES.md          NEXT
        ↓
04-DATABASE.md
        ↓
05-WORKFLOWS.md
        ↓
06-PERMISSIONS.md
        ↓
07-ROADMAP.md
```