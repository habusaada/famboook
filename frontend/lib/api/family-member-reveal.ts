"use client";

import { apiClient } from "@/lib/api/client";
import type { SelfRevealField } from "@/lib/api/family-self";

/**
 * POST /api/v1/family/household/members/{memberRef}/reveal (PWA-3B.4) — ONE
 * full value of ANOTHER household member, named only by its opaque
 * member_ref (FU-13). Deliberately a plain request, never a query or a
 * mutation: the value must never enter the query or mutation cache. The
 * caller keeps it in transient component state only. Only the field code is
 * sent in the body; the reference is never put in a query string.
 */
export async function revealMemberValue(memberRef: string, field: SelfRevealField): Promise<string | null> {
  const response = await apiClient.post<{ data: { field: SelfRevealField; value: string | null } }>(
    `/api/v1/family/household/members/${encodeURIComponent(memberRef)}/reveal`,
    { field }
  );

  return response.data.value;
}
