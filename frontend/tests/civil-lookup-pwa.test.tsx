import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi, type MockInstance } from "vitest";
import { AuthContext, authValue } from "@/components/auth/auth-context";
import CivilRegistryLookupPage from "@/app/(staff)/civil-registry/lookup/page";
import { ApiError, apiClient } from "@/lib/api/client";
import type { CurrentUser } from "@/lib/api/auth";
import type { CivilLookupList, CivilLookupQuota, CivilLookupRecord, CivilLookupRecordResponse, CivilLookupStatus } from "@/lib/types/api/civil-lookup";
import { formatCivilDate } from "@/lib/utils/civil-date";
import { renderWithClient } from "./helpers";

// FB-CIVIL-02E — the mobile-first lookup experience: segmented modes, one
// result card for every list, a visible pending state without duplicate
// requests, the National ID exactly as the API returns it, and the age
// computed from the date of birth. SYNTHETIC data only (900000xxx).

vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn(), replace: vi.fn() }), usePathname: () => "/civil-registry/lookup" }));
vi.mock("next/link", () => ({
  default: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...props}>
      {children}
    </a>
  ),
}));

const BASE = "/api/v1/civil-registry/lookup";

const staff: CurrentUser = { name: "مستخدم تجريبي", email: "staff@example.test", role: "ADMINISTRATOR", role_label: "مسؤول إداري", permissions: ["civil-lookup.search"] };

const quota = (over: Partial<CivilLookupQuota> = {}): CivilLookupQuota => ({
  enabled: true, unlimited: false, daily_limit: 50, used: 3, remaining: 47, resets_at: "2026-10-29T22:00:00+00:00", reason: null, ...over,
});

const status: { data: CivilLookupStatus } = {
  data: { context: "STAFF", quota: quota(), capabilities: { search: true, view: true, view_full: true, reverse_links: false } },
};

const entry = (n: number) => ({
  ref: `ref-${n}`, national_id: `*****${String(n).padStart(4, "0")}`, fullname: `اسم تجريبي ${n}`, gender: "1", datebirth: "1990-01-15T00:00:00", govern: "7", city: "71",
});

const list = (results = [entry(1), entry(2)], over: Partial<CivilLookupList> = {}): { data: CivilLookupList } => ({
  data: { release_id: 1, outcome: "RESULTS", results, meta: { page: 1, per_page: 25, total: results.length, last_page: 1 }, search_ref: null, quota: quota(), ...over },
});

const record = (over: Partial<CivilLookupRecord> = {}, personal: { key: string; value: string | null }[] = []): CivilLookupRecord => ({
  ref: "rec-1",
  national_id: "900000001",
  fullname: "اسم تجريبي 1",
  level: "FULL",
  sections: [
    {
      key: "personal",
      fields: [
        { key: "mainid", value: "900000001" },
        { key: "fullname", value: "اسم تجريبي 1" },
        { key: "datebirth", value: "1990-11-15T00:00:00" },
        { key: "age", value: "99" },
        { key: "deathdate", value: null },
        ...personal,
      ],
    },
    { key: "geographic", fields: [{ key: "govern", value: "7" }, { key: "street", value: "شارع تجريبي طويل جدًا للتحقق من الالتفاف دون تداخل" }] },
  ],
  links: [
    { relation: "FATHER", status: "AVAILABLE", national_id: "900000002", ref: "link-father" },
    { relation: "MOTHER", status: "NOT_IN_RELEASE", national_id: "900000099" },
    { relation: "SPOUSE", status: "EMPTY" },
    { relation: "HOUSEHOLD", status: "DISABLED" },
    { relation: "CHILDREN", status: "DISABLED" },
  ],
  ...over,
});

const opened = (r: CivilLookupRecord | null): { data: CivilLookupRecordResponse } => ({
  data: { outcome: r ? "FOUND" : "NOT_FOUND", release_id: 1, record: r, quota: quota() },
});

let post: MockInstance<typeof apiClient.post>;

/** POST replies by path: a value, an Error, or a function (e.g. a pending promise). */
function mockPosts(routes: Record<string, unknown>) {
  post = vi.spyOn(apiClient, "post").mockImplementation(async (path: string) => {
    const reply = routes[path.replace(BASE, "")];
    if (reply === undefined) throw new Error(`Unexpected POST: ${path}`);
    if (reply instanceof Error) throw reply;
    return (typeof reply === "function" ? await (reply as () => unknown)() : reply) as never;
  });
}

/** A request that stays pending until released. */
function deferred<T>() {
  let release: (value: T) => void = () => {};
  let reject: (error: unknown) => void = () => {};
  const promise = new Promise<T>((res, rej) => ((release = res), (reject = rej)));
  return { promise, release, reject };
}

async function findEl(query: () => Element | null): Promise<HTMLElement> {
  return waitFor(() => {
    const el = query();
    expect(el).not.toBeNull();
    return el as HTMLElement;
  });
}

function renderPage() {
  return renderWithClient(
    <AuthContext.Provider value={authValue(staff)}>
      <CivilRegistryLookupPage />
    </AuthContext.Provider>,
  );
}

async function searchByName(user: ReturnType<typeof userEvent.setup>, name = "سامي تجريبية") {
  await user.click(await screen.findByRole("radio", { name: "الاسم الكامل" }));
  await user.type(screen.getByLabelText("الاسم الكامل أو جزء منه"), name);
  await user.click(screen.getByRole("button", { name: "بحث" }));
}

beforeEach(() => {
  vi.spyOn(apiClient, "get").mockImplementation(async (path: string) => {
    if (path === `${BASE}/status`) return status as never;
    throw new Error(`Unexpected GET: ${path}`);
  });
});

afterEach(() => {
  vi.useRealTimers();
  vi.restoreAllMocks();
});

describe("search screen", () => {
  it("offers the three modes as one accessible radio group with touch-sized controls", async () => {
    const user = userEvent.setup();
    renderPage();
    const group = await findEl(() => document.querySelector("[data-lookup-modes]"));
    const radios = within(group).getAllByRole("radio");
    expect(radios.map((r) => r.closest("label")?.textContent)).toEqual(["رقم الهوية", "الاسم الكامل", "البحث التفصيلي"]);
    expect(screen.getByRole("radio", { name: "رقم الهوية" })).toBeChecked();
    expect(document.querySelector("fieldset legend")?.textContent).toBe("طريقة البحث");
    for (const segment of group.querySelectorAll("label > span")) expect(segment.className).toContain("min-h-11");

    await user.click(screen.getByRole("radio", { name: "البحث التفصيلي" }));
    expect(document.querySelector('[data-lookup-form="parts"]')).not.toBeNull();
    expect(screen.getByLabelText("اسم العائلة").className).toContain("h-11");
    const submit = screen.getByRole("button", { name: "بحث" });
    expect(submit.className).toContain("w-full");
    expect(submit.className).toContain("h-11");
    // The compact quota line.
    expect(document.querySelector("[data-quota-remaining]")?.textContent).toBe((47).toLocaleString("ar"));
  });
});

describe("pending state", () => {
  it("shows an indeterminate bar and «جارٍ البحث...», sends one request only, then shows the cards", async () => {
    const user = userEvent.setup();
    const pending = deferred<{ data: CivilLookupList }>();
    mockPosts({ "/search/name": () => pending.promise });
    renderPage();
    await searchByName(user);

    const bar = await findEl(() => document.querySelector('[data-lookup-progress] [role="progressbar"]'));
    expect(bar).not.toHaveAttribute("aria-valuenow");
    expect(document.querySelector("[data-lookup-status]")).toHaveAttribute("aria-live", "polite");
    expect(document.querySelector("[data-lookup-status]")?.textContent).toBe("جارٍ البحث...");
    const submit = screen.getByRole("button", { name: "جارٍ البحث..." });
    expect(submit).toBeDisabled();
    await user.click(submit);
    // Enter in the field does not resubmit either.
    await user.type(screen.getByLabelText("الاسم الكامل أو جزء منه"), "{Enter}");
    expect(post).toHaveBeenCalledTimes(1);

    pending.release(list());
    await waitFor(() => expect(document.querySelectorAll("[data-result]")).toHaveLength(2));
    expect(document.querySelector("[data-lookup-progress]")).toBeNull();
    expect(screen.getByRole("button", { name: "بحث" })).toBeEnabled();
  });

  it.each([
    [new ApiError(429, { code: "QUOTA_EXHAUSTED" }), "استنفدت رصيدك اليومي من الاستعلامات"],
    [new ApiError(403, { code: "LOOKUP_NOT_ALLOWED" }), "لا تملك صلاحية استخدام الاستعلام"],
    [new ApiError(409, { code: "RELEASE_CHANGED" }), "تغيّر الإصدار المنشور"],
    [new Error("timeout"), "تعذّر إتمام الاستعلام. حاول مرة أخرى."],
  ])("removes the bar and shows the fixed message on a refusal or failure (%#), without retrying", async (error, message) => {
    const user = userEvent.setup();
    const pending = deferred<never>();
    mockPosts({ "/search/name": () => pending.promise });
    renderPage();
    await searchByName(user);
    await findEl(() => document.querySelector("[data-lookup-progress]"));
    pending.reject(error);

    expect((await findEl(() => document.querySelector("[data-lookup-error]"))).textContent).toContain(message);
    expect(document.querySelector("[data-lookup-progress]")).toBeNull();
    expect(post).toHaveBeenCalledTimes(1);
  });

  it("shows a clear empty state for zero results", async () => {
    const user = userEvent.setup();
    mockPosts({ "/search/name": list([], { outcome: "NOT_FOUND" }) });
    renderPage();
    await searchByName(user);
    expect((await findEl(() => document.querySelector('[data-outcome="NOT_FOUND"]'))).textContent).toContain("لا توجد نتائج مطابقة");
    expect(document.querySelector("[data-result]")).toBeNull();
  });
});

describe("result cards", () => {
  it("uses the same card for the full-name and detailed searches, with the list fields only", async () => {
    const user = userEvent.setup();
    mockPosts({ "/search/name": list(), "/search/parts": list([entry(3)]) });
    renderPage();
    await searchByName(user);
    const card = await findEl(() => document.querySelector("[data-result]"));
    expect(Array.from(card.querySelectorAll("dt")).map((dt) => dt.textContent)).toEqual([
      "رقم الهوية", "تاريخ الميلاد", "الجنس (رمز المصدر)", "المحافظة / المدينة (رمز المصدر)",
    ]);
    expect(card.textContent).toContain("*****0001");
    expect(card.textContent).toContain(formatCivilDate("1990-01-15T00:00:00"));
    expect(card.textContent).not.toContain("T00:00");
    expect(within(card).getByRole("button", { name: "عرض السجل" }).className).toContain("h-11");
    expect(document.querySelector("table")).toBeNull();

    await user.click(screen.getByRole("radio", { name: "البحث التفصيلي" }));
    await user.type(screen.getByLabelText("اسم الأب"), "خالد");
    await user.type(screen.getByLabelText("اسم العائلة"), "تجريبية");
    await user.click(screen.getByRole("button", { name: "بحث" }));
    await waitFor(() => expect(document.querySelector("[data-result-name]")?.textContent).toBe("اسم تجريبي 3"));
    expect(document.querySelectorAll("[data-result]")).toHaveLength(1);
  });

  it("opens a card's record with visible feedback and without a counted request", async () => {
    const user = userEvent.setup();
    const pending = deferred<{ data: CivilLookupRecordResponse }>();
    mockPosts({ "/search/name": list(), "/records/open": () => pending.promise });
    renderPage();
    await searchByName(user);
    const card = await findEl(() => document.querySelector("[data-result]"));
    await user.click(within(card).getByRole("button", { name: "عرض السجل" }));

    expect(within(card).getByRole("button", { name: "جارٍ فتح السجل..." })).toBeDisabled();
    expect(document.querySelector("[data-lookup-status]")?.textContent).toBe("جارٍ فتح السجل...");
    pending.release(opened(record()));
    await findEl(() => document.querySelector("[data-citizen-detail]"));
    expect(post).toHaveBeenCalledWith(`${BASE}/records/open`, { ref: "ref-1" });
  });
});

describe("person details", () => {
  it("shows the National ID exactly as the API returns it: full at FULL, masked otherwise — never rebuilt", async () => {
    const user = userEvent.setup();
    mockPosts({ "/search/national-id": list([entry(1)]), "/records/open": opened(record()) });
    const { unmount } = renderPage();
    await user.type(await screen.findByRole("textbox", { name: "رقم الهوية" }), "900000001");
    await user.click(screen.getByRole("button", { name: "بحث" }));
    const header = await findEl(() => document.querySelector("[data-record-header]"));
    expect(header.querySelector('[data-field="mainid"]')?.textContent).toBe("900000001");
    expect(header.querySelector('[data-field="mainid"]')).toHaveAttribute("dir", "ltr");
    // Shown once (the header), not repeated in the section.
    expect(document.querySelectorAll('[data-field="mainid"]')).toHaveLength(1);
    unmount();

    // A masked level (EXTENDED_CONTROLLED): the screen never rebuilds the ID.
    const masked = record({ level: "EXTENDED_CONTROLLED", national_id: "*****0001" });
    masked.sections[0].fields[0] = { key: "mainid", value: "*****0001" };
    mockPosts({ "/search/national-id": list([entry(1)]), "/records/open": opened(masked) });
    renderPage();
    await user.type(await screen.findByRole("textbox", { name: "رقم الهوية" }), "900000001");
    await user.click(screen.getByRole("button", { name: "بحث" }));
    const maskedHeader = await findEl(() => document.querySelector("[data-record-header]"));
    expect(maskedHeader.querySelector('[data-field="mainid"]')?.textContent).toBe("*****0001");
    expect(document.body.textContent).not.toContain("900000001");
    expect(maskedHeader.textContent).toContain("مخفية جزئيًا");
  });

  it("computes the age from the date of birth as of today in Gaza and never shows the stored age", async () => {
    // Only Date is faked: 1990-11-15 is not yet reached on 29 Oct 2026.
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(new Date("2026-10-29T10:00:00Z"));
    const user = userEvent.setup();
    mockPosts({ "/search/national-id": list([entry(1)]), "/records/open": opened(record()) });
    renderPage();
    await user.type(await screen.findByRole("textbox", { name: "رقم الهوية" }), "900000001");
    await user.click(screen.getByRole("button", { name: "بحث" }));
    const detail = await findEl(() => document.querySelector("[data-citizen-detail]"));

    expect(detail.querySelector('[data-field="age"]')?.textContent).toBe(`${(35).toLocaleString("ar")} سنة`);
    expect(detail.querySelector('[data-field="age"]')?.textContent).not.toContain("99");
    expect(detail.querySelector('[data-field="datebirth"]')?.textContent).toBe(formatCivilDate("1990-11-15T00:00:00"));
    expect(detail.querySelector('[data-field="deathdate"]')?.textContent).toBe("غير متوفر");
    // Long values wrap in a stacked field instead of squeezing beside the label.
    expect(detail.querySelector('[data-field="street"]')?.closest("dd")?.className).toContain("break-words");
  });

  it("shows the age at death for a deceased person and never substitutes today for an unusable death date", async () => {
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(new Date("2026-10-29T10:00:00Z"));
    const cases: [string | null, string, string][] = [
      ["2020-11-14T00:00:00", "العمر عند الوفاة", `${(29).toLocaleString("ar")} سنة`],
      // A future death date, or one before the birth date: deceased, age unavailable.
      ["2027-01-01T00:00:00", "العمر عند الوفاة", "غير متوفر"],
      ["1980-01-01T00:00:00", "العمر عند الوفاة", "غير متوفر"],
      ["غير معروف", "العمر عند الوفاة", "غير متوفر"],
      // No death date: living.
      [null, "العمر", `${(35).toLocaleString("ar")} سنة`],
    ];
    for (const [deathdate, label, shown] of cases) {
      const user = userEvent.setup();
      const r = record();
      r.sections[0].fields[4] = { key: "deathdate", value: deathdate };
      mockPosts({ "/search/national-id": list([entry(1)]), "/records/open": opened(r) });
      const { unmount } = renderPage();
      await user.type(await screen.findByRole("textbox", { name: "رقم الهوية" }), "900000001");
      await user.click(screen.getByRole("button", { name: "بحث" }));
      const age = (await findEl(() => document.querySelector('[data-citizen-detail] [data-field="age"]'))) as HTMLElement;
      expect(age.textContent).toBe(shown);
      expect(age.closest("div")?.querySelector("dt")?.textContent).toBe(label);
      expect(document.querySelector("[data-citizen-detail]")?.textContent).not.toContain("99 سنة");
      unmount();
    }
  });

  it("says the age runs to today when the level does not return the death date", async () => {
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(new Date("2026-10-29T10:00:00Z"));
    const user = userEvent.setup();
    const standard = record({ level: "STANDARD", national_id: "900000001" });
    standard.sections[0].fields = standard.sections[0].fields.filter((f) => f.key !== "deathdate");
    mockPosts({ "/search/national-id": list([entry(1)]), "/records/open": opened(standard) });
    renderPage();
    await user.type(await screen.findByRole("textbox", { name: "رقم الهوية" }), "900000001");
    await user.click(screen.getByRole("button", { name: "بحث" }));
    const detail = await findEl(() => document.querySelector("[data-citizen-detail]"));
    expect(detail.querySelector('[data-field="age"]')?.textContent).toBe(`${(35).toLocaleString("ar")} سنة`);
    expect(detail.querySelector("[data-age-note]")?.textContent).toContain("تاريخ الوفاة لا يُعرض في هذا المستوى");
  });

  it("explains the STANDARD disclosure and refreshes the quota after opening a record (FB-CIVIL-02F)", async () => {
    const user = userEvent.setup();
    const get = vi.spyOn(apiClient, "get").mockResolvedValue(status as never);
    mockPosts({ "/search/name": list(), "/records/open": opened(record({ level: "STANDARD" })) });
    renderPage();
    await searchByName(user);
    const card = await findEl(() => document.querySelector("[data-result]"));
    const before = get.mock.calls.filter(([p]) => p === `${BASE}/status`).length;
    await user.click(within(card).getByRole("button", { name: "عرض السجل" }));
    const header = await findEl(() => document.querySelector("[data-record-header]"));
    expect(header.querySelector('[data-field="mainid"]')?.textContent).toBe("900000001");
    expect(header.querySelector("[data-source-note]")?.textContent).toContain("أول عرض لسجل في اليوم يُحتسب من رصيدك");
    await waitFor(() => expect(get.mock.calls.filter(([p]) => p === `${BASE}/status`).length).toBeGreaterThan(before));
  });

  it("shows «غير متوفر» for a missing, invalid or future date of birth", async () => {
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(new Date("2026-10-29T10:00:00Z"));
    for (const datebirth of [null, "غير معروف", "2030-01-01T00:00:00"]) {
      const user = userEvent.setup();
      const r = record();
      r.sections[0].fields[2] = { key: "datebirth", value: datebirth };
      mockPosts({ "/search/national-id": list([entry(1)]), "/records/open": opened(r) });
      const { unmount } = renderPage();
      await user.type(await screen.findByRole("textbox", { name: "رقم الهوية" }), "900000001");
      await user.click(screen.getByRole("button", { name: "بحث" }));
      const detail = await findEl(() => document.querySelector("[data-citizen-detail]"));
      expect(detail.querySelector('[data-field="age"]')?.textContent).toBe("غير متوفر");
      unmount();
      vi.restoreAllMocks();
      vi.spyOn(apiClient, "get").mockResolvedValue(status as never);
    }
  });

  it("opens a related person with loading feedback, keeps unavailable links distinct, and goes back without a request", async () => {
    const user = userEvent.setup();
    const pending = deferred<{ data: CivilLookupRecordResponse }>();
    const father = record({ ref: "rec-2", national_id: "900000002", fullname: "خالد تجريبي", links: [] });
    father.sections[0].fields[0] = { key: "mainid", value: "900000002" };
    father.sections[0].fields[1] = { key: "fullname", value: "خالد تجريبي" };
    mockPosts({ "/search/national-id": list([entry(1)]), "/records/open": opened(record()), "/links/open": () => pending.promise });
    renderPage();
    await user.type(await screen.findByRole("textbox", { name: "رقم الهوية" }), "900000001");
    await user.click(screen.getByRole("button", { name: "بحث" }));
    await findEl(() => document.querySelector('[data-link="FATHER"]'));

    expect(within(document.querySelector('[data-link="MOTHER"]') as HTMLElement).queryByRole("button")).toBeNull();
    expect(document.querySelector('[data-link="MOTHER"]')?.textContent).toContain("غير موجود في الإصدار المنشور");
    expect(document.querySelector('[data-link="HOUSEHOLD"]')?.textContent).toContain("غير مفعّل حاليًا");

    const fatherRow = document.querySelector('[data-link="FATHER"]') as HTMLElement;
    await user.click(within(fatherRow).getByRole("button", { name: "فتح السجل" }));
    expect(within(fatherRow).getByRole("button", { name: "جارٍ الفتح..." })).toBeDisabled();
    expect(document.querySelector("[data-lookup-status]")?.textContent).toBe("جارٍ فتح السجل...");
    pending.release(opened(father));
    await waitFor(() => expect(document.querySelector('[data-record-header] [data-field="fullname"]')?.textContent).toBe("خالد تجريبي"));

    const before = post.mock.calls.length;
    await user.click(screen.getByRole("button", { name: "رجوع" }));
    expect(document.querySelector('[data-record-header] [data-field="fullname"]')?.textContent).toBe("اسم تجريبي 1");
    await user.click(within(document.querySelector("[data-breadcrumb]") as HTMLElement).getByRole("button", { name: "نتائج البحث" }));
    expect(document.querySelectorAll("[data-result]")).toHaveLength(1);
    expect(post.mock.calls.length).toBe(before);
  });
});
