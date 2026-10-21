"use client";

import Link from "next/link";
import { ChevronLeft, Clock, UserCheck } from "lucide-react";
import { AnnouncementLevelBadge } from "@/components/announcements/announcement-parts";
import { Code } from "@/components/shared/page-layout";
import { StatStrip } from "@/components/shared/stat-strip";
import { StatusBadge } from "@/components/shared/status-badge";
import type { FollowupCounts, FollowupSide, FollowupStage, FollowupSummary } from "@/lib/api/followups";
import { formatDateTime } from "@/lib/utils/date";
import {
  closeReasonLabels,
  followupOutcomeLabels,
  followupOutcomeTones,
  followupStageLabels,
  followupStatusLabels,
  followupStatusTones,
  isPastMessageExpiry,
} from "@/lib/utils/followup";

// Shared pieces of follow-up management (FB-NOTIFY-01E.3-F3.3), side-agnostic.
// Every state is text; color only repeats it. Lists show public fields only:
// never a note, a phone number or an internal id.

const fmt = (n: number) => n.toLocaleString("ar");
export const when = (iso: string | null) => (iso ? formatDateTime(iso) : "—");

export function FollowupStageBadge({ stage }: { stage: FollowupStage }) {
  return (
    <StatusBadge tone={stage === "SECOND" ? "warning" : "brand"}>
      <span data-followup-stage={stage}>{followupStageLabels[stage] ?? stage}</span>
    </StatusBadge>
  );
}

/** OPEN, or the closed outcome (SATISFIED / EXPIRED / CLOSED_MANUALLY). */
export function FollowupStateBadge({ followup }: { followup: Pick<FollowupSummary, "status" | "outcome"> }) {
  if (followup.status === "CLOSED" && followup.outcome) {
    return (
      <StatusBadge tone={followupOutcomeTones[followup.outcome] ?? "neutral"}>
        <span data-followup-status="CLOSED" data-followup-outcome={followup.outcome}>
          {followupStatusLabels.CLOSED} · {followupOutcomeLabels[followup.outcome] ?? followup.outcome}
        </span>
      </StatusBadge>
    );
  }
  return (
    <StatusBadge tone={followupStatusTones[followup.status] ?? "neutral"}>
      <span data-followup-status={followup.status}>{followupStatusLabels[followup.status] ?? followup.status}</span>
    </StatusBadge>
  );
}

/** The time that matters for the row: due (OPEN) or closed (CLOSED). */
function rowTime(followup: FollowupSummary): string | null {
  if (followup.status === "CLOSED") return followup.closed_at ? `أُغلقت ${when(followup.closed_at)}` : null;
  return followup.due_at ? `مستحقة منذ ${when(followup.due_at)}` : null;
}

/**
 * One follow-up in a list: the family code and the household head's display
 * name, the stage, state and level, the message, and the due / closed time.
 * On the coordinator side «أنت المسؤول» marks the families whose CURRENT
 * primary coordinator the reader is (the server's `can_manage`).
 */
export function FollowupRow({ followup, href, side }: { followup: FollowupSummary; href: string; side: FollowupSide }) {
  const time = rowTime(followup);
  const expired = isPastMessageExpiry(followup);
  return (
    <li>
      <Link
        href={href}
        className="flex items-center gap-3 px-4 py-3.5 transition-colors hover:bg-surface-hover focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-ring sm:px-5"
        data-followup-row={followup.id}
      >
        <span className="flex min-w-0 flex-1 flex-col gap-1.5">
          <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
            {followup.family.family_code && <Code className="text-[13px] text-brand-800">{followup.family.family_code}</Code>}
            <span className="min-w-0 text-sm font-semibold break-words text-foreground">
              <bdi>{followup.family.head_name ?? "—"}</bdi>
            </span>
          </span>
          <span className="flex flex-wrap items-center gap-1.5">
            <FollowupStateBadge followup={followup} />
            <FollowupStageBadge stage={followup.stage} />
            {followup.message.level === "IMPORTANT" && <AnnouncementLevelBadge level="IMPORTANT" />}
            {side === "coordinator" && followup.can_manage && (
              <StatusBadge tone="success">
                <span data-followup-mine>أنت المسؤول</span>
              </StatusBadge>
            )}
          </span>
          <span className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
            {followup.message.notice_code && <Code>{followup.message.notice_code}</Code>}
            {followup.message.title && (
              <>
                <span aria-hidden>·</span>
                <span className="min-w-0 truncate" dir="auto">
                  {followup.message.title}
                </span>
              </>
            )}
          </span>
          <span className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
            {time && (
              <span className="inline-flex items-center gap-1">
                <Clock className="size-3.5" aria-hidden />
                {time}
              </span>
            )}
            {followup.status === "CLOSED" && followup.close_reason && (
              <>
                <span aria-hidden>·</span>
                <span>{closeReasonLabels[followup.close_reason] ?? followup.close_reason}</span>
              </>
            )}
            {expired && (
              <>
                <span aria-hidden>·</span>
                <span className="font-medium text-warning" data-followup-expired>
                  انتهت مدة الإعلان — تُغلق تلقائيًا
                </span>
              </>
            )}
          </span>
        </span>
        <ChevronLeft className="size-5 shrink-0 text-muted-foreground" aria-hidden />
      </Link>
    </li>
  );
}

/** The side's counters (counts only, over what the reader may see). */
export function FollowupSummaryStrip({ side, counts, isError }: { side: FollowupSide; counts: FollowupCounts | undefined; isError: boolean }) {
  const value = (n: number | undefined) => (isError ? "—" : n === undefined ? undefined : fmt(n));
  const closed = counts?.closed_last_30_days;
  return (
    <section aria-label="ملخص المتابعات" className="flex flex-col gap-2" data-followup-summary={side}>
      <StatStrip
        size="md"
        items={[
          { label: "متابعات مفتوحة", value: value(counts?.open) },
          { label: "في المرحلة الثانية", value: value(counts?.open_by_stage.SECOND) },
          { label: "لإعلانات مهمة", value: value(counts?.open_by_level.IMPORTANT) },
          side === "coordinator"
            ? { label: "أنت المسؤول عنها", value: value(counts?.mine_open) }
            : { label: "انتهت مدة إعلانها", value: value(counts?.expired_open), hint: "تُغلق تلقائيًا" },
        ]}
      />
      {closed && !isError && (
        <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground" data-followup-closed-30>
          <UserCheck className="size-3.5" aria-hidden />
          <span>أُغلقت خلال آخر 30 يومًا:</span>
          <span>
            {followupOutcomeLabels.SATISFIED} <bdi className="tabular-nums">{fmt(closed.SATISFIED)}</bdi>
          </span>
          <span aria-hidden>·</span>
          <span>
            {followupOutcomeLabels.EXPIRED} <bdi className="tabular-nums">{fmt(closed.EXPIRED)}</bdi>
          </span>
          <span aria-hidden>·</span>
          <span>
            {followupOutcomeLabels.CLOSED_MANUALLY} <bdi className="tabular-nums">{fmt(closed.CLOSED_MANUALLY)}</bdi>
          </span>
        </p>
      )}
    </section>
  );
}
