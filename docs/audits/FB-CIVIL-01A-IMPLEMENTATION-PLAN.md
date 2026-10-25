# FB-CIVIL-01A — Implementation Plan

**Status:** PROPOSED — nothing is implemented; each phase needs explicit approval.
**Date:** 2026-10-25.

## Phase 0 — Decisions and aggregate profiling (no code merged, no Production)

**Decide (management, legal, operations):**
- OD-08 lawful basis and Privacy Policy coverage;
- OD-09 / OD-10 baseline conflicts (relevant only for 01B–01D, but recorded now);
- OD-11 roadmap placement;
- OD-12 database roles;
- OD-14 file location and retention;
- OD-17 capacity.

**Aggregate profiling script** (approved machine; output **counts only**). It answers:
- **OD-01:** uniqueness and format distribution of `mainid` and `sert1`.
- **OD-03:** share of `fatherid` / `motherid` / `partnerid` values that match a `mainid` in the same export.
- **OD-04:** blank rates per column.
- **Other checks:** the share of `mainid` values meeting the 9-digit rule used for `persons`, and the number of exact duplicate rows.

**Exit:** OD-01, OD-03, OD-04, OD-05, OD-06 and OD-16 are decided with the profiling evidence.

## Phase 1 — Schema, roles, immutability (synthetic data)
- **DBA script:**
  - creates `identity_ref`, `famboook_identity_owner` and `famboook_identity_import`;
  - grants per the database proposal §3;
  - kept outside `deploy-backend.sh`, as for `db-preflight.sql`.
- **Laravel migrations:**
  - tables, `CHECK` constraints, indexes and triggers, PostgreSQL-only;
  - guarded so the SQLite suite skips them;
  - each migration must be safe to run as `famboook_app` or must refuse clearly (OD-12).
- **PostgreSQL tests:**
  - `famboook_app` cannot read or write `identity_ref`;
  - `UPDATE` / `DELETE` on versions always fail;
  - `DELETE` on records always fails;
  - updates outside the import role fail.
- **Unchanged:** the RBAC matrix (162).

## Phase 2 — Verification and staging loader
- **Loader:** manifest parser, file verifier (SHA-256, BOM, header) and the COPY loader into the `UNLOGGED` staging table. Confirm the `pdo_pgsql` COPY API on the Production PHP version (8.3), or use a `COPY FROM STDIN` stream.
- **Error sanitization:** no row content in exceptions or logs.
- **Tests:** synthetic CSV fixtures (24 tiny files plus a manifest), with bad checksum, bad header, wrong row count, wrong field count, bad UTF-8 and missing or extra file.

## Phase 3 — Validation, diff, versioning, publication
- **Logic:** row hash, key validation, duplicate and conflict quarantine, NEW / CHANGED / UNCHANGED / MISSING, blank policy, thresholds, the publication transaction, presence, the release pointer and dry run.
- **PostgreSQL tests:**
  - atomicity: a forced failure at each publication step leaves the previous release intact;
  - concurrency: two runs, lock refusal;
  - idempotency: same manifest gives NOTHING_TO_DO;
  - versions preserved; missing never deleted;
  - conflicts never published.

## Phase 4 — Operations and rehearsal
- **Command:** `famboook:identity-import` (production confirmation, `--dry-run`) and an operator runbook.
- **Rehearsal:**
  - on a restored copy of Production or an equivalent server, with the **real** files **only if OD-08 is approved**, otherwise with synthetic volume of the same size;
  - measure duration, WAL, disk and memory;
  - decide Option A vs B (OD-07).
- **Backup:** a size and backup impact review (OD-17).

## Phase 5 — First Production load (separate approval)
1. Fresh backup.
2. Dry run.
3. Review.
4. Run.
5. Record the release id.
6. Handle the source files per OD-14.

## Out of scope (separate programs)
01B inquiry, 01C suggestions, 01D tree: each needs its own baseline decisions (OD-09, OD-10), permissions, policies, UI and privacy review.

## Risks and dependencies (summary)

| Risk / dependency | Mitigation |
|---|---|
| Lawful basis not established (D3, OD-08) | Gate the Production load on it |
| Key semantics unknown (OD-01) | Configurable key; profiling first |
| Single database role today (OD-12) | DBA step plus a separate import connection |
| Production capacity unknown (OD-17) | Measure in Phase 4 |
| Restore never rehearsed (R-1) | Rehearse before Phase 5 |
| Redis assumed in the brief but not used (OD-13) | CLI design, no queue dependency |
| Baseline conflicts for 01B–01D (OD-09, OD-10) | Record now, decide before those programs |
| Sensitive data leaks through errors or logs | Sanitized COPY errors; counts-only output; tests |
