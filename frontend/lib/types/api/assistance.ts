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
export type NomineeStatus = "NOMINATED" | "REMOVED" | "APPROVED" | "REJECTED" | "NOT_DELIVERED";
export type ExecutionMode = "INTERNAL" | "EXTERNAL";
export type ReceiptMode = "PERSONAL" | "DELEGATE";
export type ExportClassification = "STANDARD" | "CONTACT" | "SENSITIVE";

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
  execution_mode: ExecutionMode;
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
  // EXTERNAL requested columns (keys + labels only).
  export_fields: ExportField[];
  created_by: { name: string } | null;
  created_at: string;
  updated_at: string;
  opened_at: string | null;
  opened_by: { name: string } | null;
  completed_at: string | null;
  completed_by: { name: string } | null;
}

export interface ExportField {
  field_key: string;
  column_label: string;
  sort_order?: number;
}

// Derived on read by the API, never stored. INTERNAL and EXTERNAL report
// different figures; EXTERNAL never reports deliveries.
export interface AssistanceStatistics {
  target: number | null;
  total_nominees: number;
  pending_approval: number;
  rejected: number;
  removed: number;
  approved: number;
  // INTERNAL
  awaiting_delivery?: number;
  delivered?: number;
  not_delivered?: number;
  reversed_deliveries?: number;
  execution_percentage?: number | null;
  package_totals?: { item_name: string; unit: string | null; quantity: string | null }[];
  monetary_totals?: { currency: Currency; total: string }[];
  // EXTERNAL
  approved_not_listed?: number;
  listed_unique?: number;
  issued_lists?: number;
  external_execution_result?: "UNKNOWN";
}

export interface AssistanceResponse {
  data: Assistance;
  statistics: AssistanceStatistics;
  // UX hints only — the API re-authorizes every write.
  abilities: {
    update: boolean;
    update_definition: boolean;
    open: boolean;
    preview: boolean;
    nominate: boolean;
    approve: boolean;
    deliver: boolean;
    reverse: boolean;
    export: boolean;
    export_sensitive: boolean;
    complete: boolean;
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
  execution_mode?: ExecutionMode;
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
  approved_at: string | null;
  approved_by: { name: string } | null;
  rejected_at: string | null;
  rejected_by: { name: string } | null;
  rejection_reason: string | null;
  not_delivered_at: string | null;
  not_delivered_by: { name: string } | null;
  not_delivered_reason: string | null;
  // INTERNAL: current delivery and reversed history. No National IDs.
  active_delivery: Delivery | null;
  reversed_deliveries: Delivery[];
  // EXTERNAL: issued lists this beneficiary appears in (not a delivery).
  listed_in: { list_id: string; list_number: string; issued_at: string }[];
}

export interface Delivery {
  id: string;
  receipt_mode: ReceiptMode;
  original_beneficiary: { person_code: string; full_name: string } | null;
  recipient: { person_code: string; full_name: string } | null;
  delivered_at: string;
  delivered_by: { name: string } | null;
  notes: string | null;
  reversed_at: string | null;
  reversed_by: { name: string } | null;
  reversal_reason: string | null;
}

// Identity check result: names/codes only — National IDs are never returned.
export interface DeliveryVerification {
  receipt_mode: ReceiptMode;
  beneficiary: { person_code: string; full_name: string };
  recipient: { person_code: string; full_name: string };
  relationship: "SON" | "DAUGHTER" | null;
  recipient_marital_status: string | null;
  package: { item_name: string; quantity_per_beneficiary: string | null; unit: string | null }[];
}

export interface DeliveryPayload {
  receipt_mode: ReceiptMode;
  beneficiary_national_id: string;
  delegate_national_id?: string;
  notes?: string | null;
}

export interface ExportFieldsResponse {
  data: {
    catalog: { field_key: string; default_label: string; classification: ExportClassification }[];
    configuration: ExportField[];
    contains_sensitive: boolean;
  };
  abilities: { configure: boolean; sensitive: boolean };
}

export interface ListColumn {
  field_key: string;
  column_label: string;
  classification: ExportClassification;
}

export type ListValue = string | number | null;

export interface ListPreview {
  columns: ListColumn[];
  contains_sensitive: boolean;
  row_count: number;
  rows: {
    beneficiary_id: string;
    target: "family" | "person";
    family_code: string;
    listed_in: string[];
    values: Record<string, ListValue>;
  }[];
}

export interface BeneficiaryList {
  id: string;
  list_number: string;
  recipient_organization: string;
  issued_at: string;
  issued_by: { name: string } | null;
  notes: string | null;
  row_count: number;
  contains_sensitive: boolean;
  columns: ListColumn[];
}

export interface BeneficiaryListDetail extends BeneficiaryList {
  assistance: { id: string; title: string };
  rows: { row_number: number; values: Record<string, ListValue> }[];
}

export interface FamilyAssistanceRow {
  id: string;
  assistance: {
    id: string;
    title: string;
    category: { code: string; name: string };
    provider_name: string;
    execution_mode: ExecutionMode;
    status: AssistanceStatus;
  };
  person: { person_code: string; full_name: string } | null;
  status: NomineeStatus;
  nominated_at: string;
  approved_at: string | null;
  delivery: { delivered_at: string; receipt_mode: ReceiptMode; recipient_name: string | null } | null;
  lists: { list_number: string; issued_at: string }[];
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
