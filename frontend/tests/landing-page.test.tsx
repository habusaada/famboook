import { existsSync, readFileSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import WelcomePage, { metadata } from "@/app/(public)/welcome/page";
import { demoAnnouncements, demoFamilyUser, demoHousehold, demoMembers, demoNotifications, demoRegistryMembers } from "@/components/landing/demo-fixtures";
import { DEMO_CARD_LABEL, demoFamilyCard } from "@/components/landing/demo-card-fixture";
import { apiClient } from "@/lib/api/client";
import { renderWithClient } from "./helpers";

// FB-WEB-02B — the public page: renders without a session, its four previews
// use only synthetic fixtures, nothing touches the network, and its module
// graph reaches no API client, session or authenticated screen.

vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn(), replace: vi.fn() }), usePathname: () => "/welcome" }));
vi.mock("next/link", () => ({
  default: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...props}>
      {children}
    </a>
  ),
}));

const ROOT = resolve(__dirname, "..");
const TAB_NAMES = ["لوحة المعلومات", "السجلات الأسرية", "بوابة الأسرة PWA", "الإشعارات والإعلانات"];

/** Every project module reachable by RUNTIME imports (import type / export type are erased). */
function runtimeGraph(entry: string): Set<string> {
  const seen = new Set<string>();
  const visit = (file: string) => {
    if (seen.has(file)) return;
    seen.add(file);
    const source = readFileSync(file, "utf8");
    const pattern = /(?:^|\n)\s*(import|export)\s+(?!type\b)([^;'"]*?\bfrom\s+)?["']([^"']+)["']/g;
    for (const match of source.matchAll(pattern)) {
      const specifier = match[3];
      const base = specifier.startsWith("@/") ? join(ROOT, specifier.slice(2)) : specifier.startsWith(".") ? resolve(dirname(file), specifier) : null;
      if (!base) continue;
      const target = [`${base}.ts`, `${base}.tsx`, join(base, "index.ts"), join(base, "index.tsx")].find(existsSync);
      if (target) visit(target);
    }
  };
  visit(entry);
  return new Set([...seen].map((file) => file.slice(ROOT.length + 1).replaceAll("\\", "/")));
}

let fetchSpy: ReturnType<typeof vi.fn>;

beforeEach(() => {
  fetchSpy = vi.fn(() => Promise.reject(new Error("network is forbidden in the preview")));
  vi.stubGlobal("fetch", fetchSpy);
});

afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe("the public route", () => {
  it("renders at /welcome without a session, in the approved order, and stays out of search engines until approved", () => {
    renderWithClient(<WelcomePage />);
    expect(screen.getByRole("heading", { level: 1, name: "فامبوك.. منصة رقمية لتنظيم العائلات والعشائر" })).toBeInTheDocument();
    const ids = Array.from(document.querySelectorAll("main > section[id]")).map((el) => el.id);
    expect(ids).toEqual(["hero", "preview", "digital-family-card", "about", "capabilities", "how-it-works", "governance", "faq"]);
    expect(metadata.robots).toEqual({ index: false, follow: false });
  });

  it("links to the existing sign-in pages on this site", () => {
    renderWithClient(<WelcomePage />);
    const hrefs = screen.getAllByRole("link").map((a) => a.getAttribute("href"));
    expect(hrefs).toContain("/login");
    expect(hrefs).toContain("/family/login");
    expect(hrefs.some((href) => href?.startsWith("https://famboook.com"))).toBe(false);
  });

  it("does not replace / and sits outside the Staff and Family layouts", () => {
    expect(existsSync(join(ROOT, "app/(staff)/page.tsx"))).toBe(true);
    expect(existsSync(join(ROOT, "app/page.tsx"))).toBe(false);
    expect(existsSync(join(ROOT, "app/(public)/layout.tsx"))).toBe(false);
  });
});

describe("content accuracy", () => {
  it("positions the platform for councils, with the approved Hero copy and audience order (FB-WEB-02C)", () => {
    renderWithClient(<WelcomePage />);
    const hero = document.getElementById("hero") as HTMLElement;
    expect(hero.textContent).toContain("منصة رقمية متخصصة تمكّن مجالس العائلات والعشائر من تنظيم بيانات فروعها وأسرها وأفرادها، وإدارة الهياكل العائلية، وتعزيز التنسيق والتواصل المجتمعي عبر منظومة موحدة.");
    expect(within(hero).getByRole("link", { name: "الدخول إلى المنصة" })).toHaveAttribute("href", "/login");
    expect(within(hero).getByRole("link", { name: "دخول رب الأسرة" })).toHaveAttribute("href", "/family/login");
    expect(within(hero).getByRole("link", { name: "استكشف إمكانات فامبوك" })).toHaveAttribute("href", "#capabilities");
    const audiences = Array.from(document.querySelectorAll("#how-it-works article h3")).map((h) => h.textContent);
    expect(audiences).toEqual(["01 — مجالس العائلات والعشائر", "02 — المنسقون والمكلفون", "03 — أرباب الأسر"]);
    expect(String(metadata.title)).toContain("منصة رقمية لتنظيم العائلات والعشائر");
    expect(metadata.description).toContain("مجالس العائلات والعشائر");
  });

  it("uses the approved operator and the active contact address only", () => {
    renderWithClient(<WelcomePage />);
    const text = document.body.textContent ?? "";
    expect(text).toContain("المركز الفلسطيني للبيانات والتنمية المجتمعية (PCDCD)");
    expect(text).not.toContain("للتنمية الثقافية");
    expect(text).not.toContain("المالكة");
    expect(document.querySelector("[data-contact-email]")).toHaveAttribute("href", "mailto:info@pcdcd.org");
    expect(document.documentElement.innerHTML).not.toContain("support@pcdcd.org");
  });

  it("does not advertise civil-registry functionality", () => {
    renderWithClient(<WelcomePage />);
    expect(document.body.textContent).not.toMatch(/السجل المدني|الاستعلام المدني/);
  });
});

describe("the preview", () => {
  it("has accessible tabs, moved by the arrow keys, and an illustrative-data notice in every panel", async () => {
    const user = userEvent.setup();
    renderWithClient(<WelcomePage />);
    const preview = document.querySelector("[data-platform-preview]") as HTMLElement;
    const tablist = within(preview).getByRole("tablist", { name: "أقسام معاينة المنصة" });
    expect(within(tablist).getAllByRole("tab").map((t) => t.textContent)).toEqual(TAB_NAMES);
    expect(within(preview).getByRole("tab", { name: TAB_NAMES[0] })).toHaveAttribute("aria-selected", "true");

    for (const name of TAB_NAMES) {
      await user.click(within(preview).getByRole("tab", { name }));
      const panel = within(preview).getByRole("tabpanel", { name });
      expect(panel.querySelector("[data-demo-notice]")?.textContent).toContain("بيانات توضيحية");
    }

    // RTL: the left arrow moves to the next tab. Radix moves focus and selection together.
    await user.click(within(preview).getByRole("tab", { name: TAB_NAMES[0] }));
    await user.keyboard("{ArrowLeft}");
    expect(within(preview).getByRole("tab", { name: TAB_NAMES[1] })).toHaveAttribute("aria-selected", "true");
    expect(document.querySelector("[data-demo-panel='registry']")).not.toBeNull();
  });

  it("makes no request on any tab, through fetch or the API client", async () => {
    const user = userEvent.setup();
    const calls = (["get", "post", "put", "patch", "delete"] as const).map((method) => vi.spyOn(apiClient, method));
    renderWithClient(<WelcomePage />);
    for (const name of TAB_NAMES) await user.click(screen.getByRole("tab", { name }));
    expect(fetchSpy).not.toHaveBeenCalled();
    for (const call of calls) expect(call).not.toHaveBeenCalled();
  });

  it("offers no link or action inside any panel", async () => {
    const user = userEvent.setup();
    renderWithClient(<WelcomePage />);
    for (const name of TAB_NAMES) {
      await user.click(screen.getByRole("tab", { name }));
      const panel = screen.getByRole("tabpanel", { name });
      expect(within(panel).queryAllByRole("link")).toHaveLength(0);
      // The only buttons a panel may hold are the pagination's, disabled on its single page.
      for (const button of within(panel).queryAllByRole("button")) expect(button).toBeDisabled();
    }
  });

  it("shows the real components: KPI cards, the registry table, the Family cards and the notification rows", async () => {
    const user = userEvent.setup();
    renderWithClient(<WelcomePage />);
    expect(document.querySelectorAll("[data-kpi]").length).toBe(4);
    await user.click(screen.getByRole("tab", { name: TAB_NAMES[1] }));
    expect(screen.getByRole("table")).toBeInTheDocument();
    expect(document.querySelector("[data-pagination]")).not.toBeNull();
    await user.click(screen.getByRole("tab", { name: TAB_NAMES[2] }));
    const family = document.querySelector("[data-portal='family']") as HTMLElement;
    for (const selector of ["[data-welcome-header]", "[data-family-card]", "[data-household-summary]", "[data-member-row='head']", "[data-demo-nav]"]) {
      expect(family.querySelector(selector), selector).not.toBeNull();
    }
    await user.click(screen.getByRole("tab", { name: TAB_NAMES[3] }));
    expect(document.querySelector("[data-announcement-status='PUBLISHED']")).not.toBeNull();
    expect(document.querySelector("[data-family-notification-state='acknowledged']")).not.toBeNull();
  });
});

describe("demo isolation", () => {
  it("reaches no API client, session, gate or authenticated screen at run time", () => {
    const graph = [...runtimeGraph(join(ROOT, "app/(public)/welcome/page.tsx"))];
    expect(graph).toContain("components/landing/demo-panels.tsx");
    const forbidden = graph.filter((file) =>
      /^lib\/api\/|^components\/auth\/|family-gate|family-shell|family-context|family-home|bottom-nav|notification-bell|family-notifications\.tsx|staff-announcement-list|announcement-parts|operational-dashboard|dashboard-sections|families-registry|family-members-table|family-service-worker|query-provider|^lib\/utils\/announcement\.ts|components\/family\/card\/family-card\.tsx/.test(file),
    );
    expect(forbidden).toEqual([]);
  });

  it("keeps fixtures free of national IDs and mobile numbers", () => {
    const serialized = JSON.stringify({ demoAnnouncements, demoFamilyUser, demoHousehold, demoMembers, demoNotifications, demoRegistryMembers });
    expect(serialized).not.toMatch(/\d{9,}/);
    for (const member of demoMembers) {
      expect([member.national_id_masked, member.mobile_masked, member.alternate_mobile_masked]).toEqual([null, null, null]);
    }
    expect(demoHousehold.family_code).toContain("DEMO");
    for (const member of demoRegistryMembers) expect(member.person_code).toContain("DEMO");
  });
});

describe("content corrections (FB-WEB-02D)", () => {
  it("describes manual in-app announcements as available and does not advertise automated or push delivery", () => {
    renderWithClient(<WelcomePage />);
    const capabilities = document.getElementById("capabilities") as HTMLElement;
    const card = within(capabilities).getByRole("heading", { name: "الإعلانات والإشعارات" }).closest("article") as HTMLElement;
    expect(card.textContent).toContain("يدويًا");
    expect(card.textContent).not.toContain("خاضع للتفعيل");
    expect(document.body.textContent).not.toMatch(/إشعارات فورية|إشعارات تلقائية|Push/i);
  });

  it("points «عن فامبوك» at the About section, and every section anchor at a real section", () => {
    renderWithClient(<WelcomePage />);
    expect(screen.getAllByRole("link", { name: "عن فامبوك" })[0]).toHaveAttribute("href", "#about");
    for (const link of document.querySelectorAll('a[href^="#"]')) {
      expect(document.getElementById((link.getAttribute("href") as string).slice(1)), link.getAttribute("href") as string).not.toBeNull();
    }
  });

  it("keeps all four preview tabs reachable without scrolling the strip, and offers a skip link", () => {
    renderWithClient(<WelcomePage />);
    const tablist = screen.getByRole("tablist", { name: "أقسام معاينة المنصة" });
    expect(tablist.className).toContain("grid-cols-2");
    expect(tablist.className).not.toContain("overflow-x-auto");
    expect(screen.getByRole("link", { name: "تخطَّ إلى المحتوى" })).toHaveAttribute("href", "#main-content");
    expect(document.querySelector("main")).toHaveAttribute("id", "main-content");
  });

  it("offers both sign-in pages in the mobile menu and closes it with Escape", async () => {
    const user = userEvent.setup();
    renderWithClient(<WelcomePage />);
    await user.click(screen.getByRole("button", { name: "فتح القائمة" }));
    const close = screen.getByRole("button", { name: "إغلاق القائمة" });
    const menu = document.getElementById(close.getAttribute("aria-controls") as string) as HTMLElement;
    expect(within(menu).getByRole("link", { name: "دخول رب الأسرة" })).toHaveAttribute("href", "/family/login");
    expect(within(menu).getByRole("link", { name: "الدخول إلى المنصة" })).toHaveAttribute("href", "/login");
    await user.keyboard("{Escape}");
    expect(screen.getByRole("button", { name: "فتح القائمة" })).toHaveAttribute("aria-expanded", "false");
  });
});

describe("governance wording (FB-WEB-02E)", () => {
  it("separates councils, operational users, the operator and household heads, and promises councils no account", () => {
    renderWithClient(<WelcomePage />);
    const how = document.getElementById("how-it-works") as HTMLElement;
    const [council, coordinators, heads] = Array.from(how.querySelectorAll("article")).map((a) => a.textContent);
    expect(council).toContain("المستفيد المؤسسي المستهدف");
    expect(council).toContain("دون حساب أو لوحة خاصة بها");
    expect(council).not.toContain("الجهة المسؤولة عن إدارة بيانات");
    expect(coordinators).toContain("المستخدمون التشغيليون");
    expect(heads).toContain("مستخدمو بوابة الأسرة");
    const operator = how.querySelector("[data-operator-role]")?.textContent;
    expect(operator).toContain("المركز الفلسطيني للبيانات والتنمية المجتمعية (PCDCD)");
    expect(operator).toContain("صلاحية تشغيلية مفتوحة");
    expect(document.body.textContent).not.toMatch(/لوحة (تحكم )?المجلس|حساب المجلس|دخول المجلس/);
  });
});

describe("the Digital Family Card section (FB-WEB-02D-CARD)", () => {
  const section = () => document.getElementById("digital-family-card") as HTMLElement;
  const field = (name: string) => section().querySelector(`[data-field="${name}"] dd`);

  it("sits right after the preview, labelled by its Arabic heading, with the approved copy", () => {
    renderWithClient(<WelcomePage />);
    expect(section().previousElementSibling).toHaveAttribute("id", "preview");
    expect(section().nextElementSibling).toHaveAttribute("id", "about");
    expect(screen.getByRole("region", { name: "بطاقة الأسرة الرقمية" })).toBe(section());
  });

  it("uses the real card face with the synthetic fixture, under the section's heading level", () => {
    renderWithClient(<WelcomePage />);
    const face = section().querySelector("[data-family-card]") as HTMLElement;
    expect(face).not.toBeNull();
    expect(within(face).getByRole("heading", { level: 3, name: demoFamilyCard.head_name })).toBeInTheDocument();
    expect(face.textContent).toContain("بطاقة الأسرة الرقمية");
    expect(field("credential_number")).toHaveTextContent("DEMO-0000-00");
    expect(field("family_code")).toHaveTextContent("DEMO-001");
    expect(field("clan")).toHaveTextContent("عشيرة تجريبية");
    expect(field("branch")).toHaveTextContent("فرع تجريبي");
    expect(face.closest("[data-portal='family']")).not.toBeNull();
    expect(runtimeGraph(join(ROOT, "app/(public)/welcome/page.tsx"))).toContain("components/family/card/family-card-face.tsx");
  });

  it("cannot be mistaken for a valid credential: a clear label, the disclaimer, and no working QR", () => {
    renderWithClient(<WelcomePage />);
    expect(section().querySelector("[data-demo-card-label]")).toHaveTextContent(DEMO_CARD_LABEL);
    expect(DEMO_CARD_LABEL).toBe("نموذج توضيحي — بيانات غير حقيقية");
    expect(section().querySelector("[data-demo-card-disclaimer]")?.textContent).toContain("وليست وثيقة هوية رسمية");
    const qr = section().querySelector("[data-family-card-qr]") as HTMLElement;
    expect(qr.querySelector("img, canvas, a")).toBeNull();
    expect(qr.querySelector("[data-demo-card-qr-placeholder]")).toHaveTextContent("غير فعّال");
    // The operational failure text would be false here.
    expect(section().querySelector("[data-family-card-no-qr]")).toBeNull();
    expect(section().textContent).not.toContain("اعرض رمز QR عند طلب");
  });

  it("holds no token, verification URL, QR or real-format card number in its fixture", () => {
    expect(demoFamilyCard.verification_url).toBeNull();
    expect(demoFamilyCard.qr).toBeNull();
    expect(demoFamilyCard.qr_available).toBe(false);
    // CredentialNumbers::FORMAT in the backend.
    expect(demoFamilyCard.credential_number).not.toMatch(/^FC-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{2}$/);
    const serialized = JSON.stringify(demoFamilyCard);
    expect(serialized).toContain("DEMO");
    expect(serialized).not.toMatch(/\d{9,}|verify|https?:|data:image/);
  });

  it("links only to the Family sign-in and the preview, with no public lookup or verification action", () => {
    renderWithClient(<WelcomePage />);
    expect(within(section()).getByRole("link", { name: "الدخول إلى بوابة الأسرة" })).toHaveAttribute("href", "/family/login");
    const hrefs = within(section()).getAllByRole("link").map((a) => a.getAttribute("href"));
    expect(hrefs).toEqual(["/family/login", "#preview"]);
    expect(within(section()).queryAllByRole("button")).toHaveLength(0);
    expect(within(section()).queryAllByRole("textbox")).toHaveLength(0);
    expect(document.documentElement.innerHTML).not.toContain('href="/verify');
  });

  it("uses the official name everywhere, explains who reaches the card, and states its limits with no speculative use", () => {
    renderWithClient(<WelcomePage />);
    const limits = section().querySelector("[data-card-uses='limits']")?.textContent;
    expect(limits).toContain("ليست وثيقة هوية رسمية");
    expect(limits).toContain("لا تمنح أهلية أو استحقاقًا");
    expect(limits).toContain("لا يكشف رقم الهوية أو الجوال");
    expect(document.body.textContent).not.toContain("بطاقة رب الأسرة");
    expect(section().textContent).toContain("رب الأسرة المخوّل عبر بوابة الأسرة");
    expect(section().querySelector("[data-card-uses='future']")).toBeNull();
    expect(section().textContent).not.toMatch(/مستقبل|لاحقًا|التنسيق المجتمعي/);
    // No expiry exists in the system, so none is claimed.
    expect(section().textContent).not.toMatch(/انتهاء|تنتهي|صالحة حتى/);
  });

  it("offers every implemented feature, stacks on narrow screens, and makes no request", () => {
    renderWithClient(<WelcomePage />);
    expect(Array.from(section().querySelectorAll("[data-card-feature]")).map((el) => el.getAttribute("data-card-feature"))).toEqual(["identity", "structure", "qr", "access", "download", "privacy"]);
    const grid = section().firstElementChild as HTMLElement;
    expect(grid.className).toContain("grid-cols-1");
    expect(grid.className).toContain("lg:grid-cols-");
    expect(fetchSpy).not.toHaveBeenCalled();
  });
});

describe("the FAQ and the mobile menu", () => {
  it("expands one answer at a time with aria-expanded and the keyboard", async () => {
    const user = userEvent.setup();
    renderWithClient(<WelcomePage />);
    const first = screen.getByRole("button", { name: /ما هي منصة فامبوك/ });
    const second = screen.getByRole("button", { name: /لمن صُممت فامبوك/ });
    expect(first).toHaveAttribute("aria-expanded", "false");
    expect(document.getElementById(first.getAttribute("aria-controls") as string)).toHaveAttribute("hidden");

    first.focus();
    await user.keyboard("{Enter}");
    expect(first).toHaveAttribute("aria-expanded", "true");
    expect(document.getElementById(first.getAttribute("aria-controls") as string)).not.toHaveAttribute("hidden");

    await user.click(second);
    expect(first).toHaveAttribute("aria-expanded", "false");
    expect(second).toHaveAttribute("aria-expanded", "true");
  });

  it("opens and closes the mobile menu with its state announced", async () => {
    const user = userEvent.setup();
    renderWithClient(<WelcomePage />);
    const toggle = screen.getByRole("button", { name: "فتح القائمة" });
    expect(toggle).toHaveAttribute("aria-expanded", "false");
    await user.click(toggle);
    const close = screen.getByRole("button", { name: "إغلاق القائمة" });
    expect(close).toHaveAttribute("aria-expanded", "true");
    expect(document.getElementById(close.getAttribute("aria-controls") as string)).not.toBeNull();
  });
});
