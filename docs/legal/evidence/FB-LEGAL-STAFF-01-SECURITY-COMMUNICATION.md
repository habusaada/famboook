# FB-LEGAL-STAFF-01 — Staff Security Communication and Acknowledgement Record

| | |
|---|---|
| Purpose | Evidence that Staff have received the approved operating rule «لا يطلب موظفو الجهة المشغّلة كلمات المرور أو رموز التحقق من المستخدمين», as required before `/terms` publication (`docs/legal/approvals/TERMS-APPROVAL-RECORD.md` §3) |
| Approved rule | FB-LEGAL-01G.1 (Executive Director, PCDCD). It is an approved rule, **not** evidence that it was communicated (`docs/decisions/FB-LEGAL-01-DECISIONS.md`) |
| Related existing instructions | `docs/09-PILOT-SOP.md`: «Security» (never share passwords or accounts; never write passwords on forms), the controlled-pilot rule (never write a National ID, mobile number, OTP or password in notes, chats or screenshots), «Mobile trust», «Corrections», «Incident». They are unchanged by this document |
| Communication status | **PREPARED — NOT SENT** |
| Incident-report recipient | **Famboook System Administrator** (role approved by FB-LEGAL-STAFF-01.1, see §1a) |
| Official communication channel | **`security@pcdcd.org`**, official institutional email. **APPROVED BY EXECUTIVE DIRECTOR** (FB-LEGAL-STAFF-01.2, see §1b) |
| Mailbox operational verification | **NOT VERIFIED**. Distribution is blocked until it is verified (§2) |
| Staff acknowledgements / evidence verification | **NOT COLLECTED** / **PENDING** |
| Terms approval record (staff evidence) | **PENDING**, unchanged. It changes only after acknowledgements are collected and verified (§4) |

## 1. Staff security notice (Arabic text prepared for distribution)

The wording below is **prepared for management review and distribution**. The first instruction reproduces the approved operating rule verbatim. The remaining instructions restate existing SOP rules and do not add new procedures.

> **تعليمات أمنية لفريق العمل — كلمات المرور ورموز التحقق**
>
> ١. لا يطلب موظفو الجهة المشغّلة كلمات المرور أو رموز التحقق من المستخدمين. لا تطلب من أي رب أسرة أو منسق أو مستخدم آخر كلمة المرور أو رمز التحقق، بأي وسيلة: شفهيًا أو هاتفيًا أو كتابيًا أو عبر تطبيقات المراسلة.
>
> ٢. لا تدوّن كلمات المرور أو رموز التحقق ولا تشاركها، ولا تكتبها في الملاحظات أو المحادثات أو النماذج أو لقطات الشاشة. ولا تشارك حسابك أو كلمة مرورك مع أي شخص.
>
> ٣. إذا احتاج مستخدم إلى المساعدة في حسابه، فاتّبع الإجراءات الرسمية المعتمدة لدعم الحسابات فقط. يستعيد المستخدم كلمة المرور بنفسه عبر المنصة برمز يُرسَل إلى رقم الجوال المسجّل، في حال إتاحة الخدمة. ويُصحَّح رقم الجوال غير الصحيح وفق إجراء التصحيح المعتمد، ويُوثَّق الرقم وفق إجراء توثيق رقم الجوال. ولا تتولَّ الدخول إلى حساب المستخدم بالنيابة عنه.
>
> ٤. أبلغ فورًا عن أي محاولة لطلب كلمات المرور أو رموز التحقق أو إفشائها إلى مدير نظام Famboook عبر البريد الإلكتروني الرسمي security@pcdcd.org، ولا تحاول معالجة الحادث بنفسك.

**Mapping to existing procedures:**

| Instruction | Source |
|---|---|
| 1 | Approved operating rule (FB-LEGAL-01G.1); `TERMS.ar.md` line 24 (baseline `0b950e2`) |
| 2 | `docs/09-PILOT-SOP.md` «Security» and the controlled-pilot note rule (no OTP or password in notes, chats or screenshots) |
| 3 | Family password recovery by code to the registered mobile (gated, default off); `docs/09-PILOT-SOP.md` «Corrections» and «Mobile trust (ADMINISTRATOR / SUPER_ADMIN)» |
| 4 | `docs/09-PILOT-SOP.md` «Incident» |

**Open dependency.**
- **Responsible role:** decided (FB-LEGAL-STAFF-01.1, §1a).
- **Official channel:** `security@pcdcd.org`, decided (FB-LEGAL-STAFF-01.2, §1b).
- **Still open:** the mailbox's operational verification (§2 prerequisite).
- **SOP placeholder:** the SOP's incident contact still reads «**<name / phone to be filled by the owner>**». The SOP is outside this document's scope and is unchanged.

## 1a. Incident-report recipient (FB-LEGAL-STAFF-01.1)

| Field | Value |
|---|---|
| Decision ID | FB-LEGAL-STAFF-01.1 |
| Approved incident-report recipient | **Famboook System Administrator** («مدير نظام Famboook») |
| Responsibility | Receive and coordinate handling of security reports concerning requests for users' passwords, OTP verification codes, or unauthorized disclosure of authentication credentials |
| Approved by | Executive Director — PCDCD |
| Decision status | **APPROVED — RESPONSIBLE ROLE ONLY** |
| Individual in the role | Not identified by this decision |
| Official communication channel | Designated separately by FB-LEGAL-STAFF-01.2 (§1b). Originally recorded here as PENDING MANAGEMENT DESIGNATION |
| Distribution status | **PREPARED — NOT SENT** |
| Staff acknowledgement status | **NOT COLLECTED** |
| Evidence verification status | **PENDING** |
| Publication of `/terms` | Not authorized by this decision |

**Decision note.** The Executive Director approved the Famboook System Administrator as the responsible role for receiving security reports concerning passwords and OTP verification codes. Distribution of the staff security notice remains blocked until an official contact channel is designated.

**Notice change (FB-LEGAL-STAFF-01.1).** Instruction 4 of the notice was updated to name this role. No incident-response workflow, response deadline, escalation procedure or new security policy is introduced.

## 1b. Official security reporting channel (FB-LEGAL-STAFF-01.2)

| Field | Value |
|---|---|
| Decision ID | FB-LEGAL-STAFF-01.2 |
| Responsible role | Famboook System Administrator (FB-LEGAL-STAFF-01.1) |
| Official security reporting channel | **`security@pcdcd.org`** |
| Channel type | Official institutional email |
| Purpose | Receive security reports from Famboook Staff and coordinators concerning requests for user passwords, OTP verification codes, or unauthorized disclosure of authentication credentials |
| Approved by | Executive Director — PCDCD |
| Channel designation | **APPROVED BY EXECUTIVE DIRECTOR** |
| Approval scope | Designation and documentation of the official communication channel only |
| Mailbox operational verification | **NOT VERIFIED** |
| Distribution status | **PREPARED — NOT SENT** |
| Staff acknowledgements | **NOT COLLECTED** |
| Evidence verification | **PENDING** |

**This decision does not confirm:**
- that the mailbox has been created or activated;
- that it can receive incoming messages;
- that the System Administrator has access to it;
- that it has been tested;
- that the notice has been distributed;
- that any staff member has acknowledged it;
- that publication of `/terms` is authorized.

**Notice change (FB-LEGAL-STAFF-01.2).** Instruction 4 now names `security@pcdcd.org` as the reporting channel. Instructions 1–3 are unchanged, and no new procedure or obligation is introduced.

## 2. Distribution (to be completed by management)

| Field | Value |
|---|---|
| Approved for distribution by (name, role) | |
| Approval date | |
| Intended recipients (roles or groups) | |
| Channel(s) (e.g. internal email, signed paper copy, briefing) | |
| Date sent | |
| Official communication channel for the Famboook System Administrator | `security@pcdcd.org` — APPROVED |

**Operational prerequisite (not complete).** Before distributing the staff notice, management must confirm that security@pcdcd.org is operational, receives incoming messages, and is accessible to the designated Famboook System Administrator.

| Prerequisite check | Value |
|---|---|
| Mailbox operational, receives incoming messages, and accessible to the designated System Administrator | ☐ NOT VERIFIED |
| Confirmed by (name, role) / date | |

## 3. Acknowledgement register

One row per staff member. Fill in only from actual acknowledgements, and record each evidence reference (signed sheet ID, email message ID, briefing attendance record).

| # | Staff identifier / name | Role | Communication date | Acknowledgement date | Channel | Evidence reference |
|---|---|---|---|---|---|---|
| 1 | | | | | | |
| 2 | | | | | | |
| 3 | | | | | | |

Add rows as needed. Do not record passwords, codes, National IDs or other personal data beyond what identifies the staff member.

## 4. Status

| Status | Meaning | Current |
|---|---|---|
| PREPARED | Notice and register drafted; nothing sent | **✓ (this document)** |
| SENT | Notice distributed to the intended recipients through the recorded channel and date | ☐ |
| ACKNOWLEDGED | Every intended recipient has acknowledged, with an evidence reference in §3 | ☐ |
| VERIFIED | Management has checked the register against the recipient list and evidence, and recorded the outcome in `TERMS-APPROVAL-RECORD.md` §3 | ☐ |

**Current communication status: PREPARED — NOT SENT.**
- No message has been sent and no recipient is listed.
- Staff acknowledgements: **NOT COLLECTED**.
- Evidence verification: **PENDING**.
- The official channel `security@pcdcd.org` is approved (§1b), but distribution stays blocked until management confirms the mailbox is operational and accessible to the System Administrator (§2 prerequisite).

| Verification (management) | Value |
|---|---|
| Verified by (name, role) | |
| Verification date | |
| Recipients expected / acknowledged | |
| Outcome recorded in the Terms approval record | PENDING |
