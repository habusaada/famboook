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

/** famboook-family-card-{credential_number}.pdf — the public card number only (as the server names it). */
export function familyCardPdfFilename(credentialNumber: string): string {
  return `famboook-family-card-${credentialNumber}.pdf`;
}

/**
 * GET /api/v1/family/card/pdf (PWA-8.3): the printable card of the EXISTING
 * ACTIVE credential — a GET, never an issuance. The bytes stay a transient
 * Blob: never in the query cache or browser storage. A temporary object URL
 * hands them to the browser's download and is revoked right after.
 */
export async function downloadFamilyCardPdf(credentialNumber: string): Promise<void> {
  const blob = await apiClient.getBlob("/api/v1/family/card/pdf", "application/pdf");
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = familyCardPdfFilename(credentialNumber);
  link.rel = "noopener";
  document.body.appendChild(link);
  try {
    link.click();
  } finally {
    link.remove();
    // Shortly after the click, so every browser has started the download.
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
  }
}

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
