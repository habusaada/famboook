# FB-GEO-01 — Production Deployment Plan

**Status:** PLAN ONLY — awaiting approval. Nothing here has been executed
on Production.
**Date:** 2026-10-16
**Scope:** the whole FB-GEO-01 slice:
- Stages 1C–4C: geographic reference schema and import, Staff API,
  residence linkage, Staff UI;
- Stage 5: Family API and RESIDENCE_UPDATE v2;
- the PX-1 fix.

**Conventions:**
- Everything follows docs/08 (§12 backups, §13 restore test, §16b–§16d
  runbook conventions).
- Every git, artisan and deploy command runs **as `deploy`**, never as root.
- `<app-root>` is the server's Famboook directory, `<sha>` the approved
  commit, `<php>` the PHP-FPM version, and `famboook_pilot` the Production
  database (as in `backup-db.sh`).
- `psql` means a read-only session as the database administrator unless a
  step says otherwise.

---

## 1. Summary

| Item | Value |
|---|---|
| Migrations | Four, additive, in this order: `2026_10_22_090000` → `090001` → `090002` → `090003` |
| Reference data | Official MoSD workbook, SHA-256 `37826e400b77e583dbd0c2e7fe34274db6465a293437ce11d0c4d20c4f945e38`; **2,139** locations and **228** review flags; imported by `famboook:import-gaza-geography` |
| Backfill | **None.** Existing residences keep `NULL` references; no text is read, inferred or changed |
| RBAC | **No change.** `RolePermissionSeeder` unchanged; `famboook:verify-permissions` must still pass |
| Packages | None (`composer.lock`, `package-lock.json` unchanged) |
| New `.env` keys | **None** |
| Queue | None needed (`QUEUE_CONNECTION=sync`; FB-GEO-01 adds no job) |
| Downtime | Maintenance mode during `deploy-backend.sh` (migrations take seconds) |
| Family channel | Unchanged: the current `CHANGE_REQUESTS_FAMILY_SUBMISSION_MODE` is kept |

---

## 2. What is deployed (working tree, 2026-10-16)

All changes are **uncommitted** on top of `8305b61` (CO-02 release
preparation). They must be committed and pushed **before** this plan can
run (Decision D1).

**Backend:**
- **Migrations:** `database/migrations/2026_10_22_090000…090003` (§3).
- **Reference and import:**
  - models `GazaLocation`, `GazaLocationReviewFlag`,
    `GazaGeographyImportRun`;
  - enums `GazaLocationLevel`, `GazaLocationReview{Issue,Severity,Status}`,
    `GazaGeographyImportStatus`;
  - `GazaGeographyImportException`, `ImportGazaGeographyAction`, command
    `ImportGazaGeography`;
  - `Support/Geography/*`: Source, Workbook, Paths, Summary, Selection,
    Lookup.
- **APIs:**
  - `GazaLocationController` (Staff) and
    `Family/FamilyGazaLocationController` (family mirrors);
  - `GazaLocationResource`;
  - routes in `routes/api.php`.
- **Residence linkage:**
  - `FamilyResidence` relations, `SelectableGazaLocation`;
  - `CreateFamilyResidenceAction`, `UpdateFamilyResidenceAction`;
  - `RegisterFamilyRequest`, `UpdateFamilyResidenceRequest`;
  - `FamilyDetailResource`.
- **RESIDENCE_UPDATE v2:**
  - `ReadsEarlierPayloadVersions`, `ChangeRequestWorkflow`;
  - `ResidenceUpdateHandler` (writes v2, reads v1 + v2);
  - `FamilyHouseholdProfileResource`, `HouseholdReadModel`.

**Frontend:**
- **Geography:** `components/geography/gaza-location-selector.tsx`,
  `lib/api/gaza-locations.ts`, `lib/types/api/gaza-location.ts`.
- **Staff:** registration page, `edit-residence-dialogs.tsx` (PX-1),
  `family-overview.tsx`, `lib/api/families.ts` (PX-1),
  `components/shared/edit-dialog-parts.tsx` (optional `submitDisabled`).
- **Family PWA:** `family-residence-update-form.tsx`, its schema,
  `lib/api/family-household.ts`.
- **Schemas and types:** `family-registration.ts`, `edit-residence.ts`,
  `lib/types/api/family.ts`.

**Tests:**
- backend: `tests/Feature/Geography/*`,
  `tests/Feature/ChangeRequests/Residence/ResidenceUpdateV2Test.php` and
  four updated tests;
- frontend: five new or updated geography and residence test files.

**Docs:** docs/02, 03, 04, 06, 07, 11, the ADR, the audits, this plan.

**Other:**
- `.gitignore` keeps `data/references/*.xlsx` out of Git.
- **Not FB-GEO-01:** the untracked `docs/12-FEATURE-DELIVERY-PLAYBOOK.md`
  (a general playbook the ADR refers to). Decision D2.

**Not deployed by Git:** the workbook (§4) — it is gitignored on purpose.

---

## 3. Migrations

### 3.1 The four FB-GEO-01 migrations (exact order)

| # | Migration | Effect | Lock / risk |
|---|---|---|---|
| 1 | `2026_10_22_090000_create_gaza_locations_table` | New table; composite parent FK `RESTRICT`; CHECKs on level, parent, landmark type, non-empty | New table only |
| 2 | `2026_10_22_090001_create_gaza_location_review_flags_table` | New table; FK `RESTRICT` to locations; CHECKs | New table only |
| 3 | `2026_10_22_090002_create_gaza_geography_import_runs_table` | New import-history table; single-RUNNING partial unique index; CHECKs | New table only |
| 4 | `2026_10_22_090003_add_location_references_to_family_residences_table` | `family_residences.original_location_id` / `current_location_id`: nullable UUID, FK `RESTRICT`, one btree index each. No CHECK, no row change | `SET LOCAL lock_timeout = '5s'`. Adding a nullable column is metadata-only; the FK validation and index build on an all-NULL column take milliseconds at Pilot size. A lock timeout (SQLSTATE 55P03) changes nothing |

### 3.2 The "eight pending migrations"

The eight migrations reported pending in earlier stages are pending on the
**local development database only**:
- `2026_10_15_090000` (self-OTP mobile trust)
- `2026_10_16_090000` (digital credentials)
- `2026_10_17_090000` and `2026_10_17_090001` (change requests, workflow
  events)
- `2026_10_18_090000` (contact update)
- `2026_10_19_090000` (auth security events `family_id`)
- `2026_10_20_090000` (National ID uniqueness)
- `2026_10_21_090000` (coordinator requests)

They belong to releases with their own runbooks (docs/08 §16b, §16c,
§16d).

**On Production, any of them that is still pending WOULD run** during a
normal `php artisan migrate` — `deploy-backend.sh` runs
`php artisan migrate --force` with no filter. Two consequences:
- `2026_10_20_090000` has its own **audit gate** (§16c) and must never run
  as a side effect of FB-GEO-01;
- `2026_10_21_090000` is the CO-02 release.

### 3.3 Strategy: no unintended schema change

1. **Step 0 reads Production's migration state.** The only acceptable
   state is: every migration up to and including
   `2026_10_21_090000_add_coordinator_change_requests` is **Ran**, and
   exactly the four `2026_10_22_0900*` migrations are **Pending**.
2. `php artisan migrate --pretend` (read-only: prints the SQL, executes
   nothing) must list **only** the four migrations' statements.
3. If anything else is pending: **STOP**. Deploy the earlier releases first
   through their own runbooks and gates (§16c audit for `2026_10_20`, §16d
   for CO-02), then restart this plan from step 0.
4. `deploy-backend.sh` is used unchanged once that state is proven. Its
   `migrate --force` then runs only the four. Running them one by one with
   `--path` is unnecessary and would diverge from the standard runbook.

---

## 4. Official workbook and import

### 4.1 Workbook

| Check | Value |
|---|---|
| File | `gaza-geographic-classification.xlsx` (134,090 bytes) |
| Pinned checksum | `GazaGeographySource::OFFICIAL_SHA256` = `37826e400b77e583dbd0c2e7fe34274db6465a293437ce11d0c4d20c4f945e38` |
| Verified locally | `sha256sum` of the local copy matches (2026-10-16) |
| Server location (default path) | `<app-root>/data/references/gaza-geographic-classification.xlsx` (`dirname(base_path())`) |
| Git | **Excluded** (`.gitignore`, Stage 1B D-10). Distribution rights unverified (Decision D3) |
| Transfer | Out of band, by the system administrator, to the path above; owner `deploy`, mode `0640`. Kept on the server after import (re-runs are idempotent and need it) |

The importer re-verifies the SHA-256 itself and refuses a different file.

### 4.2 Import behaviour (`ImportGazaGeographyAction`)

- **Idempotent:** a record already present with the same name, parent and
  type is left untouched; existing flags are never reset. A second run
  creates nothing.
- **One transaction** for locations, flags and the run's SUCCEEDED outcome.
  A failure rolls everything back and records the run as REJECTED or
  FAILED.
- **Refuses to change** an imported record (name, parent, type), and never
  deletes or deactivates anything.
- One import at a time (advisory lock plus single-RUNNING index).
- Never reads or writes families, residences or any non-geography table.
- **Production confirmation:** `--force` is required. `--dry-run` validates
  and reports without writing or recording a run.

### 4.3 Expected counts

| Level | Count |
|---|---|
| GOVERNORATE | 5 |
| CITY | 26 |
| AREA | 28 |
| NEIGHBORHOOD | 234 |
| LANDMARK | 1,846 |
| **Total** | **2,139** (all active) |

| Review issue | Flags |
|---|---|
| NAME_WHITESPACE | 191 |
| POSSIBLE_DUPLICATE_LANDMARK | 14 |
| PARENT_PLACE_MISMATCH | 10 |
| SAME_PARENT_SIMILAR_NAME | 8 |
| UNUSUAL_LANDMARK_TYPE | 5 |
| **Total** | **228** |

These were confirmed by real imports into the disposable test databases
(Stages 1C and 4C).

---

## 5. Compatibility with Production data

| Data | Effect | Evidence |
|---|---|---|
| Families, persons, memberships | None | No migration or code path writes them |
| Existing residences | Two new NULL columns; every text field byte-for-byte unchanged; no backfill | Migration 090003; checksum query V3 (§9) |
| Staff UI on residences without references | Shows «لم يُحدَّد موقع جغرافي»; selectors start empty | Stage 4B/4C tests |
| **Open RESIDENCE_UPDATE v1 requests** | Still reviewed, approved, rejected and applied **unchanged**: the v2 handler reads v1, computes their base over the original eight fields, and never migrates them | `ResidenceUpdateV2Test` (v1 compatibility tests); all existing residence tests pass |
| Families on a cached old PWA build | Their eight-field requests are accepted (references omitted = unchanged) | Handler v2 input rules; test |
| Window between backend and frontend deploys | Old frontend + new backend: no selector shown, payloads compatible | Additive API (new optional keys only) |
| Coordinator | No change (no location fields, no access to geography endpoints) | Tests; T15 guard |

---

## 6. Preflight checklist (all before the deployment day)

| # | Gate | Owner |
|---|---|---|
| P1 | Decisions D1–D6 (§12) answered in writing | Owner |
| P2 | FB-GEO-01 committed, reviewed and pushed to `origin/main`; `<sha>` recorded | Developer, after authorization |
| P3 | On `<sha>`, locally: clean tree; backend SQLite suite, frontend suite, `tsc`, ESLint and `npm run build` pass | Developer |
| P4 | On `<sha>`: PostgreSQL suite (docs/08 §16a, isolated cluster on port 5433) for `tests/Feature/Geography`, `tests/Feature/ChangeRequests`, `tests/Feature/FamilyPortal`, `tests/Feature/FamilyAuth`; known legacy fixture failures only (docs/11 FU-12) | Developer |
| P5 | **Rehearsal on a restored copy of Production** (§7) passed | Developer + system administrator |
| P6 | Production PostgreSQL major version recorded (`SELECT version()`); if 16, the P4/P5 runs used PostgreSQL 16 (Decision D4) | System administrator |
| P7 | Latest nightly backup exists off-server and a §13 restore test has passed on a recent backup | System administrator |
| P8 | Workbook transferred to the server path and its `sha256sum` matches the pinned value (§4.1) | System administrator |
| P9 | Disk and memory sufficient for `npm run build` (≈ 2 GB free) | System administrator |
| P10 | Staff briefed: optional geographic fields beside the text; reviewers see locations as names and paths | Owner |

---

## 7. Rehearsal (P5) — on a copy, never on Production

On the isolated test cluster (docs/08 §16a, port 5433), with the latest
Production backup:

```text
# restore the backup into a disposable database whose name ends in _test
createdb -h 127.0.0.1 -p 5433 -U postgres famboook_rehearsal_test
pg_restore -h 127.0.0.1 -p 5433 -U postgres --dbname=famboook_rehearsal_test --no-owner --no-privileges --exit-on-error <decrypted-backup.dump>

# run the release code against it (environment override only; .env untouched)
export DB_HOST=127.0.0.1 DB_PORT=5433 DB_DATABASE=famboook_rehearsal_test DB_USERNAME=postgres DB_PASSWORD=
php artisan migrate:status        # expect: only the four 2026_10_22_0900* pending
php artisan migrate --pretend     # expect: only their SQL
php artisan migrate --force       # expect: exactly the four
php artisan famboook:import-gaza-geography --dry-run
php artisan famboook:import-gaza-geography --force
php artisan famboook:import-gaza-geography --force   # idempotency: "created: 0"
# then run every verification query in §9 (V1–V8) against famboook_rehearsal_test
# and, if an open v1 RESIDENCE_UPDATE exists in the copy, approve + apply it there only
dropdb -h 127.0.0.1 -p 5433 -U postgres famboook_rehearsal_test
```

The restored copy holds real registry data: it stays on the isolated
cluster, is never copied elsewhere, and is dropped at the end. If the
backup is encrypted, decrypting it follows §13 (keys held offline by the
administrator).

---

## 8. Deployment runbook (exact order, with stop conditions)

```text
# ---------- 0. read-only state (record every output; no change) ----------
sudo -u deploy git -C <app-root> rev-parse HEAD
sudo -u deploy php <app-root>/backend/artisan migrate:status | grep -E "2026_10_(1[5-9]|2[0-2])"
psql -d famboook_pilot -c "SELECT version()"
psql -d famboook_pilot -f V0-baseline.sql          # §9 V0, saved to a file
sha256sum <app-root>/data/references/gaza-geographic-classification.xlsx
```
**STOP unless:**
- every migration through `2026_10_21_090000` is Ran and exactly the four
  `2026_10_22_0900*` are Pending;
- the workbook checksum equals `37826e40…945e38`.

**Checkpoint A1: owner go / no-go.**

```text
# ---------- 1. backup, then confirm it (docs/08 §12) ----------
sudo -u deploy <app-root>/deploy/scripts/backup-db.sh
#   confirm: the .dump.age and .sha256 exist, pg_restore --list succeeded, off-server copy done

# ---------- 2. code ----------
sudo -u deploy git -C <app-root> fetch origin
sudo -u deploy git -C <app-root> checkout --detach <sha>
sudo -u deploy git -C <app-root> status --short     # must be empty (the workbook is gitignored)
sudo -u deploy git -C <app-root> rev-parse HEAD      # must equal <sha>

# ---------- 3. migration preview (read-only) ----------
cd <app-root>/backend
sudo -u deploy php artisan migrate --pretend
```
**STOP** if the preview contains SQL for anything but the four FB-GEO-01
migrations.

```text
# ---------- 4. backend: down → composer → migrate → RBAC seed → verify → optimize → up ----------
sudo -u deploy <app-root>/deploy/scripts/deploy-backend.sh
sudo systemctl reload php<php>-fpm
sudo -u deploy php artisan migrate:status | grep 2026_10_22    # all four: Ran
```
**STOP conditions:**
- If `migrate` ran anything else, or failed: `php artisan up` and
  investigate. A failure inside one migration rolled that migration back;
  a lock timeout (55P03) changed nothing.
- If `famboook:verify-permissions` fails: stop and do not continue.

Run V1 and V2 (schema). **Checkpoint A2.**

```text
# ---------- 5. reference import ----------
sudo -u deploy php artisan famboook:import-gaza-geography --dry-run
#   expect: SHA-256 verified; Total in source 2139; flags 228; nothing written
sudo -u deploy php artisan famboook:import-gaza-geography --force
#   expect: "Import run: <uuid> (SUCCEEDED)", "created: 2139", flags table totalling 228
```
**STOP conditions:**
- A REJECTED or FAILED run has written no geographic record (single
  transaction). Do not retry blindly: read the reason (checksum, structure,
  revision conflict, a RUNNING run).
- The application keeps working while the reference is empty: the
  selectors show «لا توجد خيارات».

Run V4–V6 (counts). **Checkpoint A3.**

```text
# ---------- 6. frontend ----------
sudo -u deploy <app-root>/deploy/scripts/deploy-frontend.sh      # npm ci → build → restart
sudo nginx -t && sudo systemctl reload nginx

# ---------- 7. verification ----------
#   §9 V3, V7, V8 and the smoke checks in §10
```
**Checkpoint A4: owner sign-off.**

No cache step beyond the scripts is needed:
- `deploy-backend.sh` runs `optimize` (configuration, routes — the new
  routes included — views, events) and `filament:optimize`;
- the PHP-FPM reload clears OPcache;
- no `.env` change, so no extra `config:clear`;
- no queue to restart.

---

## 9. Verification queries (read-only)

```sql
-- V0 baseline (before step 1; save the output)
SELECT (SELECT count(*) FROM families) AS families,
       (SELECT count(*) FROM family_residences) AS residences,
       (SELECT count(*) FROM change_requests) AS change_requests,
       (SELECT count(*) FROM workflow_events) AS workflow_events;
SELECT payload_version, status, count(*) FROM change_requests
 WHERE type = 'RESIDENCE_UPDATE' GROUP BY 1, 2 ORDER BY 1, 2;
-- residence text fingerprint (no value is printed)
SELECT md5(string_agg(concat_ws('|', id, governorate, city, area, neighborhood, address_text,
       original_residence_text, displacement_status, displacement_location_text), E'\n' ORDER BY id))
  FROM family_residences;

-- V1 tables and constraints
SELECT to_regclass('gaza_locations'), to_regclass('gaza_location_review_flags'), to_regclass('gaza_geography_import_runs');
SELECT conname FROM pg_constraint WHERE conrelid = 'gaza_locations'::regclass ORDER BY 1;

-- V2 residence columns (both nullable, all NULL right after the migration)
SELECT column_name, is_nullable, data_type FROM information_schema.columns
 WHERE table_name = 'family_residences' AND column_name IN ('original_location_id', 'current_location_id');
SELECT count(*) FILTER (WHERE original_location_id IS NOT NULL OR current_location_id IS NOT NULL) AS referenced
  FROM family_residences;                                        -- expect 0 (no backfill)

-- V3 nothing else changed: re-run the V0 queries; every count and the md5 must be identical

-- V4 locations by level (5 / 26 / 28 / 234 / 1846; all active)
SELECT level, count(*), count(*) FILTER (WHERE is_active) AS active FROM gaza_locations GROUP BY 1 ORDER BY 1;

-- V5 review flags (191 / 14 / 10 / 8 / 5 = 228)
SELECT issue_code, count(*) FROM gaza_location_review_flags GROUP BY 1 ORDER BY 2 DESC;

-- V6 import history: exactly one SUCCEEDED run with the pinned checksum
SELECT status, source_sha256, started_at, finished_at FROM gaza_geography_import_runs ORDER BY started_at;

-- V7 hierarchy integrity (expect 0 rows each)
SELECT c.id FROM gaza_locations c LEFT JOIN gaza_locations p ON p.id = c.parent_id
 WHERE c.level <> 'GOVERNORATE' AND p.id IS NULL;
SELECT id FROM gaza_locations WHERE level = 'GOVERNORATE' AND parent_id IS NOT NULL;

-- V8 open v1 requests unchanged: same rows as in V0 (payload_version 1, same statuses)
SELECT payload_version, status, count(*) FROM change_requests
 WHERE type = 'RESIDENCE_UPDATE' GROUP BY 1, 2 ORDER BY 1, 2;
```

---

## 10. Post-deployment smoke checks (no data written beyond logins)

1. `curl https://api.famboook.com/api/v1/health` → `{"status":"ok"}`.
   `famboook.com/login` and `famboook.com/family/login` load.
   `famboook:verify-permissions` passes.
2. **Guests:**
   - `GET /api/v1/gaza-locations/governorates` → 401;
   - `GET /api/v1/family/change-requests/gaza-locations/governorates` → 401.
3. **Staff DATA_ENTRY:** «تسجيل أسرة جديدة» shows both geographic
   selectors, with 5 governorates, beside the unchanged text fields. Open a
   cascade to the landmark level, then **leave without saving**.
4. **Staff REVIEWER:** `GET /api/v1/gaza-locations/governorates` → 403. The
   change-request queue opens, and an existing v1 RESIDENCE_UPDATE (if any)
   displays as before. **Do not approve anything.**
5. **Family residence tab** of an existing family: text unchanged, and
   «لم يُحدَّد موقع جغرافي» for both references.
6. **Household head** (the authorized pilot account, docs/09):
   - `GET /api/v1/family/household/profile` contains
     `original_location: null` and `current_location: null`;
   - if the family submission mode admits this account, the residence form
     shows the two selectors. **Do not submit.**
7. Coordinator Space (if a coordinator exists) is unchanged.
8. `storage/logs/laravel.log`: no new errors.

---

## 11. Rollback and recovery

Three operations, as in docs/08 — never mix them up:
- **Feature shutdown:** a family submission mode change.
- **Code rollback:** check out the previous SHA and re-run both deploy
  scripts.
- **Data restoration:** owner decision, for data loss or corruption only.

| Situation | Reversible? | Action |
|---|---|---|
| Stopped at step 0–3 | Yes | Nothing changed: check out the previous SHA if step 2 ran; done |
| After step 4, before the import | **Yes** | Either keep the empty schema and roll back the code (additive; the previous code ignores the new tables and columns), **or** `php artisan migrate:rollback --step=4` (drops the three tables and the two columns; no row touched), then roll back the code |
| After the import, before any reference is saved (V2 `referenced` = 0 and no v2 request) | **Yes** | Preferred: roll back the code and **keep** schema and reference data (harmless, re-usable). `migrate:rollback --step=4` is also possible; it discards the reference rows, which can be re-imported from the workbook |
| A Staff user saved a reference, or a family submitted a v2 RESIDENCE_UPDATE | **No — manual recovery / fix forward** | 1. If the problem is in the family channel, feature shutdown (`CHANGE_REQUESTS_FAMILY_SUBMISSION_MODE=OFF`, `config:clear`, `optimize`, PHP-FPM reload). 2. **Fix forward.** A code rollback would leave v2 requests un-appliable: the previous handler reads v1 only, so v2 requests answer NOT_APPLICABLE. Saved references would also become invisible, though not lost. 3. `migrate:rollback` of 090003 **refuses** while any reference exists; never force it, null references, or edit rows |
| Data loss or corruption (not expected: no step writes registry data) | Manual | Restore from the step-1 backup **only** by owner decision. A restore discards every real transaction since the backup (docs/08 §16b rule B4) |

**Never:**
- drop or truncate the geography tables after references exist;
- edit `change_requests` or `workflow_events`;
- run `migrate:fresh`;
- restore a backup to "undo" this release.

---

## 12. Decisions required before deployment

| # | Decision | Recommendation |
|---|---|---|
| D1 | Authorize committing and pushing the FB-GEO-01 work (and the commit grouping), then fix `<sha>` | One release branch merged to `main`: schema/import, API/linkage, UI, v2/PX-1, docs |
| D2 | Ship `docs/12-FEATURE-DELIVERY-PLAYBOOK.md` with this release or separately | Separately (it is not FB-GEO-01), or together as a docs-only commit if the owner prefers |
| D3 | Distribution rights for the MoSD workbook: may it be copied to the Production server (still kept out of Git)? | Required before P8. Without it, the import cannot run, and the selectors stay empty («لا توجد خيارات») while text entry keeps working |
| D4 | Production PostgreSQL major version and test parity. FB-GEO-01 was tested on 18.6; docs/08 states 16+ | Rehearse (P5) on the same major version as Production |
| D5 | Deployment order relative to pending earlier releases. If step 0 shows any `2026_10_15…2026_10_21` migration pending, those releases (with the §16c National ID audit) go first, each under its own approval | Never combine them with FB-GEO-01 |
| D6 | Family channel exposure after deployment: keep the current `CHANGE_REQUESTS_FAMILY_SUBMISSION_MODE` (the v2 form then reaches exactly the families who can already submit) | Keep unchanged; any widening is a separate decision (docs/08 §16b) |

**Approval checkpoints:**
- **A0:** this plan approved;
- **A1:** go / no-go after the step-0 state read;
- **A2:** after migrations;
- **A3:** after import counts;
- **A4:** final sign-off after the smoke checks.

---

## 13. Risks

| # | Risk | Likelihood / impact | Mitigation |
|---|---|---|---|
| R1 | An unrelated pending migration runs inside `migrate` (esp. the National ID constraint without its audit) | Medium / high | Step-0 state read, `migrate --pretend`, rehearsal, stop condition |
| R2 | Workbook missing or different on the server | Low / medium | sha256 at P8 and step 0; the importer re-verifies; the app works without the reference |
| R3 | Lock wait on `family_residences` during migration 090003 | Low / low | `lock_timeout` 5s; maintenance mode; retry after investigating |
| R4 | PostgreSQL version differences | Low / medium | D4; rehearsal on the same major version |
| R5 | Open v1 requests behave differently | Low / high | Covered by tests; V0/V8 comparison; never test-approve on Production |
| R6 | Code rollback after v2 requests exist strands them | Medium / high | Fix forward; family mode OFF as the first response (§11) |
| R7 | Family PWA desktop selector is cramped (about 117px triggers in the narrow column) | Certain / low | Cosmetic; the full path is shown below; optional later fix |
| R8 | An active location under an inactive parent is accepted (OBS-1) | None today (nothing deactivates) / low | Decide before any deactivation feature |
| R9 | Not tested on real phones, Safari or Firefox | Medium / low | Manual check during smoke step 6 by the pilot account; follow-up pass |
| R10 | A legacy PostgreSQL test flake (FU-12) or the coordinator random-name test flake masks a real failure | Low / medium | Compare against the documented failure lists; rerun |

---

## 14. Not part of this deployment

- Coordinator geography, reporting and export changes.
- Any location deactivation or revision import (ADR §12 stage 6 and later).
- Any backfill of existing residences.
- Widening the family submission mode.
