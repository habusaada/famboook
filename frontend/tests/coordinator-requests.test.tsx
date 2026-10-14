import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { CoordinatorAddMemberForm } from "@/components/family/coordinator/requests/coordinator-add-member-form";
import { CoordinatorNewRequest } from "@/components/family/coordinator/requests/coordinator-new-request";
import { CoordinatorRegisterFamilyForm } from "@/components/family/coordinator/requests/coordinator-register-family-form";
import { CoordinatorRequestDetail } from "@/components/family/coordinator/requests/coordinator-request-detail";
import { CoordinatorRequests } from "@/components/family/coordinator/requests/coordinator-requests";
import { ApiError, apiClient } from "@/lib/api/client";
import type { CoordinatorPlacementOption, CoordinatorRequest, CoordinatorRequestSummary } from "@/lib/api/coordinator-requests";
import { browserStorageDump, renderWithClient } from "./helpers";

// CO-02.6b: the Coordinator request workspace — dashboard, discovery, the
// PERSON_ADD and FAMILY_ADD forms and the request detail — over the CO-02.4
// to CO-02.6a API. The server decides everything; the client only presents.
// Synthetic data and IDs only.

const router = { push: vi.fn(), replace: vi.fn() };
let search = new URLSearchParams();
vi.mock("next/navigation", () => ({
  useRouter: () => router,
  usePathname: () => "/family/coordinator/requests",
  useSearchParams: () => search,
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
  search = new URLSearchParams();
});

const BASE = "/api/v1/family/coordinator";
const ID = "5b1e2c3d-4f5a-4b6c-8d7e-9f0a1b2c3d4e";
const OPEN = { data: [{ type: "ADD_FAMILY_MEMBER" }, { type: "REGISTER_FAMILY" }], meta: { submission_enabled: true } };
const CLOSED = { data: [], meta: { submission_enabled: false } };
const RELATIONSHIPS = { data: [{ code: "SON", name: "ابن" }, { code: "DAUGHTER", name: "ابنة" }] };
const FAMILIES = {
  data: [{ family_code: "FAM-000101", clan_name: "عشيرة الاختبار", branch_group_name: null, branch_name: "فرع أ", head_name: "رب أسرة تجريبي", active_member_count: 3 }],
  meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
};
const PLACEMENTS: { data: CoordinatorPlacementOption[] } = {
  data: [
    { clan: { code: "CLAN_A", name: "عشيرة أ" }, branchless_allowed: true, branches: [{ code: "BR_A", name: "فرع أ", group: null }] },
    { clan: { code: "CLAN_B", name: "عشيرة ب" }, branchless_allowed: false, branches: [{ code: "BR_B", name: "فرع ب", group: { code: "G1", name: "مجموعة ١" } }] },
  ],
};

function row(overrides: Partial<CoordinatorRequestSummary> = {}): CoordinatorRequestSummary {
  return {
    id: ID,
    request_code: "CRQ-000301",
    type: "ADD_FAMILY_MEMBER",
    type_available: true,
    status: "SUBMITTED",
    family_code: "FAM-000101",
    placement: null,
    created_family_code: null,
    summary: "فرد تجريبي",
    submitted_at: "2026-10-14T08:00:00+03:00",
    approved_at: null,
    rejected_at: null,
    applied_at: null,
    cancelled_at: null,
    available_actions: ["cancel"],
    ...overrides,
  };
}

function detail(overrides: Partial<CoordinatorRequest> = {}): CoordinatorRequest {
  return {
    id: ID,
    request_code: "CRQ-000301",
    type: "ADD_FAMILY_MEMBER",
    type_available: true,
    status: "RETURNED_FOR_CLARIFICATION",
    family_code: "FAM-000101",
    placement: null,
    created_family_code: null,
    summary: "فرد تجريبي",
    submitted_at: "2026-10-14T08:00:00+03:00",
    reason: null,
    presentation: { rows: [{ label: "رقم الهوية", current: null, proposed: "*****4567" }] },
    approved_at: null,
    applied_at: null,
    cancelled_at: null,
    rejection: null,
    timeline: [
      { event_type: "SUBMITTED", from_status: null, to_status: "SUBMITTED", actor_side: "COORDINATOR", reason_code: null, public_message: null, created_at: "2026-10-14T08:00:00+03:00" },
      { event_type: "RETURNED", from_status: "UNDER_REVIEW", to_status: "RETURNED_FOR_CLARIFICATION", actor_side: "STAFF", reason_code: null, public_message: "أرفق ما يثبت العلاقة.", created_at: "2026-10-14T09:00:00+03:00" },
    ],
    available_actions: ["resubmit", "cancel"],
    ...overrides,
  };
}

const page = (rows: CoordinatorRequestSummary[], total = rows.length, lastPage = 1) => ({
  data: rows,
  links: {},
  meta: { current_page: 1, last_page: lastPage, per_page: 20, total, from: 1, to: rows.length, path: "" },
});

/** Answers GETs by path (exact, or the part before "?"); anything else fails the test. */
function mockGet(routes: Record<string, unknown>) {
  return vi.spyOn(apiClient, "get").mockImplementation(async (path: string) => {
    const value = path in routes ? routes[path] : routes[path.split("?")[0]];
    if (value instanceof Error) throw value;
    if (typeof value === "function") return (value as (p: string) => unknown)(path);
    if (value === undefined) throw new Error(`unexpected GET ${path}`);
    return value;
  });
}

const outcome = (replayed = false) => ({ data: { id: ID, request_code: "CRQ-000301", status: "SUBMITTED", replayed } });

async function choose(user: ReturnType<typeof userEvent.setup>, label: RegExp, option: string | RegExp) {
  await user.click(screen.getByRole("combobox", { name: label }));
  await user.click(await screen.findByRole("option", { name: option }));
}

// ---------------------------------------------------------------- dashboard

describe("dashboard", () => {
  it("lists the requests with counts from the server's totals and offers a new request", async () => {
    const counts: Record<string, number> = { "": 12, RETURNED_FOR_CLARIFICATION: 2, APPLIED: 5, REJECTED: 1 };
    const get = mockGet({
      [`${BASE}/requests`]: (path: string) => {
        const params = new URLSearchParams(path.split("?")[1] ?? "");
        if (params.get("per_page") === "1") return page([row()], counts[params.get("status") ?? ""]);
        return page([row(), row({ id: "6b1e2c3d-4f5a-4b6c-8d7e-9f0a1b2c3d4e", request_code: "CRQ-000302", type: "REGISTER_FAMILY", family_code: null, placement: { clan: { code: "CLAN_A", name: "عشيرة أ" }, branch: null }, summary: "رب أسرة مقترح", status: "RETURNED_FOR_CLARIFICATION" })], 12);
      },
      [`${BASE}/requests/types`]: OPEN,
    });
    renderWithClient(<CoordinatorRequests />);

    const overview = await screen.findByRole("region", { name: "ملخص الطلبات" });
    await waitFor(() => expect(within(overview).getByText((12).toLocaleString("ar"))).toBeInTheDocument());
    expect(within(overview).getByText((2).toLocaleString("ar"))).toBeInTheDocument();
    expect(within(overview).getByText((5).toLocaleString("ar"))).toBeInTheDocument();
    const cards = screen.getByRole("list", { name: "طلبات التنسيق" });
    expect(within(cards).getByText("رب أسرة مقترح")).toBeInTheDocument();
    expect(within(cards).getByText("عشيرة أ")).toBeInTheDocument();
    expect(within(cards).getAllByRole("link")[0]).toHaveAttribute("href", `/family/coordinator/requests/${ID}`);
    expect(screen.getByRole("table")).toBeInTheDocument();
    expect(await screen.findByRole("link", { name: /طلب جديد/ })).toHaveAttribute("href", "/family/coordinator/requests/new");
    // Counts are one-row pages per status — never derived from the visible rows.
    expect(get.mock.calls.map(([p]) => p)).toContain(`${BASE}/requests?status=RETURNED_FOR_CLARIFICATION&per_page=1`);
  });

  it("puts filters in the URL and hides «new request» while the switch is closed", async () => {
    search = new URLSearchParams("status=APPLIED&type=REGISTER_FAMILY");
    const get = mockGet({ [`${BASE}/requests`]: page([]), [`${BASE}/requests/types`]: CLOSED });
    const user = userEvent.setup();
    renderWithClient(<CoordinatorRequests />);

    expect(await screen.findByText("لا توجد طلبات مطابقة")).toBeInTheDocument();
    expect(get.mock.calls.map(([p]) => p)).toContain(`${BASE}/requests?status=APPLIED&type=REGISTER_FAMILY`);
    await choose(user, /الحالة/, "مرفوض");
    expect(router.push).toHaveBeenCalledWith("/family/coordinator/requests?status=REJECTED&type=REGISTER_FAMILY", { scroll: false });
    await waitFor(() => expect(get.mock.calls.map(([p]) => p)).toContain(`${BASE}/requests/types`));
    expect(screen.queryByRole("link", { name: /طلب جديد/ })).not.toBeInTheDocument();
  });

  it.each([
    [403, "لا تملك صلاحية تنفيذ هذا الإجراء."],
    [429, "محاولات كثيرة خلال وقت قصير. حاول بعد قليل."],
  ])("shows a safe message for %s", async (status, message) => {
    mockGet({ [`${BASE}/requests`]: new ApiError(status, { message: "raw server text" }), [`${BASE}/requests/types`]: OPEN });
    renderWithClient(<CoordinatorRequests />);
    expect(await screen.findByText(message)).toBeInTheDocument();
    expect(screen.queryByText("raw server text")).not.toBeInTheDocument();
  });
});

// ---------------------------------------------------------------- discovery

describe("new request", () => {
  it("offers only the types the server lists", async () => {
    mockGet({ [`${BASE}/requests/types`]: { data: [{ type: "REGISTER_FAMILY" }], meta: { submission_enabled: true } } });
    renderWithClient(<CoordinatorNewRequest />);
    expect(await screen.findByRole("link", { name: /تسجيل أسرة جديدة/ })).toHaveAttribute("href", "/family/coordinator/requests/new/register-family");
    expect(screen.queryByRole("link", { name: /إضافة فرد/ })).not.toBeInTheDocument();
  });

  it("says when submission is closed", async () => {
    mockGet({ [`${BASE}/requests/types`]: CLOSED });
    renderWithClient(<CoordinatorNewRequest />);
    expect(await screen.findByRole("heading", { name: "تقديم طلبات التنسيق غير متاح حاليًا" })).toBeInTheDocument();
  });
});

// ------------------------------------------------------------ PERSON_ADD

describe("add family member", () => {
  function mockForm(overrides: Record<string, unknown> = {}) {
    return mockGet({
      [`${BASE}/requests/types`]: OPEN,
      [`${BASE}/requests/relationship-types`]: RELATIONSHIPS,
      [`${BASE}/families`]: FAMILIES,
      ...overrides,
    });
  }

  async function fill(user: ReturnType<typeof userEvent.setup>, nationalId = "401234567") {
    await user.click(await screen.findByRole("button", { name: /رب أسرة تجريبي/ }));
    await user.type(screen.getByLabelText(/الاسم الكامل/), "فرد تجريبي جديد");
    await user.type(screen.getByLabelText(/رقم الهوية/), nationalId);
    await choose(user, /الجنس/, "أنثى");
    await choose(user, /صلة القرابة برب الأسرة/, "ابنة");
  }

  it("submits to the chosen Family in scope and opens the new request", async () => {
    mockForm();
    const post = vi.spyOn(apiClient, "post").mockResolvedValue(outcome());
    const user = userEvent.setup();
    renderWithClient(<CoordinatorAddMemberForm />);
    await fill(user, "٤٠١-٢٣٤-٥٦٧");
    await user.click(screen.getByRole("button", { name: "إرسال الطلب للمراجعة" }));

    await waitFor(() => expect(router.push).toHaveBeenCalledWith(`/family/coordinator/requests/${ID}`));
    expect(post).toHaveBeenCalledTimes(1);
    const [path, body] = post.mock.calls[0] as [string, Record<string, unknown>];
    expect(path).toBe(`${BASE}/families/FAM-000101/requests`);
    expect(body).toMatchObject({ type: "ADD_FAMILY_MEMBER", reason: null, data: { national_id: "401234567", relationship: "DAUGHTER", gender: "FEMALE" } });
    expect(typeof body.client_reference).toBe("string");
    expect(browserStorageDump()).not.toContain("401234567");
  });

  it("requires a Family and a nine-digit, real National ID before sending", async () => {
    mockForm();
    const post = vi.spyOn(apiClient, "post");
    const user = userEvent.setup();
    renderWithClient(<CoordinatorAddMemberForm />);
    await screen.findByRole("button", { name: "إرسال الطلب للمراجعة" });

    await user.click(screen.getByRole("button", { name: "إرسال الطلب للمراجعة" }));
    expect(await screen.findByText("اختر الأسرة ضمن نطاقك.")).toBeInTheDocument();
    await user.type(screen.getByLabelText(/رقم الهوية/), "12345");
    await user.click(screen.getByRole("button", { name: "إرسال الطلب للمراجعة" }));
    expect(await screen.findByText("رقم الهوية يجب أن يتكون من 9 أرقام.")).toBeInTheDocument();
    await user.clear(screen.getByLabelText(/رقم الهوية/));
    await user.type(screen.getByLabelText(/رقم الهوية/), "111111111");
    await user.click(screen.getByRole("button", { name: "إرسال الطلب للمراجعة" }));
    expect(await screen.findByText("أدخل رقم الهوية الحقيقي كما في الوثيقة.")).toBeInTheDocument();
    expect(post).not.toHaveBeenCalled();
  });

  it("sends once however often it is clicked, and a manual retry keeps the same reference", async () => {
    mockForm();
    let rejectFirst: (error: unknown) => void = () => undefined;
    const post = vi
      .spyOn(apiClient, "post")
      .mockImplementationOnce(() => new Promise((_, reject) => (rejectFirst = reject)))
      .mockResolvedValue(outcome(true));
    const user = userEvent.setup();
    renderWithClient(<CoordinatorAddMemberForm />);
    await fill(user);
    await user.dblClick(screen.getByRole("button", { name: "إرسال الطلب للمراجعة" }));
    await waitFor(() => expect(post).toHaveBeenCalledTimes(1));
    // While it is in flight the button is disabled: no second send.
    expect(screen.getByRole("button", { name: /جارٍ الإرسال/ })).toBeDisabled();
    rejectFirst(new TypeError("network down"));

    expect(await screen.findByText(/لن يُكرَّر طلبك/)).toBeInTheDocument();
    expect(post).toHaveBeenCalledTimes(1);
    await user.click(screen.getByRole("button", { name: "إرسال الطلب للمراجعة" }));
    await waitFor(() => expect(post).toHaveBeenCalledTimes(2));
    const refs = post.mock.calls.map(([, body]) => (body as { client_reference: string }).client_reference);
    expect(refs[0]).toBe(refs[1]);
  });

  it.each([
    [new ApiError(409, { code: "CHANGE_REQUEST_ALREADY_OPEN" }), "يوجد طلب مفتوح لنفس رقم الهوية. تابعه من «طلبات التنسيق»."],
    [new ApiError(404, { code: "CHANGE_REQUEST_NOT_FOUND" }), "الأسرة غير متاحة ضمن نطاقك."],
    [new ApiError(503, { code: "CHANGE_REQUEST_SUBMISSION_DISABLED" }), "تقديم طلبات التنسيق غير متاح حاليًا."],
    [new ApiError(429, {}), "محاولات كثيرة خلال وقت قصير. حاول بعد قليل."],
  ])("maps a refusal to a safe message (%#)", async (error, message) => {
    mockForm();
    vi.spyOn(apiClient, "post").mockRejectedValue(error);
    const user = userEvent.setup();
    renderWithClient(<CoordinatorAddMemberForm />);
    await fill(user);
    await user.click(screen.getByRole("button", { name: "إرسال الطلب للمراجعة" }));
    expect(await screen.findByText(message)).toBeInTheDocument();
    expect(router.push).not.toHaveBeenCalled();
  });

  it("shows the server's field errors on their fields", async () => {
    mockForm();
    vi.spyOn(apiClient, "post").mockRejectedValue(new ApiError(422, { message: "x", errors: { national_id: ["رقم الهوية غير صالح من الخادم."] } }));
    const user = userEvent.setup();
    renderWithClient(<CoordinatorAddMemberForm />);
    await fill(user);
    await user.click(screen.getByRole("button", { name: "إرسال الطلب للمراجعة" }));
    expect(await screen.findByText("رقم الهوية غير صالح من الخادم.")).toBeInTheDocument();
  });

  it("is unavailable unless the server lists ADD_FAMILY_MEMBER", async () => {
    const get = mockForm({ [`${BASE}/requests/types`]: { data: [{ type: "REGISTER_FAMILY" }], meta: { submission_enabled: true } } });
    renderWithClient(<CoordinatorAddMemberForm />);
    expect(await screen.findByRole("heading", { name: "طلبات إضافة فرد غير متاحة لك حاليًا" })).toBeInTheDocument();
    expect(get.mock.calls.map(([p]) => p)).not.toContain(`${BASE}/requests/relationship-types`);
  });
});

// ------------------------------------------------------------ FAMILY_ADD

describe("register family", () => {
  function mockForm(overrides: Record<string, unknown> = {}) {
    return mockGet({ [`${BASE}/requests/types`]: OPEN, [`${BASE}/requests/placements`]: PLACEMENTS, ...overrides });
  }

  async function fillHead(user: ReturnType<typeof userEvent.setup>) {
    await user.type(screen.getByLabelText(/الاسم الكامل/), "رب أسرة مقترح");
    await user.type(screen.getByLabelText(/رقم الهوية/), "412345678");
    await choose(user, /الجنس/, "ذكر");
    await user.type(screen.getByLabelText(/مكان السكن الأصلي/), "بلدة تجريبية");
  }

  it("offers «no branch» only where the scope allows it and submits the proposal", async () => {
    mockForm();
    const post = vi.spyOn(apiClient, "post").mockResolvedValue(outcome());
    const user = userEvent.setup();
    renderWithClient(<CoordinatorRegisterFamilyForm />);
    await screen.findByRole("button", { name: "إرسال الطلب للمراجعة" });

    await choose(user, /العائلة/, "عشيرة ب");
    await user.click(screen.getByRole("combobox", { name: /الفرع/ }));
    expect(screen.queryByRole("option", { name: /دون فرع/ })).not.toBeInTheDocument();
    expect(screen.getByRole("option", { name: "فرع ب — مجموعة ١" })).toBeInTheDocument();
    await user.keyboard("{Escape}");

    await choose(user, /العائلة/, "عشيرة أ");
    await choose(user, /الفرع/, /دون فرع/);
    await fillHead(user);
    await user.click(screen.getByRole("button", { name: "إرسال الطلب للمراجعة" }));

    await waitFor(() => expect(router.push).toHaveBeenCalledWith(`/family/coordinator/requests/${ID}`));
    const [path, body] = post.mock.calls[0] as [string, Record<string, unknown>];
    expect(path).toBe(`${BASE}/requests`);
    expect(body).toMatchObject({
      type: "REGISTER_FAMILY",
      placement: { clan_code: "CLAN_A", branch_code: null },
      data: { household_head: { full_name: "رب أسرة مقترح", national_id: "412345678", gender: "MALE" }, residence: { original_residence_text: "بلدة تجريبية" } },
    });
    // The original residence only: no current-residence field is sent.
    expect(Object.keys((body.data as { residence: object }).residence)).toEqual(["original_residence_text"]);
  });

  it("requires a placement, the head and the original residence", async () => {
    mockForm();
    const post = vi.spyOn(apiClient, "post");
    const user = userEvent.setup();
    renderWithClient(<CoordinatorRegisterFamilyForm />);
    await user.click(await screen.findByRole("button", { name: "إرسال الطلب للمراجعة" }));

    expect(await screen.findByText("اختر العائلة.")).toBeInTheDocument();
    expect(screen.getByText("اختر الفرع.")).toBeInTheDocument();
    expect(screen.getByText("رقم هوية رب الأسرة مطلوب.")).toBeInTheDocument();
    expect(screen.getByText("مكان السكن الأصلي مطلوب.")).toBeInTheDocument();
    expect(post).not.toHaveBeenCalled();
  });

  it("shows the server's single placement refusal on the placement", async () => {
    mockForm();
    vi.spyOn(apiClient, "post").mockRejectedValue(new ApiError(422, { message: "x", errors: { placement: ["مكان الأسرة المقترح غير متاح ضمن نطاقك."] } }));
    const user = userEvent.setup();
    renderWithClient(<CoordinatorRegisterFamilyForm />);
    await screen.findByRole("button", { name: "إرسال الطلب للمراجعة" });
    await choose(user, /العائلة/, "عشيرة أ");
    await choose(user, /الفرع/, "فرع أ");
    await fillHead(user);
    await user.click(screen.getByRole("button", { name: "إرسال الطلب للمراجعة" }));
    expect(await screen.findByText("مكان الأسرة المقترح غير متاح ضمن نطاقك.")).toBeInTheDocument();
  });

  it("explains when the scope offers no placement", async () => {
    mockForm({ [`${BASE}/requests/placements`]: { data: [] } });
    renderWithClient(<CoordinatorRegisterFamilyForm />);
    expect(await screen.findByRole("heading", { name: "لا يوجد مكان متاح لتسجيل أسرة ضمن نطاقك" })).toBeInTheDocument();
  });
});

// --------------------------------------------------------------- detail

describe("request detail", () => {
  it("shows the status, the masked proposal, the reviewers' message and the server's actions", async () => {
    mockGet({ [`${BASE}/requests/${ID}`]: { data: detail() } });
    renderWithClient(<CoordinatorRequestDetail id={ID} />);

    expect(await screen.findByText("CRQ-000301")).toBeInTheDocument();
    expect(screen.getByText("مطلوب استكمال معلومات")).toBeInTheDocument();
    expect(screen.getAllByText("*****4567").length).toBeGreaterThan(0);
    expect(document.body.textContent).not.toContain("401234567");
    expect(screen.getByText("أرفق ما يثبت العلاقة.")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "FAM-000101" })).toHaveAttribute("href", "/family/coordinator/families/FAM-000101");
    expect(screen.getByRole("button", { name: "إرسال الاستكمال" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "إلغاء الطلب" })).toBeInTheDocument();
  });

  it("offers nothing the server does not list", async () => {
    mockGet({ [`${BASE}/requests/${ID}`]: { data: detail({ status: "UNDER_REVIEW", available_actions: [] }) } });
    renderWithClient(<CoordinatorRequestDetail id={ID} />);
    expect(await screen.findByText("CRQ-000301")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "إرسال الاستكمال" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "إلغاء الطلب" })).not.toBeInTheDocument();
  });

  it("cancels only after confirmation, once, and re-reads the request", async () => {
    const get = mockGet({ [`${BASE}/requests/${ID}`]: { data: detail() } });
    const post = vi.spyOn(apiClient, "post").mockResolvedValue({ data: { id: ID, request_code: "CRQ-000301", status: "CANCELLED", replayed: false } });
    const user = userEvent.setup();
    renderWithClient(<CoordinatorRequestDetail id={ID} />);

    await user.click(await screen.findByRole("button", { name: "إلغاء الطلب" }));
    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByText(/لا يغيّر شيئًا في السجل/)).toBeInTheDocument();
    expect(post).not.toHaveBeenCalled();
    await user.click(within(dialog).getByRole("button", { name: "إلغاء الطلب" }));

    expect(await screen.findByText("أُلغي الطلب. لم يتغير شيء في السجل.")).toBeInTheDocument();
    expect(post).toHaveBeenCalledTimes(1);
    expect(post).toHaveBeenCalledWith(`${BASE}/requests/${ID}/cancel`, {});
    await waitFor(() => expect(get.mock.calls.filter(([p]) => p === `${BASE}/requests/${ID}`).length).toBeGreaterThan(1));
  });

  it("sends a clarification reply as text — the proposal itself is not editable", async () => {
    mockGet({ [`${BASE}/requests/${ID}`]: { data: detail() } });
    const post = vi.spyOn(apiClient, "post").mockResolvedValue({ data: { id: ID, request_code: "CRQ-000301", status: "RESUBMITTED", replayed: false } });
    const user = userEvent.setup();
    renderWithClient(<CoordinatorRequestDetail id={ID} />);

    await user.click(await screen.findByRole("button", { name: "إرسال الاستكمال" }));
    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).queryAllByRole("textbox")).toHaveLength(1);
    await user.type(within(dialog).getByRole("textbox"), "أرفقت الوثيقة.");
    await user.click(within(dialog).getByRole("button", { name: "إرسال الاستكمال" }));

    await waitFor(() => expect(post).toHaveBeenCalledWith(`${BASE}/requests/${ID}/resubmit`, { response: "أرفقت الوثيقة." }));
  });

  it("keeps a failed action in the dialog with a safe message and never retries it", async () => {
    mockGet({ [`${BASE}/requests/${ID}`]: { data: detail() } });
    const post = vi.spyOn(apiClient, "post").mockRejectedValue(new ApiError(409, { code: "CHANGE_REQUEST_INVALID_TRANSITION", message: "raw" }));
    const user = userEvent.setup();
    renderWithClient(<CoordinatorRequestDetail id={ID} />);
    await user.click(await screen.findByRole("button", { name: "إلغاء الطلب" }));
    await user.click(within(await screen.findByRole("dialog")).getByRole("button", { name: "إلغاء الطلب" }));

    expect(await screen.findByText("تغيّرت حالة الطلب. حدّثنا الصفحة بحالته الحالية.")).toBeInTheDocument();
    expect(post).toHaveBeenCalledTimes(1);
  });

  it("shows a proposed Family's placement and, once applied, its created code", async () => {
    mockGet({
      [`${BASE}/requests/${ID}`]: {
        data: detail({
          type: "REGISTER_FAMILY",
          status: "APPLIED",
          family_code: "FAM-000777",
          created_family_code: "FAM-000777",
          placement: { clan: { code: "CLAN_A", name: "عشيرة أ" }, branch: null },
          summary: "رب أسرة مقترح",
          available_actions: [],
        }),
      },
    });
    renderWithClient(<CoordinatorRequestDetail id={ID} />);
    expect(await screen.findByText("عشيرة أ")).toBeInTheDocument();
    expect(screen.getByText("دون فرع")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "FAM-000777" })).toBeInTheDocument();
    expect(screen.getByText("رب أسرة مقترح")).toBeInTheDocument();
  });

  it("treats a request outside the current scope as unavailable", async () => {
    mockGet({ [`${BASE}/requests/${ID}`]: new ApiError(404, { code: "CHANGE_REQUEST_NOT_FOUND" }) });
    renderWithClient(<CoordinatorRequestDetail id={ID} />);
    expect(await screen.findByText("لم نجد هذا الطلب ضمن طلباتك في نطاقك الحالي.")).toBeInTheDocument();
  });
});
