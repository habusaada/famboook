# FB-LEGAL-01C.3 — Draft Review Register and Publication Checklist

| | |
|---|---|
| Status | **Internal review document.** The three policy drafts in this folder are NOT approved and NOT published. `/contact` is published (FB-LEGAL-01D) |
| Date | 2026-10-22 (revised FB-LEGAL-01E) |
| Baseline | `9059d19` + local, uncommitted FB-LEGAL-01E revision of the three policy drafts |
| Drafts | `PRIVACY.ar.md`, `TERMS.ar.md`, `DATA-PROTECTION.ar.md` (pending); `CONTACT.ar.md` (reference copy of the published page and its launch-day version). `/about` and `/contact` live in `frontend/app/(legal)/` |
| Decisions | `docs/decisions/FB-LEGAL-01-DECISIONS.md` (D1, D10 approved; D2 partially approved — `info@pcdcd.org` active, `support@pcdcd.org` planned; D3–D9 pending) |

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
| 2 | Factual review against verified behaviour (FB-LEGAL-01E) | ✓ corrected | ✓ corrected | ✓ corrected | ✓ |
| 3 | Contact uses only the active address | ✓ `info@pcdcd.org` | ✓ `info@pcdcd.org` | ✓ `info@pcdcd.org` | ✓ |
| 4 | Every BLOCKER resolved (§3, §4) | ✗ D3, D4, D5, D6, D8 | ✗ D7 | ✗ R-32, R-33 (G-08), R-34 | ✓ |
| 5 | NON-BLOCKER items confirmed or accepted by management | ✗ | ✗ | ✗ | ✓ |
| 6 | PCDCD approval of the final text, with a recorded approver and date | ✗ | ✗ | ✗ | ✓ (FB-LEGAL-01D) |
| 7 | Approved `version` identifier and `effectiveDate` | ✗ | ✗ | ✗ | n/a (R-41) |
| 8 | Independent Arabic language review | ✗ | ✗ | ✗ | ✗ |
| 9 | Copied into the registry, markers removed, tests and build green | ✗ | ✗ | ✗ | ✓ |
| 10 | Publication approval (and D9 indexing decision) | ✗ | ✗ | ✗ | ✓ (noindex until D9) |

## 3. Review items

**Classification:**
- **BLOCKER:** the statement cannot be published until the decision is taken or the fact is confirmed. Publishing without it would be misleading, or would make a claim PCDCD has not authorised.
- **NON-BLOCKER:** the drafted wording is truthful as it stands. Management confirms it, or accepts it as drafted, at approval.

| ID | Page | Claim (as drafted after FB-LEGAL-01E) | Basis in the repository | Needs | Class |
|---|---|---|---|---|---|
| R-01 | privacy | Scope: all personal data processed in the platform | — | PCDCD confirms the policy covers every channel (Staff, Family Portal, Coordinator Space, imports) | NON-BLOCKER |
| R-02 | privacy | Health and disability data are processed when recorded by Staff | `person_health_records` (audit §4) | **D3:** legal basis for sensitive data | **BLOCKER** |
| R-03 | privacy | Security and session records keep the IP address and browser information; passwords and codes are not kept in original form | `auth_security_events` (IP, browser hash); `sessions` (**raw** IP and user agent); `auth_otp_challenges` (IP). Corrected in FB-LEGAL-01E: the earlier "derived browser value" was true of security events only | **D4:** retention of IP and session data | NON-BLOCKER (wording now accurate; the retention period follows R-10) |
| R-04 | privacy | Initial rosters are completed with the clan and, when needed, partner institutions, then jointly approved before entry | FB-GOV-01 §2, D10 | **D5 / G-03:** no partner arrangement is recorded; the wording stays conditional ("عند الحاجة") | NON-BLOCKER, provided no partner is involved before an arrangement exists |
| R-05 | privacy | The legal basis per purpose will be stated in a later version; clan approval of the import file is not individual consent | none (D3 pending); FB-GOV-01 §3 | **D3:** legal basis per purpose. Publishing a privacy policy without any legal basis is a decision management must take explicitly | **BLOCKER** |
| R-06 | privacy | Coordinators: scope only, masked values, logged full reveals, no health, residence, needs or assistance | Coordinator resources (audit §4) | confirm; true while the code is unchanged | NON-BLOCKER |
| R-07 | privacy | The household head sees members' recorded health conditions; other members have no own accounts | audit H4 (PDD-018 open); activation is head-only | **D8:** keep and disclose (as drafted), or change the behaviour and the text | **BLOCKER** |
| R-08 | privacy | The SMS provider receives the mobile number and message text only, for activation, password recovery and mobile update | `TweetsSmsSender.php`; OTP purposes | **D5:** whether to name the provider (TweetsMS) and the processor arrangement | NON-BLOCKER (accurate unnamed) |
| R-09 | privacy | Institutional sharing only under an arrangement the operator approves | none recorded | **D5, G-03.** The FB-LEGAL-01E revision removed "وبما يتوافق مع الأحكام المعمول بها", an unsupported compliance claim | NON-BLOCKER |
| R-10 | privacy, data-protection | Retention periods are not yet approved; until then data, including initial import files, stays in the platform | no purge runs in Production: the OTP purge needs the scheduler, not yet installed (FB-NOTIFY-01 runbook) | **D4:** periods, or explicit acceptance of publishing "not yet approved; data retained" | **BLOCKER** (management decision) |
| R-11 | privacy | Portal corrections "when available" | change-request gates default OFF | confirm the wording matches the Production gate state at publication | NON-BLOCKER |
| R-12 | privacy | Other inquiries and corrections via `info@pcdcd.org`, reviewed under the operator's rules | `info@pcdcd.org` ACTIVE (D2) | **D6:** the rights actually offered (access, correction, erasure, objection) and the response process. **No erasure path exists, and none is promised** | **BLOCKER** |
| R-13 | privacy | Role-based access, coordinator scope, masking and logged reveals, hashed codes and passwords, "no absolute guarantee" | audit §4. FB-LEGAL-01E removed "not recorded in system logs" (unverified: unexpected database errors can contain query bindings, docs/08 §15) | confirm. Do not add HTTPS, backup or encryption claims until verified in Production (audit H6) | NON-BLOCKER |
| R-14 | privacy | Household data includes children | `persons.birth_date`; no minor or guardian model (PDD-020) | **D6:** rights and safeguards for children | NON-BLOCKER for disclosure; **D6** before any children-specific statement |
| R-15 | privacy | The platform and its data are hosted by technical service providers contracted by the operator | hosting and backup provider UNKNOWN (CTX-PENDING-001…005); off-server backup is a placeholder | **D5:** name or describe the hosting and backup processors and the data location | **BLOCKER** |
| R-16 | privacy | The National ID is masked in Staff screens and exported in full only in assistance lists that require a special permission; full reveals are logged in the Family Portal and Coordinator Space | `PersonResource`; `ExportFieldCatalog` (`assistance.export-sensitive`); reveal audit. Corrected in FB-LEGAL-01E: the earlier text implied every full reveal is logged, but Staff see full mobile numbers without a log | confirm | NON-BLOCKER |
| R-17 | privacy | Confirming that a notification was read is not consent to processing | notification acknowledgement (audit M1) | confirm the wording | NON-BLOCKER |
| R-21 | terms | Neutral scope; no "by using you agree"; the terms contain no consent to processing | D7 pending | **D7:** whether the terms are published as informational rules without acceptance (as drafted), or acceptance is required (a later feature) | **BLOCKER** (management decision) |
| R-22 | terms | Corrections through the portal "when available", or by email | as R-11 | as R-11 | NON-BLOCKER |
| R-23 | terms | The coordinator acts within the council's delegation and the assigned scope | FB-GOV-01 §5, D10 | **G-02** council legitimacy wording | NON-BLOCKER |
| R-24 | terms | Misuse may lead to account suspension or restricted access | accounts can be deactivated (`is_active`) | PCDCD confirms the sanctions it will actually apply | NON-BLOCKER |
| R-25 | terms | No guarantee of uninterrupted availability; no liability clause | — | PCDCD decides whether a liability clause is wanted (applicable law: **D3 / G-10**); none is drafted | NON-BLOCKER |
| R-26 | terms | Staff do not need a user's password or code; do not disclose them to anyone | passwords are hashed and codes are single-use; Staff tools never ask for them | PCDCD confirms this as an operating rule for Staff | NON-BLOCKER |
| R-31 | data-protection | Staff: National ID masked, full export only with special permission; Family Portal and Coordinator Space: masking and logged reveals | audit §4. Corrected in FB-LEGAL-01E: removed "least privilege" (Staff roles have no per-family scope) and the implication that mobiles are masked for Staff (audit H3) | **D8** confirms the current Staff visibility is acceptable | NON-BLOCKER (now accurate) |
| R-32 | data-protection | Staff and coordinators are bound by confidentiality | no signed undertaking recorded | PCDCD confirms the duty exists (contract, code of conduct, coordinator delegation terms) | **BLOCKER** (a stated duty must exist) |
| R-33 | data-protection | Rosters and files handled only through approved channels; never via personal or public messaging | FB-GOV-01 §12, G-08 | **G-08:** the approved channels must exist before this is stated | **BLOCKER** |
| R-34 | data-protection | Initial files: quality review and joint approval before entry | D10 | confirm the procedure is actually followed for every import before publishing it | **BLOCKER** (confirmation of practice) |
| R-35 | data-protection | Report privacy concerns to `info@pcdcd.org` | ACTIVE (D2) | PCDCD confirms the institutional mailbox handles privacy concerns, or names a privacy contact (**D2** remainder) | NON-BLOCKER |
| R-41 | contact | Whether a contact page shows a version and effective date | — | **Resolved (FB-LEGAL-01D):** published as an informational page, with no version or effective date | resolved |
| R-42 | privacy, terms, data-protection | The drafts named the planned `support@pcdcd.org` | PLANNED, not active (FB-LEGAL-01D) | **Resolved (FB-LEGAL-01E):** replaced by `info@pcdcd.org` in all three drafts. On launch day, re-evaluate whether policy contacts move to the support address (launch-day checklist §3) | resolved |

**Not used anywhere in the drafts:**
- `media@pcdcd.org`, or the planned support address;
- telephone numbers, postal addresses, registration numbers;
- certifications, compliance claims, encryption-at-rest, HTTPS, backup or audit-coverage guarantees;
- a consent claim (the drafts state twice what is **not** consent: R-05, R-17);
- an acceptance clause.

## 4. Publication-readiness report (FB-LEGAL-01E)

### 4.1 Changes made in FB-LEGAL-01E

**`/privacy`:**
- **Operator separated from platform:** "من نحن" became «عن هذه السياسة»; "we" phrasing became "the operator", defined once as «الجهة المشغّلة».
- **Consent:** added that clan approval of the import file is not individual consent (FB-GOV-01 §3), and that confirming a notification is not consent.
- **Accuracy:**
  - children disclosed;
  - internal Staff notes disclosed;
  - session records now say what they hold (IP address and browser information);
  - the claim of a "derived browser value" for all records removed.
- **Hosting:** a hosting-processor statement added (wording held by R-15).
- **Retention:** now truthful — not yet approved, data retained meanwhile, including initial import files.
- **Removed unsupported claims:** "compliant with applicable rules" for sharing, and "passwords not recorded in system logs".
- **Security:** the masking statement narrowed to what is implemented.
- **Contact:** the support address replaced by `info@pcdcd.org`, addressed to the operator.
- **Language:** first-person and uneven constructions made consistent.

**`/terms`:**
- **Operator:** defined; a statement added that the terms contain no consent to processing.
- **Accounts:** Staff accounts covered (created by the operator); "change your password if the service is available" (the password-change gate).
- **Password rule:** "the Famboook team never asks" reworded as "Staff do not need your password or code; do not disclose them" (R-26).
- **Contact:** a single `info@pcdcd.org` line addressed to the operator.
- **Language:** minor fixes.

**`/data-protection`:**
- **Access:** "least privilege" removed (not true for Staff), replaced by role-based access.
- **Masking:** made accurate — Staff see the National ID masked, with full export only under a special permission; masking and logged reveals in the Family Portal and Coordinator Space; no claim that mobiles are masked for Staff.
- **Minimisation:** softened from an absolute "only necessary data is collected" to «يُراعى».
- **Contact:** `info@pcdcd.org`.
- **Language:** grammar fixes («يُطابَق الأشخاص», «سجلّا شخصين»).

### 4.2 Blockers and management decisions

| Page | BLOCKER items | Management decision required |
|---|---|---|
| `/privacy` | R-02, R-05 (D3 legal basis); R-07 (D8); R-10 (D4); R-12 (D6 rights); R-15 (D5 hosting) | Legal basis per purpose, including health data; household-head health visibility; retention, or explicit acceptance of "not yet approved"; the rights offered and the response process; the hosting and backup processors and the data location |
| `/terms` | R-21 (D7) | Publish as informational rules without acceptance (as drafted), or require acceptance (a later feature with its own approval) |
| `/data-protection` | R-32, R-33 (G-08), R-34 | Confirm the confidentiality duty exists in contracts or codes; approve the transfer channels; confirm the joint-approval procedure is applied to every import |

**NON-BLOCKER items to confirm at approval:**
- R-01, R-03, R-04, R-06, R-08, R-09, R-11, R-13, R-14, R-16, R-17;
- R-22, R-23, R-24, R-25, R-26;
- R-31, R-35;
- an independent Arabic language review of all three pages.

### 4.3 Recommendation

| Page | Recommendation | Reason |
|---|---|---|
| `/privacy` | **NEEDS_DECISION** | Five blocking management decisions (D3, D4, D5, D6, D8). The text is factually corrected but cannot be published truthfully without them |
| `/terms` | **READY_FOR_APPROVAL** | Text factually corrected; its one BLOCKER (R-21, D7) is a yes/no management decision that can be taken in the approval itself (publish as informational rules, as drafted). Non-blocker confirmations: R-24, R-25, R-26 |
| `/data-protection` | **NEEDS_DECISION** | It states duties and channels (R-32, R-33) that must exist before they are published, and a procedure (R-34) whose practice must be confirmed |

No page may be published before: PCDCD approval of its final text, an approved version and effective date, the language review, and a recorded publication approval. All three pages stay **PENDING_APPROVAL**, unlinked and not indexed.

## 5. Change log

| Date | Change |
|---|---|
| 2026-10-22 | Drafts of the four pending pages and this register created (FB-LEGAL-01C.3). `/about` content extended in code. Nothing published beyond `/about` |
| 2026-10-22 | FB-LEGAL-01D: `/contact` published with `info@pcdcd.org` only; R-41 resolved; R-42 added |
| 2026-10-22 | FB-LEGAL-01E: the three policy drafts reviewed and revised (factual corrections, consent boundaries, operator distinction, language). `support@pcdcd.org` replaced by `info@pcdcd.org` (R-42 resolved). New items R-14 … R-17, R-26; R-03, R-09, R-12, R-13, R-31, R-35 updated. Items classified BLOCKER / NON-BLOCKER; readiness report §4 added. All three pages remain PENDING_APPROVAL |
