# FB-WEB-02 — Publication Plan for `/welcome`

**Status:** EXECUTED on 2026-10-30 and accepted by the owner. §8 records the outcome and where execution differed from this plan. The sections before it are kept as written.

**Readers:** the release owner and the operator with server access. Follow
`docs/08-PILOT-DEPLOYMENT.md` for anything this plan does not restate.

**Scope:** publish the Landing Page V2.1 at `https://famboook.com/welcome`, still
`noindex`. Nothing else changes: `/` stays the Staff dashboard, and `/login`,
`/family` and `/family/login` stay as they are.

The landing commit changes frontend code and documentation only. It has no
migration, no backend change and no environment change.

## 1. Target and prerequisites

Production deploys an approved commit by SHA on a detached HEAD, as the `deploy`
user. `<sha>` below is the landing commit on `feature/fb-geo-02`.

Complete these before the release window:

1. **Approve and push.** The commit is local. Pushing the branch needs its own approval.
2. **Compare Production's commit with `<sha>`.** Run
   `sudo -u deploy git -C <app-root> rev-parse HEAD`, then locally
   `git log --oneline <production-sha>..<sha>`. This must be resolved first:
   - If the list shows **only the landing commit**, continue with §3.
   - If the list also shows **FB-CIVIL-02G** (`f4ddc1f`) or anything else, stop.
     Checking out `<sha>` would also place that backend code on the server, and
     02G has a migration (`2026_10_31_100000`) that a frontend-only deployment
     does not run. Release those commits first through their own approved runbook,
     or get a separate decision.
3. **Confirm the settings still hold.** The owner confirmed
   `notifications.mode = ON` and `credentials.family_card_issuance_enabled = true`.
   These show that the features are enabled, not that they work end to end. Re-read
   them if the window is more than a few days away.
4. **Confirm free disk and memory** for `npm run build` (docs/08 §16b).

## 2. Build checks before deployment

Run these on `<sha>` from `frontend/`. All must pass:

```text
npx tsc --noEmit
npx eslint .
npx vitest run tests/landing-page.test.tsx tests/family-card.test.tsx tests/family-card-panel.test.tsx tests/credential-verification.test.tsx
npm run build
```

The build output must list `/welcome` as static.

## 3. Deployment (frontend only)

```text
# Record the rollback target first
sudo -u deploy git -C <app-root> rev-parse HEAD

sudo -u deploy git -C <app-root> fetch origin
sudo -u deploy git -C <app-root> checkout --detach <sha>
sudo -u deploy git -C <app-root> status --short      # must be empty
sudo -u deploy git -C <app-root> rev-parse HEAD      # must equal <sha>

sudo -u deploy <app-root>/deploy/scripts/deploy-frontend.sh
```

`deploy-frontend.sh` runs `npm ci` and `npm run build`, then restarts
`famboook-frontend`. Sessions survive the restart, but expect a short interruption
of the Staff Portal and Family Portal, so choose a quiet window.

Do not run `deploy-backend.sh`, change `.env`, or reload PHP-FPM or nginx for this
release. If §1 step 2 passed, none of them is needed.

## 4. Preserving routes and `noindex`

| Item | Expected | Guaranteed by |
|---|---|---|
| `/` | Staff dashboard, behind sign-in | `app/(staff)/page.tsx` is unchanged |
| `/login` | Staff sign-in | Unchanged |
| `/family`, `/family/login` | Family Portal, with its manifest and service worker | Unchanged; `/welcome` sits outside `family/` |
| `/welcome` | Public, no session | `app/(public)/welcome/page.tsx` |
| Indexing | `noindex, nofollow` | `robots` metadata on the page |

Removing `noindex` is a separate decision. Do not add `/welcome` to a sitemap.

## 5. Smoke tests after deployment

All of these are read-only. Use no real personal data.

```text
curl -sI https://famboook.com/welcome                       # 200
curl -s https://famboook.com/welcome | grep -o 'name="robots" content="[^"]*"'
                                                            # noindex, nofollow
curl -s https://api.famboook.com/api/v1/health              # {"status":"ok"}
```

In a private browser window, at desktop and phone widths:

1. `/welcome` shows the approved headline and the PCDCD operator line, with no
   console errors and no blocked resources.
2. All four preview tabs open. The browser's network panel shows no call to
   `api.famboook.com` from the page.
3. The card section shows «بطاقة الأسرة الرقمية» and the label «نموذج توضيحي — بيانات
   غير حقيقية», and its QR area is the placeholder.
4. Every header and footer anchor scrolls to its section. The mobile menu opens and
   closes.
5. The landing links reach `/login` and `/family/login`.
6. `/` with no session goes to Staff sign-in, as it did before.
7. An authorized Staff user and an authorized household head can still sign in.
8. `/family` still registers its service worker, and `/welcome` registers none.
9. `storage/logs/laravel.log` and the frontend service log show no new errors.

### Outstanding operational verification (not covered by this release)

An authorized household head performs these, because they need a real session:

- Open `/family/card` and confirm the card and QR display.
- Download the PDF and confirm it opens. **PDF has not been verified end to end in
  Production.** If this fails, remove the PDF claim from the page or fix the service.
- Scan that card's QR and confirm that the public page shows only the card number,
  family code, issue date, clan, branch and the head's short name.
- Have an authorized sender publish a manual announcement to an approved audience,
  and confirm that it appears in the Family Portal. The confirmed `ON` setting does
  not prove delivery.

Do not record real tokens, national IDs or names in the release evidence.

## 6. Rollback

The release has no data or schema change, so rollback is code only:

```text
sudo -u deploy git -C <app-root> checkout --detach <previous-sha>
sudo -u deploy git -C <app-root> status --short
sudo -u deploy <app-root>/deploy/scripts/deploy-frontend.sh
```

Then confirm that `/welcome` returns 404 and that `/login` and `/family/login` load.

Do not restore a backup or run `migrate:rollback` for this release. If only a
wording problem appears, fixing forward with a new approved commit is also acceptable.

## 7. Separate future decisions

> **Note (2026-10-30, FB-WEB-04):** the root of `famboook.com` and the role of
> `app.famboook.com` are now decided in `docs/adr/FB-WEB-04-PUBLIC-HOMEPAGE.md`. Removing
> `noindex` stays a separate approval. The list below is kept as written.

These are out of scope here, and nothing in this plan pre-empts them:

- **`famboook.com` and `app.famboook.com`.** Whether the landing page becomes the
  root of `famboook.com` and the applications move to `app.famboook.com`. No document
  describes `app.famboook.com` today. Such a move would affect `FRONTEND_URL`,
  `SESSION_DOMAIN`, `SANCTUM_STATEFUL_DOMAINS`, CORS, TLS certificates, the printed QR
  verification URLs and the installed Family app, plus `navItems`, `safeNext`, the
  sidebar logo and `AuthGate`.
- **Removing `noindex`** and adding the page to search engines.
- **Updating docs/08 §1**, which still describes `famboook.com` as the Staff Portal only.

## 8. Execution record (FB-WEB-02G, FB-WEB-02H)

| Item | Value |
|---|---|
| Production URL | `https://famboook.com/welcome` |
| Commit before | `f4ddc1f4b2c2b8cd72d515f2de66340591b6305f` |
| Commit after | `be44d923cccdc9e1d7cc3f3fceaaa7f7937da016` |
| Deployment window | 2026-10-30, 15:41:29 to 15:45:52 CET |
| Rollback | Not needed |
| Acceptance | Manual, by the project owner, after visiting the portals and confirming that they work |
| SEO | `noindex, nofollow`; removing it is not approved |

Production was confirmed to match `origin/feature/fb-geo-02` after a successful
`git fetch` as the `deploy` user.

Differences from §3:

- `deploy-frontend.sh` is not executable, so it ran through `bash`.
- The script's `sudo systemctl restart` needs a password that `deploy` could not
  supply, so it exited with code 1 after a successful build. The same restart was
  then run as root.
- The previous build was copied to `frontend/.next.pre-f4ddc1f` before the build.
- The service listens on port 3001, not the 3000 named in the script's comment.

These are carried by FB-OPS-01, a separate task that has not started.

Still without recorded evidence (§5, "Outstanding operational verification"): QR
verification, PDF download and announcement delivery in Production.
