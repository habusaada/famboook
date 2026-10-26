import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, describe, expect, it, vi } from "vitest";
import { AuthContext, authValue } from "@/components/auth/auth-context";
import CivilRegistryPage from "@/app/(staff)/administration/civil-registry/page";
import NewCivilImportPage from "@/app/(staff)/administration/civil-registry/imports/new/page";
import { RunDetail } from "@/components/administration/civil-registry/run-detail";
import { ApiError, apiClient } from "@/lib/api/client";
import { CIVIL_IMPORT_POLL_MS, civilImportPollInterval } from "@/lib/api/civil-registry";
import type { CurrentUser } from "@/lib/api/auth";
import type { CivilImportOverview, CivilImportRequest, CivilImportRunDetail, CivilImportSource } from "@/lib/types/api/civil-registry";
import { renderWithClient } from "./helpers";

// FB-CIVIL-01A-UI U2 — controlled execution in the Staff application: every
// action records a request (202) for the dedicated worker. Synthetic data
// only: no identity value, no real file name, no server path.

vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn(), replace: vi.fn() }), usePathname: () => "/administration/civil-registry" }));
vi.mock("next/link", () => ({
  default: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...props}>
      {children}
    </a>
  ),
}));

const BASE = "/api/v1/civil-registry";
const RUN_ID = "00000000-0000-4000-8000-0000000000c1";
const SOURCE_ID = "00000000-0000-4000-8000-0000000000d1";
const ALL = ["civil-import.view", "civil-import.locations.view", "civil-import.audit.view", "civil-import.stage", "civil-import.validate"];

function staff(permissions: string[]): CurrentUser {
  return { name: "مسؤول تجريبي", email: "admin@example.test", role: "SUPER_ADMIN", role_label: "مدير النظام", permissions };
}

const meta = (total: number) => ({ current_page: 1, from: total ? 1 : null, last_page: 1, path: "", per_page: 25, to: total || null, total });
const page = <T,>(data: T[]) => ({ data, links: { first: null, last: null, prev: null, next: null }, meta: meta(data.length) });

function request(overrides: Partial<CivilImportRequest> = {}): CivilImportRequest {
  return {
    id: "00000000-0000-4000-8000-0000000000e1",
    type: "STAGE",
    status: "RUNNING",
    requested_by: { name: "مسؤول تجريبي" },
    source: { id: SOURCE_ID, label: "export-2026-11-01" },
    run_id: RUN_ID,
    release_id: null,
    attempts: 1,
    reason_code: null,
    message: null,
    result: {},
    cancel_requested: false,
    can_cancel: true,
    queued_at: "2026-10-26T09:00:00+03:00",
    started_at: "2026-10-26T09:00:05+03:00",
    heartbeat_at: "2026-10-26T09:01:00+03:00",
    finished_at: null,
    ...overrides,
  };
}

function detail(overrides: Partial<CivilImportRunDetail> = {}): CivilImportRunDetail {
  return {
    id: RUN_ID,
    phase: "STAGED",
    run_status: "RUNNING",
    error_code: null,
    expected_rows: 8,
    rows_loaded: 8,
    issues: 0,
    file_count: 2,
    release_id: null,
    requested_by: { name: "مسؤول تجريبي" },
    started_at: "2026-10-26T09:00:05+03:00",
    finished_at: null,
    source_table: "t03maindata",
    counters: { new_records: 0, new_versions: 0, unchanged: 0, carried_forward: 0 },
    files: [{ file_no: 1, status: "STAGED", expected_rows: 4, loaded_rows: 4, checksum_verified: true, chunks: 2, last_chunk_at: null }],
    release: null,
    allowed_actions: ["ASSESS", "PREPARE", "ABORT"],
    active_request: null,
    source: { id: SOURCE_ID, label: "export-2026-11-01", available: true },
    ...overrides,
  };
}

function source(overrides: Partial<CivilImportSource> = {}): CivilImportSource {
  return {
    id: SOURCE_ID,
    label: "export-2026-11-01",
    table: "t03maindata",
    files: 2,
    total_rows: 8,
    available: true,
    manifest_status: "VALID",
    manifest_problems: {},
    discovered_at: "2026-10-26T08:00:00+03:00",
    last_seen_at: "2026-10-26T08:59:00+03:00",
    last_validation: null,
    staged_run_id: null,
    ...overrides,
  };
}

/** Answers GETs by path (exact, or with a query string); any other path fails the test. */
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

/** Waits until an element matching the selector exists. */
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

afterEach(() => {
  vi.restoreAllMocks();
});

describe("polling with execution requests (D-UI-13)", () => {
  it("polls while a request is queued or running, whatever the phase", () => {
    expect(civilImportPollInterval(["VERIFIED"], false, true)).toBe(CIVIL_IMPORT_POLL_MS);
    expect(civilImportPollInterval([], false, true)).toBe(5000);
    expect(civilImportPollInterval(["STAGING_INTERRUPTED"], false, false)).toBe(false);
    expect(civilImportPollInterval([], true, true)).toBe(false);
  });
});

describe("run actions", () => {
  const routes = (run: CivilImportRunDetail) => ({
    [`${BASE}/imports/${RUN_ID}`]: { data: run },
    [`${BASE}/requests`]: page<CivilImportRequest>([]),
  });

  it("offers the allowed actions only, and records a confirmed request with an idempotency key", async () => {
    mockGets(routes(detail()));
    const post = vi.spyOn(apiClient, "post").mockResolvedValue({ data: request({ type: "PREPARE", status: "QUEUED" }) } as never);
    renderAs(ALL, <RunDetail runId={RUN_ID} />);
    const bar = await findEl("[data-run-actions]");
    expect(within(bar).getAllByRole("button").map((b) => b.textContent)).toEqual(["تقييم ومعاينة", "تحضير الإصدار", "إلغاء العملية"]);
    expect(screen.queryByRole("button", { name: /نشر/ })).toBeNull();

    await userEvent.click(within(bar).getByRole("button", { name: "تحضير الإصدار" }));
    const dialog = await screen.findByRole("dialog");
    expect(post).not.toHaveBeenCalled();
    await userEvent.click(within(dialog).getByRole("button", { name: "تأكيد" }));
    await waitFor(() => expect(post).toHaveBeenCalledTimes(1));
    const [path, body, headers] = post.mock.calls[0];
    expect(path).toBe(`${BASE}/imports/${RUN_ID}/prepare`);
    expect(body).toEqual({});
    expect((headers as Record<string, string>)["Idempotency-Key"]).toMatch(/^[A-Za-z0-9_-]{8,64}$/);
  });

  it("requires a reason and an acknowledgement before aborting", async () => {
    mockGets(routes(detail()));
    const post = vi.spyOn(apiClient, "post").mockResolvedValue({ data: request({ type: "ABORT", status: "QUEUED" }) } as never);
    renderAs(ALL, <RunDetail runId={RUN_ID} />);
    await userEvent.click(await screen.findByRole("button", { name: "إلغاء العملية" }));
    const dialog = await screen.findByRole("dialog");
    const confirm = within(dialog).getByRole("button", { name: "تأكيد" });
    expect(confirm).toBeDisabled();
    await userEvent.click(within(dialog).getByRole("radio", { name: "استُبدل المصدر بآخر" }));
    expect(confirm).toBeDisabled();
    await userEvent.click(within(dialog).getByRole("checkbox"));
    expect(confirm).toBeEnabled();
    await userEvent.click(confirm);
    await waitFor(() => expect(post).toHaveBeenCalledWith(`${BASE}/imports/${RUN_ID}/abort`, { reason_code: "SOURCE_REPLACED" }, expect.any(Object)));
  });

  it("shows a refusal by its code with fixed text, and the retry reuses the same key", async () => {
    mockGets(routes(detail()));
    const post = vi
      .spyOn(apiClient, "post")
      .mockRejectedValueOnce(new ApiError(409, { message: "server text that must not appear", code: "REQUEST_ALREADY_ACTIVE" }))
      .mockResolvedValueOnce({ data: request({ type: "ASSESS", status: "QUEUED" }) } as never);
    renderAs(ALL, <RunDetail runId={RUN_ID} />);
    await userEvent.click(await screen.findByRole("button", { name: "تقييم ومعاينة" }));
    const dialog = await screen.findByRole("dialog");
    await userEvent.click(within(dialog).getByRole("button", { name: "تأكيد" }));
    expect(await within(dialog).findByText("يوجد طلب تنفيذ آخر في الانتظار أو قيد التشغيل.")).toBeInTheDocument();
    expect(screen.queryByText(/server text/)).toBeNull();
    await userEvent.click(within(dialog).getByRole("button", { name: "تأكيد" }));
    await waitFor(() => expect(post).toHaveBeenCalledTimes(2));
    expect(post.mock.calls[0][2]).toEqual(post.mock.calls[1][2]);
  });

  it("shows no action without the permission, and none while a request is active", async () => {
    mockGets(routes(detail({ allowed_actions: [] })));
    renderAs(["civil-import.view"], <RunDetail runId={RUN_ID} />);
    await screen.findByText("نظرة عامة");
    expect(document.querySelector("[data-run-actions]")).toBeNull();
    expect(screen.queryByRole("button", { name: /تحضير|تقييم|إلغاء العملية/ })).toBeNull();
  });

  it("shows the active request and stops it after a confirmation", async () => {
    mockGets(routes(detail({ phase: "STAGING", allowed_actions: [], active_request: request() })));
    const del = vi.spyOn(apiClient, "delete").mockResolvedValue({ data: request({ cancel_requested: true, can_cancel: false }) } as never);
    renderAs(ALL, <RunDetail runId={RUN_ID} />);
    const card = await findEl("[data-active-request]");
    expect(within(card).getByText("قيد التنفيذ")).toBeInTheDocument();
    await userEvent.click(within(card).getByRole("button", { name: "إيقاف التنفيذ" }));
    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByText(/آخر نقطة محفوظة/)).toBeInTheDocument();
    await userEvent.click(within(dialog).getByRole("button", { name: "تأكيد" }));
    await waitFor(() => expect(del).toHaveBeenCalledWith(`${BASE}/requests/00000000-0000-4000-8000-0000000000e1`));
  });

  it("lists the run's requests with duplicate, conflict and comparison summaries", async () => {
    const assessed = request({
      id: "00000000-0000-4000-8000-0000000000e2",
      type: "ASSESS",
      status: "SUCCEEDED",
      finished_at: "2026-10-26T09:05:00+03:00",
      can_cancel: false,
      result: { publishable: false, counts: { staged: 8, new: 6, changed: 1, unchanged: 1, conflict: 2 }, conflicts: { MAINID_DUPLICATE: 2 }, warnings: { FATHERID_UNRESOLVED: 3 }, blocked: ["QUARANTINE_NOT_ALLOWED"] },
    });
    mockGets({ [`${BASE}/imports/${RUN_ID}`]: { data: detail() }, [`${BASE}/requests`]: page([assessed]) });
    renderAs(ALL, <RunDetail runId={RUN_ID} />);
    await userEvent.click(await screen.findByRole("tab", { name: "المقارنة" }));
    const preview = await findEl("[data-assessment-preview]");
    expect(within(preview).getByText("رقم الهوية الرئيسي مكرر")).toBeInTheDocument();
    expect(within(preview).getByText("السياسة لا تسمح بأي سطر محجور")).toBeInTheDocument();
    expect(within(preview).getByText("هوية الأب غير موجودة في الملف")).toBeInTheDocument();

    await userEvent.click(screen.getByRole("tab", { name: "الطلبات" }));
    const table = await findEl("[data-requests-table]");
    expect(within(table).getByText("نجح")).toBeInTheDocument();
  });
});

describe("overview", () => {
  it("shows the active request and the new-import entry for stage holders only", async () => {
    const overview: CivilImportOverview = {
      active_release: null,
      current_run: null,
      worker: { state: "BUSY", last_seen_at: "2026-10-26T09:01:00+03:00" },
      publication_enabled: false,
      pending_publication_requests: 0,
      active_request: request({ type: "VALIDATE_SOURCE", run_id: null }),
    };
    mockGets({ [`${BASE}/overview`]: { data: overview } });
    const { unmount } = renderAs(ALL, <CivilRegistryPage />);
    expect(await screen.findByText("طلب جارٍ: التحقق من المصدر")).toBeInTheDocument();
    expect(document.querySelector("[data-new-import]")).toHaveAttribute("href", "/administration/civil-registry/imports/new");
    unmount();
    renderAs(["civil-import.view"], <CivilRegistryPage />);
    await screen.findByText("طلب جارٍ: التحقق من المصدر");
    expect(document.querySelector("[data-new-import]")).toBeNull();
  });
});

describe("new import wizard", () => {
  it("is forbidden without civil-import.stage", () => {
    const get = vi.spyOn(apiClient, "get");
    renderAs(["civil-import.view", "civil-import.validate"], <NewCivilImportPage />);
    expect(document.querySelector("[data-forbidden]")).not.toBeNull();
    expect(get).not.toHaveBeenCalled();
  });

  it("explains how sources arrive when none is catalogued", async () => {
    mockGets({ [`${BASE}/sources`]: { data: [] } });
    renderAs(ALL, <NewCivilImportPage />);
    expect(await screen.findByText("لا توجد مصادر مفهرسة")).toBeInTheDocument();
    // Server-side sources only (D-UI-3): no file input anywhere.
    expect(document.querySelector('input[type="file"]')).toBeNull();
  });

  it("selects a source, validates it, confirms and follows the staging", async () => {
    let validation = request({ id: "00000000-0000-4000-8000-0000000000f1", type: "VALIDATE_SOURCE", status: "QUEUED", run_id: null });
    let staging = request({ id: "00000000-0000-4000-8000-0000000000f2", type: "STAGE", status: "QUEUED", run_id: null });
    mockGets({
      [`${BASE}/sources`]: {
        data: [source(), source({ id: "00000000-0000-4000-8000-0000000000d2", label: "export-broken", manifest_status: "INVALID", manifest_problems: { MANIFEST_COLUMNS_MISMATCH: 1 }, files: null, total_rows: null })],
      },
      [`${BASE}/requests/${validation.id}`]: () => ({ data: validation }),
      [`${BASE}/requests/${staging.id}`]: () => ({ data: staging }),
    });
    const post = vi.spyOn(apiClient, "post").mockImplementation(async (path: string) => ({ data: path.endsWith("/validate") ? validation : staging }) as never);
    renderAs(ALL, <NewCivilImportPage />);

    // Step 1: an invalid source cannot be chosen; its problems are codes in Arabic, never paths.
    const broken = await findEl('[data-source="00000000-0000-4000-8000-0000000000d2"]');
    expect(within(broken).getByRole("radio")).toBeDisabled();
    expect(within(broken).getByText(/أعمدة البيان لا تطابق/)).toBeInTheDocument();
    const next = screen.getByRole("button", { name: "التالي" });
    expect(next).toBeDisabled();
    await userEvent.click(screen.getAllByRole("radio")[0]);
    await userEvent.click(next);

    // Step 2: validation is a recorded request; the next step opens once it SUCCEEDED.
    await userEvent.click(screen.getByRole("button", { name: "التحقق من الملفات" }));
    await waitFor(() => expect(post).toHaveBeenCalledWith(`${BASE}/sources/${SOURCE_ID}/validate`, {}, expect.any(Object)));
    expect(await screen.findByText("في الانتظار")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "التالي" })).toBeDisabled();
    validation = { ...validation, status: "SUCCEEDED", can_cancel: false, result: { manifest: "VALID", files: 2, total_rows: 8, problems: {} } };
    await waitFor(() => expect(screen.getByRole("button", { name: "التالي" })).toBeEnabled(), { timeout: 7000 });
    await userEvent.click(screen.getByRole("button", { name: "التالي" }));

    // Step 3: explicit acknowledgement, then a confirmation dialog.
    const start = screen.getByRole("button", { name: "بدء التحميل" });
    expect(start).toBeDisabled();
    await userEvent.click(screen.getByRole("checkbox"));
    await userEvent.click(start);
    const dialog = await screen.findByRole("dialog");
    await userEvent.click(within(dialog).getByRole("button", { name: "تأكيد" }));
    await waitFor(() => expect(post).toHaveBeenCalledWith(`${BASE}/imports`, { source_id: SOURCE_ID }, expect.any(Object)));

    // Step 4: follows the request; links to the run once the worker opened it.
    staging = { ...staging, status: "RUNNING", run_id: RUN_ID };
    expect(await screen.findByRole("link", { name: "عرض العملية" }, { timeout: 7000 })).toHaveAttribute("href", `/administration/civil-registry/imports/${RUN_ID}`);
    expect(screen.queryByRole("button", { name: /نشر/ })).toBeNull();
  }, 20000);
});
