import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { AuthContext, authValue } from "@/components/auth/auth-context";
import { ChangeRequestDetailView } from "@/components/change-requests/change-request-detail-view";
import { ChangeRequestsQueue } from "@/components/change-requests/change-requests-queue";
import { apiClient } from "@/lib/api/client";
import type { CurrentUser } from "@/lib/api/auth";
import type { PaginatedResponse } from "@/lib/types/api/family";
import type { ChangeRequestDetail, ChangeRequestSummary, WorkflowEvent } from "@/lib/types/api/change-request";
import { renderWithClient } from "./helpers";

// CO-02.7a: the Staff Change Request workspace with Coordinator requests —
// a proposed Family (REGISTER_FAMILY, family = null) and a Coordinator's
// PERSON_ADD — over the CO-02 Staff API. No null-reference anywhere; the
// household's own requests keep their wording. Synthetic data only.

const router = { push: vi.fn(), replace: vi.fn() };
const nav = { search: "" };
vi.mock("next/navigation", () => ({
  useRouter: () => router,
  usePathname: () => "/change-requests",
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
  nav.search = "";
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
const PLACEMENT = { clan: { code: "CLAN_A", name: "عشيرة أ" }, branch: { code: "BR_A", name: "فرع أ" } };

const staff = (): CurrentUser => ({ name: "مراجع تجريبي", email: "reviewer@example.test", role: "REVIEWER", role_label: "مراجع", permissions: ALL });

function summary(overrides: Partial<ChangeRequestSummary> = {}): ChangeRequestSummary {
  return {
    id: UUID,
    request_code: "CRQ-000050",
    type: "REGISTER_FAMILY",
    type_available: true,
    status: "SUBMITTED",
    submission_channel: "COORDINATOR",
    family: null,
    placement: PLACEMENT,
    created_family_code: null,
    submitted_by: { name: "منسّق تجريبي" },
    submitted_at: "2026-10-14T09:00:00+03:00",
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
    actor: { name: "منسّق تجريبي" },
    actor_side: "COORDINATOR",
    reason_code: null,
    public_message: null,
    created_at: "2026-10-14T09:00:00+03:00",
    ...overrides,
  };
}

function detail(overrides: Partial<ChangeRequestDetail> = {}): ChangeRequestDetail {
  return {
    id: UUID,
    request_code: "CRQ-000050",
    type: "REGISTER_FAMILY",
    type_available: true,
    payload_version: 1,
    status: "UNDER_REVIEW",
    submission_channel: "COORDINATOR",
    family: null,
    placement: PLACEMENT,
    created_family_code: null,
    target_person: null,
    submitted_by: { name: "منسّق تجريبي" },
    submitted_at: "2026-10-14T09:00:00+03:00",
    reason: "أسرة نازحة غير مسجّلة",
    presentation: {
      rows: [
        { label: "اسم رب الأسرة", current: null, proposed: "رب أسرة مقترح" },
        { label: "رقم هوية رب الأسرة", current: null, proposed: "*****5678" },
        { label: "مطابقة رقم الهوية في السجل", current: "لا يوجد شخص مسجّل بهذا الرقم — تُنشأ أسرة جديدة ورب أسرة جديد عند التطبيق.", proposed: null },
      ],
    },
    review: { reviewed_by: { name: "مراجع تجريبي" }, reviewed_at: "2026-10-14T10:00:00+03:00", approved_by: null, approved_at: null, applied_by: null, applied_at: null, cancelled_at: null },
    rejection: null,
    apply_failures: { count: 0, last_failed_at: null, last_code: null },
    timeline: [
      event(),
      event({ event_type: "REVIEW_STARTED", from_status: "SUBMITTED", to_status: "UNDER_REVIEW", actor: { name: "مراجع تجريبي" }, actor_side: "STAFF", created_at: "2026-10-14T10:00:00+03:00" }),
    ],
    available_actions: ["return", "approve", "reject"],
    approval_attestations: ["IDENTITY_VERIFIED", "RELATIONSHIP_VERIFIED"],
    approval_requires_verification_method: false,
    approval_verification_method: null,
    ...overrides,
  };
}

const page = (rows: ChangeRequestSummary[]) =>
  ({
    data: rows,
    links: { first: null, last: null, prev: null, next: null },
    meta: { current_page: 1, from: 1, last_page: 1, path: "", per_page: 20, to: rows.length, total: rows.length },
  }) as PaginatedResponse<ChangeRequestSummary>;

function renderQueue(rows: ChangeRequestSummary[]) {
  const get = vi.spyOn(apiClient, "get").mockResolvedValue(page(rows) as never);
  renderWithClient(
    <AuthContext.Provider value={authValue(staff())}>
      <ChangeRequestsQueue />
    </AuthContext.Provider>
  );
  return { get };
}

function renderDetail(initial: ChangeRequestDetail, onPost?: (path: string, body: unknown, server: { state: ChangeRequestDetail }) => unknown) {
  const server = { state: initial };
  vi.spyOn(apiClient, "get").mockImplementation(async (path: string) => {
    if (path !== DETAIL_PATH) throw new Error(`Unexpected GET ${path}`);
    return { data: server.state } as never;
  });
  const post = vi.spyOn(apiClient, "post").mockImplementation(async (path: string, body?: unknown) => {
    if (!onPost) throw new Error(`Unexpected POST ${path}`);
    return onPost(path, body, server) as never;
  });
  renderWithClient(
    <AuthContext.Provider value={authValue(staff())}>
      <ChangeRequestDetailView id={UUID} />
    </AuthContext.Provider>
  );
  return { server, post };
}

const outcome = (status: string) => ({ data: { id: UUID, request_code: "CRQ-000050", status, replayed: false } });

// ------------------------------------------------------------------ queue

describe("the queue with Coordinator requests", () => {
  it("renders a proposed Family (family = null) without crashing, with its placement and channel", async () => {
    renderQueue([
      summary(),
      summary({ id: "1d6f2a3e-9c1b-4b8e-8a2f-1c2d3e4f5a6b", request_code: "CRQ-000051", status: "APPLIED", created_family_code: "FAM-000900" }),
      summary({
        id: "2d6f2a3e-9c1b-4b8e-8a2f-1c2d3e4f5a6b",
        request_code: "CRQ-000052",
        type: "RESIDENCE_UPDATE",
        submission_channel: "FAMILY",
        family: { family_code: "FAM-000123", household_head_name: "سالم الاختبار" },
        placement: null,
        submitted_by: { name: "سالم الاختبار" },
      }),
    ]);

    const table = await screen.findByRole("table");
    const proposed = within(table).getAllByRole("row").find((row) => row.textContent?.includes("CRQ-000050"))!;
    expect(within(proposed).getByText("أسرة مقترحة")).toBeInTheDocument();
    expect(within(proposed).getByText("عشيرة أ · فرع أ")).toBeInTheDocument();
    expect(within(proposed).getByText("عبر منسّق")).toBeInTheDocument();
    expect(within(proposed).getByText("تسجيل أسرة جديدة")).toBeInTheDocument();

    const applied = within(table).getAllByRole("row").find((row) => row.textContent?.includes("CRQ-000051"))!;
    expect(within(applied).getByText("FAM-000900")).toBeInTheDocument();

    const household = within(table).getAllByRole("row").find((row) => row.textContent?.includes("CRQ-000052"))!;
    expect(within(household).getByText("FAM-000123")).toBeInTheDocument();
    expect(within(household).queryByText("عبر منسّق")).not.toBeInTheDocument();

    const list = screen.getByRole("list", { name: "قائمة طلبات تحديث البيانات" });
    expect(within(list).getByRole("link", { name: /CRQ-000050 — أسرة مقترحة \(عشيرة أ · فرع أ\)/ })).toBeInTheDocument();
  });

  it("shows «دون فرع» for a Clan-level proposal", async () => {
    renderQueue([summary({ placement: { clan: PLACEMENT.clan, branch: null } })]);
    expect((await screen.findAllByText("عشيرة أ · دون فرع")).length).toBeGreaterThan(0);
  });
});

// ------------------------------------------------------------------ detail

describe("the review of a proposed Family", () => {
  it("shows the placement, the channel and the Coordinator's acts — never a Family that does not exist", async () => {
    renderDetail(detail());

    expect(await screen.findByRole("heading", { name: "تسجيل أسرة جديدة" })).toBeInTheDocument();
    expect(screen.getByText("عشيرة أ", { selector: "[data-placement-clan]" })).toBeInTheDocument();
    expect(screen.getByText("فرع أ", { selector: "[data-placement-branch]" })).toBeInTheDocument();
    expect(screen.getByText("تُنشأ عند تطبيق الطلب")).toBeInTheDocument();
    expect(screen.getByText("منسّق", { selector: "[data-submission-channel]" })).toBeInTheDocument();
    expect(screen.getByText("قدّم المنسّق الطلب")).toBeInTheDocument();
    expect(screen.getByText("كما كتبه المنسّق.")).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: "الأسرة المقترحة" })).toBeInTheDocument();
    expect(screen.getAllByText("*****5678").length).toBeGreaterThan(0);
    expect(document.querySelector("[data-family-link]")).toBeNull();
  });

  it("approves with both attestations worded for a new household head", async () => {
    const { post } = renderDetail(detail(), (path, body, server) => {
      expect(path).toBe(`${DETAIL_PATH}/approve`);
      expect(body).toEqual({ attestations: ["IDENTITY_VERIFIED", "RELATIONSHIP_VERIFIED"], verified_national_id: "412345678" });
      server.state = detail({ status: "APPROVED", available_actions: ["apply"] });
      return outcome("APPROVED");
    });
    await userEvent.click(await screen.findByRole("button", { name: "اعتماد الطلب" }));
    const dialog = await screen.findByRole("dialog");

    expect(within(dialog).getByText("تحققتُ من هوية رب الأسرة المقترح من وثيقة رسمية (بطاقة الهوية).")).toBeInTheDocument();
    expect(
      within(dialog).getByText("تحققتُ من أن الشخص المقترح هو رب هذه الأسرة فعلًا، ومن انتماء الأسرة إلى العائلة والفرع المقترحين.")
    ).toBeInTheDocument();
    expect(within(dialog).queryByText("تحققتُ من صلة القرابة المذكورة برب الأسرة.")).not.toBeInTheDocument();
    expect(within(dialog).getByText("لا يشمل هذا الإقرار أي معلومات اجتماعية أو صحية أو معلومات مساعدات.")).toBeInTheDocument();

    await userEvent.click(within(dialog).getByRole("button", { name: "اعتماد الطلب" }));
    expect(post).not.toHaveBeenCalled();
    for (const box of within(dialog).getAllByRole("checkbox")) await userEvent.click(box);
    await userEvent.type(within(dialog).getByLabelText("رقم الهوية كما في الوثيقة"), "412345678");
    await userEvent.click(within(dialog).getByRole("button", { name: "اعتماد الطلب" }));

    await waitFor(() => expect(post).toHaveBeenCalledTimes(1));
    expect(await screen.findByText("اعتُمد الطلب. لم تتغير بيانات السجل بعد — طبّق التعديل عند الجاهزية.")).toBeInTheDocument();
  });

  it("words the placement attestation as Clan membership for a Family without a Branch", async () => {
    renderDetail(detail({ placement: { clan: PLACEMENT.clan, branch: null } }), () => outcome("APPROVED"));
    await userEvent.click(await screen.findByRole("button", { name: "اعتماد الطلب" }));
    const dialog = await screen.findByRole("dialog");

    expect(
      within(dialog).getByText("تحققتُ من أن الشخص المقترح هو رب هذه الأسرة فعلًا، ومن انتماء الأسرة إلى العائلة المقترحة (دون فرع).")
    ).toBeInTheDocument();
    expect(within(dialog).queryByText(/العائلة والفرع المقترحين/)).not.toBeInTheDocument();
  });

  it("applies as a registration and then shows the created Family", async () => {
    renderDetail(detail({ status: "APPROVED", available_actions: ["apply"] }), (path, _b, server) => {
      expect(path).toBe(`${DETAIL_PATH}/apply`);
      server.state = detail({ status: "APPLIED", available_actions: [], created_family_code: "FAM-000900" });
      return outcome("APPLIED");
    });
    await userEvent.click(await screen.findByRole("button", { name: "تطبيق التعديل" }));
    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByText("تسجيل الأسرة في السجل")).toBeInTheDocument();
    expect(within(dialog).getByText(/في العائلة والفرع المقترحين بالضبط/)).toBeInTheDocument();

    await userEvent.click(within(dialog).getByRole("button", { name: "تطبيق التعديل" }));
    expect(await screen.findByText("سُجّلت الأسرة في السجل.")).toBeInTheDocument();
    const link = await screen.findByRole("link", { name: "FAM-000900" });
    expect(link).toHaveAttribute("href", "/families/FAM-000900");
  });

  it("returns the request to the Coordinator, addressed to them", async () => {
    const { post } = renderDetail(detail(), (_p, _b, server) => {
      server.state = detail({ status: "RETURNED_FOR_CLARIFICATION", available_actions: [] });
      return outcome("RETURNED_FOR_CLARIFICATION");
    });
    await userEvent.click(await screen.findByRole("button", { name: "إرجاع للاستكمال" }));
    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByText("اطلب من المنسّق المعلومات الناقصة. لا تتغير البيانات المطلوبة ولا السجل.")).toBeInTheDocument();
    await userEvent.click(within(dialog).getByRole("button", { name: "إرجاع للاستكمال" }));
    expect(await within(dialog).findByText("رسالة الاستيضاح للمنسّق مطلوبة.")).toBeInTheDocument();

    await userEvent.type(within(dialog).getByLabelText(/رسالة الاستيضاح للمنسّق/), "أرفق صورة الهوية");
    await userEvent.click(within(dialog).getByRole("button", { name: "إرجاع للاستكمال" }));
    await waitFor(() => expect(post).toHaveBeenCalledWith(`${DETAIL_PATH}/return`, { public_message: "أرفق صورة الهوية" }));
    expect(await screen.findByText("أُعيد الطلب إلى المنسّق لاستكمال المعلومات.")).toBeInTheDocument();
  });

  it("shows the Coordinator's reply in the timeline", async () => {
    renderDetail(
      detail({
        status: "RESUBMITTED",
        available_actions: [],
        timeline: [
          event(),
          event({ event_type: "RETURNED", from_status: "UNDER_REVIEW", to_status: "RETURNED_FOR_CLARIFICATION", actor_side: "STAFF", actor: { name: "مراجع تجريبي" }, public_message: "أرفق صورة الهوية" }),
          event({ event_type: "RESUBMITTED", from_status: "RETURNED_FOR_CLARIFICATION", to_status: "RESUBMITTED", public_message: "أرفقتها" }),
        ],
      })
    );
    expect(await screen.findByText("ردّ المنسّق وأعاد تقديم الطلب")).toBeInTheDocument();
    expect(screen.getByText("رد المنسّق")).toBeInTheDocument();
    expect(screen.getByText("رسالة للمنسّق")).toBeInTheDocument();
  });

  it("rejects with a message addressed to the Coordinator, and writes no Family", async () => {
    const { post } = renderDetail(detail(), (_p, _b, server) => {
      server.state = detail({
        status: "REJECTED",
        available_actions: [],
        rejection: { reason_code: "CANNOT_VERIFY", message: "تعذّر التحقق", rejected_by: { name: "مراجع تجريبي" }, rejected_at: "2026-10-14T11:00:00+03:00" },
      });
      return outcome("REJECTED");
    });
    await userEvent.click(await screen.findByRole("button", { name: "رفض الطلب" }));
    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByText("الرفض نهائي. يمكن للمنسّق تقديم طلب جديد إذا لزم.")).toBeInTheDocument();
    await userEvent.click(within(dialog).getByRole("combobox"));
    await userEvent.click(await screen.findByRole("option", { name: "سبب آخر" }));
    await userEvent.click(within(dialog).getByRole("button", { name: "رفض الطلب" }));
    expect(await within(dialog).findByText("رسالة الرفض للمنسّق مطلوبة لهذا السبب.")).toBeInTheDocument();
    await userEvent.type(within(dialog).getByLabelText(/رسالة الرفض للمنسّق/), "تعذّر التحقق");
    await userEvent.click(within(dialog).getByRole("button", { name: "رفض الطلب" }));

    await waitFor(() => expect(post).toHaveBeenCalledWith(`${DETAIL_PATH}/reject`, { rejection_reason_code: "OTHER", public_message: "تعذّر التحقق" }));
    expect(await screen.findByText("رسالة الرفض للمنسّق")).toBeInTheDocument();
    expect(screen.getByText("تُنشأ عند تطبيق الطلب")).toBeInTheDocument();
  });
});

describe("a Coordinator's PERSON_ADD (a Family exists)", () => {
  it("shows the Family and addresses the Coordinator", async () => {
    renderDetail(
      detail({
        type: "ADD_FAMILY_MEMBER",
        family: { family_code: "FAM-000123", household_head_name: "سالم الاختبار" },
        placement: null,
        presentation: { rows: [{ label: "الاسم الكامل", current: null, proposed: "فرد تجريبي" }] },
      })
    );
    expect(await screen.findByRole("link", { name: "FAM-000123" })).toHaveAttribute("href", "/families/FAM-000123");
    expect(screen.getByText("سالم الاختبار")).toBeInTheDocument();
    expect(screen.getByText("البيانات الحالية في السجل مقابل ما يطلبه المنسّق.")).toBeInTheDocument();
    expect(document.querySelector("[data-placement-clan]")).toBeNull();
    await userEvent.click(screen.getByRole("button", { name: "اعتماد الطلب" }));
    expect(within(await screen.findByRole("dialog")).getByText("تحققتُ من صلة القرابة المذكورة برب الأسرة.")).toBeInTheDocument();
  });
});

// ------------------------------------------------------------------ CO-02.7b

describe("queue filters by channel and submitter (CO-02.7b)", () => {
  it("sends the channel and submitter from the URL, and ignores invalid ones", async () => {
    nav.search = "channel=COORDINATOR&submitter=" + encodeURIComponent("منسّق");
    const { get } = renderQueue([summary()]);
    await screen.findByRole("table");
    const query = new URLSearchParams((get.mock.calls[0][0] as string).split("?")[1]);
    expect(query.get("channel")).toBe("COORDINATOR");
    expect(query.get("submitter")).toBe("منسّق");
  });

  it("never sends an unknown channel or a one-letter submitter", async () => {
    nav.search = "channel=STAFF&submitter=x";
    const { get } = renderQueue([summary()]);
    await screen.findByRole("table");
    const path = get.mock.calls[0][0] as string;
    expect(path).not.toContain("channel");
    expect(path).not.toContain("submitter");
  });

  it("puts the submitter in the URL on Enter, and the channel from its select", async () => {
    renderQueue([summary()]);
    await screen.findByRole("table");
    await userEvent.type(screen.getByLabelText("مقدّم الطلب"), "منسّق{Enter}");
    expect(router.push).toHaveBeenLastCalledWith(expect.stringContaining("submitter=" + encodeURIComponent("منسّق")), { scroll: false });
    await userEvent.click(screen.getByRole("combobox", { name: "جهة التقديم" }));
    await userEvent.click(await screen.findByRole("option", { name: "عبر منسّق" }));
    expect(router.push).toHaveBeenLastCalledWith(expect.stringContaining("channel=COORDINATOR"), { scroll: false });
  });
});

describe("rejecting a returned request (CO-02.7b)", () => {
  it("offers the rejection with every reason and sends the chosen code", async () => {
    const { post } = renderDetail(detail({ status: "RETURNED_FOR_CLARIFICATION", available_actions: ["reject"] }), (path, body, server) => {
      expect(path).toBe(`${DETAIL_PATH}/reject`);
      expect(body).toEqual({ rejection_reason_code: "INSUFFICIENT_INFORMATION" });
      server.state = detail({ status: "REJECTED", available_actions: [] });
      return outcome("REJECTED");
    });
    await userEvent.click(await screen.findByRole("button", { name: "رفض الطلب" }));
    const dialog = await screen.findByRole("dialog");
    await userEvent.click(within(dialog).getByRole("button", { name: "رفض الطلب" }));
    expect(await within(dialog).findByText("اختر سبب الرفض.")).toBeInTheDocument();
    expect(post).not.toHaveBeenCalled();
    await userEvent.click(within(dialog).getByRole("combobox"));
    expect(await screen.findByRole("option", { name: "سبب آخر" })).toBeInTheDocument();
    await userEvent.click(screen.getByRole("option", { name: "المعلومات المقدمة غير كافية" }));
    await userEvent.click(within(dialog).getByRole("button", { name: "رفض الطلب" }));
    await waitFor(() => expect(post).toHaveBeenCalledTimes(1));
  });
});
