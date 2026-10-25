# FB-CIVIL-01A — Database Proposal

**Status:** PROPOSED — nothing created, migrated or executed.
**Date:** 2026-10-25.
**Target:** PostgreSQL 16+, separate schema `identity_ref` [PROPOSED].

All column names of the raw layer are the **original source names**, unchanged [APPROVED]. All source values are `text`: National IDs and every other value are stored exactly as exported, preserving leading zeros [APPROVED]. NULL and empty string cannot be distinguished in the export [VERIFIED, manifest note], so both are stored as `''` in the raw layer and recorded consistently in the row hash.

## 1. Tables

### 1.1 `identity_ref.import_runs`
| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `status` | text | `CHECK IN ('RUNNING','SUCCEEDED','FAILED','REJECTED','NOTHING_TO_DO')`; `CHECK ((status='RUNNING') = (finished_at IS NULL))` |
| `mode` | text | `SNAPSHOT` or `PARTIAL` (OD-05; only `SNAPSHOT` enabled until decided) |
| `dry_run` | boolean | Dry runs are recorded but never publish |
| `manifest_sha256` | char(64) | SHA-256 of `manifest.json` |
| `source_table`, `source_exported_at`, `manifest_total_rows` | text, timestamptz, integer | From the manifest |
| `started_at`, `finished_at` | timestamptz | |
| `started_by_os_user` | text | The shell user. No application user: the run is CLI-only |
| Counters | integer | `rows_loaded, new, changed, unchanged, missing, duplicate_identical, conflicts, invalid` |
| `release_id` | uuid null FK → `releases` | Set only when published |
| `error_summary` | text null | **Never** contains record values |

A partial unique index allows only one `RUNNING` row (the geography precedent).

### 1.2 `identity_ref.import_files`
`id`, `run_id` FK, `file_name`, `expected_sha256`, `actual_sha256`, `expected_rows`, `loaded_rows`, `status` (`PENDING|VERIFIED|STAGED|FAILED`), timestamps.

Unique on `(run_id, file_name)`, plus `CHECK` constraints for the SHA-256 format and for `status='STAGED' ⇒ loaded_rows = expected_rows`.

### 1.3 `identity_ref.import_issues`
| Column | Notes |
|---|---|
| `id` bigint, `run_id` FK, `file_name`, `line_number` | Where the issue is — enough for an authorized operator to inspect the **source file** |
| `code` | `DUPLICATE_IDENTICAL, CONFLICT, INVALID_KEY, BLANK_OVER_VALUE, BAD_COLUMN_COUNT, ENCODING, …` |
| `column_name` | Optional |
| `key_fingerprint` | **Keyed HMAC** of the identity key (the `NationalIdFingerprint` pattern). Never the key in clear |

No raw values, names or IDs are stored [PROPOSED, security §4].

### 1.4 `identity_ref.staging_<run>` (UNLOGGED, temporary per run)
- **Columns:** the 36 source columns as `text`, plus `file_name`, `line_number`, `row_hash`.
- **Lifecycle:** created by the import role and dropped at the end of the run, or by the next run after a crash.
- **Access:** never granted to `famboook_app`.

### 1.5 `identity_ref.records` — published current state
| Column | Notes |
|---|---|
| `id` bigint PK | |
| `source_key` text NOT NULL UNIQUE | The value of the key column chosen by OD-01 |
| 36 source columns, `text` | Original names. `select` is a reserved word in SQL and must always be quoted |
| `row_hash` char(64) | |
| `first_release_id`, `last_changed_release_id` | FK → `releases` |

### 1.6 `identity_ref.record_versions` — append-only history
`id`, `record_id` FK, the 36 columns and `row_hash` **as they were**, `valid_from_release_id`, `superseded_by_release_id`, `superseded_at`.

Written only when a record changes. This table is never updated or deleted, even by the import role (§3).

### 1.7 `identity_ref.record_presence` — seen-ness per release [PROPOSED]
`(release_id, record_id)`: records present in that release's source.

**Why:** it avoids rewriting all 2.4 million `records` rows each run just to update a `last_seen` column. MISSING = published records with no presence row in the newest release.

**Alternatives** (OD-07):
- keep only the missing set per release, which is smaller if most records are present; or
- a compact bitmap.

### 1.8 `identity_ref.releases` and `identity_ref.current_release`
- **`releases`:** `id` uuid, `run_id`, `published_at`, counters, `source_manifest_sha256`.
- **`current_release`:** a single row (`CHECK (id = 1)`) holding `release_id`. It's switched in the publication transaction.

## 2. Indexes [PROPOSED]
- **Keys:** `records(source_key)` unique; `record_versions(record_id, superseded_at)`; `record_presence` primary key `(release_id, record_id)`.
- **Relationship columns:** **no indexes now.** Indexes on `fatherid` / `motherid` / `partnerid` / `householdid` belong to 01B–01D, after OD-03 validates their semantics. Adding them now would only help enumeration.
- **Load order:** COPY into staging, then build the staging hash and key indexes after the load.

## 3. Privileges and immutability (FB-CIVIL-D01) [PROPOSED]

**Database roles:**

| Role | Can | Cannot |
|---|---|---|
| `famboook_identity_owner` (NOLOGIN) | Owns schema `identity_ref` and its objects | Log in |
| `famboook_identity_import` (LOGIN, used only by the import command through a **separate** connection) | `INSERT`, `UPDATE` on `records`; `INSERT` on `record_versions`, `record_presence`, `releases`, `import_*`; `UPDATE` on `current_release`, `import_runs`, `import_files`; create and drop its own staging tables | `DELETE` / `TRUNCATE` on `records` and `record_versions` |
| `famboook_app` (existing) | **Nothing** in 01A. Read access (`SELECT` on `records` and `current_release`) is granted later, by 01B, through a security-definer function or view | Any write; `TRUNCATE` |

**Triggers:**
- `BEFORE UPDATE OR DELETE` on `record_versions` → always raises.
- `BEFORE DELETE` on `records` → always raises.
- `BEFORE UPDATE` on `records` → raises unless `current_user = 'famboook_identity_import'`.
- `BEFORE UPDATE` on `releases` → raises (releases are immutable).

**Application layer:** no model `save`/`delete` path; models for these tables are read-only (they throw on save) or not created at all in 01A. There are no endpoints, forms or permissions to edit records [APPROVED].

**Dependency [OPEN, OD-12]:**
- Creating roles needs a database administrator, because `famboook_app` cannot create roles (`docs/08` §11).
- Laravel migrations currently run as `famboook_app`.

So the schema, roles and grants must be created by a DBA step outside `deploy-backend.sh`, or the migration must run with an elevated connection. Neither exists today.

## 4. Storage estimate [RISK — to be measured]
- **`records`:** 2.4 million rows × 36 text columns; the source is 765 MB as CSV. Expect roughly 1–1.5 GB with the TOAST and row overhead, plus the key index (about 100–150 MB).
- **`record_presence`:** about 2.4 million small rows per release (about 100 MB per release). Retention of presence rows per release is OD-07.
- **`record_versions`:** grows only with real changes.
- **Backups:** grow by the same order of magnitude, as encrypted `pg_dump` (OD-17).
