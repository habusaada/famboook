# FB-LEGAL-01M — Terms Language Findings P-03 … P-11: Consolidated Decision Matrix

| | |
|---|---|
| Reference | FB-LEGAL-01M; findings P-03 … P-11 of `docs/legal/reviews/TERMS-AR-LANGUAGE-REVIEW.md` §2 |
| Current approved Terms baseline | `2e0c99d`, SHA-256 `bc1bf806aaa1809872e59658c9701aeb208981c63e202f3c8862b4bfaf4a6aa6` (verified from the Git-stored file; the working copy is identical) |
| Governance-record commit | `960d4fe` |
| Out of scope | P-01 and P-02, which are already approved and applied |
| Management approval | **APPROVED** (FB-LEGAL-01M-APPROVAL, see §0). Originally PENDING_EXECUTIVE_DIRECTOR_APPROVAL |
| Application status | **APPLIED LOCALLY** (FB-LEGAL-01M.1). Originally NOT APPLIED |
| Independent review | **PENDING**. The findings below are AI-assisted preliminary observations; none is independently confirmed |
| Publication authorization | **NOT AUTHORIZED** |

## 0. Management decision (FB-LEGAL-01M.1)

| | |
|---|---|
| Decision ID | **FB-LEGAL-01M-APPROVAL** |
| Approving authority | Executive Director, PCDCD |
| Decision date | 2026-10-22 |
| Evidence | An explicit management decision in the project conversation. No signature or separate approval document |
| Approved exact replacement set | P-04, P-05, P-06, P-07 (**without «فقط»**) and P-09: the five complete sentences in §2, applied once each (the overlaps P-04/P-05 at line 22 and P-05/P-07 at line 60 as single combined sentences) |
| Keep current text | P-03, P-10 |
| Deferred | P-08 (version-format decision), P-11 (independent reviewer) |
| Application | **APPLIED LOCALLY**. All five replacements applied; `git diff 2e0c99d` shows 5 lines replaced, 0 added or removed |
| Previous approved text baseline | `2e0c99d` |
| New Git text baseline | `0b950e2` (`0b950e2f032b5759ad996533c8186ae6b45159f0`, parent `960d4fe`; stored-file SHA-256 verified, FB-LEGAL-01M.3) |
| New Terms SHA-256 | `8932fc9a55befd7f718200415fc1f7828db3867da534a918fc62ce258e4e2b2c` (UTF-8, LF, 7,155 bytes) |
| Operational caveats kept | Production gate and SMS state remain PENDING VERIFICATION. The applied wording is conditional and claims nothing about Production. The «فقط» variant is not approved |
| Independent review | PENDING |
| Publication | NOT AUTHORIZED |

§1–§6 below are kept unchanged as the analysis history. §6 was the pre-decision approval form; §0 records the decision.

**Line numbers.** Line numbers below refer to `TERMS.ar.md` at `2e0c99d`. The review package cites the earlier numbering. The P-01 insertion moved every line from 46 onward down by two, so package lines 46–64 are lines 48–66 here.

**Approval rule.** Any applied edit, however small, changes the approved text. The full Terms text then needs renewed Executive Director approval and a new baseline, replacing `2e0c99d` (`docs/decisions/FB-LEGAL-01-DECISIONS.md` §1.1).

## 1. Decision matrix

| ID | Location and current text | Documented concern | Proposed edit | Keep-current alternative | Benefit | Legal / functional / interpretation impact | Recommendation | Changes legal meaning? | Renewed approval if applied? |
|---|---|---|---|---|---|---|---|---|---|
| **P-03** | «الحساب واستخدامه», line 24: «لا يطلب **موظفو الجهة المشغّلة** كلمات المرور أو رموز التحقق من المستخدمين؛ فلا تُفصح عنهما لأي شخص، حتى لو ادّعى أنه من **فريق العمل**.» | Two terms for Staff in one sentence; elsewhere «فريق العمل» (lines 20, 28, 30, 42) and «الموظف» (34) | None | Keep. The first clause reproduces the separately approved operating rule (FB-LEGAL-01G.1) verbatim; both terms denote the operator's Staff | — | Rewording would make the published sentence diverge from the recorded operating rule. The safety instruction must not be weakened | **KEEP CURRENT TEXT** (management may confirm that the two terms mean the same group) | No | No (no edit) |
| **P-04** | «الحساب واستخدامه», line 22: «إذا اشتبهت في استخدام حسابك من غيرك، …» | «استخدام … من غيرك» is an unidiomatic agent construction | «إذا اشتبهت في أن شخصًا آخر يستخدم حسابك، …» (rest of the sentence unchanged except P-05 below) | Keep: understandable, but stilted | Plainer for household users | None; same condition, same advice | **ACCEPT EDIT** | No | Yes |
| **P-05** | Line 22: «فغيّر كلمة المرور **إن كانت الخدمة متاحة**»; line 29: «عبر بوابة الأسرة **عند إتاحة الخدمة**»; line 60: «… **عند إتاحتها**» | Three forms of one condition | Unify on «**في حال إتاحة الخدمة**»: line 22 → «فغيّر كلمة المرور في حال إتاحة الخدمة»; line 29 → «عبر بوابة الأسرة في حال إتاحة الخدمة»; line 60 → see P-07 | Keep three forms | One condition reads as one rule. «في حال» matches the approved P-02 style («في حال نشرها») | «عند إتاحة» can be read as *when it is made available*, implying the service will come. «في حال» is purely conditional and promises nothing. This reflects actual behaviour: password change and Portal corrections are gated and off by default (`FAMILY_PASSWORD_CHANGE_ENABLED`, `CHANGE_REQUESTS_FAMILY_SUBMISSION_MODE`). Production state is unverified | **ACCEPT EDIT** | Minimal: removes an implied promise, adds no claim. Flagged for management | Yes |
| **P-06** | «مسؤوليات المنسقين», line 41: «لا يكشف المنسق القيمة الكاملة لرقم الهوية **أو الجوال** …» | Elsewhere always «رقم الجوال» | «… لرقم الهوية **أو رقم الجوال** …» | Keep: elliptical but understood | Terminology consistency | None. The coordinator reveal covers both National ID and mobile, each logged (FB-LEGAL-01G.2) | **ACCEPT EDIT** | No | Yes |
| **P-07** | «حدود الخدمة», line 60: «… وتُستخدم الرسائل القصيرة لإرسال رموز التحقق في الخدمات التي تتطلبها، **عند إتاحتها**.» | «إتاحتها» may refer to the codes or the services; the package also floated adding «فقط» | **Recommended (without «فقط»):** «… وتُستخدم الرسائل القصيرة لإرسال رموز التحقق في الخدمات التي تتطلبها، **في حال إتاحة هذه الخدمات**.» | Keep the ambiguity. **Not recommended:** the package variant «أما الرسائل القصيرة فتُستخدم **فقط** …» | Removes the ambiguity; consistent with P-05 | The first sentence of line 60 already says notifications are not sent by SMS. **Verified locally:** user-facing SMS is sent only by `OtpChallenges` (activation, password reset, contact update); `sms:check` and `FamilyAuthCheck` are operator diagnostics. «فقط» would add an absolute claim about Production behaviour, which is **PENDING VERIFICATION** (SMS configuration, FB-LEGAL-01G.2). No SMS notification capability is implied by either wording | **ACCEPT EDIT** (recommended wording). The «فقط» variant is **REQUIRES MANAGEMENT DECISION**, after Production verification | No (recommended wording). The «فقط» variant narrows the statement | Yes |
| **P-08** | «تحديث هذه الشروط», line 64: «… مع **رقم إصدارها** وتاريخ سريانها.» | The proposed identifier pattern `terms-YYYY-MM-DD` is not a number | Conditional on the version decision: if a non-numeric identifier is approved, «مع **رمز إصدارها** وتاريخ سريانها»; if a numeric one, keep «رقم» | Keep until the version format is decided | Wording matches the approved identifier | **No version and no effective date is approved.** Choosing the wording now would pre-empt that decision. No effective date may be invented | **REQUIRES MANAGEMENT DECISION** (decide together with the version approval) | No (terminology only) | Yes, if changed |
| **P-09** | «مسؤوليات المنسقين», line 42: «لا تُطبَّق مقترحات المنسق، مثل طلب تسجيل أسرة **أو إضافة فرد**، …» | «إضافة فرد» is elliptical | «… مثل طلب تسجيل أسرة **أو إضافة فرد إلى أسرة**، …» | Keep: understood from context | Clearer for household users | Accurate. Coordinators can propose `REGISTER_FAMILY` and `ADD_FAMILY_MEMBER` for a family within their scope (`SubmitChangeRequestAction`); both apply only after Staff approval. Coordinator submission is gated (`CHANGE_REQUESTS_COORDINATOR_SUBMISSION_MODE`, default OFF), which the sentence does not contradict: it states a rule, not an availability | **ACCEPT EDIT** | No | Yes |
| **P-10** | «التواصل», line 68: «… راسل الجهة المشغّلة على العنوان info@pcdcd.org.» | Bidirectional-text rendering of the full stop after the Latin email address | None to the text | Keep | — | **Correction to the review package:** P-10 there says the address "is rendered as a link". It is **not**: `legal-page-view.tsx` renders each paragraph as plain text in `<p>`. A clickable link would need an application change, outside this scope (`/contact` already offers the address as a link). The full-stop position must be checked visually at publication | **KEEP CURRENT TEXT**; rendering check **PENDING VERIFICATION** at publication | No | No (no edit) |
| **P-11** | General: partial vocalisation («يُفعَّل»، «تُنظَّم»، «المحدَّد»، «مضلِّلة»، «وتُبيِّن») | Inconsistent diacritics | None now | Keep | — | Vocalisation conventions are the language reviewer's call (checklist items 1 and 11). Wholesale changes risk touching approved wording, including P-02 («وتُبيِّن») | **DEFER TO INDEPENDENT REVIEW** | No | Only if changed |

## 2. Exact proposed wording (recommended batch)

| Line | Current | Proposed | Findings |
|---|---|---|---|
| 22 | إذا اشتبهت في استخدام حسابك من غيرك، فغيّر كلمة المرور إن كانت الخدمة متاحة، وراسل الجهة المشغّلة. | إذا اشتبهت في أن شخصًا آخر يستخدم حسابك، فغيّر كلمة المرور في حال إتاحة الخدمة، وراسل الجهة المشغّلة. | P-04, P-05 |
| 29 | إذا لاحظت خطأً في بيانات أسرتك، فاطلب تصحيحه عبر بوابة الأسرة عند إتاحة الخدمة، أو بمراسلة الجهة المشغّلة. <!-- R-22 --> | إذا لاحظت خطأً في بيانات أسرتك، فاطلب تصحيحه عبر بوابة الأسرة في حال إتاحة الخدمة، أو بمراسلة الجهة المشغّلة. <!-- R-22 --> | P-05 |
| 41 | لا يكشف المنسق القيمة الكاملة لرقم الهوية أو الجوال إلا عند الحاجة الفعلية، علمًا بأن كل كشفٍ كامل يُسجَّل. | لا يكشف المنسق القيمة الكاملة لرقم الهوية أو رقم الجوال إلا عند الحاجة الفعلية، علمًا بأن كل كشفٍ كامل يُسجَّل. | P-06 |
| 42 | لا تُطبَّق مقترحات المنسق، مثل طلب تسجيل أسرة أو إضافة فرد، إلا بعد مراجعة فريق العمل واعتمادها. | لا تُطبَّق مقترحات المنسق، مثل طلب تسجيل أسرة أو إضافة فرد إلى أسرة، إلا بعد مراجعة فريق العمل واعتمادها. | P-09 |
| 60 | تُعرض إشعارات المنصة داخلها فقط، ولا تُرسَل عبر الرسائل القصيرة أو البريد الإلكتروني. وتُستخدم الرسائل القصيرة لإرسال رموز التحقق في الخدمات التي تتطلبها، عند إتاحتها. | تُعرض إشعارات المنصة داخلها فقط، ولا تُرسَل عبر الرسائل القصيرة أو البريد الإلكتروني. وتُستخدم الرسائل القصيرة لإرسال رموز التحقق في الخدمات التي تتطلبها، في حال إتاحة هذه الخدمات. | P-05, P-07 |

**Note on «عند الحاجة الفعلية» (line 41).** It is outside P-05: it is not a service-availability condition. It stays unchanged.

## 3. Recommended batch, deferrals and decisions

| Group | Findings | Effect |
|---|---|---|
| **Recommended low-risk batch** (one approval) | P-04, P-05, P-06, P-07 (without «فقط»), P-09 | 5 lines changed (22, 29, 41, 42, 60); no line added or removed. P-01 and P-02 wording, R-24, R-25, R-26 and the operating rule are untouched |
| **Keep current text** | P-03, P-10 | No edit. P-10 rendering check at publication |
| **Defer to independent review** | P-11 | Reviewer sets the vocalisation convention |
| **Requires management decision** | P-08 (with the version approval); the «فقط» variant of P-07 (after Production SMS verification) | No edit until decided |

## 4. Risks and dependencies

- **Production verification (pending).** The P-05 and P-07 wording is conditional and makes no claim about Production gate or SMS state. That state must still be verified before publication (FB-LEGAL-01G.2 checklist).
- **No new claims.** No edit in the batch:
  - creates consent, a legal basis or acceptance (D3, D7);
  - adds an SMS notification capability;
  - weakens password or verification-code safety language;
  - assigns a version or effective date.
- **One renewed approval.** Applying the batch needs renewed Executive Director approval of the full Terms text, and produces a new baseline replacing `2e0c99d`.
- **Review before re-baseline.** The independent reviewer may still raise further points. Applying the batch first and reviewing the result once avoids a second re-baseline only if the reviewer agrees. Alternatively, management may hold the batch until the independent review and apply everything together.
- **Review package accuracy.** P-10 in the package misstates the rendering (§1). This matrix records the correction; the package itself is unchanged, as instructed.

## 5. Effect on the approved text if the batch is approved

- **Changes:** 5 lines, all editorial or clarifying. No section, provision or obligation is added or removed.
- **Process:**
  1. renewed approval of the full Terms text by the Executive Director;
  2. application in a separate change;
  3. new Git baseline with its SHA-256;
  4. independent review of that text.
- **Publication:** remains NOT AUTHORIZED.

## 6. Management approval (to be completed by management)

| Field | Value |
|---|---|
| Decision | PENDING_EXECUTIVE_DIRECTOR_APPROVAL |
| Approved findings / exact wording | PENDING |
| Rejected or deferred findings | PENDING |
| Decision date | PENDING |
| Application status | NOT APPLIED |
| New Git baseline | PENDING |
| Independent review | PENDING |
| Publication authorization | NOT AUTHORIZED |
