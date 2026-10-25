# FB-CIVIL-01A-P3 — Validation, Comparison, Versioning and Atomic Publication: Implementation

**Status:** implemented and tested on the disposable local test cluster (`famboook_test`, 127.0.0.1:5433) with synthetic data only.

**Not done:**
- It is not committed or deployed.
- No Production access.
- The real export was not used.

## 1. Gap analysis (P3 requirements → existing P1/P2 components)

| P3 requirement | Reused as is | Added in P3 |
|---|---|---|
| Identity anchors, immutable versions, no duplicate payloads | P1 `records`, `record_versions`: insert-only; `UNIQUE(record_id, content_sha256)`; trigger-computed `version_no` and SHA-256 | — |
| Release set, presence, missing identities kept | P1 `dataset_releases`, `release_presence`, `carry_forward_missing()` | — |
| Atomic activation, lock, stale base, no drops | P1 `publish_release()` (SECURITY DEFINER, `FBCIV01A` transaction lock) | A **publication gate trigger**: only a VERIFIED release can become PUBLISHED |
| Staged source, run and file lifecycle, writer session, lock | P2 staging, `import_runs`/`import_files`, `IdentityImportSession` (taken out of the P2 action), lock `FBCIV01S`, redactor | P2 `--abort` now also abandons the run's BUILDING release; purge also removes assessments |
| NEW/CHANGED/UNCHANGED/CONFLICT, quarantine, warnings | P2 key findings (issue rows) | `assess_run()`, `assess_warnings()`, `identity_ref_staging.assessments` |
| Policy (blank-field, quarantine, comparison version) | — | `release_preparations` (frozen policy record and counts), `IdentityImportPolicy` (registry), `assert_policy_allows()` |
| Prepare / verify | — | `begin_preparation()`, `complete_preparation()`, `verify_release()`, `refresh_statistics()` (all SECURITY DEFINER) |
| CLI | `famboook:identity-ref-stage` | Modes `--validate`, `--preview`, `--prepare`, `--verify`, `--publish`, `--status`. No new entry point. |

No competing schema, release model or workflow was introduced. P3 adds one table, one staging table, functions and one trigger, in `deploy/sql/identity-ref/03-publication.sql`. It changes no P1/P2 object.

## 2. Files

**New:**
- **Schema:** `deploy/sql/identity-ref/03-publication.sql` (owner-applied DBA script, like 01 and 02).
- **Application code:**
  - `backend/app/Actions/PublishIdentityReleaseAction.php`: assess, prepare, verify, publish, status.
  - `backend/app/Support/IdentityRef/IdentityImportPolicy.php`: the versioned policy registry.
  - `backend/app/Support/IdentityRef/IdentityImportSession.php`: writer role, explicit target, no reconnect, lock (from P2).
- **Tests:**
  - `backend/tests/Feature/IdentityRef/IdentityRefReleasePostgresTest.php` (10 tests);
  - `backend/tests/Feature/IdentityRef/IdentityRefReleaseSafetyPostgresTest.php` (13 tests);
  - `backend/tests/Feature/IdentityRef/IdentityRefReleasePerformancePostgresTest.php` (opt-in);
  - `backend/tests/Support/IdentityRef/IdentityRefRelease.php`.
- **Documents:** `docs/audits/FB-CIVIL-01A-P3-*.md` (this document, the policy, the test report, the decision register).

**Modified (all uncommitted work from P1/P2):**
- `backend/app/Actions/StageIdentityImportAction.php`:
  - uses `IdentityImportSession`;
  - abort abandons a BUILDING release;
  - `purgeFinishedRuns()` is public and removes assessments.
- `backend/app/Console/Commands/StageIdentityImport.php`: the P3 modes.
- `backend/app/Exceptions/IdentityImportException.php`: P3 reasons and `releaseId`.
- `backend/tests/Support/IdentityRef/IdentityRefDatabase.php`: `setUpIdentityRef(withPublication:)`.
- `backend/tests/Support/IdentityRef/SyntheticIdentityExport.php`: a streaming mutation hook.
- `backend/tests/Feature/IdentityRef/IdentityRefStagingSecurityPostgresTest.php`: the static "no publication path" check now allows `abandon_release` in the abort code only.

## 3. State transitions

### Import run (`identity_ref.import_runs`)

| From | To | By | Condition |
|---|---|---|---|
| — | RUNNING | P2 stage | Manifest valid; no other RUNNING run |
| RUNNING | REJECTED | P2 | Source invalid or changed (staging purged) |
| RUNNING | FAILED (`ABORTED`) | `--abort` | Operator; its BUILDING release is abandoned; staging purged |
| RUNNING | SUCCEEDED | P3 `--publish` | **In the activation transaction**, together with the pointer switch |

### Release (`identity_ref.dataset_releases`) and its preparation (`identity_ref.release_preparations`)

| Release | Preparation | Event | Guard (enforced by the database) |
|---|---|---|---|
| — | — | `begin_preparation` → BUILDING + PREPARING | Run RUNNING; all files STAGED and checksum-verified; all counts consistent; supported comparison version; policy gates pass; base = active release |
| BUILDING | PREPARING | Materialization (chunks) | Presence rows only while BUILDING (P1); idempotent inserts |
| BUILDING | PREPARING → PREPARED | `complete_preparation` | Presence count = expected (accepted rows + carried forward) |
| BUILDING | PREPARED → VERIFIED | `verify_release` | Classification recomputed and equal; every accepted row PRESENT with exactly its content; every carried identity = its base version; counts and policy gates hold; base still active |
| BUILDING → PUBLISHED | VERIFIED | `publish_release` + gate | Advisory lock (fail fast); base = active; release non-empty and covering the base; **preparation VERIFIED and record count = verified expectation; run RUNNING; all files STAGED** |
| BUILDING → ABANDONED | any | `--abort` (`abandon_release`) | Never publishable afterwards |

**Frozen fields:**
- A preparation's policy and counts are frozen at creation. Only PREPARING → PREPARED → VERIFIED is possible, enforced by a trigger, and the writer has no UPDATE grant at all.
- Releases and presence remain insert-only and immutable (P1).

## 4. Publication design

**Expensive work runs before activation, in short transactions, invisible to readers:**
1. **`begin_preparation`:** one transaction, set-based. It classifies every staged row into `assessments`, records the policy and counts, writes the quarantine issue rows, and creates the BUILDING release.
2. **Materialization:** windows of `--chunk` records (default 5,000), one short transaction each:
   - anchors for NEW rows (`ON CONFLICT DO NOTHING`);
   - versions for NEW and CHANGED rows (`ON CONFLICT (record_id, content_sha256) DO NOTHING`, which reuses an identical earlier version);
   - presence rows (`ON CONFLICT DO NOTHING`).

   Index paths are forced per window (`SET LOCAL`); see §6.
3. **`carry_forward_missing`** (P1), then **`complete_preparation`**.
4. **`verify_release`:** an independent, set-based proof.

**Activation is one short transaction:**
- `publish_release()` (P1 checks plus the gate, then the pointer switch);
- then `import_runs` → SUCCEEDED with its counters, and COMMIT.

The run's staging is purged after the commit. A purge failure leaves the release active, and the next abort or purge cleans up.

**Visibility:**
- Readers read only through `active_release`, so no committed preparation chunk is ever visible (tested).
- The pointer switch is atomic under MVCC: a reader sees the old complete release or the new complete release (tested with a reader session during the open activation transaction).

### Failure and retry

| Failure | Effect | Safe retry |
|---|---|---|
| A fatal validation or policy gate fails | Refused before anything is written | Fix the source (restage) or record a policy decision |
| Interrupted during materialization (crash, kill, error) | Release BUILDING, preparation PREPARING; committed chunks invisible; active release unchanged | `--prepare --run=` with the **same policy** resumes. Every window is idempotent; nothing is duplicated. |
| Prepared but not verified, or verification fails | Release BUILDING (PREPARED); not publishable (gate) | Investigate. `--abort` abandons the release and the run; restage. |
| Failure inside the activation transaction | Rolled back completely: pointer, release and run unchanged; release stays VERIFIED | `--publish --release=` again |
| A second publication at the same time | Refused at once (`CONCURRENT_IMPORT` from the session lock; `55P03` from `publish_release`'s lock) | Retry after the first finishes |
| Publish again | `ALREADY_PUBLISHED`; nothing changes | — |
| The base release changed after preparation | `STALE_BASE` at verify or publish | `--abort`, restage, prepare again (one release per run) |

## 5. Security

- **Writer limits:** the writer cannot write `release_preparations` or `assessments`, cannot execute the internal checks, and cannot publish an unverified release, even by calling `publish_release()` directly (tested with distinct LOGIN sessions).
- **Definer functions:** all five SECURITY DEFINER functions are owner-owned, pin `search_path = pg_catalog, pg_temp`, and re-check their preconditions.
- **Application role:** has no access to any P3 object (tested).
- **No operational impact:** no operational table, RBAC object or `persons`/`families` row is read or changed (tested by snapshot and static check).
- **No exposure:** no API, UI, manual edit path or relationship structure.
- **Error leaks:** PostgreSQL errors are redacted (P2 redactor). Warnings are aggregate counts. Quarantine issue rows carry code, file and line only.
- **Server-log exposure** (P2-OI-02) still applies to failed statements.
- **TEMP privilege:** `verify_release` creates a temporary table as the owner. If a DBA revokes `TEMPORARY` from `PUBLIC` (P1-OI-04), the owner role needs `TEMPORARY` on the database.

## 6. Performance findings and the fixes they led to

Measured with synthetic data (numbers in the test report):

1. **The P1 version trigger planned on an empty table.** `record_versions_prepare` computes `max(version_no)` per row. Planned while `record_versions` was empty, it scanned the whole table per inserted row: 3.3 ms per row, 9.8 million buffer reads per 5,000 rows. With fresh statistics or forced index paths it costs 0.11–0.2 ms per row.
2. **Hash joins per window.** For a 5,000-row window the planner chose hash joins with full scans of `records` and `record_versions`.
3. **The fix for 1 and 2:** each window transaction forces index paths (`SET LOCAL enable_seqscan/hashjoin/mergejoin = off`), which also applies inside the P1 trigger. Release-2 materialization went from 123 s to 22 s at 240,000 rows.
4. **Statistics refresh cost.** Repeated `ANALYZE` on the large jsonb tables cost seconds per call. It is now called only before classification and before finalization, through the narrow definer function `refresh_statistics()`.
