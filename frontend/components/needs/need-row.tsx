import Link from "next/link";
import { ChevronLeft, ClipboardList, Package, User, Users } from "lucide-react";
import { NeedPriorityBadge, NeedStatusBadge } from "@/components/needs/need-badges";
import type { Need } from "@/lib/types/api/need";
import { needQuantityLabel, needTargetLabel } from "@/lib/utils/need";

/** One Need in a family list: everything needed to triage at a glance. */
export function NeedRow({ need }: { need: Need }) {
  const quantity = needQuantityLabel(need);
  const TargetIcon = need.person ? User : Users;

  return (
    <li data-need-id={need.id}>
      <Link
        href={`/needs/${need.id}`}
        className="flex items-start gap-3 px-4 py-3 transition-colors hover:bg-muted/50"
      >
        <div className="flex min-w-0 flex-1 flex-col gap-1.5">
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-sm font-medium">{need.title}</span>
            <NeedPriorityBadge priority={need.priority} />
            <NeedStatusBadge status={need.status} />
          </div>
          <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
            <span>{need.category.name}</span>
            <span className="flex items-center gap-1">
              <TargetIcon className="size-3.5" />
              {needTargetLabel(need)}
            </span>
            {quantity && (
              <span className="flex items-center gap-1">
                <Package className="size-3.5" />
                {quantity}
              </span>
            )}
            {need.source_assessment && (
              <span className="flex items-center gap-1">
                <ClipboardList className="size-3.5" />
                من تقييم <span dir="ltr">{need.source_assessment.assessment_date}</span>
              </span>
            )}
          </div>
        </div>
        <ChevronLeft className="mt-1 size-4 shrink-0 text-muted-foreground" />
      </Link>
    </li>
  );
}
