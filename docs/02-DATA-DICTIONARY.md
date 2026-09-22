# Famboook
## Data Dictionary

**Document:** `02-DATA-DICTIONARY.md`  
**Version:** 1.0  
**Status:** Approved  
**Last Updated:** 2026-09-22  
**Project:** Famboook — Family Registry & Case Management System

---

# 1. Purpose

This document defines the canonical data structure for Famboook.

It describes:

- Core data entities.
- Field names and database identifiers.
- Data types.
- Required and optional fields.
- Relationships between entities.
- Lookup/reference data.
- Validation principles.
- Sensitive-data classification.
- Derived data.
- Data-entry and verification metadata.

This document is the primary data reference for:

- Database design.
- Backend development.
- Frontend forms.
- Validation.
- Reporting.
- Import/export.
- Data migration.
- AI-assisted development.

Detailed business rules are maintained separately in:

`03-BUSINESS-RULES.md`

Physical database implementation is maintained in:

`04-DATABASE.md`

---

# 2. Core Design Principles

Famboook follows the following principles.

## DD-P01 — Person-Centric Model

A family member is represented as an independent `Person` entity.

The system MUST NOT use fields such as:

```text
child_1_name
child_2_name
wife_1_name
wife_2_name
```

Instead:

```text
Family
 ├── Person
 ├── Person
 ├── Person
 └── Person
```

---

## DD-P02 — Family and Person Have Independent Identities

Every family has a permanent Family ID.

Example:

```text
FAM-000510
```

Every person has a permanent Person ID.

Example:

```text
PER-001825
```

A person's identity MUST NOT depend on their position inside a family.

---

## DD-P03 — Paper Forms Are Data Sources

The paper registration form is a source of data.

It is NOT the database structure.

Paper-specific limitations such as the number of rows available for family members MUST NOT limit the digital system.

---

## DD-P04 — Do Not Store Derived Data

Values that can be reliably calculated from canonical data SHOULD NOT be stored.

Examples:

```text
age
family_size
number_of_children
number_of_males
number_of_females
number_under_5
number_of_disabled_persons
```

These values must be calculated when required.

---

## DD-P05 — Historical Data Should Be Preserved

Where applicable, Famboook should preserve historical records instead of overwriting them.

Examples:

- Residence history.
- Assessments.
- Assistance.
- Case notes.
- Employment history.
- Education history.
- Household relationships.

---

## DD-P06 — Sensitive Data Requires Protection

National IDs, health information, disability information, documents and confidential case notes are sensitive data.

Access to these fields must be controlled by permissions.

---

# 3. Naming Conventions

Database identifiers use:

```text
snake_case
```

Examples:

```text
family_code
national_id
birth_date
relationship_type_id
```

Table names use plural nouns:

```text
families
persons
assessments
documents
```

Foreign keys use:

```text
<entity>_id
```

Example:

```text
family_id
person_id
created_by
```

---

# 4. Families

**Entity:** Family  
**Table:** `families`  
**Purpose:** Represents one registered household/family.

| Code | Arabic Name | Database Field | Type | Required | Notes |
|---|---|---|---|---|---|
| FAM-001 | المعرف الداخلي | `id` | BIGINT | Yes | Primary Key |
| FAM-002 | رقم الأسرة | `family_code` | VARCHAR(20) | Yes | Unique |
| FAM-003 | حالة ملف الأسرة | `status` | VARCHAR(30) | Yes | Workflow status |
| FAM-004 | تاريخ التسجيل | `registration_date` | DATE | Yes | |
| FAM-005 | مصدر التسجيل | `registration_source` | VARCHAR(30) | Yes | paper/digital/import |
| FAM-006 | رقم الاستمارة الورقية | `paper_form_no` | VARCHAR(50) | No | Source reference |
| FAM-007 | ملاحظات عامة | `notes` | TEXT | No | |
| FAM-008 | أنشئ بواسطة | `created_by` | BIGINT/FK | Yes | User |
| FAM-009 | آخر تعديل بواسطة | `updated_by` | BIGINT/FK | No | User |
| FAM-010 | تاريخ الإنشاء | `created_at` | TIMESTAMP | Yes | System |
| FAM-011 | تاريخ التعديل | `updated_at` | TIMESTAMP | Yes | System |

### Family Code

Recommended format:

```text
FAM-000001
FAM-000002
...
FAM-000510
```

`family_code` is a public/business identifier.

It MUST NOT replace the internal database primary key.

---

# 5. Persons

**Entity:** Person  
**Table:** `persons`  
**Purpose:** Represents an individual person registered in Famboook.

The household head, spouse and children are all represented using the same entity.

| Code | Arabic Name | Database Field | Type | Required | Notes |
|---|---|---|---|---|---|
| PER-001 | المعرف الداخلي | `id` | BIGINT | Yes | PK |
| PER-002 | رقم الشخص | `person_code` | VARCHAR(20) | Yes | Unique |
| PER-003 | الأسرة الحالية | `family_id` | BIGINT/FK | Yes* | Current household |
| PER-004 | رقم الفرد في الاستمارة | `paper_sequence_no` | SMALLINT | No | Paper traceability only |
| PER-005 | الاسم الكامل | `full_name` | VARCHAR(200) | Yes | |
| PER-006 | رقم الهوية | `national_id` | VARCHAR(20) | Conditional | Sensitive |
| PER-007 | الجنس | `gender` | VARCHAR(20) | Yes | Lookup |
| PER-008 | تاريخ الميلاد | `birth_date` | DATE | Conditional | |
| PER-009 | صلة القرابة برب الأسرة | `relationship_type_id` | BIGINT/FK | Yes | Lookup |
| PER-010 | رب الأسرة | `is_household_head` | BOOLEAN | Yes | Default false |
| PER-011 | الحالة الاجتماعية | `marital_status_id` | BIGINT/FK | No | |
| PER-012 | حالة الحياة | `life_status` | VARCHAR(20) | Yes | alive/deceased/unknown |
| PER-013 | رقم الهاتف | `mobile` | VARCHAR(20) | No | |
| PER-014 | هاتف بديل | `alternate_mobile` | VARCHAR(20) | No | |
| PER-015 | ملاحظات | `notes` | TEXT | No | |
| PER-016 | فعال | `is_active` | BOOLEAN | Yes | Default true |
| PER-017 | أنشئ بواسطة | `created_by` | BIGINT/FK | Yes | |
| PER-018 | آخر تعديل بواسطة | `updated_by` | BIGINT/FK | No | |
| PER-019 | تاريخ الإنشاء | `created_at` | TIMESTAMP | Yes | |
| PER-020 | تاريخ التعديل | `updated_at` | TIMESTAMP | Yes | |

### Person Code

Recommended format:

```text
PER-000001
PER-000002
PER-000003
```

---

# 6. Relationship Types

**Table:** `relationship_types`

Initial reference values:

| Code | Arabic | English |
|---|---|---|
| REL-01 | رب الأسرة / نفسه | Self / Household Head |
| REL-02 | زوج/زوجة | Spouse |
| REL-03 | ابن | Son |
| REL-04 | ابنة | Daughter |
| REL-05 | أب | Father |
| REL-06 | أم | Mother |
| REL-07 | أخ | Brother |
| REL-08 | أخت | Sister |
| REL-09 | حفيد/حفيدة | Grandchild |
| REL-10 | قريب آخر | Other Relative |
| REL-99 | أخرى | Other |

Reference values may be extended without modifying the core person schema.

---

# 7. Person Relationships

**Entity:** Person Relationship  
**Table:** `person_relationships`

Purpose:

Represents explicit relationships between two persons independently from household membership.

| Code | Arabic Name | Database Field | Type | Required |
|---|---|---|---|---|
| PRL-001 | المعرف | `id` | BIGINT | Yes |
| PRL-002 | الشخص | `person_id` | BIGINT/FK | Yes |
| PRL-003 | الشخص المرتبط | `related_person_id` | BIGINT/FK | Yes |
| PRL-004 | نوع العلاقة | `relationship_type_id` | BIGINT/FK | Yes |
| PRL-005 | تاريخ البداية | `start_date` | DATE | No |
| PRL-006 | تاريخ النهاية | `end_date` | DATE | No |
| PRL-007 | الحالة | `status` | VARCHAR(20) | Yes |
| PRL-008 | ملاحظات | `notes` | TEXT | No |

Examples:

```text
Person A → spouse → Person B
Person A → father → Person C
Person B → mother → Person C
```

---

# 8. Marital Statuses

**Table:** `marital_statuses`

Initial values:

| Code | Arabic | English |
|---|---|---|
| MAR-01 | أعزب/عزباء | Single |
| MAR-02 | متزوج/ة | Married |
| MAR-03 | مطلق/ة | Divorced |
| MAR-04 | أرمل/ة | Widowed |
| MAR-05 | منفصل/ة | Separated |
| MAR-99 | غير محدد | Unknown |

---

# 9. Family Residences

**Entity:** Residence  
**Table:** `family_residences`

Residence information is historical.

A family may therefore have multiple residence records, but only one current residence at a given time.

| Code | Arabic Name | Database Field | Type | Required |
|---|---|---|---|---|
| RES-001 | المعرف | `id` | BIGINT | Yes |
| RES-002 | الأسرة | `family_id` | BIGINT/FK | Yes |
| RES-003 | المحافظة | `governorate_id` | BIGINT/FK | No |
| RES-004 | المدينة/البلدة | `locality_id` | BIGINT/FK | No |
| RES-005 | الحي/المنطقة | `neighborhood` | VARCHAR(150) | No |
| RES-006 | العنوان التفصيلي | `address_details` | TEXT | No |
| RES-007 | نوع السكن | `housing_type_id` | BIGINT/FK | No |
| RES-008 | حيازة السكن | `tenure_type_id` | BIGINT/FK | No |
| RES-009 | حالة المسكن | `housing_condition_id` | BIGINT/FK | No |
| RES-010 | نازح | `is_displaced` | BOOLEAN | Yes |
| RES-011 | مكان النزوح | `displacement_location` | TEXT | Conditional |
| RES-012 | تاريخ النزوح | `displacement_date` | DATE | No |
| RES-013 | سبب النزوح | `displacement_reason` | TEXT | No |
| RES-014 | السكن الحالي | `is_current` | BOOLEAN | Yes |
| RES-015 | من تاريخ | `from_date` | DATE | No |
| RES-016 | حتى تاريخ | `to_date` | DATE | No |
| RES-017 | ملاحظات | `notes` | TEXT | No |

---

# 10. Housing Types

**Table:** `housing_types`

Initial candidate values:

```text
House
Apartment
Tent
Shelter
School
Relative's House
Collective Center
Temporary Structure
Other
```

> The final list must be verified against the approved paper form before database seeding.

---

# 11. Tenure Types

**Table:** `tenure_types`

Candidate values:

```text
Owned
Rented
Hosted
Temporary
Collective
Other
```

Final values are subject to source-form verification.

---

# 12. Health Profiles

**Entity:** Person Health Profile  
**Table:** `person_health_profiles`

One person has at most one current health profile.

| Code | Arabic Name | Database Field | Type | Required |
|---|---|---|---|---|
| HLT-001 | المعرف | `id` | BIGINT | Yes |
| HLT-002 | الشخص | `person_id` | BIGINT/FK | Yes |
| HLT-003 | لديه حالة صحية | `has_health_condition` | BOOLEAN | Yes |
| HLT-004 | لديه مرض مزمن | `has_chronic_disease` | BOOLEAN | Yes |
| HLT-005 | لديه إعاقة | `has_disability` | BOOLEAN | Yes |
| HLT-006 | حامل | `is_pregnant` | BOOLEAN | Conditional |
| HLT-007 | مرضعة | `is_breastfeeding` | BOOLEAN | Conditional |
| HLT-008 | يحتاج متابعة | `requires_follow_up` | BOOLEAN | Yes |
| HLT-009 | ملاحظات | `notes` | TEXT | No |

Pregnancy and breastfeeding fields are applicable only when biologically/contextually applicable according to the approved business rules.

---

# 13. Health Condition Types

**Table:** `health_condition_types`

Purpose:

Reference table for diseases and health conditions.

Examples:

```text
Diabetes
Hypertension
Heart Disease
Cancer
Kidney Disease
Respiratory Disease
Other Chronic Disease
Other
```

The final controlled vocabulary is defined separately and may evolve.

---

# 14. Person Health Conditions

**Entity:** Person Health Condition  
**Table:** `person_health_conditions`

A person may have zero or multiple health conditions.

| Code | Arabic Name | Database Field | Type | Required |
|---|---|---|---|---|
| PHC-001 | المعرف | `id` | BIGINT | Yes |
| PHC-002 | الشخص | `person_id` | BIGINT/FK | Yes |
| PHC-003 | الحالة الصحية | `health_condition_type_id` | BIGINT/FK | Yes |
| PHC-004 | التفاصيل | `details` | TEXT | No |
| PHC-005 | شدة الحالة | `severity` | VARCHAR(20) | No |
| PHC-006 | يحتاج علاج | `requires_treatment` | BOOLEAN | No |
| PHC-007 | يحتاج دواء مستمر | `requires_medication` | BOOLEAN | No |
| PHC-008 | ملاحظات | `notes` | TEXT | No |

---

# 15. Disability Types

**Table:** `disability_types`

Initial values:

| Code | Arabic | English |
|---|---|---|
| DIS-T01 | حركية | Mobility |
| DIS-T02 | بصرية | Visual |
| DIS-T03 | سمعية | Hearing |
| DIS-T04 | تواصل | Communication |
| DIS-T05 | ذهنية/فكرية | Intellectual |
| DIS-T06 | متعددة | Multiple |
| DIS-T99 | أخرى | Other |

The final classification should be aligned with the approved operational definition used by Famboook.

---

# 16. Person Disabilities

**Entity:** Person Disability  
**Table:** `person_disabilities`

| Code | Arabic Name | Database Field | Type | Required |
|---|---|---|---|---|
| PDS-001 | المعرف | `id` | BIGINT | Yes |
| PDS-002 | الشخص | `person_id` | BIGINT/FK | Yes |
| PDS-003 | نوع الإعاقة | `disability_type_id` | BIGINT/FK | Yes |
| PDS-004 | الدرجة | `severity` | VARCHAR(20) | No |
| PDS-005 | يحتاج مساعدة | `requires_assistance` | BOOLEAN | No |
| PDS-006 | يستخدم أداة مساعدة | `uses_assistive_device` | BOOLEAN | No |
| PDS-007 | الأداة المساعدة | `assistive_device` | VARCHAR(200) | Conditional |
| PDS-008 | ملاحظات | `notes` | TEXT | No |

---

# 17. Education

**Entity:** Person Education  
**Table:** `person_education`

A person may have multiple education records over time.

| Code | Arabic Name | Database Field | Type | Required |
|---|---|---|---|---|
| EDU-001 | المعرف | `id` | BIGINT | Yes |
| EDU-002 | الشخص | `person_id` | BIGINT/FK | Yes |
| EDU-003 | ملتحق بالتعليم | `is_enrolled` | BOOLEAN | No |
| EDU-004 | المستوى التعليمي | `education_level_id` | BIGINT/FK | No |
| EDU-005 | الصف/المستوى الحالي | `current_grade` | VARCHAR(50) | No |
| EDU-006 | المؤسسة التعليمية | `institution_name` | VARCHAR(200) | No |
| EDU-007 | التخصص | `specialization` | VARCHAR(200) | No |
| EDU-008 | الحالة التعليمية | `education_status_id` | BIGINT/FK | No |
| EDU-009 | من تاريخ | `from_date` | DATE | No |
| EDU-010 | حتى تاريخ | `to_date` | DATE | No |
| EDU-011 | ملاحظات | `notes` | TEXT | No |

---

# 18. Employment

**Entity:** Person Employment  
**Table:** `person_employment`

| Code | Arabic Name | Database Field | Type | Required |
|---|---|---|---|---|
| EMP-001 | المعرف | `id` | BIGINT | Yes |
| EMP-002 | الشخص | `person_id` | BIGINT/FK | Yes |
| EMP-003 | حالة العمل | `employment_status_id` | BIGINT/FK | No |
| EMP-004 | المهنة | `occupation` | VARCHAR(200) | No |
| EMP-005 | جهة العمل | `employer` | VARCHAR(200) | No |
| EMP-006 | قطاع العمل | `employment_sector_id` | BIGINT/FK | No |
| EMP-007 | لديه مصدر دخل | `has_income` | BOOLEAN | No |
| EMP-008 | قيمة الدخل | `income_amount` | DECIMAL | No |
| EMP-009 | دورية الدخل | `income_frequency` | VARCHAR(30) | No |
| EMP-010 | من تاريخ | `from_date` | DATE | No |
| EMP-011 | حتى تاريخ | `to_date` | DATE | No |
| EMP-012 | ملاحظات | `notes` | TEXT | No |

> Income-related fields are optional until explicitly approved as part of the source questionnaire.

---

# 19. Documents

**Entity:** Document  
**Table:** `documents`

Documents may belong to either a person or a family depending on document type.

| Code | Arabic Name | Database Field | Type | Required |
|---|---|---|---|---|
| DOC-001 | المعرف | `id` | BIGINT | Yes |
| DOC-002 | الأسرة | `family_id` | BIGINT/FK | Conditional |
| DOC-003 | الشخص | `person_id` | BIGINT/FK | Conditional |
| DOC-004 | نوع الوثيقة | `document_type_id` | BIGINT/FK | Yes |
| DOC-005 | رقم الوثيقة | `document_number` | VARCHAR(100) | No |
| DOC-006 | الوثيقة متوفرة | `is_available` | BOOLEAN | Yes |
| DOC-007 | تم التحقق | `is_verified` | BOOLEAN | Yes |
| DOC-008 | تاريخ الإصدار | `issue_date` | DATE | No |
| DOC-009 | تاريخ الانتهاء | `expiry_date` | DATE | No |
| DOC-010 | مسار الملف | `file_path` | VARCHAR(500) | No |
| DOC-011 | ملاحظات | `notes` | TEXT | No |
| DOC-012 | تم التحقق بواسطة | `verified_by` | BIGINT/FK | No |
| DOC-013 | تاريخ التحقق | `verified_at` | TIMESTAMP | No |

---

# 20. Document Types

**Table:** `document_types`

Candidate values:

```text
National ID
Birth Certificate
Marriage Certificate
Death Certificate
Medical Report
Disability Report
Other
```

---

# 21. Family Needs

**Entity:** Need  
**Table:** `family_needs`

A need may apply to the whole family or to a specific person.

| Code | Arabic Name | Database Field | Type | Required |
|---|---|---|---|---|
| NED-001 | المعرف | `id` | BIGINT | Yes |
| NED-002 | الأسرة | `family_id` | BIGINT/FK | Yes |
| NED-003 | الفرد | `person_id` | BIGINT/FK | No |
| NED-004 | نوع الاحتياج | `need_type_id` | BIGINT/FK | Yes |
| NED-005 | الأولوية | `priority` | VARCHAR(20) | No |
| NED-006 | الوصف | `description` | TEXT | No |
| NED-007 | الحالة | `status` | VARCHAR(30) | Yes |
| NED-008 | تاريخ تحديد الحاجة | `identified_at` | DATE | Yes |
| NED-009 | تم التحقق | `is_verified` | BOOLEAN | Yes |
| NED-010 | تحقق بواسطة | `verified_by` | BIGINT/FK | No |
| NED-011 | تاريخ التحقق | `verified_at` | TIMESTAMP | No |

---

# 22. Need Types

**Table:** `need_types`

Initial candidate values:

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

---

# 23. Assistance Records

**Entity:** Assistance  
**Table:** `assistance_records`

A need and an assistance record are different concepts.

A family may have a documented need without having received assistance.

| Code | Arabic Name | Database Field | Type | Required |
|---|---|---|---|---|
| AST-001 | المعرف | `id` | BIGINT | Yes |
| AST-002 | الأسرة | `family_id` | BIGINT/FK | Yes |
| AST-003 | الفرد | `person_id` | BIGINT/FK | No |
| AST-004 | نوع المساعدة | `assistance_type_id` | BIGINT/FK | Yes |
| AST-005 | الجهة المقدمة | `provider_name` | VARCHAR(200) | No |
| AST-006 | الوصف | `description` | TEXT | No |
| AST-007 | الكمية | `quantity` | DECIMAL | No |
| AST-008 | الوحدة | `unit` | VARCHAR(50) | No |
| AST-009 | القيمة التقديرية | `estimated_value` | DECIMAL | No |
| AST-010 | العملة | `currency` | VARCHAR(10) | No |
| AST-011 | تاريخ الاستلام | `received_at` | DATE | Yes |
| AST-012 | مرجع التوزيع | `distribution_reference` | VARCHAR(100) | No |
| AST-013 | ملاحظات | `notes` | TEXT | No |

---

# 24. Person Notes

**Entity:** Person Note  
**Table:** `person_notes`

Used for additional information related to a specific person.

| Code | Arabic Name | Database Field | Type | Required |
|---|---|---|---|---|
| PNT-001 | المعرف | `id` | BIGINT | Yes |
| PNT-002 | الشخص | `person_id` | BIGINT/FK | Yes |
| PNT-003 | نوع الملاحظة | `note_type_id` | BIGINT/FK | No |
| PNT-004 | الملاحظة | `note` | TEXT | Yes |
| PNT-005 | سرية | `is_confidential` | BOOLEAN | Yes |
| PNT-006 | بواسطة | `created_by` | BIGINT/FK | Yes |
| PNT-007 | التاريخ | `created_at` | TIMESTAMP | Yes |

---

# 25. Case Notes

**Entity:** Case Note  
**Table:** `case_notes`

Case notes represent researcher/social-worker observations and follow-up notes.

| Code | Arabic Name | Database Field | Type | Required |
|---|---|---|---|---|
| CNO-001 | المعرف | `id` | BIGINT | Yes |
| CNO-002 | الأسرة | `family_id` | BIGINT/FK | Yes |
| CNO-003 | التقييم | `assessment_id` | BIGINT/FK | No |
| CNO-004 | الفرد | `person_id` | BIGINT/FK | No |
| CNO-005 | نوع الملاحظة | `note_type_id` | BIGINT/FK | No |
| CNO-006 | الملاحظة | `note` | TEXT | Yes |
| CNO-007 | سرية | `is_confidential` | BOOLEAN | Yes |
| CNO-008 | الباحث/المستخدم | `created_by` | BIGINT/FK | Yes |
| CNO-009 | التاريخ | `created_at` | TIMESTAMP | Yes |

Case notes MUST be append-oriented.

Previous notes should not be silently overwritten.

---

# 26. Assessments

**Entity:** Assessment  
**Table:** `assessments`

An assessment represents a data collection, verification or follow-up event.

A family may have multiple assessments.

| Code | Arabic Name | Database Field | Type | Required |
|---|---|---|---|---|
| ASM-001 | المعرف | `id` | BIGINT | Yes |
| ASM-002 | رقم التقييم | `assessment_code` | VARCHAR(30) | Yes |
| ASM-003 | الأسرة | `family_id` | BIGINT/FK | Yes |
| ASM-004 | نوع التقييم | `assessment_type_id` | BIGINT/FK | Yes |
| ASM-005 | تاريخ التقييم | `assessment_date` | DATE | Yes |
| ASM-006 | جامع البيانات/الباحث | `collector_id` | BIGINT/FK | Yes |
| ASM-007 | المدقق | `reviewer_id` | BIGINT/FK | No |
| ASM-008 | الحالة | `status` | VARCHAR(30) | Yes |
| ASM-009 | الموقع | `location` | VARCHAR(255) | No |
| ASM-010 | مصدر البيانات | `source` | VARCHAR(30) | Yes |
| ASM-011 | ملاحظات | `notes` | TEXT | No |
| ASM-012 | تاريخ الإرسال | `submitted_at` | TIMESTAMP | No |
| ASM-013 | تاريخ التحقق | `verified_at` | TIMESTAMP | No |

Example assessment types:

```text
Initial Registration
Verification
Follow-up
Needs Assessment
Emergency Update
```

---

# 27. Form Submissions

**Entity:** Form Submission  
**Table:** `form_submissions`

Represents the original paper/digital form from which data was entered.

| Code | Arabic Name | Database Field | Type | Required |
|---|---|---|---|---|
| FRM-001 | المعرف | `id` | BIGINT | Yes |
| FRM-002 | الأسرة | `family_id` | BIGINT/FK | Yes |
| FRM-003 | التقييم | `assessment_id` | BIGINT/FK | No |
| FRM-004 | نوع النموذج | `form_type_id` | BIGINT/FK | Yes |
| FRM-005 | رقم النموذج الورقي | `paper_form_no` | VARCHAR(50) | No |
| FRM-006 | عدد الصفحات | `page_count` | SMALLINT | No |
| FRM-007 | الملف الأصلي | `source_file` | VARCHAR(500) | No |
| FRM-008 | أدخل بواسطة | `entered_by` | BIGINT/FK | Yes |
| FRM-009 | وقت الإدخال | `entered_at` | TIMESTAMP | Yes |
| FRM-010 | دقق بواسطة | `reviewed_by` | BIGINT/FK | No |
| FRM-011 | وقت التدقيق | `reviewed_at` | TIMESTAMP | No |
| FRM-012 | الحالة | `status` | VARCHAR(30) | Yes |
| FRM-013 | سبب الإرجاع | `return_reason` | TEXT | No |

---

# 28. Form Workflow Statuses

Initial workflow:

```text
DRAFT
   ↓
DATA_ENTRY_COMPLETED
   ↓
UNDER_REVIEW
   ├── RETURNED_FOR_CORRECTION
   │          ↓
   │      CORRECTED
   │          ↓
   └──── UNDER_REVIEW
              ↓
           VERIFIED
              ↓
           APPROVED
```

Detailed transition rules belong in:

`05-WORKFLOWS.md`

---

# 29. Users

**Entity:** User  
**Table:** `users`

The detailed authentication schema is defined in the database and permissions documentation.

Core fields:

| Code | Arabic Name | Database Field | Type |
|---|---|---|---|
| USR-001 | المعرف | `id` | BIGINT |
| USR-002 | الاسم | `name` | VARCHAR |
| USR-003 | البريد الإلكتروني | `email` | VARCHAR |
| USR-004 | الهاتف | `mobile` | VARCHAR |
| USR-005 | كلمة المرور | `password` | HASH |
| USR-006 | فعال | `is_active` | BOOLEAN |
| USR-007 | آخر دخول | `last_login_at` | TIMESTAMP |

Passwords MUST NEVER be stored as plain text.

---

# 30. Roles

Initial roles:

| Code | Role |
|---|---|
| R01 | Super Admin |
| R02 | Administrator |
| R03 | Data Entry |
| R04 | Reviewer |
| R05 | Social Worker / Researcher |
| R06 | Reports Viewer |

Detailed permissions belong in:

`06-PERMISSIONS.md`

---

# 31. Audit Logs

**Entity:** Audit Log  
**Table:** `audit_logs`

Critical data changes must be auditable.

| Code | Field | Type |
|---|---|---|
| AUD-001 | `id` | BIGINT |
| AUD-002 | `actor_id` | FK |
| AUD-003 | `event` | VARCHAR |
| AUD-004 | `entity_type` | VARCHAR |
| AUD-005 | `entity_id` | BIGINT |
| AUD-006 | `old_values` | JSON |
| AUD-007 | `new_values` | JSON |
| AUD-008 | `ip_address` | VARCHAR |
| AUD-009 | `user_agent` | TEXT |
| AUD-010 | `created_at` | TIMESTAMP |

Audit records must not be editable by normal users.

---

# 32. National ID Data Rules

`national_id` is classified as Restricted.

### DD-ID-01

National IDs MUST be stored as strings.

```text
VARCHAR
```

Not:

```text
INTEGER
BIGINT
```

### DD-ID-02

Leading zeros must be preserved.

### DD-ID-03

Whitespace should be normalized before validation.

### DD-ID-04

Duplicate detection must run before creating a new person when a National ID is supplied.

### DD-ID-05

Duplicate records MUST NOT be automatically merged.

### DD-ID-06

Potential duplicate resolution requires an authorized user.

### DD-ID-07

National IDs should be masked when the user does not have permission to view the full value.

Example:

```text
804****32
```

---

# 33. Duplicate Detection

Duplicate detection is separate from field validation.

Potential duplicate classifications:

```text
EXACT
PROBABLE
POSSIBLE
```

## EXACT

Example:

```text
Same National ID
```

## PROBABLE

Example:

```text
Same full name
+
Same birth date
+
Same gender
```

## POSSIBLE

Example:

```text
Similar name
+
Same family/context
+
Similar birth date or age
```

The system may alert the user.

The system MUST NOT automatically merge persons.

---

# 34. Derived Data

The following values should normally NOT be persisted as canonical fields:

```text
age
family_size
number_of_children
number_of_males
number_of_females
number_under_1
number_under_2
number_under_5
number_of_elderly
number_of_disabled_persons
number_with_chronic_disease
```

Examples:

Age:

```text
current_date - birth_date
```

Family size:

```text
COUNT(active persons belonging to family)
```

This prevents inconsistencies between stored totals and actual records.

---

# 35. Data Classification

Famboook uses three initial data classifications.

## Restricted

Highly sensitive data.

Examples:

```text
national_id
health information
disability information
uploaded identity documents
confidential case notes
```

Access requires explicit permission.

---

## Internal

Operational personal information.

Examples:

```text
mobile
alternate_mobile
address
family relationships
assistance history
employment information
```

---

## Operational

General system metadata.

Examples:

```text
family_code
person_code
workflow_status
registration_date
created_at
```

---

# 36. Common Metadata

Where appropriate, operational entities should include:

```text
id
created_at
updated_at
created_by
updated_by
```

Entities requiring logical deletion may include:

```text
deleted_at
```

The physical database policy for soft deletes will be defined in:

`04-DATABASE.md`

---

# 37. Reference / Lookup Tables

Current expected lookup tables include:

```text
relationship_types
marital_statuses
governorates
localities
housing_types
tenure_types
housing_condition_types
health_condition_types
disability_types
education_levels
education_statuses
employment_statuses
employment_sectors
document_types
need_types
assistance_types
note_types
assessment_types
form_types
```

Lookup values should not be unnecessarily hard-coded into application logic.

---

# 38. Conditional Fields

Some fields are required only when another condition is true.

Example:

```text
is_displaced = true
→ displacement_location may become required
```

Example:

```text
uses_assistive_device = true
→ assistive_device becomes applicable
```

Example:

```text
has_disability = true
→ one or more person_disabilities records should exist
```

Detailed rules belong in:

`03-BUSINESS-RULES.md`

---

# 39. Source Form Mapping

The initial paper form maps conceptually to Famboook as follows:

| Paper Form Section | Famboook Entity |
|---|---|
| Family identification | `families` |
| Household head | `persons` |
| Spouse(s) | `persons` |
| Family members | `persons` |
| Additional family members | `persons` |
| Relationship to head | `relationship_types` |
| Housing / displacement | `family_residences` |
| Health information | `person_health_profiles` |
| Health conditions | `person_health_conditions` |
| Disability | `person_disabilities` |
| Education | `person_education` |
| Employment | `person_employment` |
| Documents | `documents` |
| Additional person information | `person_notes` |
| Researcher notes | `case_notes` |
| Paper form metadata | `form_submissions` |
| Data collection / verification | `assessments` |

The number of rows printed on the paper form does NOT define the maximum number of digital records.

---

# 40. Core Entity Inventory

The current V1 core model consists of:

```text
families
persons
person_relationships

family_residences

person_health_profiles
person_health_conditions
person_disabilities

person_education
person_employment

documents

family_needs
assistance_records

person_notes
case_notes

assessments
form_submissions

users
roles
permissions

audit_logs

reference/lookup tables
```

---

# 41. Logical Relationship Overview

```text
                           FAMILY
                              │
          ┌───────────────────┼───────────────────┐
          │                   │                   │
          ▼                   ▼                   ▼
       PERSONS           RESIDENCES          ASSESSMENTS
          │                                       │
          │                                       ▼
          │                                FORM SUBMISSIONS
          │
   ┌──────┼─────────┬──────────┬───────────┐
   │      │         │          │           │
   ▼      ▼         ▼          ▼           ▼
 HEALTH DISABILITY EDUCATION EMPLOYMENT DOCUMENTS
   │
   ▼
HEALTH CONDITIONS


FAMILY
   │
   ├──────── NEEDS
   ├──────── ASSISTANCE
   ├──────── DOCUMENTS
   └──────── CASE NOTES


PERSON
   │
   ├──────── PERSON RELATIONSHIPS
   ├──────── PERSON NOTES
   └──────── DOCUMENTS
```

---

# 42. Pending Verification

The following items must be verified against the final approved source form before database implementation is frozen:

1. Exact wording of all checkbox options on the paper form.
2. Final housing-type values.
3. Final housing-condition values.
4. Final tenure values.
5. Exact education categories.
6. Exact employment categories.
7. Exact health-condition categories.
8. Exact disability classification.
9. Exact document checklist.
10. Any additional fields whose labels are not fully legible in the current scanned form.
11. Which extended fields are operational requirements versus future system enhancements.

No uncertain source-form value should be invented merely to complete the database schema.

---

# 43. V1 Architectural Decisions

The following decisions are considered approved for Data Dictionary V1.

### ADR-DD-01
A person is an independent entity, not a numbered column or fixed row inside a family record.

### ADR-DD-02
Family and Person have separate permanent identifiers.

### ADR-DD-03
Paper forms and assessments are separate from permanent family/person records.

### ADR-DD-04
The digital system has no fixed maximum number of family members based on paper-form rows.

### ADR-DD-05
Health conditions, disabilities, needs and assistance are repeatable records rather than fixed columns.

### ADR-DD-06
Derived statistics are calculated rather than redundantly stored.

### ADR-DD-07
Potential duplicate persons are reviewed; they are never automatically merged.

### ADR-DD-08
Historical information should be preserved where operationally relevant.

### ADR-DD-09
Sensitive data must be permission-controlled.

### ADR-DD-10
Lookup/reference values should be configurable where practical.

---

# 44. Document Status

```text
Document: Data Dictionary
Project: Famboook
Version: 1.0
Status: APPROVED
```

This document establishes the baseline data model for Famboook.

Changes that affect entity meaning, identifiers, relationships or canonical fields should be documented and versioned.

---

# 45. Change Log

| Version | Date | Status | Description |
|---|---|---|---|
| 1.0 | 2026-09-22 | Approved | Initial Famboook Data Dictionary baseline |

---

# 46. Next Documents

After approval of this document, continue with:

```text
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

Database migrations should not be treated as the source of truth until the relevant documentation has been reviewed and approved.