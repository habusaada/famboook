import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { FamilyBottomNav } from "@/components/family/bottom-nav";
import { FamilyNewRequest } from "@/components/family/requests/family-new-request";
import { FamilyRequestDetail } from "@/components/family/requests/family-request-detail";
import { FamilyRequests } from "@/components/family/requests/family-requests";
import { ApiError, apiClient } from "@/lib/api/client";
import {
  type FamilyChangeRequest,
  type FamilyChangeRequestSummary,
  type FamilyChangeRequestTypes,
  type FamilyWorkflowEvent,
  familyChangeRequestKeys,
  familyChangeRequestQuery,
} from "@/lib/api/family-change-requests";
import { FAMILY_ME_QUERY_KEY } from "@/lib/api/family-auth";
import type { PaginatedResponse } from "@/lib/types/api/family";
import { browserStorageDump, familyUser, renderWithClient } from "./helpers";

// PWA-5f: the household head's Change Requests over the PWA-5e Family API.
// Fixtures mirror FamilyChangeRequestSummaryResource / FamilyChangeRequestResource /
// FamilyWorkflowEventResource / ChangeRequestOutcomeResource and the /types
// endpoint. Synthetic data only.

const router = { push: vi.fn(), replace: vi.fn() };
const nav = { search: "", pathname: "/family/requests" };
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
  nav.search = "";
  nav.pathname = "/family/requests";
});

const UUID = "5b1e2c3d-4f5a-4b6c-8d7e-9f0a1b2c3d4e";
const UUID_2 = "7c2d3e4f-5a6b-4c7d-8e9f-0a1b2c3d4e5f";
const LIST_PATH = "/api/v1/family/change-requests";
const DETAIL_PATH = `${LIST_PATH}/${UUID}`;
const TYPES_PATH = `${LIST_PATH}/types`;

function summary(overrides: Partial<FamilyChangeRequestSummary> = {}): FamilyChangeRequestSummary {
  return {
    id: UUID,
    request_code: "CRQ-000042",
    type: "RESIDENCE_UPDATE",
    type_available: false,
    status: "SUBMITTED",
    submitted_at: "2026-10-01T09:00:00+03:00",
    approved_at: null,
    rejected_at: null,
    applied_at: null,
    cancelled_at: null,
    available_actions: ["cancel"],
    ...overrides,
  };
}

function page(rows: FamilyChangeRequestSummary[], meta: Partial<PaginatedResponse<unknown>["meta"]> = {}): PaginatedResponse<FamilyChangeRequestSummary> {
  return {
    data: rows,
    links: { first: null, last: null, prev: null, next: null },
    meta: { current_page: 1, from: rows.length ? 1 : null, last_page: 1, path: LIST_PATH, per_page: 15, to: rows.length || null, total: rows.length, ...meta },
  };
}

function event(overrides: Partial<FamilyWorkflowEvent> = {}): FamilyWorkflowEvent {
  return {
    event_type: "SUBMITTED",
    from_status: null,
    to_status: "SUBMITTED",
    actor_side: "FAMILY",
    reason_code: null,
    public_message: null,
    created_at: "2026-10-01T09:00:00+03:00",
    ...overrides,
  };
}

const ROWS = { rows: [{ label: "مكان السكن", current: "حي الاختبار", proposed: "حي التجربة" }] };

function detail(overrides: Partial<FamilyChangeRequest> = {}): FamilyChangeRequest {
  return {
    id: UUID,
    request_code: "CRQ-000042",
    type: "RESIDENCE_UPDATE",
    type_available: true,
    status: "SUBMITTED",
    submitted_at: "2026-10-01T09:00:00+03:00",
    reason: "انتقلنا إلى سكن جديد.",
    presentation: ROWS,
    approved_at: null,
    applied_at: null,
    cancelled_at: null,
    rejection: null,
    timeline: [event()],
    available_actions: ["cancel"],
    ...overrides,
  };
}

const returned = () =>
  detail({
    status: "RETURNED_FOR_CLARIFICATION",
    available_actions: ["resubmit", "cancel"],
    timeline: [
      event(),
      event({ event_type: "REVIEW_STARTED", from_status: "SUBMITTED", to_status: "UNDER_REVIEW", actor_side: "STAFF", created_at: "2026-10-02T09:00:00+03:00" }),
      event({
        event_type: "RETURNED",
        from_status: "UNDER_REVIEW",
        to_status: "RETURNED_FOR_CLARIFICATION",
        actor_side: "STAFF",
        public_message: "يرجى توضيح تاريخ الانتقال.",
        created_at: "2026-10-03T09:00:00+03:00",
      }),
    ],
  });

type Reply = unknown | Error | (() => Promise<unknown>);
async function answer(value: Reply) {
  if (typeof value === "function") return (value as () => Promise<unknown>)();
  if (value instanceof Error) throw value;
  return value;
}

/** Routes the Family Change Request GETs; any other GET fails the test. */
function mockGet({ list = page([summary()]) as Reply, detail: d = { data: detail() } as Reply, types = { data: [], meta: { submission_enabled: false } } as Reply } = {}) {
  return vi.spyOn(apiClient, "get").mockImplementation(async (path: string) => {
    if (path === TYPES_PATH) return answer(types) as never;
    if (path === DETAIL_PATH) return answer(d) as never;
    if (path === LIST_PATH || path.startsWith(`${LIST_PATH}?`)) return answer(list) as never;
    throw new Error(`Unexpected GET: ${path}`);
  });
}

const outcome = (status: string, replayed = false) => ({ data: { id: UUID, request_code: "CRQ-000042", status, replayed } });

// ======================================================================= list

describe("«طلباتي» — the Family request history", () => {
  it("shows the title, description and each request's code, type, status, date and link", async () => {
    mockGet({
      list: page([
        summary(),
        summary({ id: UUID_2, request_code: "CRQ-000043", status: "APPLIED", applied_at: "2026-10-05T09:00:00+03:00", available_actions: [] }),
      ]),
    });
    renderWithClient(<FamilyRequests />);

    expect(screen.getByRole("heading", { level: 1, name: "طلباتي" })).toBeInTheDocument();
    expect(screen.getByText("تابع طلبات تحديث بيانات أسرتك وحالة مراجعتها.")).toBeInTheDocument();
    const list = await screen.findByRole("list", { name: "طلبات الأسرة" });
    const items = within(list).getAllByRole("link");
    expect(items).toHaveLength(2);
    expect(items[0]).toHaveAttribute("href", `/family/requests/${UUID}`);
    expect(items[0]).toHaveTextContent("CRQ-000042");
    expect(items[0]).toHaveTextContent("تحديث بيانات السكن");
    expect(items[0]).toHaveTextContent("تم تقديم الطلب");
    expect(items[0]).toHaveTextContent(/قُدّم/);
    expect(items[1]).toHaveTextContent("تم تطبيق التعديل");
    expect(items[1]).toHaveTextContent(/طُبّق/);
  });

  it("asks for the whole Family's history: no submitter, family or person filter is ever sent", async () => {
    const get = mockGet();
    renderWithClient(<FamilyRequests />);
    await screen.findByRole("list", { name: "طلبات الأسرة" });

    expect(get).toHaveBeenCalledWith(LIST_PATH);
    for (const [path] of get.mock.calls) expect(String(path).split("?")[1] ?? "").not.toMatch(/submitted_by|family|person|member|mine/);
  });

  it("labels APPROVED as awaiting application, never as an applied update", async () => {
    mockGet({ list: page([summary({ status: "APPROVED", approved_at: "2026-10-04T09:00:00+03:00", available_actions: [] })]) });
    renderWithClient(<FamilyRequests />);

    const row = (await screen.findAllByRole("link", { name: /عرض الطلب/ }))[0];
    expect(row).toHaveTextContent("معتمد بانتظار التطبيق");
    expect(row).not.toHaveTextContent("تم تطبيق التعديل");
  });

  it("paginates on the server through the URL", async () => {
    nav.search = "page=2";
    const get = mockGet({ list: page([summary()], { current_page: 2, last_page: 3, total: 31 }) });
    renderWithClient(<FamilyRequests />);

    const pager = await screen.findByRole("navigation", { name: "التنقل بين الصفحات" });
    expect(get).toHaveBeenCalledWith(`${LIST_PATH}?page=2`);
    await userEvent.click(within(pager).getByRole("button", { name: /التالي/ }));
    expect(router.push).toHaveBeenLastCalledWith("/family/requests?page=3", { scroll: false });
    await userEvent.click(within(pager).getByRole("button", { name: /السابق/ }));
    expect(router.push).toHaveBeenLastCalledWith("/family/requests", { scroll: false });
  });

  it("hides the pager on a single page", async () => {
    mockGet();
    renderWithClient(<FamilyRequests />);
    await screen.findByRole("list", { name: "طلبات الأسرة" });
    expect(screen.queryByRole("navigation", { name: "التنقل بين الصفحات" })).not.toBeInTheDocument();
  });

  it("filters by status and type through the URL, sending only those filters", async () => {
    nav.search = "status=RETURNED_FOR_CLARIFICATION&type=RESIDENCE_UPDATE&page=2&submitted_by=9";
    const get = mockGet();
    renderWithClient(<FamilyRequests />);
    await screen.findByRole("list", { name: "طلبات الأسرة" });

    expect(get).toHaveBeenCalledWith(`${LIST_PATH}?status=RETURNED_FOR_CLARIFICATION&type=RESIDENCE_UPDATE&page=2`);
    expect(familyChangeRequestQuery({ status: "APPLIED" })).toBe("status=APPLIED");

    await userEvent.click(screen.getByRole("combobox", { name: "الحالة" }));
    await userEvent.click(await screen.findByRole("option", { name: "تم تطبيق التعديل" }));
    // A new filter returns to the first page.
    expect(router.push).toHaveBeenLastCalledWith("/family/requests?status=APPLIED&type=RESIDENCE_UPDATE&submitted_by=9", { scroll: false });
  });

  it("ignores unknown filter values in the URL", async () => {
    nav.search = "status=HACKED&type=NOPE";
    const get = mockGet();
    renderWithClient(<FamilyRequests />);
    await screen.findByRole("list", { name: "طلبات الأسرة" });
    expect(get).toHaveBeenCalledWith(LIST_PATH);
  });

  it("shows an empty state with a link to a new request", async () => {
    mockGet({ list: page([]) });
    renderWithClient(<FamilyRequests />);

    expect(await screen.findByText("لا توجد طلبات لأسرتك بعد")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /طلب جديد/ })).toHaveAttribute("href", "/family/requests/new");
  });

  it("shows a no-match state with a reset when filters find nothing", async () => {
    nav.search = "status=APPLIED";
    mockGet({ list: page([]) });
    renderWithClient(<FamilyRequests />);

    expect(await screen.findByText("لا توجد طلبات مطابقة")).toBeInTheDocument();
    const resets = screen.getAllByRole("button", { name: /إعادة الضبط/ });
    await userEvent.click(resets[resets.length - 1]);
    expect(router.push).toHaveBeenLastCalledWith("/family/requests", { scroll: false });
  });

  it("shows a loading state, then an error with retry", async () => {
    let fail = true;
    mockGet({ list: () => (fail ? Promise.reject(new ApiError(500, { message: "x" })) : Promise.resolve(page([summary()]))) });
    renderWithClient(<FamilyRequests />);

    expect(screen.getByText("جارٍ تحميل الطلبات")).toBeInTheDocument();
    expect(await screen.findByRole("alert")).toHaveTextContent("تعذّر تحميل الطلبات");
    fail = false;
    await userEvent.click(screen.getByRole("button", { name: /إعادة المحاولة/ }));
    expect(await screen.findByRole("list", { name: "طلبات الأسرة" })).toBeInTheDocument();
  });

  it("keeps long Arabic text inside the card", async () => {
    mockGet();
    renderWithClient(<FamilyRequests />);
    const row = (await screen.findAllByRole("link", { name: /عرض الطلب/ }))[0];
    expect(row.querySelector(".min-w-0")).not.toBeNull();
  });

  it("leaves 401 and 403 to the Family gate (no error card of its own)", async () => {
    for (const status of [401, 403]) {
      mockGet({ list: new ApiError(status, { message: "x" }) });
      const { client, unmount } = renderWithClient(<FamilyRequests />);
      await waitFor(() => expect(client.getQueryState(familyChangeRequestKeys.list({ page: 1 }))?.status).toBe("error"));
      expect(screen.queryByText("تعذّر تحميل الطلبات")).not.toBeInTheDocument();
      unmount();
    }
  });
});

// ===================================================================== detail

describe("a Family request's detail", () => {
  it("shows the status, code, reason, proposal and timeline in order", async () => {
    mockGet({ detail: { data: returned() } });
    renderWithClient(<FamilyRequestDetail id={UUID} />);

    expect(await screen.findByRole("heading", { level: 1, name: "تحديث بيانات السكن" })).toBeInTheDocument();
    expect(screen.getByText("CRQ-000042")).toBeInTheDocument();
    expect(screen.getByText("مطلوب استكمال معلومات", { selector: "[data-family-request-status]" })).toBeInTheDocument();
    expect(screen.getByText("انتقلنا إلى سكن جديد.")).toBeInTheDocument();
    expect(screen.getAllByText("حي التجربة").length).toBeGreaterThan(0);

    const timeline = screen.getByRole("list", { name: "مسار الطلب" });
    const steps = within(timeline).getAllByRole("listitem");
    expect(steps.map((s) => s.getAttribute("data-family-timeline-event"))).toEqual(["SUBMITTED", "REVIEW_STARTED", "RETURNED"]);
    expect(steps[2]).toHaveTextContent("يرجى توضيح تاريخ الانتقال.");
  });

  it("says safely when there is no presentation", async () => {
    mockGet({ detail: { data: detail({ type_available: false, presentation: null }) } });
    renderWithClient(<FamilyRequestDetail id={UUID} />);
    expect(await screen.findByText("تفاصيل التعديل المطلوب غير متاحة للعرض حاليًا.")).toBeInTheDocument();
    expect(document.querySelector("[data-comparison]")).toBeNull();
  });

  it("never renders an unsupported presentation shape", async () => {
    mockGet({ detail: { data: detail({ presentation: { current: { secret_field: "SYNTHETIC-RAW" }, proposed: { secret_field: "SYNTHETIC-NEW" } } }) } });
    renderWithClient(<FamilyRequestDetail id={UUID} />);
    expect(await screen.findByText(/غير مدعوم في هذه الواجهة/)).toBeInTheDocument();
    expect(document.body.textContent).not.toMatch(/SYNTHETIC-RAW|SYNTHETIC-NEW|secret_field/);
  });

  it("explains that APPROVED is not yet applied", async () => {
    mockGet({ detail: { data: detail({ status: "APPROVED", approved_at: "2026-10-04T09:00:00+03:00", available_actions: [] }) } });
    renderWithClient(<FamilyRequestDetail id={UUID} />);
    expect(await screen.findByText("اعتُمد طلبك، لكن التعديل لم يُطبَّق على سجل أسرتك بعد.")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /إلغاء الطلب|إرسال الاستكمال/ })).not.toBeInTheDocument();
  });

  it("shows the applied outcome", async () => {
    mockGet({ detail: { data: detail({ status: "APPLIED", applied_at: "2026-10-05T09:00:00+03:00", available_actions: [] }) } });
    renderWithClient(<FamilyRequestDetail id={UUID} />);
    const outcome = await screen.findByRole("region", { name: "نتيجة الطلب" });
    expect(outcome).toHaveTextContent("طُبّق التعديل على سجل أسرتك");
  });

  it("shows the rejection reason and message", async () => {
    mockGet({
      detail: {
        data: detail({
          status: "REJECTED",
          available_actions: [],
          rejection: { reason_code: "CANNOT_VERIFY", message: "لم نتمكن من التحقق.", rejected_at: "2026-10-04T09:00:00+03:00" },
          timeline: [event(), event({ event_type: "REJECTED", from_status: "UNDER_REVIEW", to_status: "REJECTED", actor_side: "STAFF", reason_code: "CANNOT_VERIFY" })],
        }),
      },
    });
    renderWithClient(<FamilyRequestDetail id={UUID} />);
    const outcome = await screen.findByRole("region", { name: "نتيجة الطلب" });
    expect(outcome).toHaveTextContent("لم نتمكن من التحقق.");
    expect(outcome).toHaveTextContent("لم يتغير سجل أسرتك");
  });

  it("never shows internal notes, actor names, apply failures or internal ids", async () => {
    const leaky = {
      ...detail({ status: "APPROVED", available_actions: [] }),
      internal_note: "SYNTHETIC-INTERNAL-NOTE",
      apply_failure_count: 3,
      family_id: 991,
      timeline: [
        { ...event(), internal_note: "SYNTHETIC-INTERNAL-NOTE", actor: { name: "SYNTHETIC-REVIEWER" }, id: 77 },
        event({ event_type: "APPLY_FAILED", from_status: "APPROVED", to_status: "APPROVED", actor_side: "STAFF", public_message: "SYNTHETIC-APPLY-DIAGNOSTIC" }),
      ],
    };
    mockGet({ detail: { data: leaky } });
    renderWithClient(<FamilyRequestDetail id={UUID} />);
    await screen.findByRole("list", { name: "مسار الطلب" });

    expect(document.body.textContent).not.toMatch(/SYNTHETIC-INTERNAL-NOTE|SYNTHETIC-REVIEWER|SYNTHETIC-APPLY-DIAGNOSTIC|991/);
    expect(document.querySelector('[data-family-timeline-event="APPLY_FAILED"]')).toBeNull();
  });

  it("shows a not-found state for a 404", async () => {
    mockGet({ detail: new ApiError(404, { message: "Not Found" }) });
    renderWithClient(<FamilyRequestDetail id={UUID} />);
    expect(await screen.findByRole("alert")).toHaveTextContent("لم نجد هذا الطلب ضمن طلبات أسرتك.");
    expect(screen.getByRole("link", { name: "العودة إلى طلباتي" })).toHaveAttribute("href", "/family/requests");
  });

  it("leaves 401 and 403 to the Family gate", async () => {
    for (const status of [401, 403]) {
      mockGet({ detail: new ApiError(status, { message: "x" }) });
      const { client, unmount } = renderWithClient(<FamilyRequestDetail id={UUID} />);
      client.setQueryData(FAMILY_ME_QUERY_KEY, familyUser());
      await waitFor(() => expect(client.getQueryState(familyChangeRequestKeys.detail(UUID))?.status).toBe("error"));
      expect(screen.queryByRole("alert")).not.toBeInTheDocument();
      unmount();
    }
  });

  it("offers only the actions the server lists", async () => {
    mockGet({ detail: { data: detail({ available_actions: [] }) } });
    renderWithClient(<FamilyRequestDetail id={UUID} />);
    await screen.findByRole("heading", { level: 1 });
    expect(screen.queryByRole("button", { name: /إلغاء الطلب|إرسال الاستكمال/ })).not.toBeInTheDocument();
    expect(screen.queryByRole("region", { name: "الإجراء المطلوب" })).not.toBeInTheDocument();
  });
});

// =================================================================== resubmit

describe("responding to a clarification", () => {
  async function openResubmit() {
    renderWithClient(<FamilyRequestDetail id={UUID} />);
    await userEvent.click(await screen.findByRole("button", { name: "إرسال الاستكمال" }));
    return screen.findByRole("dialog");
  }

  it("requires a response and counts characters", async () => {
    mockGet({ detail: { data: returned() } });
    const post = vi.spyOn(apiClient, "post");
    const dialog = await openResubmit();

    expect(dialog).toHaveTextContent("فريق المراجعة");
    await userEvent.click(within(dialog).getByRole("button", { name: "إرسال الاستكمال" }));
    expect(await within(dialog).findByText("اكتب ردّك على طلب الاستكمال.")).toBeInTheDocument();
    expect(post).not.toHaveBeenCalled();

    await userEvent.type(within(dialog).getByLabelText("ردّك على طلب الاستكمال"), "abc");
    expect(within(dialog).getByText(new RegExp((3).toLocaleString("ar")))).toBeInTheDocument();
  });

  it("sends the response, closes, refreshes the request and the history, and confirms", async () => {
    const get = mockGet({ detail: { data: returned() } });
    const post = vi.spyOn(apiClient, "post").mockResolvedValue(outcome("RESUBMITTED") as never);
    const dialog = await openResubmit();
    const before = get.mock.calls.filter(([p]) => p === DETAIL_PATH).length;

    await userEvent.type(within(dialog).getByLabelText("ردّك على طلب الاستكمال"), "انتقلنا في سبتمبر.");
    await userEvent.click(within(dialog).getByRole("button", { name: "إرسال الاستكمال" }));

    expect(post).toHaveBeenCalledTimes(1);
    expect(post).toHaveBeenCalledWith(`${DETAIL_PATH}/resubmit`, { response: "انتقلنا في سبتمبر." });
    await waitFor(() => expect(screen.queryByRole("dialog")).not.toBeInTheDocument());
    expect(await screen.findByText("أُرسل الاستكمال إلى فريق المراجعة.")).toBeInTheDocument();
    await waitFor(() => expect(get.mock.calls.filter(([p]) => p === DETAIL_PATH).length).toBeGreaterThan(before));
  });

  it("invalidates the detail and every history page", async () => {
    mockGet({ detail: { data: returned() } });
    vi.spyOn(apiClient, "post").mockResolvedValue(outcome("RESUBMITTED") as never);
    const { client } = renderWithClient(<FamilyRequestDetail id={UUID} />);
    client.setQueryData(familyChangeRequestKeys.list({ page: 1 }), page([summary()]));
    client.setQueryData(familyChangeRequestKeys.list({ status: "APPLIED", page: 2 }), page([]));

    await userEvent.click(await screen.findByRole("button", { name: "إرسال الاستكمال" }));
    const dialog = await screen.findByRole("dialog");
    await userEvent.type(within(dialog).getByLabelText("ردّك على طلب الاستكمال"), "رد");
    await userEvent.click(within(dialog).getByRole("button", { name: "إرسال الاستكمال" }));

    await waitFor(() => {
      expect(client.getQueryState(familyChangeRequestKeys.list({ page: 1 }))?.isInvalidated).toBe(true);
      expect(client.getQueryState(familyChangeRequestKeys.list({ status: "APPLIED", page: 2 }))?.isInvalidated).toBe(true);
    });
  });

  it("explains a conflict, keeps the dialog open, refreshes and never retries", async () => {
    const get = mockGet({ detail: { data: returned() } });
    const post = vi.spyOn(apiClient, "post").mockRejectedValue(new ApiError(409, { message: "x", code: "CHANGE_REQUEST_STATE_CONFLICT" }));
    const dialog = await openResubmit();
    const before = get.mock.calls.filter(([p]) => p === DETAIL_PATH).length;

    await userEvent.type(within(dialog).getByLabelText("ردّك على طلب الاستكمال"), "رد");
    await userEvent.click(within(dialog).getByRole("button", { name: "إرسال الاستكمال" }));

    expect(await within(dialog).findByText("تغيّرت حالة الطلب. حدّثنا الصفحة بحالته الحالية.")).toBeInTheDocument();
    await waitFor(() => expect(get.mock.calls.filter(([p]) => p === DETAIL_PATH).length).toBeGreaterThan(before));
    expect(post).toHaveBeenCalledTimes(1);
  });

  it("maps a 422 onto the response field", async () => {
    mockGet({ detail: { data: returned() } });
    vi.spyOn(apiClient, "post").mockRejectedValue(new ApiError(422, { message: "x", errors: { response: ["الرد طويل جدًا."] } }));
    const dialog = await openResubmit();

    await userEvent.type(within(dialog).getByLabelText("ردّك على طلب الاستكمال"), "رد");
    await userEvent.click(within(dialog).getByRole("button", { name: "إرسال الاستكمال" }));
    expect(await within(dialog).findByText("الرد طويل جدًا.")).toBeInTheDocument();
  });
});

// ===================================================================== cancel

describe("cancelling a request", () => {
  it("asks for confirmation explaining the registry does not change", async () => {
    mockGet();
    const post = vi.spyOn(apiClient, "post");
    renderWithClient(<FamilyRequestDetail id={UUID} />);

    await userEvent.click(await screen.findByRole("button", { name: "إلغاء الطلب" }));
    const dialog = await screen.findByRole("dialog");
    expect(dialog).toHaveTextContent("لا يغيّر شيئًا في سجل أسرتك");
    await userEvent.click(within(dialog).getByRole("button", { name: "رجوع" }));
    expect(post).not.toHaveBeenCalled();
  });

  it("cancels, closes and confirms", async () => {
    mockGet();
    const post = vi.spyOn(apiClient, "post").mockResolvedValue(outcome("CANCELLED") as never);
    renderWithClient(<FamilyRequestDetail id={UUID} />);

    await userEvent.click(await screen.findByRole("button", { name: "إلغاء الطلب" }));
    await userEvent.click(within(await screen.findByRole("dialog")).getByRole("button", { name: "إلغاء الطلب" }));

    expect(post).toHaveBeenCalledWith(`${DETAIL_PATH}/cancel`, {});
    await waitFor(() => expect(screen.queryByRole("dialog")).not.toBeInTheDocument());
    expect(await screen.findByText("أُلغي الطلب. لم يتغير سجل أسرتك.")).toBeInTheDocument();
  });

  it("shows a safe message on conflict and never retries", async () => {
    mockGet();
    const post = vi.spyOn(apiClient, "post").mockRejectedValue(new ApiError(409, { message: "x" }));
    renderWithClient(<FamilyRequestDetail id={UUID} />);

    await userEvent.click(await screen.findByRole("button", { name: "إلغاء الطلب" }));
    const dialog = await screen.findByRole("dialog");
    await userEvent.click(within(dialog).getByRole("button", { name: "إلغاء الطلب" }));

    expect(await within(dialog).findByText("تغيّرت حالة الطلب. حدّثنا الصفحة بحالته الحالية.")).toBeInTheDocument();
    await new Promise((r) => setTimeout(r, 50));
    expect(post).toHaveBeenCalledTimes(1);
  });

  it("shows friendly messages for 429, 503 and network failures", async () => {
    for (const [error, text] of [
      [new ApiError(429, { message: "x" }), "محاولات كثيرة خلال وقت قصير. حاول بعد قليل."],
      [new ApiError(503, { message: "x" }), "هذه الخدمة غير متاحة حاليًا."],
      [new TypeError("fetch failed"), "تعذّر الاتصال. تحقق من الإنترنت وحاول مرة أخرى."],
    ] as const) {
      mockGet();
      vi.spyOn(apiClient, "post").mockRejectedValue(error);
      const { unmount } = renderWithClient(<FamilyRequestDetail id={UUID} />);
      await userEvent.click(await screen.findByRole("button", { name: "إلغاء الطلب" }));
      const dialog = await screen.findByRole("dialog");
      await userEvent.click(within(dialog).getByRole("button", { name: "إلغاء الطلب" }));
      expect(await within(dialog).findByText(text)).toBeInTheDocument();
      unmount();
    }
  });
});

// ================================================================ new request

describe("«طلب جديد» — discovery", () => {
  it("says submissions are unavailable when the switch is off, with a link to «طلباتي»", async () => {
    mockGet({ types: { data: [], meta: { submission_enabled: false } } satisfies FamilyChangeRequestTypes });
    const post = vi.spyOn(apiClient, "post");
    renderWithClient(<FamilyNewRequest />);

    expect(await screen.findByRole("heading", { name: "طلبات التحديث الجديدة غير متاحة حاليًا" })).toBeInTheDocument();
    expect(screen.getByText("يمكنك متابعة طلبات أسرتك السابقة من صفحة «طلباتي». وستظهر أنواع التحديث هنا عند إتاحتها.")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "الانتقال إلى طلباتي" })).toHaveAttribute("href", "/family/requests");
    expect(screen.queryByRole("textbox")).not.toBeInTheDocument();
    expect(post).not.toHaveBeenCalled();
  });

  it("says no types are available when the switch is on but none are listed", async () => {
    mockGet({ types: { data: [], meta: { submission_enabled: true } } });
    renderWithClient(<FamilyNewRequest />);
    expect(await screen.findByRole("heading", { name: "لا توجد أنواع طلبات متاحة حاليًا" })).toBeInTheDocument();
  });

  it("lists a registered type without a Family form as not yet available, never as a fake form", async () => {
    mockGet({ types: { data: [{ type: "RESIDENCE_UPDATE" }], meta: { submission_enabled: true } } });
    renderWithClient(<FamilyNewRequest />);

    const types = await screen.findByRole("list", { name: "أنواع الطلبات" });
    const item = within(types).getByRole("listitem");
    expect(item).toHaveTextContent("تحديث بيانات السكن");
    expect(item).toHaveTextContent("غير متاح للتقديم بعد");
    expect(within(types).queryByRole("link")).not.toBeInTheDocument();
    expect(screen.queryByRole("textbox")).not.toBeInTheDocument();
  });

  it("shows an error with retry", async () => {
    mockGet({ types: new ApiError(500, { message: "x" }) });
    renderWithClient(<FamilyNewRequest />);
    expect(await screen.findByRole("alert")).toHaveTextContent("تعذّر تحميل أنواع الطلبات");
    expect(screen.getByRole("button", { name: /إعادة المحاولة/ })).toBeInTheDocument();
  });
});

// ======================================================================== nav

describe("the Family bottom navigation (PWA-5f)", () => {
  // 5f views are built but not linked (docs/07 RM-ADR-055, docs/11
  // FP-ADR-072): «+» and «طلباتي» stay disabled until PWA-6.1.
  it("keeps «طلباتي» and «+» disabled, even on a request screen", () => {
    nav.pathname = "/family/requests/" + UUID;
    renderWithClient(<FamilyBottomNav />);
    const bar = screen.getByRole("navigation");
    expect(within(bar).getAllByRole("link")).toHaveLength(3);
    for (const label of ["طلباتي", "إجراء جديد (قريبًا)"]) {
      expect(within(bar).getByRole("button", { name: label })).toBeDisabled();
    }
    expect(within(bar).queryByRole("link", { name: /طلب/ })).not.toBeInTheDocument();
  });
});

// ============================================================ accessibility

describe("accessibility and privacy", () => {
  it("names the filters, the list, the timeline and the dialog", async () => {
    mockGet({ detail: { data: returned() } });
    renderWithClient(<FamilyRequests />);
    expect(screen.getByRole("group", { name: "تصفية الطلبات" })).toBeInTheDocument();
    expect(screen.getByRole("combobox", { name: "الحالة" })).toBeInTheDocument();
    expect(screen.getByRole("combobox", { name: "نوع الطلب" })).toBeInTheDocument();
    expect(await screen.findByRole("list", { name: "طلبات الأسرة" })).toBeInTheDocument();
  });

  it("keeps nothing in browser storage", async () => {
    mockGet({ detail: { data: returned() } });
    renderWithClient(<FamilyRequestDetail id={UUID} />);
    await screen.findByRole("list", { name: "مسار الطلب" });
    expect(browserStorageDump()).not.toMatch(/CRQ-|انتقلنا|حي التجربة/);
  });
});
