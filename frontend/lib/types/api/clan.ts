// Types mirroring backend/app/Http/Resources/ClanResource.php,
// BranchGroupResource.php and BranchResource.php.
//
// Hierarchy (docs/02 §7a–§7c): Clan (UI: العشيرة / العائلة) → Branches (optionally
// classified under a Branch Group) →
// Families (households) → Persons. A Clan is NOT a Family.
// `id` is the public UUID; internal ids are never exposed.

export interface Branch {
  id: string;
  code: string;
  name: string;
  sort_order: number;
  is_active: boolean;
  family_count?: number;
  // null = ungrouped (a Branch Group is optional).
  group?: { id: string; code: string; name: string | null } | null;
}

export interface BranchGroup {
  id: string;
  code: string;
  // null = unnamed administrative container.
  name: string | null;
  // Own name, else its branches' names joined; null when neither exists.
  display_name: string | null;
  sort_order: number;
  is_active: boolean;
  branches?: Branch[];
}

export interface Clan {
  id: string;
  code: string;
  name: string;
  is_active: boolean;
  family_count?: number;
  branch_groups?: BranchGroup[];
  // Branches not organized into a Branch Group; never hidden.
  ungrouped_branches?: Branch[];
}

// The Clan/Branch representation embedded in FamilyDetail.
export interface FamilyClanRef {
  code: string;
  name: string;
  is_active: boolean;
}

export interface FamilyBranchRef {
  code: string;
  name: string;
  is_active: boolean;
  // null = the Branch is ungrouped.
  group: { code: string; name: string | null; display_name: string | null } | null;
}

export interface CreateClanPayload {
  code: string;
  name: string;
  // Optional; a new Clan is active unless explicitly created inactive.
  is_active?: boolean;
}

export interface UpdateClanPayload {
  name?: string;
  is_active?: boolean;
}

export interface CreateBranchGroupPayload {
  code: string;
  name?: string | null;
  sort_order?: number;
}

export interface UpdateBranchGroupPayload {
  name?: string | null;
  sort_order?: number;
  is_active?: boolean;
}

// branch_group_id: a Branch Group's public id (same Clan) or null = ungrouped.
export interface CreateBranchPayload {
  code: string;
  name: string;
  sort_order?: number;
  branch_group_id?: string | null;
}

// branch_group_id omitted = unchanged; a group id = assign/move; null = ungroup.
export interface UpdateBranchPayload {
  name?: string;
  sort_order?: number;
  is_active?: boolean;
  branch_group_id?: string | null;
}
