import { readFileSync } from "node:fs";
import { join } from "node:path";
import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { CoordinatorFollowupDetail, CoordinatorFollowupList } from "@/components/family/coordinator/followups/coordinator-followups";
import { CoordinatorHome } from "@/components/family/coordinator/coordinator-home";
import { ApiError, apiClient } from "@/lib/api/client";
import { FAMILY_ME_QUERY_KEY } from "@/lib/api/family-auth";
import { type FollowupCounts, type FollowupDetail, type FollowupEvent, type FollowupSummary, followupListPath } from "@/lib/api/followups";
import type { PaginatedResponse } from "@/lib/types/api/family";
import { renderWithClient } from "./helpers";

// FB-NOTIFY-01E.3-F3.3: the Coordinator follow-up screens over the F3.2
// Coordinator API (/api/v1/family/coordinator/followups — the CURRENT scope).
// Management is offered only on the server's `can_manage`; history actors are
// labels. Synthetic data only.

const router = { push: vi.fn(), replace: vi.fn() };
const nav = { search: "", pathname: "/family/coordinator/followups" };
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
vi.mock("@/components/family/coordinator/coordinator-gate", () => ({
  useCoordinatorContext: () => ({ scopes: [{ type: "BRANCH", code: "BR_A", name: "فرع أ", clan_name: "عشيرة الاختبار" }], family_count: 3 }),
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
  nav.pathname = "/family/coordinator/followups";
});

const UUID = "9d1e2c3d-4f5a-4b6c-8d7e-9f0a1b2c3d4e";
const OTHER = "9d1e2c3d-4f5a-4b6c-8d7e-9f0a1b2c3d4f";
const BASE = "/api/v1/family/coordinator/followups";
const DETAIL = `${BASE}/${UUID}`;
const HISTORY = `${DETAIL}/history`;
const SUMMARY = `${BASE}/summary`;
const OPEN_LIST = `${BASE}?status=OPEN`;
const FUTURE = "2099-01-01T00:00:00+00:00";

function row(o: Partial<FollowupSummary> = {}): FollowupSummary {
  return {
    id: UUID,
    stage: "FIRST",
    trigger_reason: "UNVIEWED",
    status: "OPEN",
    outcome: null,
    close_reason: null,
    due_at: "2026-10-20T09:00:00+00:00",
    opened_at: "2026-10-20T09:01:00+00:00",
    closed_at: null,
    message: { notice_code: "NTF-000077", level: "NORMAL", title: "اجتماع أسر الفرع", expires_at: FUTURE },
    family: { family_code: "FAM-000321", head_name: "منى الاختبار" },
    can_manage: true,
    ...o,
  };
}

function detail(o: Partial<FollowupDetail> = {}): FollowupDetail {
  return {
    ...row(),
    recipient: { viewed_at: null, acknowledged_at: null, acknowledged_late: false, had_family_account: false },
    contacts: 0,
    ...o,
  };
}

const COUNTS: FollowupCounts = {
  open: 5,
  open_by_stage: { FIRST: 4, SECOND: 1 },
  open_by_level: { NORMAL: 3, IMPORTANT: 2 },
  expired_open: 0,
  closed_last_30_days: { SATISFIED: 2, EXPIRED: 0, CLOSED_MANUALLY: 1 },
  mine_open: 3,
};

const EVENTS: FollowupEvent[] = [
  { type: "FOLLOWUP_OPENED", actor: { label: "SYSTEM" }, reason_code: null, stage: "FIRST", outcome: null, note: null, occurred_at: "2026-10-20T09:01:00+00:00" },
  { type: "CONTACT_RECORDED", actor: { label: "COORDINATOR" }, reason_code: "MESSAGE_LEFT", stage: "FIRST", outcome: null, note: "تركنا رسالة.", occurred_at: "2026-10-20T10:00:00+00:00" },
  { type: "CONTACT_RECORDED", actor: { label: "YOU" }, reason_code: "CALLED_INFORMED", stage: "FIRST", outcome: null, note: null, occurred_at: "2026-10-20T11:00:00+00:00" },
  { type: "CONTACT_RECORDED", actor: { label: "STAFF" }, reason_code: "VISITED", stage: "FIRST", outcome: null, note: null, occurred_at: "2026-10-20T12:00:00+00:00" },
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
    if (reply instanceof Error) throw reply;
    return reply as never;
  });
}

const refusal = (status: number, code?: string) => new ApiError(status, { message: "مرفوض", ...(code ? { code } : {}) });

async function chooseOption(combobox: string, option: string, container: HTMLElement) {
  await userEvent.click(within(container).getByRole("combobox", { name: combobox }));
  await userEvent.click(await screen.findByRole("option", { name: option }));
}

describe("entry and route placement", () => {
  it("the Coordinator Space home links to «متابعات الأسر»", () => {
    vi.spyOn(apiClient, "get").mockImplementation(async () => page([]) as never);
    renderWithClient(<CoordinatorHome />);
    expect(screen.getByRole("link", { name: /متابعات الأسر/ })).toHaveAttribute("href", "/family/coordinator/followups");
  });

  it("the follow-up pages live in the (space) group, behind the CURRENT-scope gate", () => {
    const space = join(process.cwd(), "app", "family", "(portal)", "coordinator", "(space)");
    expect(readFileSync(join(space, "followups", "page.tsx"), "utf8")).toContain("CoordinatorFollowupList");
    expect(readFileSync(join(space, "followups", "[id]", "page.tsx"), "utf8")).toContain("CoordinatorFollowupDetail");
    expect(readFileSync(join(space, "layout.tsx"), "utf8")).toContain("<CoordinatorGate>");
  });
});

describe("the coordinator inbox", () => {
  it("lists the visible follow-ups, marks the reader's own, and counts «أنت المسؤول عنها»", async () => {
    mockGets({ [OPEN_LIST]: page([row(), row({ id: OTHER, can_manage: false, family: { family_code: "FAM-000322", head_name: "هدى الاختبار" } })]), [SUMMARY]: { data: COUNTS } });
    renderWithClient(<CoordinatorFollowupList />);
    const list = await screen.findByRole("list", { name: "المتابعات" });
    const [mine, theirs] = within(list).getAllByRole("link");
    expect(mine).toHaveAttribute("href", `/family/coordinator/followups/${UUID}`);
    expect(mine.querySelector("[data-followup-mine]")).toHaveTextContent("أنت المسؤول");
    expect(theirs.querySelector("[data-followup-mine]")).toBeNull();
    const summary = document.querySelector("[data-followup-summary='coordinator']") as HTMLElement;
    await waitFor(() => expect(within(summary).getByText("أنت المسؤول عنها")).toBeInTheDocument());
    expect(within(summary).getByText((3).toLocaleString("ar"))).toBeInTheDocument();
    expect(within(summary).queryByText("انتهت مدة إعلانها")).toBeNull();
  });

  it("switches between all visible follow-ups and «متابعاتي» on the server (ownership=mine)", async () => {
    expect(followupListPath("coordinator", { status: "OPEN", ownership: "mine", page: 2 })).toBe(`${BASE}?status=OPEN&ownership=mine&page=2`);
    expect(followupListPath("coordinator", { status: "OPEN", ownership: "all" })).toBe(OPEN_LIST);

    mockGets({ [OPEN_LIST]: page([row()]), [SUMMARY]: { data: COUNTS } });
    const { unmount } = renderWithClient(<CoordinatorFollowupList />);
    await screen.findByRole("list", { name: "المتابعات" });
    expect(screen.getByRole("button", { name: "كل المتابعات الظاهرة" })).toHaveAttribute("aria-pressed", "true");
    await userEvent.click(screen.getByRole("button", { name: "متابعاتي" }));
    expect(router.push).toHaveBeenLastCalledWith("/family/coordinator/followups?ownership=mine", { scroll: false });
    unmount();

    nav.search = "ownership=mine&status=CLOSED";
    const get = mockGets({ [`${BASE}?status=CLOSED&ownership=mine`]: page([]), [SUMMARY]: { data: COUNTS } });
    renderWithClient(<CoordinatorFollowupList />);
    expect(await screen.findByText("لا توجد متابعات مغلقة")).toBeInTheDocument();
    expect(get.mock.calls.map(([p]) => p)).toContain(`${BASE}?status=CLOSED&ownership=mine`);
    expect(screen.getByRole("button", { name: "متابعاتي" })).toHaveAttribute("aria-pressed", "true");
  });

  it("paginates with the server's pages", async () => {
    nav.search = "page=2";
    mockGets({ [`${BASE}?status=OPEN&page=2`]: page([row()], { current_page: 2, last_page: 2, total: 30, per_page: 25 }), [SUMMARY]: { data: COUNTS } });
    renderWithClient(<CoordinatorFollowupList />);
    await screen.findByRole("list", { name: "المتابعات" });
    await userEvent.click(screen.getByRole("button", { name: /السابق/ }));
    expect(router.push).toHaveBeenLastCalledWith("/family/coordinator/followups", { scroll: false });
  });

  it("shows a 403 as unavailable for this account", async () => {
    mockGets({ [OPEN_LIST]: refusal(403, "COORDINATOR_SPACE_UNAVAILABLE"), [SUMMARY]: refusal(403, "COORDINATOR_SPACE_UNAVAILABLE") });
    renderWithClient(<CoordinatorFollowupList />);
    expect(await screen.findByText("متابعات الأسر غير متاحة لهذا الحساب.")).toBeInTheDocument();
  });
});

describe("the coordinator detail", () => {
  it("the CURRENT primary coordinator (can_manage) may record a contact and close", async () => {
    mockGets({ [DETAIL]: { data: detail({ can_manage: true }) }, [HISTORY]: { data: EVENTS } });
    renderWithClient(<CoordinatorFollowupDetail id={UUID} />);
    expect(await screen.findByRole("form", { name: "تسجيل تواصل" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "إغلاق المتابعة يدويًا" })).toBeInTheDocument();
    // The Staff-only ownership kind is never shown on this side.
    expect(document.querySelector("[data-followup-ownership]")).toBeNull();
  });

  it("a non-primary coordinator (or the own household) is read-only", async () => {
    mockGets({ [DETAIL]: { data: detail({ can_manage: false }) }, [HISTORY]: { data: EVENTS } });
    renderWithClient(<CoordinatorFollowupDetail id={UUID} />);
    expect(await screen.findByText("يمكنك الاطلاع على هذه المتابعة فقط؛ يديرها المنسّق الأساسي للأسرة أو الإدارة المركزية.")).toBeInTheDocument();
    expect(screen.queryByRole("form", { name: "تسجيل تواصل" })).toBeNull();
    expect(screen.queryByRole("button", { name: "إغلاق المتابعة يدويًا" })).toBeNull();
  });

  it("labels the history actors and never names another coordinator", async () => {
    mockGets({ [DETAIL]: { data: detail() }, [HISTORY]: { data: EVENTS } });
    renderWithClient(<CoordinatorFollowupDetail id={UUID} />);
    const history = await screen.findByRole("list", { name: "سجل المتابعة" });
    const actors = Array.from(history.querySelectorAll("[data-followup-event-actor]")).map((a) => a.textContent);
    expect(actors).toEqual(["النظام", "منسّق", "أنت", "الإدارة المركزية"]);
  });

  it("an out-of-scope follow-up and a missing one look the same", async () => {
    mockGets({ [DETAIL]: refusal(404, "FOLLOWUP_NOT_FOUND"), [HISTORY]: refusal(404, "FOLLOWUP_NOT_FOUND") });
    renderWithClient(<CoordinatorFollowupDetail id={UUID} />);
    expect(await screen.findByRole("alert")).toHaveTextContent("هذه المتابعة غير متاحة.");
    expect(document.querySelector("[data-followup-error='missing']")).not.toBeNull();
  });

  it("a server refusal still wins over a stale can_manage: 403 is shown and nothing is retried", async () => {
    mockGets({ [DETAIL]: { data: detail({ can_manage: true }) }, [HISTORY]: { data: EVENTS } });
    const post = vi.spyOn(apiClient, "post").mockRejectedValueOnce(refusal(403, "FOLLOWUP_ACTOR_NOT_ALLOWED"));
    renderWithClient(<CoordinatorFollowupDetail id={UUID} />);
    const form = await screen.findByRole("form", { name: "تسجيل تواصل" });
    await chooseOption("نتيجة التواصل", "تُركت رسالة", form);
    await userEvent.click(within(form).getByRole("button", { name: "تسجيل التواصل" }));
    expect(await within(form).findByText("لا تملك صلاحية إدارة هذه المتابعة. يمكنك الاطلاع عليها فقط.")).toBeInTheDocument();
    await new Promise((r) => setTimeout(r, 50));
    expect(post).toHaveBeenCalledTimes(1);
    expect(post.mock.calls[0][0]).toBe(`${DETAIL}/contacts`);
  });

  it("an ownership change mid-request (409) is shown on the page and the state is re-read", async () => {
    let reads = 0;
    mockGets({ [DETAIL]: () => ({ data: detail({ can_manage: ++reads === 1 }) }), [HISTORY]: { data: EVENTS } });
    vi.spyOn(apiClient, "post").mockRejectedValueOnce(refusal(409, "FOLLOWUP_OWNERSHIP_CHANGED"));
    renderWithClient(<CoordinatorFollowupDetail id={UUID} />);
    await userEvent.click(await screen.findByRole("button", { name: "إغلاق المتابعة يدويًا" }));
    const dialog = await screen.findByRole("dialog");
    await chooseOption("سبب الإغلاق", "أُبلغت الأسرة حضوريًا", dialog);
    await userEvent.click(within(dialog).getByRole("button", { name: "تأكيد الإغلاق" }));
    expect(await screen.findByText(/تغيّرت مسؤولية المتابعة أثناء التنفيذ/)).toBeInTheDocument();
    expect(await screen.findByText(/يمكنك الاطلاع على هذه المتابعة فقط/)).toBeInTheDocument();
  });

  it("a revoked scope (COORDINATOR_SPACE_UNAVAILABLE) re-reads /family/me so the space gate catches up", async () => {
    mockGets({ [DETAIL]: { data: detail() }, [HISTORY]: { data: EVENTS } });
    vi.spyOn(apiClient, "post").mockRejectedValueOnce(refusal(403, "COORDINATOR_SPACE_UNAVAILABLE"));
    const { client } = renderWithClient(<CoordinatorFollowupDetail id={UUID} />);
    const invalidate = vi.spyOn(client, "invalidateQueries");
    const form = await screen.findByRole("form", { name: "تسجيل تواصل" });
    await chooseOption("نتيجة التواصل", "زيارة", form);
    await userEvent.click(within(form).getByRole("button", { name: "تسجيل التواصل" }));
    expect(await within(form).findByText("مساحة التنسيق غير متاحة لهذا الحساب حاليًا.")).toBeInTheDocument();
    expect(invalidate.mock.calls.map(([f]) => JSON.stringify(f?.queryKey))).toContain(JSON.stringify(FAMILY_ME_QUERY_KEY));
  });

  it("explains an offline submission and keeps the contact's reference for the retry", async () => {
    mockGets({ [DETAIL]: { data: detail() }, [HISTORY]: { data: EVENTS } });
    const online = vi.spyOn(navigator, "onLine", "get").mockReturnValue(false);
    const post = vi.spyOn(apiClient, "post").mockRejectedValue(new TypeError("Failed to fetch"));
    renderWithClient(<CoordinatorFollowupDetail id={UUID} />);
    const form = await screen.findByRole("form", { name: "تسجيل تواصل" });
    await chooseOption("نتيجة التواصل", "زيارة", form);
    await userEvent.click(within(form).getByRole("button", { name: "تسجيل التواصل" }));
    expect(await within(form).findByText(/أنت غير متصل بالإنترنت/)).toBeInTheDocument();
    await userEvent.click(within(form).getByRole("button", { name: "إعادة الإرسال" }));
    await waitFor(() => expect(post).toHaveBeenCalledTimes(2));
    const [a, b] = post.mock.calls.map(([, body]) => (body as { client_reference: string }).client_reference);
    expect(a).toBe(b);
    online.mockRestore();
  });
});
