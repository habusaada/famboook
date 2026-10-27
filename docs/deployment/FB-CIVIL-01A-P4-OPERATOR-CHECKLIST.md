# FB-CIVIL-01A-P4 — Production Runbook: Identity Reference Deployment and Import

**Status:** **PREPARED, NOT APPROVED.** Approving this document authorizes no step. Every gate in §5 is its own recorded approval when it is executed.

**Who runs it:** the Production operator, in their own SSH session. Claude Code has no connection to the server and ran nothing there.

**Stages:**
1. **D0** — Delta inspection: read-only, missing or outdated facts only.
2. **P** — Preparation: no Production schema or data change.
3. **D** — Deployment: code and empty schemas; no identity data.
4. **I** — Import: real data; only after G1.

**Conventions:**
- `<app>` = `/var/www/famboook`.
- git, artisan and the import run as **`deploy`**; services run as `www-data`.
- **Names (reconciled 2026-10-26, FB-CIVIL-01A-UI X-8):**
  - `<db>` is the Production database name and `<app_role>` is the application's database role, both as configured in `<app>/backend/.env` (`DB_DATABASE`, `DB_USERNAME`).
  - The earlier draft assumed `famboook_pilot` / `famboook_app`. The operator has reported `famboook` / `famboook_user`. **D0 confirms the real values before any step;** substitute them everywhere below.
  - The identity SQL names only `famboook_identity_*` roles and never the application role.
- The DBA uses `sudo -u postgres psql -d <db> -v ON_ERROR_STOP=1`. Replace that prefix if your administrator differs.
- PHP CLI `/usr/bin/php8.3`.

**Output safety:** every command prints configuration, ids, counts, codes and sizes. Nothing prints a password, a secret or any person's data.

## 0. Evidence already on record (not repeated)

From the operator audit of **2026-10-21**, `FB-NOTIFY-01-PRODUCTION-PILOT-RUNBOOK.md` §1:

| Item | Value | Use here |
|---|---|---|
| Runtime | PHP 8.3.35, Laravel 12.69.2, systemd 255, `pdo_pgsql` and `pcntl` present | Enough for the import (COPY via `pdo_pgsql`; `mbstring` is a Laravel requirement) |
| Queue / cache | `database` / `database`; no worker, scheduler or Laravel cron | The import needs neither (artisan only) |
| Backup | 2026-10-21; archive catalog check PASS | **Outdated** (D0-6) |
| Restore rehearsal | **Waived** 2026-10-21; last successful restore **unverified** (R-1) | **Gate G5** |
| Production HEAD | `bb6cb0d` (10-21) | **Possibly outdated** (D0-1) |

## D0. Delta inspection (read-only; only what is missing or outdated)

**D0-1 — Current code and migrations.** The FB-NOTIFY release may have deployed after 10-21.

```bash
sudo -u deploy git -C /var/www/famboook rev-parse HEAD
sudo -u deploy git -C /var/www/famboook status --short | head -10
sudo -u deploy php /var/www/famboook/backend/artisan migrate:status 2>&1 | tail -8
```

**D0-2…D0-5 — PostgreSQL version, disk, logging, roles and access rules.** None of these is on record.

```bash
sudo -u postgres psql -XAtc "SHOW data_directory" | xargs -I{} df -h {}
sudo -u postgres psql -d <db> -X <<'SQL'
\pset pager off
\set ON_ERROR_STOP on
BEGIN TRANSACTION READ ONLY;
SELECT version();
SELECT name, setting FROM pg_settings WHERE name IN ('log_destination','logging_collector','log_directory','log_min_messages',
  'log_min_error_statement','log_statement','log_min_duration_statement','work_mem','max_wal_size','temp_tablespaces') ORDER BY 1;
SELECT rolname, rolsuper, rolcreaterole, rolcreatedb, rolcanlogin, rolbypassrls FROM pg_roles WHERE rolname LIKE 'famboook%' ORDER BY 1;
SELECT nspname AS identity_schema_already_present FROM pg_namespace WHERE nspname LIKE 'identity_ref%';
SELECT has_database_privilege('public', current_database(), 'TEMPORARY') AS public_has_temporary;
SELECT pg_size_pretty(pg_database_size(current_database())) AS database_size;
SELECT (SELECT count(*) FROM public.persons) AS persons, (SELECT count(*) FROM public.families) AS families,
       (SELECT count(*) FROM public.roles) AS roles, (SELECT count(*) FROM public.permissions) AS permissions;
ROLLBACK;
SQL
sudo grep -vE '^\s*(#|$)' "$(sudo -u postgres psql -XAtc 'SHOW hba_file')"
sudo ls -ld "$(sudo -u postgres psql -XAtc "SELECT CASE WHEN current_setting('log_directory') LIKE '/%' THEN current_setting('log_directory') ELSE current_setting('data_directory') || '/' || current_setting('log_directory') END")" 2>/dev/null
```

**D0-6 — The installed backup script and the latest backup.** Outdated since 10-21.

```bash
sudo grep -nE 'DB_NAME=|DB_USER=|pg_dump|exclude' /opt/famboook/backup-db.sh
sudo ls -lt /var/backups/famboook | head -3
sudo tail -3 /var/log/famboook-backup.log
```

**D0-7 — Import directory and web exposure.**

```bash
sudo nginx -T 2>/dev/null | grep -E '^\s*(root|alias)\s' | sort -u
ls -ld /var/lib/famboook-import 2>/dev/null || echo "no import directory yet"
```

**Stop conditions (do not continue to P):**
- PostgreSQL major version < 16.
- `<app_role>` is a superuser, or a member of any `famboook_identity_*` role.
- Less than **25 GB** free on the PostgreSQL data volume.
- An `identity_ref*` schema already exists.
- The working tree is not clean.

## P. Preparation (no Production schema or data change)

**P1 — Approvals recorded:** G1, G2, G3 (§5).

**P2 — The release commit (local, after G2).**
- The developer commits the reviewed file set (`docs/audits/FB-CIVIL-01A-P4-FINAL-PREPARATION-REPORT.md` §4).
- They run the targeted PostgreSQL tests on that commit and push it to the agreed release branch. Pushing requires approval.
- The commit adds **no Laravel migration and no Composer package**.

**P3 — Fresh backup and restore rehearsal** (docs/08 §12–§13; G5).

```bash
sudo -u deploy /var/www/famboook/deploy/scripts/backup-db.sh
sudo /path/to/restore-test.sh /var/backups/famboook/<db>_<stamp>.dump.age   # as the DB administrator; must end with the counts and drop its temp DB
```

**P4 — Install the updated backup script** after the code checkout (D1). The updated template excludes identity staging rows; owners and grants are restored separately with `04-reassert`. Review the diff first:

```bash
sudo diff /opt/famboook/backup-db.sh /var/www/famboook/deploy/scripts/backup-db.sh
# then re-apply your local values (RECIPIENT, rclone lines) to the new template and install it:
sudo install -m 750 -o root -g root /path/to/edited/backup-db.sh /opt/famboook/backup-db.sh
```

**P5 — Import directory and source files.**
- **Transfer:** copy the 24 CSV files and `manifest.json` over SSH/SFTP **directly to the server**, never via a developer machine (OD-14).
- **Directory:** not served by nginx, owned by `deploy`, mode 700.

```bash
sudo install -d -m 700 -o deploy -g deploy /var/lib/famboook-import/t03maindata-<date>
# after the transfer:
sudo chmod 600 /var/lib/famboook-import/t03maindata-<date>/*
sudo ls /var/lib/famboook-import/t03maindata-<date> | wc -l
sudo -u deploy php <<'PHP'
<?php
$d = '/var/lib/famboook-import/t03maindata-<date>';
$m = json_decode(file_get_contents("$d/manifest.json"), true);
$bad = 0;
foreach ($m['files'] as $f) {
    if (hash_file('sha256', "$d/{$f['file']}") !== strtolower($f['sha256'])) { $bad++; echo "MISMATCH {$f['file']}\n"; }
}
echo 'files=', count($m['files']), ' rows=', $m['total_rows'], ' mismatches=', $bad, "\n";
PHP
```

Expected: 25 entries, `files=24 rows=2399891 mismatches=0`. The staging command re-verifies everything anyway.

## D. Deployment (code and empty schemas; no identity data)

**D1 — Code** (G2): the approved SHA.

```bash
sudo -u deploy git -C /var/www/famboook fetch origin
sudo -u deploy git -C /var/www/famboook checkout --detach <approved-sha>
sudo -u deploy git -C /var/www/famboook status --short          # must be empty
sudo -u deploy php /var/www/famboook/backend/artisan migrate --pretend   # expect nothing new from FB-CIVIL-01A
sudo -u deploy /var/www/famboook/deploy/scripts/deploy-backend.sh
sudo systemctl reload php8.3-fpm
```

**D2 — Roles and schemas** (DBA; G4). All scripts are idempotent where noted, and none writes any identity data.

```bash
S=/var/www/famboook/deploy/sql/identity-ref
sudo -u postgres psql -d <db> -v ON_ERROR_STOP=1 -f $S/00-roles.dba.sql
sudo -u postgres psql -d <db> -v ON_ERROR_STOP=1 <<SQL
SET ROLE famboook_identity_owner;
\i $S/01-schema.sql
\i $S/02-staging.sql
\i $S/03-publication.sql
RESET ROLE;
SQL
sudo -u postgres psql -d <db> -v ON_ERROR_STOP=1 -f $S/05-import-login.dba.sql
```

`05` creates `famboook_identity_import` (NOLOGIN, NOINHERIT, member of the writer only). It also switches off server logging of errors and statements **for that login** (P2-OI-02).

**D3 — Client authentication for the import login.** Only if D0's `hba_file` has no rule that already covers it. Add the line before any broader rule, then reload:

```
host  <db>  famboook_identity_import  127.0.0.1/32  scram-sha-256
```

```bash
sudo -u postgres psql -XAtc "SELECT pg_reload_conf()"
```

**D4 — Verify the empty installation** (read-only).

```bash
sudo -u postgres psql -d <db> -X <<'SQL'
BEGIN TRANSACTION READ ONLY;
SELECT nspname, pg_get_userbyid(nspowner) FROM pg_namespace WHERE nspname LIKE 'identity_ref%' ORDER BY 1;          -- 2 rows, famboook_identity_owner
SELECT string_agg(DISTINCT pg_get_userbyid(p.proowner), ',') AS definer_owners FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
 WHERE n.nspname LIKE 'identity_ref%' AND p.prosecdef;                                                                -- famboook_identity_owner
SELECT has_schema_privilege('<app_role>', 'identity_ref', 'USAGE') AS app_usage,                                    -- f
       has_function_privilege('public', 'identity_ref.publish_release(bigint)', 'EXECUTE') AS public_publish;          -- f
SELECT count(*) AS triggers FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid JOIN pg_namespace n ON n.oid = c.relnamespace
 WHERE n.nspname LIKE 'identity_ref%' AND NOT t.tgisinternal;                                                         -- 41
SELECT rolname, rolcanlogin, rolinherit, rolsuper FROM pg_roles WHERE rolname LIKE 'famboook_identity%' ORDER BY 1;    -- none super; import NOLOGIN
SELECT count(*) AS releases FROM identity_ref.dataset_releases;                                                       -- 0
ROLLBACK;
SQL
```

**Rollback of D** (nothing imported yet):
- Code: check out the previous SHA and run `deploy-backend.sh`.
- Empty schemas: may stay, or a DBA may drop them; dropping is destructive and needs approval.

## I. Import (real data; only after G1 and with G6–G8 in place)

**I0 — Window and session.**
- Outside the nightly backup (02:15 UTC). Allow **about 2–3 hours** for 2.4 million rows; measured locally at about 50 minutes for a first release.
- Re-check free disk: at least 25 GB.

DBA enables the login for the window (password typed interactively):

```bash
sudo -u postgres psql -d <db> -c "ALTER ROLE famboook_identity_import LOGIN"
sudo -u postgres psql -d <db> -c "\password famboook_identity_import"
```

Operator shell. The credentials and the approval reference live **only in this shell**: never in `.env`, never with `config:cache`.

```bash
sudo -iu deploy
cd /var/www/famboook/backend
export IDENTITY_REF_DB_USERNAME=famboook_identity_import
read -rs -p 'import login password: ' IDENTITY_REF_DB_PASSWORD; echo; export IDENTITY_REF_DB_PASSWORD
export IDENTITY_REF_PRODUCTION_APPROVAL='<approval reference, e.g. CHG-2026-…>'
D=/var/lib/famboook-import/t03maindata-<date>
```

**I1 — Stage** (validates the manifest, inventory, BOM, headers, structure, row counts and SHA-256, then loads). Re-running the same command resumes.

```bash
php artisan famboook:identity-ref-stage "$D" --target=<db> --expected-files=24 --expected-rows=2399891 --yes
```

**I2 — Validate and preview** (read-only). Note the run id.

```bash
php artisan famboook:identity-ref-stage --validate --run=<run-uuid> --target=<db>
php artisan famboook:identity-ref-stage --preview  --run=<run-uuid> --target=<db>
```

**Stop if** any fatal code appears, or `QUARANTINE_NOT_ALLOWED` (policy `IDREF-POLICY-1`, zero quarantine). Then abort (§4) and correct the source.

**I3 — Prepare and verify** (invisible to readers).

```bash
php artisan famboook:identity-ref-stage --prepare --run=<run-uuid> --target=<db> --yes
php artisan famboook:identity-ref-stage --verify  --release=<release-id> --target=<db> --yes
```

**I4 — Publish** (one short transaction; it asks for confirmation).

```bash
php artisan famboook:identity-ref-stage --publish --release=<release-id> --target=<db>
```

**I5 — Post-checks.**

```bash
php artisan famboook:identity-ref-stage --status --target=<db>
```

Then re-run the D0-2 count line. It must show the **same** persons, families, roles and permissions counts as before.

**I6 — Close.**

```bash
unset IDENTITY_REF_DB_PASSWORD IDENTITY_REF_DB_USERNAME IDENTITY_REF_PRODUCTION_APPROVAL; exit
sudo -u postgres psql -d <db> -c "ALTER ROLE famboook_identity_import NOLOGIN"
```

Handle the source files per the OD-14 decision: secure deletion (`shred -u`) or an encrypted archive. Then record the run id, release id and counts in the change record.

## 4. Abort, rollback and recovery

| Situation | Action |
|---|---|
| Any refusal or interruption before publication | `php artisan famboook:identity-ref-stage --abort --target=<db> --yes`. This closes the run, abandons its BUILDING release and purges staging. The active release is unchanged. |
| Interrupted staging, preparation or publication | Re-run the same command; it resumes. A failed publication is fully rolled back. |
| Staging left over after an abort, rejection or publication (the abort warns "staging purge stopped early"; the publication logs `identity_ref.release.staging_purge_failed`) | Count first, read-only: `php artisan famboook:identity-ref-stage --purge --dry-run --target=<db>`. Then `--purge --target=<db> --yes` (default 1,000 rows per committed batch; `--purge-batch=<n>`). It deletes only the staging of finished runs, never a RUNNING run, a release or reference data. Interrupting it is safe; re-run it to resume. The summary shows deleted and remaining counts. |
| After publication | There is no "unpublish" (D-13). The release is read by no feature, API or UI in this phase. Removal would be a DBA operation on a backup-protected database and needs a separate approval. |
| **A database restore (any reason)** | Run `00-roles.dba.sql`, then **`04-reassert-after-restore.dba.sql`**, before anything uses the database. Without it, the identity objects belong to the restoring role, grants are gone, and `publish_release` is executable by PUBLIC (proven locally). A run that was RUNNING when the backup was taken cannot be prepared: abort it and stage again. |
| Disk filling during the import | PostgreSQL refuses the statement and nothing partial becomes active. Free space or abort, then retry. |

## 5. Gates

| Gate | Item | Status |
|---|---|---|
| **G1** | **Lawful basis and authority to process the real identity dataset** (OD-08 / D-15): legal basis, source authority, purpose limitation, retention, Privacy Policy coverage | **OPEN: blocks Stage I** |
| **G2** | Commit of the reviewed file set and push of the release SHA | Not given |
| **G3** | **Production command enablement**: the deployed command refuses `APP_ENV=production` unless `IDENTITY_REF_PRODUCTION_APPROVAL` carries an approval reference; writing modes also ask for confirmation. The reference to use is issued with this approval. | Mechanism ready; approval not given |
| G4 | D0 reviewed, stop conditions clear; DBA available for D2/D3 | Pending D0 |
| G5 | Fresh backup and a **passed restore rehearsal**, including `04-reassert` on the restored copy (R-1 was waived on 10-21) | Pending |
| G6 | Backup policy for `identity_ref` (several GB of sensitive data in every nightly dump; D-12) and the installed script with the staging exclusion (P4) | Decision pending |
| G7 | Import login logging settings verified (D2) and PostgreSQL log access restricted | Pending D2 |
| G8 | Maintenance window and named operator (OD-18) | Open |
| G9 | If Production runs PostgreSQL 16: a rehearsal on PostgreSQL 16 (all local tests ran on 18.6; P1-OI-09) | Pending D0-2 |
