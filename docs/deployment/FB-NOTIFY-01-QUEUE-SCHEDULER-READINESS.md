# FB-NOTIFY-01 — Production Queue Worker and Scheduler Readiness (systemd)

| | |
|---|---|
| Status | **Templates and procedure only — awaiting approval.** Nothing here has been executed. No Production access, service installation, enabling, starting, queue switch, migration or job processing is authorized by this document (release plan §0) |
| Date | 2026-10-21 |
| Code baseline | `feature/fb-geo-02` at `9b82dfd` + this local, uncommitted consolidation |
| Templates (canonical, one of each) | worker `deploy/systemd/famboook-notifications-worker.service`; scheduler `deploy/systemd/famboook-scheduler.service` + `deploy/systemd/famboook-scheduler.timer`. **No cron template** |
| Static checks | `backend/tests/Feature/Deployment/NotificationOperationsTemplatesTest.php` |
| Related | `FB-NOTIFY-01-PILOT-RELEASE-PLAN.md` (§4–§6, §10 S9–S12, §12); `FB-NOTIFY-01-PILOT-READINESS-CHECKLIST.md` (§5–§8); docs/08 §10 |

## 1. Decisions applied

| # | Decision | Result |
|---|---|---|
| **O1** | Keep `famboook-notifications-worker.service` and its health-check integration | The former `famboook-queue.service` proposal is removed. Its hardening was merged into the approved unit; **`ExecStart` is byte-identical** (`queue:work database --queue=notifications --tries=1 --timeout=60 --sleep=3 --max-time=3600 --memory=256 --no-interaction`), and the queue scope, tries, timeout, sleep, memory, restart and stop timing are unchanged. Added only: `UMask=0007`, `StartLimitIntervalSec=300` / `StartLimitBurst=10`, `PrivateDevices`, `ProtectKernelTunables`, `ProtectKernelModules`, `ProtectControlGroups`, `RestrictSUIDSGID`, `RestrictRealtime`, `LockPersonality`. `KillMode` deliberately stays the default. `notifications-health.sh` (`UNIT="famboook-notifications-worker"`) is unchanged |
| **O2** | One scheduler mechanism: the systemd service + timer | `deploy/cron/famboook-scheduler` is retired (removed). The test fails if `deploy/cron/` reappears or the deploy scripts touch cron. Never add a crontab or `/etc/cron.d` entry for `schedule:run` |
| **O3** | Keep the `database` queue driver | Unchanged. The real Production value of `QUEUE_CONNECTION` (and whether the key exists — `config/queue.php` defaults to `database` when it is absent) **must be read before activation** (§5) |

---

## 2. Scheduled tasks — audit (verified in `backend/routes/console.php` and the commands; times UTC, `config/app.php`)

`schedule:run` evaluates which tasks are due **once, at its start**, then runs them **one after another in one process** (no task uses `runInBackground`).

| # | Task | Exact frequency | Locks | Database effect | Failure risks |
|---|---|---|---|---|---|
| 1 | `famboook:purge-otp-challenges` | `daily()` = 00:00 every day | none | one `DELETE FROM auth_otp_challenges` of rows finished (consumed / superseded / locked / expired, and past any grant) more than `family_auth.retention.otp_challenge_days` (90) ago; open challenges, mobile trust, identities and `auth_security_events` untouched | **irreversible deletion**; the first run removes the whole backlog in one statement (brief lock on that table if it is large); a missed 00:00 (downtime) means no purge that day |
| 2 | `famboook:close-expired-contact-updates` | `everyFiveMinutes()` = :00, :05, … :55 | none | every `change_requests` row of type `CONTACT_UPDATE`, status `SUBMITTED`, `submitted_at` ≤ now − `family_auth.contact_update.request_ttl_seconds` (1800): in its own transaction → `CANCELLED` (cancelled_by NULL), a `workflow_events` CANCELLED row (actor SYSTEM), open OTP challenges superseded, one `auth_security_events` row | **CONTACT_UPDATE is LIVE in Production since 2026-10-11 and its sweep has never run**: the first run cancels the whole backlog; the query is unbounded (`->get()`), so a very large backlog makes that one run long |
| 3 | `NotificationHeartbeatJob` | `everyMinute()` | none | the scheduler only **queues** it (one `jobs` row on `notifications`); the worker writes a cache timestamp | without a worker, one row per minute accumulates in `jobs` (1,440 a day); with `QUEUE_CONNECTION=sync` it runs inline (readiness still refuses `sync`) |
| 4 | `famboook:notifications-reconcile` | `everyMinute()` | `withoutOverlapping(10)`, `onOneServer()` | at most 100 messages per run: stale PUBLISHING → PUBLISH_FAILED, automatic retries → QUEUED, orphaned QUEUED re-dispatched (pauses unless dispatching is safe) | needs the notification tables (no guard: fails every minute before the migrations); exit 1 on a failed step (retried next minute) |
| 5 | `famboook:notifications-expire` | `everyMinute()` | same | at most 100 PUBLISHED → ARCHIVED at `expires_at`, whatever the mode | needs the notification tables |
| 6 | `famboook:notifications-followups` | `everyMinute()` | same | at most 100 recipients per phase: OPEN follow-ups of expired messages → CLOSED EXPIRED (whatever the mode); opens due stages only when the follow-up gate is ready | needs the notification tables; a failing recipient is deferred 10 minutes (shared cache entry) |

## 3. Overlap protection and timer behaviour

**Overlap:**
- **The scheduler never overlaps itself on the host.** The timer starts the `oneshot` service; while a run is still activating, an elapse of the timer does not start a second instance (systemd does not start a unit that is already active or activating). Tasks without locks (1–3) therefore never run twice concurrently. *To verify on the server's systemd version (§7).*
- **Across servers:** a single application server (docs/08 §1). `onOneServer()` on tasks 4–6 also guards a future second server.
- **Tasks 4–6:** `withoutOverlapping(10)` holds a cache mutex with a 10-minute expiry. If a run is killed, the mutex stays until it expires: those tasks are skipped for up to 10 minutes, nothing else. `TimeoutStartSec=540` stops a hung run before that expiry. Both locks need `CACHE_STORE=database` (`cache_locks`).
- **Only one scheduler mechanism exists** (O2). Two schedulers (timer + cron) would run tasks 1–3 twice.

**Timer behaviour:**
- `OnCalendar=*-*-* *:*:00`, `AccuracySec=1s`: a run at second 0 of every minute, in the server's local time — irrelevant, because it fires every minute and Laravel evaluates due tasks in UTC.
- **Downtime (`Persistent=false`):** minutes missed while the server, systemd or the timer was down are **not caught up**. At boot (`WantedBy=timers.target`) or `systemctl start`, the next run is at the next minute boundary, and Laravel runs only what is due in that minute. A missed 00:00 skips that day's OTP purge; the 5-minute and per-minute tasks resume immediately. Exactly cron's behaviour.
- **A long run:** the timer elapse during it is not queued; the next run is the next minute boundary after the run ends. Tasks due only in the skipped minute (task 2 at a five-minute mark) wait for their next due time.
- **Rollback:** `systemctl disable --now famboook-scheduler.timer` stops future runs; a running `schedule:run` finishes or hits `TimeoutStartSec`.

---

## 4. Dependency order (verified against the code)

| Order | Step | Why it must come here |
|---|---|---|
| 1 | FB-NOTIFY-01 **migrations** (release plan S5) | tasks 4–6 have no missing-table guard; the worker's jobs read `notification_messages` |
| 2 | **`CACHE_STORE=database`** confirmed | the heartbeat and the task locks use the shared cache (`cache_locks`) |
| 3 | **Queue switch** `QUEUE_CONNECTION=database` (S9) | the scheduler's heartbeat must be queued, not run inline; `QueueNotificationPublicationDispatcher` refuses `sync`; readiness reports `QUEUE_NOT_ASYNC` otherwise. The worker itself names the `database` connection explicitly |
| 4 | **Worker** started (S10) | before the scheduler, so heartbeat rows never accumulate in `jobs` |
| 5 | **Scheduler** timer enabled (S11) | starts all six tasks; the heartbeat now proves scheduler + worker |
| 6 | **Monitoring** (S12): heartbeat FRESH, `STATE: NOT_ACTIVATED` | `NotificationReadiness::forPublication()` needs an asynchronous queue, valid timing **and** a FRESH heartbeat (≤ 300 s) |
| 7 | **Notification activation** (S14, separate approval) | mode `PILOT`; the follow-up gate additionally needs `NOTIFICATIONS_FOLLOWUPS_ENABLED=true` and a valid cutoff. Nothing before this step publishes or opens anything |

Mode stays `OFF` through steps 1–6. Steps 3–5 change nothing visible to families; tasks 1–2 do (§2) and are therefore covered by D4.

---

## 5. Read-only Production verification (inspection checkpoint AR)

Run only after AR is approved. Record every output. **Nothing below writes**: the SQL runs in a read-only transaction that is rolled back.

**Server and configuration:**
```text
cd /var/www/famboook/backend
<PHP_BINARY> -v                                     # 8.3+
<PHP_BINARY> -m | grep -x pcntl                     # must print pcntl
systemctl --version | head -1
grep -cE '^QUEUE_CONNECTION=' .env                  # 0 = key absent → effective default `database` (O3)
grep -E '^(QUEUE_CONNECTION|DB_QUEUE_RETRY_AFTER|CACHE_STORE|NOTIFICATIONS_MODE|FAMILY_LOGIN_ENABLED|FAMILY_CONTACT_UPDATE_ENABLED)=' .env
php artisan config:show queue.default               # effective value (cached config)
php artisan config:show cache.default
php artisan migrate:status | grep -E '2026_10_2[2-5]_'   # which GEO / notification migrations ran
php artisan schedule:list                           # the six tasks (lists only)
php artisan queue:failed                            # failed jobs (lists only)
php artisan famboook:notifications-check            # read-only; before the migrations it reports the tables missing
systemctl list-unit-files 'famboook-*'              # nothing installed yet
systemctl list-timers --all | grep -i famboook      # no timer yet
ls -l /etc/cron.d/ ; sudo crontab -l -u www-data 2>/dev/null   # no scheduler cron anywhere (O2)
```

**Database** (`psql` as the read-only administrator on `famboook_pilot`):
```sql
BEGIN READ ONLY;

-- Pending jobs (expected: no rows)
SELECT queue, count(*) AS jobs, to_timestamp(min(created_at)) AS oldest, max(attempts) AS max_attempts
  FROM jobs GROUP BY queue ORDER BY queue;
SELECT queue, payload::json->>'displayName' AS job, count(*)
  FROM jobs GROUP BY 1, 2 ORDER BY 1, 2;

-- Failed jobs
SELECT queue, count(*) AS failed, min(failed_at) AS oldest, max(failed_at) AS newest
  FROM failed_jobs GROUP BY queue ORDER BY queue;

-- Expired contact-update requests: cancelled within 5 minutes of enabling the scheduler
-- (1800 = family_auth.contact_update.request_ttl_seconds; use the server's value if overridden)
SELECT count(*) AS to_cancel, min(submitted_at) AS oldest
  FROM change_requests
 WHERE type = 'CONTACT_UPDATE' AND status = 'SUBMITTED'
   AND submitted_at <= now() - interval '1800 seconds';

-- OTP challenges eligible for the purge at the next 00:00 UTC
-- (90 = family_auth.retention.otp_challenge_days; use the server's value if overridden)
SELECT count(*) AS to_delete
  FROM auth_otp_challenges
 WHERE consumed_at   < now() - interval '90 days'
    OR superseded_at < now() - interval '90 days'
    OR locked_at     < now() - interval '90 days'
    OR (consumed_at IS NULL AND superseded_at IS NULL AND locked_at IS NULL
        AND expires_at < now() - interval '90 days'
        AND (grant_expires_at IS NULL OR grant_expires_at < now() - interval '90 days'));

-- Prerequisite tables
SELECT to_regclass('public.jobs') AS jobs, to_regclass('public.failed_jobs') AS failed_jobs,
       to_regclass('public.cache_locks') AS cache_locks, to_regclass('public.notification_messages') AS notifications;

ROLLBACK;
```

**Stop conditions:**
- any row in `jobs` that is not a notification job, or any unexpected count;
- a non-zero `failed_jobs` you cannot explain;
- `pcntl` missing;
- a scheduler cron found anywhere.

Report to the owner; never delete, retry or flush rows without a separate approval.

---

## 6. Rollout, validation, rollback, monitoring

**Rollout** (within release plan S9–S12, mode OFF; each step **[APPROVAL]**):
1. Queue switch: `QUEUE_CONNECTION=database`, `DB_QUEUE_RETRY_AFTER=90`, `CACHE_STORE=database`; `php artisan config:clear && php artisan optimize`; reload PHP-FPM.
2. Worker: replace `<PHP_BINARY>`; copy `famboook-notifications-worker.service` to `/etc/systemd/system/`; `systemd-analyze verify`; `daemon-reload`; `enable --now`; `systemctl is-active` → `active`.
3. Scheduler: replace `<PHP_BINARY>` in the service; copy both files; `systemd-analyze verify` both; `daemon-reload`; `enable --now famboook-scheduler.timer` (the timer, never the service). Do it well before 00:00 UTC, so the first five-minute cycle (task 2) can be watched before the first purge.

**Validation:**
| Check | Expected |
|---|---|
| `systemctl is-active famboook-notifications-worker` | `active`; `journalctl -u famboook-notifications-worker -n 50` clean |
| `systemctl list-timers famboook-scheduler.timer` | next run < 1 min; `journalctl -u famboook-scheduler -n 50` shows each run's tasks, exit 0 |
| `php artisan schedule:list` | six tasks |
| `famboook:notifications-check` | heartbeat FRESH; `NOT READY (MODE_OFF)` only; no warnings |
| `deploy/scripts/notifications-health.sh` | `STATE: NOT_ACTIVATED`, exit 0 |
| after 5 min | §5 contact-update count is 0; the journal's "Closed contact-update requests: N" equals it |
| `jobs` per queue | `notifications` stays at 0–1; nothing else grows |
| `failed_jobs` | no new row |

**Rollback:**
| Situation | Action | Effect |
|---|---|---|
| Worker | `sudo systemctl disable --now famboook-notifications-worker` | jobs wait; heartbeat STALE → publication closes |
| Scheduler | `sudo systemctl disable --now famboook-scheduler.timer` | also stops the OTP purge and contact-update expiry (today's state) |
| Queue back to `sync` | **not automatic** (release plan §12 L3): impact assessment first | — |
| Cannot be undone | CONTACT_UPDATE requests cancelled, OTP challenges purged | approved feature behaviour (D4); counted in §5 first |

**Monitoring:**
- `notifications-health.sh` every 5 min (D6);
- `systemctl --failed` (a worker past its start limit is `failed`; recover with `systemctl reset-failed famboook-notifications-worker && systemctl start …` after fixing the cause);
- `systemctl list-timers` (last and next run);
- `journalctl -u famboook-scheduler --since -1h` (a failed run is logged with its exit code);
- `jobs` growth and `failed_jobs` for `notifications`;
- `cache_locks` rows older than 10 minutes (a stuck task lock).

---

## 7. Risks

| # | Risk | Likelihood / impact | Mitigation |
|---|---|---|---|
| K1 | Existing rows in `jobs` processed unintentionally | low (expected empty) / medium | §5 counts by queue and class; the worker consumes `notifications` only; notification jobs are idempotent and re-check readiness |
| K2 | First scheduler run cancels the CONTACT_UPDATE backlog | certain if any exist / low–medium (approved, overdue behaviour; one long run if large) | §5 count; inform support; enable in working hours; watch the first cycle |
| K3 | First purge deletes the OTP backlog | depends on age / low (approved retention) | §5 count; backup in place (release plan S2); enable before 00:00 UTC |
| K4 | Scheduler before the worker or before the migrations | low / low (heartbeat rows pile up; tasks 4–6 fail each minute) | §4 order; §5 table checks |
| K5 | A second scheduler (cron) installed by habit | low / medium (tasks 1–3 twice) | O2; §5 cron check; the test forbids a cron template |
| K6 | A new queued job lands on `default` and is never processed | medium (future releases) / medium | the test fails when a third `ShouldQueue` class appears |
| K7 | `pcntl` missing | unknown / blocks the worker | §5; install the extension, never drop `--timeout` |
| K8 | Hardening blocks database access | low / medium | `systemd-analyze verify`; first start with mode OFF; rollback = disable |
| K9 | A killed run leaves a task lock for up to 10 min | low / low (tasks 4–6 skipped meanwhile) | `TimeoutStartSec=540`; monitor `cache_locks` |
| K10 | O3: `QUEUE_CONNECTION` absent means `database` already | unknown / low | §5 reads it |

## 8. Not verified here (no Linux host, no Production)

- `systemd-analyze verify` / `security` of the three units;
- the timer's no-overlap behaviour on the server's systemd version;
- `pcntl`, file ownership and the real `.env` values;
- `shellcheck`.

Verified locally: `tests/Feature/Deployment` (18 tests, static only).

## 9. Remaining approval gates

Each approval covers its own row only. Notification activation stays separate (release plan S14).

| # | Gate |
|---|---|
| 1 | Commit this consolidation (worker hardening, scheduler service and timer, cron template removal, test, documents) |
| 2 | Read-only Production inspection (§5, checkpoint AR) |
| 3 | D4 with the §5 counts (CONTACT_UPDATE cancellations, OTP purge) |
| 4 | D5 and the queue switch (§6 step 1) |
| 5 | Worker installation and start, incl. `pcntl` if missing (§6 step 2) |
| 6 | Scheduler timer installation and enable, with its start time (§6 step 3) |
| 7 | Any action on pre-existing `jobs` / `failed_jobs` rows |
| 8 | Monitoring wiring (D6) |
