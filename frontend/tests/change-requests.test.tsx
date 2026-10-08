import { act, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { AuthContext, authValue } from "@/components/auth/auth-context";
import { ChangeRequestDetailView } from "@/components/change-requests/change-request-detail-view";
import { ChangeRequestsQueue, searchFilter } from "@/components/change-requests/change-requests-queue";
import { comparisonRows } from "@/components/change-requests/change-request-comparison";
import { ApiError, apiClient } from "@/lib/api/client";
import { changeRequestQuery } from "@/lib/api/change-requests";
import type { CurrentUser } from "@/lib/api/auth";
import type { PaginatedResponse } from "@/lib/types/api/family";
import type { ChangeRequestDetail, ChangeRequestSummary, WorkflowEvent } from "@/lib/types/api/change-request";
import { activityPresentation, familyActivityPresentation } from "@/lib/utils/activity";
import { navItems } from "@/lib/navigation";
import { browserStorageDump, renderWithClient } from "./helpers";

// PWA-5d: the Staff Change Request review workspace over the PWA-5c API.
// Fixtures mirror ChangeRequestSummaryResource / ChangeRequestResource /
// WorkflowEventResource / ChangeRequestOutcomeResource. Synthetic data only.

const router = { push: vi.fn(), replace: vi.fn() };
const nav = { search: "" };
vi.mock("next/navigation", () => ({
  useRouter: () => router,
  usePathname: () => "/change-requests",
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

const UUID = "0d6f2a3e-9c1b-4b8e-8a2f-1c2d3e4f5a6b";
const DETAIL_PATH = `/api/v1/change-requests/${UUID}`;
const ALL = [
  "change-request.view",
  "change-request.review",
  "change-request.return",
  "change-request.approve",
  "change-request.reject",
  "change-request.apply",
  "change-request.view-internal-notes",
];

function staff(permissions: string[] = ALL): CurrentUser {
  return { name: "مراجع تجريبي", email: "reviewer@example.test", role: "REVIEWER", role_label: "مراجع", permissions };
}

function summary(overrides: Partial<ChangeRequestSummary> = {}): ChangeRequestSummary {
  return {
    id: UUID,
    request_code: "CRQ-000042",
    type: "RESIDENCE_UPDATE",
    type_available: false,
    status: "SUBMITTED",
    family: { family_code: "FAM-000123", household_head_name: "سالم الاختبار" },
    submitted_by: { name: "سالم الاختبار" },
    submitted_at: "2026-10-08T09:00:00+03:00",
    reviewed_at: null,
    approved_at: null,
    rejected_at: null,
    applied_at: null,
    cancelled_at: null,
    apply_failure_count: 0,
    available_actions: ["start_review"],
    ...overrides,
  };
}

function event(overrides: Partial<WorkflowEvent> = {}): WorkflowEvent {
  return {
    event_type: "SUBMITTED",
    from_status: null,
    to_status: "SUBMITTED",
    actor: { name: "سالم الاختبار" },
    actor_side: "FAMILY",
    reason_code: null,
    public_message: null,
    created_at: "2026-10-08T09:00:00+03:00",
    ...overrides,
  };
}

const ROWS = {
  rows: [
    { label: "المحافظة", current: "محافظة أ", proposed: "محافظة ب" },
    { label: "المدينة", current: "مدينة تجريبية", proposed: "مدينة تجريبية" },
    { label: "الحي", current: null, proposed: "حي جديد" },
  ],
};

function detail(overrides: Partial<ChangeRequestDetail> = {}): ChangeRequestDetail {
  return {
    id: UUID,
    request_code: "CRQ-000042",
    type: "RESIDENCE_UPDATE",
    type_available: true,
    payload_version: 1,
    status: "UNDER_REVIEW",
    family: { family_code: "FAM-000123", household_head_name: "سالم الاختبار" },
    target_person: null,
    submitted_by: { name: "سالم الاختبار" },
    submitted_at: "2026-10-08T09:00:00+03:00",
    reason: "انتقلنا إلى عنوان جديد",
    presentation: ROWS,
    review: {
      reviewed_by: { name: "مراجع تجريبي" },
      reviewed_at: "2026-10-08T10:00:00+03:00",
      approved_by: null,
      approved_at: null,
      applied_by: null,
      applied_at: null,
      cancelled_at: null,
    },
    rejection: null,
    apply_failures: { count: 0, last_failed_at: null, last_code: null },
    timeline: [
      event(),
      event({ event_type: "REVIEW_STARTED", from_status: "SUBMITTED", to_status: "UNDER_REVIEW", actor: { name: "مراجع تجريبي" }, actor_side: "STAFF", created_at: "2026-10-08T10:00:00+03:00" }),
    ],
    available_actions: ["return", "approve", "reject"],
    ...overrides,
  };
}

function page(rows: ChangeRequestSummary[], meta: Partial<PaginatedResponse<ChangeRequestSummary>["meta"]> = {}): PaginatedResponse<ChangeRequestSummary> {
  return {
    data: rows,
    links: { first: null, last: null, prev: null, next: null },
    meta: { current_page: 1, from: rows.length ? 1 : null, last_page: 1, path: "", per_page: 20, to: rows.length || null, total: rows.length, ...meta },
  } as PaginatedResponse<ChangeRequestSummary>;
}

function renderQueue(reply: PaginatedResponse<ChangeRequestSummary> | Error = page([summary()]), permissions = ALL) {
  const get = vi.spyOn(apiClient, "get").mockImplementation(async (path: string) => {
    if (!path.startsWith("/api/v1/change-requests")) throw new Error(`Unexpected GET ${path}`);
    if (reply instanceof Error) throw reply;
    return reply as never;
  });
  const result = renderWithClient(
    <AuthContext.Provider value={authValue(staff(permissions))}>
      <ChangeRequestsQueue />
    </AuthContext.Provider>
  );
  return { get, ...result };
}

/** A tiny server: GET answers the current detail; POST runs the handler and may change it. */
function renderDetail(initial: ChangeRequestDetail | Error, permissions = ALL, onPost?: (path: string, body: unknown, server: { state: ChangeRequestDetail }) => unknown) {
  const server = { state: initial instanceof Error ? detail() : initial };
  const get = vi.spyOn(apiClient, "get").mockImplementation(async (path: string) => {
    if (path !== DETAIL_PATH) throw new Error(`Unexpected GET ${path}`);
    if (initial instanceof Error) throw initial;
    return { data: server.state } as never;
  });
  const post = vi.spyOn(apiClient, "post").mockImplementation(async (path: string, body?: unknown) => {
    if (!onPost) throw new Error(`Unexpected POST ${path}`);
    return onPost(path, body, server) as never;
  });
  const result = renderWithClient(
    <AuthContext.Provider value={authValue(staff(permissions))}>
      <ChangeRequestDetailView id={UUID} />
    </AuthContext.Provider>
  );
  return { server, get, post, ...result };
}

const outcome = (status: string, replayed = false) => ({ data: { id: UUID, request_code: "CRQ-000042", status, replayed } });
const button = (name: RegExp | string) => screen.queryByRole("button", { name });

beforeEach(() => {
  vi.restoreAllMocks();
  router.push.mockReset();
  router.replace.mockReset();
  nav.search = "";
  window.localStorage.clear();
  window.sessionStorage.clear();
});

// ------------------------------------------------------------------ navigation and activity labels

describe("navigation and the activity labels", () => {
  it("offers «طلبات تحديث البيانات» only with change-request.view", () => {
    const item = navItems.find((i) => i.href === "/change-requests");
    expect(item?.label).toBe("طلبات تحديث البيانات");
    expect(item?.permissions).toEqual(["change-request.view"]);
  });

  it("labels the three Change Request activity types and falls back safely for unknown codes", () => {
    expect(familyActivityPresentation.CHANGE_REQUEST_SUBMITTED.label).toBe("قدّمت الأسرة طلب تحديث بيانات");
    expect(familyActivityPresentation.CHANGE_REQUEST_REJECTED.label).toBe("رُفض طلب تحديث بيانات");
    expect(familyActivityPresentation.CHANGE_REQUEST_APPLIED.label).toBe("طُبّق طلب تحديث بيانات على سجل الأسرة");
    for (const type of ["CHANGE_REQUEST_SUBMITTED", "CHANGE_REQUEST_REJECTED", "CHANGE_REQUEST_APPLIED"]) {
      expect(activityPresentation(type).icon).toBeTruthy();
    }
    expect(activityPresentation("SOMETHING_NEW").label).toBe("نشاط على سجل الأسرة");
  });
});

// ------------------------------------------------------------------ queue

describe("the queue", () => {
  it("renders rows with the API fields and opens a request", async () => {
    renderQueue(page([summary(), summary({ id: "11111111-2222-4333-8444-555555555555", request_code: "CRQ-000043", status: "APPROVED", approved_at: "2026-10-08T12:00:00+03:00" })], { total: 2 }));

    expect(await screen.findByRole("heading", { level: 1, name: "طلبات تحديث البيانات" })).toBeInTheDocument();
    expect(screen.getByText("مراجعة طلبات الأسر ومتابعة إجراءات اعتمادها وتطبيقها.")).toBeInTheDocument();
    const table = await screen.findByRole("table");
    expect(within(table).getByText("CRQ-000042")).toBeInTheDocument();
    expect(within(table).getAllByText("تحديث بيانات السكن")).toHaveLength(2);
    expect(within(table).getAllByText("FAM-000123")).toHaveLength(2);
    expect(within(table).getByText("مقدّم")).toBeInTheDocument();
    // APPROVED is "awaiting apply" — never shown as completed.
    expect(within(table).getByText("معتمد بانتظار التطبيق")).toBeInTheDocument();
    expect(within(table).queryByText("مطبّق")).not.toBeInTheDocument();
    expect(document.querySelector("[data-queue-total]")).toHaveTextContent((2).toLocaleString("ar"));

    await userEvent.click(within(table).getAllByRole("row")[1]);
    expect(router.push).toHaveBeenCalledWith(`/change-requests/${UUID}`);
  });

  it("sends only the supported filters from the URL, and paginates on the server", async () => {
    nav.search = "q=CRQ-000042&status=UNDER_REVIEW&type=OTHER&from=2026-10-01&to=2026-10-08&page=2&bogus=1";
    const { get } = renderQueue(page([summary()], { current_page: 2, last_page: 3, total: 41, from: 21, to: 40 }));

    await screen.findByRole("table");
    const path = get.mock.calls[0][0] as string;
    const query = new URLSearchParams(path.split("?")[1]);
    expect(Object.fromEntries(query)).toEqual({
      request_code: "CRQ-000042",
      status: "UNDER_REVIEW",
      type: "OTHER",
      submitted_from: "2026-10-01",
      submitted_to: "2026-10-08",
      page: "2",
    });
    expect(path).not.toContain("bogus");
    expect(path).not.toContain("per_page");

    await userEvent.click(screen.getByRole("button", { name: "الصفحة التالية" }));
    expect(router.push).toHaveBeenCalledWith("/change-requests?q=CRQ-000042&status=UNDER_REVIEW&type=OTHER&from=2026-10-01&to=2026-10-08&page=3&bogus=1", { scroll: false });
  });

  it("maps the search box to a request code or a family code, never anything unsupported", () => {
    expect(searchFilter("crq-000042")).toEqual({ request_code: "CRQ-000042" });
    expect(searchFilter("CRQ-12")).toEqual({});
    expect(searchFilter("  FAM-000123 ")).toEqual({ family: "FAM-000123" });
    expect(searchFilter("")).toEqual({});
    expect(changeRequestQuery({ status: "APPLIED", page: 1 })).toBe("status=APPLIED");
  });

  it("ignores an inverted date range and an unknown status from the URL", async () => {
    nav.search = "status=PENDING&from=2026-10-08&to=2026-10-01";
    const { get } = renderQueue();

    await screen.findByRole("table");
    const path = get.mock.calls[0][0] as string;
    expect(path).toContain("submitted_from=2026-10-08");
    expect(path).not.toContain("submitted_to");
    expect(path).not.toContain("status=");
    expect(screen.getByText("تاريخ النهاية قبل تاريخ البداية؛ لم يُطبَّق.")).toBeInTheDocument();
  });

  it("changes a filter through the URL and resets", async () => {
    nav.search = "status=APPLIED";
    renderQueue();
    await screen.findByRole("table");

    await userEvent.click(screen.getByRole("combobox", { name: "الحالة" }));
    await userEvent.click(await screen.findByRole("option", { name: "مرفوض" }));
    expect(router.push).toHaveBeenCalledWith("/change-requests?status=REJECTED", { scroll: false });

    await userEvent.click(screen.getAllByRole("button", { name: /إعادة الضبط/ })[0]);
    expect(router.push).toHaveBeenCalledWith("/change-requests", { scroll: false });
  });

  it("shows loading, empty, filtered-empty, error and forbidden states", async () => {
    vi.spyOn(apiClient, "get").mockReturnValue(new Promise<never>(() => {}));
    const pending = renderWithClient(
      <AuthContext.Provider value={authValue(staff())}>
        <ChangeRequestsQueue />
      </AuthContext.Provider>
    );
    expect(pending.container.querySelector('[aria-busy="true"]')).toBeInTheDocument();
    pending.unmount();
    vi.restoreAllMocks();

    const empty = renderQueue(page([]));
    expect(await screen.findByText("لا توجد طلبات تحديث بيانات")).toBeInTheDocument();
    empty.unmount();
    vi.restoreAllMocks();

    nav.search = "status=APPLIED";
    const filtered = renderQueue(page([]));
    expect(await screen.findByText("لا توجد طلبات مطابقة")).toBeInTheDocument();
    filtered.unmount();
    vi.restoreAllMocks();
    nav.search = "";

    const failed = renderQueue(new ApiError(500, { message: "x" }));
    expect(await screen.findByText("تعذّر تحميل الطلبات")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "إعادة المحاولة" })).toBeInTheDocument();
    failed.unmount();
    vi.restoreAllMocks();

    renderQueue(new ApiError(403, { message: "x" }));
    expect(await screen.findByText("لا تملك صلاحية عرض طلبات تحديث البيانات.")).toBeInTheDocument();
  });
});

// ------------------------------------------------------------------ detail

describe("the review workspace", () => {
  it("shows the identity, the comparison and the timeline in order", async () => {
    renderDetail(detail());

    expect(await screen.findByRole("heading", { level: 1, name: "تحديث بيانات السكن" })).toBeInTheDocument();
    expect(screen.getAllByText("CRQ-000042").length).toBeGreaterThan(0);
    expect(screen.getByText("انتقلنا إلى عنوان جديد")).toBeInTheDocument();

    const rows = document.querySelectorAll("tr[data-comparison-row]");
    expect(rows).toHaveLength(3);
    expect(rows[0]).toHaveAttribute("data-changed", "true");
    expect(rows[1]).toHaveAttribute("data-changed", "false");
    expect(within(rows[0] as HTMLElement).getByText("تعديل مطلوب")).toBeInTheDocument();
    expect(within(rows[1] as HTMLElement).getByText("بدون تغيير")).toBeInTheDocument();
    expect(within(rows[2] as HTMLElement).getByText("غير مسجّل")).toBeInTheDocument();

    const events = Array.from(document.querySelectorAll("[data-timeline-event]")).map((e) => e.getAttribute("data-timeline-event"));
    expect(events).toEqual(["SUBMITTED", "REVIEW_STARTED"]);
    expect(screen.getByText("قدّمت الأسرة الطلب")).toBeInTheDocument();
  });

  it("never renders a presentation that is not in the labelled-rows shape", async () => {
    expect(comparisonRows({ current: { paper_form_no: "x" }, proposed: { paper_form_no: "y" } })).toBeNull();
    expect(comparisonRows({ rows: [{ label: "", current: "a", proposed: "b" }] })).toBeNull();
    expect(comparisonRows({ rows: [{ label: "الحقل", current: { nested: 1 }, proposed: "b" }] })).toBeNull();

    renderDetail(detail({ presentation: { current: { national_id: "000000001" }, proposed: { national_id: "000000002" } } }));
    expect(await screen.findByText("عرض تفاصيل هذا النوع من الطلبات غير مدعوم في هذه الواجهة بعد.")).toBeInTheDocument();
    expect(document.body).not.toHaveTextContent("000000001");
    expect(document.body).not.toHaveTextContent("national_id");
  });

  it("falls back safely when the type has no registered handler", async () => {
    renderDetail(detail({ type_available: false, presentation: null, status: "UNDER_REVIEW", available_actions: ["return", "reject"] }));

    expect(await screen.findByText("تفاصيل هذا النوع من الطلبات غير متاحة حاليًا.")).toBeInTheDocument();
    expect(screen.getAllByText("CRQ-000042").length).toBeGreaterThan(0);
    expect(document.querySelectorAll("[data-timeline-event]")).toHaveLength(2);
    expect(button("اعتماد الطلب")).not.toBeInTheDocument();
    expect(button("تطبيق التعديل")).not.toBeInTheDocument();
  });

  it("shows internal notes only when the API included them", async () => {
    const withNote = event({ event_type: "RETURNED", from_status: "UNDER_REVIEW", to_status: "RETURNED_FOR_CLARIFICATION", actor_side: "STAFF", public_message: "وضّح العنوان", internal_note: "ملاحظة داخلية سرية" });
    const first = renderDetail(detail({ status: "RETURNED_FOR_CLARIFICATION", timeline: [event(), withNote], available_actions: [] }));
    expect(await screen.findByText("ملاحظة داخلية سرية")).toBeInTheDocument();
    expect(screen.getByText("وضّح العنوان")).toBeInTheDocument();
    first.unmount();
    vi.restoreAllMocks();

    // Without view-internal-notes the API omits the key: nothing to show.
    const { internal_note: _omit, ...withoutNote } = withNote;
    void _omit;
    renderDetail(detail({ status: "RETURNED_FOR_CLARIFICATION", timeline: [event(), withoutNote], available_actions: [] }));
    expect(await screen.findByText("وضّح العنوان")).toBeInTheDocument();
    expect(document.querySelector("[data-timeline-internal]")).toBeNull();
  });

  it("offers only the actions the API allows AND the user is permitted", async () => {
    const first = renderDetail(detail({ available_actions: ["return", "approve", "reject"] }));
    await screen.findByRole("heading", { level: 1 });
    expect(button("إرجاع للاستكمال")).toBeInTheDocument();
    expect(button("اعتماد الطلب")).toBeInTheDocument();
    expect(button("رفض الطلب")).toBeInTheDocument();
    expect(button("بدء المراجعة")).not.toBeInTheDocument();
    first.unmount();
    vi.restoreAllMocks();

    // view-only: the same response offers nothing.
    renderDetail(detail({ available_actions: ["return", "approve", "reject"] }), ["change-request.view"]);
    await screen.findByRole("heading", { level: 1 });
    expect(document.querySelector("[data-action]")).toBeNull();
    expect(screen.getByText("لا توجد إجراءات متاحة لك على هذا الطلب في حالته الحالية.")).toBeInTheDocument();
  });

  it("shows 404 and 403 states", async () => {
    const missing = renderDetail(new ApiError(404, { message: "x", code: "CHANGE_REQUEST_NOT_FOUND" }));
    expect(await screen.findByText("هذا الطلب غير متاح")).toBeInTheDocument();
    missing.unmount();
    vi.restoreAllMocks();
    renderDetail(new ApiError(403, { message: "x" }));
    expect(await screen.findByText("لا تملك صلاحية عرض هذا الطلب.")).toBeInTheDocument();
  });
});

// ------------------------------------------------------------------ actions

describe("workflow actions", () => {
  it("starts a review, then re-reads the request (no optimistic status)", async () => {
    const { post, get } = renderDetail(detail({ status: "SUBMITTED", review: { ...detail().review, reviewed_at: null, reviewed_by: null }, available_actions: ["start_review"] }), ALL, (path, _b, server) => {
      expect(path).toBe(`${DETAIL_PATH}/start-review`);
      server.state = detail({ status: "UNDER_REVIEW" });
      return outcome("UNDER_REVIEW");
    });

    await userEvent.click(await screen.findByRole("button", { name: "بدء المراجعة" }));

    expect(await screen.findByText("بدأت مراجعة الطلب.")).toBeInTheDocument();
    expect(post).toHaveBeenCalledTimes(1);
    await waitFor(() => expect(get.mock.calls.length).toBeGreaterThanOrEqual(2));
    expect(await screen.findByRole("button", { name: "اعتماد الطلب" })).toBeInTheDocument();
  });

  it("validates the return dialog and sends public and internal text separately", async () => {
    const { post } = renderDetail(detail(), ALL, (_p, _b, server) => {
      server.state = detail({ status: "RETURNED_FOR_CLARIFICATION", available_actions: [] });
      return outcome("RETURNED_FOR_CLARIFICATION");
    });

    await userEvent.click(await screen.findByRole("button", { name: "إرجاع للاستكمال" }));
    const dialog = await screen.findByRole("dialog");
    await userEvent.click(within(dialog).getByRole("button", { name: "إرجاع للاستكمال" }));
    expect(await within(dialog).findByText("رسالة الاستيضاح للأسرة مطلوبة.")).toBeInTheDocument();
    expect(post).not.toHaveBeenCalled();

    await userEvent.type(within(dialog).getByLabelText(/رسالة الاستيضاح للأسرة/), "يرجى إرفاق توضيح");
    await userEvent.type(within(dialog).getByLabelText(/ملاحظة داخلية/), "للموظفين فقط");
    await userEvent.click(within(dialog).getByRole("button", { name: "إرجاع للاستكمال" }));

    await waitFor(() => expect(post).toHaveBeenCalledTimes(1));
    expect(post).toHaveBeenCalledWith(`${DETAIL_PATH}/return`, { public_message: "يرجى إرفاق توضيح", internal_note: "للموظفين فقط" });
    expect(await screen.findByText("أُعيد الطلب إلى الأسرة لاستكمال المعلومات.")).toBeInTheDocument();
  });

  it("keeps the return dialog open on a server validation error", async () => {
    renderDetail(detail(), ALL, () => {
      throw new ApiError(422, { message: "رسالة الاستيضاح للأسرة مطلوبة.", errors: { public_message: ["هذا الحقل مطلوب."] } });
    });

    await userEvent.click(await screen.findByRole("button", { name: "إرجاع للاستكمال" }));
    const dialog = await screen.findByRole("dialog");
    await userEvent.type(within(dialog).getByLabelText(/رسالة الاستيضاح للأسرة/), "‏");
    await userEvent.click(within(dialog).getByRole("button", { name: "إرجاع للاستكمال" }));

    expect(await within(dialog).findByText("هذا الحقل مطلوب.")).toBeInTheDocument();
    expect(screen.getByRole("dialog")).toBeInTheDocument();
  });

  it("hides the internal-note field without view-internal-notes", async () => {
    renderDetail(detail(), ALL.filter((p) => p !== "change-request.view-internal-notes"));
    await userEvent.click(await screen.findByRole("button", { name: "إرجاع للاستكمال" }));
    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).queryByLabelText(/ملاحظة داخلية/)).not.toBeInTheDocument();
  });

  it("validates the reject dialog: a reason, and a message for OTHER", async () => {
    const { post } = renderDetail(detail(), ALL, (_p, _b, server) => {
      server.state = detail({ status: "REJECTED", available_actions: [] });
      return outcome("REJECTED");
    });

    await userEvent.click(await screen.findByRole("button", { name: "رفض الطلب" }));
    const dialog = await screen.findByRole("dialog");
    await userEvent.click(within(dialog).getByRole("button", { name: "رفض الطلب" }));
    expect(await within(dialog).findByText("اختر سبب الرفض.")).toBeInTheDocument();

    await userEvent.click(within(dialog).getByRole("combobox"));
    await userEvent.click(await screen.findByRole("option", { name: "سبب آخر" }));
    await userEvent.click(within(dialog).getByRole("button", { name: "رفض الطلب" }));
    expect(await within(dialog).findByText("رسالة الرفض للأسرة مطلوبة لهذا السبب.")).toBeInTheDocument();
    expect(post).not.toHaveBeenCalled();

    await userEvent.type(within(dialog).getByLabelText(/رسالة الرفض للأسرة/), "لم نتمكن من قبول الطلب");
    await userEvent.click(within(dialog).getByRole("button", { name: "رفض الطلب" }));
    await waitFor(() => expect(post).toHaveBeenCalledWith(`${DETAIL_PATH}/reject`, { rejection_reason_code: "OTHER", public_message: "لم نتمكن من قبول الطلب" }));
  });

  it("offers only NO_LONGER_APPLICABLE when rejecting an approved request", async () => {
    renderDetail(
      detail({
        status: "APPROVED",
        apply_failures: { count: 1, last_failed_at: "2026-10-08T11:00:00+03:00", last_code: "PRECONDITION_FAILED" },
        available_actions: ["apply", "reject"],
      })
    );

    await userEvent.click(await screen.findByRole("button", { name: "رفض الطلب" }));
    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByRole("combobox")).toHaveTextContent("لم يعد الطلب قابلًا للتنفيذ");
    expect(within(dialog).getByRole("combobox")).toBeDisabled();
  });

  it("confirms approval and explains that the registry is not changed yet", async () => {
    const { post } = renderDetail(detail(), ALL, (path, _b, server) => {
      expect(path).toBe(`${DETAIL_PATH}/approve`);
      server.state = detail({ status: "APPROVED", available_actions: ["apply"] });
      return outcome("APPROVED");
    });

    await userEvent.click(await screen.findByRole("button", { name: "اعتماد الطلب" }));
    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByText("اعتماد الطلب لا يغيّر بيانات السجل مباشرة. يجب تطبيق التعديل بعد الاعتماد.")).toBeInTheDocument();
    expect(post).not.toHaveBeenCalled();
    await userEvent.click(within(dialog).getByRole("button", { name: "اعتماد الطلب" }));

    expect(await screen.findByText(/اعتُمد الطلب\. لم تتغير بيانات السجل بعد/)).toBeInTheDocument();
    expect(post).toHaveBeenCalledTimes(1);
    // Approval never calls apply.
    expect(post.mock.calls.every(([path]) => !String(path).endsWith("/apply"))).toBe(true);
    expect(await screen.findByText("معتمد بانتظار التطبيق")).toBeInTheDocument();
  });

  it("shows a stale-data conflict and re-reads the request", async () => {
    const { get } = renderDetail(detail(), ALL, () => {
      throw new ApiError(409, { message: "تغيّرت البيانات المسجلة بعد تقديم الطلب.", code: "CHANGE_REQUEST_BASE_CHANGED" });
    });

    await userEvent.click(await screen.findByRole("button", { name: "اعتماد الطلب" }));
    const dialog = await screen.findByRole("dialog");
    await userEvent.click(within(dialog).getByRole("button", { name: "اعتماد الطلب" }));

    expect(await within(dialog).findByText("تغيّرت البيانات المسجلة بعد تقديم الطلب.")).toBeInTheDocument();
    await waitFor(() => expect(get.mock.calls.length).toBeGreaterThanOrEqual(2));
  });

  it("applies only after an explicit confirmation", async () => {
    const { post } = renderDetail(detail({ status: "APPROVED", available_actions: ["apply"] }), ALL, (path, _b, server) => {
      expect(path).toBe(`${DETAIL_PATH}/apply`);
      server.state = detail({ status: "APPLIED", available_actions: [] });
      return outcome("APPLIED");
    });

    await userEvent.click(await screen.findByRole("button", { name: "تطبيق التعديل" }));
    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByText("هذا الإجراء يغيّر بيانات السجل الرسمية ولا يُعاد تلقائيًا عند الفشل.")).toBeInTheDocument();
    expect(post).not.toHaveBeenCalled();

    await userEvent.click(within(dialog).getByRole("button", { name: "تطبيق التعديل" }));
    expect(await screen.findByText("طُبّق التعديل على سجل الأسرة.")).toBeInTheDocument();
    expect(await screen.findByText("مطبّق")).toBeInTheDocument();
  });

  it("shows an unexpected apply failure safely, never retries it, and refreshes to show the failure record", async () => {
    let calls = 0;
    const { post, get } = renderDetail(detail({ status: "APPROVED", available_actions: ["apply"] }), ALL, (_p, _b, server) => {
      calls++;
      server.state = detail({
        status: "APPROVED",
        apply_failures: { count: 1, last_failed_at: "2026-10-08T11:00:00+03:00", last_code: "APPLY_FAILED" },
        available_actions: ["apply"],
      });
      throw new ApiError(500, { message: "تعذّر تطبيق الطلب على السجل حاليًا. يمكن إعادة المحاولة.", code: "CHANGE_REQUEST_APPLY_FAILED", trace: "SQLSTATE secret" });
    });

    await userEvent.click(await screen.findByRole("button", { name: "تطبيق التعديل" }));
    const dialog = await screen.findByRole("dialog");
    await userEvent.click(within(dialog).getByRole("button", { name: "تطبيق التعديل" }));

    expect(await within(dialog).findByText(/تعذّر تطبيق التعديل بسبب خطأ غير متوقع، ولم يتغير سجل الأسرة/)).toBeInTheDocument();
    expect(document.body).not.toHaveTextContent("SQLSTATE");
    await act(async () => new Promise((r) => setTimeout(r, 50)));
    expect(calls).toBe(1);
    expect(post).toHaveBeenCalledTimes(1);
    await waitFor(() => expect(get.mock.calls.length).toBeGreaterThanOrEqual(2));

    await userEvent.keyboard("{Escape}");
    expect(await screen.findByText(/محاولات تطبيق لم تنجح/)).toBeInTheDocument();
    expect(screen.getByText("آخر سبب: خطأ غير متوقع أثناء التطبيق")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "إعادة محاولة التطبيق" })).toBeInTheDocument();
  });

  it("shows an expected apply refusal with its own message", async () => {
    renderDetail(detail({ status: "APPROVED", available_actions: ["apply"] }), ALL, () => {
      throw new ApiError(409, { message: "تغيّرت البيانات المسجلة بعد تقديم الطلب.", code: "CHANGE_REQUEST_BASE_CHANGED" });
    });

    await userEvent.click(await screen.findByRole("button", { name: "تطبيق التعديل" }));
    const dialog = await screen.findByRole("dialog");
    await userEvent.click(within(dialog).getByRole("button", { name: "تطبيق التعديل" }));
    expect(await within(dialog).findByText("تغيّرت البيانات المسجلة بعد تقديم الطلب.")).toBeInTheDocument();
    expect(screen.queryByText("طُبّق التعديل على سجل الأسرة.")).not.toBeInTheDocument();
  });

  it("says when a call was a replay, and stores nothing in the browser", async () => {
    renderDetail(detail({ status: "APPROVED", available_actions: ["apply"] }), ALL, () => outcome("APPLIED", true));

    await userEvent.click(await screen.findByRole("button", { name: "تطبيق التعديل" }));
    await userEvent.click(within(await screen.findByRole("dialog")).getByRole("button", { name: "تطبيق التعديل" }));
    expect(await screen.findByText("لم يتغير شيء: سبق تنفيذ هذا الإجراء على الطلب.")).toBeInTheDocument();
    expect(browserStorageDump()).not.toMatch(/CRQ|محافظة|انتقلنا/);
  });
});
