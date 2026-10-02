# Famboook
## Pilot Production Environment

**Document:** `08-PILOT-DEPLOYMENT.md`  
**Version:** 1.1  
**Status:** Approved for Pilot preparation (owner decisions: §2a)  
**Last Updated:** 2026-09-27  
**Applies to:** the first real-data Pilot (application commit `edc7fcb` + Slice D)

Templates referenced here live in `deploy/`. They contain placeholders only.
No credential, key or password is ever written into the repository, this
document, shell history or tickets.

---

# 1. Topology

```text
https://famboook.com        Next.js Staff Portal   (next start on 127.0.0.1:3000)
https://api.famboook.com    Laravel API            (PHP-FPM, /api/v1, /sanctum/csrf-cookie)
https://admin.famboook.com  Filament               (same Laravel app, ADMIN_DOMAIN)
PostgreSQL 16+              localhost / private network only
```

One Laravel application serves both `api.` and `admin.`; one `users` table;
Laravel Sanctum first-party cookie sessions. No JWT, bearer tokens, OAuth or
SSO. Nginx terminates TLS (`deploy/nginx/famboook.conf.example`).

---

# 2. Readiness audit (Slice D)

| Area | Finding | Action |
|---|---|---|
| APP_ENV / APP_DEBUG | Defaults already `production` / `false`; template sets them explicitly | Template |
| Session | Database driver; `SESSION_DOMAIN`, `SESSION_SECURE_COOKIE`, `SESSION_ENCRYPT` env-driven, unset locally | Template (§4) |
| Sanctum / CORS | Env-driven (`SANCTUM_STATEFUL_DOMAINS`, `FRONTEND_URL`); `supports_credentials=true` | Template (§4) |
| Filament | Served on every host at `/admin` | **Fixed:** `ADMIN_DOMAIN` binds the panel to `admin.famboook.com` only |
| Staff Portal → Filament link | Pointed at `<API_URL>/admin` | **Fixed:** `NEXT_PUBLIC_ADMIN_URL` |
| Frontend API URL | Silent fallback to `http://localhost:8000` if unset at build | **Fixed:** `next build` refuses without `NEXT_PUBLIC_API_URL` |
| `/dev-login` (backend) | Registered only when `APP_ENV=local`; test exists | Verified: no route in production |
| `/dev-login` (frontend page) | Present in production builds (rendered an error only) | **Fixed:** real HTTP 404 outside `next dev` |
| Seeders | `DatabaseSeeder` = reference data only, idempotent, no users | Verified + test extended |
| Stale permissions | Old DB kept a revoked grant (Pilot Gate) | **Added:** `famboook:verify-permissions`, run on every deploy |
| pg_trgm | Migration runs `CREATE EXTENSION IF NOT EXISTS pg_trgm` | Preflight (§6) |
| Trusted proxies | None configured | Correct for Nginx + PHP-FPM on one host (§9) |
| Logging | `LOG_LEVEL` default `debug` | Template: `daily`, `info` (§15) |
| Queue / scheduler | No jobs dispatched, no scheduled tasks | Not deployed (§16) |
| File storage | No uploads or stored files in V1 | No storage volume needed |
| Debug tooling | No Telescope / Debugbar installed | Verified |

---

# 2a. Owner decisions (2026-09-27)

| # | Decision |
|---|---|
| 1 | `SESSION_DOMAIN=.famboook.com` approved for the Pilot (§4) |
| 2 | Shared Staff Portal / Filament session approved (§4) |
| 3 | `SESSION_LIFETIME=120` minutes approved for the Pilot (PAUTH-022 stays open beyond the Pilot) |
| 4 | Administrator-set initial passwords accepted for the Pilot (§18); self-service password change is a post-Pilot improvement, not a blocker |
| 5 | Backups: 30 daily + 12 monthly, encrypted off-server copy (§12); a passed restore test remains mandatory before real data (§13) |
| 6 | ONE clearly synthetic smoke Family is approved in the Pilot environment before real data; it is removed by rebuilding the still-empty database (§17a), not by any delete feature |
| 7 | The incident contact stays a deployment-time placeholder (docs/09) |

---

# 3. Environment

- Laravel: `deploy/env/backend.production.env.example` → server-only
  `backend/.env`, mode `640`, owner `deploy:www-data`.
- Next.js: `deploy/env/frontend.production.env.example` →
  `frontend/.env.production.local` at build time. Only two public values:
  `NEXT_PUBLIC_API_URL`, `NEXT_PUBLIC_ADMIN_URL`. `NEXT_PUBLIC_*` is shipped
  to browsers; nothing private goes there.

Key production values: `APP_ENV=production`, `APP_DEBUG=false`,
`APP_URL=https://api.famboook.com`, `FRONTEND_URL=https://famboook.com`,
`ADMIN_DOMAIN=admin.famboook.com`, `SESSION_SECURE_COOKIE=true`,
`SESSION_ENCRYPT=true`, `LOG_CHANNEL=daily`, `LOG_LEVEL=info`,
`QUEUE_CONNECTION=sync`, `CACHE_STORE=database`, `MAIL_MAILER=log` (no mail
is sent in V1), `IMPORT_APPLY_ENABLED=false` (Import Apply stays off; §7a).

---

# 4. Cookies, Sanctum and CORS

| Setting | Value |
|---|---|
| `SESSION_DOMAIN` | `.famboook.com` |
| `SESSION_SECURE_COOKIE` | `true` (HTTPS only) |
| `SESSION_HTTP_ONLY` | `true` (session cookie unreadable by JavaScript) |
| `SESSION_SAME_SITE` | `lax` |
| `SESSION_ENCRYPT` | `true` |
| `SANCTUM_STATEFUL_DOMAINS` | `famboook.com` |
| CORS allowed origin | `https://famboook.com` only (`FRONTEND_URL`), credentials allowed |

Reasoning:

- The session and `XSRF-TOKEN` cookies are set by `api.famboook.com`. The
  Staff Portal on `famboook.com` must **read** `XSRF-TOKEN` to send the
  `X-XSRF-TOKEN` header, which requires a parent-domain cookie. A host-only
  cookie would break CSRF-protected login. `.famboook.com` is therefore the
  narrowest domain that works; it is also what lets Filament on `admin.` use
  the same session.
- All three hosts are the same *site*, so `SameSite=Lax` cookies travel on
  the portal's credentialed `fetch` calls while cross-site requests from
  other sites do not carry them. `None` is not needed and not used.
- Only `famboook.com` is stateful for Sanctum and allowed by CORS. Filament
  uses the `web` guard directly and needs neither.
- CSRF stays enabled everywhere (Sanctum's `ValidateCsrfToken`, Filament's
  `VerifyCsrfToken`). Credentials never touch `localStorage`.
- Logout (`POST /api/v1/auth/logout` or Filament logout) invalidates the one
  shared server-side session, so it ends access on every host. Deactivating
  a user deletes their sessions (AUTH-ADR-057).
- Consequence: **no other service may be hosted on a `*.famboook.com`
  subdomain** — it would receive the session cookie.

---

# 5. New database and initialization order

The Pilot starts with a **new, empty** PostgreSQL database. The development
database, its synthetic Families/Persons and its walkthrough users are never
copied.

1. DB administrator creates role `famboook_app` (`NOSUPERUSER NOCREATEDB
   NOCREATEROLE`) and database `famboook_pilot` owned by it, entering the
   password interactively (`\password`). Password goes only into the server
   `.env`.
2. Run `deploy/scripts/db-preflight.sql` as DB administrator: version ≥ 16,
   `pg_trgm` available and installed, role not superuser, database empty,
   `listen_addresses` private.
3. Configure `backend/.env`; `php artisan key:generate`; back up `APP_KEY`
   (§14).
4. `FIRST=1 deploy/scripts/deploy-backend.sh`, which runs, in order:
   `migrate --force` → `db:seed --force` (DatabaseSeeder) →
   `db:seed --class=RolePermissionSeeder --force` →
   `famboook:verify-permissions` → `optimize` → `filament:optimize`.
5. `php artisan famboook:create-super-admin` (interactive; hidden password).
6. `php artisan famboook:verify-permissions` (again, for the record).
7. Log in to `https://admin.famboook.com/admin` and create the Pilot Staff
   accounts (§18).

`DatabaseSeeder` calls exactly: RolePermissionSeeder, ClanSeeder (Al-Breem
taxonomy), RelationshipTypeSeeder, DisabilityTypeSeeder,
AssessmentDomainSeeder, NeedCategorySeeder, AssistanceCategorySeeder. It
creates **no** user, Family, Person, membership, residence, health record,
assessment, Need, Assistance program or activity, and no known-password or
`@example` account — enforced by `SeedingSafetyTest`. The `dev@famboook.test`
account exists only through the local-only `/dev-login`.

Routine deployments run the full DatabaseSeeder only when reference data
changes are released; RolePermissionSeeder runs **every** time.

---

# 6. pg_trgm

- Migration `2026_10_04_090000_add_registry_search_indexes` runs
  `CREATE EXTENSION IF NOT EXISTS pg_trgm` and three trigram GIN indexes.
- `pg_trgm` is a *trusted* extension (PostgreSQL 13+): a non-superuser with
  `CREATE` on the database (the owner) may install it, but only if the
  server has the contrib package installed.
- If the package is missing or the role lacks the privilege, the migration
  fails; PostgreSQL rolls the migration back and the deployment stops. It
  does not silently continue.
- Required practice: the DB administrator installs it in step 2
  (`CREATE EXTENSION IF NOT EXISTS pg_trgm;` in `db-preflight.sql`) and
  confirms `SELECT extname FROM pg_extension WHERE extname='pg_trgm';`
  returns a row. The migration then needs no extension privilege.

---

# 7. Role / permission deployment safety

Every deployment runs:

```text
php artisan db:seed --class=RolePermissionSeeder --force
php artisan famboook:verify-permissions
```

`famboook:verify-permissions` compares every role in the database with
`RolePermissionSeeder::ROLE_PERMISSIONS` (exact set equality), checks every
catalog permission exists, and asserts explicitly:

- DATA_ENTRY has `family-membership.update` and lacks
  `family-membership.end`, `person.national-id.update`,
  `person.national-id.view`, `assistance.approve`, `export.basic`;
- ADMINISTRATOR and SUPER_ADMIN have `family-membership.end` and
  `person.national-id.update`; nobody has `person.national-id.view`.

It also enforces the Import Apply gate (§7a): with the gate closed no role
may hold `import.apply`; with it open SUPER_ADMIN must and no other role may;
in both modes no user may hold it directly. It prints the gate state.

It prints role names and counts only (no users) and exits non-zero on any
drift, which stops `deploy-backend.sh`. Covered by
`VerifyPermissionsCommandTest`.

---

# 7a. Import Apply activation

Import Apply (docs/03 §96b) is deployed **disabled**. `IMPORT_APPLY_ENABLED`
defaults to false, and while it is false no role holds `import.apply` and the
Apply actions refuse every user. A normal deployment never activates it.

Activate only after the Step 6 Apply UI phase and the final end-to-end
review have been approved — never by editing roles or permissions in the
database by hand. On the server, as `deploy`:

```text
# 1. backend/.env
IMPORT_APPLY_ENABLED=true

# 2. drop the cached configuration so the seeder sees the new value
php artisan config:clear

# 3. apply the gated baseline: import.apply → SUPER_ADMIN only
php artisan db:seed --class=RolePermissionSeeder --force

# 4. must print "Import Apply gate: ENABLED (SUPER_ADMIN only)" and pass
php artisan famboook:verify-permissions

# 5. rebuild caches
php artisan optimize
```

`config:clear` is required: `deploy-backend.sh` seeds before `optimize`, so
the seeder would otherwise read the previous cached value (it then fails
closed — the grant is not added and the verifier reports it). Deactivation is
the same procedure with `IMPORT_APPLY_ENABLED=false`; the seeder revokes the
grant and the actions refuse immediately after the configuration is reloaded.

Operational invariant: PostgreSQL **persistent connections must stay
disabled** (no `PDO::ATTR_PERSISTENT` in `config/database.php`). The Apply
runner lock is a session-level advisory lock released at the end of each
request; a persistent connection could carry a session across requests.

---

# 8. Development-only surfaces

| Surface | Production behavior |
|---|---|
| Backend `/dev-login`, `/dev-logout` | Not registered unless `APP_ENV=local` (404); `StaffAuthenticationTest` |
| Frontend `/dev-login` | Rewritten to a non-existent path outside `next dev`: HTTP 404 (verified with `next start`) |
| Debug output | `APP_DEBUG=false`: generic error responses, no stack traces |
| Telescope / Debugbar | Not installed |
| Factories | Used by tests only; never called by seeders; `composer install --no-dev` omits Faker |
| Test/walkthrough accounts | Never seeded; exist only in development databases |

---

# 9. HTTPS and reverse proxy

- Nginx terminates TLS for all three hosts and redirects HTTP → HTTPS
  (template). HSTS (`max-age=31536000; includeSubDomains`) — add `preload`
  only after the Pilot is stable.
- Laravel runs under PHP-FPM via FastCGI with `HTTPS=on` and the real
  `REMOTE_ADDR`. Laravel therefore **trusts no proxy headers**: secure URLs
  are generated correctly and login/National ID rate limits key on the real
  client IP, which a spoofed `X-Forwarded-For` cannot change. If a load
  balancer is ever put in front, configure `trustProxies` with that
  balancer's address only — never `*`.
- `api.` serves only `/api/*`, `/sanctum/csrf-cookie`, `/up`; `admin.` serves
  Filament/Livewire/assets and refuses `/api` and `/sanctum`; the panel
  itself is bound to `ADMIN_DOMAIN`.
- Mixed content: every URL in use is HTTPS (`NEXT_PUBLIC_*`, `APP_URL`).
  Next.js is reachable only through Nginx (`-H 127.0.0.1`).

---

# 10. File and directory permissions

```text
backend/            owner deploy, group www-data, dirs 750, files 640
backend/.env        640 (deploy:www-data)
backend/storage/    770 dirs / 660 files, group www-data (logs, framework cache, sessions not used)
backend/bootstrap/cache/  770, group www-data
backend/public/     readable by www-data (Nginx static, Filament assets)
frontend/           owned by the service user; only frontend/.next/cache must be writable at runtime
```

Never `777`. PHP-FPM runs as `www-data`; the deploy user owns code.

---

# 11. Database network security

- PostgreSQL listens on `localhost` (or a private interface) only;
  `pg_hba.conf` allows `famboook_app` from `127.0.0.1/32` (or the app host)
  with `scram-sha-256`; firewall blocks 5432 from the internet.
- The application role is not a superuser and cannot create roles or
  databases. A separate read-only role may be used for backups.
- Passwords live in the server `.env` / `~/.pgpass` (mode 600) — never in
  scripts in git.

---

# 12. Backups

Policy for the Pilot:

- `deploy/scripts/backup-db.sh` via cron **daily** (02:15 UTC):
  `pg_dump --format=custom`, verified with `pg_restore --list`, encrypted
  with a public key (age/gpg), checksummed, copied off the server to
  restricted storage. Local copies are kept outside the PostgreSQL data
  directory.
- Retention (owner decision 5): 30 daily copies and 12 monthly copies
  (the first backup of each month), both as encrypted off-server copies.
- Backup files and keys are restricted to the system administrator. The
  decryption key is stored offline, not on the database server.
- Backups are **not** considered working until §13 has passed.

---

# 13. Restore test

`deploy/scripts/restore-test.sh <backup.dump.age>`:

```text
checksum → decrypt → create temporary DB famboook_restore_test_<stamp>
→ pg_restore → integrity checks → drop temporary DB (always)
```

It never touches `famboook_pilot`. Integrity checks (counts only): migrations,
roles, permissions, users, families, persons, active memberships,
activities; invariants that must be 0: persons with two active memberships,
families with two active heads, families with two current residences;
presence of the trigram indexes. Compare the counts with the live database
(read-only). Reading encrypted Assistance snapshots additionally requires
the same `APP_KEY`.

**Status: REQUIRED BEFORE REAL DATA** — not executed; no Pilot
infrastructure was available.

---

# 14. APP_KEY and encryption

`APP_KEY` encrypts: session payloads (`SESSION_ENCRYPT=true`), cookies, and
stored data — `assistance_beneficiary_list_entries.snapshot_data`
(`encrypted:array`, may contain sensitive external-list values).

- Generate once on the server; store a copy in the offline secrets store
  used for backup keys. A database backup without the matching key cannot
  decrypt those snapshots.
- Changing `APP_KEY` logs everyone out and makes encrypted columns
  unreadable unless the old key is listed in `APP_PREVIOUS_KEYS`
  (supported by `config/app.php`), which lets Laravel decrypt with old keys
  while encrypting with the new one. Any rotation needs a written plan;
  none is performed for the Pilot.
- Never print, log or paste the key.

---

# 15. Logging privacy

- Production: `LOG_CHANNEL=daily`, `LOG_LEVEL=info` (the Staff user
  administration audit events of AUTH-ADR-057 are `info`), 90 days,
  files readable by the application group only.
- Application code logs only user-administration events with user ids,
  roles and changed field **names**. No password, National ID, cookie, CSRF
  token, health detail or Assistance snapshot is logged intentionally.
- National ID inputs are never flashed to the session (`dontFlash`), are
  never in URLs (POST bodies only), and duplicate refusals are not reported
  as errors.
- Residual: an unexpected database error message can contain SQL bindings.
  Treat `storage/logs` as sensitive (same access as backups).
- Do not enable Nginx request-body logging.

---

# 16. Queues and scheduler

The V1 application dispatches no jobs, sends no mail/notifications and
defines no scheduled tasks (`routes/console.php` has none). **No queue
worker and no scheduler cron are deployed for the Pilot**
(`QUEUE_CONNECTION=sync`). Re-evaluate when a feature introduces jobs.

---

# 16a. Family Portal activation prerequisites (not yet applicable)

Recorded 2026-10-02 (docs/11 §30a). Nothing here is deployed; the Family
Portal is not implemented. This section lists what must exist **before**
Family Portal activation may be enabled in Production.

- **SMS provider.** None is chosen. PWA-1E sends through the `SmsSender`
  abstraction. `FAMILY_SMS_DRIVER` empty (the default, and the Production
  value) binds a sender that always refuses, so nothing can be delivered.
  `log` is a LOCAL development driver: it appends to its own file
  (`storage/logs/family-sms-dev.log`, destination masked) and refuses to
  run outside the `local` and `testing` environments. Deploying PWA-1E does
  not make Production SMS-ready.
- **Production gate for Family self-activation** — not satisfied by
  PWA-1E. Activation is not Production-ready until:
  1. a real SMS provider is selected and integrated;
  2. its credentials are securely configured;
  3. delivery-failure behaviour is validated;
  4. the Production queue / retry architecture is decided and implemented
     as the provider requires (PWA-1E delivers synchronously, without a
     retry, precisely so that no plaintext code is written to a queue);
  5. worker process supervision exists if queued delivery is used;
  6. the scheduler cron is configured for scheduled maintenance.
- **OTP abuse ceilings** are security settings with defaults in
  `config/family_auth.php`, overridable by `FAMILY_OTP_THROTTLE_PERSON_HOUR`
  / `_PERSON_DAY` / `_DESTINATION_HOUR` / `_DESTINATION_DAY` / `_IP_HOUR` /
  `_GLOBAL_HOUR`. They use the application cache store; if it cannot be
  read, no SMS is sent.
- **Queue worker.** §16 stays true for the Pilot. Activation needs an
  operational worker (supervised, restarted on deploy) so SMS sending and
  retries do not run inside web requests.
- **Delivery-failure handling** for SMS must exist.
- **Secrets**, stored like every other credential (§3, never in the
  repository):
  - a dedicated Family Portal fingerprint secret, separate from `APP_KEY`:
    `FAMILY_AUTH_FINGERPRINT_KEY` (at least 32 bytes, optionally
    `base64:`), with `FAMILY_AUTH_FINGERPRINT_KEY_VERSION` (default 1) and,
    only during a rotation, `FAMILY_AUTH_FINGERPRINT_PREVIOUS_KEY` /
    `FAMILY_AUTH_FINGERPRINT_PREVIOUS_KEY_VERSION`. Losing or changing it
    without the rotation procedure breaks Family login; it must be covered
    by the same custody rules as §14;
  - the SMS provider credentials.
- **Activation switch.** `FAMILY_ACTIVATION_ENABLED=false` (the default)
  until the owner approves it, following the same pattern as the Import
  Apply gate (§7a). Nothing reads it before PWA-1F.
- **PWA-1C deployment note.** The foundation (commits up to PWA-1C) adds
  seven migrations and the COORDINATOR role with ten permissions. A deploy
  runs `migrate`, the RolePermissionSeeder and `famboook:verify-permissions`
  as usual (§7). The migrations create empty tables and drop one NOT NULL
  (`users.email`); nothing is backfilled. The environment templates carry
  the names above as empty placeholders, and the fingerprint key may stay
  empty: no feature uses it yet and the Staff application is unaffected.
- **Retention.** `famboook:purge-otp-challenges` deletes finished OTP
  challenges older than `FAMILY_OTP_RETENTION_DAYS` (default 90) and is
  defined in the Laravel schedule as a daily task. §16 still holds: no
  scheduler cron is deployed, so the schedule does not run in Production
  until `schedule:run` is added to cron — a deployment prerequisite. The
  command can also be run by hand. Authentication and security events are
  retained 24 months; their purge is not implemented yet.
- **Rollout gate.** Head Succession (docs/11 FU-01) must be resolved
  before general Family Portal rollout.

---

# 17. Post-deployment smoke test

Use synthetic data only, before any real entry. The smoke Family cannot be
removed with supported behavior (V1 has no Family delete/archive, by design),
so the database is rebuilt afterwards (§17a).

1. `curl -I http://famboook.com` → 301 to HTTPS; certificates valid on all three hosts.
2. `https://famboook.com/login` loads, no mixed-content warnings in the console.
3. `curl https://api.famboook.com/api/v1/health` → `{"status":"ok"}`.
4. `https://api.famboook.com/admin` → 404; `https://admin.famboook.com/admin` → Filament login.
5. `https://famboook.com/dev-login` and `https://api.famboook.com/dev-login` → 404.
6. An unknown API route returns a generic JSON error (no stack trace).
7. Log in as SUPER_ADMIN in Filament; create a temporary DATA_ENTRY smoke user.
8. Portal login as that user; `/me` shows role DATA_ENTRY; Administration menu hidden.
9. `php artisan famboook:verify-permissions` passes.
10. Register ONE clearly synthetic Family (approved, owner decision 6): head
    "اختبار نشر — ليست بيانات حقيقية", no National ID, paper form number
    `SMOKE-TEST`. Search it by Family code and by name; open it.
11. DATA_ENTRY member menu shows no "إنهاء العضوية"; Person page shows no
    National ID card.
12. Logout → `/me` returns 401; reloading a protected page returns to login.
13. Deactivate the smoke user in Filament.
14. Continue with §17a before any real data.

---

# 17a. Rebuild after the smoke test

The smoke Family, its Person, memberships, activity entries and consumed
Family/Person codes would otherwise stay in the Pilot registry, reports and
Data Quality figures. Because no real data exists yet, the cleanest removal
is to rebuild the database:

1. Confirm no real data has been entered (only the smoke Family exists).
2. `php artisan down`; stop the Staff Portal service.
3. DB administrator: `DROP DATABASE famboook_pilot;` then recreate it empty
   (§5 step 1) and re-run `db-preflight.sql` (pg_trgm).
4. `FIRST=1 deploy/scripts/deploy-backend.sh` (keep the same `APP_KEY`).
5. `php artisan famboook:create-super-admin`; `famboook:verify-permissions`.
6. Create the real Staff accounts (§18). Smoke/test accounts are gone with
   the old database; do not recreate them.
7. Re-run smoke checks 1–9, 11 and 12 **without** creating a Family.
8. Then set up backups (§12) and pass the restore test (§13) against this
   final database.

Never do this once real data has been entered.

# 18. Pilot Staff setup

1. `php artisan famboook:create-super-admin` (named individual; hidden password).
2. Log in to Filament as that SUPER_ADMIN.
3. Create one account per real person — no shared accounts.
4. Assign the minimum role: DATA_ENTRY for entry clerks, ADMINISTRATOR for
   the designated corrector(s)/user administrators; others only if needed.
5. The administrator sets each initial password in Filament and hands it
   over through the agreed out-of-band channel (in person / separate
   channel) — never in git, chat logs or tickets. V1 has **no self-service
   password change** for Staff: a user who wants a new password asks an
   administrator, who uses "reset access" (this also ends that user's
   sessions).
6. Verify each account logs in and sees the expected menu.
7. Deactivate unused and smoke/test accounts.

---

# 19. Deployment checklist

`[R]` = REQUIRED BEFORE REAL DATA · `[P]` = RECOMMENDED DURING PILOT

**A. Server**
- [ ] [R] OS patched; PHP 8.2+ with pgsql, mbstring, intl, bcmath; Node 20+; Composer
- [ ] [R] Firewall: only 22 (restricted), 80, 443 open
- [ ] [R] Separate deploy user; PHP-FPM as www-data
- [ ] [P] Unattended security updates; server monitoring

**B. DNS / HTTPS**
- [ ] [R] A/AAAA records for famboook.com, api., admin.
- [ ] [R] Valid certificates on all three; HTTP → HTTPS redirect
- [ ] [R] Nginx config from template; `nginx -t` passes
- [ ] [R] No other service on any *.famboook.com subdomain
- [ ] [P] HSTS preload after stable operation

**C. Laravel**
- [ ] [R] `backend/.env` from template, mode 640; APP_DEBUG=false
- [ ] [R] APP_KEY generated and backed up offline
- [ ] [R] storage/ and bootstrap/cache writable by www-data only
- [ ] [R] `composer install --no-dev`; `php artisan optimize`

**D. Next.js**
- [ ] [R] `frontend/.env.production.local` with the two public URLs
- [ ] [R] `npm ci && npm run build`; service runs `next start -H 127.0.0.1 -p 3000`
- [ ] [R] Service restarts on boot (systemd/pm2)

**E. PostgreSQL**
- [ ] [R] New empty database; non-superuser app role; scram-sha-256
- [ ] [R] Not reachable from the internet; listen_addresses private
- [ ] [R] `db-preflight.sql` passed; pg_trgm installed

**F. Migrations / Seeders**
- [ ] [R] `FIRST=1 deploy-backend.sh` completed without error
- [ ] [R] Reference data present; users table empty before step G

**G. First SUPER_ADMIN**
- [ ] [R] Created interactively; Filament login works at admin.famboook.com

**H. Staff accounts**
- [ ] [R] Individual accounts with minimum roles; no shared accounts
- [ ] [R] Temporary passwords distributed out-of-band
- [ ] [R] Smoke/test accounts deactivated

**I. Permission verification**
- [ ] [R] `famboook:verify-permissions` passes (and on every deploy)

**J. Backup** (after the §17a rebuild)
- [ ] [R] Daily encrypted backup cron installed; retention 30 daily + 12 monthly
- [ ] [R] First backup of the final database produced and copied off-server
- [ ] [P] Backup failure alerting

**K. Restore test** (after the §17a rebuild)
- [ ] [R] `restore-test.sh` passed on a temporary database; counts match live
- [ ] [P] Monthly restore test during the Pilot

**L. Smoke test**
- [ ] [R] All §17 checks passed (one synthetic smoke Family)
- [ ] [R] Database rebuilt per §17a; checks re-run without creating a Family

**M. Pilot go-live**
- [ ] [R] Owner sign-off on this checklist
- [ ] [R] Pilot SOP (docs/09) handed to all Staff
- [ ] [R] Designated system administrator and incident contact named (fill the docs/09 placeholder at deployment)
- [ ] [P] Weekly review of Data Quality report and logs

---

# 20. Change Log

| Version | Date | Status | Description |
|---|---|---|---|
| 1.1.4 | 2026-10-02 | Approved | §16a: PWA-1E — `FAMILY_SMS_DRIVER` (empty = no delivery; `log` local only), the six-point Production gate for self-activation, OTP throttle overrides, OTP purge command and the scheduler-cron prerequisite. Nothing activated |
| 1.1.3 | 2026-10-02 | Approved | §16a: actual environment names (`FAMILY_AUTH_FINGERPRINT_KEY` and version, previous key and version, `FAMILY_ACTIVATION_ENABLED`) and the PWA-1C deployment note (seven additive migrations, role seeding, no backfill). Nothing activated |
| 1.1.2 | 2026-10-02 | Approved | §16a Family Portal activation prerequisites recorded (SMS provider, queue worker, delivery-failure handling, dedicated fingerprint secret, activation switch, retention, Head Succession rollout gate). Nothing deployed |
| 1.1.1 | 2026-10-01 | Approved | §3 `IMPORT_APPLY_ENABLED=false`; §7 verifier enforces the Import Apply gate; §7a Import Apply activation procedure (after the Apply UI phase and final review) and the persistent-connection invariant |
| 1.1 | 2026-09-27 | Approved for Pilot preparation | §2a owner decisions; §12 retention wording; §17 approved smoke Family; §17a rebuild after smoke test; checklist order (backup/restore after rebuild) |
| 1.0 | 2026-09-27 | Approved for Pilot preparation | Slice D: topology, audit, environment templates, cookie/Sanctum/CORS decision, database initialization, pg_trgm, permission verification, dev-surface gating, HTTPS, permissions, backup/restore, APP_KEY, logging, queues, smoke test, Staff setup, checklist |
