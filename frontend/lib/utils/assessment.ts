import {
  Accessibility,
  ClipboardList,
  Droplets,
  GraduationCap,
  HeartPulse,
  House,
  Shield,
  Utensils,
  Wallet,
  type LucideIcon,
} from "lucide-react";
import type { AssessmentRating, AssessmentStatus } from "@/lib/types/api/assessment";
import type { AssessmentDomain } from "@/lib/types/api/reference";

// The single canonical presentation of assessment codes. The backend
// stores codes only; never repeat these labels in components.

export const ASSESSMENT_RATINGS: AssessmentRating[] = ["NONE", "LOW", "MEDIUM", "HIGH", "CRITICAL"];

export const assessmentRatingLabels: Record<AssessmentRating, string> = {
  NONE: "لا يوجد احتياج",
  LOW: "منخفض",
  MEDIUM: "متوسط",
  HIGH: "مرتفع",
  CRITICAL: "حرج",
};

// Subtle tinted styles; CRITICAL is distinct without being alarming.
export const assessmentRatingStyles: Record<AssessmentRating, string> = {
  NONE: "border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300",
  LOW: "border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-300",
  MEDIUM: "border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300",
  HIGH: "border-orange-300 bg-orange-50 text-orange-800 dark:border-orange-900 dark:bg-orange-950/40 dark:text-orange-300",
  CRITICAL: "border-red-300 bg-red-50 font-semibold text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300",
};

// UI state only: no result is stored for the domain.
export const NOT_ASSESSED_CHOICE_LABEL = "غير مقيّم";
export const NOT_ASSESSED_LABEL = "لم يتم تقييمه";

export const assessmentStatusLabels: Record<AssessmentStatus, string> = {
  DRAFT: "مسودة",
  COMPLETED: "مكتمل",
};

export const assessmentDomainIcons: Record<string, LucideIcon> = {
  SHELTER: House,
  FOOD: Utensils,
  WASH: Droplets,
  HEALTH: HeartPulse,
  EDUCATION: GraduationCap,
  ECONOMIC: Wallet,
  PROTECTION: Shield,
  SPECIAL_NEEDS: Accessibility,
};

export const FALLBACK_DOMAIN_ICON: LucideIcon = ClipboardList;

/**
 * Domains to display: every active domain, plus any (possibly inactive)
 * domain that has a stored result, in reference order.
 */
export function displayDomains(
  active: AssessmentDomain[],
  resultDomains: AssessmentDomain[]
): AssessmentDomain[] {
  const byCode = new Map(active.map((d) => [d.code, d]));
  for (const domain of resultDomains) byCode.set(domain.code, domain);
  return [...byCode.values()].sort((a, b) => a.sort_order - b.sort_order);
}
