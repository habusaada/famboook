import { existsSync, readFileSync } from "node:fs";
import { join } from "node:path";
import { render, screen, waitFor } from "@testing-library/react";
import { PHASE_DEVELOPMENT_SERVER } from "next/constants";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { AuthGate } from "@/components/auth/auth-gate";
import { useMeQuery } from "@/lib/api/auth";
import { isPublicRoute, navItemFor, navItems, safeNext } from "@/lib/navigation";
import { metadata as staffMetadata } from "@/app/(staff)/layout";
import { metadata as familyMetadata } from "@/app/family/layout";
import { metadata as homeMetadata } from "@/app/(public)/page";
import config from "@/next.config";

// FB-WEB-04: `/` is the public homepage on famboook.com, app.famboook.com opens on
// /family, and the Staff dashboard is /dashboard.

const nav = vi.hoisted(() => ({ pathname: "/dashboard", search: "", replace: vi.fn() }));

vi.mock("next/navigation", () => ({
  usePathname: () => nav.pathname,
  useSearchParams: () => new URLSearchParams(nav.search),
  useRouter: () => ({ replace: nav.replace, push: vi.fn() }),
}));
vi.mock("@/lib/api/auth", () => ({ useMeQuery: vi.fn() }));
vi.mock("@/components/layout/app-shell", () => ({ AppShell: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));

const redirects = async () => (await config(PHASE_DEVELOPMENT_SERVER).redirects?.()) ?? [];
const me = (data: unknown) => vi.mocked(useMeQuery).mockReturnValue({ data, isSuccess: true, isError: false } as never);
const staff = (permissions: string[]) => ({ id: 1, name: "Demo", email: "demo@example.test", roles: ["ADMINISTRATOR"], permissions });

beforeEach(() => {
  nav.pathname = "/dashboard";
  nav.search = "";
  nav.replace.mockClear();
});

describe("route files", () => {
  it("has one homepage and no competing landing or dashboard page", () => {
    expect(existsSync(join(process.cwd(), "app/(public)/page.tsx"))).toBe(true);
    expect(existsSync(join(process.cwd(), "app/(staff)/dashboard/page.tsx"))).toBe(true);
    expect(existsSync(join(process.cwd(), "app/(staff)/page.tsx"))).toBe(false);
    expect(existsSync(join(process.cwd(), "app/(public)/welcome/page.tsx"))).toBe(false);
  });

  it("redirects /welcome to the homepage permanently, and only the app host's root to /family", async () => {
    await expect(redirects()).resolves.toEqual([
      { source: "/", has: [{ type: "host", value: "app\\.famboook\\.com" }], destination: "/family", permanent: false },
      { source: "/welcome", destination: "/", permanent: true },
    ]);
  });

  it("matches the exact app host only (Next.js anchors the value)", async () => {
    const [root] = await redirects();
    const host = new RegExp(`^${root.has?.[0].value}$`);
    expect(host.test("app.famboook.com")).toBe(true);
    for (const other of ["famboook.com", "evil-app.famboook.com", "app.famboook.com.evil.example", "appxfamboook.com", "app.famboookxcom", "www.app.famboook.com"]) {
      expect(host.test(other), other).toBe(false);
    }
  });

  it("has no redirect from /family, /dashboard, /login or /verify, so nothing can loop back", async () => {
    const rules = await redirects();
    expect(rules.map((rule) => rule.source)).toEqual(["/", "/welcome"]);
    expect(rules.find((rule) => rule.source === "/")?.destination).toBe("/family");
  });
});

describe("the installable Family app", () => {
  it("keeps its start URL and scope", () => {
    const manifest = JSON.parse(readFileSync(join(process.cwd(), "public/manifest.webmanifest"), "utf8"));
    expect(manifest.start_url).toBe("/family");
    expect(manifest.scope).toBe("/family");
  });

  it("is not linked from the homepage", () => {
    expect(homeMetadata.manifest).toBeUndefined();
  });
});

describe("indexing metadata", () => {
  it("opts the Staff and Family groups out; only the homepage has no robots rule", () => {
    expect(staffMetadata.robots).toEqual({ index: false, follow: false });
    expect(familyMetadata.robots).toEqual({ index: false, follow: false });
    expect(homeMetadata.robots).toBeUndefined();
  });
});

describe("safeNext", () => {
  it("defaults to the dashboard", () => {
    expect(safeNext(null)).toBe("/dashboard");
    expect(safeNext("")).toBe("/dashboard");
  });

  it.each(["https://evil.example", "http://evil.example/x", "//evil.example", "evil.example", "javascript:alert(1)", "/login", "/login/x", "/dev-login"])("rejects %s", (value) => {
    expect(safeNext(value)).toBe("/dashboard");
  });

  it.each([
    "/\\evil.example",
    "\\\\evil.example",
    "/\\/evil.example",
    "//\\evil.example",
    "/families\\..\\login",
    "/\t/evil.example",
    "/\n/evil.example",
    "/\r/evil.example",
    " /evil.example",
    "/ /evil.example",
    "/\u0000/evil.example",
    "/ /evil.example",
    "/.//evil.example",
    "/a/..//evil.example",
    "/%2e//evil.example",
    "/%2F%2Fevil.example",
    "/%2f/evil.example",
    "/%5Cevil.example",
    "/%5c%5cevil.example",
    "/login?next=/families",
    "/login#x",
    "/families/../login",
    "/%2e%2e/login",
    "/dev-login?x=1",
    "http:/evil.example",
    "https:evil.example",
    "data:text/html,x",
  ])("rejects the suspicious destination %j", (value) => {
    expect(safeNext(value)).toBe("/dashboard");
  });

  it.each([
    ["/families/../people", "/people"],
    ["/./people", "/people"],
  ])("returns the resolved form of %s", (value, resolved) => {
    expect(safeNext(value)).toBe(resolved);
  });

  it.each(["/", "/loginfoo", "/families/1?return=%2Ffamilies%2F2", "/people?q=%5C", "/reports?a=1&b=2#top", "/people?q=%D8%A3%D8%AD%D9%85%D8%AF"])("keeps the valid destination %s", (value) => {
    expect(safeNext(value)).toBe(value);
  });

  it.each(["/dashboard", "/families", "/families/1?tab=members"])("keeps %s", (value) => {
    expect(safeNext(value)).toBe(value);
  });
});

describe("navigation", () => {
  it("keeps the dashboard item's label, permission and group at its new address", () => {
    expect(navItems[0]).toMatchObject({ href: "/dashboard", label: "لوحة العمليات", permissions: ["dashboard.view-operational"], group: "operations" });
    expect(navItemFor("/dashboard")).toBe(navItems[0]);
    expect(navItemFor("/dashboard/anything")).toBe(navItems[0]);
  });

  it("does not treat the homepage as a Staff section or a Staff public route", () => {
    expect(navItemFor("/")).toBeUndefined();
    expect(isPublicRoute("/")).toBe(false);
  });
});

describe("AuthGate on /dashboard", () => {
  it("sends a guest to the login with the return path, and shows nothing", async () => {
    me(null);
    render(<AuthGate><p>secret dashboard</p></AuthGate>);
    await waitFor(() => expect(nav.replace).toHaveBeenCalledWith("/login?next=%2Fdashboard"));
    expect(screen.queryByText("secret dashboard")).not.toBeInTheDocument();
  });

  it("shows the dashboard with the permission", () => {
    me(staff(["dashboard.view-operational"]));
    render(<AuthGate><p>secret dashboard</p></AuthGate>);
    expect(screen.getByText("secret dashboard")).toBeInTheDocument();
    expect(nav.replace).not.toHaveBeenCalled();
  });

  it("keeps the permission-denied notice without the permission", () => {
    me(staff(["family.view"]));
    render(<AuthGate><p>secret dashboard</p></AuthGate>);
    expect(screen.queryByText("secret dashboard")).not.toBeInTheDocument();
    expect(screen.getByText("لا تملك صلاحية الوصول إلى هذا القسم.")).toBeInTheDocument();
  });
});
