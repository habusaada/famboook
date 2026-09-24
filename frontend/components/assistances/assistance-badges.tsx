import { Badge } from "@/components/ui/badge";
import type { AssistanceStatus, NominationSource } from "@/lib/types/api/assistance";
import { assistanceStatusLabels, nominationSourceLabels } from "@/lib/utils/assistance";

export function AssistanceStatusBadge({ status }: { status: AssistanceStatus }) {
  const variant = status === "OPEN" ? "default" : status === "DRAFT" ? "secondary" : "outline";
  return <Badge variant={variant}>{assistanceStatusLabels[status]}</Badge>;
}

export function NominationSourceBadge({ source }: { source: NominationSource }) {
  return (
    <Badge variant="outline" className="font-normal">
      {nominationSourceLabels[source]}
    </Badge>
  );
}
