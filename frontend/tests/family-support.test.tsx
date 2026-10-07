import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { FamilyGate } from "@/components/family/family-gate";
import { FamilyHousehold } from "@/components/family/household/family-household";
import { FamilySupport } from "@/components/family/support/family-support";
import { ApiError, apiClient } from "@/lib/api/client";
import type { FamilyDelivery, FamilyNeed } from "@/lib/api/family-household";
import { formatDateLong } from "@/lib/utils/date";
import { browserStorageDump, familyDelivery, familyGet, familyNeed, renderWithClient } from "./helpers";

// PWA-3B.7: /family/household/support «الاحتياجات والمساعدات» on GET
// /api/v1/family/household/needs and /assistance. Synthetic data only.

const router = { replace: vi.fn(), push: vi.fn() };
const location = { pathname: "/family/household/support" };
vi.mock("next/navigation", () => ({ useRouter: () => router, usePathname: () => location.pathname }));
vi.mock("next/link", () => ({
  default: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...props}>
      {children}
    </a>
  ),
}));

const NEEDS = "/api/v1/family/household/needs";
const ASSISTANCE = "/api/v1/family/household/assistance";

type Answer = unknown | Error | (() => Promise<unknown>);

function renderSupport({ needs = [] as Answer, assistance = [] as Answer } = {}) {
  const get = vi.spyOn(apiClient, "get").mockImplementation(familyGet({ needs, assistance }));
  const result = renderWithClient(
    <FamilyGate>
      <FamilySupport />
    </FamilyGate>
  );

  return { get, ...result };
}

// The sections render once the Family gate has answered.
const needsBox = async () => (await screen.findByRole("heading", { name: "الاحتياجات المسجّلة" })).closest("section") as HTMLElement;
const assistanceBox = async () => (await screen.findByRole("heading", { name: "المساعدات المستلمة" })).closest("section") as HTMLElement;
const field = (scope: HTMLElement, name: string) => scope.querySelector(`[data-field="${name}"] dd`);

const SON = { member_ref: "a".repeat(64), full_name: "ابن الاختبار", available: true };
const FORMER = { member_ref: null, full_name: "فرد سابق", available: true };
const GONE = { member_ref: null, full_name: null, available: false };

beforeEach(() => {
  vi.restoreAllMocks();
  router.replace.mockReset();
  location.pathname = "/family/household/support";
  window.localStorage.clear();
  window.sessionStorage.clear();
});

describe("the page", () => {
  it("shows the title, the way back to «أسرتي», the registered-only note and both sections, from two parameterless reads", async () => {
    const { get } = renderSupport();

    expect(await screen.findByRole("heading", { level: 1, name: "الاحتياجات والمساعدات" })).toBeInTheDocument();
    expect(document.querySelector("[data-support-back]")).toHaveAttribute("href", "/family/household");
    expect(screen.getByText("يعرض هذا القسم ما هو مسجّل في Famboook فقط.")).toBeInTheDocument();
    expect(await screen.findByRole("heading", { name: "الاحتياجات المسجّلة" })).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: "المساعدات المستلمة" })).toBeInTheDocument();
    const paths = get.mock.calls.map(([path]) => String(path));
    expect(paths).toContain(NEEDS);
    expect(paths).toContain(ASSISTANCE);
    expect(paths.join(" ")).not.toMatch(/\?|family_id|person_id|member_ref/);
  });

  it("keeps «أسرتي» the current navigation entry and adds no new one", async () => {
    renderSupport();

    const nav = await screen.findByRole("navigation", { name: "التنقل الرئيسي" });
    expect(within(nav).getByRole("link", { name: "أسرتي" })).toHaveAttribute("aria-current", "page");
    expect(within(nav).getAllByRole("link").map((link) => link.textContent)).toEqual(["الرئيسية", "أسرتي", "حسابي"]);
  });

  it("is reached from an entry card on «أسرتي», without counts", async () => {
    location.pathname = "/family/household";
    vi.spyOn(apiClient, "get").mockImplementation(familyGet());
    renderWithClient(
      <FamilyGate>
        <FamilyHousehold />
      </FamilyGate>
    );

    const entry = (await screen.findByRole("link", { name: /عرض الاحتياجات والمساعدات/ })).closest("[data-household-support]") as HTMLElement;
    expect(within(entry).getByRole("link", { name: /عرض الاحتياجات والمساعدات/ })).toHaveAttribute("href", "/family/household/support");
    expect(entry.textContent).not.toMatch(/\d/);
  });
});

describe("«الاحتياجات المسجّلة»", () => {
  const NEED_LIST: FamilyNeed[] = [
    familyNeed({ title: "سلة غذائية", quantity: "2", unit: "سلة" }),
    familyNeed({ title: "دواء ضغط", category: { code: "MEDICATION", name: "الأدوية" }, quantity: null, unit: null, person: SON }),
    familyNeed({ title: "بطانيات", status: "FULFILLED", resolved_at: "2026-09-20", person: FORMER }),
    familyNeed({ title: "خيمة", status: "CLOSED", resolved_at: "2026-09-21", person: GONE }),
  ];

  it("groups OPEN under «قائمة» and FULFILLED / CLOSED under «منتهية» with the approved labels", async () => {
    renderSupport({ needs: NEED_LIST });

    const open = (await within(await needsBox()).findByRole("heading", { name: "قائمة" })).closest("section") as HTMLElement;
    const resolved = within(await needsBox()).getByRole("heading", { name: "منتهية" }).closest("section") as HTMLElement;
    expect(within(open).getAllByRole("listitem").map((li) => li.querySelector("p")?.textContent)).toEqual(["سلة غذائية", "دواء ضغط"]);
    expect(within(resolved).getAllByRole("listitem").map((li) => li.querySelector("p")?.textContent)).toEqual(["بطانيات", "خيمة"]);
    expect(within(open).getAllByText("قائم")).toHaveLength(2);
    expect(within(resolved).getByText("تمت تلبيته")).toBeInTheDocument();
    expect(within(resolved).getByText("مغلق")).toBeInTheDocument();
  });

  it("shows category, quantity with unit, whom it concerns and the dates — «غير مسجّل» for a null quantity", async () => {
    renderSupport({ needs: NEED_LIST });
    await within(await needsBox()).findByText("سلة غذائية");

    const needs = await needsBox();
    const card = (title: string) => within(needs).getByText(title).closest("li") as HTMLElement;
    expect(field(card("سلة غذائية"), "category")).toHaveTextContent("الغذاء");
    expect(field(card("سلة غذائية"), "quantity")).toHaveTextContent("2 سلة");
    expect(field(card("سلة غذائية"), "person")).toHaveTextContent("الأسرة");
    expect(field(card("سلة غذائية"), "created_at")).toHaveTextContent(formatDateLong("2026-09-30"));
    expect(card("سلة غذائية").querySelector('[data-field="resolved_at"]')).toBeNull();
    expect(field(card("دواء ضغط"), "quantity")).toHaveTextContent("غير مسجّل");
    expect(field(card("دواء ضغط"), "person")).toHaveTextContent("ابن الاختبار");
  });

  it("dates a FULFILLED need «تاريخ التلبية» and a CLOSED one «تاريخ الإغلاق»; a former member keeps their name, a removed one is unavailable", async () => {
    renderSupport({ needs: NEED_LIST });
    await within(await needsBox()).findByText("بطانيات");

    const fulfilled = within(await needsBox()).getByText("بطانيات").closest("li") as HTMLElement;
    const closed = within(await needsBox()).getByText("خيمة").closest("li") as HTMLElement;
    expect(within(fulfilled).getByText("تاريخ التلبية")).toBeInTheDocument();
    expect(field(fulfilled, "resolved_at")).toHaveTextContent(formatDateLong("2026-09-20"));
    expect(within(closed).getByText("تاريخ الإغلاق")).toBeInTheDocument();
    expect(field(fulfilled, "person")).toHaveTextContent("فرد سابق");
    expect(field(closed, "person")).toHaveTextContent("بيانات هذا الفرد غير متاحة حاليًا");
  });

  it("says that a need's status is separate from received assistance", async () => {
    renderSupport({ needs: NEED_LIST });

    expect(await within(await needsBox()).findByText("تُحدَّث حالة الاحتياج من إدارة السجل، وهي منفصلة عن سجل المساعدات المستلمة.")).toBeInTheDocument();
  });

  it("says only that nothing is registered when there are no needs", async () => {
    renderSupport({ needs: [] });

    expect(await within(await needsBox()).findByText("لا توجد احتياجات مسجّلة.")).toBeInTheDocument();
    for (const claim of ["لا تحتاج", "لا توجد احتياجات لدى الأسرة"]) {
      expect(document.body).not.toHaveTextContent(claim);
    }
  });
});

describe("«المساعدات المستلمة»", () => {
  const DELIVERIES: FamilyDelivery[] = [
    familyDelivery({
      delivered_at: "2026-09-15",
      assistance: {
        title: "سلة رمضان", category: { code: "FOOD", name: "الغذاء" }, type: "IN_KIND", provider_name: "جهة تجريبية",
        items: [
          { item_name: "أرز", quantity: "5", unit: "كغ", unit_value: "4.5", currency: "ILS" },
          { item_name: "زيت", quantity: null, unit: null, unit_value: null, currency: null },
        ],
      },
      receipt_mode: "DELEGATE",
      recipient: { full_name: "ابنة الاختبار", available: true },
    }),
    familyDelivery({
      delivered_at: "2026-08-01",
      assistance: { title: "منحة نقدية", category: { code: "CASH", name: "المساعدة النقدية" }, type: "CASH", provider_name: "جهة مانحة", items: [] },
      beneficiary: SON,
      recipient: { full_name: "ابن الاختبار", available: true },
    }),
    familyDelivery({
      assistance: { title: "جلسة دعم", category: { code: "PROTECTION", name: "الحماية" }, type: "SERVICE", provider_name: "مؤسسة", items: [] },
      beneficiary: GONE,
      recipient: { full_name: null, available: false },
    }),
  ];

  it("lists the deliveries in the server's order (newest first) with title, provider, date, category and type", async () => {
    renderSupport({ assistance: DELIVERIES });

    await within(await assistanceBox()).findByText("سلة رمضان");
    const titles = Array.from((await assistanceBox()).querySelectorAll("[data-delivery] > p")).map((p) => p.textContent);
    expect(titles).toEqual(["سلة رمضان", "منحة نقدية", "جلسة دعم"]);
    const first = (await assistanceBox()).querySelector("[data-delivery]") as HTMLElement;
    expect(field(first, "provider_name")).toHaveTextContent("جهة تجريبية");
    expect(field(first, "delivered_at")).toHaveTextContent(formatDateLong("2026-09-15"));
    expect(within(first).getByText("الغذاء")).toBeInTheDocument();
    expect(within(first).getByText("عينية")).toBeInTheDocument();
    expect(within(await assistanceBox()).getByText("نقدية")).toBeInTheDocument();
    expect(within(await assistanceBox()).getByText("خدمة")).toBeInTheDocument();
  });

  it("shows whom it was for and who received it, with the receipt mode", async () => {
    renderSupport({ assistance: DELIVERIES });
    await within(await assistanceBox()).findByText("سلة رمضان");

    const [family, person, gone] = Array.from((await assistanceBox()).querySelectorAll("[data-delivery]")) as HTMLElement[];
    expect(field(family, "beneficiary")).toHaveTextContent("الأسرة");
    expect(field(family, "recipient")).toHaveTextContent("ابنة الاختبار (بالنيابة)");
    expect(field(person, "beneficiary")).toHaveTextContent("ابن الاختبار");
    expect(field(person, "recipient")).toHaveTextContent("ابن الاختبار (شخصيًا)");
    expect(field(gone, "beneficiary")).toHaveTextContent("بيانات هذا الفرد غير متاحة حاليًا");
    expect(field(gone, "recipient")).toHaveTextContent("بيانات هذا الفرد غير متاحة حاليًا");
  });

  it("shows each item as «{item} — {quantity} {unit}», the unit value with its currency, «غير مسجّل» for a null quantity and a note without items", async () => {
    renderSupport({ assistance: DELIVERIES });
    await within(await assistanceBox()).findByText("سلة رمضان");

    const [first, second] = Array.from((await assistanceBox()).querySelectorAll("[data-delivery]")) as HTMLElement[];
    const items = Array.from(first.querySelectorAll("[data-assistance-item]")) as HTMLElement[];
    expect(items[0]).toHaveTextContent("أرز — 5 كغ");
    expect(items[0]).toHaveTextContent("قيمة الوحدة: 4.5 شيكل");
    expect(items[1]).toHaveTextContent("زيت — غير مسجّل");
    expect(items[1].querySelector("[data-assistance-item-value]")).toBeNull();
    expect(within(second).getByText("لا توجد تفاصيل عناصر مسجّلة")).toBeInTheDocument();
  });

  it.each([
    ["USD", "دولار"],
    ["JOD", "دينار"],
    ["EUR", "يورو"],
  ] as const)("labels %s as «%s»", async (currency, label) => {
    renderSupport({
      assistance: [familyDelivery({
        assistance: { ...familyDelivery().assistance, items: [{ item_name: "مبلغ", quantity: "1", unit: null, unit_value: "100", currency }] },
      })],
    });

    const box = await assistanceBox();
    await within(box).findByText("سلة رمضان");
    expect(box.querySelector("[data-assistance-item-value]")).toHaveTextContent(`قيمة الوحدة: 100 ${label}`);
  });

  it("says only that nothing is registered when there are no deliveries", async () => {
    renderSupport({ assistance: [] });

    expect(await within(await assistanceBox()).findByText("لا توجد مساعدات مستلمة مسجّلة.")).toBeInTheDocument();
    for (const claim of ["لم تستلم", "لم تحصل"]) {
      expect(document.body).not.toHaveTextContent(claim);
    }
  });
});

describe("independent loading and errors", () => {
  it("shows each section's own skeleton while loading", async () => {
    renderSupport({ needs: () => new Promise<never>(() => {}), assistance: [familyDelivery()] });

    expect(await within(await assistanceBox()).findByText("سلة رمضان")).toBeInTheDocument();
    expect((await needsBox()).querySelector("[data-section-loading]")).not.toBeNull();
  });

  it("keeps the needs when the assistance fails, and retries only the failed section", async () => {
    let fail = true;
    renderSupport({
      needs: [familyNeed()],
      assistance: () => (fail ? Promise.reject(new ApiError(500, { message: "x" })) : Promise.resolve({ data: { deliveries: [familyDelivery()] } })),
    });

    expect(await within(await assistanceBox()).findByText("تعذّر تحميل المساعدات المستلمة")).toBeInTheDocument();
    expect(within(await needsBox()).getByText("سلة غذائية")).toBeInTheDocument();
    fail = false;
    await userEvent.click(within(await assistanceBox()).getByRole("button", { name: "إعادة المحاولة" }));
    expect(await within(await assistanceBox()).findByText("سلة رمضان")).toBeInTheDocument();
  });

  it("keeps the assistance when the needs fail", async () => {
    renderSupport({ needs: new ApiError(500, { message: "x" }), assistance: [familyDelivery()] });

    expect(await within(await needsBox()).findByText("تعذّر تحميل الاحتياجات")).toBeInTheDocument();
    expect(within(await assistanceBox()).getByText("سلة رمضان")).toBeInTheDocument();
  });
});

describe("read-only and privacy", () => {
  it("offers no add, edit, resolve, reverse or request control", async () => {
    renderSupport({ needs: [familyNeed()], assistance: [familyDelivery()] });
    await within(await assistanceBox()).findByText("سلة رمضان");

    for (const section of [(await needsBox()), (await assistanceBox())]) {
      expect(within(section).queryAllByRole("button")).toHaveLength(0);
      expect(within(section).queryAllByRole("link")).toHaveLength(0);
    }
    for (const text of [/إضافة/, /تعديل/, /تلبية الاحتياج/, /إغلاق الاحتياج/, /عكس/, /طلب تصحيح/, /تقديم طلب/]) {
      expect(screen.queryByRole("button", { name: text })).not.toBeInTheDocument();
    }
  });

  it("never renders an internal field, even if a response carried one", async () => {
    const leakyNeed = { ...familyNeed(), priority: "URGENT", description: "وصف داخلي سري", closure_reason: "سبب داخلي", uuid: "11111111-aaaa", person_code: "PER-000999" } as unknown as FamilyNeed;
    const leakyDelivery = {
      ...familyDelivery(), notes: "ملاحظة تسليم داخلية", reversal_reason: "سبب عكس", targeting_criteria: { x: "معيار استهداف" }, status: "APPROVED", uuid: "22222222-bbbb",
    } as unknown as FamilyDelivery;
    renderSupport({ needs: [leakyNeed], assistance: [leakyDelivery] });
    await within(await assistanceBox()).findByText("سلة رمضان");

    const html = document.body.innerHTML;
    for (const value of ["URGENT", "وصف داخلي سري", "سبب داخلي", "11111111", "PER-000999", "ملاحظة تسليم داخلية", "سبب عكس", "معيار استهداف", "APPROVED", "22222222"]) {
      expect(html).not.toContain(value);
    }
  });

  it("keeps nothing in browser storage", async () => {
    renderSupport({ needs: [familyNeed()], assistance: [familyDelivery()] });
    await within(await assistanceBox()).findByText("سلة رمضان");

    expect(browserStorageDump()).not.toMatch(/سلة|أرز|جهة|needs|assistance/i);
  });
});
