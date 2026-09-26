// Types mirroring the Reports V1 API (backend/app/Http/Controllers/Api/V1/
// ReportController.php). Every figure is derived on request.

import type { AssessmentRating } from "@/lib/types/api/assessment";
import type { AssistanceStatus, ExecutionMode } from "@/lib/types/api/assistance";
import type { AgeBand, DashboardData } from "@/lib/types/api/dashboard";
import type { NeedPriority, NeedStatus } from "@/lib/types/api/need";

export type ReportKey = "population" | "health" | "needs" | "assessments" | "assistance" | "data-quality";

export interface ReportMeta {
  reports: Record<ReportKey, boolean>;
  can_export: boolean;
}

export interface ReportEnvelope {
  scope: DashboardData["scope"];
  generated_at: string;
}

export interface Paged<T> {
  data: T[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
}

export interface PopulationReport extends ReportEnvelope {
  summary: {
    active_families: number;
    current_people: number;
    male: number;
    female: number;
    unknown_gender: number;
    displaced: number;
    not_displaced: number;
    unknown_displacement: number;
  };
  age_bands: { code: AgeBand; count: number }[];
  organization: {
    level: "CLAN" | "BRANCH_GROUP";
    groups: {
      code: string;
      name: string | null;
      display_name: string | null;
      is_active: boolean;
      families: number;
      people: number;
      branches: { code: string; name: string; is_active: boolean; families: number; people: number }[];
    }[];
    unassigned: { families: number; people: number } | null;
  } | null;
  top_locations: { location: string; families: number }[];
}

export interface HealthReport extends ReportEnvelope {
  health: NonNullable<DashboardData["health"]>;
}

export interface NeedReportRow {
  id: string;
  title: string;
  category: { code: string; name: string };
  priority: NeedPriority;
  status: NeedStatus;
  target_type: "FAMILY" | "PERSON";
  target: { code: string; name: string | null };
  family_code: string;
  created_date: string | null;
  resolved_date: string | null;
}

export interface NeedsReport extends ReportEnvelope {
  summary: {
    total: number;
    open: number;
    fulfilled: number;
    closed: number;
    by_priority: { priority: NeedPriority; count: number }[];
    by_target: { family: number; person: number };
    by_category: { code: string; name: string; count: number }[];
  };
  rows: Paged<NeedReportRow>;
}

export interface AssessmentsReport extends ReportEnvelope {
  total_families: number;
  domains: NonNullable<DashboardData["assessments"]>["domains"];
  lifecycle: { draft: number; completed: number };
}

export type AssessmentBucket = AssessmentRating | "NOT_ASSESSED";

export interface AssessmentFamilyRow {
  family_code: string;
  household_head: string | null;
  branch: string | null;
  assessment_id: string | null;
  assessment_date: string | null;
  rating: AssessmentBucket;
}

export interface AssessmentFamiliesReport extends ReportEnvelope {
  domain: string;
  rating: AssessmentBucket;
  rows: Paged<AssessmentFamilyRow>;
}

export interface AssistanceProgramRow {
  id: string;
  title: string;
  category: { code: string; name: string };
  provider: string;
  execution_mode: ExecutionMode;
  status: AssistanceStatus;
  target: number | null;
  nominated: number;
  approved: number;
  rejected: number;
  internal: {
    awaiting_delivery: number;
    delivered: number;
    not_delivered: number;
    reversed_deliveries: number;
    delivery_percentage: number | null;
  } | null;
  // EXTERNAL: inclusion in an issued list is never a delivery.
  external: { approved_not_listed: number; listed_unique: number; issued_lists: number } | null;
}

export interface AssistanceReport extends ReportEnvelope {
  statuses: AssistanceStatus[];
  totals: {
    internal: { programs: number; nominated: number; approved: number; rejected: number; awaiting_delivery: number; delivered: number; not_delivered: number; reversed_deliveries: number };
    external: { programs: number; nominated: number; approved: number; rejected: number; approved_not_listed: number; listed_unique: number; issued_lists: number };
  };
  rows: Paged<AssistanceProgramRow>;
}

export type DataQualityIssueCode =
  | "FAMILY_WITHOUT_BRANCH"
  | "FAMILY_WITHOUT_CURRENT_RESIDENCE"
  | "DISPLACED_WITHOUT_LOCATION"
  | "PERSON_MISSING_NATIONAL_ID"
  | "PERSON_MISSING_BIRTH_DATE"
  | "PERSON_MISSING_MOBILE"
  | "PERSON_UNKNOWN_GENDER"
  | "PERSON_MARITAL_STATUS_UNKNOWN"
  | "ACTIVE_FAMILY_WITHOUT_HEAD"
  | "HOUSEHOLD_HEAD_DECEASED";

export interface DataQualityReport extends ReportEnvelope {
  issues: { code: DataQualityIssueCode; entity: "FAMILY" | "PERSON"; group: "COMPLETENESS" | "CONSISTENCY"; count: number }[];
  integrity_guaranteed: string[];
}

export type DataQualityRecord =
  | { family_code: string; household_head: string | null; clan: string; branch: string | null }
  | { person_code: string; full_name: string; family_code: string; branch: string | null };

export interface DataQualityRecordsReport extends ReportEnvelope {
  issue: DataQualityIssueCode;
  entity: "FAMILY" | "PERSON";
  rows: Paged<DataQualityRecord>;
}
