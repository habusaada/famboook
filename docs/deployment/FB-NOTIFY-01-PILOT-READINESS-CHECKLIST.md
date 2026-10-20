# FB-NOTIFY-01 — Production PILOT Readiness Checklist and Runbook (unified notifications)

| | |
|---|---|
| Status | **Documentation and templates only (FB-NOTIFY-01B.2b.7, updated FB-NOTIFY-01E.1).** Nothing on this page has been executed. No Production access, migration, seeding, service installation or activation is authorized by it. **Production is NOT ready**: no row below is verified live |
| Code baseline | `feature/fb-geo-02` at `65d670a` (backend `29a0b12` … `d6e4354`, frontend `8f47715` … `65d670a`); not merged, not deployed. Operations templates: FB-NOTIFY-01E.1 (local, uncommitted at the time of writing) |
| Applies to | The first Production PILOT of **manual announcements** (Staff and Coordinator) and the Family inbox |
| Out of scope | SYSTEM notifications (no producer exists) and follow-ups (table only; no engine, API or seeded permission). Both gates stay OFF |
| Related | ADR FB-NOTIFY-01 §2b; docs/08 §16, §7, §10, §12; docs/06 §22b "Unified notifications"; `docs/audits/FB-NOTIFY-01B-FRONTEND-HANDOFF.md`; templates in `deploy/` (§6–§8) |

**Labels.** **[APPROVAL]** = a separate, explicit Production approval by the owner is required before the step, recorded in the deployment plan. **[VERIFY]** = a live check whose output is recorded. Like docs/08 §16b–§16d, the whole runbook needs its own approved deployment plan with exact commands; nothing here is pre-approved.

---

## 1. Code readiness vs operational readiness

| Area | Code readiness (repository) | Operational readiness (Production) |
|---|---|---|
| Schema | 4 migrations `2026_10_24_090000`–`090003`; PostgreSQL CHECKs, append-only trigger, `NTF-` sequence; guarded `down()` | **Not migrated.** `deploy-backend.sh` runs `migrate --force` on EVERY deployment, so deploying this code migrates them |
| RBAC | 7 permissions + role grants; `famboook:verify-permissions` checks them exactly | **Not seeded.** `deploy-backend.sh` seeds and verifies on every deployment |
| Domain / API | Actions, worker (T1 / T2 / T3), reconciler, expiry sweep, Staff / Coordinator / Family APIs; SQLite and PostgreSQL suites green | Not deployed |
| Frontend | Family inbox and bell; Staff `/announcements`; Coordinator history (role-only) and compose (scope) | Not deployed |
| Queue | Jobs refuse `sync` / `null`; timing invariant fails closed | Template keeps `QUEUE_CONNECTION=sync` — **not ready** |
| Worker | `deploy/systemd/famboook-notifications-worker.service` (template) | **Not installed** |
| Scheduler | `deploy/cron/famboook-scheduler` (template) | **Not installed** |
| Monitoring | `famboook:notifications-check`, `deploy/scripts/notifications-health.sh` (read-only) | **Not wired** to any alert destination |
| Mode | `NOTIFICATIONS_MODE` defaults to OFF; fails closed | Template: `OFF` |

**Ready means:** code ready, every operational row verified live, and owner approval of the deployment plan. Passing tests never prove operational readiness.

---

## 2. Decisions required before a PILOT can be proposed

| # | Decision | Owner |
|---|---|---|
| D1 | The PILOT senders: a small named set of Staff / Coordinator accounts (user ids only in the server `.env`) | Product owner |
| D2 | PILOT without follow-ups (the 24 h / 72 h engine is not implemented) — or wait for it | Product owner |
| D3 | Audit retention for notification history (ADR-07); no deletion job exists | Product owner |
| D4 | Enabling the scheduler ALSO starts the OTP purge (daily, **deletes** finished OTP challenges older than 90 days) and the contact-update expiry (every 5 min) — §7 | Product owner + system administrator |
| D5 | The queue switch `sync` → `database` for the whole application (§5) | System administrator |
| D6 | Monitoring destination and on-call for the alerts of §8 | System administrator |

---

## 3. Runbook — order of operations

| Step | Action | Label |
|---|---|---|
| R1 | **Backup**: a fresh encrypted backup (`deploy/scripts/backup-db.sh`, docs/08 §12) and a successful restore test (`restore-test.sh`, docs/08 §13) | **[APPROVAL] [VERIFY]** |
| R2 | **Backend first**: `deploy-backend.sh` (maintenance mode → composer → `migrate --force` → `RolePermissionSeeder` → `verify-permissions` → `optimize` → `queue:restart` → up). `NOTIFICATIONS_MODE=OFF`, `QUEUE_CONNECTION=sync` unchanged | **[APPROVAL]** |
| R3 | **Migration verification**: `php artisan migrate:status` lists `2026_10_24_090000`–`090003` as Ran; `famboook:notifications-check` reports `Notification tables: present` and **no warnings** (mode OFF) | **[VERIFY]** |
| R4 | **Permission verification**: `famboook:verify-permissions` exit 0 (§4) | **[VERIFY]** |
| R5 | **Frontend second**: `deploy-frontend.sh`; the bell / inbox / announcement routes answer (empty, mode OFF) | **[APPROVAL] [VERIFY]** |
| R6 | **Queue**: server `.env` `QUEUE_CONNECTION=database`, `DB_QUEUE_RETRY_AFTER=90`; `config:clear` + `optimize` + reload PHP-FPM (§5) | **[APPROVAL]** |
| R7 | **Worker**: install, `systemd-analyze verify`, enable and start `famboook-notifications-worker` (§6) | **[APPROVAL] [VERIFY]** |
| R8 | **Scheduler**: after D4, install `/etc/cron.d/famboook-scheduler` (§7) | **[APPROVAL] [VERIFY]** |
| R9 | **Monitoring**: `notifications-health.sh` → `STATE: NOT_ACTIVATED`, exit 0, worker unit `active`; heartbeat FRESH; publication readiness `NOT READY (MODE_OFF)` only; alert wiring per D6 (§8) | **[APPROVAL] [VERIFY]** |
| R10 | **Gate checks** with the mode still OFF (§9) | **[VERIFY]** |
| R11 | **PILOT allow-list**: `NOTIFICATIONS_PILOT_SENDER_USER_IDS` per D1 (still OFF) | **[APPROVAL]** |
| R12 | **Activation — the LAST step, separately approved**: `NOTIFICATIONS_MODE=PILOT`; `config:clear` + `optimize` + reload PHP-FPM + `systemctl restart famboook-notifications-worker`; `famboook:notifications-check` → `Publication readiness: READY`, no warnings; `notifications-health.sh` → `STATE: HEALTHY` | **[APPROVAL] [VERIFY]** |
| R13 | **Smoke tests** (§10) with synthetic / approved test households only | **[VERIFY]** |

Any failed [VERIFY] stops the runbook; go to §11 (rollback to OFF).

---

## 4. RBAC seeding and verification

- **Automatic:** `deploy-backend.sh` always runs `db:seed --class=RolePermissionSeeder --force` then `famboook:verify-permissions` (docs/08 §7).
- **Expected result:** 8 roles (no new role) and a catalog of 162 permissions (158 before FB-NOTIFY-01E.3-F1 added the four follow-up permissions):

  | Role | Grants |
  |---|---|
  | SUPER_ADMIN | `announcement.view`, `.create`, `.send`, `.send-all`; `notification-followup.view`, `.manage` (100 permissions in total; 101 with the Import Apply gate open) |
  | ADMINISTRATOR | `announcement.view`, `.create`, `.send`, never `send-all`; `notification-followup.view`, `.manage` |
  | COORDINATOR | `coordinator-announcement.view`, `.create`, `.send`; `coordinator-notification-followup.view`, `.manage` |
  | DATA_ENTRY, REVIEWER, SOCIAL_WORKER, REPORTS_VIEWER, FAMILY_USER | none of them |

  The follow-up permissions authorize no route yet: nothing opens or lists follow-ups until that stage is built and approved.

  The verifier also fails on any **direct** (model) grant of a notification permission.
- **Seeding activates nothing**, but drafts become possible for the holders at R2 (publish / retry answer 503 while OFF). Brief the PILOT senders before R2.
- **RBAC rollback:** only through a reviewed seeder change. Never edit roles by hand in Production.

---

## 5. Configuration (server `.env`) and the queue switch

Template: `deploy/env/backend.production.env.example` (notifications block at the end; queue block under "CACHE_STORE").

| Key | Deploy (R2) | PILOT (R6 / R11 / R12) | Notes |
|---|---|---|---|
| `QUEUE_CONNECTION` | `sync` | `database` | R6; see the global impact below |
| `DB_QUEUE_RETRY_AFTER` | — (default 90) | `90` | must stay > 60 and < 600 |
| `CACHE_STORE` | `database` | `database` | shared, lock-capable (heartbeat, `withoutOverlapping`, `onOneServer`) |
| `NOTIFICATIONS_MODE` | `OFF` | `PILOT` at R12 only | `ON` is a later, separate approval |
| `NOTIFICATIONS_PILOT_SENDER_USER_IDS` | empty | D1 ids | one malformed entry = nobody; never committed |
| `NOTIFICATIONS_SYSTEM_DISCOVERY_ENABLED` / `_FOLLOWUPS_ENABLED` | `false` | `false` | not implemented |
| `NOTIFICATIONS_FOLLOWUPS_CUTOFF` | unset | unset | follow-ups only (FB-NOTIFY-01E.3-F1); see the note below |
| `NOTIFICATIONS_QUEUE`, `_HEARTBEAT_MAX_AGE_SECONDS`, `_AUTOMATIC_PUBLISH_ATTEMPTS`, `_MANUAL_PUBLISH_RETRIES`, `_STALE_PUBLISHING_SECONDS` | defaults (`notifications`, 300, 3, 1, 600) | defaults | invalid values fail closed |
| `NOTIFICATIONS_MAX_PUBLISH_ATTEMPTS` | absent | absent | replaced; the check warns while set |

**Follow-up cutoff `NOTIFICATIONS_FOLLOWUPS_CUTOFF` (FB-NOTIFY-01E.3-F1; not needed for the announcement PILOT):**
- **Format:** a valid offset-aware ISO-8601 timestamp, e.g. `2026-11-01T00:00:00+02:00`. A date without time, no offset or an impossible date (`2026-02-30`) is INVALID: `famboook:notifications-check` warns, and follow-up readiness reports `FOLLOWUPS_CUTOFF_INVALID`. Unset reports `FOLLOWUPS_CUTOFF_MISSING`.
- **No backfill:** recipients published before the cutoff never get a follow-up.
- **No activation:** a cutoff alone activates nothing. Follow-ups also need `NOTIFICATIONS_FOLLOWUPS_ENABLED=true`, a mode other than `OFF` and a ready worker.
- **Gate stays OFF:** `NOTIFICATIONS_FOLLOWUPS_ENABLED` stays `false` until follow-ups are built, verified and separately approved. Setting the cutoff is part of that later approval, not of this PILOT.
- **D1–D6 unchanged:** the PILOT decisions D1–D6 (§2) remain unresolved; the cutoff resolves none of them.

**Global impact of `QUEUE_CONNECTION=database` (verified in the code at `65d670a`):**
- The ONLY queued jobs are `PublishNotificationJob` and `NotificationHeartbeatJob`, both on the `notifications` queue.
- SMS (OTP, contact update) is sent after the response inside the PHP-FPM process and is never queued — unaffected. Mail is `log` — unaffected.
- Jobs are stored in the existing `jobs` table; failures in `failed_jobs` (migrations `0001_01_01_000002`). Nothing runs them except a `queue:work` process: with the switch but WITHOUT the worker, jobs would wait in `jobs` — hence R6 and R7 go together.
- A future feature that adds queued jobs would also go to `jobs`; the notification worker only consumes `notifications`, so such jobs would need their own worker decision.

**After every `.env` change:** `php artisan config:clear && php artisan optimize`, reload PHP-FPM, `sudo systemctl restart famboook-notifications-worker` (if installed), then `famboook:notifications-check`. Invalid values fail closed (`Configuration: INVALID (…)`).

---

## 6. Dedicated worker

Template: `deploy/systemd/famboook-notifications-worker.service` (**[APPROVAL]** to install).

- `queue:work database --queue=notifications --tries=1 --timeout=60 --sleep=3 --max-time=3600 --memory=256`, as **`www-data`** (the PHP-FPM account; reads `backend/.env` through the group, writes `storage/`), in `/var/www/famboook/backend`, `Restart=always`, logs to the journal (`journalctl -u famboook-notifications-worker`), `TimeoutStopSec=75` so a running publication can finish. No secrets in the unit. Replace `<PHP_BINARY>` with the server's PHP 8.3+ CLI.
- **Timing invariant:** job / worker timeout **60 s** < `retry_after` **90 s** < stale threshold **600 s** (`NotificationConfig::queueTimingValid`; readiness reports `QUEUE_TIMING_INVALID` otherwise). The repository test `NotificationOperationsTemplatesTest` keeps the template in line with the code.
- **Maintenance mode:** the worker has no `--force`, so it pauses while `deploy-backend.sh` holds the application down.
- **Deployments:** `deploy-backend.sh` runs `php artisan queue:restart` after `optimize` (non-fatal: on failure it warns; restart the unit by hand). The worker exits after its current job and systemd restarts it on the new code.
- **Pre-install checks on the Linux server** (not available on the development workstation, so not yet run):
  ```text
  <PHP_BINARY> -v                                   # PHP 8.3+
  <PHP_BINARY> -m | grep -x pcntl                   # REQUIRED: --timeout and graceful SIGTERM depend on it
  sudo -u www-data test -r /var/www/famboook/backend/.env && echo env-readable
  sudo -u www-data test -w /var/www/famboook/backend/storage/logs && echo storage-writable
  shellcheck deploy/scripts/deploy-backend.sh deploy/scripts/notifications-health.sh
  ```
  Replace `<PHP_BINARY>` in the unit first; `systemd-analyze verify` refuses the placeholder. The hardening (`ProtectSystem=full`, `ProtectHome=true`, `PrivateTmp=true`, `NoNewPrivileges=true`) leaves `/var/www`, `/run` (a PostgreSQL socket) and TCP to the database untouched; the application's cache and locks are in the database.
- **Install / verify / remove:**
  ```text
  sudo cp deploy/systemd/famboook-notifications-worker.service /etc/systemd/system/
  sudo systemd-analyze verify /etc/systemd/system/famboook-notifications-worker.service
  sudo systemctl daemon-reload && sudo systemctl enable --now famboook-notifications-worker
  systemctl is-active famboook-notifications-worker            # active
  # remove: sudo systemctl disable --now famboook-notifications-worker
  ```
- **Failed jobs:** `failed_jobs` rows are operational records (30 days recommended, ADR §2a D9), separate from the immutable notification history. Never `queue:retry` a notification job: retries are `NotificationRetryPolicy`'s.

---

## 7. Scheduler and its side effects

Template: `deploy/cron/famboook-scheduler` (`/etc/cron.d` format, `www-data`, every minute; **[APPROVAL]** to install, after D4).

Enabling it starts **every** task in `backend/routes/console.php` (verified at `65d670a`; times in UTC, `config/app.php` timezone):

| Task | When | Effect | Prerequisite |
|---|---|---|---|
| `famboook:purge-otp-challenges` | daily 00:00 | **Deletes** finished OTP challenges (consumed / superseded / locked / expired) older than `FAMILY_OTP_RETENTION_DAYS` (90). Open challenges, mobile trust history and security events are never touched. Its FIRST run deletes the whole backlog older than 90 days | **D4 approval** (data deletion) |
| `famboook:close-expired-contact-updates` | every 5 min | `SUBMITTED` `CONTACT_UPDATE` requests past their verification window → `CANCELLED` by SYSTEM; codes superseded. With `FAMILY_CONTACT_UPDATE_ENABLED=false` there are normally none | D4 approval |
| `NotificationHeartbeatJob` | every minute | queues the heartbeat; with `sync` it would run inline and still never make readiness pass | the worker (R7) |
| `famboook:notifications-reconcile` | every minute, `withoutOverlapping(10)`, `onOneServer` | stale-claim recovery (runs even when OFF); retries and re-dispatch pause unless dispatching is safe | shared cache locks |
| `famboook:notifications-expire` | every minute, same locks | `PUBLISHED` → `ARCHIVED` at `expires_at` (runs whatever the mode) | shared cache locks |

- **Locks:** `withoutOverlapping` / `onOneServer` need the atomic locks of a shared cache store: `CACHE_STORE=database` with the `cache_locks` table (migration `0001_01_01_000001`). Single application server (docs/08 §1).
- **Pre-install checks:** replace `<PHP_BINARY>`; the file must be owned by root, mode 644, end with a newline, and its name must contain no dot (cron.d ignores such files). After installing, confirm it is picked up: `grep CRON /var/log/syslog` or `journalctl -u cron` shows `(www-data) CMD (cd /var/www/famboook/backend && … schedule:run …)` within a minute.
- **Install / verify / remove:**
  ```text
  sudo cp deploy/cron/famboook-scheduler /etc/cron.d/famboook-scheduler && sudo chmod 644 /etc/cron.d/famboook-scheduler
  php artisan schedule:list                                     # the five tasks above
  # remove: sudo rm /etc/cron.d/famboook-scheduler
  ```

---

## 8. Monitoring (read-only)

Procedure: `deploy/scripts/notifications-health.sh` (read-only; `PHP_BIN=/usr/bin/php8.3` if `php` is not the 8.3+ CLI). It ends with one `STATE:` line. Wiring its exit code to an alert destination is **[APPROVAL]** (D6).

| State | Exit | When | Alert? |
|---|---|---|---|
| `NOT_ACTIVATED` | 0 | `NOTIFICATIONS_MODE=OFF` and no check warning — a stopped or absent worker is expected while OFF | no |
| `HEALTHY` | 0 | PILOT / ON, no warning, worker unit `active` (or `systemctl` unavailable, then the FRESH heartbeat proves a worker) | no |
| `DEGRADED` | 1 | any check warning (any mode), or PILOT / ON with the worker unit not `active` | yes |
| `UNAVAILABLE` | 2 | the check could not run (PHP, application or database failure) | yes |

Not covered by the script: messages in `PUBLISH_FAILED` (the check does not count them; senders see them, and transient errors are logged as "Notification publication failed.").

| Condition | How it shows | Operator response |
|---|---|---|
| Worker down (PILOT / ON) | `DEGRADED`; worker unit not active, then `Worker heartbeat: STALE / ABSENT`; publish → 503 | `journalctl -u famboook-notifications-worker`; `systemctl restart …`; if it keeps failing, mode OFF |
| Scheduler down | heartbeat STALE although the worker is active; stale counts grow | check `/etc/cron.d/famboook-scheduler`, cron service, `php artisan schedule:list` |
| Stale publications | `Stale PUBLISHING (> 600 s): N` > 0 | the reconciler recovers them each minute; if N persists, check the scheduler and locks |
| Failed notification jobs | `Failed jobs (notification queue): N` > 0 | read the exception class in `failed_jobs` / logs; do NOT `queue:retry`; the message state is authoritative |
| Publication failures | NOT in the health script: senders' details show the code; `storage/logs` has "Notification publication failed." | transient ones are retried automatically; permanent ones need the sender to cancel; repeated `PUBLICATION_ERROR` → logs, possibly mode OFF |
| Configuration invalid | check exit 1 with `Configuration: INVALID (keys)` | fix the server `.env`; `config:clear` + `optimize` |

Run it at least every 15 minutes during the PILOT. It prints counts and codes only — no ids, names or content.

---

## 9. Publication gate checks (R10 with OFF; repeat after R12)

| Check | Expected |
|---|---|
| Mode OFF | publish / retry → `503 NOTIFICATION_PUBLICATION_UNAVAILABLE`; drafts still work |
| PILOT, sender not allow-listed | 503 for that sender only |
| Worker stopped for longer than the heartbeat age | 503 for everyone; the check reports `HEARTBEAT_STALE` |
| `QUEUE_CONNECTION=sync` | 503; `QUEUE_NOT_ASYNC` |
| Mode switched OFF between the claim and publication | T2 refuses; transient `PUBLICATION_ERROR`; the reconciler pauses while OFF (accepted limitation) |

---

## 10. Smoke tests (R13; synthetic or approved test households only)

1. `famboook:verify-permissions` exits 0; `notifications-health.sh` → `STATE: HEALTHY`.
2. A role-only REVIEWER gets 403 on `GET /api/v1/notifications`; a family-side account gets 403 there.
3. A PILOT ADMINISTRATOR picks one approved test family by its family code in `/announcements/new`; the recipient count is 1. Internal database ids are never used, typed or exposed.
4. Publish returns 202 («في انتظار النشر»); within about a minute the detail shows `PUBLISHED` with `recipient_count_at_publication = 1`.
5. The test household sees it in the inbox and the bell (unread 1); opening the detail brings it to 0. For an IMPORTANT message, acknowledge; the sender's statistics show 1 acknowledged.
6. Cancel a second draft; it never appears to the family.
7. A non-allow-listed sender gets the 503 message on publish.
8. A message with `expires_at` about 1 h ahead is archived by the sweep after expiry and appears in the family archive.
9. `failed_jobs` has no entry for the `notifications` queue.

---

## 11. Rollback

| Situation | Action | Effect |
|---|---|---|
| **Back to OFF (the default response)** | `NOTIFICATIONS_MODE=OFF`; `config:clear` + `optimize`; reload PHP-FPM; restart the worker | publish / retry → 503; QUEUED messages are not claimed (T1 pauses); inbox and history keep working; stale recovery and expiry continue |
| Worker problem | stop the unit; mode OFF | messages stay QUEUED or are recovered as stale; nothing partial is ever visible |
| Scheduler problem | remove `/etc/cron.d/famboook-scheduler` | ALSO stops the OTP purge and contact-update expiry; heartbeat goes stale → publication closes (503) |
| Queue back to `sync` | `QUEUE_CONNECTION=sync` + caches + reload | publication closes (503); queued jobs stay in `jobs` until a worker runs again |
| Withdraw a queued message | the sender cancels it (allowed even when OFF) | `CANCELLED`, never published |
| Wrong published message | **no unpublish exists** | product decision (e.g. a corrective announcement); consider a short expiry for pilot messages |
| Code rollback | redeploy the previous release | see the data-preserving limits below |
| RBAC rollback | reviewed seeder change + verify | — |

**Data-preserving limits.** The four tables are additive. Before any message exists, the migrations can be rolled back. **Once any message exists, `down()` refuses**, and `notification_recipient_events` is append-only (trigger): rollback is then code-only with the mode OFF and the tables kept. Never drop tables or delete notification history to roll back; restoring the R1 backup would lose every later write of the whole application and is a disaster-recovery decision, not a feature rollback.

---

## 12. Sign-off

- [ ] D1–D6 decided and recorded
- [ ] R1 backup and restore test verified
- [ ] R2–R4 backend deployed; migrations and permissions verified
- [ ] R5 frontend deployed after the backend
- [ ] R6 queue switched; R7 worker active and `systemd-analyze verify` clean
- [ ] R8 scheduler installed after D4; `schedule:list` shows the five tasks
- [ ] R9 monitoring verified; heartbeat FRESH
- [ ] R10 gate checks with OFF
- [ ] R11 allow-list set; R12 activation separately approved; check READY, no warnings
- [ ] R13 smoke tests passed
- [ ] §11 rollback to OFF rehearsed or acknowledged
- [ ] Owner approval recorded for every [APPROVAL] step
