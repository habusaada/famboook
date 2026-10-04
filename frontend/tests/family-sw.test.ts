import { readFileSync } from "node:fs";
import { join } from "node:path";
import vm from "node:vm";
import { describe, expect, it, vi } from "vitest";
import { FAMILY_SW_SCOPE, FAMILY_SW_URL, registerFamilyServiceWorker } from "@/lib/pwa/family-service-worker";

// The real public/family-sw.js, run in a sandbox with a recording fake of
// Cache Storage: what it precaches, which requests it handles, and — above
// all — that nothing but the static offline page ever enters the cache.

const ORIGIN = "https://famboook.com";
const SOURCE = readFileSync(join(__dirname, "..", "public", "family-sw.js"), "utf8");

type Listener = (event: { waitUntil?: (p: Promise<unknown>) => void; respondWith?: (p: Promise<Response | undefined>) => void; request?: Request }) => void;

function worker(network: (request: Request) => Promise<Response>) {
  const listeners: Record<string, Listener> = {};
  const stores = new Map<string, Map<string, Response>>();
  const writes: string[] = [];
  const cacheFor = (name: string) => {
    if (!stores.has(name)) stores.set(name, new Map());
    const store = stores.get(name)!;
    return {
      add: async (request: Request) => {
        writes.push(`${name} ${new URL(request.url, ORIGIN).pathname}`);
        store.set(new URL(request.url, ORIGIN).pathname, await network(request));
      },
      put: async (request: Request | string) => {
        writes.push(`${name} ${String(request)}`);
      },
      match: async (url: string) => store.get(new URL(url, ORIGIN).pathname)?.clone(),
    };
  };
  const self = {
    location: new URL(`${ORIGIN}/family-sw.js`),
    addEventListener: (type: string, fn: Listener) => (listeners[type] = fn),
    skipWaiting: vi.fn(async () => undefined),
    clients: { claim: vi.fn(async () => undefined) },
  };
  const caches = {
    open: async (name: string) => cacheFor(name),
    keys: async () => [...stores.keys()],
    delete: vi.fn(async (name: string) => stores.delete(name)),
  };
  const fetchSpy = vi.fn((request: Request) => network(request));
  vm.runInNewContext(SOURCE, {
    self,
    caches,
    fetch: fetchSpy,
    Request: class extends Request {
      constructor(input: string, init?: RequestInit) {
        super(new URL(input, ORIGIN), init);
      }
    },
    URL,
    Promise,
  });

  async function lifecycle(type: "install" | "activate") {
    let done: Promise<unknown> = Promise.resolve();
    listeners[type]({ waitUntil: (p) => (done = p) });
    await done;
  }

  /** The response the worker gives, or "untouched" when it lets the browser handle it. */
  async function request(url: string, init: RequestInit & { mode?: RequestMode } = {}) {
    // A Request cannot be constructed with mode "navigate": set it afterwards.
    const { mode, ...rest } = init;
    const request = new Request(url, rest);
    if (mode) Object.defineProperty(request, "mode", { value: mode });
    const answers: Promise<Response | undefined>[] = [];
    listeners.fetch({ request, respondWith: (p) => answers.push(p) });

    return answers.length === 0 ? "untouched" : await answers[0];
  }

  return { self, caches, stores, writes, fetchSpy, lifecycle, request };
}

const page = (body: string, status = 200) => new Response(body, { status, headers: { "Content-Type": "text/html" } });
const offline = () => page("<h1>لا يوجد اتصال بالإنترنت</h1>");
const network = async (request: Request) => (new URL(request.url).pathname === "/family/offline.html" ? offline() : page("private family page"));

describe("installation and activation", () => {
  it("precaches only the static offline page, then takes over at once", async () => {
    const sw = worker(network);

    await sw.lifecycle("install");

    expect(sw.writes).toEqual(["famboook-family-v1 /family/offline.html"]);
    expect(sw.self.skipWaiting).toHaveBeenCalled();
  });

  it("removes only its own obsolete versions, then claims its pages", async () => {
    const sw = worker(network);
    sw.stores.set("famboook-family-v0", new Map());
    sw.stores.set("some-other-app", new Map());
    await sw.lifecycle("install");

    await sw.lifecycle("activate");

    expect([...sw.stores.keys()].sort()).toEqual(["famboook-family-v1", "some-other-app"]);
    expect(sw.self.clients.claim).toHaveBeenCalled();
  });
});

describe("Family Portal navigations", () => {
  it.each(["/family", "/family/login", "/family/activate", "/family/coordinator/families/F-1"])(
    "loads %s from the network and stores nothing",
    async (path) => {
      const sw = worker(network);
      await sw.lifecycle("install");

      const response = await sw.request(`${ORIGIN}${path}`, { mode: "navigate" });

      expect(response).not.toBe("untouched");
      expect(await (response as Response).text()).toBe("private family page");
      expect(sw.writes).toEqual(["famboook-family-v1 /family/offline.html"]);
    },
  );

  it("shows the static offline page — never an earlier private page — when the network fails", async () => {
    let online = true;
    const sw = worker(async (request) => {
      if (!online) throw new TypeError("Failed to fetch");
      return network(request);
    });
    await sw.lifecycle("install");
    await sw.request(`${ORIGIN}/family`, { mode: "navigate" });

    online = false;
    const response = (await sw.request(`${ORIGIN}/family`, { mode: "navigate" })) as Response;

    const body = await response.text();
    expect(body).toContain("لا يوجد اتصال بالإنترنت");
    expect(body).not.toContain("private family page");
  });

  it("passes a server error page through instead of pretending to be offline", async () => {
    const sw = worker(async (request) => (new URL(request.url).pathname === "/family/offline.html" ? offline() : page("server error", 500)));
    await sw.lifecycle("install");

    const response = (await sw.request(`${ORIGIN}/family/login`, { mode: "navigate" })) as Response;

    expect(response.status).toBe(500);
    expect(await response.text()).toBe("server error");
  });
});

describe("everything else is never touched or cached", () => {
  it.each([
    ["the Family Auth API", "https://api.famboook.com/api/v1/family/auth/login", { method: "POST", mode: "cors" as RequestMode }],
    ["family data", "https://api.famboook.com/api/v1/family/me", { mode: "cors" as RequestMode }],
    ["the household summary", "https://api.famboook.com/api/v1/family/household", { mode: "cors" as RequestMode }],
    ["the household members", "https://api.famboook.com/api/v1/family/household/members", { mode: "cors" as RequestMode }],
    ["coordinator data", "https://api.famboook.com/api/v1/family/coordinator/families", { mode: "cors" as RequestMode }],
    ["the CSRF cookie", "https://api.famboook.com/sanctum/csrf-cookie", { mode: "cors" as RequestMode }],
    ["a same-origin fetch under /family", `${ORIGIN}/family/login`, { mode: "cors" as RequestMode }],
    ["a same-origin fetch of the members page data", `${ORIGIN}/family/members`, { mode: "cors" as RequestMode }],
    ["a script or style", `${ORIGIN}/_next/static/chunks/app.js`, { mode: "no-cors" as RequestMode }],
    ["an icon", `${ORIGIN}/icons/icon-192.png`, { mode: "no-cors" as RequestMode }],
    ["a Staff page", `${ORIGIN}/families`, { mode: "navigate" as RequestMode }],
    ["the Staff login", `${ORIGIN}/login`, { mode: "navigate" as RequestMode }],
    ["the Staff home", `${ORIGIN}/`, { mode: "navigate" as RequestMode }],
    ["a look-alike path", `${ORIGIN}/familyx`, { mode: "navigate" as RequestMode }],
    ["a form post navigation", `${ORIGIN}/family/login`, { method: "POST", mode: "navigate" as RequestMode }],
  ])("%s", async (_label, url, init) => {
    const sw = worker(network);
    await sw.lifecycle("install");

    expect(await sw.request(url, init)).toBe("untouched");
    expect(sw.fetchSpy).not.toHaveBeenCalled(); // the worker made no request of its own
    expect(sw.writes).toEqual(["famboook-family-v1 /family/offline.html"]);
  });

  it("never calls cache.put — the only cache write is the install precache", () => {
    expect(SOURCE).not.toMatch(/\.put\(/);
    expect(SOURCE.match(/\.add\(/g)).toHaveLength(1);
  });
});

describe("registration", () => {
  it("registers the worker with the Family scope", async () => {
    const register = vi.fn(async () => ({}) as ServiceWorkerRegistration);

    expect(await registerFamilyServiceWorker({ register })).toBe(true);
    expect(register).toHaveBeenCalledWith("/family-sw.js", { scope: "/family" });
    expect([FAMILY_SW_URL, FAMILY_SW_SCOPE]).toEqual(["/family-sw.js", "/family"]);
  });

  it("does nothing without service worker support and never throws", async () => {
    expect(await registerFamilyServiceWorker(undefined)).toBe(false);
    expect(await registerFamilyServiceWorker({ register: vi.fn(async () => Promise.reject(new Error("blocked"))) })).toBe(false);
  });

  it("covers the Family start page and leaves every Staff path out of scope", () => {
    // A worker controls URLs whose path starts with its scope.
    const inScope = (path: string) => path.startsWith(FAMILY_SW_SCOPE);
    expect(["/family", "/family/login", "/family/activate"].every(inScope)).toBe(true);
    expect(["/", "/login", "/families", "/families/1", "/people", "/assistances", "/administration/imports"].some(inScope)).toBe(false);
  });

  it("is registered from the Family Portal layout only", () => {
    const read = (file: string) => readFileSync(join(__dirname, "..", file), "utf8");
    expect(read("app/family/layout.tsx")).toContain("<FamilyServiceWorker />");
    expect(read("app/layout.tsx")).not.toContain("ServiceWorker");
    expect(read("app/(staff)/layout.tsx")).not.toContain("ServiceWorker");
  });
});
