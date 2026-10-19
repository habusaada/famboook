# FB-NOTIFY-01 — Production PILOT Readiness Checklist (unified notifications)

| | |
|---|---|
| Status | **Documentation only (FB-NOTIFY-01B.2b.7).** Nothing on this page has been executed. No Production access, migration, seeding or activation is authorized by it |
| Code baseline | `feature/fb-geo-02` at `022acc8`, local commits `29a0b12` … `022acc8`; not merged, not deployed |
| Applies to | The first Production PILOT of **manual announcements** (Staff and Coordinator) and the Family inbox |
| Out of scope | SYSTEM notifications (no discovery command exists yet) and follow-ups (tables only; no automation or API; `notification-followup.*` not seeded). Both of their gates stay OFF |
| Related | ADR FB-NOTIFY-01 §2b; docs/08 §16 and §7; docs/06 §22b "Unified notifications"; `docs/audits/FB-NOTIFY-01B-FRONTEND-HANDOFF.md` |

Like docs/08 §16b–§16d, this checklist needs its own deployment plan with commands, approved by the owner, before any step is executed.

---

## 1. Code readiness vs operational readiness

| Area | Code readiness (repository) | Operational readiness (Production) |
|---|---|---|
| Schema | 4 migrations `2026_10_24_090000`–`090003`; 52 PostgreSQL CHECKs, an append-only trigger and the `NTF-` sequence. Verified on `famboook_test` only | **Not migrated**: neither the local `famboook` database nor Production |
| RBAC | 7 permissions + role grants in `RolePermissionSeeder`; `famboook:verify-permissions` checks them exactly | **Not seeded** in Production |
| Domain / API | Lifecycle actions, worker (T1 / T2 / T3), reconciler, expiry sweep, Staff / Coordinator / Family APIs. SQLite and PostgreSQL suites green at `022acc8` | Not deployed |
| Frontend | **None.** Handoff written. B1 resolved for families in FB-NOTIFY-01B.2b.8a (`family_codes`; structure references deferred); B2–B3 open | Not built |
| Queue | `PublishNotificationJob`: 1 try, 60 s timeout; refuses `sync` / `null` | Production template has `QUEUE_CONNECTION=sync`, so it is **not ready** |
| Worker | Heartbeat job plus a readiness gate | **No worker exists** (docs/08 §16) |
| Scheduler | Heartbeat (every minute); reconcile and expire (every minute, `withoutOverlapping(10)`, `onOneServer()`) | **No cron exists** (docs/08 §16) |
| Mode | `NOTIFICATIONS_MODE` defaults to OFF; fails closed | Unset, so OFF |

**Ready to deploy means:** code ready, every operational row verified, and owner approval of the deployment plan. **Code readiness alone is not enough.**

---

## 2. Prerequisites before a PILOT can be proposed

1. **Frontend:** the B1 public family references (`family_codes`, FB-NOTIFY-01B.2b.8a) are in the local code baseline only; they must be merged and deployed with the rest. Branch, branch-group and clan selection stays deferred. The Staff, Coordinator and Family screens are built and reviewed (handoff §12).
2. **Merge:** the branch is merged and released through the normal process, with this feature's commits reviewed.
3. **Production configuration decision:** queue connection, worker supervisor, cron, and the cache store for heartbeat and locks (§5–§7).
4. **Retention** of the notification audit history is confirmed (ADR-07). No deletion job exists, and none may be added without approval.
5. **PILOT senders:** a named, small set of real Staff or Coordinator accounts, chosen by the owner. Their user ids go into the server `.env` only.

---

## 3. Migration impact and rollback constraints

- **Additive only:** 4 new tables (`notification_messages`, `notification_recipients`, `notification_followups`, `notification_recipient_events`) and the sequence `notification_message_code_seq`. No existing table changes, and no backfill.
- **Run them only after a verified backup** (docs/08 §12), during a maintenance window like docs/08 §16c.
- **Rollback:**
  - Before any message exists, `migrate:rollback` of the four migrations is possible.
  - **After any message exists, `down()` refuses** ("Cannot drop notification_messages: notification history exists."). The append-only trigger also blocks UPDATE and DELETE on `notification_recipient_events`.
  - Once the PILOT has created data, rollback is therefore **code-only**: set the mode to OFF and keep the tables. Never drop tables or delete history to roll back.
- **Post-migration checks:** `famboook:notifications-check` reports `Notification tables: present`; with the mode OFF it reports no warnings.

---

## 4. RBAC seeding and verification

- **Seeder:** `php artisan db:seed --class=RolePermissionSeeder --force`, then `php artisan famboook:verify-permissions`, which must exit 0. This is the procedure of docs/08 §7, which `deploy-backend.sh` already runs on every deployment.
- **Expected result:** 8 roles (no new role) and a catalog of 158 permissions:

  | Role | Grants |
  |---|---|
  | SUPER_ADMIN | `announcement.view`, `.create`, `.send`, `.send-all` (98 permissions in total; 99 with the Import Apply gate open) |
  | ADMINISTRATOR | `announcement.view`, `.create`, `.send`, never `send-all` |
  | COORDINATOR | `coordinator-announcement.view`, `.create`, `.send` |
  | DATA_ENTRY, REVIEWER, SOCIAL_WORKER, REPORTS_VIEWER, FAMILY_USER | none of them |

  The verifier also fails on any **direct** (model) grant of a notification permission.
- **Seeding alone activates nothing:** with the mode OFF, publish and retry answer 503. Drafts, however, become possible for the holders as soon as the code and seeding are deployed. Decide whether Staff are briefed before that point.
- **RBAC rollback:** remove the grants only through a reviewed seeder change. Never edit roles by hand in Production.

---

## 5. `NOTIFICATIONS_MODE` and configuration (server `.env`)

| Key | PILOT value | Notes |
|---|---|---|
| `NOTIFICATIONS_MODE` | `OFF` at deploy, then `PILOT` | `ON` is a later, separate approval |
| `NOTIFICATIONS_PILOT_SENDER_USER_IDS` | comma-separated user ids | One malformed entry means nobody. Never commit the value. |
| `NOTIFICATIONS_SYSTEM_DISCOVERY_ENABLED` | `false` | not implemented; SYSTEM needs ON in any case |
| `NOTIFICATIONS_SYSTEM_CUTOFF` | unset | |
| `NOTIFICATIONS_FOLLOWUPS_ENABLED` | `false` | not implemented |
| `NOTIFICATIONS_QUEUE` | `notifications` | the worker must listen on it |
| `NOTIFICATIONS_HEARTBEAT_MAX_AGE_SECONDS` | `300` | 120–3600 |
| `NOTIFICATIONS_AUTOMATIC_PUBLISH_ATTEMPTS` | `3` | 1–3 |
| `NOTIFICATIONS_MANUAL_PUBLISH_RETRIES` | `1` | 0–1; never more than 4 claims in total |
| `NOTIFICATIONS_STALE_PUBLISHING_SECONDS` | `600` | must exceed the queue's `retry_after` |
| `NOTIFICATIONS_MAX_PUBLISH_ATTEMPTS` | **absent** | replaced; the check command warns while it is set |
| Rate limits (`NOTIFICATIONS_*_LIMIT_USER_MINUTE`) | defaults | Staff and coordinator 60 read / 20 write; family 60 read / 30 action |

- **After every `.env` change:** `php artisan config:cache`, then `famboook:notifications-check`.
- **Invalid values fail closed:** the check reports `Configuration: INVALID (…)` and every operation is closed.

---

## 6. Queue connection and dedicated worker

- **Asynchronous queue:** `QUEUE_CONNECTION` must be asynchronous; `database` is recommended, and the `jobs` and `failed_jobs` migrations already exist. **`sync` and `null` are refused.** Readiness reports `QUEUE_NOT_ASYNC`, and the dispatcher dispatches nothing.
- **Timing invariant:** job timeout **60 s** < queue `retry_after` (`DB_QUEUE_RETRY_AFTER`, default **90 s**) < stale threshold **600 s**. Otherwise readiness reports `QUEUE_TIMING_INVALID`.
- **Dedicated worker:** a supervised worker process (systemd or supervisor, restart on failure) running `php artisan queue:work --queue=notifications --tries=1 --timeout=60 --sleep=3 --max-time=3600`, as the application user.
- **Other queues:** other features' jobs do not share this worker unless a later decision says so.
- **On deploy:** `php artisan queue:restart` so the worker loads new code.
- **Failed jobs:** `failed_jobs` keeps operational records (30 days recommended, ADR §2a D9). They are separate from the immutable notification history.

---

## 7. Scheduler and distributed locks

- **Cron:** `* * * * * php /path/to/backend/artisan schedule:run >> /dev/null 2>&1` as the application user. **No cron exists today** (docs/08 §16). It also starts the two existing commands: the daily OTP purge and the 5-minute contact-update expiry. Confirm that this is intended.
- **Scheduled notification tasks:**
  - `NotificationHeartbeatJob`: every minute, queued on `notifications`.
  - `famboook:notifications-reconcile`: every minute, `withoutOverlapping(10)`, `onOneServer()`. It recovers stale `PUBLISHING`, requeues transient failures within the retry policy, and re-dispatches orphaned `QUEUED` messages after 180 s, at most once every 300 s per message.
  - `famboook:notifications-expire`: every minute, same locks; it archives `PUBLISHED` messages whose `expires_at` has been reached.
- **Locks:** `withoutOverlapping` and `onOneServer` use the cache store's atomic locks. The cache store must be **shared and lock-capable**. `CACHE_STORE=database` in the Production template qualifies (it creates the `cache_locks` table); `array` or `file` across several servers does not.
- **Single-server topology:** the Pilot runs on one application server (docs/08 §1). `onOneServer` remains harmless and future-proof.

---

## 8. Heartbeat and health checks

- **Heartbeat:** the cache key `famboook:notifications:worker-heartbeat` holds the time of the last heartbeat job **run by a worker**. A fresh heartbeat (≤ 300 s) proves both the cron and the worker. It must live in the **shared** cache store: a per-process store never shows the worker's beat, so readiness stays closed.
- **`php artisan famboook:notifications-check`:**
  - read-only, counts and codes only; exits 1 on any warning;
  - reports the mode, gates, allow-list size, queue driver, heartbeat state and age, readiness per channel, QUEUED / PUBLISHING / stale counts, and failed jobs on the queue.
- **Expected output in PILOT:**
  - `Notification mode (effective): PILOT`;
  - `Queue connection driver: database (asynchronous)`;
  - `Worker heartbeat: FRESH`;
  - `Publication readiness: READY`;
  - SYSTEM discovery and follow-ups `NOT READY (… DISABLED …)`, which is expected;
  - `Notifications: no warnings.`
- **Monitoring:** run the check periodically (for example every 15 min by the operator, or from monitoring) and alert on exit 1, a STALE or ABSENT heartbeat, a stale `PUBLISHING` count above 0, or failed jobs on `notifications`.
- **Logs** (`storage/logs`) contain message uuids, codes and counts only: "Notification reconciliation", "…paused", and hand-off warnings.

---

## 9. Publication gate checks

The `notifications.publication-ready` middleware (publish and retry), the Domain Actions, and the worker's T1 and T2 all evaluate `NotificationReadiness`. **Verify each gate fails closed before opening the PILOT:**

| Check | Expected |
|---|---|
| Mode OFF | publish / retry → `503 NOTIFICATION_PUBLICATION_UNAVAILABLE`; drafts still work |
| PILOT, sender not allow-listed | 503 for that sender only |
| Worker stopped for longer than the heartbeat age | 503 for everyone; the check reports `HEARTBEAT_STALE` |
| `QUEUE_CONNECTION=sync` | 503; `QUEUE_NOT_ASYNC` |
| Mode switched OFF between the claim and publication | T2 refuses; transient `PUBLICATION_ERROR`; the reconciler pauses while OFF (accepted limitation) |

---

## 10. Smoke tests (after deployment, mode PILOT, synthetic or approved test households only)

1. `famboook:verify-permissions` exits 0; `famboook:notifications-check` reports no warnings.
2. A role-only REVIEWER gets 403 on `GET /api/v1/notifications`; a family-side account gets 403 there.
3. A PILOT ADMINISTRATOR creates a draft for one approved test family through the public family reference. **This requires the B1 family references (`family_codes`, FB-NOTIFY-01B.2b.8a) to be deployed.** Internal database ids are never used, typed or exposed, even for a smoke test. Until they are deployed, this and the following family-level steps cannot be run, and the PILOT is not ready. `audience-preview` returns 1.
4. Publish returns 202. Within about a minute the detail shows `PUBLISHED` with `recipient_count_at_publication = 1`.
5. The test household sees it in the inbox (`unread_count` 1). Opening the detail and its view POST brings the count to 0. For an IMPORTANT test message, acknowledge and check that the sender's `statistics` show 1 acknowledged.
6. Cancel a second draft, and check that it never appears to the family.
7. A non-allow-listed sender gets 503 on publish.
8. A message with `expires_at` about 1 h ahead is archived by the sweep after expiry and appears in the family archive.
9. `failed_jobs` has no entry for the `notifications` queue.

---

## 11. Rollback and shutdown plan

| Situation | Action | Effect |
|---|---|---|
| Stop new publications | `NOTIFICATIONS_MODE=OFF`, `config:cache` | publish / retry return 503; QUEUED messages are not claimed (T1 pauses); the inbox keeps working |
| Worker problem | stop the worker; mode OFF | messages stay QUEUED or are recovered as stale; nothing partial is visible |
| Withdraw a queued message | the sender cancels it (allowed even when OFF) | `CANCELLED`, never published |
| Wrong published message | **no unpublish exists**; a published message is never cancelled | needs a product decision (for example a corrective announcement). Optionally, set a short expiry at authoring time for pilot messages |
| Code rollback | redeploy the previous release; keep the tables (the `down()` guard) | the frontend must not reference the routes |
| RBAC rollback | reviewed seeder change + verify | — |
| Full deactivation | mode OFF + stop the worker; **keep** the cron if other scheduled commands depend on it | history is preserved |

---

## 12. Sign-off

- [ ] Code readiness confirmed (merged commit, suites green on PostgreSQL)
- [ ] B1 public family references deployed (structure references deferred); frontend built; B2–B3 resolved or explicitly accepted
- [ ] Backup verified; migrations applied; check reports tables present
- [ ] RBAC seeded; verifier exits 0
- [ ] Queue asynchronous; dedicated worker supervised; `queue:restart` in the deploy procedure
- [ ] Cron installed; locks on a shared, lock-capable cache store
- [ ] Heartbeat FRESH; publication readiness READY in PILOT
- [ ] Gate fail-closed checks (§9) done
- [ ] Smoke tests (§10) passed
- [ ] Rollback plan (§11) rehearsed or acknowledged
- [ ] Owner approval recorded
