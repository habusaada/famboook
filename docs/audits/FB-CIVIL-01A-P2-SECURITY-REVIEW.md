# FB-CIVIL-01A-P2 — Security Review: Manifest Validation and Staging Loader

**Scope:** what was verified, and how.

**Environment:** the disposable local test cluster only: `famboook_test` on 127.0.0.1:5433, PostgreSQL 18.6. All data was synthetic.

**Not done:**
- No Production or other database was accessed.
- No real CSV file was read.
- The database dump was not opened.

## 1. Roles used

| Role | Use in P2 | Privileges added in P2 |
|---|---|---|
| `famboook_identity_owner` | Owns the new schema `identity_ref_staging` and its objects. Applies `02-staging.sql` through the DBA's `SET ROLE`. | None (owner) |
| `famboook_identity_writer` | The loader. It acts as this role through `SET ROLE` from a login that is a member of it. | `USAGE` on `identity_ref_staging`; `SELECT, INSERT, DELETE` on `file_chunks` and `rows`. Nothing else: no UPDATE, TRUNCATE, CREATE or ownership. |
| `famboook_identity_reader` | Not used | None |
| `famboook_app` (application) | Not used | **None.** No schema USAGE and no table privilege. |

**No RBAC change.** Spatie roles and permissions are untouched. No P1 privilege was widened.

## 2. How the loader was verified with real sessions

- **Loader sessions:** every loader test opens its **own LOGIN session** as a throwaway role.
  - The role is created per test with a random password that is never stored, and dropped afterwards.
  - It is `NOINHERIT` and a member of exactly one role: `famboook_identity_writer`, or `famboook_app` for the denial tests.
  - The loader itself runs `SET ROLE famboook_identity_writer` and then checks the result. **No loader test runs in the administrator session.**
- **Administrator connection:** used only to set up fixtures, to inspect results, and to simulate corruption or crashes.
- **Writer identity check** (`StageIdentityImportAction::actAsWriter`). The loader refuses (`WRONG_ROLE`) unless all of these hold:
  - `SET ROLE famboook_identity_writer` succeeds (the login is a member);
  - `current_user` is the writer;
  - the role is not a superuser.
- **Target check:** the loader refuses (`UNSAFE_TARGET`) unless the connected database equals the explicitly named `--target`.
- **No silent reconnect:** reconnection is disabled, so a dropped session can never continue under another identity. This was tested by killing the session mid-chunk.
- **Environment:** the command refuses to run in the `production` environment. P2 is approved for local synthetic data only.

## 3. Verified controls

| Control | Evidence |
|---|---|
| **Application role** cannot run the loader (`WRONG_ROLE`, no run recorded). Read, insert, delete, truncate and create in staging all give `42501`. `famboook_app` and the reader have no USAGE on staging. | `IdentityRefStagingSecurityPostgresTest::test_the_application_role_cannot_stage_or_write` |
| **Writer privilege set** is exactly `SELECT, INSERT, DELETE` on both staging tables. It has no CREATE. Every staging object is owned by the owner role. All staging functions pin `search_path` and are **not** SECURITY DEFINER. | `…::test_writer_least_privilege_and_staging_integrity_rules` |
| **Database-enforced staging rules**, for every role. Each is refused: <ul><li>UPDATE;</li><li>TRUNCATE;</li><li>DISABLE TRIGGER;</li><li>CREATE;</li><li>a row inserted into a chunk committed by another transaction;</li><li>a non-contiguous checkpoint;</li><li>a checkpoint whose rows are missing at COMMIT;</li><li>deleting the completed staging of a RUNNING run.</li></ul> | same test |
| **No publication.** No record, version, release or presence row is written, and the active-release pointer does not move (verified with a published release present). The loader source contains no reference to any publication object. | `…::test_staging_never_changes_the_active_release_or_reference_data` |
| **Operational registry and RBAC untouched.** Verified across a full stage plus abort: `public` object ACLs, the row counts of `persons`, `families`, `users`, `roles`, `permissions` and the Spatie pivots, and the attributes of `famboook_app`. Staging has no foreign key outside `identity_ref`. | `…::test_operational_registry_and_rbac_are_untouched` |
| **One staging process at a time.** A held lock gives `CONCURRENT_IMPORT` immediately (under 5 s). A different manifest while a run is RUNNING gives `RUN_CONFLICT`. | `…::test_concurrent_imports_are_refused` |
| **Redaction** | §4 |
| **P1 protections still effective:** all 24 P1 tests pass with the P2 schema applied. | `tests/Feature/IdentityRef/IdentityRef*PostgresTest` (P1 classes) |

## 4. Sensitive data protection

**Where values leak.** PostgreSQL error texts carry record values: `Key (…)=(…)`, `Failing row contains (…)`, and COPY's `CONTEXT: COPY rows, line N: "<the record>"`. Laravel's `QueryException` adds the SQL and its bindings. The test proves the COPY leak is real on an unredacted error.

**What the loader does about it:**
- **Exceptions:** every unexpected error is passed through `IdentityRefErrorRedactor`, which keeps only the SQLSTATE, the `IDENTITY_REF_*` code and the constraint and relation names. The result is thrown as `IdentityImportException`, and the original is **not chained**, because Laravel's handler would otherwise log its message and bindings.
- **Structural errors:** `IdentityCsvException` and the manifest exceptions carry only a fixed code, a file name that passed the safe-name rule, and a line number. An unsafe file name in the manifest is never echoed.
- **Logs:** PSR-3 events with the run id, safe file names, counts, codes and redacted errors only.
- **Console output:** counts and issue codes only.
- **Issues** (`import_issues`): code, file, line and column. **No key fingerprint is stored.** A 9-digit key space is cheap to brute-force, so even a hash would leak the key. File and line are enough to locate a record in the source.
- **No previews, no temporary files, no API:**
  - nothing writes a CSV preview;
  - the loader creates no temporary file and streams directly from the source;
  - there is no endpoint and no UI.

**Evidence:** `…::test_logs_errors_and_output_are_redacted`. It checks a failing chunk (database error with values in its SQL, bindings and DETAIL), a direct COPY error, a full successful run, the report, the issue rows and the command output. None contain a synthetic identity, the synthetic name, `Failing row`, `Key (`, the SQL, or `COPY`.

**Not covered by application-side redaction:** the **PostgreSQL server log**. A failed COPY logs its `CONTEXT` line, including record text, at the default settings. This must be configured by a DBA before any real data is loaded (P2-OI-02, extends P1-OI-03).

## 5. Input safety

- **Directory:** resolved with `realpath`, and must not itself be a symlink.
- **Inventory:** exactly `manifest.json` plus the listed files. Any other entry is refused, including stray files and sub-directories (`UNEXPECTED_ENTRIES`).
- **File names:** must match `^[A-Za-z0-9][A-Za-z0-9._-]{0,123}\.csv$`. That rules out separators, `..`, absolute paths and drive letters.
- **Path checks:** each file must be a regular file, not a symlink, and must resolve inside the directory. Duplicates (case-insensitive) are refused.
  - The symlink case is implemented, but its test was **skipped on this machine**: Windows without Developer Mode cannot create symlinks (P2-OI-06).
- **Size limits:** the manifest is capped at 1 MB and parsed with depth 16. Each record is capped at 1 MB (`RECORD_TOO_LONG`), so a malformed file cannot exhaust memory.
- **File swapped after validation:** every file is hashed again while it is loaded. A mismatch at the end of a file rejects the run and purges its staging (`SOURCE_CHANGED`). A change before a resume is caught by revalidation (tested).

## 6. Known limits

- **Superuser sessions:** a superuser session can also run the loader. It then acts with writer privileges after `SET ROLE`. Operators should use a dedicated login that is a member of the writer role (P2-OI-01).
- **Credential caching:** credentials come from `IDENTITY_REF_DB_*` in the operator's environment, not from `.env`. Running `php artisan config:cache` while they are set would write them into `bootstrap/cache/config.php` (P2-OI-12).
- **UNLOGGED staging and backups:** staging data is UNLOGGED, but `pg_dump` includes UNLOGGED table data by default. A backup taken while real data is staged would contain it (P2-OI-03).
