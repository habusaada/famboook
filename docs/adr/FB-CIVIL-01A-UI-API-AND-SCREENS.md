# FB-CIVIL-01A-UI — API Contracts and Screens

| | |
|---|---|
| Status | **PROPOSED implementation contract (U0 finalized 2026-10-26).** Not implemented |
| Architecture | `docs/adr/FB-CIVIL-01A-UI-IMPORT-MANAGEMENT.md` (state machines §3, worker §4, monitor boundary §5, RBAC §7, audit §8) |
| Decisions | `docs/decisions/FB-CIVIL-01A-UI-DECISIONS.md` (D-UI-1 … D-UI-13, S-1 … S-7) |

## 1. API conventions (as the existing `/api/v1`)

- **Authentication:** Sanctum, staff side; the Staff middleware stack, as `/imports/initial-families`.
- **Authorization:** `can:<permission>` middleware on every route, plus a Policy. Frontend checks are UX only.
- **Responses:** `{"data": …}` from API Resources; paginated lists add `meta` (`current_page`, `last_page`, `per_page`, `total`).
- **Errors:**
  - `401`, `403`, `404`;
  - `409 {"message", "code"}` for a disallowed phase or rule;
  - `422` validation;
  - `503 {"message", "code": "MONITOR_NOT_CONFIGURED"}` when the monitor credentials file is missing or unreadable. There is never a fallback to the application role.
- **S-1:** no endpoint executes an import. Write endpoints only insert a request (202) or a publication decision; the worker executes.
- **Never in any response:**
  - a national ID, name, date of birth, phone, address, religion or any source value;
  - a raw row or payload, a key fingerprint, a SHA value, an internal file id, a server path;
  - SQL or a database message. Failures carry the FB-CIVIL reason code and the redacted text.
- **D-UI-8:** source **file names** and **line numbers** appear only in the `locations` endpoints, which require `civil-import.locations.view` and are audited. Everywhere else files are identified by `file_no` (an ordinal within the run).
- **Identifiers and formats:** run, request and publication request ids are UUIDs; release ids are integers; times are ISO-8601 with offset.

**409 codes:**
- `PHASE_NOT_ALLOWED`;
- `REQUEST_ALREADY_ACTIVE`;
- `PUBLICATION_ALREADY_OPEN`;
- `SAME_USER_APPROVAL`;
- `PUBLICATION_GATE_CLOSED`;
- `PUBLICATION_EXPIRED`;
- `BASE_CHANGED`.

## 2. Read endpoints (U1; `civil-import.view` unless noted)

**`GET /api/v1/civil-registry/overview`**

```json
{ "data": {
  "active_release": { "id": 3, "record_count": 2399891, "published_at": "…", "run_id": "uuid" },
  "current_run": { "id": "uuid", "phase": "PREPARING", "started_at": "…" },
  "worker": { "state": "IDLE|BUSY|STALE|NOT_INSTALLED", "last_seen_at": "…" },
  "publication_enabled": false,
  "pending_publication_requests": 0
} }
```

`active_release` and `current_run` are null when absent. `worker` is filled from U2 (`NOT_INSTALLED` in U1).

**`GET /api/v1/civil-registry/imports?page=1&status=RUNNING`**

```json
{ "data": [ { "id": "uuid", "phase": "STAGED", "run_status": "RUNNING", "error_code": null,
              "expected_rows": 2399891, "rows_loaded": 2399891, "issues": 0, "release_id": null,
              "requested_by": null, "started_at": "…", "finished_at": null } ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 25, "total": 1 } }
```

`requested_by` is `{ "name": … }` for runs started from the UI (U2+), and `null` for runs started from the CLI.

**`GET /api/v1/civil-registry/imports/{run}`**

```json
{ "data": {
  "id": "uuid", "phase": "VERIFIED", "run_status": "RUNNING", "source_table": "t03maindata",
  "expected_rows": 2399891, "rows_loaded": 2399891, "issues": 0,
  "counters": { "new_records": 0, "new_versions": 0, "unchanged": 0, "carried_forward": 0 },
  "files": [ { "file_no": 1, "status": "STAGED", "expected_rows": 100000, "loaded_rows": 100000,
               "checksum_verified": true, "chunks": 20, "last_chunk_at": "…" } ],
  "release": { "id": 4, "status": "BUILDING", "base_release_id": 3, "preparation_status": "VERIFIED" },
  "allowed_actions": ["REQUEST_PUBLICATION", "ABORT"],
  "active_request": null
} }
```

`allowed_actions` is a hint only: the phase intersected with the user's permissions.

**`GET /api/v1/civil-registry/imports/{run}/issues`**: counts only.

```json
{ "data": [ { "code": "MAINID_DUPLICATE", "column": "mainid", "rows": 2 } ] }
```

**Locations** (`civil-import.locations.view`; each call writes a `LOCATIONS_VIEWED` audit event):
- `GET /api/v1/civil-registry/imports/{run}/files/locations` → `{"data": [{"file_no": 1, "file_name": "t03maindata_part_00001.csv"}]}`
- `GET /api/v1/civil-registry/imports/{run}/issues/locations?code=MAINID_DUPLICATE&page=1` → `{"data": [{"file_name": "…", "line_number": 42117, "code": "MAINID_DUPLICATE", "column": "mainid"}], "meta": {…}}`

**`GET /api/v1/civil-registry/releases`** and **`/releases/{release}`**

```json
{ "data": {
  "id": 4, "status": "BUILDING", "run_id": "uuid", "base_release_id": 3, "record_count": null, "is_active": false,
  "preparation": { "status": "VERIFIED", "policy_id": "IDREF-POLICY-1", "comparison_policy": "IDREF-CMP-1",
                   "blank_field_policy": "PRESERVE_EXISTING_ON_BLANK", "quarantine": "ZERO_UNRESOLVED_QUARANTINE",
                   "counts": { "staged": 2399891, "new": 1200, "changed": 2388, "unchanged": 2396303, "conflict": 0,
                               "carried_forward": 1200, "blank_over_value": 0, "expected_presence": 2401091 },
                   "warnings": { "MAINID_NOT_9_DIGITS": 2, "FATHERID_UNRESOLVED": 260487 },
                   "prepared_at": "…", "verified_at": "…" },
  "published_at": null
} }
```

**`GET /api/v1/civil-registry/audit?run=uuid&page=1`** (`civil-import.audit.view`)

```json
{ "data": [ { "occurred_at": "…", "event_type": "PUBLICATION_REQUESTED", "actor": { "side": "STAFF", "name": "…" },
              "run_id": "uuid", "release_id": 4, "outcome_code": null, "details": { "expected_presence": 2401091 } } ],
  "meta": { … } }
```

## 3. Execution endpoints (U2)

**Read:** `GET /api/v1/civil-registry/sources` (`civil-import.stage`) returns the catalogue written by the worker. It shows labels (O-2) and never file names or contents.

```json
{ "data": [ { "id": "uuid", "label": "t03maindata-2026-11-01", "table": "t03maindata", "files": 24,
              "total_rows": 2399891, "available": true, "discovered_at": "…", "staged_run_id": null } ] }
```

**Write:** every write returns **202** with the created request, `{"data": {"id": "uuid", "type": "STAGE", "status": "QUEUED", …}}`.

| Method and path | Permission | Body | Phase required |
|---|---|---|---|
| `POST /civil-registry/imports` | `civil-import.stage` | `{"source_id": "uuid"}` | No RUNNING run, or the same source (resume) |
| `POST /civil-registry/imports/{run}/abort` | `civil-import.stage` | `{"reason_code": "OPERATOR_DECISION"}` | Not REJECTED, ABORTED or PUBLISHED |
| `POST /civil-registry/imports/{run}/assess` | `civil-import.validate` | — | STAGED or later, before PUBLISHED |
| `POST /civil-registry/imports/{run}/prepare` | `civil-import.validate` | — (registry policy `IDREF-POLICY-1`; a synthetic policy is never accepted) | STAGED or PREPARING |
| `POST /civil-registry/releases/{release}/verify` | `civil-import.validate` | — | PREPARED |
| `DELETE /civil-registry/requests/{request}` | requester or `civil-import.stage` | — | Request QUEUED |
| `GET /civil-registry/requests/{request}` | `civil-import.view` | — | — |

Request resource:

```json
{ "data": { "id": "uuid", "type": "ASSESS", "status": "SUCCEEDED", "requested_by": { "name": "…" },
            "run_id": "uuid", "release_id": null, "reason_code": null, "message": null,
            "result": { "fatal": [], "blocked": [], "counts": { "staged": 2399891, "new": 1200 },
                        "conflicts": {}, "warnings": {} },
            "queued_at": "…", "started_at": "…", "finished_at": "…" } }
```

`result` is copied from the FB-CIVIL-01A assessment and holds counts and codes only.

## 4. Publication endpoints (U3)

| Method and path | Permission | Body | Rules |
|---|---|---|---|
| `POST /civil-registry/releases/{release}/publication-requests` | `civil-import.publish.request` | `{"note_code": "ROUTINE_REFRESH"}` (codes only) | Release VERIFIED; no other open request; expires_at = now + 24 h |
| `POST /civil-registry/publication-requests/{id}/withdraw` | the requester | — | PENDING_APPROVAL |
| `POST /civil-registry/publication-requests/{id}/approve` | `civil-import.publish.approve` | `{"confirm_release_id": 4, "confirm_expected_presence": 2401091}` | User ≠ requester (`SAME_USER_APPROVAL`); gate open (`PUBLICATION_GATE_CLOSED`); not expired; base unchanged. Creates the PUBLISH request (202) |
| `POST /civil-registry/publication-requests/{id}/reject` | `civil-import.publish.approve` | `{"reason_code": "COUNTS_UNEXPECTED"}` | User ≠ requester |
| `GET /civil-registry/publication-requests?status=PENDING_APPROVAL` | `civil-import.view` | — | — |

An approved publication can still be refused by the worker (S-3). For example, the real-data authorization reference may be missing, the gate may be closed in the worker's configuration, or the base may have changed. The PUBLISH request then ends `FAILED` with the reason code (`AUTHORIZATION_MISSING`, `PUBLICATION_GATE_CLOSED`, `BASE_CHANGED`, `NOT_VERIFIED`), a `PUBLICATION_REFUSED` audit event is written, and the previous release stays active.

## 5. Frontend (Next.js Staff app)

**Routes:** `app/(staff)/administration/civil-registry/`, each page wrapped in `RequirePermission`. The navigation entry (`lib/navigation.ts`) appears only with `civil-import.view`.

| Route | Page | Stage |
|---|---|---|
| `/administration/civil-registry` | Overview: active release, current run, worker state, pending approvals, publication gate status ("مغلق" by default) | U1 |
| `/administration/civil-registry/imports` | Import history: filterable table, `RegistryPagination` | U1 |
| `/administration/civil-registry/imports/[runId]` | Run detail; tabs: **Overview** (phase stepper, counters), **Files and checkpoints** (by `file_no`, with names only with `locations.view`; per-file `Meter`), **Validation** (fatal / quarantine / warnings by code; locations with `locations.view`), **Comparison** (counts versus the active release), **Release**, **Audit** | U1 read; actions U2 / U3 |
| `/administration/civil-registry/imports/new` | Wizard: (1) choose a catalogued source, (2) confirm the manifest summary, (3) queue staging, then live progress | U2 |
| `/administration/civil-registry/releases` and `/[releaseId]` | Releases: list and detail (policy, counts, warnings) | U1 |
| `/administration/civil-registry/approvals` | Publication requests: request, withdraw, approve or reject with typed confirmation; the approve button is hidden for the requester and when the gate is closed | U3 |
| `/administration/civil-registry/audit` | Audit history (`civil-import.audit.view`) | U1 |

**Components:**
- **Reused:** `PageLayout`, `KpiCard`, `StatStrip`, `StatusBadge`, `Meter`, `EmptyState`, `RegistryPagination`, `RequirePermission`, shadcn `Table`/`Tabs`/`Dialog`/`Alert`/`Card`/`Badge`, and the stepper pattern from `import-wizard/wizard-parts.tsx`.
- **`StatusBadge` tones:**
  - success: PUBLISHED, VERIFIED;
  - info: RUNNING, QUEUED;
  - warning: AWAITING_APPROVAL, INTERRUPTED;
  - danger: REJECTED, FAILED;
  - neutral: ABORTED, EXPIRED;
  - brand: the active release.
- **New, in `components/administration/civil-registry/`:** `PhaseStepper`, `FileProgressTable`, `IssueSummary`, `LocationsPanel` (only with `locations.view`), `ComparisonCounts`, `RequestStatusCard`, `PublicationApprovalPanel`, `AuditTimeline`, and `labels.ts` (Arabic labels for phases, codes and events).

**Data:**
- `lib/api/civil-registry.ts` (TanStack Query hooks); types in `lib/types`.
- Zod schemas for bodies; React Hook Form for the typed approval confirmation.
- **Polling (D-UI-13):** `refetchInterval` returns 5000 only while a request is QUEUED or RUNNING, otherwise false; `refetchIntervalInBackground: false`, so it stops in hidden tabs (the `lib/api/announcements.ts` pattern).

**UI rules (S-7):**
- Arabic RTL; existing palette and tokens only (docs/10, FB-UI-01); no new colours.
- Existing number formatting.
- Abort and approve use a confirmation `Dialog`; approval needs the typed release id and expected count.
- Nothing renders a national ID or source value, and no endpoint can supply one.
