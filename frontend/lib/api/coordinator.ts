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

// ------------------------------------------------- members directory (CO-01C)

/** The details of a Family in scope (coordinator-member.view, AUTH-ADR-092). */
export type CoordinatorFamilyDetail = {
  family_code: string;
  clan_name: string | null;
  branch_group_name: string | null;
  branch_name: string | null;
  head_name: string | null;
  /** Active memberships registered in Famboook. */
  registered_member_count: number;
  /** The current declaration's own figure; never derived from the members. */
  declared_household_size: number | null;
  declared_at: string | null;
};

/** One member: basic fields; the National ID and mobile are MASKED only. */
export type CoordinatorMember = {
  /** Opaque, bound to this Family; grants nothing by itself. */
  member_ref: string;
  available: boolean;
  full_name: string | null;
  relationship: { code: string; name: string } | null;
  is_household_head: boolean;
  gender: "MALE" | "FEMALE" | null;
  /** Whole years; null when unknown or deceased. */
  age: number | null;
  marital_status: "SINGLE" | "MARRIED" | "DIVORCED" | "WIDOWED" | "UNKNOWN" | null;
  life_status: "ALIVE" | "DECEASED" | "UNKNOWN" | null;
  national_id_masked: string | null;
  mobile_masked: string | null;
};

export type CoordinatorDirectory = {
  family: CoordinatorFamilyDetail;
  members: CoordinatorMember[];
  /** UX only: whether reveal controls are offered. The server re-checks. */
  can_reveal: boolean;
};

export type CoordinatorRevealField = "NATIONAL_ID" | "MOBILE";

/**
 * The directory of one Family in scope: the directory, "missing" (404: out of
 * scope or nonexistent) or "forbidden" (403: no directory permission — the
 * page then shows the summary only, or Coordinator Space is closed).
 */
export function useCoordinatorDirectoryQuery(code: string) {
  return useQuery({
    queryKey: [...COORDINATOR_QUERY_KEY, "directory", code],
    queryFn: async (): Promise<CoordinatorDirectory | "missing" | "forbidden"> => {
      try {
        return (await apiClient.get<{ data: CoordinatorDirectory }>(`${BASE}/families/${encodeURIComponent(code)}/members`)).data;
      } catch (error) {
        if (error instanceof ApiError && error.status === 404) return "missing";
        if (error instanceof ApiError && error.status === 403) return "forbidden";
        throw error;
      }
    },
    retry: false,
    // Masked data only, but nothing of another household lingers in memory.
    gcTime: 0,
  });
}

/**
 * POST …/families/{code}/members/{memberRef}/reveal — ONE full value of ONE
 * member, audited by the server. Deliberately a plain request, never a query
 * or a mutation, so the value never enters a cache; the caller keeps it in
 * transient component state only. Only the field code is sent in the body.
 */
export async function revealCoordinatorMemberValue(code: string, memberRef: string, field: CoordinatorRevealField): Promise<string | null> {
  const response = await apiClient.post<{ data: { field: CoordinatorRevealField; value: string | null } }>(
    `${BASE}/families/${encodeURIComponent(code)}/members/${encodeURIComponent(memberRef)}/reveal`,
    { field }
  );

  return response.data.value;
}
