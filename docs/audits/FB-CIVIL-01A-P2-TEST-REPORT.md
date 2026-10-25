# FB-CIVIL-01A-P2 — Test Report

## 1. Environment

| Item | Value |
|---|---|
| **Database** | The disposable local test cluster `famboook_test` on 127.0.0.1:5433, PostgreSQL 18.6, checked by:<ul><li>`TestDatabaseGuard` (the database must be `*_test`);</li><li>the harness (refuses port 5432);</li><li>the drop script (refuses anything other than `_test`).</li></ul> |
| **Loader sessions** | Real LOGIN sessions of throwaway `NOINHERIT` roles, each a member of only `famboook_identity_writer` (or only `famboook_app` for the denial test). Created per test with a random password and dropped afterwards. |
| **Data** | Synthetic only, from `SyntheticIdentityExport`, written to a fresh directory under the system temp folder and deleted after each test. **No real CSV file was read. No dump was opened.** |
| **Concurrency** | One PostgreSQL suite at a time. Nothing else used the test cluster during the runs. |
| **PHP** | 8.3.33, `pdo_pgsql`; COPY via `pgsqlCopyFromArray` confirmed working |

**Commands:**

```
cd backend
php vendor/bin/phpunit tests/Unit/IdentityRef                              # no database
php vendor/bin/phpunit -c phpunit.pgsql.xml tests/Feature/IdentityRef      # P1 + P2 PostgreSQL
IDENTITY_REF_PERF=1 php -d memory_limit=1G vendor/bin/phpunit -c phpunit.pgsql.xml tests/Feature/IdentityRef/IdentityRefStagingPerformancePostgresTest.php
```

## 2. Results (final runs)

| Suite | Result |
|---|---|
| `tests/Unit/IdentityRef`, no database | **12 tests, 60 assertions: 11 passed, 1 skipped** (the symlink case; this Windows machine cannot create symlinks, P2-OI-06) |
| `tests/Feature/IdentityRef` on PostgreSQL (P1 + P2) | **43 tests, 500 assertions: 42 passed, 1 skipped** (the opt-in volume test). This includes the **24 P1 regression tests, all passing** with the P2 schema and the P1 constraint hardening. |
| Volume rehearsal (`IDENTITY_REF_PERF=1`) | **1 test, 3 assertions: passed.** 2,399,891 synthetic rows staged (§4). |
| Full default (SQLite) suite, run after the final change | **Exit code 0.** 3,250 tests, 30,188 assertions: **3,017 passed, 0 failed, 0 errors, 233 skipped** (PostgreSQL-only tests, including the 19 new PostgreSQL classes' tests, plus the symlink case), in 30 min 20 s |

## 3. Required cases → tests

**Abbreviations:**
- **U** = `tests/Unit/IdentityRef/IdentitySourceValidationTest`
- **S** = `tests/Feature/IdentityRef/IdentityRefStagingPostgresTest`
- **X** = `tests/Feature/IdentityRef/IdentityRefStagingSecurityPostgresTest`

| # | Required | Test(s) | Result |
|---|---|---|---|
| 1 | Valid manifest | U `test_a_valid_export_passes_manifest_and_source_validation`; S `test_a_valid_export_is_staged_with_consistent_tracking` | PASS |
| 2 | Incorrect checksum | U `test_an_incorrect_checksum_is_rejected`; S `test_invalid_sources_are_rejected_and_recorded_without_staging` | PASS |
| 3 | Missing file | U `test_missing_extra_duplicated_and_unsafe_files_are_rejected` | PASS |
| 4 | Extra file | same test (an extra file and a sub-directory), plus S (no run recorded) | PASS |
| 5 | Invalid header | U `test_invalid_header_and_missing_bom_are_rejected` | PASS |
| 6 | Missing BOM | same test | PASS |
| 7 | Wrong row count | U `test_a_wrong_row_count_is_rejected` | PASS |
| 8 | Malformed CSV | U `test_malformed_and_truncated_csv_is_rejected_with_its_line`: unterminated quote, quote in an unquoted field, text after a closing quote, wrong field count, blank line, invalid UTF-8, NUL, truncation; plus `test_an_oversized_record_is_rejected_without_reading_it_whole` | PASS |
| 9 | Quoted delimiters and embedded newlines | U `test_quoted_delimiters_and_embedded_newlines_are_preserved` (logical vs physical numbering); S `test_staged_payloads_preserve_every_source_value` | PASS |
| 10 | Duplicate `mainid` | S `test_key_findings_are_recorded_as_issues_without_excluding_rows` | PASS |
| 11 | Leading-zero IDs | U `test_leading_zeros_lengths_unicode_and_null_versus_empty_are_preserved`; S preservation test | PASS |
| 12 | Nonstandard ID lengths | same tests (`7`, `123`, 14 digits) plus the S key-findings test | PASS |
| 13 | Unicode preservation | same tests (combining marks, decomposed characters, astral plane and emoji, Arabic) | PASS |
| 14 | Empty string vs NULL | same tests; jsonb `null` vs `""` verified in the database | PASS |
| 15 | Large synthetic streaming input | S `test_large_synthetic_input_is_streamed_in_bounded_memory` (100,000 rows); volume rehearsal (2,399,891 rows) | PASS |
| 16 | Interrupted loading | S `test_interrupted_load_resumes_without_duplication` (crash after a commit); S `test_a_killed_session_leaves_no_partial_chunk` (`pg_terminate_backend` mid-chunk) | PASS |
| 17 | Resume without duplication | S interrupted-load test (3 chunks verified, 11 rows loaded, 20 distinct); `test_an_inconsistent_checkpoint_resets_and_reloads_the_file`; `test_a_source_changed_before_resume_rejects_the_run` | PASS |
| 18 | Concurrent import refusal | X `test_concurrent_imports_are_refused` | PASS |
| 19 | Transaction rollback | S `test_a_failed_chunk_is_rolled_back_completely` | PASS |
| 20 | Application-role write denial | X `test_the_application_role_cannot_stage_or_write` | PASS |
| 21 | Import-writer least privilege | X `test_writer_least_privilege_and_staging_integrity_rules` | PASS |
| 22 | No active release changes | X `test_staging_never_changes_the_active_release_or_reference_data` | PASS |
| 23 | No operational registry changes | X `test_operational_registry_and_rbac_are_untouched` | PASS |
| 24 | Error and log redaction | X `test_logs_errors_and_output_are_redacted` | PASS |
| — | Abort and cleanup | S `test_abort_closes_the_run_and_purges_its_staging` | PASS |
| — | Operator command | X `test_the_command_requires_an_explicit_target_and_prints_counts_only` (wrong target refused, counts-only output, abort, refused in production) | PASS |

**Totals:** 24 of 24 required cases passed, with 0 failed and 0 blocked. One sub-case was not executed on this machine: symlink rejection (P2-OI-06).

## 4. Performance observations (synthetic data, developer laptop, local test cluster)

| Measurement | Value |
|---|---|
| Volume | 24 files, 2,399,891 rows (23 × 100,000 + 99,891), 467 MB of synthetic CSV |
| Validation pass plus staging, end to end | **368 s** (about 6.5k rows/s overall: two full reads plus COPY with database-side checks) |
| 100,000-row test | 11.7–15.4 s across runs |
| PHP memory | **Peak growth 15.6 MB** at full volume, the same as at 100,000 rows: bounded by the chunk, not the input |
| Staging size | `rows` about 1.95 GB (jsonb of 36 keys plus the primary key); checkpoints 0.1 MB |

**Interpretation:**
- Staging time is dominated by the per-row database checks: the 36-key payload CHECK and the generated `mainid` column. These are deliberate integrity costs.
- A real export (765 MB, longer values) will be somewhat slower.
- Possible later optimizations, not needed now:
  - larger chunks;
  - checking the payload once at P3 instead of per row at staging.
- Production timing, WAL and disk must be measured on a target-class server (P2-OI-10, OD-17).

## 5. Failures found during development (all fixed)

1. **Real defect (P1 schema).**
   - `import_files.loaded_rows` was nullable with no default, so `loaded_rows + n` stayed NULL.
   - The P1 CHECKs let a STAGED or VERIFIED file pass with NULL counters or checksum, because a NULL comparison passes a CHECK.
   - **Fixed in the schema:** `loaded_rows` is now `NOT NULL DEFAULT 0`, and the checks are NULL-safe (P2-OI-08). The P1 suite passes.
2. **Test bug:** a helper named `run()` clashed with PHPUnit's final `TestCase::run()`. Renamed.
3. **Test bug:** an arrow function captured a variable by value, so a hook's result was lost. Fixed with a holder object. The kill itself had worked.
4. **Test bugs:** the order of an assertion's array keys, and an integrity probe that collided with an existing key (23505) before reaching the rule under test. The probe now deletes a row first.
5. **Test fixture:** the synthetic generator built all rows in memory (320 MB at 100,000 rows). It now streams (74 MB for the whole suite).

No integrity check was weakened to make a test pass.

## 6. Not run / deferred

- **Symlink rejection:** not executable on this Windows machine (P2-OI-06).
- **Full PostgreSQL regression suite:** not run. No operational application code changed; P2 touches only new files, the identity scripts and one new connection entry. It takes about an hour, and known unrelated failures exist.
- **Any Production, staging-server or real-data run:** not done, by instruction.
