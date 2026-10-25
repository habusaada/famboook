# FB-CIVIL-01A — Identity Reference Import Engine: Repository Audit

**Audit date:** 2026-10-25
**Mode:** read-only audit and planning. No application code, migration, database, permission, import or Production change.
**Repository baseline:** `feature/fb-geo-02` at `3f6bb7d`.
**Companions:**
- `FB-CIVIL-01A-TECHNICAL-DESIGN.md`
- `FB-CIVIL-01A-DATABASE-PROPOSAL.md`
- `FB-CIVIL-01A-IMPORT-WORKFLOW.md`
- `FB-CIVIL-01A-SECURITY-AND-PRIVACY.md`
- `FB-CIVIL-01A-IMPLEMENTATION-PLAN.md`
- `FB-CIVIL-01A-OPEN-DECISIONS.md`

**Labels used in all seven documents:**
- **[VERIFIED]** — located in the repository or measured on the export metadata.
- **[APPROVED]** — a business decision given in the FB-CIVIL-01A brief.
- **[PROPOSED]** — an implementation choice, not yet approved.
- **[OPEN]** — needs a decision (see the open-decisions register).
- **[RISK]** — a risk or dependency.

---

## 1. Source export (metadata only)

**Scope of this check:**
- Only the manifest, each file's first line (the header) and each file's SHA-256 were read.
- **No person record was read, printed or profiled.**
- The verification script printed counts, booleans and checksums only.

| Item | Result |
|---|---|
| Manifest `tool` | "Famboook Access Exporter", exported 2026-10-25T10:06:43Z |
| Source file / table | `هويات(1).accdb` / `t03maindata` [VERIFIED] |
| Columns | 36, identical in name and order to the 36 listed in the brief [VERIFIED] |
| Column metadata | **Names only** — no types, keys, nullability or constraints [VERIFIED] |
| Rows | `total_rows` 2,399,891 = sum of the 24 per-file counts (23 × 100,000 + 99,891) [VERIFIED] |
| Encoding | every file starts with the UTF-8 BOM; header present in every file [VERIFIED] |
| Checksums | 24/24 files match their manifest SHA-256 [VERIFIED] |
| Headers | 24/24 file headers equal the manifest column list [VERIFIED] |
| Manifest note | "All fields serialized as CSV text. Empty values may represent NULL or empty string. No source database writes." [VERIFIED] |
| Size | ≈ 765 MB on disk (≈ 33 MB per file) [VERIFIED] |

**Consequence [VERIFIED]:** the export carries no evidence about which column is a key, or how the relationship columns relate to it. NULL and empty string are indistinguishable. These questions are therefore OPEN; see `FB-CIVIL-01A-OPEN-DECISIONS.md`.

## 2. Existing import architecture

| Component | Location | What it does [VERIFIED] |
|---|---|---|
| Initial Family Import (workbook) | `app/Actions/{CreateImportBatch, SelectImportWorksheet, ConfirmImportMapping, ReconcileImportBatch, StartImportApply, RunImportApplyChunk, CompleteImportApply, ApplyImportRow, RecordImportApplyEffect, ReplaceImportWorkbook}Action.php`; `InitialFamilyImportController`, `ImportApplyController` | <ul><li>Staff upload an `.xlsx`, stored privately; worksheet and header mapping.</li><li>Rows staged in `import_rows` (`raw_payload jsonb`, `issues jsonb`); reconciliation and dry run.</li><li>Chunked **Apply** into operational families and persons.</li></ul> |
| Apply runner lock | `app/Support/Import/Apply/ApplyRunnerLock.php` | One runner per batch; a PostgreSQL session advisory lock (an in-process lock on SQLite) |
| Gaza geography reference import | `app/Console/Commands/ImportGazaGeography.php`, `app/Actions/ImportGazaGeographyAction.php` (541 lines) | **The closest precedent.** <ul><li>Artisan-only, and deliberately not in `DatabaseSeeder`.</li><li>The source file is kept outside Git; its SHA-256 is verified.</li><li>`--dry-run` and a production confirmation.</li><li>Global advisory lock, plus a partial unique index allowing a single `RUNNING` run.</li><li>The run is recorded in its own committed row; all data is written in **one transaction**, rolled back on failure.</li><li>Idempotent; never deletes; counts-only reporting.</li></ul> |
| Import run tracking | `import_batches` (bigint id, uuid, `source_checksum char(64)`, status, row_count, failure_reason, uploaded_by, applied_by); `gaza_geography_import_runs` (uuid id, `source_sha256`, status `RUNNING`/`SUCCEEDED`/`FAILED`/`REJECTED`, counters, `error_summary`) | `CHECK` constraints added through `DB::statement` (status set, `(status='RUNNING') = (finished_at IS NULL)`, SHA-256 format) |
| File storage | `ImportBatchWorkbook::DISK = 'local'` → `storage/app/private` | **[RISK]** The `local` disk has `'serve' => true` in `config/filesystems.php`. Identity source files must not use a served disk |
| Bulk loading | — | No `COPY`-based loader exists. Both importers insert through the query builder in chunks. `pdo_pgsql` is loaded locally [VERIFIED]; the driver-specific COPY method still has to be confirmed by a test [OPEN, technical] |

## 3. Queues, cache and Redis

| Fact | Evidence |
|---|---|
| Default queue: `database` | `config/queue.php` `env('QUEUE_CONNECTION', 'database')` [VERIFIED] |
| Default cache: `database` | `config/cache.php` [VERIFIED] |
| Redis | Configured in `.env.example` (`phpredis`), **not used** by any approved deployment [VERIFIED] |
| Production | `queue.default = database`, `cache.default = database` per the operator audit in `docs/deployment/FB-NOTIFY-01-PRODUCTION-PILOT-RUNBOOK.md` §1 (decision P3: keep `database`) [VERIFIED as documented, not re-checked] |
| Only worker | `famboook-notifications-worker`: `--queue=notifications --timeout=60` [VERIFIED] |

**[RISK] Mismatch with the brief.** The brief states "Queues and caching: Redis". The repository and Production use the `database` driver. The design must not assume Redis (OD-13).

## 4. PostgreSQL schema and migration conventions [VERIFIED]

- **Ids:** bigint `id()` for domain tables; `uuid` ids for run tables (`gaza_geography_import_runs`).
- **Constraints:** PostgreSQL-only `CHECK` constraints and partial unique indexes are written with `DB::statement` and guarded by the driver, because the SQLite test suite skips them.
- **Foreign keys:** `restrictOnDelete()` throughout the newer tables.
- **National ID on `persons`:**
  - `persons.national_id` is a nullable `string`;
  - `2026_10_20_090000_enforce_unique_person_national_id.php` adds `CHECK (national_id ~ '^[0-9]{9}$' AND national_id !~ '^([0-9])\1{8}$')` and uniqueness;
  - IDs are strings, so leading zeros are preserved.
- **National ID support classes:** `app/Support/{NationalId, NationalIdFingerprint, NationalIdGuard, NationalIdMask, RegistryNationalId}.php`. These cover normalization, keyed fingerprints, masking and constant-time comparison.
- **Database:** PostgreSQL 16+ (`docs/04`, `docs/08`).
- **Database role:** a single non-superuser application role, `famboook_app`. The same role runs migrations through `deploy-backend.sh` (`docs/08` §11, `deploy/scripts/db-preflight.sql`). A separate read-only backup role is optional. **No separate import-writer role exists.**

## 5. Authorization and permissions [VERIFIED]

- **Permission model:** Spatie Permission, with 162 permissions named `domain.action` (e.g. `import.upload`, `activity-log.view`). Policies are authoritative.
- **Verification:** `famboook:verify-permissions` checks the exact per-role matrix.
- **Roles:** `StaffRoles` lists SUPER_ADMIN, ADMINISTRATOR, DATA_ENTRY, REVIEWER, SOCIAL_WORKER and REPORTS_VIEWER, plus family-side roles.
- **Gated-permission precedent:**
  - `import.apply` is granted to SUPER_ADMIN **only** when `config('import.apply_enabled')` is true (`RolePermissionSeeder::GATED_ROLE_PERMISSIONS`, `config/import.php`, `docs/06` §61);
  - deploying the code grants nothing.
- **Import routes:** `/api/v1/imports/initial-families/*`, guarded by `can:import.upload|validate|review` middleware.
- **No identity-reference permission, route, policy or resource exists.**

## 6. Staff import screens [VERIFIED]

- **Screens:** `frontend/app/(staff)/administration/imports/` (list and detail) and `frontend/components/administration/import-wizard/` (steps: clan, dry-run, apply and others).
- **Admin panel:** Filament is limited to `app/Filament/Resources/Users` (system administration).
- **Fit:** these screens are a wizard for workbook-to-operational imports. They are **not** suitable for a 765 MB, 2.4-million-row sensitive reference dataset; see the technical design §2.

## 7. Test coverage [VERIFIED]

- **Import tests:** 16 test files under `tests/Feature/Import`, plus geography import tests (`GazaGeographyImportTest`, `GazaGeographyImportRunTest`).
- **Two suites:**
  - SQLite in memory (`phpunit.xml`): 3,006 passed on `3f6bb7d`;
  - PostgreSQL (`phpunit.pgsql.xml`, port 5433): the PostgreSQL-only behaviour (locks, `CHECK` constraints, concurrency) is tested only here.
- **Known pre-existing PostgreSQL-only failures:** about 10, documented.
- **Implication:** COPY, triggers, roles and atomic publication are PostgreSQL-only, so FB-CIVIL-01A needs PostgreSQL tests, with **synthetic** fixtures only.

## 8. Documentation and decisions [VERIFIED]

- **Roadmap:** no document mentions a civil registry, a reference identity dataset or `t03maindata`, and `docs/07-ROADMAP.md` has no FB-CIVIL stage.
- **`docs/03` §47e** (around line 1273): "**there is no global National ID search**. IDs are never stored, returned, put in URLs, flashed or logged." It also states "No genealogy is inferred".
- **`docs/03` line 214:** "Out of scope for V1: multi-tenancy, **genealogy**, …".
- **`docs/02` §15:** family relationships are recorded relative to the current household head only.
- **Legal (FB-LEGAL-01):**
  - the Privacy Policy (version 1.0, prepared) describes the data processed for the registry, and says the legal basis is not yet documented (D3 PENDING);
  - it does not describe a population-scale reference identity dataset.

## 9. Conflicts with the current family and person model

| # | Conflict | Effect |
|---|---|---|
| C-1 | `docs/03` §47e forbids global National ID search | FB-CIVIL-01B (inquiry by National ID) contradicts an approved rule. 01A must not create any search path; 01B needs an explicit baseline change (OD-09) |
| C-2 | Genealogy is out of scope for V1 (`docs/03` line 214, §47e) | 01C (child suggestions) and 01D (family tree) contradict it (OD-10) |
| C-3 | `persons.national_id` must be 9 digits and unique | Reference values may not meet that format. Raw values must be stored unchanged in the reference layer, and the reference must never write to `persons` [APPROVED: no direct import into `persons`] |
| C-4 | Privacy Policy and D3 | Processing about 2.4 million identities is not covered by the current policy text, and D3 (legal basis) is unresolved (OD-08) |
| C-5 | Single database role | The approved immutability (FB-CIVIL-D01) needs database-level separation that the current single-role setup cannot express (OD-12) |

## 10. Production safety constraints [VERIFIED]

- **Production:**
  - recorded Production commit `bb6cb0d`; candidate `3f6bb7d` not yet deployed;
  - `deploy-backend.sh` runs `migrate --force` automatically;
  - backups are age-encrypted `pg_dump` files, and **a restore has never been rehearsed** (runbook R-1).
- **[RISK] Disk and memory:** the capacity for about 2.4 million rows plus versions and backups is unknown (OD-17).
- **[RISK] Stray dump file:** an untracked database dump, `famboook-2026-10-23_18-18-28.dump` (1.6 MB), sits in the repository root and is **not** covered by `.gitignore`. It must not be committed. It was not opened during this audit (OD-19).
- **This audit:** made no Production access and no change.
