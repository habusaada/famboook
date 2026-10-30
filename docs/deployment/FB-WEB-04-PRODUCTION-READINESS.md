# FB-WEB-04 — Production Readiness and Deployment Checklist

**Status:** not deployed. Nothing here has been executed, and deployment needs the project
owner's explicit approval. Decision record: `docs/adr/FB-WEB-04-PUBLIC-HOMEPAGE.md`.

**Evidence:** Production observations come from a read-only inspection on 2026-10-30.
Re-verify each one, because Production changed during that same day.

## 1. Unresolved operational items

| # | Item | Observed | Needed | Blocks |
|---|---|---|---|---|
| 1 | Uncommitted `backend/config/cors.php` | Modified in the Production working tree; adds `CORS_ALLOWED_ORIGINS` | Replace it with the reviewed repository version (§3) | **Deployment** |
| 2 | Untracked files in the Production repository | Includes names such as `backend/e]);`, `backend/t();`, `backend/Family::query()` and fragments of shell commands; contents not opened | A person with server access reviews them and decides; `git clean` is not a safe shortcut, because the `.next.pre-*` rollback builds are untracked too | **Deployment** |
| 3 | Duplicate `SESSION_DOMAIN` in the backend `.env` | `null`, then `.famboook.com`; live cookies used `.famboook.com` | Keep one line, `.famboook.com`, in an approved window | Cleanup |
| 4 | Deployment script | `deploy-frontend.sh` is not executable, and its `sudo systemctl restart` needs a password that `deploy` lacks; FB-OPS-01, not started | Use the known workaround: run it with `bash`, and an administrator restarts the service | Known workaround |
| 5 | Real Staff authentication | Not tested on this change; only browser API stubs were used | §5 | **Acceptance** |
| 6 | Real Family authentication | Not tested on this change | §5 | **Acceptance** |
| 7 | PWA behaviour | Unit tests only | §5 | **Acceptance** |
| 8 | QR verification and privacy headers | Headers seen on an invalid token only; no end-to-end QR test recorded | §5 | **Acceptance** |
| 9 | Nginx `X-Robots-Tag` policy | `noindex` on all Famboook hosts; `/verify` on the apex sends it twice, which is harmless | Unchanged by this release; §6 is a separate approval | SEO only |
| 10 | `app.famboook.com` vhost comment | Says "HTTPS staging configuration" | Correct the comment at the next approved Nginx change; the host is official Production | Cleanup |

## 2. Pre-deployment verification

Read-only, on Production:

- [ ] `git log -1` shows the expected current commit (`be44d92` when inspected)
- [ ] `git status --short` is reviewed, and every entry has a decision (items 1 and 2)
- [ ] `git diff -- backend/config/cors.php` is saved outside the repository for the record
- [ ] `backend/.env` has `CORS_ALLOWED_ORIGINS=https://famboook.com,https://app.famboook.com`
- [ ] `backend/.env` has `SANCTUM_STATEFUL_DOMAINS` including `famboook.com` and `app.famboook.com`
- [ ] `backend/.env` has `CREDENTIAL_VERIFY_BASE_URL=https://famboook.com/verify`
- [ ] `frontend/.env.production` still has its public URLs and legal effective date
- [ ] Listener is `127.0.0.1:3001`, and `famboook-frontend` is active
- [ ] `nginx -t` passes, and every Famboook host answers `X-Robots-Tag: noindex, nofollow`
- [ ] Disk space is enough for one more build
- [ ] A current database backup exists, per docs/08 §12, even though this release has no migration

Locally:

- [ ] Typecheck, lint, tests and build pass on the exact commit
- [ ] Backend `CorsConfigurationTest` passes
- [ ] The commit has been reviewed and pushed, with explicit approval for each step

Tell the operator of the co-hosted site about the window. Reloading PHP-FPM and Nginx is
graceful, but both are shared.

## 3. Deployment

Do not improvise, and stop at any failed step.

**Backend configuration, first.** The new frontend's `app.` host needs CORS to keep working.

- [ ] Remove the Production-only edit so the update can apply: `git checkout -- backend/config/cors.php`. This is a deliberate discard, allowed only after the diff is saved
- [ ] `git pull --ff-only`, confirming the expected commit
- [ ] `php artisan config:cache`
- [ ] `systemctl reload php<version>-fpm`
- [ ] Both origins pass a preflight:

```bash
for o in https://famboook.com https://app.famboook.com; do
  curl -si -X OPTIONS https://api.famboook.com/api/v1/me \
    -H "Origin: $o" -H "Access-Control-Request-Method: GET" \
    | grep -iE '^(HTTP|access-control-allow-(origin|credentials))'
done
```

**Frontend.**

- [ ] Copy `frontend/.next` to `frontend/.next.pre-<commit>`
- [ ] `bash deploy/scripts/deploy-frontend.sh` as `deploy`. It exits 1 at the `sudo` restart after a successful build (item 4)
- [ ] As an administrator, `systemctl restart famboook-frontend`. Expect a few seconds of downtime
- [ ] Confirm the route matrix:

```bash
curl -sI https://famboook.com/ | grep -iE '^(HTTP|x-robots|location)'          # 200, noindex
curl -sI https://famboook.com/welcome | grep -iE '^(HTTP|location)'            # 308, /
curl -sI https://app.famboook.com/ | grep -iE '^(HTTP|location)'               # 307, /family
curl -sI https://app.famboook.com/family | grep -iE '^(HTTP|location)'         # 200, none
curl -sI https://famboook.com/verify/not-a-real-token | grep -iE '^(HTTP|x-robots|referrer|cache-control)'
curl -s -o /dev/null -w '%{http_code}\n' https://al-breem.com/                 # 200
```

## 4. Stop gate

Continue to SEO activation only after every item in §5 passes.

## 5. Authentication and PWA acceptance

Use real synthetic accounts against real Laravel, in a fresh private window with the console
open. Do not record passwords, tokens or personal data.

**Public and routing**
- [ ] `famboook.com/` shows the landing page while signed out
- [ ] `app.famboook.com/` opens the Family Portal, or the Family login when signed out
- [ ] `/welcome` lands on `/` on the apex

**Staff, on both `famboook.com` and `app.famboook.com`**
- [ ] `/dashboard` signed out goes to `/login?next=%2Fdashboard`, with no dashboard flash
- [ ] Login without `next` reaches `/dashboard`
- [ ] A signed-out deep link to a synthetic Family returns there after login
- [ ] `/login?next=/\evil.example` and `?next=//evil.example` reach `/dashboard`
- [ ] A wrong password shows the normal error, with no `419` or CORS error
- [ ] Reload and a new tab keep the session
- [ ] The sidebar logo and «لوحة العمليات» reach `/dashboard`, and the item highlights
- [ ] An Administrator sees the dashboard; a role without the permission sees the notice
- [ ] A create or edit on synthetic data succeeds, confirming CSRF
- [ ] Logout ends the session
- [ ] Record whether a login on one host also signs in the other, because the cookie is shared

**Filament**
- [ ] `admin.famboook.com/admin/login` works, and a form or dialog behaves normally

**Family, on both `app.famboook.com/family` and `famboook.com/family`**
- [ ] Signed out goes to `/family/login`, not the Staff login
- [ ] A household head reaches home, members, notifications and account
- [ ] The card displays and its PDF downloads
- [ ] Reload and a new tab keep the session; logout clears it
- [ ] A Coordinator reaches `/family/coordinator`
- [ ] A Family account cannot open `/dashboard` or see Staff data
- [ ] A Staff account gets the expected refusal at `/family`

**PWA**
- [ ] The manifest loads with `start_url` and `scope` of `/family`
- [ ] The service worker's scope is `/family` only, and none registers on `/` or `/dashboard`
- [ ] An already installed app still opens after deployment
- [ ] A new installation works from `app.famboook.com/family`
- [ ] The offline page appears with the network off

**QR verification**
- [ ] Scanning a synthetic card works, and the token is not recorded
- [ ] Only the restricted public information is shown
- [ ] An invalid token shows the generic message
- [ ] `/verify/*` keeps `no-store`, `noindex, nofollow` and `Referrer-Policy: no-referrer`

## 6. SEO activation (separate approval)

Not part of this release. Use the map in the ADR, §7.

- [ ] §5 has passed, and `/` is confirmed to be the landing page. Never do this first
- [ ] Owner approval is recorded, including Search Console access
- [ ] Back up the vhost to `/etc/nginx/backups/`
- [ ] Add the map, edit the `famboook.com` HTTPS block only, and run `nginx -t`. If it fails, do not reload
- [ ] `systemctl reload nginx`, never restart
- [ ] `/` has no `X-Robots-Tag`
- [ ] `/welcome`, `/login`, `/dashboard`, `/family`, `/verify/x` and `/about` keep `noindex`
- [ ] `app.`, `api.` and `admin.` keep `noindex`
- [ ] `al-breem.com` is unchanged
- [ ] Search Console URL Inspection reports `/` as indexable

## 7. Rollback

Apply in this order, and only the parts that were deployed.

**SEO, always first:**
```bash
cp -a /etc/nginx/backups/<backup> /etc/nginx/sites-available/famboook.com
rm -f /etc/nginx/conf.d/famboook-robots.conf
nginx -t && systemctl reload nginx
```

Confirm `/` is `noindex` again before going further. Otherwise a rolled-back `/` would expose
the Staff shell without any `noindex`.

**Frontend:**

Restore the saved build (`frontend/.next.pre-<commit>`) with the matching frontend source, then
run `systemctl restart famboook-frontend`. `/` is then the Staff dashboard again, `/dashboard`
returns 404, and `app.famboook.com/` no longer redirects.

**Backend CORS: keep it.** Do not restore the old `cors.php`, or `app.famboook.com` loses API
access. If the new code itself fails, restore the saved Production edit, run
`php artisan config:cache`, and reload PHP-FPM.

Nothing in this release changes the database, so no data rollback applies. Search engines
may take time to drop a homepage already indexed.
