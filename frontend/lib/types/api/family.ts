// Types mirroring backend/app/Http/Resources/FamilySummaryResource.php,
// FamilyDetailResource.php, and FamilyMemberResource.php exactly.
// families.status is record lifecycle only (docs/02-DATA-DICTIONARY.md
// §7) — ACTIVE/INACTIVE/ARCHIVED. Review/workflow status is a separate,
// not-yet-implemented concept and must not be conflated with this field.

export type FamilyLifecycleStatus = "ACTIVE" | "INACTIVE" | "ARCHIVED";

export type Gender = "MALE" | "FEMALE";

export type RegistrationSource =
  | "PAPER_FORM"
  | "MANUAL_ENTRY"
  | "IMPORT"
  | "VERIFIED_SOURCE";

export interface FamilySummary {
  family_code: string;
  status: FamilyLifecycleStatus;
  household_head_name: string | null;
  member_count: number;
  registration_date: string | null;
  updated_at: string | null;
}

export interface FamilyMemberDetail {
  person_code: string;
  full_name: string;
  gender: Gender;
  birth_date: string | null;
  is_household_head: boolean;
  is_active: boolean;
  // Always null until reference data (relationship_types) is
  // implemented — do not render a relationship label from this.
  relationship_type_id: number | null;
}

export interface FamilyDetail {
  family_code: string;
  status: FamilyLifecycleStatus;
  registration_date: string | null;
  registration_source: RegistrationSource | null;
  paper_form_no: string | null;
  notes: string | null;
  updated_at: string | null;
  residence: {
    governorate: string;
    city: string;
    area: string | null;
    neighborhood: string | null;
    address_text: string | null;
    displacement_status: string | null;
  } | null;
  member_count: number;
  male_count: number;
  female_count: number;
  members: FamilyMemberDetail[];
}

export interface PaginationLinks {
  first: string | null;
  last: string | null;
  prev: string | null;
  next: string | null;
}

export interface PaginationMeta {
  current_page: number;
  from: number | null;
  last_page: number;
  path: string;
  per_page: number;
  to: number | null;
  total: number;
}

export interface PaginatedResponse<T> {
  data: T[];
  links: PaginationLinks;
  meta: PaginationMeta;
}

export interface ResourceResponse<T> {
  data: T;
  message?: string;
}

// Canonical nested payload expected by POST /api/v1/families
// (backend/app/Http/Requests/Api/V1/RegisterFamilyRequest.php).
export interface RegisterFamilyPayload {
  registration_date: string;
  registration_source: RegistrationSource;
  paper_form_no?: string | null;
  notes?: string | null;
  household_head: {
    full_name: string;
    national_id?: string | null;
    gender: Gender;
    birth_date: string;
    mobile?: string | null;
    alternate_mobile?: string | null;
  };
  residence: {
    governorate: string;
    city: string;
    area?: string | null;
    neighborhood?: string | null;
    address_text?: string | null;
    displacement_status?: string | null;
    residence_type?: string | null;
    latitude?: number | null;
    longitude?: number | null;
  };
}

// Canonical payload for POST /api/v1/families/{family}/members
// (backend/app/Http/Requests/Api/V1/AddFamilyMemberRequest.php).
export interface AddFamilyMemberPayload {
  full_name: string;
  national_id?: string | null;
  gender: Gender;
  birth_date: string;
  mobile?: string | null;
  alternate_mobile?: string | null;
}
