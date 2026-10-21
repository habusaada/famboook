import { readdirSync, readFileSync, statSync } from "node:fs";
import { join, relative, sep } from "node:path";
import { screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import CoordinatorSpaceLayout from "@/app/family/(portal)/coordinator/(space)/layout";
import { CoordinatorRoleGate } from "@/components/family/coordinator/coordinator-role-gate";
import { FamilyUserContext } from "@/components/family/family-context";
import { FamilyGate } from "@/components/family/family-gate";
import { ApiError, apiClient } from "@/lib/api/client";
import type { FamilyUser } from "@/lib/api/family-auth";
import { familyUser, renderWithClient } from "./helpers";

// FB-NOTIFY-01C.3b: the Coordinator routes are split by guard. Every
// scope-dependent page lives in the coordinator/(space) route group behind
// CoordinatorGate (the CURRENT scope); CoordinatorRoleGate (the COORDINATOR
// role only) is ready for future role-only history pages. Synthetic data only.

const router = { replace: vi.fn(), push: vi.fn() };
const location = { pathname: "/family/coordinator" };
vi.mock("next/navigation", () => ({ useRouter: () => router, usePathname: () => location.pathname }));
vi.mock("next/link", () => ({
  default: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...props}>
      {children}
    </a>
  ),
}));

beforeEach(() => {
  router.replace.mockReset();
  location.pathname = "/family/coordinator";
});

const ROOT = join(process.cwd(), "app", "family", "(portal)", "coordinator");

/** Every page file under coordinator/, relative, with "/" separators. */
function pages(dir = ROOT): string[] {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name);
    if (statSync(path).isDirectory()) return pages(path);
    return name === "page.tsx" ? [relative(ROOT, path).split(sep).join("/")] : [];
  });
}

/** The public URL of a page file: route groups "(x)" add no segment. */
function urlOf(page: string): string {
  const segments = page.split("/").slice(0, -1).filter((s) => !(s.startsWith("(") && s.endsWith(")")));
  return ["/family/coordinator", ...segments].join("/");
}

// The public Coordinator URLs before the refactor (8f47715), unchanged, plus
// the FB-NOTIFY-01C.3d announcement routes and the FB-NOTIFY-01E.3-F3.3
// follow-up routes.
const PUBLIC_URLS = [
  "/family/coordinator",
  "/family/coordinator/announcements",
  "/family/coordinator/announcements/[id]",
  "/family/coordinator/announcements/new",
  "/family/coordinator/families/[code]",
  // FB-NOTIFY-01E.3-F3.3: follow-ups of the CURRENT scope, inside (space).
  "/family/coordinator/followups",
  "/family/coordinator/followups/[id]",
  "/family/coordinator/requests",
  "/family/coordinator/requests/[id]",
  "/family/coordinator/requests/new",
  "/family/coordinator/requests/new/add-family-member",
  "/family/coordinator/requests/new/register-family",
];

const ROLE_ONLY_REVOKED: FamilyUser = familyUser({ roles: ["FAMILY_USER", "COORDINATOR"], coordinator: true, coordinator_space: false });
const ACTIVE_COORDINATOR: FamilyUser = familyUser({ roles: ["FAMILY_USER", "COORDINATOR"], coordinator: true, coordinator_space: true });
const HOUSEHOLD_HEAD: FamilyUser = familyUser();

const CONTEXT = { scopes: [{ type: "BRANCH", code: "BR_A", name: "فرع أ", clan_name: "عشيرة الاختبار" }], family_count: 3 };

/** Routes apiClient.get by exact path; an ApiError reply is thrown; anything else fails the test. */
function api(routes: Record<string, unknown>) {
  return vi.spyOn(apiClient, "get").mockImplementation(async (path: string) => {
    if (!(path in routes)) throw new Error(`Unexpected request: ${path}`);
    const reply = routes[path];
    if (reply instanceof ApiError) throw reply;
    return reply as never;
  });
}

describe("the Coordinator route tree", () => {
  it("keeps every existing public URL exactly", () => {
    expect(pages().map(urlOf).sort()).toEqual([...PUBLIC_URLS].sort());
  });

  it("places every scope-dependent page in the (space) group; only the announcement history is role-only", () => {
    const roleOnly = ["announcements/page.tsx", "announcements/[id]/page.tsx"];
    for (const page of pages()) {
      if (roleOnly.includes(page)) continue;
      expect(page.startsWith("(space)/"), page).toBe(true);
    }
    expect(pages()).toEqual(expect.arrayContaining([...roleOnly, "(space)/announcements/new/page.tsx"]));
    expect(readFileSync(join(ROOT, "(space)", "layout.tsx"), "utf8")).toContain("<CoordinatorGate>");
    // The role-only history: CoordinatorRoleGate, never the current-scope gate.
    const history = readFileSync(join(ROOT, "announcements", "layout.tsx"), "utf8");
    expect(history).toContain("<CoordinatorRoleGate>");
    expect(history).not.toContain("<CoordinatorGate>");
  });

  it("has no layout above the group that could bypass or replace the gate", () => {
    const root = readdirSync(ROOT);
    expect(root).not.toContain("layout.tsx");
    expect(root).not.toContain("page.tsx");
  });
});

describe("the (space) layout — scope-dependent pages", () => {
  function renderSpace() {
    return renderWithClient(
      <CoordinatorSpaceLayout>
        <p data-scope-page>صفحة مساحة التنسيق</p>
      </CoordinatorSpaceLayout>
    );
  }

  it("opens with a current scope, asking only the coordinator context", async () => {
    const get = api({ "/api/v1/family/coordinator/context": { data: CONTEXT } });
    renderSpace();

    expect(await screen.findByText("صفحة مساحة التنسيق")).toBeInTheDocument();
    expect(get.mock.calls.map(([path]) => path)).toEqual(["/api/v1/family/coordinator/context"]);
  });

  it("stays closed for a coordinator whose scope is revoked (the server refuses the context)", async () => {
    api({ "/api/v1/family/coordinator/context": new ApiError(403, { code: "COORDINATOR_SPACE_UNAVAILABLE" }) });
    renderSpace();

    expect(await screen.findByRole("alert")).toHaveTextContent("مساحة التنسيق غير متاحة لهذا الحساب.");
    expect(document.querySelector("[data-scope-page]")).toBeNull();
  });

  it("renders nothing of the page while the context is loading", () => {
    vi.spyOn(apiClient, "get").mockImplementation(() => new Promise<never>(() => {}));
    renderSpace();

    expect(screen.getByLabelText("جارٍ تحميل مساحة التنسيق")).toBeInTheDocument();
    expect(document.querySelector("[data-scope-page]")).toBeNull();
  });
});

describe("CoordinatorRoleGate — future role-only pages", () => {
  function renderRoleGate(user: FamilyUser) {
    const get = vi.spyOn(apiClient, "get");
    renderWithClient(
      <FamilyUserContext.Provider value={user}>
        <CoordinatorRoleGate>
          <p data-role-page>سجل المنسق</p>
        </CoordinatorRoleGate>
      </FamilyUserContext.Provider>
    );
    return get;
  }

  it("lets a coordinator without a current scope through — coordinator_space false is not a lost role", () => {
    const get = renderRoleGate(ROLE_ONLY_REVOKED);
    expect(screen.getByText("سجل المنسق")).toBeInTheDocument();
    expect(get).not.toHaveBeenCalled();
  });

  it("lets an active coordinator through", () => {
    renderRoleGate(ACTIVE_COORDINATOR);
    expect(screen.getByText("سجل المنسق")).toBeInTheDocument();
  });

  it("refuses an account without the COORDINATOR role, and asks the server nothing", () => {
    const get = renderRoleGate(HOUSEHOLD_HEAD);
    expect(screen.getByRole("alert")).toHaveTextContent("هذه الصفحة متاحة لمنسقي العائلات فقط.");
    expect(document.querySelector("[data-role-page]")).toBeNull();
    expect(get).not.toHaveBeenCalled();
  });

  it("stays behind FamilyGate: without a session nothing renders and the Family login opens", async () => {
    vi.spyOn(apiClient, "get").mockRejectedValue(new ApiError(401, null));
    renderWithClient(
      <FamilyGate>
        <CoordinatorRoleGate>
          <p data-role-page>سجل المنسق</p>
        </CoordinatorRoleGate>
      </FamilyGate>
    );

    await waitFor(() => expect(router.replace).toHaveBeenCalledWith("/family/login"));
    expect(document.querySelector("[data-role-page]")).toBeNull();
  });

  it("stays behind FamilyGate: a non-family account never reaches it", async () => {
    vi.spyOn(apiClient, "get").mockRejectedValue(new ApiError(403, null));
    renderWithClient(
      <FamilyGate>
        <CoordinatorRoleGate>
          <p data-role-page>سجل المنسق</p>
        </CoordinatorRoleGate>
      </FamilyGate>
    );

    expect(await screen.findByText("هذا الحساب ليس حساب أسرة، ولا يمكنه استخدام بوابة الأسرة.")).toBeInTheDocument();
    expect(document.querySelector("[data-role-page]")).toBeNull();
  });
});
