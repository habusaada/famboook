// Types mirroring backend/app/Http/Resources/AssistanceResource.php,
// AssistanceNomineeResource.php and AssistanceNomineeController (V1-A:
// definition, targeting, nomination — no approval or delivery).

import type { AssessmentRating } from "@/lib/types/api/assessment";
import type { PaginatedResponse } from "@/lib/types/api/family";
import type { NeedPriority, NeedStatus } from "@/lib/types/api/need";
import type { AssistanceCategory } from "@/lib/types/api/reference";

export type AssistanceStatus = "DRAFT" | "OPEN" | "COMPLETED" | "CANCELLED";
export type AssistanceType = "IN_KIND" | "CASH" | "SERVICE";
export type Currency = "ILS" | "USD" | "JOD" | "EUR";
export type NominationSource = "TARGETING" | "MANUAL" | "NEED";
export type NomineeStatus = "NOMINATED" | "REMOVED";

export interface AssistanceItem {
  item_name: string;
  // Planned per beneficiary — never delivered amounts.
  quantity_per_beneficiary: string | null;
  unit: string | null;
  unit_value: string | null;
  currency: Currency | null;
}

export interface TargetingCriteria {
  min_family_members?: number;
  max_family_members?: number;
  displacement_status?: "DISPLACED" | "NOT_DISPLACED";
  displacement_location_text?: string;
  has_child_under_two?: boolean;
  min_children_under_two?: number;
  has_pregnant_member?: boolean;
  has_breastfeeding_member?: boolean;
  has_member_with_disability?: boolean;
  has_member_with_chronic_disease?: boolean;
  need_category_code?: string;
  need_priorities?: NeedPriority[];
  assessment_domain_code?: string;
  assessment_ratings?: AssessmentRating[];
}

export interface Assistance {
  // Public UUID (the database id is never exposed).
  id: string;
  title: string;
  category: AssistanceCategory;
  assistance_type: AssistanceType;
  provider_name: string;
  target_beneficiaries: number | null;
  start_date: string | null;
  end_date: string | null;
  description: string | null;
  status: AssistanceStatus;
  // Derived (current nominees), never stored.
  nominee_count: number;
  items?: AssistanceItem[];
  targeting_criteria: TargetingCriteria;
  created_by: { name: string } | null;
  created_at: string;
  updated_at: string;
  opened_at: string | null;
  opened_by: { name: string } | null;
}

export interface AssistanceResponse {
  data: Assistance;
  // UX hints only — the API re-authorizes every write.
  abilities: {
    update: boolean;
    update_definition: boolean;
    open: boolean;
    preview: boolean;
    nominate: boolean;
  };
}

export type AssistanceListResponse = PaginatedResponse<Assistance> & {
  abilities: { create: boolean };
};

export interface AssistanceFilters {
  status?: AssistanceStatus;
  type?: AssistanceType;
  category?: string;
}

export interface AssistancePayload {
  title?: string;
  category_code?: string;
  assistance_type?: AssistanceType;
  provider_name?: string;
  target_beneficiaries?: number | null;
  start_date?: string | null;
  end_date?: string | null;
  description?: string | null;
  items?: {
    item_name: string;
    quantity_per_beneficiary: number | null;
    unit: string | null;
    unit_value: number | null;
    currency: Currency | null;
  }[];
}

// One family in the targeting preview: minimal eligibility indicators
// only — never disease names, notes, descriptions or identity numbers.
export interface TargetingMatch {
  family_code: string;
  household_head_name: string | null;
  displacement: { status: "DISPLACED" | "NOT_DISPLACED" | null; location_text: string | null } | null;
  member_count: number;
  indicators: {
    children_under_two?: number;
    has_pregnant_member?: boolean;
    has_breastfeeding_member?: boolean;
    has_member_with_disability?: boolean;
    has_member_with_chronic_disease?: boolean;
  };
  matching_need: { category: { code: string; name: string }; priority: NeedPriority; count: number } | null;
  matching_assessment: {
    domain: { code: string; name: string };
    rating: AssessmentRating;
    assessment_date: string;
  } | null;
  already_nominated: boolean;
}

export interface TargetingPreviewResponse {
  data: TargetingMatch[];
  criteria: TargetingCriteria;
  meta: { current_page: number; last_page: number; per_page: number; total: number };
}

export interface Nominee {
  id: string;
  family: { family_code: string; household_head_name: string | null };
  // null = family-level nominee.
  person: { person_code: string; full_name: string } | null;
  nomination_source: NominationSource;
  source_need: {
    id: string;
    title: string;
    category: { code: string; name: string };
    priority: NeedPriority;
    status: NeedStatus;
  } | null;
  status: NomineeStatus;
  nominated_at: string;
  nominated_by: { name: string } | null;
  removed_at: string | null;
  removed_by: { name: string } | null;
}

// Derived on read, never stored.
export interface NomineeSummary {
  total: number;
  family: number;
  person: number;
  targeting: number;
  need: number;
  manual: number;
  removed: number;
}

export type NomineesResponse = PaginatedResponse<Nominee> & { summary: NomineeSummary };

export interface NominationResult {
  data: { created: number; skipped_duplicates: number };
}

export interface NomineeCandidate {
  family_code: string;
  household_head_name: string | null;
  family_nominated: boolean;
  members: { person_code: string; full_name: string; nominated: boolean }[];
}
