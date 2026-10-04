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
  ios: "اضغط زر المشاركة ثم «إضافة إلى الشاشة الرئيسية».",
  // Careful wording: a home-screen shortcut is not always an installed app.
  other: "إن كان متصفحك يدعم تثبيت التطبيقات، فاختر «تثبيت التطبيق» من قائمة المتصفح.",
} as const;

/**
 * Firefox on Android: Famboook's supported installation path is Google
 * Chrome (the pilot showed Firefox offering only a home-screen shortcut).
 * Guidance, not a statement about Firefox in general.
 */
export const CHROME_RECOMMENDATION = {
  title: "تثبيت فامبوك كتطبيق",
  body: "لأفضل تجربة تثبيت، افتح فامبوك باستخدام Google Chrome ثم اختر «تثبيت التطبيق».",
  note: "يمكنك متابعة استخدام فامبوك من Firefox دون تثبيت.",
} as const;

export type InstallPath = "ios" | "firefox-android" | "other";

/** Which guidance fits this browser when it offers no native install prompt. */
export function installPath(userAgent: string, maxTouchPoints = 0): InstallPath {
  if (/iPhone|iPad|iPod/i.test(userAgent) || (/Macintosh/i.test(userAgent) && maxTouchPoints > 1)) return "ios";
  if (/Android/i.test(userAgent) && /Firefox\//i.test(userAgent)) return "firefox-android";

  return "other";
}

function isStandalone(): boolean {
  const iosStandalone = (window.navigator as Navigator & { standalone?: boolean }).standalone === true;

  return iosStandalone || (typeof window.matchMedia === "function" && window.matchMedia("(display-mode: standalone)").matches);
}

function readDismissed(): boolean {
  try {
    return window.localStorage.getItem(INSTALL_DISMISSED_KEY) === "1";
  } catch {
    return false;
  }
}

const noSubscription = () => () => {};

/**
 * Installing the Family app, per browser:
 *
 *   native prompt (Chrome / Chromium)  "تثبيت فامبوك" opens the browser's own
 *                                      install prompt — only when pressed
 *   Firefox on Android                 a guidance card recommending Google
 *                                      Chrome; no install button
 *   iPhone / iPad                      Safari's "Add to Home Screen" steps
 *   anything else                      a careful generic hint
 *
 * Hidden entirely in the installed app (standalone), after a confirmed
 * install, or once dismissed on this device. It never claims an installation
 * it did not see.
 */
export function InstallFamboook({ className }: { className?: string }) {
  // Client-only facts (installed app, browser, a dismissal) are read after
  // hydration: the server renders nothing, the browser decides.
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

  const path = installPath(window.navigator.userAgent, window.navigator.maxTouchPoints);

  function dismiss() {
    try {
      window.localStorage.setItem(INSTALL_DISMISSED_KEY, "1");
    } catch {
      // Storage unavailable: hide for this visit only.
    }
    setClosed(true);
  }

  const dismissButton = (
    <Button
      type="button"
      variant="ghost"
      size="icon"
      onClick={dismiss}
      aria-label="إخفاء اقتراح التثبيت"
      className="size-9 shrink-0 text-muted-foreground hover:bg-surface-2"
    >
      <X className="size-4" aria-hidden />
    </Button>
  );

  // Firefox on Android, no native prompt: guidance, not an action.
  if (!prompt && path === "firefox-android") {
    return (
      <section
        aria-labelledby="install-famboook-title"
        className={cn("flex w-full items-start gap-3 rounded-2xl border border-border bg-surface-1 p-3.5 text-start", className)}
        data-install-famboook="chrome-recommendation"
      >
        {/* eslint-disable-next-line @next/next/no-img-element -- a small static brand mark */}
        <img src="/brand/google-chrome.svg" alt="Google Chrome" width={20} height={20} className="mt-0.5 size-5 shrink-0" />
        <div className="min-w-0 flex-1">
          <h2 id="install-famboook-title" className="text-sm font-semibold text-foreground">
            {CHROME_RECOMMENDATION.title}
          </h2>
          <p className="mt-1 text-[13px] leading-relaxed text-muted-foreground">{CHROME_RECOMMENDATION.body}</p>
          <p className="mt-1.5 text-xs leading-relaxed text-subtle-foreground">{CHROME_RECOMMENDATION.note}</p>
        </div>
        {dismissButton}
      </section>
    );
  }

  async function install() {
    if (!prompt) {
      setHelp(path === "ios" ? INSTALL_HELP.ios : INSTALL_HELP.other);
      return;
    }
    await prompt.prompt();
    const { outcome } = await prompt.userChoice;
    // A prompt can be used once; an accepted install hides the action, and
    // "appinstalled" confirms it as well.
    setPrompt(null);
    if (outcome === "accepted") setClosed(true);
  }

  return (
    <section
      aria-label="تثبيت فامبوك"
      className={cn("flex w-full items-start gap-3 rounded-2xl border border-brand-100 bg-brand-50 p-3 text-start", className)}
      data-install-famboook={prompt ? "native" : path}
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
      {dismissButton}
    </section>
  );
}
