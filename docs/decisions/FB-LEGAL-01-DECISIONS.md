# FB-LEGAL-01 — Legal Pages and Release Identity: Decision Register

| | |
|---|---|
| Status | **D1 APPROVED** (2026-10-22). **D2–D9 PENDING** — PCDCD confirmation required |
| Source | `docs/audits/FB-LEGAL-01A-AUDIT.md` (read-only audit, baseline `c5d448c`) |
| Implementation | FB-LEGAL-01B (local, uncommitted at the time of writing): public pages infrastructure, release identity, footer, legal links |
| Rule | No legal text, contact detail, retention period, legal basis, right or security guarantee is published until its decision below is APPROVED and recorded here. Drafts never go into publicly served routes |

---

## 1. Decisions

| # | Decision | Status | Recorded outcome / what is needed |
|---|---|---|---|
| **D1** | Platform identity, operator and release wording | **APPROVED (2026-10-22)** | Famboook remains the platform name and brand. The Palestinian Center for Data and Community Development (PCDCD) is the legal operator and data management authority. No assumption that PCDCD owns Famboook intellectual property. The release designation is **Famboook V1.0**. Footer wording: «Famboook V1.0» · «© 2026 Famboook — جميع الحقوق محفوظة» · «تشغيل وإدارة البيانات: المركز الفلسطيني للبيانات والتنمية المجتمعية (PCDCD)». Existing branding, logo, palette, typography, tokens, layouts and behaviour unchanged |
| **D2** | Official legal identity and contact details | PENDING | PCDCD legal name as registered, registration number, postal address, official email and telephone, the privacy or data-protection contact, and the complaint and incident contact. **Blocks `/contact`** and the contact sections of every policy |
| **D3** | Legal basis for processing | PENDING | Applicable law; the legal basis for each processing purpose; whether any processing relies on consent, and for what. A Terms of Use acceptance is **not** consent to personal-data processing |
| **D4** | Retention and deletion | PENDING | A retention period per data category (audit §4), and whether and how deletion or erasure is offered. Today only the 90-day OTP purge exists; the documented 24-month security-event retention is not implemented (audit H2) |
| **D5** | Hosting and processors | PENDING | Hosting provider and data location; off-server backup provider and location; the processor arrangement with the SMS provider (TweetsMS); any other processor |
| **D6** | Rights of adults and children | PENDING | Household members without their own account (their data is visible to the household head); minors and guardians; how a person asks to access or correct their data; the erasure and objection position |
| **D7** | Terms acknowledgement | PENDING | Whether acceptance is recorded (activation, interstitial, Staff), whether it blocks account use, whether the raw IP is stored, re-acceptance on new versions. **No acknowledgement storage exists or is built** until decided (audit §8) |
| **D8** | Sensitive-data visibility | PENDING | Full mobile numbers for every Staff role with `person.view` (audit H3); household-head visibility of all members' health condition names (audit H4) — keep and disclose, or change |
| **D9** | Indexing and publication | PENDING | Whether search engines may index the legal pages; the public wording of the release line beyond the footer. Until decided every legal page is `noindex, nofollow` |

## 2. Page readiness (FB-LEGAL-01B)

| Route | Technical state | Publication state | Linked? | Blocked by |
|---|---|---|---|---|
| `/about` | Ready | **PUBLISHED** — verified product facts (name, purpose as stated in the application metadata, Staff and Family Portal), the release (Famboook V1.0, Initial Production Release, launch 2026-10-01) and the D1 operator wording only | Yes (footer, Family account, Staff menu) | — (indexing: D9) |
| `/privacy` | Ready (registry entry, renderer, metadata) | **PENDING_APPROVAL** — neutral "not published yet" notice; no policy text, version, effective date or acceptance control | No | D2, D3, D4, D5, D6, D8 |
| `/terms` | Ready | **PENDING_APPROVAL** | No | D2, D3, D7 |
| `/data-protection` | Ready | **PENDING_APPROVAL** | No | D2, D3, D4, D5, D6, D8 |
| `/contact` | Ready | **PENDING_APPROVAL** — no email, telephone or address is invented | No | D2 |

**Publishing a page later** needs only a registry change in `frontend/lib/legal/pages.ts`: status `PUBLISHED`, kind `policy`, the approved `version`, `effectiveDate` and `sections`. The page is then rendered with its version and effective date and appears automatically in `LegalLinks` (footer, Family account page, Staff user menu). No route or component change is needed. Each publication still needs its decision APPROVED here, its content approved by PCDCD, and a recorded publication approval.

## 3. Content requirements per page (for PCDCD drafting — no draft text is kept in the repository)

The coverage matrix in `docs/audits/FB-LEGAL-01A-AUDIT.md` §7 lists every topic, what the repository can truthfully support (✓), where a limitation must be stated (⚠), and what only PCDCD can supply. Approved texts must not claim:
- retention, erasure or audited Staff access that is not implemented;
- legal compliance or security certification;
- contact details, registration numbers or agreements not confirmed under D2 / D5.

## 4. Change log

| Date | Change |
|---|---|
| 2026-10-22 | Register created. D1 APPROVED; D2–D9 PENDING. FB-LEGAL-01B implemented locally: `/about` published; `/privacy`, `/terms`, `/data-protection`, `/contact` pending and unlinked |
