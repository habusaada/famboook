// Types mirroring backend/app/Http/Resources/FamilyActivityResource.php.
// The Family Activity Log is read-only and system-generated: there are no
// payload types because the Staff App never writes activity.

import type { HealthRecordType } from "@/lib/types/api/health";

// Canonical event codes (backend/app/Enums/FamilyActivityType.php). Arabic
// wording lives in lib/utils/activity.ts, never in the stored event.
export type FamilyActivityType =
  | "FAMILY_CREATED"
  | "FAMILY_UPDATED"
  | "FAMILY_MEMBER_ADDED"
  | "PERSON_UPDATED"
  | "RESIDENCE_UPDATED"
  | "DISPLACEMENT_UPDATED"
  | "HEALTH_RECORD_CREATED"
  | "HEALTH_RECORD_UPDATED"
  | "HEALTH_RECORD_CLOSED"
  | "ASSESSMENT_CREATED"
  | "ASSESSMENT_UPDATED"
  | "ASSESSMENT_COMPLETED";

export interface FamilyActivity {
  // Public UUID (the database id is never exposed).
  id: string;
  event_type: FamilyActivityType;
  occurred_at: string;
  // The authenticated application user who performed the action — not
  // the field researcher. Null for future system/import operations.
  actor: { name: string } | null;
  subject: {
    type: "family" | "person" | "residence" | "health_record" | "assessment" | null;
    person: { person_code: string; full_name: string } | null;
  };
  // Allow-listed keys only: the broad health category, never details.
  // Assessment events carry no metadata (no ratings, no notes).
  metadata: { health_record_type?: HealthRecordType };
}
