"use client";

import { apiClient } from "@/lib/api/client";

// Public Digital Family Card verification (docs/11 §19, FP-ADR-070). The
// browser posts the token in the BODY — never in an API URL — so the API's
// per-IP limiter sees the real client. The token is not stored anywhere on
// the client (no query cache, no browser storage).

/** Exactly the fields the public page may show (PFP-010). */
export type VerifiedFamilyCard = {
  type: "FAMILY";
  credential_number: string;
  family_code: string;
  issued_at: string;
  clan: string | null;
  branch: string | null;
  /** The current head shortened to first + last word; null = no current head. */
  head_name: string | null;
};

export async function verifyCredential(token: string): Promise<VerifiedFamilyCard> {
  return (await apiClient.post<{ data: VerifiedFamilyCard }>("/api/v1/credentials/verify", { token })).data;
}
