# FB-CIVIL-01A-P2 — Manifest Validation and Staging Loader: Design

**Status:** implemented and tested on the disposable local test cluster with synthetic data only.

**What P2 does not do:**
- It does **not** publish: no record, version, release or active-release change. That is P3.
- It is not committed.
- It was not run on Production.
- No real identity file was read.

## 1. Components

| File | Role |
|---|---|
| `deploy/sql/identity-ref/02-staging.sql` | Owner-applied DBA script: schema `identity_ref_staging`, its tables, integrity triggers and grants |
| `backend/app/Support/IdentityRef/IdentityManifest.php` | Manifest and inventory validation (no database) |
| `backend/app/Support/IdentityRef/IdentityCsvReader.php` | Bounded-memory RFC 4180 reader; hashes while it reads |
| `backend/app/Support/IdentityRef/IdentitySourceVerifier.php` | Read-only validation pass over every file |
| `backend/app/Support/IdentityRef/IdentityStagingLoader.php` | Chunked COPY with checkpoints, and resume verification |
| `backend/app/Actions/StageIdentityImportAction.php` | Orchestration: role and target checks, lock, run lifecycle, rejection, key findings, abort |
| `backend/app/Console/Commands/StageIdentityImport.php` | `famboook:identity-ref-stage` (artisan only, refused in production) |
| `backend/app/Exceptions/IdentityImportException.php` and three small exceptions in `Support/IdentityRef` | Sanitized refusal and interruption codes |
| `backend/config/database.php` | Adds the `identity_ref_import` connection; credentials come from the operator environment |

**Changed P1 files:**
- `01-schema.sql`: `import_files` constraints made NULL-safe (P2-OI-08).
- `90-drop.local-test-only.sql`: also drops the staging schema.
- The test harness: also applies `02-staging.sql`.

This follows the existing patterns:
- a thin artisan command plus a Domain Action, as in `ImportGazaGeography`;
- an advisory lock, run rows, and a sanitized exception without the original chained;
- an owner-applied SQL schema, as in P1.

**No queue:** the real queue and cache use the `database` driver, not Redis, and the CLI design needs neither (OD-13).

## 2. Source contract and validation (before any staging row exists)

**Manifest** (`manifest.json`, as profiled in P0): `{table, columns, total_rows, encoding?, files: [{file, rows, sha256}]}`.

| Level | Checks | Failure |
|---|---|---|
| **Manifest** (`IdentityManifest`) | <ul><li>Valid JSON object (BOM tolerated, at most 1 MB).</li><li>`table` safe; `columns` exactly the 36 approved names in order; `encoding` UTF-8 if present.</li><li>`total_rows` = Σ file rows.</li><li>Each entry: safe name, `rows` ≥ 0, 64-hex SHA-256; no duplicates (case-insensitive).</li><li>Optional operator approval: `--expected-files=24 --expected-rows=2399891`.</li></ul> | `MANIFEST_INVALID`. **No run is recorded** (nothing identifies a broken manifest). |
| **Inventory** | <ul><li>The directory holds exactly `manifest.json` plus the listed files.</li><li>Each file is regular, not a symlink, and resolves inside the directory.</li></ul> | `MANIFEST_INVALID` |
| **Files** (`IdentitySourceVerifier`), one streaming pass each | <ul><li>UTF-8 BOM; header exactly the 36 names.</li><li>Every record parses into exactly 36 fields, with valid quoting and UTF-8, no NUL, at most 1 MB.</li><li>Record count = manifest `rows`; SHA-256 = manifest.</li></ul> | Issues recorded (code, file, line). Files `FAILED`, run **`REJECTED`**, staging purged, `SOURCE_INVALID`. |

Only after every file passes are the files marked `VERIFIED` and loading starts.

## 3. Streaming reader

- **Bounded memory:** reads line by line (`fgets`, bounded by the record limit), holding at most one record. A record is completed across physical lines only while inside a quoted field (legitimate embedded line breaks).
- **Hash while reading:** every byte read goes into a SHA-256 context, so parsing and checksum verification are one pass.
- **Values unchanged:**
  - never trimmed, cast or decoded, so leading zeros, spaces, unknown codes, invalid dates and Unicode are preserved byte for byte;
  - quoted fields may contain commas, doubled quotes and CR/LF.
- **NULL vs empty string:** an unquoted empty field is `NULL`; a quoted `""` is `''`. This is the only distinction CSV can carry (P2-OI-05).
- **Numbering:**
  - `record_no` is the logical record (1 = first after the header);
  - `line_no` is the physical line where the record starts (header = line 1), so issues point to a findable place in the file.
- **Fast path:** a record without quotes is split with `explode`. A record with quotes goes through a small `strpos` scanner.

## 4. Staging storage

The staging schema is `identity_ref_staging`, separate from the immutable `identity_ref` and owned by `famboook_identity_owner`. Both tables are **UNLOGGED**.

```
identity_ref.import_runs ─< identity_ref.import_files ─< identity_ref_staging.file_chunks ─< identity_ref_staging.rows
                                                          (checkpoint per committed chunk)    (36 raw fields as jsonb)
```

- **`file_chunks`** (primary key `file_id, chunk_no`): the record range, byte range and SHA-256 of the chunk's raw bytes.
- **`rows`** (primary key `file_id, record_no`; foreign key to its chunk):
  - `line_no`;
  - `source_payload` (jsonb; same 36-key CHECK as `identity_ref.record_versions`);
  - `mainid`, a stored generated column used for the key findings.

**Rules enforced by the database, for every role:**
1. A checkpoint only for a `VERIFIED` file of a `RUNNING` run, contiguous with the previous one (records and bytes).
2. A row only into a chunk created **in the same transaction**, within its record range. The check compares the chunk row's `xmin` with the current transaction id, in a statement-level trigger, so its cost is per statement, not per row.
3. **At COMMIT** (deferred constraint trigger, once per chunk): the chunk holds exactly `row_count` rows covering its range. A checkpoint can therefore never describe a partial chunk.
4. No UPDATE and no TRUNCATE. DELETE (cleanup) is refused for a `STAGED` file of a `RUNNING` run.

**Why UNLOGGED:** bulk staging data needs no WAL. A server crash empties both tables together, and the loader then restarts the affected files after verifying that (§6). The backup implication is P2-OI-03.

## 5. Loading and transactions

**COPY.** Rows go in with `COPY identity_ref_staging.rows (…) FROM STDIN`, through `pdo_pgsql`'s `pgsqlCopyFromArray`, which is confirmed on PHP 8.3. Privileges were evaluated first:
- COPY FROM STDIN needs only `INSERT`. No file-system COPY is used, so no server file access and no superuser `pg_read_server_files` are needed;
- the payload is escaped for the COPY text format.

**One chunk = one transaction** (default 5,000 rows):
1. insert the checkpoint;
2. COPY the rows;
3. `import_files.loaded_rows += n`;
4. `import_runs.rows_loaded` recomputed from its files;
5. COMMIT.

The progress counters therefore always equal the committed staged rows.

**When a file is finished:**
- at its end, the file's SHA-256 and record count are checked against the manifest a second time. This detects a file swapped after validation, and gives `SOURCE_CHANGED`: run `REJECTED` and staging purged;
- then the file is marked `STAGED`.

**The last file** is marked `STAGED` in the same transaction that records the **key findings** as issues:
- `MAINID_MISSING`;
- `MAINID_DUPLICATE`, for every row of a duplicated key, with file and line;
- `MAINID_NOT_9_DIGITS`;
- `MAINID_SURROUNDING_SPACE`;
- `MAINID_TOO_LONG`.

So "every file STAGED" means "analysis done". No row is excluded or changed; what a finding means is P3's decision.

**After staging,** the run stays **`RUNNING`, awaiting P3.** Re-running the command on it is a no-op that reports the totals.

## 6. Resumability

Re-running the same command resumes the RUNNING run, but only if its manifest SHA-256 and file list are identical. Otherwise the result is `RUN_CONFLICT`: abort first.

**A resumed run:**
1. **Revalidates** the manifest, the inventory and every file (full hash, structure, counts), including files already staged. A changed file gives `REJECTED` and staging purged.
2. **Verifies the checkpoints** of each unfinished file against the database: the staged row count, the highest record and `loaded_rows` must all equal the checkpoint totals. Any disagreement resets that file: its staging rows are deleted and it is loaded again from the first record.
3. **Verifies the checkpoints against the file:** while re-reading, every checkpointed chunk's raw bytes must hash to its recorded SHA-256 and occupy the recorded byte range. Any mismatch resets the file.
4. **Continues** after the last verified checkpoint. An uncommitted chunk does not exist (transactional), so nothing is skipped. The primary key `(file_id, record_no)` plus rule 2 make a duplicate impossible.

**Interruption handling.** An interruption (exception, killed session, crash) leaves the run `RUNNING`. The error is logged and rethrown in redacted form (`STAGING_INTERRUPTED`). A rejection (invalid or changed source) closes the run `REJECTED` and purges its staging.

## 7. Locking and concurrency

- **Lock:** a session advisory lock `FBCIV01S` taken with `pg_try_advisory_lock`. It fails fast with `CONCURRENT_IMPORT` and never waits. It is distinct from P1's publication lock `FBCIV01A`.
- **One run:** the P1 single-`RUNNING` index allows one import run at a time.
- **Unlock:** the lock is released in `finally`. If the session dies, PostgreSQL releases it.

## 8. Cleanup policy

| Situation | Staging |
|---|---|
| Run REJECTED (invalid or changed source) | Purged in the same transaction that closes the run |
| Operator abort (`--abort`) | The RUNNING run is closed `FAILED` (`ABORTED`), and the staging of every finished run is purged |
| Staging complete, awaiting P3 | Kept, and protected from DELETE while the run is RUNNING |
| After publication (P3) | P3 must purge (P2-OI-04) |

## 9. Operator commands (local, synthetic data only)

**Prerequisites:** the scripts are applied to a **local** database, `00-roles.dba.sql`, then `01-schema.sql` and `02-staging.sql` (both as the owner). A local login is a member of the writer role, for example created by the local DBA:

```sql
CREATE ROLE famboook_identity_stager LOGIN NOINHERIT PASSWORD '<typed interactively>' IN ROLE famboook_identity_writer;
```

**Run** (credentials in the shell session only; never in `.env`; never with `config:cache`):

```bash
cd backend
export IDENTITY_REF_DB_DATABASE=famboook_local IDENTITY_REF_DB_USERNAME=famboook_identity_stager
read -rs IDENTITY_REF_DB_PASSWORD && export IDENTITY_REF_DB_PASSWORD
php artisan famboook:identity-ref-stage /path/to/synthetic-export \
    --target=famboook_local --expected-files=24 --expected-rows=2399891
# interrupted? run the same command again to resume
php artisan famboook:identity-ref-stage --abort --target=famboook_local   # discard the RUNNING run
```

**Synthetic data for a local try:**
- Use `Tests\Support\IdentityRef\SyntheticIdentityExport::generated(24, 100000, 100000001, 99891)`.
- Or run the opt-in volume test, which builds exactly that in a temporary directory and deletes it afterwards:

```bash
IDENTITY_REF_PERF=1 php vendor/bin/phpunit -c phpunit.pgsql.xml tests/Feature/IdentityRef/IdentityRefStagingPerformancePostgresTest.php
```

**Exit codes:** 0 = staged, 1 = refused or interrupted. The output shows counts and issue codes only.

## 10. Performance

Measured with synthetic data at the contract size (24 files, 2,399,891 rows) on a developer laptop:
- **Time:** validation plus staging took 368 s.
- **Memory:** PHP peak memory growth was 15.6 MB.
- **Staging size:** about 1.95 GB.

Details are in the test report §4.

- **Memory** is bounded by one chunk: about 5,000 encoded rows plus one record.
- **I/O** is two sequential reads per file (the validation pass and the load pass).
