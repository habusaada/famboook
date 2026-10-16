# FB-GEO-01 — Stage 1B: Reference Data Decision Register

**Date:** 2026-10-15
**Type:** governance and decision documentation only. No code, schema,
seeder, test, permission, configuration or Production change. The source
workbook is unchanged (SHA-256
`37826e400b77e583dbd0c2e7fe34274db6465a293437ce11d0c4d20c4f945e38`).
**Inputs:** `docs/adr/FB-GEO-01-GAZA-GEOGRAPHIC-REFERENCE.md`
(ADR-FB-GEO-01), `docs/audits/FB-GEO-01-STAGE-1A-DATASET-VALIDATION.md`
(Stage 1A, CONDITIONAL PASS).
**Stage 1C readiness:** **CONDITIONAL** (§7). *Update:* the conditions
were locked on 2026-10-15, and Stage 1C is implemented locally (§8).

Status values:
- **APPROVED**: the Stage 1A decision question is fully answered by approved
  policy. A follow-up review may remain, but it does not change the import.
- **PARTIALLY RESOLVED**: the principle is approved, but a concrete detail
  the question asked about is still open.
- **PENDING**: no approved policy answers the question yet.

A general principle is never read as approving an unresolved detail.

---

## 1. Source authority

| Item | Status |
|---|---|
| Source authority | **Palestinian Ministry of Social Development (MoSD)** |
| Basis | Provided and confirmed by the Famboook project owner (2026-10-15); not independently verified |
| Coverage | Gaza Strip, five governorates (consistent with Stage 1A §3.2) |
| Issuing department | **Unverified** |
| Formal release date | **Unverified** (the workbook file was created 2026-08-26 by Apache POI; that is not a release date) |
| Licence / terms of use | **Unverified** |
| Dataset version | **Unverified** |
| Standing in Famboook | The workbook is the **authoritative baseline** for the Famboook geographic reference (ADR D1) |
| External accuracy | **Not independently verified.** Famboook does not claim the data is complete or geographically correct outside what Stage 1A checked |

---

## 2. Approved data-governance principles

Approved 2026-10-15 by the project owner. They are recorded in ADR-FB-GEO-01
§3.2 as G1–G10.

| # | Principle |
|---|---|
| G1 | Preserve original source codes, names and hierarchy. |
| G2 | Do not merge or delete apparent duplicates automatically. |
| G3 | Preserve unusual landmark types and flag them for review. |
| G4 | Preserve geographically unexpected parent relationships and flag them for review. |
| G5 | Allow display-only trimming without overwriting source values. |
| G6 | Use unique internal identifiers and retain original source codes. |
| G7 | Treat source coverage as potentially incomplete. |
| G8 | Landmarks remain optional for residence selection. |
| G9 | Allow partial hierarchy selection, subject to validation. |
| G10 | Require explicit authorization before any future source correction or replacement. |

---

## 3. Decision register

| ID | Stage 1A finding | Approved policy | Status | Remaining question / validation | Stage 1C impact |
|---|---|---|---|---|---|
| D-1 | W6: provenance unknown (publisher, date, licence, version) | Source authority: MoSD, owner-confirmed (§1) | **PARTIALLY RESOLVED** | Issuing department, release date, licence and version remain unverified; owner must accept importing with them recorded as unverified | Provenance metadata is recorded with unverified fields marked; does not block a **local** import; owner acceptance needed before Production |
| D-2 | W4: whitespace in names; store verbatim or trimmed? | G1, G5 | **PARTIALLY RESOLVED** | Verbatim storage and display-only **edge trimming** are approved. Collapsing **inner** double spaces (Stage 1A suggestion) is not covered by G5 and is not approved. Whether search and sorting use trimmed values is open | Import stores names byte-for-byte; no trimmed copy is persisted as the source value |
| D-3 | W2: 5 landmarks with non-landmark types (rows 159, 266, 390, 947, 1455) | G3 (preserve + flag) | **PARTIALLY RESOLVED** | "Exclude" is ruled out by G3. Still open: are flagged landmarks **selectable** (active) or inactive until reviewed? Where is the review flag stored? | Imported with original type; active/inactive value and flag storage must be decided first |
| D-4 | W3: 4 same-parent neighborhood pairs differing only by spaces | G1, G2, G5 | **PARTIALLY RESOLVED** | Both codes are kept (G2). Open: which code, if any, is inactive, and how the UI tells them apart. After display trimming (G5) each pair shows **identical labels** under one parent | Both codes imported; active/inactive per pair must be decided; flag for review |
| D-5 | W1: 10 neighborhoods named after a different parent place | G4, G10 | **APPROVED** | Domain review remains: the owner/MoSD confirms the 10 relationships or supplies a corrected source revision (G10). Selectability follows the cross-cutting rule (§4 Q1) | Imported **as supplied** with a review flag; never re-parented by Famboook |
| D-6 | W9: 7 possible duplicate landmarks (same type, name, neighborhood) | G2 | **APPROVED** | Owner confirmation of whether each pair is two facilities or a duplicate. Any removal or merge is a source correction under G10 | All 7 pairs imported as distinct records; no merge |
| D-7 | W8: codes unique per level only (134 cross-level collisions); code scheme | G6, G1 | **PARTIALLY RESOLVED** | G6 approves unique internal identifiers **plus** retained source codes. **Format is not approved.** Options: (a) `UNIQUE (level, source_code)` with a level-prefixed public code such as `NEI-218`; (b) a generated opaque code plus `UNIQUE (level, source_code)`. Collision handling is §5 | Must be decided before the Stage 1C schema; collisions across levels must never break uniqueness |
| D-8 | W5: completeness unprovable | G7, G10, ADR D1/D13 | **APPROVED** | None for the import. Missing places are added only from an authorized source revision, never by hand | The workbook is imported as the V1 reference; no completeness claim is made |
| D-9 | W7: Area level is a pass-through for 25 of 26 cities; auto-select? | G1 (keep the level), G9 (partial selection, subject to validation) | **PENDING** | UI auto-selection of a single area is **not approved**. G9 permits partial selection but its validation rules (which levels a selection may stop at) are still open (ADR §14 V5) | None on data: all 28 areas are imported (G1). Affects Stage 3 (validation) and Stage 4 (UI) |
| D-10 | I7: should the workbook be tracked by Git? | — (no principle covers it) | **PENDING** | Owner decision. Track in Git (reproducible, checksum-pinned seeding) or keep outside Git and deliver separately with a recorded checksum. Licence is unverified (D-1), so redistributing through a repository host is an owner decision | **Blocks Stage 1C**: the import's input location and delivery to other environments depend on it |

---

## 4. Cross-cutting open questions (Stage 1C prerequisites)

| # | Question | Affects |
|---|---|---|
| Q1 | Are records flagged for review (D-3, D-4, D-5, D-6) **selectable** for residences while the review is open? Policy is needed per flag type or once for all | D-3, D-4, D-5, D-6 |
| Q2 | How is a review flag stored: on the reference row (e.g. a status and reason), in a separate review list, or only in the import report? | D-3 to D-6, R5 |
| Q3 | Unique code format (D-7) | Schema |
| Q4 | Where the workbook lives and how it reaches each environment (D-10) | Import input |
| Q5 | Owner acceptance of unverified provenance fields for Production (D-1) | Production gate |

---

## 5. Code stability and collision handling (applies to D-7, once a format is chosen)

- **Across levels** (current file: 134 collisions): handled by scoping
  uniqueness to `(level, source_code)`; a source code alone is never a key.
- **Within a level** (current file: none, proven in Stage 1A §5): a second
  record with an existing `(level, source_code)` in the **same** source
  version is an import error. Nothing is imported.
- **Across source versions:** the same `(level, source_code)` with a
  different name or parent is reported as **CHANGED** and held for review.
  It is never applied silently, because a reused source code could mean a
  different place (G10).
- The internal identifier and the public code, once issued, never change
  and are never reused for a different place.

---

## 6. Stage 1C import governance — proposed requirements

These are **requirements for a future implementation**. They grant no
permission to implement Stage 1C now.

| # | Requirement |
|---|---|
| R1 | **Idempotent:** re-running the same source version changes nothing and never reactivates a record an administrator deactivated |
| R2 | **Checksum verification:** the import refuses to run unless the source file's SHA-256 matches the expected, recorded value |
| R3 | **Original values retained:** source codes, names (byte-for-byte), parent codes and landmark types are stored as supplied (G1). Display values are derived, never written back |
| R4 | **Internal code stability:** internal identifiers and public codes are permanent (§5) |
| R5 | **Provenance metadata:** each import records source authority (MoSD), file name, checksum, import date, the importing environment, and version / release date / licence (or "unverified") |
| R6 | **Change report:** each run reports records **added**, **changed** (name, parent, type) and **removed** (present before, absent from the new source), plus review flags raised |
| R7 | **No destructive synchronization:** a record missing from a new source is reported, never deleted. Any deactivation needs explicit authorization (G10) |
| R8 | **No family impact:** the import never reads, assigns, re-assigns or clears any family residence or residence location reference |
| R9 | **Local first:** the import is run and tested locally (SQLite and PostgreSQL suites) before any Production deployment, which needs explicit approval |

---

## 7. Stage 1C readiness

**CONDITIONAL.** The data and the governance principles support building
the import. These decisions must be recorded first:

1. **D-7 / Q3:** unique code format.
2. **D-10 / Q4:** Git tracking and delivery of the workbook.
3. **Q1 + Q2:** selectability and storage of review flags (resolves the
   remaining parts of D-3 and D-4).

Not blocking a **local** Stage 1C: D-1 (needed before Production), D-2's
inner-space and search details (display stage), D-9 (Stages 3–4).

Summary: **APPROVED** D-5, D-6, D-8 · **PARTIALLY RESOLVED** D-1, D-2,
D-3, D-4, D-7 · **PENDING** D-9, D-10.

---

## 8. Stage 1C locked decisions and status (2026-10-15)

The owner locked the Stage 1C conditions on 2026-10-15. Stage 1C was then
implemented **locally only** (ADR-FB-GEO-01 §4.3). Production is not
migrated or imported.

| ID | Locked decision | Status now |
|---|---|---|
| D-7 / Q3 | UUID identifiers. Original MoSD codes kept in `source_code`. `UNIQUE (level, source_code)`, after the workbook proved per-level uniqueness. No replacement codes manufactured. Implementation: the id is a deterministic UUIDv5 of (level, source_code) | **APPROVED, implemented locally** |
| D-10 / Q4 | Workbook kept outside Git (`/data/references/*.xlsx` git-ignored); SHA-256 verified before import; never staged or committed | **APPROVED, implemented locally** |
| Q1 | All official records preserved. Flagged records imported active and selectable. Never auto-merged, renamed, relocated or deleted | **APPROVED, implemented locally**. Resolves the open parts of D-3 and D-4 |
| Q2 | Separate `gaza_location_review_flags` table: location, issue code, severity, source audit reference, review status, notes. One flag per (location, issue). Flags never alter source data | **APPROVED, implemented locally** |
| D-3 | Unusual landmark types: preserved, flagged (UNUSUAL_LANDMARK_TYPE), active | **APPROVED** (via Q1) |
| D-4 | Same-parent near-duplicates: both codes imported, flagged (SAME_PARENT_SIMILAR_NAME), active | **APPROVED** (via Q1). Disambiguating identical display labels remains a UI concern (Stage 4) |
| D-1 | Unchanged: MoSD authority; department, release date, licence and version unverified | **PARTIALLY RESOLVED**. Owner acceptance needed before Production |
| D-2 | Unchanged: names stored verbatim, display-only edge trimming (`GazaLocation::displayName()`). Inner-space collapse and search behaviour not approved | **PARTIALLY RESOLVED** |
| D-9 | Unchanged | **PENDING** (Stages 3–4) |
| Q5 | Unchanged | **PENDING** (Production gate) |

**Requirement coverage (§6):**
- **Met locally:** R1, R2, R3, R4, R6, R7, R8, R9.
- **R5 partly met in Stage 1C:** the import printed authority, path,
  checksum and per-level results, but no history table recorded each run.
- **R5 met locally since Stage 1C.1 (2026-10-15):** each executed or refused
  import is recorded in `gaza_geography_import_runs`:
  - authority, file name, actual SHA-256, version (NULL = unverified);
  - status RUNNING / SUCCEEDED / FAILED / REJECTED, start and finish times;
  - source and outcome counts, and a sanitized error summary.

  A missing file and a dry run are not recorded (ADR §4.4).

**Imported locally:** 2,139 records and 228 review flags (§4.3 of the ADR).
**Production:** not migrated, not imported.
