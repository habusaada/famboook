# FB-CIVIL-01A-UI U4 — Local Operational Acceptance

**Status:** PREPARED (2026-10-26). The full scenario has **not** been executed. It needs explicit approval first.

**Scope:** an end-to-end manual acceptance of U1–U3 on the local machine.

**Safety rules:**
- **Synthetic identity data only.**
- **Never** Production, the development database (`127.0.0.1:5432/famboook`), `backend/.env`, or a real export.
- Publication stays disabled. It may be opened **only** inside this disposable acceptance environment (§5).

## 1. Environment

| Component | Value |
|---|---|
| Database | `127.0.0.1:5433/famboook_u4_test`: the disposable test cluster. It is owned by the throwaway application login `famboook_u4_app`, which has no identity privilege. |
| Identity schemas | FB-CIVIL-01A `00`–`03`, then `06-monitor` and `07-control-plane`, applied as a DBA would |
| Logins | Each is a member of **one** role only: `famboook_u4_monitor_login` → monitor (the API), `famboook_u4_control_login` → control plane (worker), `famboook_u4_import_login` → identity writer (worker) |
| State (credentials, sources) | `%LOCALAPPDATA%/famboook-u4` (outside the repository): `web.env`, `worker.env`, `identity-monitor.php`, `accounts.txt`, `sources/` |
| API | `http://localhost:8010` (`php artisan serve` with `web.env`) |
| Staff Portal | `http://localhost:3010` (`next dev` with `NEXT_PUBLIC_API_URL=http://localhost:8010`) |
| Worker | `backend/identity-import-worker`, with a **clean** environment holding `worker.env` only (as systemd `EnvironmentFile=`) |

Ports 8010 and 3010 avoid the normal development stack (8000 and 3000, which uses the development database). Do not run both Staff Portals in the same browser profile: cookies are per host, not per port.

## 2. Accounts (passwords only in `accounts.txt`)

| Account | Role | Use |
|---|---|---|
| `dev@famboook.test` | SUPER_ADMIN | **Requester**: sign in at `http://localhost:3010/dev-login` (no password; local only) |
| `u4-approver@famboook.test` | SUPER_ADMIN | **Second user / approver** (O-3): `/login`, in a second browser profile or private window |
| `u4-viewer@famboook.test` | REPORTS_VIEWER + direct `civil-import.view` | Monitoring only: no execution, no publication |
| `u4-outsider@famboook.test` | ADMINISTRATOR | No civil import access: every screen is forbidden |

## 3. Build, launch, tear down (Git Bash, from the repository root)

```bash
"/c/Program Files/PostgreSQL/18/bin/pg_ctl.exe" -D C:/Users/Hamed-Pc/AppData/Local/famboook-pg-test/data -o "-p 5433" -l C:/Users/Hamed-Pc/AppData/Local/famboook-pg-test/server.log start -w
php deploy/local-acceptance/u4-setup.php            # first time; add --reset to rebuild from scratch
bash deploy/local-acceptance/u4.sh api              # terminal 1
bash deploy/local-acceptance/u4.sh ui               # terminal 2
bash deploy/local-acceptance/u4.sh worker           # terminal 3 (Ctrl+C stops it; --once = one iteration)
bash deploy/local-acceptance/u4.sh status           # gate state, sources
bash deploy/local-acceptance/u4.sh verify           # famboook:verify-permissions on the acceptance database
bash deploy/local-acceptance/u4.sh teardown         # drops famboook_u4_test, the u4 logins and the state directory
```

The worker of the automated test suites uses its own throwaway logins. Do **not** run a PostgreSQL test suite while the acceptance stack is running on the same cluster.

## 4. Synthetic sources (`%LOCALAPPDATA%/famboook-u4/sources`)

| Label | Content | Expected |
|---|---|---|
| `u4-export-a` | 2 files, 100 identities (900100001…) | the first release |
| `u4-export-b` | 2 files, 110 identities over A: 10 changed, 15 new, 5 missing | the second release; comparison 15 new / 10 changed / 5 carried forward |
| `u4-export-invalid-manifest` | the columns of the manifest are reversed | catalogued INVALID (`MANIFEST_COLUMNS_MISMATCH`), cannot be selected |
| `u4-export-tampered` | file 2 changed after its manifest | the manifest is VALID; validation fails (`SOURCE_INVALID`, one checksum or row-count problem) |

## 5. Publication gate (acceptance environment only)

- **Default:** closed.
  - Web: `CIVIL_IMPORT_PUBLISH_ENABLED=false`, so nobody holds `civil-import.publish.approve`.
  - Worker: closed, and there is no `IDENTITY_REF_PRODUCTION_APPROVAL`.
- **Open:** `bash deploy/local-acceptance/u4.sh gate open`.
  - It sets both gates to `true` in `web.env` / `worker.env`.
  - It sets `IDENTITY_REF_PRODUCTION_APPROVAL=U4-LOCAL-ACCEPTANCE` in `worker.env` only.
  - It re-seeds the role baseline, so SUPER_ADMIN gains `publish.approve`.
  - Then restart `api` and `worker`.
- **Close:** `bash deploy/local-acceptance/u4.sh gate close`, then restart. The script refuses to run unless both files target `5433/famboook_u4_test`.
- **Always close the gate** at the end of the session, before teardown.

## 6. Acceptance checklist

Record each case: **PASS / FAIL**, the time, and an observation (codes and counts only).

### A. Environment and boundaries
1. `u4.sh status`: both gates false, no authorization reference, four sources.
2. `u4.sh verify`: permission verification passes; it says `Civil import publication gate: DISABLED`.
3. The worker starts and logs `civil import worker u4-worker started`. On the overview the worker shows **IDLE** (or **BUSY**). With the worker stopped for more than 2 minutes it shows **STALE**.
4. As `famboook_u4_app`, the application login: `SELECT 1 FROM identity_ref.import_runs` fails with `42501`. Check with `psql -U famboook_u4_app`, using the password from `web.env`.

### B. Access and navigation
1. Sign in as `u4-outsider`: `/administration/civil-registry` is forbidden, and the card is missing on `/administration`.
2. Sign in as `u4-viewer`:
   - the overview, history, releases and approvals pages are visible;
   - there is no "استيراد جديد" button, no action button, and no audit link;
   - `/administration/civil-registry/imports/new` is forbidden.
3. As `dev`: every section, plus "استيراد جديد".

### C. Sources and validation
1. In the new-import wizard, step 1:
   - the four sources are listed by **label only**, never by path;
   - `u4-export-invalid-manifest` cannot be selected and shows "أعمدة البيان لا تطابق الأعمدة المعتمدة".
2. Choose `u4-export-tampered` and click "التحقق من الملفات":
   - the status goes QUEUED, then RUNNING, then FAILED, while polling;
   - the result shows one problem by code; no file name or path appears;
   - "التالي" stays disabled.
3. Choose `u4-export-a` and validate: the result is SUCCEEDED (2 files, 100 rows, no problems), and "التالي" is enabled.

### D. Staging, cancellation, resume, abort
1. Stage `u4-export-a`: tick the checkbox, confirm the dialog, and follow progress to the link "عرض العملية".
2. Run detail:
   - the phase is "تم التحميل";
   - the files are listed by number with their progress;
   - the actions are تقييم ومعاينة / تحضير الإصدار / إلغاء العملية.
3. Cancel while running. To make a run long enough, temporarily set `CIVIL_IMPORT_CHUNK_SIZE=1` in `worker.env`, or use `u4-export-b`.
   - "إيقاف التنفيذ" leads to CANCELLED, `CANCELLED_BY_OPERATOR`;
   - the phase becomes "توقف التحميل — قابل للاستئناف";
   - "استئناف التحميل" finishes with `resumed` = yes and no duplicates (rows = 100).
4. Worker failure: kill the worker process (Task Manager / Ctrl+C twice) while staging. After restarting it:
   - the run is resumable;
   - a request older than 10 minutes without a heartbeat becomes `WORKER_LOST` at the next start.
5. Optional: abort a run (choose a reason and acknowledge). The phase becomes ABORTED and the staging is purged; the active release is unchanged.

### E. Assessment, preparation, verification
1. تقييم ومعاينة: the result is publishable. Comparison: 100 new. There are no conflicts or warnings, or only expected warnings by code.
2. تحضير الإصدار: the phase becomes "تم التحضير", and a release appears under "الإصدارات" with policy `IDREF-POLICY-1`.
3. التحقق من الإصدار: the phase becomes "تم التحقق", and "طلب نشر" appears.

### F. Publication with the gate CLOSED (default): the active release must not change
1. As `dev`: "طلب نشر", choose a justification, then confirm. The request is "بانتظار الاعتماد" and the phase is "بانتظار اعتماد النشر".
2. As `u4-approver`:
   - the approvals page says "النشر على بيئة الإنتاج معطّل" and "بوابة النشر مغلقة";
   - the request shows **no** approve button.
3. The overview shows the active release unchanged (none yet). The automated proof: `CivilImportGateClosedInvariantPostgresTest`.

### G. Two-person rules (open the gate: §5)
1. **Same user:** `dev` opens their own request. It shows "هذا طلبك…", with no approve or reject button and only "سحب الطلب". A direct API call fails with `SAME_USER_APPROVAL`, which the automated tests cover.
2. **Reject:** `u4-approver` chooses "رفض" with a reason. The request becomes REJECTED and can never be approved or withdrawn afterwards.
3. **Withdraw:** `dev` opens a new request, then chooses "سحب الطلب". It becomes WITHDRAWN.
4. **Expiry:**
   - **Abort while pending:** abort the run while a request is pending. The request shows EXPIRED, `RELEASE_ABANDONED` (or `RELEASE_CHANGED`).
   - **Baseline change:** see H.3.
   - **24-hour timeout:** cannot be produced manually without changing the clock. It is verified by `CivilImportPublicationApiTest` (application time) and `CivilImportPublicationPostgresTest` (database clock).
5. **Confirmation:** in "اعتماد النشر", a wrong release number or expected presence fails with "رقم الإصدار أو الحضور المتوقع المُدخل لا يطابق الطلب". Confirm stays disabled until both are typed and the box is ticked.

### H. Publication with the gate OPEN (acceptance environment only)
1. `dev` requests publication; `u4-approver` approves with the correct numbers. The request becomes "معتمد — بانتظار التنفيذ". Within one worker cycle:
   - it becomes "نُشر";
   - the overview shows the active release with 100 records;
   - the run is "منشور".
2. **Second release:** stage, prepare and verify `u4-export-b`, then request and approve. The snapshot shows 15 new, 10 changed and 5 carried forward. After publication the active release is B (110 + 5 = 115 present).
3. **Baseline change:**
   - Request publication of a third candidate, then publish something else first, as an operator would from the shell:

     ```bash
     ( set -a; . "$LOCALAPPDATA/famboook-u4/web.env"; . <(grep '^IDENTITY_REF_DB_' "$LOCALAPPDATA/famboook-u4/worker.env"); set +a
       cd backend && php artisan famboook:identity-ref-stage --publish --release=<id> --target=famboook_u4_test --yes )
     ```
   - Expected: the pending request shows EXPIRED with `BASE_CHANGED`. An approved but not yet executed request ends FAILED with `BASE_CHANGED`.
4. **Missing authorization reference:**
   - Remove `IDENTITY_REF_PRODUCTION_APPROVAL` from `worker.env` and restart the worker; approve a request.
   - Expected: FAILED, "لا يوجد مرجع تفويض بالبيانات الحقيقية لدى العامل"; the active release is unchanged.
   - Restore the reference afterwards.
5. **Close the gate:** `u4.sh gate close`, then restart. `verify` shows DISABLED, and the approve buttons disappear.

### I. Audit and redaction
1. سجل التدقيق contains:
   - SOURCE_CATALOGUED;
   - REQUEST_QUEUED / STARTED / SUCCEEDED / FAILED / CANCELLED;
   - PUBLICATION_REQUESTED / APPROVED / REJECTED / WITHDRAWN / EXPIRED / REFUSED;
   - RELEASE_PUBLISHED;
   - LOCATIONS_VIEWED (after revealing file names).
2. No page, API response or audit row shows a national ID, a name, a path, a SHA value or the authorization reference. Browser devtools: inspect the JSON of `/api/v1/civil-registry/*`.
3. File names appear only after "عرض أسماء الملفات" (`locations.view`), and each view is audited.

### J. Teardown
1. `u4.sh gate close`, stop the three processes, `u4.sh teardown`. If no PostgreSQL suite follows, stop the cluster.

## 7. Linux-specific tests (target platform)

`tests/Feature/CivilRegistry/CivilImportWorkerProcessLinuxTest.php` is marked `RequiresOperatingSystemFamily('Linux')` and needs `pcntl` and `posix`. It is skipped on Windows. It runs the **real worker process**:
- SIGKILL mid-staging: the run stays resumable, the FBCIV01S advisory lock is released, the request becomes `WORKER_LOST`, and a resume completes without duplicates;
- SIGTERM: the current request finishes, then the worker exits 0 with the state STOPPED;
- a source replaced by a symlink after cataloguing is refused (`SOURCE_UNAVAILABLE`).

Also Linux-only (they skip on Windows):
- `CivilImportSourceCatalogueTest` (symlinked root, sources and files);
- `IdentitySourceValidationTest::test_symlinked_files_are_rejected`.

Run on a Linux machine with PostgreSQL 16+ and a disposable `*_test` database:

```bash
php vendor/bin/phpunit -c phpunit.pgsql.xml --display-skipped tests/Feature/CivilRegistry tests/Unit/IdentityRef
```

## 8. Approver-permission revocation gap and proposal

**Gap.** The two users are authorized when the request is created and when it is decided. The worker cannot re-check, at execution time, that they are still active and still hold their permissions, because the control role has no access to `users` or the permission tables.

Execution normally follows within one worker cycle (about 30 s). If the worker is down, the window lasts up to the 24 hours of the request. Today the only remedy is cancelling the queued PUBLISH request.

**Status: PROPOSED — not applied.** Nothing below exists in a migration, in `07` or in the worker. Applying it needs a separate architectural approval (a migration, a `07` change, a worker change and the tests in §8.6).

### 8.1 Function (application schema, created by a migration run as the application role)

The application schema is `public` (`config/database.php`, `search_path`). Spatie runs without teams, on guard `web`, with the default pivot columns (`model_id`, `role_id`, `permission_id`), and `model_type` holds the class name `App\Models\User` (Spatie roles are not in the morph map).

```sql
-- Must run as the application role (the owner of users, the Spatie tables and
-- civil_import_publication_requests), never as a superuser. In ONE transaction,
-- so the function is never executable by PUBLIC, not even for a moment.
BEGIN;

DO $$
BEGIN
    IF (SELECT rolsuper OR rolbypassrls FROM pg_roles WHERE rolname = current_user) THEN
        RAISE EXCEPTION 'CIVIL_IMPORT_AUTHZ_OWNER_MUST_NOT_BE_PRIVILEGED';
    END IF;
    IF (SELECT r.rolname FROM pg_class c JOIN pg_roles r ON r.oid = c.relowner
         WHERE c.oid = 'public.users'::regclass) <> current_user THEN
        RAISE EXCEPTION 'CIVIL_IMPORT_AUTHZ_OWNER_MUST_OWN_USERS';
    END IF;
END
$$;

CREATE OR REPLACE FUNCTION public.civil_import_publication_authorized(p_publication uuid)
RETURNS boolean
LANGUAGE plpgsql
VOLATILE                      -- it takes row locks (not allowed in a STABLE function)
STRICT                        -- NULL in, NULL out (the worker treats anything but true as a refusal)
SECURITY DEFINER
PARALLEL UNSAFE
SET search_path = pg_catalog, pg_temp   -- every object below is schema-qualified
SET lock_timeout = '5s'                 -- a blocked check fails closed instead of waiting
AS $fn$
DECLARE
    v_requested_by bigint;
    v_decided_by   bigint;
    v_active_users integer;
BEGIN
    -- 1. The request: APPROVED, decided by another user. FOR SHARE: it cannot
    --    change status while the publication runs.
    SELECT p.requested_by, p.decided_by
      INTO v_requested_by, v_decided_by
      FROM public.civil_import_publication_requests AS p
     WHERE p.id = p_publication
       AND p.status = 'APPROVED'
       AND p.decided_by IS NOT NULL
       AND p.decided_by <> p.requested_by
       FOR SHARE OF p;
    IF NOT FOUND THEN
        RETURN false;
    END IF;

    -- 2. Both users exist and are active. FOR SHARE blocks a concurrent
    --    deactivation (UPDATE) and the Staff administration row lock (FOR UPDATE)
    --    until this transaction ends. Ordered by id: a stable lock order.
    PERFORM 1
       FROM public.users AS u
      WHERE u.id IN (v_requested_by, v_decided_by)
        AND u.is_active IS TRUE
      ORDER BY u.id
        FOR SHARE OF u;
    GET DIAGNOSTICS v_active_users = ROW_COUNT;
    IF v_active_users <> 2 THEN
        RETURN false;
    END IF;

    -- 3. The requester still holds civil-import.publish.request THROUGH A ROLE.
    --    Every granting row is locked (FOR KEY SHARE): removing the role from the
    --    user, or the permission from the role, waits for this transaction.
    PERFORM 1
       FROM public.model_has_roles AS mr
       JOIN public.roles AS r                 ON r.id = mr.role_id AND r.guard_name = 'web'
       JOIN public.role_has_permissions AS rp ON rp.role_id = mr.role_id
       JOIN public.permissions AS perm        ON perm.id = rp.permission_id AND perm.guard_name = 'web'
      WHERE mr.model_type = 'App\Models\User'
        AND mr.model_id = v_requested_by
        AND perm.name = 'civil-import.publish.request'
        FOR KEY SHARE OF mr, rp;
    IF NOT FOUND THEN
        RETURN false;
    END IF;

    -- 4. The approver still holds civil-import.publish.approve THROUGH A ROLE.
    --    This permission exists only while the web publication gate is open.
    PERFORM 1
       FROM public.model_has_roles AS mr
       JOIN public.roles AS r                 ON r.id = mr.role_id AND r.guard_name = 'web'
       JOIN public.role_has_permissions AS rp ON rp.role_id = mr.role_id
       JOIN public.permissions AS perm        ON perm.id = rp.permission_id AND perm.guard_name = 'web'
      WHERE mr.model_type = 'App\Models\User'
        AND mr.model_id = v_decided_by
        AND perm.name = 'civil-import.publish.approve'
        FOR KEY SHARE OF mr, rp;
    IF NOT FOUND THEN
        RETURN false;
    END IF;

    RETURN true;
END
$fn$;

COMMENT ON FUNCTION public.civil_import_publication_authorized(uuid) IS
    'FB-CIVIL-01A-UI: true only while an APPROVED publication request still has an active, role-authorized requester and approver. Boolean only; locks the granting rows until the caller''s transaction ends.';

REVOKE ALL ON FUNCTION public.civil_import_publication_authorized(uuid) FROM PUBLIC;

COMMIT;
```

`model_type` is written as `'App\Models\User'`. This requires `standard_conforming_strings = on`, which is the default since PostgreSQL 9.1; the 07 assertion below checks it.

### 8.2 `07-control-plane.dba.sql` additions (DBA, inside its existing `DO` block, after the table grants)

```sql
    -- Approver eligibility (FB-CIVIL-01A-UI §8): EXECUTE on one boolean function, nothing else.
    IF to_regprocedure(format('%I.civil_import_publication_authorized(uuid)', app_schema)) IS NULL THEN
        RAISE EXCEPTION 'CIVIL_IMPORT_CONTROL_RUN_MIGRATIONS_FIRST';
    END IF;
    IF EXISTS (SELECT 1 FROM pg_proc f JOIN pg_roles o ON o.oid = f.proowner
                WHERE f.oid = to_regprocedure(format('%I.civil_import_publication_authorized(uuid)', app_schema))
                  AND (NOT f.prosecdef OR o.rolsuper OR o.rolbypassrls
                       OR o.oid <> (SELECT relowner FROM pg_class WHERE oid = format('%I.users', app_schema)::regclass)
                       OR NOT ('search_path=pg_catalog, pg_temp' = ANY (f.proconfig)))) THEN
        RAISE EXCEPTION 'CIVIL_IMPORT_AUTHZ_FUNCTION_UNSAFE';
    END IF;
    IF current_setting('standard_conforming_strings') <> 'on' THEN
        RAISE EXCEPTION 'CIVIL_IMPORT_AUTHZ_REQUIRES_STANDARD_STRINGS';
    END IF;
    EXECUTE format('REVOKE ALL ON FUNCTION %I.civil_import_publication_authorized(uuid) FROM PUBLIC', app_schema);
    EXECUTE format('REVOKE ALL ON FUNCTION %I.civil_import_publication_authorized(uuid) FROM famboook_civil_control_login', app_schema);
    EXECUTE format('GRANT EXECUTE ON FUNCTION %I.civil_import_publication_authorized(uuid) TO famboook_civil_control', app_schema);
    -- The control role must never read users or permissions directly (re-asserted on every run).
    EXECUTE format('REVOKE ALL ON %I.users, %I.roles, %I.permissions, %I.model_has_roles, %I.model_has_permissions, '
        || '%I.role_has_permissions FROM famboook_civil_control, famboook_civil_control_login',
        app_schema, app_schema, app_schema, app_schema, app_schema, app_schema);
    -- The locks taken by the function must not be held indefinitely by the control login.
    ALTER ROLE famboook_civil_control_login SET idle_in_transaction_session_timeout = '60s';
    ALTER ROLE famboook_civil_control_login SET transaction_timeout = '120s';   -- PostgreSQL 17+
```

`GRANT` is issued without `WITH GRANT OPTION`. The login inherits nothing (`NOINHERIT`), so it must call `SET ROLE famboook_civil_control` before calling the function, which is the same as for every other control-plane grant.

### 8.3 Worker change (for reference; PHP, not SQL)

In `IdentityImportWorker::publish()`, immediately after the "APPROVED by another user" check and before the gate, reference, window, baseline and release checks:

1. On the control connection, open a transaction: `BEGIN`.
2. Run `SELECT public.civil_import_publication_authorized(:id) AS ok`.
   - Anything other than `true` → `ROLLBACK`, then refuse `APPROVAL_NO_LONGER_AUTHORIZED`.
   - A `55P03` (`lock_not_available`) or `40P01` (deadlock) → the same refusal (fail closed).
3. Run the remaining checks and `publish_release()` on the import connection.
4. `COMMIT` the control transaction only after the import-side publication has committed or failed. The locks from step 2 cover the whole publication.

After that, `finish()` runs as today. A crash releases the locks when the connection drops, and `sweepPublications()` reconciles the publication request from the engine state.

### 8.4 Privilege-escalation risks

| # | Risk | Mitigation in the SQL above | Residual |
|---|---|---|---|
| P1 | The definer runs with the owner's rights; a superuser owner would turn any bug into superuser access | The migration refuses a `rolsuper`/`rolbypassrls` owner; `07` re-checks the owner on every run | None if `07` is run after each migration |
| P2 | `search_path` hijack: the caller shadows `users`, an operator or a function (e.g. a `pg_temp.users` table) | `SET search_path = pg_catalog, pg_temp`; every relation is `public.`-qualified; `pg_catalog` comes first, so built-in operators cannot be shadowed | None |
| P3 | A window in which `PUBLIC` holds `EXECUTE` (the PostgreSQL default for new functions) | `CREATE` and `REVOKE … FROM PUBLIC` in one transaction; `07` revokes again | None |
| P4 | Re-granting: the control role passes `EXECUTE` on | No `WITH GRANT OPTION`; `07` is idempotent and revokes first | None |
| P5 | Data exposure through the function | Returns one boolean; takes a publication-request id, not a user id; no dynamic SQL, no `RAISE` with data, no output parameters | A boolean oracle: "is this APPROVED request still authorized". The control role already sees the ids and statuses of these requests. |
| P6 | A compromised control login uses the locks to delay revocations (denial of service against deactivation) | `lock_timeout` 5 s inside the function; `idle_in_transaction_session_timeout` 60 s and `transaction_timeout` 120 s on the login; `CONNECTION LIMIT 4` | A revocation can be delayed by up to about 120 s per transaction. A determined attacker can repeat this; it shows up in `pg_stat_activity` and in the worker heartbeat. |
| P7 | The owner (the application role) replaces the function with one that returns `true` | Out of the function's reach: the application role already owns the tables. The worker's own gate and `IDENTITY_REF_PRODUCTION_APPROVAL` remain independent. `07` checks the function's properties, not its body. | Accepted: the application role is trusted for its own schema |
| P8 | The control role gains write access to users or permissions | The function has no `INSERT`/`UPDATE`/`DELETE`; row locks are not writes; `07` revokes every table privilege on `users` and the Spatie tables from both control roles | None |
| P9 | Direct permission grants (`model_has_permissions`) bypassing the role-only rule | Only role-derived permissions are counted; a direct grant gives `false` (the verifier already flags it as CRITICAL) | None |

### 8.5 Race conditions

The function's guarantee holds at one instant. When it returns `true`, the request row, both user rows and every granting role row are locked by the caller's transaction, and every condition held at that moment. Each statement in plpgsql takes a new snapshot under READ COMMITTED. Rows checked earlier are already locked, so they cannot change before the last check runs.

| # | Interleaving | Outcome |
|---|---|---|
| R1 | Revocation (role removal, deactivation, gate close and re-seed) commits before the check | The check sees it → `false` → refused |
| R2 | Revocation in progress (uncommitted) when the check runs | The check waits for the row lock. If the revocation commits, the locked row is re-evaluated (READ COMMITTED re-check) or is gone → `false`. If it takes longer than 5 s → `55P03` → refused (fail closed). |
| R3 | Revocation starts after the check, during `publish_release()` | Blocked by `FOR SHARE` (users) or `FOR KEY SHARE` (role rows) until the control transaction commits. The publication is linearized before the revocation; the revocation applies to every later publication. |
| R4 | Staff administration (`ManageStaffUsersAction`: users `FOR UPDATE`, then `syncRoles`) at the same time | Same lock order as the function (users, then role rows), so no deadlock with this path. Any other deadlock is detected by PostgreSQL (`40P01`) → refused. |
| R5 | Withdrawal, expiry or a second decision on the request at the same time | The request row is `FOR SHARE`; a status change waits. The approval trigger and the one-PUBLISH-per-request index are unchanged. |
| R6 | The worker crashes while it holds the locks | The connection drops and the locks are released. The import-side publication either committed or did not; `sweepPublications()` reconciles (unchanged). |
| R7 | The import-side publication takes longer than `transaction_timeout` (120 s) | The control transaction is terminated, the locks are released, and the publication itself continues on the import connection. `transaction_timeout` must therefore exceed the longest publication (measure it; the current local `publish_release()` is sub-second). Otherwise R3 is not covered for the remainder. |
| R8 | A grant made after the check | Irrelevant: it only adds authority |

### 8.6 PostgreSQL isolation tests (real LOGIN sessions, `phpunit.pgsql.xml`, synthetic data)

Fixture: requester A and approver B, both SUPER_ADMIN; the web gate is open and the roles are re-seeded; publication request P is APPROVED by B. "Control" means a session as `famboook_civil_control_login` after `SET ROLE famboook_civil_control`. "App" means the application role on a second PDO connection.

| # | Test | Expected |
|---|---|---|
| 1 | Control: `SELECT public.civil_import_publication_authorized(P)` | `true` |
| 2 | App removes SUPER_ADMIN from B (`model_has_roles` DELETE), then the control check | `false` |
| 3 | App deactivates A (`is_active = false`); separately, deactivates B | `false` in both cases |
| 4 | Close the web gate and re-seed (`publish.approve` removed from SUPER_ADMIN) | `false` |
| 5 | B loses the role but receives `civil-import.publish.approve` directly (`model_has_permissions`) | `false` (role-derived only) |
| 6 | P is PENDING, REJECTED, EXECUTED or FAILED; an unknown uuid; NULL | `false`, `false`, `false`, `false`, `false`, NULL |
| 7 | Control: `SELECT 1 FROM users`, `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` | `42501` on each |
| 8 | Monitor login, import login, and a non-owner application login: call the function | `42501`; `has_function_privilege('public', …, 'EXECUTE')` is false |
| 9 | Catalog: `prosecdef` true; `provolatile = 'v'`; `proconfig` contains `search_path=pg_catalog, pg_temp` and `lock_timeout=5s`; owner = the owner of `users`, not superuser; `proacl` = owner plus `famboook_civil_control=X` exactly | All match |
| 10 | Hijack: control session `CREATE TEMP TABLE users (id bigint, is_active boolean)` with fake rows, `SET search_path = pg_temp, public`, deactivate B for real | `false` (the fake table is ignored) |
| 11 | Race R2: App `BEGIN; DELETE` B's `model_has_roles` row (uncommitted); control calls the function (blocks); App `COMMIT` | The control call returns `false` |
| 12 | Race R2 timeout: App holds the uncommitted DELETE for more than 5 s | Control gets `55P03` |
| 13 | Race R3: control `BEGIN`, check → `true`; App `SET lock_timeout = '1s'`; DELETE B's role row | App gets `55P03`. After control `COMMIT`, the App retry succeeds and a new check returns `false`. |
| 14 | Race R3 with deactivation: as #13, using `UPDATE users SET is_active = false` and `ManageStaffUsersAction` (`FOR UPDATE`) | Both wait; neither deadlocks |
| 15 | `transaction_timeout`: control `BEGIN`, check, then stay idle for longer than the limit (with a shortened limit set in the test session) | The session is terminated; the App DELETE then succeeds immediately |
| 16 | `07` re-run twice | Idempotent; the same `proacl`; no new table privilege for the control roles |
| 17 | Worker end-to-end: approve, revoke B's role, run `--once` | `APPROVAL_NO_LONGER_AUTHORIZED`, `PUBLICATION_REFUSED` audited, publication request FAILED, active release unchanged |
| 18 | Worker end-to-end, R3: the test hook revokes during `publish.*` | The revocation waits; the publication completes (EXECUTED); the next check returns `false` |

**Rejected alternatives:**
- **SELECT on `users` and the permission tables for the control role:** too broad, as it exposes names and e-mails.
- **A permission snapshot at approval:** does not detect revocation.
- **Checking only in the web process:** the worker is the last gate (S-3).
- **`SERIALIZABLE` instead of row locks:** cannot span the two connections (control and import).
- **A STABLE function without locks:** leaves the R3 window open for the whole publication.

## 9. Execution record (2026-10-26)

The scenario was executed by `deploy/local-acceptance/u4-acceptance.php`:
- It drives the real API with real staff sessions, the real worker process (a hard kill included) and the acceptance database.
- Results are in `%LOCALAPPDATA%/famboook-u4/u4-results.json`.

**Final run (fresh environment, `APP_DEBUG=false`):** 64 checks: **64 PASS, 0 FAIL, 1 BLOCKED**.
- **B.4 BLOCKED:** a second `next dev` could not run from the same `frontend` directory while another one was already running on :3000. That one was not started by this run and was not stopped. Screen behaviour was covered by Vitest.

**B.4 resolved (gap closure, same day).**
- **Root cause:** not the port. Next 16 `next dev` takes an exclusive lock at `<distDir>/lock` (`.next/lock`), so only one dev server can run per build-output directory.
- **Fix:**
  - `next.config.ts` accepts `FAMBOOOK_NEXT_DIST_DIR`, restricted to a sub-folder of `.next/`; unset, nothing changes;
  - `u4.sh ui` uses `.next/u4-acceptance`;
  - `next dev` rewrites `tsconfig.json` and `next-env.d.ts`, so `u4.sh` keeps the developer's copies and restores them on exit (or with `u4.sh restore-frontend`).
- **Verified:** :3010 (U4) and :3000 (the developer's server, untouched) answered at the same time.

**Visual verification (headless Microsoft Edge driven over the DevTools protocol, `deploy/local-acceptance/u4-visual.mjs`).** Screenshots and the facts collected from the live DOM are in `%LOCALAPPDATA%/famboook-u4/visual/`. The screenshots were inspected.
- **Routes:**
  - overview, `imports`, `imports/new`, `approvals`, an approval detail, a run detail, `audit`;
  - signed in as the requester, the approver, the viewer and the outsider.
- **Layout:** every page has `dir=rtl` and `lang=ar`, the active tab and the sidebar item are marked, and there is no horizontal overflow.
- **Permissions:**
  - the viewer has no audit tab and no "New import" button, and gets the "no access" panel on `imports/new` and `audit`;
  - the outsider gets the "no access" panel on the overview and on `approvals`.
- **Indicators:**
  - the publication gate reads "مغلقة", and `approvals` shows "النشر على بيئة الإنتاج معطّل.";
  - the worker card shows the live state.
- **Loading, empty and error states:**
  - loading skeletons (with network latency emulated);
  - the empty states of filtered `imports` and `approvals`;
  - the error state with the monitor credentials missing ("لم تُضبط متابعة استيراد السجل المدني…" plus a retry button, with no technical detail).
- **Found and fixed:** the worker's `IDLE` label read "متوقف (جاهز)" ("stopped") while the worker loop was running. It now reads "يعمل — بانتظار الطلبات".

**Defects found by the acceptance, and fixed:**
1. **The overview never showed the worker's real state** (it was always NOT_INSTALLED; U2). The heartbeat is now read; there is a regression test.
2. **The publication approval window depended on the database session's time zone** (U3):
   - the trigger compared the database clock with naive UTC `expires_at`, so on an `Asia/Jerusalem` cluster requests "expired" 2 hours early;
   - fixed by migration `2026_10_26_120000_fix_civil_import_publication_window_clock` (UTC comparison, UTC defaults), with a regression test in three session time zones.
3. **An unreachable control database was reported as `CONTROL_LOGIN_REFUSED`;** it is now `CONTROL_UNREACHABLE`.

**Environment adjustments (not product defects):**
- The worker's clean environment on Windows needs `SYSTEMROOT`.
- The acceptance `web.env` now sets `APP_DEBUG=false`, as Production. With the local `.env` value (true), Laravel's debug payload of a 403 carried file paths.

## 10. Known limits of the local environment

- **Windows instead of Linux and systemd:** the account separation (`famboook-import`), `ProtectSystem`, `InaccessiblePaths`, signals and symlinks are not exercised. See §7.
- **Clean environment on Windows:** the worker's clean environment needs `SYSTEMROOT` for Windows sockets (`u4.sh` passes it). It is irrelevant on Linux.
- **Visual pass in headless Edge at 1440 px only:** phone width, screen readers and keyboard navigation were not exercised by the automation.
- **`php artisan serve`:** it is single-threaded. Polling every 5 s plus manual use is fine for one tester.
