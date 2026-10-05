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
