# FB-LEGAL-01O — Independent Arabic Language Review: Checklist

| | |
|---|---|
| Text under review | `docs/legal/drafts/TERMS.ar.md` at `0b950e2`, lines 13–68 |
| Expected SHA-256 | `8932fc9a55befd7f718200415fc1f7828db3867da534a918fc62ce258e4e2b2c` |
| Brief | `INDEPENDENT-ARABIC-REVIEW-BRIEF.md` |
| Status of this checklist | **BLANK — NOT STARTED.** Every item is unmarked |

**How to mark.** Set the **Result** of each item to exactly one of:
- `PASS`;
- `ISSUE` (record the issue in the results template and give its finding ID);
- `NOT APPLICABLE`;
- `NOT REVIEWED`.

Every item starts as `☐`. No item is pre-marked.

## 1. Document identity and checksum verification

| # | Check | Result | Note / finding ID |
|---|---|---|---|
| 1.1 | The text was obtained with `git show 0b950e2:docs/legal/drafts/TERMS.ar.md` | ☐ | |
| 1.2 | Its SHA-256 equals `8932fc9a55befd7f718200415fc1f7828db3867da534a918fc62ce258e4e2b2c` | ☐ | |
| 1.3 | Review limited to lines 13–68; lines 1–11 (metadata) and `<!-- R-xx -->` markers excluded | ☐ | |
| 1.4 | Reviewer understands each line is a separate plain-text paragraph (no lists, no links) | ☐ | |

## 2. Full-text Arabic language review

| # | Section (line) | Spelling | Grammar | Punctuation | Clarity | Note / finding ID |
|---|---|---|---|---|---|---|
| 2.1 | «نطاق هذه الشروط» (13–16) | ☐ | ☐ | ☐ | ☐ | |
| 2.2 | «الحساب واستخدامه» (18–24) | ☐ | ☐ | ☐ | ☐ | |
| 2.3 | «دقة المعلومات» (26–30) | ☐ | ☐ | ☐ | ☐ | |
| 2.4 | «الاطلاع المصرّح به» (32–35) | ☐ | ☐ | ☐ | ☐ | |
| 2.5 | «مسؤوليات المنسقين» (37–42) | ☐ | ☐ | ☐ | ☐ | |
| 2.6 | «الاستخدام غير المسموح» (44–53) | ☐ | ☐ | ☐ | ☐ | |
| 2.7 | «حدود الخدمة» (55–60) | ☐ | ☐ | ☐ | ☐ | |
| 2.8 | «تحديث هذه الشروط» (62–64) | ☐ | ☐ | ☐ | ☐ | |
| 2.9 | «التواصل» (66–68) | ☐ | ☐ | ☐ | ☐ | |
| 2.10 | Headings: wording and form consistent | ☐ | | | | |
| 2.11 | Pronouns and verbs: consistent second-person address and voice | ☐ | | | | |
| 2.12 | No ambiguous or awkward expressions remain | ☐ | | | | |

## 3. Terminology and consistency

| # | Check | Result | Note / finding ID |
|---|---|---|---|
| 3.1 | Operator: «الجهة المشغّلة» used consistently after its definition (line 15) | ☐ | |
| 3.2 | Staff: «فريق العمل» / «الموظف» / «موظفو الجهة المشغّلة» (see P-03) | ☐ | |
| 3.3 | Family account: «رب الأسرة», «حساب بوابة الأسرة», «بوابة الأسرة» | ☐ | |
| 3.4 | Coordinator: «المنسق», «نطاق تكليفه» / «نطاق التكليف» | ☐ | |
| 3.5 | Identifiers: «رقم الهوية», «رقم الجوال» (see P-06) | ☐ | |
| 3.6 | Credentials: «كلمة المرور», «رمز التحقق» (singular and plural) | ☐ | |
| 3.7 | Verification-code SMS clearly distinct from in-platform notifications (line 60) | ☐ | |
| 3.8 | Availability condition «في حال إتاحة الخدمة» used consistently where applied (see P-05) | ☐ | |
| 3.9 | Internal references consistent (Privacy Policy, line 16; version and effective date, line 64) | ☐ | |

## 4. P-01 … P-11 verification

Confirm that each management disposition is correctly reflected in the text and linguistically sound. Management approval is **not** independent confirmation.

| # | Finding | Location (`0b950e2`) | What to confirm | Result | Note / finding ID |
|---|---|---|---|---|---|
| 4.1 | P-01 | Line 46 | Introductory sentence present once, correct and natural | ☐ | |
| 4.2 | P-02 | Line 16 | Privacy Policy reference conditional («في حال نشرها») and clear | ☐ | |
| 4.3 | P-03 | Line 24 | Keeping both Staff terms is linguistically acceptable | ☐ | |
| 4.4 | P-04 | Line 22 | «إذا اشتبهت في أن شخصًا آخر يستخدم حسابك» correct and natural | ☐ | |
| 4.5 | P-05 | Lines 22, 29, 60 | Conditional wording consistent and grammatical | ☐ | |
| 4.6 | P-06 | Line 41 | «لرقم الهوية أو رقم الجوال» applied | ☐ | |
| 4.7 | P-07 | Line 60 | SMS sentence clear; «فقط» **not** added | ☐ | |
| 4.8 | P-08 | Line 64 | «رقم إصدارها» acceptable for numeric `MAJOR.MINOR` versions | ☐ | |
| 4.9 | P-09 | Line 42 | «أو إضافة فرد إلى أسرة» correct | ☐ | |
| 4.10 | P-10 | Line 68 | Full stop after the plain-text email reads correctly in RTL | ☐ | |
| 4.11 | P-11 | Whole text | Assessed in section 5 below | ☐ | |

## 5. P-11 diacritics assessment

| # | Check | Result | Note / finding ID |
|---|---|---|---|
| 5.1 | Decide whether diacritics are needed at all, and under which convention (for example only where needed to avoid ambiguity) | ☐ | |
| 5.2 | Existing diacritics checked for consistency (for example «يُفعَّل», «تُنظَّم», «المحدَّد», «مضلِّلة», «وتُبيِّن», «يُعدّ») | ☐ | |
| 5.3 | Exact words needing adjustment listed (or "none") | ☐ | |
| 5.4 | For each proposed adjustment, whether it could affect meaning | ☐ | |
| 5.5 | Proposed adjustments do not alter approved P-01 / P-02 wording without flagging it | ☐ | |

## 6. Potential meaning-changing edits

| # | Check | Result | Note / finding ID |
|---|---|---|---|
| 6.1 | Every proposed edit classified for possible change of legal meaning | ☐ | |
| 6.2 | No proposed edit weakens the password / verification-code safety wording (lines 21–24) | ☐ | |
| 6.3 | No proposed edit changes sanctions (line 53), service limits (line 59) or the no-consent statement (line 16) without flagging it as SUBSTANTIVE_OR_POLICY | ☐ | |
| 6.4 | No proposed edit adds consent, acceptance, guarantees or capabilities | ☐ | |
| 6.5 | Edits that may change meaning are marked for management decision, not presented as editorial | ☐ | |

## 7. Final reviewer declaration

| # | Item | Result |
|---|---|---|
| 7.1 | All sections 1–6 completed (no `☐` left) or the review is marked incomplete | ☐ |
| 7.2 | All issues recorded in the results template with exact current and proposed wording | ☐ |
| 7.3 | The approved text was not modified by the reviewer | ☐ |
| 7.4 | Reviewer independence declaration completed in the results template | ☐ |
| 7.5 | Reviewer understands the review does not authorize publication | ☐ |
