# FB-LEGAL-01 — Legal Pages and Release Identity: Decision Register

| | |
|---|---|
| Status | **D1 APPROVED** (2026-10-22). **D10 APPROVED** as operating procedure (2026-10-22; not a legal approval). **D2 PARTIALLY APPROVED** (institutional email active; support email planned — FB-LEGAL-01D). **D3–D9 PENDING** — PCDCD confirmation required |
| Source | `docs/audits/FB-LEGAL-01A-AUDIT.md` (read-only audit, baseline `c5d448c`) |
| Implementation | FB-LEGAL-01B (local, uncommitted at the time of writing): public pages infrastructure, release identity, footer, legal links |
| Rule | No legal text, contact detail, retention period, legal basis, right or security guarantee is published until its decision below is APPROVED and recorded here. Drafts never go into publicly served routes |

---

## 1. Decisions

| # | Decision | Status | Recorded outcome / what is needed |
|---|---|---|---|
| **D1** | Platform identity, operator and release wording | **APPROVED (2026-10-22)** | Famboook remains the platform name and brand. The Palestinian Center for Data and Community Development (PCDCD) is the legal operator and data management authority. No assumption that PCDCD owns Famboook intellectual property. The release designation is **Famboook V1.0**. Footer wording: «Famboook V1.0» · «© 2026 Famboook — جميع الحقوق محفوظة» · «تشغيل وإدارة البيانات: المركز الفلسطيني للبيانات والتنمية المجتمعية (PCDCD)». Existing branding, logo, palette, typography, tokens, layouts and behaviour unchanged |
| **D2** | Official legal identity and contact details | **PARTIALLY APPROVED (2026-10-22; corrected FB-LEGAL-01D)** — `info@pcdcd.org`: **ACTIVE**, official institutional email, the current channel for inquiries and support, shown on `/contact`. `support@pcdcd.org`: **PLANNED, not active** — not displayed until activated and verified on launch day (`docs/deployment/FB-LEGAL-01D-LAUNCH-DAY-CONTACT-CHECKLIST.md`). `media@pcdcd.org`: not for Famboook pages. **Still pending:** everything else in this row | PCDCD legal name as registered, registration number, postal address, official email and telephone, the privacy or data-protection contact, and the complaint and incident contact. **Blocks `/contact`** and the contact sections of every policy |
| **D3** | Legal basis for processing | PENDING | Applicable law; the legal basis for each processing purpose; whether any processing relies on consent, and for what. A Terms of Use acceptance is **not** consent to personal-data processing |
| **D4** | Retention and deletion | PENDING | A retention period per data category (audit §4), and whether and how deletion or erasure is offered. Today only the 90-day OTP purge exists; the documented 24-month security-event retention is not implemented (audit H2) |
| **D5** | Hosting and processors | PENDING | Hosting provider and data location; off-server backup provider and location; the processor arrangement with the SMS provider (TweetsMS); any other processor |
| **D6** | Rights of adults and children | PENDING | Household members without their own account (their data is visible to the household head); minors and guardians; how a person asks to access or correct their data; the erasure and objection position |
| **D7** | Terms acknowledgement | PENDING | Whether acceptance is recorded (activation, interstitial, Staff), whether it blocks account use, whether the raw IP is stored, re-acceptance on new versions. **No acknowledgement storage exists or is built** until decided (audit §8) |
| **D8** | Sensitive-data visibility | PENDING | Full mobile numbers for every Staff role with `person.view` (audit H3); household-head visibility of all members' health condition names (audit H4) — keep and disclose, or change |
| **D9** | Indexing and publication | PENDING | Whether search engines may index the legal pages; the public wording of the release line beyond the footer. Until decided every legal page is `noindex, nofollow` |
| **D10** | Clan onboarding workflow and joint import-file approval | **APPROVED (2026-10-22) — operating procedure only** | Recorded in `docs/governance/FB-GOV-01-CLAN-ONBOARDING.md`. **Approved:** (1) the nine-step onboarding sequence; (2) the raw clan roster is never the import file; (3) only a file that passed the data-quality review and that **both the formally delegated clan coordinator and Famboook management** approved, identified by its SHA-256 checksum, enters the existing import workflow; (4) any change to the file voids the approval. **Not approved by D10:** any legal basis, consent or partner agreement. Clan participation, coordinator delegation and file approval are three distinct approvals, and none authorizes processing of every member's personal or sensitive data (FB-GOV-01 §3). The open questions are FB-GOV-01 §15 G-01 … G-12 (they depend on D3–D6, D8). No system change: the joint approval is a governance procedure; the application does not record it (FB-GOV-01 §14) |

## 2. Page readiness (FB-LEGAL-01B)

| Route | Technical state | Publication state | Linked? | Blocked by |
|---|---|---|---|---|
| `/about` | Ready | **PUBLISHED** — verified product facts (purpose, family registry, Family Portal, coordinators, Staff), the D10 onboarding sequence (FB-LEGAL-01C.3), the release (Famboook V1.0, Initial Production Release, launch 2026-10-01) and the D1 operator wording; no contact detail | Yes (footer, Family account, Staff menu) | — (indexing: D9) |
| `/privacy` | Ready (registry entry, renderer, metadata) | **PENDING_APPROVAL** — neutral "not published yet" notice; no policy text, version, effective date or acceptance control | No | D2, D3, D4, D5, D6, D8 |
| `/terms` | Ready | **PENDING_APPROVAL** | No | D2, D3, D7 |
| `/data-protection` | Ready | **PENDING_APPROVAL** | No | D2, D3, D4, D5, D6, D8 |
| `/contact` | Ready | **PUBLISHED (FB-LEGAL-01D, local; not deployed)** — the institutional address `info@pcdcd.org` only, as a mail link; no form, telephone or address. The support address is added on launch day after verification | Yes (footer, Family account, Staff menu) | — (indexing: D9; support address: launch-day checklist) |

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
| 2026-10-22 | FB-LEGAL-01D: `/contact` PUBLISHED locally with `info@pcdcd.org` (ACTIVE) only; D2 corrected — `support@pcdcd.org` is PLANNED, not active, and is not displayed; launch-day checklist created. `/privacy`, `/terms`, `/data-protection` stay PENDING_APPROVAL |
| 2026-10-22 | FB-LEGAL-01C.3: D2 partially approved (two email addresses). Drafts of `/privacy`, `/terms`, `/data-protection`, `/contact` kept in `docs/legal/drafts/` (not in code: the registry ships in public JavaScript); review register `docs/legal/drafts/FB-LEGAL-01C3-REVIEW.md`. `/about` content extended (platform, registry, Family Portal, coordinators, Staff, D10 onboarding). The four pages stay PENDING_APPROVAL |
| 2026-10-22 | FB-LEGAL-01C.1: D10 (clan onboarding workflow, joint import-file approval) recorded as APPROVED operating procedure; governance document `docs/governance/FB-GOV-01-CLAN-ONBOARDING.md` created; its open questions G-01 … G-12 depend on D3–D6, D8 |
