"use client";

import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/lib/api/client";
import { useFamilyAccessFailure } from "@/lib/api/family-household";
import type { Gender } from "@/lib/types/api/family";
import type { MaritalStatus } from "@/lib/utils/marital-status";

// «بياناتي الشخصية» (PWA-3B.1, docs/11 §23a): the signed-in household head's
// OWN registry data. The server resolves the Person from the session; nothing
// here sends an identifier. The National ID and mobiles arrive MASKED only —
// the full values are a separate reveal (PWA-3B.2) and never part of this
// payload. Kept in the query cache only (cleared on logout), never in
// browser storage.

export type FamilySelf = {
  full_name: string;
  /** "*****6789"; null = not recorded. */
  national_id_masked: string | null;
  gender: Gender | null;
  birth_date: string | null;
  /** As stored; UNKNOWN is an explicit value, not a missing one. */
  marital_status: MaritalStatus | null;
  /** "05*****567"; null = not recorded. */
  mobile_masked: string | null;
  alternate_mobile_masked: string | null;
  alternate_mobile_owner_relation: string | null;
  relationship: { code: string; name: string } | null;
  is_household_head: boolean;
  membership_started_at: string | null;
};

export const FAMILY_SELF_QUERY_KEY = ["family", "self"] as const;

/** The own sensitive values the self reveal may return (PWA-3B.2). */
export type SelfRevealField = "NATIONAL_ID" | "MOBILE" | "ALTERNATE_MOBILE";

/**
 * POST /api/v1/family/self/reveal — ONE own full value. Deliberately a plain
 * request, not a query or a mutation: the value must never enter the query
 * or mutation cache. The caller keeps it in transient component state only
 * and drops it on hide and on unmount. Only the field code is sent.
 */
export async function revealSelfValue(field: SelfRevealField): Promise<string | null> {
  const response = await apiClient.post<{ data: { field: SelfRevealField; value: string | null } }>(
    "/api/v1/family/self/reveal",
    { field }
  );

  return response.data.value;
}

/** GET /api/v1/family/self. */
export function useFamilySelfQuery() {
  const query = useQuery({
    queryKey: FAMILY_SELF_QUERY_KEY,
    queryFn: async () => (await apiClient.get<{ data: FamilySelf }>("/api/v1/family/self")).data,
    staleTime: 60 * 1000,
    retry: false,
  });
  useFamilyAccessFailure(query.error);

  return query;
}
