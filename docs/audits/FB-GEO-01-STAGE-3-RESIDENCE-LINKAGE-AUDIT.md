# FB-GEO-01 — Stage 3.1: Residence Linkage Contract Audit

**Date:** 2026-10-15
**Mode:** read-only code audit and documentation. No application code, test,
migration, database or Production change. Nothing ran against a database.
**ADR stage:** Stage 3 (residence linkage). This is **3.1**, the preparation
step. Roadmap unchanged: Stage 3 linkage · Stage 4 Staff selector · Stage 5
Family PWA · Stage 6 reporting.
**Inputs:**
- `CLAUDE.md`, ADR-FB-GEO-01 (§4–§8.1, §12–§14);
- Stage 1A / 1B, 2A / 2B and 3A documents;
- docs/02 §19 / §47 / §57, docs/03 "Residence decision", docs/04 §24–§25,
  docs/06 §46;
- the code at the current working tree.

**Verdict:** **CONDITIONAL** (§13).

Sections are labelled **VERIFIED** (read from code) or **PROPOSED** (design
for approval).

---

## 1. Executive summary

- **VERIFIED:**
  - One table, `family_residences`, holds a Family's residence. The
    **original residence (`original_residence_text`) and the current
    address live on the same row**: the current row
    (`is_current = true`), one per Family by the partial unique index
    `uq_family_current_residence`.
  - No history workflow exists. Corrections update the current row in
    place, and `residence.change` is unassigned.
- **PROPOSED placement:** both `original_location_id` and
  `current_location_id` go on **`family_residences`**, next to the text
  they describe: nullable UUID, FK to `gaza_locations.id`, RESTRICT.
  - The ADR's open question V9 (whether `original_location_id` should later
    move to Family level when history exists) does **not** block Stage 3,
    because no history rows are created.
- **VERIFIED: seven residence write paths**, all funnelling through two
  Actions:
  - `CreateFamilyResidenceAction` (staff registration, coordinator
    REGISTER_FAMILY APPLY, import APPLY);
  - `UpdateFamilyResidenceAction` (staff PATCH, RESIDENCE_UPDATE v1
    APPLY).
  - Both Actions filter input through field whitelists, so adding the two
    keys changes nothing for callers that do not send them (v1,
    coordinator, import).
- **PROPOSED Stage 3 scope:**
  - schema, model, the two Actions, staff registration and staff
    residence-correction validation, and the staff `FamilyDetailResource`;
  - the details `is_active` field on the geography API (G1, if approved).
  - **Deferred:** Family PWA, RESIDENCE_UPDATE v2, coordinator workflows,
    import.
- **Integrity split:**
  - The foreign key guarantees *existence* and blocks deletion.
  - *"Newly selected must be active"* and *"an unchanged inactive value may
    stay"* need application validation, both in the Form Request (field
    errors) and in the Action under a row lock (race-safe, and for
    non-HTTP callers).

---

## 2. Residence schema findings (VERIFIED)

| Item | Finding | Source |
|---|---|---|
| Canonical table | `family_residences` (bigint `id`, `family_id` FK `families` RESTRICT) | `backend/database/migrations/2026_09_23_153943_create_family_residences_table.php` |
| Current-address fields | `governorate`, `city`, `area`, `neighborhood` VARCHAR NULL; `address_text` TEXT NULL; `residence_type` | same |
| Coordinates | `latitude` / `longitude` DECIMAL(10,7) NULL (accepted by staff registration; no UI; PDD-024) | same; `RegisterFamilyRequest` |
| Original residence | `original_residence_text` VARCHAR NULL, **on the same row** as the current address | `2026_09_24_100000_add_displacement_and_alternate_contact_fields.php` |
| Displacement | `displacement_status` (DISPLACED / NOT_DISPLACED / NULL), `displacement_location_text` (DISPLACED only) | same; PostgreSQL CHECKs `chk_residence_displacement_status`, `chk_residence_displacement_location` |
| History columns | `started_at`, `ended_at`, `is_current` (default true), `source`, `notes`, `created_by` / `updated_by` FK users (NULL on delete) | create migration |
| One current residence | `CREATE UNIQUE INDEX uq_family_current_residence ON family_residences (family_id) WHERE is_current = true` | create migration |
| Other indexes | None besides the PK, the `family_id` FK and the partial unique index | migrations |
| Model | `App\Models\FamilyResidence`: `$fillable` lists every column above; casts `is_current` bool, `displacement_status` → `DisplacementStatus`, dates, `latitude` / `longitude` `decimal:7`; relation `family()` | `backend/app/Models/FamilyResidence.php` |
| Family relations | `residences(): HasMany`; `currentResidence(): HasOne … where('is_current', true)` | `backend/app/Models/Family.php:58–66` |
| History behaviour | Corrections only, in place; `residence.change` designed but unassigned; **no code ends a residence or creates a second row** | `UpdateFamilyResidenceAction`, docs/06 §46 |
| Geography tables | `gaza_locations.id` is UUID (native `uuid` on PostgreSQL, string on SQLite); deterministic UUIDv5; `is_active`; FK RESTRICT from review flags | `2026_10_22_090000_create_gaza_locations_table.php` |
| SQLite FK on an existing table | Precedent: `2026_10_01_090001_add_clan_and_branch_to_families.php` adds FKs to `families` and works in the SQLite suite; `down()` drops by columns on SQLite | migration |

**Placement conclusion:** a residence row is "the Family's residence
facts": its original residence *and* its current address. Both structured
references belong on that row, mirroring `original_residence_text` and the
current-address text (ADR D8, §4.2).

---

## 3. Residence write-path inventory (VERIFIED)

| # | Path | Entry point | Validation | Action / write | Response | Authorization | Tests | Linkage impact | Stage 3? |
|---|---|---|---|---|---|---|---|---|---|
| W1 | **Staff registration** `POST /api/v1/families` | `FamilyController::store` (`Api/V1/FamilyController.php:75`) | `RegisterFamilyRequest::rules()` (`residence` required array; text fields nullable; `displacement_location_text` `prohibited_unless` DISPLACED) | `RegisterFamilyAction::handle()` → `CreateFamilyResidenceAction::handle()` (`FIELDS` whitelist; first current row; `lockForUpdate` on Family) | `FamilyDetailResource` 201 | `can:family.create` (route) + `authorize()` | `Families/RegisterFamilyTest` (11), `Registry/CreationFoundationTest` (16), `Corrections/OptionalResidenceLocationTest` (4) | Accept optional `residence.original_location_id`, `residence.current_location_id` | **Yes** |
| W2 | **Staff residence correction** `PATCH /api/v1/families/{family}/residence` | `FamilyResidenceController::update` | `UpdateFamilyResidenceRequest` (`sometimes` partial PATCH; status-aware closure for the location text) | `UpdateFamilyResidenceAction::handle()`: `currentResidence()->lockForUpdate()`, `fill(array_intersect_key(...editable))`; `RESIDENCE_UPDATED` / `DISPLACEMENT_UPDATED` events by field group | `FamilyDetailResource` | `can:residence.update` + `authorize()` | `Families/UpdateFamilyResidenceTest` (20), `Families/FamilyResidenceDisplacementTest` (14), `Activity/FamilyActivityLogTest` (28) | Accept optional location fields; omitted = unchanged, `null` = clear | **Yes** |
| W3 | **RESIDENCE_UPDATE v1** (Family PWA submit → Staff APPLY) | `/api/v1/family/change-requests` → `ResidenceUpdateHandler` | `inputRules()`: all 8 fields `present`, `OnlyKeys`; `dataRules()`; `preconditions()` | APPLY: `UpdateFamilyResidenceAction::handle($family, $request->submitted_data, …)` (`ResidenceUpdateHandler.php:153`) | Change Request resources | `change-request.submit` (family); `change-request.apply` (staff) | `ChangeRequests/Residence/ResidenceUpdateTest` (25), `ResidenceUpdateConcurrencyTest` (4) | **None:** v1 payloads can never contain location keys (`OnlyKeys` + `normalize()` builds from `FIELDS`) | **No change** (v2 = Stage 5) |
| W4 | **Coordinator REGISTER_FAMILY** (APPLY) | `RegisterFamilyHandler::apply` → `RegisterFamilyAction::handle([... 'residence' => ['original_residence_text' => …]])` (`RegisterFamilyHandler.php:244–257`) | `proposalRules()`: `residence.original_residence_text` required text | `CreateFamilyResidenceAction` (source `COORDINATOR_REQUEST`) | Change Request resources | `coordinator-request.submit`; staff approve / apply | `ChangeRequests/Coordinator/RegisterFamilyRequestTest` (21) | **None** (no location key passed → NULL) | **No change** (ADR D12, CO-02 D10) |
| W5 | **Import APPLY** | `ApplyImportRowAction` (`ApplyImportRowAction.php:184–192`) via `ImportApplyPlanner` (RESIDENCE effect: `original_residence_text` + source IMPORT) | Planner / reconciler rules | `CreateFamilyResidenceAction` | Import reports | `import.apply` (SUPER_ADMIN, gated) | `Import/ApplyImportRowTest` (23) and others | **None:** never infers a location from text (ADR D10) | **No change** |
| W6 | Family PWA reads (not a write) | `HouseholdReadModel` explicit `select([...])`; `FamilyHouseholdProfileResource` | — | — | profile `residence` block | `family-portal.access` + `family.context` | `FamilyPortal/FamilyHouseholdProfileTest` (17) | Must **not** expose location fields in Stage 3 | **No change** (Stage 5) |
| W7 | Background jobs / other services | None write residences. `ImportReconciler` only reads (`original_residence_text` diff); reports, data quality, targeting and export read text | — | — | — | — | — | None | No change (Stage 6) |

`UpdateFamilyAction` explicitly never touches residence (docblock,
`UpdateFamilyAction.php:17`). Coordinator resources never expose residence
(`CoordinatorFamilyDetailResource`, `CoordinatorFamilySummaryResource`,
`CoordinatorMemberResource` docblocks).

---

## 4. Proposed database contract (PROPOSED)

Migration `2026_10_22_090003_add_location_references_to_family_residences_table`:

```text
family_residences
  + original_location_id  UUID NULL  FK → gaza_locations.id  ON DELETE RESTRICT
  + current_location_id   UUID NULL  FK → gaza_locations.id  ON DELETE RESTRICT
  + INDEX (original_location_id)
  + INDEX (current_location_id)
```

- `foreignUuid(...)->nullable()->constrained('gaza_locations')->restrictOnDelete()`
  gives native `uuid` on PostgreSQL, matching `gaza_locations.id`, and a
  string on SQLite, matching the SQLite `gaza_locations.id`.
- **Semantics** (ADR §5): `original_location_id` is the residence *before
  displacement*. `current_location_id` is where the Family lives now, **and
  is the displacement location when DISPLACED**.
- `current_location_id` is valid for every displacement status. It is
  **not** covered by `chk_residence_displacement_location`, and a status
  change never clears it.
- **No `displacement_location_id`.**
- **No CHECK constraint** is added. Any level may be selected (§5), and
  active-ness is mutable, so it cannot be a CHECK.
- **Preserved:** every existing column, CHECK, index and row, unchanged.
  **No backfill:** both columns are NULL for every existing row after
  migrating. Nothing is inferred from text (ADR D10).
- **Model:** add both to `FamilyResidence::$fillable`.
  - Relations `originalLocation(): BelongsTo` and
    `currentLocation(): BelongsTo` (`GazaLocation`, keys above).
  - No casts are needed (UUID strings).
- **Activity groups** (ADR §5), extending the existing constants in
  `UpdateFamilyResidenceAction`:
  - `current_location_id` → `ADDRESS_FIELDS` → `RESIDENCE_UPDATED`;
  - `original_location_id` → `DISPLACEMENT_FIELDS` →
    `DISPLACEMENT_UPDATED` (the same group as `original_residence_text`).
  - Values are never recorded, as today.

---

## 5. Validation and integrity rules (PROPOSED)

### 5.1 What the foreign key guarantees, and what it does not

| Requirement | Database FK | Application |
|---|---|---|
| Referenced location exists | ✓ | Also validated first, so the user gets a 422 field error, not a 500 |
| A referenced location cannot be deleted | ✓ RESTRICT (locations are never deleted anyway) | — |
| UUID syntax | ✗ (PostgreSQL raises 22P02 → 500) | `uuid` rule **before** any database lookup (`bail`), the lesson from Stage 2B |
| New selection must be active | ✗ (`is_active` is mutable) | Rule + Action re-check |
| An unchanged inactive value may stay | ✗ | Rule compares with the stored value |
| Any level selectable | — (no CHECK) | No level restriction by default; a form may later require a depth (ADR V5) |
| Omitted ≠ cleared | — | `sometimes` on PATCH (existing convention); `array_intersect_key` in the Action |

### 5.2 Reusable rule

**PROPOSED:** `App\Rules\SelectableGazaLocation`, following the
`App\Rules\OnlyKeys` convention (`ValidationRule`).

```text
new SelectableGazaLocation(?string $storedValue = null, ?GazaLocationLevel $minimumLevel = null)
```

- Fails if the value is not a UUID (guard, though `uuid` precedes it), the
  location does not exist, or the location is inactive **and differs from
  `$storedValue`**.
- `$minimumLevel` stays unused in Stage 3: any level is accepted until a
  form decides otherwise (D-3).
- Messages are Arabic, per the existing convention, e.g. «الموقع الجغرافي
  المحدد غير متاح للاختيار.»

Field rules:

| Request | Rules |
|---|---|
| `RegisterFamilyRequest` | `residence.original_location_id` / `residence.current_location_id`: `['nullable', 'bail', 'uuid', new SelectableGazaLocation()]` (no stored value: must be active) |
| `UpdateFamilyResidenceRequest` | `original_location_id` / `current_location_id`: `['sometimes', 'nullable', 'bail', 'uuid', new SelectableGazaLocation($stored)]`, where `$stored` is the current residence's value (the same pattern as `effectiveDisplacementStatus()`, reading `route('family')->currentResidence`) |

### 5.3 Action-level integrity (race-safe)

- Inside the existing transactions (`CreateFamilyResidenceAction`: Family
  `lockForUpdate`; `UpdateFamilyResidenceAction`: current residence
  `lockForUpdate`), re-check each **changed** location reference with
  `GazaLocation::whereKey($id)->where('is_active', true)->sharedLock()->exists()`.
  - On PostgreSQL, `FOR SHARE` blocks a concurrent deactivation until
    commit, so a value validated active cannot become inactive between
    check and write. The lock is a no-op on SQLite.
  - On failure, throw a `ValidationException` on the field.
  - This also protects any future non-HTTP caller.
- An unchanged value (equal to the stored value) is never re-checked, so a
  saved inactive location survives unrelated edits.
- **Explicit clearing:** PATCH `{ "current_location_id": null }` clears it.
  Omitting the key leaves it unchanged (existing `sometimes` semantics).
  Registration: absent or `null` → NULL.
- **Concurrent residence edits:** unchanged. Row lock on the current
  residence; the last committed writer wins per field, as today.
- **Deactivation reality (VERIFIED):** no code path deactivates a location
  today (the import never reactivates or deactivates; no admin UI). The race
  above is defensive.
- **Backward compatibility:** requests without the new keys behave exactly
  as today (all rules `nullable` / `sometimes`). RESIDENCE_UPDATE v1 is
  untouched.

### 5.4 Text and structure divergence (known, accepted by the ADR)

- Structured and text fields are independent. Editing `city` text does not
  change `current_location_id`, and vice versa.
- Likewise, a v1 APPLY can change text while a staff-set structured location
  stays.
- This is the approved coexistence model (ADR D9 / D10, no inference).
  Displaying both side by side is a Stage 4 UI concern (Stage 3A UI-D9).

---

## 6. API compatibility matrix (PROPOSED)

| Response | Stage 3 change | Rationale |
|---|---|---|
| `FamilyDetailResource` (staff detail; POST /families and PATCH residence responses) | **Add** to the `residence` block: `original_location_id`, `current_location_id` (UUID or null), plus `original_location` / `current_location` summaries `{id, level, name, is_active, path: [{id, level, name}]}` or null. All existing keys unchanged | Additive; the staff UI needs both the IDs and a display path |
| `FamilyHouseholdProfileResource`, `HouseholdReadModel` (Family PWA) | **None** | Stage 5 (family-visible, v2) |
| Coordinator resources | **None** (never residence, CO-02 D11) | — |
| `FamilySummaryResource` (registry list) | **None** (no residence today) | — |
| Change Request presentations (RESIDENCE_UPDATE v1) | **None** | v1 unchanged |
| Exports, reports, data quality | **None** | Stage 6 |

**N+1:**
- The single-family responses eager-load
  `currentResidence.originalLocation` and `currentResidence.currentLocation`.
- Paths come from `GazaLocationPaths::attach(collect([$original, $current])->filter())`
  (Stage 2B), which runs at most four queries for both together.
- `FamilyController::show`, `RegisterFamilyAction` (`fresh([...])`) and
  `UpdateFamilyResidenceAction` (`fresh([...])`) add these relations.

**Never exposed:** source codes, landmark type codes, review flags, import
runs or timestamps. The summaries reuse the Stage 2B field rules.

**Timing:** the staff API fields are introduced **immediately in Stage 3**
as additive, optional request and response fields; no version bump is needed
for an additive JSON field. Family-side exposure is a **versioned workflow
change** (RESIDENCE_UPDATE payload v2, Stage 5).

---

## 7. Inactive location handling (G1, PROPOSED; no API code changed)

**Smallest safe change** to `GET /api/v1/gaza-locations/{uuid}`:
- Add `is_active` (boolean) to the node and to **each `path` item**.
- Lists, children and search are **unchanged**: they return active
  locations only, without the field.

Implementation outline (Stage 3.2):
- `GazaLocationPaths` also selects `is_active`.
- A details-only resource (or a flag on `GazaLocationResource`) adds the
  field for `show` only.
- API-D4 (Stage 2A §12) is amended to record it.

The residence summaries (§6) carry `is_active` too, so the staff UI can
show a saved inactive location historically («غير مفعّل») without ever
offering it as a new option. Stage 3A §10 has the full behaviour.

**Tests (when implemented):**
- Details `is_active` true / false for the node.
- An inactive ancestor is flagged in `path`.
- Lists, children and search keys are unchanged (exact-key assertions).
- Inactive locations are still absent from lists.
- The existing assertion that `is_active` never appears
  (`GazaLocationApiTest::test_no_internal_field_is_exposed_and_flagged_records_stay_selectable`)
  is **deliberately updated** for the details response only, with the
  approval recorded.

---

## 8. Migration and rollout strategy (PROPOSED)

| Aspect | Plan |
|---|---|
| Placement / naming | `family_residences.original_location_id`, `.current_location_id` (§4) |
| Type | `foreignUuid` nullable; native `uuid` on PostgreSQL |
| FK / delete | `constrained('gaza_locations')->restrictOnDelete()`; named constraints |
| Indexes | One btree per column (FK checks on RESTRICT, future Stage 6 grouping) |
| PostgreSQL | `ADD COLUMN … NULL` is metadata-only (no rewrite). Adding the FK validates existing rows (all NULL) quickly. Use `SET LOCAL lock_timeout = '5s'` like DB-ADR-061, so a lock wait fails fast |
| SQLite (tests) | FKs on an existing table, as in the `add_clan_and_branch_to_families` precedent; `down()` drops foreign keys by columns on SQLite |
| Rollback | `down()` **refuses** (RuntimeException, counts only) while any row has a non-null location reference, as the DB-ADR-061 / CO-02 guarded rollbacks do. It never silently drops linkage data. Otherwise it drops the FKs, indexes and columns |
| Existing data | Untouched; no backfill; CHECKs and the partial unique index unchanged |
| Local testing | Apply **only this migration by explicit `--path`** on the local development database. The **8 unrelated pending migrations stay pending** and are not part of this feature. Run the SQLite and PostgreSQL test suites on the isolated test databases only |
| Production prerequisites (out of scope) | The full ordered migration backlog (including the 8 unrelated ones and the FB-GEO-01 migrations 090000–090003); the official import under its own approval; explicit deployment approval. **None of this is done in Stage 3** |

---

## 9. Test matrix (PROPOSED; no test created)

| # | Test | Path | Databases |
|---|---|---|---|
| T1 | Existing residences have NULL location references after migrating; all text fields byte-identical | schema | both |
| T2 | Registration with an active location at **each** level (governorate … landmark) stores it; any level accepted | W1 | both |
| T3 | Malformed UUID → 422 (not 500); the `uuid` rule runs before the lookup | W1, W2 | **PostgreSQL essential** (22P02), SQLite |
| T4 | Unknown UUID → 422 | W1, W2 | both |
| T5 | Inactive location as a new selection → 422 | W1, W2 | both |
| T6 | Saved location deactivated afterwards; PATCH of an unrelated field keeps it; PATCH sending the same UUID is accepted | W2 | both |
| T7 | Omitted location key keeps the stored value; explicit `null` clears it | W2 | both |
| T8 | Changing `displacement_status` never clears `current_location_id`; `displacement_location_text` behaviour unchanged | W2 | both |
| T9 | Selection is independent of names: two same-named locations under different parents are distinct by UUID | W1, W2 | both |
| T10 | Activity: `current_location_id` → RESIDENCE_UPDATED, `original_location_id` → DISPLACEMENT_UPDATED; no values recorded | W2 | both |
| T11 | Authorization unchanged: `family.create` / `residence.update` only; family-side accounts refused by `staff.side` | W1, W2 | both |
| T12 | RESIDENCE_UPDATE v1: existing suites pass unchanged; a v1 payload with location keys is refused (`OnlyKeys`); v1 APPLY leaves location references untouched | W3 | both |
| T13 | Coordinator REGISTER_FAMILY and import APPLY create residences with NULL location references | W4, W5 | both |
| T14 | `FamilyDetailResource`: new keys present (null-safe), the exact summary key set, `is_active`, path; no source code, flag or import field; existing keys unchanged | API | both |
| T15 | Family PWA profile and household responses do **not** contain location keys | W6 | both |
| T16 | Query count for staff detail with both locations set is constant (paths batched) | API | both |
| T17 | FK RESTRICT: deleting a referenced `gaza_locations` row fails | schema | both |
| T18 | Race (PostgreSQL only): a concurrent deactivation waits on `FOR SHARE`; no inactive value is newly committed | W2 | PostgreSQL |
| T19 | Migration `down()` refuses while references exist; succeeds when all NULL | schema | both |
| T20 | G1: details `is_active` (node, path); lists, children and search unchanged | geography API | both |

---

## 10. Risks and unresolved dependencies

| # | Risk / dependency | Severity | Mitigation |
|---|---|---|---|
| R1 | Text and structure diverge (staff or v1 edits one side only) | Medium | Accepted by ADR D9 / D10; Stage 4 shows both; no inference |
| R2 | Malformed UUID reaching PostgreSQL → 500 | Medium | `bail` + `uuid` before lookup (T3) |
| R3 | Inactive value newly committed under a race | Low (no deactivation path exists) | Action re-check with `FOR SHARE` (T18) |
| R4 | A saved inactive location dropped by an unrelated edit | Medium | Stored-value comparison in the rule; partial PATCH (T6) |
| R5 | V9: `original_location_id` per row vs per Family once history exists | Low now | No history rows in Stage 3; revisit with `residence.change` |
| R6 | Migration ordering in Production (8 unrelated pending migrations precede FB-GEO-01) | High for deployment | Out of scope; a deployment plan is required |
| R7 | Exposing location fields to families too early | Medium | No Family-side change in Stage 3 (T15) |
| R8 | G1 changes an asserted invariant of the Stage 2B tests | Low | A deliberate, approved test update (§7) |
| D1 | Stage 4 UI (selector) not built | — | Stage 4 after Stage 3.2 |
| D2 | Required selection depth per form (ADR V5) | — | D-3 |

---

## 11. Decisions requiring approval

| # | Decision | Recommendation |
|---|---|---|
| D-1 | Column placement | Both on `family_residences` (§2, §4) |
| D-2 | G1: `is_active` on the details endpoint (node and path) and in residence summaries | Approve (minimum change, §7) |
| D-3 | Required geographic depth for staff forms | None in Stage 3 (any level); revisit in Stage 4 |
| D-4 | Response shape: IDs only vs IDs plus embedded summaries with path | IDs plus summaries in `FamilyDetailResource` (§6) |
| D-5 | Activity grouping of the two fields | `current_location_id` → RESIDENCE_UPDATED; `original_location_id` → DISPLACEMENT_UPDATED |
| D-6 | Guarded rollback | `down()` refuses while references exist (§8) |
| D-7 | Validation placement | Reusable `SelectableGazaLocation` rule plus an Action-level re-check under `FOR SHARE` |
| D-8 | Indexes | One btree per FK column |
| D-9 | Applying the migration locally | Only this migration by explicit path; the unrelated pending migrations stay pending |

---

## 12. Exact Stage 3.2 implementation sequence (after approval)

1. Migration `2026_10_22_090003_add_location_references_to_family_residences_table`
   (§4, §8; guarded `down()`).
2. `FamilyResidence`: `$fillable` plus `originalLocation()` /
   `currentLocation()`.
3. `App\Rules\SelectableGazaLocation`.
4. `RegisterFamilyRequest`, `UpdateFamilyResidenceRequest`: the optional
   fields (§5.2).
5. `CreateFamilyResidenceAction::FIELDS` and
   `UpdateFamilyResidenceAction::{ADDRESS_FIELDS, DISPLACEMENT_FIELDS}`:
   the new keys, plus the in-transaction active re-check (§5.3).
6. `FamilyDetailResource`: IDs and summaries. Eager loads in
   `FamilyController::show`, `RegisterFamilyAction` and
   `UpdateFamilyResidenceAction` (`fresh([...])`); batched paths.
7. G1 (if approved): `GazaLocationPaths` selects `is_active`; the details
   response adds `is_active`; API-D4 amended; Stage 2B tests updated
   deliberately.
8. Tests T1–T20 on SQLite and PostgreSQL (isolated test databases; one
   PostgreSQL run at a time).
9. Local development database: apply only this migration by `--path`; no
   data change.
10. Documentation: ADR (§4.2 as built, §12 Stage 3), docs/02 §19, docs/04
    §24, docs/06 §46 note (no RBAC change), docs/07 roadmap, change logs.

Not part of Stage 3.2: the Staff selector UI (Stage 4), Family PWA / v2
(Stage 5), coordinator or import changes, reporting (Stage 6), Production.

---

## 13. Readiness verdict: **CONDITIONAL**

The verified code supports the linkage with small, additive, well-isolated
changes. Every write path flows through two Actions with field whitelists,
partial-PATCH semantics already distinguish omitted from cleared, and the
existing migrations give precedents for FKs, guarded rollbacks and lock
timeouts.

Stage 3.2 can start once **D-1, D-2, D-4, D-6 and D-7** are approved. The
others have recommended defaults. Production deployment remains out of
scope and depends on R6.

---

## 14. Stage 3.2 status (2026-10-15)

D-1 to D-9 were approved as recommended and implemented **locally**
(ADR-FB-GEO-01 §4.2). Production is not migrated.

| Item | As built |
|---|---|
| D-1 placement | Both columns on `family_residences` |
| D-2 G1 | Details `is_active` (node and path); residence summaries carry `is_active` |
| D-3 depth | None (any level) |
| D-4 response | IDs plus summaries in `FamilyDetailResource` only |
| D-5 activity | `current_location_id` → RESIDENCE_UPDATED; `original_location_id` → DISPLACEMENT_UPDATED |
| D-6 rollback | `down()` refuses while references exist |
| D-7 validation | `App\Rules\SelectableGazaLocation` plus an action re-check under `FOR SHARE` (`App\Support\Geography\GazaLocationSelection`) |
| D-8 indexes | One btree per column |
| D-9 local migration | Only `2026_10_22_090003` applied by `--path`; the 8 unrelated migrations still pending |

**Deviations from this audit:**
- Residence summary `path` items carry `is_active` too (§6 listed
  `{id, level, name}`), for consistency with the details endpoint and so an
  inactive **ancestor** is visible.
- Location ids are normalized to lower case before validation, comparison
  and storage, so an upper-case form of a stored UUID is the same value on
  SQLite as on PostgreSQL.
- T18 (concurrency) lives in its own PostgreSQL-only class
  (`ResidenceLocationConcurrencyTest`, DatabaseTruncation), because a second
  session cannot see the uncommitted fixtures of `RefreshDatabase`.
- T15 is a source-level guard: the family-side and coordinator read files
  contain no location reference.
- **Found during implementation (SQLite only):** adding a foreign key to an
  existing SQLite table makes Laravel rebuild it, and the rebuild recreated
  the partial `uq_family_current_residence` **without** `WHERE is_current =
  true`. That made a historical residence row a duplicate, and
  `FamilyHouseholdProfileTest` caught it. The migration now captures the
  table's SQLite index definitions before the rebuild and restores them
  exactly, in both `up()` and `down()`. T1 asserts that a historical row is
  still accepted.
  - PostgreSQL alters in place; its index was verified unchanged on the
    development database.

**Tests:**
- `tests/Feature/Geography/ResidenceLocationLinkageTest.php` (T1–T17, T19,
  T20) and `ResidenceLocationConcurrencyTest.php` (T18).
- `GazaLocationApiTest` updated deliberately for G1.

---

## Appendix: evidence index

- **Schema:**
  - `backend/database/migrations/2026_09_23_153943_create_family_residences_table.php`;
  - `2026_09_24_100000_add_displacement_and_alternate_contact_fields.php`;
  - `2026_10_01_090001_add_clan_and_branch_to_families.php` (FK precedent);
  - `2026_10_20_090000_enforce_unique_person_national_id.php`
    (`lock_timeout`, guarded refusal precedent);
  - `2026_10_22_090000_create_gaza_locations_table.php`.
- **Models:** `backend/app/Models/FamilyResidence.php`, `Family.php`
  (`residences`, `currentResidence`), `GazaLocation.php`.
- **Actions:** `CreateFamilyResidenceAction` (`FIELDS`),
  `UpdateFamilyResidenceAction` (`ADDRESS_FIELDS`, `DISPLACEMENT_FIELDS`,
  `handle`), `RegisterFamilyAction::handle`, `ApplyImportRowAction` (lines
  184–192), `UpdateFamilyAction` (docblock).
- **HTTP:** `FamilyController::{store, show}`,
  `FamilyResidenceController::update`, `RegisterFamilyRequest::rules`,
  `UpdateFamilyResidenceRequest::{authorize, rules, effectiveDisplacementStatus}`.
- **Change Requests:** `Support/ChangeRequests/Handlers/ResidenceUpdateHandler.php`
  (`FIELDS`, `inputRules`, `normalize`, `baseValues`, `apply`),
  `RegisterFamilyHandler.php` (`proposalRules`, `apply`).
- **Family PWA reads:** `Support/FamilyPortal/HouseholdReadModel.php`,
  `Http/Resources/FamilyHouseholdProfileResource.php`.
- **Coordinator resources:** `Http/Resources/Coordinator{FamilyDetail,FamilySummary,Member}Resource.php`.
- **Rules:** `backend/app/Rules/OnlyKeys.php`.
- **Geography API:** `GazaLocationController`, `GazaLocationResource`,
  `Support/Geography/GazaLocationPaths.php`,
  `tests/Feature/Geography/GazaLocationApiTest.php`.
- **Tests counted:**
  - `Families/RegisterFamilyTest`, `UpdateFamilyResidenceTest`,
    `FamilyResidenceDisplacementTest`;
  - `Corrections/OptionalResidenceLocationTest`;
  - `ChangeRequests/Residence/ResidenceUpdateTest`,
    `ResidenceUpdateConcurrencyTest`;
  - `ChangeRequests/Coordinator/RegisterFamilyRequestTest`;
  - `Import/ApplyImportRowTest`;
  - `FamilyPortal/FamilyHouseholdProfileTest`;
  - `Registry/CreationFoundationTest`;
  - `Activity/FamilyActivityLogTest`.
