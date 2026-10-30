"use client";

import { useId, useState } from "react";
import Link from "next/link";
import { ArrowLeft, ChevronDown, Menu, X } from "lucide-react";
import { BrandLogo } from "@/components/shared/brand-logo";
import { cn } from "@/lib/utils";
import { LANDING_NAV } from "./landing-content";

// FB-WEB-02B — the only stateful parts of the page outside the preview: the
// fixed header with its mobile menu, and the FAQ accordion (one answer open
// at a time, as in the approved reference).

const focusRing = "focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring";

export function LandingHeader() {
  const [open, setOpen] = useState(false);
  const menuId = useId();

  return (
    <header className="fixed inset-x-0 top-0 z-50 border-b border-stroke-subtle/70 bg-surface-1/90 shadow-e1 backdrop-blur-xl" data-landing-header onKeyDown={(event) => event.key === "Escape" && setOpen(false)}>
      <div className="mx-auto flex h-16 max-w-7xl items-center justify-between gap-4 px-4 sm:px-6 lg:h-20 lg:px-12">
        <a href="#hero" className={cn("rounded-lg", focusRing)} aria-label="فامبوك — أعلى الصفحة">
          <BrandLogo className="h-10" alt="" />
        </a>

        <nav aria-label="أقسام الصفحة" className="hidden items-center gap-1 rounded-xl border border-stroke-subtle/60 bg-surface-2 p-1 text-sm font-medium whitespace-nowrap xl:flex">
          {LANDING_NAV.map((item) => (
            <a key={item.href} href={item.href} className={cn("rounded-lg px-3.5 py-1.5 text-muted-foreground transition-colors hover:bg-surface-1 hover:text-foreground", focusRing)}>
              {item.label}
            </a>
          ))}
        </nav>

        <div className="flex items-center gap-2">
          <Link
            href="/login"
            className={cn("hidden h-10 items-center justify-center gap-2 rounded-xl bg-brand-700 px-5 text-sm font-medium whitespace-nowrap text-white transition-colors hover:bg-brand-800 sm:inline-flex", focusRing)}
          >
            الدخول إلى المنصة
            <ArrowLeft className="size-4" aria-hidden />
          </Link>
          <button
            type="button"
            onClick={() => setOpen((value) => !value)}
            aria-expanded={open}
            aria-controls={menuId}
            aria-label={open ? "إغلاق القائمة" : "فتح القائمة"}
            className={cn("flex size-11 items-center justify-center rounded-xl text-foreground transition-colors hover:bg-surface-hover xl:hidden", focusRing)}
          >
            {open ? <X className="size-6" aria-hidden /> : <Menu className="size-6" aria-hidden />}
          </button>
        </div>
      </div>

      {open && (
        <nav id={menuId} aria-label="أقسام الصفحة" className="border-t border-stroke-subtle bg-surface-1 px-4 py-4 shadow-e2 xl:hidden">
          <div className="flex flex-col gap-1 text-sm font-medium text-foreground">
            {LANDING_NAV.map((item) => (
              <a key={item.href} href={item.href} onClick={() => setOpen(false)} className={cn("rounded-lg px-3 py-2.5 hover:bg-surface-hover", focusRing)}>
                {item.label}
              </a>
            ))}
            <Link href="/family/login" className={cn("mt-2 flex h-11 items-center justify-center gap-2 rounded-xl border border-brand-700/30 font-semibold text-brand-700", focusRing)}>
              دخول رب الأسرة
            </Link>
            <Link href="/login" className={cn("mt-1 flex h-11 items-center justify-center gap-2 rounded-xl bg-brand-700 font-semibold text-white", focusRing)}>
              الدخول إلى المنصة
              <ArrowLeft className="size-4" aria-hidden />
            </Link>
          </div>
        </nav>
      )}
    </header>
  );
}

export function LandingFaq({ items }: { items: readonly { question: string; answer: string }[] }) {
  const [openIndex, setOpenIndex] = useState<number | null>(null);
  const baseId = useId();

  return (
    <div className="flex flex-col gap-3" data-landing-faq>
      {items.map((item, index) => {
        const open = openIndex === index;
        const answerId = `${baseId}-answer-${index}`;
        return (
          <div key={item.question} className="overflow-hidden rounded-xl border border-stroke-subtle bg-surface-2">
            <h3>
              <button
                type="button"
                onClick={() => setOpenIndex(open ? null : index)}
                aria-expanded={open}
                aria-controls={answerId}
                className={cn("flex w-full items-center justify-between gap-4 p-4 text-start text-sm font-bold text-foreground transition-colors hover:text-brand-700 sm:text-base", "focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring")}
              >
                <span>{item.question}</span>
                <ChevronDown className={cn("size-5 shrink-0 text-brand-700 transition-transform motion-reduce:transition-none", open && "rotate-180")} aria-hidden />
              </button>
            </h3>
            <div id={answerId} hidden={!open} className="border-t border-stroke-subtle/60 px-4 pt-3 pb-4 text-sm leading-relaxed text-muted-foreground">
              {item.answer}
            </div>
          </div>
        );
      })}
    </div>
  );
}
