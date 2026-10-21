import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import CoordinatorAnnouncementsLayout from "@/app/family/(portal)/coordinator/announcements/layout";
import CoordinatorSpaceLayout from "@/app/family/(portal)/coordinator/(space)/layout";
import { AccountView } from "@/components/family/account/account-view";
import { CoordinatorAnnouncementCompose } from "@/components/family/coordinator/announcements/coordinator-announcement-compose";
import { CoordinatorAnnouncementDetail, coordinatorActions } from "@/components/family/coordinator/announcements/coordinator-announcement-detail";
import { CoordinatorAnnouncementList } from "@/components/family/coordinator/announcements/coordinator-announcement-list";
import { CoordinatorHome } from "@/components/family/coordinator/coordinator-home";
import { CoordinatorGate } from "@/components/family/coordinator/coordinator-gate";
import { FamilyUserContext } from "@/components/family/family-context";
import { FamilyGate } from "@/components/family/family-gate";
import { FamilyShell } from "@/components/family/family-shell";
import { type AnnouncementDetail, type AnnouncementSummary, announcementBase, announcementKeys } from "@/lib/api/announcements";
import { ApiError, apiClient } from "@/lib/api/client";
import { FAMILY_ME_QUERY_KEY, type FamilyUser } from "@/lib/api/family-auth";
import type { PaginatedResponse } from "@/lib/types/api/family";
import { browserStorageDump, familyGet, familyUser, renderWithClient } from "./helpers";

// FB-NOTIFY-01C.3d: the Coordinator announcement screens over the
// FB-NOTIFY-01B.2b.6c Coordinator API (/api/v1/family/coordinator/notifications)
// and the shared announcement components. History is role-only; composing
// needs the current scope. Synthetic data only.

const router = { push: vi.fn(), replace: vi.fn() };
const nav = { search: "", pathname: "/family/coordinator/announcements" };
vi.mock("next/navigation", () => ({
  useRouter: () => router,
  usePathname: () => nav.pathname,
  useSearchParams: () => new URLSearchParams(nav.search),
}));
vi.mock("next/link", () => ({
  default: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...props}>
      {children}
    </a>
  ),
}));

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
  nav.pathname = "/family/coordinator/announcements";
});

const UUID = "5b1e2c3d-4f5a-4b6c-8d7e-9f0a1b2c3d4e";
const BASE = "/api/v1/family/coordinator/notifications";
const DETAIL = `${BASE}/${UUID}`;
const DIRECTORY = (q: string) => `/api/v1/family/coordinator/families?page=1&q=${encodeURIComponent(q)}`;

// Pilot isolation (ADR §2h): these coordinators are shown their notification screens by the server.
const ACTIVE: FamilyUser = familyUser({ roles: ["FAMILY_USER", "COORDINATOR"], coordinator: true, coordinator_space: true, coordinator_notifications: true });
const REVOKED: FamilyUser = familyUser({ roles: ["FAMILY_USER", "COORDINATOR"], coordinator: true, coordinator_space: false, coordinator_notifications: true });
const NOT_IN_PILOT: FamilyUser = familyUser({ roles: ["FAMILY_USER", "COORDINATOR"], coordinator: true, coordinator_space: true, coordinator_notifications: false });
const HEAD_ONLY: FamilyUser = familyUser();
const CONTEXT = { scopes: [{ type: "BRANCH", code: "BR_A", name: "فرع أ", clan_name: "عشيرة الاختبار" }], family_count: 3 };

function summary(o: Partial<AnnouncementSummary> = {}): AnnouncementSummary {
  return {
    id: UUID,
    notice_code: "NTF-000077",
    level: "NORMAL",
    status: "DRAFT",
    title: "اجتماع أسر الفرع",
    audience_type: "COORDINATOR_SCOPE",
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
  return { ...summary(), body: "نلتقي يوم الخميس.\nالحضور مهم.", audience_selection: [], statistics_basis: null, statistics: null, available_actions: ["PUBLISH", "CANCEL"], ...o };
}

function page<T>(rows: T[], meta: Partial<PaginatedResponse<T>["meta"]> = {}): PaginatedResponse<T> {
  return {
    data: rows,
    links: { first: null, last: null, prev: null, next: null },
    meta: { current_page: 1, from: rows.length ? 1 : null, last_page: 1, path: BASE, per_page: 20, to: rows.length || null, total: rows.length, ...meta },
  };
}

function mockGets(routes: Record<string, unknown>) {
  return vi.spyOn(apiClient, "get").mockImplementation(async (path: string) => {
    if (!(path in routes)) throw new Error(`Unexpected GET: ${path}`);
    const route = routes[path];
    const reply = typeof route === "function" ? (route as () => unknown)() : route;
    if (reply === "pending") return new Promise<never>(() => {});
    if (reply instanceof Error) throw reply;
    return reply as never;
  });
}

const refusal = (status: number, code?: string, extra: Record<string, unknown> = {}) =>
  new ApiError(status, { message: "مرفوض", ...(code ? { code } : {}), ...extra });

function asUser(user: FamilyUser, ui: React.ReactElement) {
  const rendered = renderWithClient(<FamilyUserContext.Provider value={user}>{ui}</FamilyUserContext.Provider>);
  rendered.client.setQueryData(FAMILY_ME_QUERY_KEY, user);
  return rendered;
}

/** Every request path and body, for isolation and leakage checks. */
function traffic(): string {
  const get = vi.mocked(apiClient.get).mock?.calls ?? [];
  const post = vi.isMockFunction(apiClient.post) ? vi.mocked(apiClient.post).mock.calls : [];
  return JSON.stringify([get, post]);
}

// ----------------------------------------------------------------- guards

describe("route guards", () => {
  it("opens the history for a coordinator WITHOUT a current scope, without asking the coordinator context", async () => {
    const get = mockGets({ [BASE]: page([summary()]) });
    asUser(REVOKED, (
      <CoordinatorAnnouncementsLayout>
        <CoordinatorAnnouncementList />
      </CoordinatorAnnouncementsLayout>
    ));

    expect(await screen.findByText("اجتماع أسر الفرع")).toBeInTheDocument();
    expect(screen.getByText(/لا يوجد لديك نطاق تنسيق حالي/)).toBeInTheDocument();
    expect(screen.queryByRole("link", { name: "إعلان جديد" })).toBeNull();
    expect(get.mock.calls.map(([p]) => p)).toEqual([BASE]);
  });

  it("refuses the history to a household head without the COORDINATOR role, asking nothing", () => {
    const get = vi.spyOn(apiClient, "get");
    asUser(HEAD_ONLY, (
      <CoordinatorAnnouncementsLayout>
        <CoordinatorAnnouncementList />
      </CoordinatorAnnouncementsLayout>
    ));
    expect(screen.getByRole("alert")).toHaveTextContent("هذه الصفحة متاحة لمنسقي العائلات فقط.");
    expect(get).not.toHaveBeenCalled();
  });

  it("keeps composing behind the current scope: a revoked coordinator never reaches the form", async () => {
    mockGets({ "/api/v1/family/coordinator/context": refusal(403, "COORDINATOR_SPACE_UNAVAILABLE") });
    asUser(REVOKED, (
      <CoordinatorSpaceLayout>
        <CoordinatorAnnouncementCompose />
      </CoordinatorSpaceLayout>
    ));
    expect(await screen.findByText("مساحة التنسيق غير متاحة لهذا الحساب.")).toBeInTheDocument();
    expect(screen.queryByRole("form", { name: "إعلان جديد" })).toBeNull();
  });

  it("stays behind FamilyGate: without a session the Family login opens", async () => {
    vi.spyOn(apiClient, "get").mockRejectedValue(new ApiError(401, null));
    renderWithClient(
      <FamilyGate>
        <CoordinatorAnnouncementsLayout>
          <CoordinatorAnnouncementList />
        </CoordinatorAnnouncementsLayout>
      </FamilyGate>
    );
    await waitFor(() => expect(router.replace).toHaveBeenCalledWith("/family/login"));
    expect(screen.queryByText("إعلاناتي")).toBeNull();
  });
});

// ------------------------------------------------------------- navigation

describe("entry points", () => {
  it("links «الإعلانات» from active Coordinator Space", async () => {
    mockGets({ "/api/v1/family/coordinator/context": { data: CONTEXT }, "/api/v1/family/coordinator/families?page=1": { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } } });
    asUser(ACTIVE, (
      <CoordinatorGate>
        <CoordinatorHome />
      </CoordinatorGate>
    ));
    expect(await screen.findByRole("link", { name: /الإعلانات/ })).toHaveAttribute("href", "/family/coordinator/announcements");
  });

  it("offers «إعلاناتي» in حسابي to a coordinator with or without a current scope, and to nobody else", async () => {
    for (const [user, shown] of [[ACTIVE, true], [REVOKED, true], [NOT_IN_PILOT, false], [HEAD_ONLY, false]] as const) {
      vi.restoreAllMocks();
      vi.spyOn(apiClient, "get").mockImplementation(familyGet({ user, coordinator: CONTEXT }));
      const { unmount } = renderWithClient(
        <FamilyGate>
          <AccountView />
        </FamilyGate>
      );
      await screen.findByRole("heading", { name: "حسابي" });
      const link = document.querySelector("[data-account-coordinator-announcements]");
      if (shown) expect(link).toHaveAttribute("href", "/family/coordinator/announcements");
      else expect(link).toBeNull();
      unmount();
    }
  });

  it("shows no «الإعلانات» card to a coordinator the server does not show notifications to (pilot isolation)", async () => {
    mockGets({ "/api/v1/family/coordinator/context": { data: CONTEXT }, "/api/v1/family/coordinator/families?page=1": { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } } });
    asUser(NOT_IN_PILOT, (
      <CoordinatorGate>
        <CoordinatorHome />
      </CoordinatorGate>
    ));
    expect(await screen.findByRole("link", { name: /طلبات التنسيق/ })).toBeInTheDocument();
    expect(document.querySelector("[data-coordinator-announcements-link]")).toBeNull();
    expect(document.querySelector("[data-coordinator-followups-link]")).toBeNull();
  });

  it("adds no bottom navigation item", async () => {
    nav.pathname = "/family/account";
    mockGets({ "/api/v1/family/notifications": refusal(404), "/api/v1/family/change-requests/types": { data: [], meta: { submission_enabled: false } } });
    asUser(REVOKED, <FamilyShell>محتوى</FamilyShell>);
    const bottom = screen.getByRole("navigation", { name: "التنقل الرئيسي" });
    expect(within(bottom).getAllByRole("listitem")).toHaveLength(5);
    expect(within(bottom).queryByText(/الإعلانات|إعلاناتي/)).toBeNull();
  });
});

// -------------------------------------------------------------------- list

describe("the announcement history", () => {
  it("lists own announcements as cards and offers «إعلان جديد» only with a current scope", async () => {
    mockGets({ [BASE]: page([summary({ status: "PUBLISHED", level: "IMPORTANT", published_at: "2026-10-19T10:00:00+03:00", recipient_count_at_publication: 3 })]) });
    asUser(ACTIVE, <CoordinatorAnnouncementList />);

    const list = await screen.findByRole("list", { name: "إعلاناتي" });
    const card = within(list).getByRole("link");
    expect(card).toHaveAttribute("href", `/family/coordinator/announcements/${UUID}`);
    expect(within(card).getByText("NTF-000077").tagName).toBe("BDI");
    expect(within(card).getByText("منشور")).toBeInTheDocument();
    expect(within(card).getByText("مهم")).toBeInTheDocument();
    expect(card.querySelector("[data-announcement-recipients]")).toHaveTextContent("3");
    expect(screen.getByRole("link", { name: "إعلان جديد" })).toHaveAttribute("href", "/family/coordinator/announcements/new");
    expect(screen.queryByText(/لا يوجد لديك نطاق تنسيق حالي/)).toBeNull();
  });

  it("filters and pages in the URL with the supported status only", async () => {
    nav.search = "status=PUBLISH_FAILED&page=2";
    const get = mockGets({ [`${BASE}?status=PUBLISH_FAILED&page=2`]: page([summary({ status: "PUBLISH_FAILED" })], { current_page: 2, last_page: 3 }) });
    asUser(ACTIVE, <CoordinatorAnnouncementList />);
    await screen.findByText("اجتماع أسر الفرع");
    expect(get).toHaveBeenCalledWith(`${BASE}?status=PUBLISH_FAILED&page=2`);
    await userEvent.click(screen.getByRole("button", { name: /التالي/ }));
    expect(router.push).toHaveBeenCalledWith("/family/coordinator/announcements?status=PUBLISH_FAILED&page=3", { scroll: false });
  });

  it("shows loading, empty, retryable error, 403 and 429 states", async () => {
    mockGets({ [BASE]: "pending" });
    const loading = asUser(ACTIVE, <CoordinatorAnnouncementList />);
    expect(screen.getByRole("status")).toHaveTextContent("جارٍ تحميل الإعلانات");
    loading.unmount();

    vi.restoreAllMocks();
    mockGets({ [BASE]: page([]) });
    const empty = asUser(ACTIVE, <CoordinatorAnnouncementList />);
    expect(await screen.findByText("لا توجد إعلانات بعد")).toBeInTheDocument();
    empty.unmount();

    vi.restoreAllMocks();
    let calls = 0;
    mockGets({ [BASE]: () => (++calls === 1 ? refusal(500) : page([summary()])) });
    const failing = asUser(ACTIVE, <CoordinatorAnnouncementList />);
    await screen.findByRole("alert");
    await userEvent.click(screen.getByRole("button", { name: "إعادة المحاولة" }));
    expect(await screen.findByText("اجتماع أسر الفرع")).toBeInTheDocument();
    failing.unmount();

    vi.restoreAllMocks();
    mockGets({ [BASE]: refusal(403) });
    const forbidden = asUser(REVOKED, <CoordinatorAnnouncementList />);
    expect(await screen.findByRole("alert")).toHaveTextContent("سجل الإعلانات غير متاح لهذا الحساب.");
    // A refused history never ends the Family session or context.
    expect(forbidden.client.getQueryData<FamilyUser>(FAMILY_ME_QUERY_KEY)?.context.available).toBe(true);
    forbidden.unmount();

    vi.restoreAllMocks();
    mockGets({ [BASE]: refusal(429, "TOO_MANY_REQUESTS") });
    asUser(ACTIVE, <CoordinatorAnnouncementList />);
    expect(await screen.findByRole("alert")).toHaveTextContent("محاولات كثيرة خلال وقت قصير. حاول بعد قليل.");
  });
});

// ----------------------------------------------------------------- compose

function directory(codes: string[]) {
  return { data: codes.map((code, i) => ({ family_code: code, clan_name: null, branch_group_name: null, branch_name: "فرع أ", head_name: `رب أسرة ${i + 1}`, active_member_count: 2 })), meta: { current_page: 1, last_page: 1, per_page: 25, total: codes.length } };
}

async function fillContent(title = "اجتماع أسر الفرع") {
  await userEvent.type(screen.getByLabelText("العنوان"), title);
  await userEvent.type(screen.getByLabelText("النص"), "نلتقي يوم الخميس.");
}

describe("composing a coordinator draft", () => {
  it("offers only coordinator audiences and previews the whole current scope", async () => {
    const post = vi.spyOn(apiClient, "post").mockResolvedValue({ data: { recipient_count: 3 } } as never);
    asUser(ACTIVE, <CoordinatorAnnouncementCompose />);

    expect(screen.getByLabelText("كامل نطاقي الحالي")).toBeChecked();
    expect(screen.getByLabelText("أسر محددة من نطاقي")).toBeInTheDocument();
    expect(screen.queryByLabelText(/جميع الأسر النشطة|أسر محددة$/)).toBeNull();
    await userEvent.click(screen.getByRole("button", { name: "احسب عدد المستلمين" }));
    expect(await screen.findByText(/عدد الأسر المستلمة الآن/)).toHaveTextContent("3");
    expect(post).toHaveBeenCalledWith(`${BASE}/audience-preview`, { audience: { type: "COORDINATOR_SCOPE" } });
  });

  it("selects in-scope families by public code from the Coordinator directory, previews, and saves idempotently", async () => {
    const get = mockGets({ [DIRECTORY("FAM")]: directory(["FAM-000201", "FAM-000202"]) });
    const drafts: { client_reference: string }[] = [];
    let replies: unknown[] = [];
    const post = vi.spyOn(apiClient, "post").mockImplementation(async (path: string, body: unknown) => {
      if (path === `${BASE}/audience-preview`) return { data: { recipient_count: 1 } } as never;
      if (path !== BASE) throw new Error(`Unexpected POST: ${path}`);
      drafts.push(body as { client_reference: string });
      const reply = replies.shift();
      if (reply instanceof Error) throw reply;
      return reply as never;
    });
    asUser(ACTIVE, <CoordinatorAnnouncementCompose />);

    await fillContent();
    await userEvent.click(screen.getByLabelText("أسر محددة من نطاقي"));
    await userEvent.type(screen.getByLabelText("البحث عن أسرة"), "FAM");
    await userEvent.click(await screen.findByRole("button", { name: "إضافة FAM-000202" }, { timeout: 3000 }));
    await userEvent.click(screen.getByRole("button", { name: "احسب عدد المستلمين" }));
    await screen.findByText(/عدد الأسر المستلمة الآن/);
    expect(post).toHaveBeenCalledWith(`${BASE}/audience-preview`, { audience: { type: "COORDINATOR_SELECTION", family_codes: ["FAM-000202"] } });
    expect(get).toHaveBeenCalledWith(DIRECTORY("FAM"));

    replies = [new TypeError("network"), { data: detail({ audience_type: "COORDINATOR_SELECTION" }), meta: { replayed: true } }];
    await userEvent.click(screen.getByRole("button", { name: "حفظ المسودة" }));
    expect(await screen.findByText("تعذّر الاتصال. تحقق من الإنترنت وحاول مرة أخرى.")).toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: "حفظ المسودة" }));
    await waitFor(() => expect(router.push).toHaveBeenCalledWith(`/family/coordinator/announcements/${UUID}`));
    expect(drafts).toHaveLength(2);
    expect(drafts[1].client_reference).toBe(drafts[0].client_reference);
    expect(drafts[0]).toMatchObject({ audience: { type: "COORDINATOR_SELECTION", family_codes: ["FAM-000202"] } });

    // Coordinator endpoints only; never a Staff endpoint or an internal identifier.
    const all = traffic();
    expect(all).not.toContain('"/api/v1/notifications');
    expect(all).not.toContain("/api/v1/families");
    expect(all).not.toMatch(/family_ids|branch_ids|branch_group_ids|clan_ids/);
    expect(browserStorageDump()).not.toMatch(/اجتماع أسر الفرع|FAM-000202/);
  });

  it("refuses a zero-recipient or ineligible audience and invalidates the preview on change", async () => {
    mockGets({ [DIRECTORY("FAM")]: directory(["FAM-000201"]) });
    const post = vi.spyOn(apiClient, "post").mockResolvedValueOnce({ data: { recipient_count: 0 } } as never);
    asUser(ACTIVE, <CoordinatorAnnouncementCompose />);
    await fillContent();

    await userEvent.click(screen.getByRole("button", { name: "احسب عدد المستلمين" }));
    expect(await screen.findByText("لا توجد أسر مستلمة ضمن هذا التحديد.")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "حفظ المسودة" })).toBeDisabled();

    await userEvent.click(screen.getByLabelText("أسر محددة من نطاقي"));
    await userEvent.type(screen.getByLabelText("البحث عن أسرة"), "FAM");
    await userEvent.click(await screen.findByRole("button", { name: "إضافة FAM-000201" }, { timeout: 3000 }));
    post.mockRejectedValueOnce(refusal(422, "NOTIFICATION_AUDIENCE_FAMILY_NOT_ELIGIBLE"));
    await userEvent.click(screen.getByRole("button", { name: "احسب عدد المستلمين" }));
    // The same generic message for unknown, inactive, deleted or out-of-scope.
    expect(await screen.findByText("إحدى الأسر المحددة غير متاحة للإرسال. راجع الأسر المختارة.")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "حفظ المسودة" })).toBeDisabled();

    post.mockResolvedValueOnce({ data: { recipient_count: 1 } } as never);
    await userEvent.click(screen.getByRole("button", { name: "احسب عدد المستلمين" }));
    await screen.findByText(/عدد الأسر المستلمة الآن/);
    await userEvent.click(screen.getByLabelText("كامل نطاقي الحالي"));
    expect(screen.getByText("تغيّر تحديد المستلمين. احسب العدد من جديد.")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "حفظ المسودة" })).toBeDisabled();
  });

  it("explains a scope revoked meanwhile, re-reads /family/me, and keeps the Family context", async () => {
    nav.pathname = "/family/coordinator/announcements/new";
    let me = ACTIVE;
    const get = mockGets({ "/api/v1/family/me": () => ({ user: me }) });
    vi.spyOn(apiClient, "post").mockImplementation(async () => {
      me = REVOKED; // the scope was revoked on the server meanwhile
      throw refusal(403, "COORDINATOR_SPACE_UNAVAILABLE");
    });
    const { client } = renderWithClient(
      <FamilyGate>
        <CoordinatorAnnouncementCompose />
      </FamilyGate>
    );

    await userEvent.click(await screen.findByRole("button", { name: "احسب عدد المستلمين" }));
    expect(await screen.findByText("مساحة التنسيق غير متاحة لهذا الحساب حاليًا.")).toBeInTheDocument();
    await waitFor(() => expect(get.mock.calls.filter(([p]) => p === "/api/v1/family/me")).toHaveLength(2));
    await waitFor(() => expect(client.getQueryData<FamilyUser>(FAMILY_ME_QUERY_KEY)?.coordinator_space).toBe(false));
    // Re-read, never cleared: the session and the household context stay.
    expect(client.getQueryData<FamilyUser>(FAMILY_ME_QUERY_KEY)?.context.available).toBe(true);
    expect(router.replace).not.toHaveBeenCalled();
  });
});

// ------------------------------------------------------------------ detail

describe("side isolation", () => {
  it("never shares a base path or a cache entry between the Staff and Coordinator sides", () => {
    expect(announcementBase("coordinator")).toBe(BASE);
    expect(announcementBase("staff")).toBe("/api/v1/notifications");
    expect(announcementKeys.detail("coordinator", UUID)).not.toEqual(announcementKeys.detail("staff", UUID));
    expect(announcementKeys.list("coordinator", {})).not.toEqual(announcementKeys.list("staff", {}));
  });
});

describe("a coordinator announcement's details", () => {
  it("lets an active coordinator publish (202 = queued) and cancel; a revoked one only cancel", async () => {
    expect(coordinatorActions(detail(), ACTIVE)).toEqual(["PUBLISH", "CANCEL"]);
    expect(coordinatorActions(detail(), REVOKED)).toEqual(["CANCEL"]);
    expect(coordinatorActions(detail({ status: "PUBLISH_FAILED", available_actions: ["CANCEL", "RETRY"] }), REVOKED)).toEqual(["CANCEL"]);
    expect(coordinatorActions(detail(), HEAD_ONLY)).toEqual([]);

    let current = detail();
    vi.spyOn(apiClient, "get").mockImplementation(async () => ({ data: current }) as never);
    const post = vi.spyOn(apiClient, "post").mockImplementation(async () => {
      current = detail({ status: "QUEUED", queued_at: new Date().toISOString(), available_actions: ["CANCEL"] });
      return { data: current, meta: { replayed: false } } as never;
    });
    asUser(ACTIVE, <CoordinatorAnnouncementDetail id={UUID} />);
    await userEvent.click(await screen.findByRole("button", { name: "طلب النشر" }));
    const dialog = await screen.findByRole("dialog");
    const confirm = within(dialog).getByRole("button", { name: "طلب النشر" });
    await userEvent.click(confirm);
    expect(await screen.findByText("طُلب نشر الإعلان وهو الآن في انتظار النشر. لم يُنشر بعد.")).toBeInTheDocument();
    expect(post).toHaveBeenCalledTimes(1);
    expect(post).toHaveBeenCalledWith(`${DETAIL}/publish`, {});
    expect(document.querySelector("[data-announcement-status='QUEUED']")).not.toBeNull();
  });

  it("lets a revoked coordinator read and cancel — no publish, no retry — with a clear notice", async () => {
    mockGets({ [DETAIL]: { data: detail({ status: "PUBLISH_FAILED", publish_failure_code: "AUDIENCE_EMPTY", publish_failed_at: "2026-10-19T11:00:00+03:00", publish_attempts: 1, available_actions: ["CANCEL", "RETRY"] }) } });
    const post = vi.spyOn(apiClient, "post").mockResolvedValue({ data: detail({ status: "CANCELLED", available_actions: [] }), meta: { replayed: false } } as never);
    asUser(REVOKED, <CoordinatorAnnouncementDetail id={UUID} />);

    expect(await screen.findByText(/لا يوجد لديك نطاق تنسيق حالي/)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "طلب النشر" })).toBeNull();
    expect(screen.queryByRole("button", { name: "إعادة محاولة النشر" })).toBeNull();
    await userEvent.click(screen.getByRole("button", { name: "إلغاء الإعلان" }));
    await userEvent.click(within(await screen.findByRole("dialog")).getByRole("button", { name: "إلغاء الإعلان" }));
    expect(await screen.findByText("أُلغي الإعلان. لن يُنشر.")).toBeInTheDocument();
    expect(post).toHaveBeenCalledWith(`${DETAIL}/cancel`, {});
  });

  it("shows no recipient statistics on NO_CURRENT_SCOPE, keeping the publication-time count", async () => {
    mockGets({
      [DETAIL]: { data: detail({ status: "PUBLISHED", published_at: "2026-10-19T10:00:00+03:00", recipient_count_at_publication: 4, available_actions: [], statistics_basis: "NO_CURRENT_SCOPE", statistics: null }) },
    });
    asUser(REVOKED, <CoordinatorAnnouncementDetail id={UUID} />);

    expect(await screen.findByText("لا يوجد نطاق تنسيق حالي")).toBeInTheDocument();
    expect(screen.getByText("لا تتوفر إحصاءات على هذا الأساس.")).toBeInTheDocument();
    expect(screen.queryByText("اطّلعت على الإعلان")).toBeNull();
    expect(screen.getByText("المستلمون عند النشر").nextElementSibling).toHaveTextContent("4");
  });

  it("renders the body as plain text and never an internal identifier", async () => {
    mockGets({ [DETAIL]: { data: detail({ body: "<b>عريض</b>\nسطر", audience_type: "COORDINATOR_SELECTION", audience_selection: { family_ids: 2 }, available_actions: [] }) } });
    asUser(ACTIVE, <CoordinatorAnnouncementDetail id={UUID} />);
    const body = await screen.findByText(/<b>عريض<\/b>/);
    expect(body.querySelector("b")).toBeNull();
    expect(body.className).toContain("whitespace-pre-line");
    expect(screen.getByText("أسر محددة", { selector: "dt" }).nextElementSibling).toHaveTextContent("2");
    expect(document.body.textContent).not.toContain("family_ids");
  });

  it("keeps the state on 503, explains 409 and 429, and shows another sender's message as unavailable", async () => {
    mockGets({ [DETAIL]: { data: detail() } });
    const post = vi
      .spyOn(apiClient, "post")
      .mockRejectedValueOnce(refusal(503, "NOTIFICATION_PUBLICATION_UNAVAILABLE"))
      .mockRejectedValueOnce(refusal(409, "NOTIFICATION_INVALID_TRANSITION"))
      .mockRejectedValueOnce(refusal(429, "TOO_MANY_REQUESTS"));
    const { unmount } = asUser(ACTIVE, <CoordinatorAnnouncementDetail id={UUID} />);

    await userEvent.click(await screen.findByRole("button", { name: "طلب النشر" }));
    const dialog = await screen.findByRole("dialog");
    const confirm = within(dialog).getByRole("button", { name: "طلب النشر" });
    await userEvent.click(confirm);
    expect(await within(dialog).findByText(/ولم يُنفَّذ هذا الطلب/)).toBeInTheDocument();
    await userEvent.click(confirm);
    expect(await within(dialog).findByText(/تغيّرت حالة الإعلان/)).toBeInTheDocument();
    await userEvent.click(confirm);
    expect(await within(dialog).findByText("محاولات كثيرة خلال وقت قصير. حاول بعد قليل.")).toBeInTheDocument();
    expect(post).toHaveBeenCalledTimes(3);
    expect(document.querySelector("[data-announcement-status='DRAFT']")).not.toBeNull();
    unmount();

    vi.restoreAllMocks();
    mockGets({ [DETAIL]: refusal(404, "NOTIFICATION_NOT_FOUND") });
    asUser(ACTIVE, <CoordinatorAnnouncementDetail id={UUID} />);
    expect(await screen.findByRole("alert")).toHaveTextContent("هذا الإعلان غير متاح.");
    expect(screen.getByRole("link", { name: "العودة إلى إعلاناتي" })).toHaveAttribute("href", "/family/coordinator/announcements");
  });

  it("asks the session flow on 401 and never treats it as a coordinator refusal", async () => {
    mockGets({ [DETAIL]: refusal(401) });
    asUser(ACTIVE, <CoordinatorAnnouncementDetail id={UUID} />);
    expect(await screen.findByRole("alert")).toHaveTextContent("انتهت الجلسة. سجّل الدخول مرة أخرى.");
  });

  it("polls a queued announcement until the server confirms PUBLISHED, then stops", async () => {
    const replies = [
      detail({ status: "QUEUED", queued_at: new Date().toISOString(), available_actions: ["CANCEL"] }),
      detail({ status: "PUBLISHED", published_at: new Date().toISOString(), available_actions: [] }),
    ];
    const get = vi.spyOn(apiClient, "get").mockImplementation(async () => ({ data: replies.length > 1 ? replies.shift() : replies[0] }) as never);
    const { unmount } = asUser(ACTIVE, <CoordinatorAnnouncementDetail id={UUID} />);
    await screen.findByText("في انتظار النشر", { selector: "[data-announcement-publishing] *" });
    expect(await screen.findByText("منشور", {}, { timeout: 8000 })).toBeInTheDocument();
    const calls = get.mock.calls.length;
    unmount();
    await new Promise((r) => setTimeout(r, 5500));
    expect(get.mock.calls.length).toBe(calls);
    expect(get.mock.calls.every(([p]) => p === DETAIL)).toBe(true);
  }, 25000);
});
