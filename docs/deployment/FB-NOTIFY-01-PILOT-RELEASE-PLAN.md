# FB-NOTIFY-01 — PILOT Release Decision Document (announcements + follow-ups)

| | |
|---|---|
| Status | **PLAN ONLY — awaiting owner approval.** Nothing here has been executed. No Production access, merge, push, migration, seeding, queue switch, service installation, login activation or notification activation is authorized by this document |
| Date | 2026-10-21 |
| Code baseline | `feature/fb-geo-02` at `4d2181c` (local) |
| Supersedes / consolidates | the FB-NOTIFY-01 PILOT readiness checklist (`FB-NOTIFY-01-PILOT-READINESS-CHECKLIST.md`), which remains the detailed runbook for §5–§11 there. This document adds follow-ups, the release coupling with FB-GEO and the access prerequisites, and corrects the checklist where it is stale (§13) |
| Audience | Product owner (decisions) and system administrator (execution) |

Labels as in the checklist: **[APPROVAL]** needs an explicit, recorded owner approval before the step; **[VERIFY]** is a live check whose output is recorded. Any failed [VERIFY] stops the procedure.

## 0. What approving this document means

- **Approving this document (A0) approves the plan only.** It authorizes no Production step: no Production access (not even read-only), merge, push, deployment, migration, seeding, `.env` change, queue switch, worker or cron installation, login activation or notification activation.
- **Every Production step needs its own explicit, recorded approval** at the time it is executed, as labelled [APPROVAL] in §10 and listed in §16. An earlier approval never carries over to a later step.
- **These stay separate decisions**, each approved on its own and never implied by A0 or by each other:
  1. the FB-GEO-01 / FB-GEO-02 release (Release 1) and any pending earlier release (Release 0);
  2. Family login and activation in Production (docs/08 §16a);
  3. applying the Production migrations of each release;
  4. installing infrastructure: the queue switch, the worker and the scheduler timer;
  5. activating notifications and follow-ups (mode PILOT, follow-up gate, cutoff).
- Every existing gate and safety rule of the readiness checklist, docs/08 and the FB-GEO-01 plan still applies; this document removes none of them.

---

## 1. Release content and dependency chain

### 1.1 What Production would receive

Production's current commit and migration state are **not recorded in the repository**. Step S0 (§10) reads them, under its own read-only authorization (AR). The chain on top of `origin/main` is:

| Layer | Range | Commits | State | Migrations |
|---|---|---|---|---|
| A. FB-GEO-01 | on `origin/main` (`e5303c1`) | — | on `main`; **Production plan unexecuted, not approved** (`FB-GEO-01-PRODUCTION-DEPLOYMENT-PLAN.md`) | `2026_10_22_090000`–`090003` (+ the MoSD reference import) |
| B. FB-GEO-02 / GEO-03 / UI | `origin/main..origin/feature/fb-geo-02` (`4a19b71` … `bb6cb0d`) | 8 | pushed, **not merged** | `2026_10_23_090000` (drops `chk_residence_displacement_location`) |
| C. FB-NOTIFY-01 | `origin/feature/fb-geo-02..4d2181c` (`45130ff` … `4d2181c`) | 27 | **local only, not pushed** | `2026_10_24_090000`–`090003`, `2026_10_25_090000` |

Layer C cannot be deployed without A and B: it is built on them, and `deploy-backend.sh` runs `migrate --force`, which applies **every** pending migration.

Earlier releases may also be pending on Production (FB-GEO-01 plan D5): CO-01C `2026_10_19_090000`, CO-01D.4 `2026_10_20_090000` (its own §16c audit gate) and CO-02 `2026_10_21_090000` (docs/08 §16d, "Ready for Review"). If S0 shows any of them pending, they go first under their own approvals.

### 1.2 Layer C commits (27, oldest first)

`45130ff` `3437df5` docs (architecture, design alignment) · `29a0b12` `50419d3` schema · `60f62bd` gates · `d71f595` audience / events · `da77d93` lifecycle actions · `281ec85` retry policy · `5246484` worker · `27890a1` reconciliation · `6fadbd7` family API · `3ee4958` Staff / Coordinator APIs · `022acc8` RBAC · `2de1874` docs · `d6e4354` public family references · `8f47715` family UI · `2db3e07` coordinator route guards · `a880ff1` Staff UI · `65d670a` Coordinator UI · `19bea0e` operations templates · `a8a1d2a` follow-up ownership + permissions · `1521a7e` follow-up engine · `8fe5c19` management actions · `6353df7` authorization serialization · `9eb5869` follow-up APIs · `663a7ff` follow-up UI · `4d2181c` end-to-end PILOT test.

### 1.3 Recommended release sequence (each its own approval)

1. **Release 0 (only if S0 shows them pending):** CO-01 / CO-01D.4 / CO-02 per docs/08 §16c–§16d.
2. **Release 1 — FB-GEO:** merge layer B to `main`, then execute the FB-GEO-01 plan, extended by the one GEO-02 migration (§2). Smoke-check and settle.
3. **Release 2 — FB-NOTIFY-01 (this document):** push and merge layer C, deploy **closed** (mode OFF), install the infrastructure, then activate separately.

Never combine Release 1 and Release 2 in one deployment: a failure would be ambiguous and the rollback paths differ.

---

## 2. Migrations

### 2.1 FB-GEO (Release 1)

| Migration | Effect | Approved? | Safety |
|---|---|---|---|
| `2026_10_22_090000` / `090001` / `090002` | new geography tables (locations, review flags, import runs) | ADR FB-GEO-01 approved; **Production plan awaiting approval (A0)** | additive; `down()` drops them |
| `2026_10_22_090003` | nullable location references on `family_residences` | same | additive; `lock_timeout` 5 s; rollback refuses once a reference is saved |
| `2026_10_23_090000` | drops PostgreSQL CHECK `chk_residence_displacement_location` (historical displacement text kept) | ADR FB-GEO-02 approved by the owner 2026-10-17, **not deployed; no Production plan yet** | schema only, no row touched; `lock_timeout` 5 s; `down()` refuses while a non-displaced residence keeps a historical text |

**Verdict:** safe as designed and covered by the FB-GEO tests, but **not approved for Production**. The FB-GEO-01 plan (§12 D1–D6, workbook distribution rights D3, PostgreSQL version parity D4) must be approved and extended with `2026_10_23_090000` before Release 1.

### 2.2 FB-NOTIFY-01 (Release 2)

| Migration | Effect | `down()` |
|---|---|---|
| `2026_10_24_090000` | `notification_messages`; `NTF-` sequence; about 27 CHECKs; unique `uuid`, `notice_code`, `origin_workflow_event_id`, (`sender_user_id`, `client_reference`) | refuses once any message exists |
| `2026_10_24_090001` | `notification_recipients`; unique (`message_id`, `family_id`) | refuses once any row exists |
| `2026_10_24_090002` | `notification_followups`; unique (`recipient_id`, `stage`) | refuses once any row exists |
| `2026_10_24_090003` | `notification_recipient_events`; append-only trigger | refuses once any event exists |
| `2026_10_25_090000` | nullable `client_reference` + partial unique index; CHECK `chk_nre_client_reference` | refuses once any reference is stored |

All five are **additive**: no existing table or row changes. Before any message exists they can be rolled back; afterwards rollback is code-only with the mode OFF and the tables kept. Verified on PostgreSQL (`NotificationSchemaTest`, `FollowupApiPostgresTest` migration test) — never on a Production copy yet (S3 rehearsal below).

---

## 3. Backup and verified restore

Per docs/08 §12–§13, before **each** release:
1. `deploy/scripts/backup-db.sh`: fresh encrypted dump; confirm the `.dump.age` and `.sha256` exist, `pg_restore --list` succeeds, and the off-server copy is done. **[VERIFY]**
2. `deploy/scripts/restore-test.sh` into a disposable database whose name ends in `_test` (never the Production database). **[VERIFY]**
3. **Rehearsal (recommended, as FB-GEO-01 §7):** run `migrate --pretend`, then `migrate` of the release code against the restored copy; confirm only the expected migrations run. **[VERIFY]**

A restore is a disaster-recovery decision only: it discards every later write of the whole application.

---

## 4. Queue switch: `sync` → `database`

- **What changes:** `QUEUE_CONNECTION=database`, `DB_QUEUE_RETRY_AFTER=90` (must stay > 60 and < 600).
- **Impact (verified at `4d2181c`):** the ONLY queued jobs are `PublishNotificationJob` and `NotificationHeartbeatJob`, both on the `notifications` queue. SMS (OTP, contact updates) runs after the response in PHP-FPM and is never queued; mail is `log`. Nothing else in the application changes behaviour.
- **Coupling:** with the switch but **without** a worker, jobs accumulate in `jobs` and publication answers 503 (`HEARTBEAT_ABSENT`). The switch and the worker install go together (S9–S10).
- **Storage:** existing `jobs` / `failed_jobs` tables. Keep `failed_jobs` 30 days (ADR §2a D9). Never `queue:retry` a notification job.
- **The switch is one-way in practice.** Returning to `sync` is not an automatic rollback step (§12 L3): it needs its own impact assessment and approval.

---

## 5. Worker: PHP `pcntl` and systemd

Template `deploy/systemd/famboook-notifications-worker.service`:
`queue:work database --queue=notifications --tries=1 --timeout=60 --sleep=3 --max-time=3600 --memory=256`, user `www-data`, `Restart=always`, `RestartSec=5`, `TimeoutStopSec=75`.

Pre-install checks on the server (none run yet) **[VERIFY]**:
```text
<PHP_BINARY> -v                         # 8.3+
<PHP_BINARY> -m | grep -x pcntl         # REQUIRED (job timeout and graceful SIGTERM)
sudo -u www-data test -r /var/www/famboook/backend/.env && echo env-readable
sudo -u www-data test -w /var/www/famboook/backend/storage/logs && echo storage-writable
```
Replace `<PHP_BINARY>`; `systemd-analyze verify` must be clean. Without `pcntl` the worker cannot be installed — a **blocker** to resolve by the system administrator (install the PHP extension), not by changing the unit.

---

## 6. Scheduler and its side effects

Templates `deploy/systemd/famboook-scheduler.service` + `famboook-scheduler.timer` (`www-data`, every minute, no catch-up; the only scheduler mechanism — no cron in parallel, `FB-NOTIFY-01-QUEUE-SCHEDULER-READINESS.md`). Enabling the timer starts **six** tasks (`backend/routes/console.php`):

| Task | When | Effect |
|---|---|---|
| `famboook:purge-otp-challenges` | daily 00:00 UTC | **deletes** finished OTP challenges older than 90 days; the **first run deletes the whole backlog** |
| `famboook:close-expired-contact-updates` | every 5 min | cancels expired SUBMITTED CONTACT_UPDATE requests (none while that feature is off) |
| `NotificationHeartbeatJob` | every minute | worker liveness |
| `famboook:notifications-reconcile` | every minute, `withoutOverlapping(10)`, `onOneServer` | stale-claim recovery, retries |
| `famboook:notifications-expire` | every minute, same locks | archives messages at `expires_at` |
| `famboook:notifications-followups` | every minute, same locks | closes follow-ups of expired messages; opens new ones only when the follow-up gate is ready |

Requires `CACHE_STORE=database` (atomic locks in `cache_locks`). Before installing, record the number of OTP challenges the first purge will delete (read-only count) so the deletion is a known quantity (D4).

---

## 7. Family login and coordinator access prerequisites

| Prerequisite | Why | Current state |
|---|---|---|
| `FAMILY_LOGIN_ENABLED=true` | test households read the inbox; coordinators reach Coordinator Space at all (docs/08 §16a: "reachable only once Family login is enabled") | template `false`; **Production value UNKNOWN** — read at S0 |
| Activated test-household accounts | the inbox needs an activated household account (activation: `FAMILY_ACTIVATION_ENABLED`, SMS via TweetsMS, docs/08 §16a) | UNKNOWN |
| Coordinator accounts | the coordinator first activates as an ordinary family account; holds COORDINATOR; has a non-revoked scope assignment (created by SUPER_ADMIN / ADMINISTRATOR); the test families are NOT their own household | UNKNOWN |

If Family login is closed in Production, opening it is a **separate release decision under docs/08 §16a** (SMS, rate limits, response floor, CSRF smoke) — not part of this release. Without it, only a Staff-only PILOT is possible: families would receive messages they cannot read, every follow-up would open, and coordinators could not take part. **Recommendation:** do not start the notification PILOT until at least the approved test households can sign in.

---

## 8. PILOT participants

| Role | Recommended | Notes |
|---|---|---|
| Staff senders | 1–2 ADMINISTRATOR accounts (never SUPER_ADMIN for daily sending: `send-all` reaches every ACTIVE family) | user ids in `NOTIFICATIONS_PILOT_SENDER_USER_IDS`; one malformed entry blocks everyone |
| Coordinator sender | 1 coordinator with one BRANCH scope | same allow-list |
| Follow-up managers | the same ADMINISTRATOR accounts (central) and the coordinator (primary for the branch) | follow-up APIs do NOT use the allow-list; access is RBAC + ownership |
| Test families | 3–5 approved households in the coordinator's branch, with activated accounts, briefed in advance; plus 1 accountless family to observe follow-ups | only these may be addressed — senders select by family code |

The allow-list restricts **publication** only. Drafts are possible for every holder of `announcement.create` / `coordinator-announcement.create` as soon as the code is deployed: brief all Staff and coordinators before deployment.

---

## 9. Gates and cutoff

| Key | At deployment | At activation (S14) |
|---|---|---|
| `NOTIFICATIONS_MODE` | `OFF` | `PILOT` (never `ON` in this release) |
| `NOTIFICATIONS_PILOT_SENDER_USER_IDS` | empty | D1 ids |
| `NOTIFICATIONS_FOLLOWUPS_ENABLED` | `false` | per D2 (recommended `true`) |
| `NOTIFICATIONS_FOLLOWUPS_CUTOFF` | unset | per D2: the activation instant, offset-aware ISO-8601 (e.g. `2026-11-02T09:00:00+02:00`) |
| `NOTIFICATIONS_SYSTEM_DISCOVERY_ENABLED` / `NOTIFICATIONS_SYSTEM_CUTOFF` | `false` / unset | unchanged (no SYSTEM producer exists; SYSTEM works only in mode ON) |
| `QUEUE_CONNECTION` / `DB_QUEUE_RETRY_AFTER` / `CACHE_STORE` | `sync` / — / `database` | `database` / `90` / `database` (switched at S9) |

**Expiry rule for PILOT messages:** a follow-up opens only if the message is still unexpired at the deadline — NORMAL 72 h, IMPORTANT 24 h (FIRST) and 72 h (SECOND). Messages meant to exercise follow-ups must expire **more than 72 h** after publication; the checklist's "short expiry" advice applies only to messages that should never open follow-ups.

After any `.env` change: `php artisan config:clear && php artisan optimize`, reload PHP-FPM, `sudo systemctl restart famboook-notifications-worker`, then `famboook:notifications-check`.

---

## 10. Ordered release procedure (Release 2)

Preconditions: Release 1 (and any Release 0) done and settled under their own approvals; D1–D6 recorded; this plan approved (**A0**). A0 alone authorizes none of the steps below.

| Step | Action | Label |
|---|---|---|
| S0 | **Read-only state — a separate authorization checkpoint (AR):** Production access, even read-only, needs its own approval before S0; it authorizes reading only, no change of any kind. Read: Production commit, `php artisan migrate:status`, `famboook:verify-permissions`, `famboook:family-auth-check`, `.env` values of §7 and §9 (names and booleans only), PHP version and `pcntl`. Expect: Release 1 applied; exactly the five notification migrations pending. Anything else: STOP | [VERIFY] → **A1** go / no-go |
| S1 | Push layer C; merge to `main`; record the full SHA. Locally on that SHA: backend SQLite suite; PostgreSQL Notifications + FamilyAuth suites (one process at a time); frontend tests, typecheck, ESLint, build | [APPROVAL] [VERIFY] |
| S2 | Backup + restore test (§3) | [APPROVAL] [VERIFY] |
| S3 | Rehearsal on the restored copy: `migrate --pretend` lists only the five; `migrate` succeeds; `famboook:notifications-check` reports tables present | [VERIFY] |
| S4 | Server `.env`: explicit `NOTIFICATIONS_MODE=OFF`, empty allow-list, `NOTIFICATIONS_FOLLOWUPS_ENABLED=false`, cutoffs unset, `QUEUE_CONNECTION=sync` unchanged | [APPROVAL] |
| S5 | Backend: `deploy-backend.sh` (down → composer → migrate → RolePermissionSeeder → verify-permissions (162) → optimize → queue:restart → up) | [APPROVAL] |
| S6 | `migrate:status` shows the five as Ran; `famboook:notifications-check` no warnings (mode OFF) | [VERIFY] → **A2** |
| S7 | Frontend: `deploy-frontend.sh`; bell, inbox, `/announcements`, `/followups` answer (empty) | [APPROVAL] [VERIFY] |
| S8 | Gate checks with OFF: publish → 503; drafts work; follow-up lists empty and 200 | [VERIFY] |
| S9 | Queue switch (§4) | [APPROVAL] |
| S10 | Worker install (§5), `systemd-analyze verify`, enable, `is-active` | [APPROVAL] [VERIFY] |
| S11 | Scheduler install (§6) after recording the OTP purge count; `schedule:list` shows six tasks; `systemctl list-timers` shows the next run; the scheduler journal shows each run | [APPROVAL] [VERIFY] |
| S12 | Monitoring (§11): `notifications-health.sh` → `STATE: NOT_ACTIVATED`, heartbeat FRESH; alert path tested | [APPROVAL] [VERIFY] → **A3** |
| S13 | Allow-list set (still OFF); senders and families briefed (§8) | [APPROVAL] |
| S14 | **Activation (last, separately approved):** `NOTIFICATIONS_MODE=PILOT`; per D2 `NOTIFICATIONS_FOLLOWUPS_ENABLED=true` + cutoff; caches, PHP-FPM, worker restart; check → publication READY, follow-up readiness READY, no warnings; health → `HEALTHY` | [APPROVAL] [VERIFY] |
| S15 | Smoke tests day 0 (checklist §10 items 1–9) and the **real-browser acceptance (§13)**, using only an internal, Staff-controlled test household — no approved test family receives anything yet | [VERIFY] |
| S15b | **Opening to the test families:** only after S15 passed in full and is recorded. If any §13 item fails: no announcement to the test families; fix, redeploy under its own approval, repeat S15 | [APPROVAL] |
| S16 | Follow-up smoke tests: an IMPORTANT message to two test families (expiry > 72 h), one acknowledges; at +24 h FIRST opens for the other; Staff records a contact; the coordinator records a contact and closes one; at +72 h SECOND opens only for the unacknowledged family; `notifications-check` counts match | [VERIFY] → **A4** sign-off |

---

## 11. Health checks, monitoring and escalation

- **Automatic:** the monitoring agent (or a dedicated root systemd timer) runs `deploy/scripts/notifications-health.sh` every 5 minutes; a non-zero exit (`DEGRADED` 1 / `UNAVAILABLE` 2) notifies the system administrator (D6).
- **Manual during the PILOT:** `famboook:notifications-check` at least every 15 minutes in working hours for the first week, then daily; plus `failed_jobs` for the `notifications` queue and the `PUBLISH_FAILED` count (not covered by the script).
- **Escalation:**
  1. System administrator: worker or scheduler down → restart; configuration invalid → fix `.env`.
  2. Any doubt about correctness, repeated `PUBLICATION_ERROR`, or a family complaint → **mode OFF** (§12 L1) and inform the product owner the same day.
  3. Product owner decides on resumption.

---

## 12. Rollback and recovery

| Level | Trigger | Action | Effect |
|---|---|---|---|
| L1 Feature shutdown (default) | any problem | `NOTIFICATIONS_MODE=OFF` (and `NOTIFICATIONS_FOLLOWUPS_ENABLED=false`); caches; PHP-FPM; worker restart | publication 503; nothing new opens; inbox, history and existing follow-up management keep working. **Not stopped by L1:** see the note below |
| L2 Infrastructure | worker / scheduler fault | stop the worker unit; `systemctl disable --now famboook-scheduler.timer` | messages stay QUEUED or recover as stale; removing cron also stops the OTP purge and contact-update expiry; heartbeat goes stale → publication closes |
| L3 Queue back to `sync` | **not an automatic step** | only after a recorded impact assessment and its own approval (below) | publication closes (503); jobs wait in `jobs` |

**What closing the gates does NOT stop (L1).** Mode OFF and the follow-up gate stop new publication and new follow-ups only. While the scheduler runs, these continue by design:
- the expiry sweep (messages archived at `expires_at`);
- the follow-up expiry closure (OPEN follow-ups of expired messages close EXPIRED);
- stale-publication recovery by the reconciler;
- audit preservation: every event stays recorded and append-only, and authorized users keep reading and managing existing follow-ups;
- the other scheduled maintenance (OTP purge, contact-update expiry, heartbeat).

Stopping those needs L2, which has side effects of its own beyond notifications.

**L3 impact assessment (required before any switch back to `sync`):**
- list every queued job class in the deployed code at that time (today only `PublishNotificationJob` and `NotificationHeartbeatJob`; a later release may add others);
- count the rows waiting in `jobs` per queue, and decide what happens to them (they are not run under `sync`);
- confirm no other feature or worker depends on the database queue;
- record the expected effect (publication closed, heartbeat stale) and how the switch will be reversed.

Without that assessment, use L1 (and L2 if needed) instead.
| L4 Code rollback | defect | redeploy the previous SHA with mode OFF. **Before any message exists** the migrations can be rolled back; **after**, `down()` refuses — keep the tables, roll back code only | history preserved |
| L5 Data restore | data loss / corruption only | owner decision; restore the S2 backup | discards every later write of the whole application |

- **Wrong published message:** there is no unpublish; send a corrective announcement and let the wrong one expire.
- **Never:** drop or truncate notification tables, edit `notification_recipient_events` (append-only), run `migrate:fresh`, or `queue:retry` a notification job.

---

## 13. Real-browser acceptance checklist (NOT yet verified anywhere)

No browser automation was available during FB-NOTIFY-01E.4; component tests (jsdom) and `next build` passed. **Every item below must pass and be recorded before the PILOT is opened to the test families (S15b).** Check on desktop (≥1280 px) and a real phone (≈375 px; Android Chrome and iOS Safari):

- [ ] **RTL:** Arabic text right-aligned; family and notice codes read LTR inside RTL; chevrons point the right way; no mirrored icons
- [ ] **Navigation:** Staff sidebar shows «المتابعات» only with `notification-followup.view`; Coordinator Space home card opens «متابعات الأسر»; back links work
- [ ] **Filters:** OPEN / CLOSED, stage, level, outcome (CLOSED only), family code, «متابعاتي»; the URL updates; reset keeps OPEN / CLOSED and mine / all
- [ ] **Pagination:** next / previous; page resets on a filter change
- [ ] **Detail and history:** Staff see actor names and notes; a coordinator sees labels only (النظام / الإدارة المركزية / أنت / منسّق); notes absent from lists
- [ ] **Contact form:** outcome required; the note counter and sensitive-data warning are visible; a second identical submission after a forced offline error is replayed, not duplicated
- [ ] **Closure dialog:** opens, needs a reason, closes on success, keeps focus inside, Escape closes it when idle
- [ ] **Read-only:** a non-primary coordinator sees the read-only note and no controls
- [ ] **States:** loading skeletons, empty states, the 403 state, an error with retry
- [ ] **Mobile overflow:** no horizontal scroll at 360–390 px on inbox, detail, dialog; long names and titles wrap
- [ ] **Keyboard:** Tab order through filters, rows, form and dialog; visible focus rings; Enter submits the family-code search
- [ ] **Family side:** bell count, inbox, NORMAL view, IMPORTANT acknowledge dialog, archive after expiry

---

## 14. Decisions

| # | Decision | Recommended default | Owner |
|---|---|---|---|
| D1 | PILOT senders | 1–2 ADMINISTRATOR accounts + 1 coordinator with one BRANCH scope; 3–5 activated test households in that branch + 1 accountless family; no SUPER_ADMIN sending | Product owner |
| D2 | Follow-ups in the PILOT | **Include them**, enabled at activation with `NOTIFICATIONS_FOLLOWUPS_CUTOFF` = the activation instant. The engine, APIs and screens are built and tested end to end, and the allow-list bounds every follow-up to PILOT messages. Fallback: announcements only for the first week, follow-ups after a clean week | Product owner |
| D4 | Scheduler side effects | **Approve**, after recording the purge count (§6) and with the S2 backup in place. The OTP purge is the approved 90-day retention; the contact-update expiry is inert while that feature is off | Product owner + system administrator |
| D5 | Queue switch `sync` → `database` | **Approve**, executed together with the worker (S9–S10); only the two notification jobs are affected | System administrator |
| D6 | Monitoring and on-call | `notifications-health.sh` every 5 minutes, non-zero exit → system administrator; manual check every 15 minutes during the first PILOT week; escalation per §11 with mode OFF as the first response | System administrator |

Also required, outside D1–D6, each as a **separate explicit approval** (never implied by A0 or by D1–D6):
- **FB-GEO release** (FB-GEO-01 plan A0 and its D1–D6), extended with `2026_10_23_090000`.
- **Family login and activation** in Production (§7): if closed, a separate docs/08 §16a release decision.
- **Production migrations** of each release, approved at the step where they run.
- **Infrastructure installation:** the queue switch, the worker and the scheduler timer, each approved on its own.
- **D3 audit retention** (not a PILOT blocker).

Recording a recommended default above is not an approval.

---

## 15. Blockers versus deferred improvements

**Mandatory before the PILOT:**
1. Layer C pushed, merged and an approved SHA (S1).
2. Release 1 (FB-GEO) approved and executed first; Release 0 if S0 shows pending CO migrations.
3. This plan approved; D1, D2, D4, D5, D6 recorded.
4. Verified backup and restore (S2).
5. `pcntl` present; worker installed; queue switched; scheduler installed; heartbeat FRESH.
6. Family login open for the test households, with activated accounts and a scoped coordinator (§7).
7. Real-browser acceptance (§13) passed.
8. Monitoring path tested (S12).

**Deferred (not blockers):**
- Alerts for `PUBLISH_FAILED` and failed jobs in the check.
- Audit retention (D3), `failed_jobs` pruning.
- NOTE_CORRECTION.
- SYSTEM notifications (no producer; mode ON only).
- Structure-based audiences.
- CLOSED-list index.
- `backend/.env.example` lacks `NOTIFICATIONS_*` keys.
- docs/09 has no notification SOP (a short Arabic briefing for PILOT senders is advisable before S13).
- Stale wording in the readiness checklist (follow-ups "out of scope / not implemented"; the scheduler wording is corrected) and the env template comment ("Not implemented yet … follow-ups"): to be corrected in a docs-only change.

---

## 16. Approval checkpoints

- **A0** this plan (the document only; authorizes no Production step, §0)
- **AR** read-only Production inspection (S0): reading only, no change
- **A1** go / no-go after S0
- **A2** after migrations (S6)
- **A3** after infrastructure and monitoring (S12)
- **A4** final sign-off after the follow-up smoke tests (S16)

Separate approvals, never implied by any checkpoint above:
- each [APPROVAL] step of §10;
- Release 0 / Release 1 (FB-GEO);
- Family login and activation;
- activation (S14);
- opening to the test families (S15b), after the real-browser acceptance passed.
