import type { DisplacementStatus } from "@/lib/types/api/family";

// null = never collected (legacy records) — shown as unknown, never
// presented as "غير نازحة".
export function displacementStatusLabel(
  status: DisplacementStatus | null | undefined
): string {
  if (status === "DISPLACED") return "نازحة";
  if (status === "NOT_DISPLACED") return "غير نازحة";
  return "غير محدد";
}
