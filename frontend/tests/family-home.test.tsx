import { act, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { FamilyGate } from "@/components/family/family-gate";
import { FamilyHome } from "@/components/family/family-home";
import { SUMMARY_CAPTION } from "@/components/family/home/household-cards";
import { QUICK_ACTIONS } from "@/components/family/home/quick-actions";
import { ApiError, apiClient } from "@/lib/api/client";
import { FAMILY_HOUSEHOLD_QUERY_KEY } from "@/lib/api/family-household";
import { browserStorageDump, familyGet, familyHousehold, familyUser, renderWithClient } from "./helpers";

// PWA-3A Step 2: the Family Portal home on GET /api/v1/family/household.
// Synthetic data only.

const router = { replace: vi.fn(), push: vi.fn() };
vi.mock("next/navigation", () => ({ useRouter: () => router, usePathname: () => "/family" }));
vi.mock("next/link", () => ({
  default: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...props}>
      {children}
    </a>
  ),
}));

const HOUSEHOLD = "/api/v1/family/household";

function renderHome() {
  return renderWithClient(
    <FamilyGate>
      <FamilyHome />
    </FamilyGate>
  );
}

const summary = () => screen.findByRole("region", { name: "ملخص الأسرة" });
const stat = (container: HTMLElement, name: "declared" | "registered") =>
  container.querySelector(`[data-stat="${name}"] dd`) as HTMLElement;

beforeEach(() => {
  router.replace.mockReset();
  window.localStorage.clear();
  window.sessionStorage.clear();
});

describe("the household summary", () => {
  it("reads GET /api/v1/family/household with no family, person or membership identifier", async () => {
    const get = vi.spyOn(apiClient, "get").mockImplementation(familyGet());

    renderHome();
    await summary();

    const paths = get.mock.calls.map(([path]) => String(path));
    expect(paths).toContain(HOUSEHOLD);
    expect(paths.filter((p) => p.startsWith(HOUSEHOLD))).toEqual([HOUSEHOLD]);
    expect(paths.join(" ")).not.toMatch(/family_id|person_id|membership_id|\?/);
  });

  it("shows the declared size and the registered count as two separate facts", async () => {
    vi.spyOn(apiClient, "get").mockImplementation(familyGet({ household: familyHousehold({ declared_household_size: 7, registered_member_count: 2 }) }));

    renderHome();
    const card = await summary();

    expect(within(card).getByText("عدد أفراد الأسرة حسب الإقرار")).toBeInTheDocument();
    expect(within(card).getByText("الأفراد المسجّلون بالتفصيل")).toBeInTheDocument();
    expect(stat(card, "declared")).toHaveTextContent(/^7$/);
    expect(stat(card, "registered")).toHaveTextContent(/^2$/);
    expect(stat(card, "declared")).toHaveClass("tabular-nums");
    expect(within(card).getByText(SUMMARY_CAPTION)).toBeInTheDocument();
  });

  it("never calculates a difference or speaks of missing members", async () => {
    vi.spyOn(apiClient, "get").mockImplementation(familyGet({ household: familyHousehold({ declared_household_size: 7, registered_member_count: 2 }) }));

    renderHome();
    await summary();

    const text = document.body.textContent ?? "";
    expect(text).not.toMatch(/(^|\D)5(\D|$)/); // 7 − 2
    expect(text).not.toMatch(/غير مسجل|غير المسجل|ناقص|مفقود|متبقي|المتبقين|الفرق/);
  });

  it.each([
    ["not declared", null, "غير مُعلن"],
    ["a declared zero", 0, "0"],
  ])("keeps %s distinct", async (_label, declared, shown) => {
    vi.spyOn(apiClient, "get").mockImplementation(familyGet({ household: familyHousehold({ declared_household_size: declared }) }));

    renderHome();
    const card = await summary();

    expect(stat(card, "declared").textContent).toBe(shown);
  });

  it("replaces the old activated card and the generic coming-soon list", async () => {
    vi.spyOn(apiClient, "get").mockImplementation(familyGet());

    renderHome();
    await summary();

    expect(screen.queryByText("حسابك مفعّل")).not.toBeInTheDocument();
    expect(screen.queryByText("قريبًا في البوابة")).not.toBeInTheDocument();
    expect(screen.queryByText(/اكتمال|إكمال الملف|%/)).not.toBeInTheDocument();
    expect(screen.queryByText(/طلباتك|طلب مفتوح|طلبات مفتوحة/)).not.toBeInTheDocument();
  });

  it("links the members entry to /family/members, without a coming-soon badge", async () => {
    vi.spyOn(apiClient, "get").mockImplementation(familyGet());

    renderHome();
    const card = await summary();

    const entry = within(card).getByRole("link", { name: "عرض أفراد الأسرة" });
    expect(entry).toHaveAttribute("href", "/family/members");
    expect(entry).not.toHaveTextContent("قريبًا");
    expect(within(card).queryByText("قريبًا")).not.toBeInTheDocument();
    expect(within(card).queryByRole("button")).not.toBeInTheDocument();
  });
});

describe("the family identity card", () => {
  it("shows the clan, the branch and the code (left-to-right)", async () => {
    vi.spyOn(apiClient, "get").mockImplementation(familyGet());

    renderHome();
    const card = await screen.findByRole("region", { name: "الأسرة" });

    expect(within(card).getByText("عائلة الاختبار")).toBeInTheDocument();
    expect(within(card).getByText("فرع الاختبار")).toBeInTheDocument();
    expect(within(card).getByText("FAM-000123")).toHaveAttribute("dir", "ltr");
  });

  it("leaves the branch out cleanly when there is none", async () => {
    vi.spyOn(apiClient, "get").mockImplementation(familyGet({ household: familyHousehold({ branch_name: null }) }));

    const { container } = renderHome();
    await screen.findByRole("region", { name: "الأسرة" });

    expect(container.querySelector("[data-family-branch]")).toBeNull();
    expect(screen.queryByText(/الفرع/)).not.toBeInTheDocument();
    expect(screen.queryByText("null")).not.toBeInTheDocument();
  });

  it("keeps one page heading: the greeting", async () => {
    vi.spyOn(apiClient, "get").mockImplementation(familyGet());

    renderHome();
    await summary();

    expect(screen.getAllByRole("heading", { level: 1 }).map((h) => h.textContent)).toEqual(["سالم الاختبار"]);
    for (const name of ["الأسرة", "ملخص الأسرة", "الخدمات السريعة"]) {
      expect(screen.getByRole("heading", { level: 2, name })).toBeInTheDocument();
    }
  });
});

describe("Coordinator Space and the quick actions", () => {
  it("shows the coordinator entry only when /me says the space is open, apart from the household", async () => {
    vi.spyOn(apiClient, "get").mockImplementation(
      familyGet({ user: familyUser({ roles: ["FAMILY_USER", "COORDINATOR"], coordinator: true, coordinator_space: true }) })
    );

    renderHome();
    const card = await summary();

    expect(screen.getByRole("link", { name: /مساحة التنسيق/ })).toHaveAttribute("href", "/family/coordinator");
    expect(within(card).queryByText(/مساحة التنسيق/)).not.toBeInTheDocument();
    expect(stat(card, "registered")).toHaveTextContent(/^2$/);
  });

  it("has no coordinator entry for an ordinary household head", async () => {
    vi.spyOn(apiClient, "get").mockImplementation(familyGet());

    renderHome();
    await summary();

    expect(screen.queryByText("مساحة التنسيق")).not.toBeInTheDocument();
  });

  it("lists the four approved quick actions, all disabled and marked coming soon", async () => {
    vi.spyOn(apiClient, "get").mockImplementation(familyGet());

    renderHome();
    const section = await screen.findByRole("region", { name: "الخدمات السريعة" });

    expect(QUICK_ACTIONS.map((a) => a.label)).toEqual(["إضافة مولود", "تحديث بيانات الأسرة", "الصحة والإعاقة", "تسجيل احتياج"]);
    for (const { label } of QUICK_ACTIONS) {
      const action = within(section).getByRole("button", { name: new RegExp(label) });
      expect(action).toBeDisabled();
      expect(action).toHaveAttribute("aria-disabled", "true");
      expect(action).toHaveTextContent("قريبًا");
    }
    expect(within(section).queryByRole("link")).not.toBeInTheDocument();
  });

  it("keeps the bottom navigation: الرئيسية (current), أسرتي, طلباتي and حسابي are links, «+» disabled while closed", async () => {
    vi.spyOn(apiClient, "get").mockImplementation(familyGet());

    renderHome();
    await summary();

    const nav = screen.getByRole("navigation", { name: "التنقل الرئيسي" });
    expect(within(nav).getAllByRole("link").map((l) => l.textContent)).toEqual(["الرئيسية", "أسرتي", "طلباتي", "حسابي"]);
    expect(within(nav).getByRole("link", { name: "الرئيسية" })).toHaveAttribute("aria-current", "page");
    // docs/11 FP-ADR-074: «+» follows server type discovery (closed by default).
    expect(await within(nav).findByRole("button", { name: "إجراء جديد (قريبًا)" })).toBeDisabled();
  });

  it("still offers the install suggestion", async () => {
    vi.spyOn(apiClient, "get").mockImplementation(familyGet());

    const { container } = renderHome();
    await summary();

    expect(container.querySelector("[data-install-famboook]")).not.toBeNull();
  });
});

describe("loading, errors and access", () => {
  it("shows a busy skeleton while the household loads", async () => {
    let release: (value: unknown) => void = () => undefined;
    vi.spyOn(apiClient, "get").mockImplementation(familyGet({ household: () => new Promise((resolve) => (release = resolve)) }));

    const { container } = renderHome();

    expect(await screen.findByRole("status")).toHaveTextContent("جارٍ تحميل بيانات الأسرة");
    expect(container.querySelector("[data-household]")).toHaveAttribute("aria-busy", "true");
    expect(container.querySelector("[data-household-loading]")).not.toBeNull();
    // The greeting and the shell do not wait for the household.
    expect(screen.getByRole("heading", { level: 1, name: "سالم الاختبار" })).toBeInTheDocument();

    await act(async () => release({ data: familyHousehold() }));
    expect(await summary()).toBeInTheDocument();
    expect(container.querySelector("[data-household]")).toHaveAttribute("aria-busy", "false");
    expect(container.querySelector("[data-household-loading]")).toBeNull();
  });

  it("shows a transient failure inline, inside the shell, and retries", async () => {
    let calls = 0;
    vi.spyOn(apiClient, "get").mockImplementation(
      familyGet({
        household: async () => {
          calls++;
          if (calls === 1) throw new ApiError(500, null);
          return { data: familyHousehold() };
        },
      })
    );

    renderHome();

    expect(await screen.findByRole("alert")).toHaveTextContent("تعذّر تحميل بيانات الأسرة");
    // The shell stays: navigation, greeting, quick actions.
    expect(screen.getByRole("navigation", { name: "التنقل الرئيسي" })).toBeInTheDocument();
    expect(screen.getByRole("heading", { level: 1, name: "سالم الاختبار" })).toBeInTheDocument();
    expect(screen.queryByRole("heading", { name: "الوصول غير متاح حاليًا" })).not.toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: "إعادة المحاولة" }));

    expect(await summary()).toBeInTheDocument();
    expect(calls).toBe(2);
  });

  it.each([
    ["the family context boundary", { message: "بيانات الأسرة غير متاحة لهذا الحساب.", code: "FAMILY_CONTEXT_UNAVAILABLE" }],
    ["the permission check", { message: "This action is unauthorized." }],
    ["an empty body", null],
  ])("treats ANY 403 (%s) as family data unavailable", async (_label, body) => {
    vi.spyOn(apiClient, "get").mockImplementation(familyGet({ household: new ApiError(403, body) }));

    renderHome();

    expect(await screen.findByRole("heading", { name: "الوصول غير متاح حاليًا" })).toBeInTheDocument();
    expect(screen.queryByRole("navigation")).not.toBeInTheDocument();
    expect(screen.queryByText("سالم الاختبار")).not.toBeInTheDocument();
    expect(screen.queryByText(/This action is unauthorized|FAMILY_CONTEXT_UNAVAILABLE/)).not.toBeInTheDocument();
    expect(screen.queryByText("تعذّر تحميل بيانات الأسرة")).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: "تسجيل الخروج" })).toBeEnabled();
    expect(router.replace).not.toHaveBeenCalled();
  });

  it("returns an expired session (401) to the Family login", async () => {
    vi.spyOn(apiClient, "get").mockImplementation(familyGet({ household: new ApiError(401, { message: "Unauthenticated." }) }));

    renderHome();

    await waitFor(() => expect(router.replace).toHaveBeenCalledWith("/family/login"));
    expect(screen.queryByText("تعذّر تحميل بيانات الأسرة")).not.toBeInTheDocument();
  });

  it("keeps the household in memory only: nothing in browser storage", async () => {
    vi.spyOn(apiClient, "get").mockImplementation(familyGet());

    const { client } = renderHome();
    await summary();

    expect(client.getQueryData(FAMILY_HOUSEHOLD_QUERY_KEY)).toEqual(familyHousehold());
    const dump = browserStorageDump();
    for (const value of ["FAM-000123", "عائلة الاختبار", "فرع الاختبار", "سالم الاختبار", "registered_member_count", "household"]) {
      expect(dump).not.toContain(value);
    }
  });
});
