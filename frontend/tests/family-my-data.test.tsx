import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { MyData } from "@/components/family/account/my-data";
import { FamilyGate } from "@/components/family/family-gate";
import { ApiError, apiClient } from "@/lib/api/client";
import type { FamilySelf } from "@/lib/api/family-self";
import { formatDateLong } from "@/lib/utils/date";
import { browserStorageDump, familyGet, familySelf, renderWithClient } from "./helpers";

// PWA-3B.1: /family/account/me «بياناتي الشخصية» on GET /api/v1/family/self.
// Masked values only, no reveal. Synthetic data only.

const router = { replace: vi.fn(), push: vi.fn() };
vi.mock("next/navigation", () => ({ useRouter: () => router, usePathname: () => "/family/account/me" }));
vi.mock("next/link", () => ({
  default: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...props}>
      {children}
    </a>
  ),
}));

// Full values that must never reach the page (the payload never has them).
const FULL_VALUES = ["123456789", "0591234567", "0567654321"];

function renderMyData(self: FamilySelf | Error | (() => Promise<unknown>) = familySelf()) {
  const get = vi.spyOn(apiClient, "get").mockImplementation(familyGet({ self }));
  const result = renderWithClient(
    <FamilyGate>
      <MyData />
    </FamilyGate>
  );

  return { get, ...result };
}

const section = (id: string) => document.querySelector(`[data-my-data-section="${id}"]`) as HTMLElement;
const field = (name: string) => document.querySelector(`[data-field="${name}"] dd`);

beforeEach(() => {
  vi.restoreAllMocks();
  window.localStorage.clear();
  window.sessionStorage.clear();
});

describe("the screen", () => {
  it("renders the three approved sections from GET /api/v1/family/self, which sends no identifier", async () => {
    const { get } = renderMyData();

    expect(await screen.findByRole("heading", { level: 1, name: "بياناتي الشخصية" })).toBeInTheDocument();
    expect(await screen.findByRole("heading", { name: "البيانات الأساسية" })).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: "بيانات الاتصال" })).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: "بيانات العضوية" })).toBeInTheDocument();
    expect(get).toHaveBeenCalledWith("/api/v1/family/self");
    expect(get.mock.calls.every(([path]) => !/\d|PER-|FAM-/.test(String(path).replace("/api/v1", "")))).toBe(true);
  });

  it("shows every approved value", async () => {
    renderMyData();
    await screen.findByRole("heading", { name: "البيانات الأساسية" });

    expect(field("full_name")).toHaveTextContent("سالم أحمد الاختبار");
    expect(field("gender")).toHaveTextContent("ذكر");
    expect(field("birth_date")).toHaveTextContent(formatDateLong("1980-01-15"));
    expect(field("age")).toHaveTextContent(/سنة/);
    expect(field("marital_status")).toHaveTextContent("متزوج/ة");
    expect(field("alternate_mobile_owner_relation")).toHaveTextContent("أخ");
    expect(within(section("membership")).getByText("رب الأسرة", { selector: "dd *" })).toBeInTheDocument();
    expect(field("relationship")).toHaveTextContent("رب الأسرة");
    expect(field("membership_started_at")).toHaveTextContent(formatDateLong("2001-03-04"));
  });
});

describe("sensitive values", () => {
  it("shows the National ID, mobile and alternate mobile masked, exactly as received", async () => {
    renderMyData();
    await screen.findByRole("heading", { name: "البيانات الأساسية" });

    expect(field("national_id")).toHaveTextContent("*****6789");
    expect(field("mobile")).toHaveTextContent("05*****567");
    expect(field("alternate_mobile")).toHaveTextContent("05*****321");
  });

  it("has no full value anywhere, no reveal control and nothing in browser storage", async () => {
    const { container } = renderMyData();
    await screen.findByRole("heading", { name: "البيانات الأساسية" });

    for (const full of FULL_VALUES) {
      expect(container.innerHTML).not.toContain(full);
    }
    // The screen itself (the shell's navigation is not part of it).
    const page = container.querySelector("[data-my-data]") as HTMLElement;
    expect(within(page).queryByRole("button")).not.toBeInTheDocument();
    expect(page.querySelector("[aria-pressed]")).toBeNull();
    expect(screen.queryByRole("button", { name: /إظهار|إخفاء|show|hide/i })).not.toBeInTheDocument();
    // PWA-3B.1 is read-only: no edit or request link either.
    expect(within(page).queryByRole("link")).not.toBeInTheDocument();
    expect(browserStorageDump()).not.toMatch(/\*{5}|سالم/);
  });
});

describe("absent and unknown values", () => {
  it("shows «غير مسجّل» for null values and omits rows that do not apply", async () => {
    renderMyData(
      familySelf({
        national_id_masked: null, gender: null, birth_date: null, mobile_masked: null, alternate_mobile_masked: null,
        alternate_mobile_owner_relation: null, relationship: null, membership_started_at: null,
      })
    );
    await screen.findByRole("heading", { name: "البيانات الأساسية" });

    for (const name of ["national_id", "gender", "birth_date", "mobile", "alternate_mobile", "relationship", "membership_started_at"]) {
      expect(field(name), name).toHaveTextContent("غير مسجّل");
    }
    // A null birth date is «غير مسجّل», never «غير معروف».
    expect(field("birth_date")).not.toHaveTextContent("غير معروف");
    // No age without a birth date; no owner relation without an alternate number.
    expect(document.querySelector('[data-field="age"]')).toBeNull();
    expect(document.querySelector('[data-field="alternate_mobile_owner_relation"]')).toBeNull();
  });

  it("shows marital status UNKNOWN as «غير معروف»", async () => {
    renderMyData(familySelf({ marital_status: "UNKNOWN" }));
    await screen.findByRole("heading", { name: "البيانات الأساسية" });

    expect(field("marital_status")).toHaveTextContent("غير معروف");
    expect(field("marital_status")).not.toHaveTextContent("غير مسجّل");
  });

  it("keeps an alternate number's missing owner relation as «غير مسجّل»", async () => {
    renderMyData(familySelf({ alternate_mobile_owner_relation: null }));
    await screen.findByRole("heading", { name: "بيانات الاتصال" });

    expect(field("alternate_mobile_owner_relation")).toHaveTextContent("غير مسجّل");
  });
});

describe("loading and errors", () => {
  it("shows a loading state while the data is on its way", async () => {
    renderMyData(() => new Promise(() => {}));

    expect(await screen.findByText("جارٍ تحميل بياناتك الشخصية")).toBeInTheDocument();
    expect(document.querySelector("[data-my-data-loading]")).not.toBeNull();
  });

  it("shows an inline error with a retry for a server failure", async () => {
    const { get } = renderMyData(new ApiError(500, { message: "x" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("تعذّر تحميل بياناتك الشخصية");
    get.mockImplementation(familyGet());
    await userEvent.click(screen.getByRole("button", { name: "إعادة المحاولة" }));

    expect(await screen.findByRole("heading", { name: "البيانات الأساسية" })).toBeInTheDocument();
  });

  it("leaves a 403 to the access flow: no data and no inline error", async () => {
    renderMyData(new ApiError(403, { message: "x", code: "FAMILY_CONTEXT_UNAVAILABLE" }));

    await screen.findByRole("heading", { level: 1, name: "بياناتي الشخصية" }).catch(() => null);
    expect(screen.queryByRole("heading", { name: "البيانات الأساسية" })).not.toBeInTheDocument();
    expect(document.querySelector("[data-my-data-error]")).toBeNull();
  });
});
