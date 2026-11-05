# FB-FAMILY-ADD-01 — Activation Decision Register and Approval Artifacts

**Status:** prepared by RR-04 (2026-11-05), local only. **Nothing here is approved.** Each item names the decision, its owner, the
exact evidence that closes it and the reviewed change that follows. Until every item is recorded, the feature stays `OFF`
(CLAUDE.md, ADR §12). Runbook: `docs/deployment/FB-FAMILY-ADD-01-PILOT-READINESS-CHECKLIST.md`.

## 1. Production state (reported by the project owner, 2026-11-05)

| Item | State |
|---|---|
| Deployed commit | `7caa92469b10928f7ada3dba842df2c899f71bc9`; migrations applied; the Next.js registry-children page deployed |
| Civil registry | release id `1`, `PUBLISHED`, 2,399,891 records; database `famboook`, schema `identity_ref` (same database as Laravel) |
| Lookup login, fingerprint key, `APP_DEBUG=false` | PASS |
| DBA-1 … DBA-3 | `identity_ref_lookup.release_pin_held(integer,integer)` MISSING; `10-registry-admission-pin.dba.sql` not verified as applied |
| Switches | mode `OFF`; binding `false`; life policy `VERIFIER_ONLY`; no approved declaration |
| Code gates | `CivilRegistryValues::MAPPINGS_APPROVED = false`; no permission holder |

## 2. Decisions still required

| # | Decision | Owner | Exact evidence that closes it | Change that follows (each its own reviewed commit or step) |
|---|---|---|---|---|
| 1 | **G-01** legal basis and purpose | Project owner + legal counsel | A dated, signed decision covering this purpose (a head admits their own son or daughter from the civil registry), G1 / OD-08 as they apply, and the OD-10 reading (not genealogy) | None in code; the reference is cited in item 7 |
| 2 | **G-02** adult children's data and the declaration wording | Project owner + legal and privacy counsel | A dated decision approving: visibility to the head, notice and objection position, retention of `family_registry_admission_*` rows, residual disclosure, and **the exact wording of §3, verbatim** | §3 commit; then `FAMILY_REGISTRY_ADMISSION_DECLARATION_VERSION=2026-11-v1` |
| 3 | **G-03** parent-link semantics | Civil registry data owner | Written confirmation that `fatherid` / `motherid` hold the parent's `mainid` (National ID), what an unresolved value means (12.53 % of populated `fatherid`, 4.27 % of `motherid`, audit P0 §3), and that `mainid` is the person's National ID | None in code (the adapter already matches exact `fatherid` / `motherid` only) |
| 4 | **G-04** gender codes | Civil registry data owner | The authoritative code table for `gender`, confirming §4 | §4 commit (`MAPPINGS_APPROVED = true`) |
| 5 | **D-06R** revised life policy (instead of G-05) | Project owner + legal and privacy counsel | (a) the issuing authority's written statement that release `1`'s source export lists living persons only, **with its extraction date**; (b) legal and privacy review of admitting on the head's declaration of current life, recorded `UNKNOWN`; (c) an approval reference `[A-Za-z0-9._:/-]{3,80}` | Configuration only (§5) |
| 6 | **G-06** independent security review | Engineering lead + security reviewer | A review of the passing evidence: `RegistryAdmissionCivilRegistryPostgresTest`, `RegistryAdmissionPinScriptsPostgresTest`, `AdmitRegistryChildConcurrencyTest`, `RegistryAdmissionPilotAccessTest` and the full PostgreSQL suite on the candidate commit | None |
| 7 | **G-07** activation | Project owner | A recorded approval naming: the commit, mode `PILOT`, the pilot Family id(s), the pilot account(s) by Family code, DBA-1 … DBA-3 output (precheck and verify), the DBA-4 operations statement, the D-06R reference | Checklist §3 steps 3 – 8 |
| 8 | **DBA-4** one cluster, one database | Operations | A written statement that the application connection and the lookup login reach the same primary and database under every route, pooler and failover; plus the `12-…-verify.sql` and `--probe-pin` output | None |

The **one decision** that unblocks the declaration is item 2: approval of the §3 wording, both statements, verbatim. No
other declaration is prepared, and none is approved by this document.

## 3. Declaration to approve (G-02)

Source: `backend/config/family_registry_admission.php`, draft `2026-11-draft-2` (unchanged). Two statements, each confirmed
separately in the UI (two checkboxes); the second is delivered only while D-06R is active.

1. Household membership (`text`):
   > أُقرّ بأن هذا الابن / هذه الابنة من أفراد أسرتي ويقيم/تقيم فعليًا ضمن هذه الأسرة، وأن هذا الإقرار صحيح على مسؤوليتي.
2. Current life (`life_text`):
   > وأُقرّ بأن هذا الابن / هذه الابنة على قيد الحياة في تاريخ هذا الإقرار، وأتحمّل مسؤولية صحة ذلك.

A draft is never approved in place (a version's text never changes). After the decision, a reviewed commit adds a new
version with the same two texts, character for character:

```php
// G-02 approved <date>, reference <G-02 reference>: the wording of 2026-11-draft-2, verbatim.
'2026-11-v1' => [
    'approved' => true,
    'text' => 'أُقرّ بأن هذا الابن / هذه الابنة من أفراد أسرتي ويقيم/تقيم فعليًا ضمن هذه الأسرة، وأن هذا الإقرار صحيح على مسؤوليتي.',
    'life_text' => 'وأُقرّ بأن هذا الابن / هذه الابنة على قيد الحياة في تاريخ هذا الإقرار، وأتحمّل مسؤولية صحة ذلك.',
],
```

The same commit updates `RegistryAdmissionReadinessTest::test_the_shipped_catalogue_holds_only_the_unapproved_adr_draft…`,
which pins the shipped catalogue as unapproved.

## 4. Gender table to confirm (G-04)

Observed in the 2026-10-25 export (audit P0: 4 distinct values including the one empty row), matched exactly as stored:

| Stored value | Mapping | Admission |
|---|---|---|
| `ذكر` | `MALE` → relationship `SON` | eligible when every other rule passes |
| `أنثى` | `FEMALE` → relationship `DAUGHTER` | eligible when every other rule passes |
| `غير معروف` | none | always `NOT_ELIGIBLE` |
| empty, or any other value | none | always `NOT_ELIGIBLE` |

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
