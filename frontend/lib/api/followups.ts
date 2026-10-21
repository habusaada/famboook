"use client";

import { type QueryClient, keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ApiError, apiClient } from "@/lib/api/client";
import { FAMILY_ME_QUERY_KEY } from "@/lib/api/family-auth";
import type { PaginatedResponse } from "@/lib/types/api/family";

// Follow-up management (FB-NOTIFY-01E.3-F3.3; backend F3.2, ADR FB-NOTIFY-01
// §2f): one module for both sides — the Staff API
// (/api/v1/notifications/followups) and the Coordinator API
// (/api/v1/family/coordinator/followups, CURRENT scope only) — with the same
// shapes. Follow-ups are addressed by their public uuid only. Nothing is
// optimistic and no write is ever retried automatically: a contact carries a
// client_reference (the server replays a repeat, never duplicates); a closure
// is idempotent by state (a second one is FOLLOWUP_NOT_OPEN).

export type FollowupSide = "staff" | "coordinator";

export type FollowupStatus = "OPEN" | "CLOSED";
export type FollowupStage = "FIRST" | "SECOND";
export type FollowupLevel = "NORMAL" | "IMPORTANT";
export type FollowupOutcome = "SATISFIED" | "EXPIRED" | "CLOSED_MANUALLY";
export type FollowupTriggerReason = "UNVIEWED" | "UNACKNOWLEDGED";
export type FollowupContactOutcome = "CALLED_INFORMED" | "CALLED_NO_ANSWER" | "VISITED" | "MESSAGE_LEFT" | "OTHER";
export type FollowupCloseReason = "INFORMED_BY_PHONE" | "INFORMED_IN_PERSON" | "FAMILY_UNREACHABLE" | "FAMILY_INACTIVE" | "NOT_APPLICABLE";
export type FollowupOwnership = "all" | "mine";

export const FOLLOWUP_STAGES: FollowupStage[] = ["FIRST", "SECOND"];
export const FOLLOWUP_LEVELS: FollowupLevel[] = ["NORMAL", "IMPORTANT"];
export const FOLLOWUP_OUTCOMES: FollowupOutcome[] = ["SATISFIED", "EXPIRED", "CLOSED_MANUALLY"];
export const FOLLOWUP_CONTACT_OUTCOMES: FollowupContactOutcome[] = ["CALLED_INFORMED", "CALLED_NO_ANSWER", "VISITED", "MESSAGE_LEFT", "OTHER"];
export const FOLLOWUP_CLOSE_REASONS: FollowupCloseReason[] = ["INFORMED_BY_PHONE", "INFORMED_IN_PERSON", "FAMILY_UNREACHABLE", "FAMILY_INACTIVE", "NOT_APPLICABLE"];

/** The server's note limit (FollowupContactRequest / FollowupCloseRequest). */
export const FOLLOWUP_NOTE_MAX = 1000;

/** FollowupResource (list rows): public fields only — never an internal id, a user, a phone number or a note. */
export type FollowupSummary = {
  id: string;
  stage: FollowupStage;
  trigger_reason: FollowupTriggerReason;
  status: FollowupStatus;
  outcome: FollowupOutcome | null;
  close_reason: FollowupCloseReason | null;
  due_at: string | null;
  opened_at: string | null;
  closed_at: string | null;
  message: { notice_code: string | null; level: FollowupLevel | null; title: string | null; expires_at: string | null };
  family: { family_code: string | null; head_name: string | null };
  /** Coordinator side only: the reader is the family's CURRENT primary coordinator. */
  can_manage?: boolean;
};

/** FollowupDetailResource. Staff: ownership KIND only (never who); coordinator: can_manage. */
export type FollowupDetail = FollowupSummary & {
  recipient: { viewed_at: string | null; acknowledged_at: string | null; acknowledged_late: boolean; had_family_account: boolean };
  contacts: number;
  ownership?: { kind: "PRIMARY_COORDINATOR" | "CENTRAL" };
};

/** FollowupEventResource. Staff see the actor's side and name; a coordinator a label only. */
export type FollowupEvent = {
  type: string;
  actor: { side: string; name: string | null } | { label: "SYSTEM" | "STAFF" | "YOU" | "COORDINATOR" };
  reason_code: string | null;
  stage: FollowupStage | null;
  outcome: FollowupOutcome | null;
  note: string | null;
  occurred_at: string | null;
};

export type FollowupCounts = {
  open: number;
  open_by_stage: Record<FollowupStage, number>;
  open_by_level: Record<FollowupLevel, number>;
  expired_open: number;
  closed_last_30_days: Record<FollowupOutcome, number>;
  /** Coordinator side only. */
  mine_open?: number;
};

export type FollowupFilters = {
  status?: FollowupStatus;
  stage?: FollowupStage;
  level?: FollowupLevel;
  outcome?: FollowupOutcome;
  family_code?: string;
  ownership?: FollowupOwnership;
  page?: number;
};

export type FollowupContactInput = { outcome: FollowupContactOutcome; note: string | null; client_reference: string };
export type FollowupCloseInput = { reason: FollowupCloseReason; note: string | null };
export type FollowupContactResult = { event: FollowupEvent; replayed: boolean };

const BASES: Record<FollowupSide, string> = {
  staff: "/api/v1/notifications/followups",
  coordinator: "/api/v1/family/coordinator/followups",
};

export function followupBase(side: FollowupSide): string {
  return BASES[side];
}

/** The in-app page of one follow-up. */
export function followupHref(side: FollowupSide, id: string): string {
  return `${side === "staff" ? "/followups" : "/family/coordinator/followups"}/${encodeURIComponent(id)}`;
}

export const followupKeys = {
  all: (side: FollowupSide) => ["followups", side] as const,
  list: (side: FollowupSide, filters: FollowupFilters) => ["followups", side, "list", filters] as const,
  summary: (side: FollowupSide) => ["followups", side, "summary"] as const,
  detail: (side: FollowupSide, id: string) => ["followups", side, "detail", id] as const,
  history: (side: FollowupSide, id: string) => ["followups", side, "history", id] as const,
};

/** Only the supported list parameters, only those set; `ownership` on the coordinator side only. */
export function followupListPath(side: FollowupSide, filters: FollowupFilters): string {
  const params = new URLSearchParams();
  if (filters.status) params.set("status", filters.status);
  if (filters.stage) params.set("stage", filters.stage);
  if (filters.level) params.set("level", filters.level);
  if (filters.outcome && filters.status === "CLOSED") params.set("outcome", filters.outcome);
  if (filters.family_code) params.set("family_code", filters.family_code);
  if (side === "coordinator" && filters.ownership === "mine") params.set("ownership", "mine");
  if (filters.page && filters.page > 1) params.set("page", String(filters.page));
  const query = params.toString();
  return query ? `${BASES[side]}?${query}` : BASES[side];
}

const itemPath = (side: FollowupSide, id: string) => `${BASES[side]}/${encodeURIComponent(id)}`;

/** A coordinator refused with COORDINATOR_SPACE_UNAVAILABLE: /family/me is re-read so the space gate catches up. */
function refreshCoordinatorFlags(queryClient: QueryClient, side: FollowupSide, error: unknown) {
  if (side !== "coordinator" || !(error instanceof ApiError) || error.status !== 403) return;
  const code = error.payload && typeof error.payload === "object" ? (error.payload as { code?: unknown }).code : null;
  if (code === "COORDINATOR_SPACE_UNAVAILABLE") void queryClient.invalidateQueries({ queryKey: FAMILY_ME_QUERY_KEY });
}

/** After a write (or a write whose outcome is uncertain): every view of the side re-reads the server. */
function invalidateSide(queryClient: QueryClient, side: FollowupSide, id: string, detail = true) {
  void queryClient.invalidateQueries({ queryKey: [...followupKeys.all(side), "list"] });
  void queryClient.invalidateQueries({ queryKey: followupKeys.summary(side) });
  if (detail) void queryClient.invalidateQueries({ queryKey: followupKeys.detail(side, id) });
  void queryClient.invalidateQueries({ queryKey: followupKeys.history(side, id) });
}

export function useFollowupsQuery(side: FollowupSide, filters: FollowupFilters) {
  return useQuery({
    queryKey: followupKeys.list(side, filters),
    queryFn: () => apiClient.get<PaginatedResponse<FollowupSummary>>(followupListPath(side, filters)),
    placeholderData: keepPreviousData,
    retry: false,
  });
}

export function useFollowupSummaryQuery(side: FollowupSide) {
  return useQuery({
    queryKey: followupKeys.summary(side),
    queryFn: async () => (await apiClient.get<{ data: FollowupCounts }>(`${BASES[side]}/summary`)).data,
    retry: false,
  });
}

export function useFollowupQuery(side: FollowupSide, id: string) {
  return useQuery({
    queryKey: followupKeys.detail(side, id),
    queryFn: async () => (await apiClient.get<{ data: FollowupDetail }>(itemPath(side, id))).data,
    enabled: id.length > 0,
    retry: false,
  });
}

export function useFollowupHistoryQuery(side: FollowupSide, id: string, enabled = true) {
  return useQuery({
    queryKey: followupKeys.history(side, id),
    queryFn: async () => (await apiClient.get<{ data: FollowupEvent[] }>(`${itemPath(side, id)}/history`)).data,
    enabled: enabled && id.length > 0,
    retry: false,
  });
}

/**
 * POST …/contacts: 201 a new contact, 200 the replay of the same
 * client_reference (the caller keeps the reference for a retry of the SAME
 * contact). retry: false — the caller decides when to send again.
 */
export function useRecordFollowupContact(side: FollowupSide, id: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (input: FollowupContactInput): Promise<FollowupContactResult> => {
      const response = await apiClient.post<{ data: FollowupEvent; meta?: { replayed?: boolean } }>(`${itemPath(side, id)}/contacts`, input);
      return { event: response.data, replayed: response.meta?.replayed === true };
    },
    retry: false,
    onSuccess: () => invalidateSide(queryClient, side, id),
    onError: (error) => {
      // A 409 means the follow-up moved (closed, expired, owner changed): show its current state.
      if (error instanceof ApiError && error.status === 409) invalidateSide(queryClient, side, id);
      refreshCoordinatorFlags(queryClient, side, error);
    },
  });
}

/**
 * POST …/close: the server's detail replaces the cached one. NEVER retried
 * automatically; after any failure the follow-up is re-read, so an uncertain
 * outcome (a lost response) shows the real state before a manual retry.
 */
export function useCloseFollowup(side: FollowupSide, id: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (input: FollowupCloseInput) => (await apiClient.post<{ data: FollowupDetail }>(`${itemPath(side, id)}/close`, input)).data,
    retry: false,
    onSuccess: (detail) => {
      queryClient.setQueryData(followupKeys.detail(side, id), detail);
      invalidateSide(queryClient, side, id, false);
    },
    onError: (error) => {
      invalidateSide(queryClient, side, id);
      refreshCoordinatorFlags(queryClient, side, error);
    },
  });
}
