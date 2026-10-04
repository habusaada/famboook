"use client";

import Link from "next/link";
import { AlertCircle, ChevronLeft, RotateCw, UsersRound } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import type { FamilyHousehold } from "@/lib/api/family-household";

export const SUMMARY_CAPTION =
  "العدد الأول هو ما صُرّح به في استمارة الأسرة، والثاني عدد الأفراد المسجّلين بأسمائهم في السجل، وقد يختلفان.";

const card = "rounded-2xl border border-border bg-surface-1 p-4";

/** Which family this is: the clan, the branch when there is one, the code. */
export function FamilyIdentityCard({ household }: { household: FamilyHousehold }) {
  return (
    <section className={card} aria-labelledby="family-identity-title" data-family-card>
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <h2 id="family-identity-title" className="text-xs font-medium text-subtle-foreground">
            الأسرة
          </h2>
          <p className="mt-1.5 truncate text-base font-semibold text-foreground" data-family-clan>
            <bdi>{household.clan_name ?? "أسرتي"}</bdi>
          </p>
          {household.branch_name && (
            <p className="mt-0.5 truncate text-sm text-muted-foreground" data-family-branch>
              الفرع: <bdi>{household.branch_name}</bdi>
            </p>
          )}
        </div>
        <span dir="ltr" className="shrink-0 rounded-lg bg-brand-50 px-2.5 py-1 font-mono text-[13px] font-medium text-brand-800" data-family-code>
          {household.family_code}
        </span>
      </div>
    </section>
  );
}

function Stat({ label, children, testId }: { label: string; children: React.ReactNode; testId: string }) {
  return (
    <div className="flex min-h-24 flex-col justify-between gap-2 rounded-xl border border-stroke-subtle bg-surface-2 p-3" data-stat={testId}>
      <dt className="text-[13px] leading-snug text-muted-foreground">{label}</dt>
      <dd className="text-2xl leading-none font-bold text-foreground tabular-nums">{children}</dd>
    </div>
  );
}

/**
 * Two separate facts, never compared: what the family declared, and how many
 * members are registered individually. No difference is calculated and
 * neither is presented as the more correct one.
 */
export function HouseholdSummaryCard({ household }: { household: FamilyHousehold }) {
  const declared = household.declared_household_size;

  return (
    <section className={card} aria-labelledby="household-summary-title" data-household-summary>
      <h2 id="household-summary-title" className="text-base font-semibold text-foreground">
        ملخص الأسرة
      </h2>
      <dl className="mt-3 grid grid-cols-2 gap-3">
        <Stat label="عدد أفراد الأسرة حسب الإقرار" testId="declared">
          {declared === null ? <span className="text-base font-semibold text-subtle-foreground">غير مُعلن</span> : declared}
        </Stat>
        <Stat label="الأفراد المسجّلون بالتفصيل" testId="registered">
          {household.registered_member_count}
        </Stat>
      </dl>
      <p className="mt-3 text-xs leading-relaxed text-subtle-foreground" data-summary-caption>
        {SUMMARY_CAPTION}
      </p>
      <Link
        href="/family/members"
        className="mt-3 flex min-h-11 w-full items-center justify-between gap-3 rounded-xl border border-border px-3.5 text-sm font-semibold text-brand-700 transition-colors hover:bg-brand-50 focus-visible:outline-2 focus-visible:outline-ring"
        data-members-entry
      >
        <span className="flex items-center gap-2">
          <UsersRound className="size-4" aria-hidden />
          عرض أفراد الأسرة
        </span>
        <ChevronLeft className="size-4" aria-hidden />
      </Link>
    </section>
  );
}

/** Roughly the size of the two cards, so the page does not jump on load. */
export function HouseholdSkeleton() {
  return (
    <div className="flex flex-col gap-6" data-household-loading>
      <p role="status" className="sr-only">
        جارٍ تحميل بيانات الأسرة
      </p>
      <div className={card} aria-hidden>
        <Skeleton className="h-3 w-12" />
        <div className="mt-2 flex items-center justify-between gap-3">
          <Skeleton className="h-5 w-32" />
          <Skeleton className="h-7 w-24 rounded-lg" />
        </div>
      </div>
      <div className={card} aria-hidden>
        <Skeleton className="h-5 w-24" />
        <div className="mt-3 grid grid-cols-2 gap-3">
          <Skeleton className="h-24 rounded-xl" />
          <Skeleton className="h-24 rounded-xl" />
        </div>
        <Skeleton className="mt-3 h-3 w-full" />
        <Skeleton className="mt-1.5 h-3 w-2/3" />
        <Skeleton className="mt-3 h-11 rounded-xl" />
      </div>
    </div>
  );
}

/** A transient failure: inline, inside the shell, with a retry. */
export function HouseholdError({ onRetry, retrying }: { onRetry: () => void; retrying: boolean }) {
  return (
    <section className={`${card} flex flex-col items-center gap-3 py-6 text-center`} data-household-error>
      <AlertCircle className="size-6 text-danger" aria-hidden />
      <p className="text-sm font-medium text-foreground" role="alert">
        تعذّر تحميل بيانات الأسرة
      </p>
      <Button variant="outline" className="h-10 gap-2 px-4" onClick={onRetry} disabled={retrying}>
        <RotateCw className={`size-4 ${retrying ? "animate-spin" : ""}`} aria-hidden />
        إعادة المحاولة
      </Button>
    </section>
  );
}
