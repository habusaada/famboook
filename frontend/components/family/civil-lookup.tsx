"use client";

import Link from "next/link";
import { ChevronLeft, Lock, SearchCheck } from "lucide-react";
import { Button } from "@/components/ui/button";
import { CivilLookupPage } from "@/components/civil-lookup/civil-lookup-page";
import { useFamilyUser } from "@/components/family/family-context";

// FB-CIVIL-02B — the Civil Registry Lookup on the Family Portal (household
// head) and in Coordinator Space (docs/adr/FB-CIVIL-02B-FAMILY-COORDINATOR-LOOKUP.md).
// The /family/me civil_lookup flags only decide whether the entry and the
// page are shown; every lookup is authorized by the server for its mount
// (gates, explicit grant, role policy, quota), and the shared page sends
// nothing when the flag is off.

type Side = "family" | "coordinator";

const BACK: Record<Side, { href: string; label: string }> = {
  family: { href: "/family", label: "العودة إلى الرئيسية" },
  coordinator: { href: "/family/coordinator", label: "العودة إلى مساحة التنسيق" },
};

export const CIVIL_LOOKUP_HREF: Record<Side, string> = {
  family: "/family/civil-registry",
  coordinator: "/family/coordinator/civil-registry",
};

/** The lookup page of one side, or a neutral notice when it is not available to this account. */
export function FamilyCivilLookup({ side }: { side: Side }) {
  const user = useFamilyUser();
  const allowed = user.civil_lookup?.[side] === true;

  if (!allowed) {
    return (
      <section
        className="flex flex-col items-center gap-3 rounded-2xl border border-border bg-surface-1 px-5 py-10 text-center"
        data-civil-lookup-unavailable={side}
      >
        <Lock className="size-6 text-muted-foreground" aria-hidden />
        <p className="text-sm text-foreground">خدمة الاستعلام عن السجل المدني غير متاحة لهذا الحساب.</p>
        <Button asChild variant="outline" className="h-11 rounded-xl px-5 text-sm font-semibold">
          <Link href={BACK[side].href}>{BACK[side].label}</Link>
        </Button>
      </section>
    );
  }

  return <CivilLookupPage space={side} />;
}

/** The entry card, shown only when the server says this side's lookup is available. */
export function CivilLookupEntry({ side }: { side: Side }) {
  const user = useFamilyUser();
  if (user.civil_lookup?.[side] !== true) return null;

  return (
    <Link
      href={CIVIL_LOOKUP_HREF[side]}
      className="flex items-center gap-3 rounded-2xl border border-border bg-surface-1 p-4 transition-colors hover:bg-surface-hover focus-visible:outline-2 focus-visible:outline-ring"
      data-civil-lookup-entry={side}
    >
      <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-700" aria-hidden>
        <SearchCheck className="size-5" />
      </span>
      <span className="flex min-w-0 flex-1 flex-col gap-0.5">
        <span className="font-semibold text-foreground">الاستعلام عن السجل المدني</span>
        <span className="text-[13px] text-muted-foreground">ابحث برقم الهوية أو بالاسم ضمن رصيدك اليومي.</span>
      </span>
      <ChevronLeft className="size-5 shrink-0 text-muted-foreground" aria-hidden />
    </Link>
  );
}
