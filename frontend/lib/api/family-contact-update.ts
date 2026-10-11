"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/lib/api/client";
import type { ChangeRequestStatus } from "@/lib/types/api/change-request";

// Mobile CONTACT_UPDATE (docs/11 FP-ADR-079): the household head changes a
// member's mobile — or their own — with a code sent to the new number (and,
// for their own TRUSTED number, the current password and a code sent to the
// old number). The server decides everything; responses carry MASKED
// numbers and timers only. Numbers, codes and the password live in the
// form's memory and the request body only — never in browser storage, and
// the mutations are not kept once settled (gcTime 0).

const PATH = "/api/v1/family/contact-updates";

/** One code's destination (masked) and timers. */
export type ContactUpdateCode = {
  masked: string | null;
  verified: boolean;
  expires_in_seconds: number;
  resend_after_seconds: number;
  can_resend: boolean;
};

export type ContactUpdateVerification = {
  /** The head's own number. */
  self: boolean;
  /** The head's TRUSTED number moves to the new one (needs the old-number code). */
  trust_transfer: boolean;
  new_mobile: ContactUpdateCode | null;
  old_mobile: ContactUpdateCode | null;
};

export type ContactUpdateState = {
  id: string;
  request_code: string;
  status: ChangeRequestStatus;
  replayed: boolean;
  verification: ContactUpdateVerification;
};

export type ContactUpdateResult = {
  id: string;
  request_code: string;
  status: ChangeRequestStatus;
  replayed: boolean;
  trust_transferred: boolean;
};

export type ContactUpdateStart = {
  member_ref: string;
  mobile: string;
  current_password?: string;
  client_reference: string;
};

export function useStartContactUpdate() {
  return useMutation({
    mutationFn: async (input: ContactUpdateStart) => (await apiClient.post<{ data: ContactUpdateState }>(PATH, input)).data,
    retry: false,
    gcTime: 0,
  });
}

export function useCompleteContactUpdate() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ id, code, oldCode }: { id: string; code: string; oldCode?: string }) =>
      (await apiClient.post<{ data: ContactUpdateResult }>(`${PATH}/${encodeURIComponent(id)}/verify`, oldCode ? { code, old_code: oldCode } : { code })).data,
    retry: false,
    gcTime: 0,
    onSuccess: () => {
      // The registry changed: the members, the own data, the account and «طلباتي».
      for (const key of [["family", "change-requests"], ["family", "household"], ["family", "self"], ["family", "account"]]) {
        void queryClient.invalidateQueries({ queryKey: key });
      }
    },
  });
}

export function useResendContactUpdateCode() {
  return useMutation({
    mutationFn: async ({ id, target }: { id: string; target: "new" | "old" }) =>
      (await apiClient.post<{ data: ContactUpdateState }>(`${PATH}/${encodeURIComponent(id)}/resend`, { target })).data,
    retry: false,
    gcTime: 0,
  });
}
