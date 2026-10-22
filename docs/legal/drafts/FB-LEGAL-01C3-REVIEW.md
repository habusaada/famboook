# FB-LEGAL-01C.3 — Draft Review Register and Publication Checklist

| | |
|---|---|
| Status | **Internal review document.** The three policy drafts in this folder are NOT approved and NOT published. `/contact` is published (FB-LEGAL-01D) |
| Date | 2026-10-22 (revised FB-LEGAL-01E; decisions recorded FB-LEGAL-01F) |
| Baseline | `1de6a6e` + local, uncommitted FB-LEGAL-01F decision recording |
| Drafts | `PRIVACY.ar.md`, `TERMS.ar.md`, `DATA-PROTECTION.ar.md` (pending); `CONTACT.ar.md` (reference copy of the published page and its launch-day version). `/about` and `/contact` live in `frontend/app/(legal)/` |
| Decisions | `docs/decisions/FB-LEGAL-01-DECISIONS.md`: D1, D7, D10 approved; D2, D4, D6, D8 partially approved; D3, D5, D9 pending. **A recorded management decision is not legal compliance verification and does not authorize publication** |

## 1. Why the drafts are documentation, not code

**Architecture limitation (found 2026-10-22).** The publication registry `frontend/lib/legal/pages.ts` is imported by client components: `components/layout/user-menu.tsx` (`"use client"`), and the footer through `FamilyAuthCard`, `LoginView` and `CredentialVerification`. Its contents are therefore shipped in the public JavaScript bundle. **Any draft text placed in the registry, even under `PENDING_APPROVAL`, would be downloadable by anyone.**

So:
- the policy drafts are kept here, outside every served route;
- the registry holds no content for pending pages, and the publication controls are unchanged.

**Publication path (unchanged architecture):**
1. copy an approved draft into its registry entry (`status: "PUBLISHED"`, `kind: "policy"`, approved `version`, `effectiveDate`, `sections`);
2. remove the review markers;
3. run the tests.

The page then renders with its version and effective date and appears in `LegalLinks` automatically.

If drafts must later live in code before approval (for preview), that needs a separate, server-only module that no client component imports, plus a test that enforces it. It is not built here.

## 2. Publication-readiness checklist

| # | Check | `/privacy` | `/terms` | `/data-protection` | `/contact` |
|---|---|---|---|---|---|
| 1 | Draft complete (all required topics) | ✓ | ✓ | ✓ | ✓ published |
| 2 | Factual review against verified behaviour (FB-LEGAL-01E) | ✓ corrected; R-15 unverified | ✓ corrected | ✓ corrected | ✓ |
| 3 | Contact uses only the active address | ✓ `info@pcdcd.org` | ✓ `info@pcdcd.org` | ✓ `info@pcdcd.org` | ✓ |
| 4 | Wording aligned with recorded decisions (FB-LEGAL-01F) | ✓ D4, D6, D8 | ✓ D7 | ✓ D4, D6 | ✓ |
| 5 | Every PUBLICATION_BLOCKER_OPEN item closed (§3, §4) | ✗ R-02, R-05, R-10, R-12, R-15 | ✓ none open | ✗ R-32, R-33, R-34 | ✓ |
| 6 | NON-BLOCKER items confirmed or accepted by management | ✗ | ✗ R-22 … R-26 | ✗ | ✓ |
| 7 | PCDCD approval of the final text, with a recorded approver and date | ✗ | ✗ | ✗ | ✓ (FB-LEGAL-01D) |
| 8 | Approved `version` identifier and `effectiveDate` | ✗ | ✗ | ✗ | n/a (R-41) |
| 9 | Independent Arabic language review | ✗ | ✗ | ✗ | ✗ |
| 10 | Copied into the registry, markers removed, tests and build green | ✗ | ✗ | ✗ | ✓ |
| 11 | Publication approval (and D9 indexing decision) | ✗ | ✗ | ✗ | ✓ (noindex until D9) |

## 3. Review items

**Class** (severity for publication):
- **BLOCKER:** the statement cannot be published until the decision is taken or the fact is confirmed. Publishing without it would be misleading, or would make a claim PCDCD has not authorised.
- **NON-BLOCKER:** the drafted wording is truthful as it stands. Management confirms it, or accepts it as drafted, at final text approval.

**Status** (FB-LEGAL-01F; an item may carry more than one):
- **MANAGEMENT_DECISION_RECORDED:** a decision in the register covers the item. This alone never closes a blocker.
- **IMPLEMENTATION_OR_VERIFICATION_PENDING:** a fact, procedure or follow-up still has to be verified, formalized or built.
- **PUBLICATION_BLOCKER_OPEN:** the page cannot be published while this stays open.
- **RESOLVED:** nothing further is needed for publication.
- "—": unchanged since FB-LEGAL-01E; confirm at final text approval.

| ID | Page | Claim (as drafted) | Basis in the repository | Needs | Class | Status (FB-LEGAL-01F) |
|---|---|---|---|---|---|---|
| R-01 | privacy | Scope: all personal data processed in the platform | — | PCDCD confirms the policy covers every channel (Staff, Family Portal, Coordinator Space, imports) | NON-BLOCKER | — |
| R-02 | privacy | Health and disability data are processed when recorded by Staff | `person_health_records` (audit §4) | **D3:** legal basis for sensitive health data. D8 concerns who sees the data, not the basis for processing it | BLOCKER | PUBLICATION_BLOCKER_OPEN |
| R-03 | privacy | Security and session records keep the IP address and browser information; passwords and codes are not kept in original form | `auth_security_events` (IP, browser hash); `sessions` (raw IP and user agent); `auth_otp_challenges` (IP) | Retention of IP and session data follows R-10 | NON-BLOCKER | MANAGEMENT_DECISION_RECORDED (D4: no period stated, none invented) |
| R-04 | privacy | Initial rosters are completed with the clan and, when needed, partner institutions, then jointly approved before entry | FB-GOV-01 §2, D10 | **D5 / G-03:** no partner arrangement is recorded; the wording stays conditional («عند الحاجة») | NON-BLOCKER, provided no partner is involved before an arrangement exists | — |
| R-05 | privacy | The legal basis per purpose will be stated in a later version; clan approval of the import file is not individual consent | none (D3 pending); FB-GOV-01 §3, G-01, G-10 | **D3:** legal basis per purpose, including imported records. Publishing a privacy policy without any legal basis is a decision management must take explicitly | BLOCKER | PUBLICATION_BLOCKER_OPEN |
| R-06 | privacy | Coordinators: scope only, masked values, logged full reveals, no health, residence, needs or assistance | Coordinator resources (audit §4) | Confirm; true while the code is unchanged | NON-BLOCKER | — |
| R-07 | privacy | The household head **currently** sees members' recorded health conditions **through the Family Portal**; other members have no own accounts | audit H4 (PDD-018 open); activation is head-only | **D8 (partial):** disclose, as drafted. The text does not call this visibility privacy-approved. Follow-up **F-01** reviews authorization, minimization and visibility; no permission or UI change here. The basis for processing the health data stays under R-02 | was BLOCKER; now NON-BLOCKER for disclosure | MANAGEMENT_DECISION_RECORDED; IMPLEMENTATION_OR_VERIFICATION_PENDING (F-01, not a publication blocker) |
| R-08 | privacy | The SMS provider receives the mobile number and message text only, for activation, password recovery and mobile update | `TweetsSmsSender.php`; OTP purposes | **D5:** whether to name the provider (TweetsMS) and the processor arrangement | NON-BLOCKER (accurate unnamed) | — (D5 pending) |
| R-09 | privacy | Institutional sharing only under an arrangement the operator approves | none recorded | **D5, G-03** | NON-BLOCKER | — (D5 pending) |
| R-10 | privacy, data-protection | Retention periods are not yet approved; until then data, including initial import files, stays in the platform | no purge runs in Production: the OTP purge needs the scheduler, not yet installed (FB-NOTIFY-01 runbook) | **D4 (partial):** the wording is permitted and no period is invented. **Outstanding:** the formal retention and deletion policy (G-04, G-11; no erasure path exists). For `/privacy`, publishing before that policy exists must be explicitly accepted in the publication approval. For `/data-protection`, the limits sentence is permitted by D4 and does not block | BLOCKER (`/privacy` only) | MANAGEMENT_DECISION_RECORDED; IMPLEMENTATION_OR_VERIFICATION_PENDING (formal policy); PUBLICATION_BLOCKER_OPEN (`/privacy`) |
| R-11 | privacy | Portal corrections "when available" | change-request gates default OFF | Confirm the wording matches the Production gate state at publication | NON-BLOCKER | — |
| R-12 | privacy | Privacy questions and correction requests via `info@pcdcd.org`; the operator reviews incoming requests. No response time, deletion outcome or procedure is promised | `info@pcdcd.org` ACTIVE (D2) | **D6 (partial):** the intake channel is approved, and the wording no longer refers to "rules the operator approves", which do not exist yet. **Outstanding:** the internal handling procedure and responsibilities (who reads, assesses, answers and records), and the rights actually offered. A privacy-request channel must not be published before someone is responsible for handling it | BLOCKER | MANAGEMENT_DECISION_RECORDED; IMPLEMENTATION_OR_VERIFICATION_PENDING; PUBLICATION_BLOCKER_OPEN |
| R-13 | privacy | Role-based access, coordinator scope, masking and logged reveals, hashed codes and passwords, "no absolute guarantee" | audit §4 | Confirm. Do not add HTTPS, backup or encryption claims until verified in Production (audit H6) | NON-BLOCKER | — |
| R-14 | privacy | Household data includes children | `persons.birth_date`; no minor or guardian model (PDD-020) | **D6 remainder:** rights and safeguards for children | NON-BLOCKER for disclosure; D6 before any children-specific statement | — (D6 remainder pending) |
| R-15 | privacy | «تُستضاف المنصة وبياناتها لدى مزوّدي خدمات تقنية تتعاقد معهم الجهة المشغّلة» | hosting and backup provider UNKNOWN (CTX-PENDING-001…005); off-server backup is a placeholder. **No evidence of a contract between PCDCD and a hosting provider is held in the repository** | **D5:** the hosting and backup providers, the contractual relationship and the data location. **The sentence is an unverified factual claim:** before publication it must be verified with evidence, or reworded to what is verified | BLOCKER | IMPLEMENTATION_OR_VERIFICATION_PENDING; PUBLICATION_BLOCKER_OPEN |
| R-16 | privacy | The National ID is masked in Staff screens and exported in full only in assistance lists that require a special permission; full reveals are logged in the Family Portal and Coordinator Space | `PersonResource`; `ExportFieldCatalog` (`assistance.export-sensitive`); reveal audit | Confirm. Staff full-mobile visibility stays under the D8 remainder | NON-BLOCKER | — |
| R-17 | privacy | Confirming that a notification was read is not consent to processing | notification acknowledgement (audit M1) | Confirm the wording | NON-BLOCKER | — |
| R-21 | terms | Neutral scope; no "by using you agree"; the terms contain no consent to processing | **D7 APPROVED:** informational publication model | None for this item. No text may claim electronic acceptance; none does | was BLOCKER | MANAGEMENT_DECISION_RECORDED; RESOLVED |
| R-22 | terms | Corrections through the portal "when available", or by email | as R-11 | As R-11 | NON-BLOCKER | — |
| R-23 | terms | The coordinator acts within the council's delegation and the assigned scope | FB-GOV-01 §5, D10 | **G-02:** council legitimacy wording | NON-BLOCKER | — |
| R-24 | terms | **Approved wording (FB-LEGAL-01G.1):** «عند الاستخدام غير المسموح، قد تتخذ الجهة المشغّلة، وفق الصلاحيات والإجراءات المتاحة، تدابير لتقييد الوصول أو تعليق الحساب أو سحب صلاحيات المنسق.» No procedure, notice, appeal or automatic sanction is claimed, and the text does not claim that every suspension is available through an administrative interface | Staff accounts: `ManageStaffUsersAction` (`user.suspend`), enforced on the next request by `EnsureUserIsActive`. Coordinators: `RevokeCoordinatorRoleAction`, `RevokeCoordinatorScopeAction` (routes exist). Family accounts: `SuspendUserPersonLinkAction` / `EndUserPersonLinkAction` exist in the domain, but **no endpoint or screen exposes them**; a Family suspension today needs a technical action | Wording approved by management (FB-LEGAL-01G.1). «وفق الصلاحيات والإجراءات المتاحة» limits the measures to what exists | NON-BLOCKER | MANAGEMENT_DECISION_RECORDED (wording approved) |
| R-25 | terms | No guarantee of uninterrupted availability; temporary stops for maintenance or technical reasons; data shown as recorded. **No liability clause, no exclusion or immunity claim** | — | Management approved keeping the current wording unchanged (FB-LEGAL-01G.1). No blanket liability waiver or legal guarantee. Adding a liability or exclusion clause would need legal review under the applicable law (D3 / G-10) | NON-BLOCKER | MANAGEMENT_DECISION_RECORDED (wording approved) |
| R-26 | terms | **Activation (approved wording, FB-LEGAL-01G.1):** «يُفعّل حساب بوابة الأسرة باستخدام رقم الهوية ورمز التحقق المُرسَل إلى رقم الجوال المسجّل.» It replaces wording that suggested a record is created during authentication. Account is personal; change the password "if the service is available"; the code is valid for a short time and used only in the platform; neither Staff nor the operator need the password or code, so never disclose them, even to someone claiming to be Staff | activation by National ID + SMS code; OTP TTL 300 s, 5 attempts, 3 sends (`config/family_auth.php`); codes and passwords hashed; password reset and change gates default OFF; login rate-limited; Staff tools never ask for a password or code | Wording approved (FB-LEGAL-01G.1). **Operating rule approved:** «لا يطلب موظفو الجهة المشغّلة كلمات المرور أو رموز التحقق من المستخدمين.» This is an approved rule, **not** evidence of staff training or an enforcement procedure. No duration or attempt count is published (configurable) | NON-BLOCKER | MANAGEMENT_DECISION_RECORDED; IMPLEMENTATION_OR_VERIFICATION_PENDING (communicating and enforcing the rule among Staff) |
| R-31 | data-protection | Staff: National ID masked, full export only with special permission; Family Portal and Coordinator Space: masking and logged reveals | audit §4 | **D8 remainder:** confirm the current Staff visibility (full mobiles) is acceptable | NON-BLOCKER (accurate) | — (D8 remainder pending) |
| R-32 | data-protection | Staff and coordinators are bound by confidentiality | no signed undertaking recorded | PCDCD confirms the duty exists (contract, code of conduct, coordinator delegation terms) | BLOCKER | IMPLEMENTATION_OR_VERIFICATION_PENDING; PUBLICATION_BLOCKER_OPEN |
| R-33 | data-protection | Rosters and files handled only through approved channels; never via personal or public messaging | FB-GOV-01 §12, G-08 | **G-08:** approved secure transfer channels must exist before this is stated | BLOCKER | IMPLEMENTATION_OR_VERIFICATION_PENDING; PUBLICATION_BLOCKER_OPEN |
| R-34 | data-protection | Initial files: quality review and joint approval before entry | D10 | Evidence that the joint approval (clan coordinator + Famboook management) was followed for each relevant import, before publishing it as practice | BLOCKER | IMPLEMENTATION_OR_VERIFICATION_PENDING; PUBLICATION_BLOCKER_OPEN |
| R-35 | data-protection | Report privacy concerns to `info@pcdcd.org` | ACTIVE (D2) | **D6 (partial):** the intake channel is approved. Handling responsibilities follow R-12 | NON-BLOCKER | MANAGEMENT_DECISION_RECORDED; IMPLEMENTATION_OR_VERIFICATION_PENDING (handling, via R-12) |
| R-41 | contact | Whether a contact page shows a version and effective date | — | Resolved in FB-LEGAL-01D: an informational page, with no version or effective date | — | RESOLVED |
| R-42 | privacy, terms, data-protection | The drafts named the planned `support@pcdcd.org` | PLANNED, not active (D2, D6) | Resolved in FB-LEGAL-01E: replaced by `info@pcdcd.org`. On launch day, re-evaluate whether policy contacts move to the support address (launch-day checklist §3) | — | RESOLVED |

**Follow-ups that do not block publication:**

| ID | Follow-up | Source | Status |
|---|---|---|---|
| F-01 | Review authorization, minimization and visibility of sensitive information (health) for the household head in the Family Portal. No permission or UI change before a separate decision | D8 (partial), audit H4, PDD-018 | IMPLEMENTATION_OR_VERIFICATION_PENDING |

**Not used anywhere in the drafts:**
- `media@pcdcd.org`, or the planned support address;
- telephone numbers, postal addresses, registration numbers;
- response times, deletion outcomes or guaranteed procedures;
- certifications, compliance claims, encryption-at-rest, HTTPS, backup or audit-coverage guarantees;
- a consent claim (the drafts state twice what is **not** consent: R-05, R-17);
- an acceptance clause, or a claim that users accepted the terms.

## 4. Publication-readiness report

### 4.1 FB-LEGAL-01E changes (committed `1de6a6e`)

**`/privacy`:**
- **Operator separated from platform:** "من نحن" became «عن هذه السياسة»; "we" phrasing became "the operator", defined once as «الجهة المشغّلة».
- **Consent:** clan approval of the import file is not individual consent; confirming a notification is not consent.
- **Accuracy:** children and internal Staff notes disclosed; session records now say what they hold.
- **Hosting:** a hosting-processor statement added (R-15, unverified).
- **Retention:** not yet approved; data retained meanwhile.
- **Removed unsupported claims:** "compliant with applicable rules", "passwords not recorded in system logs".
- **Contact:** `info@pcdcd.org`.

**`/terms`:** operator defined; no consent in the terms; Staff accounts covered; password rule R-26; single `info@pcdcd.org` contact.

**`/data-protection`:** "least privilege" removed; masking made accurate; minimisation softened; `info@pcdcd.org`.

### 4.2 FB-LEGAL-01F alignment (minimal edits)

- **`PRIVACY.ar.md`:**
  - R-07 now states that the head sees the data «حاليًا عبر بوابة الأسرة» (D8).
  - R-12 names `info@pcdcd.org` for privacy questions and correction requests. "Reviewed under the rules the operator approves", which implied an existing procedure, became «وتتولّى الجهة المشغّلة مراجعة الطلبات الواردة إلى هذا العنوان» (D6).
  - Header updated.
- **`TERMS.ar.md`:** header only (D7 approved). The page text already matched the informational model.
- **`DATA-PROTECTION.ar.md`:** unchanged. Its retention and contact wording already matches D4 and D6.

### 4.3 Blocker matrix

| Page | PUBLICATION_BLOCKER_OPEN | Decision or evidence still needed |
|---|---|---|
| `/privacy` | <ul><li>R-02, R-05 (D3)</li><li>R-10 (D4 remainder)</li><li>R-12 (D6 remainder)</li><li>R-15 (D5)</li></ul> | <ul><li>Legal basis for personal and sensitive health data, including imported records.</li><li>A formal retention and deletion policy, or explicit acceptance of publishing without it.</li><li>An internal request-handling procedure and responsible person.</li><li>Hosting and backup providers, contracts, location and sharing arrangements, with evidence for the R-15 sentence.</li></ul> |
| `/terms` | none | Final text approval, including R-24 sanctions, R-25 liability wording and R-26 password guidance |
| `/data-protection` | <ul><li>R-32</li><li>R-33 (G-08)</li><li>R-34</li></ul> | <ul><li>Evidence of the confidentiality duty.</li><li>Approved secure transfer channels.</li><li>Evidence the joint approval was followed for each relevant import.</li></ul> |

**Open, non-blocking:**
- F-01;
- the D8 remainder (Staff full mobiles; R-16, R-31);
- the D6 remainder for children (R-14);
- D5 naming of the SMS provider (R-08, R-09);
- G-02 (R-23);
- an independent Arabic language review of all three pages.

### 4.4 Recommendation

| Page | Recommendation | Reason |
|---|---|---|
| `/privacy` | **NEEDS_DECISION** | Five blockers stay open. D4, D6 and D8 are recorded, but each leaves an outstanding part (R-10, R-12), and D3 and D5 are pending |
| `/terms` | **READY_FOR_FINAL_TEXT_APPROVAL** | Its only blocker (R-21) is resolved by D7. The remaining items are confirmations within the final text approval. **Not PUBLISHED**; publication still needs final text approval, the language review, an approved version and effective date, and a recorded publication approval |
| `/data-protection` | **NEEDS_DECISION** | It states duties, channels and a practice (R-32, R-33, R-34) for which no evidence is recorded |

All three pages stay **PENDING_APPROVAL**, unlinked and not indexed. `frontend/lib/legal/pages.ts` is unchanged.

### 4.5 `/terms` final text review (FB-LEGAL-01G)

**Changes (`TERMS.ar.md`; no other section changed):**
- **R-24:** «قد يؤدي الاستخدام غير المسموح إلى إيقاف الحساب أو تقييد الاطلاع» → «عند الاستخدام غير المسموح، قد تقرّر الجهة المشغّلة إيقاف الحساب، أو سحب صلاحيات المنسق أو نطاق تكليفه، أو تقييد الاطلاع بطريقة أخرى». It names the measures that exist, and the operator's discretion, without a procedure.
- **R-25:** unchanged. It already gives no guarantee and claims no immunity.
- **R-26:** added «رمز التحقق صالح لمدة قصيرة، ولا يُستخدم إلا في المنصة نفسها». The disclosure rule now covers the operator and anyone claiming to be Staff.

**Classification: READY_FOR_MANAGEMENT_APPROVAL**, not published.

**Final approval checklist (`/terms`):**

| # | Item | State |
|---|---|---|
| 1 | D7 informational model; no acceptance clause or claim (R-21) | ✓ |
| 2 | Platform (Famboook) and operator (PCDCD) distinguished; contact `info@pcdcd.org` only | ✓ |
| 3 | R-24 sanctions wording | ✓ approved (FB-LEGAL-01G.1) |
| 4 | R-25: current wording kept, no liability clause | ✓ approved (FB-LEGAL-01G.1) |
| 5 | R-26 activation wording and the operating rule «لا يطلب موظفو الجهة المشغّلة كلمات المرور أو رموز التحقق من المستخدمين» | ✓ approved (FB-LEGAL-01G.1); communication to Staff not yet evidenced |
| 6 | R-22 / R-11: the "when available" wording matches the Production gate state at publication | ☐ at publication |
| 7 | R-23: coordinator delegation verification (G-02) | ☐ open |
| 8 | Independent Arabic language review | ☐ |
| 9 | Approved `version` (proposed pattern `terms-YYYY-MM-DD`) and `effectiveDate`; recorded approver and date | ☐ none assigned |
| 10 | Initial indexing setting | ✓ `noindex` (D9 partial, FB-LEGAL-01G.3) |
| 11 | Separate publication approval (registry change, markers removed, tests green) | ☐ |

### 4.6 `/terms` editorial amendments (FB-LEGAL-01G.3)

These are approved as text decisions, **not** as publication authorization.

1. **Activation:** the two sentences are merged into «حساب بوابة الأسرة مخصّص لرب الأسرة المسجّل، ويُفعَّل باستخدام رقم الهوية ورمز التحقق المُرسَل إلى رقم الجوال المسجّل.»
2. **Password and code (R-26):** «لا يطلب موظفو الجهة المشغّلة كلمات المرور أو رموز التحقق من المستخدمين؛ فلا تُفصح عنهما لأي شخص، حتى لو ادّعى أنه من فريق العمل.» The separate line «الحساب شخصي: لا تشارك كلمة المرور أو رمز التحقق…» is kept.
3. **Notifications:** «تُعرض إشعارات المنصة داخلها فقط، ولا تُرسَل عبر الرسائل القصيرة أو البريد الإلكتروني. وتُستخدم الرسائل القصيرة لإرسال رموز التحقق في الخدمات التي تتطلبها، عند إتاحتها.»
   - **Verified locally:** `SmsDispatcher` is used only by `OtpChallenges`, for activation, password reset and contact update, plus the operator command `sms:check`. The notification module sends no SMS or mail. The OTP services are gated, default OFF.
   - **Not verified:** the Production state.
4. **Unchanged:** the R-24 sanctions sentence, the access-restriction terms (الاطلاع / تقييد الوصول), R-25 and D7.

The approval record template is `docs/legal/approvals/TERMS-APPROVAL-RECORD.md`. G-02 stays open; it must be closed, or explicitly accepted as open, in that record.

### 4.7 `/terms` approval authority (FB-LEGAL-01H)

- **Designated authority:** the Executive Director of PCDCD («المدير التنفيذي للمركز الفلسطيني للبيانات والتنمية المجتمعية») approves the final `/terms` text (decision TERMS-TEXT-APPROVAL-AUTHORITY, APPROVED).
- **Text approval:** still PENDING. The designation is not the approval.
- **Publication:** a separate authorization, still PENDING.
- **Classification:** `/terms` remains **READY_FOR_MANAGEMENT_APPROVAL**, not PUBLISHED.
- **Unchanged:** R-24, R-25 and R-26 are not reopened (no inconsistency found); G-02 stays open.

## 5. Change log

| Date | Change |
|---|---|
| 2026-10-22 | Drafts of the four pending pages and this register created (FB-LEGAL-01C.3). `/about` content extended in code. Nothing published beyond `/about` |
| 2026-10-22 | FB-LEGAL-01D: `/contact` published with `info@pcdcd.org` only; R-41 resolved; R-42 added |
| 2026-10-22 | FB-LEGAL-01E: the three policy drafts reviewed and revised (factual corrections, consent boundaries, operator distinction, language). `support@pcdcd.org` replaced by `info@pcdcd.org` (R-42 resolved). New items R-14 … R-17, R-26; items classified BLOCKER / NON-BLOCKER; readiness report §4 added |
| 2026-10-22 | FB-LEGAL-01F: decisions D7 (approved), D4, D6, D8 (partially approved) recorded. A status column was added (MANAGEMENT_DECISION_RECORDED, IMPLEMENTATION_OR_VERIFICATION_PENDING, PUBLICATION_BLOCKER_OPEN, RESOLVED). R-21 resolved. R-07 no longer blocks (disclosure), and follow-up F-01 was opened. R-10, R-12 stay blocking with narrowed scope. R-15 is flagged as an unverified contractual claim. `/terms` is READY_FOR_FINAL_TEXT_APPROVAL; `/privacy` and `/data-protection` are NEEDS_DECISION. All three remain PENDING_APPROVAL |
| 2026-10-22 | FB-LEGAL-01G: final text review of `/terms`. R-24 (sanctions) reworded to the existing measures at the operator's discretion; R-25 (service limits) unchanged; R-26 (password and code) extended. Final approval checklist §4.5 added. `/terms` is READY_FOR_MANAGEMENT_APPROVAL and remains PENDING_APPROVAL |
| 2026-10-22 | FB-LEGAL-01G.1: management wording applied. R-26 activation sentence; R-24 sanctions sentence «وفق الصلاحيات والإجراءات المتاحة»; R-25 kept unchanged. Operating rule «لا يطلب موظفو الجهة المشغّلة كلمات المرور أو رموز التحقق من المستخدمين» approved (not evidence of training or enforcement). D7 preserved; G-02 open. `/terms` remains PENDING_APPROVAL |
| 2026-10-22 | FB-LEGAL-01G.3: `/terms` editorial amendments applied (§4.6). D9 partially approved (`noindex` initial setting for `/terms`); version pattern `terms-YYYY-MM-DD` proposed, none assigned; approval record template created. G-02 open. `/terms` remains PENDING_APPROVAL |
| 2026-10-22 | FB-LEGAL-01H: the Executive Director of PCDCD designated as the `/terms` text approval authority (§4.7). Text approval and publication authorization stay PENDING. `/terms` remains READY_FOR_MANAGEMENT_APPROVAL and PENDING_APPROVAL |
