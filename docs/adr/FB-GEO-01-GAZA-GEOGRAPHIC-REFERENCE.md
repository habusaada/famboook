# ADR-FB-GEO-01 — Unified Gaza Geographic Reference

**Status:** Approved architecture (FB-GEO-01 Phase 0, 2026-10-15).
Stage 1A dataset validation done (CONDITIONAL PASS,
`docs/audits/FB-GEO-01-STAGE-1A-DATASET-VALIDATION.md`); Stage 1B
reference-data governance recorded (§3.2–§3.4,
`docs/audits/FB-GEO-01-STAGE-1B-DECISION-REGISTER.md`; Stage 1C
readiness CONDITIONAL). Stage 1C implemented **locally** (§4.3).
**Implementation:** in progress, local only.

| | Status |
|---|---|
| Approved design | This record (Phase 0, amended in Stages 1B and 1C) |
| Implemented local schema | `gaza_locations` and `gaza_location_review_flags` (Stage 1C, §4.3); `gaza_geography_import_runs` import history (Stage 1C.1, §4.4) |
| Imported local data | Official workbook imported into the local development database: 2,139 records and 228 review flags (Stage 1C, §4.3) |
| Implemented local API | Read-only Staff endpoints `/api/v1/gaza-locations/…` (Stage 2B, §8.1) |
| Implemented local residence linkage | `family_residences.original_location_id` / `current_location_id`, validation, staff API fields; location details `is_active` (Stage 3.2, §4.2) |
| Implemented local selector component | Reusable `GazaLocationSelector`, query hooks and types (Stage 4A, §8.2) |
| Implemented local Staff UI | Selector in Staff registration and residence correction; residence summaries shown on the family residence tab (Stage 4B, §8.3) |
| Verified locally | Stage 3.2–4B end to end on the real API, a disposable PostgreSQL database and a real browser; two defects fixed (Stage 4C, §8.4; `docs/audits/FB-GEO-01-STAGE-4C-INTEGRATION-VERIFICATION.md`) |
| Implemented local Family PWA reference API | Read-only family mirrors (governorates, children, details) and a source-aware selector (Stage 5, §8.5) |
| Implemented local RESIDENCE_UPDATE v2 | Owner-approved 2026-10-16: optional location references in the family residence request, review and apply; v1 requests still read; family profile summaries; Family PWA form selectors; PX-1 fixed (Stage 5, §8.6) |
| Not built | Coordinator access, any permission, reporting change, location deactivation workflow |
| Production | **Not migrated, not imported.** Every stage (§12) needs its own approval, and no migration, seeder or import runs in Production without explicit approval (docs/12 §3) |

**Resolves:** docs/02 **PDD-006** "Final residence geographic hierarchy" —
*architecturally resolved, implementation pending.*

**Registered ADRs:** docs/02 DD-ADR-036 (data), docs/04 DB-ADR-063
(schema), docs/06 AUTH-ADR-094 (authorization — no RBAC change), docs/07
RM-ADR-061 (roadmap). docs/03 "Residence decision (governorate / city)" is
amended to point here. This file is the complete design record those
entries point to.

> Repository note: Famboook records ADRs inside the numbered documents,
> each in its own register. This dedicated file holds the full design
> because it spans several registers (same convention as
> `docs/adr/CO-02-COORDINATOR-REQUESTS.md`); the registers stay
> authoritative for their own decisions.

> Reading convention. Sections marked **CURRENT IMPLEMENTATION** describe
> the code at `main` 8305b61. Sections marked **APPROVED FUTURE DESIGN**
> describe what is approved but not built. Where a future design detail is
> still open it is listed in §14 rather than decided here.

---

## 1. Context — CURRENT IMPLEMENTATION (verified against `main` at 8305b61)

| Fact | Where |
|---|---|
| Residence is one table, `family_residences`, with one current row per Family (partial unique index `uq_family_current_residence`) | migration `2026_09_23_153943_create_family_residences_table`; docs/04 §24–25 |
| Current address is free text: `governorate`, `city`, `area`, `neighborhood` (VARCHAR NULL) and `address_text` (TEXT NULL); NULL = not recorded | same migration; docs/02 §19; docs/03 "Residence decision" |
| Original residence (before displacement) is one free-text field, `original_residence_text` | migration `2026_09_24_100000_add_displacement_and_alternate_contact_fields` |
| Displacement: `displacement_status` (DISPLACED / NOT_DISPLACED / NULL = not collected) and free-text `displacement_location_text`, allowed only when DISPLACED | same migration; PostgreSQL CHECKs `chk_residence_displacement_status`, `chk_residence_displacement_location` |
| No geographic reference table, no geographic foreign key, no maps; `latitude` / `longitude` exist and are accepted by Staff registration but no UI collects or shows them (PDD-024 open) | schema; docs/02 §19 |
| Residence history is designed (`is_current`, `ended_at`) but only in-place correction exists; `residence.change` is unassigned | `UpdateFamilyResidenceAction`; docs/06 §46 |
| Writes go through `CreateFamilyResidenceAction` (first residence: registration, import, coordinator REGISTER_FAMILY APPLY) and `UpdateFamilyResidenceAction` (correction; `RESIDENCE_UPDATED` / `DISPLACEMENT_UPDATED` activity events) | `app/Actions/` |
| Staff correction: `PATCH /api/v1/families/{family}/residence`, permission `residence.update` (SUPER_ADMIN, ADMINISTRATOR, DATA_ENTRY, SOCIAL_WORKER) | `routes/api.php`; `UpdateFamilyResidenceRequest`; docs/06 §46 |
| Family Portal: RESIDENCE_UPDATE Change Request, payload version 1, exactly eight text/status fields, every field `present`, unknown keys refused, `baseValues` stale-write check over those eight fields | `ResidenceUpdateHandler`; docs/02 §47; docs/11 §30a |
| Coordinator REGISTER_FAMILY captures the ORIGINAL residence only (`original_residence_text`, required) | `RegisterFamilyHandler`; docs/adr/CO-02 D10 |
| Import writes `original_residence_text` only, never infers a current address | `ImportApplyPlanner`, `ApplyImportRowAction`; docs/03 §"المدينة" mapping |
| Reports, data quality, targeting and exports read the free text (top displacement locations by exact stored text; `DISPLACED_WITHOUT_LOCATION`; `LIKE` targeting) | `PopulationAggregates`, `DataQualityReport`, `FamilyTargeting`, `ExportFieldCatalog` |
| Reference data follows `code` / `name` / `is_active` / `sort_order`, seeded idempotently, deactivated never deleted; hierarchical reference integrity has a precedent in Clan → Branch Group → Branch composite keys | `disability_types`, `create_clan_structure_tables`, `DisabilityTypeSeeder` |
| No reference-data management endpoint exists; reference values change only through versioned seeders | `routes/api.php`; docs/06 §56 |

**Problem.** Free text cannot be counted, filtered or validated
consistently ("خانيونس" / "خان يونس" / "خانيونس - المواصي"), so the
geographic distribution of original and current residence — the core
question for a displaced population — cannot be answered reliably.

---

## 2. Approved decisions

| # | Decision |
|---|---|
| D1 | The supplied Gaza geographic classification spreadsheet is the **primary geographic reference**. No geographic record is invented, guessed or completed from other sources. |
| D2 | Hierarchy: **Governorate → City → Area → Neighborhood → Landmark**. |
| D3 | The **same** reference serves the original residence and the current residence. |
| D4 | **When the Family is DISPLACED, the current residence IS the displacement location.** No third, independent geographic displacement address is created. |
| D5 | When the Family is NOT_DISPLACED, the current residence is its ordinary current accommodation. |
| D6 | Geographic selection is limited to the **five Gaza Strip governorates**. |
| D7 | Preferred storage: one self-referencing `gaza_locations` table, subject to technical validation (§14). |
| D8 | Proposed residence references: nullable `original_location_id` and `current_location_id` on `family_residences`. |
| D9 | Every existing free-text address field is **preserved** for backward compatibility. |
| D10 | Existing family addresses are **never** automatically inferred, overwritten, deleted or backfilled. |
| D11 | RESIDENCE_UPDATE payload **v1 stays compatible**; a v2 payload is documented as future design (§7). |
| D12 | **No RBAC change**; reviewer and coordinator workflows are unchanged. |
| D13 | Reference management is **centrally controlled**. |
| D14 | The **Landmark** level is optional. |
| D15 | Historical residence tracking (`residence.change`) remains a **separate** implementation concern. |

---

## 3. Geographic reference — APPROVED FUTURE DESIGN

### 3.1 Levels

```text
GOVERNORATE   one of the five Gaza Strip governorates (D6)
  CITY
    AREA
      NEIGHBORHOOD
        LANDMARK  optional (D14)
```

Each node has exactly one parent of the level directly above it; a
GOVERNORATE has none. Whether the spreadsheet skips a level anywhere (for
example a neighborhood directly under a city, or a landmark under an
area) is a technical validation (§14 V2); the rule above is not relaxed
without a further decision.

### 3.2 Source and governance

**Source (amended Stage 1B, 2026-10-15).**
- The source is the workbook `data/references/gaza-geographic-classification.xlsx`
  (SHA-256 `37826e400b77e583dbd0c2e7fe34274db6465a293437ce11d0c4d20c4f945e38`).
  It is present locally, validated in Stage 1A, and **kept outside Git**.
  Stage 1C D-10: `/data/references/*.xlsx` is git-ignored, and the importer
  verifies the SHA-256 before reading.
- **Source authority: Palestinian Ministry of Social Development (MoSD).**
  This was provided and confirmed by the Famboook project owner and has not
  been independently verified.
- Coverage: the Gaza Strip, five governorates.
- **Unverified:** the issuing department, formal release date, licence and
  dataset version.
- The workbook is the **authoritative baseline** for Famboook (D1). Its
  external geographic accuracy and completeness have **not** been
  independently verified.
- It is geographic reference data, not personal data.

**Approved data-governance principles (Stage 1B, owner-approved
2026-10-15).** Their application to each Stage 1A finding is in the
decision register.

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

**Maintenance rules (Phase 0, still in force).**
- Every node gets a stable machine `code` (docs/02 §70 Reference Code
  Principle). Its Arabic name is taken from the workbook as supplied, and
  spelling is never "corrected" during import (G1). The code **format** is
  pending (register D-7, §4.1).
- Nodes are deactivated, never deleted, once any residence references them
  (docs/06 §56).
- Changes are centrally controlled (D13): a reviewed, versioned dataset is
  applied by an idempotent import and deployed under the normal approval
  gate. Corrections or replacement of the source need explicit authorization
  (G10). No user-facing editor is part of this ADR.

### 3.3 Stage 1C import governance — proposed requirements

These are requirements for the future import, recorded in Stage 1B. They
are **not** an authorization to implement it. Details and rationale are in
register §5–§6.

- **R1 Idempotent:** re-running the same source changes nothing and never
  reactivates a deactivated record.
- **R2 Checksum:** the import refuses a source file whose SHA-256 differs
  from the expected, recorded value.
- **R3 Original values retained** byte-for-byte (codes, names, parents,
  types). Display values are derived and never written back.
- **R4 Internal code stability:** issued identifiers and codes never change
  and are never reused.
- **R5 Provenance metadata** per import: authority, file, checksum, date,
  environment, and version / release date / licence (or "unverified").
- **R6 Change report:** records added, changed (name, parent, type) and
  removed, plus review flags raised. A source code reused with a different
  name or parent is CHANGED and held for review.
- **R7 No destructive synchronization:** a record absent from a new source
  is reported, never deleted. Deactivation needs explicit authorization.
- **R8 No family impact:** no family residence or location reference is
  read, assigned, re-assigned or cleared.
- **R9 Local first:** tested locally on SQLite and PostgreSQL. Production
  needs explicit approval.

### 3.4 Stage 1B decision status (summary)

| Status | Decisions (Stage 1A §11) |
|---|---|
| APPROVED | D-5 parent anomalies imported as supplied and flagged · D-6 possible duplicate landmarks imported, not merged · D-8 coverage accepted as potentially incomplete |
| PARTIALLY RESOLVED | D-1 provenance (MoSD confirmed; details unverified) · D-2 whitespace (edge trim for display approved; inner-space collapse and search behaviour open) |
| PENDING | D-9 single-area auto-selection |
| APPROVED in Stage 1C | D-3 / D-4 (flagged records imported active and selectable, Q1) · D-7 (UUID ids, `source_code` kept, `UNIQUE (level, source_code)`) · D-10 (workbook outside Git, checksum-verified) · Q2 (separate `gaza_location_review_flags` table) |

Stage 1C was **CONDITIONAL** on D-7, D-10 and Q1–Q2. The owner locked all
four on 2026-10-15 (register §8).

---

## 4. Proposed schema — APPROVED FUTURE DESIGN (not migrated)

### 4.1 `gaza_locations` (D7)

```text
id               BIGINT PK
uuid             UUID UNIQUE            -- public reference, as clans/branches
code             VARCHAR(50) UNIQUE     -- stable, immutable after creation
name             VARCHAR(150)           -- Arabic name as in the source
level            VARCHAR(20)            -- GOVERNORATE | CITY | AREA | NEIGHBORHOOD | LANDMARK (CHECK)
parent_id        BIGINT NULL FK gaza_locations.id RESTRICT
governorate_id   BIGINT NULL FK gaza_locations.id RESTRICT  -- the node's governorate (itself for a governorate)
sort_order       INTEGER DEFAULT 0
is_active        BOOLEAN DEFAULT TRUE
source_ref       VARCHAR NULL           -- row key in the source spreadsheet (§14 V3)
created_at / updated_at
```

Proposed integrity (PostgreSQL; SQLite tests rely on application
validation, as for the existing displacement CHECKs):

- `CHECK (level IN (...))`;
- `CHECK ((level = 'GOVERNORATE') = (parent_id IS NULL))`;
- a parent of the level directly above (CHECK via a composite key
  `(id, level)` referenced by `(parent_id, parent_level)`, or a trigger —
  §14 V4);
- at most five active GOVERNORATE rows is a **seed/validation** rule, not a
  CHECK.

*Stage 1A/1B note:* source codes are unique per level but not across
levels (134 collisions), so `code UNIQUE` above cannot be the raw source
code. Under G6, unique internal identifiers are approved and original
source codes are retained, with uniqueness on `(level, source_code)`. The
public `code` **format is pending** (register D-7). Landmark types
(60 source types) need their own small reference (Stage 1A §9.1).

`governorate_id` is a property of the reference node, not a registry
statistic: it is stored so reports group by governorate without a
recursive query. It never changes for a node.

### 4.2 `family_residences` additions (D8)

```text
original_location_id  UUID NULL FK gaza_locations.id RESTRICT   -- UUID since Stage 1C (D-7)
current_location_id   UUID NULL FK gaza_locations.id RESTRICT
```

- Each stores the **deepest known node** of its hierarchy; ancestors are
  derived from the reference, never stored on the residence (no redundant
  derived data). Whether a selection may stop above NEIGHBORHOOD (e.g.
  "governorate and city known only") is §14 V5.
- Both are nullable: NULL = not structured (not recorded, legacy, or
  outside the Gaza reference).
- `current_location_id` is valid for DISPLACED, NOT_DISPLACED and NULL
  status alike (§5). It must **not** inherit the DISPLACED-only rule of
  `chk_residence_displacement_location`.
- Existing columns, CHECKs and the partial unique index are unchanged.
- One additive migration; `down()` drops the two columns, then (separate
  migration) the table. No backfill.

**§4.2 as built locally — Stage 3.2 (2026-10-15; Production NOT migrated).**
Migration `2026_10_22_090003_add_location_references_to_family_residences_table`.
Contract: `docs/audits/FB-GEO-01-STAGE-3-RESIDENCE-LINKAGE-AUDIT.md` (D-1 to
D-9).

- **Columns:** `family_residences.original_location_id` and
  `.current_location_id`. Each is a nullable UUID (native `uuid` on
  PostgreSQL), FK `gaza_locations` RESTRICT, with one index. No CHECK, no
  backfill, and no existing column, CHECK or row changed.
  - `down()` refuses while any reference exists, and otherwise uses a 5-second
    lock timeout.
  - On SQLite (tests), the migration restores the table's index definitions
    after Laravel's table rebuild, so the partial
    `uq_family_current_residence` keeps its `WHERE is_current = true`.
- **Validation:** `App\Rules\SelectableGazaLocation` runs after
  `nullable|bail|uuid` (a malformed id is a 422, never a PostgreSQL 22P02).
  - The location must exist; a NEW selection must be active; the stored value
    is accepted unchanged even if inactive; any level is accepted.
  - The actions re-check every changed reference inside the write
    transaction with `FOR SHARE` (`GazaLocationSelection`).
  - Omitted = unchanged, `null` = cleared. Ids are stored lower-case.
- **Actions:**
  - `CreateFamilyResidenceAction` accepts both keys. Callers that omit them
    (coordinator REGISTER_FAMILY, import) store NULL as before.
  - `UpdateFamilyResidenceAction` groups `current_location_id` with the
    address (RESIDENCE_UPDATED) and `original_location_id` with displacement
    (DISPLACEMENT_UPDATED). A displacement-status change never clears
    `current_location_id`.
  - The RESIDENCE_UPDATE v1 contract is unchanged; its payload can never
    carry the keys.
- **Staff `FamilyDetailResource`:** additive keys in `residence`:
  - `original_location_id`, `current_location_id`;
  - `original_location` / `current_location` summaries,
    `{id, level, name, is_active, path: [{id, level, name, is_active}]}`,
    in at most six location queries for both together.

  The Family PWA, coordinator, report and export contracts are unchanged.

**Local result:**
- Only this migration was applied to the `famboook` development database
  (PostgreSQL 18.6); the 8 unrelated pending migrations stay pending.
- The local database holds 0 residence rows, so there was no data to change.
- No residence is linked.

### 4.3 As built locally — Stage 1C (2026-10-15; Production NOT migrated)

**Locked decisions (owner, 2026-10-15):**
- D-7: UUID identifiers; source codes kept in `source_code`; `UNIQUE
  (level, source_code)`; no replacement codes manufactured.
- D-10: the workbook stays outside Git and is checksum-verified.
- Q1: flagged records are imported active and selectable, never merged,
  renamed, relocated or deleted.
- Q2: findings live in a separate `gaza_location_review_flags` table.

**`gaza_locations`** (migration `2026_10_22_090000_create_gaza_locations_table`)

```text
id                  UUID PK         -- deterministic UUIDv5 of (level, source_code)
level               VARCHAR(20)     -- GOVERNORATE | CITY | AREA | NEIGHBORHOOD | LANDMARK
source_code         VARCHAR(20)     -- official code as text, as supplied
name                VARCHAR(255)    -- source name byte-for-byte (spaces kept)
parent_id           UUID NULL
parent_level        VARCHAR(20) NULL
landmark_type_code  VARCHAR(20) NULL  -- LANDMARK only (LAN_TYPE)
landmark_type_name  VARCHAR(255) NULL -- LANDMARK only (LAN_TYPE_TITLE)
is_active           BOOLEAN DEFAULT TRUE
sort_order          INTEGER NULL    -- optional display order (not set by the import)
created_at / updated_at
UNIQUE (level, source_code); UNIQUE (id, level)
FK (parent_id, parent_level) → (id, level) RESTRICT   -- fk_gaza_locations_parent
PostgreSQL CHECKs: chk_gaza_location_level, chk_gaza_location_parent
  (each level's parent is the level above; GOVERNORATE has none),
  chk_gaza_location_landmark_type, chk_gaza_location_source_code
```

**`gaza_location_review_flags`** (migration `2026_10_22_090001_…`)

```text
id                UUID PK
gaza_location_id  UUID FK gaza_locations RESTRICT
issue_code        VARCHAR(50)   -- PARENT_PLACE_MISMATCH | UNUSUAL_LANDMARK_TYPE |
                                --  SAME_PARENT_SIMILAR_NAME | POSSIBLE_DUPLICATE_LANDMARK | NAME_WHITESPACE
severity          VARCHAR(10)   -- WARNING | INFO (CHECK)
source_reference  VARCHAR(100)  -- e.g. "FB-GEO-01 Stage 1A W2 (§6.2)"
review_status     VARCHAR(30) DEFAULT 'OPEN'  -- OPEN | CONFIRMED | CORRECTION_REQUIRED | DISMISSED (CHECK)
notes             TEXT NULL     -- finding and source rows
created_at / updated_at
UNIQUE (gaza_location_id, issue_code)
```

**Differences from the §4.1 draft:**

| Draft (§4.1) | As built | Reason |
|---|---|---|
| BIGINT `id` + `uuid` + `code UNIQUE` | UUID `id` + `source_code` | D-7. The source code is unique per level only (Stage 1A W8) |
| `governorate_id` | not created | Only reporting needs it; deferred to that stage (§9), derivable from the parent chain |
| separate landmark-types reference (Stage 1A §9.1 option) | type code and name on the landmark row | Stage 1C requirement ("landmark type fields where applicable"); kept as supplied |
| `source_ref` | not created | `source_code` is the key; flags carry source row numbers |
| `sort_order INTEGER DEFAULT 0` | nullable, unset | No order in the source |

**Import** (`php artisan famboook:import-gaza-geography [--path=] [--dry-run]`):
- Components: `ImportGazaGeographyAction`, `GazaGeographyWorkbook`,
  `GazaGeographySource`.
- The import is **not** part of `DatabaseSeeder`, and in production the
  command asks for confirmation.
- R1 idempotent, by deterministic ids and the `(level, source_code)` /
  `(location, issue)` keys.
- R2 SHA-256 verification.
- R3 values as supplied; `LAN_TITLE` is verified to be "type - name" and
  not stored.
- R4 stable ids.
- R6 report of created, already imported and missing-from-source records.
- R7 nothing deleted.
- R8 no family or residence access.
- A source that would change an imported name, parent or type aborts with
  every difference listed (G10). Contradictory or invalid rows abort; none
  is skipped.
- **R5** was only partly met in Stage 1C; it is met since Stage 1C.1
  (§4.4).

**Local result:** `famboook` development database, PostgreSQL 18.6; only
these two migrations were applied. 2,139 records: 5 governorates, 26 cities,
28 areas, 234 neighborhoods, 1,846 landmarks. 228 OPEN flags:
- 10 PARENT_PLACE_MISMATCH
- 5 UNUSUAL_LANDMARK_TYPE
- 8 SAME_PARENT_SIMILAR_NAME
- 14 POSSIBLE_DUPLICATE_LANDMARK
- 191 NAME_WHITESPACE

All records are active. A second run created nothing.

### 4.4 Import history and lifecycle — Stage 1C.1 (local; Production NOT migrated)

**`gaza_geography_import_runs`** (migration
`2026_10_22_090002_create_gaza_geography_import_runs_table`). It has no
foreign key to the geographic tables and is kept apart from the official
records.

```text
id                             UUID PK
source_authority               VARCHAR(150)   -- e.g. MoSD for the official source
source_filename                VARCHAR(255)   -- file name only, never a path
source_sha256                  VARCHAR(64)    -- SHA-256 of the file actually read
source_version                 VARCHAR(50) NULL  -- NULL = unstated (never invented)
status                         VARCHAR(20)    -- RUNNING | SUCCEEDED | FAILED | REJECTED
started_at                     TIMESTAMP
finished_at                    TIMESTAMP NULL  -- NULL exactly while RUNNING
governorates_count … landmarks_count   INT NULL   -- source counts (NULL if rejected before parsing)
locations_created, locations_unchanged, locations_missing_from_source,
flags_created, flags_unchanged         INT NULL   -- set when SUCCEEDED
error_summary                  TEXT NULL      -- sanitized
created_at / updated_at
UNIQUE (status) WHERE status = 'RUNNING'       -- uq_gaza_import_single_running (both drivers)
PostgreSQL CHECKs: chk_gaza_import_status, chk_gaza_import_finished, chk_gaza_import_sha256
```

`locations_missing_from_source` is an addition to the requested fields: it
records R6's "removed" count durably.

**Lifecycle** (`ImportGazaGeographyAction`):

| Step | What happens | Recorded as |
|---|---|---|
| 1 | The source file must exist | Missing file: **CLI error only, no run** (nothing identifies the source) |
| 2 | Checksum, sheet, columns and every record validated (read-only); audited findings must exist in the source | Failure: **REJECTED** run with the actual file checksum; counts NULL |
| 3 | PostgreSQL session advisory lock taken; a RUNNING run is inserted and committed **outside** the import transaction | Another import holds the lock: **REJECTED** (CONCURRENT_IMPORT) |
| 4 | **One import transaction:** geographic records, review flags, and the run's update to SUCCEEDED with counts | Committed together, so SUCCEEDED ⇔ data committed |
| 5 | Any failure in step 4 rolls the whole transaction back (no partial records); then the run is closed outside it | A refusal (e.g. REVISION_CONFLICT): **REJECTED**. Any other error: **FAILED** |
| 6 | The original exception is rethrown unchanged; the lock is released in `finally` | — |

Further rules:
- A **dry run** computes the report, rolls back and records no run.
- **Repeated successful imports** are separate SUCCEEDED runs, even when
  nothing is created.
- **Sanitization:** a refusal stores its reason, message and problems
  (source rows and values). An unexpected error stores only its class and
  SQLSTATE, never its message, SQL, host, port, database name, path or
  credentials. If recording a run itself fails, the error is passed to
  Laravel's `report()` and the original exception still propagates.

**Concurrency:**
- On **PostgreSQL**, `pg_try_advisory_lock` (key `0x464247454F3031`) is
  held for the whole run. It is shared by every process on the database and
  released automatically if the process dies.
- Because the lock proves no other import is alive, a leftover RUNNING row
  (from a killed process) is closed as FAILED ("Interrupted") by the next
  import. This changes only the import log, never geographic records.
- The `LOCK TABLE gaza_locations IN SHARE ROW EXCLUSIVE MODE` inside the
  transaction is kept.
- The **single-RUNNING unique index** is the database backstop on every
  driver. On SQLite (tests only) a RUNNING row refuses the import.
- Location ids stay deterministic (UUIDv5). Re-imports never duplicate
  locations or flags, and never change review statuses.

**Local result (2026-10-15):** only this migration was applied to the
`famboook` development database (PostgreSQL 18.6); unrelated pending
migrations were left pending. A re-import was recorded as one SUCCEEDED run
(0 created, 2,139 unchanged, 228 flags unchanged). The location and flag
fingerprints were identical before and after.

---

## 5. Residence semantics — APPROVED FUTURE DESIGN

| Field | Meaning |
|---|---|
| `original_location_id` | The Family's residence **before displacement** (structured); never a birthplace. *Amended by ADR FB-GEO-02 G2-2: decoupled from `original_residence_text`, which is the identity-card residence.* |
| `current_location_id` | Where the Family lives **now**. |
| `displacement_status = DISPLACED` | `current_location_id` **is the displacement location** (D4). |
| `displacement_status = NOT_DISPLACED` | `current_location_id` is the ordinary current accommodation (D5). |
| `displacement_status = NULL` | Status not collected; `current_location_id` is still the current place if recorded. Never read as NOT_DISPLACED. |

There is **no** `displacement_location_id` (D4). A change of
`displacement_status` never clears `current_location_id`. *(Amended by ADR
FB-GEO-02 G2-6: switching away from DISPLACED no longer clears
`displacement_location_text`; it is kept as historical text. The original
wording follows.)* Switching away
from DISPLACED still clears the legacy `displacement_location_text` as it
does today.

Activity events (future): `current_location_id` is an address field
(`RESIDENCE_UPDATED`); `original_location_id` is a displacement field
(`DISPLACEMENT_UPDATED`), matching the existing grouping of
`original_residence_text`.

---

## 6. Legacy compatibility — APPROVED FUTURE DESIGN

- `governorate`, `city`, `area`, `neighborhood`, `address_text`,
  `original_residence_text` and `displacement_location_text` stay, with
  their current meaning, validation and CHECKs (D9).
- No migration, seeder, command or APPLY copies, parses, matches or deletes
  text into a location reference or vice versa (D10). A structured value is
  only ever set by an explicit human selection.
- `address_text` keeps the street-level detail the reference does not hold.
- For a DISPLACED Family, the current-address text fields and
  `displacement_location_text` may both describe the displacement place
  (they are legacy, separately entered values). Neither is rewritten when
  `current_location_id` is set.
- Display: the structured path when present; otherwise the legacy text;
  otherwise "غير مسجّل". The exact Staff layout is a UI-stage decision.
- An original residence outside the Gaza reference keeps
  `original_location_id` NULL and its text (D6).
- Any future text-to-location *suggestion* tool would be read-only and
  need its own approval; none is approved here.

---

## 7. Change Requests — RESIDENCE_UPDATE

**CURRENT IMPLEMENTATION:** payload version 1, the eight fields listed in
docs/02 §47; strict key set; all fields `present`; staleness over the
eight fields.

**APPROVED FUTURE DESIGN (D11):**

- v1 stays accepted unchanged, and an open v1 request created before the
  schema change still validates, stays stale-checked over its eight fields
  and APPLIES unchanged. A deployed Family PWA (including a cached service
  worker) keeps working.
- v2 = the v1 fields **plus** optional `original_location` and
  `current_location`, sent as location `code` (never an internal id), each
  nullable. A v2 request is stored with `payload_version = 2`; its base
  values add the two references.
- Only active nodes are selectable in a new submission; a node deactivated
  after submission is a precondition failure at APPLY, not a silent change.
- APPLY still runs through `UpdateFamilyResidenceAction`; review, approval
  and APPLY transitions (APPROVED ≠ APPLIED) are unchanged.
- Coordinator REGISTER_FAMILY is unchanged (D12); adding a structured
  original residence to it would be a separate, approved change.

*Superseded for new submissions by ADR FB-GEO-02 (payload v3, 2026-10-17):
v3 carries only the two references, `address_text` and
`displacement_status`; v1 and v2 stay readable and applicable.*

---

## 8. Authorization — APPROVED FUTURE DESIGN (no RBAC change)

**CURRENT IMPLEMENTATION:** `residence.update` (SUPER_ADMIN,
ADMINISTRATOR, DATA_ENTRY, SOCIAL_WORKER); `reference-data.view`
(SUPER_ADMIN, ADMINISTRATOR, DATA_ENTRY); `reference-data.create` /
`.update` / `.deactivate` (SUPER_ADMIN). These mappings are unchanged by
this ADR.

- **Writing** a location reference on a residence is covered by the
  existing permission of the operation that writes the residence
  (`residence.update`, registration, Change Request APPLY). No new
  permission.
- **Reading** the reference for a form must be possible for every role that
  may edit a residence. The intended pattern is the existing union check
  (`reference-data.view` **or** the feature permission, as
  `assessment-domains` / `need-categories`), which changes no role
  mapping; the exact rule is confirmed by AUTH review at Stage 2 (§14 V7).
- **Family Portal:** reference options are read inside the existing family
  boundary for the RESIDENCE_UPDATE form; the household head never sees
  another Family's data. Residence stays family-visible as today.
- **Coordinators:** unchanged. Coordinator Space never shows another
  Family's current residence (docs/adr/CO-02 D11).
- **Management** (D13): centrally controlled through the reviewed dataset;
  if a management UI is ever built it belongs to System / High
  Administration (Filament) under the existing `reference-data.*`
  permissions, by a separate decision.
- Location reference data is not personal data; a residence's location is
  family data and keeps the residence's existing exposure rules.

### 8.1 As built locally — Stage 2B Staff read API (2026-10-15; Production NOT deployed)

Contract and decisions: `docs/audits/FB-GEO-01-STAGE-2A-API-AUTHORIZATION-AUDIT.md`
(§12 records the Stage 2B decision status and deviations).

**Routes.** All are GET, inside the existing `auth:sanctum` + `staff.side`
group. `{location}` is constrained with `whereUuid`.

| Route | Returns |
|---|---|
| `/api/v1/gaza-locations/governorates` | Active governorates, north to south (official codes 41 → 45; the codes are not exposed) |
| `/api/v1/gaza-locations/search?q=&level=&parent_id=&page=&per_page=` | Active matches, paginated, each with its `path` |
| `/api/v1/gaza-locations/{location}/children` | Active immediate children of an **active** location (inactive parent: 404; landmark: `[]`) |
| `/api/v1/gaza-locations/{location}` | One location, **active or inactive**, with its `path` |

**Authorization (API-D1).** `GazaLocationController` checks
`canAny(['reference-data.view', 'residence.update'])` and refuses with 403,
following the existing `ReferenceController` union pattern.
- Allowed: SUPER_ADMIN, ADMINISTRATOR, DATA_ENTRY, SOCIAL_WORKER.
- Refused (403): REVIEWER and REPORTS_VIEWER, which hold neither
  permission.
- Family-side and mixed accounts are refused by `staff.side`.
- No permission, role or mapping changed.

**Response (API-D4)** comes from the `GazaLocationResource` whitelist:
- `id`, `level`, `name` (edge-trimmed display value), `parent_id`;
- `has_children` (active children only);
- `landmark_type` (the type name for a LANDMARK, otherwise null; Staff
  only);
- `path` (search and details): `[{id, level, name}]` from the governorate
  down to the parent.

Never exposed: source codes, landmark type codes, timestamps, review flags,
import runs, or any family data. Flagged records are listed and selectable
like any other.

`is_active` was excluded in Stage 2B. **Since Stage 3.2 (G1, API-D4
amended)**, the details endpoint carries `is_active` for the node and each
path item, so a saved inactive selection can be shown historically.
Governorates, children and search never carry it (they return active
locations only).

**Search:**
- `q`: required, 2–100 characters, case-insensitive "contains" through
  `RegistrySearch` (escaped wildcards, no Arabic normalization, PDD-003).
- `level`: optional, a `GazaLocationLevel` value.
- `parent_id`: optional, an active location. It scopes the search to **all
  descendants** of that location, not only its children, through a recursive
  CTE that works on PostgreSQL and SQLite.
- `per_page`: default 20, maximum 50. Ordered by level, then trimmed name,
  then id.
- The response uses the Laravel paginator shape (`data`, `links`, `meta`).

**Queries:**
- Search runs a constant 5 or 6 queries per request, whatever the page size:
  the count, the page (with `has_children` as an EXISTS subquery), and one
  batched ancestor query per level (`GazaLocationPaths`).
- Children takes 2 queries, including the permission check.
- Nothing is written.
- No index, cache header or rate limiter was added.

**Ordering note:** name ordering follows the database collation (`C` on the
test database, `English_United States.1252` on the local development
database). It is deterministic within a database because `id` breaks ties,
but it can differ between environments.

**Test adjustment:** these are the first `whereUuid` routes.
`ApiUnauthenticatedTest` sweeps every protected route expecting a 401; its
parameter generator now produces a hyphenated UUID where a route's
constraint requires one, instead of the hyphen-less value that fits the
alphanumeric constraints. Its assertions (401 and the exact JSON body) are
unchanged.

**Not built:** the Family PWA mirrors (API-D2, Stage 5), coordinator access
(API-D3), residence references (Stage 3) and UI (Stage 4).

### 8.2 As built locally — Stage 4A reusable selector (2026-10-15; not integrated)

Design: `docs/audits/FB-GEO-01-STAGE-3A-SELECTOR-DESIGN.md` (§18 records
the Stage 4A status and deviations).

**Files:**
- `frontend/components/geography/gaza-location-selector.tsx`:
  `GazaLocationSelector` and `GazaLocationPathLine`.
- `frontend/lib/api/gaza-locations.ts`: `useGazaGovernorates`,
  `useGazaLocationChildren(parentId)` and `useGazaLocationDetails(id)`.
  - Keys `["gaza-locations", "governorates" | "children" | "detail", id]`.
  - 5-minute stale time; no retry on 401, 403 or 404.
- `frontend/lib/types/api/gaza-location.ts`.

**Public contract:**

```ts
<GazaLocationSelector
  value={uuid | null}                 // deepest selected location, any level
  onChange={(uuid: string | null) => …}
  id?="prefix" label?="…" disabled? error?
  requiredLevel?={GazaLocationLevel | null}   // presentation only
  showLandmark?={true}
/>
```

**When `onChange` fires:** only on a user action.
- Choosing an item emits its UUID.
- «غير محدد» or «بدون معلم» on a level emits the parent's UUID.
- «مسح الاختيار» emits null only after «تأكيد المسح».
- A saved (committed) value is replaced through a staged candidate:
  «تأكيد الاختيار» emits once, «إلغاء» emits nothing (Stage 4B, §8.3).

It never fires on mount, hydration, fetching or single-option lists (no
auto-selection).

**Behaviour:**
- Governorates load first. Each level loads only the selected parent's
  `/{uuid}/children`, and a rendering guard keeps only items whose
  `parent_id` is that parent.
- The next level is shown only when the selected item `has_children`, and
  its label comes from the item's `level`.
- Changing a level clears its descendants.
- A saved UUID is rebuilt from the details `path`.
- If the saved location or an ancestor is inactive, the path is shown
  read-only with «غير مفعّلة». «تغيير» starts a new selection without
  touching the stored value, and «إلغاء» returns to it.
- An unknown or failed saved UUID is never cleared.
- Every Select gets `dir="rtl"`; controls are `h-11` on mobile and `h-10`
  from `sm`. No new dependency, color, token or theme change.

Stage 4B placed the selector in the Staff forms (§8.3).

### 8.3 As built locally — Stage 4B Staff residence UI (2026-10-15)

**Selector cancellation semantics** (`gaza-location-selector.tsx`; the
public contract is unchanged):
- An empty field still emits each choice at once.
- A committed value (saved, or confirmed earlier) stays read-only with its
  path. «تغيير» opens a candidate selection, kept in component state apart
  from the committed value, that emits nothing.
- «تأكيد الاختيار» emits the candidate exactly once. «إلغاء» emits
  nothing, before or after a candidate is chosen, so React Hook Form
  `dirtyFields` stays unchanged.
- «مسح الاختيار» stages a clear. Only «تأكيد المسح» emits null.
- Loading, hydration and API responses never emit. An inactive saved value
  stays until a replacement is confirmed.

**Staff registration** (`app/(staff)/families/new/page.tsx`,
`lib/schemas/family-registration.ts`):
- Two independent selectors, both optional, starting at null: the
  original-location selector in the «النزوح» block, below
  `original_residence_text`, and the current-location selector in the
  «السكن الحالي» block, below the text address. The current selector is
  labelled «الحالي / مكان النزوح» when the family is displaced (D4).
- No required depth, no auto-selection, no inference from text. A note
  states that the selection does not replace the text.
- The payload sends `residence.original_location_id` and
  `residence.current_location_id` as UUID or null. Other fields and rules
  are unchanged, and the 422 errors map back to both fields.

**Staff residence correction** (`components/families/edit-residence-dialogs.tsx`,
`lib/schemas/edit-residence.ts`):
- `original_location_id` is in the displacement dialog and
  `current_location_id` in the current-address dialog, matching the
  Action's activity grouping (DISPLACEMENT_UPDATED / ADDRESS_UPDATED).
- Each dialog hydrates from the saved UUID, active or inactive.
- The payload compares the form value with the **saved residence**, not
  the control state:
  - unchanged: the key is omitted, so the stored value (even inactive) is
    preserved;
  - new selection: the UUID is sent;
  - confirmed clear: null is sent.
- Cancelling the dialog sends nothing, and reopening resets from the saved
  data. Text fields, displacement-status behaviour and RESIDENCE_UPDATE v1
  are unchanged.

**Display** (`components/families/family-overview.tsx` `ResidenceSummary`,
used by the residence tab):
- The original and current blocks show «الموقع الجغرافي» beside the
  unchanged text, as the full path from the `original_location` /
  `current_location` summaries already in the family response (no extra
  request), with «غير مفعّلة» on inactive items.
- A missing reference shows «لم يُحدَّد موقع جغرافي».
- No source codes, review flags or UUIDs are shown.

**Not done:** Family PWA and RESIDENCE_UPDATE v2 (Stage 5, blocked on
API-D2), coordinator use (API-D3), reporting (Stage 6).

### 8.4 Stage 4C verification (2026-10-16; local)

Record: `docs/audits/FB-GEO-01-STAGE-4C-INTEGRATION-VERIFICATION.md`.

**Environment:** the disposable `famboook_stage4c_test` database on the
separate test instance (127.0.0.1:5433), with the official workbook
imported; the real Laravel API, `next dev`, and headless Edge at desktop,
tablet and mobile widths. The development database was not touched.

**Results:**
- Real API: 51 of 51 checks. A full `/children` sweep reached all 2,139
  active locations with only direct children at each level.
- Persistence matrix: omitted = preserved, `null` = cleared, inactive saved
  UUID preserved; inactive new, invalid and unknown UUIDs → 422.
- Browser: 25 of 25 checks, including the captured PATCH bodies for every
  cancellation and confirmation path.
- Backend: SQLite full suite green; on PostgreSQL only the 10 known legacy
  fixture failures (docs/11 FU-12).

**Fixed:**
- **D-1:** selector triggers rendered 32px because `SelectTrigger`'s
  `data-[size=default]:h-8` outranked `h-11 sm:h-10`. Now `h-11!` /
  `sm:h-10!`.
- **D-2:** geography tests near the 5s timeout under load; explicit 20s
  timeouts.

**Recorded, not changed:**
- **PX-1 (pre-existing):** reopening a residence correction dialog before
  the family refetch completes resends stale text. Geographic references
  are unaffected because an unchanged reference is omitted.
- **OBS-1:** an active location under an inactive ancestor is accepted
  (rule D-7 checks the node only). Unreachable today; needs a decision
  before any deactivation workflow.


### 8.5 Stage 5 progress — Family PWA (2026-10-16; local)

**Built (no v1, RBAC or theme change):**
- **Family reference API (API-D2):** `GET /api/v1/family/change-requests/gaza-locations/governorates`,
  `/{location}/children`, `/{location}`.
  - It sits in the family group (`auth:sanctum` + `family.side` +
    `can:family-portal.access` + `family.context`), so Staff and
    coordinator-only accounts are refused (403). Family accounts are still
    refused by the Staff lookups.
  - Same queries as the Staff lookups (new `GazaLocationLookup`, also used
    by `GazaLocationController`).
  - Same resource with `forFamily()`: no `landmark_type` (API-D4), and never
    codes, flags or import metadata.
  - Details carry `is_active`. `no-store, private`. GET only; nothing is
    written.
  - Search is not mirrored (the selector does not use it).
- **Selector:** `GazaLocationSelector` takes `source="family"`; the hooks
  key their caches by source. Staff behaviour is unchanged.

**Blocked — RESIDENCE_UPDATE v1 cannot carry locations** (resolved: the
owner approved v2 below on 2026-10-16; implemented as §8.6).
- `ResidenceUpdateHandler` v1 accepts exactly its eight text/status keys
  (`OnlyKeys`).
- Its proposal, base fingerprint and presentation are built from those
  eight fields.
- `ChangeRequestWorkflow::verify()` refuses any request whose
  `payload_version` differs from the handler's.
- Adding the two references is therefore a v2 contract, not an extension.
  The selector is not placed in the v1 form.

**Smallest compatible change (needs owner approval):**
1. **Handler v2:** keep the eight fields and add `original_location_id` /
   `current_location_id`.
   - Input: nullable UUID; `SelectableGazaLocation` with the stored value,
     so an unchanged inactive value is accepted and a new one must be
     active.
   - Proposal: the changed keys only, as today.
   - Base: add both ids, so a Staff change between submit and apply gives
     `BASE_CHANGED`.
   - Apply: unchanged (`UpdateFamilyResidenceAction` already re-checks
     active under lock).
2. **Version compatibility:** let a handler accept several payload versions
   (`acceptedPayloadVersions()`, checked by `verify()`), so open v1 requests
   still review and apply. v1 data is a valid subset of v2.
3. **Review:** present each reference as its full path (names, «غير
   مفعّلة»), never a UUID, as current → proposed rows. Persisted only by
   APPLY after approval, as today.
4. **Family read model:** expose the two saved references (ids and
   summaries) in `FamilyHouseholdProfileResource` / `HouseholdReadModel`, so
   the form can prefill them. This changes the family read contract and the
   T15 guard.
5. **Form:** two selectors (`source="family"`) beside the unchanged text
   fields in `family-residence-update-form.tsx`, sending v2.
6. **Tests:** v1 open requests still apply, v2 submit → review → approve →
   apply, reject, inactive and unknown ids, base change, no direct write.

### 8.6 RESIDENCE_UPDATE v2 and Family geography (2026-10-16; local)

Owner-approved implementation of §8.5.

**Engine:**
- New optional interface `ReadsEarlierPayloadVersions`. A handler that
  implements it lists the payload versions it still reviews and applies,
  and computes the base **for each request's own version**.
- `ChangeRequestWorkflow::verify()` / `assertBaseUnchanged()` use it.
- Every other handler is unchanged (exact-version check as before).

**`ResidenceUpdateHandler`:** writes v2 and reads v1 and v2.
- **Input:** the eight fields plus optional `original_location_id` /
  `current_location_id`.
  - Omitted = unchanged, so an eight-field client still works.
  - `null` clears.
  - A UUID must exist; a new one must be active; the stored one is accepted
    even if inactive (`SelectableGazaLocation`).
  - Ids are normalized to lower case.
- **Proposal:** the changed keys only.
- **Base:** v2 adds both ids, so a Staff change of either after submission
  is `BASE_CHANGED`. A v1 request's base is still its eight fields.
- **Preconditions** (approve and apply): a new reference must still be
  active.
- **Apply:** unchanged (`UpdateFamilyResidenceAction`).
- **Presentation:** each reference as its full path
  («… ‹ …», «(غير مفعّلة)»), never a UUID, for reviewer and family.
- Existing v1 requests are never migrated.

**Family profile:** `FamilyHouseholdProfileResource` adds
`original_location` / `current_location` as `GazaLocationSummary`
summaries (`id`, `level`, `name`, `is_active`, `path`) beside the unchanged
text. There is no raw `*_location_id` key, and the coordinator view still has
no location data (T15 updated).

**Family PWA form:** two `GazaLocationSelector source="family"` instances,
below the text fields of «عنوان السكن الحالي» and «النزوح». They prefill
from the profile summaries, send v2, and map 422 errors to the selectors.
Families still cannot write `family_residences`: the Staff route returns 403.

**PX-1 fixed** (Staff correction dialogs):
- `useUpdateFamilyResidence` merges the PATCH response's authoritative
  `residence` into the cached family at once.
- Each dialog blocks saving while the family is being refetched
  («جارٍ تحديث بيانات السكن…»).
- An open dialog resets to newer residence data instead of keeping a stale
  baseline.
- The regression test fails without the fix.

**Verification:**
- Backend tests: v1 compatibility, v2 end to end, validation, concurrency,
  presentation, profile, no direct write.
- Frontend tests: the family form and PX-1.
- Real browser and real API on the disposable test database: household head
  submit (desktop, tablet, mobile, RTL), family and reviewer views by name,
  reviewer approve and apply, profile read-back.

**Noted, not changed:** on desktop the Family PWA column is about 430px
wide, while the selector's two-column breakpoint follows the viewport. Each
trigger is then about 117px wide and long names truncate; the full path is
shown below.

---

## 9. Reporting implications — APPROVED FUTURE DESIGN

**CURRENT IMPLEMENTATION:** top displacement locations by exact stored
text; `DISPLACED_WITHOUT_LOCATION` checks `displacement_location_text`;
targeting uses a text `LIKE`.

- Aggregates may group by any reference level using the residence's node
  and the node's ancestors (governorate via `governorate_id`).
- Displacement geography = `current_location_id` of DISPLACED Families
  (D4); origin geography = `original_location_id`.
- Families without a structured reference are reported as an explicit
  "غير مصنّف" bucket — never assigned by parsing text.
- The existing text-based outputs keep working until each is replaced by
  its own approved change. A future `DISPLACED_WITHOUT_LOCATION` rule
  ("no text and no `current_location_id`") is a data-quality decision at
  Stage 6.
- Fine-grained geography (neighborhood / landmark) combined with other
  filters can identify households; minimum aggregation level and small-cell
  handling for exports are §14 V8. Coordinates remain PDD-024.

---

## 10. Risks

| # | Risk | Mitigation |
|---|---|---|
| R1 | Breaking deployed Family PWA clients by changing the strict v1 contract | v1 accepted unchanged; v2 additive and optional (§7) |
| R2 | In-flight v1 requests misjudged as stale or applied with new fields | Base values per payload version; test a v1 request opened before the migration |
| R3 | Legacy data silently reinterpreted (e.g. current-address text that actually held the pre-displacement home) | No backfill or inference (D10); structured values only by explicit selection |
| R4 | A stale structured reference after a status change | `current_location_id` is status-independent by design; only the legacy text is cleared |
| R5 | Spreadsheet quality: duplicates, skipped levels, spelling variants, non-Gaza rows | Stage 1 validation report before any seed (§14 V1–V3) |
| R6 | Reference edits changing historical meaning | Immutable codes; deactivate, never delete; RESTRICT foreign keys |
| R7 | SQLite tests cannot add CHECKs to existing tables | Existing pattern: PostgreSQL CHECKs plus Request / Action validation; run the PostgreSQL suite |
| R8 | Large neighborhood lists unusable in a plain select | Searchable, cascading picker at the UI stage |
| R9 | Re-identification through fine-grained geographic reports | §14 V8 before any report/export change |
| R10 | Scope creep into residence history | D15: `residence.change` stays separate |

---

## 11. Alternatives considered

| Alternative | Outcome |
|---|---|
| Keep free text only, normalize in reports | Rejected: parsing text is inference (D10) and stays unreliable |
| Separate tables per level (governorates, cities, …) with composite keys, as Clan → Branch | Not preferred: stronger per-level FKs but five tables and an awkward optional landmark; kept as fallback if §14 V4 rejects the single table |
| Store every level on the residence (governorate_id … landmark_id) | Rejected: redundant, derivable data and more consistency rules |
| A separate `displacement_location_id` | Rejected by D4: the current residence is the displacement location |
| Replace the free-text columns | Rejected by D9 / D10 |
| Use coordinates / GIS | Out of scope; PDD-024 stays open |

---

## 12. Implementation stages

Each stage is a separate, approved, locally tested change. Nothing is
deployed, migrated or seeded in Production without explicit approval.

| Stage | Scope |
|---|---|
| 0 | **This ADR and documentation** (done 2026-10-15, documentation only) |
| 1A | Dataset validation (done 2026-10-15, read-only; CONDITIONAL PASS) |
| 1B | Reference-data governance and decision register (done 2026-10-15, documentation only) |
| 1C | `gaza_locations` + `gaza_location_review_flags` migrations, models, idempotent checksum-verified import (§3.3), schema and import tests. **Done locally 2026-10-15** (§4.3); Production not migrated |
| 2 | 2A API and authorization audit (done). 2B read-only Staff API `/api/v1/gaza-locations/…`, API Resource, API-D1 authorization and tests: **done locally 2026-10-15** (§8.1); Production not deployed |
| 3 | 3.1 linkage contract audit (done). 3.2 `original_location_id` / `current_location_id` migration, model relations, Create/Update residence Actions, staff Requests, `FamilyDetailResource`, details `is_active` (G1) and tests: **done locally 2026-10-15** (§4.2); Production not migrated |
| 4 | Staff UI: cascading searchable picker in registration and residence correction; legacy text shown alongside. **4A** reusable `GazaLocationSelector`: **done locally 2026-10-15** (§8.2). **4B** Staff registration, residence correction and residence display integration: **done locally 2026-10-15** (§8.3). **4C** integration verification: **done locally 2026-10-16**, CONDITIONAL for Stage 5 (§8.4) |
| 5 | RESIDENCE_UPDATE v2; Family Portal read model, profile resource and form; v1 compatibility tests. Family reference API and source-aware selector: **done locally 2026-10-16** (§8.5). RESIDENCE_UPDATE v2, family profile summaries, Family PWA form and PX-1: **done locally 2026-10-16** (§8.6) |
| 6 | Reporting, data quality and export changes (§9), each approved separately |
| Later | Residence history (`residence.change`), coordinator original-residence picker, targeting by location — each its own decision |

---

## 13. Acceptance criteria (for the implementation as a whole)

1. `gaza_locations` holds exactly the reviewed spreadsheet content: five
   active governorates, every other node with a parent of the level above,
   unique stable codes; the validation report is attached to the Stage 1
   change.
2. Re-running the seeder changes nothing and never reactivates a node.
3. Residence writes accept only active nodes; inactive, unknown or
   wrong-level input is refused with a field error.
4. `current_location_id` is accepted for DISPLACED, NOT_DISPLACED and NULL
   status; there is no displacement-specific location reference.
5. No existing residence row has a location reference after the
   migrations; every free-text field is byte-for-byte unchanged.
6. RESIDENCE_UPDATE v1 submissions and pre-existing open v1 requests
   behave exactly as before; v2 submit → review → approve → APPLY works.
7. No role, permission or role mapping changes (seeder diff empty for
   RBAC); unauthorized roles get 403 on any new endpoint.
8. API responses expose location `code` and names, never internal ids, and
   never more residence data than today to any audience.
9. SQLite and PostgreSQL test suites pass (known pre-existing PostgreSQL
   failures documented, none new).

---

## 14. Unresolved technical validations

| # | Validation | Needed before |
|---|---|---|
| V1 | The spreadsheet is **not in the repository** and has not been reviewed; its rows, columns and licensing/provenance must be confirmed | Stage 1 |
| V2 | Does every row fit Governorate → City → Area → Neighborhood → Landmark, or are levels skipped? | Stage 1 |
| V3 | Stable code scheme: reuse a source code column if one exists, else a generated immutable code; `source_ref` mapping | Stage 1 |
| V4 | Single table vs per-level tables (D7): can parent-level integrity be enforced cleanly (composite key or trigger), and do SQLite tests remain workable? | Stage 1 |
| V5 | May a selection stop above NEIGHBORHOOD (partial knowledge)? Proposed yes (deepest known node); to be confirmed | Stage 3 |
| V6 | Should the legacy text fields be editable in the same Staff form once structured selection exists, and how are both displayed? | Stage 4 |
| V7 | Exact read authorization for the reference endpoint(s) for Staff, Family Portal and (later) coordinators, with no mapping change | Stage 2 |
| V8 | Minimum aggregation level and small-cell rules for geographic reports and exports | Stage 6 |
| V9 | Whether `original_location_id` should later move to Family level when residence history (`residence.change`) is built, since each new residence row would otherwise carry it again | Residence history decision |

**Status after Stage 1A / 1B (2026-10-15):**

| # | Status |
|---|---|
| V1 | Workbook received and validated (Stage 1A). Authority MoSD, owner-confirmed. Department, release date, licence and version unverified (register D-1). Git tracking pending (D-10) |
| V2 | **Answered:** all 1,846 rows fill all five levels; no level is skipped (Stage 1A §5) |
| V3 | **Answered (Stage 1C D-7):** UUID ids; the source code is kept in `source_code` with `UNIQUE (level, source_code)`; no replacement code is manufactured |
| V4 | **Answered and implemented (Stage 1C):** one self-referencing table with a composite parent key, on PostgreSQL and SQLite (§4.3) |
| V5 | **Principle approved (G9), validation pending:** the data supports partial selection; which levels a selection may stop at is still open. Single-area auto-selection is pending (D-9) |

---

## 15. Documentation updated in Phase 0

| Document | Change |
|---|---|
| docs/02 | §19 current vs future note; §47 RESIDENCE_UPDATE v2 note; §92 PDD-006 marked architecturally resolved; DD-ADR-036; change log 1.2.40 |
| docs/03 | "Residence decision (governorate / city)" and "Remaining limitations" amended; change log 1.2.62 |
| docs/04 | §24 future columns note; new §24a `gaza_locations` (approved, not migrated); DB-ADR-063; change log 1.2.47 |
| docs/06 | §46 and §56 notes (no RBAC change); AUTH-ADR-094; change log 1.2.69 |
| docs/07 | Slice C "Still open" annotated; RM-ADR-061; change log 1.2.64 |

Stage 1A / 1B (2026-10-15):

| Document | Change |
|---|---|
| docs/audits/FB-GEO-01-STAGE-1A-DATASET-VALIDATION.md | Stage 1A audit (new); amended in 1B with source authority and decision status |
| docs/audits/FB-GEO-01-STAGE-1B-DECISION-REGISTER.md | Stage 1B decision register (new) |
| this ADR | Header status; §3.2 source authority and G1–G10; §3.3 import requirements; §3.4 decision summary; §4.1 code note; §12 stages 1A–1C; §14 status |

Stage 1C (2026-10-15):

| Document | Change |
|---|---|
| this ADR | Header status table; §3.2 D-10; §3.4 statuses; new §4.3 (as built locally); §12 stage 1C; §14 V3 / V4 |
| docs/audits/FB-GEO-01-STAGE-1B-DECISION-REGISTER.md | §8 Stage 1C locked decisions and updated statuses |
| docs/04 | §24a as implemented locally; DB-ADR-064; change log 1.2.48 |
| docs/07 | Progress entry; RM-ADR-062; change log 1.2.65 |

Stage 1C.1 (2026-10-15):

| Document | Change |
|---|---|
| this ADR | Header table; §4.3 R5 note; new §4.4 (import history, lifecycle, failure semantics, concurrency) |
| docs/audits/FB-GEO-01-STAGE-1B-DECISION-REGISTER.md | §8 R5 met |
| docs/04 | §24a `gaza_geography_import_runs`; DB-ADR-065; change log 1.2.49 |
| docs/07 | Progress entry; RM-ADR-063; change log 1.2.66 |

Stage 2A / 2B (2026-10-15):

| Document | Change |
|---|---|
| docs/audits/FB-GEO-01-STAGE-2A-API-AUTHORIZATION-AUDIT.md | Stage 2A audit (new); §12 Stage 2B decision status and deviations |
| this ADR | Header table; new §8.1 (as built); §12 stage 2 |
| docs/04 | §24a API listing; change log 1.2.50 |
| docs/06 | §56 note; AUTH-ADR-095; change log 1.2.70 |
| docs/07 | Progress entry; RM-ADR-064; change log 1.2.67 |

Stage 3A / 3.1 / 3.2 (2026-10-15):

| Document | Change |
|---|---|
| docs/audits/FB-GEO-01-STAGE-3A-SELECTOR-DESIGN.md | Selector design audit (new; advance design for Stage 4) |
| docs/audits/FB-GEO-01-STAGE-3-RESIDENCE-LINKAGE-AUDIT.md | Stage 3.1 contract (new); §14 Stage 3.2 status |
| docs/audits/FB-GEO-01-STAGE-2A-API-AUTHORIZATION-AUDIT.md | §12 API-D4 amended (details `is_active`) |
| this ADR | Header table; §4.2 as built; §8.1 details `is_active`; §12 stage 3 |
| docs/04 | §24 as built; DB-ADR-066; change log 1.2.51 |
| docs/06 | §46 note (no RBAC change); change log 1.2.71 |
| docs/07 | Progress entry; RM-ADR-065; change log 1.2.68 |

Stage 4A (2026-10-15):

| Document | Change |
|---|---|
| this ADR | Header table; new §8.2; §12 stage 4 (4A done, 4B not started) |
| docs/audits/FB-GEO-01-STAGE-3A-SELECTOR-DESIGN.md | §18 Stage 4A status, contract and deviations |
| docs/07 | Progress entry; RM-ADR-066; change log 1.2.69 |

Stage 4B (2026-10-15):

| Document | Change |
|---|---|
| this ADR | Header table; §8.2 cancellation semantics; new §8.3; §12 stage 4 (4B done) |
| docs/audits/FB-GEO-01-STAGE-3A-SELECTOR-DESIGN.md | §18 cancellation deviation corrected; §19 Stage 4B status |
| docs/07 | Progress entry; RM-ADR-067; change log 1.2.70 |

Stage 4C (2026-10-16):

| Document | Change |
|---|---|
| docs/audits/FB-GEO-01-STAGE-4C-INTEGRATION-VERIFICATION.md | Verification record (new) |
| this ADR | Header table; new §8.4; §12 stage 4 (4C done) |
| docs/07 | Progress entry; RM-ADR-068; change log 1.2.71 |

Stage 5 progress (2026-10-16):

| Document | Change |
|---|---|
| this ADR | Header table; new §8.5; §12 stage 5 |
| docs/audits/FB-GEO-01-STAGE-2A-API-AUTHORIZATION-AUDIT.md | API-D2 status |
| docs/07 | Progress entry; RM-ADR-069; change log 1.2.72 |

RESIDENCE_UPDATE v2 (2026-10-16):

| Document | Change |
|---|---|
| this ADR | Header table; §8.5 note; new §8.6; §12 stage 5 |
| docs/11 | PWA-6.1 record: payload v2 row |
| docs/07 | Progress entry; RM-ADR-070; change log 1.2.73 |
