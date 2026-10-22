# Terms of Use (`/terms`) — Arabic Language Review Package

| | |
|---|---|
| Document | شروط الاستخدام — Terms of Use (`/terms`) |
| Path | `docs/legal/drafts/TERMS.ar.md` |
| Approved text baseline | `c937176` (original). **Amended text:** `c937176` + the approved P-02 replacement (FB-LEGAL-01K.1), SHA-256 `d98b948810f645fdb38344312d6bb86c8a78f034d94c5da775f29b5ccea74f7e`; Git baseline **`4da00de`** (history). **Current amended text:** `4da00de` + the approved P-01 insertion (FB-LEGAL-01L.1), SHA-256 `bc1bf806aaa1809872e59658c9701aeb208981c63e202f3c8862b4bfaf4a6aa6`; Git baseline **`2e0c99d`**. **The independent review must examine the current amended text** |
| Management approval commit | `27b7041` (TERMS-FINAL-TEXT-APPROVAL, Executive Director, PCDCD) |
| Approval decision date | 2026-10-22 |
| Review status | **AWAITING_INDEPENDENT_REVIEW** |
| Independent reviewer name | PENDING |
| Reviewer affiliation / role | PENDING |
| Review date | PENDING |
| Review outcome | PENDING |

**Governance status (unchanged by this package):**
- final text approval: APPROVED;
- independent Arabic language review: PENDING;
- G-02: OPEN;
- Production verification: PENDING;
- publication: NOT AUTHORIZED;
- initial indexing: `noindex`;
- version and effective date: PENDING.

**Change rule.** The currently approved text is fixed at `2e0c99d` (P-01 amended; SHA-256 `bc1bf806aaa1809872e59658c9701aeb208981c63e202f3c8862b4bfaf4a6aa6`). The earlier approved baselines were `4da00de` (P-02) and `c937176` (original). Any correction, however small, changes the approved text. It must be re-approved by the Executive Director, PCDCD, and recorded against a new baseline before publication (`docs/decisions/FB-LEGAL-01-DECISIONS.md` §1.1).

**Format note for the reviewer.** Each line under a `##` heading is published as a **separate paragraph**, not as a list item (`LegalSection.paragraphs`). The `<!-- R-xx -->` markers are internal and are removed on publication; they are not part of the text under review.

## 1. Editorial checklist

| # | Check | Reviewer result |
|---|---|---|
| 1 | Arabic spelling and grammar (agreement, case endings where vocalised, hamza, ta marbuta) | PENDING |
| 2 | Terminology consistency (one term per concept throughout) | PENDING |
| 3 | Clear, accessible language for household users of mixed reading levels | PENDING |
| 4 | Consistent description of the Family account (رب الأسرة، حساب بوابة الأسرة) and of the coordinator (المنسق، نطاق التكليف) | PENDING |
| 5 | Consistent terms for National ID (رقم الهوية), mobile number (رقم الجوال), verification code (رمز التحقق) and password (كلمة المرور) | PENDING |
| 6 | Clear distinction between verification-code SMS messages and in-platform notifications | PENDING |
| 7 | Sanctions (R-24) and service-limit (R-25) wording consistent in tone and terms with the rest of the text | PENDING |
| 8 | No wording implying mandatory electronic acceptance (D7) | PENDING |
| 9 | No invented consent, legal guarantee or operational capability | PENDING |
| 10 | RTL punctuation, Arabic comma and semicolon, guillemets «», parentheses and mixed Latin text (Famboook, PCDCD, email address) | PENDING |
| 11 | Numbering and digits (none expected in the text) | PENDING |

## 2. Preliminary findings

> **AI-ASSISTED PRELIMINARY REVIEW — NOT THE INDEPENDENT REVIEW REQUIRED FOR PUBLICATION.**
> Prepared during FB-LEGAL-01J to help the independent reviewer. It does not satisfy the language-review requirement, approves nothing, and applies no change to `TERMS.ar.md`. Every suggestion is optional until the independent reviewer and management decide.

**Severity legend:**
- **TYPOGRAPHICAL:** spelling, diacritics or punctuation.
- **LINGUISTIC:** grammar, idiom or terminology.
- **CLARITY:** comprehension for users.
- **SUBSTANTIVE_OR_POLICY:** affects meaning or a governance position.

**Re-approval:** under the change rule above, **every** applied change needs renewed management approval. The last column says whether the change also alters meaning, and so needs more than an editorial confirmation.

| # | Section (line) | Class | Existing wording | Suggested wording | Reason | Renewed approval |
|---|---|---|---|---|---|---|
| P-01 | الاستخدام غير المسموح (46–50) | CLARITY | The five prohibitions start directly with nouns: «انتحال شخصية غيرك، …» … «أي استخدام يعطّل …» | Add a lead paragraph before them: «يُعدّ من الاستخدام غير المسموح ما يأتي:» | Each line renders as a separate paragraph, not a bullet. Without a lead-in, noun phrases such as «جمع البيانات من المنصة بوسائل آلية» read as fragments, or even as permitted activities | Yes. Editorial; the meaning is unchanged. **Preliminary finding:** addressed through approved Option A (FB-LEGAL-01L.1; Option B not approved). **Management approval:** APPROVED (2026-10-22, Executive Director, PCDCD). **Application:** APPLIED LOCALLY (Git baseline `2e0c99d`). **Independent reviewer confirmation:** PENDING |
| P-02 | نطاق هذه الشروط (16) | SUBSTANTIVE_OR_POLICY | «إذ تُنظَّم معالجة البيانات في سياسة الخصوصية.» | Option A: keep it, and publish `/terms` only together with or after `/privacy`. Option B: «إذ تُنظَّم معالجة البيانات في سياسة الخصوصية عند نشرها.» | The text refers to a Privacy Policy that is not published and is NEEDS_DECISION. Published alone, the reference points to a page that shows "not published yet" | Yes. **Management direction:** APPROVED (FB-LEGAL-01K). **Exact replacement:** APPROVED (FB-LEGAL-01K.1, 2026-10-22, Executive Director, PCDCD), being the revised Option B «… وتُتاح عبر المنصة في حال نشرها.» (proposal §0). **Application:** APPLIED LOCALLY. **Independent Arabic review:** PENDING. **Review of the amended text:** REQUIRED |
| P-03 | الحساب واستخدامه (24) and throughout | LINGUISTIC | «لا يطلب موظفو الجهة المشغّلة … حتى لو ادّعى أنه من فريق العمل» | Keep the approved operating-rule wording, and confirm that «موظفو الجهة المشغّلة» and «فريق العمل» mean the same group | Two terms for Staff within one sentence. Elsewhere the text uses «فريق العمل» (lines 20, 28, 30, 42) and «الموظف» (34) | Only if a term is changed; the sentence reproduces an approved operating rule |
| P-04 | الحساب واستخدامه (22) | LINGUISTIC | «إذا اشتبهت في استخدام حسابك من غيرك» | «إذا اشتبهت في أن شخصًا آخر يستخدم حسابك» | «استخدام … من غيرك» is an unidiomatic agent construction; the suggestion is plainer for household users | Yes. Editorial |
| P-05 | الحساب واستخدامه (22); دقة المعلومات (29); حدود الخدمة (58) | CLARITY | «إن كانت الخدمة متاحة» / «عند إتاحة الخدمة» / «عند إتاحتها» | One form throughout, e.g. «عند إتاحة الخدمة» | Three variants of the same condition; one form reads as one rule | Yes. Editorial |
| P-06 | مسؤوليات المنسقين (41) | LINGUISTIC | «لرقم الهوية أو الجوال» | «لرقم الهوية أو رقم الجوال» | Terminology consistency: the text otherwise always says «رقم الجوال» (lines 20, and the privacy policy) | Yes. Editorial |
| P-07 | حدود الخدمة (58) | CLARITY | «وتُستخدم الرسائل القصيرة لإرسال رموز التحقق في الخدمات التي تتطلبها، عند إتاحتها.» | «أما الرسائل القصيرة فتُستخدم فقط لإرسال رموز التحقق في الخدمات التي تتطلبها، عند إتاحة هذه الخدمات.» | «إتاحتها» can refer to the codes or the services. «فقط» makes the SMS/notification distinction explicit (check 6) | Yes. Adding «فقط» narrows the statement; it is accurate locally (FB-LEGAL-01G.3 §4.6) but should be confirmed |
| P-08 | تحديث هذه الشروط (62) | SUBSTANTIVE_OR_POLICY | «مع رقم إصدارها وتاريخ سريانها» | «مع رمز إصدارها وتاريخ سريانها», or keep «رقم» if the version identifier will be numeric | The proposed identifier `terms-YYYY-MM-DD` is not a number. The wording should match the version format management approves | Yes. Depends on the version decision |
| P-09 | مسؤوليات المنسقين (42) | CLARITY | «مثل طلب تسجيل أسرة أو إضافة فرد» | «مثل طلب تسجيل أسرة أو إضافة فرد إلى أسرة» | «إضافة فرد» alone is elliptical | Yes. Editorial |
| P-10 | نطاق هذه الشروط (15); التواصل (66) | TYPOGRAPHICAL | Latin text inside Arabic: «منصة Famboook», «(PCDCD)», «info@pcdcd.org.» | No text change. At publication, check that the final period after the email address renders on the correct side and is not taken as part of the address (it is rendered as a link) | A bidirectional-text rendering check, not a wording issue | No (rendering check) |
| P-11 | General | TYPOGRAPHICAL | Partial vocalisation: «يُفعَّل», «تُنظَّم», «المحدَّد», «مضلِّلة»; most words unvocalised | No change, or the reviewer sets one convention (vocalise only where needed to disambiguate) | Consistency of diacritics | Only if changed |

**Checks with no finding (preliminary):**
- **D7:** no acceptance or "by using you agree" wording (check 8).
- **Consent:** no consent claim; line 16 explicitly excludes consent.
- **Guarantees:** none, and no immunity or liability clause (check 9, R-25).
- **Sanctions:** permissive and limited to «الصلاحيات والإجراءات المتاحة» (R-24).
- **Password and code terms:** consistent («كلمة المرور»، «رمز التحقق»), plural forms in line 24 only.
- **Grammar:** «فلا تُفصح عنهما» (line 24): the dual pronoun refers to the two kinds named (passwords and codes) and is acceptable.

**Summary:**
- **Counts:** 11 preliminary findings.
  - TYPOGRAPHICAL: 2 (P-10, P-11).
  - LINGUISTIC: 3 (P-03, P-04, P-06).
  - CLARITY: 4 (P-01, P-05, P-07, P-09).
  - SUBSTANTIVE_OR_POLICY: 2 (P-02, P-08).
- **No blocking error** in grammar or meaning was found.
- **Recommended before publication:** P-01 (prohibited-use lead-in) and a decision on P-02 (Privacy Policy reference).

## 3. Independent review record

To be completed **only** by the independent reviewer. Do not prefill.

| Field | Value |
|---|---|
| Reviewer name | PENDING |
| Reviewer role / affiliation | PENDING |
| Independence from the drafting work (yes / no, explain) | PENDING |
| Review date | PENDING |
| Text examined (baseline commit) | PENDING (expected `2e0c99d`, the P-01 amended text, SHA-256 `bc1bf806aaa1809872e59658c9701aeb208981c63e202f3c8862b4bfaf4a6aa6`) |
| Sections examined | PENDING (all / list) |
| Findings and recommendations (own findings; position on P-01 … P-11) | PENDING |
| Outcome | PENDING: `APPROVED_AS_IS` / `APPROVED_WITH_CORRECTIONS` / `REVISION_REQUIRED` |
| Evidence / reference of review confirmation (signed note, email reference, document ID) | PENDING |

**After the review:**
- **APPROVED_AS_IS:** record the outcome in `docs/legal/approvals/TERMS-APPROVAL-RECORD.md` §1b. The text baseline stays `2e0c99d`.
- **APPROVED_WITH_CORRECTIONS or REVISION_REQUIRED:**
  1. apply the corrections in a separate change;
  2. obtain renewed text approval from the Executive Director, PCDCD;
  3. record the new baseline in the approval record.
