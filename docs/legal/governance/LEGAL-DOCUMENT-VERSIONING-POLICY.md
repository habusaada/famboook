# Legal Document Versioning Policy

| | |
|---|---|
| Decision | **FB-LEGAL-01N — Legal Document Versioning Policy**: APPROVED |
| Authority | Executive Director, PCDCD |
| Decision date | 2026-10-22 |
| Evidence | An explicit Executive Director decision in the project conversation. No signature or separate approval document |
| Applies to | Famboook legal documents published through the legal-page registry (`/terms`, `/privacy`, `/data-protection`, and any later policy page) |
| Register | `docs/decisions/FB-LEGAL-01-DECISIONS.md` §1.1 |
| Status of this document | Governance documentation only. It authorizes no publication, version assignment, effective date, registry change, application change or Production change |

## 1. Version format

Legal documents use **`MAJOR.MINOR`**. Examples: `1.0`, `1.1`, `2.0`.

This replaces the earlier *proposed* pattern `terms-YYYY-MM-DD` (FB-LEGAL-01G.2 / 01G.3). That pattern was never approved for assignment.

## 2. Initial publication

- The **target** version for the first authorized publication of a legal document is **`1.0`**.
- This is a target only. **No current draft has been assigned `1.0`** or any other publication version.
- The version is assigned only through a separate, documented publication-readiness or publication-approval decision for that document.

## 3. Subsequent revisions (intended convention)

After a document is published:

| Change | Version step | Example |
|---|---|---|
| Non-substantive revision: clarification, editorial or linguistic change | Increment `MINOR` | `1.0` → `1.1` |
| Substantive change to rights, obligations, data-processing representations or other materially significant legal provisions | Increment `MAJOR` | `1.1` → `2.0` |
| Ambiguous change | Classified by management before the next version is assigned | — |

No subsequent version of any document has been approved.

## 4. Draft versus published version

| Concept | What it is | Is it a publication? |
|---|---|---|
| Internal draft identifier / Git commit | A commit hash identifying an exact file state (e.g. `0b950e2`) | No |
| Approved text baseline | The commit whose text management approved; integrity is checked by SHA-256 | No |
| Publication version | The `MAJOR.MINOR` number assigned by a separate decision (registry `version` field) | Only together with a publication authorization |
| Effective date | The date from which a published version applies, assigned by a separate decision (registry `effectiveDate` field) | Only together with a publication authorization |
| Publication authorization | The separate act that allows the registry entry to become `PUBLISHED` and the page to be deployed | Yes |

- A Git commit is not a publication version.
- A management-approved draft is not necessarily a published legal document.

**Current state of `/terms`:**
- **Text baseline:** `0b950e2` (SHA-256 `8932fc9a55befd7f718200415fc1f7828db3867da534a918fc62ce258e4e2b2c`).
- **Latest governance commit:** `985e774`.
- **Neither constitutes publication.**
- **Publication version:** UNASSIGNED.
- **Effective date:** UNASSIGNED.
- **Registry:** `PENDING_APPROVAL`.

## 5. Effective date

- **How it is set:** the effective date is assigned separately, through an explicit authorized decision.
- **It is never inferred automatically from:**
  - a Git commit date;
  - a draft creation date;
  - a management text-approval date;
  - a version-number decision;
  - a deployment date.
- **Current state:** no effective date is assigned for any legal document.

## 6. Publication controls

- Publication requires a separate authorization after all applicable legal, governance, operational and technical prerequisites have been satisfied or explicitly resolved.
- This policy does not override any existing publication safeguard:
  - the D3 / D5 / D9 decisions;
  - G-02;
  - the independent language review;
  - the Production verification;
  - the `noindex` initial setting for `/terms`.

## 7. Technical note (no change made)

The registry type documents `version` with the example `"privacy-2026-11-01"` (a comment in `frontend/lib/legal/pages.ts`). The field is a free string, so it can hold `1.0`; no code change is needed or made here. Aligning that comment with this policy is a separate code-level item, for when the registry is next changed under its own approval.
