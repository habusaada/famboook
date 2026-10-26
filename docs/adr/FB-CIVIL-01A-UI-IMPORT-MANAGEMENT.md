# ADR FB-CIVIL-01A-UI — Civil Identity Import Management

| | |
|---|---|
| Status | **PROPOSED implementation contract (U0 finalized 2026-10-26).** Decisions D-UI-1 … D-UI-13 and security requirements S-1 … S-7 are approved in `docs/decisions/FB-CIVIL-01A-UI-DECISIONS.md`. **Nothing is implemented.** |
| Builds on | FB-CIVIL-01A P1–P4 (commit `13897e3`): schemas `identity_ref` / `identity_ref_staging`, the staging and publication actions, the database publication gate, policy `IDREF-POLICY-1`, and the P4 Production approval reference |
| Companion | `docs/adr/FB-CIVIL-01A-UI-API-AND-SCREENS.md` |
| Schema / data change | None in U0. **Proposed:** a metadata-only monitor schema (U1, DBA script, local first; S-5) and application-side control tables (U2/U3, Laravel migrations). No existing `identity_ref` object changes. |
| RBAC change | None in U0. **Proposed:** seven `civil-import.*` permissions (§7) |
| Production | No Production change is authorized by this ADR |

## 1. Context

**What FB-CIVIL-01A delivered:**
- An import engine operated **only from the shell** by an operator holding the import login.
- Credentials in the operator's shell only.
- The application's database role has no access to the identity schemas.

The owner now wants an administrative UI to follow and drive imports **without weakening any of that isolation**.

**Inspection (2026-10-26, HEAD `13897e3`):**
- **No civil identity UI, route or API exists.**
- **Engine is reusable:** `StageIdentityImportAction` and `PublishIdentityReleaseAction` take a connection and options, and are not tied to the CLI.
- **Engine state is metadata only:** `import_runs`, `import_files`, `file_chunks`, `import_issues` (code / file / line, no values), `dataset_releases`, `release_preparations`, `active_release`.
- **Precedent:** the split permissions and closed-by-default gate of the Initial Family Import (`import.*`, `IMPORT_APPLY_ENABLED`, docs/08 §7a), and its wizard (`components/administration/import-wizard/*`).
- **Frontend tooling:** Vitest and Testing Library, ESLint, `tsc` (`frontend/package.json`).
- **Workers:** the only worker is the notification worker (60 s timeout), unsuitable for imports of about 50 minutes or more.

## 2. Decision summary

```
 Browser (Staff app, Next.js)                 Laravel API (PHP-FPM as www-data, app role famboook_user)
 /administration/civil-registry/*   ─HTTP─▶   /api/v1/civil-registry/*
   reads: metadata, requests, audit            • control plane: civil_import_* tables (app schema)
   writes: requests / approvals only (S-2)     • IdentityImportMonitor ─▶ identity_ref_monitor views (metadata)
   polls every 5 s only while active (D-UI-13)    credentials read at run time from /etc/famboook/identity-monitor.php
                                               • NEVER opens the identity writer connection (S-1)
                                                        │ civil_import_requests (QUEUED)
                                                        ▼
                     famboook-identity-import-worker (systemd, OS account famboook-import, non-root; D-UI-10)
                       • EnvironmentFile=/etc/famboook/identity-import-worker.env (root:root 0600)
                       • polls requests (FOR UPDATE SKIP LOCKED), one at a time
                       • identity_ref_import ─▶ SET ROLE famboook_identity_writer ─▶ FB-CIVIL-01A actions
                       • control-plane login ─▶ request status, heartbeats, audit (O-1)
```

## 3. Workflow state machines

### 3.1 Import phase (derived from FB-CIVIL-01A state; shown in the UI)

| UI phase | Derived from | Next allowed actions |
|---|---|---|
| `SOURCE_AVAILABLE` | A catalogued source directory not yet staged | STAGE |
| `STAGING` | A STAGE request RUNNING; run RUNNING; files not all STAGED | ABORT |
| `STAGING_INTERRUPTED` | Run RUNNING, files not all STAGED, no request active | STAGE (resume), ABORT |
| `REJECTED` | `import_runs.status = REJECTED` | none (terminal) |
| `STAGED` | All files STAGED, no release | ASSESS, PREPARE, ABORT |
| `PREPARING` | Release BUILDING, preparation PREPARING | PREPARE (resume, when no request active), ABORT |
| `PREPARED` | Preparation PREPARED | VERIFY, ABORT |
| `VERIFIED` | Preparation VERIFIED | REQUEST_PUBLICATION, ABORT |
| `AWAITING_APPROVAL` | Publication request PENDING_APPROVAL | APPROVE / REJECT (another user), WITHDRAW (requester), ABORT |
| `APPROVED` | Publication request APPROVED; PUBLISH request queued or running | — |
| `PUBLISHED` | Release PUBLISHED; run SUCCEEDED | none (terminal) |
| `ABORTED` | Run FAILED with `ABORTED` | none (terminal) |

The database remains the authority. The worker re-checks every precondition through the FB-CIVIL-01A functions, and the UI phase and `allowed_actions` are hints.

### 3.2 Execution request (`civil_import_requests`)

| From | To | By | Condition |
|---|---|---|---|
| — | QUEUED | API (the action's permission) | The phase allows it; no other request QUEUED or RUNNING |
| QUEUED | CANCELLED | API (requester or `civil-import.stage`) | Not yet picked up |
| QUEUED | RUNNING | worker | `FOR UPDATE SKIP LOCKED` plus the FB-CIVIL `FBCIV01S` advisory lock |
| RUNNING | SUCCEEDED / FAILED | worker | The action's outcome; FAILED carries a reason code and a redacted message |
| RUNNING | FAILED (`WORKER_LOST`) | worker at start-up | No heartbeat for 10 minutes; the FB-CIVIL operation is resumable |

Request types: `STAGE`, `ABORT`, `ASSESS`, `PREPARE`, `VERIFY`, `PUBLISH`. `PUBLISH` can only be created by an approval (§3.3), never directly by the API.

### 3.3 Publication request (`civil_import_publication_requests`)

| From | To | By | Condition |
|---|---|---|---|
| — | PENDING_APPROVAL | user A, `civil-import.publish.request` | Release VERIFIED; run RUNNING; no other open publication request |
| PENDING_APPROVAL | APPROVED | user **B ≠ A**, `civil-import.publish.approve` | Gate open; release still VERIFIED and its base still active; within 24 h. Inserts the QUEUED `PUBLISH` request in the same transaction. |
| PENDING_APPROVAL | REJECTED | user B ≠ A, `civil-import.publish.approve` | A reason code |
| PENDING_APPROVAL | WITHDRAWN | user A | — |
| PENDING_APPROVAL | EXPIRED | system (on read, or the worker's sweep) | 24 h passed (D-UI-11), or the base is no longer active |
| APPROVED | EXECUTED / FAILED | worker (`PUBLISH`) | `publish_release()` result. A failure is rolled back, the previous release stays active, and a new request is needed. |

**Publication preconditions (S-3).** The worker publishes only if **all** of these hold at execution time:
1. an APPROVED publication request whose approver ≠ requester;
2. the gate `civil_import.publish_enabled = true` in the **worker's** configuration;
3. the release is still VERIFIED and its base is still active;
4. the real-data authorization reference `IDENTITY_REF_PRODUCTION_APPROVAL` is present in the worker's protected environment file. It is issued only after G1 (lawful basis) and G3; it is the shared Production gate (X-7).

The FB-CIVIL-01A database gate still independently refuses any release that is not VERIFIED.

## 4. Dedicated worker and credential isolation (U2)

| Aspect | Design |
|---|---|
| Process | `php artisan famboook:identity-ref-worker`: a long-running loop, one request at a time, 30 s idle poll, heartbeat every 30 s (`civil_import_worker_heartbeats`) |
| OS account (D-UI-10) | `famboook-import`: system user, **non-root**, no login shell. It needs read access to the application code (world-readable or the `deploy` group) and owns the source root `CIVIL_IMPORT_SOURCE_ROOT` (proposed `/var/lib/famboook-import`, mode 0700). Operators place files there with `sudo -u famboook-import`. |
| Service | `deploy/systemd/famboook-identity-import-worker.service`: `User=famboook-import`, `EnvironmentFile=/etc/famboook/identity-import-worker.env`, `NoNewPrivileges=yes`, `ProtectSystem=strict` with a write path for the source root only, `Restart=on-failure`. **Not installed, enabled or started by any script.** |
| Credential file (D-UI-10) | `/etc/famboook/identity-import-worker.env`, `root:root 0600`, read by systemd as root before dropping privileges. It holds `IDENTITY_REF_DB_USERNAME=famboook_identity_import`, `IDENTITY_REF_DB_PASSWORD`, the control-plane login (O-1), `CIVIL_IMPORT_PUBLISH_ENABLED` and, only after G1/G3, `IDENTITY_REF_PRODUCTION_APPROVAL`. **Outside the repository**; never in `.env` or cached configuration (the P4 runtime override reads them from the process environment). |
| Database sessions | (a) `identity_ref_import`: `SET ROLE famboook_identity_writer` through the existing `IdentityImportSession` (target check, no reconnect, `FBCIV01S` lock). (b) The control-plane login: requests, heartbeats, audit only (O-1). |
| Logs | The existing redacted `identity_ref.*` PSR events, to **stderr / journald** (the worker account does not write `storage/logs`). No identity value. |
| Failure | A crash leaves the FB-CIVIL operation resumable. Stale RUNNING requests become `WORKER_LOST`, and the UI offers resume. The active release is never affected before a committed publication. |
| Concurrency | One systemd unit, `FOR UPDATE SKIP LOCKED`, and the `FBCIV01S` advisory lock |
| S-1 | No controller, request, job dispatched from HTTP, or action called from HTTP references `identity_ref_import`, `IdentityImportSession`, `StageIdentityImportAction` or `PublishIdentityReleaseAction`. Enforced by an architecture test (§9). |

## 5. Metadata-only access boundary (U1; local first, Production separately, S-5)

### 5.1 Proposed DBA script `deploy/sql/identity-ref/06-monitor.dba.sql`

The script runs as the DBA, then as `famboook_identity_owner` for the views. It changes no existing object or privilege, and it never names the application role.

```sql
-- roles (DBA)
CREATE ROLE famboook_identity_monitor NOLOGIN NOINHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
CREATE ROLE famboook_identity_monitor_login LOGIN NOINHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS
    CONNECTION LIMIT 10 IN ROLE famboook_identity_monitor;              -- password set interactively (\password)
GRANT CONNECT ON DATABASE <db> TO famboook_identity_monitor_login;

-- as famboook_identity_owner
CREATE SCHEMA identity_ref_monitor;  REVOKE ALL ON SCHEMA identity_ref_monitor FROM PUBLIC;
-- every view: WITH (security_barrier = true, security_invoker = false)   (owner's rights; no base-table privilege needed)
CREATE VIEW identity_ref_monitor.runs AS SELECT id, status, error_code, source_table, expected_rows, rows_loaded,
       new_records, new_versions, unchanged, carried_forward, issues, started_at, finished_at FROM identity_ref.import_runs;
CREATE VIEW identity_ref_monitor.files AS SELECT f.run_id,
       row_number() OVER (PARTITION BY f.run_id ORDER BY f.file_name) AS file_no,            -- ordinal, no name (D-UI-8)
       f.expected_rows, f.loaded_rows, f.status, (f.actual_sha256 IS NOT DISTINCT FROM f.expected_sha256) AS checksum_verified,
       p.chunks, p.chunk_rows, p.last_chunk_at
  FROM identity_ref.import_files f
  LEFT JOIN (SELECT file_id, count(*) AS chunks, sum(row_count) AS chunk_rows, max(created_at) AS last_chunk_at
               FROM identity_ref_staging.file_chunks GROUP BY file_id) p ON p.file_id = f.id;
CREATE VIEW identity_ref_monitor.file_locations AS SELECT run_id,
       row_number() OVER (PARTITION BY run_id ORDER BY file_name) AS file_no, file_name FROM identity_ref.import_files;   -- locations.view only
CREATE VIEW identity_ref_monitor.issue_counts AS SELECT run_id, code, column_name, count(*) AS n FROM identity_ref.import_issues GROUP BY 1, 2, 3;
CREATE VIEW identity_ref_monitor.issue_locations AS SELECT run_id, file_name, line_number, code, column_name FROM identity_ref.import_issues;   -- locations.view only
CREATE VIEW identity_ref_monitor.releases AS SELECT id, run_id, status, base_release_id, record_count, created_at, published_at, abandoned_at
  FROM identity_ref.dataset_releases;
CREATE VIEW identity_ref_monitor.preparations AS SELECT release_id, run_id, status, policy_id, comparison_policy, blank_field_policy,
       quarantine_max_rows, quarantine_max_ratio, staged_rows, new_count, changed_count, unchanged_count, conflict_count,
       carried_forward_count, blank_over_value_count, expected_presence, warnings, created_at, prepared_at, verified_at
  FROM identity_ref.release_preparations;
CREATE VIEW identity_ref_monitor.active_release AS SELECT release_id, activated_at FROM identity_ref.active_release;
GRANT USAGE ON SCHEMA identity_ref_monitor TO famboook_identity_monitor;
GRANT SELECT ON ALL TABLES IN SCHEMA identity_ref_monitor TO famboook_identity_monitor;
```

### 5.2 Least-privilege review

| Check | Result |
|---|---|
| Base tables | The monitor roles get **no** privilege on any `identity_ref` / `identity_ref_staging` table, sequence or function. Views run with the owner's rights (`security_invoker = false`, stated explicitly). |
| Never exposed | `records`, `record_versions` (payloads), `release_presence`, `release_incoming`, staging `rows` / `assessments`, `key_fingerprint`, `manifest_sha256`, file SHA values, internal file ids |
| File names (D-UI-8) | **Kept out of the default views** (`files` has an ordinal only). Names appear only in `file_locations` / `issue_locations`, which the application reads only for `civil-import.locations.view` holders. The database cannot know the web user, so this split limits accidental exposure; it is not a per-user database control. |
| Predicate leaks | `security_barrier = true` on every view |
| Session | The login is NOINHERIT: `IdentityImportMonitor` runs `SET ROLE famboook_identity_monitor`, refuses if the role is missing, and disables silent reconnects (the FB-CIVIL-01A session pattern). It has a connection limit of 10, and access comes from localhost only (`pg_hba`). |
| Application role | `famboook_user` is **not** a member of any monitor or identity role (D-UI-9). Tests prove it still gets `42501` everywhere, including `identity_ref_monitor`. |
| Restore | `04-reassert-after-restore.dba.sql` is extended for the monitor schema's owner and grants (U1) |
| Not needed | No EXECUTE, no sequence privilege, no default privileges, no TEMPORARY requirement, no write privilege |

### 5.3 Monitor credentials (D-UI-9)

- **Configuration:** `config/civil_import.php` holds only the **path** of the credentials file: `CIVIL_IMPORT_MONITOR_CREDENTIALS_FILE`, default `/etc/famboook/identity-monitor.php`. The path is not secret and may be cached.
- **The file:** `root:www-data 0640`. It returns `['username' => 'famboook_identity_monitor_login', 'password' => '…']`. `IdentityImportMonitor` reads it at connection time and sets the `identity_ref_monitor` connection's credentials at run time. **The password is never in `.env` or `bootstrap/cache/config.php`.**
- **Local and tests:** the test harness sets the connection directly with a throwaway login, as in the FB-CIVIL-01A tests.
- **Missing or unreadable file:** the monitoring pages show "monitoring not configured" (503 `MONITOR_NOT_CONFIGURED`); nothing falls back to the application role.

## 6. Application-side data (Laravel migrations, app schema)

| Table | Content | Stage |
|---|---|---|
| `civil_import_audit_events` | **Append-only** (PostgreSQL trigger blocks UPDATE/DELETE, as `workflow_events`): occurred_at, actor_user_id (null for SYSTEM), actor_side (STAFF/SYSTEM), event_type, request_id, publication_request_id, run_id, release_id, outcome_code, details (jsonb, allow-listed keys: counts, codes, durations, file_no) | U1 |
| `civil_import_sources` | Catalogue written by the worker: id, label (directory name, safe pattern), manifest table, file count, total rows, discovered/last-seen, `available`. **No file content, no file names.** | U2 |
| `civil_import_requests` | id (uuid), type, status, requested_by, source_id / run_id / release_id, reason_code, redacted_message, result (jsonb: counts and codes, allow-list), timestamps | U2 |
| `civil_import_worker_heartbeats` | instance, last_seen_at, current_request_id | U2 |
| `civil_import_publication_requests` | id, release_id, run_id, status, requested_by, decided_by, `CHECK (decided_by IS NULL OR decided_by <> requested_by)`, decision_reason_code, expires_at (created + 24 h), timestamps | U3 |

**Source catalogue:**
- The web process (`www-data`) never reads the source root.
- The worker catalogues directories that hold a `manifest.json`. Names must match `^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$` and resolve inside the root; symlinks are refused, as in P2.

## 7. RBAC matrix (approved principle; names and defaults per D-UI-7/8/12)

| Permission | Allows | SUPER_ADMIN | Others | Stage |
|---|---|---|---|---|
| `civil-import.view` | Overview, run history, status, progress (files by ordinal), validation and comparison counts, releases | ✔ | — | U1 |
| `civil-import.locations.view` | Source file names and identifiers, issue line numbers (D-UI-8); every read is audited | ✔ | — | U1 |
| `civil-import.audit.view` | Audit history | ✔ | — | U1 |
| `civil-import.stage` | Queue STAGE and ABORT; cancel queued requests; see source labels (O-2) | ✔ | — | U2 |
| `civil-import.validate` | Queue ASSESS, PREPARE and VERIFY | ✔ | — | U2 |
| `civil-import.publish.request` | Create and withdraw a publication request | ✔ | — | U3 |
| `civil-import.publish.approve` | Approve or reject **another user's** request | **Only while** `civil_import.publish_enabled = true` (default **false**): seeded to SUPER_ADMIN by `RolePermissionSeeder` (the `import.apply_enabled` pattern) | — | U3 |

**Rules:**
- Grants change only through the seeder; `famboook:verify-permissions` fails if `publish.approve` is held while the gate is closed.
- Every route is guarded by `can:` middleware and a Policy; frontend checks are UX only.
- The two-person rule needs at least two eligible users (O-3).

## 8. Audit events

| Event type | Actor | Recorded data |
|---|---|---|
| `SOURCE_CATALOGUED` / `SOURCE_UNAVAILABLE` | SYSTEM | source id, file count, total rows |
| `REQUEST_QUEUED` / `_CANCELLED` | STAFF | request id, type, target |
| `REQUEST_STARTED` / `_SUCCEEDED` / `_FAILED` | SYSTEM | request id, outcome code, duration, counts |
| `PUBLICATION_REQUESTED` / `_WITHDRAWN` | STAFF | publication request id, release id, counts snapshot |
| `PUBLICATION_APPROVED` / `_REJECTED` | STAFF (≠ requester) | publication request id, reason code |
| `PUBLICATION_EXPIRED` | SYSTEM | cause `TIMEOUT` / `BASE_CHANGED` |
| `PUBLICATION_REFUSED` | SYSTEM | the S-3 precondition that failed (gate closed, no authorization reference, base changed, not VERIFIED) |
| `RELEASE_PUBLISHED` | SYSTEM | release id, run id, activation seconds |
| `LOCATIONS_VIEWED` | STAFF | run id (D-UI-8) |

Audit rows never contain an identity value, a file name, a path or SQL.

## 9. Test acceptance criteria

**PostgreSQL** (disposable `_test` cluster, real LOGIN sessions, synthetic data):
1. The monitor login, after SET ROLE, reads every `identity_ref_monitor` view, and gets `42501` on every `identity_ref` / `identity_ref_staging` table, function and sequence.
2. A catalogue test: no view exposes a payload, key, fingerprint, hash or internal file id; `files`, `runs`, `issue_counts`, `releases`, `preparations` and `active_release` contain no file name.
3. The application role gets `42501` on every identity schema, **including** `identity_ref_monitor`, and is not a member of any identity role.
4. `04-reassert` restores the monitor schema's ownership and grants exactly (snapshot equality, as in P4).
5. Worker (U2): stage → assess → prepare → verify; crash and resume; `WORKER_LOST`; the active release never changes before PUBLISH.
6. Publication (U3), refused in each case:
   - same user;
   - gate closed;
   - authorization reference missing;
   - expired;
   - base changed;
   - not VERIFIED.

   A successful publish switches atomically; a failed publish keeps the previous release.
7. All 76 FB-CIVIL-01A PostgreSQL tests stay green (S-6).

**Laravel:**
- Every endpoint answers 401 unauthenticated, 403 without its permission, and 409 on a disallowed phase.
- File names and line numbers are absent without `locations.view`.
- Resource snapshots contain no national ID, name or payload key.
- **S-1 architecture test:** no HTTP-reachable class references the import connection or the import actions.
- The monitor password is absent from `config:cache` output and `.env`.
- The audit table is append-only.
- `famboook:verify-permissions` covers the new grants and the gate.
- The SQLite suite skips the PostgreSQL-only parts.

**Next.js (Vitest / Testing Library):**
- Pages require `civil-import.view`; action buttons require their permission (UX only).
- **Polling runs only while a request is QUEUED or RUNNING and `document.visibilityState === 'visible'`**, every 5 s, and stops otherwise (D-UI-13).
- File names are not rendered without `locations.view`.
- Arabic RTL with the existing tokens and `StatusBadge` tones.
- No identity value in any fixture.
- `npm run lint`, `typecheck` and `test` pass.

## 10. Implementation plan

| Stage | Scope | Before start | Exit criteria |
|---|---|---|---|
| **U1: read-only monitoring** | `06-monitor.dba.sql` and `04-reassert` extension (**local only**); `config/civil_import.php`; `IdentityImportMonitor`; read APIs; the audit table and `LOCATIONS_VIEWED`; permissions `view` / `locations.view` / `audit.view`; Next.js overview, history, run detail, releases and audit pages. Docs: X-1, X-3, X-4, X-6, X-8. | U1 approval | §9 PostgreSQL 1–4 and 7; Laravel and Next.js read-only criteria. **Then a separate approval to apply `06-monitor` on Production (S-5).** |
| **U2: execution** | Sources, requests, heartbeats; the worker command and systemd template; the shared Production gate (X-7); STAGE / ABORT / ASSESS / PREPARE / VERIFY; the new-import wizard and live progress. Docs: X-2, X-5. | U1 accepted; O-1 and O-2 decided | §9 PostgreSQL 5; Laravel request-state tests; S-1 test |
| **U3: publication approval** | Publication requests, approval, rejection, expiry; PUBLISH; `publish.request` / `.approve` with the gate closed by default; the approvals page | U2 accepted; O-3 verified | §9 PostgreSQL 6; the gate is closed and no authorization reference exists in every default configuration (S-4) |

**Local implementability of U1** (no Production connection needed):
- the disposable PostgreSQL cluster and the FB-CIVIL-01A test harness (which applies 00–03 and will apply 06);
- throwaway LOGIN roles for the monitor login;
- the SQLite suite;
- the Next.js dev server and Vitest.

**Real data:** none of U1–U3 lifts the FB-CIVIL-01A real-data gates (G1, G3, G5–G9, D-11, D-12). Under S-3 the worker cannot publish without the real-data authorization reference.
