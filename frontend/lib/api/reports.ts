"use client";

import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { apiClient } from "@/lib/api/client";
import type { Clan } from "@/lib/types/api/clan";
import type { ReportKey, ReportMeta } from "@/lib/types/api/reports";

export type ReportParams = Record<string, string | number | undefined>;

function query(params: ReportParams): string {
  const search = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== "") search.set(key, String(value));
  }
  return search.toString();
}

/** Which reports the user may open, and whether they may export. */
export function useReportMeta() {
  return useQuery({
    queryKey: ["reports", "meta"],
    queryFn: () => apiClient.get<{ data: ReportMeta }>("/api/v1/reports/meta"),
    staleTime: 5 * 60 * 1000,
  });
}

export function useReportScopeOptions() {
  return useQuery({
    queryKey: ["reports", "scope-options"],
    queryFn: () => apiClient.get<{ data: Clan[] }>("/api/v1/reports/scope-options"),
    staleTime: 5 * 60 * 1000,
  });
}

/** One report endpoint (or drill-down) for the given scope and filters. */
export function useReport<T>(path: string, params: ReportParams, enabled = true) {
  return useQuery({
    queryKey: ["reports", path, params],
    queryFn: () => apiClient.get<{ data: T }>(`/api/v1/reports/${path}?${query(params)}`),
    enabled,
    placeholderData: keepPreviousData,
  });
}

/**
 * Downloads a report XLSX through the authenticated API (generated on
 * request; no public URL). Returns an HTTP status on failure.
 */
export async function downloadReport(report: ReportKey, params: ReportParams): Promise<number | null> {
  const base = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";
  const response = await fetch(`${base}/api/v1/reports/${report}/export?${query(params)}`, {
    credentials: "include",
    headers: { Accept: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" },
  });
  if (!response.ok) return response.status;

  // Same name as the server's Content-Disposition (not CORS-exposed).
  const today = new Date();
  const pad = (n: number) => String(n).padStart(2, "0");
  const filename = `${report}-report-${today.getFullYear()}-${pad(today.getMonth() + 1)}-${pad(today.getDate())}.xlsx`;
  const url = URL.createObjectURL(await response.blob());
  const link = document.createElement("a");
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
  return null;
}
