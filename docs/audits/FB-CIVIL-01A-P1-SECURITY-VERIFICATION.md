# FB-CIVIL-01A-P1 — Security Verification

Everything below was executed against the disposable local test cluster: `famboook_test` on 127.0.0.1:5433,
PostgreSQL 18.6, synthetic data only. Nothing was verified on Production, and no Production role exists yet.

## 1. Roles

| Role | Attributes (verified) | Privileges on `identity_ref` |
|---|---|---|
| `famboook_identity_owner` | NOLOGIN; no SUPERUSER, CREATEDB, CREATEROLE, REPLICATION or BYPASSRLS | Owns the schema and every object. Has CONNECT and CREATE on the database, for applying the schema. Used only through the DBA's `SET ROLE`. |
| `famboook_identity_writer` | NOLOGIN as shipped, with the same restrictions. A DBA enables LOGIN interactively for P2. | `USAGE` on the schema. `SELECT, INSERT` on `records`, `record_versions`, `release_presence`, `dataset_releases`, `import_issues`, `import_runs` and `import_files`. `SELECT` on `active_release`. Column-level `UPDATE` on the run/file status and counter columns only. `EXECUTE` on `source_columns`, `is_valid_source_payload`, `carry_forward_missing`, `publish_release` and `abandon_release`. **No** UPDATE/DELETE/TRUNCATE on reference data, no DDL, no CREATE on the schema or the database, no ownership, no membership in the owner role. |
| `famboook_identity_reader` | NOLOGIN, with the same restrictions | **Nothing.** Reserved for FB-CIVIL-01B. |
| `famboook_app` (existing application role) | Unchanged | **Nothing**: no schema USAGE, no table privilege, no function EXECUTE. `PUBLIC` is revoked on the schema, tables, sequences and functions, including default privileges. |

## 2. How role separation was verified

**Distinct PostgreSQL roles were used. Role separation was not simulated.** Two mechanisms were used, and
each covers what the other cannot:

1. **`SET ROLE` from the administrator session.** This covers every object-privilege test. PostgreSQL checks
   object privileges against `current_user`, which after `SET ROLE` is the target role. Every `42501` in the
   tests is PostgreSQL's own check for `famboook_app`, `famboook_identity_reader`, `famboook_identity_writer`
   or `famboook_identity_owner`.
2. **A real, separate LOGIN session.** `SET ROLE` checks membership against the *session* user, which here is
   a superuser, so mechanism 1 cannot prove that the writer cannot become the owner. The test
   `test_a_real_writer_login_session_cannot_escalate` therefore creates a throwaway LOGIN role that is only a
   member of `famboook_identity_writer` (random password, generated per run, never stored), opens its own
   connection, and verifies the following:
   - `session_user` is that role;
   - writer privileges apply;
   - each of these is refused with `42501`:
     - `SET ROLE famboook_identity_owner`;
     - `SET ROLE famboook_app`;
     - `SET session_replication_role = replica`, which would skip triggers;
     - `ALTER TABLE … DISABLE TRIGGER ALL`;
     - `DELETE`;
     - a direct update of the pointer.

   The throwaway role is terminated and dropped in `finally`.

**What was not verified:**
- the `famboook_identity_writer` role itself logging in. As shipped it is NOLOGIN, and in the test it is used
  through membership;
- `pg_hba.conf` rules;
- SSL;
- any Production or staging role.

## 3. Verified controls

| Control | Evidence (test) |
|---|---|
| The application role and the reader can neither read nor write: 13 statements (SELECT on tables and the view, INSERT, UPDATE, DELETE, TRUNCATE, EXECUTE on all three functions, CREATE TABLE), all `42501`; `has_*_privilege` is false for every table and privilege | `IdentityRefRolesPostgresTest::test_application_and_reader_roles_have_no_access_at_all` |
| The writer can do the full import path: run, file, issue, records, versions, presence, publish, finish | `…::test_writer_can_record_a_run_and_publish_through_the_controlled_path` |
| The writer cannot bypass or escalate. Refused: UPDATE/DELETE/TRUNCATE on reference data, a direct pointer write, a direct release transition, UPDATE of a fixed run column, DISABLE TRIGGER, DROP CONSTRAINT, DROP TABLE, DROP FUNCTION, CREATE TABLE, `CREATE OR REPLACE` of `publish_release`, `session_replication_role`. A GRANT to the application role grants nothing. | `…::test_writer_cannot_bypass_the_controlled_path_or_escalate` |
| No special attributes; none of 5 forbidden memberships exist; the writer has no CREATE on the schema or the database | `…::test_roles_carry_no_special_attributes_or_memberships` |
| Every function in the schema is owner-owned, pins `search_path = pg_catalog, pg_temp`, and has no PUBLIC EXECUTE. Exactly two are SECURITY DEFINER (`publish_release`, `abandon_release`). All object references in function bodies are schema-qualified. | `…::test_functions_have_a_protected_search_path_and_no_public_execute` |
| The schema refuses to be applied by the administrator or by the application role; only the owner can apply it | `IdentityRefSchemaPostgresTest::test_the_schema_refuses_to_be_applied_by_any_role_but_the_owner` |
| UPDATE, DELETE and TRUNCATE of reference data are refused for the **administrator, the owner and the writer** (13 statements × 3 roles) | `…::test_reference_data_cannot_be_updated_deleted_or_truncated_by_any_role` |
| The pointer cannot name an abandoned release, even when the administrator tries | `IdentityRefPublicationPostgresTest::test_failed_publication_keeps_the_previous_release` |
| Errors are redacted before logging (see §4) | `IdentityRefIsolationPostgresTest` (2 tests) |
| Applying the schema leaves every non-`identity_ref` object unchanged: table owners and ACLs, columns, triggers, `famboook_app` attributes, role memberships, the `public` schema ACL, and the row counts of `persons`, `families`, `users`, `roles`, `permissions` and the Spatie pivot tables (60 public tables in the snapshot). No foreign key or view depends on anything outside `identity_ref`. | `…::test_operational_tables_and_rbac_are_untouched` |

## 4. Redaction

PostgreSQL error texts carry values: `DETAIL: Key (mainid)=(…) already exists.` and `Failing row contains (…)`.
Laravel's `QueryException` appends the SQL and its bindings on top. The test proves this leak vector is real
on an unredacted error.

`App\Support\IdentityRef\IdentityRefErrorRedactor::redact()` keeps only fixed identifiers:
- the SQLSTATE;
- an `IDENTITY_REF_*` code;
- the constraint name;
- the relation name.

The tests check four real error classes (unique, check, immutable trigger, presence duplicate). None of the
synthetic identities, the synthetic name, `Failing row`, `Key (`, or the SQL survive redaction.

The schema itself cannot store values in its diagnostics:
- every `RAISE` message is an `IDENTITY_REF_*` code whose only arguments are the operation and the table name;
- `import_issues` has no value column, and its `code`, `column_name` and `file_name` are restricted by
  pattern or allow-list;
- `import_runs.error_code` is pattern-restricted. A value-bearing code is rejected (tested).

**Not covered by application-side redaction:** the PostgreSQL server log. With the default
`log_min_error_statement = error`, the server logs the failing statement. With literal SQL, that statement can
include values. This is a DBA configuration item (P1-OI-03).

## 5. Known limits (by design, documented)

- **A superuser or the owner role can bypass every control:** they can disable triggers, drop objects, or set
  `session_replication_role`. Mitigation:
  - the owner is NOLOGIN and used only by the DBA through `SET ROLE`;
  - no application credential is a superuser or a member of the owner role (verified for the roles above).

  Whether `famboook_app` is a superuser in Production cannot be verified here (P1-OI-02).
- **The writer can read all reference data** (it needs this for deduplication). Its credential is therefore as
  sensitive as the dataset.
- **The writer can insert anchors and versions that are never published.** They are inert: only presence rows
  in a published release make data visible. Nothing ties such inserts to a `RUNNING` run (P1-OI-05).
- **`TEMPORARY` on the database is held by `PUBLIC` by default,** so the writer can create temporary tables
  (P1-OI-04).
