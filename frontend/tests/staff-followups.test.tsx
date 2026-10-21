import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import FollowupsPage from "@/app/(staff)/followups/page";
import { StaffFollowupDetail, StaffFollowupList } from "@/components/followups/staff-followups";
import { AuthContext, authValue } from "@/components/auth/auth-context";
import { SidebarNav } from "@/components/layout/sidebar-nav";
import type { CurrentUser } from "@/lib/api/auth";
import { ApiError, apiClient } from "@/lib/api/client";
import { type FollowupCounts, type FollowupDetail, type FollowupEvent, type FollowupSummary, followupKeys, followupListPath } from "@/lib/api/followups";
import { navItemFor, navItems } from "@/lib/navigation";
import type { PaginatedResponse } from "@/lib/types/api/family";
import { browserStorageDump, renderWithClient } from "./helpers";

// FB-NOTIFY-01E.3-F3.3: the Staff follow-up screens over the F3.2 Staff API
// (/api/v1/notifications/followups). Fixtures mirror FollowupResource /
// FollowupDetailResource / FollowupEventResource. Synthetic data only.

const router = { push: vi.fn(), replace: vi.fn() };
const nav = { search: "", pathname: "/followups" };
vi.mock("next/navigation", () => ({
  useRouter: () => router,
  usePathname: () => nav.pathname,
  useSearchParams: () => new URLSearchParams(nav.search),
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
  nav.pathname = "/followups";
});

const UUID = "7c1e2c3d-4f5a-4b6c-8d7e-9f0a1b2c3d4e";
const BASE = "/api/v1/notifications/followups";
const DETAIL = `${BASE}/${UUID}`;
const HISTORY = `${DETAIL}/history`;
const SUMMARY = `${BASE}/summary`;
const OPEN_LIST = `${BASE}?status=OPEN`;
const VIEW = ["notification-followup.view"];
const MANAGE = ["notification-followup.view", "notification-followup.manage"];
const FUTURE = "2099-01-01T00:00:00+00:00";

function staff(permissions: string[]): CurrentUser {
  return { name: "موظف تجريبي", email: "staff@example.test", role: "ADMINISTRATOR", role_label: "مدير", permissions };
}

function withAuth(ui: React.ReactElement, permissions: string[] = MANAGE) {
  return renderWithClient(<AuthContext.Provider value={authValue(staff(permissions))}>{ui}</AuthContext.Provider>);
}

function row(o: Partial<FollowupSummary> = {}): FollowupSummary {
  return {
    id: UUID,
    stage: "FIRST",
    trigger_reason: "UNACKNOWLEDGED",
    status: "OPEN",
    outcome: null,
    close_reason: null,
    due_at: "2026-10-20T09:00:00+00:00",
    opened_at: "2026-10-20T09:01:00+00:00",
    closed_at: null,
    message: { notice_code: "NTF-000042", level: "IMPORTANT", title: "موعد توزيع المساعدات", expires_at: FUTURE },
    family: { family_code: "FAM-000123", head_name: "سالم الاختبار" },
    ...o,
  };
}

function detail(o: Partial<FollowupDetail> = {}): FollowupDetail {
  return {
    ...row(),
    recipient: { viewed_at: "2026-10-19T10:00:00+00:00", acknowledged_at: null, acknowledged_late: false, had_family_account: true },
    contacts: 1,
    ownership: { kind: "CENTRAL" },
    ...o,
  };
}

const COUNTS: FollowupCounts = {
  open: 12,
  open_by_stage: { FIRST: 9, SECOND: 3 },
  open_by_level: { NORMAL: 5, IMPORTANT: 7 },
  expired_open: 2,
  closed_last_30_days: { SATISFIED: 4, EXPIRED: 1, CLOSED_MANUALLY: 6 },
};

const EVENTS: FollowupEvent[] = [
  { type: "FOLLOWUP_OPENED", actor: { side: "SYSTEM", name: null }, reason_code: null, stage: "FIRST", outcome: null, note: null, occurred_at: "2026-10-20T09:01:00+00:00" },
  {
    type: "CONTACT_RECORDED",
    actor: { side: "STAFF", name: "موظف المتابعة" },
    reason_code: "CALLED_NO_ANSWER",
    stage: "FIRST",
    outcome: null,
    note: "لم يرد أحد، سنحاول لاحقًا.",
    occurred_at: "2026-10-20T11:00:00+00:00",
  },
];

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

const detailRoutes = (d: FollowupDetail = detail(), events: FollowupEvent[] = EVENTS) => ({ [DETAIL]: { data: d }, [HISTORY]: { data: events } });

const contactBodies = () => vi.mocked(apiClient.post).mock.calls.filter(([path]) => path === `${DETAIL}/contacts`).map(([, body]) => body as Record<string, unknown>);

async function chooseOption(combobox: string, option: string, container: HTMLElement | Document = document) {
  await userEvent.click(within(container as HTMLElement).getByRole("combobox", { name: combobox }));
  await userEvent.click(await screen.findByRole("option", { name: option }));
}

// ------------------------------------------------------------- navigation

describe("navigation and route permissions", () => {
  it("shows «المتابعات» only with notification-followup.view and guards every /followups route with it", () => {
    const item = navItems.find((i) => i.href === "/followups");
    expect(item?.permissions).toEqual(["notification-followup.view"]);
    expect(navItemFor("/followups")).toBe(item);
    expect(navItemFor(`/followups/${UUID}`)).toBe(item);

    const { unmount } = withAuth(<SidebarNav />, VIEW);
    expect(screen.getByRole("link", { name: "المتابعات" })).toHaveAttribute("href", "/followups");
    unmount();
    // announcement.view alone (or any other Staff permission) does not open it.
    withAuth(<SidebarNav />, ["announcement.view", "family.view"]);
    expect(screen.queryByRole("link", { name: "المتابعات" })).toBeNull();
  });

  it("the list page renders inside a Suspense boundary", async () => {
    mockGets({ [OPEN_LIST]: page([row()]), [SUMMARY]: { data: COUNTS } });
    withAuth(<FollowupsPage />);
    expect(await screen.findByRole("heading", { name: "المتابعات" })).toBeInTheDocument();
  });
});

// ------------------------------------------------------------------ inbox

describe("the Staff inbox", () => {
  it("lists OPEN follow-ups by default with the summary counters and status indicators", async () => {
    mockGets({ [OPEN_LIST]: page([row(), row({ id: "8c1e2c3d-4f5a-4b6c-8d7e-9f0a1b2c3d4f", stage: "SECOND", family: { family_code: "FAM-000124", head_name: "ليلى الاختبار" } })]), [SUMMARY]: { data: COUNTS } });
    withAuth(<StaffFollowupList />);

    const list = await screen.findByRole("list", { name: "المتابعات" });
    const rows = within(list).getAllByRole("link");
    expect(rows).toHaveLength(2);
    expect(rows[0]).toHaveAttribute("href", `/followups/${UUID}`);
    expect(rows[0]).toHaveTextContent("FAM-000123");
    expect(rows[0]).toHaveTextContent("سالم الاختبار");
    expect(rows[0].querySelector("[data-followup-status='OPEN']")).not.toBeNull();
    expect(rows[0].querySelector("[data-followup-stage='FIRST']")).toHaveTextContent("المرحلة الأولى");
    expect(rows[1].querySelector("[data-followup-stage='SECOND']")).toHaveTextContent("المرحلة الثانية");
    expect(rows[0].querySelector("[data-announcement-level='IMPORTANT']")).not.toBeNull();

    const summary = document.querySelector("[data-followup-summary='staff']") as HTMLElement;
    await waitFor(() => expect(within(summary).getByText((12).toLocaleString("ar"))).toBeInTheDocument());
    expect(within(summary).getByText("انتهت مدة إعلانها")).toBeInTheDocument();
    expect(summary.querySelector("[data-followup-closed-30]")).toHaveTextContent(`أُغلقت يدويًا ${(6).toLocaleString("ar")}`);
    expect(screen.getByRole("button", { name: "المفتوحة" })).toHaveAttribute("aria-pressed", "true");
  });

  it("shows only public fields in list rows: no note, phone, National ID or internal id", async () => {
    const leaky = { ...row(), note: "ملاحظة سرية", phone: "0599000000", national_id: "123456789", family_id: 77, assignment_id: 9 } as FollowupSummary;
    mockGets({ [OPEN_LIST]: page([leaky]), [SUMMARY]: { data: COUNTS } });
    withAuth(<StaffFollowupList />);
    const list = await screen.findByRole("list", { name: "المتابعات" });
    for (const secret of ["ملاحظة سرية", "0599000000", "123456789", "77"]) expect(list.textContent).not.toContain(secret);
    expect(browserStorageDump()).not.toContain("FAM-000123");
  });

  it("builds every filter into the server query (status, stage, level, outcome, family code, page)", () => {
    expect(followupListPath("staff", { status: "OPEN" })).toBe(`${BASE}?status=OPEN`);
    expect(followupListPath("staff", { status: "CLOSED", stage: "SECOND", level: "IMPORTANT", outcome: "CLOSED_MANUALLY", family_code: "FAM-000123", page: 3 })).toBe(
      `${BASE}?status=CLOSED&stage=SECOND&level=IMPORTANT&outcome=CLOSED_MANUALLY&family_code=FAM-000123&page=3`
    );
    // An outcome only applies to CLOSED; the Staff side never sends ownership.
    expect(followupListPath("staff", { status: "OPEN", outcome: "SATISFIED", ownership: "mine" })).toBe(`${BASE}?status=OPEN`);
  });

  it("reads the filters from the URL and asks the server for exactly them", async () => {
    nav.search = "status=CLOSED&stage=SECOND&level=IMPORTANT&outcome=EXPIRED&family_code=FAM-000123&page=2";
    const get = mockGets({
      [`${BASE}?status=CLOSED&stage=SECOND&level=IMPORTANT&outcome=EXPIRED&family_code=FAM-000123&page=2`]: page(
        [row({ status: "CLOSED", outcome: "EXPIRED", closed_at: "2026-10-21T09:00:00+00:00" })],
        { current_page: 2, last_page: 3, total: 41 }
      ),
      [SUMMARY]: { data: COUNTS },
    });
    withAuth(<StaffFollowupList />);
    const list = await screen.findByRole("list", { name: "المتابعات" });
    expect(list.querySelector("[data-followup-outcome='EXPIRED']")).toHaveTextContent("انتهت مدة الإعلان");
    expect(get).toHaveBeenCalledTimes(2);
    expect(screen.getByRole("button", { name: "المغلقة" })).toHaveAttribute("aria-pressed", "true");
    expect(screen.getByRole("combobox", { name: "نتيجة الإغلاق" })).toBeInTheDocument();
  });

  it("changes status, filters, family code and page through the URL (page resets on a filter)", async () => {
    nav.search = "page=2";
    mockGets({ [`${BASE}?status=OPEN&page=2`]: page([row()], { current_page: 2, last_page: 3, total: 45 }), [SUMMARY]: { data: COUNTS } });
    withAuth(<StaffFollowupList />);
    await screen.findByRole("list", { name: "المتابعات" });

    await userEvent.click(screen.getByRole("button", { name: "المغلقة" }));
    expect(router.push).toHaveBeenLastCalledWith("/followups?status=CLOSED", { scroll: false });

    await chooseOption("المرحلة", "المرحلة الثانية");
    expect(router.push).toHaveBeenLastCalledWith("/followups?stage=SECOND", { scroll: false });

    await userEvent.type(screen.getByLabelText("رمز الأسرة"), " FAM-000123 ");
    await userEvent.click(screen.getByRole("button", { name: "بحث برمز الأسرة" }));
    expect(router.push).toHaveBeenLastCalledWith("/followups?family_code=FAM-000123", { scroll: false });

    await userEvent.click(screen.getByRole("button", { name: /التالي/ }));
    expect(router.push).toHaveBeenLastCalledWith("/followups?page=3", { scroll: false });
  });

  it("the reset keeps OPEN / CLOSED and clears the filters", async () => {
    nav.search = "status=CLOSED&stage=FIRST";
    mockGets({ [`${BASE}?status=CLOSED&stage=FIRST`]: page([]), [SUMMARY]: { data: COUNTS } });
    withAuth(<StaffFollowupList />);
    expect(await screen.findByText("لا توجد متابعات مطابقة")).toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: "إعادة الضبط" }));
    expect(router.push).toHaveBeenLastCalledWith("/followups?status=CLOSED", { scroll: false });
  });

  it("shows an empty state, a 403 state and a retryable error", async () => {
    mockGets({ [OPEN_LIST]: page([]), [SUMMARY]: { data: COUNTS } });
    const { unmount } = withAuth(<StaffFollowupList />);
    expect(await screen.findByText("لا توجد متابعات مفتوحة")).toBeInTheDocument();
    unmount();

    mockGets({ [OPEN_LIST]: refusal(403), [SUMMARY]: refusal(403) });
    const second = withAuth(<StaffFollowupList />);
    expect(await screen.findByText("لا تملك صلاحية عرض المتابعات.")).toBeInTheDocument();
    second.unmount();

    let calls = 0;
    mockGets({ [OPEN_LIST]: () => (++calls === 1 ? refusal(429) : page([row()])), [SUMMARY]: { data: COUNTS } });
    withAuth(<StaffFollowupList />);
    expect(await screen.findByText("محاولات كثيرة خلال وقت قصير. انتظر دقيقة ثم حاول مجددًا.")).toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: "إعادة المحاولة" }));
    expect(await screen.findByRole("list", { name: "المتابعات" })).toBeInTheDocument();
  });

  it("keeps the list usable when only the summary fails", async () => {
    mockGets({ [OPEN_LIST]: page([row()]), [SUMMARY]: refusal(503, "FOLLOWUP_BUSY") });
    withAuth(<StaffFollowupList />);
    expect(await screen.findByRole("list", { name: "المتابعات" })).toBeInTheDocument();
    await waitFor(() => expect(document.querySelectorAll("[data-followup-summary] [data-stat]")[0]).toHaveTextContent("—"));
  });

  it("is laid out for phones first (stacked filters, full-width controls)", async () => {
    mockGets({ [OPEN_LIST]: page([row()]), [SUMMARY]: { data: COUNTS } });
    withAuth(<StaffFollowupList />);
    await screen.findByRole("list", { name: "المتابعات" });
    expect(screen.getByRole("search", { name: "تصفية المتابعات" }).className).toContain("flex-col");
    expect(screen.getByRole("search", { name: "تصفية المتابعات" }).className).toContain("sm:flex-row");
    expect(screen.getByRole("combobox", { name: "المرحلة" }).className).toContain("w-full");
  });
});

// ----------------------------------------------------------------- detail

describe("the Staff detail", () => {
  it("shows the follow-up, the recipient's own state, the ownership KIND and the history with names and notes", async () => {
    mockGets(detailRoutes());
    withAuth(<StaffFollowupDetail id={UUID} />);
    expect(await screen.findByRole("heading", { name: /سالم الاختبار/ })).toBeInTheDocument();
    expect(document.querySelector("[data-followup-ownership='CENTRAL']")).toHaveTextContent("مركزية");
    expect(screen.getByText("لم تؤكد الأسرة الاطلاع على الإعلان")).toBeInTheDocument();

    const history = await screen.findByRole("list", { name: "سجل المتابعة" });
    const items = within(history).getAllByRole("listitem");
    expect(items[0]).toHaveTextContent("فُتحت المتابعة");
    expect(items[0]).toHaveTextContent("النظام");
    expect(items[1]).toHaveTextContent("موظف المتابعة");
    expect(items[1]).toHaveTextContent("اتصال دون رد");
    expect(items[1].querySelector("[data-followup-event-note]")).toHaveAttribute("dir", "auto");
    // RTL: codes are isolated LTR.
    expect(screen.getByText("FAM-000123").closest("bdi")).toHaveAttribute("dir", "ltr");
  });

  it("offers contact and closure with notification-followup.manage; read-only without it", async () => {
    mockGets(detailRoutes());
    const { unmount } = withAuth(<StaffFollowupDetail id={UUID} />);
    expect(await screen.findByRole("form", { name: "تسجيل تواصل" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "إغلاق المتابعة يدويًا" })).toBeInTheDocument();
    expect(screen.getAllByText(/لا تكتب فيها معلومات صحية أو أرقام هويات/)).not.toHaveLength(0);
    unmount();

    mockGets(detailRoutes());
    withAuth(<StaffFollowupDetail id={UUID} />, VIEW);
    expect(await screen.findByText("لا تملك صلاحية إدارة المتابعات؛ يمكنك الاطلاع فقط.")).toBeInTheDocument();
    expect(screen.queryByRole("form", { name: "تسجيل تواصل" })).toBeNull();
    expect(screen.queryByRole("button", { name: "إغلاق المتابعة يدويًا" })).toBeNull();
  });

  it("offers nothing on a CLOSED follow-up or once the message expired", async () => {
    mockGets(detailRoutes(detail({ status: "CLOSED", outcome: "SATISFIED", closed_at: "2026-10-21T08:00:00+00:00" })));
    const { unmount } = withAuth(<StaffFollowupDetail id={UUID} />);
    expect(await screen.findByText("مغلقة · استجابت الأسرة")).toBeInTheDocument();
    expect(screen.queryByRole("form", { name: "تسجيل تواصل" })).toBeNull();
    expect(document.querySelector("[data-followup-read-only]")).toBeNull();
    unmount();

    mockGets(detailRoutes(detail({ message: { notice_code: "NTF-000042", level: "IMPORTANT", title: "موعد", expires_at: "2020-01-01T00:00:00+00:00" } })));
    withAuth(<StaffFollowupDetail id={UUID} />);
    await waitFor(() => expect(document.querySelector("[data-followup-expired]")).toHaveTextContent("انتهت مدة الإعلان"));
    expect(screen.queryByRole("form", { name: "تسجيل تواصل" })).toBeNull();
  });

  it("shows a missing follow-up as unavailable", async () => {
    mockGets({ [DETAIL]: refusal(404, "FOLLOWUP_NOT_FOUND"), [HISTORY]: refusal(404, "FOLLOWUP_NOT_FOUND") });
    withAuth(<StaffFollowupDetail id={UUID} />);
    expect(await screen.findByRole("alert")).toHaveTextContent("هذه المتابعة غير متاحة.");
    expect(screen.getByRole("link", { name: "العودة إلى المتابعات" })).toHaveAttribute("href", "/followups");
  });
});

// ---------------------------------------------------------------- contact

const recorded = (replayed = false) => ({
  data: { type: "CONTACT_RECORDED", actor: { side: "STAFF", name: "موظف تجريبي" }, reason_code: "VISITED", stage: "FIRST", outcome: null, note: null, occurred_at: "2026-10-21T10:00:00+00:00" },
  meta: { replayed },
});

describe("recording a contact", () => {
  it("validates the outcome, sends a uuid client_reference and invalidates list, summary, detail and history", async () => {
    mockGets(detailRoutes());
    const post = vi.spyOn(apiClient, "post").mockResolvedValue(recorded() as never);
    const { client } = withAuth(<StaffFollowupDetail id={UUID} />);
    const invalidate = vi.spyOn(client, "invalidateQueries");
    const form = await screen.findByRole("form", { name: "تسجيل تواصل" });

    await userEvent.click(within(form).getByRole("button", { name: "تسجيل التواصل" }));
    expect(await within(form).findByText("اختر نتيجة التواصل")).toBeInTheDocument();
    expect(post).not.toHaveBeenCalled();

    await chooseOption("نتيجة التواصل", "زيارة", form);
    await userEvent.type(within(form).getByLabelText(/ملاحظة داخلية/), "  زرنا البيت  ");
    await userEvent.click(within(form).getByRole("button", { name: "تسجيل التواصل" }));

    expect(await screen.findByText("سُجّل التواصل. تبقى المتابعة مفتوحة حتى تُغلق.")).toBeInTheDocument();
    const [body] = contactBodies();
    expect(body.outcome).toBe("VISITED");
    expect(body.note).toBe("زرنا البيت");
    expect(String(body.client_reference)).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
    const keys = invalidate.mock.calls.map(([filters]) => JSON.stringify(filters?.queryKey));
    expect(keys).toEqual(
      expect.arrayContaining([
        JSON.stringify([...followupKeys.all("staff"), "list"]),
        JSON.stringify(followupKeys.summary("staff")),
        JSON.stringify(followupKeys.detail("staff", UUID)),
        JSON.stringify(followupKeys.history("staff", UUID)),
      ])
    );
    // The form is reset for the next contact.
    expect(within(form).getByLabelText(/ملاحظة داخلية/)).toHaveValue("");
  });

  it("re-sends the SAME reference after a lost response, a 503 or a 429 — never a new one, never automatically", async () => {
    mockGets(detailRoutes());
    const post = vi
      .spyOn(apiClient, "post")
      .mockRejectedValueOnce(new TypeError("Failed to fetch"))
      .mockRejectedValueOnce(refusal(503, "FOLLOWUP_BUSY"))
      .mockRejectedValueOnce(refusal(429))
      .mockResolvedValueOnce(recorded(true) as never);
    withAuth(<StaffFollowupDetail id={UUID} />);
    const form = await screen.findByRole("form", { name: "تسجيل تواصل" });
    await chooseOption("نتيجة التواصل", "زيارة", form);
    await userEvent.click(within(form).getByRole("button", { name: "تسجيل التواصل" }));

    expect(await within(form).findByText(/لن يُسجَّل مرتين/)).toBeInTheDocument();
    expect(post).toHaveBeenCalledTimes(1);
    await userEvent.click(within(form).getByRole("button", { name: "إعادة الإرسال" }));
    expect(await within(form).findByText("النظام مشغول حاليًا ولم يُحفظ شيء. أعد المحاولة بعد لحظات.")).toBeInTheDocument();
    await userEvent.click(within(form).getByRole("button", { name: "إعادة الإرسال" }));
    expect(await within(form).findByText(/محاولات كثيرة/)).toBeInTheDocument();
    await userEvent.click(within(form).getByRole("button", { name: "إعادة الإرسال" }));

    expect(await screen.findByText("سبق تسجيل هذا التواصل؛ لم يُسجَّل مرة أخرى.")).toBeInTheDocument();
    const references = contactBodies().map((b) => b.client_reference);
    expect(references).toHaveLength(4);
    expect(new Set(references).size).toBe(1);
  });

  it("a changed outcome or note is a new contact with a new reference; a success clears the reference", async () => {
    mockGets(detailRoutes());
    vi.spyOn(apiClient, "post")
      .mockRejectedValueOnce(new TypeError("Failed to fetch"))
      .mockResolvedValueOnce(recorded() as never)
      .mockResolvedValueOnce(recorded() as never);
    withAuth(<StaffFollowupDetail id={UUID} />);
    const form = await screen.findByRole("form", { name: "تسجيل تواصل" });
    await chooseOption("نتيجة التواصل", "زيارة", form);
    await userEvent.click(within(form).getByRole("button", { name: "تسجيل التواصل" }));
    await within(form).findByText(/لن يُسجَّل مرتين/);

    await userEvent.type(within(form).getByLabelText(/ملاحظة داخلية/), "تفاصيل");
    await userEvent.click(within(form).getByRole("button", { name: "إعادة الإرسال" }));
    await screen.findByText("سُجّل التواصل. تبقى المتابعة مفتوحة حتى تُغلق.");

    await chooseOption("نتيجة التواصل", "زيارة", form);
    await userEvent.click(within(form).getByRole("button", { name: "تسجيل التواصل" }));
    await waitFor(() => expect(contactBodies()).toHaveLength(3));
    const [first, second, third] = contactBodies().map((b) => b.client_reference);
    expect(second).not.toBe(first);
    expect(third).not.toBe(second);
  });

  it("drops the reference on a 409 reference conflict, and maps 422 to the field", async () => {
    mockGets(detailRoutes());
    vi.spyOn(apiClient, "post")
      .mockRejectedValueOnce(refusal(409, "FOLLOWUP_CLIENT_REFERENCE_CONFLICT"))
      .mockRejectedValueOnce(refusal(422, undefined, { errors: { note: ["الملاحظة طويلة جدًا."] } }));
    withAuth(<StaffFollowupDetail id={UUID} />);
    const form = await screen.findByRole("form", { name: "تسجيل تواصل" });
    await chooseOption("نتيجة التواصل", "أخرى", form);
    await userEvent.click(within(form).getByRole("button", { name: "تسجيل التواصل" }));
    expect(await within(form).findByText(/مرجعه استُخدم لتواصل مختلف/)).toBeInTheDocument();
    await userEvent.click(within(form).getByRole("button", { name: "تسجيل التواصل" }));
    expect(await within(form).findByText("الملاحظة طويلة جدًا.")).toBeInTheDocument();
    const [a, b] = contactBodies().map((x) => x.client_reference);
    expect(a).not.toBe(b);
  });

  it("a state or ownership 409 is shown on the page and the follow-up is re-read", async () => {
    let reads = 0;
    mockGets({ [DETAIL]: () => ({ data: ++reads === 1 ? detail() : detail({ status: "CLOSED", outcome: "SATISFIED" }) }), [HISTORY]: { data: EVENTS } });
    vi.spyOn(apiClient, "post").mockRejectedValueOnce(refusal(409, "FOLLOWUP_NOT_OPEN"));
    withAuth(<StaffFollowupDetail id={UUID} />);
    const form = await screen.findByRole("form", { name: "تسجيل تواصل" });
    await chooseOption("نتيجة التواصل", "زيارة", form);
    await userEvent.click(within(form).getByRole("button", { name: "تسجيل التواصل" }));
    expect(await screen.findByText("أُغلقت هذه المتابعة بالفعل. حدّثنا الصفحة بحالتها الحالية.")).toBeInTheDocument();
    expect(await screen.findByText("مغلقة · استجابت الأسرة")).toBeInTheDocument();
    expect(screen.queryByRole("form", { name: "تسجيل تواصل" })).toBeNull();
  });
});

// ---------------------------------------------------------------- closure

describe("closing manually", () => {
  it("asks for confirmation and a reason, then closes and shows the server's state", async () => {
    let reads = 0;
    mockGets({ [DETAIL]: () => ({ data: ++reads === 1 ? detail() : detail({ status: "CLOSED", outcome: "CLOSED_MANUALLY", close_reason: "FAMILY_UNREACHABLE" }) }), [HISTORY]: { data: EVENTS } });
    const post = vi.spyOn(apiClient, "post").mockResolvedValue({ data: detail({ status: "CLOSED", outcome: "CLOSED_MANUALLY", close_reason: "FAMILY_UNREACHABLE" }) } as never);
    withAuth(<StaffFollowupDetail id={UUID} />);

    await userEvent.click(await screen.findByRole("button", { name: "إغلاق المتابعة يدويًا" }));
    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByText(/لا يُعدّ الإغلاق اطلاعًا أو تأكيدًا من الأسرة/)).toBeInTheDocument();
    expect(post).not.toHaveBeenCalled();

    await userEvent.click(within(dialog).getByRole("button", { name: "تأكيد الإغلاق" }));
    expect(await within(dialog).findByText("اختر سبب الإغلاق")).toBeInTheDocument();
    expect(post).not.toHaveBeenCalled();

    await chooseOption("سبب الإغلاق", "تعذّر الوصول إلى الأسرة", dialog);
    await userEvent.click(within(dialog).getByRole("button", { name: "تأكيد الإغلاق" }));
    expect(await screen.findByText("أُغلقت المتابعة يدويًا.")).toBeInTheDocument();
    expect(post).toHaveBeenCalledWith(`${DETAIL}/close`, { reason: "FAMILY_UNREACHABLE", note: null });
    expect(screen.queryByRole("dialog")).toBeNull();
    expect(document.querySelector("[data-followup-outcome='CLOSED_MANUALLY']")).not.toBeNull();
  });

  it("is never retried automatically: a 503 keeps the dialog with retry guidance; a lost response re-reads the state", async () => {
    mockGets(detailRoutes());
    const post = vi.spyOn(apiClient, "post").mockRejectedValueOnce(refusal(503, "FOLLOWUP_BUSY")).mockRejectedValueOnce(new TypeError("Failed to fetch"));
    const { client } = withAuth(<StaffFollowupDetail id={UUID} />);
    const invalidate = vi.spyOn(client, "invalidateQueries");
    await userEvent.click(await screen.findByRole("button", { name: "إغلاق المتابعة يدويًا" }));
    const dialog = await screen.findByRole("dialog");
    await chooseOption("سبب الإغلاق", "لا تنطبق المتابعة", dialog);
    await userEvent.click(within(dialog).getByRole("button", { name: "تأكيد الإغلاق" }));

    expect(await within(dialog).findByText("النظام مشغول حاليًا ولم يُحفظ شيء. أعد المحاولة بعد لحظات.")).toBeInTheDocument();
    await new Promise((r) => setTimeout(r, 50));
    expect(post).toHaveBeenCalledTimes(1);

    await userEvent.click(within(dialog).getByRole("button", { name: "تأكيد الإغلاق" }));
    expect(await within(dialog).findByText(/قد لا يكون الإغلاق قد تم/)).toBeInTheDocument();
    expect(post).toHaveBeenCalledTimes(2);
    expect(invalidate.mock.calls.map(([f]) => JSON.stringify(f?.queryKey))).toContain(JSON.stringify(followupKeys.detail("staff", UUID)));
  });

  it("a FOLLOWUP_NOT_OPEN closes the dialog and explains it on the page", async () => {
    mockGets(detailRoutes());
    vi.spyOn(apiClient, "post").mockRejectedValueOnce(refusal(409, "FOLLOWUP_NOT_OPEN"));
    withAuth(<StaffFollowupDetail id={UUID} />);
    await userEvent.click(await screen.findByRole("button", { name: "إغلاق المتابعة يدويًا" }));
    const dialog = await screen.findByRole("dialog");
    await chooseOption("سبب الإغلاق", "لا تنطبق المتابعة", dialog);
    await userEvent.click(within(dialog).getByRole("button", { name: "تأكيد الإغلاق" }));
    expect(await screen.findByText("أُغلقت هذه المتابعة بالفعل. حدّثنا الصفحة بحالتها الحالية.")).toBeInTheDocument();
    await waitFor(() => expect(screen.queryByRole("dialog")).toBeNull());
  });

  it("maps 403 and the expiry refusal to safe messages", async () => {
    mockGets(detailRoutes());
    vi.spyOn(apiClient, "post").mockRejectedValueOnce(refusal(403, "FOLLOWUP_ACTOR_NOT_ALLOWED"));
    withAuth(<StaffFollowupDetail id={UUID} />);
    await userEvent.click(await screen.findByRole("button", { name: "إغلاق المتابعة يدويًا" }));
    const dialog = await screen.findByRole("dialog");
    await chooseOption("سبب الإغلاق", "الأسرة غير نشطة", dialog);
    await userEvent.click(within(dialog).getByRole("button", { name: "تأكيد الإغلاق" }));
    expect(await within(dialog).findByText("لا تملك صلاحية إدارة هذه المتابعة. يمكنك الاطلاع عليها فقط.")).toBeInTheDocument();
  });
});
