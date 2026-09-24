import { z } from "zod";
import type {
  Assessment,
  AssessmentDraftPayload,
  AssessmentRating,
} from "@/lib/types/api/assessment";
import type { AssessmentDomain } from "@/lib/types/api/reference";

// UX validation only — Laravel's assessment requests and actions are
// authoritative (active domains, completion rules, immutability).

const ratingChoice = z.union([
  z.enum(["NONE", "LOW", "MEDIUM", "HIGH", "CRITICAL"]),
  // "" = غير مقيّم: nothing is sent or stored for this domain.
  z.literal(""),
]);

export const assessmentSchema = z.object({
  assessmentDate: z
    .string()
    .min(1, "تاريخ التقييم مطلوب")
    .refine((value) => value <= todayIso(), "لا يمكن أن يكون تاريخ التقييم في المستقبل"),
  generalNotes: z.string().max(5000, "النص طويل جدًا"),
  results: z.record(
    z.string(),
    z.object({
      rating: ratingChoice,
      notes: z.string().max(2000, "النص طويل جدًا"),
    })
  ),
});

export type AssessmentFormValues = z.infer<typeof assessmentSchema>;

export function todayIso(): string {
  const now = new Date();
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

export function assessmentFormValues(
  domains: AssessmentDomain[],
  assessment?: Assessment
): AssessmentFormValues {
  const results: AssessmentFormValues["results"] = {};
  for (const domain of domains) {
    const stored = assessment?.results.find((r) => r.domain.code === domain.code);
    results[domain.code] = { rating: stored?.rating ?? "", notes: stored?.notes ?? "" };
  }

  return {
    assessmentDate: assessment?.assessment_date ?? todayIso(),
    generalNotes: assessment?.general_notes ?? "",
    results,
  };
}

export function assessedCount(values: AssessmentFormValues): number {
  return Object.values(values.results).filter((r) => r.rating !== "").length;
}

/** The full draft state; unassessed domains are simply left out. */
export function toAssessmentPayload(
  domains: AssessmentDomain[],
  values: AssessmentFormValues
): AssessmentDraftPayload {
  return {
    assessment_date: values.assessmentDate,
    general_notes: values.generalNotes.trim() || null,
    results: domains.flatMap((domain) => {
      const entry = values.results[domain.code];
      if (!entry || entry.rating === "") return [];
      return [
        {
          domain_code: domain.code,
          rating: entry.rating as AssessmentRating,
          notes: entry.notes.trim() || null,
        },
      ];
    }),
  };
}

export const assessmentApiFieldToFormField: Record<string, keyof AssessmentFormValues> = {
  assessment_date: "assessmentDate",
  general_notes: "generalNotes",
};
