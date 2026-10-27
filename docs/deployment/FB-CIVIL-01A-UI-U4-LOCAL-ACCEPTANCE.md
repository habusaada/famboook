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
| `dev@famboook.test` | SUPER_ADMIN | **Staging operator**: sign in at `http://localhost:3010/dev-login` (no password; local only). It stages, prepares and verifies; having no known password, it can never confirm a publication |
| `u4-operator@famboook.test` | SUPER_ADMIN | **Publication operator (D-UI-12a)**: `/login`, in a second browser profile or private window. Requests **and** confirms publication with their password; there is no second approver |
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
  - Web: `CIVIL_IMPORT_PUBLISH_ENABLED=false`, so nobody holds `civil-import.publish.confirm`.
  - Worker: closed, and there is no `IDENTITY_REF_PRODUCTION_APPROVAL`.
- **Open:** `bash deploy/local-acceptance/u4.sh gate open`.
  - It sets both gates to `true` in `web.env` / `worker.env`.
  - It sets `IDENTITY_REF_PRODUCTION_APPROVAL=U4-LOCAL-ACCEPTANCE` in `worker.env` only.
  - It re-seeds the role baseline, so SUPER_ADMIN gains `publish.confirm`.
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
1. As `u4-operator`: "طلب نشر", choose a justification, then confirm. The page opens the request: "بانتظار التأكيد"; the run phase is "بانتظار تأكيد النشر".
2. Still as `u4-operator`:
   - the page says "النشر على بيئة الإنتاج معطّل" and "بوابة النشر مغلقة";
   - the request shows "هذا طلبك: لا يمكن تأكيده ما دامت بوابة النشر مغلقة." and **no** "تأكيد النشر" button, only "سحب الطلب";
   - a direct API confirmation fails with 403, even with the right password (nobody holds the permission).
3. The overview shows the active release unchanged (none yet). The automated proof: `CivilImportGateClosedInvariantPostgresTest`.

### G. Single-operator rules (open the gate: §5)
1. **Only the requester confirms:** `dev` (another SUPER_ADMIN) opening the operator's request sees "يؤكد هذا الطلبَ مقدّمُه فقط." and no button; a direct API call fails with 403 `CONFIRMATION_BY_REQUESTER_ONLY`. `u4-outsider` and `u4-viewer` get 403.
2. **Password re-authentication:**
   - In "تأكيد النشر", a wrong password fails with "كلمة المرور غير صحيحة؛ لم يُؤكَّد النشر." The field is cleared, and the failure is audited as `PUBLICATION_REAUTH_FAILED` (never the password).
   - After five wrong passwords, confirmation is locked for 15 minutes: "محاولات كثيرة بكلمة مرور غير صحيحة…" (429). The automated run checks this on `dev`'s own request, so the operator is never locked out.
3. **Withdraw:** "سحب الطلب" closes the request (WITHDRAWN); it can never be confirmed or withdrawn again.
4. **Expiry:**
   - **Abort while pending:** abort the run while a request is pending. The request shows EXPIRED, `RELEASE_ABANDONED` (or `RELEASE_CHANGED`).
   - **Window passed:** the automated run inserts a request with a 2-second window (as the DBA), waits, and confirms: 409 `PUBLICATION_EXPIRED`, EXPIRED `TIMEOUT`. The 24-hour value itself is verified by `CivilImportPublicationApiTest` and `CivilImportPublicationPostgresTest`.
   - **Baseline change:** see H.3.
5. **Confirmation:** the dialog shows the release number and "عدد السجلات المتحقَّق منها". A wrong typed number fails with "رقم الإصدار أو عدد السجلات المتحقَّق منها المُدخل لا يطابق الطلب." "تأكيد النشر" stays disabled until both numbers and the password are entered and the box is ticked.
6. **Immutable history:** audit events and publication requests cannot be changed or deleted, even by the database superuser (`23001`).

### H. Publication with the gate OPEN (acceptance environment only)
1. `u4-operator` requests publication and confirms it with the correct numbers and password. The request becomes "مؤكَّد — بانتظار التنفيذ"; a repeated confirmation is refused (409) and exactly one PUBLISH request exists. Within one worker cycle:
   - it becomes "نُشر";
   - the overview shows the active release with 100 records;
   - the run is "منشور".
2. **Second release:** stage and prepare `u4-export-b`. Before verification a publication request is refused (409 `PHASE_NOT_ALLOWED`). Verify, then request and confirm. The snapshot shows 15 new, 10 changed and 5 carried forward. After publication the active release is B (110 + 5 = 115 present).
   - **Concurrency (automated):** two confirmations are sent at once while another database session holds the request row. Both wait, one wins (202), the other is refused (409), and exactly one PUBLISH request exists. `php artisan serve` handles one request at a time, so the true database race is proven by `CivilImportPublicationPostgresTest` (two sessions).
3. **Baseline change:**
   - Request publication of a third candidate, then publish something else first, as an operator would from the shell:

     ```bash
     ( set -a; . "$LOCALAPPDATA/famboook-u4/web.env"; . <(grep '^IDENTITY_REF_DB_' "$LOCALAPPDATA/famboook-u4/worker.env"); set +a
       cd backend && php artisan famboook:identity-ref-stage --publish --release=<id> --target=famboook_u4_test --yes )
     ```
   - Expected: the pending request shows EXPIRED with `BASE_CHANGED`. A confirmed but not yet executed request ends FAILED with `BASE_CHANGED`.
4. **Missing authorization reference:**
   - Remove `IDENTITY_REF_PRODUCTION_APPROVAL` from `worker.env` and restart the worker; confirm a request.
   - Expected: FAILED, "لا يوجد مرجع تفويض بالبيانات الحقيقية لدى العامل"; the active release is unchanged.
   - Restore the reference afterwards.
5. **Close the gate:** `u4.sh gate close`, then restart. `verify` shows DISABLED; the operator's own new request shows no "تأكيد النشر" button and a direct confirmation fails with 403.

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

## 8. Confirmer-eligibility gap and proposal (single operator, D-UI-12a)

**Policy (D-UI-12a, 2026-10-27).** A SUPER_ADMIN may request, confirm and publish the same release without a second approver. The requester confirms their **own** request after re-entering their password. There is no second approver and no approver role. The former two-person version of this proposal (two users, `publish.approve`) is withdrawn.

**Gap.** The operator is authorized when they request and when they confirm (the action locks their user row, re-checks `is_active` and the SUPER_ADMIN role, and the confirmation needs the gated `civil-import.publish.confirm`). The worker cannot re-check, **at execution time**, that this one user is still active and still authorized, because the control role has no access to `users` or the permission tables.

Execution normally follows within one worker cycle (about 30 s). If the worker is down, the window lasts up to the 24 hours of the request. Today the only remedy is cancelling the queued PUBLISH request.

**Status: PROPOSED — not applied.** Nothing below exists in a migration, in `07` or in the worker. Applying it needs a separate architectural approval (a migration, a `07` change, a worker change and the tests in §8.6).

### 8.1 Function (application schema, created by a migration run as the application role)

The application schema is `public` (`config/database.php`, `search_path`). Spatie runs without teams, on guard `web`, with the default pivot columns (`model_id`, `role_id`, `permission_id`), and `model_type` holds the class name `App\Models\User` (Spatie roles are not in the morph map). The role name and both permission names mirror the action: SUPER_ADMIN, `civil-import.publish.request`, `civil-import.publish.confirm`.

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
    v_operator bigint;
BEGIN
    -- 1. The request: APPROVED, i.e. confirmed by its own requester (D-UI-12a; the database
    --    guard already refuses any other confirmation). FOR SHARE: it cannot change status
    --    while the publication runs.
    SELECT p.requested_by
      INTO v_operator
      FROM public.civil_import_publication_requests AS p
     WHERE p.id = p_publication
       AND p.status = 'APPROVED'
       AND p.decided_by IS NOT NULL
       AND p.decided_by = p.requested_by
       FOR SHARE OF p;
    IF NOT FOUND THEN
        RETURN false;
    END IF;

    -- 2. The operator still exists and is active. FOR SHARE blocks a concurrent deactivation
    --    (UPDATE) and the Staff administration row lock (FOR UPDATE) until this transaction ends.
    PERFORM 1
       FROM public.users AS u
      WHERE u.id = v_operator
        AND u.is_active IS TRUE
        FOR SHARE OF u;
    IF NOT FOUND THEN
        RETURN false;
    END IF;

    -- 3. The operator still holds the SUPER_ADMIN role, and through it BOTH
    --    civil-import.publish.request and civil-import.publish.confirm (the latter exists only
    --    while the web publication gate is open). Every granting row is locked (FOR KEY SHARE):
    --    removing the role from the user, or a permission from the role, waits for this transaction.
    PERFORM 1
       FROM public.model_has_roles AS mr
       JOIN public.roles AS r                 ON r.id = mr.role_id AND r.guard_name = 'web' AND r.name = 'SUPER_ADMIN'
       JOIN public.role_has_permissions AS rp ON rp.role_id = mr.role_id
       JOIN public.permissions AS perm        ON perm.id = rp.permission_id AND perm.guard_name = 'web'
      WHERE mr.model_type = 'App\Models\User'
        AND mr.model_id = v_operator
        AND perm.name IN ('civil-import.publish.request', 'civil-import.publish.confirm')
        FOR KEY SHARE OF mr, rp;
    IF (SELECT count(DISTINCT perm.name)
          FROM public.model_has_roles AS mr
          JOIN public.roles AS r                 ON r.id = mr.role_id AND r.guard_name = 'web' AND r.name = 'SUPER_ADMIN'
          JOIN public.role_has_permissions AS rp ON rp.role_id = mr.role_id
          JOIN public.permissions AS perm        ON perm.id = rp.permission_id AND perm.guard_name = 'web'
         WHERE mr.model_type = 'App\Models\User'
           AND mr.model_id = v_operator
           AND perm.name IN ('civil-import.publish.request', 'civil-import.publish.confirm')) <> 2 THEN
        RETURN false;
    END IF;

    RETURN true;
END
$fn$;

COMMENT ON FUNCTION public.civil_import_publication_authorized(uuid) IS
    'FB-CIVIL-01A-UI D-UI-12a: true only while an APPROVED publication request, confirmed by its requester, still has that requester active and role-authorized (SUPER_ADMIN, publish.request and publish.confirm). Boolean only; locks the granting rows until the caller''s transaction ends.';

REVOKE ALL ON FUNCTION public.civil_import_publication_authorized(uuid) FROM PUBLIC;

COMMIT;
```

`model_type` is written as `'App\Models\User'`. This requires `standard_conforming_strings = on`, which is the default since PostgreSQL 9.1; the 07 assertion below checks it. The second statement of step 3 counts the permissions already locked by the first, in the same transaction, so both see the same rows.

### 8.2 `07-control-plane.dba.sql` additions (DBA, inside its existing `DO` block, after the table grants)

```sql
    -- Confirmer eligibility (FB-CIVIL-01A-UI §8, D-UI-12a): EXECUTE on one boolean function, nothing else.
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

In `IdentityImportWorker::publish()`, immediately after the "APPROVED and confirmed by its requester" check and before the gate, reference, window, baseline and release checks:

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
| P5 | Data exposure through the function | Returns one boolean; takes a publication-request id, not a user id; no dynamic SQL, no `RAISE` with data, no output parameters | A boolean oracle: "is this confirmed request's operator still authorized". The control role already sees the ids and statuses of these requests. |
| P6 | A compromised control login uses the locks to delay revocations (denial of service against deactivation) | `lock_timeout` 5 s inside the function; `idle_in_transaction_session_timeout` 60 s and `transaction_timeout` 120 s on the login; `CONNECTION LIMIT 4` | A revocation can be delayed by up to about 120 s per transaction. A determined attacker can repeat this; it shows up in `pg_stat_activity` and in the worker heartbeat. |
| P7 | The owner (the application role) replaces the function with one that returns `true` | Out of the function's reach: the application role already owns the tables. The worker's own gate and `IDENTITY_REF_PRODUCTION_APPROVAL` remain independent. `07` checks the function's properties, not its body. | Accepted: the application role is trusted for its own schema |
| P8 | The control role gains write access to users or permissions | The function has no `INSERT`/`UPDATE`/`DELETE`; row locks are not writes; `07` revokes every table privilege on `users` and the Spatie tables from both control roles | None |
| P9 | Direct permission grants (`model_has_permissions`) or another role bypassing the SUPER_ADMIN-only rule | Only permissions held **through the SUPER_ADMIN role** are counted; a direct grant or another role gives `false` (the verifier already flags direct grants as CRITICAL) | None |
| P10 | Single operator: one compromised SUPER_ADMIN account can request and confirm a publication alone | Accepted by policy D-UI-12a. Mitigated outside this function: fresh password per confirmation (audited, 5 failures per 15 minutes), typed release id and record count, both publication gates, the worker's real-data authorization reference, the 24-hour window and the immutable audit trail. This function only narrows the revocation window. | Accepted risk (owner policy) |

### 8.5 Race conditions

The function's guarantee holds at one instant. When it returns `true`, the request row, the operator's user row and every granting role row are locked by the caller's transaction, and every condition held at that moment. Each statement in plpgsql takes a new snapshot under READ COMMITTED. Rows checked earlier are already locked, so they cannot change before the last check runs.

| # | Interleaving | Outcome |
|---|---|---|
| R1 | Revocation (role removal, deactivation, gate close and re-seed) commits before the check | The check sees it → `false` → refused |
| R2 | Revocation in progress (uncommitted) when the check runs | The check waits for the row lock. If the revocation commits, the locked row is re-evaluated (READ COMMITTED re-check) or is gone → `false`. If it takes longer than 5 s → `55P03` → refused (fail closed). |
| R3 | Revocation starts after the check, during `publish_release()` | Blocked by `FOR SHARE` (user) or `FOR KEY SHARE` (role rows) until the control transaction commits. The publication is linearized before the revocation; the revocation applies to every later publication. |
| R4 | Staff administration (`ManageStaffUsersAction`: user `FOR UPDATE`, then `syncRoles`) at the same time | Same lock order as the function (user, then role rows), so no deadlock with this path. Any other deadlock is detected by PostgreSQL (`40P01`) → refused. |
| R5 | Withdrawal, expiry or a second confirmation on the request at the same time | The request row is `FOR SHARE`; a status change waits. The confirmation-by-requester guard and the one-PUBLISH-per-request index are unchanged. |
| R6 | The worker crashes while it holds the locks | The connection drops and the locks are released. The import-side publication either committed or did not; `sweepPublications()` reconciles (unchanged). |
| R7 | The import-side publication takes longer than `transaction_timeout` (120 s) | The control transaction is terminated, the locks are released, and the publication itself continues on the import connection. `transaction_timeout` must therefore exceed the longest publication (measure it; the current local `publish_release()` is sub-second). Otherwise R3 is not covered for the remainder. |
| R8 | A grant made after the check | Irrelevant: it only adds authority |

### 8.6 PostgreSQL isolation tests (real LOGIN sessions, `phpunit.pgsql.xml`, synthetic data)

Fixture: operator O (SUPER_ADMIN, active); the web gate is open and the roles are re-seeded; publication request P is requested **and confirmed by O** (APPROVED, `decided_by = requested_by`). "Control" means a session as `famboook_civil_control_login` after `SET ROLE famboook_civil_control`. "App" means the application role on a second PDO connection.

| # | Test | Expected |
|---|---|---|
| 1 | Control: `SELECT public.civil_import_publication_authorized(P)` | `true` |
| 2 | App removes SUPER_ADMIN from O (`model_has_roles` DELETE), then the control check | `false` |
| 3 | App deactivates O (`is_active = false`) | `false` |
| 4 | Close the web gate and re-seed (`publish.confirm` removed from SUPER_ADMIN) | `false` |
| 5 | O loses the role but receives both permissions directly (`model_has_permissions`), or through another role | `false` (SUPER_ADMIN role only) |
| 6 | P is PENDING, WITHDRAWN, EXECUTED or FAILED; an APPROVED row decided by another user (legacy, guards disabled in the test); an unknown uuid; NULL | `false` for each; NULL for NULL |
| 7 | Control: `SELECT 1 FROM users`, `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` | `42501` on each |
| 8 | Monitor login, import login, and a non-owner application login: call the function | `42501`; `has_function_privilege('public', …, 'EXECUTE')` is false |
| 9 | Catalog: `prosecdef` true; `provolatile = 'v'`; `proconfig` contains `search_path=pg_catalog, pg_temp` and `lock_timeout=5s`; owner = the owner of `users`, not superuser; `proacl` = owner plus `famboook_civil_control=X` exactly | All match |
| 10 | Hijack: control session `CREATE TEMP TABLE users (id bigint, is_active boolean)` with fake rows, `SET search_path = pg_temp, public`, deactivate O for real | `false` (the fake table is ignored) |
| 11 | Race R2: App `BEGIN; DELETE` O's `model_has_roles` row (uncommitted); control calls the function (blocks); App `COMMIT` | The control call returns `false` |
| 12 | Race R2 timeout: App holds the uncommitted DELETE for more than 5 s | Control gets `55P03` |
| 13 | Race R3: control `BEGIN`, check → `true`; App `SET lock_timeout = '1s'`; DELETE O's role row | App gets `55P03`. After control `COMMIT`, the App retry succeeds and a new check returns `false`. |
| 14 | Race R3 with deactivation: as #13, using `UPDATE users SET is_active = false` and `ManageStaffUsersAction` (`FOR UPDATE`) | Both wait; neither deadlocks |
| 15 | `transaction_timeout`: control `BEGIN`, check, then stay idle for longer than the limit (with a shortened limit set in the test session) | The session is terminated; the App DELETE then succeeds immediately |
| 16 | `07` re-run twice | Idempotent; the same `proacl`; no new table privilege for the control roles |
| 17 | Worker end-to-end: O confirms, App revokes O's role, run `--once` | `APPROVAL_NO_LONGER_AUTHORIZED`, `PUBLICATION_REFUSED` audited, publication request FAILED, active release unchanged |
| 18 | Worker end-to-end, R3: the test hook revokes during `publish.*` | The revocation waits; the publication completes (EXECUTED); the next check returns `false` |

**Rejected alternatives:**
- **SELECT on `users` and the permission tables for the control role:** too broad, as it exposes names and e-mails.
- **A permission snapshot at confirmation:** does not detect revocation.
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

### 9.1 Single-operator alignment run (2026-10-27, D-UI-12a)

The acceptance tooling was aligned with D-UI-12a (commits `c66d85d`, `f7b04fc` and this alignment):
- `u4-operator` (SUPER_ADMIN, password) is both requester and confirmer; `u4-approver` no longer exists;
- `dev` (another SUPER_ADMIN, no known password) stages and prepares, and serves as the "not the requester" case;
- the acceptance `web.env` uses `CACHE_STORE=database`, as Production, so the re-authentication lockout survives between requests.

**Environment:** rebuilt with `u4-setup.php --reset`. A rebuild was technically necessary: section F asserts that no release is active, which a previous run leaves behind, and the new account, cache setting and migration must exist. Synthetic data only.

**Result:** `u4-acceptance.php`, 73 checks: **73 PASS, 0 FAIL, 0 BLOCKED**. The single-operator checks:
- **B.2:** with the gate closed, the operator cannot confirm even with the right password (403); outsider and viewer 403; `/approve` and `/reject` are gone (404).
- **F.2:** the operator's own request shows `can_confirm=false`; confirmation 403 for the operator and for `dev`; the request stays pending.
- **G.1:** another SUPER_ADMIN gets 403 `CONFIRMATION_BY_REQUESTER_ONLY`; outsider and viewer 403.
- **G.2:** a wrong password gets 422 `REAUTH_FAILED`, audited and never echoed; a missing password 422; nothing queued.
- **G.2b:** five wrong passwords get 422 each, then 429 `REAUTH_LOCKED`; five audited failures.
- **G.3:** a withdrawn request is never confirmable or withdrawable again, and spends no password attempt.
- **G.4:** a request whose window has passed gets 409 `PUBLICATION_EXPIRED` and is EXPIRED `TIMEOUT`.
- **G.5:** a wrong typed release id or record count gets 422 `CONFIRMATION_MISMATCH`.
- **G.6:** audit events and publication requests cannot be changed or deleted, even by the superuser (`23001`).
- **H.1:** the operator confirms (202); requester and confirmer are the same user; a repeated confirmation gets 409 and exactly one PUBLISH request exists; release A is published atomically.
- **H.2:** before verification, 409 `PHASE_NOT_ALLOWED`; two concurrent confirmations behind a held row lock give 202 and 409 with exactly one PUBLISH request; release B is published.
- **H.3a/b, H.4:** baseline changes and a missing authorization reference are refused as before.
- **H.5:** after the gate closes, the operator's own new request cannot be confirmed (403).
- **I.1:** `PUBLICATION_CONFIRMED` and `PUBLICATION_REAUTH_FAILED` are present.
- **I.2:** no identity value or password appears in 239 API responses or in the control-plane rows.

**Visual (`u4-visual.mjs`, headless Edge):**
- the main pass is unchanged for dev, viewer and outsider, and adds the operator's history and request detail;
- `--confirm-dialog --release=6` (gate opened for the pass and closed again; the request was withdrawn, never submitted) shows the dialog in RTL with the release id (6), the verified record count (115), a `current-password` field and a disabled submit button;
- one layout defect was found and fixed: the record count overflowed the two-column summary box in the narrow dialog, which now uses one column.

## 10. Known limits of the local environment

- **Windows instead of Linux and systemd:** the account separation (`famboook-import`), `ProtectSystem`, `InaccessiblePaths`, signals and symlinks are not exercised. See §7.
- **Clean environment on Windows:** the worker's clean environment needs `SYSTEMROOT` for Windows sockets (`u4.sh` passes it). It is irrelevant on Linux.
- **Visual pass in headless Edge at 1440 px only:** phone width, screen readers and keyboard navigation were not exercised by the automation.
- **`php artisan serve`:** it is single-threaded. Polling every 5 s plus manual use is fine for one tester.
