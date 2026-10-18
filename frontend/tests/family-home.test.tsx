import { act, render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { FamilyGate } from "@/components/family/family-gate";
import { FamilyHome } from "@/components/family/family-home";
import { SUMMARY_CAPTION } from "@/components/family/home/household-cards";
import { FIRST_SIGN_IN_TEXT, UNKNOWN_SIGN_IN_TEXT, WelcomeHeader, formatLocalClock, formatLocalDate, formatLocalDateTime } from "@/components/family/home/welcome-header";
import { renderToString } from "react-dom/server";
import { ApiError, apiClient } from "@/lib/api/client";
import { FAMILY_HOUSEHOLD_QUERY_KEY } from "@/lib/api/family-household";
import { browserStorageDump, coordinatorContext, coordinatorSummary, familyGet, familyHousehold, familyUser, renderWithClient } from "./helpers";

// PWA-3A Step 2: the Family Portal home on GET /api/v1/family/household.
// Synthetic data only.

const router = { replace: vi.fn(), push: vi.fn() };
vi.mock("next/navigation", () => ({ useRouter: () => router, usePathname: () => "/family" }));
vi.mock("next/link", () => ({
  default: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...props}>
      {children}
    </a>
  ),
}));

const HOUSEHOLD = "/api/v1/family/household";

function renderHome() {
  return renderWithClient(
    <FamilyGate>
      <FamilyHome />
    </FamilyGate>
  );
}

const summary = () => screen.findByRole("region", { name: "ملخص الأسرة" });
const stat = (container: HTMLElement, name: "declared" | "registered") =>
  container.querySelector(`[data-stat="${name}"] dd`) as HTMLElement;

beforeEach(() => {
  router.replace.mockReset();
  window.localStorage.clear();
  window.sessionStorage.clear();
});

describe("the household summary", () => {
  it("reads GET /api/v1/family/household with no family, person or membership identifier", async () => {
    const get = vi.spyOn(apiClient, "get").mockImplementation(familyGet());

    renderHome();
    await summary();

    const paths = get.mock.calls.map(([path]) => String(path));
    expect(paths).toContain(HOUSEHOLD);
    expect(paths.filter((p) => p.startsWith(HOUSEHOLD))).toEqual([HOUSEHOLD]);
    expect(paths.join(" ")).not.toMatch(/family_id|person_id|membership_id|\?/);
  });

  it("shows the declared size and the registered count as two separate facts", async () => {
    vi.spyOn(apiClient, "get").mockImplementation(familyGet({ household: familyHousehold({ declared_household_size: 7, registered_member_count: 2 }) }));

    renderHome();
    const card = await summary();

    expect(within(card).getByText("عدد أفراد الأسرة حسب الإقرار")).toBeInTheDocument();
    expect(within(card).getByText("الأفراد المسجّلون بالتفصيل")).toBeInTheDocument();
    expect(stat(card, "declared")).toHaveTextContent(/^7$/);
    expect(stat(card, "registered")).toHaveTextContent(/^2$/);
    expect(stat(card, "declared")).toHaveClass("tabular-nums");
    expect(within(card).getByText(SUMMARY_CAPTION)).toBeInTheDocument();
  });

  it("never calculates a difference or speaks of missing members", async () => {
    vi.spyOn(apiClient, "get").mockImplementation(familyGet({ household: familyHousehold({ declared_household_size: 7, registered_member_count: 2 }) }));

    renderHome();
    await summary();

    // The live clock shows the real time (it may contain a 5): it is not a household figure.
    const page = document.body.cloneNode(true) as HTMLElement;
    page.querySelector('[data-welcome="now"]')?.remove();
    const text = page.textContent ?? "";
    expect(text).not.toMatch(/(^|\D)5(\D|$)/); // 7 − 2
    expect(text).not.toMatch(/غير مسجل|غير المسجل|ناقص|مفقود|متبقي|المتبقين|الفرق/);
  });

  it.each([
    ["not declared", null, "غير مُعلن"],
    ["a declared zero", 0, "0"],
  ])("keeps %s distinct", async (_label, declared, shown) => {
    vi.spyOn(apiClient, "get").mockImplementation(familyGet({ household: familyHousehold({ declared_household_size: declared }) }));

    renderHome();
    const card = await summary();

    expect(stat(card, "declared").textContent).toBe(shown);
  });

  it("replaces the old activated card and the generic coming-soon list", async () => {
    vi.spyOn(apiClient, "get").mockImplementation(familyGet());

    renderHome();
    await summary();

    expect(screen.queryByText("حسابك مفعّل")).not.toBeInTheDocument();
    expect(screen.queryByText("قريبًا في البوابة")).not.toBeInTheDocument();
    expect(screen.queryByText(/اكتمال|إكمال الملف|%/)).not.toBeInTheDocument();
    expect(screen.queryByText(/طلباتك|طلب مفتوح|طلبات مفتوحة/)).not.toBeInTheDocument();
  });

  it("links the members entry to /family/members, without a coming-soon badge", async () => {
    vi.spyOn(apiClient, "get").mockImplementation(familyGet());

    renderHome();
    const card = await summary();

    const entry = within(card).getByRole("link", { name: "عرض أفراد الأسرة" });
    expect(entry).toHaveAttribute("href", "/family/members");
    expect(entry).not.toHaveTextContent("قريبًا");
    expect(within(card).queryByText("قريبًا")).not.toBeInTheDocument();
    expect(within(card).queryByRole("button")).not.toBeInTheDocument();
  });
});

describe("the family identity card", () => {
  it("shows the clan, the branch and the code (left-to-right)", async () => {
    vi.spyOn(apiClient, "get").mockImplementation(familyGet());

    renderHome();
    const card = await screen.findByRole("region", { name: "الأسرة" });

    expect(within(card).getByText("عائلة الاختبار")).toBeInTheDocument();
    expect(within(card).getByText("فرع الاختبار")).toBeInTheDocument();
    expect(within(card).getByText("FAM-000123")).toHaveAttribute("dir", "ltr");
  });

  it("leaves the branch out cleanly when there is none", async () => {
    vi.spyOn(apiClient, "get").mockImplementation(familyGet({ household: familyHousehold({ branch_name: null }) }));

    const { container } = renderHome();
    await screen.findByRole("region", { name: "الأسرة" });

    expect(container.querySelector("[data-family-branch]")).toBeNull();
    expect(screen.queryByText(/الفرع/)).not.toBeInTheDocument();
    expect(screen.queryByText("null")).not.toBeInTheDocument();
  });

  it("keeps one page heading: the greeting", async () => {
    vi.spyOn(apiClient, "get").mockImplementation(familyGet());

    renderHome();
    await summary();

    expect(screen.getAllByRole("heading", { level: 1 }).map((h) => h.textContent)).toEqual(["سالم الاختبار"]);
    for (const name of ["الأسرة", "ملخص الأسرة", "الوصول السريع", "الخدمات"]) {
      expect(screen.getByRole("heading", { level: 2, name })).toBeInTheDocument();
    }
  });
});

describe("Coordinator Space and the quick actions", () => {
  it("shows the coordinator section only when /me says the space is open, below and apart from the household", async () => {
    const get = vi.spyOn(apiClient, "get").mockImplementation(
      familyGet({ user: familyUser({ roles: ["FAMILY_USER", "COORDINATOR"], coordinator: true, coordinator_space: true }) })
    );

    renderHome();
    const card = await summary();
    const section = await screen.findByRole("region", { name: "مساحة التنسيق" });

    expect(within(section).getByRole("link", { name: /استعراض الأسر في النطاق/ })).toHaveAttribute("href", "/family/coordinator");
    expect(within(card).queryByText(/مساحة التنسيق/)).not.toBeInTheDocument();
    expect(stat(card, "registered")).toHaveTextContent(/^2$/);
    // Family content first, the Coordinator section last.
    expect(card.compareDocumentPosition(section) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(get.mock.calls.map(([path]) => String(path))).toContain("/api/v1/family/coordinator/summary");
  });

  it("has no coordinator section, and never asks for coordinator data, for an ordinary household head", async () => {
    const get = vi.spyOn(apiClient, "get").mockImplementation(familyGet());

    renderHome();
    await summary();

    expect(screen.queryByText("مساحة التنسيق")).not.toBeInTheDocument();
    expect(get.mock.calls.map(([path]) => String(path)).some((path) => path.includes("/coordinator/"))).toBe(false);
  });

  it("keeps the bottom navigation: الرئيسية (current), أسرتي, طلباتي and حسابي are links, «+» disabled while closed", async () => {
    vi.spyOn(apiClient, "get").mockImplementation(familyGet());

    renderHome();
    await summary();

    const nav = screen.getByRole("navigation", { name: "التنقل الرئيسي" });
    expect(within(nav).getAllByRole("link").map((l) => l.textContent)).toEqual(["الرئيسية", "أسرتي", "طلباتي", "حسابي"]);
    expect(within(nav).getByRole("link", { name: "الرئيسية" })).toHaveAttribute("aria-current", "page");
    // docs/11 FP-ADR-074: «+» follows server type discovery (closed by default).
    expect(await within(nav).findByRole("button", { name: "إجراء جديد (قريبًا)" })).toBeDisabled();
  });

  it("still offers the install suggestion", async () => {
    vi.spyOn(apiClient, "get").mockImplementation(familyGet());

    const { container } = renderHome();
    await summary();

    expect(container.querySelector("[data-install-famboook]")).not.toBeNull();
  });
});

describe("the order of the home (FB-FAMILY-DASHBOARD-01)", () => {
  it("greets first, then the family, the shortcuts, the services and the coordinator section", async () => {
    vi.spyOn(apiClient, "get").mockImplementation(
      familyGet({ user: familyUser({ roles: ["FAMILY_USER", "COORDINATOR"], coordinator: true, coordinator_space: true }) })
    );

    const { container } = renderHome();
    await summary();
    await screen.findByRole("region", { name: "مساحة التنسيق" });

    const order = ["[data-welcome-header]", "[data-family-card]", "[data-household-summary]", "[data-home-shortcuts]", "[data-home-services-section]", "[data-coordinator-dashboard]"].map(
      (selector) => container.querySelector(selector) as HTMLElement
    );
    order.forEach((element, i) => {
      expect(element, String(i)).not.toBeNull();
      if (i > 0) expect(order[i - 1].compareDocumentPosition(element) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    });
  });

  it("links the four shortcuts to the existing portal routes", async () => {
    vi.spyOn(apiClient, "get").mockImplementation(familyGet());

    renderHome();
    await summary();

    const nav = screen.getByRole("navigation", { name: "الوصول السريع" });
    expect(within(nav).getAllByRole("link").map((l) => l.getAttribute("href"))).toEqual(["/family/card", "/family/household", "/family/requests", "/family/account"]);
  });
});

describe("the welcome header", () => {
  afterEach(() => vi.useRealTimers());

  const clock = () => document.querySelector("[data-welcome-clock]") as HTMLElement;
  const nowTime = () => document.querySelector('[data-welcome="now"] time') as HTMLElement;

  it("shows today's date and a live 12-hour clock with seconds, updated every second", () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date("2026-10-18T09:05:30.400Z"));

    render(<WelcomeHeader user={familyUser()} />);
    expect(nowTime()).toHaveAttribute("dateTime", "2026-10-18T09:05:30.400Z");
    expect(nowTime()).toHaveTextContent(formatLocalDate(new Date("2026-10-18T09:05:30.400Z")));
    expect(clock()).toHaveTextContent(formatLocalClock(new Date("2026-10-18T09:05:30.400Z")));
    expect(clock().textContent).toMatch(/^\d{1,2}:\d{2}:\d{2} (ص|م)$/);
    expect(clock()).toHaveClass("tabular-nums");

    // Aligned to the next second boundary, then once per second.
    act(() => vi.advanceTimersByTime(600));
    expect(nowTime()).toHaveAttribute("dateTime", "2026-10-18T09:05:31.000Z");
    for (const second of ["32", "33", "34"]) {
      act(() => vi.advanceTimersByTime(1000));
      expect(nowTime()).toHaveAttribute("dateTime", `2026-10-18T09:05:${second}.000Z`);
    }
    expect(clock()).toHaveTextContent(formatLocalClock(new Date("2026-10-18T09:05:34Z")));
    // The clock is not a live region: screen readers are not interrupted every second.
    expect(document.querySelector("[aria-live]")).toBeNull();
  });

  it("clears its timers when unmounted", () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date("2026-10-18T09:05:30.400Z"));
    const before = vi.getTimerCount();

    const { unmount } = render(<WelcomeHeader user={familyUser()} />);
    act(() => vi.advanceTimersByTime(2_000));
    expect(vi.getTimerCount()).toBe(before + 1);
    unmount();
    expect(vi.getTimerCount()).toBe(before);
  });

  it("formats in the device's own time zone, 12-hour with the Arabic AM / PM", () => {
    const morning = new Date(2026, 9, 18, 8, 5, 7);
    const evening = new Date(2026, 9, 18, 20, 5, 7);
    // Local wall-clock hours whatever the runner's time zone.
    expect(formatLocalClock(morning)).toBe("8:05:07 ص");
    expect(formatLocalClock(evening)).toBe("8:05:07 م");
    expect(new Intl.DateTimeFormat().resolvedOptions().timeZone).toBe(Intl.DateTimeFormat().resolvedOptions().timeZone);
  });

  it("keeps the previous sign-in static while the clock runs", () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date("2026-10-18T09:05:30Z"));
    render(<WelcomeHeader user={familyUser({ previous_sign_in_at: "2026-10-17T18:42:00+00:00" })} />);
    const before = document.querySelector('[data-welcome="previous-sign-in"]')?.textContent;

    act(() => vi.advanceTimersByTime(5_000));
    expect(document.querySelector('[data-welcome="previous-sign-in"]')?.textContent).toBe(before);
    expect(before).toContain(formatLocalDateTime(new Date("2026-10-17T18:42:00Z")));
  });

  it("renders no clock on the server, so hydration cannot mismatch", () => {
    const html = renderToString(<WelcomeHeader user={familyUser()} />);

    expect(html).not.toContain("<time");
    expect(html).not.toContain("data-welcome-clock");
    expect(html).toContain("سالم الاختبار");
  });

  it("shows the previous successful sign-in in local time, from the server's UTC timestamp", () => {
    render(<WelcomeHeader user={familyUser({ previous_sign_in_at: "2026-10-17T18:42:00+00:00", first_sign_in: false })} />);

    const line = document.querySelector('[data-welcome="previous-sign-in"]') as HTMLElement;
    expect(line).toHaveTextContent("آخر دخول سابق:");
    expect(line.querySelector("time")).toHaveAttribute("dateTime", "2026-10-17T18:42:00+00:00");
    expect(line).toHaveTextContent(formatLocalDateTime(new Date("2026-10-17T18:42:00Z")));
    // Latin digits, as elsewhere in the portal.
    expect(line.textContent).toMatch(/2026/);
  });

  it("says first sign-in only when the server is certain, and unknown otherwise", () => {
    const { rerender } = render(<WelcomeHeader user={familyUser({ previous_sign_in_at: null, first_sign_in: true })} />);
    expect(screen.getByText(FIRST_SIGN_IN_TEXT)).toBeInTheDocument();

    rerender(<WelcomeHeader user={familyUser({ previous_sign_in_at: null, first_sign_in: false })} />);
    expect(screen.getByText(UNKNOWN_SIGN_IN_TEXT)).toBeInTheDocument();
    expect(screen.queryByText(FIRST_SIGN_IN_TEXT)).not.toBeInTheDocument();

    // An older server without the fields: unknown, never "first".
    rerender(<WelcomeHeader user={familyUser()} />);
    expect(screen.getByText(UNKNOWN_SIGN_IN_TEXT)).toBeInTheDocument();
  });
});

describe("the services (from the server only)", () => {
  it("links each available type to its form and shows a listed type without a form as unavailable", async () => {
    vi.spyOn(apiClient, "get").mockImplementation(
      familyGet({
        requestTypes: { data: [{ type: "RESIDENCE_UPDATE" }, { type: "CONTACT_UPDATE" }, { type: "BIRTH_REPORT" }], meta: { submission_enabled: true } },
      })
    );

    renderHome();
    const section = await screen.findByRole("region", { name: "الخدمات" });
    const available = await within(section).findByRole("list", { name: "خدمات متاحة" });

    expect(within(available).getAllByRole("link").map((l) => l.getAttribute("href"))).toEqual([
      "/family/requests/new/residence-update",
      "/family/requests/new/contact-update",
    ]);
    const unavailable = within(section).getByRole("list", { name: "خدمات غير متاحة حاليًا" });
    expect(within(unavailable).getByText("تسجيل مولود")).toBeInTheDocument();
    expect(within(unavailable).queryByRole("link")).not.toBeInTheDocument();
    expect(unavailable.querySelector('[data-home-service-unavailable="BIRTH_REPORT"]')).toHaveAttribute("aria-disabled", "true");
  });

  it("offers nothing clickable while submission is closed (the Production default)", async () => {
    vi.spyOn(apiClient, "get").mockImplementation(familyGet());

    renderHome();
    const section = await screen.findByRole("region", { name: "الخدمات" });

    expect(await within(section).findByText(/لا تتوفر خدمات للتقديم حاليًا/)).toBeInTheDocument();
    expect(within(section).queryByRole("link")).not.toBeInTheDocument();
    expect(screen.queryByText("قريبًا", { selector: "[data-home-services-section] *" })).not.toBeInTheDocument();
  });

  it("shows a services failure inline without hiding the family", async () => {
    vi.spyOn(apiClient, "get").mockImplementation(familyGet({ requestTypes: new ApiError(500, null) }));

    renderHome();
    const card = await summary();
    const section = screen.getByRole("region", { name: "الخدمات" });

    expect(await within(section).findByRole("alert")).toHaveTextContent("تعذّر تحميل الخدمات");
    expect(stat(card, "registered")).toHaveTextContent(/^2$/);
  });
});

describe("the coordinator section", () => {
  const COORDINATOR = familyUser({ roles: ["FAMILY_USER", "COORDINATOR"], coordinator: true, coordinator_space: true });
  const section = () => screen.findByRole("region", { name: "مساحة التنسيق" });

  it("shows the server's scope and four statistics, never a list length", async () => {
    vi.spyOn(apiClient, "get").mockImplementation(
      familyGet({
        user: COORDINATOR,
        coordinator: coordinatorContext({
          scopes: [
            { type: "CLAN", code: "C1", name: "عشيرة أولى", clan_name: "عشيرة أولى" },
            { type: "BRANCH_GROUP", code: "G1", name: "مجموعة الشمال", clan_name: "عشيرة ثانية" },
            { type: "BRANCH", code: "B1", name: "فرع الوادي", clan_name: "عشيرة ثالثة" },
          ],
        }),
        summary: coordinatorSummary({ family_count: 37, person_count: 151, requests: { in_progress: 4, completed: 9, closed: 2 } }),
      })
    );

    renderHome();
    const card = await section();
    await within(card).findByText("37");

    const scopes = card.querySelector("[data-coordinator-dashboard-scopes]") as HTMLElement;
    expect(within(scopes).getAllByRole("listitem").map((li) => li.textContent)).toEqual(["عشيرة: عشيرة أولى", "مجموعة فروع: مجموعة الشمال", "فرع: فرع الوادي"]);
    const value = (field: string) => card.querySelector(`[data-coordinator-stat="${field}"] dd`)?.textContent;
    expect([value("families"), value("persons"), value("in-progress"), value("completed")]).toEqual(["37", "151", "4", "9"]);
    // Closed requests are shown apart, never as completed.
    expect(card.querySelector("[data-coordinator-closed]")).toHaveTextContent("2");
    expect(within(card).getByText("طلبات مكتملة")).toBeInTheDocument();
  });

  it("offers the request actions only when the server enables them", async () => {
    vi.spyOn(apiClient, "get").mockImplementation(familyGet({ user: COORDINATOR }));

    renderHome();
    const card = await section();
    await within(card).findByText("12");

    expect(within(card).getByRole("link", { name: /طلبات التنسيق التي قدّمتها/ })).toHaveAttribute("href", "/family/coordinator/requests");
    for (const field of ["register-family", "add-family-member"]) {
      expect(card.querySelector(`[data-coordinator-action-unavailable="${field}"]`)).toHaveAttribute("aria-disabled", "true");
    }
    expect(within(card).queryByRole("link", { name: /طلب تسجيل أسرة جديدة/ })).not.toBeInTheDocument();
  });

  it("links register-family and add-member when the server lists them", async () => {
    vi.spyOn(apiClient, "get").mockImplementation(
      familyGet({
        user: COORDINATOR,
        coordinatorRequestTypes: { data: [{ type: "REGISTER_FAMILY" }, { type: "ADD_FAMILY_MEMBER" }], meta: { submission_enabled: true } },
      })
    );

    renderHome();
    const card = await section();

    expect(await within(card).findByRole("link", { name: /طلب تسجيل أسرة جديدة/ })).toHaveAttribute("href", "/family/coordinator/requests/new/register-family");
    expect(within(card).getByRole("link", { name: /طلب إضافة فرد إلى أسرة/ })).toHaveAttribute("href", "/family/coordinator/requests/new/add-family-member");
  });

  it("explains an empty scope with zeros", async () => {
    vi.spyOn(apiClient, "get").mockImplementation(
      familyGet({ user: COORDINATOR, summary: coordinatorSummary({ family_count: 0, person_count: 0, requests: { in_progress: 0, completed: 0, closed: 0 } }) })
    );

    renderHome();
    const card = await section();

    expect(await within(card).findByText("لا توجد أسر ضمن نطاقك حاليًا.")).toBeInTheDocument();
    expect(card.querySelector("[data-coordinator-closed]")).toBeNull();
  });

  it("keeps a coordinator failure inside its section, with a retry, and the family dashboard intact", async () => {
    let calls = 0;
    vi.spyOn(apiClient, "get").mockImplementation(
      familyGet({
        user: COORDINATOR,
        summary: async () => {
          calls++;
          if (calls === 1) throw new ApiError(500, null);
          return { data: coordinatorSummary() };
        },
      })
    );

    renderHome();
    const card = await summary();
    const coordinator = await section();

    expect(await within(coordinator).findByRole("alert")).toHaveTextContent("تعذّر تحميل بيانات التنسيق");
    expect(stat(card, "registered")).toHaveTextContent(/^2$/);
    await userEvent.click(within(coordinator).getByRole("button", { name: "إعادة المحاولة" }));
    expect(await within(coordinator).findByText("48")).toBeInTheDocument();
  });

  it("shows a loading state while the coordinator figures arrive", async () => {
    vi.spyOn(apiClient, "get").mockImplementation(familyGet({ user: COORDINATOR, summary: () => new Promise(() => undefined) }));

    renderHome();
    const card = await section();

    expect(card).toHaveAttribute("aria-busy", "true");
    expect(card.querySelector("[data-coordinator-dashboard-loading]")).not.toBeNull();
  });
});

describe("loading, errors and access", () => {
  it("shows a busy skeleton while the household loads", async () => {
    let release: (value: unknown) => void = () => undefined;
    vi.spyOn(apiClient, "get").mockImplementation(familyGet({ household: () => new Promise((resolve) => (release = resolve)) }));

    const { container } = renderHome();

    expect(await screen.findByRole("status")).toHaveTextContent("جارٍ تحميل بيانات الأسرة");
    expect(container.querySelector("[data-household]")).toHaveAttribute("aria-busy", "true");
    expect(container.querySelector("[data-household-loading]")).not.toBeNull();
    // The greeting and the shell do not wait for the household.
    expect(screen.getByRole("heading", { level: 1, name: "سالم الاختبار" })).toBeInTheDocument();

    await act(async () => release({ data: familyHousehold() }));
    expect(await summary()).toBeInTheDocument();
    expect(container.querySelector("[data-household]")).toHaveAttribute("aria-busy", "false");
    expect(container.querySelector("[data-household-loading]")).toBeNull();
  });

  it("shows a transient failure inline, inside the shell, and retries", async () => {
    let calls = 0;
    vi.spyOn(apiClient, "get").mockImplementation(
      familyGet({
        household: async () => {
          calls++;
          if (calls === 1) throw new ApiError(500, null);
          return { data: familyHousehold() };
        },
      })
    );

    renderHome();

    expect(await screen.findByRole("alert")).toHaveTextContent("تعذّر تحميل بيانات الأسرة");
    // The shell stays: navigation, greeting, quick actions.
    expect(screen.getByRole("navigation", { name: "التنقل الرئيسي" })).toBeInTheDocument();
    expect(screen.getByRole("heading", { level: 1, name: "سالم الاختبار" })).toBeInTheDocument();
    expect(screen.queryByRole("heading", { name: "الوصول غير متاح حاليًا" })).not.toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: "إعادة المحاولة" }));

    expect(await summary()).toBeInTheDocument();
    expect(calls).toBe(2);
  });

  it.each([
    ["the family context boundary", { message: "بيانات الأسرة غير متاحة لهذا الحساب.", code: "FAMILY_CONTEXT_UNAVAILABLE" }],
    ["the permission check", { message: "This action is unauthorized." }],
    ["an empty body", null],
  ])("treats ANY 403 (%s) as family data unavailable", async (_label, body) => {
    vi.spyOn(apiClient, "get").mockImplementation(familyGet({ household: new ApiError(403, body) }));

    renderHome();

    expect(await screen.findByRole("heading", { name: "الوصول غير متاح حاليًا" })).toBeInTheDocument();
    expect(screen.queryByRole("navigation")).not.toBeInTheDocument();
    expect(screen.queryByText("سالم الاختبار")).not.toBeInTheDocument();
    expect(screen.queryByText(/This action is unauthorized|FAMILY_CONTEXT_UNAVAILABLE/)).not.toBeInTheDocument();
    expect(screen.queryByText("تعذّر تحميل بيانات الأسرة")).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: "تسجيل الخروج" })).toBeEnabled();
    expect(router.replace).not.toHaveBeenCalled();
  });

  it("returns an expired session (401) to the Family login", async () => {
    vi.spyOn(apiClient, "get").mockImplementation(familyGet({ household: new ApiError(401, { message: "Unauthenticated." }) }));

    renderHome();

    await waitFor(() => expect(router.replace).toHaveBeenCalledWith("/family/login"));
    expect(screen.queryByText("تعذّر تحميل بيانات الأسرة")).not.toBeInTheDocument();
  });

  it("keeps the household in memory only: nothing in browser storage", async () => {
    vi.spyOn(apiClient, "get").mockImplementation(familyGet());

    const { client } = renderHome();
    await summary();

    expect(client.getQueryData(FAMILY_HOUSEHOLD_QUERY_KEY)).toEqual(familyHousehold());
    const dump = browserStorageDump();
    for (const value of ["FAM-000123", "عائلة الاختبار", "فرع الاختبار", "سالم الاختبار", "registered_member_count", "household"]) {
      expect(dump).not.toContain(value);
    }
  });
});
