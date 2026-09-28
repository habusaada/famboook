import { StatusBadge, type StatusTone } from "@/components/shared/status-badge";
import type { AssessmentRating, AssessmentStatus } from "@/lib/types/api/assessment";
import { NOT_ASSESSED_LABEL, assessmentRatingLabels, assessmentStatusLabels } from "@/lib/utils/assessment";

const ratingTones: Record<AssessmentRating, StatusTone> = {
  NONE: "neutral",
  LOW: "brand",
  MEDIUM: "info",
  HIGH: "warning",
  CRITICAL: "danger",
};

/**
 * One domain's stored rating, or — when no result exists — "لم يتم تقييمه"
 * (never NONE). Text always carries the meaning; tone only supports it.
 */
export function AssessmentRatingTag({ rating }: { rating: AssessmentRating | null }) {
  if (rating === null) {
    return (
      <span
        className="inline-flex h-6 shrink-0 items-center rounded-control border border-dashed border-stroke-subtle px-2 text-xs whitespace-nowrap text-muted-foreground"
        data-rating="NOT_ASSESSED"
      >
        {NOT_ASSESSED_LABEL}
      </span>
    );
  }
  return (
    <span data-rating={rating} className="contents">
      <StatusBadge tone={ratingTones[rating]} className={rating === "CRITICAL" ? "font-semibold" : undefined}>
        <span className="sr-only">التقييم: </span>
        {assessmentRatingLabels[rating]}
      </StatusBadge>
    </span>
  );
}

// Assessment presentation on the shared StatusBadge language (docs/10) for
// the Assessments workspace. The legacy AssessmentStatusBadge stays as it
// is for the Family Profile.

/** DRAFT = unfinished work (info, never alarming); COMPLETED = immutable record. */
export function AssessmentStatusTag({ status }: { status: AssessmentStatus }) {
  return (
    <StatusBadge tone={status === "COMPLETED" ? "success" : "info"}>
      <span className="sr-only">الحالة: </span>
      {assessmentStatusLabels[status]}
    </StatusBadge>
  );
}

/** Arabic count noun: 1 تقييم · 2 تقييمان · 3–10 تقييمات · 11+ تقييمًا. */
export function assessmentsNoun(n: number): string {
  if (n === 1) return "تقييم";
  if (n === 2) return "تقييمان";
  if (n >= 3 && n <= 10) return "تقييمات";
  return "تقييمًا";
}

/** Count of assessed domains as recorded: "3 مجالات مُقيَّمة". Never a score. */
export function assessedDomainsLabel(n: number): string {
  if (n === 0) return "لا مجالات مُقيَّمة";
  if (n === 1) return "مجال واحد مُقيَّم";
  if (n === 2) return "مجالان مُقيَّمان";
  if (n <= 10) return `${n} مجالات مُقيَّمة`;
  return `${n} مجالًا مُقيَّمًا`;
}
