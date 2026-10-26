# FB-CIVIL-01A — Security and Privacy

**Status:** PROPOSED. **Date:** 2026-10-25.

## 1. Sensitivity [VERIFIED from the column list]

The dataset holds, for about 2.4 million people:
- names, National IDs and dates of birth and death;
- family links (`fatherid`, `motherid`, `partnerid`, `householdid`, `householdrel`);
- religion (`relgion`), phone numbers (`phonenum`), addresses (`govern`, `city`, `street`);
- work, salary and grade (`worker`, `worktype`, `job`, `mgrade`, `msalary`, `mstartw`, `civilg`, `cwloc`).

This is **population-scale, highly sensitive personal data**, far beyond the operational registry. Any leak or bulk enumeration would be severe.

## 2. Lawful processing — gate before loading [OPEN]

| Item | State |
|---|---|
| Legal basis for processing personal and health data | D3 is **PENDING** (`docs/decisions/FB-LEGAL-01-DECISIONS.md`). The prepared Privacy Policy says the legal basis is not yet documented |
| Coverage by the Privacy Policy | The Privacy Policy (version 1.0, prepared) does **not** describe a population reference dataset, its source, or its use for inquiry or suggestions |
| Authority to hold the source | The lawful source and the authority to hold the source (`هويات(1).accdb`) are not documented in the repository |
| Loading into Production | **[PROPOSED gate]** Loading real data into Production needs a recorded decision covering lawful basis, source authority, purpose limitation, retention and the Privacy Policy update (OD-08). Building and testing the engine with **synthetic** data does not |

## 3. Access model [PROPOSED]
- **No user access in 01A:**
  - no API, page, Filament resource, export, report or search;
  - `famboook_app` has no privilege on `identity_ref` (database proposal §3).
- **Who runs imports:** an authorized server operator, from the shell, with the import role's credentials kept outside the application `.env` or in a separate secret (OD-12). There is no `identity.*` permission in 01A, so the RBAC baseline and the 162-permission matrix stay unchanged.
- **Amended 2026-10-26 (FB-CIVIL-01A-UI U1, X-1; AUTH-ADR-098):**
  - FB-CIVIL-01A-UI adds metadata-only import management under `civil-import.*` (`civil-import.view`, `civil-import.locations.view`, `civil-import.audit.view`; SUPER_ADMIN only). The catalog is now 165.
  - The API reads only the `identity_ref_monitor` views, through the dedicated `famboook_identity_monitor` role. These are counts, codes, ordinals and timestamps; file names and line numbers need `locations.view` and are audited.
  - Identity values remain unreachable from any API, page or export. `famboook_app` still has no privilege on `identity_ref`.
  - Imports are still run from the shell (execution arrives with U2).
- **Amended 2026-10-26 (FB-CIVIL-01A-UI U2, local):**
  - Authorized staff (`civil-import.stage` / `.validate`, SUPER_ADMIN only) can REQUEST validation, staging, abort, assessment, preparation and verification. The web application only records the request.
  - A dedicated worker executes it:
    - as the non-root `famboook-import` account;
    - with credentials only from a root-owned file;
    - never reading `backend/.env` or the cached configuration;
    - through two separate least-privilege logins (control plane / identity import).
  - Sources are server-side directories only (opaque id and safe label; no path, no upload).
  - Results, audit events and API responses hold counts and codes only.
  - Publication stays shell-only and gated (U3).
- **Amended 2026-10-26 (FB-CIVIL-01A-UI U3, local):** publication from the UI needs two different authorized users:
  - one requests (`civil-import.publish.request`); another approves (`civil-import.publish.approve`), which exists only while the publication gate is open, and is closed by default;
  - the request expires after 24 h, or when the baseline or the release changes;
  - the worker publishes only with its own gate open and a real-data authorization reference, after re-checking every condition, through the engine's atomic activation.
  - Requests hold codes and counts only. Production publication stays disabled.
- **Immutability (FB-CIVIL-D01):** privileges plus triggers. No edit or delete path exists for any user, SUPER_ADMIN included [APPROVED].
- **Future (01B):**
  - only with a separately approved baseline change (OD-09: `docs/03` §47e forbids global National ID search);
  - a dedicated permission and policy, with every lookup audited;
  - rate limits and anti-enumeration (exact-match only, no prefix or wildcard, no listing);
  - data minimization (only the columns approved for each purpose).

## 4. Logging and error handling [PROPOSED]
- **Never logged or stored outside `identity_ref.records` and `record_versions`:** record values, names or IDs. That covers application logs, exceptions, run summaries, issues and console output.
- **Issues:** recorded as file + line + code + column + **keyed fingerprint** of the key.
- **Error messages:** must not echo row content. PostgreSQL COPY errors can include the offending line, so the loader must catch and replace them with "line N, field count / encoding" before logging (a known pitfall; `docs/08` §15 notes that query bindings can appear in errors).
- **Console output:** counts only.

## 5. Files, storage and backups [PROPOSED / OPEN]
- **Source files:**
  - held in a non-served directory outside `storage/app/private`; the `local` disk has `serve => true` (audit §2);
  - owned by the operator, mode `600`;
  - never committed to Git; never copied to developer machines or test fixtures;
  - retention after import is OD-14.
- **Development and tests:** synthetic data only (`CLAUDE.md`), including the PostgreSQL test suite.
- **Backups:**
  - `pg_dump` will include `identity_ref` (encrypted with age today), so backup size and handling grow;
  - restore is untested (R-1);
  - whether `identity_ref` needs its own backup policy is part of OD-17.
- **Database:** encryption at rest on the server is not verified (`FB-LEGAL-01A` audit H6), so no claim of it may be made.
- **Repository hygiene:** the untracked `famboook-2026-10-23_18-18-28.dump` in the repository root is not ignored by Git. Remove it from the working tree and add `*.dump` to `.gitignore` (OD-19).

## 6. What this audit did with the real export
- Read the manifest, the header lines and the SHA-256 of each file only.
- No record was read, printed, profiled or copied.
- Profiling of record values (OD-01 to OD-05) is proposed as an **approved, aggregate-only** step: output counts and distributions, never values, run by an authorized person on an approved machine (implementation plan, Phase 0).
