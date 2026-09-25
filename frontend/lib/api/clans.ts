"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/lib/api/client";
import type {
  Branch,
  BranchGroup,
  Clan,
  CreateBranchGroupPayload,
  CreateBranchPayload,
  CreateClanPayload,
  UpdateBranchGroupPayload,
  UpdateBranchPayload,
  UpdateClanPayload,
} from "@/lib/types/api/clan";

const CLANS_KEY = ["reference", "clans"] as const;

// Active Clans with their active Branch Groups and Branches — what may be
// selected for a Family (permission clan.view).
export function useClans() {
  return useQuery({
    queryKey: [...CLANS_KEY, "active"],
    queryFn: () => apiClient.get<{ data: Clan[] }>("/api/v1/reference/clans"),
    staleTime: 5 * 60 * 1000,
  });
}

// The full tree including inactive items, with family counts
// (Administration screen, permission clan.manage).
export function useClanTree() {
  return useQuery({
    queryKey: [...CLANS_KEY, "all"],
    queryFn: () =>
      apiClient.get<{ data: Clan[] }>("/api/v1/reference/clans?include_inactive=1"),
  });
}

function useStructureMutation<TVars>(request: (vars: TVars) => Promise<unknown>) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: request,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: CLANS_KEY });
      // Family displays embed Clan/Branch names.
      queryClient.invalidateQueries({ queryKey: ["families"] });
    },
  });
}

export function useCreateClan() {
  return useStructureMutation((payload: CreateClanPayload) =>
    apiClient.post<{ data: Clan }>("/api/v1/clans", payload)
  );
}

export function useUpdateClan() {
  return useStructureMutation(({ id, ...payload }: UpdateClanPayload & { id: string }) =>
    apiClient.patch<{ data: Clan }>(`/api/v1/clans/${id}`, payload)
  );
}

export function useCreateBranchGroup() {
  return useStructureMutation(
    ({ clanId, ...payload }: CreateBranchGroupPayload & { clanId: string }) =>
      apiClient.post<{ data: BranchGroup }>(`/api/v1/clans/${clanId}/branch-groups`, payload)
  );
}

export function useUpdateBranchGroup() {
  return useStructureMutation(({ id, ...payload }: UpdateBranchGroupPayload & { id: string }) =>
    apiClient.patch<{ data: BranchGroup }>(`/api/v1/branch-groups/${id}`, payload)
  );
}

export function useCreateBranch() {
  return useStructureMutation(
    ({ groupId, ...payload }: CreateBranchPayload & { groupId: string }) =>
      apiClient.post<{ data: Branch }>(`/api/v1/branch-groups/${groupId}/branches`, payload)
  );
}

export function useUpdateBranch() {
  return useStructureMutation(({ id, ...payload }: UpdateBranchPayload & { id: string }) =>
    apiClient.patch<{ data: Branch }>(`/api/v1/branches/${id}`, payload)
  );
}
