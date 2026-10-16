"use client";

import { useQuery } from "@tanstack/react-query";
import { ApiError, apiClient } from "@/lib/api/client";
import type { GazaLocation, GazaLocationDetails } from "@/lib/types/api/gaza-location";

// The Gaza geographic reference (FB-GEO-01), read through either API:
// - "staff": /api/v1/gaza-locations (`reference-data.view` or
//   `residence.update`; staff.side);
// - "family": the read-only Family PWA mirrors (API-D2) under
//   /api/v1/family/change-requests/gaza-locations (family-portal.access +
//   family.context); same data, no landmark type.
// The source is part of every query key, so the two caches never mix. The
// reference changes only through the official import, so the reference-data
// cache convention (5 minutes) applies.

export const GAZA_LOCATIONS_KEY = ["gaza-locations"] as const;

export type GazaLocationSource = "staff" | "family";

const BASE: Record<GazaLocationSource, string> = {
  staff: "/api/v1/gaza-locations",
  family: "/api/v1/family/change-requests/gaza-locations",
};

/** The query key of one list, e.g. for reading names from the cache. */
export function gazaLocationsKey(source: GazaLocationSource, ...rest: (string | null)[]) {
  return [...GAZA_LOCATIONS_KEY, source, ...rest] as const;
}

const STALE_TIME = 5 * 60 * 1000;

// A 404 means "not available" (unknown id, or an inactive parent's
// children): retrying cannot help. Auth failures are never retried either.
// Anything else gets the global single retry.
function retryUnlessNotFound(failureCount: number, error: unknown): boolean {
  if (error instanceof ApiError && [401, 403, 404].includes(error.status)) return false;
  return failureCount < 1;
}

/** Active governorates, north → south. */
export function useGazaGovernorates(source: GazaLocationSource = "staff") {
  return useQuery({
    queryKey: gazaLocationsKey(source, "governorates"),
    queryFn: () => apiClient.get<{ data: GazaLocation[] }>(`${BASE[source]}/governorates`),
    staleTime: STALE_TIME,
    retry: retryUnlessNotFound,
  });
}

/**
 * Active DIRECT children of one parent. One cache entry per parent UUID, so
 * a late response for a previous parent can never be read as this parent's.
 * Disabled until a parent is given.
 */
export function useGazaLocationChildren(parentId: string | null, source: GazaLocationSource = "staff") {
  return useQuery({
    queryKey: gazaLocationsKey(source, "children", parentId),
    queryFn: () =>
      apiClient.get<{ data: GazaLocation[] }>(`${BASE[source]}/${encodeURIComponent(parentId ?? "")}/children`),
    enabled: parentId !== null,
    staleTime: STALE_TIME,
    retry: retryUnlessNotFound,
  });
}

/** One location (active or not) with is_active and its ancestor path. */
export function useGazaLocationDetails(id: string | null, source: GazaLocationSource = "staff") {
  return useQuery({
    queryKey: gazaLocationsKey(source, "detail", id),
    queryFn: () =>
      apiClient.get<{ data: GazaLocationDetails }>(`${BASE[source]}/${encodeURIComponent(id ?? "")}`),
    enabled: id !== null,
    staleTime: STALE_TIME,
    retry: retryUnlessNotFound,
  });
}
