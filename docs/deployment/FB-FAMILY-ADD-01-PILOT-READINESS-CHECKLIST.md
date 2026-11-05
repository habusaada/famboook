# FB-FAMILY-ADD-01 — Registry Child Admission: Pilot Readiness Checklist and Runbook

**Status:** prepared by RR-02 (2026-11-04), completed by RR-04 (2026-11-05), local only. **NOT approved, NOT applied, NOT
activated.** Nothing here authorizes a step. Each step names the decision it needs. Design:
`docs/adr/FB-FAMILY-ADD-01-DIRECT-ADMISSION.md` (§16). Decisions, evidence and approval artifacts:
`docs/decisions/FB-FAMILY-ADD-01-ACTIVATION-DECISIONS.md`.

**Shipped state:** every guard is closed.
- `FAMILY_REGISTRY_ADMISSION_MODE=OFF`.
- No role holds `family-member.admit-from-registry`.
- No approved declaration exists.
- `FAMILY_REGISTRY_ADMISSION_CIVIL_REGISTRY_BINDING=false`.
- No life verifier is bound, and the gender mapping is not approved.

## 1. Readiness matrix

"Technical" means built and tested locally. "External" means only the named authority can close it.

| Gate | Technical state (RR-02) | Still needed | Owner |
|---|---|---|---|
| G-01 legal basis | none possible | recorded legal decision for this purpose | Project owner + legal counsel |
| G-02 privacy, adult children, declaration wording | the catalogue holds draft `2026-11-draft-1` (ADR §6 wording), `approved: false`; delivered and accepted only once approved | approved wording; then a reviewed commit adding or approving the version and setting `FAMILY_REGISTRY_ADMISSION_DECLARATION_VERSION` | Project owner + legal and privacy counsel |
| G-03 parent-link semantics | the adapter reads exact `fatherid` / `motherid` matches only | written confirmation from the registry owner, plus aggregate profiling | Civil registry data owner |
| G-04 gender codes | mapping provisional (`CivilRegistryValues::MAPPINGS_APPROVED = false`); every child is `NOT_ELIGIBLE` | an authoritative code table, then a reviewed change of the constant | Civil registry data owner |
| G-05 proof of life | `RegistryLifeVerifier` contract; fails closed (missing, stale, future, undated, unknown, error); none bound | the official source and its freshness rule, then a verifier implementation package | Registry data owner + project owner |
| D-06R revised life policy (alternative to G-05 for admission only; PROPOSED) | `REGISTRY_SCOPE_AND_DECLARATION` path, OFF by default (§16); a living-scope release plus the head's separate life declaration; recorded UNKNOWN, never ALIVE; evidence of basis, approval, extraction date and declaration time | the issuing authority's written scope statement for each release (for 2026-10-25: reported by the project owner, document to be filed); legal and privacy review; an approved declaration with `life_text`; a recorded approval reference | Project owner + legal and privacy counsel |
| G-06 tests and security review | PostgreSQL, concurrency and integrity suites pass locally, including DBA-1 … DBA-3 against the real schema | an independent security review of that evidence | Engineering lead + security reviewer |
| G-07 activation | readiness command; flag, permission and binding are separate switches | a recorded approval naming the commit, mode, allowlist and DBA evidence | Project owner |
| DBA-1 publication waits for the pin | `deploy/sql/identity-ref/10-registry-admission-pin.dba.sql`; precheck `11-…`, verification `12-…`, rollback `13-…` (RR-04) | apply and verify (step 3) | DBA |
| DBA-2 forward-only pointer under the pin | same files | apply and verify | DBA |
| DBA-3 `release_pin_held()` for the reader only | same files | apply and verify | DBA |
| DBA-4 one cluster, one database, including failover | `11-…` and `12-…` check one primary and one database now; `--probe-pin` proves the pin end to end at that moment | an operations guarantee for routing, poolers and failover | Operations |

## 2. Before anything: what must already be true

1. FB-CIVIL-02A is deployed: `identity_ref`, `08-lookup.dba.sql`, the lookup login and `CIVIL_LOOKUP_CREDENTIALS_FILE`.
2. A published release exists.
3. `APP_DEBUG=false`. With debug on, every admission refuses to run (query capture).
4. `FAMILY_AUTH_FINGERPRINT_KEY` is set. Admission references are sealed with it.

## 3. Runbook (only after the decisions named in each step)

Run from the release directory on the Production server, with the backend in `backend/`. `<db>` is the database that holds
both the application and `identity_ref` (`famboook`). `psql` runs as the database administrator. Every value in `<…>` comes
from a recorded decision.

1. **Decisions recorded** (decision register, items 1 – 5). Code changes follow them, each in its own reviewed commit:
   - G-02: the approved declaration `2026-11-v1` (register §3);
   - G-04: `MAPPINGS_APPROVED = true` (register §4);
   - G-05: a `RegistryLifeVerifier` and its binding, **or** the D-06R approval (register item 5).

   Deploy that commit as usual (`deploy/scripts/deploy-backend.sh`). It brings no migration, and every switch stays as it is.
2. **G-06 review** of the passing evidence (register item 6).
3. **DBA, after a verified backup** (`deploy/scripts/backup-db.sh`, then `pg_restore --list` of the dump):
   ```bash
   cd deploy/sql/identity-ref
   psql -X -v ON_ERROR_STOP=1 -d <db> -f 11-registry-admission-pin-precheck.sql   # read-only; keep the NOTICE lines
   psql -X -1 -v ON_ERROR_STOP=1 -d <db> -f 10-registry-admission-pin.dba.sql     # one transaction
   psql -X -v ON_ERROR_STOP=1 -d <db> -f 12-registry-admission-pin-verify.sql     # read-only; expect 4 x VERIFY PASS
   ```
   - The precheck refuses with `IDENTITY_REF_PIN_PRECHECK_<reason>`:
     - when this is not a primary;
     - when the application tables and `identity_ref` are not both in this database (DBA-4);
     - when `08-lookup.dba.sql` is missing;
     - when a publication is running;
     - when no published release is active.

     Fix the reason and rerun the precheck. Do not apply `10` meanwhile.
   - `10` is idempotent. With `-1`, a failure changes nothing.
   - Any `IDENTITY_REF_PIN_VERIFY_…` error is a NO-GO. Rerun `10` and verify again, or roll back (step 9d).
   - Rerun `10` and `12` after any restore, after `08-lookup.dba.sql`, and after `04-reassert-after-restore.dba.sql`.
4. **G-07 approval** (register item 7). It names:
   - the commit;
   - `PILOT`;
   - the pilot Family id(s) and Family code(s);
   - the step 3 output.
5. **Configuration.** Add these values to `backend/.env` (register §5; values only from the decisions):
   ```dotenv
   FAMILY_REGISTRY_ADMISSION_DECLARATION_VERSION=2026-11-v1
   FAMILY_REGISTRY_LIFE_POLICY=REGISTRY_SCOPE_AND_DECLARATION
   FAMILY_REGISTRY_LIFE_POLICY_APPROVAL=<D-06R approval reference>
   FAMILY_REGISTRY_LIVING_SCOPE_RELEASES=1:<extraction date of release 1>
   FAMILY_REGISTRY_ADMISSION_CIVIL_REGISTRY_BINDING=true
   FAMILY_REGISTRY_ADMISSION_PILOT_FAMILY_IDS=<pilot Family id(s)>
   FAMILY_REGISTRY_ADMISSION_MODE=PILOT
   ```
   Then rebuild the config cache: `php artisan config:cache`. Also restart PHP-FPM if OPcache keeps the old config.
6. **Permission**, per account only, never through a role:
   ```bash
   php artisan famboook:registry-admission-pilot-access grant --family=<FAM-code> --approval=<G-07 reference>
   php artisan famboook:verify-permissions        # must still pass: no role holds the permission
   ```
   The grant is refused in these cases, with nothing changed:
   - the Family is not allowlisted;
   - any role holds the permission;
   - the head has no single eligible Family Portal account;
   - the approval reference is missing or malformed.
7. **Readiness.**
   ```bash
   php artisan famboook:registry-admission-readiness --probe-pin
   ```
   It must exit 0, with `[PASS]` for every technical control (§6). Exit 0 approves nothing. The output never shows IDs,
   allowlist entries or credentials.
8. **Smoke test** with the approved pilot head, on the head's own device:
   - the «أفراد الأسرة» entry appears;
   - the list shows both delivered statements;
   - one admission succeeds with both boxes ticked;
   - a replay answers 200;
   - `family_registry_admissions` has one row, with `life_basis = REGISTRY_SCOPE_DECLARATION`;
   - the new Person has `life_status = UNKNOWN`.

   Read counts and those two columns only, never payloads.
9. **Rollback**, in this order, stopping at the first step that suffices:
   - a. Stop at once: `FAMILY_REGISTRY_ADMISSION_MODE=OFF`, then `php artisan config:cache`.
   - b. Remove the grants: `php artisan famboook:registry-admission-pilot-access revoke-all`.
   - c. Unbind: `FAMILY_REGISTRY_ADMISSION_CIVIL_REGISTRY_BINDING=false`, then `php artisan config:cache`.
   - d. DBA, only by a recorded DBA decision, and only after c:
     ```bash
     psql -X -1 -v ON_ERROR_STOP=1 -d <db> -f 13-registry-admission-pin-rollback.dba.sql
     ```
     It refuses while a publication runs or an admission holds the pin. Afterwards `12-…-verify.sql` reports
     `IDENTITY_REF_PIN_VERIFY_DBA1_NOT_APPLIED`.
   - e. Code: redeploy the previous commit. This feature's migrations need no rollback. Admission rows are append-only
     evidence and stay.

## 4. Monitoring

Watch the following, without reading payloads:
- `family_registry_admission_events` (outcomes and counts);
- 503 `REGISTRY_UNAVAILABLE` rates;
- `IDENTITY_REF_PUBLICATION_PINNED` on publication;
- session-level advisory locks on `'FBCIV01P'`, which block publication;
- `family_registry_admission.pilot_access.*` log lines (grants and revocations, with internal ids and the approval
  reference).

## 5. Shutdown (no code change)

- **Stop at once:** `FAMILY_REGISTRY_ADMISSION_MODE=OFF`, then clear the config cache.
  - The entry disappears, because `/me` reports `registry_admission: false`.
  - Discovery and admission answer 404.
  - Existing members and evidence stay. An admitted member is removed only through existing Staff actions.
- **Stop the revised life policy only:** `FAMILY_REGISTRY_LIFE_POLICY=VERIFIER_ONLY`. Unproven lives are refused again;
  admitted members stay, recorded UNKNOWN.
- **Unbind the registry:** `FAMILY_REGISTRY_ADMISSION_CIVIL_REGISTRY_BINDING=false`. Discovery and admission answer 503.
- **DBA objects:** leaving them in place is safe. DBA-1 only makes publication wait at most `famboook.publication_pin_wait`
  (default 30 s) for running admissions. Removing them is step 9d.

## 6. Expected readiness output at PILOT

```text
FB-FAMILY-ADD-01 registry child admission — readiness (read-only)
  mode: PILOT
  pilot families allowed: <n>
  roles holding the admission permission: 0; accounts holding it directly: <n>
Technical controls:
  [PASS] O-K / G-02  an APPROVED declaration is active
  [PASS] G-04        the gender code mapping is approved
  life policy: REGISTRY_SCOPE_AND_DECLARATION; approval reference recorded: YES; living-scope releases listed: 1
  [PASS] G-05 / D-06R a life basis is available
  [PASS] SOURCE      the civil registry is bound as the source
  [PASS] PIN         a release pin verifier is bound
  [PASS] LOOKUP      the lookup login is configured
  [PASS] KEY         the reference fingerprint key is configured
  [PASS] DEBUG       APP_DEBUG is off (no query capture)
  [PASS] ALLOWLIST   the pilot Family allowlist is not empty
  [PASS] SCOPE       no role holds the admission permission
  pilot accounts holding the permission: <n>; of them outside the pilot: 0
  [PASS] GRANTS      every holder is an allowlisted household head
  [PASS] DBA-1       publish_release() takes the pin
  [PASS] DBA-2       the active release pointer is guarded
  [PASS] DBA-3 / 4   the lookup login sees this session's pin now
  [PASS] D-06R       the active release is a listed living-scope release
External approvals (not checkable here; each needs its recorded decision):
  [EXTERNAL] … (nine lines: G-01 … G-07, D-06R, DBA-4)
Technical controls: all PASS (this approves nothing).
```
