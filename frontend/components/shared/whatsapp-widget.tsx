"use client";

import { useEffect, useId, useRef, useState } from "react";
import { LifeBuoy, MessageCircle, MessageCircleQuestion, UsersRound, X, type LucideIcon } from "lucide-react";
import { cn } from "@/lib/utils";
import { WHATSAPP_PRIVACY_NOTICE, type WhatsAppIntent, whatsAppLink, whatsAppNumber } from "@/lib/whatsapp";

// FB-WEB-05: the floating contact button of the public homepage (app/(public)/page.tsx only;
// the legal pages show the verified channel only, FB-LEGAL-01D). It opens WhatsApp
// with a prefilled message the visitor can edit: no API, session, storage or analytics.

const focusRing = "focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring";

const ACTIONS: { intent: WhatsAppIntent; label: string; icon: LucideIcon }[] = [
  { intent: "join", label: "طلب انضمام عائلة أو عشيرة", icon: UsersRound },
  { intent: "support", label: "الدعم الفني", icon: LifeBuoy },
  { intent: "general", label: "استفسار عام", icon: MessageCircleQuestion },
];

export function WhatsAppWidget() {
  const [open, setOpen] = useState(false);
  const rootRef = useRef<HTMLDivElement>(null);
  const toggleRef = useRef<HTMLButtonElement>(null);
  const panelId = useId();
  const titleId = useId();
  const number = whatsAppNumber();

  useEffect(() => {
    if (!open) return;
    const onPointerDown = (event: PointerEvent) => {
      if (!rootRef.current?.contains(event.target as Node)) setOpen(false);
    };
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key !== "Escape") return;
      setOpen(false);
      toggleRef.current?.focus();
    };
    document.addEventListener("pointerdown", onPointerDown);
    document.addEventListener("keydown", onKeyDown);
    return () => {
      document.removeEventListener("pointerdown", onPointerDown);
      document.removeEventListener("keydown", onKeyDown);
    };
  }, [open]);

  if (!number) return null;

  return (
    <div ref={rootRef} className="fixed end-4 bottom-[calc(1rem+env(safe-area-inset-bottom))] z-40 print:hidden sm:end-6 sm:bottom-[calc(1.5rem+env(safe-area-inset-bottom))]" data-whatsapp-widget>
      <button
        ref={toggleRef}
        type="button"
        onClick={() => setOpen((value) => !value)}
        aria-expanded={open}
        aria-controls={panelId}
        aria-label={open ? "إغلاق نافذة التواصل" : "تواصل معنا عبر واتساب"}
        className={cn("flex size-14 items-center justify-center rounded-widget bg-brand-700 text-white shadow-e3 transition-colors hover:bg-brand-800", focusRing)}
      >
        {open ? <X className="size-6" strokeWidth={1.75} aria-hidden /> : <MessageCircle className="size-6" strokeWidth={1.75} aria-hidden />}
      </button>

      {/* After the button in the DOM, so Tab moves from the button into the panel. */}
      <div
        id={panelId}
        role="region"
        aria-labelledby={titleId}
        hidden={!open}
        className="absolute end-0 bottom-full mb-3 max-h-[calc(100svh-7rem)] w-[min(20rem,calc(100vw-2rem))] overflow-y-auto rounded-widget border border-stroke-subtle bg-surface-1 shadow-e3"
      >
        <div className="border-b border-stroke-subtle bg-brand-50 px-4 py-3">
          <h2 id={titleId} className="text-base font-semibold text-foreground">
            فامبوك | تواصل معنا
          </h2>
          <p className="mt-0.5 text-xs text-muted-foreground">فريق إدارة المنصة</p>
        </div>
        <div className="p-4">
          <p className="text-sm text-foreground">أهلًا بك في فامبوك! كيف يمكننا مساعدتك؟</p>
          <ul className="mt-3 flex flex-col gap-2">
            {ACTIONS.map(({ intent, label, icon: Icon }) => (
              <li key={intent}>
                <a
                  href={whatsAppLink(number, intent)}
                  target="_blank"
                  rel="noopener noreferrer"
                  onClick={() => setOpen(false)}
                  className={cn("flex min-h-11 items-center gap-3 rounded-lg border border-border bg-surface-2 px-3 py-2 text-sm font-medium text-foreground transition-colors hover:border-brand-500 hover:bg-brand-50", focusRing)}
                >
                  <Icon className="size-4 shrink-0 text-brand-700" strokeWidth={1.75} aria-hidden />
                  {label}
                  <span className="sr-only">(يفتح واتساب في نافذة جديدة)</span>
                </a>
              </li>
            ))}
          </ul>
          <p className="mt-3 text-xs leading-relaxed text-muted-foreground" data-whatsapp-privacy-notice>
            {WHATSAPP_PRIVACY_NOTICE}
          </p>
        </div>
      </div>
    </div>
  );
}
