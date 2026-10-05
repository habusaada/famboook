import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { FamilyGate } from "@/components/family/family-gate";
import { FamilyMembers } from "@/components/family/members/family-members";
import { apiClient } from "@/lib/api/client";
import type { FamilyMember } from "@/lib/api/family-household";
import { formatDateLong } from "@/lib/utils/date";
import { familyGet, familyMember, familyMembers, renderWithClient } from "./helpers";

// PWA-3B.3: the in-page member detail sheet on /family/members — read-only,
// fed by the member list, sensitive values MASKED only (the member reveal is
// PWA-3B.4). Synthetic data only.

const router = { replace: vi.fn(), push: vi.fn() };
vi.mock("next/navigation", () => ({ useRouter: () => router, usePathname: () => "/family/members" }));
vi.mock("next/link", () => ({
  default: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...props}>
      {children}
    </a>
  ),
}));

const HEAD = familyMember({
  full_name: "سالم الاختبار",
  relationship: { code: "HEAD", name: "رب الأسرة" },
  is_household_head: true,
  birth_date: "1980-01-15",
  marital_status: "MARRIED",
  national_id_masked: "*****6789",
  mobile_masked: "05*****567",
  alternate_mobile_masked: "05*****321",
  alternate_mobile_owner_relation: "أخ",
  membership_started_at: "2001-03-04",
});

const SPOUSE = familyMember({
  full_name: "زوجة الاختبار",
  relationship: { code: "SPOUSE", name: "زوج/زوجة" },
  gender: "FEMALE",
  birth_date: "1985-03-03",
  marital_status: "UNKNOWN",
  national_id_masked: "*****6554",
  mobile_masked: "05*****334",
  membership_started_at: null,
});

function renderMembers(members: FamilyMember[]) {
  const get = vi.spyOn(apiClient, "get").mockImplementation(familyGet({ members: familyMembers(members) }));
  const post = vi.spyOn(apiClient, "post");
  const result = renderWithClient(
    <FamilyGate>
      <FamilyMembers />
    </FamilyGate>
  );

  return { get, post, ...result };
}

async function openDetails(name: string) {
  await userEvent.click(await screen.findByRole("button", { name: `تفاصيل ${name}` }));
  return screen.findByRole("dialog");
}

const field = (container: HTMLElement, name: string) => container.querySelector(`[data-field="${name}"] dd`) as HTMLElement | null;

beforeEach(() => {
  vi.restoreAllMocks();
  window.localStorage.clear();
  window.sessionStorage.clear();
});

describe("opening the details", () => {
  it("opens from an available member's card, with no request and no identifier", async () => {
    const { get } = renderMembers([HEAD, SPOUSE]);

    const sheet = await openDetails("زوجة الاختبار");

    expect(within(sheet).getByRole("heading", { name: "زوجة الاختبار" })).toBeInTheDocument();
    // Only the session check and the list itself are ever requested (a
    // routine refetch of either is fine): no member-specific request.
    const paths = new Set(get.mock.calls.map(([path]) => String(path)));
    expect([...paths].sort()).toEqual(["/api/v1/family/household/members", "/api/v1/family/me"]);
  });

  it("offers no details for an unavailable member", async () => {
    renderMembers([HEAD, familyMember({ available: false, full_name: null, gender: null, birth_date: null, life_status: null })]);
    const cards = within(await screen.findByRole("list", { name: "أفراد الأسرة" })).getAllByRole("listitem");

    expect(within(cards[1]).queryByRole("button")).not.toBeInTheDocument();
    expect(cards[1]).toHaveTextContent("بيانات هذا الفرد غير متاحة حاليًا");
  });

  it("lets the head open their registry details and keeps the «بياناتي الشخصية» link", async () => {
    renderMembers([HEAD, SPOUSE]);
    const cards = within(await screen.findByRole("list", { name: "أفراد الأسرة" })).getAllByRole("listitem");

    expect(within(cards[0]).getByRole("link", { name: "بياناتي الشخصية" })).toHaveAttribute("href", "/family/account/me");
    const sheet = await openDetails("سالم الاختبار");
    expect(field(sheet, "household_head")).toHaveTextContent("رب الأسرة");
  });

  it("closes with the Arabic close control", async () => {
    renderMembers([HEAD, SPOUSE]);
    const sheet = await openDetails("زوجة الاختبار");

    await userEvent.click(within(sheet).getByRole("button", { name: "إغلاق" }));
    await waitFor(() => expect(screen.queryByRole("dialog")).not.toBeInTheDocument());
  });
});

describe("the details", () => {
  it("show the approved registry fields in three groups", async () => {
    renderMembers([HEAD, SPOUSE]);
    const sheet = await openDetails("سالم الاختبار");

    expect(within(sheet).getAllByRole("heading", { level: 3 }).map((h) => h.textContent)).toEqual([
      "البيانات الأساسية",
      "بيانات الهوية والاتصال",
      "بيانات العضوية",
    ]);
    expect(field(sheet, "full_name")).toHaveTextContent("سالم الاختبار");
    expect(field(sheet, "relationship")).toHaveTextContent("رب الأسرة");
    expect(field(sheet, "gender")).toHaveTextContent("ذكر");
    expect(field(sheet, "birth_date")).toHaveTextContent(formatDateLong("1980-01-15"));
    expect(field(sheet, "age")).toHaveTextContent(/سنة/);
    expect(field(sheet, "marital_status")).toHaveTextContent("متزوج/ة");
    expect(field(sheet, "life_status")).toHaveTextContent("على قيد الحياة");
    expect(field(sheet, "alternate_mobile_owner_relation")).toHaveTextContent("أخ");
    expect(field(sheet, "membership_started_at")).toHaveTextContent(formatDateLong("2001-03-04"));
    expect(sheet.querySelector('[data-field="death_date"]')).toBeNull();
  });

  it("show the identity and contact values masked, with a reveal control only for recorded values and no request", async () => {
    const { post } = renderMembers([HEAD, SPOUSE]);
    const sheet = await openDetails("زوجة الاختبار");

    expect(field(sheet, "national_id")).toHaveTextContent("*****6554");
    expect(field(sheet, "mobile")).toHaveTextContent("05*****334");
    expect(field(sheet, "alternate_mobile")).toHaveTextContent("غير مسجّل");
    // PWA-3B.4: the close control plus one unpressed Eye per recorded value.
    expect(within(sheet).getAllByRole("button").map((b) => b.getAttribute("aria-label"))).toEqual([
      "إغلاق",
      "إظهار رقم الهوية",
      "إظهار رقم الجوال",
    ]);
    expect(sheet.querySelectorAll('[aria-pressed="true"]')).toHaveLength(0);
    // Nothing is requested until an Eye is pressed.
    expect(post).not.toHaveBeenCalled();
    expect(sheet.textContent).not.toMatch(/PER-|person_code|\d{9}|05\d{8}/);
  });

  it("show an explicit UNKNOWN marital status as «غير معروف» and a missing date as «غير مسجّل»", async () => {
    renderMembers([HEAD, SPOUSE]);
    const sheet = await openDetails("زوجة الاختبار");

    expect(field(sheet, "marital_status")).toHaveTextContent("غير معروف");
    expect(field(sheet, "membership_started_at")).toHaveTextContent("غير مسجّل");
  });

  it("show a null birth date as «غير مسجّل», never «غير معروف», and no age", async () => {
    renderMembers([HEAD, familyMember({ full_name: "ابن بلا تاريخ", birth_date: null })]);
    const sheet = await openDetails("ابن بلا تاريخ");

    expect(field(sheet, "birth_date")).toHaveTextContent("غير مسجّل");
    expect(field(sheet, "birth_date")).not.toHaveTextContent("غير معروف");
    expect(sheet.querySelector('[data-field="age"]')).toBeNull();
  });

  it("show an UNKNOWN life status as «الحالة غير مؤكدة»", async () => {
    renderMembers([HEAD, familyMember({ full_name: "ابنة غير مؤكدة", gender: "FEMALE", life_status: "UNKNOWN" })]);
    const sheet = await openDetails("ابنة غير مؤكدة");

    expect(field(sheet, "life_status")).toHaveTextContent("الحالة غير مؤكدة");
  });
});

describe("a deceased member", () => {
  it("stays in the list and shows the death date", async () => {
    renderMembers([HEAD, familyMember({ full_name: "ابن متوفى", life_status: "DECEASED", death_date: "2024-11-20" })]);
    const cards = within(await screen.findByRole("list", { name: "أفراد الأسرة" })).getAllByRole("listitem");
    expect(cards).toHaveLength(2);

    const sheet = await openDetails("ابن متوفى");
    expect(field(sheet, "life_status")).toHaveTextContent("متوفى");
    expect(field(sheet, "death_date")).toHaveTextContent(formatDateLong("2024-11-20"));
    expect(sheet.querySelector('[data-field="age"]')).toBeNull();
  });

  it("says «تاريخ الوفاة غير معروف» when the date is not recorded — never an invented date", async () => {
    renderMembers([HEAD, familyMember({ full_name: "ابنة متوفاة", gender: "FEMALE", life_status: "DECEASED", death_date: null })]);
    const sheet = await openDetails("ابنة متوفاة");

    expect(field(sheet, "life_status")).toHaveTextContent("متوفاة");
    expect(field(sheet, "death_date")).toHaveTextContent("تاريخ الوفاة غير معروف");
    expect(field(sheet, "death_date")?.textContent).not.toMatch(/\d/);
  });
});

describe("the member reference (FU-13)", () => {
  it("identifies the opened member without ever reaching the DOM or a URL", async () => {
    const refs = ["1".repeat(64), "2".repeat(64)];
    const { get } = renderMembers([
      { ...HEAD, member_ref: refs[0] },
      { ...SPOUSE, member_ref: refs[1] },
    ]);

    const sheet = await openDetails("زوجة الاختبار");

    // The second member's details, chosen by its reference.
    expect(within(sheet).getByRole("heading", { name: "زوجة الاختبار" })).toBeInTheDocument();
    for (const ref of refs) {
      expect(document.body.innerHTML).not.toContain(ref);
    }
    expect(get.mock.calls.map(([path]) => String(path)).join(" ")).not.toMatch(/[0-9a-f]{64}/);
  });

  it("keeps two members with identical names apart", async () => {
    renderMembers([HEAD, { ...SPOUSE, member_ref: "3".repeat(64) }, { ...SPOUSE, full_name: "زوجة الاختبار", mobile_masked: "05*****999", member_ref: "4".repeat(64) }]);
    const buttons = await screen.findAllByRole("button", { name: "تفاصيل زوجة الاختبار" });

    await userEvent.click(buttons[1]);
    const sheet = await screen.findByRole("dialog");

    expect(field(sheet, "mobile")).toHaveTextContent("05*****999");
  });
});
