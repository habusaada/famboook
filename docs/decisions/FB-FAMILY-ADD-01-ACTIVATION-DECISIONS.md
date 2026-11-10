# FB-FAMILY-ADD-01 — Activation Decision Register and Approval Artifacts

**Status:** prepared by RR-04 (2026-11-05), updated 2026-11-10 for owner review, local only. **Recorded:** the G-02
declaration *wording* (§3, approved 2026-11-05) and the owner's confirmations of §6. **Nothing else here is approved**,
including the G-02 privacy / legal review. §7 holds DRAFT decision texts for G-01, G-02, G-03, G-04 and D-06R; each
becomes a decision only when its approval evidence and reference are recorded in it. Until every item is recorded, the
feature stays `OFF` (CLAUDE.md, ADR §12). Runbook: `docs/deployment/FB-FAMILY-ADD-01-PILOT-READINESS-CHECKLIST.md`.

## 1. Production state (verified by the read-only audit of 2026-11-10)

| Item | State |
|---|---|
| Deployed commit | `7caa92469b10928f7ada3dba842df2c899f71bc9`; 83 migrations Ran, 0 Pending (batch 22); frontend build `.builds/20261104-7caa924` with the registry-children page |
| Civil registry | release id `1`, `PUBLISHED`, 2,399,891 records; database `famboook`, schema `identity_ref` (same database as Laravel) — owner-reported 2026-11-05 |
| Readiness (`famboook:registry-admission-readiness`) | mode `OFF`; BLOCKED: O-K / G-02, G-04, G-05 / D-06R, SOURCE, PIN; PASS: LOOKUP, KEY, DEBUG; 0 permission holders |
| DBA-1 … DBA-3 | `identity_ref_lookup.release_pin_held(integer,integer)` MISSING (2026-11-05) |
| Not deployed | `f705d7d` (RR-04 tooling, DBA scripts 11–13), `d320f3a` (declaration `2026-11-v1`) |
| Code gates | `CivilRegistryValues::MAPPINGS_APPROVED = false` |

## 2. Decisions still required

| # | Decision | Owner | Exact evidence that closes it | Change that follows (each its own reviewed commit or step) |
|---|---|---|---|---|
| 1 | **G-01** legal basis and purpose | Project owner + legal counsel | A dated, signed decision covering this purpose (a head admits their own son or daughter from the civil registry), G1 / OD-08 as they apply, and the OD-10 reading (not genealogy) | None in code; the reference is cited in item 7 |
| 2 | **G-02** adult children's data and the declaration wording | Project owner + legal and privacy counsel | **Wording: RECORDED** (§3, 2026-11-05). **Still OPEN:** a dated privacy / legal decision approving visibility to the head, the notice and objection position, retention of `family_registry_admission_*` rows and residual disclosure | Done: `2026-11-v1` in the catalogue. Then `FAMILY_REGISTRY_ADMISSION_DECLARATION_VERSION=2026-11-v1`, only under G-07 after the review |
| 3 | **G-03** parent-link semantics | Civil registry data owner | Written confirmation that `fatherid` / `motherid` hold the parent's `mainid` (National ID), what an unresolved value means (12.53 % of populated `fatherid`, 4.27 % of `motherid`, audit P0 §3), and that `mainid` is the person's National ID | None in code (the adapter already matches exact `fatherid` / `motherid` only) |
| 4 | **G-04** gender codes | Civil registry data owner | The authoritative code table for `gender`, confirming §4 | §4 commit (`MAPPINGS_APPROVED = true`) |
| 5 | **D-06R** revised life policy (instead of G-05) | Project owner + legal and privacy counsel | (a) the issuing authority's written statement that release `1`'s source export lists living persons only, **with its extraction date**; (b) legal and privacy review of admitting on the head's declaration of current life, recorded `UNKNOWN`; (c) an approval reference `[A-Za-z0-9._:/-]{3,80}` | Configuration only (§5) |
| 6 | **G-06** independent security review | Engineering lead + security reviewer | A review of the passing evidence: `RegistryAdmissionCivilRegistryPostgresTest`, `RegistryAdmissionPinScriptsPostgresTest`, `AdmitRegistryChildConcurrencyTest`, `RegistryAdmissionPilotAccessTest` and the full PostgreSQL suite on the candidate commit. **OPEN:** a focused, non-independent review of `23c82c2` (2026-11-10) found no blocker; its finding F-1 is decided (§7.6); independent reviewer sign-off is still required | None |
| 7 | **G-07** activation | Project owner | A recorded approval naming: the commit, mode `PILOT`, the pilot Family id(s), the pilot account(s) by Family code, DBA-1 … DBA-3 output (precheck and verify), the DBA-4 operations statement, the D-06R reference | Checklist §3 steps 3 – 8 |
| 8 | **DBA-4** one cluster, one database | Operations | A written statement that the application connection and the lookup login reach the same primary and database under every route, pooler and failover; plus the `12-…-verify.sql` and `--probe-pin` output | None |

The wording half of item 2 is recorded (§3). The privacy / legal half is not, and is not claimed here. No other
declaration is prepared.

## 3. Declaration wording — APPROVED (G-02, wording only)

**Decision:** the Famboook administration approved the exact Arabic wording below on **2026-11-05**, as reported by the
project owner. No separate reference number was given and none is invented here. **This approves the wording only**: the
privacy / legal review for adult children (item 2) remains open and is a precondition of G-07.

Two statements, each confirmed separately in the UI (two checkboxes); the second is delivered only while D-06R is active.

1. Household membership (`text`):
   > أُقرّ بأن هذا الابن / هذه الابنة من أفراد أسرتي ويقيم/تقيم فعليًا ضمن هذه الأسرة، وأن هذا الإقرار صحيح على مسؤوليتي.
2. Current life (`life_text`):
   > وأُقرّ بأن هذا الابن / هذه الابنة على قيد الحياة في تاريخ هذا الإقرار، وأتحمّل مسؤولية صحة ذلك.

Implemented in `backend/config/family_registry_admission.php` as version **`2026-11-v1`**, `approved => true`, both texts byte
for byte equal to `2026-11-draft-2`. The drafts stay, unapproved, for historical compatibility (a version's text never
changes). `RegistryAdmissionReadinessTest::test_the_shipped_catalogue_approves_only_2026_11_v1_with_the_draft_2_wording_verbatim`
pins the catalogue. The version is delivered only when `FAMILY_REGISTRY_ADMISSION_DECLARATION_VERSION` names it; it
activates nothing by itself.

## 4. Gender table to confirm (G-04)

Observed in the 2026-10-25 export (audit P0: 4 distinct values including the one empty row), matched exactly as stored:

| Stored value | Mapping | Admission |
|---|---|---|
| `ذكر` | `MALE` → relationship `SON` | eligible when every other rule passes |
| `أنثى` | `FEMALE` → relationship `DAUGHTER` | eligible when every other rule passes |
| `غير معروف` | none | always `NOT_ELIGIBLE` |
| empty, or any other value | none | always `NOT_ELIGIBLE` |

**Verification (2026-11-05): G-04 stays OPEN.**
- The three non-empty values match the export as observed (WP-04B; P0: 4 distinct values including the one empty row).
  The labels are self-describing Arabic words, and the mapping above is the plain reading.
- But P0 records only the count of distinct values, not a code table, and `FB-CIVIL-01A-P0-RECOMMENDATIONS.md` keeps
  the `gender` code list `[OPEN]` until the source owner supplies it. No source-owner document exists in the repository.
- Missing evidence: the civil registry data owner's written code table for `gender`. It must confirm (a) `ذكر` = male,
  `أنثى` = female, (b) what `غير معروف` and an empty value mean, and (c) that no other value is issued.
- `MAPPINGS_APPROVED` therefore stays `false`. `CivilRegistryValuesTest` pins the table by its UTF-8 bytes.

The data owner confirms the table and that no other code is issued. Then a reviewed commit sets
`CivilRegistryValues::MAPPINGS_APPROVED = true` with the reference in its comment. It also updates
`CivilRegistryValuesTest`, `RegistryAdmissionReadinessTest` and every test that asserts `GENDER_MAPPING_PROVISIONAL` or
`[BLOCKED] G-04` as shipped. **Until then every child is `NOT_ELIGIBLE` and no admission is possible in any mode.**

## 5. Production configuration (after items 1 – 8)

Placeholders in `<…>` come only from the recorded decisions; none is invented here.

```dotenv
FAMILY_REGISTRY_ADMISSION_DECLARATION_VERSION=2026-11-v1
FAMILY_REGISTRY_LIFE_POLICY=REGISTRY_SCOPE_AND_DECLARATION
FAMILY_REGISTRY_LIFE_POLICY_APPROVAL=<D-06R approval reference>
FAMILY_REGISTRY_LIVING_SCOPE_RELEASES=1:<extraction date of release 1, YYYY-MM-DD, from the issuing authority's statement>
FAMILY_REGISTRY_ADMISSION_CIVIL_REGISTRY_BINDING=true
FAMILY_REGISTRY_ADMISSION_PILOT_FAMILY_IDS=<pilot Family id(s) named in G-07>
FAMILY_REGISTRY_ADMISSION_MODE=PILOT
```

The pilot account is granted per account, never through a role:

```bash
php artisan famboook:registry-admission-pilot-access grant --family=<FAM-code named in G-07> --approval=<G-07 reference>
```

`ON` is a separate G-07 decision after the pilot is reviewed. It needs its own grant model, such as the `FAMILY_USER` role
proposed in docs/06 §61d-2.

**Owner decision (2026-11-10): general release, no PILOT.** The owner decided to release to all families at once with
`FAMILY_REGISTRY_ADMISSION_MODE=ON`, skipping the PILOT and its review. **This is not an activation permission.** G-07
must still name the commit and record the approval, and G-01, G-02, D-06R and G-06 stay OPEN.

- Grant model: `FAMILY_USER` holds `family-member.admit-from-registry`. Implemented in `b4628f9` on the local branch
  `release/rc-20261110-23c82c2`; not pushed, not deployed.
- The permission authorizes nothing alone: `family.context` (eligible household head), the mode switch and every
  eligibility gate still decide.
- `PILOT_FAMILY_IDS` and the per-account `registry-admission-pilot-access` grant are not used.
- Rollback for all families at once: `FAMILY_REGISTRY_ADMISSION_MODE=OFF`, then `php artisan config:cache`.

## 6. Owner confirmations (2026-11-10)

Recorded as stated by the project owner.

| # | Confirmation | Form | Scope of the confirmation |
|---|---|---|---|
| C-1 | The civil registry dataset was obtained from an **official, trusted authority** | Owner confirmation, **recorded and not reopened** | Establishes the source. It does **not** by itself verify field semantics (G-03, G-04) or authorize this purpose (G-01) |
| C-2 | The issuing authority **orally** described the dataset as containing **living persons only** | Owner statement of an **oral** description | No written scope statement or extraction date is on file (D-06R, §7.5) |
| C-3 | The existing implementation and historical test evidence are to be reused (§8) | Owner decision | Does not replace G-06 (independent review) |
| C-4 | Goal: discovery and direct admission for **one** pilot Family | Owner decision | Does not replace G-07 (activation approval) |
| C-5 | **V1 priority:** household data collection and completion using the official civil registry. The existing discovery and direct admission are kept as implemented: eligible sons and daughters of any age; no admission while an active membership exists elsewhere; all safeguards kept | Owner decision | Not a privacy or legal approval (G-01, G-02 stay OPEN) |
| C-6 | **Deferred:** any new objection or Data Challenge workflow is a future phase. `PERSON_CORRECTION` («تصحيح بيانات فرد») is not changed | Owner decision | The deferral is not a privacy approval; G-02(d) stays OPEN for legal assessment |

Evidence classes used in §7:

- **Oral:** a spoken statement relayed by the owner.
- **Documented:** a written artefact on file, such as a letter, an e-mail or a data dictionary.
- **Legal approval:** a dated decision by the owner with legal and privacy counsel, with a reference.
- **Technical verification:** something checked in code, tests or read-only Production inspection.

## 7. Decision entries for owner review (DRAFT — none approved)

Each entry gives the proposed decision text, the current evidence by class, what is still required and the reference
field. An entry is APPROVED only when its "Approval" line holds a dated reference and the required evidence is on file.

### 7.1 G-01 — Legal basis and purpose

- **Proposed decision:** "Famboook may use civil registry release `1`, obtained from the official authority (C-1), to
  show an authenticated household head the children whose record names them as parent, and to admit an eligible son or
  daughter to that head's household, in PILOT for the named Family. It is not used for genealogy (OD-10)."
- **Oral:** none on purpose. **Documented:** C-1 (source recorded). **Legal approval:** none. **Technical:** n/a.
- **Required:** a dated decision of the owner with legal counsel covering **this purpose**, G1 / OD-08 as they apply.
  The source's official status is settled by C-1 and is not part of this requirement.
- **Approval:** `<reference>` — `<date>` — `<signatories>` — status **OPEN**.

### 7.2 G-02 — Privacy of children's data, including adult children

Current behaviour (technical verification, `7caa924`):

- Discovery shows each child record naming the head as parent with **first name, birth year and a public status only**
  (`FamilyRegistryChildResource`). No National ID, full date, relationship or reason is shown.
- The list includes **adult** children, children who are members of **another household**, and records that may be
  **deceased**; these appear as `NOT_ELIGIBLE` without a reason (`RegistryChildAssessment::onlyRequestOrPending`).
- Every read is recorded as `DISCOVERY_LISTED` with a keyed IP hash.
- Admission creates or attaches the Person in the head's household with life status `UNKNOWN`.
- There is **no age rule**, **no notice** to the admitted person and **no objection channel** in the feature. Removal is
  only through existing Staff actions.
- `family_registry_admissions` and `family_registry_admission_events` are append-only, with **no retention period
  implemented**.

**Owner-approved functional requirement (2026-11-10).** Recorded as the project owner's approved *functional* behaviour.
It is **not** the G-02 privacy / legal approval, which stays OPEN:

1. The feature operates only inside the existing Family Head Portal.
2. The authenticated household head discovers and directly admits eligible sons and daughters from the civil registry.
3. **No age restriction:** adult children (18+) are included.
4. A child with an active membership in another household is not admitted.
5. No child portal, child account or new approval workflow.
6. All existing safeguards stay: declarations, eligibility checks, authorization, audit logging, concurrency protection.

Match with the implementation (code evidence, `7caa924`):

| # | Status | Evidence |
|---|---|---|
| 1 | Matches | `FamilyAccessResolver` refuses a non-head (`is_household_head`); `family.side`, `family.context`, `family-portal.access`; `FamilyRegistryAdmissionApiTest::test_a_guest_a_staff_account_and_a_non_head_are_refused` |
| 2 | Matches | `GET …/registry-children`, `POST …/admissions`; the relationship comes from the gender mapping (G-04 still open) |
| 3 | Matches | `RegistryChildEligibility` has no age or birth-date rule |
| 4 | Matches | `RegistryChildEligibility` reasons `ACTIVE_MEMBERSHIP_ELSEWHERE` / `ALREADY_MEMBER`, re-checked under the locks in `AdmitRegistryChildAction` |
| 5 | Matches | Direct admission; no child account or workflow exists in the feature |
| 6 | Matches | Unchanged; see §8 |

No discrepancy. No code change follows from this requirement.

The legal and privacy assessment of each point below remains **OPEN**, including whether showing and admitting adult
children in this way is lawful and proportionate.

| Point | Decision to record |
|---|---|
| a. Adult children | Functional behaviour decided by the owner (above): included, no age restriction. **Open:** the legal / privacy assessment of showing and admitting adults without their involvement |
| b. Visibility | Is first name + birth year acceptable for every listed record, including members of another household and possibly deceased persons? |
| c. Notification | Is the admitted person (or an adult child) to be informed, by whom and how? No mechanism exists; for the pilot it can be an operational step |
| d. Objection | Functional decision by the owner (below, and C-6): existing mechanisms only; a new objection workflow is **deferred** to a future phase. **Open:** the legal / privacy assessment of objection handling in V1 |
| e. Retention | Retention period for `family_registry_admissions` and `family_registry_admission_events` (including `DISCOVERY_LISTED`), and what happens at its end |

**Owner functional decision for point d (2026-11-10).** An objection to a household membership created by registry
admission uses the **existing Data Challenge Request mechanism**. No new objection system is designed. No separate
portal or account for children.

Existing elements found (documentation and code at `7caa924`):

| Step | Existing element | Usable by an adult child without a Family Portal account? |
|---|---|---|
| Intake | Approved contact channel `info@pcdcd.org` for "privacy questions, correction requests and related inquiries" (`docs/decisions/FB-LEGAL-01-DECISIONS.md` D6, PARTIALLY APPROVED, contact channel only); the contact page lists «طلب تصحيح» of family data (`frontend/app/(legal)/contact/page.tsx`) | Yes — e-mail needs no account |
| Intake (in-system) | Change Request workflow | **No.** It is submitted by the household head (`FAMILY_USER`, `change-request.submit`) or a scoped Coordinator; no type carries an objection to a membership; `PERSON_CORRECTION` refuses membership data |
| Review procedure | D6 "internal request-handling procedure and responsibilities" | **PENDING** in D6 |
| Resolution | `EndFamilyMembershipAction` via `POST /api/v1/families/{family}/members/{person}/end`, permission `family-membership.end`. It ends a current, non-head membership; the row stays as history with a reason, and the Person is unchanged | Staff-side; works regardless of the child's account |

**Owner clarification (2026-11-10).** The "Data Challenge Request" meant is the existing «تصحيح بيانات فرد» screen
(`PERSON_CORRECTION`, `frontend/components/family/requests/family-person-correction-form.tsx`,
`PersonCorrectionHandler`).

Code evidence:
- It corrects four Person fields only (full name, birth date, gender, marital status) and refuses membership data.
- Only the household head can submit it.

So it does **not** carry an objection to a membership, and the affected person cannot use it. It stays unchanged (C-6).

In V1 an objection is therefore handled with existing means only:
- intake by the D6 channel `info@pcdcd.org`;
- Staff review;
- resolution by «إنهاء العضوية في الأسرة» (`EndFamilyMembershipAction`, `family-membership.end`, SUPER_ADMIN and
  ADMINISTRATOR) or «تصحيح صلة القرابة» (`CorrectMembershipRelationshipAction`).

**Gaps (reported, no code change; a new workflow is deferred, C-6):**

1. The D6 handling procedure (who reads, assesses, answers and records a request) is still pending.
2. An admitted adult child is not told that the membership exists (point c), so they may not know an objection is
   possible.

- **Oral:** none. **Documented:** the declaration wording (§3). **Legal approval:** none.
- **Approval:** `<reference>` — `<date>` — `<signatories>` — status **OPEN** (wording half recorded in §3; functional
  decisions for points a and d recorded above).

### 7.3 G-03 — Field semantics `mainid`, `fatherid`, `motherid`

- **Proposed decision:** "`mainid` is the person's National ID; `fatherid` / `motherid` hold the father's / mother's
  `mainid`; an unresolved value means <explanation>."
- **Oral:** none on field semantics. C-1 establishes a trusted source but **does not verify the meaning of fields**.
  **Documented:** none. **Technical:** audit P0 §3 (`docs/audits/FB-CIVIL-01A-P0-PROFILING-REPORT.md`):
  - resolution 87.47 % (`fatherid`) and 95.73 % (`motherid`);
  - 0 self-references;
  - 12 / 20 parent-child cycles;
  - 1 id referenced as both father and mother.

  The adapter matches exact values only (`CivilRegistryValues::parentLink`).
- **Required (minimum):** the authority's data dictionary, or a written confirmation of the three fields, including what
  an unresolved value means. **Fallback (owner and legal decision only):** a written owner attestation citing the
  authority's information, supported by aggregate consistency counts on an isolated copy. It is recorded as an owner
  decision, not as source approval.
- **Owner functional approval (2026-11-10):**
  - `mainid` = the person's National ID;
  - `fatherid` = the father's National ID;
  - `motherid` = the mother's National ID.

  This is an **owner** approval. It is **not** official source documentation, and **not** an independent legal or
  security approval. The meaning of unresolved parent references is **not** explained and stays open.
- **Attestation:** `docs/decisions/FB-FAMILY-ADD-01-OWNER-ATTESTATION-A3-A4.md` §ثانياً (no signature captured).
- **Approval:** `FB-FAMILY-ADD-01-G03-G04-20261110` — 2026-11-10 — the project owner (explicit instruction) — status
  **OWNER-APPROVED**. Not official source documentation; not an independent legal or security approval (G-01, G-06
  separate).

### 7.4 G-04 — Gender codes

- **Proposed decision:** "The `gender` codes are exactly `ذكر` (male), `أنثى` (female) and `غير معروف` (unknown); an
  empty value means <meaning>; no other code is issued." Table in §4.
- **Oral:** none. C-1 does not verify the code table. **Documented:** none. **Technical:** P0 shows 4 distinct values
  including one empty row; `CivilRegistryValuesTest` pins the table by its bytes.
- **Required:** as 7.3 (the same document can serve both).
- **Follow-up:** a reviewed commit setting `MAPPINGS_APPROVED = true` with this reference, plus a targeted run of the
  tests it touches (§4). No other test cycle.
- **Owner functional approval (2026-11-10):**
  - `ذكر` = male / son;
  - `أنثى` = female / daughter;
  - `غير معروف` = unknown, not admitted automatically;
  - NULL or empty = unknown, not admitted automatically.

  This matches the current code (`CivilRegistryValues::GENDER`). It is an **owner** approval, not official source
  documentation, and not an independent legal or security approval. Whether the source issues **no other code** is
  **not** confirmed; any other value stays UNSUPPORTED in code.
- **Attestation:** `docs/decisions/FB-FAMILY-ADD-01-OWNER-ATTESTATION-A3-A4.md` §ثالثاً (no signature captured).
- **Prepared change:** `docs/decisions/FB-FAMILY-ADD-01-G04-mappings-approved.patch`; applied by the owner's
  authorization on 2026-11-10 to the local branch `feature/fb-family-add-01-g04-prep` (not merged, not deployed).
- **Approval:** `FB-FAMILY-ADD-01-G03-G04-20261110` — 2026-11-10 — the project owner (explicit instruction) — status
  **OWNER-APPROVED**. Not official source documentation; not an independent legal or security approval (G-06 reviews
  the commit before deployment).

### 7.5 D-06R — Living-only scope of release `1` (stays PROPOSED)

Three dates are kept apart until each is verified:

| Date | Current evidence | Status |
|---|---|---|
| Extraction date (what the configuration needs) | **2026-10-25**, owner attestation (2026-11-10): extraction and import on the same day | **Owner-attested**; no independent document. `FAMILY_REGISTRY_LIVING_SCOPE_RELEASES` stays unset until D-06R is approved |
| Import date into Famboook | **2026-10-25**, owner-reported; profiling ran on 2026-10-25 | Owner-reported |
| Release date (publication in `identity_ref`) | `identity_ref.dataset_releases.published_at` of release 1 | Verifiable read-only; not yet recorded |

- **Proposed decision:** superseded by the draft decision text below (2026-11-10).

**Draft decision text — D-06R (2026-11-10). Status: DRAFT / OPEN — not approved.**

> **قرار اعتماد سياسة التحقق من الحياة — D-06R**
> المشروع: Famboook V1 — الميزة: FB-FAMILY-ADD-01 — السياسة: `REGISTRY_SCOPE_AND_DECLARATION`
>
> 1. **الأساس:** إصدار السجل المدني رقم (1)، المستخرَج من الجهة المصدِرة والمستورد إلى Famboook في **2026-10-25**،
>    بحسب إقرار المالك. وأفاد المالك بأن موظفاً لدى الجهة المصدِرة أبلغه شفهياً بأن البيانات تقتصر على **الأحياء وقت
>    الاستخراج**. هذه **إفادة المالك**، وليست شهادة رسمية موثقة من الجهة.
> 2. **الإقرار:** تُعالج طلبات إضافة الأبناء والبنات بإقرار ربّ الأسرة وفق النسخة `2026-11-v1`، ببندين **منفصلين**
>    يؤكد كلاً منهما على حدة: العضوية والإقامة ضمن الأسرة (`text`)، والحياة في تاريخ الإقرار (`life_text`). غياب
>    تأكيد الحياة يرفض الطلب.
> 3. **حالة الحياة:** لا يُعتبر وجود الشخص في السجل المدني إثباتاً مستقلاً بأنه حي. تُسجَّل حالة الحياة **UNKNOWN**،
>    ولا تُسجَّل ALIVE، ما لم تتوفر أدلة تحقق رسمية.
> 4. **الرفض:** يُمنع قبول الطلبات التي تتعارض مع معلومات الوفاة أو الهوية أو النسب أو العضوية الأسرية القائمة.
> 5. **النطاق المقترح:** الإطلاق العام لجميع الأسر (`FAMILY_REGISTRY_ADMISSION_MODE=ON`). **الإيقاف:** للسياسة وحدها
>    `FAMILY_REGISTRY_LIFE_POLICY=VERIFIER_ONLY`، وللميزة كلها `FAMILY_REGISTRY_ADMISSION_MODE=OFF`، ثم
>    `php artisan config:cache`.
> 6. **مسائل يجب أن تقيّمها المراجعة القانونية والخصوصية:** أن الأساس إفادة شفهية بلسان المالك؛ وأن تحليل P0 لم يجد
>    بيانات وفاة (`deathdate` و `presonstate` فارغان في كل السجلات)؛ وأن **776 سجلاً** تتجاوز أعمارها 110 سنوات.
>
> **مرجع القرار:** __________ — يُحدَّد عند الاعتماد الفعلي، ويطابق الصيغة `[A-Za-z0-9._:/-]{3,80}`، ويُحفظ في كل
> صف إضافة (`life_policy_reference`).
> **المراجعة القانونية والخصوصية:** معلّقة.
> **حالة القرار:** مسودة غير معتمدة.

**Owner decision on the draft above (2026-11-10): OWNER APPROVED — CONDITIONAL.**

| Item | Recorded |
|---|---|
| Policy | `REGISTRY_SCOPE_AND_DECLARATION` |
| Release | `1`, extracted and imported on 2026-10-25 (owner attestation) |
| Life status | Stays `UNKNOWN`; never `ALIVE` without official verification |
| Conditions agreed by the owner | (1) the oral living-only statement is documented as the owner's written attestation; (2) periodic Staff review of admitted children (existing «تأكيد الحياة» action) |
| Objections (owner correction, 2026-11-10) | Electronic objections are **out of V1 scope** (C-6) and are not a condition of this approval. Any problem is handled administratively through existing Staff procedures, with no new feature |
| Other candidate conditions (retention period, age exclusion, re-evaluation per release) | **Not recorded as agreed.** No owner statement of them is on file |
| Legal and privacy review | **PENDING** |
| Final D-06R approval | **OPEN** until that review is recorded |
| Approval reference | **None issued.** `FAMILY_REGISTRY_LIFE_POLICY_APPROVAL` stays unset; no signature is recorded on behalf of counsel |

The owner's conditional approval does not authorize setting the life-policy configuration or `MODE=ON`.

**Owner final decision (2026-11-10): OWNER APPROVED — FINAL.** Reference **`FB-FAMILY-ADD-01-OWNER-20261110-FINAL`**,
issued by the project owner. It matches the configuration format `[A-Za-z0-9._:/-]{3,80}`.

The decision approves D-06R and authorizes proceeding with the general release to all families, under the existing
technical controls:
- release `1`, imported on 2026-10-25;
- registry presence is not proof of life;
- life status `UNKNOWN` without reliable evidence;
- the parent identity, relationship, single-membership and audit controls kept;
- no new objection system in V1;
- no PILOT before the general release.

**Limits stated by the owner:** this is the owner's decision alone. The independent legal, privacy and security reviews
(G-01, G-02, G-06) stay **PENDING**. It is neither their approval nor evidence that every release requirement is met.
Production confirmed `OFF` at the time of the decision.

This reference is the value for `FAMILY_REGISTRY_LIFE_POLICY_APPROVAL`. Each Production change still needs its own
go-ahead.
- **Oral:** C-2 (living-only, oral). **Documented:** none. **Legal approval:** none.
- **Technical:** D-06R is implemented and tested (`4e075c4`, RR-04 readiness check). P0 shows no death data and 776
  records over age 110; the legal review must address this explicitly.
- **Required:**
  1. the written scope statement, or the owner's written attestation of the oral statement (who gave it and when) — the
     legal review decides whether this suffices;
  2. the extraction date in writing;
  3. technical identification of release `1` (2,399,891 records) as that dataset;
  4. the legal and privacy review;
  5. a reference matching `[A-Za-z0-9._:/-]{3,80}`.
- **Attestation draft:** `docs/decisions/FB-FAMILY-ADD-01-OWNER-ATTESTATION-A3-A4.md` §أولاً (unsigned; A-4 OPEN).
  Owner testimony (2026-11-10), not independently verified official documentation:
  - requirement 1: a staff member of the issuing authority told the owner directly that the dataset contained living
    persons only at extraction. The name, role and date of the conversation are not given;
  - requirement 2: the extraction date is 2026-10-25, the same day as the import;
  - requirement 3: release `1` (about 2.4 million records) is that imported dataset.

  Tracking reference: `FB-FAMILY-ADD-01-D06R-20261110`. **It is not a D-06R approval.**

  Still unresolved:
  - requirement 4: the legal and privacy review, including whether owner testimony suffices;
  - requirement 5: the approval reference recorded after that review.
- **Approval:** `<reference>` — `<date>` — `<signatories>` — status **PROPOSED / OPEN**.

### 7.6 F-1 — Marital status mapping (finding of the focused G-06 review)

- **Finding:** with `MAPPINGS_APPROVED = true` (`23c82c2`), a newly created Person's `marital_status` is written from
  the registry through `CivilRegistryValues::MARITAL` (`AdmitRegistryChildAction`; `RegistryChildAdmission`). That
  table was not part of G-04.
- **Owner decision (2026-11-10):** the existing table is approved for V1 **exactly as implemented** in `23c82c2`, with
  no code change:

  | Source value | Application value |
  |---|---|
  | `اعزب` | `SINGLE` |
  | `متزوج` | `MARRIED` |
  | `أرمل` | `WIDOWED` |
  | `مطلق` | `DIVORCED` |
  | `متعدد الزوجات`, `غير مسجل`, NULL / empty, any other value | `UNKNOWN` |

  It applies only to a newly created Person; an attached existing Person is not changed. It does not affect
  eligibility.
- **Status: OWNER-APPROVED** (2026-11-10). A functional owner approval, not official source confirmation and not an
  independent legal or security approval. G-06 stays OPEN.

## 8. Evidence reused (no repetition)

Implementation: 22 commits, `7cdad82` … `d320f3a` (2026-11-01 … 2026-11-07). Historical test results, isolated
environment, synthetic data:

- RR-04 full suites (3,735 tests): SQLite passed after one fix; PostgreSQL showed only the pre-existing failures outside
  this feature.
- `d320f3a`: FamilyPortal on SQLite 561 OK; PostgreSQL 576 / 579, then concurrency 5 / 5 OK on a clean cluster.
- Targeted: PinScripts 6, CivilRegistry 7, PilotAccess 8, Readiness 16.

These are historical results, not Production verification. G-06 reviews this evidence. The only new run required is the
targeted run after the G-04 commit (§7.4).

## 9. Remaining actions before deployment

| # | Action | Type | Depends on |
|---|---|---|---|
| 1 | Record §7.1 G-01 | Legal approval | — |
| 2 | Record §7.2 G-02 (a–e); a code change only if (a) requires it | Legal approval | 1 |
| 3 | Obtain the document for §7.3 / §7.4, or record the fallback decision | Documented / owner decision | 1 |
| 4 | Record §7.5 D-06R with the extraction date and reference | Documented + legal approval | 1, 2 |
| 5 | G-04 commit and targeted test run | Technical | 3 |
| 6 | G-06 review of the §8 evidence and the G-04 commit | Review | 5 |
| 7 | DBA-4 operations statement | Documented | — |
| 8 | Production: `SESSION_DOMAIN` cleanup, backup, deploy `d320f3a` + G-04 commit (feature OFF), DBA `11` → `10` → `12` | Production, separate authorizations | 5, 6, 7 |
| 9 | G-07 approval; then the §5 configuration, grant, `--probe-pin` exit 0, smoke test | Production, owner | 1–8 |
