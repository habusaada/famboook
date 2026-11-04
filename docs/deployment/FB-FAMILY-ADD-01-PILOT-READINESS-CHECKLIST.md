# FB-FAMILY-ADD-01 — Registry Child Admission: Pilot Readiness Checklist and Runbook

**Status:** prepared by RR-02 (2026-11-04), local only. **NOT approved, NOT applied, NOT activated.** Nothing here
authorizes a step. Each step names the decision it needs. Design: `docs/adr/FB-FAMILY-ADD-01-DIRECT-ADMISSION.md` (§16).

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
| G-06 tests and security review | PostgreSQL, concurrency and integrity suites pass locally, including DBA-1 … DBA-3 against the real schema | an independent security review of that evidence | Engineering lead + security reviewer |
| G-07 activation | readiness command; flag, permission and binding are separate switches | a recorded approval naming the commit, mode, allowlist and DBA evidence | Project owner |
| DBA-1 publication waits for the pin | `deploy/sql/identity-ref/10-registry-admission-pin.dba.sql` | apply and verify (step 3) | DBA |
| DBA-2 forward-only pointer under the pin | same file | apply and verify | DBA |
| DBA-3 `release_pin_held()` for the reader only | same file | apply and verify | DBA |
| DBA-4 one cluster, one database, including failover | `--probe-pin` proves it at the moment of the probe | an operations guarantee for routing, poolers and failover | Operations |

## 2. Before anything: what must already be true

1. FB-CIVIL-02A is deployed: `identity_ref`, `08-lookup.dba.sql`, the lookup login and `CIVIL_LOOKUP_CREDENTIALS_FILE`.
2. A published release exists.
3. `APP_DEBUG=false`. With debug on, every admission refuses to run (query capture).
4. `FAMILY_AUTH_FINGERPRINT_KEY` is set. Admission references are sealed with it.

## 3. Runbook (only after the decisions named in each step)

1. **Decisions G-01 … G-05 recorded.** Code changes follow them, each in its own reviewed commit:
   - G-02: the approved declaration version in the catalogue;
   - G-04: the gender table;
   - G-05: a `RegistryLifeVerifier` and its binding.
2. **G-06 review** of the passing evidence (PostgreSQL suites listed in ADR §16).
3. **DBA, after a backup**, as the administrator, in the database that holds both the application and `identity_ref`:
   ```bash
   psql -v ON_ERROR_STOP=1 -f deploy/sql/identity-ref/10-registry-admission-pin.dba.sql
   ```
   Then verify, with no registry record read:
   ```bash
   FAMILY_REGISTRY_ADMISSION_CIVIL_REGISTRY_BINDING=true php artisan famboook:registry-admission-readiness --probe-pin
   ```
   Expect `[PASS]` for DBA-1, DBA-2 and DBA-3 / 4. Re-apply the file after any restore.
4. **G-07 approval** naming the commit, `PILOT` and the Family allowlist.
5. **Configuration**, then clear the config cache:
   - `FAMILY_REGISTRY_ADMISSION_DECLARATION_VERSION=<approved version>`;
   - `FAMILY_REGISTRY_ADMISSION_CIVIL_REGISTRY_BINDING=true`;
   - `FAMILY_REGISTRY_ADMISSION_MODE=PILOT`;
   - `FAMILY_REGISTRY_ADMISSION_PILOT_FAMILY_IDS=<ids>`.
6. **Permission.** Grant `family-member.admit-from-registry` only to the pilot accounts, as G-07 records it.
7. **Readiness.** `php artisan famboook:registry-admission-readiness --probe-pin` must exit 0.
   - Exit 0 approves nothing. It means only that no technical control is open.
   - The output never shows IDs, allowlist entries or credentials.
8. **Smoke test** with an approved pilot head:
   - the «أفراد الأسرة» entry appears;
   - the list shows the delivered declaration;
   - one admission succeeds;
   - a replay answers 200.

## 4. Monitoring

Watch the following, without reading payloads:
- `family_registry_admission_events` (outcomes and counts);
- 503 `REGISTRY_UNAVAILABLE` rates;
- `IDENTITY_REF_PUBLICATION_PINNED` on publication;
- session-level advisory locks on `'FBCIV01P'`, which block publication.

## 5. Shutdown and rollback (no code change)

- **Stop at once:** `FAMILY_REGISTRY_ADMISSION_MODE=OFF`, then clear the config cache.
  - The entry disappears, because `/me` reports `registry_admission: false`.
  - Discovery and admission answer 404.
  - Existing members and evidence stay. An admitted member is removed only through existing Staff actions.
- **Unbind the registry:** `FAMILY_REGISTRY_ADMISSION_CIVIL_REGISTRY_BINDING=false`. Discovery and admission answer 503.
- **DBA objects:** leaving them in place is safe. DBA-1 only makes publication wait at most `famboook.publication_pin_wait`
  (default 30 s) for running admissions. Removing them is a separate DBA decision.
