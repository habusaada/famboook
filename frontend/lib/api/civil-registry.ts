"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
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
  CivilImportRequest,
  CivilImportRequestStatus,
  CivilImportRun,
  CivilImportRunDetail,
  CivilImportRunStatus,
  CivilImportSource,
} from "@/lib/types/api/civil-registry";

// FB-CIVIL-01A-UI — civil identity import management, metadata only
// (docs/adr/FB-CIVIL-01A-UI-API-AND-SCREENS.md §2–§3). U1 reads; U2 records
// execution REQUESTS (202) that the dedicated worker executes — no hook here
// executes or publishes anything. The API authorizes every call.

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
  sources: () => [...civilImportKeys.all, "sources"] as const,
  requests: (run: string | null, page: number) => [...civilImportKeys.all, "requests", run, page] as const,
  request: (id: string) => [...civilImportKeys.all, "request", id] as const,
};

/** Request statuses during which the worker may still change state (D-UI-13). */
export const ACTIVE_REQUEST_STATUSES: ReadonlySet<CivilImportRequestStatus> = new Set<CivilImportRequestStatus>(["QUEUED", "RUNNING"]);

/** Phases in which an operation is running (D-UI-13). Waiting phases (STAGED, PREPARED, VERIFIED) are idle. */
export const ACTIVE_PHASES: ReadonlySet<CivilImportPhase> = new Set<CivilImportPhase>(["STAGING", "PREPARING"]);

export const CIVIL_IMPORT_POLL_MS = 5000;

/**
 * D-UI-13: refresh every 5 s only while an operation is active — a request
 * QUEUED or RUNNING, or a staging / preparation in progress. Visibility is
 * enforced by TanStack Query (refetchIntervalInBackground: false pauses the
 * interval in hidden tabs). After a failed read, polling stops until the page
 * is reloaded or the user retries.
 */
export function civilImportPollInterval(
  phases: readonly (CivilImportPhase | null | undefined)[],
  lastReadFailed = false,
  requestActive = false,
): number | false {
  if (lastReadFailed) return false;
  return requestActive || phases.some((phase) => phase != null && ACTIVE_PHASES.has(phase)) ? CIVIL_IMPORT_POLL_MS : false;
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
    refetchInterval: (query) =>
      civilImportPollInterval([query.state.data?.current_run?.phase], query.state.error !== null, query.state.data?.active_request != null),
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
    refetchInterval: (query) =>
      civilImportPollInterval([query.state.data?.phase], query.state.error !== null, query.state.data?.active_request != null),
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

// ---------------------------------------------------------------- U2: execution requests

export function useCivilImportSources(enabled = true) {
  return useQuery({
    queryKey: civilImportKeys.sources(),
    queryFn: async () => (await apiClient.get<{ data: CivilImportSource[] }>(`${BASE}/sources`)).data,
    enabled,
    retry: false,
  });
}

export function useCivilImportRequests(run: string | null, page: number) {
  return useQuery({
    queryKey: civilImportKeys.requests(run, page),
    queryFn: () =>
      apiClient.get<PaginatedResponse<CivilImportRequest>>(`${BASE}/requests?page=${page}${run ? `&run=${encodeURIComponent(run)}` : ""}`),
    placeholderData: keepPreviousData,
    retry: false,
    refetchInterval: (query) =>
      civilImportPollInterval(
        [],
        query.state.error !== null,
        (query.state.data?.data ?? []).some((r) => ACTIVE_REQUEST_STATUSES.has(r.status)),
      ),
    refetchIntervalInBackground: false,
  });
}

/** One request, polled every 5 s while it is QUEUED or RUNNING and the page is visible. */
export function useCivilImportRequest(id: string | null) {
  return useQuery({
    queryKey: civilImportKeys.request(id ?? ""),
    queryFn: async () => (await apiClient.get<{ data: CivilImportRequest }>(`${BASE}/requests/${encodeURIComponent(id ?? "")}`)).data,
    enabled: id !== null && id.length > 0,
    retry: false,
    refetchInterval: (query) =>
      civilImportPollInterval(
        [],
        query.state.error !== null,
        query.state.data != null && ACTIVE_REQUEST_STATUSES.has(query.state.data.status),
      ),
    refetchIntervalInBackground: false,
  });
}

/** What the operator asks the worker to do; each maps to one endpoint (202 = recorded, not executed). */
export type CivilImportCommand =
  | { kind: "VALIDATE_SOURCE"; sourceId: string }
  | { kind: "STAGE"; sourceId: string }
  | { kind: "ABORT"; runId: string; reasonCode: CivilImportAbortReason }
  | { kind: "ASSESS"; runId: string }
  | { kind: "PREPARE"; runId: string }
  | { kind: "VERIFY"; releaseId: number };

export type CivilImportAbortReason = "OPERATOR_DECISION" | "SOURCE_REPLACED" | "DATA_PROBLEM";

export function civilImportCommandPath(command: CivilImportCommand): { path: string; body: Record<string, string> } {
  switch (command.kind) {
    case "VALIDATE_SOURCE":
      return { path: `${BASE}/sources/${encodeURIComponent(command.sourceId)}/validate`, body: {} };
    case "STAGE":
      return { path: `${BASE}/imports`, body: { source_id: command.sourceId } };
    case "ABORT":
      return { path: `${BASE}/imports/${encodeURIComponent(command.runId)}/abort`, body: { reason_code: command.reasonCode } };
    case "ASSESS":
      return { path: `${BASE}/imports/${encodeURIComponent(command.runId)}/assess`, body: {} };
    case "PREPARE":
      return { path: `${BASE}/imports/${encodeURIComponent(command.runId)}/prepare`, body: {} };
    case "VERIFY":
      return { path: `${BASE}/releases/${command.releaseId}/verify`, body: {} };
  }
}

/** A client idempotency key: a retried submission never queues a second request. */
export function newIdempotencyKey(): string {
  return `ui-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
}

/** Records an execution request (the worker executes it). Refreshes every civil-registry read on success. */
export function useQueueCivilImportRequest() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async ({ command, idempotencyKey }: { command: CivilImportCommand; idempotencyKey: string }) => {
      const { path, body } = civilImportCommandPath(command);
      return (await apiClient.post<{ data: CivilImportRequest }>(path, body, { "Idempotency-Key": idempotencyKey })).data;
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: civilImportKeys.all }),
  });
}

/** Cancels a QUEUED request, or asks the worker to stop a RUNNING one at its next committed chunk. */
export function useCancelCivilImportRequest() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (id: string) =>
      (await apiClient.delete<{ data: CivilImportRequest }>(`${BASE}/requests/${encodeURIComponent(id)}`)).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: civilImportKeys.all }),
  });
}
