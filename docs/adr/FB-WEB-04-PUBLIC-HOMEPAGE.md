# FB-WEB-04 — Public Homepage & Staff Dashboard Separation

**Status:** approved by the project owner; committed locally, NOT
deployed (2026-10-30). Deployment needs separate approval. See
`docs/deployment/FB-WEB-04-PRODUCTION-READINESS.md`.

**Identifier:** `FB-WEB-03` stays with the deferred «فامبوك بالأرقام» feature. This work
was first drafted under that number by mistake and was renumbered before any commit.

**Supersedes:** FB-WEB-02 ADR §1 ("`/` stays the Staff dashboard") and the open question
in its §6, and the `/welcome` address in `docs/deployment/FB-WEB-02-PUBLICATION-PLAN.md`.
Those documents keep their original text with a dated note.

## 1. Background and objectives

`/` rendered the Staff dashboard, and guests were sent to `/login`. The public landing
page lived at `/welcome`.

The objectives are that visitors to `famboook.com` see the landing page, Staff keep the
dashboard behind the same protection at a new address, and `app.famboook.com` opens on
the Family Portal.

## 2. Hostname architecture

`app.famboook.com` is an official Production application hostname, not Staging.

| Host | Purpose | Served by |
|---|---|---|
| `famboook.com` | Public homepage; Staff, Family and `/verify` routes also remain here | Next.js on `127.0.0.1:3001` |
| `www.famboook.com` | 301 to `famboook.com` | Nginx |
| `app.famboook.com` | Application host; `/` opens the Family Portal | The same Next.js process |
| `api.famboook.com` | Laravel API | PHP-FPM |
| `admin.famboook.com` | Filament | PHP-FPM |

Both Next.js hosts share one process, and Nginx passes `Host`, so the host-specific rule
is in `frontend/next.config.ts`. It ships with the build, is tested, and needs no reload
of the Nginx instance shared with another site. The hosting facts above come from a
read-only inspection on 2026-10-30 and must be re-confirmed before deployment.

Printed QR codes keep using `https://famboook.com/verify/*`
(`CREDENTIAL_VERIFY_BASE_URL`), so `/verify` stays on the apex.

## 3. Route mapping

| Route | `famboook.com` | `app.famboook.com` | Access |
|---|---|---|---|
| `/` | Landing page, 200 | 307 to `/family` (query kept) | Public |
| `/welcome` | 308 to `/` | 308 to `/`, then 307 to `/family` | Public |
| `/dashboard` | Operational dashboard | Same | Staff, `dashboard.view-operational` |
| `/login` | Staff login | Same | Public |
| `/family`, `/family/*` | Family Portal | Same | Family authentication |
| `/verify/*` | Public verification | Same | Public; restricted data |
| `/about`, `/contact`, `/privacy`, `/terms`, `/data-protection` | Unchanged | Unchanged | Public |

The host value is the escaped regular expression `app\.famboook\.com`, which Next.js
anchors, so lookalike hosts do not match. No rule has `/family` as its source, so the
redirect cannot loop. The redirect is temporary so that the entry point can change later.

## 4. Staff authentication

- `AuthGate`, Sanctum, sessions, CSRF and permission definitions are unchanged.
- A guest at `/dashboard` goes to `/login?next=%2Fdashboard`. A login without `next` goes
  to `/dashboard`. A user without the permission sees the existing notice.
- `safeNext` is stricter. It refuses backslashes, whitespace and control characters, and
  an encoded slash or backslash in the path. It judges and returns the form a browser
  resolves, which also closes `/.//host` and `/login?…`. Valid internal targets still work.
- The sidebar logo and the navigation item point to `/dashboard`.

## 5. Family Portal and PWA

The manifest, `start_url` and `scope` (`/family`), the service worker, Family
authentication and Family cookies are unchanged. The only edit under `app/family` is the
`noindex` metadata in its layout. The homepage links no manifest and registers no worker.

## 6. CORS

`backend/config/cors.php` reads `CORS_ALLOWED_ORIGINS`, a comma-separated list of exact
origins, and falls back to `FRONTEND_URL` when it is unset or empty. Entries are trimmed,
and empty entries and `*` are dropped, because this API allows credentials. Paths, methods,
headers and `supports_credentials` are unchanged.

Required in Production:

```env
CORS_ALLOWED_ORIGINS=https://famboook.com,https://app.famboook.com
SANCTUM_STATEFUL_DOMAINS=famboook.com,app.famboook.com
```

The 2026-10-30 inspection also found `www.famboook.com` in the live
`SANCTUM_STATEFUL_DOMAINS`. It is harmless because that host only redirects.

This code is functionally compatible with the change inspected on Production for a clean
list of origins. It is not byte-identical, and it differs by dropping `*` and empty entries
and by falling back when the variable is empty.

## 7. SEO indexing policy

**Current policy, unchanged by this work:** Nginx sends `X-Robots-Tag: noindex, nofollow`
on every Famboook host, so the homepage stays out of search engines after deployment.

The application is prepared as follows:

- The homepage has its title, description, canonical URL and Open Graph metadata, and no
  `robots` rule of its own. There is no `og:image`, because none is approved.
- The Staff and Family layouts, `/verify` and the legal pages carry `noindex` metadata.

**Proposed future change, not approved or applied:** allow the exact path `/` on
`famboook.com` only, with `noindex` as the default:

```nginx
# /etc/nginx/conf.d/famboook-robots.conf
map $uri $famboook_apex_robots {
    default "noindex, nofollow";
    /       "";
}
```

```diff
# famboook.com HTTPS server block only
-    add_header X-Robots-Tag "noindex, nofollow" always;
+    add_header X-Robots-Tag $famboook_apex_robots always;
```

This snippet has not been syntax-tested. `app.`, `api.` and `admin.` are not edited, and
`robots.txt` stays as it is: blocking a URL there would stop crawlers seeing `noindex`.

**Order is a safety rule.** The currently deployed `/` is the Staff shell, which has no
meta `noindex`. Nginx must change only after the new frontend is live and verified. In a
rollback, Nginx is restored first.

## 8. Deployment prerequisites

Listed in full in `docs/deployment/FB-WEB-04-PRODUCTION-READINESS.md`. The main ones are
resolving the uncommitted `backend/config/cors.php` on Production, confirming the
environment values above, and reviewing the untracked files on Production.

## 9. Acceptance criteria

- `famboook.com/` shows the landing page to any visitor.
- `app.famboook.com/` answers 307 to `/family`, and `/family` does not redirect back.
- `/welcome` answers 308 to `/`.
- `/dashboard` is protected, and a login without `next` reaches it.
- Real Staff and Family logins work on both hosts. Browser API stubs do not count.
- The PWA keeps its scope, and an already installed app still opens.
- `/verify/*` keeps `no-store`, `noindex` and `no-referrer`.
- All Famboook hosts stay `noindex` until SEO activation is approved separately.

## 10. Rollback

The steps are in the readiness document. In short: restore Nginx first if SEO was
activated, then restore the previous frontend build and restart the service. Keep the CORS
change, because `app.famboook.com` needs it with either frontend. After a rollback,
`/dashboard` no longer exists, and `/` is the Staff dashboard again.
