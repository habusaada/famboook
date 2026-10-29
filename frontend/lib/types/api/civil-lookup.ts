// FB-CIVIL-02A — Civil Registry Lookup (docs/adr/FB-CIVIL-02A-CIVIL-REGISTRY-LOOKUP.md).
// Values are returned raw by the API (undocumented codes are never
// interpreted); an empty value is null. National IDs arrive masked unless the
// user holds civil-lookup.view-full; references are opaque strings.
// FB-CIVIL-02B: the same shapes on the Family Portal and in Coordinator Space
// (the EXTENDED_CONTROLLED level; identifiers always masked).

/** Where a lookup is made from: one API, three mounts (docs/adr/FB-CIVIL-02B-FAMILY-COORDINATOR-LOOKUP.md). */
export type CivilLookupSpace = "staff" | "family" | "coordinator";

export type CivilLookupContext = "STAFF" | "FAMILY" | "COORDINATOR";

export type CivilLookupSide = "FAMILY" | "COORDINATOR";

export type CivilLookupMode = "national-id" | "name" | "parts";

export type CivilLookupOutcome = "FOUND" | "RESULTS" | "NOT_FOUND" | "TOO_BROAD";

export interface CivilLookupQuota {
  enabled: boolean;
  unlimited: boolean;
  daily_limit: number | null;
  used: number;
  remaining: number | null;
  resets_at: string;
  reason: string | null;
  used_after?: number;
}

export interface CivilLookupStatus {
  context: CivilLookupContext;
  quota: CivilLookupQuota;
  capabilities: { search: boolean; view: boolean; view_full: boolean; reverse_links: boolean };
}

export interface CivilLookupResultEntry {
  ref: string;
  national_id: string | null;
  fullname: string | null;
  gender: string | null;
  datebirth: string | null;
  govern: string | null;
  city: string | null;
}

export interface CivilLookupListMeta {
  page: number;
  per_page: number;
  total: number | null;
  last_page: number;
}

export interface CivilLookupList {
  release_id: number;
  outcome: CivilLookupOutcome;
  results: CivilLookupResultEntry[];
  meta: CivilLookupListMeta;
  search_ref: string | null;
  quota: CivilLookupQuota;
  relation?: CivilLookupReverseRelation;
}

export type CivilLookupForwardRelation = "FATHER" | "MOTHER" | "SPOUSE";

export type CivilLookupReverseRelation = "HOUSEHOLD" | "CHILDREN";

export type CivilLookupRelation = CivilLookupForwardRelation | CivilLookupReverseRelation;

export interface CivilLookupLink {
  relation: CivilLookupRelation;
  status: "AVAILABLE" | "NOT_IN_RELEASE" | "EMPTY" | "DISABLED";
  national_id?: string | null;
  ref?: string;
}

export type CivilLookupSectionKey = "personal" | "family" | "geographic" | "social" | "reference";

export interface CivilLookupRecord {
  ref: string;
  national_id: string | null;
  fullname: string | null;
  level: "STANDARD" | "FULL" | "EXTENDED_CONTROLLED";
  sections: { key: CivilLookupSectionKey; fields: { key: string; value: string | null }[] }[];
  links: CivilLookupLink[];
}

export interface CivilLookupRecordResponse {
  outcome: "FOUND" | "NOT_FOUND";
  release_id: number;
  relation?: CivilLookupRelation;
  record: CivilLookupRecord | null;
  quota: CivilLookupQuota;
}

// ---------------------------------------------------------------- administration

export interface CivilLookupPolicy {
  role: string;
  enabled: boolean;
  daily_limit: number | null;
  unlimited: boolean;
  unlimited_allowed: boolean;
  updated_by: string | null;
  updated_at: string | null;
  today: { lookups: number; users: number; exhausted: number };
}

export interface CivilLookupUsage {
  date: string;
  resets_at: string;
  total_lookups: number;
  active_users: number;
  policies: CivilLookupPolicy[];
  exhausted_users: { user_id: number; name: string; role: string; used: number; daily_limit: number }[];
  lookup_enabled: boolean;
  reverse_links_enabled: boolean;
  /** FB-CIVIL-02B: the sides whose switch is open, i.e. that may be GRANTED now. */
  grantable_sides: CivilLookupSide[];
  /** FB-CIVIL-02B: whether each family-side lookup is open (all its gates). */
  side_lookup_enabled: Record<CivilLookupSide, boolean>;
}

export interface CivilLookupPolicyChange {
  id: number;
  subject_type: "ROLE" | "USER";
  role: string | null;
  user: { id: number; name: string } | null;
  old_enabled: boolean | null;
  new_enabled: boolean | null;
  old_daily_limit: number | null;
  new_daily_limit: number | null;
  old_access: string | null;
  new_access: string | null;
  old_sides: CivilLookupSide[];
  new_sides: CivilLookupSide[];
  changed_by: string | null;
  changed_at: string | null;
}

export interface CivilLookupUserAccessRow {
  user: { id: number | null; name: string | null; email: string | null };
  /** FB-CIVIL-02D: the account side and, for a family-side account, its linked Person's code (its edit identifier). */
  account_side: CivilLookupAccountSide | null;
  person_code: string | null;
  access: "REVOKED" | "GRANTED";
  sides: CivilLookupSide[];
  changed_by: string | null;
  changed_at: string | null;
}

/** FB-CIVIL-02D: the latest overrides only — a recent list, never a directory. */
export interface CivilLookupUserAccessList {
  data: CivilLookupUserAccessRow[];
  meta: { limit: number; total: number };
}

// ---------------------------------------------------------------- access page (FB-CIVIL-02D)

/** An individual override; DEFAULT = no override (the role policies decide). */
export type CivilLookupAccessValue = "GRANTED" | "REVOKED" | "DEFAULT";

export type CivilLookupAccountSide = "STAFF" | "FAMILY" | "INVALID" | "NONE";

/** One account's current access, as the server resolves it. */
export interface CivilLookupAccountState {
  user: { id: number; name: string; email: string | null; is_active: boolean; account_side: CivilLookupAccountSide; roles: string[] };
  person: { person_code: string; full_name: string; link_status: string } | null;
  access: CivilLookupAccessValue;
  sides: CivilLookupSide[];
  changed_by: string | null;
  changed_at: string | null;
  /** The latest per-user change in the history (also after a return to DEFAULT). */
  last_change: { changed_by: string | null; changed_at: string | null } | null;
  /** The sides that may be GRANTED to this account right now (family-side only). */
  grantable_sides: CivilLookupSide[];
  role_policy: { role: string; daily_limit: number | null } | null;
  /** Effective eligibility per context right now — gates, permissions and policies included. */
  effective: Partial<Record<CivilLookupContext, { allowed: boolean; reason: string | null }>>;
}

export interface CivilLookupStaffAccount {
  id: number;
  name: string;
  email: string | null;
  is_active: boolean;
  account_side: CivilLookupAccountSide;
  roles: string[];
}
