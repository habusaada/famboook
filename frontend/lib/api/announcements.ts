"use client";

import { type QueryClient, keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ApiError, apiClient } from "@/lib/api/client";
import type { CoordinatorFamily } from "@/lib/api/coordinator";
import { FAMILY_ME_QUERY_KEY } from "@/lib/api/family-auth";
import type { FamiliesListResponse, PaginatedResponse } from "@/lib/types/api/family";

// Announcement management (FB-NOTIFY-01C.3c; backend FB-NOTIFY-01B.2b.6c /
// 2b.8a): the SENDER's own announcements. One module for both sides — the
// Staff API (/api/v1/notifications) now, the Coordinator API
// (/api/v1/family/coordinator/notifications) later — with the same request
// and response shapes. Families are named by their public family_code only;
// no internal id is ever sent or read. Nothing is optimistic and no write is
// retried automatically: idempotency is the server's (client_reference for
// drafts, state for publish / cancel / retry).

export type AnnouncementSide = "staff" | "coordinator";

export type AnnouncementStatus = "DRAFT" | "QUEUED" | "PUBLISHING" | "PUBLISHED" | "PUBLISH_FAILED" | "ARCHIVED" | "CANCELLED";
export type AnnouncementLevel = "NORMAL" | "IMPORTANT";
export type AnnouncementAudienceType = "STAFF_FAMILIES" | "STAFF_ALL_ACTIVE" | "COORDINATOR_SCOPE" | "COORDINATOR_SELECTION";
export type AnnouncementAction = "PUBLISH" | "CANCEL" | "RETRY";
export type AnnouncementFailureCode =
  | "SENDER_NOT_AUTHORIZED"
  | "AUDIENCE_INVALID"
  | "AUDIENCE_EMPTY"
  | "EXPIRED_BEFORE_PUBLICATION"
  | "PUBLICATION_ERROR"
  | "STALE_PUBLISHING";
export type StatisticsBasis = "ALL_RECIPIENTS" | "CURRENT_COORDINATOR_SCOPE" | "NO_CURRENT_SCOPE";

export const ANNOUNCEMENT_STATUSES: AnnouncementStatus[] = ["DRAFT", "QUEUED", "PUBLISHING", "PUBLISHED", "PUBLISH_FAILED", "ARCHIVED", "CANCELLED"];

/** The PUBLIC audience grammar of the HTTP API: families by family_code, never an id. */
export type PublicAudience =
  | { type: "STAFF_FAMILIES"; family_codes: string[] }
  | { type: "STAFF_ALL_ACTIVE" }
  | { type: "COORDINATOR_SCOPE" }
  | { type: "COORDINATOR_SELECTION"; family_codes: string[] };

/** AnnouncementResource, summary form (list rows). */
export type AnnouncementSummary = {
  id: string;
  notice_code: string;
  level: AnnouncementLevel;
  status: AnnouncementStatus;
  title: string;
  audience_type: AnnouncementAudienceType | null;
  expires_at: string | null;
  created_at: string | null;
  queued_at: string | null;
  published_at: string | null;
  /** The publication-time recipient count (a historical fact). */
  recipient_count_at_publication: number | null;
  publish_attempts: number;
  publish_failure_code: string | null;
  publish_failed_at: string | null;
  archived_at: string | null;
  cancelled_at: string | null;
  cancellation_reason_code: string | null;
};

/** Aggregate counts only; the acknowledgement figures are null for NORMAL. */
export type AnnouncementStatistics = {
  recipients: number;
  with_account: number;
  without_account: number;
  viewed: number;
  not_viewed: number;
  acknowledged: number | null;
  acknowledged_late: number | null;
  pending_acknowledgement: number | null;
};

/** AnnouncementResource, detail form (GET {id} and every write response). */
export type AnnouncementDetail = AnnouncementSummary & {
  body: string;
  /** How many items each stored criterion selects (a count, never the ids); `[]` when none. */
  audience_selection: Record<string, number> | [];
  statistics_basis: StatisticsBasis | null;
  statistics: AnnouncementStatistics | null;
  /** What the message's STATE allows; permissions are still required. */
  available_actions: AnnouncementAction[];
};

export type AnnouncementDraftInput = {
  title: string;
  body: string;
  level: AnnouncementLevel;
  audience: PublicAudience;
  expires_at: string | null;
  client_reference: string;
};

export type AnnouncementOutcome = { announcement: AnnouncementDetail; replayed: boolean };

export type AnnouncementFilters = { status?: AnnouncementStatus; page?: number };

/** The server's page size limit (AnnouncementIndexRequest::MAX_PER_PAGE). */
export const ANNOUNCEMENT_MAX_PER_PAGE = 50;

/** NotificationAudience::MAX_SELECTED — distinct families per audience. */
export const ANNOUNCEMENT_MAX_FAMILIES = 500;

const BASES: Record<AnnouncementSide, string> = {
  staff: "/api/v1/notifications",
  coordinator: "/api/v1/family/coordinator/notifications",
};

export function announcementBase(side: AnnouncementSide): string {
  return BASES[side];
}

export const announcementKeys = {
  all: (side: AnnouncementSide) => ["announcements", side] as const,
  list: (side: AnnouncementSide, filters: AnnouncementFilters) => ["announcements", side, "list", filters] as const,
  detail: (side: AnnouncementSide, id: string) => ["announcements", side, "detail", id] as const,
};

/** Only the supported list parameters, and only those that are set. */
export function announcementListPath(side: AnnouncementSide, filters: AnnouncementFilters): string {
  const params = new URLSearchParams();
  if (filters.status) params.set("status", filters.status);
  if (filters.page && filters.page > 1) params.set("page", String(filters.page));
  const query = params.toString();
  return query ? `${BASES[side]}?${query}` : BASES[side];
}

/** A publication attempt is in progress: the detail is polled. */
export function isPublicationActive(status: AnnouncementStatus): boolean {
  return status === "QUEUED" || status === "PUBLISHING";
}

/**
 * Detail polling while a publication attempt is active (QUEUED / PUBLISHING),
 * progressive and bounded — the Staff read limit (60 / min per user) is
 * shared by every open tab:
 *
 *   first 2 min after queueing   every 5 s   (prompt feedback)
 *   until 5 min                  every 15 s
 *   until 15 min                 every 30 s
 *   after 15 min                 stopped     (the page offers a manual refresh)
 *
 * After a failed read (429 included) the next one waits at least 60 s.
 * Elapsed time comes from queued_at; a queued_at in the future (clock skew)
 * counts as just queued. TanStack Query stops the interval when the tab is
 * hidden (refetchIntervalInBackground: false) and on unmount, and never runs
 * two reads of the same query at once.
 */
export const ANNOUNCEMENT_POLL_TIERS: readonly { untilMs: number; everyMs: number }[] = [
  { untilMs: 2 * 60 * 1000, everyMs: 5000 },
  { untilMs: 5 * 60 * 1000, everyMs: 15000 },
  { untilMs: 15 * 60 * 1000, everyMs: 30000 },
];
export const ANNOUNCEMENT_POLL_ERROR_BACKOFF_MS = 60 * 1000;

export function announcementPollInterval(detail: AnnouncementDetail | undefined, now = Date.now(), lastReadFailed = false): number | false {
  if (!detail || !isPublicationActive(detail.status)) return false;
  const queued = detail.queued_at ? Date.parse(detail.queued_at) : Number.NaN;
  const elapsed = Number.isFinite(queued) ? Math.max(0, now - queued) : 0;
  const tier = ANNOUNCEMENT_POLL_TIERS.find((t) => elapsed < t.untilMs);
  if (!tier) return false;
  return lastReadFailed ? Math.max(tier.everyMs, ANNOUNCEMENT_POLL_ERROR_BACKOFF_MS) : tier.everyMs;
}

/**
 * A coordinator operation refused with COORDINATOR_SPACE_UNAVAILABLE (the
 * scope was revoked meanwhile): /family/me is re-read so the coordinator
 * flags catch up. The session and the Family context are NOT touched — the
 * account keeps its household and its announcement history.
 */
function refreshCoordinatorFlags(queryClient: QueryClient, side: AnnouncementSide, error: unknown) {
  if (side !== "coordinator" || !(error instanceof ApiError) || error.status !== 403) return;
  const code = error.payload && typeof error.payload === "object" ? (error.payload as { code?: unknown }).code : null;
  if (code === "COORDINATOR_SPACE_UNAVAILABLE") void queryClient.invalidateQueries({ queryKey: FAMILY_ME_QUERY_KEY });
}

export function useAnnouncementsQuery(side: AnnouncementSide, filters: AnnouncementFilters) {
  return useQuery({
    queryKey: announcementKeys.list(side, filters),
    queryFn: () => apiClient.get<PaginatedResponse<AnnouncementSummary>>(announcementListPath(side, filters)),
    placeholderData: keepPreviousData,
    retry: false,
  });
}

/** One own announcement; polled (bounded) while a publication attempt is active. Polling stops on unmount. */
export function useAnnouncementQuery(side: AnnouncementSide, id: string) {
  return useQuery({
    queryKey: announcementKeys.detail(side, id),
    queryFn: async () => (await apiClient.get<{ data: AnnouncementDetail }>(`${BASES[side]}/${encodeURIComponent(id)}`)).data,
    enabled: id.length > 0,
    retry: false,
    refetchInterval: (query) => announcementPollInterval(query.state.data, Date.now(), query.state.error !== null),
    refetchIntervalInBackground: false,
  });
}

/** POST …/audience-preview: how many families the audience reaches now (counts only). */
export function useAudiencePreview(side: AnnouncementSide) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (audience: PublicAudience) =>
      (await apiClient.post<{ data: { recipient_count: number } }>(`${BASES[side]}/audience-preview`, { audience })).data.recipient_count,
    retry: false,
    onError: (error) => refreshCoordinatorFlags(queryClient, side, error),
  });
}

/** POST …: a new DRAFT (201) or the replay of the same client_reference (200). */
export function useCreateAnnouncement(side: AnnouncementSide) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: AnnouncementDraftInput): Promise<AnnouncementOutcome> => {
      const response = await apiClient.post<{ data: AnnouncementDetail; meta?: { replayed?: boolean } }>(BASES[side], input);
      return { announcement: response.data, replayed: response.meta?.replayed === true };
    },
    retry: false,
    onSuccess: (outcome) => {
      queryClient.setQueryData(announcementKeys.detail(side, outcome.announcement.id), outcome.announcement);
      void queryClient.invalidateQueries({ queryKey: [...announcementKeys.all(side), "list"] });
    },
    onError: (error) => refreshCoordinatorFlags(queryClient, side, error),
  });
}

const ACTION_PATHS: Record<AnnouncementAction, string> = { PUBLISH: "publish", CANCEL: "cancel", RETRY: "retry" };

/**
 * publish / cancel / retry. The server's detail replaces the cached one
 * (202 = queued, NOT published; 200 = replay); after a failure the detail is
 * re-read (a 409 means it moved). Cancel sends no reason_code: no approved
 * catalogue exists. retry: false — a 503 is never retried automatically.
 */
export function useAnnouncementAction(side: AnnouncementSide, id: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (action: AnnouncementAction): Promise<AnnouncementOutcome> => {
      const response = await apiClient.post<{ data: AnnouncementDetail; meta?: { replayed?: boolean } }>(
        `${BASES[side]}/${encodeURIComponent(id)}/${ACTION_PATHS[action]}`,
        {}
      );
      return { announcement: response.data, replayed: response.meta?.replayed === true };
    },
    retry: false,
    onSuccess: (outcome) => {
      queryClient.setQueryData(announcementKeys.detail(side, id), outcome.announcement);
    },
    onError: (error) => {
      void queryClient.invalidateQueries({ queryKey: announcementKeys.detail(side, id) });
      refreshCoordinatorFlags(queryClient, side, error);
    },
    onSettled: () => {
      void queryClient.invalidateQueries({ queryKey: [...announcementKeys.all(side), "list"] });
    },
  });
}

/**
 * Coordinator family search for the audience picker: the existing Coordinator
 * family directory (GET /api/v1/family/coordinator/families, behind
 * coordinator.space) — the ACTIVE families of the CURRENT scope only, from
 * two characters. Only family_code and the head's display name are used.
 */
export function useCoordinatorFamilySearch(term: string) {
  const q = term.trim();
  return useQuery({
    queryKey: ["announcements", "coordinator", "family-search", q],
    queryFn: () =>
      apiClient.get<{ data: CoordinatorFamily[] }>(`/api/v1/family/coordinator/families?${new URLSearchParams({ page: "1", q }).toString()}`),
    enabled: q.length >= 2,
    placeholderData: keepPreviousData,
    retry: false,
    staleTime: 30 * 1000,
  });
}

/**
 * Staff family search for the audience picker: the existing registry endpoint
 * (GET /api/v1/families, family.view), ACTIVE families only, from two
 * characters. Only family_code and display fields are used.
 */
export function useStaffFamilySearch(term: string) {
  const search = term.trim();
  return useQuery({
    queryKey: ["announcements", "staff", "family-search", search],
    queryFn: () =>
      apiClient.get<FamiliesListResponse>(`/api/v1/families?${new URLSearchParams({ search, status: "ACTIVE" }).toString()}`),
    enabled: search.length >= 2,
    placeholderData: keepPreviousData,
    retry: false,
    staleTime: 30 * 1000,
  });
}
