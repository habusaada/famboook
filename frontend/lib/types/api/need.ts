// Types mirroring backend/app/Http/Resources/NeedResource.php and
// NeedController. Need data is only returned by the need endpoints
// (need.* permissions).

import type { AssessmentStatus } from "@/lib/types/api/assessment";
import type { PaginatedResponse } from "@/lib/types/api/family";
import type { NeedCategory } from "@/lib/types/api/reference";

export type NeedPriority = "LOW" | "MEDIUM" | "HIGH" | "URGENT";

export type NeedStatus = "OPEN" | "FULFILLED" | "CLOSED";

export interface Need {
  // Public UUID (the database id is never exposed).
  id: string;
  family: { family_code: string; household_head_name: string | null };
  // null = the whole family.
  person: { person_code: string; full_name: string } | null;
  category: NeedCategory;
  title: string;
  description: string | null;
  priority: NeedPriority;
  // Decimal as a string ("5", "2.5"); requested, not delivered.
  quantity: string | null;
  unit: string | null;
  status: NeedStatus;
  source_assessment: { id: string; assessment_date: string; status: AssessmentStatus } | null;
  created_by: { name: string } | null;
  created_at: string;
  updated_at: string;
  resolved_at: string | null;
  resolved_by: { name: string } | null;
  closure_reason: string | null;
}

export interface NeedResponse {
  data: Need;
  // UX hints only — the API re-authorizes every write.
  abilities: { update: boolean; fulfill: boolean; close: boolean };
}

// Derived on read by the API, never stored.
export interface FamilyNeedSummary {
  open: number;
  urgent_open: number;
  fulfilled: number;
  closed: number;
}

export type FamilyNeedsResponse = PaginatedResponse<Need> & {
  summary: FamilyNeedSummary;
  abilities: { create: boolean };
};

export interface NeedFilters {
  status?: NeedStatus;
  priority?: NeedPriority;
  category?: string;
  target?: "family" | "person";
}

export interface NeedPayload {
  person_code?: string | null;
  source_assessment_id?: string | null;
  category_code?: string;
  title?: string;
  description?: string | null;
  priority?: NeedPriority;
  quantity?: number | null;
  unit?: string | null;
}
