# Terms of Use (`/terms`) — Management Approval Record

| | |
|---|---|
| Status | **AMENDED TEXT APPROVED — PUBLICATION NOT AUTHORIZED.** Recorded as approved: the approval-authority designation (FB-LEGAL-01H), the original final administrative text approval (FB-LEGAL-01I, §1), the P-02 amended text approval (FB-LEGAL-01K.1, §1a), the P-01 amended text approval (FB-LEGAL-01L.1, §1c), the consolidated P-03 … P-11 text approval (FB-LEGAL-01M.1, §1d) and the initial indexing setting. Every other field stays PENDING until the responsible person fills it in. This record grants no publication, deployment, indexing or activation authorization |
| Document | `docs/legal/drafts/TERMS.ar.md` |
| Text baseline (original) | `c937176` (FB-LEGAL-01G.4: final wording and this template). Kept as history |
| Current approved text | `2e0c99d` + the five FB-LEGAL-01M line replacements (§1d). SHA-256 `8932fc9a55befd7f718200415fc1f7828db3867da534a918fc62ce258e4e2b2c`; Git baseline **`0b950e2`** (`0b950e2f032b5759ad996533c8186ae6b45159f0`; stored-file SHA-256 verified, FB-LEGAL-01M.3) |
| Previous approved text (P-01) | `4da00de` + the P-01 insertion (§1c). SHA-256 `bc1bf806aaa1809872e59658c9701aeb208981c63e202f3c8862b4bfaf4a6aa6`; Git baseline **`2e0c99d`** (`2e0c99dcb873e9f0a00b11dc81cfb1dcba852837`; stored-file SHA-256 verified, FB-LEGAL-01L.3) |
| Previous approved text (P-02) | `c937176` + the P-02 replacement (§1a). SHA-256 `d98b948810f645fdb38344312d6bb86c8a78f034d94c5da775f29b5ccea74f7e`; Git baseline **`4da00de`** (stored-file SHA-256 verified, FB-LEGAL-01K.3). Kept as history |
| Approval history | `c937176`: original approved text. `27b7041`: original approval-record commit. `4da00de`: approved P-02 amended text. `b602d00`: governance commit registering `4da00de`. `2e0c99d`: approved P-01 amended text. `960d4fe`: governance commit registering `2e0c99d`. `0b950e2`: approved consolidated FB-LEGAL-01M text (current) |
| Approval authority | المدير التنفيذي للمركز الفلسطيني للبيانات والتنمية المجتمعية — Executive Director, PCDCD |
| Authority designation status | **APPROVED** (FB-LEGAL-01H, `docs/decisions/FB-LEGAL-01-DECISIONS.md` §1.1). A role designation only: it is **not** the text approval, names no individual and carries no signature or date |
| Model | Informational usage rules; no mandatory electronic acceptance (FB-LEGAL-01 D7, APPROVED) |
| Platform / operator | Famboook / المركز الفلسطيني للبيانات والتنمية المجتمعية (PCDCD), «الجهة المشغّلة» |
| Contact in the text | `info@pcdcd.org` |
| References | `docs/decisions/FB-LEGAL-01-DECISIONS.md`; `docs/legal/drafts/FB-LEGAL-01C3-REVIEW.md` §4.5, §4.6 |

**Text approval does not authorize publication.** Part 4 is a separate act.

## 1. Text approval (original baseline — historical)

The original approval of `c937176` was recorded at commit `27b7041`. It stays valid for that unchanged text and does not by itself cover the amended text (§1a).

| Field | Value |
|---|---|
| Approval type | Final administrative text approval (FB-LEGAL-01I; decision TERMS-FINAL-TEXT-APPROVAL) |
| Final text approval (by the Executive Director, PCDCD) | **APPROVED** |
| Approving authority | Executive Director — Palestinian Center for Data and Community Development (PCDCD) |
| Approved document | `docs/legal/drafts/TERMS.ar.md` |
| Approved Git baseline | `c937176` |
| Decision date | 2026-10-22 |
| Approval scope | The final Arabic Terms of Use wording, including the previously approved R-24, R-25 and R-26 wording; the informational usage-rules model under D7; no mandatory electronic acceptance in the current phase |
| Approval evidence | An explicit management instruction in the project conversation (FB-LEGAL-01I). **Not** a signed approval, an independently verified legal opinion or a completed legal compliance assessment |
| Named approver | Not recorded. The approval is recorded by role; no personal name was provided, and none is inferred |
| Signature | None. No handwritten or digital signature is recorded |
| Conditions attached to the text approval | None stated in the approval instruction. The exclusions are listed in `docs/decisions/FB-LEGAL-01-DECISIONS.md` §1.1 |
| Approved text = Git baseline above, unchanged | Yes. `TERMS.ar.md` is unchanged between `c937176` and `017c791` (verified with `git diff`) |
## 1a. Amended text approval (P-02)

| Field | Value |
|---|---|
| Decision | **TERMS-P02-AMENDED-TEXT-APPROVAL** (FB-LEGAL-01K.1) |
| Status | **APPROVED** |
| Authority | Executive Director, PCDCD |
| Decision date | 2026-10-22 |
| Scope | The complete previously approved Terms text (`c937176`), with only the expressly approved P-02 replacement in «نطاق هذه الشروط», second paragraph: «لا تتضمن هذه الشروط موافقةً على معالجة البيانات الشخصية. وتُبيِّن سياسة الخصوصية، وهي وثيقة مستقلة عن هذه الشروط، كيفية معالجة البيانات الشخصية، وتُتاح عبر المنصة في حال نشرها.» (revised Option B, `docs/legal/proposals/FB-LEGAL-01K-P02-TERMS-AMENDMENT.md` §0) |
| Evidence | An explicit management instruction in the project conversation (FB-LEGAL-01K.1). Not a signed approval, a legal review or a compliance assessment |
| Named approver / signature | Not recorded / none |
| Additional conditions | None stated |
| Amended file integrity reference | SHA-256 `d98b948810f645fdb38344312d6bb86c8a78f034d94c5da775f29b5ccea74f7e` (UTF-8, LF, 7,026 bytes). Verify after the commit with `git show <commit>:docs/legal/drafts/TERMS.ar.md \| sha256sum`. This is a content reference, not a substitute for the commit hash |
| Difference from `c937176` | Exactly one line (the P-02 sentence); verified with `git diff c937176` |
| New Git baseline | **`4da00de`** (FB-LEGAL-01K.2, parent `27b7041`). Verified in FB-LEGAL-01K.3: `git show 4da00de:docs/legal/drafts/TERMS.ar.md \| sha256sum` equals the integrity reference above, and the only difference from `c937176` is the P-02 line |

## 1c. Amended text approval (P-01)

| Field | Value |
|---|---|
| Decision | **TERMS-P01-AMENDED-TEXT-APPROVAL** (FB-LEGAL-01L.1) |
| Status | **APPROVED** |
| Authority | Executive Director, PCDCD |
| Decision date | 2026-10-22 |
| Evidence | An explicit management decision in the project conversation (FB-LEGAL-01L.1). Not a signed approval, a legal review or a compliance assessment |
| Named approver / signature | Not recorded / none |
| Additional conditions | None stated |
| Scope | The previously approved Terms text at `4da00de`, with only the approved P-01 introductory sentence inserted (Option A, `docs/legal/proposals/FB-LEGAL-01L-P01-TERMS-EDITORIAL-PROPOSAL.md` §0; Option B not approved) |
| Approved wording | «يُعدّ من الاستخدام غير المسموح ما يأتي:» |
| Approved placement | Immediately after the heading «## الاستخدام غير المسموح», before the five existing prohibited-use provisions, as its own paragraph |
| Difference from `4da00de` | 2 lines added (the sentence and its separating blank line), 0 removed; verified with `git diff 4da00de` |
| Amended file integrity reference | SHA-256 `bc1bf806aaa1809872e59658c9701aeb208981c63e202f3c8862b4bfaf4a6aa6` (UTF-8, LF, 7,099 bytes). Verify after the commit with `git show <commit>:docs/legal/drafts/TERMS.ar.md \| sha256sum` |
| New Git text baseline | **`2e0c99d`** (`2e0c99dcb873e9f0a00b11dc81cfb1dcba852837`, FB-LEGAL-01L.2, parent `b602d00`). Verified in FB-LEGAL-01L.3: `git show 2e0c99d:docs/legal/drafts/TERMS.ar.md \| sha256sum` equals the integrity reference above, and the only difference from `4da00de` is the P-01 insertion |
| Publication authorization | **NOT AUTHORIZED**. This is a text approval only |

## 1d. Consolidated text approval (P-03 … P-11)

| Field | Value |
|---|---|
| Decision | **TERMS-P03-P11-CONSOLIDATED-TEXT-APPROVAL** (FB-LEGAL-01M.1; matrix decision FB-LEGAL-01M-APPROVAL) |
| Status | **APPROVED** |
| Authority | Executive Director, PCDCD |
| Decision date | 2026-10-22 |
| Evidence | An explicit management decision in the project conversation (FB-LEGAL-01M.1). Not a signed approval, a legal review or a compliance assessment |
| Named approver / signature | Not recorded / none |
| Additional conditions | None stated |
| Scope | The Terms text at `2e0c99d` with only the five approved line replacements in `docs/legal/proposals/FB-LEGAL-01M-TERMS-LANGUAGE-DECISION-MATRIX.md` §2 |
| Approved edits | **P-04 + P-05** (line 22): «إذا اشتبهت في أن شخصًا آخر يستخدم حسابك، فغيّر كلمة المرور في حال إتاحة الخدمة، وراسل الجهة المشغّلة.» **P-05** (line 29): «… عبر بوابة الأسرة في حال إتاحة الخدمة، …». **P-06** (line 41): «… لرقم الهوية أو رقم الجوال …». **P-09** (line 42): «… أو إضافة فرد إلى أسرة، …». **P-07 + P-05** (line 60): «… في الخدمات التي تتطلبها، في حال إتاحة هذه الخدمات.» (without «فقط») |
| Decisions without edits | P-03, P-10: keep current text. P-08: deferred pending the version-format decision. P-11: deferred to the independent reviewer |
| Difference from `2e0c99d` | Exactly 5 lines replaced, 0 added or removed. Verified with `git diff 2e0c99d`; each new line equals the matrix §2 "Proposed" sentence |
| Amended file integrity reference | SHA-256 `8932fc9a55befd7f718200415fc1f7828db3867da534a918fc62ce258e4e2b2c` (UTF-8, LF, 7,155 bytes). Verify after the commit with `git show <commit>:docs/legal/drafts/TERMS.ar.md \| sha256sum` |
| New Git text baseline | **`0b950e2`** (`0b950e2f032b5759ad996533c8186ae6b45159f0`, FB-LEGAL-01M.2, parent `960d4fe`). Verified in FB-LEGAL-01M.3: `git show 0b950e2:docs/legal/drafts/TERMS.ar.md \| sha256sum` equals the integrity reference above; 5 lines replaced against `2e0c99d`, 0 added or removed |
| Publication authorization | **NOT AUTHORIZED**. This is a text approval only; no version or effective date is assigned |

## 1b. Independent Arabic language review (must cover the current amended text, §1d)

| Field | Value |
|---|---|
| Independent Arabic reviewer (name) | PENDING |
| Arabic review date | PENDING |
| Arabic review outcome | PENDING (no changes / changes applied at commit ____) |

## 2. Version and effective date

| Field | Value |
|---|---|
| Document version | PENDING. Proposed pattern `terms-YYYY-MM-DD`, where the date is the effective date. Approved by the Executive Director when explicitly authorized |
| Effective date | PENDING. The publication date or later, never retroactive. It must equal the `effectiveDate` set in the registry. Approved by the Executive Director when explicitly authorized |

## 3. Conditions before publication

| Condition | Disposition | By / date |
|---|---|---|
| G-02: coordinator delegation (council legitimacy and evidence; R-23) | PENDING (closed / accepted as open, with reason) | PENDING |
| Production configuration verified against the text: `FAMILY_ACTIVATION_ENABLED`, `FAMILY_LOGIN_ENABLED`, `FAMILY_PASSWORD_RESET_ENABLED`, `FAMILY_PASSWORD_CHANGE_ENABLED`, `FAMILY_CONTACT_UPDATE_ENABLED`, `CHANGE_REQUESTS_FAMILY_SUBMISSION_MODE`, `CHANGE_REQUESTS_COORDINATOR_SUBMISSION_MODE`, `NOTIFICATIONS_MODE`, SMS provider configuration | PENDING (record the values observed; "when available" wording confirmed) | PENDING |
| Operating rule «لا يطلب موظفو الجهة المشغّلة كلمات المرور أو رموز التحقق من المستخدمين» communicated to Staff | PENDING (approved as a rule; communication not yet evidenced) | PENDING |
| Initial indexing setting | **`noindex`** (D9 partially approved, FB-LEGAL-01G.3). Future indexing needs a separate decision | — |

## 4. Publication authorization (separate act)

| Field | Value |
|---|---|
| Publication authorized by (name, role) | PENDING |
| Authorization date | PENDING |
| Registry change commit (`frontend/lib/legal/pages.ts`: `PUBLISHED`, `kind: "policy"`, version, effective date, sections; review markers removed; tests green) | PENDING |
| Deployment reference (release, date) | PENDING |
| Post-deployment check (page renders version and effective date; appears in legal links; `noindex` present) | PENDING |
