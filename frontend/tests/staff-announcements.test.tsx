import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import NewAnnouncementPage from "@/app/(staff)/announcements/new/page";
import { StaffAnnouncementCompose } from "@/components/announcements/staff-announcement-compose";
import { StaffAnnouncementDetail } from "@/components/announcements/staff-announcement-detail";
import { StaffAnnouncementList } from "@/components/announcements/staff-announcement-list";
import { AuthContext, authValue } from "@/components/auth/auth-context";
import { SidebarNav } from "@/components/layout/sidebar-nav";
import {
  type AnnouncementDetail,
  type AnnouncementSummary,
  ANNOUNCEMENT_POLL_ERROR_BACKOFF_MS,
  announcementListPath,
  announcementPollInterval,
} from "@/lib/api/announcements";
import type { CurrentUser } from "@/lib/api/auth";
import { ApiError, apiClient } from "@/lib/api/client";
import { navItemFor, navItems } from "@/lib/navigation";
import type { PaginatedResponse } from "@/lib/types/api/family";
import { browserStorageDump, renderWithClient } from "./helpers";

// FB-NOTIFY-01C.3c: the Staff announcement screens over the FB-NOTIFY-01B.2b
// Staff API (/api/v1/notifications). Fixtures mirror AnnouncementResource;
// audiences use PUBLIC family codes (2b.8a). Synthetic data only.

const router = { push: vi.fn(), replace: vi.fn() };
const nav = { search: "", pathname: "/announcements" };
vi.mock("next/navigation", () => ({
  useRouter: () => router,
  usePathname: () => nav.pathname,
  useSearchParams: () => new URLSearchParams(nav.search),
}));

// jsdom lacks the pointer / scroll APIs Radix Select uses.
beforeAll(() => {
  Object.assign(Element.prototype, {
    hasPointerCapture: () => false,
    releasePointerCapture: () => undefined,
    scrollIntoView: () => undefined,
  });
});

beforeEach(() => {
  vi.restoreAllMocks();
  router.push.mockReset();
  router.replace.mockReset();
  nav.search = "";
  nav.pathname = "/announcements";
});

const UUID = "5b1e2c3d-4f5a-4b6c-8d7e-9f0a1b2c3d4e";
const BASE = "/api/v1/notifications";
const DETAIL = `${BASE}/${UUID}`;
const ALL_STAFF = ["announcement.view", "announcement.create", "announcement.send"];

function staff(permissions: string[] = ALL_STAFF): CurrentUser {
  return { name: "موظف تجريبي", email: "staff@example.test", role: "ADMINISTRATOR", role_label: "مدير", permissions };
}

function withAuth(ui: React.ReactElement, permissions: string[] = ALL_STAFF) {
  return renderWithClient(<AuthContext.Provider value={authValue(staff(permissions))}>{ui}</AuthContext.Provider>);
}

function summary(o: Partial<AnnouncementSummary> = {}): AnnouncementSummary {
  return {
    id: UUID,
    notice_code: "NTF-000042",
    level: "NORMAL",
    status: "DRAFT",
    title: "موعد توزيع المساعدات",
    audience_type: "STAFF_FAMILIES",
    expires_at: null,
    created_at: "2026-10-19T09:00:00+03:00",
    queued_at: null,
    published_at: null,
    recipient_count_at_publication: null,
    publish_attempts: 0,
    publish_failure_code: null,
    publish_failed_at: null,
    archived_at: null,
    cancelled_at: null,
    cancellation_reason_code: null,
    ...o,
  };
}

function detail(o: Partial<AnnouncementDetail> = {}): AnnouncementDetail {
  return {
    ...summary(),
    body: "يُرجى مراجعة مركز التوزيع.\nالسطر الثاني.",
    audience_selection: { family_ids: 2 },
    statistics_basis: null,
    statistics: null,
    available_actions: ["PUBLISH", "CANCEL"],
    ...o,
  };
}

function page<T>(rows: T[], meta: Partial<PaginatedResponse<T>["meta"]> = {}): PaginatedResponse<T> {
  return {
    data: rows,
    links: { first: null, last: null, prev: null, next: null },
    meta: { current_page: 1, from: rows.length ? 1 : null, last_page: 1, path: BASE, per_page: 20, to: rows.length || null, total: rows.length, ...meta },
  };
}

type Reply = unknown | (() => unknown);

/** GETs answered by exact path; an Error / ApiError is thrown; "pending" never settles. */
function mockGets(routes: Record<string, Reply>) {
  return vi.spyOn(apiClient, "get").mockImplementation(async (path: string) => {
    if (!(path in routes)) throw new Error(`Unexpected GET: ${path}`);
    const route = routes[path];
    const reply = typeof route === "function" ? (route as () => unknown)() : route;
    if (reply === "pending") return new Promise<never>(() => {});
    if (reply instanceof Error) throw reply;
    return reply as never;
  });
}

function refusal(status: number, code?: string, extra: Record<string, unknown> = {}) {
  return new ApiError(status, { message: "مرفوض", ...(code ? { code } : {}), ...extra });
}

// ------------------------------------------------------------- navigation

describe("navigation and route permissions", () => {
  it("shows «الإعلانات» only with announcement.view, and guards every /announcements route with it", () => {
    const item = navItems.find((i) => i.href === "/announcements");
    expect(item?.permissions).toEqual(["announcement.view"]);
    expect(navItemFor("/announcements/new")).toBe(item);
    expect(navItemFor(`/announcements/${UUID}`)).toBe(item);

    const { unmount } = withAuth(<SidebarNav />, ["announcement.view"]);
    expect(screen.getByRole("link", { name: "الإعلانات" })).toHaveAttribute("href", "/announcements");
    unmount();
    withAuth(<SidebarNav />, ["family.view"]);
    expect(screen.queryByRole("link", { name: "الإعلانات" })).toBeNull();
  });

  it("opens the compose page only with announcement.create", () => {
    const { unmount } = withAuth(<NewAnnouncementPage />, ["announcement.view"]);
    expect(screen.getByText("لا تملك صلاحية الوصول إلى هذه الصفحة.")).toBeInTheDocument();
    expect(screen.queryByRole("form", { name: "إعلان جديد" })).toBeNull();
    unmount();

    withAuth(<NewAnnouncementPage />, ["announcement.view", "announcement.create"]);
    expect(screen.getByRole("form", { name: "إعلان جديد" })).toBeInTheDocument();
  });
});

// ------------------------------------------------------------------- list

describe("the announcement list", () => {
  it("lists the sender's announcements with code, title, level, status, dates and recipients", async () => {
    mockGets({
      [BASE]: page([
        summary({ status: "PUBLISHED", level: "IMPORTANT", published_at: "2026-10-19T10:00:00+03:00", queued_at: "2026-10-19T09:30:00+03:00", recipient_count_at_publication: 12 }),
      ]),
    });
    withAuth(<StaffAnnouncementList />);

    const list = await screen.findByRole("list", { name: "إعلاناتي" });
    const row = within(list).getByRole("link");
    expect(row).toHaveAttribute("href", `/announcements/${UUID}`);
    expect(within(row).getByText("NTF-000042").tagName).toBe("BDI");
    expect(within(row).getByText("موعد توزيع المساعدات")).toBeInTheDocument();
    expect(within(row).getByText("منشور")).toBeInTheDocument();
    expect(within(row).getByText("مهم")).toBeInTheDocument();
    expect(within(row).getByText(/^نُشر/)).toBeInTheDocument();
    expect(row.querySelector("[data-announcement-recipients]")).toHaveTextContent("12");
    expect(screen.getByRole("link", { name: "إعلان جديد" })).toHaveAttribute("href", "/announcements/new");
  });

  it("hides «إعلان جديد» without announcement.create", async () => {
    mockGets({ [BASE]: page([]) });
    withAuth(<StaffAnnouncementList />, ["announcement.view"]);
    await screen.findByText("لا توجد إعلانات بعد");
    expect(screen.queryByRole("link", { name: "إعلان جديد" })).toBeNull();
  });

  it("filters by a supported status and pages in the URL; an unknown status is never sent", async () => {
    nav.search = "status=PUBLISH_FAILED&page=2";
    const get = mockGets({ [`${BASE}?status=PUBLISH_FAILED&page=2`]: page([summary({ status: "PUBLISH_FAILED" })], { current_page: 2, last_page: 3, total: 41, from: 21, to: 40 }) });
    withAuth(<StaffAnnouncementList />);

    await screen.findByText("موعد توزيع المساعدات");
    expect(get).toHaveBeenCalledWith(`${BASE}?status=PUBLISH_FAILED&page=2`);
    await userEvent.click(screen.getByRole("button", { name: "الصفحة التالية" }));
    expect(router.push).toHaveBeenCalledWith("/announcements?status=PUBLISH_FAILED&page=3", { scroll: false });

    expect(announcementListPath("staff", { status: undefined, page: 1 })).toBe(BASE);
    vi.restoreAllMocks();
    nav.search = "status=SENT";
    const unknown = mockGets({ [BASE]: page([]) });
    withAuth(<StaffAnnouncementList />);
    await waitFor(() => expect(unknown).toHaveBeenCalledWith(BASE));
  });

  it("shows loading, empty, retryable error, rate-limit and no-permission states", async () => {
    mockGets({ [BASE]: "pending" });
    const loading = withAuth(<StaffAnnouncementList />);
    expect(screen.getByRole("status")).toHaveTextContent("جارٍ تحميل الإعلانات");
    loading.unmount();

    vi.restoreAllMocks();
    let calls = 0;
    const get = mockGets({ [BASE]: () => (++calls === 1 ? refusal(500) : page([summary()])) });
    const failing = withAuth(<StaffAnnouncementList />);
    expect(await screen.findByText("تعذّر تحميل الإعلانات")).toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: "إعادة المحاولة" }));
    expect(await screen.findByText("موعد توزيع المساعدات")).toBeInTheDocument();
    expect(get).toHaveBeenCalledTimes(2);
    failing.unmount();

    vi.restoreAllMocks();
    mockGets({ [BASE]: refusal(429) });
    const limited = withAuth(<StaffAnnouncementList />);
    expect(await screen.findByText("محاولات كثيرة خلال وقت قصير. حاول بعد قليل.")).toBeInTheDocument();
    limited.unmount();

    vi.restoreAllMocks();
    mockGets({ [BASE]: refusal(403) });
    withAuth(<StaffAnnouncementList />);
    expect(await screen.findByText("لا تملك صلاحية عرض الإعلانات.")).toBeInTheDocument();
  });
});

// ---------------------------------------------------------------- compose

const FAMILY_SEARCH = (term: string) => `/api/v1/families?search=${encodeURIComponent(term)}&status=ACTIVE`;

function familyResults(codes: string[]) {
  return {
    ...page(codes.map((code, i) => ({ family_code: code, status: "ACTIVE", household_head_name: `رب أسرة ${i + 1}`, clan_name: null, branch_name: null, member_count: 1, declared_household_size: null, registration_date: null, updated_at: null }))),
    summary: { total: codes.length, active: codes.length, inactive: 0, archived: 0 },
  };
}

async function fillContent(title = "إعلان تجريبي", body = "نص الإعلان التجريبي.") {
  await userEvent.type(screen.getByLabelText("العنوان"), title);
  await userEvent.type(screen.getByLabelText("النص"), body);
}

async function pickFamily(code: string) {
  await userEvent.type(screen.getByLabelText("البحث عن أسرة"), "FAM");
  await userEvent.click(await screen.findByRole("button", { name: `إضافة ${code}` }, { timeout: 3000 }));
}

describe("composing a draft", () => {
  it("picks families by public code, previews on the server and saves one draft with a client_reference", async () => {
    mockGets({ [FAMILY_SEARCH("FAM")]: familyResults(["FAM-000101", "FAM-000102"]) });
    const post = vi.spyOn(apiClient, "post").mockImplementation(async (path: string) => {
      if (path === `${BASE}/audience-preview`) return { data: { recipient_count: 2 } } as never;
      if (path === BASE) return { data: detail(), meta: { replayed: false } } as never;
      throw new Error(`Unexpected POST: ${path}`);
    });
    withAuth(<StaffAnnouncementCompose />);

    await fillContent();
    await pickFamily("FAM-000101");
    await userEvent.click(screen.getByRole("button", { name: "إضافة FAM-000102" }));
    expect(within(screen.getByRole("list", { name: "الأسر المختارة" })).getAllByRole("listitem")).toHaveLength(2);
    expect(screen.getByRole("button", { name: "حفظ المسودة" })).toBeDisabled();

    await userEvent.click(screen.getByRole("button", { name: "احسب عدد المستلمين" }));
    expect(await screen.findByText(/عدد الأسر المستلمة الآن/)).toHaveTextContent("2");
    expect(post).toHaveBeenCalledWith(`${BASE}/audience-preview`, { audience: { type: "STAFF_FAMILIES", family_codes: ["FAM-000101", "FAM-000102"] } });

    await userEvent.click(screen.getByRole("button", { name: "حفظ المسودة" }));
    await waitFor(() => expect(router.push).toHaveBeenCalledWith(`/announcements/${UUID}`));
    const [, draft] = post.mock.calls.find(([path]) => path === BASE)!;
    expect(draft).toEqual({
      title: "إعلان تجريبي",
      body: "نص الإعلان التجريبي.",
      level: "NORMAL",
      audience: { type: "STAFF_FAMILIES", family_codes: ["FAM-000101", "FAM-000102"] },
      expires_at: null,
      client_reference: expect.stringMatching(/^[0-9a-f-]{36}$/),
    });
    // Never an internal identifier in any request, and nothing kept in browser storage.
    expect(JSON.stringify(post.mock.calls)).not.toMatch(/family_ids|branch_ids|branch_group_ids|clan_ids/);
    expect(browserStorageDump()).not.toMatch(/إعلان تجريبي|FAM-000101/);
  });

  it("offers every ACTIVE family only with announcement.send-all", async () => {
    const { unmount } = withAuth(<StaffAnnouncementCompose />);
    expect(screen.queryByLabelText("جميع الأسر النشطة")).toBeNull();
    // Never a Coordinator audience on the Staff side.
    expect(screen.queryByLabelText(/نطاقي/)).toBeNull();
    unmount();

    const post = vi.spyOn(apiClient, "post").mockResolvedValue({ data: { recipient_count: 340 } } as never);
    withAuth(<StaffAnnouncementCompose />, [...ALL_STAFF, "announcement.send-all"]);
    await userEvent.click(screen.getByLabelText("جميع الأسر النشطة"));
    expect(screen.queryByLabelText("البحث عن أسرة")).toBeNull();
    await userEvent.click(screen.getByRole("button", { name: "احسب عدد المستلمين" }));
    expect(await screen.findByText(/عدد الأسر المستلمة الآن/)).toHaveTextContent("340");
    expect(post).toHaveBeenCalledWith(`${BASE}/audience-preview`, { audience: { type: "STAFF_ALL_ACTIVE" } });
  });

  it("invalidates the preview when the audience changes, and refuses an empty or ineligible audience", async () => {
    mockGets({ [FAMILY_SEARCH("FAM")]: familyResults(["FAM-000101"]) });
    const post = vi.spyOn(apiClient, "post").mockResolvedValueOnce({ data: { recipient_count: 1 } } as never);
    withAuth(<StaffAnnouncementCompose />);

    await userEvent.click(screen.getByRole("button", { name: "احسب عدد المستلمين" }));
    expect(screen.getByRole("alert")).toHaveTextContent("اختر أسرة واحدة على الأقل.");
    expect(post).not.toHaveBeenCalled();

    await fillContent();
    await pickFamily("FAM-000101");
    await userEvent.click(screen.getByRole("button", { name: "احسب عدد المستلمين" }));
    await screen.findByText(/عدد الأسر المستلمة الآن/);
    expect(screen.getByRole("button", { name: "حفظ المسودة" })).toBeEnabled();

    await userEvent.click(screen.getByRole("button", { name: "إزالة FAM-000101" }));
    expect(screen.getByText("تغيّر تحديد المستلمين. احسب العدد من جديد.")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "حفظ المسودة" })).toBeDisabled();

    await pickFamily("FAM-000101");
    post.mockResolvedValueOnce({ data: { recipient_count: 0 } } as never);
    await userEvent.click(screen.getByRole("button", { name: "احسب عدد المستلمين" }));
    expect(await screen.findByText("لا توجد أسر مستلمة ضمن هذا التحديد.")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "حفظ المسودة" })).toBeDisabled();

    post.mockRejectedValueOnce(refusal(422, "NOTIFICATION_AUDIENCE_FAMILY_NOT_ELIGIBLE"));
    await userEvent.click(screen.getByRole("button", { name: "احسب عدد المستلمين" }));
    expect(await screen.findByText("إحدى الأسر المحددة غير متاحة للإرسال. راجع الأسر المختارة.")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "حفظ المسودة" })).toBeDisabled();
  });

  it("re-sends the same client_reference for an unchanged payload, a new one after a change or a conflict, and opens the replayed draft", async () => {
    mockGets({ [FAMILY_SEARCH("FAM")]: familyResults(["FAM-000101"]) });
    const drafts: { client_reference: string; title: string }[] = [];
    let replies: unknown[] = [];
    vi.spyOn(apiClient, "post").mockImplementation(async (path: string, body: unknown) => {
      if (path === `${BASE}/audience-preview`) return { data: { recipient_count: 1 } } as never;
      drafts.push(body as { client_reference: string; title: string });
      const reply = replies.shift();
      if (reply instanceof Error) throw reply;
      return reply as never;
    });
    withAuth(<StaffAnnouncementCompose />);
    await fillContent();
    await pickFamily("FAM-000101");
    await userEvent.click(screen.getByRole("button", { name: "احسب عدد المستلمين" }));
    await screen.findByText(/عدد الأسر المستلمة الآن/);
    const save = () => userEvent.click(screen.getByRole("button", { name: "حفظ المسودة" }));

    replies = [new TypeError("network"), new TypeError("network")];
    await save();
    expect(await screen.findByText("تعذّر الاتصال. تحقق من الإنترنت وحاول مرة أخرى.")).toBeInTheDocument();
    await save();
    await waitFor(() => expect(drafts).toHaveLength(2));
    expect(drafts[1].client_reference).toBe(drafts[0].client_reference);

    // A changed payload never reuses the uncertain reference.
    await userEvent.type(screen.getByLabelText("العنوان"), " معدّل");
    replies = [refusal(409, "NOTIFICATION_IDEMPOTENCY_CONFLICT")];
    await save();
    await waitFor(() => expect(drafts).toHaveLength(3));
    expect(drafts[2].client_reference).not.toBe(drafts[0].client_reference);
    expect(await screen.findByText("رقم مرجع هذه المسودة استُخدم لمحتوى مختلف. راجع البيانات واحفظ مجددًا.")).toBeInTheDocument();

    // After a conflict the next attempt gets a fresh reference; a 200 replay opens the draft.
    replies = [{ data: detail(), meta: { replayed: true } }];
    await save();
    await waitFor(() => expect(router.push).toHaveBeenCalledWith(`/announcements/${UUID}`));
    expect(drafts[3].client_reference).not.toBe(drafts[2].client_reference);
    expect(router.push).toHaveBeenCalledTimes(1);
  });

  it("validates lengths client-side and maps server field errors", async () => {
    mockGets({ [FAMILY_SEARCH("FAM")]: familyResults(["FAM-000101"]) });
    vi.spyOn(apiClient, "post").mockImplementation(async (path: string) => {
      if (path === `${BASE}/audience-preview`) return { data: { recipient_count: 1 } } as never;
      throw refusal(422, undefined, { errors: { title: ["العنوان غير صالح من الخادم."] } });
    });
    withAuth(<StaffAnnouncementCompose />);

    await pickFamily("FAM-000101");
    await userEvent.click(screen.getByRole("button", { name: "احسب عدد المستلمين" }));
    await screen.findByText(/عدد الأسر المستلمة الآن/);
    await userEvent.click(screen.getByRole("button", { name: "حفظ المسودة" }));
    expect(await screen.findByText("عنوان الإعلان مطلوب")).toBeInTheDocument();
    expect(screen.getByText("نص الإعلان مطلوب")).toBeInTheDocument();

    await fillContent("   عنوان   ", "نص");
    await userEvent.click(screen.getByRole("button", { name: "حفظ المسودة" }));
    expect(await screen.findByText("العنوان غير صالح من الخادم.")).toBeInTheDocument();
    expect(document.querySelector("[data-announcement-submit-error]")).toHaveTextContent("تحقق من البيانات المدخلة.");
    expect(router.push).not.toHaveBeenCalled();
  });
});

// ----------------------------------------------------------------- detail

describe("an announcement's details", () => {
  it("shows content as plain text, counts only, lifecycle and statistics with their basis", async () => {
    mockGets({
      [DETAIL]: {
        data: detail({
          status: "PUBLISHED",
          level: "IMPORTANT",
          body: "<b>عريض</b>\nسطر",
          published_at: "2026-10-19T10:00:00+03:00",
          recipient_count_at_publication: 2,
          publish_attempts: 1,
          available_actions: [],
          statistics_basis: "ALL_RECIPIENTS",
          statistics: { recipients: 2, with_account: 1, without_account: 1, viewed: 1, not_viewed: 1, acknowledged: 1, acknowledged_late: 0, pending_acknowledgement: 1 },
        }),
      },
    });
    withAuth(<StaffAnnouncementDetail id={UUID} />);

    expect(await screen.findByRole("heading", { level: 1, name: "موعد توزيع المساعدات" })).toBeInTheDocument();
    const body = screen.getByText(/<b>عريض<\/b>/);
    expect(body).toHaveAttribute("dir", "auto");
    expect(body.className).toContain("whitespace-pre-line");
    expect(body.querySelector("b")).toBeNull();
    expect(screen.getByText("NTF-000042").tagName).toBe("BDI");
    expect(screen.getByText("أسر محددة", { selector: "dt" }).nextElementSibling).toHaveTextContent("2");
    expect(screen.getByText("كل مستلمي الإعلان")).toBeInTheDocument();
    expect(screen.getByText("أسر بلا حساب مفعّل").nextElementSibling).toHaveTextContent("1");
    expect(document.body.textContent).not.toContain("family_ids");
    expect(screen.queryByRole("button", { name: "طلب النشر" })).toBeNull();
  });

  it("offers an action only when the state lists it AND the account holds the permission", async () => {
    mockGets({ [DETAIL]: { data: detail() } });
    const viewOnly = withAuth(<StaffAnnouncementDetail id={UUID} />, ["announcement.view", "announcement.create"]);
    await screen.findByText("NTF-000042");
    expect(screen.getByRole("button", { name: "إلغاء الإعلان" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "طلب النشر" })).toBeNull();
    expect(screen.getByText("لا تملك صلاحية طلب نشر الإعلانات.")).toBeInTheDocument();
    viewOnly.unmount();

    withAuth(<StaffAnnouncementDetail id={UUID} />);
    await screen.findByText("NTF-000042");
    expect(screen.getByRole("button", { name: "طلب النشر" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "إلغاء الإعلان" })).toBeInTheDocument();
  });

  it("publishes after confirmation, once, and treats 202 as queued — never published", async () => {
    let current = detail();
    vi.spyOn(apiClient, "get").mockImplementation(async () => ({ data: current }) as never);
    let resolve: (v: unknown) => void = () => undefined;
    const post = vi.spyOn(apiClient, "post").mockImplementation(() => new Promise((r) => (resolve = r)) as never);
    withAuth(<StaffAnnouncementDetail id={UUID} />);

    await userEvent.click(await screen.findByRole("button", { name: "طلب النشر" }));
    const dialog = await screen.findByRole("dialog");
    expect(post).not.toHaveBeenCalled();
    const confirm = within(dialog).getByRole("button", { name: "طلب النشر" });
    await userEvent.click(confirm);
    await userEvent.click(confirm);
    expect(post).toHaveBeenCalledTimes(1);
    expect(post).toHaveBeenCalledWith(`${DETAIL}/publish`, {});

    current = detail({ status: "QUEUED", queued_at: new Date().toISOString(), available_actions: ["CANCEL"] });
    resolve({ data: current, meta: { replayed: false } });
    expect(await screen.findByText("طُلب نشر الإعلان وهو الآن في انتظار النشر. لم يُنشر بعد.")).toBeInTheDocument();
    expect(document.querySelector("[data-announcement-publishing]")).toHaveTextContent("في انتظار النشر");
    expect(screen.queryByText("منشور")).toBeNull();
  });

  it("keeps the draft on 503 with a clear explanation and never retries it automatically", async () => {
    mockGets({ [DETAIL]: { data: detail() } });
    const post = vi.spyOn(apiClient, "post").mockRejectedValue(refusal(503, "NOTIFICATION_PUBLICATION_UNAVAILABLE"));
    withAuth(<StaffAnnouncementDetail id={UUID} />);

    await userEvent.click(await screen.findByRole("button", { name: "طلب النشر" }));
    const dialog = await screen.findByRole("dialog");
    await userEvent.click(within(dialog).getByRole("button", { name: "طلب النشر" }));
    const message = await within(dialog).findByText(/نشر الإشعارات غير متاح حاليًا/);
    expect(message).toHaveTextContent("ولم يُنفَّذ هذا الطلب. لم تتغير حالة الإعلان");
    // Never a claim about earlier deliveries: a 503 can also answer a stale page.
    expect(message.textContent).not.toMatch(/أي أسرة|لم يُرسل/);
    await new Promise((r) => setTimeout(r, 100));
    expect(post).toHaveBeenCalledTimes(1);
    expect(document.querySelector("[data-announcement-status='DRAFT']")).not.toBeNull();
  });

  it("polls progressively while queued, backs off after a failed read, and stops after the window", () => {
    const now = Date.parse("2026-10-19T12:00:00Z");
    const queuedAgo = (ms: number) => detail({ status: "QUEUED", queued_at: new Date(now - ms).toISOString() });
    expect(announcementPollInterval(queuedAgo(0), now)).toBe(5000);
    expect(announcementPollInterval(queuedAgo(119_000), now)).toBe(5000);
    expect(announcementPollInterval(queuedAgo(2 * 60_000), now)).toBe(15000);
    expect(announcementPollInterval(queuedAgo(5 * 60_000), now)).toBe(30000);
    expect(announcementPollInterval(queuedAgo(15 * 60_000), now)).toBe(false);
    // Clock skew (queued_at ahead of this clock) counts as just queued; no queued_at as well.
    expect(announcementPollInterval(queuedAgo(-60_000), now)).toBe(5000);
    expect(announcementPollInterval(detail({ status: "PUBLISHING", queued_at: null }), now)).toBe(5000);
    // A failed read (a 429 included) waits at least a minute; never faster than the tier.
    expect(announcementPollInterval(queuedAgo(0), now, true)).toBe(ANNOUNCEMENT_POLL_ERROR_BACKOFF_MS);
    expect(announcementPollInterval(queuedAgo(14 * 60_000), now, true)).toBe(ANNOUNCEMENT_POLL_ERROR_BACKOFF_MS);
    expect(announcementPollInterval(queuedAgo(16 * 60_000), now, true)).toBe(false);
    for (const status of ["DRAFT", "PUBLISHED", "PUBLISH_FAILED", "CANCELLED", "ARCHIVED"] as const) {
      expect(announcementPollInterval(detail({ status }), now)).toBe(false);
    }
    expect(announcementPollInterval(undefined, now)).toBe(false);
  });

  it("shows PUBLISHED only once the server says so, then stops polling", async () => {
    const replies = [
      detail({ status: "QUEUED", queued_at: new Date().toISOString(), available_actions: ["CANCEL"] }),
      detail({ status: "PUBLISHED", published_at: new Date().toISOString(), available_actions: [] }),
    ];
    const get = vi.spyOn(apiClient, "get").mockImplementation(async () => ({ data: replies.length > 1 ? replies.shift() : replies[0] }) as never);
    const { unmount } = withAuth(<StaffAnnouncementDetail id={UUID} />);
    expect(await screen.findByText("في انتظار النشر", { selector: "[data-announcement-publishing] *" })).toBeInTheDocument();
    expect(screen.queryByText("منشور")).toBeNull();
    expect(await screen.findByText("منشور", {}, { timeout: 8000 })).toBeInTheDocument();
    expect(document.querySelector("[data-announcement-publishing]")).toBeNull();
    // Terminal: a whole first-tier interval later, nothing more was requested.
    const calls = get.mock.calls.length;
    await new Promise((r) => setTimeout(r, 5500));
    expect(get.mock.calls.length).toBe(calls);
    unmount();
  }, 25000);

  it("backs off after a rate-limited poll instead of retrying every few seconds", async () => {
    const queued = detail({ status: "QUEUED", queued_at: new Date().toISOString(), available_actions: ["CANCEL"] });
    let calls = 0;
    const get = vi.spyOn(apiClient, "get").mockImplementation(async () => {
      calls += 1;
      if (calls === 2) throw refusal(429);
      return { data: queued } as never;
    });
    const { unmount } = withAuth(<StaffAnnouncementDetail id={UUID} />);

    await screen.findByText("في انتظار النشر", { selector: "[data-announcement-publishing] *" });
    await waitFor(() => expect(get).toHaveBeenCalledTimes(2), { timeout: 8000 });
    // The 429 keeps the last server state on screen and waits a minute, not 5 s.
    await new Promise((r) => setTimeout(r, 6000));
    expect(get).toHaveBeenCalledTimes(2);
    expect(document.querySelector("[data-announcement-status='QUEUED']")).not.toBeNull();
    unmount();
  }, 25000);

  it("offers a manual refresh once the polling window has ended, and polls nothing more", async () => {
    const stale = detail({ status: "QUEUED", queued_at: new Date(Date.now() - 20 * 60_000).toISOString(), available_actions: ["CANCEL"] });
    const get = mockGets({ [DETAIL]: { data: stale } });
    withAuth(<StaffAnnouncementDetail id={UUID} />);

    const banner = await screen.findByText("لم يصل الإعلان إلى الأسر بعد، وتوقف التحديث التلقائي. حدّث الحالة يدويًا.");
    expect(banner.closest("[data-announcement-publishing]")).toHaveAttribute("data-announcement-publishing", "stopped");
    expect(get).toHaveBeenCalledTimes(1);
    await userEvent.click(screen.getByRole("button", { name: "تحديث الحالة" }));
    await waitFor(() => expect(get).toHaveBeenCalledTimes(2));
  });

  it("retries a failed publication and explains a refused retry", async () => {
    const get = mockGets({
      [DETAIL]: { data: detail({ status: "PUBLISH_FAILED", publish_failure_code: "PUBLICATION_ERROR", publish_failed_at: "2026-10-19T11:00:00+03:00", publish_attempts: 3, available_actions: ["CANCEL", "RETRY"] }) },
    });
    vi.spyOn(apiClient, "post").mockRejectedValue(refusal(409, "NOTIFICATION_RETRY_LIMIT_REACHED"));
    withAuth(<StaffAnnouncementDetail id={UUID} />);

    expect(await screen.findByText(/يعيد النظام المحاولة تلقائيًا/)).toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: "إعادة محاولة النشر" }));
    const dialog = await screen.findByRole("dialog");
    await userEvent.click(within(dialog).getByRole("button", { name: "إعادة المحاولة" }));
    expect(await within(dialog).findByText("تم بلوغ الحد الأقصى لمحاولات النشر، ويمكن إلغاء الإعلان.")).toBeInTheDocument();
    await waitFor(() => expect(get.mock.calls.length).toBeGreaterThanOrEqual(2));
  });

  it("cancels without a reason code and reports a replayed cancellation", async () => {
    mockGets({ [DETAIL]: { data: detail() } });
    const post = vi.spyOn(apiClient, "post").mockResolvedValue({ data: detail({ status: "CANCELLED", available_actions: [] }), meta: { replayed: true } } as never);
    withAuth(<StaffAnnouncementDetail id={UUID} />);

    await userEvent.click(await screen.findByRole("button", { name: "إلغاء الإعلان" }));
    await userEvent.click(within(await screen.findByRole("dialog")).getByRole("button", { name: "إلغاء الإعلان" }));
    expect(await screen.findByText("لم يتغير شيء: سبق إلغاء هذا الإعلان.")).toBeInTheDocument();
    expect(post).toHaveBeenCalledWith(`${DETAIL}/cancel`, {});
  });

  it("shows another sender's or a missing announcement as unavailable", async () => {
    mockGets({ [DETAIL]: refusal(404, "NOTIFICATION_NOT_FOUND") });
    withAuth(<StaffAnnouncementDetail id={UUID} />);
    expect(await screen.findByRole("alert")).toHaveTextContent("هذا الإعلان غير متاح.");
    expect(screen.getByRole("link", { name: "العودة إلى الإعلانات" })).toHaveAttribute("href", "/announcements");
  });

  it("explains permission and rate-limit refusals of an action", async () => {
    mockGets({ [DETAIL]: { data: detail() } });
    vi.spyOn(apiClient, "post").mockRejectedValueOnce(refusal(403, "NOTIFICATION_AUDIENCE_NOT_AUTHORIZED")).mockRejectedValueOnce(refusal(429));
    withAuth(<StaffAnnouncementDetail id={UUID} />);

    await userEvent.click(await screen.findByRole("button", { name: "طلب النشر" }));
    const dialog = await screen.findByRole("dialog");
    await userEvent.click(within(dialog).getByRole("button", { name: "طلب النشر" }));
    expect(await within(dialog).findByText("لا تملك صلاحية الإرسال إلى هذه الفئة من الأسر.")).toBeInTheDocument();
    await userEvent.click(within(dialog).getByRole("button", { name: "طلب النشر" }));
    expect(await within(dialog).findByText("محاولات كثيرة خلال وقت قصير. حاول بعد قليل.")).toBeInTheDocument();
  });
});
