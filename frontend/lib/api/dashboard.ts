"use client";

import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { apiClient } from "@/lib/api/client";
import type { Clan } from "@/lib/types/api/clan";
import type { DashboardData, DashboardScopeQuery } from "@/lib/types/api/dashboard";

// Clans → Branch Groups → Branches the dashboard can be scoped to
// (dashboard.view-operational; no clan.view needed).
export function useDashboardScopeOptions() {
  return useQuery({
    queryKey: ["dashboard", "scope-options"],
    queryFn: () => apiClient.get<{ data: Clan[] }>("/api/v1/dashboard/scope-options"),
    staleTime: 5 * 60 * 1000,
  });
}

export function useDashboard(scope: DashboardScopeQuery | null) {
  return useQuery({
    queryKey: ["dashboard", scope],
    queryFn: () => {
      const params = new URLSearchParams();
      for (const [key, value] of Object.entries(scope ?? {})) {
        if (value) params.set(key, value);
      }
      return apiClient.get<{ data: DashboardData }>(`/api/v1/dashboard?${params.toString()}`);
    },
    enabled: scope !== null,
    // Keep the previous figures on screen while a new scope loads.
    placeholderData: keepPreviousData,
  });
}
