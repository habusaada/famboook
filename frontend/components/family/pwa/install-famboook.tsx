"use client";

import { useEffect, useState, useSyncExternalStore } from "react";
import { Download, X } from "lucide-react";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";

/** The non-standard event Chromium browsers fire when the app can be installed. */
type BeforeInstallPromptEvent = Event & {
  prompt: () => Promise<void>;
  userChoice: Promise<{ outcome: "accepted" | "dismissed" }>;
};

/** A UI convenience only: hiding the suggestion has no account or security meaning. */
export const INSTALL_DISMISSED_KEY = "famboook.install.dismissed";

export const INSTALL_HELP = {
  firefox: "من قائمة المتصفح ⋮ اختر «تثبيت».",
  ios: "اضغط زر المشاركة ثم «إضافة إلى الشاشة الرئيسية».",
  other: "من قائمة المتصفح اختر «إضافة إلى الشاشة الرئيسية» أو «تثبيت التطبيق».",
} as const;

function isStandalone(): boolean {
  if (typeof window === "undefined") return false;
  const iosStandalone = (window.navigator as Navigator & { standalone?: boolean }).standalone === true;

  return iosStandalone || (typeof window.matchMedia === "function" && window.matchMedia("(display-mode: standalone)").matches);
}

/** Which manual instructions fit this browser (used only without a native prompt). */
export function installHelpFor(userAgent: string, maxTouchPoints = 0): string {
  const ios = /iPhone|iPad|iPod/i.test(userAgent) || (/Macintosh/i.test(userAgent) && maxTouchPoints > 1);
  if (ios) return INSTALL_HELP.ios;
  if (/Android/i.test(userAgent) && /Firefox/i.test(userAgent)) return INSTALL_HELP.firefox;

  return INSTALL_HELP.other;
}

function readDismissed(): boolean {
  try {
    return window.localStorage.getItem(INSTALL_DISMISSED_KEY) === "1";
  } catch {
    return false;
  }
}

/**
 * "تثبيت فامبوك": a quiet suggestion to install the Family app. Where the
 * browser offers its native install prompt (Chromium's beforeinstallprompt)
 * the button opens it — only when pressed, never by itself. Elsewhere (Firefox,
 * Safari…) it shows how to install from the browser menu. Hidden when already
 * running as the installed app, after a confirmed install, or once dismissed
 * on this device. It never claims an installation it did not see.
 */
const noSubscription = () => () => {};

export function InstallFamboook({ className }: { className?: string }) {
  // Client-only facts (installed app, a dismissal) are read after hydration:
  // the server renders nothing, the browser decides.
  const hydrated = useSyncExternalStore(noSubscription, () => true, () => false);
  const [closed, setClosed] = useState(false);
  const [prompt, setPrompt] = useState<BeforeInstallPromptEvent | null>(null);
  const [help, setHelp] = useState<string | null>(null);

  useEffect(() => {
    function onBeforeInstallPrompt(event: Event) {
      // Keep the browser from prompting on its own; the button decides.
      event.preventDefault();
      setPrompt(event as BeforeInstallPromptEvent);
    }
    function onInstalled() {
      setPrompt(null);
      setClosed(true);
    }
    window.addEventListener("beforeinstallprompt", onBeforeInstallPrompt);
    window.addEventListener("appinstalled", onInstalled);

    return () => {
      window.removeEventListener("beforeinstallprompt", onBeforeInstallPrompt);
      window.removeEventListener("appinstalled", onInstalled);
    };
  }, []);

  if (!hydrated || closed || isStandalone() || readDismissed()) return null;

  async function install() {
    if (!prompt) {
      setHelp(installHelpFor(window.navigator.userAgent, window.navigator.maxTouchPoints));
      return;
    }
    await prompt.prompt();
    const { outcome } = await prompt.userChoice;
    // A prompt can be used once; an accepted install hides the action, and
    // "appinstalled" confirms it as well.
    setPrompt(null);
    if (outcome === "accepted") setClosed(true);
  }

  function dismiss() {
    try {
      window.localStorage.setItem(INSTALL_DISMISSED_KEY, "1");
    } catch {
      // Storage unavailable: hide for this visit only.
    }
    setClosed(true);
  }

  return (
    <section
      aria-label="تثبيت فامبوك"
      className={cn("flex w-full items-start gap-3 rounded-2xl border border-brand-100 bg-brand-50 p-3 text-start", className)}
      data-install-famboook
    >
      <div className="min-w-0 flex-1">
        <Button type="button" variant="ghost" onClick={install} className="h-10 gap-2 px-2 text-sm font-semibold text-brand-800 hover:bg-brand-100">
          <Download className="size-4" aria-hidden />
          تثبيت فامبوك
        </Button>
        {help && (
          <p className="px-2 pt-1 text-[13px] leading-relaxed text-brand-900" role="status" data-install-help>
            {help}
          </p>
        )}
      </div>
      <Button
        type="button"
        variant="ghost"
        size="icon"
        onClick={dismiss}
        aria-label="إخفاء اقتراح التثبيت"
        className="size-10 shrink-0 text-brand-800 hover:bg-brand-100"
      >
        <X className="size-4" aria-hidden />
      </Button>
    </section>
  );
}
