// Types mirroring the GET /api/v1/dashboard response
// (backend/app/Support/Dashboard/OperationalDashboard.php). Every figure is
// derived on request; a section is null when the user lacks the
// underlying domain permission (never computed, not merely hidden).

import type { FamilyActivity } from "@/lib/types/api/activity";
import type { AssessmentRating } from "@/lib/types/api/assessment";
import type { NeedPriority } from "@/lib/types/api/need";

export type AgeBand = "UNDER_2" | "AGE_2_5" | "AGE_6_17" | "AGE_18_59" | "AGE_60_PLUS" | "UNKNOWN";

export interface DashboardScopeQuery {
  clan: string;
  branch_group?: string;
  branch?: string;
}

export interface DashboardData {
  scope: {
    clan: { code: string; name: string };
    branch_group: { code: string; name: string | null; display_name: string | null } | null;
    branch: { code: string; name: string } | null;
  };
  generated_at: string;
  as_of_date: string;
  kpis: {
    active_families: number | null;
    current_people: number | null;
    displaced_families: number | null;
    open_needs: number | null;
  };
  demographics: {
    total: number;
    gender: { male: number; female: number; unknown: number };
    age_bands: { code: AgeBand; count: number }[];
  } | null;
  displacement: {
    total_families: number;
    displaced: number;
    not_displaced: number;
    unknown: number;
    top_locations: { location: string; families: number }[];
  } | null;
  health: {
    people_with_disability: number;
    people_with_chronic_disease: number;
    active_pregnancy: number;
    active_breastfeeding: number;
    disability_types: { code: string | null; name: string | null; people: number }[];
  } | null;
  needs: {
    open: number;
    families_with_open_needs: number;
    by_priority: { priority: NeedPriority; count: number }[];
    top_categories: { code: string; name: string; count: number }[];
  } | null;
  assessments: {
    total_families: number;
    domains: {
      code: string;
      name: string;
      is_active: boolean;
      ratings: Record<AssessmentRating, number>;
      assessed_families: number;
      not_assessed_families: number;
    }[];
  } | null;
  assistance: {
    open_programs: { internal: number; external: number };
    internal: {
      nominated: number;
      approved: number;
      awaiting_delivery: number;
      delivered: number;
      not_delivered: number;
    };
    // EXTERNAL: inclusion in an issued list is never a delivery.
    external: {
      nominated: number;
      approved: number;
      approved_not_listed: number;
      listed_unique: number;
    };
  } | null;
  recent_activity: (FamilyActivity & { family: { family_code: string | null } })[] | null;
}
