"use client";

import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/lib/api/client";
import { useFamilyAccessFailure } from "@/lib/api/family-household";

// «بطاقة الأسرة الرقمية» (PWA-8.2, docs/11 FP-ADR-070). POST /api/v1/family/card
// ENSURES the Family's card — issued once, lazily, by the server — and
// returns it; nothing here sends an identifier. A POST-backed query: it is
// idempotent, never cached for long (staleTime 0) and cleared on logout.

export type FamilyCard = {
  credential_number: string;
  family_code: string;
  issued_at: string;
  clan: string | null;
  branch: string | null;
  /** The current household head's registered full name (the owner's own card). */
  head_name: string;
  verification_url: string | null;
  /** An SVG data URI; null when the QR cannot be shown right now. */
  qr: string | null;
  qr_available: boolean;
};

export const FAMILY_CARD_QUERY_KEY = ["family", "card"] as const;

export function useFamilyCardQuery() {
  const query = useQuery({
    queryKey: FAMILY_CARD_QUERY_KEY,
    queryFn: async () => (await apiClient.post<{ data: FamilyCard }>("/api/v1/family/card", {})).data,
    staleTime: 0,
    retry: false,
  });
  useFamilyAccessFailure(query.error);

  return query;
}
