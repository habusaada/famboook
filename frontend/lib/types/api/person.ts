// Types mirroring backend/app/Http/Resources/PersonResource.php exactly.
// national_id is deliberately absent from the API response (see the
// resource's docblock) — do not add it here to match a UI wish; it
// isn't exposed until field-level authorization exists.

import type { Gender } from "@/lib/types/api/family";
import type { RelationshipType } from "@/lib/types/api/reference";

export type LifeStatus = "ALIVE" | "DECEASED" | "UNKNOWN";

export interface PersonFamilyMembership {
  family_code: string;
  is_household_head: boolean;
  relationship_type: RelationshipType | null;
  started_at: string | null;
}

export interface PersonDetail {
  person_code: string;
  full_name: string;
  gender: Gender;
  birth_date: string | null;
  mobile: string | null;
  alternate_mobile: string | null;
  // Descriptive only (e.g. "أحمد محمد – أخ") — never a linked Person.
  alternate_mobile_owner_relation: string | null;
  life_status: LifeStatus;
  is_active: boolean;
  family_membership?: PersonFamilyMembership;
}

// Canonical payload for PATCH /api/v1/people/{person}
// (backend/app/Http/Requests/Api/V1/UpdatePersonRequest.php). All
// fields optional — partial updates only. Does not include
// life_status, death_date, or household-head/membership fields; those
// remain separate controlled domain operations.
export interface UpdatePersonPayload {
  full_name?: string;
  national_id?: string | null;
  gender?: Gender;
  birth_date?: string | null;
  mobile?: string | null;
  alternate_mobile?: string | null;
}
