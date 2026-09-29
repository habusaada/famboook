"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/lib/api/client";
import type {
  FamilyKeyDecision,
  FamilyKeysPayload,
  ImportBatch,
  ImportBatchDetail,
  ImportColumns,
  ImportMode,
  ProblemRowFilter,
  ProblemRowsPage,
  ReconciliationRowsPage,
  ReconciliationStatus,
} from "@/lib/types/api/imports";

const BASE = "/api/v1/imports/initial-families";
const KEY = ["imports", "initial-families"] as const;

type Detail = { data: ImportBatchDetail };

// Recent batches (import.review).
export function useImportBatches(enabled = true) {
  return useQuery({
    queryKey: KEY,
    queryFn: () => apiClient.get<{ data: ImportBatch[] }>(BASE),
    enabled,
  });
}

export function useImportBatch(id: string | null) {
  return useQuery({
    queryKey: [...KEY, id],
    queryFn: () => apiClient.get<Detail>(`${BASE}/${id}`),
    enabled: id !== null,
  });
}

// Selected worksheet's columns, suggestions and masked samples (import.validate).
export function useImportColumns(id: string | null, worksheet: string | null) {
  return useQuery({
    queryKey: [...KEY, id, "columns", worksheet],
    queryFn: () => apiClient.get<{ data: ImportColumns }>(`${BASE}/${id}/columns`),
    enabled: id !== null && worksheet !== null,
  });
}

export function useImportFamilyKeys(id: string | null, enabled: boolean) {
  return useQuery({
    queryKey: [...KEY, id, "family-keys"],
    queryFn: () => apiClient.get<FamilyKeysPayload>(`${BASE}/${id}/family-keys`),
    enabled: id !== null && enabled,
  });
}

/** Step 4 mutations return the refreshed key list; the batch summary (progress, readiness) is re-fetched. */
function useKeyMutation<TVars>(id: string, request: (vars: TVars) => Promise<FamilyKeysPayload>) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: request,
    onSuccess: (res) => {
      queryClient.setQueryData([...KEY, id, "family-keys"], res);
      queryClient.invalidateQueries({ queryKey: [...KEY, id] });
    },
  });
}

export type ResolveFamilyKeyVars = {
  source_family_key: string;
  decision: FamilyKeyDecision;
  branch_id?: string;
  branch?: { name: string; code: string };
  same_as_key?: string;
};

export function useResolveFamilyKey(id: string) {
  return useKeyMutation(id, (vars: ResolveFamilyKeyVars) => apiClient.put<FamilyKeysPayload>(`${BASE}/${id}/family-keys/resolution`, vars));
}

export function useClearFamilyKey(id: string) {
  return useKeyMutation(id, ({ key }: { key: string }) =>
    apiClient.post<FamilyKeysPayload>(`${BASE}/${id}/family-keys/resolution/clear`, { source_family_key: key })
  );
}

// Explicitly selected keys only; all or nothing.
export function useBulkResolveFamilyKeys(id: string) {
  return useKeyMutation(
    id,
    (vars: { decision: "CREATE_NEW_BRANCH" | "NO_BRANCH"; items: { source_family_key: string; name?: string; code?: string }[] }) =>
      apiClient.post<FamilyKeysPayload>(`${BASE}/${id}/family-keys/bulk`, vars)
  );
}

export function useImportProblemRows(id: string | null, filter: ProblemRowFilter, page: number, enabled: boolean) {
  return useQuery({
    queryKey: [...KEY, id, "rows", filter, page],
    queryFn: () => apiClient.get<ProblemRowsPage>(`${BASE}/${id}/rows?filter=${filter}&page=${page}`),
    enabled: id !== null && enabled,
  });
}

/** Mutations return the batch detail; every batch view is refreshed after them. */
function useBatchMutation<TVars>(request: (vars: TVars) => Promise<Detail>) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: request,
    onSuccess: (res) => {
      queryClient.setQueryData([...KEY, res.data.id], res);
      queryClient.invalidateQueries({ queryKey: KEY });
    },
  });
}

// Steps 1–2: explicit Clan + import mode + workbook (inspection only, no staging).
export function useCreateImportBatch() {
  return useBatchMutation(({ clanCode, mode, file }: { clanCode: string; mode: ImportMode; file: File }) => {
    const form = new FormData();
    form.append("clan_code", clanCode);
    form.append("import_mode", mode);
    form.append("file", file);
    return apiClient.upload<Detail>(BASE, form);
  });
}

export function useReplaceImportWorkbook(id: string) {
  return useBatchMutation(({ file }: { file: File }) => {
    const form = new FormData();
    form.append("file", file);
    return apiClient.upload<Detail>(`${BASE}/${id}/file`, form);
  });
}

export function useSelectImportWorksheet(id: string) {
  return useBatchMutation(({ worksheet }: { worksheet: string }) =>
    apiClient.put<Detail>(`${BASE}/${id}/worksheet`, { worksheet })
  );
}

// Step 5: compare staged rows with the registry (never a registry write).
export function useReconcileImportBatch(id: string) {
  return useBatchMutation(() => apiClient.post<Detail>(`${BASE}/${id}/reconcile`, {}));
}

export function useReconciliationRows(id: string | null, status: ReconciliationStatus | "", page: number, enabled: boolean) {
  return useQuery({
    queryKey: [...KEY, id, "reconciliation", status, page],
    queryFn: () => apiClient.get<ReconciliationRowsPage>(`${BASE}/${id}/reconciliation?page=${page}${status ? `&status=${status}` : ""}`),
    enabled: id !== null && enabled,
  });
}

// Step 3: confirm the mapping — this is when rows are staged.
export function useConfirmImportMapping(id: string) {
  return useBatchMutation(({ mapping, ignored }: { mapping: Record<string, string | null>; ignored: string[] }) =>
    apiClient.post<Detail>(`${BASE}/${id}/mapping`, { mapping, ignored })
  );
}
