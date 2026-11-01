import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { join } from "node:path";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { PHASE_PRODUCTION_BUILD } from "next/constants";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { WhatsAppWidget } from "@/components/shared/whatsapp-widget";
import { WHATSAPP_MESSAGES, WHATSAPP_PRIVACY_NOTICE, normalizeWhatsAppNumber, whatsAppLink, whatsAppNumber } from "@/lib/whatsapp";
import config from "@/next.config";

// FB-WEB-05: the public WhatsApp contact widget: links only, on the public layouts only.

const OPEN = "تواصل معنا عبر واتساب";
const CLOSE = "إغلاق نافذة التواصل";
const FRONTEND = join(__dirname, "..");

const message = (href: string) => decodeURIComponent(new URL(href).searchParams.get("text") ?? "");

beforeEach(() => {
  vi.stubEnv("NEXT_PUBLIC_WHATSAPP_NUMBER", "");
});
afterEach(() => {
  vi.unstubAllEnvs();
});

describe("the number", () => {
  it.each([
    ["970595990929", "970595990929"],
    ["+970595990929", "970595990929"],
    ["00970595990929", "970595990929"],
    [" +970 59-599 (0929) ", "970595990929"],
  ])("normalizes %j", (value, expected) => {
    expect(normalizeWhatsAppNumber(value)).toBe(expected);
  });

  it.each(["", "abc", "0595990929", "1234567", "1234567890123456", "97059599092x", "970595990929;x", "+"])("refuses %j", (value) => {
    expect(normalizeWhatsAppNumber(value)).toBeNull();
  });

  it("defaults to the approved number when unset or blank, and never guesses for an invalid value", () => {
    expect(whatsAppNumber(undefined)).toBe("970595990929");
    expect(whatsAppNumber("  ")).toBe("970595990929");
    expect(whatsAppNumber("+970 599 000 000")).toBe("970599000000");
    expect(whatsAppNumber("not-a-number")).toBeNull();
  });

  it("refuses a production build with an invalid configured number", () => {
    vi.stubEnv("NEXT_PUBLIC_API_URL", "https://api.example.invalid");
    vi.stubEnv("NEXT_PUBLIC_WHATSAPP_NUMBER", "not-a-number");
    expect(() => config(PHASE_PRODUCTION_BUILD)).toThrow(/NEXT_PUBLIC_WHATSAPP_NUMBER/);
  });
});

describe("the links", () => {
  it.each(["join", "support", "general"] as const)("encodes the whole %s message for wa.me", (intent) => {
    const href = whatsAppLink("970595990929", intent);
    expect(href.startsWith("https://wa.me/970595990929?text=")).toBe(true);
    expect(href).not.toMatch(/[\s؀-ۿ]/);
    expect(message(href)).toBe(WHATSAPP_MESSAGES[intent]);
  });

  it("keeps the approved wording, with a distinct message per action", () => {
    expect(WHATSAPP_MESSAGES.join).toContain("اسم العائلة/العشيرة:\nالمحافظة:\nاسم ممثل العائلة:\nصفته:");
    expect(WHATSAPP_MESSAGES.support).toContain("وصف المشكلة:");
    expect(WHATSAPP_MESSAGES.general.endsWith("لدي استفسار بخصوص المنصة:")).toBe(true);
    expect(new Set(Object.values(WHATSAPP_MESSAGES)).size).toBe(3);
  });
});

describe("the widget", () => {
  const openWidget = async () => {
    const user = userEvent.setup();
    render(
      <>
        <WhatsAppWidget />
        <button type="button">خارج</button>
      </>,
    );
    await user.click(screen.getByRole("button", { name: OPEN }));
    return user;
  };

  it("starts closed, with only the button exposed", () => {
    render(<WhatsAppWidget />);
    expect(screen.getByRole("button", { name: OPEN })).toHaveAttribute("aria-expanded", "false");
    expect(screen.queryByRole("region")).toBeNull();
    expect(screen.queryByRole("link")).toBeNull();
  });

  it("opens the approved text and three links that open WhatsApp safely", async () => {
    await openWidget();
    const panel = screen.getByRole("region", { name: "فامبوك | تواصل معنا" });
    expect(within(panel).getByText("فريق إدارة المنصة")).toBeInTheDocument();
    expect(within(panel).getByText("أهلًا بك في فامبوك! كيف يمكننا مساعدتك؟")).toBeInTheDocument();

    const expected = [
      ["طلب انضمام عائلة أو عشيرة", "join"],
      ["الدعم الفني", "support"],
      ["استفسار عام", "general"],
    ] as const;
    expect(within(panel).getAllByRole("link")).toHaveLength(3);
    for (const [name, intent] of expected) {
      const link = within(panel).getByRole("link", { name: new RegExp(name) });
      expect(message(link.getAttribute("href") ?? "")).toBe(WHATSAPP_MESSAGES[intent]);
      expect(link).toHaveAttribute("target", "_blank");
      expect(link).toHaveAttribute("rel", "noopener noreferrer");
    }
  });

  it("states, before any choice, that WhatsApp's terms apply and warns against sensitive data", async () => {
    await openWidget();
    const panel = screen.getByRole("region");
    const notice = within(panel).getByText(WHATSAPP_PRIVACY_NOTICE);
    expect(notice.textContent).toContain("واتساب");
    expect(notice.textContent).toContain("سياسة الخصوصية");
    expect(notice.textContent).toContain("عدم إرسال أرقام الهوية أو البيانات العائلية الحساسة");
    for (const text of Object.values(WHATSAPP_MESSAGES)) expect(text).not.toMatch(/الهوية|رقم الجوال/);
  });

  it("uses a configured number", async () => {
    vi.stubEnv("NEXT_PUBLIC_WHATSAPP_NUMBER", "+970 599 000 000");
    await openWidget();
    expect(screen.getAllByRole("link")[0].getAttribute("href")).toMatch(/^https:\/\/wa\.me\/970599000000\?text=/);
  });

  it("renders nothing with an invalid number", () => {
    vi.stubEnv("NEXT_PUBLIC_WHATSAPP_NUMBER", "not-a-number");
    const { container } = render(<WhatsAppWidget />);
    expect(container).toBeEmptyDOMElement();
  });

  it("closes on Escape and returns focus to the button", async () => {
    const user = await openWidget();
    await user.tab();
    expect(screen.getAllByRole("link")[0]).toHaveFocus();
    await user.keyboard("{Escape}");
    expect(screen.queryByRole("region")).toBeNull();
    expect(screen.getByRole("button", { name: OPEN })).toHaveFocus();
  });

  it("is usable by keyboard alone, in reading order", async () => {
    const user = userEvent.setup();
    render(<WhatsAppWidget />);
    await user.tab();
    expect(screen.getByRole("button", { name: OPEN })).toHaveFocus();
    await user.keyboard("{Enter}");
    expect(screen.getByRole("button", { name: CLOSE })).toHaveAttribute("aria-expanded", "true");
    for (const name of [/طلب انضمام/, /الدعم الفني/, /استفسار عام/]) {
      await user.tab();
      expect(screen.getByRole("link", { name })).toHaveFocus();
    }
  });

  it("closes on an outside click but not on a click inside", async () => {
    const user = await openWidget();
    await user.click(screen.getByText("فريق إدارة المنصة"));
    expect(screen.getByRole("region")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "خارج" }));
    expect(screen.queryByRole("region")).toBeNull();
  });

  it("closes from its own button and after choosing an action", async () => {
    const user = await openWidget();
    await user.click(screen.getByRole("button", { name: CLOSE }));
    expect(screen.queryByRole("region")).toBeNull();
    await user.click(screen.getByRole("button", { name: OPEN }));
    const link = screen.getByRole("link", { name: /الدعم الفني/ });
    link.addEventListener("click", (event) => event.preventDefault());
    await user.click(link);
    expect(screen.queryByRole("region")).toBeNull();
  });

  it("makes no request and stores nothing", async () => {
    const fetchSpy = vi.spyOn(globalThis, "fetch");
    const user = await openWidget();
    const link = screen.getByRole("link", { name: /استفسار عام/ });
    link.addEventListener("click", (event) => event.preventDefault());
    await user.click(link);
    expect(fetchSpy).not.toHaveBeenCalled();
    expect(window.localStorage.length).toBe(0);
    expect(window.sessionStorage.length).toBe(0);
  });
});

describe("where it appears", () => {
  it("is on the public homepage and nowhere else: /contact stays the verified channel only (FB-LEGAL-01D)", () => {
    const files = execFileSync("git", ["ls-files", "--cached", "--others", "--exclude-standard", "app", "components"], { cwd: FRONTEND, encoding: "utf8" })
      .split("\n")
      .filter((file) => /\.(ts|tsx)$/.test(file) && !file.endsWith("components/shared/whatsapp-widget.tsx"));
    const users = files.filter((file) => readFileSync(join(FRONTEND, file), "utf8").includes("whatsapp-widget"));
    expect(users).toEqual(["app/(public)/page.tsx"]);
  });

  it("reaches no API client, session or third-party script, and uses no raw colors", () => {
    const widget = readFileSync(join(FRONTEND, "components/shared/whatsapp-widget.tsx"), "utf8");
    const lib = readFileSync(join(FRONTEND, "lib/whatsapp.ts"), "utf8");
    const imports = [...widget.matchAll(/from "([^"]+)"/g)].map((match) => match[1]);
    expect(imports.sort()).toEqual(["@/lib/utils", "@/lib/whatsapp", "lucide-react", "react"]);
    expect(lib).not.toMatch(/^import /m);
    expect(widget).not.toMatch(/#[0-9a-fA-F]{3,8}\b|green|emerald|<script|localStorage|fetch\(/);
  });
});
