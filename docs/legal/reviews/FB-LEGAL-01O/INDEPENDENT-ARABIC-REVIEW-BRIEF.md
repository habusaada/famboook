# FB-LEGAL-01O — Independent Arabic Language Review: Brief

| | |
|---|---|
| Package status | **PREPARED — REVIEW NOT STARTED.** This package prepares the independent review; it neither performs nor certifies it |
| Overall review status | **AWAITING_INDEPENDENT_REVIEW** |
| Companion documents | `INDEPENDENT-ARABIC-REVIEW-CHECKLIST.md`; `INDEPENDENT-ARABIC-REVIEW-RESULTS-TEMPLATE.md` (same folder) |
| Earlier preliminary review | `docs/legal/reviews/TERMS-AR-LANGUAGE-REVIEW.md`. That was an AI-assisted preliminary review and **not** an independent review |

## A. Document identification

| Field | Value |
|---|---|
| Document | Arabic Terms of Use (شروط الاستخدام), page `/terms` |
| Project | Famboook |
| Operating organization | Palestinian Center for Data and Community Development (PCDCD), «الجهة المشغّلة» |
| Source path | `docs/legal/drafts/TERMS.ar.md` |
| Approved text commit | `0b950e2` (`0b950e2f032b5759ad996533c8186ae6b45159f0`) |
| SHA-256 of the approved text | `8932fc9a55befd7f718200415fc1f7828db3867da534a918fc62ce258e4e2b2c` (UTF-8, LF, 7,155 bytes) |
| Latest governance commit | `e09a462` |
| Version | **UNASSIGNED**. First publication target: `1.0` (`docs/legal/governance/LEGAL-DOCUMENT-VERSIONING-POLICY.md`) |
| Effective date | **UNASSIGNED** |
| Publication | **NOT AUTHORIZED** (`/terms` is `PENDING_APPROVAL`) |

**How to obtain and verify the exact text:**

```bash
git show 0b950e2:docs/legal/drafts/TERMS.ar.md > TERMS-0b950e2.md
sha256sum TERMS-0b950e2.md   # must equal 8932fc9a55befd7f718200415fc1f7828db3867da534a918fc62ce258e4e2b2c
```

If the checksum differs, stop and report it. You are not reviewing the approved text.

**What is in scope in the file:**
- **Text to review:** lines **13–68**, the nine `##` sections:
  - «نطاق هذه الشروط» (13);
  - «الحساب واستخدامه» (18);
  - «دقة المعلومات» (26);
  - «الاطلاع المصرّح به» (32);
  - «مسؤوليات المنسقين» (37);
  - «الاستخدام غير المسموح» (44);
  - «حدود الخدمة» (55);
  - «تحديث هذه الشروط» (62);
  - «التواصل» (66).
- **Not published:** lines 1–11, an English metadata table, and the `<!-- R-xx -->` review markers. They are removed on publication and are not part of the text under review.
- **Rendering:** each line under a heading is published as a separate plain-text paragraph (`<p>`). There is no list markup and no links, so an email address appears as plain text.

## B. Review scope

Examine the **complete** Arabic text (lines 13–68) for:
1. spelling and orthography (including hamza, tā' marbūṭa, alif maqṣūra);
2. grammar and sentence structure (agreement, case where vocalised, verb forms);
3. punctuation (Arabic comma «،», semicolon «؛», colon, full stop, guillemets «», parentheses);
4. clarity and readability for household users of mixed reading levels;
5. terminology consistency (one term per concept);
6. pronoun and verb consistency (second-person address, active and passive voice);
7. ambiguous or awkward expressions;
8. consistency of diacritics (finding P-11);
9. formatting and heading consistency;
10. consistency of references within the document (for example the operator, «الجهة المشغّلة», and the Privacy Policy).

**Legal meaning.** Flag any wording where a linguistic change could **materially alter legal meaning**. The reviewer does **not** make a binding legal determination and must not change approved obligations, rights, sanctions or safety rules, even indirectly through wording.

## C. Change-control rules

1. The reviewer **may propose** edits but **must not alter** `TERMS.ar.md` or any approved text.
2. Every proposed change records:
   - location (section and line);
   - the current exact wording;
   - the proposed exact wording;
   - the reason;
   - severity (TYPOGRAPHICAL / LINGUISTIC / CLARITY / SUBSTANTIVE_OR_POLICY);
   - whether meaning may change.
3. Every proposed edit goes to **management review** (Executive Director, PCDCD). An approved edit is applied by the development team in a separate change, re-approved, and produces a new text baseline with a new SHA-256.
4. Findings are recorded in `INDEPENDENT-ARABIC-REVIEW-RESULTS-TEMPLATE.md` (a copy of it, completed by the reviewer).

## D. Prior findings P-01 … P-11 (traceability)

These are management dispositions. **None has independent reviewer confirmation yet**, and the reviewer is asked to confirm or challenge each one.

Line numbers refer to `0b950e2`.

| ID | Location (`0b950e2`) | Subject | Management disposition | Independent confirmation |
|---|---|---|---|---|
| P-01 | «الاستخدام غير المسموح», line 46 | Introductory sentence «يُعدّ من الاستخدام غير المسموح ما يأتي:» | Approved and applied (Option A) | PENDING |
| P-02 | «نطاق هذه الشروط», line 16 | Conditional Privacy Policy reference «… وتُتاح عبر المنصة في حال نشرها.» | Approved and applied (revised Option B) | PENDING |
| P-03 | «الحساب واستخدامه», line 24 | Two Staff terms in one sentence («موظفو الجهة المشغّلة» / «فريق العمل») | KEEP CURRENT TEXT. The sentence reproduces an approved operating rule | PENDING |
| P-04 | Line 22 | «إذا اشتبهت في أن شخصًا آخر يستخدم حسابك» | Approved and applied | PENDING |
| P-05 | Lines 22, 29, 60 | Conditional wording unified as «في حال إتاحة الخدمة» / «في حال إتاحة هذه الخدمات» | Approved and applied (these lines only) | PENDING |
| P-06 | «مسؤوليات المنسقين», line 41 | «لرقم الهوية أو رقم الجوال» | Approved and applied | PENDING |
| P-07 | «حدود الخدمة», line 60 | SMS sentence ending «… في حال إتاحة هذه الخدمات.» | Approved and applied **without adding «فقط»** | PENDING |
| P-08 | «تحديث هذه الشروط», line 64 | «رقم إصدارها» | RESOLVED BY MANAGEMENT — KEEP CURRENT TEXT (numeric `MAJOR.MINOR` versions, FB-LEGAL-01N) | PENDING |
| P-09 | «مسؤوليات المنسقين», line 42 | «أو إضافة فرد إلى أسرة» | Approved and applied | PENDING |
| P-10 | «التواصل», line 68 | Full stop after «info@pcdcd.org» in right-to-left text | KEEP CURRENT TEXT (rendered as plain text, not a link) | PENDING |
| P-11 | Whole text | Partial and inconsistent diacritics | **DEFERRED TO INDEPENDENT REVIEWER** | PENDING (reviewer to assess) |

**Sources:**
- the decision matrix `docs/legal/proposals/FB-LEGAL-01M-TERMS-LANGUAGE-DECISION-MATRIX.md`;
- the P-01 and P-02 proposals in `docs/legal/proposals/`;
- `docs/legal/approvals/TERMS-APPROVAL-RECORD.md`.

## E. Separation of responsibilities

| Role | Responsibility | Not responsible for |
|---|---|---|
| Executive Director, PCDCD | Management approval (or rejection) of any proposed text change; text approvals | Linguistic certification |
| Independent reviewer | Linguistic assessment, findings and recommendations; confirmation or challenge of P-01 … P-11 | Approving text changes, legal determinations, or publication |
| Development team | Implements **only** approved changes; records baselines and checksums | Choosing wording |
| Publication authority | Separate publication authorization (version, effective date, registry, deployment) | — |

**Completing the independent review does not authorize publication**, and the reviewer is not represented as having approved publication.

## F. Status that this package does not change

- `/terms`, `/privacy`, `/data-protection`: `PENDING_APPROVAL`.
- Independent review: AWAITING_INDEPENDENT_REVIEW.
- D3: unresolved.
- G-02: OPEN.
- Production feature-switch and SMS checks: PENDING.
- Staff password/OTP communication evidence: PENDING.
- Version and effective date: UNASSIGNED.
- Publication authorization: NOT AUTHORIZED.

## G. ملخّص للمراجع (Arabic summary for the reviewer)

يُطلب من المراجع المستقل تدقيق النص العربي الكامل لشروط الاستخدام (الأسطر 13–68 من الملف في الالتزام `0b950e2`) من حيث الإملاء والنحو وعلامات الترقيم والوضوح واتساق المصطلحات والتشكيل. ولا يُعدّل المراجع النص المعتمد مباشرةً، بل يقترح التعديلات مع ذكر الموضع والنص الحالي والنص المقترح والسبب ودرجة الأهمية، ومع بيان ما إذا كان التعديل قد يغيّر المعنى القانوني. وتخضع جميع المقترحات لاعتماد الإدارة. ولا يُعدّ إتمام المراجعة إذنًا بالنشر.
