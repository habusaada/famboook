# FB-CIVIL-01A — Identity Reference Import Engine: Technical Design

**Status:** PROPOSED — not approved for implementation.
**Date:** 2026-10-25.
**Basis:** `FB-CIVIL-01A-REPOSITORY-AUDIT.md`.
**Labels:** see that document's header.

## 1. Goals and non-goals

**Approved goals [APPROVED]:**
- Load the `t03maindata` reference export, and later periodic exports, into a **separate** identity reference store.
- Never write to `persons` or `families`.
- New identities are inserted and changed identities are updated automatically, with the previous version preserved.
- Unchanged identities are untouched, and missing identities are never deleted.
- Conflicts and duplicates are isolated and reported.
- Every change is auditable.
- Publication is atomic: users never see a partial dataset, and a failed run leaves the previous release in place.
- Reference records are immutable for every user, including SUPER_ADMIN (FB-CIVIL-D01).

**Non-goals in this phase [APPROVED]:**
- inquiry or search (01B), suggestions (01C), family tree (01D);
- any API or UI that reads identity records;
- household-side access;
- export of reference data.

## 2. Architecture overview [PROPOSED]

```
operator (server shell, approved window)
  └─ php artisan famboook:identity-import <dir> [--dry-run]       ← no HTTP upload, no queue
        1  lock            advisory lock + single RUNNING run (as the geography importer)
        2  verify          manifest schema, 24 files, SHA-256, header == 36 names, row counts
        3  stage           COPY each file into an UNLOGGED staging table (raw text, 36 columns)
        4  validate        per-row and cross-row checks → identity_import_issues (no raw values)
        5  diff            row hash vs published current state → NEW / CHANGED / UNCHANGED / CONFLICT
        6  gate            thresholds (OD-06) → REJECTED keeps the previous release
        7  publish         ONE transaction: versions appended, current rows inserted/updated,
                           release row created, release pointer switched → COMMIT
        8  cleanup         staging dropped; run closed SUCCEEDED (counts only)
```

**Why the command line rather than the staff wizard or a queued job:**
- **Size:** 765 MB of highly sensitive data should not travel through a browser upload, a web worker or `import_rows jsonb`. The files are placed on the server by an authorized operator, in a non-served directory.
- **Precedent:** the approved geography importer (`ImportGazaGeographyAction`) already uses this pattern: artisan only, checksum-verified, advisory lock, one transaction, run rows, dry run.
- **No queue:**
  - the only worker has `--timeout=60` and serves notifications;
  - a long-running import must not occupy it;
  - Redis is not available (audit §3).

  A later web trigger can enqueue the same command on a dedicated queue; that's out of scope here.

**Orchestration code [PROPOSED]:**

| Class | Role |
|---|---|
| `IdentityImportCommand` | Thin: argument parsing, confirmation, output of counts |
| `RunIdentityImportAction` | Orchestration and run-state transitions |
| `IdentityManifest` | Parse and validate the manifest |
| `IdentityStagingLoader` | COPY |
| `IdentityRowValidator` | Pure, unit-testable checks |
| `IdentityDiffer` | Set-based SQL |
| `PublishIdentityReleaseAction` | The publication transaction |

All of this follows the existing Domain Action style and adds no generic framework.

## 3. Evaluation of the proposed components

| Proposed | Verdict | Change recommended |
|---|---|---|
| `identity_import_runs` | Keep | Model on `gaza_geography_import_runs`: uuid, status `CHECK`, single-`RUNNING` partial unique index, counters, `error_summary` without values |
| `identity_import_files` | Keep | One row per manifest file: name, expected and actual SHA-256, expected and loaded rows, status. This is what makes **resume** possible (§6) |
| `identity_import_issues` | Keep, with **no raw values** | Store run, file, line number, issue code, column name and a **keyed fingerprint** of the identity key (`NationalIdFingerprint` pattern). Never the record or the National ID |
| `identity_persons` | Rename to `identity_records` | "persons" invites confusion with the operational `persons` table (audit C-3). It stores the **published current state**: 36 raw text columns, the source key, the row hash, and the first-seen, last-changed and last-seen release ids |
| `identity_person_versions` | Rename to `identity_record_versions` | Append-only previous states: the full 36 columns plus `superseded_by_release_id`. Written only when a record changes |
| `identity_dataset_releases` | Keep | One row per published release (run, counts, published_at), plus a single-row `identity_current_release` pointer |
| *(missing)* staging | **Add** | An `UNLOGGED` staging table, created per run and dropped after: fast COPY with no WAL. It's never read by the application and is acceptable to lose in a crash (the run is simply restarted) |

**Placement [PROPOSED]:** a separate PostgreSQL schema, `identity_ref`. It allows schema-level privileges (OD-12), a clear boundary from operational tables and separate backups or retention if ever decided.

## 4. Atomic publication: options

| Option | How | Pros | Cons |
|---|---|---|---|
| **A. Diff applied in one transaction (recommended)** | Staging fully validated first; then one transaction: insert NEW rows, update CHANGED rows with the old state copied to versions, update `last_seen` for unchanged rows (or record seen-ness in a set-based way), insert the release row, switch the pointer, COMMIT | <ul><li>PostgreSQL MVCC: readers see the old committed state until COMMIT, so nothing is partial.</li><li>Natural versioning; small later transactions when only deltas change.</li><li>Same pattern as the geography importer.</li></ul> | <ul><li>The first load is one large transaction (about 2.4 million inserts), so it needs disk and WAL headroom.</li><li>Updating `last_seen` on every row each run is a full-table write; prefer storing seen-ness per release (database proposal §3).</li></ul> |
| B. Table swap | Build a full new table, then `ALTER TABLE … RENAME` in one transaction | Instant publication; old table kept for rollback | <ul><li>Brief `ACCESS EXCLUSIVE` lock.</li><li>Versions must still be computed separately.</li><li>Doubles storage at swap time.</li><li>Breaks foreign keys and privileges unless recreated.</li></ul> |
| C. Release-scoped rows | Every row carries `valid_from_release` and `valid_to_release`; readers filter on the published release | No in-place updates; history built in | Every future read query must filter correctly (privacy and logic risk); larger indexes; more complex |

**Recommendation [PROPOSED]:** Option A, with seen-ness stored per release rather than by rewriting rows. Option B is a fallback if the first-load transaction proves too heavy in the rehearsal (implementation plan, Phase 4). See OD-07.

## 5. Change detection, duplicates and conflicts [PROPOSED]

- **Row hash:** `sha256` over the 36 values in a fixed order, with a field separator and an explicit marker for empty values. Comparing hashes avoids comparing 36 columns per row.
- **Identity key:** **configurable**, never hard-coded, until OD-01 decides it (`mainid` is only a candidate).
- **Within one run:**
  - same key, identical rows → **DUPLICATE_IDENTICAL**: kept once, issue recorded;
  - same key, different rows → **CONFLICT**: the key is quarantined and **not** published in that run. Its previous published version stays unchanged, and the issue is recorded.
- **Against the published state:** a published key whose new row differs becomes **CHANGED**, versioned and updated, subject to the blank-value policy (OD-04).
- **Missing keys:** a key present before but absent now is **MISSING**: never deleted [APPROVED], and reported through seen-ness.
- **Bad keys:** an empty key or a key failing basic format checks gets issue **INVALID_KEY** and is not published. Which format is valid is OPEN, because the reference may legitimately contain non-9-digit identifiers (OD-16).
- **Circuit breakers (automatic publication remains the default):** the run is REJECTED, keeping the previous release, if:
  - the conflict ratio is above a threshold;
  - the row count drops sharply against the previous release (snapshot mode); or
  - the change ratio is above a threshold.

  Threshold values are OD-06.

## 6. Resume and failure recovery [PROPOSED]

| Failure point | Recovery |
|---|---|
| Failure before publication | Nothing published changes. The run becomes FAILED (or REJECTED for validation refusals). A new run starts from verification. Files whose SHA-256 and row counts already verified may be re-staged without re-hashing **only within the same run id**; a new run re-verifies everything (simple and safe) |
| Failure during publication | The single transaction rolls back, and the previous release stays published |
| Crash with the run left `RUNNING` | The next run closes it as FAILED, "interrupted" (geography pattern), and drops its staging table |
| Same SHA set as the published release | Stops with "nothing to do" (idempotent) |

## 7. Performance (estimates — to be measured in Phase 4)

| Step | Estimate |
|---|---|
| COPY of 765 MB into an `UNLOGGED` table | Typically minutes on modest hardware |
| Hashing and validation | Set-based SQL over staging |
| Diff | Hash join on the key index |
| First load | About 2.4 million inserts plus index builds. Create secondary indexes **after** load where possible |
| Storage | Raw text about 0.8–1.5 GB in table form plus indexes; versions grow only with real changes. Backups grow accordingly (age-encrypted `pg_dump`) [RISK: Production capacity unknown, OD-17] |

PHP memory stays bounded: no row is held in PHP. COPY streams from the file, and every comparison is SQL.

## 8. Integration hooks for 01B–01D (design only, nothing built)

- Records keep the raw relationship columns (`fatherid`, `motherid`, `partnerid`, `householdid`, `householdrel`) **unchanged**.
- A future derived relationship table (`identity_relationships`) can be built **from a published release** once OD-03 has validated the semantics.
- No foreign key to `persons`, no matching against `persons`, and no read endpoint in 01A.
