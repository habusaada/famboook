// Canonical field names follow docs/02-DATA-DICTIONARY.md §6-14
// (families, persons, family_memberships). This is a frontend-only
// presentation model for the mock UI phase — it will be replaced by
// types generated from the Laravel API (TanStack Query) without
// requiring UI rewrites, per docs/02 §67 (frontend state is not
// canonical registry data).

export type Gender = "MALE" | "FEMALE";

// docs/02-DATA-DICTIONARY.md §15 relationship_types examples.
export type RelationshipType =
  | "HEAD"
  | "SPOUSE"
  | "SON"
  | "DAUGHTER"
  | "FATHER"
  | "MOTHER"
  | "OTHER";

export const relationshipLabels: Record<RelationshipType, string> = {
  HEAD: "رب الأسرة",
  SPOUSE: "زوج/زوجة",
  SON: "ابن",
  DAUGHTER: "ابنة",
  FATHER: "أب",
  MOTHER: "أم",
  OTHER: "أخرى",
};

// UI-only display status for this mock phase. The canonical
// `families.status` field (docs/02 §7) is ACTIVE/INACTIVE/ARCHIVED;
// this blends that with review/verification workflow state purely
// for registry presentation purposes and will be reconciled once
// the real workflow/permissions model is implemented (docs/05, docs/06).
export type FamilyStatus =
  | "APPROVED"
  | "PENDING_REVIEW"
  | "NEEDS_COMPLETION"
  | "INACTIVE";

export const familyStatusLabels: Record<FamilyStatus, string> = {
  APPROVED: "معتمدة",
  PENDING_REVIEW: "قيد المراجعة",
  NEEDS_COMPLETION: "تحتاج استكمال",
  INACTIVE: "غير نشطة",
};

// Reflects family_memberships.is_active (docs/02 §14), not
// persons.life_status.
export type MemberStatus = "ACTIVE" | "INACTIVE";

export const memberStatusLabels: Record<MemberStatus, string> = {
  ACTIVE: "نشط",
  INACTIVE: "غير نشط",
};

export interface FamilyMember {
  personCode: string; // PER-000001, docs/02 §10
  fullName: string;
  relationship: RelationshipType;
  isHouseholdHead: boolean; // family_memberships.is_household_head
  gender: Gender;
  birthDate: string; // ISO date string
  status: MemberStatus;
}

export interface FamilyResidence {
  governorate: string;
  city: string;
  area?: string;
  displacementStatus?: string;
}

export interface Family {
  familyCode: string; // FAM-000001, docs/02 §7
  status: FamilyStatus;
  registrationDate: string; // ISO date string
  registrationSource: string;
  paperFormNo?: string;
  notes?: string;
  updatedAt: string; // human-readable relative label for the mock table
  residence: FamilyResidence;
  members: FamilyMember[];
}
