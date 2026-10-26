// FB-CIVIL-01A-UI — civil identity import management (metadata only). U1:
// CivilImportRunResource, CivilImportRunDetailResource,
// CivilImportReleaseResource, CivilImportAuditEventResource. U2: execution
// requests (CivilImportRequestResource) and catalogued sources
// (CivilImportSourceResource). These types hold no identity value, path or
// file name by construction: the API never returns one.

export type CivilImportPhase =
  | "STAGING"
  | "STAGING_INTERRUPTED"
  | "STAGED"
  | "PREPARING"
  | "PREPARED"
  | "VERIFIED"
  | "PUBLISHED"
  | "REJECTED"
  | "ABORTED"
  | "FAILED"
  | "NOTHING_TO_DO";

export type CivilImportRunStatus = "RUNNING" | "SUCCEEDED" | "FAILED" | "REJECTED" | "NOTHING_TO_DO";

export interface CivilImportOverview {
  active_release: {
    id: number;
    record_count: number | null;
    published_at: string | null;
    run_id: string;
  } | null;
  current_run: {
    id: string;
    phase: CivilImportPhase;
    started_at: string | null;
  } | null;
  worker: {
    state: "IDLE" | "BUSY" | "STALE" | "NOT_INSTALLED";
    last_seen_at: string | null;
  };
  publication_enabled: boolean;
  pending_publication_requests: number;
  active_request: CivilImportRequest | null;
}

export interface CivilImportRun {
  id: string;
  phase: CivilImportPhase;
  run_status: CivilImportRunStatus;
  error_code: string | null;
  expected_rows: number;
  rows_loaded: number;
  issues: number;
  file_count: number;
  release_id: number | null;
  requested_by: { name: string } | null;
  started_at: string | null;
  finished_at: string | null;
}

/** A source file by ordinal only (D-UI-8); its name needs civil-import.locations.view. */
export interface CivilImportFile {
  file_no: number;
  status: "PENDING" | "VERIFIED" | "STAGED" | "FAILED";
  expected_rows: number;
  loaded_rows: number;
  checksum_verified: boolean;
  chunks: number;
  last_chunk_at: string | null;
}

export interface CivilImportRunDetail extends CivilImportRun {
  source_table: string;
  counters: {
    new_records: number;
    new_versions: number;
    unchanged: number;
    carried_forward: number;
  };
  files: CivilImportFile[];
  release: {
    id: number;
    status: CivilImportReleaseStatus;
    base_release_id: number | null;
    preparation_status: string | null;
  } | null;
  /** A hint (phase ∩ permissions); the API and the worker re-check everything. */
  allowed_actions: CivilImportAction[];
  active_request: CivilImportRequest | null;
  /** The catalogued source of a run staged from the UI; label only for civil-import.stage holders (O-2). */
  source: { id: string; label: string | null; available: boolean } | null;
}

export interface CivilImportIssueCount {
  code: string;
  column: string | null;
  rows: number;
}

export interface CivilImportFileLocation {
  file_no: number;
  file_name: string;
}

export interface CivilImportIssueLocation {
  file_name: string;
  line_number: number | null;
  code: string;
  column: string | null;
}

export type CivilImportReleaseStatus = "BUILDING" | "PUBLISHED" | "ABANDONED";

export interface CivilImportCounts {
  staged: number;
  new: number;
  changed: number;
  unchanged: number;
  conflict: number;
  carried_forward: number;
  blank_over_value: number;
  expected_presence: number;
}

export interface CivilImportRelease {
  id: number;
  status: CivilImportReleaseStatus;
  run_id: string;
  base_release_id: number | null;
  record_count: number | null;
  is_active: boolean;
  preparation: {
    status: "PREPARING" | "PREPARED" | "VERIFIED";
    policy_id: string;
    comparison_policy: string;
    blank_field_policy: string;
    quarantine: string;
    counts: CivilImportCounts;
    warnings: Record<string, number>;
    prepared_at: string | null;
    verified_at: string | null;
  } | null;
  created_at: string | null;
  published_at: string | null;
  abandoned_at: string | null;
}

export interface CivilImportAuditEvent {
  occurred_at: string | null;
  event_type: string;
  actor: { side: "STAFF" | "SYSTEM"; name: string | null };
  run_id: string | null;
  release_id: number | null;
  outcome_code: string | null;
  details: Record<string, string | number>;
}

export interface CivilImportPageMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

// ---- U2: execution requests and sources

export type CivilImportAction = "STAGE" | "ABORT" | "ASSESS" | "PREPARE" | "VERIFY";

export type CivilImportRequestType = "VALIDATE_SOURCE" | "STAGE" | "ABORT" | "ASSESS" | "PREPARE" | "VERIFY";

export type CivilImportRequestStatus = "QUEUED" | "RUNNING" | "SUCCEEDED" | "FAILED" | "CANCELLED";

/** Counts and codes only (CivilImportResult allow-list). */
export interface CivilImportRequestResult {
  run_id?: string;
  release_id?: number;
  resumed?: boolean;
  publishable?: boolean;
  manifest?: string;
  files?: number;
  total_rows?: number;
  rows_staged?: number;
  rows_loaded_now?: number;
  files_staged_now?: number;
  chunks_loaded?: number;
  base_release?: number;
  rows_purged?: number;
  counts?: Partial<CivilImportCounts>;
  issues?: Record<string, number>;
  conflicts?: Record<string, number>;
  warnings?: Record<string, number>;
  problems?: Record<string, number>;
  fatal?: string[];
  blocked?: string[];
  policy_id?: string;
  duration_seconds?: number;
}

export interface CivilImportRequest {
  id: string;
  type: CivilImportRequestType;
  status: CivilImportRequestStatus;
  requested_by: { name: string } | null;
  source: { id: string; label: string | null } | null;
  run_id: string | null;
  release_id: number | null;
  attempts: number;
  reason_code: string | null;
  /** Fixed Arabic text of reason_code (never source data). */
  message: string | null;
  result: CivilImportRequestResult;
  cancel_requested: boolean;
  can_cancel: boolean;
  queued_at: string | null;
  started_at: string | null;
  heartbeat_at: string | null;
  finished_at: string | null;
}

export interface CivilImportSource {
  id: string;
  /** The directory's safe label (O-2); never a path. */
  label: string;
  table: string | null;
  files: number | null;
  total_rows: number | null;
  available: boolean;
  manifest_status: "VALID" | "INVALID";
  manifest_problems: Record<string, number>;
  discovered_at: string | null;
  last_seen_at: string | null;
  last_validation: {
    request_id: string;
    status: CivilImportRequestStatus;
    reason_code: string | null;
    problems: Record<string, number>;
    finished_at: string | null;
  } | null;
  staged_run_id: string | null;
}
