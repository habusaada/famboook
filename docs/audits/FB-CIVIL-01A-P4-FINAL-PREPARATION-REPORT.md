# FB-CIVIL-01A-P4 — Final Production Readiness Report

**Date:** 2026-10-25.

**Scope:**
- Local preparation only. **No Production connection** was made: Claude Code has no SSH access.
- Nothing was deployed, imported, committed or pushed.

**Supersedes:** `FB-CIVIL-01A-P4-LOCAL-PREPARATION-REPORT.md`.

**Operator instructions:** `docs/deployment/FB-CIVIL-01A-P4-OPERATOR-CHECKLIST.md` (the final runbook).

## 1. Production evidence review

| Evidence on record (operator audit 2026-10-21) | Assessment |
|---|---|
| PHP 8.3.35, Laravel 12.69.2, `pdo_pgsql` and `pcntl` present | Sufficient; not re-checked |
| Queue and cache `database`; no worker, scheduler or cron | Irrelevant to the import (artisan only); not re-checked |
| Backup 2026-10-21, archive catalog PASS | **Outdated:** a fresh backup is required (P3) |
| Restore rehearsal waived; last successful restore **unverified** | **Material:** gate G5 |
| Production HEAD `bb6cb0d` | **Possibly outdated** (FB-NOTIFY release planned after it): D0-1 |

**Missing facts that materially affect deployment safety.** These are the only items left to inspect (runbook D0):

| # | Fact | Why it matters |
|---|---|---|
| D0-1 | Current HEAD, migrations, clean tree | Deployment base |
| D0-2 | PostgreSQL major version | ≥ 16 required; 16 → rehearsal on 16 (G9) |
| D0-3 | **Free disk on the PostgreSQL volume** | ≥ 25 GB (a local run ran out of disk at about 12.5 GB free) |
| D0-4 | **PostgreSQL logging settings and log directory access** | Server-log leakage of row values (P2-OI-02) |
| D0-5 | `famboook_*` role attributes (application role not superuser), no `identity_ref*` schema yet, `pg_hba` rules | P1 security model; the import login's access |
| D0-6 | Installed backup script and latest backup | Staging exclusion; backup freshness |
| D0-7 | Import directory not served by nginx | OD-14 |

## 2. Critical findings addressed in P4 (local, tested)

1. **Recovery breaks the P1 security model. Fixed.**
   - **Cause:** `backup-db.sh` and `restore-test.sh` use `--no-owner --no-privileges`.
   - **Proven** with a real `pg_dump` → `pg_restore` on the disposable cluster: after a restore, the SECURITY DEFINER functions are owned by `postgres`, **PUBLIC can execute `publish_release`**, and the writer has no grants.
   - **Fix:** the new DBA script `deploy/sql/identity-ref/04-reassert-after-restore.dba.sql` returns ownership, grants (including column grants) and revocations **exactly** to the fresh-install state. It is idempotent, proven by test and by the real dump/restore cycle (all 41 triggers intact).
   - **Runbook:** every restore must run `00-roles` + `04-reassert` first.
2. **Server-log leakage. Addressed for the import login.**
   - The new `05-import-login.dba.sql` creates `famboook_identity_import`: NOLOGIN until the window, NOINHERIT, member of the writer only.
   - For that login only, it sets `log_min_messages`, `log_min_error_statement`, `log_statement`, `log_min_duration_*` and parameter logging to off.
   - **Verified in a real session:** the settings are active; the login cannot switch them back on and cannot become the owner.
   - **Still to do:** restricting PostgreSQL log access stays an operator item (G7).
3. **Staging and backups.**
   - The `backup-db.sh` template now excludes identity **staging rows** (`--exclude-table-data='identity_ref_staging.*'`); the table definitions are kept. `restore-test.sh` reports the identity schemas.
   - After a restore, a run that was RUNNING cannot be prepared (row counts disagree). The runbook says: abort and restage.
   - **Whether `identity_ref` itself belongs in every nightly dump** (several GB of sensitive data) is decision D-12 / G6.
4. **Cached configuration would break the import, or leak the password. Fixed.**
   - Production runs `artisan optimize`, which caches config without the operator's variables, so the command would refuse. Exporting them during a deploy would write the password into `bootstrap/cache/config.php` (P2-OI-12).
   - Now the import login and password are **never in config**: the command reads `IDENTITY_REF_DB_USERNAME`/`_PASSWORD` from the operator's shell at run time (tested).
5. **Production command enablement: mechanism prepared, closed by default.**
   - In production the command now refuses unless `IDENTITY_REF_PRODUCTION_APPROVAL` carries an approval reference (logged with every run, never in `.env`).
   - Every writing mode also asks for confirmation.
   - Without the reference, behaviour is unchanged (refused). Tested.

## 3. Tests run (targeted only; no full regression, as instructed)

| Suite | Result |
|---|---|
| `IdentityRefOperationsPostgresTest` (new): restore re-assertion, refusal without roles, import login and server-log settings, credentials from the shell, Production gate | **5 / 5 passed** |
| With the command-dependent classes (`IdentityRefStagingSecurityPostgresTest`, `IdentityRefReleaseSafetyPostgresTest`) | **25 tests, 294 assertions: all passed** |
| Real `pg_dump --no-owner --no-privileges` → `pg_restore` → `00` + `04` on the disposable cluster (empty schemas, no data) | Before 04: definer owner `postgres`, PUBLIC may execute `publish_release`. After 04: identical to a fresh install; 41 triggers. Temporary database dropped. |
| Read-only D0 SQL (syntax) | Executed against the local test database inside a read-only transaction ending in ROLLBACK |
| Checksum snippet of runbook P5 | Tested on a synthetic folder (detects a mismatch) |

## 4. Git changes prepared for review (not staged, not committed)

**Scan results:**
- No secrets, keys or passwords.
- No CSV, dump or env file.
- No real identity data. The only 9-digit values are synthetic fixtures (`9000…`, `9555…`, `1000…`, `012345678`), plus a synthetic phone value in a test.

**Included: 70 files.**
- **App code:** `backend/app/Actions/PublishIdentityReleaseAction.php`, `backend/app/Actions/StageIdentityImportAction.php`, `backend/app/Console/Commands/StageIdentityImport.php`, `backend/app/Exceptions/IdentityImportException.php`, `backend/app/Support/IdentityRef/` (11 files).
- **Configuration:** `backend/config/database.php` (modified: one connection entry, no credentials).
- **Tests:** `backend/tests/Feature/IdentityRef/` (13), `backend/tests/Support/IdentityRef/` (5), `backend/tests/Unit/IdentityRef/` (1).
- **SQL:** `deploy/sql/identity-ref/` (00–05, 90).
- **Deploy scripts:** `deploy/scripts/backup-db.sh` and `deploy/scripts/restore-test.sh` (modified).
- **Docs:** `docs/audits/FB-CIVIL-01A-*` (P0 aggregate outputs, P1–P4) and `docs/deployment/FB-CIVIL-01A-P4-OPERATOR-CHECKLIST.md`.
- **Tools:** `tools/identity-profiling/` (P0 profiler and its synthetic tests).

**Excluded:**
- `famboook-2026-10-23_18-18-28.dump`: never opened. Recommendation: move it out of the repository and add `*.dump` to `.gitignore` (OD-19, separate change).
- `backend/tests/Support/Notifications/NotificationActorProcesses.php`: an unrelated, earlier test fix; separate commit.
- `docs/12-FEATURE-DELIVERY-PLAYBOOK.md`: unrelated.

**Commands for the developer after approval G2.** Explicit paths only, never `git add -A`.

```bash
git add backend/app/Actions/PublishIdentityReleaseAction.php backend/app/Actions/StageIdentityImportAction.php \
        backend/app/Console/Commands/StageIdentityImport.php backend/app/Exceptions/IdentityImportException.php \
        backend/app/Support/IdentityRef backend/config/database.php \
        backend/tests/Feature/IdentityRef backend/tests/Support/IdentityRef backend/tests/Unit/IdentityRef \
        deploy/sql/identity-ref deploy/scripts/backup-db.sh deploy/scripts/restore-test.sh \
        docs/audits/FB-CIVIL-01A-*.md docs/audits/FB-CIVIL-01A-P0-QUALITY-METRICS.json \
        docs/deployment/FB-CIVIL-01A-P4-OPERATOR-CHECKLIST.md tools/identity-profiling
git status --short          # verify: no .dump, no NotificationActorProcesses.php, no docs/12
git diff --cached --stat
```

**Proposed commit message:**

```
feat(identity-ref): FB-CIVIL-01A identity reference store, staging loader and gated publication (P0–P4)
```

## 5. Remaining approvals and blockers

| Gate | Item | Status |
|---|---|---|
| **G1** | **Lawful processing of sensitive identity data** (OD-08 / D-15): legal basis, authority to hold the source, purpose limitation, retention, Privacy Policy coverage | **OPEN: blocks the import** |
| **G2** | Commit and push of the reviewed file set (§4) | Awaiting approval |
| **G3** | **Production command enablement:** issue the approval reference for `IDENTITY_REF_PRODUCTION_APPROVAL` | Mechanism ready; awaiting approval |
| G4 | Delta inspection D0 run and reviewed; DBA available | Pending operator |
| G5 | Fresh backup and **passed restore rehearsal including `04-reassert`** (R-1 was waived) | Pending |
| G6 | Backup policy for `identity_ref` (D-12) and installation of the updated backup script | Decision pending |
| G7 | Import login settings applied (05); PostgreSQL log access restricted | Pending |
| G8 | Maintenance window and named operator (OD-18) | Open |
| G9 | Rehearsal on PostgreSQL 16, if that is the Production major version | Pending D0-2 |
| — | P1–P3 open items that remain: D-05/D-06/D-07 (comparison of `sert1`, `age`, NULL vs `""`), D-11 snapshot semantics, D-13 no rollback after publication, P2-OI-06 symlink test on Linux | Documented |

**Ready:** code, schemas, recovery script, import login script, backup template, the final runbook (delta inspection, preparation, deployment, import, abort and recovery) and the Git review package.

**Not ready, by design:** anything that touches Production or real data, until G1–G9.
