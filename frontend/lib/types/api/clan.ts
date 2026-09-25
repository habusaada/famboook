// Types mirroring backend/app/Http/Resources/ClanResource.php,
// BranchGroupResource.php and BranchResource.php.
//
// Hierarchy (docs/02 §7a–§7c): Clan (UI: العشيرة / العائلة) → Branch Groups →
// Branches → Families (households) → Persons. A Clan is NOT a Family.
// `id` is the public UUID; internal ids are never exposed.

export interface Branch {
  id: string;
  code: string;
  name: string;
  sort_order: number;
  is_active: boolean;
  family_count?: number;
  group?: { id: string; code: string; name: string | null };
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
  group: { code: string; name: string | null; display_name: string | null };
}

export interface CreateClanPayload {
  code: string;
  name: string;
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

export interface CreateBranchPayload {
  code: string;
  name: string;
  sort_order?: number;
}

export interface UpdateBranchPayload {
  name?: string;
  sort_order?: number;
  is_active?: boolean;
}
