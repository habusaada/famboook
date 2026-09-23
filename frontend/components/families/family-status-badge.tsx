import { Badge } from "@/components/ui/badge";
import {
  familyStatusLabels,
  type FamilyStatus,
} from "@/lib/types/family";

const statusVariant: Record<
  FamilyStatus,
  "default" | "secondary" | "destructive" | "outline"
> = {
  APPROVED: "default",
  PENDING_REVIEW: "secondary",
  NEEDS_COMPLETION: "destructive",
  INACTIVE: "outline",
};

export function FamilyStatusBadge({ status }: { status: FamilyStatus }) {
  return (
    <Badge variant={statusVariant[status]}>{familyStatusLabels[status]}</Badge>
  );
}
