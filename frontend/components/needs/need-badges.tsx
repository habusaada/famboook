import { cn } from "cn";
import { Badge } from "@/components/ui/badge";
import type { NeedPriority, NeedStatus } from "@/lib/types/api/need";
import { needPriorityLabels, needPriorityStyles, needStatusLabels } from "@/lib/utils/need";

export function NeedPriorityBadge({ priority }: { priority: NeedPriority }) {
  return (
    <Badge variant="outline" className={cn(needPriorityStyles[priority])}>
      {needPriorityLabels[priority]}
    </Badge>
  );
}

export function NeedStatusBadge({ status }: { status: NeedStatus }) {
  return (
    <Badge variant={status === "OPEN" ? "default" : status === "FULFILLED" ? "secondary" : "outline"}>
      {needStatusLabels[status]}
    </Badge>
  );
}
