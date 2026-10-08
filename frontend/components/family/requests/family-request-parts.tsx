import Link from "next/link";
import { ArrowRight, CheckCircle2, CircleX, FileInput, MessageSquareReply, RotateCcw, Search, Undo2, type LucideIcon } from "lucide-react";
import { StatusBadge } from "@/components/shared/status-badge";
import type { FamilyWorkflowEvent } from "@/lib/api/family-change-requests";
import type { ChangeRequestStatus, WorkflowEventType } from "@/lib/types/api/change-request";
import { rejectionReasonLabels } from "@/lib/utils/change-request";
import { formatDateTime } from "@/lib/utils/date";
import {
  familyMessageLabel,
  familyRequestEventLabels,
  familyRequestStatusLabels,
  familyRequestStatusTones,
} from "@/lib/utils/family-change-request";

/** A request status for the family: text plus tone (APPLIED is the only success). */
export function FamilyRequestStatus({ status }: { status: ChangeRequestStatus }) {
  return (
    <StatusBadge tone={familyRequestStatusTones[status] ?? "neutral"}>
      <span data-family-request-status={status}>{familyRequestStatusLabels[status] ?? status}</span>
    </StatusBadge>
  );
}

/** The page header used by the request screens: back link and title. */
export function FamilyRequestsHeader({ back, title, description }: { back?: { href: string; label: string }; title: string; description?: string }) {
  return (
    <header>
      {back && (
        <Link
          href={back.href}
          className="-ms-2 inline-flex min-h-10 items-center gap-1 rounded-lg px-2 text-sm font-medium text-brand-700 hover:bg-brand-50 focus-visible:outline-2 focus-visible:outline-ring"
        >
          <ArrowRight className="size-4" aria-hidden />
          {back.label}
        </Link>
      )}
      <h1 className="mt-1 text-2xl leading-snug font-bold text-foreground">{title}</h1>
      {description && <p className="mt-1 text-sm text-muted-foreground">{description}</p>}
    </header>
  );
}

const ICONS: Partial<Record<WorkflowEventType, LucideIcon>> = {
  SUBMITTED: FileInput,
  REVIEW_STARTED: Search,
  RETURNED: Undo2,
  RESUBMITTED: MessageSquareReply,
  APPROVED: CheckCircle2,
  REJECTED: CircleX,
  APPLIED: CheckCircle2,
  CANCELLED: RotateCcw,
};

/**
 * The family-visible timeline, in the server's order (PWA-5f). Only what the
 * Family API returns: no names, no internal notes, no apply diagnostics —
 * an APPLY_FAILED event is skipped even if one ever arrived.
 */
export function FamilyRequestTimeline({ events }: { events: FamilyWorkflowEvent[] }) {
  const shown = events.filter((event) => event.event_type in familyRequestEventLabels);
  if (shown.length === 0) return <p className="text-sm text-muted-foreground">لا توجد أحداث لهذا الطلب بعد.</p>;

  return (
    <ol className="flex flex-col" aria-label="مسار الطلب" data-family-timeline>
      {shown.map((event, i) => {
        const Icon = ICONS[event.event_type] ?? FileInput;
        const reason = event.event_type === "REJECTED" && event.reason_code ? rejectionReasonLabels[event.reason_code] : null;
        return (
          <li key={i} className="relative flex gap-3 pb-5 last:pb-0" data-family-timeline-event={event.event_type}>
            {i < shown.length - 1 && <span aria-hidden className="absolute start-4 top-9 bottom-0 w-px bg-stroke-subtle" />}
            <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-700" aria-hidden>
              <Icon className="size-4" />
            </span>
            <div className="flex min-w-0 flex-1 flex-col gap-1">
              <span className="font-semibold text-foreground">{familyRequestEventLabels[event.event_type]}</span>
              <time className="text-xs text-muted-foreground tabular-nums" dateTime={event.created_at}>
                {formatDateTime(event.created_at)}
              </time>
              {reason && <p className="text-sm text-foreground">السبب: {reason}</p>}
              {event.public_message && (
                <div className="rounded-xl bg-surface-2 px-3 py-2 text-sm">
                  <span className="block text-xs font-medium text-muted-foreground">{familyMessageLabel(event.event_type)}</span>
                  <p className="break-words whitespace-pre-line text-foreground">{event.public_message}</p>
                </div>
              )}
            </div>
          </li>
        );
      })}
    </ol>
  );
}
