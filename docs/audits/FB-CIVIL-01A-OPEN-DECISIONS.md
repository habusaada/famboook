# FB-CIVIL-01A — Open Decisions

**Status:** every item below is **OPEN** unless marked otherwise. Nothing here is decided by this document.
**Date:** 2026-10-25.

**Approved decisions (from the brief) [APPROVED]:**
- FB-CIVIL-D01: immutable for every user, including SUPER_ADMIN.
- Automatic insert and update after successful validation, with previous versions preserved.
- Missing identities are never deleted.
- Conflicts and duplicates are isolated and reported.
- Atomic publication.
- No direct import into `persons`.
- Production read-only for this task.

## Data-integrity decisions (questions 1–7 of the brief)

| # | Question | Evidence | Recommended safe default |
|---|---|---|---|
| OD-01 | Is `mainid` the unique National ID key? | **None.** The manifest has names only; record values were not read | Make the key column **configurable**. Run the aggregate profiling (Phase 0) for uniqueness and format. Until decided, nothing is published to Production |
| OD-02 | Role of `sert1`? | None (no data dictionary for the source) | Store it raw, give it no meaning, and never use it as a key unless profiling and the source owner confirm it |
| OD-03 | Do `fatherid` / `motherid` / `partnerid` reference `mainid`? | None | Store raw. Build **no** relationship structures in 01A. Profile the match rate. Any derived relationship (01B–01D) needs this decision first |
| OD-04 | Do blank values in a later import replace non-blank values? | The manifest says empty may be NULL or empty string, so they can't be told apart | **Do not overwrite** a non-blank published value with a blank one. Record `BLANK_OVER_VALUE`, keep the old value, and leave the decision to the source owner |
| OD-05 | Are future imports full snapshots or partial updates? | None | Enable **SNAPSHOT** only. A run with sharply fewer rows than the current release is REJECTED by the gate. Partial mode stays disabled until decided |
| OD-06 | Quarantine without blocking automatic publication; threshold values | Design §5 | Quarantine only the conflicting keys, so their previous version stays. Publish the rest automatically. Proposed starting thresholds, to be confirmed: <ul><li>reject if conflicts are more than 0.5% of rows;</li><li>reject if the row count is below 98% of the previous release;</li><li>reject if changed rows are more than 20%.</li></ul> |
| OD-07 | Versioning and publication approach | Design §4 | Option A (one-transaction diff with MVCC), versions table and per-release presence. Option B (swap) is the fallback if the Phase 4 rehearsal shows the first load is too heavy. Retention of presence rows per release is also to be decided |

## Governance, legal and operational decisions

| # | Decision | Evidence | Recommended safe default |
|---|---|---|---|
| OD-08 | Lawful basis, source authority, purpose limitation, retention and Privacy Policy coverage for the reference dataset | D3 PENDING; the Privacy Policy 1.0 does not describe this dataset | **Block any Production load** until recorded. Engineering may proceed with synthetic data |
| OD-09 | FB-CIVIL-01B contradicts `docs/03` §47e "there is no global National ID search" | `docs/03` around line 1273 | 01A creates no search. 01B needs an explicit baseline change, with anti-enumeration and audit design. **2026-10-28:** the design is PROPOSED in `docs/adr/FB-CIVIL-02A-CIVIL-REGISTRY-LOOKUP.md` (implemented locally, off by default); the exception itself is **not approved** |
| OD-10 | 01C and 01D contradict "genealogy out of scope for V1" (`docs/03` line 214, §47e) | `docs/03` | Record now; decide before 01C or 01D starts |
| OD-11 | Roadmap placement (FB-CIVIL is not in `docs/07-ROADMAP.md`) | `docs/07` | Add it as a separate program after the current release |
| OD-12 | Database roles and who runs identity migrations (`famboook_app` cannot create roles; migrations run as `famboook_app`) | `docs/08` §11, `db-preflight.sql` | A DBA script for the schema, roles and grants. The import uses a separate connection and credentials. Migrations refuse clearly without the prerequisite roles |
| OD-13 | Queue technology (the brief says Redis; the repository and Production use `database`) | Audit §3 | Use the CLI design with no queue dependency in 01A. Any move to Redis is a separate infrastructure decision |
| OD-14 | Location and retention of the source files after import | — | A non-served directory with mode 600. Delete securely, or keep an encrypted archive, per the retention decision. Never in Git or on developer machines |
| OD-15 | Any linkage between `identity_ref.records` and `persons` | Approved: no direct import | None in 01A. Any future matching is a separately designed, audited feature |
| OD-16 | Key format validation (`persons` requires 9 digits; the reference may differ) | `2026_10_20` migration | Store raw. Flag non-conforming keys as issues. Exclude only empty keys until the format rule is decided from profiling |
| OD-17 | Production capacity and backup impact (disk, memory, WAL, backup size and time) | Unknown | Measure in the Phase 4 rehearsal before any load |
| OD-18 | Who may run the import (named operator role) and in which window | — | A named operator with server access, after a dry-run review; runs recorded in `import_runs` |
| OD-19 | Untracked `famboook-2026-10-23_18-18-28.dump` in the repository root, not ignored by Git | `git status`, `git check-ignore` | Move it out of the repository and add `*.dump` to `.gitignore`. Treat it as sensitive. Not opened by this audit |
