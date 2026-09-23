import { Badge } from "@/components/ui/badge";
import type { FamilyLifecycleStatus } from "@/lib/types/api/family";

const statusLabels: Record<FamilyLifecycleStatus, string> = {
  ACTIVE: "نشطة",
  INACTIVE: "غير نشطة",
  ARCHIVED: "مؤرشفة",
};

const statusVariant: Record<
  FamilyLifecycleStatus,
  "default" | "secondary" | "outline"
> = {
  ACTIVE: "default",
  INACTIVE: "secondary",
  ARCHIVED: "outline",
};

export function FamilyStatusBadge({ status }: { status: FamilyLifecycleStatus }) {
  return <Badge variant={statusVariant[status]}>{statusLabels[status]}</Badge>;
}
