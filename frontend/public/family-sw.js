/*
 * Famboook Family Portal service worker (docs/11, FP-ADR-055).
 *
 * Deliberately minimal. It caches ONE thing — the static offline page,
 * precached at install — and nothing else, ever:
 *
 *   - only NAVIGATION requests to /family or /family/... are handled, network
 *     first; a successful response goes straight to the page and is never
 *     stored, so no private family page can be shown again offline;
 *   - when the network genuinely fails, the static Arabic offline page is
 *     returned instead;
 *   - every other request — the API (api.famboook.com), Family Auth, the CSRF
 *     cookie, scripts, styles, images, other origins, Staff pages — is not
 *     touched at all: the browser handles it as if there were no worker.
 *
 * Registered from the Family Portal only, with scope /family.
 */
const CACHE = "famboook-family-v1";
const CACHE_PREFIX = "famboook-family-";
const OFFLINE_URL = "/family/offline.html";

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches
      .open(CACHE)
      .then((cache) => cache.add(new Request(OFFLINE_URL, { cache: "reload" })))
      .then(() => self.skipWaiting()),
  );
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches
      .keys()
      // Only our own obsolete versions; nothing else in Cache Storage.
      .then((names) => Promise.all(names.filter((name) => name.startsWith(CACHE_PREFIX) && name !== CACHE).map((name) => caches.delete(name))))
      .then(() => self.clients.claim()),
  );
});

/** A top-level page load of the Family Portal itself. */
function isFamilyNavigation(request) {
  if (request.mode !== "navigate" || request.method !== "GET") return false;
  const url = new URL(request.url);

  return url.origin === self.location.origin && (url.pathname === "/family" || url.pathname.startsWith("/family/"));
}

self.addEventListener("fetch", (event) => {
  if (!isFamilyNavigation(event.request)) return;

  event.respondWith(
    // Network first, never stored. Only a network failure (fetch rejects)
    // falls back; an HTTP error page from the server is shown as is.
    fetch(event.request).catch(() => caches.open(CACHE).then((cache) => cache.match(OFFLINE_URL))),
  );
});
