"use client";

import { keepPreviousData, useMutation, useQuery } from "@tanstack/react-query";
import { ApiError, apiClient } from "@/lib/api/client";
import type { PaginatedResponse } from "@/lib/types/api/family";
import type {
  CivilImportAuditEvent,
  CivilImportFileLocation,
  CivilImportIssueCount,
  CivilImportIssueLocation,
  CivilImportOverview,
  CivilImportPageMeta,
  CivilImportPhase,
  CivilImportRelease,
  CivilImportRun,
  CivilImportRunDetail,
  CivilImportRunStatus,
} from "@/lib/types/api/civil-registry";

// FB-CIVIL-01A-UI U1 — read-only, metadata-only civil identity import
// monitoring (docs/adr/FB-CIVIL-01A-UI-API-AND-SCREENS.md §2). No hook here
// starts, changes or publishes an import; the API authorizes every call.

const BASE = "/api/v1/civil-registry";

export const civilImportKeys = {
  all: ["civil-registry"] as const,
  overview: () => [...civilImportKeys.all, "overview"] as const,
  runs: (page: number, status: string | null) => [...civilImportKeys.all, "runs", status, page] as const,
  run: (id: string) => [...civilImportKeys.all, "run", id] as const,
  issues: (id: string) => [...civilImportKeys.all, "run", id, "issues"] as const,
  issueLocations: (id: string, code: string, page: number) => [...civilImportKeys.all, "run", id, "issue-locations", code, page] as const,
  releases: (page: number) => [...civilImportKeys.all, "releases", page] as const,
  release: (id: number) => [...civilImportKeys.all, "release", id] as const,
  audit: (run: string | null, page: number) => [...civilImportKeys.all, "audit", run, page] as const,
};

/** Phases in which an operation is running (D-UI-13). Waiting phases (STAGED, PREPARED, VERIFIED) are idle. */
export const ACTIVE_PHASES: ReadonlySet<CivilImportPhase> = new Set<CivilImportPhase>(["STAGING", "PREPARING"]);

export const CIVIL_IMPORT_POLL_MS = 5000;

/**
 * D-UI-13: refresh every 5 s only while an operation is active. Visibility is
 * enforced by TanStack Query (refetchIntervalInBackground: false pauses the
 * interval in hidden tabs). After a failed read, polling stops until the page
 * is reloaded or the user retries.
 */
export function civilImportPollInterval(phases: readonly (CivilImportPhase | null | undefined)[], lastReadFailed = false): number | false {
  if (lastReadFailed) return false;
  return phases.some((phase) => phase != null && ACTIVE_PHASES.has(phase)) ? CIVIL_IMPORT_POLL_MS : false;
}

/** The fixed code of a refusal (e.g. MONITOR_NOT_CONFIGURED), if any. */
export function civilImportErrorCode(error: unknown): string | null {
  if (!(error instanceof ApiError) || !error.payload || typeof error.payload !== "object") return null;
  const code = (error.payload as { code?: unknown }).code;
  return typeof code === "string" ? code : null;
}

export function useCivilImportOverview() {
  return useQuery({
    queryKey: civilImportKeys.overview(),
    queryFn: async () => (await apiClient.get<{ data: CivilImportOverview }>(`${BASE}/overview`)).data,
    retry: false,
    refetchInterval: (query) => civilImportPollInterval([query.state.data?.current_run?.phase], query.state.error !== null),
    refetchIntervalInBackground: false,
  });
}

export function useCivilImportRuns(page: number, status: CivilImportRunStatus | null = null) {
  return useQuery({
    queryKey: civilImportKeys.runs(page, status),
    queryFn: () => apiClient.get<PaginatedResponse<CivilImportRun>>(`${BASE}/imports?page=${page}${status ? `&status=${status}` : ""}`),
    placeholderData: keepPreviousData,
    retry: false,
    refetchInterval: (query) =>
      civilImportPollInterval(
        (query.state.data?.data ?? []).map((r) => r.phase),
        query.state.error !== null,
      ),
    refetchIntervalInBackground: false,
  });
}

export function useCivilImportRun(id: string) {
  return useQuery({
    queryKey: civilImportKeys.run(id),
    queryFn: async () => (await apiClient.get<{ data: CivilImportRunDetail }>(`${BASE}/imports/${encodeURIComponent(id)}`)).data,
    enabled: id.length > 0,
    retry: false,
    refetchInterval: (query) => civilImportPollInterval([query.state.data?.phase], query.state.error !== null),
    refetchIntervalInBackground: false,
  });
}

export function useCivilImportIssues(id: string) {
  return useQuery({
    queryKey: civilImportKeys.issues(id),
    queryFn: async () => (await apiClient.get<{ data: CivilImportIssueCount[] }>(`${BASE}/imports/${encodeURIComponent(id)}/issues`)).data,
    enabled: id.length > 0,
    retry: false,
  });
}

/**
 * File names (civil-import.locations.view, D-UI-8). Fetched only on an
 * explicit request: each read is audited on the server.
 */
export function useRevealFileLocations(id: string) {
  return useMutation({
    mutationFn: async () =>
      (await apiClient.get<{ data: CivilImportFileLocation[] }>(`${BASE}/imports/${encodeURIComponent(id)}/files/locations`)).data,
  });
}

/** Issue file names and line numbers (civil-import.locations.view); enabled on explicit request only, audited. */
export function useCivilImportIssueLocations(id: string, code: string | null, page: number) {
  return useQuery({
    queryKey: civilImportKeys.issueLocations(id, code ?? "", page),
    queryFn: () =>
      apiClient.get<{
        data: CivilImportIssueLocation[];
        meta: CivilImportPageMeta;
      }>(`${BASE}/imports/${encodeURIComponent(id)}/issues/locations?code=${encodeURIComponent(code ?? "")}&page=${page}`),
    enabled: id.length > 0 && code !== null,
    retry: false,
    staleTime: Infinity,
  });
}

export function useCivilImportReleases(page: number) {
  return useQuery({
    queryKey: civilImportKeys.releases(page),
    queryFn: () => apiClient.get<PaginatedResponse<CivilImportRelease>>(`${BASE}/releases?page=${page}`),
    placeholderData: keepPreviousData,
    retry: false,
  });
}

export function useCivilImportRelease(id: number | null) {
  return useQuery({
    queryKey: civilImportKeys.release(id ?? 0),
    queryFn: async () => (await apiClient.get<{ data: CivilImportRelease }>(`${BASE}/releases/${id}`)).data,
    enabled: id !== null && id > 0,
    retry: false,
  });
}

export function useCivilImportAudit(run: string | null, page: number, enabled = true) {
  return useQuery({
    queryKey: civilImportKeys.audit(run, page),
    queryFn: () =>
      apiClient.get<PaginatedResponse<CivilImportAuditEvent>>(`${BASE}/audit?page=${page}${run ? `&run=${encodeURIComponent(run)}` : ""}`),
    placeholderData: keepPreviousData,
    retry: false,
    enabled,
  });
}
