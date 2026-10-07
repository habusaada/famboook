"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/lib/api/client";

// Staff management of a Family's Digital Family Card (PWA-8.2, docs/06 §22b):
// view, issue, revoke, reissue — each behind its own family-card.*
// permission on the server. Never a token or QR here: Staff reprint arrives
// with the PDF (PWA-8.3).

export type FamilyCardStatus = "ACTIVE" | "REVOKED";
export type FamilyCardRevokeReason = "REISSUED" | "ADMINISTRATIVE" | "COMPROMISED";
/** The reasons a Staff revocation may choose; REISSUED is set by the reissue action only. */
export type StaffRevokeReason = Exclude<FamilyCardRevokeReason, "REISSUED">;

export type StaffFamilyCard = {
  credential_number: string;
  status: FamilyCardStatus;
  issued_at: string;
  /** Display name; null = issued by the system (the Family Portal). */
  issued_by: string | null;
  revoked_at: string | null;
  revoked_by: string | null;
  revoke_reason: FamilyCardRevokeReason | null;
};

export type StaffFamilyCardState = { active: StaffFamilyCard | null; history: StaffFamilyCard[] };

export const staffFamilyCardKey = (familyCode: string) => ["families", familyCode, "card"] as const;

function path(familyCode: string, suffix = "") {
  return `/api/v1/families/${encodeURIComponent(familyCode)}/card${suffix}`;
}

export function useStaffFamilyCard(familyCode: string, enabled: boolean) {
  return useQuery({
    queryKey: staffFamilyCardKey(familyCode),
    queryFn: async () => (await apiClient.get<{ data: StaffFamilyCardState }>(path(familyCode))).data,
    enabled: enabled && familyCode.length > 0,
    retry: false,
  });
}

/** Every response carries the authoritative state; the activity timeline changes too. */
function useCardMutation<TPayload>(familyCode: string, send: (payload: TPayload) => Promise<{ data: StaffFamilyCardState }>) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: send,
    onSuccess: (response) => {
      queryClient.setQueryData(staffFamilyCardKey(familyCode), response.data);
      void queryClient.invalidateQueries({ queryKey: ["families", familyCode] });
    },
    // A refusal may mean another operator changed the card: re-read it.
    onError: () => void queryClient.invalidateQueries({ queryKey: staffFamilyCardKey(familyCode) }),
  });
}

export function useIssueFamilyCard(familyCode: string) {
  return useCardMutation<void>(familyCode, () => apiClient.post(path(familyCode), {}));
}

export function useRevokeFamilyCard(familyCode: string) {
  return useCardMutation<{ reason: StaffRevokeReason }>(familyCode, (payload) => apiClient.post(path(familyCode, "/revoke"), payload));
}

export function useReissueFamilyCard(familyCode: string) {
  return useCardMutation<void>(familyCode, () => apiClient.post(path(familyCode, "/reissue"), {}));
}
