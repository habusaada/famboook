// Types mirroring backend InitialFamilyImportController / ImportBatchResource
// (docs/03 §96a, Import Wizard). Batch-level facts only: the API never
// returns row payloads, National IDs or names (samples are masked).

export type ImportMode = "INITIAL" | "INCREMENTAL";

export type ImportBatchStatus =
  | "UPLOADED"
  | "VALIDATING"
  | "READY_FOR_REVIEW"
  | "READY_TO_APPLY"
  | "APPLYING"
  | "PARTIALLY_APPLIED"
  | "APPLIED"
  | "FAILED";

export type ImportRowStatus = "PENDING" | "VALID" | "FLAGGED" | "REJECTED" | "APPLIED" | "SKIPPED";

export interface ImportWorksheet {
  name: string;
  data_rows: number;
  column_count: number;
  plausible: boolean;
  recognized: boolean;
  too_many_rows: boolean;
}

export interface ImportColumnMapping {
  fields: Record<string, string>;
  ignored: string[];
}

export interface ImportBatch {
  id: string;
  status: ImportBatchStatus;
  import_mode: ImportMode;
  source_filename: string;
  source_size_bytes: number | null;
  row_count: number;
  clan?: { code: string; name: string };
  uploaded_by?: { name: string } | null;
  created_at: string | null;
  worksheets: ImportWorksheet[];
  suggested_worksheet: string | null;
  worksheet_name: string | null;
  column_mapping: ImportColumnMapping | null;
  mapping_confirmed_at: string | null;
  staged: boolean;
}

// TOTAL = READY + NEEDS_REVIEW + REJECTED (backend-calculated).
export interface ImportCounts {
  total: number;
  ready: number;
  needs_review: number;
  rejected: number;
}

export type ReconciliationStatus = "NEW" | "UNCHANGED" | "CHANGED" | "DUPLICATE_IN_FILE" | "CONFLICT" | "REVIEW_REQUIRED";

// Backend-authoritative reconciliation (never a registry write). STALE =
// inputs changed since the last run (staging, key decisions or registry).
export interface ReconciliationSummary {
  state: "NOT_RUN" | "CURRENT" | "STALE";
  reconciled_at: string | null;
  counts: Record<ReconciliationStatus, number> | null;
  requires_review: number | null;
  stats: {
    head_existing_person: number;
    head_no_existing_person: number;
    head_no_national_id: number;
    head_existing_person_no_family: number;
    family_matches: number;
    spouse_existing_person_candidates: number;
    cross_role_collision_rows: number;
    head_match: Record<string, number>;
    family_match: Record<string, number>;
    issues: Record<string, number>;
  } | null;
}

export interface ReconciliationIssue {
  code: string;
  context: Record<string, unknown> | null;
}

export interface ReconciliationDifference {
  field: string;
  scope: "person" | "family";
  registry: string | number | null;
  source: string | number | null;
}

export interface ReconciliationRow {
  row_number: number;
  source_family_key: string | null;
  head_name: string | null;
  national_id_masked: string | null;
  status: ReconciliationStatus;
  head_match: string;
  family_match: string;
  person_code: string | null;
  family_code: string | null;
  spouse_matches: { slot: number; status: string }[];
  issues: ReconciliationIssue[];
  differences: ReconciliationDifference[];
}

export interface ReconciliationRowsPage {
  data: ReconciliationRow[];
  meta: { current_page: number; last_page: number; total: number };
}

export interface ImportBatchSummary {
  counts: ImportCounts;
  key_resolution: KeyResolutionCounts;
  reconciliation: ReconciliationSummary;
  staged_rows: number;
  statuses: Record<ImportRowStatus, number>;
  structural_errors: number;
  missing_family_key: number;
  formula_family_key: number;
  distinct_family_keys: number;
  issues: Record<string, number>;
}

export interface ImportBatchDetail extends ImportBatch {
  summary: ImportBatchSummary;
}

export interface ImportField {
  field: string;
  required: boolean;
}

export interface ImportColumn {
  letter: string;
  position: number;
  header: string;
  suggested_field: string | null;
  // Masked preview values (IDs/phones show the last 3 digits only).
  samples: string[];
}

export interface ImportColumns {
  worksheet: string;
  fields: ImportField[];
  columns: ImportColumn[];
  excluded_columns: { letter: string; header: string }[];
  confirmed_mapping: ImportColumnMapping | null;
}

export type FamilyKeyDecision = "MATCH_EXISTING_BRANCH" | "CREATE_NEW_BRANCH" | "SAME_BRANCH_AS_KEY" | "NO_BRANCH";

export interface ImportBranchRef {
  id: string;
  code: string;
  name: string;
  is_active: boolean;
  group?: string | null;
}

// A saved decision; null on the key = UNRESOLVED (≠ NO_BRANCH).
export interface FamilyKeyResolution {
  decision: FamilyKeyDecision;
  branch: ImportBranchRef | null;
  reference_source_key: string | null;
  resolved_by: { name: string } | null;
  resolved_at: string;
}

// Backend-authoritative progress (blank keys never need a decision).
export interface KeyResolutionCounts {
  distinct_keys: number;
  resolved_keys: number;
  unresolved_keys: number;
  match_existing: number;
  created_branch: number;
  same_branch_as_key: number;
  no_branch: number;
  complete: boolean;
}

// One distinct source key: discovery facts, exact-name SUGGESTION, decision.
export interface FamilyKeyDiscovery {
  key: string;
  row_count: number;
  formula_rows: number;
  example_rows: number[];
  existing_branch: { code: string; name: string; is_active: boolean } | null;
  resolution: FamilyKeyResolution | null;
}

export interface FamilyKeysPayload {
  data: FamilyKeyDiscovery[];
  meta: {
    resolution: KeyResolutionCounts;
    clan: { code: string; name: string; is_active: boolean };
    // Selectable Branches of the batch's target Clan only.
    branches: ImportBranchRef[];
    can_create_branch: boolean;
  };
}

export type ProblemRowFilter = "all" | "needs_review" | "rejected";

export interface ProblemRow {
  row_number: number;
  status: ImportRowStatus;
  issues: string[];
  source_family_key: string | null;
}

export interface ProblemRowsPage {
  data: ProblemRow[];
  meta: { current_page: number; last_page: number; total: number };
}

// ---- Step 6 Dry Run (docs/03 §96b): the pure Apply plan, read only ----------

export type ApplyIntent = "CREATE" | "REUSE" | "OMIT" | "BLOCK";

export type DryRunState = "READY" | "ROWS_BLOCKED" | "PRECONDITIONS_FAILED";

export type DryRunFilter = "all" | "executable" | "blocked" | "warnings";

export type DryRunEffectGroup =
  | "families"
  | "head_persons"
  | "head_memberships"
  | "spouse_persons"
  | "spouse_memberships"
  | "declarations"
  | "residences";

export interface DryRunCounts {
  source_rows: number;
  executable_rows: number;
  blocked_rows: number;
  warning_rows: number;
  effects: Record<DryRunEffectGroup, Record<ApplyIntent, number>>;
  spouse_slots: number;
  spouse_person_reuse: { existing: number; planned: number };
  persons: { create: number; reuse_existing: number };
  // Every effect reason (omissions and blocks) → occurrences.
  reasons: Record<string, number>;
  // Rows per non-blocking warning code.
  warnings: Record<string, number>;
}

export interface DryRunSummary {
  state: DryRunState;
  preconditions: { code: string; count?: number }[];
  plan_fingerprint: string;
  // Always false in this phase: final execution is not enabled.
  execution_enabled: boolean;
  counts: DryRunCounts | null;
}

/** One planned effect: intent, reason and safe references only (no names, no IDs). */
export interface DryRunEffect {
  intent: ApplyIntent | null;
  reason: string | null;
  person_code?: string;
  owner?: { row_number: number; effect: string };
  gender?: string;
  life_status?: string;
  branch?: string | null;
}

export interface DryRunRow {
  row_number: number;
  source_family_key: string | null;
  national_id_masked: string | null;
  executable: boolean;
  block_reasons: string[];
  warnings: string[];
  head_person: DryRunEffect;
  family: DryRunEffect;
  head_membership: DryRunEffect;
  declaration: DryRunEffect;
  residence: DryRunEffect;
  spouses: { slot: number; national_id_masked: string | null; person: DryRunEffect; membership: DryRunEffect }[];
}

export interface DryRunRowsPage {
  data: DryRunRow[];
  meta: { current_page: number; last_page: number; total: number };
}
