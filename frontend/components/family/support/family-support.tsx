"use client";

import Link from "next/link";
import { ArrowRight, Info } from "lucide-react";
import { AssistanceSection } from "@/components/family/support/assistance-section";
import { NeedsSection } from "@/components/family/support/needs-section";
import { REGISTERED_ONLY_NOTE } from "@/lib/utils/family-portal-labels";

/**
 * «الاحتياجات والمساعدات» (PWA-3B.7, docs/11 §23a): the household's
 * registered needs and the assistance it actually received, reached from
 * «أسرتي». Two independent sections, each with its own request, loading and
 * error. Read-only: no add, edit, resolve, reverse or request control —
 * corrections belong to the Change Request engine (PWA-5). A 401 or 403 is
 * handled by the session/access flow.
 */
export function FamilySupport() {
  return (
    <div className="flex flex-col gap-5">
      <header>
        <Link
          href="/family/household"
          className="-ms-2 inline-flex min-h-10 items-center gap-1 rounded-lg px-2 text-sm font-medium text-brand-700 hover:bg-brand-50 focus-visible:outline-2 focus-visible:outline-ring"
          data-support-back
        >
          <ArrowRight className="size-4" aria-hidden />
          أسرتي
        </Link>
        <h1 className="mt-1 text-2xl leading-snug font-bold text-foreground">الاحتياجات والمساعدات</h1>
        <p className="mt-1.5 flex items-start gap-2 text-[13px] leading-relaxed text-muted-foreground" data-support-note>
          <Info className="mt-0.5 size-4 shrink-0" aria-hidden />
          {REGISTERED_ONLY_NOTE}
        </p>
      </header>

      <NeedsSection />
      <AssistanceSection />
    </div>
  );
}
