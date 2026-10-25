# FB-CIVIL-01A-P1 — Reference Database Foundation: Implementation

Status: implemented and tested on a disposable local PostgreSQL test cluster only.
Synthetic data only. Not applied to Production or to any other database. Not committed.

Scope: the `identity_ref` PostgreSQL schema, its roles, its integrity and privilege controls, and their
tests. Out of scope (not started): the CSV import engine (P2), any API or UI, reader access (FB-CIVIL-01B),
and any change to the operational `persons` / `families` registry or to RBAC.

## 1. Files

| File | Purpose |
|---|---|
| `deploy/sql/identity-ref/00-roles.dba.sql` | DBA script: creates the three NOLOGIN roles (idempotent, re-asserts attributes) |
| `deploy/sql/identity-ref/01-schema.sql` | Schema, tables, constraints, triggers, functions, view, grants. Refuses to run unless `current_user = famboook_identity_owner` |
| `deploy/sql/identity-ref/90-drop.local-test-only.sql` | Drops the schema; refuses unless the database name ends in `_test` and the port is not 5432 |
| `backend/app/Support/IdentityRef/IdentityRefErrorRedactor.php` | Turns a database error into a value-free log line |
| `backend/tests/Support/IdentityRef/IdentityRefDatabase.php` | Test harness: applies the scripts exactly as a DBA would, runs statements under real roles, synthetic fixtures |
| `backend/tests/Feature/IdentityRef/*PostgresTest.php` | 24 PostgreSQL tests (4 classes) covering the 20 required cases |

### Why SQL scripts and not a Laravel migration

`deploy-backend.sh` runs `php artisan migrate` as the application role. A migration would make
`famboook_app` the owner of `identity_ref`, and an owner bypasses grants and can `ALTER TABLE … DISABLE TRIGGER`.
That contradicts the requirement that the application role has no write access. The schema is therefore
owned by a dedicated NOLOGIN role and applied by a DBA through reviewed SQL. Laravel's `migrate:fresh` /
`RefreshDatabase` drop only the tables on the connection's search path (`public`), so they never touch
`identity_ref`. In Production the application role could not drop the schema anyway.

### Applying (DBA procedure; not performed outside the test cluster)

```sql
-- as a PostgreSQL administrator, connected to the target database
\i deploy/sql/identity-ref/00-roles.dba.sql
SET ROLE famboook_identity_owner;
\i deploy/sql/identity-ref/01-schema.sql
RESET ROLE;
-- only when P2 is approved, interactively, never in Git:
-- ALTER ROLE famboook_identity_writer LOGIN PASSWORD '<interactive>';
```

Both scripts are pure SQL, so they run under `psql -v ON_ERROR_STOP=1 -f` or as one PDO exec. Production use
needs an explicit, recorded approval (see the open issues document).

## 2. Data model

```
import_runs ─┬─< import_files
             ├─< import_issues
             ├─< records (first_seen_run_id) ─< record_versions
             └── dataset_releases (1:1 run) ─< release_presence >── record_versions
                     ^ base_release_id                (release, record) -> version
active_release (single row, id = 1) ──> dataset_releases (PUBLISHED only)
active_records (view) = active_release ⨝ release_presence ⨝ records ⨝ record_versions
```

| Table | Content | Mutability |
|---|---|---|
| `import_runs` | One run: manifest SHA-256, source table, expected rows, counters, `error_code` (pattern `^[A-Z][A-Z0-9_]*$`, never free text) | `RUNNING` → one terminal state, then frozen; identity fields fixed; at most one `RUNNING` (partial unique index) |
| `import_files` | One source file per run: safe file name, expected/actual SHA-256, rows, status | Mutable only while the run is `RUNNING`; identity fields fixed |
| `import_issues` | Sanitized issue: file name, line, code, source column name, optional 32-byte key fingerprint. **No value column exists.** | Insert-only |
| `records` | Identity anchor: one row per `mainid` | Insert-only |
| `record_versions` | Immutable raw payload (the 36 source fields as `jsonb`), `version_no`, `content_sha256` | Insert-only |
| `dataset_releases` | One release per run, `base_release_id` | `BUILDING` → `PUBLISHED` or `ABANDONED`, then frozen; at most one `BUILDING` |
| `release_presence` | Which version of which identity belongs to a release; `PRESENT` or `CARRIED_FORWARD` | Insert-only, and only while the release is `BUILDING` |
| `active_release` | The pointer to the published release | Changed only by `publish_release()`; must name a `PUBLISHED` release; cannot be deleted |

Every foreign key is `ON DELETE RESTRICT`. There are no cascades.

## 3. Key decisions

### 3.1 `mainid`

- Stored as `text`, unique on `records`.
- No 9-digit requirement and no numeric cast, so leading zeros and lengths such as `7` or `123` are kept as given.
- CHECK constraints allow only: non-empty, at most 64 characters, no leading or trailing whitespace. A
  whitespace-padded key is rejected rather than silently trimmed.
- Uniqueness has two scopes:
  - one anchor per `mainid` globally;
  - one version per identity per release (primary key `(release_id, record_id)`).

### 3.2 Raw preservation of the 36 fields

- `source_payload` is a `jsonb` object. A CHECK (`is_valid_source_payload`) requires exactly the 36 source
  keys, and every value must be a JSON string or JSON `null`.
- Nothing is trimmed, cast, normalized or decoded, so the following are all preserved byte for byte:
  - `NULL` vs `''`;
  - leading zeros (`'000000042'`, `'035'`);
  - unknown codes;
  - surrounding spaces;
  - invalid dates.
- A BEFORE INSERT trigger rejects any payload whose `mainid` differs from its anchor's, so a record cannot be silently re-keyed.
- Interpreting codes and dates is deliberately left to later, separately approved layers.

### 3.3 Versioning and no-change updates

- A BEFORE INSERT trigger computes `content_sha256 = sha256(convert_to(payload::text, 'UTF8'))`. `jsonb`
  text output is canonical (key order and whitespace normalized), so the database, not the client, decides
  whether content changed.
- `UNIQUE (record_id, content_sha256)` plus `INSERT … ON CONFLICT DO NOTHING` means an unchanged row reuses its
  existing version. A changed row gets `version_no = max + 1`, and every earlier version stays.
- Two concurrent inserts of a new version for the same record can collide on `UNIQUE (record_id, version_no)`.
  The result is a safe 23505 failure, never a wrong number. The single-`RUNNING`-run rule makes this practically unreachable.

### 3.4 Atomic publication with immutable release sets

A mutable "current records" table would expose partial data while it is rewritten. Instead:

1. **Build.** The writer builds a new release while it is `BUILDING`, inserting presence rows (committed or not).
   Readers never see them: `active_records` reads only through the pointer.
2. **Carry forward.** `carry_forward_missing(release)` copies every identity of the base release that is absent
   from the new source as `CARRIED_FORWARD`, with its current version. **A missing identity is never deleted.**
3. **Publish.** `publish_release(release)` (SECURITY DEFINER) does everything in the caller's transaction:
   - takes a global transaction-level advisory lock with `pg_try_advisory_xact_lock`; if another publication
     holds it, the call fails fast with `55P03 IDENTITY_REF_PUBLICATION_IN_PROGRESS` and never waits;
   - requires the release to be `BUILDING` (`FOR UPDATE`);
   - requires `base_release_id` to equal the currently active release (`FOR UPDATE` on the pointer). Otherwise
     it raises `40001 IDENTITY_REF_RELEASE_STALE_BASE`, which prevents lost updates;
   - requires the release to be non-empty (`IDENTITY_REF_RELEASE_EMPTY`);
   - requires the release to contain every identity of its base (`IDENTITY_REF_RELEASE_DROPS_IDENTITIES`);
   - then freezes the release as `PUBLISHED` with its `record_count` and upserts the pointer.
4. **Visibility.** The pointer switch commits atomically. Under MVCC, every reader statement (or REPEATABLE READ
   transaction) sees exactly one complete release, either the old one or the new one.
5. **Failure.** A failed or rolled-back publication changes nothing: the previous release stays active.
6. **Abandonment.** `abandon_release()` marks an unpublishable `BUILDING` release `ABANDONED`. Its rows stay,
   inert, and it can never become active: triggers enforce this even for an administrator.

### 3.5 Recovery

- **Session dies mid-transaction:** PostgreSQL rolls everything back. Tested by `pg_terminate_backend` on the writer session.
- **Process dies after committing a `RUNNING` run and a `BUILDING` release:**
  - no new run can start (single-`RUNNING` index), so nothing is silently built on top of it;
  - the operator calls `abandon_release()` and sets the run `FAILED` with `error_code = 'INTERRUPTED'`;
  - then a fresh run proceeds. The active release is untouched throughout.

### 3.6 Immutability that holds for every role

- `BEFORE UPDATE/DELETE` row triggers and `BEFORE TRUNCATE` statement triggers raise `42501
  IDENTITY_REF_IMMUTABLE` on the reference tables.
- Triggers fire for the owner and for superusers too, unless they are explicitly disabled. Only the owner
  or a superuser can disable them (see the security verification).
- This is defense in depth on top of the grants: the writer has no `UPDATE`, `DELETE` or `TRUNCATE` at all.

## 4. Tradeoffs

| Topic | Choice | Cost / consequence |
|---|---|---|
| **Storage** | Each release stores its full presence set: one row per identity, `CARRIED_FORWARD` included. | About 4 fixed-width columns plus the primary-key index per row, in the order of 60–80 MB per million identities per release, plus versions only for changed rows. Old releases cannot be pruned while history is immutable (open decision P1-OI-06). |
| **Reads** | `active_records` joins pointer → presence (PK prefix `release_id`) → records → versions. | Index-only lookups by primary key. No index on `records.mainid` is needed beyond its UNIQUE index. Lookups by other payload fields are deliberately not indexed in P1, because no reader exists yet. |
| **Publication cost** | The DROPS_IDENTITIES check is an anti-join between two releases. | Linear in release size, inside the publish transaction, while the advisory lock blocks only other publishers, never readers. |
| **Rollback** | There is no "unpublish". Rolling back means publishing a new release built from an older release's versions. | Not implemented in P1; this is a P2/operations decision (P1-OI-07). |
| **Concurrency** | One `RUNNING` run, one `BUILDING` release, fail-fast publication lock, stale-base check. | Imports are strictly serial by design. |

## 5. Compatibility

The schema uses only features available in PostgreSQL 16:
- `gen_random_uuid()`;
- identity columns;
- `sha256()`;
- `EXECUTE FUNCTION` triggers;
- partial unique indexes.

It was executed on the local test cluster, PostgreSQL 18.6. A run on a PostgreSQL 16 instance is still
outstanding (P1-OI-09).
