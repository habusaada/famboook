// Types mirroring backend/app/Http/Resources/HealthRecordResource.php and
// HealthRecordController@index. Health data is only ever returned by the
// health-record endpoints (health-record.* permissions).

import type { Gender } from "@/lib/types/api/family";
import type { DisabilityType } from "@/lib/types/api/reference";

export type HealthRecordType =
  | "DISABILITY"
  | "CHRONIC_DISEASE"
  | "PREGNANCY"
  | "BREASTFEEDING";

export interface HealthRecord {
  // Public UUID (the database id is never exposed).
  id: string;
  type: HealthRecordType;
  person: {
    person_code: string;
    full_name: string;
    gender: Gender;
  };
  disability_type: DisabilityType | null;
  condition_name: string | null;
  details: string | null;
  started_at: string | null;
  ended_at: string | null;
  is_active: boolean;
}

// All derived on read (never stored): distinct persons with an ACTIVE
// record, and DOB-based indicators relative to reference_date.
export interface FamilyHealthSummary {
  disability_persons: number;
  chronic_disease_persons: number;
  pregnant: number;
  breastfeeding: number;
  under_two: number;
  recent_births: number;
}

export interface FamilyHealthResponse {
  data: HealthRecord[];
  summary: FamilyHealthSummary;
  reference_date: string;
  // UX hints only — the API re-authorizes every write.
  abilities: { create: boolean; update: boolean; close: boolean };
}

export interface CreateHealthRecordPayload {
  person_code: string;
  type: HealthRecordType;
  disability_type_id?: number | null;
  condition_name?: string | null;
  details?: string | null;
  started_at?: string | null;
}

export interface UpdateHealthRecordPayload {
  disability_type_id?: number;
  condition_name?: string;
  details?: string | null;
  started_at?: string | null;
}

export interface CloseHealthRecordPayload {
  ended_at?: string | null;
}
