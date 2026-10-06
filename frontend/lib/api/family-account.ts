"use client";

import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/lib/api/client";
import { useFamilyAccessFailure } from "@/lib/api/family-household";

// «حسابي» (PWA-3B.5, docs/11 §23a): the signed-in household head's own
// account facts that /family/me does not carry. The server resolves the
// account from the session; nothing here sends an identifier. Kept in the
// query cache only (cleared on logout), never in browser storage.

/** The CURRENT mobile trust state, as decided by the server (never history). */
export type FamilyMobileTrustState = "TRUSTED" | "STALE" | "REVOKED" | "UNVERIFIED" | "NO_MOBILE" | "UNAVAILABLE";

export type FamilyAccount = {
  /** When the account was activated (ISO 8601); null = not recorded. */
  activated_at: string | null;
  mobile: {
    state: FamilyMobileTrustState;
    /** "05*****567" — the same mask «بياناتي الشخصية» shows; null = none. */
    masked: string | null;
  };
};

export const FAMILY_ACCOUNT_QUERY_KEY = ["family", "account"] as const;

/** GET /api/v1/family/account. */
export function useFamilyAccountQuery() {
  const query = useQuery({
    queryKey: FAMILY_ACCOUNT_QUERY_KEY,
    queryFn: async () => (await apiClient.get<{ data: FamilyAccount }>("/api/v1/family/account")).data,
    staleTime: 60 * 1000,
    retry: false,
  });
  useFamilyAccessFailure(query.error);

  return query;
}
