"use client";

import { useEffect } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { ApiError, apiClient } from "@/lib/api/client";
import { FAMILY_ME_QUERY_KEY, type FamilyUser } from "@/lib/api/family-auth";
import type { DisplacementStatus, Gender } from "@/lib/types/api/family";
import type { MaritalStatus } from "@/lib/utils/marital-status";

// The signed-in household head's own household (PWA-3A). The server resolves
// the Family from the session; nothing here sends a family, person or
// membership identifier. Kept in the query cache only (cleared on logout),
// never in browser storage.

export type FamilyHousehold = {
  family_code: string;
  clan_name: string | null;
  branch_name: string | null;
  head: { full_name: string };
  /** Source-declared household total from the current declaration; null = not declared. */
  declared_household_size: number | null;
  declared_at: string | null;
  /** Active family memberships. A separate fact, never compared with the declared size. */
  registered_member_count: number;
};

export type FamilyMemberRelationship = { code: string; name: string };

/**
 * One active membership (PWA-3A, completed by PWA-3B.3). The relationship,
 * the head flag and the membership start belong to the membership and are
 * always present; the Person fields are null when the Person's data is not
 * available (available = false). The National ID and mobiles arrive MASKED
 * only — a member reveal is PWA-3B.4, never this payload.
 */
export type FamilyMember = {
  /**
   * The opaque reference of this MEMBERSHIP (FU-13): 64 lowercase hex
   * characters, stable while the membership is active. An identity for the
   * client only — never an id, never authorization, never put in the DOM.
   */
  member_ref: string;
  available: boolean;
  full_name: string | null;
  relationship: FamilyMemberRelationship | null;
  is_household_head: boolean;
  gender: Gender | null;
  birth_date: string | null;
  /** As stored; UNKNOWN is an explicit value, not a missing one. */
  marital_status: MaritalStatus | null;
  life_status: "ALIVE" | "DECEASED" | "UNKNOWN" | null;
  /** Only meaningful when DECEASED; null = date not recorded. */
  death_date: string | null;
  national_id_masked: string | null;
  mobile_masked: string | null;
  alternate_mobile_masked: string | null;
  alternate_mobile_owner_relation: string | null;
  membership_started_at: string | null;
};

/** One row per active membership, in the server's order (never re-sorted here). */
export type FamilyMembers = {
  family_code: string;
  members: FamilyMember[];
};

/** The source of a household declaration (as stored). */
export type DeclarationSource = "PAPER_FORM" | "MANUAL_ENTRY" | "IMPORT" | "VERIFIED_SOURCE";

/**
 * The «أسرتي» record (PWA-3A Step 4, completed by PWA-3B.3): the family
 * facts, the CURRENT declaration and the CURRENT residence (each null when
 * none is recorded). Values arrive as stored: null means not recorded, 0 is
 * a value, and a null displacement_status means not collected — never "not
 * displaced".
 */
export type FamilyProfile = {
  family: {
    family_code: string;
    clan_name: string | null;
    /** null when the branch has no named group. */
    branch_group_name: string | null;
    branch_name: string | null;
    head: { full_name: string };
    registration_date: string | null;
    paper_form_no: string | null;
    registered_member_count: number;
  };
  /** Source-declared facts: never derived from, or compared with, the registered members. */
  declaration: {
    declared_household_size: number | null;
    declared_living_sons: number | null;
    declared_living_daughters: number | null;
    declared_at: string | null;
    source: DeclarationSource;
  } | null;
  residence: {
    /** The family's residence before displacement — never the current address. */
    original_residence_text: string | null;
    displacement_status: DisplacementStatus | null;
    displacement_location_text: string | null;
    residence_type: string | null;
    started_at: string | null;
    current_address: {
      governorate: string | null;
      city: string | null;
      area: string | null;
      neighborhood: string | null;
      address_text: string | null;
    };
  } | null;
};

export const FAMILY_HOUSEHOLD_QUERY_KEY = ["family", "household"] as const;
export const FAMILY_MEMBERS_QUERY_KEY = ["family", "household", "members"] as const;
export const FAMILY_PROFILE_QUERY_KEY = ["family", "household", "profile"] as const;
export const FAMILY_HEALTH_QUERY_KEY = ["family", "household", "health"] as const;

/**
 * The session/access flow for a Family data request. An expired session
 * (401) drops the signed-in user, so the Family gate returns to the login.
 * ANY 403 means the family data is unavailable to this account — whatever
 * the body says — and the shell shows its access-unavailable notice. Both
 * only ever take access away locally; the next /me answer from the server
 * stays authoritative. Other failures are left to the page.
 */
export function useFamilyAccessFailure(error: unknown) {
  const queryClient = useQueryClient();
  const status = error instanceof ApiError ? error.status : null;

  useEffect(() => {
    if (status === 401) {
      queryClient.setQueryData(FAMILY_ME_QUERY_KEY, null);
    } else if (status === 403) {
      queryClient.setQueryData<FamilyUser | null>(FAMILY_ME_QUERY_KEY, (user) =>
        user ? { ...user, context: { available: false, family: null } } : user
      );
    }
  }, [status, queryClient]);
}

/** GET /api/v1/family/household. */
export function useFamilyHouseholdQuery() {
  const query = useQuery({
    queryKey: FAMILY_HOUSEHOLD_QUERY_KEY,
    queryFn: async () => (await apiClient.get<{ data: FamilyHousehold }>("/api/v1/family/household")).data,
    staleTime: 60 * 1000,
    retry: false,
  });
  useFamilyAccessFailure(query.error);

  return query;
}

/** GET /api/v1/family/household/members. */
export function useFamilyMembersQuery() {
  const query = useQuery({
    queryKey: FAMILY_MEMBERS_QUERY_KEY,
    queryFn: async () => (await apiClient.get<{ data: FamilyMembers }>("/api/v1/family/household/members")).data,
    staleTime: 60 * 1000,
    retry: false,
  });
  useFamilyAccessFailure(query.error);

  return query;
}

/** GET /api/v1/family/household/profile. */
export function useFamilyProfileQuery() {
  const query = useQuery({
    queryKey: FAMILY_PROFILE_QUERY_KEY,
    queryFn: async () => (await apiClient.get<{ data: FamilyProfile }>("/api/v1/family/household/profile")).data,
    staleTime: 60 * 1000,
    retry: false,
  });
  useFamilyAccessFailure(query.error);

  return query;
}

/** True when the failure is handled by the session/access flow, not inline. */
export function isAccessFailure(error: unknown): boolean {
  return error instanceof ApiError && (error.status === 401 || error.status === 403);
}

// ------------------------------------------------------------------ health

/** The canonical health record types (docs/02 §22). */
export type HealthRecordType = "DISABILITY" | "CHRONIC_DISEASE" | "PREGNANCY" | "BREASTFEEDING";

/**
 * One registered health fact (PWA-3B.6). is_active is the domain rule
 * (ended_at is null); a closed record is history, never hidden. NULL stays
 * NULL. Never details or any identifier.
 */
export type FamilyHealthRecord = {
  type: HealthRecordType;
  /** DISABILITY only. */
  disability_type: { code: string; name: string } | null;
  /** CHRONIC_DISEASE only, as registered. */
  condition_name: string | null;
  started_at: string | null;
  ended_at: string | null;
  is_active: boolean;
};

/** Only members with at least one record appear, by their opaque member_ref. */
export type FamilyHouseholdHealth = {
  members: { member_ref: string; records: FamilyHealthRecord[] }[];
};

/**
 * GET /api/v1/family/household/health — the registered health facts of the
 * household's members, grouped by member_ref. Sensitive: never kept fresh
 * from an earlier view (staleTime 0); query cache only, cleared on logout.
 */
export function useFamilyHouseholdHealthQuery() {
  const query = useQuery({
    queryKey: FAMILY_HEALTH_QUERY_KEY,
    queryFn: async () => (await apiClient.get<{ data: FamilyHouseholdHealth }>("/api/v1/family/household/health")).data,
    staleTime: 0,
    retry: false,
  });
  useFamilyAccessFailure(query.error);

  return query;
}

/** The records of ONE member, by member_ref only; [] when nothing is registered. */
export function healthRecordsOf(health: FamilyHouseholdHealth, memberRef: string): FamilyHealthRecord[] {
  return health.members.find((member) => member.member_ref === memberRef)?.records ?? [];
}
