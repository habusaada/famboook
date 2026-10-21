import { StrictMode } from "react";
import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { FamilyUserContext } from "@/components/family/family-context";
import { FamilyShell } from "@/components/family/family-shell";
import { FamilyNotificationDetail } from "@/components/family/notifications/family-notification-detail";
import { FamilyNotifications } from "@/components/family/notifications/family-notifications";
import { FamilyNotificationsGate } from "@/components/family/notifications/family-notifications-gate";
import { NotificationBell } from "@/components/family/notifications/notification-bell";
import { ApiError, apiClient } from "@/lib/api/client";
import { FAMILY_ME_QUERY_KEY, type FamilyUser } from "@/lib/api/family-auth";
import {
  type FamilyNotification,
  type FamilyNotificationInboxPage,
  type FamilyNotificationSummary,
  familyNotificationListPath,
  normalizePage,
} from "@/lib/api/family-notifications";
import { bellLabel, safeActionPath, unreadBadgeText } from "@/lib/utils/family-notification";
import { browserStorageDump, familyUser, renderWithClient } from "./helpers";

// FB-NOTIFY-01C.2: the household's notifications over the FB-NOTIFY-01B.2b.6b
// Family API. Fixtures mirror FamilyNotificationResource and the paginated
// inbox / archive (meta.unread_count on the inbox only). Synthetic data only.

const router = { push: vi.fn(), replace: vi.fn() };
const nav = { search: "", pathname: "/family/notifications" };
vi.mock("next/navigation", () => ({
  useRouter: () => router,
  usePathname: () => nav.pathname,
  useSearchParams: () => new URLSearchParams(nav.search),
}));

beforeEach(() => {
  vi.restoreAllMocks();
  router.push.mockReset();
  router.replace.mockReset();
  nav.search = "";
  nav.pathname = "/family/notifications";
});

const UUID = "5b1e2c3d-4f5a-4b6c-8d7e-9f0a1b2c3d4e";
const UUID_2 = "7c2d3e4f-5a6b-4c7d-8e9f-0a1b2c3d4e5f";
const INBOX = "/api/v1/family/notifications";
const ARCHIVE = `${INBOX}/archive`;
const DETAIL = `${INBOX}/${UUID}`;
const TYPES = "/api/v1/family/change-requests/types";

function summary(overrides: Partial<FamilyNotificationSummary> = {}): FamilyNotificationSummary {
  return {
    id: UUID,
    notice_code: "NTF-000042",
    kind: "ANNOUNCEMENT",
    source: "إدارة Famboook",
    level: "NORMAL",
    title: "موعد توزيع المساعدات",
    action_path: null,
    published_at: "2026-10-19T09:00:00+03:00",
    expires_at: null,
    expired: false,
    viewed_at: null,
    requires_acknowledgement: false,
    acknowledged_at: null,
    acknowledged_late: false,
    available_actions: ["VIEW"],
    ...overrides,
  };
}

function detail(overrides: Partial<FamilyNotification> = {}): FamilyNotification {
  return { ...summary(), body: "يُرجى مراجعة مركز التوزيع.\nالسطر الثاني.", ...overrides };
}

function page(rows: FamilyNotificationSummary[], meta: Partial<FamilyNotificationInboxPage["meta"]> = {}): FamilyNotificationInboxPage {
  return {
    data: rows,
    links: { first: null, last: null, prev: null, next: null },
    meta: { current_page: 1, from: rows.length ? 1 : null, last_page: 1, path: INBOX, per_page: 20, to: rows.length || null, total: rows.length, unread_count: 0, ...meta },
  };
}

/** Answers GETs from a path map; a function value is called, an Error is thrown, "pending" never settles. */
function mockGets(routes: Record<string, unknown>) {
  return vi.spyOn(apiClient, "get").mockImplementation(async (path: string) => {
    if (!(path in routes)) throw new Error(`Unexpected GET: ${path}`);
    const reply = routes[path];
    if (reply === "pending") return new Promise<never>(() => {});
    if (reply instanceof Error) throw reply;
    return (typeof reply === "function" ? reply() : reply) as never;
  });
}

function refusal(status: number, code?: string) {
  return new ApiError(status, code ? { message: "مرفوض", code } : { message: "مرفوض" });
}

function seedUser(client: ReturnType<typeof renderWithClient>["client"], user: FamilyUser = familyUser()) {
  client.setQueryData(FAMILY_ME_QUERY_KEY, user);
}

// ---------------------------------------------------------------- utilities

describe("notification utilities", () => {
  it("formats the badge and the accessible bell name", () => {
    expect([unreadBadgeText(1), unreadBadgeText(9), unreadBadgeText(10), unreadBadgeText(250)]).toEqual(["1", "9", "9+", "9+"]);
    expect(bellLabel(undefined)).toBe("الإشعارات");
    expect(bellLabel(0)).toBe("الإشعارات");
    expect(bellLabel(3)).toBe("الإشعارات، 3 غير مقروءة");
    expect(bellLabel(12)).toBe("الإشعارات، أكثر من 9 غير مقروءة");
  });

  it("accepts only plain family-relative action paths", () => {
    expect(safeActionPath("/family/requests/" + UUID)).toBe(`/family/requests/${UUID}`);
    expect(safeActionPath("/family")).toBe("/family");
    for (const unsafe of [null, undefined, "", "https://evil.example/family", "//evil.example", "/family//evil", "/family/../api", "/families", "/staff/families",
      "/family/requests?x=1", "/family/requests#x", "javascript:alert(1)", "/family\\evil", " /family/requests"]) {
      expect(safeActionPath(unsafe as string | null), String(unsafe)).toBeNull();
    }
  });

  it("builds list paths within the server's limits and normalizes pages", () => {
    expect(familyNotificationListPath("inbox", 1)).toBe(INBOX);
    expect(familyNotificationListPath("archive", 3)).toBe(`${ARCHIVE}?page=3`);
    expect(familyNotificationListPath("inbox", 1, 500)).toBe(`${INBOX}?per_page=50`);
    expect([normalizePage("2"), normalizePage("0"), normalizePage("-1"), normalizePage("abc"), normalizePage("1.5"), normalizePage(null)]).toEqual([2, 1, 1, 1, 1, 1]);
  });
});

// --------------------------------------------------------------------- bell

describe("the header bell", () => {
  it("shows the server's unread count, 9+ above nine, with an Arabic accessible name", async () => {
    mockGets({ [INBOX]: page([summary()], { unread_count: 3 }) });
    renderWithClient(<NotificationBell />);

    const bell = await screen.findByRole("link", { name: "الإشعارات، 3 غير مقروءة" });
    expect(bell).toHaveAttribute("href", "/family/notifications");
    expect(within(bell).getByText("3")).toBeInTheDocument();
  });

  it("caps the badge at 9+", async () => {
    mockGets({ [INBOX]: page([], { unread_count: 12 }) });
    renderWithClient(<NotificationBell />);
    expect(await screen.findByText("9+")).toBeInTheDocument();
  });

  it("shows no misleading zero while loading, at zero, or after a failure", async () => {
    mockGets({ [INBOX]: "pending" });
    const { unmount } = renderWithClient(<NotificationBell />);
    expect(screen.getByRole("link", { name: "الإشعارات" })).toBeInTheDocument();
    expect(document.querySelector("[data-family-unread-badge]")).toBeNull();
    unmount();

    vi.restoreAllMocks();
    mockGets({ [INBOX]: page([], { unread_count: 0 }) });
    const zero = renderWithClient(<NotificationBell />);
    await waitFor(() => expect(apiClient.get).toHaveBeenCalled());
    expect(document.querySelector("[data-family-unread-badge]")).toBeNull();
    zero.unmount();

    vi.restoreAllMocks();
    mockGets({ [INBOX]: refusal(500) });
    renderWithClient(<NotificationBell />);
    await waitFor(() => expect(apiClient.get).toHaveBeenCalled());
    expect(document.querySelector("[data-family-unread-badge]")).toBeNull();
  });

  it("reads the inbox without any family or person identifier", async () => {
    const get = mockGets({ [INBOX]: page([], { unread_count: 1 }) });
    renderWithClient(<NotificationBell />);
    await screen.findByText("1");
    expect(get.mock.calls.map(([p]) => p)).toEqual([INBOX]);
  });
});

describe("the bell in the Family shell", () => {
  function renderShell(user: FamilyUser) {
    mockGets({ [INBOX]: page([], { unread_count: 2 }), [TYPES]: { data: [], meta: { submission_enabled: false } } });
    return renderWithClient(
      <FamilyUserContext.Provider value={user}>
        <FamilyShell>
          <p>المحتوى</p>
        </FamilyShell>
      </FamilyUserContext.Provider>
    );
  }

  it("is in the household header, and the five-entry bottom navigation is unchanged", async () => {
    nav.pathname = "/family";
    renderShell(familyUser({ notifications: true }));

    const header = screen.getByRole("banner");
    expect(await within(header).findByRole("link", { name: "الإشعارات، 2 غير مقروءة" })).toBeInTheDocument();
    const bottom = screen.getByRole("navigation", { name: "التنقل الرئيسي" });
    expect(within(bottom).getAllByRole("listitem")).toHaveLength(5);
    expect(within(bottom).queryByText("الإشعارات")).toBeNull();
  });

  it("is hidden in Coordinator Space", () => {
    nav.pathname = "/family/coordinator";
    renderShell(familyUser({ coordinator: true, coordinator_space: true, roles: ["FAMILY_USER", "COORDINATOR"] }));
    expect(document.querySelector("[data-family-notification-bell]")).toBeNull();
    expect(apiClient.get).not.toHaveBeenCalledWith(INBOX);
  });

  it("is hidden, and nothing is polled, when the server does not show notifications (pilot isolation)", async () => {
    nav.pathname = "/family";
    for (const user of [familyUser({ notifications: false }), familyUser()]) {
      vi.mocked(apiClient.get).mockClear?.();
      const { unmount } = renderShell(user);
      expect(await screen.findByText("المحتوى")).toBeInTheDocument();
      expect(document.querySelector("[data-family-notification-bell]")).toBeNull();
      await new Promise((r) => setTimeout(r, 20));
      expect(apiClient.get).not.toHaveBeenCalledWith(INBOX);
      unmount();
    }
  });

  it("is hidden, and nothing is read, without a Family context", () => {
    nav.pathname = "/family";
    renderShell(familyUser({ context: { available: false, family: null } }));
    expect(document.querySelector("[data-family-notification-bell]")).toBeNull();
    expect(apiClient.get).not.toHaveBeenCalledWith(INBOX);
  });
});

// -------------------------------------------------------------------- lists

describe("the inbox and the archive", () => {
  it("lists cards without bodies and records no view", async () => {
    const post = vi.spyOn(apiClient, "post");
    mockGets({
      [INBOX]: page([
        summary({ level: "IMPORTANT", requires_acknowledgement: true, available_actions: ["VIEW", "ACKNOWLEDGE"] }),
        summary({ id: UUID_2, title: "تحديث بيانات السكن", source: "منسق العائلة", viewed_at: "2026-10-19T10:00:00+03:00", available_actions: [] }),
      ], { unread_count: 1 }),
    });
    renderWithClient(<FamilyNotifications view="inbox" />);

    const list = await screen.findByRole("list", { name: "الإشعارات الواردة" });
    const [first, second] = within(list).getAllByRole("link");
    expect(first).toHaveAttribute("href", `/family/notifications/${UUID}`);
    expect(first).toHaveTextContent("جديد: موعد توزيع المساعدات");
    expect(within(first).getByText("مهم")).toBeInTheDocument();
    expect(within(first).getByText("بانتظار تأكيد الاطلاع")).toBeInTheDocument();
    expect(within(first).getByText("إدارة Famboook")).toBeInTheDocument();
    expect(second).not.toHaveTextContent("جديد:");
    expect(within(second).getByText("منسق العائلة")).toBeInTheDocument();
    expect(list).not.toHaveTextContent("يُرجى مراجعة");
    expect(screen.getByRole("link", { name: "الوارد" })).toHaveAttribute("aria-current", "page");
    expect(screen.getByRole("link", { name: "الأرشيف" })).toHaveAttribute("href", "/family/notifications/archive");
    expect(post).not.toHaveBeenCalled();
  });

  it("shows archived notifications as expired, with late acknowledgement", async () => {
    nav.pathname = "/family/notifications/archive";
    mockGets({
      [ARCHIVE]: page([
        summary({ expired: true, level: "IMPORTANT", requires_acknowledgement: true, acknowledged_at: "2026-10-20T10:00:00+03:00", acknowledged_late: true, viewed_at: "2026-10-20T10:00:00+03:00", available_actions: [] }),
      ]),
    });
    renderWithClient(<FamilyNotifications view="archive" />);

    const list = await screen.findByRole("list", { name: "الإشعارات المؤرشفة" });
    expect(within(list).getByText("منتهي")).toBeInTheDocument();
    expect(within(list).getByText("أُكّد الاطلاع متأخرًا")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "الأرشيف" })).toHaveAttribute("aria-current", "page");
  });

  it("pages through the server's pages in the URL", async () => {
    nav.search = "page=2";
    const get = mockGets({ [`${INBOX}?page=2`]: page([summary()], { current_page: 2, last_page: 3, unread_count: 1 }) });
    renderWithClient(<FamilyNotifications view="inbox" />);

    await screen.findByText("موعد توزيع المساعدات");
    expect(get).toHaveBeenCalledWith(`${INBOX}?page=2`);
    await userEvent.click(screen.getByRole("button", { name: "التالي" }));
    expect(router.push).toHaveBeenCalledWith("/family/notifications?page=3", { scroll: true });
    await userEvent.click(screen.getByRole("button", { name: "السابق" }));
    expect(router.push).toHaveBeenCalledWith("/family/notifications", { scroll: true });
  });

  it("resets a malformed or refused page to the first page", async () => {
    nav.search = "page=abc";
    mockGets({ [INBOX]: page([]) });
    renderWithClient(<FamilyNotifications view="inbox" />);
    await waitFor(() => expect(router.replace).toHaveBeenCalledWith("/family/notifications"));

    vi.restoreAllMocks();
    router.replace.mockReset();
    nav.search = "page=7";
    mockGets({ [`${INBOX}?page=7`]: refusal(422) });
    renderWithClient(<FamilyNotifications view="inbox" />);
    await waitFor(() => expect(router.replace).toHaveBeenCalledWith("/family/notifications"));
  });

  it("shows loading, empty and retryable error states", async () => {
    mockGets({ [INBOX]: "pending" });
    const loading = renderWithClient(<FamilyNotifications view="inbox" />);
    expect(screen.getByRole("status")).toHaveTextContent("جارٍ تحميل الإشعارات");
    loading.unmount();

    vi.restoreAllMocks();
    mockGets({ [INBOX]: page([]) });
    const empty = renderWithClient(<FamilyNotifications view="inbox" />);
    expect(await screen.findByText("لا توجد إشعارات جديدة")).toBeInTheDocument();
    empty.unmount();

    vi.restoreAllMocks();
    let calls = 0;
    const get = mockGets({ [INBOX]: () => (++calls === 1 ? Promise.reject(refusal(500)) : page([summary()])) });
    renderWithClient(<FamilyNotifications view="inbox" />);
    expect(await screen.findByRole("alert")).toHaveTextContent("تعذّر تحميل الإشعارات");
    await userEvent.click(screen.getByRole("button", { name: "إعادة المحاولة" }));
    expect(await screen.findByText("موعد توزيع المساعدات")).toBeInTheDocument();
    expect(get).toHaveBeenCalledTimes(2);
  });

  it("says when the rate limit was reached", async () => {
    mockGets({ [INBOX]: refusal(429, "TOO_MANY_REQUESTS") });
    renderWithClient(<FamilyNotifications view="inbox" />);
    expect(await screen.findByRole("alert")).toHaveTextContent("محاولات كثيرة خلال وقت قصير");
  });

  it("hands 401 to the session flow and 403 to the context flow", async () => {
    mockGets({ [INBOX]: refusal(401) });
    const signedOut = renderWithClient(<FamilyNotifications view="inbox" />);
    seedUser(signedOut.client);
    await waitFor(() => expect(signedOut.client.getQueryData(FAMILY_ME_QUERY_KEY)).toBeNull());
    expect(screen.queryByRole("alert")).toBeNull();
    signedOut.unmount();

    vi.restoreAllMocks();
    mockGets({ [INBOX]: refusal(403, "FAMILY_CONTEXT_UNAVAILABLE") });
    const noContext = renderWithClient(<FamilyNotifications view="inbox" />);
    seedUser(noContext.client);
    await waitFor(() => expect(noContext.client.getQueryData<FamilyUser>(FAMILY_ME_QUERY_KEY)?.context.available).toBe(false));
  });
});

// ------------------------------------------------------------------- detail

describe("a notification's details", () => {
  it("reads first, then records the view once — even under StrictMode", async () => {
    const order: string[] = [];
    vi.spyOn(apiClient, "get").mockImplementation(async (path: string) => {
      order.push(`GET ${path}`);
      return { data: detail() } as never;
    });
    const post = vi.spyOn(apiClient, "post").mockImplementation(async (path: string) => {
      order.push(`POST ${path}`);
      return { data: detail({ viewed_at: "2026-10-19T11:00:00+03:00", available_actions: [] }), meta: { replayed: false } } as never;
    });
    renderWithClient(
      <StrictMode>
        <FamilyNotificationDetail id={UUID} />
      </StrictMode>
    );

    expect(await screen.findByRole("heading", { level: 1, name: "موعد توزيع المساعدات" })).toBeInTheDocument();
    await waitFor(() => expect(post).toHaveBeenCalledTimes(1));
    expect(post).toHaveBeenCalledWith(`${DETAIL}/view`, {});
    expect(order[0]).toBe(`GET ${DETAIL}`);
    expect(order.indexOf(`POST ${DETAIL}/view`)).toBeGreaterThan(0);
    // The server's answer replaced the detail; no second view.
    await new Promise((r) => setTimeout(r, 50));
    expect(post).toHaveBeenCalledTimes(1);
  });

  it("records nothing when the server lists no VIEW, and accepts a replayed view", async () => {
    mockGets({ [DETAIL]: { data: detail({ viewed_at: "2026-10-18T09:00:00+03:00", available_actions: [] }) } });
    const post = vi.spyOn(apiClient, "post");
    renderWithClient(<FamilyNotificationDetail id={UUID} />);
    await screen.findByText("NTF-000042");
    await new Promise((r) => setTimeout(r, 50));
    expect(post).not.toHaveBeenCalled();
  });

  it("never shows a failed view as recorded, and never re-sends it on the same mount", async () => {
    const get = mockGets({ [DETAIL]: { data: detail() } });
    const post = vi.spyOn(apiClient, "post").mockRejectedValue(refusal(500));
    renderWithClient(<FamilyNotificationDetail id={UUID} />);

    await screen.findByText("NTF-000042");
    await waitFor(() => expect(post).toHaveBeenCalledTimes(1));
    // The detail is re-read from the server; it still shows what the server says.
    await waitFor(() => expect(get.mock.calls.filter(([p]) => p === DETAIL).length).toBeGreaterThanOrEqual(2));
    await new Promise((r) => setTimeout(r, 50));
    expect(post).toHaveBeenCalledTimes(1);
    expect(screen.queryByRole("alert")).toBeNull();
    expect(screen.getByRole("heading", { level: 1, name: "موعد توزيع المساعدات" })).toBeInTheDocument();
  });

  it("renders the body as plain text with its line breaks, and the notice metadata", async () => {
    mockGets({ [DETAIL]: { data: detail({ body: "<b>عريض</b>\n<script>x()</script>", available_actions: [] }) } });
    renderWithClient(<FamilyNotificationDetail id={UUID} />);

    const body = await screen.findByText(/<b>عريض<\/b>/);
    expect(body).toHaveAttribute("dir", "auto");
    expect(body.className).toContain("whitespace-pre-line");
    expect(body.querySelector("b, script")).toBeNull();
    expect(screen.getByText("NTF-000042").tagName).toBe("BDI");
    expect(screen.getByText("إدارة Famboook")).toBeInTheDocument();
    expect(screen.getByText("تاريخ النشر:")).toBeInTheDocument();
  });

  it("links only a safe family-relative action_path", async () => {
    mockGets({ [DETAIL]: { data: detail({ action_path: `/family/requests/${UUID_2}`, available_actions: [] }) } });
    const safe = renderWithClient(<FamilyNotificationDetail id={UUID} />);
    expect(await screen.findByRole("link", { name: "عرض التفاصيل المرتبطة" })).toHaveAttribute("href", `/family/requests/${UUID_2}`);
    safe.unmount();

    vi.restoreAllMocks();
    mockGets({ [DETAIL]: { data: detail({ action_path: "https://evil.example/family", available_actions: [] }) } });
    renderWithClient(<FamilyNotificationDetail id={UUID} />);
    await screen.findByText("NTF-000042");
    expect(screen.queryByRole("link", { name: "عرض التفاصيل المرتبطة" })).toBeNull();
  });

  it("shows another family's or a missing notification as not available, without any POST", async () => {
    mockGets({ [DETAIL]: refusal(404, "NOTIFICATION_NOT_FOUND") });
    const post = vi.spyOn(apiClient, "post");
    renderWithClient(<FamilyNotificationDetail id={UUID} />);

    expect(await screen.findByRole("heading", { name: "الإشعار غير متاح" })).toBeInTheDocument();
    expect(screen.getByRole("alert")).toHaveTextContent("لم نجد هذا الإشعار ضمن إشعارات أسرتك.");
    expect(screen.getByRole("link", { name: "العودة إلى الإشعارات" })).toHaveAttribute("href", "/family/notifications");
    expect(post).not.toHaveBeenCalled();
  });

  it("explains an expired notification and leads back to the archive", async () => {
    mockGets({ [DETAIL]: { data: detail({ expired: true, expires_at: "2026-10-18T09:00:00+03:00", available_actions: [] }) } });
    renderWithClient(<FamilyNotificationDetail id={UUID} />);
    expect(await screen.findByText(/انتهت مدة هذا الإشعار/)).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "أرشيف الإشعارات" })).toHaveAttribute("href", "/family/notifications/archive");
    expect(screen.getByText("انتهى في:")).toBeInTheDocument();
  });

  it("offers no acknowledgement for a NORMAL notification", async () => {
    mockGets({ [DETAIL]: { data: detail({ available_actions: [] }) } });
    renderWithClient(<FamilyNotificationDetail id={UUID} />);
    await screen.findByText("NTF-000042");
    expect(screen.queryByRole("button", { name: "تأكيد الاطلاع" })).toBeNull();
    expect(document.querySelector("[data-family-notification-section='acknowledgement']")).toBeNull();
  });
});

describe("IMPORTANT acknowledgement", () => {
  const important = (o: Partial<FamilyNotification> = {}) =>
    detail({ level: "IMPORTANT", requires_acknowledgement: true, viewed_at: "2026-10-19T10:00:00+03:00", available_actions: ["ACKNOWLEDGE"], ...o });

  it("needs an explicit confirmation, sends once, and shows the server's timestamp", async () => {
    let current = important();
    vi.spyOn(apiClient, "get").mockImplementation(async () => ({ data: current }) as never);
    let resolve: (value: unknown) => void = () => undefined;
    const post = vi.spyOn(apiClient, "post").mockImplementation(() => new Promise((r) => (resolve = r)) as never);
    renderWithClient(<FamilyNotificationDetail id={UUID} />);

    await userEvent.click(await screen.findByRole("button", { name: "تأكيد الاطلاع" }));
    const dialog = await screen.findByRole("dialog");
    expect(post).not.toHaveBeenCalled();
    const confirm = within(dialog).getByRole("button", { name: "تأكيد الاطلاع" });
    await userEvent.click(confirm);
    await userEvent.click(confirm);
    expect(post).toHaveBeenCalledTimes(1);
    expect(post).toHaveBeenCalledWith(`${DETAIL}/acknowledge`, {});

    current = important({ acknowledged_at: "2026-10-19T12:00:00+03:00", available_actions: [] });
    resolve({ data: current, meta: { replayed: false } });
    expect(await screen.findByText("تم تأكيد اطلاعك على الإشعار.")).toBeInTheDocument();
    expect(document.querySelector("[data-family-notification-acknowledged]")).not.toBeNull();
    expect(document.querySelector("[data-family-notification-late]")).toBeNull();
    expect(screen.queryByRole("button", { name: "تأكيد الاطلاع" })).toBeNull();
  });

  it("shows a late acknowledgement after expiry and a replay as already done", async () => {
    mockGets({ [DETAIL]: { data: important({ expired: true, expires_at: "2026-10-18T09:00:00+03:00" }) } });
    vi.spyOn(apiClient, "post").mockResolvedValue({
      data: important({ expired: true, acknowledged_at: "2026-10-19T12:00:00+03:00", acknowledged_late: true, available_actions: [] }),
      meta: { replayed: true },
    } as never);
    renderWithClient(<FamilyNotificationDetail id={UUID} />);

    expect(await screen.findByText(/سيُسجَّل التأكيد متأخرًا/)).toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: "تأكيد الاطلاع" }));
    await userEvent.click(within(await screen.findByRole("dialog")).getByRole("button", { name: "تأكيد الاطلاع" }));
    expect(await screen.findByText("سبق تأكيد الاطلاع على هذا الإشعار.")).toBeInTheDocument();
    expect(document.querySelector("[data-family-notification-late]")).not.toBeNull();
  });

  it("re-reads the notification after a 409 and keeps the dialog open with a safe message", async () => {
    const get = mockGets({ [DETAIL]: { data: important() } });
    vi.spyOn(apiClient, "post").mockRejectedValue(refusal(409, "NOTIFICATION_NOT_ACKNOWLEDGEABLE"));
    renderWithClient(<FamilyNotificationDetail id={UUID} />);

    await userEvent.click(await screen.findByRole("button", { name: "تأكيد الاطلاع" }));
    await userEvent.click(within(await screen.findByRole("dialog")).getByRole("button", { name: "تأكيد الاطلاع" }));
    expect(await screen.findByText("تغيّرت حالة الإشعار. حدّثنا الصفحة بحالته الحالية.")).toBeInTheDocument();
    await waitFor(() => expect(get.mock.calls.filter(([p]) => p === DETAIL).length).toBeGreaterThanOrEqual(2));
  });

  it("shows NOTIFICATION_ACTOR_NOT_ALLOWED as this action's refusal without ending the Family context", async () => {
    mockGets({ [DETAIL]: { data: important() } });
    vi.spyOn(apiClient, "post").mockRejectedValue(refusal(403, "NOTIFICATION_ACTOR_NOT_ALLOWED"));
    const { client } = renderWithClient(<FamilyNotificationDetail id={UUID} />);
    seedUser(client);

    await userEvent.click(await screen.findByRole("button", { name: "تأكيد الاطلاع" }));
    await userEvent.click(within(await screen.findByRole("dialog")).getByRole("button", { name: "تأكيد الاطلاع" }));
    expect(await screen.findByText("لا يمكن تنفيذ هذا الإجراء على الإشعار من هذا الحساب.")).toBeInTheDocument();
    expect(client.getQueryData<FamilyUser>(FAMILY_ME_QUERY_KEY)?.context.available).toBe(true);
  });

  it("ends the Family context on FAMILY_CONTEXT_UNAVAILABLE, and reports a rate limit", async () => {
    mockGets({ [DETAIL]: { data: important() } });
    const post = vi.spyOn(apiClient, "post").mockRejectedValueOnce(refusal(429, "TOO_MANY_REQUESTS"));
    const { client } = renderWithClient(<FamilyNotificationDetail id={UUID} />);
    seedUser(client);

    await userEvent.click(await screen.findByRole("button", { name: "تأكيد الاطلاع" }));
    const dialog = await screen.findByRole("dialog");
    await userEvent.click(within(dialog).getByRole("button", { name: "تأكيد الاطلاع" }));
    expect(await within(dialog).findByText("محاولات كثيرة خلال وقت قصير. حاول بعد قليل.")).toBeInTheDocument();

    post.mockRejectedValueOnce(refusal(403, "FAMILY_CONTEXT_UNAVAILABLE"));
    await userEvent.click(within(dialog).getByRole("button", { name: "تأكيد الاطلاع" }));
    await waitFor(() => expect(client.getQueryData<FamilyUser>(FAMILY_ME_QUERY_KEY)?.context.available).toBe(false));
  });

  it("keeps notification data out of browser storage", async () => {
    mockGets({ [DETAIL]: { data: important() } });
    renderWithClient(<FamilyNotificationDetail id={UUID} />);
    await screen.findByText("NTF-000042");
    expect(browserStorageDump()).not.toContain("موعد توزيع المساعدات");
    expect(browserStorageDump()).not.toContain(UUID);
  });
});

// --------------------------------------------------------- pilot isolation

describe("the notification pages gate (pilot isolation)", () => {
  function renderGate(user: FamilyUser) {
    mockGets({ [INBOX]: page([], { unread_count: 0 }) });
    return renderWithClient(
      <FamilyUserContext.Provider value={user}>
        <FamilyNotificationsGate>
          <FamilyNotifications view="inbox" />
        </FamilyNotificationsGate>
      </FamilyUserContext.Provider>
    );
  }

  it("renders nothing of the inbox and reads nothing when the server does not show notifications", async () => {
    renderGate(familyUser({ notifications: false }));
    expect(screen.getByRole("heading", { name: "الإشعارات غير متاحة حاليًا" })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "العودة إلى الرئيسية" })).toHaveAttribute("href", "/family");
    await new Promise((r) => setTimeout(r, 20));
    expect(apiClient.get).not.toHaveBeenCalled();
  });

  it("opens the inbox when the server shows notifications", async () => {
    renderGate(familyUser({ notifications: true }));
    await waitFor(() => expect(apiClient.get).toHaveBeenCalledWith(INBOX));
    expect(screen.queryByText("الإشعارات غير متاحة حاليًا")).toBeNull();
  });

  it("the route layout uses the gate", async () => {
    const { default: Layout } = await import("@/app/family/(portal)/notifications/layout");
    renderWithClient(
      <FamilyUserContext.Provider value={familyUser()}>
        <Layout>
          <p>محتوى محمي</p>
        </Layout>
      </FamilyUserContext.Provider>
    );
    expect(screen.queryByText("محتوى محمي")).toBeNull();
    expect(screen.getByText("الإشعارات غير متاحة حاليًا")).toBeInTheDocument();
  });
});
