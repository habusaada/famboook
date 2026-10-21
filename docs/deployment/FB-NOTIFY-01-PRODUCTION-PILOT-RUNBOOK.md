# FB-NOTIFY-01 — Production Pilot Deployment Runbook (approval-gated)

| | |
|---|---|
| Status | **PREPARATION ONLY — nothing executed.** Approving this document authorizes no step (release plan §0). Every gate G0–G9 is its own explicit, recorded approval at the time it is executed |
| Date | 2026-10-21 (revision 2: final pre-deployment decisions applied) |
| From → to | Production `bb6cb0d` → candidate `5d486b3`, released through **`main`** |
| Supersedes | release plan §1.3 and §10 sequencing (they assumed FB-GEO was not yet in Production). The release plan's decisions, rollback levels and safety rules otherwise stay in force |
| Companions | `FB-NOTIFY-01-PILOT-RELEASE-PLAN.md`, `FB-NOTIFY-01-QUEUE-SCHEDULER-READINESS.md` (§5 read-only checks), `FB-NOTIFY-01-PILOT-READINESS-CHECKLIST.md` |

Conventions: `<app>` = `/var/www/famboook`; git, artisan and deploy commands run as **`deploy`**; services run as `www-data`; `psql` = the administrator's read-only session on `famboook_pilot` (docs/08); PHP CLI `/usr/bin/php8.3` (confirm with audit block B1).

## 0. Decisions applied (2026-10-21)

| # | Decision | Effect on this runbook |
|---|---|---|
| P1 | `main` is the official release branch | G1 fast-forwards `origin/main` to the candidate (§2.1); Production checks out that SHA |
| P2 | Frontend deferred until internal S15 preparation | the frontend gate moves after the services (G6), and is blocked by the visibility changes of §3 unless the owner accepts the exposure |
| P3 | Keep the effective `database` queue; no unnecessary `.env` change | no `.env` edit at G2: the notification keys are absent, so the closed defaults apply; values are **verified**, not written |
| P4 | Five migrations documented individually | §4 |
| P5 | Backend code and automatic migrations are one gate | G2; no separation procedure is proposed |
| P6 | Waived restore rehearsal recorded accurately | §9 R-1 |
| P7 | Exact monitoring acceptance criteria | §8 |
| P8 | Internal households test without exposing others | §3 and §7 |

---

## 1. Verified baseline (operator audit, 2026-10-21 — not re-verified by this document)

| Item | Value |
|---|---|
| Production HEAD | `bb6cb0d` (head of `origin/feature/fb-geo-02`; not on `main`) |
| Runtime | PHP 8.3.35, Laravel 12.69.2, systemd 255; `pcntl`, `pdo_pgsql` present |
| Effective queue / cache | `database` / `database` |
| Services | no notification worker, no scheduler service / timer, no Laravel cron |
| Migrations | all Ran (incl. FB-GEO `2026_10_22_*`, `2026_10_23_090000`); notification migrations absent |
| `jobs` / `failed_jobs` | preliminarily empty — final confirmation at S0 |
| Contact-update expiry / OTP purge candidates | 0 / 0 at audit time — recheck at S0 and S6 |
| Backup | 2026-10-21, archive catalog PASS |
| Restore rehearsal | waived (§9 R-1) |

**Documentation drift:** `FB-GEO-01-PRODUCTION-DEPLOYMENT-PLAN.md` still says "PLAN ONLY … nothing executed", and docs/08 records no `bb6cb0d` deployment, although Production runs it. Recording that deployment is a separate documentation task.

---

## 2. Release content

### 2.1 Branch and commits (verified locally; remote refs current — `git fetch --dry-run` reported nothing new)

| Check | Result |
|---|---|
| `origin/main` | `e5303c1` |
| `origin/main` ancestor of `5d486b3` | **yes — a fast-forward is possible; no merge commit** |
| Commits `origin/main..5d486b3` | **37** (8 FB-GEO-02/03/UI already in Production via `bb6cb0d` + 29 FB-NOTIFY-01); 0 merge commits |
| Commits `bb6cb0d..5d486b3` (what Production receives) | **29**, all FB-NOTIFY-01 |
| Migrations `origin/main..bb6cb0d` | `2026_10_23_090000` only (already Ran in Production) |
| Migrations `bb6cb0d..5d486b3` | the five of §4 |
| Local `main` branch | none (only `origin/main`) |

The fast-forward brings `main` in line with Production plus this release. It also records the 8 GEO commits on `main` for the first time; their Production deployment is undocumented (§1).

### 2.2 Content `bb6cb0d..5d486b3`

- **RBAC:** `RolePermissionSeeder` +11 permissions → catalog 162; no role added, no grant removed.
- **Configuration:** `config/notifications.php`; no new `.env` key required (all default closed).
- **Routes:** Staff, Family, Coordinator notification and follow-up APIs; middleware alias `notifications.publication-ready`.
- **Scheduler definitions:** +4 tasks (inert without a scheduler).
- **Dependencies:** `composer.lock`, `package-lock.json` unchanged.
- **Frontend:** 65 files.
- **Operations templates:** worker, scheduler service and timer, health script; `deploy-backend.sh` adds a non-fatal `queue:restart`.

---

## 3. Frontend exposure audit and proposed code changes (P2, P8)

### 3.1 What the candidate frontend exposes

| Surface | Shown to | Gate today | Exposure |
|---|---|---|---|
| Family header bell + unread poll (`GET /api/v1/family/notifications` every 2 min per open tab) | **every family with a Family context** (`family-shell.tsx`: `available && !coordinatorMode`) | none (no mode or pilot check) | every family sees the feature (empty) as soon as the frontend is deployed |
| `/family/notifications`, `/archive`, `/[id]` | any family, by URL | none | empty pages; only the family's own data |
| Coordinator: «إعلاناتي» link (account page), Coordinator Space cards «الإعلانات», «متابعات الأسر» | **every coordinator** (role / current scope) | none | all coordinators see composing and follow-ups; publish answers 503 unless allow-listed in PILOT |
| Staff sidebar «الإعلانات», «المتابعات» | SUPER_ADMIN, ADMINISTRATOR (permissions) | RBAC | internal Staff only — acceptable |

APIs: each family or coordinator only ever reads its own or in-scope data. Restricting the APIs is not needed for privacy; the exposure is **visibility of the feature**, plus the polling load.

**Status (2026-10-21): C1–C3 and C5 are IMPLEMENTED locally (uncommitted), per ADR §2h.** The tables below remain as the design record. What changed from the proposal:
- **C1 eligibility:** not "has received a notification" alone. It is OFF none; PILOT a listed pilot household (`NOTIFICATIONS_PILOT_FAMILY_IDS`) or a household already holding notifications; ON every household.
- **C5:** implemented server-side instead of the procedural control of §3.3.

### 3.2 Proposed changes (design record — now implemented, see ADR §2h)

| # | Change | Where | Effect |
|---|---|---|---|
| **C1** | `/api/v1/family/me` adds `notifications: bool` = the current Family has at least one recipient row of a published message (one `EXISTS` on `notification_recipients (family_id)`) | `FamilyCurrentUserResource` | families never addressed never learn of the feature |
| **C2** | Render `NotificationBell` and run the unread poll only when `user.notifications` is true; the inbox pages show the existing empty state otherwise (no redirect needed) | `family-shell.tsx`, `notification-bell.tsx` | no bell and no polling for non-recipients; recipients keep their history even after mode OFF |
| **C3** | `/family/me` adds `notification_pilot: bool` = COORDINATOR AND (mode `ON`, or mode `PILOT` and the user is in `NOTIFICATIONS_PILOT_SENDER_USER_IDS`); hide the coordinator announcement link and cards, and the follow-up card, unless true | `FamilyCurrentUserResource`, `account-view.tsx`, `coordinator-home.tsx` | only the pilot coordinator sees coordinator notification screens; authorization unchanged (server still decides) |
| C4 (optional) | Staff sidebar entries only while mode ≠ OFF | `navigation.ts` + a Staff `/me` flag | cosmetic; Staff are internal |

- **Classification:** C1–C3 change **visibility only** — no authorization, business rule, audience or follow-up logic. They still amend the approved UI behaviour (docs/11 family notification entry points, ADR §2g), so they need an owner decision and a doc update.
- **Tests:** the frontend tests for the bell, the coordinator cards and the routes must be extended.
- **Re-verification:** the candidate SHA changes. S1's local verification (backend SQLite, PostgreSQL Notifications + FamilyAuth one process at a time, frontend test / typecheck / lint / build) must be repeated on the new SHA.

### 3.3 Coordinator audience exposure — resolved by C5 (ADR §2h)

**Now enforced server-side:** in PILOT, `COORDINATOR_SCOPE`, `STAFF_ALL_ACTIVE`, structure selections and any family outside `NOTIFICATIONS_PILOT_FAMILY_IDS` are refused with 422 `NOTIFICATION_AUDIENCE_NOT_IN_PILOT` on every path, including the worker. The original analysis follows for the record.

A coordinator choosing «كامل نطاق التنسيق» (`COORDINATOR_SCOPE`) publishes to **every family in the scope**. Neither the allow-list nor C3 prevents that. Options:

1. **(Recommended) Procedural control:** the pilot coordinator uses only «أسر محددة» (`COORDINATOR_SELECTION`) with the approved internal family codes; S15 row B08 tests the scope option only with a coordinator whose scope contains **internal households only**, or is skipped.
2. **Code change C5:** refuse `COORDINATOR_SCOPE` while mode is PILOT. This is an audience business-rule change (ADR-04), so it needs an ADR decision — not proposed here.

---

## 4. Migrations, individually (P4)

All five run inside `deploy-backend.sh` (`migrate --force`), each in its own PostgreSQL transaction, in this order. None alters a column or row of an existing Production table. Foreign keys to existing tables take a short `SHARE ROW EXCLUSIVE` lock on those tables while the constraint is created (instant: the new tables are empty). No `lock_timeout` is set, so this relies on maintenance mode (no web writes) and no worker or scheduler running.

| # | Migration | Creates / changes | References existing tables | `down()` | Rollback implication |
|---|---|---|---|---|---|
| M1 | `2026_10_24_090000_create_notification_messages_table` | table `notification_messages`; sequence `notification_message_code_seq` (`NTF-` codes); unique `uuid`, `notice_code`, `origin_workflow_event_id`, (`sender_user_id`, `client_reference`); about 27 PostgreSQL CHECKs | `users` (3 FKs, restrict), `workflow_events` (restrict) | throws once any message exists; else drops the table and the sequence | reversible only while empty; afterwards code-only rollback, table kept. Restrict FKs mean the referenced users / workflow events can never be deleted while referenced (no feature deletes them today) |
| M2 | `…090001_create_notification_recipients_table` | table `notification_recipients`; unique (`message_id`, `family_id`), (`id`, `message_id`); 2 partial indexes; 5 CHECKs | M1, `families`, `persons`, `users` (restrict) | throws once any row exists | same; referenced families / persons cannot be hard-deleted while referenced (soft deletes unaffected) |
| M3 | `…090002_create_notification_followups_table` | table `notification_followups`; unique (`recipient_id`, `stage`); partial index `notification_followups_open_due_index`; 8 CHECKs | M2, `users` | throws once any row exists | same |
| M4 | `…090003_create_notification_recipient_events_table` | table `notification_recipient_events`; composite FKs to M2 / M3 / itself; 11 CHECKs; **append-only trigger** `trg_notification_recipient_events_append_only` and its function | M1–M3, `users` | throws once any event exists; else drops the table and the function | once an event exists the audit is permanent: no UPDATE or DELETE is possible, by design |
| M5 | `2026_10_25_090000_add_client_reference_to_notification_recipient_events` | **the one alteration of an existing table — M4's table, created in the same release:** nullable `client_reference` uuid; partial unique index (`followup_id`, `actor_user_id`, `client_reference`); CHECK `chk_nre_client_reference`. No backfill (the table is empty at deployment). The append-only trigger guards UPDATE / DELETE, not ALTER | — | throws once any reference is stored; else drops the CHECK, the index and the column | rollback order: M5 first, then M4 … M1 (`migrate:rollback --step=5` while everything is empty) |

**Partial failure:** a failure in migration *n* rolls back *n* only; M1…M(n−1) stay applied and empty. Recovery: `php artisan migrate:rollback --step=<n−1>` (succeeds while empty), then roll back the code (S2 rollback).

---

## 5. Deployment script behaviour (unchanged review)

- **`deploy-backend.sh`:** down → composer → `migrate --force` (automatic) → `RolePermissionSeeder` → `verify-permissions` (162) → `optimize` → `filament:optimize` → `queue:restart` (non-fatal) → up.
  - A failure leaves maintenance mode on.
  - It never checks out code: always `php artisan down` **before** `git checkout`.
- **`deploy-frontend.sh`:** `npm ci` → `npm run build` (writes `.next` in place while the old server runs) → `systemctl restart famboook-frontend`.
  - Low-traffic window.
  - Rollback = fix forward, or a full code rollback to one SHA (never a mixed tree).
- **Not in any script:** service installation, enabling or starting; `.env` changes; activation.

---

## 6. Gates and runbook (revised)

Every stage: precondition → commands → expected → **GO / NO-GO** → rollback. A NO-GO stops the runbook at that stage.

| Gate | Stage | Approves | Changes for users |
|---|---|---|---|
| **G0** | S0 | read-only pre-flight | none |
| **G1** | S1 | code transfer: fast-forward `origin/main`, `git fetch` on the server | none |
| **G2** | S2–S3 | fresh backup + **backend code and the five migrations (one gate, P5)** + RBAC seeding, notifications OFF, **old frontend kept** | none visible (new APIs answer; drafts possible only through the API) |
| **G3** | S4 | service installation (nothing enabled) | none |
| **G4** | S5 | worker activation | none |
| **G5** | S6 | scheduler activation | overdue contact-update cancellations / OTP purge (0 expected) |
| **G6** | S7 | frontend deployment — **only with C1–C3 deployed, or with the owner's written acceptance of §3.1 exposure** | with C1–C3: none until a family receives a notification; without: every family sees an empty bell |
| **G7** | S8 | internal pilot: mode PILOT, internal allow-list, follow-ups per D2; S15 with internal households | internal households only |
| **G8** | S9 | approved test families, after S15 PASS | test families only |
| **G9** | — | public activation (`ON`) — **not covered** | — |

### S0 — Pre-flight (G0, read-only)

```bash
cd /var/www/famboook && git rev-parse HEAD && git status --porcelain | wc -l
cd backend
php artisan migrate:status | grep -c Pending
php artisan famboook:verify-permissions >/dev/null && echo perms_ok
for k in queue.default cache.default notifications.mode notifications.followups_enabled notifications.system_discovery_enabled; do php artisan config:show "$k" | tail -n 1; done
ps -eo args | grep -c '[q]ueue:work'
```
Plus audit blocks B1, B4 (psql read-only pattern) and B5.

- **GO:**
  - HEAD `bb6cb0d…`, 0 local changes, 0 pending migrations, `perms_ok`;
  - `queue.default = database`, `cache.default = database`, `notifications.mode` = `OFF` (or absent: the config file is not deployed yet — expected at `bb6cb0d`);
  - 0 `queue:work` processes;
  - `jobs` / `failed_jobs` empty; contact-update and OTP counts 0.
- **NO-GO:** anything else.

### S1 — Code transfer (G1)

- **Precondition:** the local verification on the candidate SHA:
  - backend SQLite;
  - PostgreSQL Notifications + FamilyAuth, one process at a time;
  - frontend test, typecheck, lint, build.
- **Commands:**
  ```bash
  # local
  git fetch origin && git merge-base --is-ancestor origin/main <SHA> && echo ff_ok
  git push origin <SHA>:refs/heads/main                       # fast-forward only; never --force
  # server (deploy)
  cd /var/www/famboook && git fetch origin
  git rev-parse origin/main                                   # = <SHA>
  git merge-base --is-ancestor HEAD origin/main && echo ff_from_production_ok
  git diff --name-status HEAD origin/main -- backend/database/migrations   # exactly the five A lines
  ```
- **GO:** `ff_ok`, push accepted, the server sees `<SHA>`, `ff_from_production_ok`, exactly five added migrations, working tree unchanged.
- **NO-GO:** a rejected push, a different SHA, any other migration.
- **Rollback:** none on the server. `main` was only fast-forwarded, so no history is rewritten.

### S2 — Fresh backup (part of G2)

`deploy/scripts/backup-db.sh` (docs/08 §12): `.dump.age` and `.sha256` exist, `pg_restore --list` succeeds, off-server copy done.

- **GO:** all confirmed within the hour before S3.
- **NO-GO:** any failure.

### S3 — Backend and migrations (G2, one gate)

- **Precondition:** S2 GO; maintenance window announced; no `.env` change (P3) — S0 showed the effective values.
- **Commands:**
  ```bash
  cd /var/www/famboook/backend && php artisan down --retry=60
  cd /var/www/famboook && git checkout --detach <SHA>
  ./deploy/scripts/deploy-backend.sh
  sudo systemctl reload php8.3-fpm
  cd backend
  php artisan migrate:status | grep -E '2026_10_2[45]_'
  for k in notifications.mode notifications.followups_enabled notifications.system_discovery_enabled; do php artisan config:show "$k" | tail -n 1; done
  php artisan famboook:notifications-check
  ```
- **Expected:**
  - "Backend deployed"; M1–M5 Ran; `verify-permissions` 162;
  - `notifications.mode OFF`, follow-ups false, SYSTEM discovery false;
  - the check reports tables present and publication `NOT READY` (`MODE_OFF`, heartbeat ABSENT), no other warning;
  - the site up; the **old frontend still serving**.
- **GO:** the above, plus:
  - REVIEWER `GET /api/v1/notifications` → 403;
  - ADMINISTRATOR publish → 503;
  - existing registry, Change Request and Family Portal screens unchanged (docs/08 §16b smoke).
- **NO-GO:** any failure.
- **Rollback:**
  - while no notification row exists: `php artisan migrate:rollback --step=5`, `git checkout --detach bb6cb0d`, `./deploy/scripts/deploy-backend.sh`, `php artisan up`;
  - once any row exists: code-only rollback with the tables kept.
  - The `bb6cb0d` seeder removes the 11 grants from the roles; the 11 permission rows stay unassigned and harmless.

### S4 — Service installation (G3)

```bash
cd /var/www/famboook
sed 's#<PHP_BINARY>#/usr/bin/php8.3#' deploy/systemd/famboook-notifications-worker.service | sudo tee /etc/systemd/system/famboook-notifications-worker.service >/dev/null
sed 's#<PHP_BINARY>#/usr/bin/php8.3#' deploy/systemd/famboook-scheduler.service | sudo tee /etc/systemd/system/famboook-scheduler.service >/dev/null
sudo cp deploy/systemd/famboook-scheduler.timer /etc/systemd/system/
sudo systemd-analyze verify /etc/systemd/system/famboook-notifications-worker.service /etc/systemd/system/famboook-scheduler.service /etc/systemd/system/famboook-scheduler.timer
sudo systemctl daemon-reload
systemctl is-enabled famboook-notifications-worker famboook-scheduler.timer
```
- **GO:** `verify` silent; both `disabled`.
- **NO-GO:** any `verify` output.
- **Rollback:** remove the three files, then `daemon-reload`.

### S5 — Worker activation (G4)

- **Precondition:** S4 GO; `jobs` empty (B4).
- **Commands:**
  ```bash
  sudo systemctl enable --now famboook-notifications-worker
  ```
- **GO (after 5 min):** the worker criteria of §8, and `jobs` still empty.
- **NO-GO:** any criterion missed.
- **Rollback:** `sudo systemctl disable --now famboook-notifications-worker`.

### S6 — Scheduler activation (G5)

- **Precondition:** S5 GO; B4 and B5 rechecked immediately before; D4 approved with those counts; working hours, well before 00:00 UTC.
- **Commands:**
  ```bash
  sudo systemctl enable --now famboook-scheduler.timer
  ```
- **GO (after 15 min):** the scheduler and heartbeat criteria of §8. Contact-update cancellations equal the B5 count.
- **NO-GO:** any criterion missed.
- **Rollback:** `sudo systemctl disable --now famboook-scheduler.timer`. Cancellations and purges already done are permanent (§9).

### S7 — Frontend deployment (G6, deferred: P2)

- **Precondition:**
  - S6 GO for at least one full day;
  - **C1–C3 deployed in the candidate SHA (S1–S3 repeated for that SHA), OR the owner's written acceptance of the §3.1 exposure**;
  - low-traffic window; family support briefed.
- **Commands:** `./deploy/scripts/deploy-frontend.sh`.
- **GO:**
  - the build succeeds; `famboook-frontend` active;
  - Staff and Family logins and main screens load;
  - with C1–C3: no bell for any family (no family has notifications yet), no coordinator notification cards for any coordinator (mode OFF); Staff `/announcements` and `/followups` empty for SUPER_ADMIN and ADMINISTRATOR, absent for others.
- **NO-GO:** a build failure, a broken page, a bell or a card visible where it should not be.
- **Rollback:** fix forward, or the full code rollback to one SHA.

### S8 — Internal pilot and S15 (G7)

- **Precondition:**
  - S7 GO;
  - D1, D2, D6 recorded;
  - internal Staff sender ids;
  - **internal households** (§7) with activated accounts;
  - the pilot coordinator per §3.3.
- **Commands:** in `.env` (the first `.env` change of this release, approved at G7):
  ```text
  NOTIFICATIONS_MODE=PILOT
  NOTIFICATIONS_PILOT_SENDER_USER_IDS=<internal sender user ids>
  NOTIFICATIONS_PILOT_FAMILY_IDS=<internal household family ids>   # ADR §2h: the only addressable households
  NOTIFICATIONS_FOLLOWUPS_ENABLED=true            # if D2 = include
  NOTIFICATIONS_FOLLOWUPS_CUTOFF=<this instant, ISO-8601 with offset>
  ```
  Then `php artisan config:clear && php artisan optimize`, `sudo systemctl reload php8.3-fpm`, `sudo systemctl restart famboook-notifications-worker`.
- **GO for activation:** the activation criteria of §8.
- **Then:** the S15 matrix (§7), and the follow-up smoke tests at +24 h / +72 h (release plan S16).
- **GO for G8:** every applicable S15 cell PASS and recorded; §8 steady-state criteria held for the whole S15 period.
- **NO-GO:** any FAIL.
- **Rollback:** release plan §12 L1 (mode OFF, follow-ups off, caches, PHP-FPM, worker restart).

### S9 — Test families (G8)

- **Precondition:** S8 GO; test families and senders briefed.
- **Commands:** none on the server; senders address only approved family codes.
- **Daily GO:** §8 steady-state criteria; no family complaint.
- **NO-GO:** L1.

---

## 7. S15 — internal households without exposing other families (P8)

**How exposure is contained:**
1. **Publication:** only allow-listed internal senders can publish (PILOT). They address internal households by family code (`STAFF_FAMILIES` / `COORDINATOR_SELECTION`). ADMINISTRATOR cannot use `STAFF_ALL_ACTIVE` (no `send-all`). The coordinator scope option is controlled per §3.3.
2. **Visibility:** with C1–C2, only a family that received a pilot notification sees the bell and is polled. With C3, only the allow-listed coordinator sees coordinator notification screens.
3. **Follow-ups** exist only for recipients, so only for internal households.
4. **Accounts:** internal households are Staff-controlled family accounts (activated, briefed, consenting), each with a family code recorded in the S15 log. One accountless internal family can be included to observe follow-ups.

**Matrix** (D desktop Chrome ≥ 1280 px · A Android Chrome ≈ 375 px · I iOS Safari ≈ 375 px; each cell PASS / FAIL + tester + time):

| # | Account | Scenario | D | A | I |
|---|---|---|---|---|---|
| B00 | A non-internal family account (read-only observation by its own household, or a Staff-controlled non-pilot household) | **no bell, no badge**, no polling request in the network panel (C1–C2) | | | |
| B01 | REVIEWER | no «الإعلانات» / «المتابعات»; direct URL → no access | | | — |
| B02 | Pilot ADMINISTRATOR | compose to 1 internal family by code, preview 1, save, publish → «في انتظار النشر» → `PUBLISHED` ≤ 1 min | | | — |
| B03 | Non-pilot ADMINISTRATOR | publish → 503 message; draft kept | | | — |
| B04 | Pilot ADMINISTRATOR | cancel a second draft; never reaches the family | | | — |
| B05 | Internal household head | bell appears only after the first notification; badge 1 → inbox → detail → badge clears | | | |
| B06 | Internal household head | IMPORTANT: acknowledge dialog; sender statistics show 1 acknowledged | | | |
| B07 | Internal household head | archive shows an expired message | | | |
| B08 | Pilot coordinator | «أسر محددة» to an internal family; out-of-scope code refused; «كامل نطاق التنسيق» only if the scope holds internal households only (§3.3), else SKIPPED | — | | |
| B09 | Non-pilot coordinator | no coordinator notification cards or link (C3) | — | | |
| B10 | Pilot ADMINISTRATOR | `/followups` filters, pagination, summary | | | — |
| B11 | Pilot ADMINISTRATOR | follow-up detail and history (names, notes); contact; close with confirmation and reason | | | — |
| B12 | Primary pilot coordinator | «متابعات الأسر», «متابعاتي»; contact; close | — | | |
| B13 | Non-primary coordinator in scope | read-only note; labels only in the history | — | | |
| B14 | Any | offline contact submission → message; retry → one contact in the history | | | |
| B15 | Any | RTL (codes LTR); no horizontal scroll at 360–390 px | | | |
| B16 | Any | keyboard: tab order, visible focus, dialog focus trap, Escape | | — | — |
| B17 | Any | loading, empty and error states | | | |

**GO for G8:** every applicable cell PASS. B00 and B09 FAIL means exposure: L1 at once.

---

## 8. Monitoring acceptance criteria (P7)

Measured with `famboook:notifications-check` (C), `deploy/scripts/notifications-health.sh` (H), `systemctl` / `journalctl` (S) and read-only `psql` (Q).

| Area | Criterion (all must hold) | Measured by |
|---|---|---|
| **Health — OFF stages (S3–S7)** | H prints `STATE: NOT_ACTIVATED` and exits 0; C ends with `Notifications: no warnings.` and has no `WARN` line | H, C |
| **Health — PILOT (S8–S9)** | H `STATE: HEALTHY`, exit 0; C `Publication readiness: READY`; with D2, `Follow-up readiness: READY`; no `WARN` | H, C |
| **Worker** | `ActiveState=active`; `NRestarts` increases only through `--max-time` (≤ 1 per 60 min ± 5); `journalctl -u famboook-notifications-worker -p warning --since -15min` empty | S |
| **Heartbeat** | C `Worker heartbeat: FRESH`, age ≤ 120 s in steady state (hard limit 300 s = `NOTIFICATIONS_HEARTBEAT_MAX_AGE_SECONDS`); within 3 min after S6 | C |
| **Scheduler** | `systemctl list-timers famboook-scheduler.timer`: last run ≤ 70 s ago; `journalctl -u famboook-scheduler --since -15min`: 15 runs ± 1, none failed; `php artisan schedule:list` = 6 tasks | S |
| **Queue backlog** | `SELECT count(*), coalesce(extract(epoch FROM now()) - min(created_at), 0) FROM jobs WHERE queue = 'notifications';` → count ≤ 2 and oldest ≤ 120 s; `SELECT count(*) FROM jobs WHERE queue <> 'notifications';` → 0 | Q |
| **Failed jobs** | `SELECT count(*) FROM failed_jobs WHERE failed_at > now() - interval '24 hours';` → 0 | Q |
| **Publication** | C `QUEUED` and `PUBLISHING` both 0 outside an active publication; `Stale PUBLISHING (> 600 s): 0`; no `PUBLISH_FAILED` except deliberate tests | C |
| **Task locks** | `journalctl -u famboook-scheduler --since -15min \| grep -c 'famboook:notifications-reconcile'` → 15 ± 1, and the same for `notifications-expire` and `notifications-followups`. Laravel skips a still-locked `withoutOverlapping` task **silently** (an event, no output), so a stuck lock shows only as the task missing from the runs; missing for > 10 min = a stuck lock. Expired rows in `cache_locks` are normal until pruned and are not a criterion | S |
| **Contact Update / OTP** | the first runs report exactly the B5 counts ("Closed contact-update requests: N", "Purged N…"); afterwards N per run is small and explainable | S |
| **Follow-ups (PILOT)** | C `expired_open` 0 after each minute; `open_followups` only for internal households | C, Q |

**Cadence:**
- H every 5 min, automated (D6), exit ≠ 0 alerts the system administrator;
- the full table at each GO check;
- every 15 min in working hours during the first pilot week, then daily.

---

## 9. Irreversible operations and residual risk

| # | Item | Reversible? | Mitigation |
|---|---|---|---|
| I-1 | M1–M5 after the first notification row | no (`down()` refuses) | code-only rollback, tables kept |
| I-2 | Published messages | no unpublish | internal households first |
| I-3 | Follow-up contacts, closures, all events | no (append-only) | internal pilot first |
| I-4 | Contact-update cancellations by the scheduler | no | B5 = 0; recheck at S6 |
| I-5 | OTP purge deletions | no | B5 = 0; recheck; before 00:00 UTC |
| I-6 | Partial migration failure | partly | §4 |
| I-7 | RBAC grants | yes | the old seeder removes them |
| I-8 | Frontend in-place build | yes, slowly | low-traffic window |

**R-1 — restore rehearsal (accurate record):**
- **Evidence for this release:** the latest backup (2026-10-21) passed an **archive catalog check** (`pg_restore --list`). This proves the encrypted archive is readable and lists its objects. **It does not prove that a restore succeeds, is complete, or how long it takes.**
- **The fresh restore rehearsal** (docs/08 §13, `restore-test.sh` into a disposable `_test` database) **was waived by the owner on 2026-10-21** for this release.
- **Last successful restore rehearsal:** not recorded in this repository (UNVERIFIED).
- **Consequence:** if a data restore were ever needed (L5, disaster recovery only), its success and duration are unproven.
- **Accepted by:** the owner (name and date to be recorded in the G2 approval).
- A **fresh backup at S2 remains mandatory**.

---

## 10. Remaining blockers and unverified assumptions

**Blockers before the stages they gate:**

| # | Blocker | Gates |
|---|---|---|
| X1 | C1–C3 implemented locally (ADR §2h) — **commit approval pending**; the committed SHA becomes the candidate and S1's local verification is repeated on it | G1, G6 |
| X2 | C5 implemented locally (ADR §2h) — same commit | G1, G7 |
| X3 | D1 (internal senders and households), D2 (follow-ups), D4 (with B5 counts), D6 (monitoring and alert path) recorded | G5–G7 |
| X4 | Final S0 confirmation (`jobs`, `failed_jobs`, B5, tree clean) | G1 |
| X5 | R-1 acceptance recorded with name and date | G2 |
| X6 | Local verification on the final candidate SHA | G1 |

### 10.1 Owner decisions D1, D2, D4, D6 (proposed values; nothing is approved by listing them)

| # | Decision | Proposed value | Operational impact |
|---|---|---|---|
| **D1** | PILOT participants | **Senders:** 1–2 internal ADMINISTRATOR accounts + 1 coordinator with one BRANCH scope (user ids in `NOTIFICATIONS_PILOT_SENDER_USER_IDS`); no SUPER_ADMIN sending. **Households:** 3–5 Staff-controlled internal households with activated accounts, inside that coordinator's branch, plus 1 accountless internal household to observe follow-ups (family ids in `NOTIFICATIONS_PILOT_FAMILY_IDS`) | Only these senders can publish (others 503), only to these households (others 422). Only these households see the bell (C1), only the listed coordinator sees coordinator screens (C3). Changing either list needs `config:clear` + `optimize` + PHP-FPM reload + worker restart. A household removed from the list keeps its own history visible |
| **D2** | Follow-ups in the PILOT | **Include**, enabled at G7: `NOTIFICATIONS_FOLLOWUPS_ENABLED=true`, `NOTIFICATIONS_FOLLOWUPS_CUTOFF` = the activation instant (ISO-8601 with offset). Pilot messages that should exercise follow-ups expire > 72 h after publication | Follow-ups open only for pilot recipients (bounded by C5): NORMAL after 72 h unviewed, IMPORTANT after 24 h / 72 h unacknowledged. Staff and the primary coordinator must check «المتابعات» daily. Fallback: announcements only for one week, then follow-ups |
| **D4** | Scheduler side effects | **Approve** at G5, after B5 shows the counts (0 / 0 at audit) and with the S2 backup in place; enable in working hours, before 00:00 UTC | Enables the six tasks. The contact-update sweep (live feature, never run) cancels abandoned requests past their window. The OTP purge deletes finished challenges older than 90 days at 00:00 UTC. Both are irreversible but approved retention behaviour. Removing the timer stops them again |
| **D6** | Monitoring and on-call | `notifications-health.sh` every 5 min (by the monitoring agent, or a dedicated root systemd timer — decided by the system administrator), a non-zero exit alerts the system administrator; §8 criteria checked at every gate; a manual check every 15 min in working hours in the first pilot week, then daily | First response to any doubt: release plan L1 (mode OFF). The owner is informed the same day. Requires a named on-call person and a tested alert path before G7 |

**Unverified assumptions:** the Production tree is clean at `bb6cb0d`; PHP-FPM unit `php8.3-fpm` and CLI `/usr/bin/php8.3`; frontend unit `famboook-frontend`; Family login open for the internal households (`famboook:family-auth-check` at S0); the timer's no-overlap behaviour on systemd 255; `systemd-analyze` results; disk and memory for `npm run build`; no other queue consumer.

**First safe deployment checkpoint:**
- **G0 (S0)** — read-only, changes nothing.
- The first change is **G1 (S1)**: it fast-forwards `origin/main` and fetches on the server. It changes no Production file, schema, service or behaviour.
- G1 can proceed on `5d486b3` if C1–C3 are rejected or postponed. If they are approved, G1 waits for the new SHA.
