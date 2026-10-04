import { act, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { INSTALL_DISMISSED_KEY, INSTALL_HELP, InstallFamboook, installHelpFor } from "@/components/family/pwa/install-famboook";

// "تثبيت فامبوك": the native prompt where the browser offers one — only when
// pressed — and honest manual instructions everywhere else.

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
    const user = userEvent.setup();
    render(<InstallFamboook />);
    const event = installPrompt("accepted");

    act(() => {
      window.dispatchEvent(event);
    });

    expect(event.defaultPrevented).toBe(true);
    expect(event.prompt).not.toHaveBeenCalled();

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

describe("without a native prompt", () => {
  it.each([
    ["Firefox on Android", "Mozilla/5.0 (Android 14; Mobile; rv:131.0) Gecko/131.0 Firefox/131.0", INSTALL_HELP.firefox],
    ["Safari on iPhone", "Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1", INSTALL_HELP.ios],
    ["Safari on iPad", "Mozilla/5.0 (iPad; CPU OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1", INSTALL_HELP.ios],
    ["another browser", "Mozilla/5.0 (Windows NT 10.0; Win64; x64) Gecko/20100101 Firefox/131.0", INSTALL_HELP.other],
  ])("shows the instructions for %s and claims nothing", async (_label, userAgent, help) => {
    setUserAgent(userAgent);
    const user = userEvent.setup();
    render(<InstallFamboook />);

    await user.click(button());

    expect(screen.getByRole("status")).toHaveTextContent(help);
    expect(button()).toBeInTheDocument();
  });

  it("uses the approved wording", () => {
    expect(INSTALL_HELP.firefox).toBe("من قائمة المتصفح ⋮ اختر «تثبيت».");
    expect(INSTALL_HELP.ios).toBe("اضغط زر المشاركة ثم «إضافة إلى الشاشة الرئيسية».");
    // iPadOS reports itself as a Mac with touch.
    expect(installHelpFor("Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Safari/605.1.15", 5)).toBe(INSTALL_HELP.ios);
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
