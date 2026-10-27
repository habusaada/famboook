import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, describe, expect, it, vi } from "vitest";
import { AuthContext, authValue } from "@/components/auth/auth-context";
import CivilImportApprovalsPage from "@/app/(staff)/administration/civil-registry/approvals/page";
import { PublicationDetail, expiryLabel } from "@/components/administration/civil-registry/publications";
import { RunDetail } from "@/components/administration/civil-registry/run-detail";
import { ApiError, apiClient } from "@/lib/api/client";
import type { CurrentUser } from "@/lib/api/auth";
import type { CivilImportOverview, CivilImportPublicationRequest, CivilImportRunDetail } from "@/lib/types/api/civil-registry";
import { renderWithClient } from "./helpers";

// FB-CIVIL-01A-UI U3 / D-UI-12a — single-operator publication in the Staff
// application: the requester confirms their own request with their password.
// Nothing in the UI publishes: the confirmation queues the worker's request.
// Synthetic data only: no identity value.

const router = vi.hoisted(() => ({ push: vi.fn(), replace: vi.fn() }));
vi.mock("next/navigation", () => ({ useRouter: () => router, usePathname: () => "/administration/civil-registry/approvals" }));
vi.mock("next/link", () => ({
  default: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...props}>
      {children}
    </a>
  ),
}));

const BASE = "/api/v1/civil-registry";
const PUB_ID = "00000000-0000-4000-8000-0000000000a1";
const RUN_ID = "00000000-0000-4000-8000-0000000000c1";
const ALL = ["civil-import.view", "civil-import.audit.view", "civil-import.publish.request", "civil-import.publish.confirm"];
/** Synthetic test password; never a real credential. */
const PASSWORD = "synthetic-test-password";

function staff(permissions: string[]): CurrentUser {
  return { name: "مسؤول تجريبي", email: "admin@example.test", role: "SUPER_ADMIN", role_label: "مدير النظام", permissions };
}

const meta = (total: number) => ({ current_page: 1, from: total ? 1 : null, last_page: 1, path: "", per_page: 25, to: total || null, total });
const page = <T,>(data: T[]) => ({ data, links: { first: null, last: null, prev: null, next: null }, meta: meta(data.length) });

function publication(overrides: Partial<CivilImportPublicationRequest> = {}): CivilImportPublicationRequest {
  return {
    id: PUB_ID,
    status: "PENDING_APPROVAL",
    release_id: 4,
    run_id: RUN_ID,
    base_release_id: 3,
    justification_code: "ROUTINE_REFRESH",
    decision_reason_code: null,
    outcome_code: null,
    snapshot: { staged: 8, new: 6, changed: 1, unchanged: 1, expected_presence: 1208 },
    requested_by: { name: "مقدّم تجريبي" },
    decided_by: null,
    requested_at: "2026-10-26T09:00:00+03:00",
    expires_at: new Date(Date.now() + 5 * 3600 * 1000).toISOString(),
    decided_at: null,
    finished_at: null,
    publish_request: null,
    is_own: true,
    can_confirm: true,
    can_withdraw: true,
    ...overrides,
  };
}

function overview(enabled: boolean): CivilImportOverview {
  return {
    active_release: { id: 3, record_count: 1200, published_at: null, run_id: RUN_ID },
    current_run: null,
    worker: { state: "IDLE", last_seen_at: null },
    publication_enabled: enabled,
    pending_publication_requests: 1,
    active_request: null,
  };
}

function mockGets(routes: Record<string, unknown | (() => unknown)>) {
  return vi.spyOn(apiClient, "get").mockImplementation(async (path: string) => {
    const key = Object.keys(routes).find((k) => path === k || path.startsWith(`${k}?`));
    if (!key) throw new Error(`Unexpected GET: ${path}`);
    const reply = routes[key];
    const value = typeof reply === "function" ? (reply as () => unknown)() : reply;
    if (value instanceof Error) throw value;
    return value as never;
  });
}

async function findEl(selector: string): Promise<HTMLElement> {
  return waitFor(() => {
    const element = document.querySelector(selector);
    if (!element) throw new Error(`missing ${selector}`);
    return element as HTMLElement;
  });
}

function renderAs(permissions: string[], ui: React.ReactElement) {
  return renderWithClient(<AuthContext.Provider value={authValue(staff(permissions))}>{ui}</AuthContext.Provider>);
}

/** Opens the confirmation dialog and fills it in; returns the dialog. */
async function fillConfirmation({ release = "4", count = "1208", password = PASSWORD, acknowledge = true } = {}) {
  await userEvent.click(await screen.findByRole("button", { name: "تأكيد النشر" }));
  const dialog = await screen.findByRole("dialog");
  if (release) await userEvent.type(within(dialog).getByLabelText("رقم الإصدار"), release);
  if (count) await userEvent.type(within(dialog).getByLabelText("عدد السجلات المتحقَّق منها"), count);
  if (password) await userEvent.type(within(dialog).getByLabelText("كلمة المرور"), password);
  if (acknowledge) await userEvent.click(within(dialog).getByRole("checkbox"));
  return dialog;
}

afterEach(() => {
  vi.restoreAllMocks();
  router.push.mockReset();
});

describe("expiry", () => {
  it("shows the time left of a pending request only", () => {
    const now = Date.parse("2026-10-26T09:00:00Z");
    expect(expiryLabel({ status: "PENDING_APPROVAL", expires_at: "2026-10-26T14:30:00Z" }, now)).toBe(`ينتهي خلال ${(5).toLocaleString("ar")} ساعة`);
    expect(expiryLabel({ status: "PENDING_APPROVAL", expires_at: "2026-10-26T09:20:00Z" }, now)).toBe(`ينتهي خلال ${(20).toLocaleString("ar")} دقيقة`);
    expect(expiryLabel({ status: "PENDING_APPROVAL", expires_at: "2026-10-26T08:00:00Z" }, now)).toBe("انتهت المهلة");
    expect(expiryLabel({ status: "EXPIRED", expires_at: "2026-10-26T08:00:00Z" }, now)).toBe("—");
  });
});

describe("publication queue", () => {
  it("states that Production publication is disabled and lists requests with their status and expiry", async () => {
    mockGets({
      [`${BASE}/overview`]: { data: overview(false) },
      [`${BASE}/publication-requests`]: page([publication(), publication({ id: "00000000-0000-4000-8000-0000000000a2", status: "EXECUTED", outcome_code: "PUBLISHED", decided_by: { name: "مقدّم تجريبي" } })]),
    });
    renderAs(ALL, <CivilImportApprovalsPage />);
    expect(await screen.findByRole("heading", { name: "تأكيد النشر وسجله" })).toBeInTheDocument();
    const notice = await findEl("[data-publication-gate]");
    expect(within(notice).getByText("النشر على بيئة الإنتاج معطّل.")).toBeInTheDocument();
    await waitFor(() => expect(notice).toHaveAttribute("data-publication-gate", "closed"));
    expect(within(notice).getByText(/لا يمكن تأكيد أي طلب نشر/)).toBeInTheDocument();
    const table = await findEl("[data-publications-table]");
    expect(within(table).getByText("بانتظار التأكيد")).toBeInTheDocument();
    expect(within(table).getByText("أكّده")).toBeInTheDocument();
    expect(within(table).getByText("نُشر")).toBeInTheDocument();
    expect(within(table).getByText(/ينتهي خلال/)).toBeInTheDocument();
    // No rejection filter any more (history rows still appear under "all").
    expect(screen.queryByRole("button", { name: /مرفوض/ })).toBeNull();
  });

  it("is forbidden without civil-import.view", () => {
    const get = vi.spyOn(apiClient, "get");
    renderAs(["civil-import.publish.confirm"], <CivilImportApprovalsPage />);
    expect(document.querySelector("[data-forbidden]")).not.toBeNull();
    expect(get).not.toHaveBeenCalled();
  });
});

describe("publication confirmation", () => {
  const routes = (p: CivilImportPublicationRequest, enabled = true) => ({
    [`${BASE}/overview`]: { data: overview(enabled) },
    [`${BASE}/publication-requests/${PUB_ID}`]: { data: p },
    [`${BASE}/audit`]: page([]),
  });

  it("shows the release id and verified record count, and confirms only with both typed back, the password and an acknowledgement", async () => {
    mockGets(routes(publication()));
    const post = vi.spyOn(apiClient, "post").mockResolvedValue({ data: {} } as never);
    renderAs(ALL, <PublicationDetail id={PUB_ID} />);
    expect(await screen.findByText("هذا طلبك: راجع الأعداد، ثم أكّده بإعادة إدخال كلمة المرور.")).toBeInTheDocument();
    await userEvent.click(await screen.findByRole("button", { name: "تأكيد النشر" }));
    const dialog = await screen.findByRole("dialog");
    const summary = dialog.querySelector("[data-confirm-summary]") as HTMLElement;
    expect(within(summary).getByText("رقم الإصدار")).toBeInTheDocument();
    expect(within(summary).getByText("عدد السجلات المتحقَّق منها")).toBeInTheDocument();
    expect(summary.querySelector("[data-confirm-release]")?.textContent).toBe((4).toLocaleString("ar"));
    expect(summary.querySelector("[data-confirm-count]")?.textContent).toBe((1208).toLocaleString("ar"));
    const confirm = within(dialog).getByRole("button", { name: "تأكيد النشر" });
    expect(confirm).toBeDisabled();
    await userEvent.type(within(dialog).getByLabelText("رقم الإصدار"), "4");
    await userEvent.type(within(dialog).getByLabelText("عدد السجلات المتحقَّق منها"), "1208");
    await userEvent.click(within(dialog).getByRole("checkbox"));
    expect(confirm).toBeDisabled(); // no password yet
    const password = within(dialog).getByLabelText("كلمة المرور");
    expect(password).toHaveAttribute("type", "password");
    expect(password).toHaveAttribute("autocomplete", "current-password");
    await userEvent.type(password, PASSWORD);
    expect(confirm).toBeEnabled();
    await userEvent.click(confirm);
    await waitFor(() =>
      expect(post).toHaveBeenCalledWith(`${BASE}/publication-requests/${PUB_ID}/confirm`, {
        password: PASSWORD,
        confirm_release_id: 4,
        confirm_expected_presence: 1208,
      }),
    );
  });

  it("shows a wrong password by its code, never the server text, and clears the password", async () => {
    mockGets(routes(publication()));
    vi.spyOn(apiClient, "post").mockRejectedValue(new ApiError(422, { message: "server text", code: "REAUTH_FAILED" }));
    renderAs(ALL, <PublicationDetail id={PUB_ID} />);
    const dialog = await fillConfirmation();
    await userEvent.click(within(dialog).getByRole("button", { name: "تأكيد النشر" }));
    expect(await within(dialog).findByText("كلمة المرور غير صحيحة؛ لم يُؤكَّد النشر.")).toBeInTheDocument();
    expect(screen.queryByText("server text")).toBeNull();
    expect(within(dialog).getByLabelText("كلمة المرور")).toHaveValue("");
    expect(within(dialog).getByRole("button", { name: "تأكيد النشر" })).toBeDisabled();
  });

  it.each([
    ["REAUTH_LOCKED", 429, "محاولات كثيرة بكلمة مرور غير صحيحة؛ أعد المحاولة بعد 15 دقيقة."],
    ["PUBLICATION_EXPIRED", 409, "انتهت صلاحية طلب النشر؛ يلزم طلب جديد."],
    ["PUBLICATION_NOT_PENDING", 409, "طلب النشر لم يعد بانتظار التأكيد. حدّث الصفحة."],
    ["CONFIRMATION_MISMATCH", 422, "رقم الإصدار أو عدد السجلات المتحقَّق منها المُدخل لا يطابق الطلب."],
    ["PUBLICATION_GATE_CLOSED", 409, "بوابة النشر مغلقة؛ لا يمكن تأكيد أي نشر."],
  ])("explains the %s refusal in Arabic", async (code, status, text) => {
    mockGets(routes(publication()));
    vi.spyOn(apiClient, "post").mockRejectedValue(new ApiError(status, { message: "server text", code }));
    renderAs(ALL, <PublicationDetail id={PUB_ID} />);
    const dialog = await fillConfirmation();
    await userEvent.click(within(dialog).getByRole("button", { name: "تأكيد النشر" }));
    expect(await within(dialog).findByText(text)).toBeInTheDocument();
  });

  it("lets the requester withdraw", async () => {
    mockGets(routes(publication()));
    const post = vi.spyOn(apiClient, "post").mockResolvedValue({ data: {} } as never);
    renderAs(ALL, <PublicationDetail id={PUB_ID} />);
    await userEvent.click(await screen.findByRole("button", { name: "سحب الطلب" }));
    await userEvent.click(within(await screen.findByRole("dialog")).getByRole("button", { name: "تأكيد" }));
    await waitFor(() => expect(post).toHaveBeenCalledWith(`${BASE}/publication-requests/${PUB_ID}/withdraw`, {}));
  });

  it("offers nothing to another user: only the requester confirms", async () => {
    mockGets(routes(publication({ is_own: false, can_confirm: false, can_withdraw: false })));
    renderAs(ALL, <PublicationDetail id={PUB_ID} />);
    expect(await screen.findByText("يؤكد هذا الطلبَ مقدّمُه فقط.")).toBeInTheDocument();
    expect(document.querySelector("[data-publication-actions]")).toBeNull();
    expect(screen.queryByRole("button", { name: /رفض|اعتماد/ })).toBeNull();
  });

  it("explains that the requester cannot confirm while the gate is closed", async () => {
    mockGets(routes(publication({ can_confirm: false }), false));
    renderAs(ALL, <PublicationDetail id={PUB_ID} />);
    expect(await screen.findByText("هذا طلبك: لا يمكن تأكيده ما دامت بوابة النشر مغلقة.")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "تأكيد النشر" })).toBeNull();
    expect(screen.getByRole("button", { name: "سحب الطلب" })).toBeInTheDocument();
  });

  it("shows outcomes and counts only, with no action on a closed request", async () => {
    mockGets(routes(publication({ status: "FAILED", outcome_code: "AUTHORIZATION_MISSING", can_confirm: false, can_withdraw: false }), false));
    renderAs(ALL, <PublicationDetail id={PUB_ID} />);
    expect(await screen.findByText("لا يوجد مرجع تفويض بالبيانات الحقيقية لدى العامل")).toBeInTheDocument();
    expect(document.querySelector("[data-publication-actions]")).toBeNull();
    const snapshot = await findEl("[data-publication-snapshot]");
    expect(within(snapshot).getByText("الحضور المتوقع")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /نشر الآن|نشر$/ })).toBeNull();
  });

  it("labels a request closed by the policy change", async () => {
    mockGets(routes(publication({ status: "EXPIRED", outcome_code: "POLICY_CHANGED", can_confirm: false, can_withdraw: false })));
    renderAs(ALL, <PublicationDetail id={PUB_ID} />);
    expect(await screen.findByText("أُغلق عند اعتماد سياسة النشر الجديدة (مشغّل واحد)")).toBeInTheDocument();
  });
});

describe("requesting publication from a verified run", () => {
  it("asks for a justification, opens a request (no publication) and goes to its confirmation page", async () => {
    const run: CivilImportRunDetail = {
      id: RUN_ID,
      phase: "VERIFIED",
      run_status: "RUNNING",
      error_code: null,
      expected_rows: 8,
      rows_loaded: 8,
      issues: 0,
      file_count: 2,
      release_id: 4,
      requested_by: null,
      started_at: null,
      finished_at: null,
      source_table: "t03maindata",
      counters: { new_records: 0, new_versions: 0, unchanged: 0, carried_forward: 0 },
      files: [],
      release: { id: 4, status: "BUILDING", base_release_id: 3, preparation_status: "VERIFIED" },
      allowed_actions: ["REQUEST_PUBLICATION", "ASSESS", "ABORT"],
      active_request: null,
      source: null,
    };
    mockGets({ [`${BASE}/imports/${RUN_ID}`]: { data: run }, [`${BASE}/requests`]: page([]) });
    const post = vi.spyOn(apiClient, "post").mockResolvedValue({ data: publication() } as never);
    renderAs(ALL, <RunDetail runId={RUN_ID} />);
    await userEvent.click(await screen.findByRole("button", { name: "طلب نشر" }));
    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByText(/لا يُنشر شيء حتى تؤكده بنفسك خلال 24 ساعة بإعادة إدخال كلمة المرور/)).toBeInTheDocument();
    const confirm = within(dialog).getByRole("button", { name: "تأكيد" });
    expect(confirm).toBeDisabled();
    await userEvent.click(within(dialog).getByRole("radio", { name: "تحديث دوري" }));
    await userEvent.click(confirm);
    await waitFor(() => expect(post).toHaveBeenCalledWith(`${BASE}/releases/4/publication-requests`, { justification_code: "ROUTINE_REFRESH" }));
    await waitFor(() => expect(router.push).toHaveBeenCalledWith(`/administration/civil-registry/approvals/${PUB_ID}`));
  });
});
