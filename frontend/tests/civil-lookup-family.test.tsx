import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, describe, expect, it, vi, type MockInstance } from "vitest";
import { FamilyUserContext } from "@/components/family/family-context";
import { CivilLookupEntry, FamilyCivilLookup } from "@/components/family/civil-lookup";
import FamilyCivilRegistryPage from "@/app/family/(portal)/civil-registry/page";
import CoordinatorCivilRegistryPage from "@/app/family/(portal)/coordinator/(space)/civil-registry/page";
import { ApiError, apiClient } from "@/lib/api/client";
import type { FamilyUser } from "@/lib/api/family-auth";
import type {
  CivilLookupList,
  CivilLookupQuota,
  CivilLookupRecord,
  CivilLookupStatus,
} from "@/lib/types/api/civil-lookup";
import { browserStorageDump, familyUser, renderWithClient } from "./helpers";

// FB-CIVIL-02B — the Civil Registry Lookup on the Family Portal and in
// Coordinator Space (the per-side grant administration is covered by
// civil-lookup-access.test.tsx, FB-CIVIL-02D).
// SYNTHETIC data only (900000xxx identifiers, placeholder names). The API
// authorizes, profiles and counts; these tests cover what each side shows,
// where it sends its requests, and that nothing is kept in the browser.

vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn(), replace: vi.fn() }), usePathname: () => "/family" }));
vi.mock("next/link", () => ({
  default: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...props}>
      {children}
    </a>
  ),
}));

const FAMILY = "/api/v1/family/civil-registry/lookup";
const COORDINATOR = "/api/v1/family/coordinator/civil-registry/lookup";
const STAFF = "/api/v1/civil-registry/lookup";

const HEAD: FamilyUser = familyUser({ civil_lookup: { family: true, coordinator: false } });
const COORD: FamilyUser = familyUser({
  roles: ["FAMILY_USER", "COORDINATOR"], coordinator: true, coordinator_space: true, civil_lookup: { family: false, coordinator: true },
});

const quota = (over: Partial<CivilLookupQuota> = {}): CivilLookupQuota => ({
  enabled: true, unlimited: false, daily_limit: 5, used: 2, remaining: 3, resets_at: "2026-10-27T22:00:00+00:00", reason: null, ...over,
});

const status = (context: CivilLookupStatus["context"], over: Partial<CivilLookupQuota> = {}): { data: CivilLookupStatus } => ({
  data: { context, quota: quota(over), capabilities: { search: true, view: true, view_full: false, reverse_links: false } },
});

const list = (): { data: CivilLookupList } => ({
  data: {
    release_id: 1, outcome: "FOUND", meta: { page: 1, per_page: 25, total: 1, last_page: 1 }, search_ref: null, quota: quota({ used: 3, remaining: 2 }),
    results: [{ ref: "ref-1", national_id: "*****0001", fullname: "اسم تجريبي 1", gender: "1", datebirth: "1990-01-15T00:00:00", govern: "10", city: "101" }],
  },
});

const extended = (over: Partial<CivilLookupRecord> = {}): CivilLookupRecord => ({
  ref: "rec-1",
  national_id: "*****0001",
  fullname: "اسم تجريبي 1",
  level: "EXTENDED_CONTROLLED",
  sections: [
    { key: "personal", fields: [{ key: "mainid", value: "*****0001" }, { key: "name1", value: "اسم" }, { key: "maritalst", value: "2" }, { key: "birthplace", value: null }] },
    { key: "geographic", fields: [{ key: "govern", value: "10" }, { key: "city", value: "101" }] },
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

let get: MockInstance<typeof apiClient.get>;
let post: MockInstance<typeof apiClient.post>;

function mockApi(base: string, statusReply: unknown, posts: Record<string, unknown> = {}) {
  get = vi.spyOn(apiClient, "get").mockImplementation(async (path: string) => {
    if (path === `${base}/status`) {
      if (statusReply instanceof Error) throw statusReply;
      return statusReply as never;
    }
    throw new Error(`Unexpected GET: ${path}`);
  });
  post = vi.spyOn(apiClient, "post").mockImplementation(async (path: string) => {
    if (!path.startsWith(base)) throw new Error(`POST outside the mount: ${path}`);
    const reply = posts[path.slice(base.length)];
    if (reply === undefined) throw new Error(`Unexpected POST: ${path}`);
    return reply as never;
  });
}

function renderFamily(user: FamilyUser, ui: React.ReactElement) {
  return renderWithClient(<FamilyUserContext.Provider value={user}>{ui}</FamilyUserContext.Provider>);
}

async function findEl(query: () => Element | null): Promise<HTMLElement> {
  return waitFor(() => {
    const el = query();
    expect(el).not.toBeNull();
    return el as HTMLElement;
  });
}

afterEach(() => {
  vi.restoreAllMocks();
});

describe("entry points", () => {
  it("shows each side's entry only when the server says that side is available", () => {
    const { unmount } = renderFamily(HEAD, <><CivilLookupEntry side="family" /><CivilLookupEntry side="coordinator" /></>);
    expect(document.querySelector('[data-civil-lookup-entry="family"]')).toHaveAttribute("href", "/family/civil-registry");
    expect(document.querySelector('[data-civil-lookup-entry="coordinator"]')).toBeNull();
    expect(screen.getByText("الاستعلام عن السجل المدني")).toBeInTheDocument();
    unmount();

    const second = renderFamily(COORD, <><CivilLookupEntry side="family" /><CivilLookupEntry side="coordinator" /></>);
    expect(document.querySelector('[data-civil-lookup-entry="coordinator"]')).toHaveAttribute("href", "/family/coordinator/civil-registry");
    expect(document.querySelector('[data-civil-lookup-entry="family"]')).toBeNull();
    second.unmount();

    // Absent (an older server, or closed): nothing — being a household head or a coordinator is not enough.
    renderFamily(familyUser({ coordinator: true, coordinator_space: true }), <><CivilLookupEntry side="family" /><CivilLookupEntry side="coordinator" /></>);
    expect(document.querySelector("[data-civil-lookup-entry]")).toBeNull();
  });

  it("shows a neutral notice and sends nothing when the side is not available", () => {
    get = vi.spyOn(apiClient, "get");
    post = vi.spyOn(apiClient, "post");
    renderFamily(familyUser(), <FamilyCivilRegistryPage />);
    expect(document.querySelector('[data-civil-lookup-unavailable="family"]')?.textContent).toContain("غير متاحة لهذا الحساب");
    expect(document.querySelector("[data-civil-lookup]")).toBeNull();
    expect(get).not.toHaveBeenCalled();
    expect(post).not.toHaveBeenCalled();
  });
});

describe("Family Portal lookup", () => {
  it("searches through the family mount and shows the extended controlled record with masked identifiers", async () => {
    const user = userEvent.setup();
    mockApi(FAMILY, status("FAMILY"), {
      "/search/national-id": list(),
      "/records/open": { data: { outcome: "FOUND", release_id: 1, record: extended(), quota: quota() } },
      "/links/open": { data: { outcome: "FOUND", release_id: 1, relation: "FATHER", record: extended({ fullname: "الأب التجريبي", national_id: "*****0002" }), quota: quota() } },
    });
    renderFamily(HEAD, <FamilyCivilRegistryPage />);

    expect(await findEl(() => document.querySelector('[data-civil-lookup="family"]'))).toBeInTheDocument();
    expect(document.querySelector('[data-lookup-header="family"]')?.textContent).toContain("رصيدك اليومي واحد");
    expect(document.querySelector("[data-quota-remaining]")?.textContent).toBe((3).toLocaleString("ar"));
    expect(get).toHaveBeenCalledWith(`${FAMILY}/status`);

    await user.type(screen.getByLabelText("رقم الهوية"), "900000001");
    await user.click(screen.getByRole("button", { name: "بحث" }));
    const detail = await findEl(() => document.querySelector('[data-citizen-detail][data-level="EXTENDED_CONTROLLED"]'));
    expect(post).toHaveBeenCalledWith(`${FAMILY}/search/national-id`, { national_id: "900000001" });
    expect(post).toHaveBeenCalledWith(`${FAMILY}/records/open`, { ref: "ref-1" });
    expect(detail.querySelector("[data-source-note]")?.textContent).toContain("بيانات الهوية والحالة الاجتماعية والمكان فقط");
    expect(detail.querySelector('[data-field="mainid"]')?.textContent).toBe("*****0001");
    expect(detail.querySelector('[data-field="birthplace"]')?.textContent).toBe("غير متوفر");
    // Only what the API returned is shown: no field outside the profile appears.
    expect(detail.querySelector('[data-section="social"], [data-section="reference"]')).toBeNull();
    // Identifiers are isolated left-to-right inside the RTL page.
    expect(detail.querySelector('[data-link="FATHER"] bdi')).toHaveAttribute("dir", "ltr");
    expect(document.querySelector('[data-link="HOUSEHOLD"]')?.textContent).toContain("غير مفعّل");
    expect(document.body.textContent).not.toContain("900000002");

    await user.click(screen.getByRole("button", { name: "فتح السجل" }));
    const crumb = await findEl(() => document.querySelector("[data-breadcrumb] [aria-current=page]"));
    await waitFor(() => expect(crumb.textContent).toContain("الأب التجريبي"));
    expect(post).toHaveBeenLastCalledWith(`${FAMILY}/links/open`, { ref: "link-father" });
    expect(document.querySelector("[data-breadcrumb]")).not.toBeNull();

    expect(browserStorageDump()).not.toMatch(/9000000|اسم تجريبي|ref-1|link-father/);
  });

  it("shows the disabled and access-denied states with fixed Arabic text", async () => {
    mockApi(FAMILY, status("FAMILY", { enabled: false, reason: "LOOKUP_DISABLED", daily_limit: null, remaining: 0 }));
    const first = renderFamily(HEAD, <FamilyCivilRegistryPage />);
    expect((await findEl(() => document.querySelector('[data-lookup-disabled="LOOKUP_DISABLED"]'))).textContent).toContain("غير مفعّلة حاليًا");
    expect(document.querySelector("[data-lookup-form]")).toBeNull();
    first.unmount();
    vi.restoreAllMocks();

    mockApi(FAMILY, new ApiError(403, { message: "server text", code: "LOOKUP_NOT_ALLOWED" }));
    renderFamily(HEAD, <FamilyCivilRegistryPage />);
    expect(await screen.findByText("لا تملك صلاحية استخدام الاستعلام عن السجل المدني، أو أن الخدمة غير مفعّلة لدورك.")).toBeInTheDocument();
    expect(screen.queryByText("server text")).toBeNull();
  });
});

describe("Coordinator Space lookup", () => {
  it("uses the coordinator mount, shows the same shared quota and says it does not concern the scope's families", async () => {
    const user = userEvent.setup();
    mockApi(COORDINATOR, status("COORDINATOR", { used: 4, remaining: 1 }), { "/search/name": { data: { ...list().data, outcome: "RESULTS" } } });
    renderFamily(COORD, <CoordinatorCivilRegistryPage />);

    const header = await findEl(() => document.querySelector('[data-lookup-header="coordinator"]'));
    expect(header.textContent).toContain("لا يتعلق بأسر نطاق التنسيق");
    expect(document.querySelector("[data-quota-remaining]")?.textContent).toBe((1).toLocaleString("ar"));

    await user.click(screen.getByRole("tab", { name: "الاسم الكامل" }));
    await user.type(screen.getByLabelText("الاسم الكامل أو جزء منه"), "سامي خالد");
    await user.click(screen.getByRole("button", { name: "بحث" }));
    expect(await findEl(() => document.querySelector("[data-result]"))).toBeInTheDocument();
    expect(post).toHaveBeenCalledWith(`${COORDINATOR}/search/name`, { fullname: "سامي خالد" });
    expect(post.mock.calls.every(([path]) => !String(path).startsWith(STAFF) && !String(path).startsWith(`${FAMILY}/`))).toBe(true);
  });

  it("does not open for a coordinator whose lookup is not granted, whatever the assignment", () => {
    get = vi.spyOn(apiClient, "get");
    renderFamily({ ...COORD, civil_lookup: { family: false, coordinator: false } }, <FamilyCivilLookup side="coordinator" />);
    expect(document.querySelector('[data-civil-lookup-unavailable="coordinator"]')).not.toBeNull();
    expect(get).not.toHaveBeenCalled();
  });
});
