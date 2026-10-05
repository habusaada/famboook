// Types mirroring backend/app/Http/Resources/PersonResource.php exactly.
// The full National ID is never returned (docs/06 §39). Holders of
// person.national-id.view-masked receive only `national_id_masked`
// (AUTH-ADR-059); for everyone else the key is absent.

import type { MaritalStatus } from "@/lib/utils/marital-status";
import type { Gender } from "@/lib/types/api/family";
import type { RelationshipType } from "@/lib/types/api/reference";

export type LifeStatus = "ALIVE" | "DECEASED" | "UNKNOWN";

// How Staff verified a life-status change: POST .../confirm-alive
// (ConfirmPersonAliveRequest) and POST .../record-death
// (RecordPersonDeathRequest).
export type LifeStatusVerificationMethod = "IN_PERSON" | "STAFF_CALLBACK" | "AUTHORIZED_RECORD_REVIEW";

export interface ConfirmPersonAlivePayload {
  verification_method: LifeStatusVerificationMethod;
}

// POST /api/v1/people/{person}/record-death (RecordPersonDeathRequest). The
// death date is always sent: a Y-m-d date, or null when it is unknown.
export interface RecordPersonDeathPayload {
  death_date: string | null;
  verification_method: LifeStatusVerificationMethod;
}

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
  marital_status: MaritalStatus;
  birth_date: string | null;
  mobile: string | null;
  alternate_mobile: string | null;
  // Descriptive only (e.g. "أحمد محمد – أخ") — never a linked Person.
  alternate_mobile_owner_relation: string | null;
  life_status: LifeStatus;
  is_active: boolean;
  // Present only with person.national-id.view-masked: "*****6789", or
  // null when no National ID is recorded. Absent = not authorized.
  national_id_masked?: string | null;
  family_membership?: PersonFamilyMembership;
}

// PUT /api/v1/people/{person}/national-id (CorrectNationalIdRequest). The
// replacement is typed twice; it is never pre-filled and never shown back.
export interface CorrectNationalIdPayload {
  national_id: string;
  national_id_confirmation: string;
}

// Canonical payload for PATCH /api/v1/people/{person}
// (backend/app/Http/Requests/Api/V1/UpdatePersonRequest.php). All
// fields optional — partial updates only. Does not include the National
// ID, life_status, death_date, or household-head/membership fields; those
// remain separate controlled domain operations.
export interface UpdatePersonPayload {
  full_name?: string;
  gender?: Gender;
  marital_status?: MaritalStatus;
  birth_date?: string | null;
  mobile?: string | null;
  alternate_mobile?: string | null;
  alternate_mobile_owner_relation?: string | null;
}

// A People registry row (backend PersonSummaryResource): no National ID,
// contact details or health data. `family` is null without family.view.
export interface PersonSummary {
  person_code: string;
  full_name: string;
  gender: Gender | null;
  birth_date: string | null;
  life_status: LifeStatus;
  family: {
    family_code: string;
    is_household_head: boolean;
    relationship: { code: string; name: string } | null;
    branch_name: string | null;
  } | null;
}

// An existing Person holding a National ID that was entered again
// (duplicate check / refused creation). Never contains the National ID.
export interface NationalIdMatch {
  person_code: string;
  full_name: string | null;
  family: {
    family_code: string;
    is_household_head: boolean;
    relationship: string | null;
  } | null;
}
