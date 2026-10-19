"use client";

import { type QueryClient, keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ApiError, apiClient } from "@/lib/api/client";
import { FAMILY_ME_QUERY_KEY, type FamilyUser } from "@/lib/api/family-auth";
import { useFamilyAccessFailure } from "@/lib/api/family-household";
import type { PaginatedResponse, PaginationMeta } from "@/lib/types/api/family";

// The household's notifications (FB-NOTIFY-01C.2; backend FB-NOTIFY-01B.2b.6b,
// ADR FB-NOTIFY-01). The server resolves the Family from the session — nothing
// here sends a family or person identifier; a notification is addressed by its
// public uuid only. Reading never records a view: /view and /acknowledge are
// explicit POSTs, sent by the detail screen only. Kept in the query cache only
// (cleared on sign-out), never in browser storage. Nothing is optimistic and
// no mutation is retried.

export type FamilyNotificationKind = "SYSTEM" | "ANNOUNCEMENT";
export type FamilyNotificationLevel = "NORMAL" | "IMPORTANT";
export type FamilyNotificationAction = "VIEW" | "ACKNOWLEDGE";

/** One inbox / archive row (FamilyNotificationResource, without the body). */
export type FamilyNotificationSummary = {
  id: string;
  notice_code: string;
  kind: FamilyNotificationKind;
  /** The family-visible source, ready to display («إدارة Famboook» / «منسق العائلة»). */
  source: string;
  level: FamilyNotificationLevel;
  title: string;
  /** A family-relative path to an authorized view, or null. */
  action_path: string | null;
  published_at: string | null;
  expires_at: string | null;
  /** Archived, or past expires_at (server-computed). */
  expired: boolean;
  viewed_at: string | null;
  requires_acknowledgement: boolean;
  acknowledged_at: string | null;
  acknowledged_late: boolean;
  available_actions: FamilyNotificationAction[];
};

/** GET …/{uuid}, and the /view and /acknowledge responses: the row plus its body. */
export type FamilyNotification = FamilyNotificationSummary & { body: string };

/** GET /api/v1/family/notifications: the active inbox and the unread count. */
export type FamilyNotificationInboxPage = PaginatedResponse<FamilyNotificationSummary> & {
  meta: PaginationMeta & { unread_count: number };
};

/** GET /api/v1/family/notifications/archive: archived or expired notifications. */
export type FamilyNotificationArchivePage = PaginatedResponse<FamilyNotificationSummary>;

export type FamilyNotificationView = "inbox" | "archive";

/** The server's page size limit (FamilyNotificationIndexRequest::MAX_PER_PAGE). */
export const FAMILY_NOTIFICATIONS_MAX_PER_PAGE = 50;
export const FAMILY_NOTIFICATIONS_PER_PAGE = 20;

/**
 * The header bell reads the unread count from the inbox's first page — the
 * same request (and cache entry) as the inbox screen, with no query string.
 * The read limit (60 / min per user) is shared by the bell, the lists and
 * the detail: one read per minute at most, refreshed every two minutes while
 * the page is visible and on focus.
 */
export const FAMILY_UNREAD_STALE_MS = 60 * 1000;
export const FAMILY_UNREAD_REFRESH_MS = 2 * 60 * 1000;

const PATH = "/api/v1/family/notifications";

export const familyNotificationKeys = {
  all: ["family", "notifications"] as const,
  inbox: (page: number, perPage: number) => ["family", "notifications", "inbox", { page, perPage }] as const,
  archive: (page: number) => ["family", "notifications", "archive", { page }] as const,
  detail: (id: string) => ["family", "notifications", "detail", id] as const,
};

/** A positive page number, or 1. */
export function normalizePage(raw: string | null | undefined): number {
  const page = Number(raw);
  return Number.isInteger(page) && page >= 1 ? page : 1;
}

/** The list path with only the parameters that differ from the server defaults. */
export function familyNotificationListPath(view: FamilyNotificationView, page: number, perPage = FAMILY_NOTIFICATIONS_PER_PAGE): string {
  const params = new URLSearchParams();
  if (page > 1) params.set("page", String(page));
  if (perPage !== FAMILY_NOTIFICATIONS_PER_PAGE) params.set("per_page", String(Math.min(Math.max(1, perPage), FAMILY_NOTIFICATIONS_MAX_PER_PAGE)));
  const base = view === "archive" ? `${PATH}/archive` : PATH;
  const query = params.toString();
  return query ? `${base}?${query}` : base;
}

/** The stable error code of an API refusal ({message, code}), if any. */
export function apiErrorCode(error: unknown): string | null {
  if (!(error instanceof ApiError) || !error.payload || typeof error.payload !== "object") return null;
  const code = (error.payload as { code?: unknown }).code;
  return typeof code === "string" ? code : null;
}

export const FAMILY_CONTEXT_UNAVAILABLE = "FAMILY_CONTEXT_UNAVAILABLE";

/** The Family context is gone (the same state useFamilyAccessFailure sets for a query 403). */
function markFamilyContextUnavailable(queryClient: QueryClient) {
  queryClient.setQueryData<FamilyUser | null>(FAMILY_ME_QUERY_KEY, (user) =>
    user ? { ...user, context: { available: false, family: null } } : user
  );
}

/** The inbox (with meta.unread_count) or the archive, one server page. */
export function useFamilyNotificationsQuery(view: FamilyNotificationView, page: number) {
  const result = useQuery<FamilyNotificationInboxPage | FamilyNotificationArchivePage>({
    queryKey: view === "archive" ? familyNotificationKeys.archive(page) : familyNotificationKeys.inbox(page, FAMILY_NOTIFICATIONS_PER_PAGE),
    queryFn: () => apiClient.get(familyNotificationListPath(view, page)),
    placeholderData: keepPreviousData,
    retry: false,
  });
  useFamilyAccessFailure(result.error);

  return result;
}

/**
 * The unread count for the header bell: `undefined` until the server has
 * answered (never a misleading zero), and after any failure.
 */
export function useFamilyUnreadNotificationsQuery() {
  const result = useQuery({
    queryKey: familyNotificationKeys.inbox(1, FAMILY_NOTIFICATIONS_PER_PAGE),
    queryFn: () => apiClient.get<FamilyNotificationInboxPage>(familyNotificationListPath("inbox", 1)),
    select: (page) => page.meta.unread_count,
    staleTime: FAMILY_UNREAD_STALE_MS,
    refetchInterval: FAMILY_UNREAD_REFRESH_MS,
    refetchIntervalInBackground: false,
    retry: false,
  });
  useFamilyAccessFailure(result.error);

  return result;
}

/** One notification of the Family — reading only; it records no view. */
export function useFamilyNotificationQuery(id: string) {
  const result = useQuery({
    queryKey: familyNotificationKeys.detail(id),
    queryFn: async () => (await apiClient.get<{ data: FamilyNotification }>(`${PATH}/${encodeURIComponent(id)}`)).data,
    enabled: id.length > 0,
    retry: false,
  });
  useFamilyAccessFailure(result.error);

  return result;
}

export type FamilyNotificationOutcome = { notification: FamilyNotification; replayed: boolean };

/**
 * POST …/{uuid}/view or …/acknowledge. The server's answer replaces the
 * cached detail (never an optimistic guess); the inbox (and the bell's
 * count) and the archive are re-read after success OR failure, the detail
 * after a failure (a 409 means it moved). retry: false.
 *
 * A 403 FAMILY_CONTEXT_UNAVAILABLE ends the Family context like any query
 * 403; any other 403 (NOTIFICATION_ACTOR_NOT_ALLOWED) is only this action's
 * refusal and leaves the context alone.
 */
export function useFamilyNotificationMutation(id: string, action: "view" | "acknowledge") {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (): Promise<FamilyNotificationOutcome> => {
      const response = await apiClient.post<{ data: FamilyNotification; meta?: { replayed?: boolean } }>(
        `${PATH}/${encodeURIComponent(id)}/${action}`,
        {}
      );
      return { notification: response.data, replayed: response.meta?.replayed === true };
    },
    retry: false,
    onSuccess: (outcome) => {
      queryClient.setQueryData(familyNotificationKeys.detail(id), outcome.notification);
    },
    onError: (error) => {
      if (error instanceof ApiError && error.status === 403 && apiErrorCode(error) === FAMILY_CONTEXT_UNAVAILABLE) {
        markFamilyContextUnavailable(queryClient);
        return;
      }
      void queryClient.invalidateQueries({ queryKey: familyNotificationKeys.detail(id) });
    },
    onSettled: () => {
      void queryClient.invalidateQueries({ queryKey: ["family", "notifications", "inbox"] });
      void queryClient.invalidateQueries({ queryKey: ["family", "notifications", "archive"] });
    },
  });
}
