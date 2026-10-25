# FB-CIVIL-01A-P3 — Test Report

## 1. Environment

| Item | Value |
|---|---|
| **Database** | The disposable local test cluster `famboook_test`, 127.0.0.1:5433, PostgreSQL 18.6. Guards: `TestDatabaseGuard` (`*_test`), the harness (refuses port 5432), the drop script. Schema 00–03 is applied as `famboook_identity_owner` for every P3 test. |
| **Sessions** | Every staging, prepare, verify and publish step runs in its **own LOGIN session** of a throwaway `NOINHERIT` role that is a member of only `famboook_identity_writer` (or only `famboook_app` for the denial tests). Each role has a random password and is dropped after the test. The administrator session only sets up, inspects and simulates corruption. |
| **Data** | Synthetic only, generated per test in a temporary folder and deleted afterwards. **No real export was used, Production was not contacted, and the dump was not opened.** |
| **Concurrency** | One PostgreSQL suite at a time. The SQLite regression ran only after all PostgreSQL work had finished. |

## 2. Results (final runs, after the last code change)

| Suite | Result |
|---|---|
| `tests/Unit/IdentityRef` | **12 tests, 60 assertions: 11 passed, 1 skipped** (symlink case: this Windows machine cannot create symlinks; P2-OI-06) |
| `tests/Feature/IdentityRef` on PostgreSQL: P1 (24) + P2 (18) + **P3 (23)** + 2 opt-in volume tests | **67 tests, 705 assertions: 65 passed, 0 failed, 2 skipped** (the opt-in volume tests) |
| P3 alone (`IdentityRefReleasePostgresTest`, `IdentityRefReleaseSafetyPostgresTest`) | **23 tests, 206 assertions: all passed** |
| Full default (SQLite) suite, after the last change | **Exit code 0.** 3,274 tests, 30,188 assertions: **3,017 passed, 0 failed, 0 errors, 257 skipped** (PostgreSQL-only tests, including the 24 new P3 ones, plus the symlink case), in 27 min 28 s |

**Coverage of the earlier phases:** the P1 tests run on schema 00–02. The P3 publication gate deliberately refuses any release that was not prepared and verified, so the P1 publication primitives are tested without it; the P3 tests run on 00–03. The P2 tests also run on 00–02, and the P2 code path is exercised again with 03 applied through the P3 tests.

## 3. Required scenarios → tests

**Abbreviations:** **R** = `IdentityRefReleasePostgresTest`; **S** = `IdentityRefReleaseSafetyPostgresTest`.

| # | Scenario | Test | Result |
|---|---|---|---|
| 1 | First release, NEW identities | R `test_first_release_publishes_new_identities` | PASS |
| 2 | Second release, only UNCHANGED | R `test_second_release_with_unchanged_identities_creates_no_version` | PASS |
| 3 | Second release, CHANGED | R `test_mixed_release_…`; R `test_a_reverted_value_reuses_the_earlier_version` | PASS |
| 4 | Mixed NEW/CHANGED/UNCHANGED | R `test_mixed_release_versions_changes_and_retains_missing_identities` | PASS |
| 5 | Missing identities retained | same test (CARRIED_FORWARD, nothing deleted) | PASS |
| 6 | Leading-zero identifiers | R first-release test (`012345678`) | PASS |
| 7 | Non-9-digit identifiers | R first-release test (`7`, `123`, published, warning only) | PASS |
| 8 | Duplicate staged `mainid` | R `test_row_level_quarantine_…`; S `test_quarantine_fails_closed_…` | PASS |
| 9 | Missing `mainid` | R `test_row_level_quarantine_under_an_approved_threshold` | PASS |
| 10 | Unmatched father/mother | R `test_unmatched_parent_identifiers_are_reported_not_rejected` | PASS |
| 11 | Blank-field policy unresolved | R `test_blank_over_value_follows_the_blank_field_policy` (blocked; plus the synthetic QUARANTINE_ROW and ACCEPT_SOURCE paths) | PASS |
| 12 | Invalid source mapping | S `test_an_invalid_source_mapping_is_fatal` | PASS |
| 13 | Incomplete staging | S `test_incomplete_staging_is_a_fatal_validation_failure` (application and database) | PASS |
| 14 | Invalid checksum/manifest status | S `test_a_run_with_failed_checksum_verification_cannot_be_prepared` | PASS |
| 15 | Fatal validation failure | S tests 12–14 (nothing written each time) | PASS |
| 16 | Row-level quarantine | R `test_row_level_quarantine_under_an_approved_threshold` | PASS |
| 17 | Quarantine threshold not approved | S `test_quarantine_fails_closed_without_an_approved_threshold` (unresolved, exceeded; database gate too) | PASS |
| 18 | Publish an unprepared release | S `test_an_unprepared_or_unverified_release_cannot_be_published` (action, and direct `publish_release()` blocked by the gate) | PASS |
| 19 | Publish an invalid release | S `test_an_invalid_release_cannot_be_verified_or_published` (tampered presence fails verification; an abandoned release can't publish) | PASS |
| 20 | Repeated preparation | R `test_preparation_resumes_and_repeats_without_duplicates` | PASS |
| 21 | Repeated publication | R `test_repeated_publication_is_idempotent` | PASS |
| 22 | Concurrent publication | S `test_concurrent_publication_attempts_are_refused` (second process `CONCURRENT_IMPORT`; direct call `55P03`) | PASS |
| 23 | Failure before activation | S `test_failures_before_and_during_activation_…` (crash during preparation) | PASS |
| 24 | Failure during activation | same test (failure inside the activation transaction, fully rolled back, retry succeeds) | PASS |
| 25 | Active release unchanged after failure | same test (pointer, release BUILDING/VERIFIED, run RUNNING) | PASS |
| 26 | No partial visibility | same test (separate reader session during preparation and during the open activation transaction); R resume test | PASS |
| 27 | Immutable previous versions | R mixed test (payload unchanged; UPDATE/DELETE refused for writer and owner) | PASS |
| 28 | Writer privilege isolation | S `test_writer_privilege_isolation` (13 bypass attempts → `42501`; definer list; search_path; ownership) | PASS |
| 29 | Application role denial | S `test_the_application_role_has_no_access` | PASS |
| 30 | No operational table changes | S `test_operational_tables_are_untouched` (ACL and row-count snapshot; static check) | PASS |
| 31 | Release-presence tracking | R tests 2, 4 and quarantine (PRESENT / CARRIED_FORWARD, same versions) | PASS |
| 32 | Comparison counts | R `test_preview_counts_match_the_prepared_release_and_write_nothing`, plus counts asserted in every R test | PASS |
| 33 | Version provenance | R first-release and mixed tests (`created_run_id` → run → release) | PASS |
| 34 | Retry after interrupted preparation | R `test_preparation_resumes_and_repeats_without_duplicates` | PASS |
| 35 | Unsupported comparison version | S `test_an_unsupported_comparison_policy_is_refused` (application and database) | PASS |
| 36 | No sensitive values in CLI output or logs | S `test_cli_output_and_logs_carry_no_values` (all modes, confirmation required, synthetic policy not selectable, failing activation logged) | PASS |

**Totals:** 36 of 36 scenarios passed, with 0 failed and 0 blocked.

## 4. Synthetic performance measurements

**Setup:** a developer laptop, PostgreSQL 18.6 with defaults (`shared_buffers` 128 MB, `work_mem` 4 MB, `max_wal_size` 1 GB), `--chunk=5000`.

**Release 2 composition:** the same identities, 1 % CHANGED (value to value), 0.5 % missing, 0.5 % NEW.

### 4.1 Full scale: release 1, 2,399,891 rows (all NEW)

Measured **before** the final `work_mem` and payload-free join changes, which lowered these phases at 10 % scale; see §4.3.

| Phase | Time |
|---|---|
| Staging (P2) | 332 s |
| Validate + compare (read-only) | 348 s |
| Prepare | 1,921 s (begin 458 s, materialize 1,102 s, finalize 35 s) |
| Verify | 238 s |
| **Activation transaction** | **23.1 s** |
| PHP peak memory growth | 15.7 MB |
| WAL | 5.95 GB during preparation; total 6.3 GB |
| Storage | 1.95 GB staged; 5.2 GB after publication (staging purged; table files keep their size until vacuum) |

### 4.2 Full scale: release 2 — not completed (finding, fixed)

- **What happened:** the full-scale release-2 validation **filled the disk** ("No space left on device", parallel hash-join temporary files). PostgreSQL refused the query cleanly, and nothing was prepared or published.
- **Cause:** `assess_run` carried every base version's full payload through a hash join, at `work_mem` 4 MB.
- **Fixes:**
  - the base side now carries only ids and hashes; the base payload is fetched by version id only for changed rows;
  - the assessment, preparation and verification functions run with `work_mem = 128MB`;
  - statistics are refreshed before the read-only assessment.
- **Not re-run at full scale:** this machine has about 12.5 GB free, too little margin to risk filling the system disk again. The rehearsal now refuses to start a release without ~4.5 KB of free disk per row (~11 GB at full scale).
- **Status:** a full two-release rehearsal on a host with at least 25 GB free remains open (decision register D-12; Phase 4).

### 4.3 10 % scale: 240,000 rows, two releases (final code)

| Measurement | Release 1 (all NEW) | Release 2 (1,200 NEW / 2,388 CHANGED / 236,412 UNCHANGED / 1,200 carried) |
|---|---|---|
| Staging | 29.1 s | 31.2 s |
| Validate + compare | 19.6 s | 32.3 s |
| Prepare (begin / materialize / finalize) | 130.7 s (22.4 / 89.6 / 4.0) | 88.2 s (32.2 / 20.2 / 9.9) |
| Verify | 13.3 s | 81.4 s |
| **Activation** | **0.2 s** | **2.9 s** |
| PHP peak memory growth | 15.7 MB | 15.4 MB |
| WAL | 427 MB (preparation) | 79 MB (preparation); 129 MB total |
| Temporary files (cumulative) | 1.44 GB | 1.45 GB |
| Storage after publication | 523 MB | 760 MB |

### 4.4 Interpretation

- **Memory:** PHP memory is flat at about 15 MB; no identity set is ever held in PHP.
- **Preparation:** chunked in windows of 5,000 rows, and invisible until activation. A failure costs at most one window of rollback, and a retry resumes.
- **Activation:** one short transaction that switches the pointer and closes the run.
  - Measured: 23 s at 2.4 million rows for the first release, 0.2–2.9 s at 240,000 rows.
  - Its cost is the P1 coverage checks: counting presence and the anti-join against the base.
  - Readers are never blocked (MVCC). Only another publication is refused during that time.
- **Locks:** preparation takes only row-level locks on new rows and the session advisory lock. Activation additionally locks the release row and the pointer row. Readers are never blocked.
- **WAL:** the first full release writes about 6 GB (2.4 million anchors, versions and presence rows). Later releases write in proportion to the changes plus presence (about 0.2 GB per release at full scale).
- **Estimate for a full-scale release 2:** about 5 minutes of staging plus about 25 minutes for prepare and verify. This is a linear extrapolation from 10 %, still to be confirmed on a larger host.

## 5. Problems found during P3 (all fixed; none weakened a control)

1. **P1 version trigger plan (performance).** Planned on an empty table, it scanned `record_versions` once per row. Fixed with transaction-local index paths per window, which also apply inside the trigger.
2. **Per-window hash joins.** The planner chose full scans of `records` and `record_versions` per 5,000-row window. Same fix.
3. **Statistics refresh cost.** Repeated `ANALYZE` cost minutes. It is now run only before classification and before finalization.
4. **Disk-full at full scale.** See §4.2.
5. **Test fixes:**
   - a hung test: `Artisan::call` is interactive by default, so `confirm()` waited for input; fixed with `--no-interaction`;
   - expected values corrected where the code was right: the quarantine ratio, warning key order, a test policy blocked by a real blank-over-value, the preparation of the wrong run;
   - the P2 static check updated for the new abort path.
