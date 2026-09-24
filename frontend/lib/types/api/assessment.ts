// Types mirroring backend/app/Http/Resources/AssessmentResource.php,
// AssessmentSummaryResource.php and AssessmentController. Assessment data
// is only ever returned by the assessment endpoints (assessment.* permissions).

import type { AssessmentDomain } from "@/lib/types/api/reference";

export type AssessmentStatus = "DRAFT" | "COMPLETED";

// The five stored ratings. "Not assessed" is deliberately NOT a rating: a
// domain without a result was not assessed (UI state only).
export type AssessmentRating = "NONE" | "LOW" | "MEDIUM" | "HIGH" | "CRITICAL";

export interface AssessmentResult {
  domain: AssessmentDomain;
  rating: AssessmentRating;
  notes: string | null;
}

export interface Assessment {
  // Public UUID (the database id is never exposed).
  id: string;
  family: { family_code: string };
  // Business date: when the family was assessed (not created_at).
  assessment_date: string;
  status: AssessmentStatus;
  general_notes: string | null;
  created_by: { name: string } | null;
  created_at: string;
  updated_at: string;
  completed_at: string | null;
  completed_by: { name: string } | null;
  results: AssessmentResult[];
}

export interface AssessmentResponse {
  data: Assessment;
  // UX hints only — the API re-authorizes every write.
  abilities: { update: boolean; complete: boolean };
}

// List rows carry ratings only — never general or domain notes.
export interface AssessmentSummary {
  id: string;
  assessment_date: string;
  status: AssessmentStatus;
  assessed_domain_count: number;
  ratings: { domain: AssessmentDomain; rating: AssessmentRating }[];
  created_by: { name: string } | null;
  created_at: string;
  completed_at: string | null;
}

export interface AssessmentDraftPayload {
  assessment_date?: string;
  general_notes?: string | null;
  // The complete result set of the draft: omitted domains are "not assessed".
  results?: { domain_code: string; rating: AssessmentRating; notes: string | null }[];
}
