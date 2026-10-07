"use client";

import Link from "next/link";
import { ChevronLeft, IdCard, Network } from "lucide-react";
import { useFamilyUser } from "@/components/family/family-context";
import {
  FamilyIdentityCard,
  HouseholdError,
  HouseholdSkeleton,
  HouseholdSummaryCard,
} from "@/components/family/home/household-cards";
import { QuickActions } from "@/components/family/home/quick-actions";
import { InstallFamboook } from "@/components/family/pwa/install-famboook";
import { isAccessFailure, useFamilyHouseholdQuery } from "@/lib/api/family-household";

/**
 * The Family Portal home (docs/11 §23, PWA-3A): the greeting, the install
 * suggestion, the household from GET /api/v1/family/household, Coordinator
 * Space where open, and the quick actions to come. The server resolves the
 * Family; a 401 or 403 is handled by the session/access flow (the gate and
 * the shell), any other failure inline.
 */
export function FamilyHome() {
  const user = useFamilyUser();
  const household = useFamilyHouseholdQuery();

  return (
    <div className="flex flex-col gap-6">
      <section aria-labelledby="family-greeting">
        <p className="text-sm text-muted-foreground">مرحبًا بك</p>
        <h1 id="family-greeting" className="mt-0.5 text-2xl leading-snug font-bold text-foreground" data-family-greeting>
          <bdi>{user.display_name ?? "مستخدم بوابة الأسرة"}</bdi>
        </h1>
      </section>

      <InstallFamboook />

      <div aria-busy={household.isPending} className="flex flex-col gap-6" data-household>
        {household.data ? (
          <>
            <FamilyIdentityCard household={household.data} />
            <HouseholdSummaryCard household={household.data} />
          </>
        ) : household.isError ? (
          !isAccessFailure(household.error) && (
            <HouseholdError onRetry={() => household.refetch()} retrying={household.isFetching} />
          )
        ) : (
          <HouseholdSkeleton />
        )}
      </div>

      {/* «بطاقة الأسرة الرقمية» (PWA-8.2): the household's card and QR. */}
      <Link
        href="/family/card"
        className="flex items-center gap-3 rounded-2xl border border-border bg-surface-1 p-4 transition-colors hover:bg-surface-hover focus-visible:outline-2 focus-visible:outline-ring"
        data-family-card-entry
      >
        <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-700" aria-hidden>
          <IdCard className="size-5" />
        </span>
        <span className="min-w-0 flex-1">
          <span className="block text-base font-semibold text-foreground">بطاقة الأسرة الرقمية</span>
          <span className="mt-0.5 block text-[13px] text-muted-foreground">عرض بطاقة أسرتك ورمز التحقق</span>
        </span>
        <ChevronLeft className="size-5 shrink-0 text-muted-foreground" aria-hidden />
      </Link>

      {user.coordinator_space && (
        // Shown only when the server says Coordinator Space is open; the space
        // itself asks the server again. It never feeds the household figures.
        <Link
          href="/family/coordinator"
          className="flex items-center gap-3 rounded-2xl border border-brand-100 bg-brand-50 p-4 transition-colors hover:bg-brand-100 focus-visible:outline-2 focus-visible:outline-ring"
          data-coordinator-entry
        >
          <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand-700 text-white" aria-hidden>
            <Network className="size-5" />
          </span>
          <span className="min-w-0 flex-1">
            <span className="block text-base font-semibold text-brand-900">مساحة التنسيق</span>
            <span className="mt-0.5 block text-[13px] text-brand-800">متابعة الأسر ضمن نطاق التنسيق المعيّن لك.</span>
          </span>
          <ChevronLeft className="size-5 shrink-0 text-brand-700" aria-hidden />
        </Link>
      )}

      <QuickActions />
    </div>
  );
}
