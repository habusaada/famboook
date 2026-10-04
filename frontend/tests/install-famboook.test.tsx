import { execSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { join } from "node:path";
import { act, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { CHROME_RECOMMENDATION, INSTALL_DISMISSED_KEY, INSTALL_HELP, InstallFamboook, installPath } from "@/components/family/pwa/install-famboook";

// Installing the Family app, per browser: the native prompt where the
// browser offers one (only when pressed), a Chrome recommendation on Firefox
// for Android, Safari's steps on iOS, a careful hint elsewhere.

const FIREFOX_ANDROID = "Mozilla/5.0 (Android 14; Mobile; rv:131.0) Gecko/131.0 Firefox/131.0";
const CHROME_ANDROID = "Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36";
const IPHONE = "Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1";

const button = () => screen.getByRole("button", { name: "تثبيت فامبوك" });
const queryAction = () => document.querySelector("[data-install-famboook]");

function installPrompt(outcome: "accepted" | "dismissed") {
  const event = new Event("beforeinstallprompt", { cancelable: true }) as Event & {
    prompt: ReturnType<typeof vi.fn>;
    userChoice: Promise<{ outcome: string }>;
  };
  event.prompt = vi.fn(async () => undefined);
  event.userChoice = Promise.resolve({ outcome });
  return event;
}

function setStandalone(matches: boolean) {
  Object.defineProperty(window, "matchMedia", {
    configurable: true,
    value: (query: string) => ({ matches: matches && query === "(display-mode: standalone)", media: query, addEventListener() {}, removeEventListener() {} }),
  });
}

function setUserAgent(userAgent: string) {
  Object.defineProperty(window.navigator, "userAgent", { configurable: true, value: userAgent });
}

beforeEach(() => {
  window.localStorage.clear();
  setStandalone(false);
});

afterEach(() => {
  setUserAgent("Mozilla/5.0 (X11; Linux x86_64) jsdom");
});

describe("with the browser's native install prompt (Chrome, Edge, Samsung Internet)", () => {
  it("captures the prompt without showing it, and opens it only when pressed", async () => {
    setUserAgent(CHROME_ANDROID);
    const user = userEvent.setup();
    render(<InstallFamboook />);
    const event = installPrompt("accepted");

    act(() => {
      window.dispatchEvent(event);
    });

    expect(event.defaultPrevented).toBe(true);
    expect(event.prompt).not.toHaveBeenCalled();

    expect(queryAction()).toHaveAttribute("data-install-famboook", "native");
    await user.click(button());

    expect(event.prompt).toHaveBeenCalledTimes(1);
    expect(queryAction()).toBeNull();
  });

  it("stays available when the user declines the native prompt", async () => {
    const user = userEvent.setup();
    render(<InstallFamboook />);
    const event = installPrompt("dismissed");
    act(() => {
      window.dispatchEvent(event);
    });

    await user.click(button());

    expect(event.prompt).toHaveBeenCalledTimes(1);
    expect(button()).toBeInTheDocument();
    expect(screen.queryByText(/تم التثبيت/)).not.toBeInTheDocument();
  });

  it("hides itself once the browser reports the app installed", () => {
    render(<InstallFamboook />);

    act(() => {
      window.dispatchEvent(new Event("appinstalled"));
    });

    expect(queryAction()).toBeNull();
  });
});

describe("Firefox on Android", () => {
  it("recommends Google Chrome in a guidance card — with no install button", () => {
    setUserAgent(FIREFOX_ANDROID);
    render(<InstallFamboook />);

    const card = screen.getByRole("region", { name: CHROME_RECOMMENDATION.title });
    expect(card).toHaveAttribute("data-install-famboook", "chrome-recommendation");
    expect(screen.getByRole("heading", { name: "تثبيت فامبوك كتطبيق" })).toBeInTheDocument();
    expect(card).toHaveTextContent("لأفضل تجربة تثبيت، افتح فامبوك باستخدام Google Chrome ثم اختر «تثبيت التطبيق».");
    expect(card).toHaveTextContent("يمكنك متابعة استخدام فامبوك من Firefox دون تثبيت.");
    expect(screen.getByRole("img", { name: "Google Chrome" })).toHaveAttribute("src", "/brand/google-chrome.svg");
    // Guidance only: no install action, no fake "open Chrome" link.
    expect(screen.queryByRole("button", { name: "تثبيت فامبوك" })).not.toBeInTheDocument();
    expect(screen.queryByRole("link")).not.toBeInTheDocument();
    expect(screen.getAllByRole("button").map((b) => b.getAttribute("aria-label"))).toEqual(["إخفاء اقتراح التثبيت"]);
  });

  it("can be dismissed like the other suggestions", async () => {
    setUserAgent(FIREFOX_ANDROID);
    const user = userEvent.setup();
    render(<InstallFamboook />);

    await user.click(screen.getByRole("button", { name: "إخفاء اقتراح التثبيت" }));

    expect(queryAction()).toBeNull();
  });
});

describe("iPhone and iPad Safari", () => {
  it.each([
    ["iPhone", IPHONE, 0],
    ["iPad", "Mozilla/5.0 (iPad; CPU OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1", 0],
    ["iPadOS reporting a Mac", "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15", 5],
  ])("shows Safari's Add to Home Screen steps on %s — never the Chrome recommendation", async (_label, userAgent, touchPoints) => {
    setUserAgent(userAgent);
    Object.defineProperty(window.navigator, "maxTouchPoints", { configurable: true, value: touchPoints });
    const user = userEvent.setup();
    render(<InstallFamboook />);

    await user.click(button());

    expect(screen.getByRole("status")).toHaveTextContent("اضغط زر المشاركة ثم «إضافة إلى الشاشة الرئيسية».");
    expect(document.body.textContent).not.toContain("Google Chrome");
    Object.defineProperty(window.navigator, "maxTouchPoints", { configurable: true, value: 0 });
  });
});

describe("other browsers without a native prompt", () => {
  it("gives a careful hint that promises no standalone app", async () => {
    setUserAgent("Mozilla/5.0 (Windows NT 10.0; Win64; x64) Gecko/20100101 Firefox/131.0");
    const user = userEvent.setup();
    render(<InstallFamboook />);

    await user.click(button());

    expect(screen.getByRole("status")).toHaveTextContent(INSTALL_HELP.other);
    expect(INSTALL_HELP.other).not.toContain("الشاشة الرئيسية");
    expect(document.body.textContent).not.toContain("Google Chrome");
  });

  it("classifies browsers by their user agent", () => {
    expect(installPath(FIREFOX_ANDROID)).toBe("firefox-android");
    expect(installPath(CHROME_ANDROID)).toBe("other");
    expect(installPath(IPHONE)).toBe("ios");
    expect(installPath("Mozilla/5.0 (Windows NT 10.0; Win64; x64) Gecko/20100101 Firefox/131.0")).toBe("other");
  });
});

describe("when it is shown", () => {
  it("is hidden inside the installed app (standalone)", () => {
    setStandalone(true);
    render(<InstallFamboook />);

    expect(queryAction()).toBeNull();
  });

  it("is hidden after a dismissal on this device — a UI convenience only", async () => {
    const user = userEvent.setup();
    const first = render(<InstallFamboook />);

    await user.click(screen.getByRole("button", { name: "إخفاء اقتراح التثبيت" }));

    expect(queryAction()).toBeNull();
    expect(window.localStorage.getItem(INSTALL_DISMISSED_KEY)).toBe("1");
    first.unmount();
    render(<InstallFamboook />);
    expect(queryAction()).toBeNull();
    // Nothing else is stored.
    expect(Object.keys(window.localStorage)).toEqual(expect.not.arrayContaining(["token", "session"]));
    expect(window.localStorage.length).toBe(1);
  });
});

describe("the Staff Portal", () => {
  it("never renders the install suggestion", () => {
    const staffFiles = execSync("git ls-files app/(staff) components", { cwd: join(__dirname, ".."), encoding: "utf8" })
      .split("\n")
      .filter((file) => /\.(ts|tsx)$/.test(file) && !file.startsWith("components/family/"));
    const users = staffFiles.filter((file) => readFileSync(join(__dirname, "..", file), "utf8").includes("InstallFamboook"));

    expect(staffFiles.length).toBeGreaterThan(20);
    expect(users).toEqual([]);
  });
});
