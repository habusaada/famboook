import { StatusBadge, type StatusTone } from "@/components/shared/status-badge";
import type { AssistanceStatus, ExecutionMode, NominationSource, NomineeStatus } from "@/lib/types/api/assistance";
import { assistanceStatusLabels, executionModeShort, nominationSourceLabels, nomineeStatusLabels } from "@/lib/utils/assistance";

// Assistance presentation on the shared StatusBadge language (docs/10).
// Stored codes only, canonical labels from lib/utils/assistance; the legacy
// assistance-badges.tsx stays as it is for the screens not yet migrated.

const programTones: Record<AssistanceStatus, StatusTone> = {
  DRAFT: "neutral",
  OPEN: "brand",
  COMPLETED: "success",
  // Schema-only in V1 (no transition reaches it); shown only if recorded.
  CANCELLED: "neutral",
};

export function AssistanceStatusTag({ status }: { status: AssistanceStatus }) {
  return (
    <StatusBadge tone={programTones[status]} className={status === "CANCELLED" ? "line-through decoration-1" : undefined}>
      <span className="sr-only">الحالة: </span>
      {assistanceStatusLabels[status]}
    </StatusBadge>
  );
}

const modeTones: Record<ExecutionMode, StatusTone> = {
  INTERNAL: "neutral",
  EXTERNAL: "info",
};

/** داخلي = delivered through Famboook; خارجي = a list issued to another provider. */
export function ExecutionModeTag({ mode }: { mode: ExecutionMode }) {
  return (
    <StatusBadge tone={modeTones[mode]}>
      <span className="sr-only">طريقة التنفيذ: </span>
      {executionModeShort[mode]}
    </StatusBadge>
  );
}

const nomineeTones: Record<NomineeStatus, StatusTone> = {
  NOMINATED: "warning",
  APPROVED: "brand",
  REJECTED: "neutral",
  NOT_DELIVERED: "neutral",
  REMOVED: "neutral",
};

export function NomineeStatusTag({ status }: { status: NomineeStatus }) {
  return (
    <StatusBadge tone={nomineeTones[status]}>
      <span className="sr-only">حالة الترشيح: </span>
      {nomineeStatusLabels[status]}
    </StatusBadge>
  );
}

/** How a nominee entered the program (targeting, manual, open need). */
export function NominationSourceTag({ source }: { source: NominationSource }) {
  return (
    <StatusBadge tone={source === "NEED" ? "info" : "neutral"}>
      <span className="sr-only">مصدر الترشيح: </span>
      {nominationSourceLabels[source]}
    </StatusBadge>
  );
}

/** Arabic count noun: 1 برنامج · 2 برنامجان · 3–10 برامج · 11+ برنامجًا. */
export function programsNoun(n: number): string {
  if (n === 1) return "برنامج";
  if (n === 2) return "برنامجان";
  if (n >= 3 && n <= 10) return "برامج";
  return "برنامجًا";
}

/** Filtered count phrase: 1 برنامج مطابق · 2 برنامجان مطابقان · 3–10 برامج مطابقة · 11+ برنامجًا مطابقًا. */
export function matchingProgramsNoun(n: number): string {
  if (n === 1) return "برنامج مطابق";
  if (n === 2) return "برنامجان مطابقان";
  if (n >= 3 && n <= 10) return "برامج مطابقة";
  return "برنامجًا مطابقًا";
}

/** "24 مرشحًا من هدف 50" — two server values side by side, never a percentage. */
export function nomineesAgainstTarget(nominees: number, target: number | null): string {
  const fmt = (n: number) => n.toLocaleString("ar");
  const count =
    nominees === 0
      ? "لا مرشحين"
      : nominees === 1
        ? "مرشح واحد"
        : nominees === 2
          ? "مرشحان"
          : nominees <= 10
            ? `${fmt(nominees)} مرشحين`
            : `${fmt(nominees)} مرشحًا`;
  return target === null ? `${count} · بلا هدف محدد` : `${count} من هدف ${fmt(target)}`;
}
