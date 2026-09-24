"use client";

import {
  keepPreviousData,
  useInfiniteQuery,
  useMutation,
  useQuery,
  useQueryClient,
} from "@tanstack/react-query";
import { apiClient } from "@/lib/api/client";
import type {
  AssistanceFilters,
  BeneficiaryList,
  BeneficiaryListDetail,
  DeliveryPayload,
  DeliveryVerification,
  ExportField,
  ExportFieldsResponse,
  FamilyAssistanceRow,
  ListPreview,
  AssistanceListResponse,
  AssistancePayload,
  AssistanceResponse,
  NominationResult,
  NomineeCandidate,
  NomineesResponse,
  TargetingCriteria,
  TargetingPreviewResponse,
} from "@/lib/types/api/assistance";

const listKey = ["assistances", "list"];
const detailKey = (id: string) => ["assistances", "detail", id];
const nomineesKey = (id: string) => ["assistances", "nominees", id];

const path = (id: string, suffix = "") => `/api/v1/assistances/${encodeURIComponent(id)}${suffix}`;

export function useAssistances(filters: AssistanceFilters) {
  return useInfiniteQuery({
    queryKey: [...listKey, filters],
    queryFn: ({ pageParam }) => {
      const params = new URLSearchParams({ page: String(pageParam) });
      for (const [key, value] of Object.entries(filters)) if (value) params.set(key, value);
      return apiClient.get<AssistanceListResponse>(`/api/v1/assistances?${params}`);
    },
    initialPageParam: 1,
    getNextPageParam: (last) =>
      last.meta.current_page < last.meta.last_page ? last.meta.current_page + 1 : undefined,
    retry: false,
  });
}

export function useAssistance(id: string) {
  return useQuery({
    queryKey: detailKey(id),
    queryFn: () => apiClient.get<AssistanceResponse>(path(id)),
    enabled: id.length > 0,
    retry: false,
  });
}

function useAssistanceWrite<TPayload>(request: (payload: TPayload) => Promise<AssistanceResponse>) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: request,
    onSuccess: (response) => {
      queryClient.setQueryData(detailKey(response.data.id), response);
      queryClient.invalidateQueries({ queryKey: listKey });
    },
  });
}

export function useCreateAssistance() {
  return useAssistanceWrite((payload: AssistancePayload) =>
    apiClient.post<AssistanceResponse>("/api/v1/assistances", payload)
  );
}

export function useUpdateAssistance(id: string) {
  return useAssistanceWrite((payload: AssistancePayload) =>
    apiClient.patch<AssistanceResponse>(path(id), payload)
  );
}

export function useOpenAssistance(id: string) {
  return useAssistanceWrite(() => apiClient.post<AssistanceResponse>(path(id, "/open"), {}));
}

/**
 * Explicitly requested preview (never on every field change). Read-only on
 * the server: nothing is persisted.
 */
export function useTargetingPreview(id: string) {
  return useMutation({
    mutationFn: ({ criteria, page }: { criteria: TargetingCriteria; page: number }) =>
      apiClient.post<TargetingPreviewResponse>(path(id, "/targeting-preview"), { criteria, page }),
  });
}

export function useNominees(id: string, includeRemoved: boolean, page: number) {
  return useQuery({
    queryKey: [...nomineesKey(id), { includeRemoved, page }],
    queryFn: () =>
      apiClient.get<NomineesResponse>(
        path(id, `/nominees?page=${page}${includeRemoved ? "&include_removed=1" : ""}`)
      ),
    placeholderData: keepPreviousData,
    retry: false,
  });
}

export function useNomineeCandidates(id: string, search: string) {
  const term = search.trim();
  return useQuery({
    queryKey: ["assistances", "candidates", id, term],
    queryFn: () =>
      apiClient.get<{ data: NomineeCandidate[] }>(
        path(id, `/nominee-candidates?search=${encodeURIComponent(term)}`)
      ),
    enabled: term.length >= 2,
    retry: false,
  });
}

// Every nomination change refreshes the nominee list, the derived counts
// on the Assistance, and any open preview's "already nominated" flags.
function useNomination<TPayload, TResult>(id: string, request: (payload: TPayload) => Promise<TResult>) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: request,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: nomineesKey(id) });
      queryClient.invalidateQueries({ queryKey: detailKey(id) });
      queryClient.invalidateQueries({ queryKey: listKey });
      queryClient.invalidateQueries({ queryKey: ["assistances", "candidates", id] });
      // Nominations appear on family timelines.
      queryClient.invalidateQueries({ queryKey: ["families"] });
    },
  });
}

export function useNominateManually(id: string) {
  return useNomination(id, (payload: { family_code: string; person_code: string | null }) =>
    apiClient.post<unknown>(path(id, "/nominees/manual"), payload)
  );
}

export function useNominateFromNeeds(id: string) {
  return useNomination(id, (needIds: string[]) =>
    apiClient.post<NominationResult>(path(id, "/nominees/from-needs"), { need_ids: needIds })
  );
}

export function useNominateFromTargeting(id: string) {
  return useNomination(id, (payload: { family_codes: string[]; criteria: TargetingCriteria }) =>
    apiClient.post<NominationResult>(path(id, "/nominees/from-targeting"), payload)
  );
}

export function useRemoveNominee(id: string) {
  return useNomination(id, (nomineeId: string) =>
    apiClient.post<unknown>(path(id, `/nominees/${encodeURIComponent(nomineeId)}/remove`), {})
  );
}

// ---------------------------------------------------------------------------
// V1-B execution: approval, INTERNAL delivery, EXTERNAL lists.

function useExecution<TPayload, TResult>(id: string, request: (payload: TPayload) => Promise<TResult>) {
  // Same refresh set as nominations (nominees, counts, family timelines),
  // plus issued lists and family assistance history.
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: request,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: nomineesKey(id) });
      queryClient.invalidateQueries({ queryKey: detailKey(id) });
      queryClient.invalidateQueries({ queryKey: listKey });
      queryClient.invalidateQueries({ queryKey: ["assistances", "lists", id] });
      queryClient.invalidateQueries({ queryKey: ["families"] });
    },
  });
}

export function useApproveNominee(id: string) {
  return useExecution(id, (nomineeId: string) =>
    apiClient.post<unknown>(path(id, `/nominees/${encodeURIComponent(nomineeId)}/approve`), {})
  );
}

export function useBulkApprove(id: string) {
  return useExecution(id, (nomineeIds: string[]) =>
    apiClient.post<{ data: { approved: number } }>(path(id, "/nominees/bulk-approve"), { nominee_ids: nomineeIds })
  );
}

export function useRejectNominee(id: string, nomineeId: string) {
  return useExecution(id, (payload: { rejection_reason: string }) =>
    apiClient.post<unknown>(path(id, `/nominees/${encodeURIComponent(nomineeId)}/reject`), payload)
  );
}

/** Identity check only; National IDs travel in the body and are never returned. */
export function useVerifyDelivery(id: string, nomineeId: string) {
  return useMutation({
    mutationFn: (payload: DeliveryPayload) =>
      apiClient.post<{ data: DeliveryVerification }>(
        path(id, `/nominees/${encodeURIComponent(nomineeId)}/delivery/verify`),
        payload
      ),
  });
}

export function useRecordDelivery(id: string, nomineeId: string) {
  return useExecution(id, (payload: DeliveryPayload) =>
    apiClient.post<unknown>(path(id, `/nominees/${encodeURIComponent(nomineeId)}/delivery`), payload)
  );
}

export function useMarkNotDelivered(id: string, nomineeId: string) {
  return useExecution(id, (payload: { not_delivered_reason: string }) =>
    apiClient.post<unknown>(path(id, `/nominees/${encodeURIComponent(nomineeId)}/not-delivered`), payload)
  );
}

export function useReverseDelivery(id: string, deliveryId: string) {
  return useExecution(id, (payload: { reversal_reason: string }) =>
    apiClient.post<unknown>(`/api/v1/assistance-deliveries/${encodeURIComponent(deliveryId)}/reverse`, payload)
  );
}

export function useCompleteAssistance(id: string) {
  return useExecution(id, () => apiClient.post<AssistanceResponse>(path(id, "/complete"), {}));
}

export function useExportFields(id: string, enabled: boolean) {
  return useQuery({
    queryKey: ["assistances", "export-fields", id],
    queryFn: () => apiClient.get<ExportFieldsResponse>(path(id, "/export-fields")),
    enabled,
    retry: false,
  });
}

export function useUpdateExportConfiguration(id: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (fields: ExportField[]) =>
      apiClient.put<ExportFieldsResponse>(path(id, "/export-configuration"), {
        fields: fields.map(({ field_key, column_label }) => ({ field_key, column_label })),
      }),
    onSuccess: (response) => {
      queryClient.setQueryData(["assistances", "export-fields", id], response);
      queryClient.invalidateQueries({ queryKey: detailKey(id) });
    },
  });
}

/** Dynamic preview of the list with current data; persists nothing. */
export function useListPreview(id: string) {
  return useMutation({
    mutationFn: () => apiClient.post<{ data: ListPreview }>(path(id, "/beneficiary-lists/preview"), {}),
  });
}

export function useIssueList(id: string) {
  return useExecution(
    id,
    (payload: { beneficiary_ids: string[]; recipient_organization: string | null; notes: string | null }) =>
      apiClient.post<{ data: BeneficiaryList }>(path(id, "/beneficiary-lists"), payload)
  );
}

export function useBeneficiaryLists(id: string, enabled: boolean) {
  return useQuery({
    queryKey: ["assistances", "lists", id],
    queryFn: () => apiClient.get<{ data: BeneficiaryList[] }>(path(id, "/beneficiary-lists")),
    enabled,
    retry: false,
  });
}

export function useBeneficiaryList(listId: string | null) {
  return useQuery({
    queryKey: ["assistance-lists", listId],
    queryFn: () =>
      apiClient.get<{ data: BeneficiaryListDetail }>(`/api/v1/assistance-beneficiary-lists/${encodeURIComponent(listId!)}`),
    enabled: !!listId,
    retry: false,
  });
}

/**
 * Downloads an issued list's XLSX through the authenticated API (no public
 * URL is ever created). Returns an HTTP status on failure.
 */
export async function downloadBeneficiaryList(list: BeneficiaryList): Promise<number | null> {
  const base = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";
  const response = await fetch(`${base}/api/v1/assistance-beneficiary-lists/${encodeURIComponent(list.id)}/download`, {
    credentials: "include",
    headers: { Accept: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" },
  });
  if (!response.ok) return response.status;

  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = `${list.list_number}-${list.issued_at.slice(0, 10)}.xlsx`;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
  return null;
}

export function useFamilyAssistances(familyCode: string) {
  return useQuery({
    queryKey: ["families", familyCode, "assistances"],
    queryFn: () =>
      apiClient.get<{ data: FamilyAssistanceRow[] }>(`/api/v1/families/${encodeURIComponent(familyCode)}/assistances`),
    enabled: familyCode.length > 0,
    retry: false,
  });
}
