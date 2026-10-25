# FB-CIVIL-01A-P4 — Local Preparation Report

**Date:** 2026-10-25.

**Scope:**
- Local preparation for the identity reference import.
- No connection to Production was attempted (none exists from this machine); the operator manages Production manually through SSH.
- Nothing was deployed, imported, committed or pushed.

## 1. Deliverables

| File | Content |
|---|---|
| `docs/deployment/FB-CIVIL-01A-P4-OPERATOR-CHECKLIST.md` | **A.** Read-only SSH inspection, copy-paste ready. **B.** Deployment preparation (gated outline). **C.** Deployment and import (gated outline). **§4** Gates. |
| This report | Local readiness and gates |

The read-only database block in Part A was executed against the local disposable test cluster to confirm its syntax: all statements succeeded inside `BEGIN TRANSACTION READ ONLY … ROLLBACK`. It was never executed against Production.

## 2. Basis: the actual repository

| Item | Source |
|---|---|
| App root `/var/www/famboook`; code owner `deploy`; PHP-FPM `www-data` | docs/08 §10; `deploy/systemd/famboook-notifications-worker.service` |
| Database `famboook_pilot`, role `famboook_app`, PostgreSQL 16+ on localhost | `deploy/env/backend.production.env.example`; `deploy/scripts/db-preflight.sql` |
| Deployment = git checkout of a sha, then `deploy-backend.sh` (migrations as `famboook_app`) | `deploy/scripts/deploy-backend.sh`; `docs/deployment/FB-GEO-01-PRODUCTION-DEPLOYMENT-PLAN.md` |
| Backups: `pg_dump --format=custom` + age, `/opt/famboook/backup-db.sh`, `/var/backups/famboook` | `deploy/scripts/backup-db.sh`; docs/08 §12 |
| Queue `sync` (database queue only after the notification worker), cache `database`. **No Redis or Docker in the repository.** | env template; docs/08 §16 |

## 3. Local readiness of the FB-CIVIL-01A code

| Check | Result |
|---|---|
| P1–P3.1 tests | Passed earlier (P3 report; P3.1: 27/27 targeted). Not re-run for P4 (no code change in P4). |
| Laravel migrations added | **None**: `deploy-backend.sh` / `migrate` are unaffected |
| Composer packages, routes, deploy scripts, systemd units, env templates changed | **None** |
| Schema delivery | Owner-applied DBA scripts `deploy/sql/identity-ref/00`–`03` (applied by hand, in Part B) |
| Effect of deploying the code alone | No data or schema change. The new `identity_ref_import` connection reads `IDENTITY_REF_DB_*` (absent, so "not configured"), and the command **refuses `APP_ENV=production`**. |
| Code state | All FB-CIVIL-01A work is **uncommitted** in the local working tree (branch `feature/fb-geo-02`) |

## 4. What blocks B and C (from the checklist §4)

1. **G1, lawful basis (OD-08 / D-15): blocks any real-data import.**
2. **G2, commit and push approval**: the server deploys from git.
3. **G3, production enablement**: a small P4 code change (allow the command in production behind an explicit confirmation). It needs approval and was not made.
4. **G4–G8:**
   - Part A review: PostgreSQL version, `famboook_app` not a superuser, at least 25 GB free, PHP extensions;
   - a passed restore test;
   - a backup policy for `identity_ref` and UNLOGGED staging;
   - server-log hardening;
   - a maintenance window and a named operator.

## 5. Risks carried forward

- **Disk:** a full first import measured locally at about 5.2 GB of data, about 6 GB of WAL and several GB of temporary files. The local two-release rehearsal ran out of disk at about 12.5 GB free, so the checklist requires at least 25 GB on the PostgreSQL volume.
- **Activation:** about 23 s at full scale (first release). Readers are not blocked.
- **Backups:** the nightly `pg_dump` would include `identity_ref` (several GB of sensitive data) and, by default, UNLOGGED staging.

## 6. Next step

The operator runs **Part A** and returns the complete output. Parts B and C are then finalized from that output, and only after the §4 gates are approved.
