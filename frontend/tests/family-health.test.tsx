import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { FamilyGate } from "@/components/family/family-gate";
import { FamilyHousehold } from "@/components/family/household/family-household";
import { FamilyMembers } from "@/components/family/members/family-members";
import { ApiError, apiClient } from "@/lib/api/client";
import type { FamilyHealthRecord, FamilyHouseholdHealth } from "@/lib/api/family-household";
import { formatDateLong } from "@/lib/utils/date";
import { browserStorageDump, familyGet, familyHealth, familyMember, familyMembers, renderWithClient } from "./helpers";

// PWA-3B.6: household health on GET /api/v1/family/household/health — the
// member sheet's «الحالة الصحية», the member-card chip and the «أسرتي» line.
// Synthetic data only.

const router = { replace: vi.fn(), push: vi.fn() };
const location = { pathname: "/family/members" };
vi.mock("next/navigation", () => ({ useRouter: () => router, usePathname: () => location.pathname }));
vi.mock("next/link", () => ({
  default: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...props}>
      {children}
    </a>
  ),
}));

const HEALTH = "/api/v1/family/household/health";

const HEAD = familyMember({
  full_name: "سالم الاختبار", relationship: { code: "HEAD", name: "رب الأسرة" }, is_household_head: true, birth_date: "1980-01-01",
});
const WIFE = familyMember({ full_name: "زوجة الاختبار", relationship: { code: "SPOUSE", name: "زوجة" }, gender: "FEMALE", birth_date: "1985-01-01" });
const SON = familyMember({ full_name: "ابن الاختبار" });
const MEMBERS = [HEAD, WIFE, SON];

function record(overrides: Partial<FamilyHealthRecord> = {}): FamilyHealthRecord {
  return {
    type: "CHRONIC_DISEASE", disability_type: null, condition_name: "السكري", started_at: "2015-02-01", ended_at: null, is_active: true,
    ...overrides,
  };
}

const WIFE_RECORDS: FamilyHealthRecord[] = [
  record({ type: "DISABILITY", disability_type: { code: "MOTOR", name: "حركية" }, condition_name: null, started_at: "2019-05-01" }),
  record({ type: "DISABILITY", disability_type: { code: "VISUAL", name: "بصرية" }, condition_name: null, started_at: null }),
  record({ condition_name: "ضغط الدم", started_at: "2016-03-01" }),
  record({ type: "PREGNANCY", condition_name: null, started_at: "2026-05-01" }),
  record({ type: "BREASTFEEDING", condition_name: null, started_at: "2024-01-01", ended_at: "2025-01-01", is_active: false }),
  record({ condition_name: "فقر الدم", started_at: "2010-01-01", ended_at: "2012-06-30", is_active: false }),
];

const HOUSEHOLD_HEALTH = familyHealth([
  { member_ref: WIFE.member_ref, records: WIFE_RECORDS },
  { member_ref: HEAD.member_ref, records: [record()] },
]);

function renderMembers(health: FamilyHouseholdHealth | Error | (() => Promise<unknown>) = HOUSEHOLD_HEALTH) {
  const get = vi.spyOn(apiClient, "get").mockImplementation(familyGet({ members: familyMembers(MEMBERS), health }));
  const result = renderWithClient(
    <FamilyGate>
      <FamilyMembers />
    </FamilyGate>
  );

  return { get, ...result };
}

async function openMember(name: string) {
  await userEvent.click(await screen.findByRole("button", { name: `تفاصيل ${name}` }));
  return screen.findByRole("dialog");
}

const healthSection = (sheet: HTMLElement) => sheet.querySelector('[data-member-section="health"]') as HTMLElement;
const card = (name: string) => screen.getByText(name).closest("li") as HTMLElement;

beforeEach(() => {
  vi.restoreAllMocks();
  router.replace.mockReset();
  location.pathname = "/family/members";
  window.localStorage.clear();
  window.sessionStorage.clear();
});

describe("the member sheet «الحالة الصحية»", () => {
  it("reads GET /api/v1/family/household/health once, with no identifier", async () => {
    const { get } = renderMembers();
    await openMember("زوجة الاختبار");

    const paths = get.mock.calls.map(([path]) => String(path)).filter((path) => path.includes("health"));
    expect(new Set(paths)).toEqual(new Set([HEALTH]));
  });

  it("shows the section after the basic data, in the applicable sub-groups only", async () => {
    renderMembers();
    const sheet = await openMember("زوجة الاختبار");

    const sections = Array.from(sheet.querySelectorAll("[data-member-section]")).map((s) => s.getAttribute("data-member-section"));
    expect(sections.indexOf("health")).toBe(sections.indexOf("basic") + 1);
    const health = healthSection(sheet);
    expect(await within(health).findByRole("heading", { name: "الإعاقة" })).toBeInTheDocument();
    expect(within(health).getByRole("heading", { name: "الأمراض المزمنة" })).toBeInTheDocument();
    expect(within(health).getByRole("heading", { name: "الحمل والرضاعة" })).toBeInTheDocument();
    expect(within(health).getByText("هذه هي البيانات الصحية المسجّلة حاليًا في سجل الأسرة.")).toBeInTheDocument();
  });

  it("renders each disability as «إعاقة …», each chronic disease by name, and pregnancy / breastfeeding", async () => {
    renderMembers();
    const health = healthSection(await openMember("زوجة الاختبار"));

    const disability = within(health).getByRole("heading", { name: "الإعاقة" }).closest("section") as HTMLElement;
    expect(within(disability).getAllByRole("listitem").map((li) => li.querySelector("p")?.textContent)).toEqual(["إعاقة حركية", "إعاقة بصرية"]);
    const chronic = within(health).getByRole("heading", { name: "الأمراض المزمنة" }).closest("section") as HTMLElement;
    expect(within(chronic).getAllByRole("listitem").map((li) => li.querySelector("p")?.textContent)).toEqual(["ضغط الدم", "فقر الدم"]);
    const maternal = within(health).getByRole("heading", { name: "الحمل والرضاعة" }).closest("section") as HTMLElement;
    expect(within(maternal).getAllByRole("listitem").map((li) => li.querySelector("p")?.textContent)).toEqual(["حمل", "رضاعة"]);
  });

  it("labels current and ended records, shows the end date only for ended ones, and «غير مسجّل» for a null date", async () => {
    renderMembers();
    const health = healthSection(await openMember("زوجة الاختبار"));

    const item = (title: string) => within(health).getByText(title).closest("li") as HTMLElement;
    expect(within(item("ضغط الدم")).getByText("حالية")).toBeInTheDocument();
    expect(within(item("ضغط الدم")).getByText(formatDateLong("2016-03-01"))).toBeInTheDocument();
    expect(within(item("ضغط الدم")).queryByText("تاريخ الانتهاء")).not.toBeInTheDocument();
    expect(within(item("فقر الدم")).getByText("منتهية")).toBeInTheDocument();
    expect(within(item("فقر الدم")).getByText("تاريخ الانتهاء")).toBeInTheDocument();
    expect(within(item("فقر الدم")).getByText(formatDateLong("2012-06-30"))).toBeInTheDocument();
    expect(within(item("إعاقة بصرية")).getByText("غير مسجّل")).toBeInTheDocument();
    expect(health).not.toHaveTextContent("غير معروف");
  });

  it("says exactly that nothing is registered for a member without records — never that they are healthy", async () => {
    renderMembers();
    const health = healthSection(await openMember("ابن الاختبار"));

    expect(await within(health).findByText("لا توجد بيانات صحية مسجّلة لهذا الفرد.")).toBeInTheDocument();
    for (const claim of ["سليم", "لا توجد أمراض", "لا توجد إعاقة"]) {
      expect(health).not.toHaveTextContent(claim);
    }
    expect(within(health).queryByRole("heading", { name: "الإعاقة" })).not.toBeInTheDocument();
  });

  it("shows the head's own records too, in the applicable sub-group only", async () => {
    renderMembers();
    const health = healthSection(await openMember("سالم الاختبار"));

    expect(await within(health).findByText("السكري")).toBeInTheDocument();
    expect(within(health).getByRole("heading", { name: "الأمراض المزمنة" })).toBeInTheDocument();
    expect(within(health).queryByRole("heading", { name: "الإعاقة" })).not.toBeInTheDocument();
    expect(within(health).queryByRole("heading", { name: "الحمل والرضاعة" })).not.toBeInTheDocument();
  });

  it("shows a loading state, then an error with retry that leaves the member's other data in place", async () => {
    let fail = true;
    renderMembers(() => (fail ? Promise.reject(new ApiError(500, { message: "x" })) : Promise.resolve({ data: HOUSEHOLD_HEALTH })));
    const sheet = await openMember("زوجة الاختبار");

    expect(await within(sheet).findByText("تعذّر تحميل البيانات الصحية")).toBeInTheDocument();
    expect(within(sheet).getByRole("heading", { name: "البيانات الأساسية" })).toBeInTheDocument();
    expect(within(sheet).getByRole("heading", { name: "بيانات الهوية والاتصال" })).toBeInTheDocument();

    fail = false;
    await userEvent.click(within(sheet).getByRole("button", { name: "إعادة المحاولة" }));
    expect(await within(healthSection(sheet)).findByText("ضغط الدم")).toBeInTheDocument();
  });

  it("shows a skeleton while the health data loads", async () => {
    renderMembers(() => new Promise<never>(() => {}));
    const sheet = await openMember("زوجة الاختبار");

    expect(sheet.querySelector("[data-health-loading]")).not.toBeNull();
    expect(within(sheet).getByRole("heading", { name: "البيانات الأساسية" })).toBeInTheDocument();
  });

  it("never shows another member's records when switching members", async () => {
    renderMembers();
    const first = healthSection(await openMember("زوجة الاختبار"));
    expect(await within(first).findByText("ضغط الدم")).toBeInTheDocument();

    await userEvent.click(within(screen.getByRole("dialog")).getByRole("button", { name: "إغلاق" }));
    await waitFor(() => expect(screen.queryByRole("dialog")).not.toBeInTheDocument());
    const second = healthSection(await openMember("ابن الاختبار"));

    expect(within(second).getByText("لا توجد بيانات صحية مسجّلة لهذا الفرد.")).toBeInTheDocument();
    for (const other of ["ضغط الدم", "إعاقة حركية", "حمل", "السكري"]) {
      expect(within(second).queryByText(other)).not.toBeInTheDocument();
    }
  });

  it("offers no edit, add, close or request control and renders no internal field", async () => {
    const leaky = familyHealth([
      {
        member_ref: WIFE.member_ref,
        records: [{ ...record(), details: "ملاحظة داخلية سرية", uuid: "11111111-2222-4333-8444-555555555555", person_code: "PER-000999", created_by: "موظف" } as FamilyHealthRecord],
      },
    ]);
    renderMembers(leaky);
    const health = healthSection(await openMember("زوجة الاختبار"));
    await within(health).findByText("السكري");

    expect(within(health).queryAllByRole("button")).toHaveLength(0);
    expect(within(health).queryAllByRole("link")).toHaveLength(0);
    for (const text of [/تعديل/, /إضافة/, /إغلاق السجل/, /حذف/, /طلب/, /إظهار/]) {
      expect(within(health).queryByText(text)).not.toBeInTheDocument();
    }
    const html = document.body.innerHTML;
    for (const value of ["ملاحظة داخلية سرية", "11111111-2222", "PER-000999", "موظف"]) {
      expect(html).not.toContain(value);
    }
  });
});

describe("the member-card chip", () => {
  it("marks exactly the members with registered records, of any status", async () => {
    renderMembers(familyHealth([{ member_ref: SON.member_ref, records: [record({ ended_at: "2020-01-01", is_active: false })] }]));
    await screen.findByText("بيانات صحية مسجّلة");

    expect(within(card("ابن الاختبار")).getByText("بيانات صحية مسجّلة")).toBeInTheDocument();
    expect(within(card("سالم الاختبار")).queryByText("بيانات صحية مسجّلة")).not.toBeInTheDocument();
    expect(within(card("زوجة الاختبار")).queryByText("بيانات صحية مسجّلة")).not.toBeInTheDocument();
  });

  it("shows no chip when nothing is registered or the health data fails", async () => {
    renderMembers(new ApiError(500, { message: "x" }));
    await screen.findByText("ابن الاختبار");

    expect(screen.queryByText("بيانات صحية مسجّلة")).not.toBeInTheDocument();
    expect(screen.queryByText(/سليم/)).not.toBeInTheDocument();
  });
});

describe("«أسرتي»", () => {
  function renderHousehold(health: FamilyHouseholdHealth) {
    location.pathname = "/family/household";
    vi.spyOn(apiClient, "get").mockImplementation(familyGet({ health }));
    return renderWithClient(
      <FamilyGate>
        <FamilyHousehold />
      </FamilyGate>
    );
  }

  it("counts the members with registered health data inside the members card, which links to the list", async () => {
    renderHousehold(HOUSEHOLD_HEALTH);

    const line = await screen.findByText(/بيانات صحية مسجّلة لـ/);
    expect(line).toHaveTextContent("بيانات صحية مسجّلة لـ 2 من الأفراد");
    const members = line.closest("[data-household-members]") as HTMLElement;
    expect(within(members).getByRole("link", { name: /عرض أفراد الأسرة/ })).toHaveAttribute("href", "/family/members");
  });

  it("shows no line when nothing is registered", async () => {
    renderHousehold(familyHealth());

    await screen.findByText(/عرض أفراد الأسرة/);
    expect(screen.queryByText(/بيانات صحية مسجّلة/)).not.toBeInTheDocument();
  });
});

describe("privacy", () => {
  it("keeps nothing in browser storage", async () => {
    renderMembers();
    const health = healthSection(await openMember("زوجة الاختبار"));
    await within(health).findByText("ضغط الدم");

    expect(browserStorageDump()).not.toMatch(/ضغط|حركية|DISABILITY|health/i);
  });
});
