# FB-CIVIL-01A — Import Workflow

**Status:** PROPOSED. **Date:** 2026-10-25.

## 1. Run states

```
RUNNING ──► SUCCEEDED      (published: new release id)
        ├─► NOTHING_TO_DO  (manifest SHA set identical to the current release's)
        ├─► REJECTED       (verification, validation or a threshold refused the run — previous release kept)
        └─► FAILED         (unexpected error or interruption — previous release kept)
```
A dry run ends SUCCEEDED or REJECTED with `dry_run = true` and **never publishes**.

## 2. Steps

| # | Step | Checks | On failure |
|---|---|---|---|
| 0 | Operator places the 24 CSV files and `manifest.json` in the approved non-served server directory (OD-14) | — | — |
| 1 | `php artisan famboook:identity-import <dir> --dry-run` | — | — |
| 2 | Lock | `pg_try_advisory_lock(<key>)` plus the single-`RUNNING` index; closes a stale `RUNNING` run as FAILED (interrupted) | Refuse: "import in progress" |
| 3 | Manifest | <ul><li>JSON schema; `columns` = the 36 approved names in order;</li><li>`files` count and names; `total_rows` = Σ rows;</li><li>no file missing or extra in the directory.</li></ul> | REJECTED |
| 4 | Files | Per file: SHA-256 = manifest; UTF-8 BOM; header = columns | REJECTED (and the file row FAILED) |
| 5 | Stage | <ul><li>COPY the file into `staging_<run>` (the BOM removed, header skipped);</li><li>`loaded_rows` = manifest rows;</li><li>every line has exactly 36 fields;</li><li>valid UTF-8.</li></ul> | REJECTED |
| 6 | Validate | Per row: key present (configured key column); basic key-format check (OD-16). Cross-row: duplicate keys → `DUPLICATE_IDENTICAL` or `CONFLICT` | Issues recorded; only the row or key is excluded |
| 7 | Diff | Row hash vs `records`: NEW / CHANGED / UNCHANGED; MISSING = published keys not in staging; blank-value policy (OD-04) | — |
| 8 | Gate | Thresholds (OD-06): conflict ratio, row-count drop, change ratio | REJECTED: nothing published |
| 9 | Publish (not in a dry run) | **One transaction:** <ol><li>insert `releases`;</li><li>copy the old state of CHANGED records into `record_versions`;</li><li>update CHANGED, insert NEW;</li><li>insert `record_presence`;</li><li>switch `current_release`;</li><li>set run `release_id`;</li><li>COMMIT.</li></ol> | Rollback: FAILED; previous release unchanged |
| 10 | Cleanup | Drop `staging_<run>`; close the run; release the lock (in `finally`) | — |
| 11 | Report | Counts and issue codes only (no values) | — |

## 3. Concurrency

| Concern | Handling |
|---|---|
| Two imports at once | Impossible: advisory lock plus partial unique index |
| Readers (future) | Unaffected: MVCC shows the last committed release during the run and the publication |
| Writers outside the import role | None: privileges and triggers (database proposal §3) |

## 4. Periodic imports
- **Same command, new export:** idempotent by the manifest SHA set.
- **Missing identities** are never deleted [APPROVED], and they're reported per release.
- **Snapshot or partial:**
  - snapshot vs partial is OD-05;
  - until it's decided, only `SNAPSHOT` mode is enabled, and a partial export is refused by the row-count gate;
  - this matters because under snapshot semantics "missing" means "absent from the source", while a partial export makes that meaningless.

## 5. Operator procedure (outline; a full runbook comes in Phase 4)

1. **Backup:** take a fresh, verified backup (Production backups are age-encrypted, and **a restore has never been tested**: runbook R-1).
2. **Dry run first.** Review the counts and issue codes; GO only if they're within the approved thresholds.
3. **Real run** in an approved window. Record the release id and counts.
4. **Files:** handle the source files per the retention decision (OD-14), deleting them securely or archiving them encrypted.
