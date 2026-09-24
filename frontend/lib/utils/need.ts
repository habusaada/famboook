import type { Need, NeedPriority, NeedStatus } from "@/lib/types/api/need";

// The single canonical presentation of Need codes. The backend stores
// codes only; never repeat these labels in components.

export const NEED_PRIORITIES: NeedPriority[] = ["LOW", "MEDIUM", "HIGH", "URGENT"];

export const needPriorityLabels: Record<NeedPriority, string> = {
  LOW: "منخفضة",
  MEDIUM: "متوسطة",
  HIGH: "مرتفعة",
  URGENT: "عاجلة",
};

// Subtle tinted styles; URGENT is clear without being alarming.
export const needPriorityStyles: Record<NeedPriority, string> = {
  LOW: "border-border text-muted-foreground",
  MEDIUM: "border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-300",
  HIGH: "border-orange-300 bg-orange-50 text-orange-800 dark:border-orange-900 dark:bg-orange-950/40 dark:text-orange-300",
  URGENT: "border-red-300 bg-red-50 font-semibold text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300",
};

export const NEED_STATUSES: NeedStatus[] = ["OPEN", "FULFILLED", "CLOSED"];

export const needStatusLabels: Record<NeedStatus, string> = {
  OPEN: "مفتوح",
  FULFILLED: "تمت تلبيته",
  CLOSED: "مغلق",
};

export const FAMILY_TARGET_LABEL = "الأسرة";

/** Who the need is for: the whole family or one member. */
export function needTargetLabel(need: Need): string {
  return need.person?.full_name ?? FAMILY_TARGET_LABEL;
}

export function needQuantityLabel(need: Need): string | null {
  if (need.quantity === null) return null;
  return need.unit ? `${need.quantity} ${need.unit}` : need.quantity;
}
