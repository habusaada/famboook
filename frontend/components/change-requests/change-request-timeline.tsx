import {
  CheckCircle2,
  CircleAlert,
  CircleX,
  ClipboardCheck,
  FileInput,
  Lock,
  MessageSquareReply,
  RotateCcw,
  Search,
  Undo2,
  type LucideIcon,
} from "lucide-react";
import { IconBox } from "@/components/shared/icon-box";
import type { WorkflowEvent, WorkflowEventType } from "@/lib/types/api/change-request";
import { formatDateTime } from "@/lib/utils/date";
import {
  actorSideLabels,
  changeRequestStatusLabels,
  reasonCodeLabel,
  workflowEventLabels,
} from "@/lib/utils/change-request";

const ICONS: Record<WorkflowEventType, LucideIcon> = {
  SUBMITTED: FileInput,
  REVIEW_STARTED: Search,
  RETURNED: Undo2,
  RESUBMITTED: MessageSquareReply,
  APPROVED: ClipboardCheck,
  REJECTED: CircleX,
  APPLIED: CheckCircle2,
  APPLY_FAILED: CircleAlert,
  CANCELLED: RotateCcw,
};

const TONES: Partial<Record<WorkflowEventType, "brand" | "success" | "danger" | "warning" | "neutral">> = {
  APPLIED: "success",
  REJECTED: "danger",
  APPLY_FAILED: "danger",
  RETURNED: "warning",
  CANCELLED: "neutral",
};

/** Whose words a public message is. */
function messageLabel(event: WorkflowEvent): string {
  if (event.event_type === "RESUBMITTED") return "رد الأسرة";
  if (event.event_type === "REJECTED") return "رسالة الرفض للأسرة";
  return "رسالة للأسرة";
}

/**
 * The workflow timeline, in the server's chronological order (PWA-5d).
 * internal_note is shown only when the API included it (holders of
 * change-request.view-internal-notes) — never inferred from a role. Event
 * metadata is never shown.
 */
export function ChangeRequestTimeline({ events }: { events: WorkflowEvent[] }) {
  if (events.length === 0) {
    return <p className="text-sm text-muted-foreground">لا توجد أحداث مسجلة لهذا الطلب.</p>;
  }

  return (
    <ol className="flex flex-col" aria-label="مسار الطلب" data-timeline>
      {events.map((event, i) => {
        const reason = reasonCodeLabel(event.event_type, event.reason_code);
        const hasNote = "internal_note" in event && event.internal_note;
        return (
          <li key={i} className="relative flex gap-3 pb-5 last:pb-0" data-timeline-event={event.event_type}>
            {i < events.length - 1 && <span aria-hidden className="absolute start-4 top-9 bottom-0 w-px bg-stroke-subtle" />}
            <IconBox icon={ICONS[event.event_type] ?? FileInput} tone={TONES[event.event_type] ?? "brand"} size="sm" />
            <div className="flex min-w-0 flex-1 flex-col gap-1">
              <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
                <span className="font-semibold text-foreground">{workflowEventLabels[event.event_type] ?? "حدث"}</span>
                <time className="text-xs text-muted-foreground tabular-nums" dateTime={event.created_at}>
                  {formatDateTime(event.created_at)}
                </time>
              </div>
              <span className="text-xs text-muted-foreground">
                {event.actor?.name ?? actorSideLabels[event.actor_side] ?? "—"}
                {event.actor && <> · {actorSideLabels[event.actor_side]}</>}
                {event.from_status && event.from_status !== event.to_status && (
                  <>
                    {" "}
                    · {changeRequestStatusLabels[event.from_status]} ← {changeRequestStatusLabels[event.to_status]}
                  </>
                )}
              </span>
              {reason && (
                <p className="text-sm text-foreground" data-timeline-reason>
                  <span className="text-muted-foreground">السبب: </span>
                  {reason}
                </p>
              )}
              {event.public_message && (
                <div className="rounded-control bg-surface-2 px-3 py-2 text-sm" data-timeline-public>
                  <span className="block text-xs font-medium text-muted-foreground">{messageLabel(event)}</span>
                  <p className="break-words whitespace-pre-line text-foreground">{event.public_message}</p>
                </div>
              )}
              {hasNote && (
                <div className="rounded-control border border-dashed border-stroke-subtle px-3 py-2 text-sm" data-timeline-internal>
                  <span className="flex items-center gap-1 text-xs font-medium text-muted-foreground">
                    <Lock className="size-3" aria-hidden />
                    ملاحظة داخلية — لا تظهر للأسرة
                  </span>
                  <p className="break-words whitespace-pre-line text-foreground">{event.internal_note}</p>
                </div>
              )}
            </div>
          </li>
        );
      })}
    </ol>
  );
}
