import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi, type MockInstance } from "vitest";
import { AuthContext, authValue } from "@/components/auth/auth-context";
import AdministrationPage from "@/app/(staff)/administration/page";
import CivilRegistryLookupPage from "@/app/(staff)/civil-registry/lookup/page";
import CivilLookupQuotasPage from "@/app/(staff)/administration/civil-registry/lookup-quotas/page";
import { ApiError, apiClient } from "@/lib/api/client";
import type { CurrentUser } from "@/lib/api/auth";
import { navItemFor, navItems } from "@/lib/navigation";
import type {
  CivilLookupList,
  CivilLookupQuota,
  CivilLookupRecord,
  CivilLookupRecordResponse,
  CivilLookupStatus,
  CivilLookupUsage,
} from "@/lib/types/api/civil-lookup";
import { browserStorageDump, renderWithClient } from "./helpers";

// FB-CIVIL-02A — Staff Portal Civil Registry Lookup. SYNTHETIC data only
// (900000xxx identifiers, placeholder names). The API authorizes and counts;
// these tests cover what the screen sends, shows and keeps (memory only).

vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn(), replace: vi.fn() }), usePathname: () => "/civil-registry/lookup" }));
vi.mock("next/link", () => ({
  default: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...props}>
      {children}
    </a>
  ),
}));

const BASE = "/api/v1/civil-registry/lookup";

function staff(permissions: string[]): CurrentUser {
  return { name: "مستخدم تجريبي", email: "staff@example.test", role: "ADMINISTRATOR", role_label: "مسؤول إداري", permissions };
}

function renderAs(permissions: string[], ui: React.ReactElement) {
  return renderWithClient(<AuthContext.Provider value={authValue(staff(permissions))}>{ui}</AuthContext.Provider>);
}

const quota = (over: Partial<CivilLookupQuota> = {}): CivilLookupQuota => ({
  enabled: true, unlimited: false, daily_limit: 50, used: 3, remaining: 47, resets_at: "2026-10-27T22:00:00+00:00", reason: null, ...over,
});

const status = (over: Partial<CivilLookupStatus["quota"]> = {}, view = true): { data: CivilLookupStatus } => ({
  data: { context: "STAFF", quota: quota(over), capabilities: { search: true, view, view_full: false, reverse_links: false } },
});

const entry = (n: number) => ({
  ref: `ref-${n}`, national_id: `*****${String(n).padStart(4, "0")}`, fullname: `اسم تجريبي ${n}`, gender: "1", datebirth: "1990-01-15T00:00:00", govern: null, city: null,
});

const list = (over: Partial<CivilLookupList> = {}): { data: CivilLookupList } => ({
  data: {
    release_id: 1, outcome: "RESULTS", results: [entry(1)], meta: { page: 1, per_page: 25, total: 1, last_page: 1 }, search_ref: null, quota: quota(), ...over,
  },
});

const record = (over: Partial<CivilLookupRecord> = {}): CivilLookupRecord => ({
  ref: "rec-1",
  national_id: "*****0001",
  fullname: "اسم تجريبي 1",
  level: "STANDARD",
  sections: [
    { key: "personal", fields: [{ key: "mainid", value: "*****0001" }, { key: "name1", value: "اسم" }, { key: "birthplace", value: null }] },
    { key: "geographic", fields: [{ key: "govern", value: "7" }] },
  ],
  links: [
    { relation: "FATHER", status: "AVAILABLE", national_id: "*****0002", ref: "link-father" },
    { relation: "MOTHER", status: "NOT_IN_RELEASE", national_id: "*****0099" },
    { relation: "SPOUSE", status: "EMPTY" },
    { relation: "HOUSEHOLD", status: "DISABLED" },
    { relation: "CHILDREN", status: "DISABLED" },
  ],
  ...over,
});

const opened = (r: CivilLookupRecord | null): { data: CivilLookupRecordResponse } => ({
  data: { outcome: r ? "FOUND" : "NOT_FOUND", release_id: 1, record: r, quota: quota() },
});

let post: MockInstance<typeof apiClient.post>;

/** Waits until the element exists (waitFor alone would resolve with null). */
async function findEl(query: () => Element | null): Promise<HTMLElement> {
  return waitFor(() => {
    const el = query();
    expect(el).not.toBeNull();
    return el as HTMLElement;
  });
}

function mockPosts(routes: Record<string, unknown>) {
  post = vi.spyOn(apiClient, "post").mockImplementation(async (path: string) => {
    const reply = routes[path.replace(BASE, "")];
    if (reply === undefined) throw new Error(`Unexpected POST: ${path}`);
    if (reply instanceof Error) throw reply;
    return (typeof reply === "function" ? (reply as () => unknown)() : reply) as never;
  });
}

beforeEach(() => {
  vi.spyOn(apiClient, "get").mockImplementation(async (path: string) => {
    if (path === `${BASE}/status`) return status() as never;
    throw new Error(`Unexpected GET: ${path}`);
  });
});

afterEach(() => {
  vi.restoreAllMocks();
});

describe("navigation", () => {
  it("has a lookup entry for civil-lookup.search and an admin card for usage.view", () => {
    expect(navItems.find((i) => i.href === "/civil-registry/lookup")?.permissions).toEqual(["civil-lookup.search"]);
    expect(navItemFor("/civil-registry/lookup")?.href).toBe("/civil-registry/lookup");
    expect(navItems.find((i) => i.href === "/administration")?.permissions).toContain("civil-lookup.usage.view");
    const { unmount } = renderAs(["civil-lookup.usage.view"], <AdministrationPage />);
    expect(document.querySelector('[data-admin-card="civil-lookup-quotas"]')).toHaveAttribute("href", "/administration/civil-registry/lookup-quotas");
    unmount();
    renderAs(["civil-lookup.search"], <AdministrationPage />);
    expect(document.querySelector('[data-admin-card="civil-lookup-quotas"]')).toBeNull();
  });

  it("refuses the page without civil-lookup.search", () => {
    renderAs([], <CivilRegistryLookupPage />);
    expect(document.querySelector("[data-forbidden]")).not.toBeNull();
  });
});

describe("lookup screen", () => {
  it("says when the service is off and shows no search form", async () => {
    vi.spyOn(apiClient, "get").mockResolvedValue(status({ enabled: false, reason: "LOOKUP_DISABLED" }) as never);
    renderAs(["civil-lookup.search"], <CivilRegistryLookupPage />);
    expect(await screen.findByText("خدمة الاستعلام عن السجل المدني غير مفعّلة حاليًا.")).toBeInTheDocument();
    expect(document.querySelector("[data-lookup-form]")).toBeNull();
  });

  it("validates a National ID before sending, opens the single result for free and keeps nothing in the browser", async () => {
    const user = userEvent.setup();
    mockPosts({ "/search/national-id": list(), "/records/open": opened(record()) });
    renderAs(["civil-lookup.search", "civil-lookup.view"], <CivilRegistryLookupPage />);
    await screen.findByText("الرصيد اليومي:", { exact: false });
    expect(document.querySelector("[data-quota-remaining]")?.textContent).toBe((47).toLocaleString("ar"));

    await user.type(screen.getByLabelText("رقم الهوية"), "12345");
    await user.click(screen.getByRole("button", { name: "بحث" }));
    expect(screen.getByText("رقم الهوية يجب أن يتكون من 9 أرقام.")).toBeInTheDocument();
    expect(post).not.toHaveBeenCalled();

    await user.clear(screen.getByLabelText("رقم الهوية"));
    await user.type(screen.getByLabelText("رقم الهوية"), "900000001");
    await user.click(screen.getByRole("button", { name: "بحث" }));
    await waitFor(() => expect(document.querySelector("[data-citizen-detail]")).not.toBeNull());
    expect(post).toHaveBeenNthCalledWith(1, `${BASE}/search/national-id`, { national_id: "900000001" });
    expect(post).toHaveBeenNthCalledWith(2, `${BASE}/records/open`, { ref: "ref-1" });

    // Sections, raw values and "غير متوفر"; linked statuses; nothing interpreted.
    const detail = document.querySelector("[data-citizen-detail]") as HTMLElement;
    expect(within(detail).getByText("البيانات الشخصية")).toBeInTheDocument();
    expect(within(detail).getByText("مكان الميلاد (رمز المصدر)")).toBeInTheDocument();
    expect(detail.querySelector('[data-field="birthplace"]')?.textContent).toBe("غير متوفر");
    expect(detail.querySelector('[data-field="govern"]')?.textContent).toBe("7");
    expect(document.querySelector('[data-link="MOTHER"]')?.textContent).toContain("غير موجود في الإصدار المنشور");
    expect(document.querySelector('[data-link="HOUSEHOLD"]')?.textContent).toContain("غير مفعّل حاليًا");
    expect(within(document.querySelector('[data-link="MOTHER"]') as HTMLElement).queryByRole("button")).toBeNull();

    // Memory only: no browser storage, no identifier in the address.
    expect(browserStorageDump()).not.toContain("900000001");
    expect(window.location.href).not.toContain("900000001");
  });

  it("paginates the same search, opens a linked citizen as a new lookup and goes back without a request", async () => {
    const user = userEvent.setup();
    const many = Array.from({ length: 25 }, (_, i) => entry(i + 1));
    mockPosts({
      "/search/name": list({ results: many, meta: { page: 1, per_page: 25, total: 30, last_page: 2 }, search_ref: "search-1" }),
      "/search/page": list({ results: [entry(26)], meta: { page: 2, per_page: 25, total: 30, last_page: 2 }, search_ref: null }),
      "/records/open": opened(record()),
      "/links/open": opened(record({ ref: "rec-2", fullname: "اسم الأب التجريبي", national_id: "*****0002", links: [] })),
    });
    renderAs(["civil-lookup.search", "civil-lookup.view"], <CivilRegistryLookupPage />);
    await user.click(await screen.findByRole("tab", { name: "الاسم الكامل" }));

    await user.type(screen.getByLabelText("الاسم الكامل أو جزء منه"), "مح");
    await user.click(screen.getByRole("button", { name: "بحث" }));
    expect(document.querySelector("[data-form-error]")).not.toBeNull();
    expect(post).not.toHaveBeenCalled();

    await user.clear(screen.getByLabelText("الاسم الكامل أو جزء منه"));
    await user.type(screen.getByLabelText("الاسم الكامل أو جزء منه"), "سامي تجريبية");
    await user.click(screen.getByRole("button", { name: "بحث" }));
    await waitFor(() => expect(document.querySelectorAll("[data-result]")).toHaveLength(25));
    expect(post).toHaveBeenLastCalledWith(`${BASE}/search/name`, { fullname: "سامي تجريبية" });

    await user.click(screen.getByRole("button", { name: "الصفحة التالية" }));
    await waitFor(() => expect(document.querySelectorAll("[data-result]")).toHaveLength(1));
    expect(post).toHaveBeenLastCalledWith(`${BASE}/search/page`, { ref: "search-1", page: 2 });

    await user.click(screen.getByRole("button", { name: "عرض" }));
    await waitFor(() => expect(document.querySelector("[data-citizen-detail]")).not.toBeNull());
    await user.click(within(document.querySelector('[data-link="FATHER"]') as HTMLElement).getByRole("button", { name: "فتح السجل" }));
    await waitFor(() => expect(screen.getByText("الأب: اسم الأب التجريبي")).toBeInTheDocument());
    expect(post).toHaveBeenLastCalledWith(`${BASE}/links/open`, { ref: "link-father" });
    const calls = post.mock.calls.length;

    // Back and breadcrumb: from memory, no request (and so never counted).
    await user.click(screen.getByRole("button", { name: "رجوع" }));
    expect(document.querySelector('[data-link="FATHER"]')).not.toBeNull();
    await user.click(within(document.querySelector("[data-breadcrumb]") as HTMLElement).getByRole("button", { name: "نتائج البحث" }));
    expect(document.querySelectorAll("[data-result]")).toHaveLength(1);
    expect(post.mock.calls.length).toBe(calls);
  });

  it("shows fixed Arabic text for refusals, never the server message", async () => {
    const user = userEvent.setup();
    mockPosts({ "/search/national-id": new ApiError(429, { code: "QUOTA_EXHAUSTED", message: "server text 900000001" }) });
    renderAs(["civil-lookup.search", "civil-lookup.view"], <CivilRegistryLookupPage />);
    await user.type(await screen.findByLabelText("رقم الهوية"), "900000001");
    await user.click(screen.getByRole("button", { name: "بحث" }));
    const alert = await findEl(() => document.querySelector('[data-lookup-error="QUOTA_EXHAUSTED"]'));
    expect(alert.textContent).toContain("استنفدت رصيدك اليومي");
    expect(alert.textContent).not.toContain("server text");
  });

  it("disables the search when the daily quota is used up", async () => {
    vi.spyOn(apiClient, "get").mockResolvedValue(status({ used: 50, remaining: 0 }) as never);
    renderAs(["civil-lookup.search"], <CivilRegistryLookupPage />);
    expect(await screen.findByText("استنفدت رصيدك اليومي.", { exact: false })).toBeInTheDocument();
    expect(document.querySelector('[data-quota="exhausted"]')).not.toBeNull();
    expect(screen.getByRole("button", { name: "بحث" })).toBeDisabled();
  });

  it("sends only the given detailed fields and explains a too broad search", async () => {
    const user = userEvent.setup();
    mockPosts({ "/search/parts": list({ outcome: "TOO_BROAD", results: [], meta: { page: 1, per_page: 25, total: null, last_page: 1 } }) });
    renderAs(["civil-lookup.search"], <CivilRegistryLookupPage />);
    await user.click(await screen.findByRole("tab", { name: "البحث التفصيلي" }));
    await user.type(screen.getByLabelText("اسم الأب"), "خالد");
    await user.type(screen.getByLabelText("اسم العائلة"), "تجريبية");
    await user.click(screen.getByRole("button", { name: "بحث" }));
    expect(await screen.findByText("نتائج كثيرة جدًا")).toBeInTheDocument();
    expect(post).toHaveBeenLastCalledWith(`${BASE}/search/parts`, { name1: null, father: "خالد", grandfath: null, family: "تجريبية" });
  });
});

describe("quota administration", () => {
  const usage = (): { data: CivilLookupUsage } => ({
    data: {
      date: "2026-10-27",
      resets_at: "2026-10-27T22:00:00+00:00",
      total_lookups: 12,
      active_users: 3,
      lookup_enabled: false,
      reverse_links_enabled: false,
      grantable_sides: [],
      side_lookup_enabled: { FAMILY: false, COORDINATOR: false },
      exhausted_users: [{ user_id: 7, name: "مدخل تجريبي", role: "DATA_ENTRY", used: 50, daily_limit: 50 }],
      policies: [
        { role: "SUPER_ADMIN", enabled: true, daily_limit: null, unlimited: true, unlimited_allowed: true, updated_by: null, updated_at: null, today: { lookups: 2, users: 1, exhausted: 0 } },
        { role: "DATA_ENTRY", enabled: true, daily_limit: 50, unlimited: false, unlimited_allowed: false, updated_by: null, updated_at: null, today: { lookups: 10, users: 2, exhausted: 1 } },
        { role: "COORDINATOR", enabled: false, daily_limit: 5, unlimited: false, unlimited_allowed: false, updated_by: null, updated_at: null, today: { lookups: 0, users: 0, exhausted: 0 } },
      ],
    },
  });

  let get: MockInstance<typeof apiClient.get>;

  beforeEach(() => {
    get = vi.spyOn(apiClient, "get").mockImplementation(async (path: string) => {
      if (path === `${BASE}/admin/usage`) return usage() as never;
      if (path.startsWith(`${BASE}/admin/changes`)) {
        return {
          data: [{ id: 1, subject_type: "ROLE", role: "DATA_ENTRY", user: null, old_enabled: true, new_enabled: true, old_daily_limit: 60, new_daily_limit: 50,
            old_access: null, new_access: null, changed_by: "مدير تجريبي", changed_at: "2026-10-27T08:00:00+00:00" }],
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
        } as never;
      }
      if (path === `${BASE}/admin/user-access`) return { data: [] } as never;
      throw new Error(`Unexpected GET: ${path}`);
    });
  });

  it("lets a manager change a role's limit and shows the history", async () => {
    const user = userEvent.setup();
    const put = vi.spyOn(apiClient, "put").mockResolvedValue({ data: { role: "DATA_ENTRY", enabled: true, daily_limit: 40 } } as never);
    renderAs(["civil-lookup.usage.view", "civil-lookup.manage"], <CivilLookupQuotasPage />);
    const row = await findEl(() => document.querySelector('[data-policy="DATA_ENTRY"]'));
    expect(within(row).getByText("مدخل بيانات")).toBeInTheDocument();
    expect(document.querySelector('[data-policy="SUPER_ADMIN"] [data-unlimited]')).not.toBeNull();
    // FB-CIVIL-02C (corrected): an automatic role shows no enable switch.
    expect(document.querySelector('[data-policy="SUPER_ADMIN"] [data-automatic]')?.textContent).toContain("تلقائي");
    expect(document.querySelector('[data-policy="SUPER_ADMIN"] input[type="checkbox"]')).toBeNull();
    expect(document.querySelector('[data-policy="DATA_ENTRY"] input[type="checkbox"]')).not.toBeNull();
    expect(document.querySelector('[data-lookup-state="DISABLED"]')).not.toBeNull();
    expect(document.querySelector("[data-exhausted-users]")?.textContent).toContain("مدخل تجريبي");

    const input = within(row).getByLabelText("الحد اليومي لدور مدخل بيانات");
    await user.clear(input);
    await user.type(input, "40");
    await user.click(within(row).getByRole("button", { name: "حفظ" }));
    expect(put).toHaveBeenCalledWith(`${BASE}/admin/policies/DATA_ENTRY`, { enabled: true, daily_limit: 40 });
    expect(await screen.findByText("سجل تغييرات سياسات الأدوار")).toBeInTheDocument();
    // FB-CIVIL-02D: role changes only; per-user access has its own page.
    expect(get).toHaveBeenCalledWith(`${BASE}/admin/changes?page=1&subject=ROLE`);
    expect(document.querySelector("[data-access-form]")).toBeNull();
    expect(document.querySelector('[data-access-link] a')).toHaveAttribute("href", "/administration/civil-registry/lookup-access");
    expect(document.querySelector('[data-change="ROLE"]')?.textContent).toContain("مدخل بيانات");
  });

  it("is read-only without civil-lookup.manage", async () => {
    renderAs(["civil-lookup.usage.view"], <CivilLookupQuotasPage />);
    const row = await findEl(() => document.querySelector('[data-policy="DATA_ENTRY"]'));
    expect(within(row).queryByRole("button", { name: "حفظ" })).toBeNull();
    expect(within(row).queryByRole("spinbutton")).toBeNull();
    expect(document.querySelector("[data-user-access]")).toBeNull();
    expect(document.querySelector("[data-policy-changes]")).toBeNull();
  });
});
