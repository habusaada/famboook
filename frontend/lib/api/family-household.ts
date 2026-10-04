"use client";

import { useEffect } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { ApiError, apiClient } from "@/lib/api/client";
import { FAMILY_ME_QUERY_KEY, type FamilyUser } from "@/lib/api/family-auth";
import type { Gender } from "@/lib/types/api/family";

// The signed-in household head's own household (PWA-3A). The server resolves
// the Family from the session; nothing here sends a family, person or
// membership identifier. Kept in the query cache only (cleared on logout),
// never in browser storage.

export type FamilyHousehold = {
  family_code: string;
  clan_name: string | null;
  branch_name: string | null;
  head: { full_name: string };
  /** Source-declared household total from the current declaration; null = not declared. */
  declared_household_size: number | null;
  declared_at: string | null;
  /** Active family memberships. A separate fact, never compared with the declared size. */
  registered_member_count: number;
};

export type FamilyMemberRelationship = { code: string; name: string };

/**
 * One active membership. The relationship and the head flag belong to the
 * membership and are always present; the Person fields are null when the
 * Person's data is not available (available = false).
 */
export type FamilyMember = {
  available: boolean;
  full_name: string | null;
  relationship: FamilyMemberRelationship | null;
  is_household_head: boolean;
  gender: Gender | null;
  birth_date: string | null;
  life_status: "ALIVE" | "DECEASED" | "UNKNOWN" | null;
};

/** One row per active membership, in the server's order (never re-sorted here). */
export type FamilyMembers = {
  family_code: string;
  members: FamilyMember[];
};

export const FAMILY_HOUSEHOLD_QUERY_KEY = ["family", "household"] as const;
export const FAMILY_MEMBERS_QUERY_KEY = ["family", "household", "members"] as const;

/**
 * The session/access flow for a Family data request. An expired session
 * (401) drops the signed-in user, so the Family gate returns to the login.
 * ANY 403 means the family data is unavailable to this account — whatever
 * the body says — and the shell shows its access-unavailable notice. Both
 * only ever take access away locally; the next /me answer from the server
 * stays authoritative. Other failures are left to the page.
 */
function useFamilyAccessFailure(error: unknown) {
  const queryClient = useQueryClient();
  const status = error instanceof ApiError ? error.status : null;

  useEffect(() => {
    if (status === 401) {
      queryClient.setQueryData(FAMILY_ME_QUERY_KEY, null);
    } else if (status === 403) {
      queryClient.setQueryData<FamilyUser | null>(FAMILY_ME_QUERY_KEY, (user) =>
        user ? { ...user, context: { available: false, family: null } } : user
      );
    }
  }, [status, queryClient]);
}

/** GET /api/v1/family/household. */
export function useFamilyHouseholdQuery() {
  const query = useQuery({
    queryKey: FAMILY_HOUSEHOLD_QUERY_KEY,
    queryFn: async () => (await apiClient.get<{ data: FamilyHousehold }>("/api/v1/family/household")).data,
    staleTime: 60 * 1000,
    retry: false,
  });
  useFamilyAccessFailure(query.error);

  return query;
}

/** GET /api/v1/family/household/members. */
export function useFamilyMembersQuery() {
  const query = useQuery({
    queryKey: FAMILY_MEMBERS_QUERY_KEY,
    queryFn: async () => (await apiClient.get<{ data: FamilyMembers }>("/api/v1/family/household/members")).data,
    staleTime: 60 * 1000,
    retry: false,
  });
  useFamilyAccessFailure(query.error);

  return query;
}

/** True when the failure is handled by the session/access flow, not inline. */
export function isAccessFailure(error: unknown): boolean {
  return error instanceof ApiError && (error.status === 401 || error.status === 403);
}
