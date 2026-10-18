"use client";

import { useFamilyUser } from "@/components/family/family-context";
import { CoordinatorDashboard } from "@/components/family/home/coordinator-dashboard";
import { HomeShortcuts } from "@/components/family/home/home-shortcuts";
import {
  FamilyIdentityCard,
  HouseholdError,
  HouseholdSkeleton,
  HouseholdSummaryCard,
} from "@/components/family/home/household-cards";
import { HomeServices } from "@/components/family/home/quick-actions";
import { WelcomeHeader } from "@/components/family/home/welcome-header";
import { InstallFamboook } from "@/components/family/pwa/install-famboook";
import { isAccessFailure, useFamilyHouseholdQuery } from "@/lib/api/family-household";

/**
 * The unified Family Portal home (docs/11 §23, FB-FAMILY-DASHBOARD-01):
 * the greeting with the current time and the previous sign-in, the
 * household (GET /api/v1/family/household), shortcuts, the services the
 * server makes available, and — only when /family/me says Coordinator Space
 * is open — the Coordinator section. The server resolves the Family; a 401
 * or 403 is handled by the session/access flow, any other failure inline.
 */
export function FamilyHome() {
  const user = useFamilyUser();
  const household = useFamilyHouseholdQuery();

  return (
    <div className="flex flex-col gap-6">
      <WelcomeHeader user={user} />

      <InstallFamboook />

      <div aria-busy={household.isPending} className="flex flex-col gap-4" data-household>
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

      <HomeShortcuts />

      <HomeServices />

      {/* Shown only when the server says Coordinator Space is open; its own
          endpoints authorize again. It never feeds the household figures. */}
      {user.coordinator_space && <CoordinatorDashboard />}
    </div>
  );
}
