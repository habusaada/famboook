# FB-LEGAL-01C.3 — Draft Review Register and Publication Checklist

| | |
|---|---|
| Status | **Internal review document.** The drafts in this folder are NOT approved and NOT published |
| Date | 2026-10-22 |
| Baseline | `1f559df` + local, uncommitted FB-LEGAL-01C.3 changes |
| Drafts | `PRIVACY.ar.md`, `TERMS.ar.md`, `DATA-PROTECTION.ar.md`, `CONTACT.ar.md` (this folder). `/about` content is integrated in `frontend/app/(legal)/about/page.tsx` (published page) |
| Decisions | `docs/decisions/FB-LEGAL-01-DECISIONS.md` (D1, D10 approved; D2 partially approved; D3–D9 pending) |

## 1. Why the drafts are documentation, not code

**Architecture limitation (found 2026-10-22).** The publication registry `frontend/lib/legal/pages.ts` is imported by client components: `components/layout/user-menu.tsx` (`"use client"`), and the footer through `FamilyAuthCard`, `LoginView` and `CredentialVerification`. Its contents are therefore shipped in the public JavaScript bundle. **Any draft text placed in the registry, even under `PENDING_APPROVAL`, would be downloadable by anyone.**

So:
- the four drafts are kept here, outside every served route;
- the registry still holds no content for pending pages, and the publication controls are unchanged.

**Publication path (unchanged architecture):**
1. copy an approved draft into its registry entry (`status: "PUBLISHED"`, `kind: "policy"`, approved `version`, `effectiveDate`, `sections`);
2. remove the review markers;
3. run the tests.

The page then renders with its version and effective date and appears in `LegalLinks` automatically.

If drafts must later live in code before approval (for preview), that needs a separate, server-only module that no client component imports, plus a test that enforces it. It is not built here.

## 2. Publication-readiness checklist

| # | Check | `/privacy` | `/terms` | `/data-protection` | `/contact` |
|---|---|---|---|---|---|
| 1 | Draft complete (all required topics) | ✓ | ✓ | ✓ | ✓ |
| 2 | Every R-marker resolved (§3) | ✗ | ✗ | ✗ | ✗ |
| 3 | Underlying decisions approved | ✗ D2 (partial), D3, D4, D5, D6, D8 | ✗ D3, D7 | ✗ D3, D4, D5, D8 | ✓ emails approved (D2 partial); page text ✗ |
| 4 | PCDCD approval of the final text, with a recorded approver and date | ✗ | ✗ | ✗ | ✗ |
| 5 | Approved `version` identifier and `effectiveDate` | ✗ | ✗ | ✗ | n/a (R-41) |
| 6 | Arabic language review | ✗ | ✗ | ✗ | ✗ |
| 7 | Copied into the registry, markers removed, tests and build green | ✗ | ✗ | ✗ | ✗ |
| 8 | Publication approval (and D9 indexing decision) | ✗ | ✗ | ✗ | ✗ |

**Verdict:** none of the four pages is ready to publish. `/contact` is closest: it depends only on approval of its text and of publication.

## 3. Unresolved claims requiring review

| ID | Page | Claim | Basis in the repository | Needs |
|---|---|---|---|---|
| R-01 | privacy | Scope of the policy (all personal data in the platform) | — | PCDCD confirms the policy covers every channel (Staff, Family Portal, Coordinator Space, imports) |
| R-02 | privacy | Health and disability data are processed when recorded by Staff | `person_health_records` (audit §4) | **D3** legal basis for sensitive data; wording review |
| R-03 | privacy | Security logs keep the IP address and a derived browser value; passwords and codes are not kept in original form | `auth_security_events`, `auth_otp_challenges` (audit §4) | **D4** retention of IP addresses; confirm the wording |
| R-04 | privacy | Initial clan rosters are completed with partner institutions and approved before entry | FB-GOV-01 §2, D10 | **D5 / G-03:** no partner arrangement is recorded, so the wording must stay conditional ("عند الحاجة") or name the arrangements |
| R-05 | privacy | "Processing on the basis the operator adopts; stated in an update" | none | **D3:** the legal basis per purpose. Publishing without it is a known gap the owner must accept or resolve |
| R-06 | privacy | Coordinators: scope only, masked values, audited reveals, no health, residence, needs or assistance | Coordinator resources (audit §4) | confirm; it stays true only while the code is unchanged |
| R-07 | privacy | The household head sees members' recorded health conditions | audit H4 (PDD-018 open) | **D8:** keep and disclose (as drafted), or change the behaviour and the text |
| R-08 | privacy | The SMS provider receives the mobile number and message text only | `TweetsSmsSender.php` | **D5:** whether to name the provider (TweetsMS) and the processor arrangement |
| R-09 | privacy | Institutional sharing only under an approved arrangement and applicable rules | none recorded | **D5, G-03; D3 / G-10** applicable law |
| R-10 | privacy, data-protection | Retention periods "to be announced" | only the OTP purge (90 days) is implemented | **D4:** periods, or acceptance of publishing without them |
| R-11 | privacy | Household heads can request corrections through the portal "when available" | change-request gates default OFF | confirm the wording matches the Production gate state at publication |
| R-12 | privacy | Other inquiries and correction requests via support@pcdcd.org; reviewed "under the operator's rules" | email approved | **D6:** the rights actually offered (access, correction, erasure, objection) and the response process. **No erasure path exists.** Do not promise one |
| R-13 | privacy | Security practices (role-based access, masking, audited reveals, hashed codes and passwords) and "no absolute guarantee" | audit §4 | confirm. Do not add HTTPS, backup or encryption claims until verified in Production (audit H6) |
| R-21 | terms | Neutral scope sentence; no "by using you agree" | D7 pending | **D7:** whether the terms bind users and how (no acceptance mechanism is built) |
| R-22 | terms, contact | Corrections through the portal "when available", or by email | as R-11 | as R-11 |
| R-23 | terms | The coordinator acts within the council's delegation and the assigned scope | FB-GOV-01 §5 | **G-02** council legitimacy wording |
| R-24 | terms | Misuse may lead to account suspension or restricted access | accounts can be deactivated (`is_active`) | PCDCD confirms the sanctions it will actually apply |
| R-25 | terms | No guarantee of uninterrupted availability | — | PCDCD wording on service limitations and liability (applicable law, **D3 / G-10**) |
| R-31 | data-protection | Masking and audited reveals | audit §4 | Staff see full mobile numbers (audit H3): the text says "where the full value is not needed"; **D8** confirms this is acceptable |
| R-32 | data-protection | Staff and coordinator confidentiality duty | no signed undertaking recorded | PCDCD confirms the duty exists (contract, code of conduct) |
| R-33 | data-protection | Rosters and files handled only through approved channels; never via personal or public messaging | FB-GOV-01 §12, G-08 | **G-08:** the approved channels |
| R-34 | data-protection | Initial files: quality review and joint approval before entry | D10 | confirm the procedure is actually followed before publishing it |
| R-35 | data-protection | Report privacy concerns to support@pcdcd.org | email approved | PCDCD confirms support handles privacy concerns, or names a privacy contact (**D2** remainder) |
| R-41 | contact | Whether a contact page shows a version and effective date | — | publication decision |

**Not used anywhere in the drafts:**
- `media@pcdcd.org`;
- telephone numbers, postal addresses, registration numbers;
- certifications, compliance claims, encryption-at-rest, backup or audit-coverage guarantees;
- a consent claim.

## 4. Change log

| Date | Change |
|---|---|
| 2026-10-22 | Drafts of the four pending pages and this register created (FB-LEGAL-01C.3). `/about` content extended in code. Nothing published beyond `/about` |
