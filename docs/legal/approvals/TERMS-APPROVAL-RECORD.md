# Terms of Use (`/terms`) — Management Approval Record

| | |
|---|---|
| Status | **NOT COMPLETED.** Only the approval-authority designation (FB-LEGAL-01H) and the initial indexing setting are recorded as approved. Every other field stays PENDING until the responsible person fills it in. This record grants no text approval or publication authorization by existing |
| Document | `docs/legal/drafts/TERMS.ar.md` |
| Text baseline | `c937176` (FB-LEGAL-01G.4: final wording and this template) |
| Approval authority | المدير التنفيذي للمركز الفلسطيني للبيانات والتنمية المجتمعية — Executive Director, PCDCD |
| Authority designation status | **APPROVED** (FB-LEGAL-01H, `docs/decisions/FB-LEGAL-01-DECISIONS.md` §1.1). A role designation only: it is **not** the text approval, names no individual and carries no signature or date |
| Model | Informational usage rules; no mandatory electronic acceptance (FB-LEGAL-01 D7, APPROVED) |
| Platform / operator | Famboook / المركز الفلسطيني للبيانات والتنمية المجتمعية (PCDCD), «الجهة المشغّلة» |
| Contact in the text | `info@pcdcd.org` |
| References | `docs/decisions/FB-LEGAL-01-DECISIONS.md`; `docs/legal/drafts/FB-LEGAL-01C3-REVIEW.md` §4.5, §4.6 |

**Text approval does not authorize publication.** Part 4 is a separate act.

## 1. Text approval

| Field | Value |
|---|---|
| Final text approval (by the Executive Director, PCDCD) | PENDING (approved / approved with conditions / not approved) |
| Named approver (name of the Executive Director at the time of approval) | PENDING |
| Text approval date | PENDING |
| Conditions attached to the text approval | PENDING (none / list) |
| Approved text = Git baseline above, unchanged | PENDING (yes / no; if no, list the changes and record a new baseline) |
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
