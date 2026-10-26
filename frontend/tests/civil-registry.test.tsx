import { act, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { focusManager } from "@tanstack/react-query";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { AuthContext, authValue } from "@/components/auth/auth-context";
import AdministrationPage from "@/app/(staff)/administration/page";
import CivilRegistryPage from "@/app/(staff)/administration/civil-registry/page";
import CivilImportHistoryPage from "@/app/(staff)/administration/civil-registry/imports/page";
import CivilImportAuditPage from "@/app/(staff)/administration/civil-registry/audit/page";
import { RunDetail } from "@/components/administration/civil-registry/run-detail";
import { ReleaseDetail } from "@/components/administration/civil-registry/releases";
import { ApiError, apiClient } from "@/lib/api/client";
import { CIVIL_IMPORT_POLL_MS, civilImportPollInterval } from "@/lib/api/civil-registry";
import type { CurrentUser } from "@/lib/api/auth";
import { navItems } from "@/lib/navigation";
import type {
  CivilImportAuditEvent,
  CivilImportOverview,
  CivilImportRelease,
  CivilImportRun,
  CivilImportRunDetail,
} from "@/lib/types/api/civil-registry";
import { renderWithClient } from "./helpers";

// FB-CIVIL-01A-UI U1 — read-only, metadata-only civil import monitoring.
// Synthetic data only: no identity value, no real file name or record.

vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn(), replace: vi.fn() }), usePathname: () => "/administration/civil-registry" }));
vi.mock("next/link", () => ({
  default: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...props}>
      {children}
    </a>
  ),
}));

const VIEW = "civil-import.view";
const LOCATIONS = "civil-import.locations.view";
const AUDIT = "civil-import.audit.view";
const ALL = [VIEW, LOCATIONS, AUDIT];
const BASE = "/api/v1/civil-registry";
const RUN_ID = "00000000-0000-4000-8000-0000000000a1";

function staff(permissions: string[]): CurrentUser {
  return { name: "مسؤول تجريبي", email: "admin@example.test", role: "SUPER_ADMIN", role_label: "مدير النظام", permissions };
}

const meta = (total: number) => ({ current_page: 1, from: total ? 1 : null, last_page: 1, path: "", per_page: 25, to: total || null, total });
const page = <T,>(data: T[]) => ({ data, links: { first: null, last: null, prev: null, next: null }, meta: meta(data.length) });

function overview(overrides: Partial<CivilImportOverview> = {}): CivilImportOverview {
  return {
    active_release: { id: 3, record_count: 1200, published_at: "2026-10-20T09:00:00+03:00", run_id: RUN_ID },
    current_run: null,
    worker: { state: "NOT_INSTALLED", last_seen_at: null },
    publication_enabled: false,
    pending_publication_requests: 0,
    ...overrides,
  };
}

function run(overrides: Partial<CivilImportRun> = {}): CivilImportRun {
  return {
    id: RUN_ID,
    phase: "VERIFIED",
    run_status: "SUCCEEDED",
    error_code: null,
    expected_rows: 1200,
    rows_loaded: 1200,
    issues: 4,
    file_count: 2,
    release_id: 4,
    requested_by: null,
    started_at: "2026-10-25T08:00:00+03:00",
    finished_at: "2026-10-25T08:30:00+03:00",
    ...overrides,
  };
}

function detail(overrides: Partial<CivilImportRunDetail> = {}): CivilImportRunDetail {
  return {
    ...run(),
    source_table: "t03maindata",
    counters: { new_records: 10, new_versions: 3, unchanged: 1187, carried_forward: 0 },
    files: [
      { file_no: 1, status: "STAGED", expected_rows: 700, loaded_rows: 700, checksum_verified: true, chunks: 2, last_chunk_at: "2026-10-25T08:10:00+03:00" },
      { file_no: 2, status: "STAGED", expected_rows: 500, loaded_rows: 500, checksum_verified: true, chunks: 1, last_chunk_at: "2026-10-25T08:20:00+03:00" },
    ],
    release: { id: 4, status: "BUILDING", base_release_id: 3, preparation_status: "VERIFIED" },
    allowed_actions: [],
    active_request: null,
    ...overrides,
  };
}

const RELEASE: CivilImportRelease = {
  id: 4,
  status: "BUILDING",
  run_id: RUN_ID,
  base_release_id: 3,
  record_count: null,
  is_active: false,
  preparation: {
    status: "VERIFIED",
    policy_id: "IDREF-POLICY-1",
    comparison_policy: "ACCEPT_SOURCE",
    blank_field_policy: "PRESERVE_EXISTING_ON_BLANK",
    quarantine: "ZERO_UNRESOLVED_QUARANTINE",
    counts: { staged: 1200, new: 10, changed: 3, unchanged: 1187, conflict: 0, carried_forward: 0, blank_over_value: 2, expected_presence: 1200 },
    warnings: { BLANK_OVER_VALUE: 2 },
    prepared_at: "2026-10-25T08:40:00+03:00",
    verified_at: "2026-10-25T08:45:00+03:00",
  },
  created_at: "2026-10-25T08:35:00+03:00",
  published_at: null,
  abandoned_at: null,
};

const EVENT: CivilImportAuditEvent = {
  occurred_at: "2026-10-25T09:00:00+03:00",
  event_type: "LOCATIONS_VIEWED",
  actor: { side: "STAFF", name: "مسؤول تجريبي" },
  run_id: RUN_ID,
  release_id: null,
  outcome_code: null,
  details: { scope: "FILES" },
};

/** Answers GETs by path; any unexpected path fails the test. */
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

function renderAs(permissions: string[], ui: React.ReactElement) {
  return renderWithClient(<AuthContext.Provider value={authValue(staff(permissions))}>{ui}</AuthContext.Provider>);
}

let writes: unknown[] = [];

beforeEach(() => {
  writes = [vi.spyOn(apiClient, "post"), vi.spyOn(apiClient, "patch"), vi.spyOn(apiClient, "put")];
});

afterEach(() => {
  // U1 is read-only: no screen may send a write request.
  for (const spy of writes) expect(spy).not.toHaveBeenCalled();
  vi.restoreAllMocks();
  vi.useRealTimers();
  focusManager.setFocused(undefined);
});

describe("polling interval (D-UI-13)", () => {
  it("polls every five seconds only while staging or preparing, and stops after a failed read", () => {
    expect(civilImportPollInterval(["STAGING"])).toBe(CIVIL_IMPORT_POLL_MS);
    expect(civilImportPollInterval(["PREPARING"])).toBe(5000);
    expect(civilImportPollInterval(["VERIFIED", "PREPARING"])).toBe(5000);
    for (const idle of ["STAGED", "PREPARED", "VERIFIED", "PUBLISHED", "REJECTED", "ABORTED", "FAILED", "NOTHING_TO_DO"] as const) {
      expect(civilImportPollInterval([idle])).toBe(false);
    }
    expect(civilImportPollInterval([undefined, null])).toBe(false);
    expect(civilImportPollInterval(["STAGING"], true)).toBe(false);
  });

  it("refetches the overview while an import is active and the page is visible, never otherwise", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    const get = mockGets({ [`${BASE}/overview`]: { data: overview({ current_run: { id: RUN_ID, phase: "STAGING", started_at: null } }) } });
    renderAs(ALL, <CivilRegistryPage />);
    await waitFor(() => expect(get).toHaveBeenCalledTimes(1));
    await act(async () => vi.advanceTimersByTime(5100));
    await waitFor(() => expect(get).toHaveBeenCalledTimes(2));

    // Hidden page: the interval pauses.
    focusManager.setFocused(false);
    await act(async () => vi.advanceTimersByTime(15000));
    expect(get).toHaveBeenCalledTimes(2);
  });

  it("does not poll when no import is active", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    const get = mockGets({ [`${BASE}/overview`]: { data: overview() } });
    renderAs(ALL, <CivilRegistryPage />);
    await waitFor(() => expect(get).toHaveBeenCalledTimes(1));
    await act(async () => vi.advanceTimersByTime(20000));
    expect(get).toHaveBeenCalledTimes(1);
  });
});

describe("navigation and access", () => {
  it("shows the administration entry and card only with civil-import.view", () => {
    const admin = navItems.find((item) => item.href === "/administration");
    expect(admin?.permissions).toContain(VIEW);

    const { unmount } = renderAs([VIEW], <AdministrationPage />);
    expect(document.querySelector('[data-admin-card="civil-registry"]')).toHaveAttribute("href", "/administration/civil-registry");
    unmount();
    renderAs(["clan.manage"], <AdministrationPage />);
    expect(document.querySelector('[data-admin-card="civil-registry"]')).toBeNull();
  });

  it("renders the forbidden state without calling the API when the permission is missing", () => {
    const get = vi.spyOn(apiClient, "get");
    const { unmount } = renderAs(["clan.manage"], <CivilRegistryPage />);
    expect(document.querySelector("[data-forbidden]")).not.toBeNull();
    unmount();
    renderAs([VIEW], <CivilImportAuditPage />);
    expect(document.querySelector("[data-forbidden]")).not.toBeNull();
    expect(get).not.toHaveBeenCalled();
  });

  it("hides the audit section link without civil-import.audit.view", async () => {
    mockGets({ [`${BASE}/overview`]: { data: overview() } });
    renderAs([VIEW], <CivilRegistryPage />);
    const nav = await screen.findByRole("navigation", { name: "أقسام السجل المدني" });
    expect(within(nav).getByText("سجل الاستيراد")).toBeInTheDocument();
    expect(within(nav).queryByText("سجل التدقيق")).toBeNull();
  });

  it("shows a forbidden block when the API refuses, and fixed text when monitoring is not configured", async () => {
    mockGets({ [`${BASE}/overview`]: new ApiError(403, { message: "This action is unauthorized." }) });
    const { unmount } = renderAs(ALL, <CivilRegistryPage />);
    await waitFor(() => expect(document.querySelector("[data-forbidden]")).not.toBeNull());
    unmount();

    mockGets({ [`${BASE}/overview`]: new ApiError(503, { message: "Civil import monitoring is not configured on this server.", code: "MONITOR_NOT_CONFIGURED" }) });
    renderAs(ALL, <CivilRegistryPage />);
    expect(await screen.findByText("لم تُضبط متابعة استيراد السجل المدني على هذا الخادم بعد.")).toBeInTheDocument();
    expect(document.querySelector('[data-error="MONITOR_NOT_CONFIGURED"]')).not.toBeNull();
    // The server message is never rendered.
    expect(screen.queryByText(/not configured on this server/)).toBeNull();
  });
});

describe("overview", () => {
  it("shows the active release, the closed publication gate and no execution or publication control", async () => {
    mockGets({ [`${BASE}/overview`]: { data: overview() } });
    renderAs(ALL, <CivilRegistryPage />);
    expect(await screen.findByText("مغلقة")).toHaveAttribute("data-publication-gate", "closed");
    expect(screen.getByText("غير مثبّت بعد")).toBeInTheDocument();
    expect(screen.getByText("لا يوجد")).toBeInTheDocument();
    expect(screen.queryByRole("button")).toBeNull();
    expect(document.querySelector("[data-read-only-note]")).not.toBeNull();
  });

  it("shows a loading state while the overview is pending", () => {
    vi.spyOn(apiClient, "get").mockReturnValue(new Promise<never>(() => {}));
    renderAs(ALL, <CivilRegistryPage />);
    expect(document.querySelector("[data-loading]")).not.toBeNull();
  });
});

describe("import history", () => {
  it("lists runs with phase and counts, and filters by status", async () => {
    const get = mockGets({ [`${BASE}/imports`]: page([run(), run({ id: "00000000-0000-4000-8000-0000000000a2", phase: "FAILED", run_status: "FAILED" })]) });
    renderAs(ALL, <CivilImportHistoryPage />);
    const table = await screen.findByRole("table");
    expect(within(table).getAllByRole("row")).toHaveLength(3);
    expect(document.querySelector('[data-phase="VERIFIED"]')).not.toBeNull();
    expect(document.querySelector('[data-phase="FAILED"]')).not.toBeNull();

    await userEvent.click(screen.getByRole("button", { name: "فشل" }));
    await waitFor(() => expect(get).toHaveBeenLastCalledWith(`${BASE}/imports?page=1&status=FAILED`));
  });

  it("shows the empty state when there is no import", async () => {
    mockGets({ [`${BASE}/imports`]: page([]) });
    renderAs(ALL, <CivilImportHistoryPage />);
    expect(await screen.findByText("لا توجد عمليات استيراد")).toBeInTheDocument();
  });
});

describe("import details", () => {
  const routes = (extra: Record<string, unknown> = {}) => ({
    [`${BASE}/imports/${RUN_ID}`]: { data: detail() },
    [`${BASE}/imports/${RUN_ID}/issues`]: { data: [{ code: "QUARANTINE_ROW", column: "mainid", rows: 4 }] },
    [`${BASE}/releases/4`]: { data: RELEASE },
    [`${BASE}/audit`]: page([EVENT]),
    ...extra,
  });

  it("identifies files by number only and offers no location control without civil-import.locations.view", async () => {
    const get = mockGets(routes());
    renderAs([VIEW], <RunDetail runId={RUN_ID} />);
    await userEvent.click(await screen.findByRole("tab", { name: "الملفات ونقاط الاستئناف" }));
    const files = await screen.findByRole("table");
    expect(within(files).getAllByRole("row")).toHaveLength(3);
    expect(document.querySelector("[data-file-name]")).toBeNull();
    expect(document.querySelector("[data-locations-panel]")).toBeNull();

    await userEvent.click(screen.getByRole("tab", { name: "التحقق" }));
    expect(await screen.findByText("سطر محجور")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "المواقع" })).toBeNull();
    expect(screen.queryByRole("tab", { name: "التدقيق" })).toBeNull();
    expect(get.mock.calls.some(([path]) => String(path).includes("/locations"))).toBe(false);
    // No execution, abort or publication control in U1.
    expect(screen.queryByRole("button", { name: /تشغيل|نشر|إلغاء|اعتماد/ })).toBeNull();
  });

  it("reveals file names only on an explicit request with civil-import.locations.view", async () => {
    const get = mockGets(routes({ [`${BASE}/imports/${RUN_ID}/files/locations`]: { data: [{ file_no: 1, file_name: "synthetic_a.csv" }, { file_no: 2, file_name: "synthetic_b.csv" }] } }));
    renderAs(ALL, <RunDetail runId={RUN_ID} />);
    await userEvent.click(await screen.findByRole("tab", { name: "الملفات ونقاط الاستئناف" }));
    expect(screen.queryByText("synthetic_a.csv")).toBeNull();
    expect(get.mock.calls.some(([path]) => String(path).includes("/locations"))).toBe(false);

    await userEvent.click(screen.getByRole("button", { name: "عرض أسماء الملفات" }));
    expect(await screen.findByText("synthetic_a.csv")).toBeInTheDocument();
    expect(get).toHaveBeenCalledWith(`${BASE}/imports/${RUN_ID}/files/locations`);
  });

  it("shows issue locations for one code on request, and the run audit with civil-import.audit.view", async () => {
    const get = mockGets(
      routes({
        [`${BASE}/imports/${RUN_ID}/issues/locations`]: {
          data: [{ file_name: "synthetic_a.csv", line_number: 17, code: "QUARANTINE_ROW", column: "mainid" }],
          meta: { current_page: 1, last_page: 1, per_page: 50, total: 1 },
        },
      })
    );
    renderAs(ALL, <RunDetail runId={RUN_ID} />);
    await userEvent.click(await screen.findByRole("tab", { name: "التحقق" }));
    await userEvent.click(await screen.findByRole("button", { name: "المواقع" }));
    expect(await screen.findByText("synthetic_a.csv")).toBeInTheDocument();
    expect(get).toHaveBeenCalledWith(`${BASE}/imports/${RUN_ID}/issues/locations?code=QUARANTINE_ROW&page=1`);

    await userEvent.click(screen.getByRole("tab", { name: "التدقيق" }));
    expect(await screen.findByText("عرض مواقع الملفات")).toBeInTheDocument();
    expect(get).toHaveBeenCalledWith(`${BASE}/audit?page=1&run=${RUN_ID}`);
  });

  it("shows the comparison counts and policy of the run's release", async () => {
    mockGets(routes());
    renderAs([VIEW], <RunDetail runId={RUN_ID} />);
    await userEvent.click(await screen.findByRole("tab", { name: "المقارنة" }));
    const counts = await screen.findByText("فراغ فوق قيمة");
    expect(counts).toBeInTheDocument();
    await userEvent.click(screen.getByRole("tab", { name: "الإصدار" }));
    expect(await screen.findByText("IDREF-POLICY-1")).toBeInTheDocument();
  });

  it("shows a not-found message for an unknown run", async () => {
    mockGets({ [`${BASE}/imports/${RUN_ID}`]: new ApiError(404, { message: "Not Found." }) });
    renderAs([VIEW], <RunDetail runId={RUN_ID} />);
    expect(await screen.findByText("العنصر المطلوب غير موجود.")).toBeInTheDocument();
  });
});

describe("releases and audit", () => {
  it("renders a release with its preparation counts and warnings", async () => {
    mockGets({ [`${BASE}/releases/4`]: { data: RELEASE } });
    renderAs([VIEW], <ReleaseDetail releaseId="4" />);
    expect(await screen.findByText("الإبقاء على القيمة الحالية عند الفراغ")).toBeInTheDocument();
    expect(screen.getByText("لا حجر غير محسوم")).toBeInTheDocument();
  });

  it("refuses a malformed release id without calling the API", () => {
    const get = vi.spyOn(apiClient, "get");
    renderAs([VIEW], <ReleaseDetail releaseId="4;drop" />);
    expect(screen.getByText("رقم الإصدار غير صالح")).toBeInTheDocument();
    expect(get).not.toHaveBeenCalled();
  });

  it("lists audit events with Arabic labels and an empty state", async () => {
    mockGets({ [`${BASE}/audit`]: page([EVENT]) });
    const { unmount } = renderAs(ALL, <CivilImportAuditPage />);
    expect(await screen.findByText("عرض مواقع الملفات")).toBeInTheDocument();
    expect(screen.getByText("أسماء الملفات")).toBeInTheDocument();
    unmount();

    mockGets({ [`${BASE}/audit`]: page([]) });
    renderAs(ALL, <CivilImportAuditPage />);
    expect(await screen.findByText("لا توجد أحداث تدقيق")).toBeInTheDocument();
  });
});
