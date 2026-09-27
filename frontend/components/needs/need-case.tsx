import { StatusBadge, type StatusTone } from "@/components/shared/status-badge";
import type { NeedPriority, NeedStatus } from "@/lib/types/api/need";
import { needPriorityLabels, needStatusLabels } from "@/lib/utils/need";

// Case-management presentation for the Needs workspace (/needs, /needs/[id]),
// on the shared StatusBadge language (docs/10). Stored values only: the tone
// follows the recorded priority/status, never a derived urgency.

const priorityTones: Record<NeedPriority, StatusTone> = {
  LOW: "neutral",
  MEDIUM: "info",
  HIGH: "warning",
  URGENT: "danger",
};

const statusTones: Record<NeedStatus, StatusTone> = {
  OPEN: "brand",
  FULFILLED: "success",
  CLOSED: "neutral",
};

export function NeedPriorityTag({ priority }: { priority: NeedPriority }) {
  return (
    <StatusBadge tone={priorityTones[priority]} className={priority === "URGENT" ? "font-semibold" : undefined}>
      <span className="sr-only">الأولوية: </span>
      {needPriorityLabels[priority]}
    </StatusBadge>
  );
}

export function NeedStatusTag({ status }: { status: NeedStatus }) {
  return (
    <StatusBadge tone={statusTones[status]}>
      <span className="sr-only">الحالة: </span>
      {needStatusLabels[status]}
    </StatusBadge>
  );
}

/** How long a need has existed, from created_at: "اليوم", "منذ يومين", "منذ ١٢ يومًا". */
export function needAgeLabel(createdAt: string, now: Date = new Date()): string {
  const days = Math.max(Math.floor((now.getTime() - new Date(createdAt).getTime()) / 86_400_000), 0);
  if (days === 0) return "اليوم";
  if (days === 1) return "منذ يوم";
  if (days === 2) return "منذ يومين";
  if (days <= 10) return `منذ ${days} أيام`;
  return `منذ ${days} يومًا`;
}

/** Arabic count noun: 1 احتياج · 2 احتياجان · 3–10 احتياجات · 11+ احتياجًا. */
export function needsNoun(n: number): string {
  if (n === 1) return "احتياج";
  if (n === 2) return "احتياجان";
  if (n >= 3 && n <= 10) return "احتياجات";
  return "احتياجًا";
}
