"use client";

import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { ApiError, apiClient } from "@/lib/api/client";

// Coordinator Space (docs/11 §8): read-only views of what the server says
// the Coordinator may consider. The scope always comes from the server; the
// search and the page number are navigation state and grant nothing.

export type CoordinatorScopeType = "CLAN" | "BRANCH_GROUP" | "BRANCH";

export type CoordinatorScope = {
  type: CoordinatorScopeType;
  code: string;
  name: string | null;
  clan_name: string | null;
};

export type CoordinatorContext = {
  scopes: CoordinatorScope[];
  family_count: number;
};

/** A Family as a Coordinator may see it: summary fields only. */
export type CoordinatorFamily = {
  family_code: string;
  clan_name: string | null;
  branch_group_name: string | null;
  branch_name: string | null;
  head_name: string | null;
  active_member_count: number;
};

export type CoordinatorFamilyPage = {
  data: CoordinatorFamily[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
};

const BASE = "/api/v1/family/coordinator";

export const COORDINATOR_QUERY_KEY = ["family", "coordinator"] as const;

export function useCoordinatorContextQuery() {
  return useQuery({
    queryKey: [...COORDINATOR_QUERY_KEY, "context"],
    queryFn: async () => (await apiClient.get<{ data: CoordinatorContext }>(`${BASE}/context`)).data,
    retry: false,
  });
}

export function useCoordinatorFamiliesQuery(search: string, page: number) {
  return useQuery({
    queryKey: [...COORDINATOR_QUERY_KEY, "families", search, page],
    queryFn: () => {
      const params = new URLSearchParams({ page: String(page) });
      if (search) params.set("q", search);
      return apiClient.get<CoordinatorFamilyPage>(`${BASE}/families?${params.toString()}`);
    },
    placeholderData: keepPreviousData,
    retry: false,
  });
}

/** One summary, or null when the Family is not available to this Coordinator. */
export function useCoordinatorFamilyQuery(code: string) {
  return useQuery({
    queryKey: [...COORDINATOR_QUERY_KEY, "family", code],
    queryFn: async (): Promise<CoordinatorFamily | null> => {
      try {
        return (await apiClient.get<{ data: CoordinatorFamily }>(`${BASE}/families/${encodeURIComponent(code)}`)).data;
      } catch (error) {
        if (error instanceof ApiError && error.status === 404) return null;
        throw error;
      }
    },
    retry: false,
  });
}

/** The label of one scope chip. */
export function scopeLabel(scope: CoordinatorScope): string {
  const name = scope.name ?? scope.code;
  switch (scope.type) {
    case "CLAN":
      return `عشيرة: ${name}`;
    case "BRANCH_GROUP":
      return `مجموعة فروع: ${name}`;
    case "BRANCH":
      return `فرع: ${name}`;
  }
}

/** Clan · Group · Branch, skipping the levels the Family does not have. */
export function hierarchyLabel(family: CoordinatorFamily): string {
  return [family.clan_name, family.branch_group_name, family.branch_name].filter(Boolean).join(" · ");
}
