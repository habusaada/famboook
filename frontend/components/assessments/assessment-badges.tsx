import { cn } from "cn";
import { Badge } from "@/components/ui/badge";
import type { AssessmentRating, AssessmentStatus } from "@/lib/types/api/assessment";
import {
  assessmentDomainIcons,
  assessmentRatingLabels,
  FALLBACK_DOMAIN_ICON,
  assessmentRatingStyles,
  assessmentStatusLabels,
  NOT_ASSESSED_LABEL,
} from "@/lib/utils/assessment";

export function AssessmentRatingBadge({
  rating,
  className,
}: {
  rating: AssessmentRating | null;
  className?: string;
}) {
  if (rating === null) {
    return (
      <Badge variant="outline" className={cn("border-dashed font-normal text-muted-foreground", className)}>
        {NOT_ASSESSED_LABEL}
      </Badge>
    );
  }

  return (
    <Badge variant="outline" className={cn(assessmentRatingStyles[rating], className)}>
      {assessmentRatingLabels[rating]}
    </Badge>
  );
}

export function AssessmentDomainIcon({ code, className }: { code: string; className?: string }) {
  const Icon = assessmentDomainIcons[code] ?? FALLBACK_DOMAIN_ICON;
  return <Icon className={className} />;
}

export function AssessmentStatusBadge({ status }: { status: AssessmentStatus }) {
  return (
    <Badge variant={status === "COMPLETED" ? "default" : "secondary"}>
      {assessmentStatusLabels[status]}
    </Badge>
  );
}
