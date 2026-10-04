/**
 * The Family Portal service worker (public/family-sw.js) and its scope.
 *
 * The scope is "/family" — without a trailing slash — so that the portal's
 * own start page, /family, is controlled too ("/family/" would leave out
 * exactly the page the manifest starts at). The worker itself handles only
 * /family and /family/... navigations, so Staff pages such as /families,
 * /login or / are never touched.
 */
export const FAMILY_SW_URL = "/family-sw.js";
export const FAMILY_SW_SCOPE = "/family";

type Registrar = Pick<ServiceWorkerContainer, "register">;

/** Registers the worker where the browser supports it; never throws. */
export async function registerFamilyServiceWorker(container: Registrar | undefined = typeof navigator === "undefined" ? undefined : navigator.serviceWorker): Promise<boolean> {
  if (!container) return false;
  try {
    await container.register(FAMILY_SW_URL, { scope: FAMILY_SW_SCOPE });

    return true;
  } catch {
    // Not installable this time; the portal works the same without it.
    return false;
  }
}
