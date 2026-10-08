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
- Historical incident (recorded 2026-10-04): one browser received `419`
  on a Family POST while the API was reachable, `/sanctum/csrf-cookie`
  succeeded and the CORS preflight succeeded; clearing that browser's site
  data resolved it. It is treated as a stale / duplicate-cookie incident,
  not a Sanctum or CORS defect, and no configuration change is prescribed
  without a reproduced defect. **Before the first new Family Portal write
  endpoint is released, run a production-like CSRF write smoke test**
  (docs/11 §33a FU-08).

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

- **SMS provider: TweetsMS** (integrated 2026-10-03, see "TweetsMS SMS
  delivery" below). Everything sends through the `SmsSender` abstraction.
  `FAMILY_SMS_DRIVER` selects the driver: empty (the default, and the value
  in the Production template) binds a sender that always refuses, so
  nothing can be delivered; `tweetsms` is the Production driver; `log` is a
  LOCAL development driver: it appends to its own file
  (`storage/logs/family-sms-dev.log`, destination masked) and refuses to
  run outside the `local` and `testing` environments. Deploying the
  TweetsMS code changes nothing until the server `.env` sets
  `FAMILY_SMS_DRIVER=tweetsms` and the two TweetsMS values.
- **Production gate for Family self-activation.** Activation is not
  Production-ready until:
  1. a real SMS provider is selected and integrated — **code delivered**
     (TweetsMS);
  2. its credentials are securely configured — in the server `.env` only;
  3. delivery-failure behaviour is validated — with `famboook:sms-check`
     and one real test SMS (procedure below);
  4. the Production queue / retry architecture is decided and implemented
     — **decided and delivered**: the OTP SMS is sent after the HTTP
     response in the same PHP process, with no queue and no automatic
     retry (the user's resend is the retry), so no plaintext code is ever
     written to a queue;
  5. worker process supervision exists if queued delivery is used — **not
     applicable**: SMS delivery uses no queue;
  6. the scheduler cron is configured for scheduled maintenance — still
     a separate prerequisite, unchanged by TweetsMS;
  7. PWA-1G Family login is delivered and validated — the code is
     delivered (PWA-1G); validating it in the target environment remains;
  8. the response-time floor is set against the real provider;
  9. the three Family Auth flags are enabled deliberately, one decision
     each — never by a deployment.
- **Family Auth flags (PWA-1G).** Three independent switches, all `false`
  by default and in the Production template: `FAMILY_ACTIVATION_ENABLED`,
  `FAMILY_LOGIN_ENABLED`, `FAMILY_PASSWORD_RESET_ENABLED`. A closed surface
  answers 503 and does nothing. Login sends no SMS; activation and password
  reset do, and must stay closed until items 1–6 hold. Enabling activation
  without login would strand activated users after their session ends.
- **Login lockout (PWA-1G).** `FAMILY_LOGIN_LIMIT_DECAY_SECONDS` (900),
  `_IP_ATTEMPTS` (20), `_IDENTIFIER_IP_FAILURES` (5),
  `_IDENTIFIER_FAILURES` (20). Password reset request ceilings:
  `FAMILY_PASSWORD_RESET_LIMIT_*`, same names and defaults as activation.
  Reset start and resend use the same response floor as activation.
- **Sessions.** Family and Staff share one session configuration
  (`SESSION_LIFETIME=120`). A Family-specific lifetime is not implemented;
  it is to be reviewed with the installable PWA and the account module. A
  password reset deletes the account's session rows — this relies on
  `SESSION_DRIVER=database`; with any other driver the remaining sessions
  are still refused on their next request by the password-hash check.
- **Operational prerequisite.** Staff manage mobile trust from the Person
  profile card «توثيق رقم الجوال» (FU-15, docs/06 §22b; SUPER_ADMIN and
  ADMINISTRATOR through `person-mobile-trust.*`). Nobody can reset without
  a TRUSTED current mobile; a REVOKED trust is never revived — a new grant
  of the current registered mobile creates a new trust. FU-15 needs no
  migration and no environment change; frontend and backend deploy
  together as usual.
- **Digital Family Card (PWA-8.2, docs/11 FP-ADR-070).**
  1. Backend first (`deploy-backend.sh`): `composer install` (adds the
     explicit `chillerlan/php-qrcode` ^5 requirement, already present in
     the lock), `migrate --force` — ONE additive table,
     `digital_credentials`, no backfill — then `RolePermissionSeeder`
     (four `family-card.*` permissions) and `famboook:verify-permissions`.
     Then the frontend. The new routes are unused until the frontend ships.
  2. Environment (optional): `FAMILY_CARD_ISSUANCE_ENABLED` (default true —
     the switch blocks NEW credentials only, never verification or
     revocation); `CREDENTIAL_VERIFY_BASE_URL` (default
     `FRONTEND_URL` + `/verify/`); `CREDENTIAL_VERIFY_LIMIT_IP_MINUTE` /
     `_HOUR` (30 / 300); `FAMILY_CARD_LIMIT_USER_MINUTE` (10).
  3. `APP_KEY` seals the stored QR tokens: keep it stable; on any rotation
     set `APP_PREVIOUS_KEYS`, or existing cards show without a QR until
     reissued (verification of printed QRs is unaffected — it uses the hash).
  4. Smoke test: a test head opens `/family/card` (a card is issued once);
     scanning its QR on another device shows «بطاقة صالحة»; a Staff revoke
     makes it fail generically; a reissue gives a new number and QR and the
     old QR fails; `storage/logs` contains no token.
  5. Optional nginx hardening: do not access-log `/verify/` on
     `famboook.com` (the URL path carries the token) — the commented
     `location ^~ /verify/` block in `deploy/nginx/famboook.conf.example`;
     `nginx -t` before reloading. The API logs no token (it is in the body).
  6. Rollback: before any card exists, roll the code back and
     `migrate:rollback --step=1`. After cards exist, never drop the table
     (printed QRs would stop verifying): forward-fix; the issuance switch
     stops new cards while verification continues.
- **Digital Family Card PDF (PWA-8.3, docs/11 FP-ADR-071).**
  1. Before deploying, on the server: `php -m` must list `gd`, `mbstring`,
     `xml` and `zlib` (mPDF requires gd and mbstring; xml for SVG — the
     logo — and zlib for compression). Install the missing `php8.3-*`
     packages first.
  2. `deploy-backend.sh` as usual: `composer install --no-dev` adds
     `mpdf/mpdf` ^8.3 (and `setasign/fpdi`, `paragonie/random_compat`, the
     `mpdf/psr-*` shims); **no migration**; then `optimize`. Then the
     frontend.
  3. mPDF writes ONLY its temp / font-metric cache to
     `storage/framework/cache/mpdf` (`FAMILY_CARD_PDF_TEMP_DIR`), created
     on first use — `storage/` must stay writable by www-data (§ storage
     permissions). PDFs are generated in memory and never stored; the
     directory holds no PDF, QR or token and may be deleted at any time.
  4. The fonts ship in the repository
     (`backend/resources/fonts/ibm-plex-sans-arabic/`, OFL); nothing to
     install. The first PDF builds the font cache (slower once).
  5. Optional: `FAMILY_CARD_PDF_LIMIT_USER_MINUTE` (default 5).
  6. Manual Arabic check on Production: download as a test household head
     on Android Chrome, iOS Safari (and the installed PWA) and desktop;
     open in a PDF viewer; Arabic shaped and right-to-left, `FC-…` /
     `FAM-…` left-to-right in place, the date «16 أكتوبر 2026» style, long
     names wrap, the logo renders; print on A4 in colour and greyscale; the
     QR scans and shows «بطاقة صالحة»; after a Staff reissue the old PDF's
     QR fails. `storage/logs` contains no token.
  7. Rollback: code only (no migration); the mPDF cache directory can be
     removed.
- **Change Request foundation (PWA-5a, docs/05 WF-ADR-049, docs/04
  DB-ADR-058).** When deployed:
  1. `deploy-backend.sh` as usual — two additive migrations
     (`change_requests`, `workflow_events`; on PostgreSQL also the sequence
     `change_request_code_seq`, the CHECK constraints and the append-only
     trigger and function); no backfill; no new package and no `.env` key
     (the family submission switch arrives with PWA-5e);
  2. the seeder grants the new change-request permissions; run
     `php artisan famboook:verify-permissions` and expect no problem;
  3. nothing is reachable: no route, no UI, no request type;
  4. before the deploy, run the PostgreSQL suite (§16a) — the CHECK and
     trigger tests run only there;
  5. rollback: CODE only. Both migrations' `down()` refuse while a request
     or event exists; never drop or truncate request history (the trigger
     refuses UPDATE and DELETE on `workflow_events`).
- **Staff Change Request API (PWA-5c, docs/06 AUTH-ADR-089).** No
  migration, package, `.env` key or frontend change; `deploy-backend.sh`
  and `optimize` as usual. With the Production registry empty no request
  can be submitted, so the queue stays empty: after the deploy a Staff
  reviewer's `GET /api/v1/change-requests` answers 200 with no data, a
  family-side account 403, a guest 401. Rollback: code only.
- **Staff review workspace (PWA-5d, docs/07 RM-ADR-058).** Frontend only
  (`deploy-frontend.sh`); no backend change. After the deploy a reviewer
  sees «طلبات تحديث البيانات» in the Staff navigation and an empty queue
  («لا توجد طلبات تحديث بيانات») — no request type is enabled; DATA_ENTRY,
  SOCIAL_WORKER and REPORTS_VIEWER do not see the entry. Rollback: the
  previous frontend build.
- **Family Change Request API (PWA-5e, docs/11 FP-ADR-073).** No
  migration or package. New `.env` key `CHANGE_REQUESTS_FAMILY_SUBMISSION_ENABLED`
  — keep it `false` (or absent) in Production until a request type is
  approved and enabled (PWA-6.1); `optimize` after any change. Post-deploy:
  as an activated household head `GET /api/v1/family/change-requests`
  answers 200 with an empty history and `GET …/types` `{"data": [],
  "meta": {"submission_enabled": false}}`; a POST answers 503
  CHANGE_REQUEST_SUBMISSION_DISABLED. Rollback: code only.
- **Password reset enablement (FU-14, docs/11 FP-ADR-065).** Deploying
  FU-14 enables nothing: `FAMILY_PASSWORD_RESET_ENABLED` stays `false`
  in the server `.env` through the deployment, and the login page shows
  «نسيت كلمة المرور؟» only once the server reports reset open
  (`GET /api/v1/family/auth/capabilities`). Enabling it is one explicit
  decision, after every item below, in this order:
  1. after the FU-14 deployment, with the flag still `false`:
     `GET https://api.famboook.com/api/v1/family/auth/capabilities`
     answers `password_reset: false` with `Cache-Control: no-store,
     private`, the login page shows no «نسيت كلمة المرور؟», and
     `/family/forgot-password` shows the unavailable notice;
  2. Production CSRF write smoke (docs/11 FU-08): from a normal browser
     profile with no stale cookies, a Family login (a write through
     `/sanctum/csrf-cookie`) succeeds without 419. No Sanctum / CORS change
     without a reproduced defect;
  3. `SESSION_DRIVER=database` in the server `.env` (the reset deletes the
     account's `sessions` rows; with any other driver only the
     password-hash check ends old sessions);
  4. the response floor: confirm the Production
     `FAMILY_ACTIVATION_MIN_RESPONSE_MS` was measured against the real
     provider (gate item 8). Reset shares it; do not guess or change it as
     part of enabling reset;
  5. TweetsMS operational: `php artisan famboook:sms-check` ends with
     `Configuration: OK` (YES/NO only — never copy key or sender values
     into notes or tickets), and activation SMS are arriving;
  6. `php artisan famboook:family-auth-check` ends with "no warnings";
  7. set `FAMILY_PASSWORD_RESET_ENABLED=true`, then
     `php artisan config:cache` (the flag is read from the cached
     configuration; without the refresh nothing changes);
  8. capabilities now answer `password_reset: true`; after a page reload
     the login page shows «نسيت كلمة المرور؟» — only now;
  9. test household (synthetic or the operator's own, already activated,
     TRUSTED mobile): sign in on a second device or browser, then on the
     first run the full reset (National ID → code by SMS → new password).
     The completing browser lands in `/family`; the old password is
     refused; the new one signs in; the second device's session is gone
     on its next request;
  10. where a Coordinator test account exists: after its reset it is still
      FAMILY_USER + COORDINATOR with the same scopes, and Coordinator Space
      opens;
  11. check `auth_security_events` for the test: `PASSWORD_RESET_REQUESTED`
      SUCCESS, `OTP_*` with purpose `PASSWORD_RESET`,
      `PASSWORD_RESET_COMPLETED` SUCCESS, `SESSIONS_REVOKED` — and no
      National ID, mobile, code or password anywhere in them or in the
      logs.
- **Password reset rollback.** Set `FAMILY_PASSWORD_RESET_ENABLED=false`,
  then `php artisan config:cache`. The four reset routes answer 503 at
  once, capabilities answer `password_reset: false` and the link
  disappears on the next page load. No migration and no data change;
  open reset challenges simply expire. Login and activation are not
  affected.
- **Coordinators (PWA-1H).** Deploying PWA-1H adds one permission,
  `coordinator-family.view-summary`, granted to COORDINATOR: run the
  RolePermissionSeeder and `famboook:verify-permissions` as usual (§7). No
  migration, no new flag. Coordinators are managed through the Staff API
  only (no Staff screen yet) by SUPER_ADMIN and ADMINISTRATOR; a
  Coordinator must first activate as an ordinary Family account, and
  Coordinator Space is reachable only once Family login is enabled. An
  assignment never expires: remove it explicitly when a Coordinator stops.
- **Activation abuse controls (PWA-1F).** Defaults in
  `config/family_auth.php`, overridable by
  `FAMILY_ACTIVATION_LIMIT_START_IP_MINUTE` (10) / `_START_IP_HOUR` (30) /
  `_START_IDENTIFIER_HOUR` (5) / `_VERIFY_IP_MINUTE` (30) /
  `_RESEND_IP_MINUTE` (10) / `_COMPLETE_IP_MINUTE` (10). They use the
  application cache store, as do the decoy activation references.
  `FAMILY_ACTIVATION_MIN_RESPONSE_MS` (400) is a **development default**:
  since TweetsMS is called after the response, the floor no longer has to
  cover the provider's latency — it covers the difference between a real
  challenge and a decoy (database work only) and is reviewed against
  Production measurements (gate item 8). Since PWA-1I it applies to all
  four public OTP steps — start, verify, resend and complete. It holds a
  PHP worker for its
  duration; the after-response SMS call holds the same worker for up to
  the TweetsMS timeout (8 s by default) once the client has its answer.
- **OTP abuse ceilings** are security settings with defaults in
  `config/family_auth.php`, overridable by `FAMILY_OTP_THROTTLE_PERSON_HOUR`
  / `_PERSON_DAY` / `_DESTINATION_HOUR` / `_DESTINATION_DAY` / `_IP_HOUR` /
  `_GLOBAL_HOUR`. They use the application cache store; if it cannot be
  read, no SMS is sent.
- **Queue worker.** §16 stays true for the Pilot. SMS delivery does not
  need a worker: it runs after the response, in the web process (PHP-FPM
  finishes the request first), and nothing is queued. A worker is still
  needed later for announcement fan-out (PWA-9).
- **Delivery-failure handling** exists: every failure is classified
  (TEMPORARY_FAILURE, PERMANENT_FAILURE, PROVIDER_CONFIGURATION_FAILURE,
  UNKNOWN with a reason code), logged without the number, text, code or key,
  and recorded on the OTP_ISSUED security event. The public answer never
  changes. A configuration or credit failure is logged CRITICAL, at most
  once per reason every 10 minutes.
- **Secrets**, stored like every other credential (§3, never in the
  repository):
  - a dedicated Family Portal fingerprint secret, separate from `APP_KEY`:
    `FAMILY_AUTH_FINGERPRINT_KEY` (at least 32 bytes, optionally
    `base64:`), with `FAMILY_AUTH_FINGERPRINT_KEY_VERSION` (default 1) and,
    only during a rotation, `FAMILY_AUTH_FINGERPRINT_PREVIOUS_KEY` /
    `FAMILY_AUTH_FINGERPRINT_PREVIOUS_KEY_VERSION`. Losing or changing it
    without the rotation procedure breaks Family login; it must be covered
    by the same custody rules as §14;
  - the SMS provider credentials: `TWEETSMS_API_KEY` and `TWEETSMS_SENDER`,
    in the server `.env` only — never in `.env.example`, the Production
    template, the repository, a ticket or a command line.
- **Activation switch.** `FAMILY_ACTIVATION_ENABLED=false` (the default)
  until the owner approves it, following the same pattern as the Import
  Apply gate (§7a). Since PWA-1F it gates the four public activation
  endpoints: while false they answer 503 and do nothing. Deploying PWA-1F
  does not enable activation, and `/family/activate` then only shows
  "service unavailable". The Family Portal is served by the existing
  frontend on the same origin, so CORS, the stateful domain and the session
  cookie need no change.
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

## TweetsMS SMS delivery

Recorded 2026-10-03 (docs/11 §30a). The code is in the repository; nothing
is configured or enabled on the server by deploying it.

- **Request.** `POST https://www.tweetsms.ps/api.php/office/sendsms`, JSON:
  `{"api_key", "sender", "message", "to"}` — one destination, nothing else
  (no groups, date, time, name, National ID or family data).
- **Number format.** `to` is the registry's own local format,
  `05XXXXXXXX`, sent as stored. It is never converted to `970…` or
  `+970…`, and storage is unchanged.
- **Success.** Only a JSON result `code` of `999` (number or string) is a
  send. HTTP 2xx, `"status": "success"` or `"msg": "send success"` prove
  nothing alone; a malformed, missing or unknown result is not a send.
  `999` means TweetsMS **accepted** the SMS — it is not proof that the
  handset received it.
- **Failure classes.** -126 TEMPORARY_FAILURE (PROVIDER_BUSY); -124
  INSUFFICIENT_CREDIT, -110 INVALID_CREDENTIALS, -111 ACCOUNT_INACTIVE,
  -112 ACCOUNT_BLOCKED, -114 SENDING_STOPPED, -115 / -116 INVALID_SENDER —
  all PROVIDER_CONFIGURATION_FAILURE; -100 MISSING_PARAMETERS and -120
  INVALID_DESTINATION — PERMANENT_FAILURE; anything else UNKNOWN.
  Provider codes are never shown to Family Portal users.
- **Transport.** TLS verified, redirects refused, connect timeout 3 s,
  total timeout 8 s, no automatic retry. A timeout is UNKNOWN (the SMS may
  have been sent).
- **Timing.** The OTP SMS of activation and password reset is sent after
  the HTTP response, in the same PHP process, with no queue. The code
  exists only in memory until then.
- **Configuration** (server `.env` only):

  ```dotenv
  FAMILY_SMS_DRIVER=tweetsms
  TWEETSMS_API_KEY=<from the TweetsMS account — never in Git>
  TWEETSMS_SENDER=<the approved sender name>
  # optional: TWEETSMS_ENDPOINT, TWEETSMS_CONNECT_TIMEOUT=3, TWEETSMS_TIMEOUT=8
  ```

  With `tweetsms` and a missing key or sender nothing is sent (the failure
  is logged CRITICAL); the Staff application keeps working.
- **Validation procedure** (before any Family Auth flag is enabled):
  1. set the three values in the server `.env`, then
     `php artisan config:cache`;
  2. `php artisan famboook:sms-check` — prints the driver and API key /
     sender as YES/NO, never their values, and sends nothing. It must end
     with `Configuration: OK`;
  3. `php artisan famboook:sms-check --send-test=05XXXXXXXX` with a
     number the operator holds — confirms interactively, sends ONE fixed
     non-OTP text ("رسالة اختبار من Famboook") and prints `SENT` or the
     outcome and reason. The full number is never printed;
  4. confirm the SMS arrived on the handset (`SENT` alone does not prove
     it);
  5. only then enable the Family Auth flags, one decision each.
- **Rollback.** Set `FAMILY_SMS_DRIVER=` (empty), or close the Family Auth
  flags, then `php artisan config:cache`. No migration and no data change
  is involved; challenges issued meanwhile simply expire.

## Installable Family app (FP-ADR-055): deployment notes

Recorded 2026-10-04. Frontend only: a normal frontend build and restart.

- New public files: `/manifest.webmanifest`, `/icons/*`, `/family-sw.js`,
  `/family/offline.html`. `/family-sw.js` must keep being served with
  `Cache-Control: max-age=0` (the Next.js default for public files) so that
  updates reach installed apps; nginx needs no change.
- Check after deploy: `/family-sw.js` answers 200 `application/javascript`;
  on an Android phone, Chrome offers "تثبيت التطبيق" and Firefox "تثبيت"
  on `/family/login` after one visit (the worker installs on the first
  load). An existing home-screen shortcut may keep its old look until it is
  removed and the app is installed.
- Rollback: deploy the previous frontend and remove the worker for existing
  users by serving a `/family-sw.js` that unregisters itself (a worker is
  never removed by deleting the file alone).

## First-activation refusal (FP-ADR-054): deployment notes

Recorded 2026-10-04, after the Production pilot.

- **No migration, no new env.** The activation `start` now answers an input
  that cannot activate with `422 ACTIVATION_REFUSED` instead of a decoy
  confirmation. **Deploy the frontend and the backend together** — the
  previous frontend does not know the new code.
- **What staff will see in the pilot:** a non-head, unknown or otherwise
  ineligible National ID stays on step 1 with "تعذّر متابعة التفعيل بهذه البيانات. تأكد من إدخال رقم هوية رب الأسرة المسجل في فامبوك، ثم حاول مرة أخرى." No SMS is sent and no
  row is created; the reason is in `auth_security_events`
  (`ELIGIBILITY_DENIED`, count-only queries).

## First self-activation (SELF_OTP): deployment notes

Recorded 2026-10-04 (docs/11 FP-ADR-053).

- **One migration** (`2026_10_15_090000_allow_self_otp_mobile_trust`):
  CHECK constraints only, no data change; run by `deploy-backend.sh`.
- **The frontend and backend deploy together**: the activation API changed
  (`start` now returns a confirmation and the masked number; the new
  `send` issues the code). An old frontend against the new API (or the
  reverse) cannot activate — deploy both in the same window.
- **New optional env:** `FAMILY_ACTIVATION_LIMIT_SEND_IP_MINUTE` (10).
- **Pilot impact:** a pilot household no longer needs a Staff trust grant;
  it needs a VALID current registered mobile (`05XXXXXXXX`) that its head
  holds. The first pilot's existing IN_PERSON trust is reused as it is.
  Because any eligible head with a registered number can now self-activate
  once the activation flag is on, enabling activation now opens it to every
  eligible household — not only to prepared ones. Decide the rollout on that
  basis.
- **Residual risk accepted with the decision:** an outdated or reassigned
  registry number reaches whoever holds it now; the masked digits are shown
  to anyone who enters an eligible National ID.

## PWA-1I — security hardening: Production requirements

Recorded 2026-10-03 (docs/11 §30a). PWA-1I adds no migration, no flag and
no permission; deploying it enables nothing. What it requires of the
environment, and what must be validated there, is recorded here — none of
it has been verified on the server yet.

- **Readiness check.** `php artisan famboook:family-auth-check` —
  read-only; counts and YES/NO only, never a National ID, mobile,
  fingerprint or secret. Run it after every deploy and before any flag
  change; it must end with "no warnings". It reports a missing or invalid
  fingerprint key AND a **wrong** one (ACTIVE identities / TRUSTED mobiles
  that no longer match), which otherwise fails silently.
- **PHP-FPM** (after-response SMS, docs/11 FP-ADR-051):
  - the SAPI serving the API must be `fpm-fcgi` — only PHP-FPM (or
    LiteSpeed) sends the response before the SMS call;
  - `request_terminate_timeout` at least 30 seconds, or unset — the SMS
    call runs after the response for up to the TweetsMS timeout (8 s);
  - `pm.max_children` with headroom: every OTP request holds a worker for
    the response floor plus up to 8 s after its response.
- **Client IP and proxies.** The throttles key on the client IP as nginx
  passes it (`REMOTE_ADDR`); Laravel trusts no proxy header. This is only
  correct while nothing sits in front of nginx. If a CDN, load balancer or
  reverse proxy is ever introduced, Laravel `trustProxies` and nginx
  `real_ip` (restricted to that proxy's addresses) must be configured
  BEFORE relying on any IP throttle — otherwise every client shares one IP.
- **CGNAT risk.** Palestinian mobile users, and users sharing one venue or
  network, may share a public IP address. The per-IP ceilings (activation /
  reset start 10 per minute and 30 per hour, OTP SMS 20 per hour, login 20
  per 15 minutes) may then be reached by legitimate users, and an exhausted
  SMS ceiling turns a real start into a decoy ("code sent", nothing
  arrives; the reason is in auth_security_events only). No new values are
  set without data: a limited-cohort review of these events is required
  before general rollout.
- **Response floor.** One value for start, verify, resend and complete.
  Measure on Production-like infrastructure (same PostgreSQL, PHP-FPM and
  `CACHE_STORE=database`, synthetic data) with the floor at 0: real and
  decoy, at least 500 samples each, p50 / p95 / p99. Set the floor above
  the slowest p99 with a margin; confirm in Production with the single test
  household against unknown identifiers.
- **PostgreSQL validation** before the deploy, on a PHYSICALLY SEPARATE
  test instance — never the development PostgreSQL server or database
  (developer workstation, Windows):
  1. create a separate, disposable PostgreSQL cluster once (`initdb` into a
     data directory in the user profile, with password authentication) and
     run it with `pg_ctl` on **port 5433, listening on localhost only**. It
     is not installed as a service and does not touch the development
     server's configuration; start it with `pg_ctl … start` when needed and
     stop it with `pg_ctl … stop`. Deleting its data directory removes it;
  2. create the database in THAT instance, by hand:
     `createdb -h 127.0.0.1 -p 5433 -U postgres famboook_test`
     (host, port, user and database as `phpunit.pgsql.xml` forces them; the
     application never creates it);
  3. keep the instance's password outside Git: one line for
     `127.0.0.1:5433` in the user's libpq password file (`pgpass.conf`).
     `phpunit.pgsql.xml` forces an empty `DB_PASSWORD`, so libpq reads that
     file; `PGPASSWORD` is not used in that case. Never write the password
     into the repository, `.env.example`, this document or a ticket;
  4. run `vendor/bin/phpunit -c phpunit.pgsql.xml tests/Feature/FamilyAuth`
     (the CHECK constraints, `PostgresConcurrencyTest` and every other
     Family Auth test), then the whole suite the same way.
  `TestDatabaseGuard` refuses any database whose name does not end in
  `_test`, and the development database of `.env` — but it checks the NAME
  only, not the server: it is a second line of defence, not a substitute
  for the physical separation above.
- **Retention.** `auth_otp_challenges.ip` keeps the raw client IP of the
  issuing request for at most the 90-day retention of finished challenges
  (`famboook:purge-otp-challenges`, which needs the scheduler cron).
- **Production validation still owed:** real PHP-FPM early flush
  (activation start timing with the floor at 0 and TweetsMS live), the
  settings above, the response-floor measurement, the PostgreSQL suite, the
  readiness check clean, and the controlled flag rollout (§16a gate).

---

# 16b. Change Request release (PWA-5b … PWA-6.1a)

Production runs PWA-5a (`e36ebd7`: the two Change Request tables, CHECKs,
sequence, append-only trigger and permissions). This release brings
PWA-5b, 5c, 5d, 5e, 5f, 6.1 and 6.1a. It is deployed **closed**: the family
submission switch stays off, and opening the pilot is a separate,
explicit authorization (docs/09 «طلبات تحديث السكن — التجربة المضبوطة»).

## Release content since PWA-5a (verified 2026-10-08)

| Item | Change |
|---|---|
| Migrations | **None.** No file under `backend/database/migrations` changed after `e36ebd7`; the PWA-5a schema is used as is |
| Seeders / permissions | **None.** `RolePermissionSeeder` is unchanged since PWA-5a; seeding stays idempotent and `famboook:verify-permissions` must pass |
| Packages | None (`composer.lock`, `package-lock.json` unchanged) |
| Configuration | New `config/change_requests.php`; one `.env` key `CHANGE_REQUESTS_FAMILY_SUBMISSION_ENABLED` (absent = false). Optional limits `CHANGE_REQUESTS_FAMILY_SUBMIT_LIMIT_USER_MINUTE` (3), `…_HOUR` (20), `CHANGE_REQUESTS_FAMILY_ACTION_LIMIT_USER_MINUTE` (10) |
| Backend | Domain engine (5b), Staff API (5c), Family API (5e), RESIDENCE_UPDATE handler and presentation context (6.1) — the only registered type |
| Frontend | Staff «طلبات تحديث البيانات» workspace (5d), Family request screens (5f), residence form (6.1), Family navigation (6.1a) |
| Unchanged | Family authentication, activation, login, password reset, the Digital Family Card / PDF / public verification, registry screens |

## Release gates (all before the deployment)

1. The exact commit to deploy is on `origin/main` and recorded (full SHA).
2. The local repository is clean at that commit; backend SQLite suite,
   frontend suite, typecheck, ESLint and production build pass on it.
3. The PostgreSQL suite (§16a, isolated test cluster on port 5433) passes
   for `tests/Feature/ChangeRequests` — including the concurrency tests,
   which run only there.
4. The latest nightly backup exists off-server **and** a restore test (§13)
   has passed on it. Take a fresh `backup-db.sh` run immediately before
   the deployment and confirm its checksum.
5. The server `.env` contains `CHANGE_REQUESTS_FAMILY_SUBMISSION_ENABLED=false`
   (added explicitly, not left implicit).
6. Free disk space and memory are sufficient for `npm run build` (it
   fails with out-of-memory errors on a 4 GB machine under load).

## Deployment (runbook — do not improvise)

Production's checkout is a detached HEAD owned by the `deploy` user. Every
git and deployment command runs **as `deploy`**, never as root (a root
checkout leaves root-owned files that break later deployments).
`<app-root>` is the server's Famboook directory, `<sha>` the approved commit.

```text
# 0. backup, then confirm it
sudo -u deploy <app-root>/deploy/scripts/backup-db.sh

# 1. code
sudo -u deploy git -C <app-root> fetch origin
sudo -u deploy git -C <app-root> checkout --detach <sha>
sudo -u deploy git -C <app-root> status --short     # must be empty
sudo -u deploy git -C <app-root> rev-parse HEAD      # must equal <sha>

# 2. configuration (server .env, by the system administrator)
CHANGE_REQUESTS_FAMILY_SUBMISSION_ENABLED=false

# 3. backend: down → composer → migrate (expects "Nothing to migrate")
#    → RolePermissionSeeder → famboook:verify-permissions → optimize → up
sudo -u deploy <app-root>/deploy/scripts/deploy-backend.sh

# 4. PHP-FPM picks up the new code and cached config
sudo systemctl reload php<version>-fpm

# 5. frontend: npm ci → build → restart the service
sudo -u deploy <app-root>/deploy/scripts/deploy-frontend.sh

# 6. web server
sudo nginx -t && sudo systemctl reload nginx
```

If `migrate` reports anything other than "Nothing to migrate", **stop**:
the server is not at the expected PWA-5a schema.

## Post-deployment smoke checks (read-only)

No request is created; no data is written beyond logins.

1. `curl https://api.famboook.com/api/v1/health` → `{"status":"ok"}`;
   `famboook.com/login` and `famboook.com/family/login` load.
2. `php artisan famboook:verify-permissions` passes.
3. `php artisan tinker --execute="dump(config('change_requests.family_submission_enabled'));"`
   prints `false`.
4. A guest `GET /api/v1/change-requests` → 401; `GET /api/v1/family/change-requests/types` → 401.
5. A Staff REVIEWER sees «طلبات تحديث البيانات» and an empty (or
   unchanged) queue; a DATA_ENTRY user does not see the entry.
6. An authorized household head (the pilot account, docs/09): the bottom
   navigation shows «طلباتي» as a link and «+» disabled
   («إجراء جديد (قريبًا)»); «طلباتي» opens an empty history;
   `GET …/change-requests/types` answers
   `{"data": [], "meta": {"submission_enabled": false}}`;
   `/family/requests/new/residence-update` shows «طلبات تحديث السكن غير
   متاحة حاليًا». Do NOT submit anything.
7. Existing features unaffected: Family login, «أسرتي», «حسابي», the Digital
   Family Card page and its PDF, a public `/verify` check of a known
   synthetic or authorized card.
8. `storage/logs/laravel.log` shows no new errors.

## Opening the pilot (separate authorization)

Only after the owner authorizes the pilot in writing and docs/09's
preconditions are met:

```text
# server .env
CHANGE_REQUESTS_FAMILY_SUBMISSION_ENABLED=true
sudo -u deploy php artisan config:clear
sudo -u deploy php artisan optimize
sudo systemctl reload php<version>-fpm
```

`GET …/types` then lists RESIDENCE_UPDATE and «+» becomes active within a
minute (or on refocus). The switch opens the channel for **every** eligible
household head with an active account, not only the pilot Family: open it
for the agreed pilot window only, while only authorized pilot accounts are
activated, and close it afterwards.

## Feature shutdown (any time, no code change)

```text
CHANGE_REQUESTS_FAMILY_SUBMISSION_ENABLED=false
sudo -u deploy php artisan config:clear
sudo -u deploy php artisan optimize
sudo systemctl reload php<version>-fpm
```

Effect: new submissions answer 503 CHANGE_REQUEST_SUBMISSION_DISABLED and
«+» returns to disabled; existing requests stay readable; families can
still reply to a clarification or cancel; Staff can still review, approve,
reject and APPLY. Nothing is deleted. This is the first response to any
incident in the request channel.

## Rollback

Three different operations — never mix them up:

| Operation | What it does | When |
|---|---|---|
| Feature shutdown | Switch off (above) | First response to any problem with submissions |
| Code rollback | Check out the previous commit (`e36ebd7`, PWA-5a) as `deploy`, re-run `deploy-backend.sh` and `deploy-frontend.sh` | A defect in the release code, and only per the cases below |
| Data restoration | Restore a backup | Only for data loss or corruption, by decision of the owner — never as a "rollback" of this release |

**A. Before any real request exists** (`change_requests` and
`workflow_events` are empty — check with a read-only count):
a code rollback to `e36ebd7` is compatible, because this release added no
migration — the PWA-5a schema stays as is. Do **not** roll back the
PWA-5a migrations, drop the tables, or truncate anything.

**B. After a real request exists:**
1. turn the switch off (feature shutdown);
2. prefer a **forward fix** of the code; a code rollback to PWA-5a would
   leave existing requests without the screens and actions that serve them
   (families could not see or cancel them, Staff could not apply them);
3. never roll back migrations, delete or edit `change_requests` rows, or
   touch `workflow_events` (the append-only trigger refuses UPDATE / DELETE
   — do not disable it);
4. never restore a database backup to "undo" requests: a restore also
   discards every other real transaction since the backup (registrations,
   corrections, deliveries, card events). A request that should not take
   effect is rejected (or, once APPROVED and no longer applicable,
   rejected as NO_LONGER_APPLICABLE) through the normal workflow; an
   applied correction is corrected by a new correction, never by deleting
   history.

## Remaining Production prerequisites

- The deployment above, by the system administrator.
- §13 restore test passed on a current backup (still marked REQUIRED).
- Written pilot authorization and the pilot Family / accounts (docs/09).
- A designated Staff reviewer with `change-request.apply`, briefed.
- The incident contact in docs/09 filled in by the owner.

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
| 1.1.5 | 2026-10-02 | Approved | §16a: PWA-1F — activation gate now read by the public endpoints; activation limiter and response-floor overrides (400 ms is a development default); PWA-1G Family login added to the Production gate (seven points). Nothing activated |
| 1.1.6 | 2026-10-03 | Approved | §16a: PWA-1G — three independent Family Auth flags (all false), login lockout and password reset limiter overrides, session notes, gate item 7 code-delivered and items 8–9 added. Nothing activated |
| 1.1.7 | 2026-10-03 | Approved | §16a: PWA-1H — seed `coordinator-family.view-summary`, no migration or flag, coordinators managed through the Staff API by SUPER_ADMIN and ADMINISTRATOR, no assignment expiry |
| 1.1.8 | 2026-10-03 | Approved | §16a: TweetsMS SMS delivery — `FAMILY_SMS_DRIVER=tweetsms`, `TWEETSMS_API_KEY` / `TWEETSMS_SENDER` in the server `.env` only, `05XXXXXXXX` unchanged, success only on code 999 (accepted, not handset delivery), failure classes, after-response with no queue and no retry, `famboook:sms-check` validation procedure and rollback; gate items 1, 4 and 5 resolved, scheduler cron still separate. Nothing enabled |
| 1.1.9 | 2026-10-03 | Approved | §16a: PWA-1I — `famboook:family-auth-check` (read-only readiness; detects a wrong fingerprint key), PHP-FPM requirements (`fpm-fcgi`, `request_terminate_timeout` >= 30 s or unset, `pm.max_children` headroom), proxy / `trustProxies` rule, CGNAT risk and limited-cohort review, response floor on all four OTP steps and its measurement, `famboook_test` PostgreSQL procedure, raw challenge IP retention. No migration, nothing enabled |
| 1.1.10 | 2026-10-03 | Approved | §16a PWA-1I: the PostgreSQL validation runs on a physically separate, disposable test cluster (`initdb`, `pg_ctl`, port 5433, localhost only, never a service, never the development server); credentials in `pgpass.conf` only; `TestDatabaseGuard` is a secondary guard (name only) |
| 1.1.11 | 2026-10-04 | Approved | §16a: first self-activation deployment notes — migration, frontend and backend deployed together, `FAMILY_ACTIVATION_LIMIT_SEND_IP_MINUTE`, pilot impact (no Staff grant needed; activation flag opens it to every eligible household), residual risks |
| 1.1.12 | 2026-10-04 | Approved | §16a: first-activation refusal (FP-ADR-054) deployment notes — no migration, frontend and backend together, what pilot staff see |
| 1.1.13 | 2026-10-04 | Approved | §16a: installable Family app (FP-ADR-055) — new public files, cache header of the worker, post-deploy checks, rollback of a service worker |
| 1.1.14 | 2026-10-04 | Approved | Session cookies: the Family 419 incident recorded as a stale / duplicate-cookie incident; production-like CSRF write smoke test required before the first new Family Portal write endpoint (docs/11 FU-08) |
| 1.1.15 | 2026-10-06 | Approved | §16a: FU-14 password reset enablement checklist (flag stays false through deployment, CSRF write smoke, `SESSION_DRIVER=database`, measured response floor, TweetsMS check without secrets, `config:cache` after the flag change, capabilities and link verified, test-household reset with prior-session revocation, coordinator preservation, security events) and rollback. Nothing enabled |
| 1.1.16 | 2026-10-06 | Approved | §16a: FU-15 — the Staff mobile trust screen exists (Person profile «توثيق رقم الجوال»); the "API only" operational prerequisite is closed. No migration, no environment change |
| 1.1.17 | 2026-10-07 | Approved | §16a: PWA-8.2 Digital Family Card — deployment order (backend migration and seeding first), environment switches and limits, APP_KEY / APP_PREVIOUS_KEYS, smoke test, optional nginx `/verify/` access-log hardening, rollback / forward-fix |
| 1.1.18 | 2026-10-07 | Approved | §16a: PWA-8.3 Digital Family Card PDF — `php -m` gd / mbstring / xml / zlib, composer adds mPDF (no migration), mPDF temp / font cache under storage/framework/cache/mpdf, bundled fonts, manual Arabic and print check, rollback |
| 1.1.19 | 2026-10-07 | Approved | §16a: PWA-5a Change Request foundation — two additive migrations (PostgreSQL CHECKs, code sequence, append-only trigger), permission seeding and verify-permissions, PostgreSQL suite before the deploy, code-only rollback; no `.env` change |
| 1.1.20 | 2026-10-08 | Approved | §16a: PWA-5c Staff Change Request API — no migration or configuration; post-deploy checks (empty queue 200 for a reviewer, 403 family-side, 401 guest); code-only rollback |
| 1.1.21 | 2026-10-08 | Approved | §16a: PWA-5d Staff review workspace — frontend-only deploy, post-deploy checks (navigation by permission, empty queue), rollback to the previous build |
| 1.1.22 | 2026-10-08 | Approved | §16a: PWA-5e Family Change Request API — CHANGE_REQUESTS_FAMILY_SUBMISSION_ENABLED stays false in Production, post-deploy checks, code-only rollback |
| 1.1.23 | 2026-10-08 | Approved | §16b: Change Request release PWA-5b … PWA-6.1a — content since PWA-5a (no migration, no seeder change, one `.env` key), release gates, deployment with the switch off, read-only smoke checks, feature shutdown, rollback before / after real requests; env template carries the switch |
| 1.1.3 | 2026-10-02 | Approved | §16a: actual environment names (`FAMILY_AUTH_FINGERPRINT_KEY` and version, previous key and version, `FAMILY_ACTIVATION_ENABLED`) and the PWA-1C deployment note (seven additive migrations, role seeding, no backfill). Nothing activated |
| 1.1.2 | 2026-10-02 | Approved | §16a Family Portal activation prerequisites recorded (SMS provider, queue worker, delivery-failure handling, dedicated fingerprint secret, activation switch, retention, Head Succession rollout gate). Nothing deployed |
| 1.1.1 | 2026-10-01 | Approved | §3 `IMPORT_APPLY_ENABLED=false`; §7 verifier enforces the Import Apply gate; §7a Import Apply activation procedure (after the Apply UI phase and final review) and the persistent-connection invariant |
| 1.1 | 2026-09-27 | Approved for Pilot preparation | §2a owner decisions; §12 retention wording; §17 approved smoke Family; §17a rebuild after smoke test; checklist order (backup/restore after rebuild) |
| 1.0 | 2026-09-27 | Approved for Pilot preparation | Slice D: topology, audit, environment templates, cookie/Sanctum/CORS decision, database initialization, pg_trgm, permission verification, dev-surface gating, HTTPS, permissions, backup/restore, APP_KEY, logging, queues, smoke test, Staff setup, checklist |
