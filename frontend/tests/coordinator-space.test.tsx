import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { CoordinatorFamilySummary } from "@/components/family/coordinator/coordinator-family-summary";
import { CoordinatorGate } from "@/components/family/coordinator/coordinator-gate";
import { CoordinatorHome } from "@/components/family/coordinator/coordinator-home";
import { FamilyGate } from "@/components/family/family-gate";
import { FamilyHome } from "@/components/family/family-home";
import { ApiError, apiClient } from "@/lib/api/client";
import type { CoordinatorFamily } from "@/lib/api/coordinator";
import { browserStorageDump, familyHousehold, familyUser, renderWithClient } from "./helpers";

const router = { replace: vi.fn(), push: vi.fn() };
const location = { pathname: "/family" };
vi.mock("next/navigation", () => ({ useRouter: () => router, usePathname: () => location.pathname }));
vi.mock("next/link", () => ({
  default: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...props}>
      {children}
    </a>
  ),
}));

// Synthetic values only.
const COORDINATOR = familyUser({ roles: ["FAMILY_USER", "COORDINATOR"], coordinator: true, coordinator_space: true });
const CONTEXT = {
  scopes: [
    { type: "CLAN", code: "TEST_CLAN", name: "عشيرة الاختبار", clan_name: "عشيرة الاختبار" },
    { type: "BRANCH_GROUP", code: "GRP_A", name: "مجموعة أ", clan_name: "عشيرة الاختبار" },
    { type: "BRANCH", code: "BR_A", name: "فرع أ", clan_name: "عشيرة الاختبار" },
  ],
  family_count: 27,
};
const family = (n: number): CoordinatorFamily => ({
  family_code: `FAM-10000${n}`,
  clan_name: "عشيرة الاختبار",
  branch_group_name: n % 2 ? "مجموعة أ" : null,
  branch_name: "فرع أ",
  head_name: `رب الأسرة ${n}`,
  active_member_count: n + 1,
});
const page = (items: CoordinatorFamily[], current = 1, last = 1, total = items.length) => ({
  data: items,
  meta: { current_page: current, last_page: last, per_page: 25, total },
});

/** Routes apiClient.get by path; an ApiError reply is thrown. */
function api(routes: Record<string, unknown | ((path: string) => unknown)>) {
  return vi.spyOn(apiClient, "get").mockImplementation(async (path: string) => {
    const key = Object.keys(routes).find((prefix) => path.startsWith(prefix));
    if (!key) throw new Error(`Unexpected request: ${path}`);
    const route = routes[key];
    const reply = typeof route === "function" ? (route as (p: string) => unknown)(path) : route;
    if (reply instanceof ApiError) throw reply;
    return reply as never;
  });
}

function renderSpace() {
  return renderWithClient(
    <FamilyGate>
      <CoordinatorGate>
        <CoordinatorHome />
      </CoordinatorGate>
    </FamilyGate>
  );
}

beforeEach(() => {
  router.replace.mockReset();
  location.pathname = "/family";
  window.localStorage.clear();
  window.sessionStorage.clear();
});

describe("the entry point", () => {
  it("is shown only when /family/me says Coordinator Space is open", async () => {
    api({ "/api/v1/family/me": { user: COORDINATOR }, "/api/v1/family/household": { data: familyHousehold() } });
    renderWithClient(
      <FamilyGate>
        <FamilyHome />
      </FamilyGate>
    );

    const entry = await screen.findByRole("link", { name: /مساحة التنسيق/ });
    expect(entry).toHaveAttribute("href", "/family/coordinator");
  });

  it.each([
    ["a family user", familyUser()],
    ["a coordinator without an effective scope", familyUser({ roles: ["FAMILY_USER", "COORDINATOR"], coordinator: true, coordinator_space: false })],
  ])("is absent for %s, and the household stays as it is", async (_label, user) => {
    api({ "/api/v1/family/me": { user }, "/api/v1/family/household": { data: familyHousehold() } });
    renderWithClient(
      <FamilyGate>
        <FamilyHome />
      </FamilyGate>
    );

    expect(await screen.findByRole("heading", { name: "ملخص الأسرة" })).toBeInTheDocument();
    expect(screen.queryByText("مساحة التنسيق")).not.toBeInTheDocument();
    expect(screen.getByRole("navigation", { name: "التنقل الرئيسي" })).toBeInTheDocument();
  });
});

describe("Coordinator Space", () => {
  beforeEach(() => {
    location.pathname = "/family/coordinator";
  });

  it("is a separate mode: the strip, the way back, no household navigation", async () => {
    const get = api({
      "/api/v1/family/me": { user: COORDINATOR },
      "/api/v1/family/coordinator/context": { data: CONTEXT },
      "/api/v1/family/coordinator/families": page([family(1)]),
    });
    renderSpace();

    expect(await screen.findByRole("heading", { level: 1, name: "مساحة التنسيق" })).toBeInTheDocument();
    expect(screen.getByText("أنت في مساحة التنسيق")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /العودة إلى أسرتي/ })).toHaveAttribute("href", "/family");
    expect(screen.queryByRole("navigation", { name: "التنقل الرئيسي" })).not.toBeInTheDocument();
    // The Coordinator's own household is not presented here.
    expect(screen.queryByRole("heading", { name: "ملخص الأسرة" })).not.toBeInTheDocument();
    expect(screen.queryByText("FAM-000123")).not.toBeInTheDocument();
    expect(get).not.toHaveBeenCalledWith("/api/v1/family/household");
  });

  it("shows the effective scopes and the family count from the server", async () => {
    api({
      "/api/v1/family/me": { user: COORDINATOR },
      "/api/v1/family/coordinator/context": { data: CONTEXT },
      "/api/v1/family/coordinator/families": page([]),
    });
    renderSpace();

    await screen.findByRole("heading", { name: "نطاق التنسيق" });
    const chips = within(document.querySelector("[data-coordinator-scopes]") as HTMLElement).getAllByRole("listitem");
    expect(chips.map((chip) => chip.textContent)).toEqual(["عشيرة: عشيرة الاختبار", "مجموعة فروع: مجموعة أ", "فرع: فرع أ"]);
    expect(screen.getByText(/عدد الأسر في النطاق/)).toHaveTextContent("عدد الأسر في النطاق: 27");
    expect(await screen.findByText("لا توجد أسر مطابقة.")).toBeInTheDocument();
  });

  it("lists the families with the summary fields only", async () => {
    api({
      "/api/v1/family/me": { user: COORDINATOR },
      "/api/v1/family/coordinator/context": { data: CONTEXT },
      "/api/v1/family/coordinator/families": page([family(1), family(2)]),
    });
    renderSpace();

    const rows = await screen.findAllByRole("link", { name: /رب الأسرة/ });
    expect(rows).toHaveLength(2);
    expect(rows[0]).toHaveAttribute("href", "/family/coordinator/families/FAM-100001");
    expect(rows[0]).toHaveTextContent("رب الأسرة 1");
    expect(rows[0]).toHaveTextContent("FAM-100001");
    expect(rows[0]).toHaveTextContent("عشيرة الاختبار · مجموعة أ · فرع أ");
    expect(rows[0]).toHaveTextContent("عدد الأفراد: 2");
    // A Family without a Branch Group simply skips that level.
    expect(rows[1]).toHaveTextContent("عشيرة الاختبار · فرع أ");
    expect(within(rows[0]).getByText("FAM-100001")).toHaveAttribute("dir", "ltr");
  });

  it("searches on the server and starts again from the first page", async () => {
    const get = api({
      "/api/v1/family/me": { user: COORDINATOR },
      "/api/v1/family/coordinator/context": { data: CONTEXT },
      "/api/v1/family/coordinator/families": (path: string) =>
        path.includes("q=") ? page([family(7)]) : page([family(1)], 1, 2, 26),
    });
    const user = userEvent.setup();
    renderSpace();
    await screen.findByText("رب الأسرة 1");

    await user.click(screen.getByRole("button", { name: "التالي" }));
    await waitFor(() => expect(get).toHaveBeenCalledWith("/api/v1/family/coordinator/families?page=2"));

    await user.type(screen.getByLabelText("بحث برمز الأسرة أو اسم رب الأسرة"), "FAM-1");
    await user.click(screen.getByRole("button", { name: "بحث" }));

    await waitFor(() => expect(get).toHaveBeenCalledWith("/api/v1/family/coordinator/families?page=1&q=FAM-1"));
    expect(await screen.findByText("رب الأسرة 7")).toBeInTheDocument();
  });

  it("paginates with the server's pages", async () => {
    api({
      "/api/v1/family/me": { user: COORDINATOR },
      "/api/v1/family/coordinator/context": { data: CONTEXT },
      "/api/v1/family/coordinator/families": (path: string) =>
        path.includes("page=2") ? page([family(3)], 2, 2, 26) : page([family(1)], 1, 2, 26),
    });
    const user = userEvent.setup();
    renderSpace();

    expect(await screen.findByText("صفحة 1 من 2")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "السابق" })).toBeDisabled();
    await user.click(screen.getByRole("button", { name: "التالي" }));

    expect(await screen.findByText("صفحة 2 من 2")).toBeInTheDocument();
    expect(await screen.findByText("رب الأسرة 3")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "التالي" })).toBeDisabled();
  });

  it("shows a neutral notice when the server refuses the space, and loads no family", async () => {
    const get = api({
      "/api/v1/family/me": { user: COORDINATOR },
      "/api/v1/family/coordinator/context": new ApiError(403, { message: "…", code: "COORDINATOR_SPACE_UNAVAILABLE" }),
    });
    renderSpace();

    expect(await screen.findByRole("alert")).toHaveTextContent("مساحة التنسيق غير متاحة لهذا الحساب.");
    expect(screen.getAllByRole("link", { name: /العودة إلى أسرتي/ }).length).toBeGreaterThan(0);
    expect(get.mock.calls.some(([path]) => path.startsWith("/api/v1/family/coordinator/families"))).toBe(false);
  });

  it("writes nothing to browser storage", async () => {
    api({
      "/api/v1/family/me": { user: COORDINATOR },
      "/api/v1/family/coordinator/context": { data: CONTEXT },
      "/api/v1/family/coordinator/families": page([family(1)]),
    });
    const writes = [vi.spyOn(window.localStorage, "setItem"), vi.spyOn(window.sessionStorage, "setItem")];
    renderSpace();
    await screen.findByText("رب الأسرة 1");

    for (const write of writes) expect(write).not.toHaveBeenCalled();
    expect(browserStorageDump()).not.toContain("FAM-100001");
  });
});

describe("a family summary", () => {
  it("shows the same six fields and nothing else", async () => {
    api({ "/api/v1/family/coordinator/families/FAM-100001": { data: family(1) } });
    renderWithClient(<CoordinatorFamilySummary code="FAM-100001" />);

    const summary = await screen.findByRole("heading", { level: 1, name: "رب الأسرة 1" });
    expect(summary).toBeInTheDocument();
    const list = document.querySelector("[data-coordinator-summary]") as HTMLElement;
    expect(within(list).getAllByRole("term").map((t) => t.textContent)).toEqual([
      "رمز الأسرة",
      "رب الأسرة",
      "العشيرة",
      "مجموعة الفروع",
      "الفرع",
      "عدد الأفراد",
    ]);
    expect(list).toHaveTextContent("FAM-100001");
    expect(list).toHaveTextContent("2");
    expect(screen.getByRole("link", { name: /العودة إلى قائمة الأسر/ })).toHaveAttribute("href", "/family/coordinator");
  });

  it("says the family is not available — the same for out of scope and nonexistent", async () => {
    const get = api({ "/api/v1/family/coordinator/families/": new ApiError(404, { message: "…", code: "FAMILY_NOT_FOUND" }) });
    renderWithClient(<CoordinatorFamilySummary code="FAM-999999" />);

    expect(await screen.findByRole("alert")).toHaveTextContent("الأسرة غير متاحة.");
    expect(get).toHaveBeenCalledWith("/api/v1/family/coordinator/families/FAM-999999");
  });

  it("encodes the code it was given", async () => {
    const get = api({ "/api/v1/family/coordinator/families/": new ApiError(404, { message: "…" }) });
    renderWithClient(<CoordinatorFamilySummary code="../me" />);

    await screen.findByRole("alert");
    expect(get).toHaveBeenCalledWith("/api/v1/family/coordinator/families/..%2Fme");
  });
});
