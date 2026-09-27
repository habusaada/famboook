import { StatusBadge, type StatusTone } from "@/components/shared/status-badge";
import type { FamilyLifecycleStatus } from "@/lib/types/api/family";

const statusLabels: Record<FamilyLifecycleStatus, string> = {
  ACTIVE: "نشطة",
  INACTIVE: "غير نشطة",
  ARCHIVED: "مؤرشفة",
};

const statusTone: Record<FamilyLifecycleStatus, StatusTone> = {
  ACTIVE: "success",
  INACTIVE: "neutral",
  ARCHIVED: "neutral",
};

export function FamilyStatusBadge({ status }: { status: FamilyLifecycleStatus }) {
  return <StatusBadge tone={statusTone[status]}>{statusLabels[status]}</StatusBadge>;
}
