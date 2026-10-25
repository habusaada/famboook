# FB-CIVIL-01A-P1 — Test Report

## Environment

| Item | Value |
|---|---|
| Database | Disposable local test cluster `famboook_test`, 127.0.0.1:5433, PostgreSQL 18.6 |
| Connection user | `postgres`, the administrator of the disposable cluster only (from `backend/phpunit.pgsql.xml`) |
| Guards | `TestDatabaseGuard` (database must be `*_test`). The harness also refuses port 5432 and non-`_test` names, and the drop script refuses the same. |
| Data | Synthetic only. Identities are `9000001xx`-style test values plus `012345678`, `7` and `123`; names are placeholders such as `اسم-تجريبي`. No real CSV was read. |
| Concurrency | Only this suite ran against PostgreSQL. No other PostgreSQL session was open (checked in `pg_stat_activity`). |

**Command:**

```
cd backend
php vendor/bin/phpunit -c phpunit.pgsql.xml tests/Feature/IdentityRef
```

`php artisan test --configuration=…` is not used: it warns that `--configuration` cannot be passed twice.

## Result

```
OK (24 tests, 318 assertions)   — run 1 (after two test-bug fixes, below)
OK (24 tests, 318 assertions)   — run 2 (after Pint), ~10 s
```

- **Default SQLite configuration:** all 24 tests are skipped ("PostgreSQL only").
- **Cleanup check after the runs:** the schema `identity_ref` is gone and the throwaway login role is gone.
  The three `famboook_identity_*` roles remain as cluster objects of the test cluster, by design.

## Required cases → tests

| # | Required test | Test(s) | Result |
|---|---|---|---|
| 1 | Schema and constraints | `IdentityRefSchemaPostgresTest::test_schema_objects_exist_and_are_owned_by_the_owner_role`, `::test_the_schema_refuses_to_be_applied_by_any_role_but_the_owner`, `::test_malformed_payloads_are_rejected` | PASS |
| 2 | Duplicate `mainid` within its scope | `::test_duplicate_mainid_is_rejected_per_anchor_and_per_release` | PASS |
| 3 | Leading zeros | `::test_leading_zeros_and_non_standard_lengths_are_preserved` | PASS |
| 4 | Nonstandard lengths | same test (`7`, `123`, `012345678`) | PASS |
| 5 | NULL vs empty string | `::test_null_versus_empty_string_and_all_raw_fields_are_preserved` | PASS |
| 6 | Raw preservation (all 36 fields, unknown codes, spaces, invalid dates) | same test | PASS |
| 7 | Foreign keys | `::test_foreign_keys_hold_and_never_cascade` | PASS |
| 8 | Immutable history | `::test_reference_data_cannot_be_updated_deleted_or_truncated_by_any_role`, `IdentityRefPublicationPostgresTest::test_unchanged_rows_create_no_new_versions` (release 1 still resolves to version 1) | PASS |
| 9 | Delete prevention | `IdentityRefSchemaPostgresTest::test_reference_data_cannot_be_updated_deleted_or_truncated_by_any_role` (administrator, owner, writer) | PASS |
| 10 | Application-role write denial (and no reads) | `IdentityRefRolesPostgresTest::test_application_and_reader_roles_have_no_access_at_all` | PASS |
| 11 | Authorized writer writes | `::test_writer_can_record_a_run_and_publish_through_the_controlled_path`. Also: `::test_writer_cannot_bypass_the_controlled_path_or_escalate`, `::test_a_real_writer_login_session_cannot_escalate`, `::test_roles_carry_no_special_attributes_or_memberships`, `::test_functions_have_a_protected_search_path_and_no_public_execute` | PASS |
| 12 | Atomic publication | `IdentityRefPublicationPostgresTest::test_publication_is_atomic_and_building_rows_are_never_visible` | PASS |
| 13 | Failed publish keeps the previous release | `::test_failed_publication_keeps_the_previous_release` (empty release, rollback after publish, abandoned release) | PASS |
| 14 | Concurrent reader consistency | `::test_concurrent_readers_see_one_complete_release` (separate REPEATABLE READ session across the switch) | PASS |
| 15 | Concurrent publication locking | `::test_concurrent_publication_is_locked_and_stale_bases_are_rejected` (second session gets `55P03` in under 5 s, then `40001` for a stale base) | PASS |
| 16 | No-change updates | `::test_unchanged_rows_create_no_new_versions` | PASS |
| 17 | Missing identities not deleted | `::test_missing_identities_are_carried_forward_not_deleted` | PASS |
| 18 | Interrupted transaction recovery | `::test_interrupted_import_is_recoverable` (`pg_terminate_backend` mid-transaction; committed orphan run and release recovered) | PASS |
| 19 | Error and log redaction | `IdentityRefIsolationPostgresTest::test_database_errors_are_redacted_before_logging`, `::test_schema_messages_and_issue_rows_cannot_carry_values` | PASS (application side; server log is P1-OI-03) |
| 20 | No changes to operational persons or RBAC | `::test_operational_tables_and_rbac_are_untouched` (catalog plus row-count snapshot before and after applying the schema and publishing) | PASS |

**Totals:** 20 of 20 required cases passed. None failed and none are blocked.

## Failures found during development (fixed in the tests, not in the schema)

1. **Leading-zeros test:** `array_keys()` turned the numeric-string keys `'123'`, `'7'` and `'900000001'` into
   PHP integers. The test now reads the stored `mainid` text directly. The database values were correct.
2. **Isolation test:** the "no reference outside `identity_ref`" regex matched the comment text
   `00-roles.dba.sql`. Comments are now stripped before matching.
3. **Isolation test:** one PHP syntax error (an extra parenthesis), caught at load time.

No schema change was needed to make any test pass. No check was weakened.

## Deferred / not run

- **Full PostgreSQL regression suite:** not run. No existing application file was modified, and a full
  PostgreSQL run takes about an hour on this machine. The known pre-existing PostgreSQL-only failures are
  unrelated.
- **Full default (SQLite) suite:** run as a regression check: `OK, 3219 tests, 30128 assertions, 213 skipped`
  (PostgreSQL-only tests, including the 24 above), in 33 min 49 s. No failures.
- **PostgreSQL 16:** not run on a 16.x server (P1-OI-09).
- **Volume and performance:** no test at real dataset scale. P2 must measure publication time and the size
  of the presence set per release using synthetic data of realistic size.
