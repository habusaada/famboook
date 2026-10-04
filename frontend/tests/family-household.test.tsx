import { act, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { FamilyGate } from "@/components/family/family-gate";
import { FamilyHousehold, NO_CURRENT_ADDRESS, NO_RESIDENCE, NOT_RECORDED } from "@/components/family/household/family-household";
import { SUMMARY_CAPTION } from "@/components/family/home/household-cards";
import { ApiError, apiClient } from "@/lib/api/client";
import { FAMILY_PROFILE_QUERY_KEY, type FamilyProfile } from "@/lib/api/family-household";
import { browserStorageDump, familyGet, familyProfile, renderWithClient } from "./helpers";

// PWA-3A Step 4: /family/household («أسرتي») on GET
// /api/v1/family/household/profile. Synthetic data only.

const router = { replace: vi.fn(), push: vi.fn() };
const location = { pathname: "/family/household" };
vi.mock("next/navigation", () => ({ useRouter: () => router, usePathname: () => location.pathname }));
vi.mock("next/link", () => ({
  default: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...props}>
      {children}
    </a>
  ),
}));

const PROFILE = "/api/v1/family/household/profile";
const EMPTY_ADDRESS = { governorate: null, city: null, area: null, neighborhood: null };

function renderHousehold(profile: FamilyProfile | Error | (() => Promise<unknown>) = familyProfile()) {
  const get = vi.spyOn(apiClient, "get").mockImplementation(familyGet({ profile }));
  const result = renderWithClient(
    <FamilyGate>
      <FamilyHousehold />
    </FamilyGate>
  );

  return { get, ...result };
}

const info = () => screen.findByRole("region", { name: "معلومات الأسرة" });
const residenceCard = () => screen.findByRole("region", { name: "السكن" });
const field = (container: HTMLElement, name: string) => container.querySelector(`[data-field="${name}"] dd`) as HTMLElement | null;

beforeEach(() => {
  router.replace.mockReset();
  location.pathname = "/family/household";
  window.localStorage.clear();
  window.sessionStorage.clear();
});

describe("the page", () => {
  it("reads only GET /api/v1/family/household/profile — never the members — with no identifier", async () => {
    const { get } = renderHousehold();
    await info();

    const paths = get.mock.calls.map(([path]) => String(path));
    expect(paths).toContain(PROFILE);
    expect(paths).not.toContain("/api/v1/family/household/members");
    expect(paths.join(" ")).not.toMatch(/family_id|residence_id|declaration_id|person_id|\?/);
  });

  it("has one h1 «أسرتي» with the family code (LTR) beside it, and three section headings", async () => {
    const { container } = renderHousehold();
    await info();

    expect(screen.getAllByRole("heading", { level: 1 }).map((h) => h.textContent)).toEqual(["أسرتي"]);
    expect(container.querySelector("header [data-family-code]")).toHaveAttribute("dir", "ltr");
    expect(container.querySelector("header [data-family-code]")?.textContent).toBe("FAM-000123");
    expect(screen.getAllByRole("heading", { level: 2 }).map((h) => h.textContent)).toEqual(["معلومات الأسرة", "السكن", "أفراد الأسرة"]);
  });
});

describe("معلومات الأسرة", () => {
  it("shows the complete family facts", async () => {
    renderHousehold(familyProfile({ declared_household_size: 7, declared_at: "2026-09-01", registered_member_count: 5 }));
    const card = await info();

    expect(field(card, "family_code")?.querySelector("[dir=ltr]")?.textContent).toBe("FAM-000123");
    expect(field(card, "clan")?.textContent).toBe("عائلة الاختبار");
    expect(field(card, "branch")?.textContent).toBe("فرع الاختبار");
    expect(field(card, "head")?.textContent).toBe("سالم الاختبار");
    expect(field(card, "head")?.querySelector("bdi")).not.toBeNull();
    expect(field(card, "declared_household_size")?.textContent).toBe("7");
    expect(field(card, "declared_at")?.textContent).toBe("1 سبتمبر 2026");
    expect(field(card, "registered_member_count")?.textContent).toBe("5");
    expect(within(card).getByText(SUMMARY_CAPTION)).toBeInTheDocument();
  });

  it("hides the branch and the declaration date when there are none", async () => {
    renderHousehold(familyProfile({ branch_name: null, declared_at: null }));
    const card = await info();

    expect(card.querySelector('[data-field="branch"]')).toBeNull();
    expect(card.querySelector('[data-field="declared_at"]')).toBeNull();
    expect(within(card).queryByText("الفرع")).not.toBeInTheDocument();
    expect(within(card).queryByText("تاريخ الإقرار")).not.toBeInTheDocument();
  });

  it("says «غير مُعلن» for a null declared size, and keeps a declared zero", async () => {
    renderHousehold(familyProfile({ declared_household_size: null }));
    expect(field(await info(), "declared_household_size")?.textContent).toBe("غير مُعلن");
  });

  it("keeps a declared zero as 0", async () => {
    renderHousehold(familyProfile({ declared_household_size: 0 }));
    expect(field(await info(), "declared_household_size")?.textContent).toBe("0");
  });

  it("never shows registration, status, source, paper form or declared children", async () => {
    renderHousehold();
    const card = await info();

    expect(card.textContent).not.toMatch(/تاريخ التسجيل|الحالة|المصدر|رقم الاستمارة|أبناء|بنات|ذكور|إناث/);
    expect(Array.from(card.querySelectorAll("[data-field]")).map((el) => el.getAttribute("data-field"))).toEqual([
      "family_code", "clan", "branch", "head", "declared_household_size", "declared_at", "registered_member_count",
    ]);
  });
});

describe("السكن", () => {
  it("shows a complete residence: original, displacement, location and the address most local first", async () => {
    renderHousehold();
    const card = await residenceCard();

    expect(field(card, "original_residence")?.textContent).toBe("بني سهيلا – خانيونس");
    expect(field(card, "original_residence")?.querySelector("bdi")).not.toBeNull();
    expect(field(card, "displacement_status")?.textContent).toBe("نازحة");
    expect(field(card, "displacement_location")?.textContent).toBe("مواصي خانيونس");
    expect(field(card, "current_address")?.textContent).toBe("حي الاختبار، المواصي، خانيونس، خانيونس");
    expect(field(card, "current_address")?.querySelectorAll("bdi")).toHaveLength(4);
    // The approved label, without the old "(قبل النزوح)".
    expect(within(card).getByText("السكن الأصلي")).toBeInTheDocument();
    expect(card.textContent).not.toContain("قبل النزوح");
  });

  it("shows one neutral message, and no rows, when there is no residence", async () => {
    renderHousehold(familyProfile({}, null));
    const card = await residenceCard();

    expect(within(card).getByText(NO_RESIDENCE)).toBeInTheDocument();
    expect(card.querySelector("[data-field]")).toBeNull();
  });

  it("says «غير مسجّل» when the original residence is missing", async () => {
    renderHousehold(familyProfile({}, { original_residence_text: null }));
    expect(field(await residenceCard(), "original_residence")?.textContent).toBe(NOT_RECORDED);
  });

  it.each([
    ["DISPLACED", "نازحة", true],
    ["NOT_DISPLACED", "غير نازحة", false],
    [null, "غير محدد", false],
  ] as const)("labels %s as «%s», with the location row only for DISPLACED", async (status, label, hasLocation) => {
    renderHousehold(familyProfile({}, { displacement_status: status, displacement_location_text: status === "DISPLACED" ? "مواصي" : null }));
    const card = await residenceCard();

    expect(field(card, "displacement_status")?.textContent).toBe(label);
    expect(card.querySelector('[data-field="displacement_location"]') !== null).toBe(hasLocation);
    if (!hasLocation) expect(within(card).queryByText("مكان النزوح الحالي")).not.toBeInTheDocument();
  });

  it("says «غير مسجّل» for a displaced family without a location", async () => {
    renderHousehold(familyProfile({}, { displacement_status: "DISPLACED", displacement_location_text: null }));
    expect(field(await residenceCard(), "displacement_location")?.textContent).toBe(NOT_RECORDED);
  });

  it("shows only the populated parts of a partial address — no empty labels", async () => {
    renderHousehold(familyProfile({}, { current_address: { governorate: "غزة", city: null, area: "  ", neighborhood: "الرمال" } }));
    const card = await residenceCard();

    const address = field(card, "current_address") as HTMLElement;
    expect(address.textContent).toBe("الرمال، غزة");
    expect(address.querySelectorAll("[data-address-part]")).toHaveLength(2);
    expect(card.textContent).not.toMatch(/المحافظة|المنطقة|الحي|null|undefined/);
  });

  it("says «لم يُسجَّل عنوان السكن الحالي» for an empty address, as in an imported family", async () => {
    renderHousehold(familyProfile({}, { original_residence_text: "بيت لاهيا", displacement_status: null, displacement_location_text: null, current_address: EMPTY_ADDRESS }));
    const card = await residenceCard();

    expect(field(card, "current_address")?.textContent).toBe(NO_CURRENT_ADDRESS);
    expect(field(card, "current_address")?.querySelector("[data-address-part]")).toBeNull();
    // The original residence is never presented as the current address.
    expect(field(card, "original_residence")?.textContent).toBe("بيت لاهيا");
    expect(field(card, "current_address")?.textContent).not.toContain("بيت لاهيا");
  });

  it("renders free text as stored, isolated for mixed directions", async () => {
    const mixed = "Block 7 – حي النصر <b>";
    renderHousehold(familyProfile({}, { original_residence_text: mixed }));
    const card = await residenceCard();

    const bdi = field(card, "original_residence")?.querySelector("bdi");
    expect(bdi?.textContent).toBe(mixed);
    expect(card.querySelector("b")).toBeNull();
  });
});

describe("أفراد الأسرة", () => {
  it("shows the registered count from the profile and links to /family/members", async () => {
    renderHousehold(familyProfile({ registered_member_count: 9 }));
    const card = await screen.findByRole("region", { name: "أفراد الأسرة" });

    expect(card.querySelector("[data-members-count]")?.textContent).toBe("أفراد الأسرة المسجلون (9)");
    expect(within(card).getByRole("link", { name: "عرض أفراد الأسرة" })).toHaveAttribute("href", "/family/members");
  });
});

describe("loading, errors and access", () => {
  it("shows a busy skeleton while the profile loads", async () => {
    let release: (value: unknown) => void = () => undefined;
    const { container } = renderHousehold(() => new Promise((resolve) => (release = resolve)));

    expect(await screen.findByRole("status")).toHaveTextContent("جارٍ تحميل بيانات الأسرة");
    expect(container.querySelector("[data-household]")).toHaveAttribute("aria-busy", "true");
    expect(container.querySelector("[data-household-loading]")).not.toBeNull();
    expect(screen.getByRole("heading", { level: 1, name: "أسرتي" })).toBeInTheDocument();

    await act(async () => release({ data: familyProfile() }));
    expect(await info()).toBeInTheDocument();
    expect(container.querySelector("[data-household]")).toHaveAttribute("aria-busy", "false");
    expect(container.querySelector("[data-household-loading]")).toBeNull();
  });

  it("shows a server failure inline and retries", async () => {
    let calls = 0;
    renderHousehold(async () => {
      calls++;
      if (calls === 1) throw new ApiError(500, null);
      return { data: familyProfile() };
    });

    expect(await screen.findByRole("alert")).toHaveTextContent("تعذّر تحميل بيانات الأسرة");
    expect(screen.getByRole("navigation", { name: "التنقل الرئيسي" })).toBeInTheDocument();
    expect(screen.queryByRole("heading", { name: "الوصول غير متاح حاليًا" })).not.toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: "إعادة المحاولة" }));

    expect(await info()).toBeInTheDocument();
    expect(calls).toBe(2);
  });

  it("shows a network failure inline too", async () => {
    renderHousehold(new TypeError("Failed to fetch"));

    expect(await screen.findByRole("alert")).toHaveTextContent("تعذّر تحميل بيانات الأسرة");
    expect(screen.queryByRole("heading", { name: "الوصول غير متاح حاليًا" })).not.toBeInTheDocument();
  });

  it.each([
    ["the family context boundary", { message: "بيانات الأسرة غير متاحة لهذا الحساب.", code: "FAMILY_CONTEXT_UNAVAILABLE" }],
    ["the permission check", { message: "This action is unauthorized." }],
  ])("treats ANY 403 (%s) as family data unavailable", async (_label, body) => {
    renderHousehold(new ApiError(403, body));

    expect(await screen.findByRole("heading", { name: "الوصول غير متاح حاليًا" })).toBeInTheDocument();
    expect(screen.queryByRole("navigation")).not.toBeInTheDocument();
    expect(screen.queryByText("تعذّر تحميل بيانات الأسرة")).not.toBeInTheDocument();
    expect(router.replace).not.toHaveBeenCalled();
  });

  it("returns an expired session (401) to the Family login", async () => {
    renderHousehold(new ApiError(401, { message: "Unauthenticated." }));

    await waitFor(() => expect(router.replace).toHaveBeenCalledWith("/family/login"));
    expect(screen.queryByText("تعذّر تحميل بيانات الأسرة")).not.toBeInTheDocument();
  });
});

describe("navigation and privacy", () => {
  it("makes أسرتي the current entry on /family/household, linked there; the rest stays disabled", async () => {
    renderHousehold();
    await info();

    const nav = screen.getByRole("navigation", { name: "التنقل الرئيسي" });
    const household = within(nav).getByRole("link", { name: "أسرتي" });
    expect(household).toHaveAttribute("href", "/family/household");
    expect(household).toHaveAttribute("aria-current", "page");
    expect(within(nav).getByRole("link", { name: "الرئيسية" })).not.toHaveAttribute("aria-current");
    expect(within(nav).getAllByRole("link")).toHaveLength(2);
    for (const label of ["طلباتي", "حسابي", "إجراء جديد (قريبًا)"]) {
      expect(within(nav).getByRole("button", { name: label })).toBeDisabled();
    }
  });

  it("renders no identifier, contact, coordinate or staff data, even if a response carried some", async () => {
    const profile = familyProfile();
    const leaky = {
      family: { ...profile.family, id: 4242, national_id: "807766554", mobile: "0597766554", paper_form_no: "PAPER-7788", notes: "ملاحظة سرية" },
      residence: { ...profile.residence, address_text: "عنوان تفصيلي سري", latitude: "31.5012345", longitude: "34.4612345", source: "IMPORT", residence_type: "نوع سري" },
    } as unknown as FamilyProfile;
    renderHousehold(leaky);
    await info();

    for (const secret of ["4242", "807766554", "0597766554", "PAPER-7788", "ملاحظة سرية", "عنوان تفصيلي سري", "31.50", "34.46", "IMPORT", "نوع سري"]) {
      expect(document.body.textContent).not.toContain(secret);
    }
  });

  it("keeps the profile in memory only: nothing in browser storage", async () => {
    const { client } = renderHousehold();
    await info();

    expect(client.getQueryData(FAMILY_PROFILE_QUERY_KEY)).toEqual(familyProfile());
    const dump = browserStorageDump();
    for (const value of ["FAM-000123", "عائلة الاختبار", "بني سهيلا", "مواصي خانيونس", "سالم الاختبار", "profile"]) {
      expect(dump).not.toContain(value);
    }
  });
});
