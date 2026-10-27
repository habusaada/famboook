# FB-CIVIL-01A-UI — Civil Identity Import Management UI: Decision Register

| | |
|---|---|
| Status | **D-UI-1 … D-UI-13 APPROVED by the owner (2026-10-26); D-UI-6 and D-UI-12 SUPERSEDED by D-UI-12a (owner policy, 2026-10-27).** The security requirements S-1 … S-7 (§2) are approved. Implementation details in the companion documents remain the **proposed contract for U1–U3**. No decision in this register authorizes Production use, a Production privilege change, a migration on Production, or the import or publication of real civil identity data |
| Source | Read-only inspection (2026-10-26, HEAD `13897e3`), the U0 brief and the U0 finalization brief |
| Architecture | `docs/adr/FB-CIVIL-01A-UI-IMPORT-MANAGEMENT.md` |
| API and screens | `docs/adr/FB-CIVIL-01A-UI-API-AND-SCREENS.md` |
| Engine | FB-CIVIL-01A P1–P4 (`docs/audits/FB-CIVIL-01A-*`, `deploy/sql/identity-ref/*`) |
| Rule | The real-data gates of FB-CIVIL-01A are **unchanged**, in particular D-15 (lawful basis), D-11 (snapshot), D-12 (backups) and the restore rehearsal. Opening publication in the UI is **not** an authorization to load or publish real data (S-3) |

---

## 1. Decisions

| # | Decision | Status | Recorded outcome |
|---|---|---|---|
| **D-UI-1** | Where the screen lives | **APPROVED** | The existing **Next.js Staff application**, under `/administration/civil-registry/…`, beside the Initial Family Import. **Not Filament.** It reuses the existing components, permission gating, Arabic RTL conventions and palette (docs/10, FB-UI-01). |
| **D-UI-2** | Project identifier | **APPROVED** | **FB-CIVIL-01A-UI.** **FB-CIVIL-01B stays reserved for civil registry search and inquiry**, which still needs its own baseline change (OD-09). |
| **D-UI-3** | Source of import files | **APPROVED** | **Server-side source directories only:** placed by an authorized operator under a configured, non-served root. **No browser upload** in this project. |
| **D-UI-4** | How the UI reads import state | **APPROVED** | **Metadata only**, through a separately controlled database access path: the `identity_ref_monitor` views, read by a dedicated monitor login (ADR §5). The application's general role keeps no access to any identity schema. |
| **D-UI-5** | Who executes imports | **APPROVED** | A **dedicated import execution worker** (ADR §4). **HTTP requests never execute an identity import** (S-1). The web application only records requests (S-2). |
| **D-UI-6** | Publication approval | **SUPERSEDED by D-UI-12a (2026-10-27)** | ~~Two distinct users. Restated and detailed in D-UI-12.~~ |
| **D-UI-7** | Permissions | **APPROVED** | Granular `civil-import.*` permissions (ADR §7), assignable later to authorized roles through `RolePermissionSeeder` only. Publication approval sits behind a gate that is **closed by default**. |
| **D-UI-8** | File names and line numbers | **APPROVED** | Source **file names and file identifiers, and issue line numbers**, are shown only with the separate permission `civil-import.locations.view`. Without it, files appear by ordinal ("file 3 of 24") and issues as counts per code. **No personal identity value is ever exposed**, with or without this permission. |
| **D-UI-9** | Monitoring connection and its credentials | **APPROVED** | A dedicated Laravel connection `identity_ref_monitor` with **isolated credentials**. **On Production the credentials live in a protected configuration file outside the project** (proposed `/etc/famboook/identity-monitor.php`, `root:www-data 0640`). They are read at run time, never placed in `.env` or the cached configuration (ADR §5.3). **`famboook_user` (the application's general role) is never granted identity access**, not even membership of the monitor role. |
| **D-UI-10** | Worker OS account and credentials | **APPROVED** | A dedicated, **non-root** OS account (proposed `famboook-import`, no login shell). The import login's credentials are kept **outside the repository**, in a **root-owned protected file** (proposed `/etc/famboook/identity-import-worker.env`, `root:root 0600`), loaded by systemd `EnvironmentFile=` (ADR §4). |
| **D-UI-11** | Publication request lifetime | **APPROVED** | **24 hours**, then `EXPIRED`. A request is also invalidated as soon as the release's base is no longer the active release. |
| **D-UI-12** | Two-person publication | **SUPERSEDED by D-UI-12a (2026-10-27)** | ~~Publication needs two distinct authorized users: one holding `civil-import.publish.request`, the other holding `civil-import.publish.approve`. The requester can never approve their own request.~~ Rows and audit events recorded under this rule stay as history. |
| **D-UI-12a** | SUPER_ADMIN single-operator publication | **APPROVED (owner policy, 2026-10-27)** | **A SUPER_ADMIN may request, confirm and publish the same release without a second approver.** The requester confirms their **own** request — nobody else can — as an **active SUPER_ADMIN** holding `civil-import.publish.confirm` (seeded to SUPER_ADMIN **only while the publication gate is open**, default closed), after **re-entering their password** and typing back the release id and its verified record count (expected presence). The release must still be VERIFIED and every existing precondition is re-checked (window D-UI-11, baseline, no other active request); the dedicated worker still publishes (S-3). Wrong passwords are audited and rate-limited (5 per 15 minutes). `civil-import.publish.approve` and the reject action are removed; pending two-person requests expire (`POLICY_CHANGED`) and are never confirmed or published automatically; an approval recorded under D-UI-12 without that confirmation is refused by the worker. Enforced by the action, the model, the database guards, the worker and the audit trail. |
| **D-UI-13** | Status refresh | **APPROVED** | Poll **every 5 seconds only while a relevant operation is active** (a request QUEUED or RUNNING) **and the page is visible**. No polling otherwise; no WebSocket or broadcast infrastructure. |

## 2. Approved security requirements

| # | Requirement | Where it is enforced |
|---|---|---|
| **S-1** | HTTP requests never execute identity imports directly | The API only inserts `civil_import_requests` rows. No controller or action called from HTTP opens the import connection (architecture test, ADR §9). |
| **S-2** | The web application records requests; a separate worker performs them | ADR §4 (worker), §6 (control-plane tables) |
| **S-3** | Publication requires **all** of: the permission; the enabled gate; an eligible VERIFIED release; independent approval; **every real-data authorization requirement** | ADR §3.3 and §4.3. The worker refuses to publish unless a recorded real-data authorization reference (`IDENTITY_REF_PRODUCTION_APPROVAL`, the FB-CIVIL-01A P4 mechanism) is present in its protected environment. That reference is issued only once G1 (lawful basis) and G3 are approved. |
| **S-4** | Production publication is disabled by default | The gate `civil_import.publish_enabled = false`; no role holds `publish.approve`; no authorization reference in any default configuration; the FB-CIVIL-01A database gate (VERIFIED only) |
| **S-5** | PostgreSQL privilege changes are prepared and tested locally first, then approved separately for Production | `06-monitor.dba.sql` and its PostgreSQL tests run on the disposable cluster in U1; applying them to Production is a separate, recorded approval (ADR §10) |
| **S-6** | All FB-CIVIL-01A isolation controls are preserved | No existing identity object, privilege or trigger changes. The 76 FB-CIVIL-01A PostgreSQL tests stay green (ADR §9). |
| **S-7** | The existing palette and Arabic RTL conventions are preserved | API-and-screens §5: existing tokens, `StatusBadge` tones and components only |

## 3. Conflicts with approved documents and resolution

Nothing below has been changed in the baseline documents. Each amendment is applied in the stage that needs it, after approval.

| # | Approved document says | New decision | Resolution | When |
|---|---|---|---|---|
| X-1 | `docs/audits/FB-CIVIL-01A-SECURITY-AND-PRIVACY.md` §3: "No user access in 01A … no `identity.*` permission, the RBAC baseline … unchanged" | D-UI-1/4/7 | Amend §3: FB-CIVIL-01A-UI adds metadata-only import management under `civil-import.*`; identity values remain unreachable | U1 |
| X-2 | `docs/audits/FB-CIVIL-01A-TECHNICAL-DESIGN.md` §2: "No queue … a later web trigger can enqueue … out of scope" | D-UI-5 | Consistent with "later". Record the dedicated worker, which is not the notification queue worker. | U2 |
| X-3 | `docs/06-PERMISSIONS.md`: closed permission catalog | D-UI-7/8 | A new AUTH-ADR (next free number), a catalog update, `famboook:verify-permissions` extended. The current catalog count is verified at U1 start; the "162" figure in docs/06 predates FB-NOTIFY. | U1, U2, U3 |
| X-4 | `docs/07-ROADMAP.md`: FB-CIVIL not listed (OD-11) | — | Add the FB-CIVIL program entry | U1 |
| X-5 | `docs/08-PILOT-DEPLOYMENT.md` §16: notification worker only | D-UI-5/10 | Add the identity import worker (account, unit, credential file, health, stop); not enabled by any script | U2 |
| X-6 | FB-CIVIL-01A P1: only the writer reads `identity_ref`; the reader role is reserved for 01B | D-UI-4 | **Do not reuse** `famboook_identity_reader`. A separate `famboook_identity_monitor` on views only. | U1 (local); Production separately (S-5) |
| X-7 | The Production gate (`IDENTITY_REF_PRODUCTION_APPROVAL`) exists only in the artisan command | D-UI-5, S-3 | Extract it into one shared class used by the command and the worker | U2 |
| X-8 | P4 runbook names `famboook_pilot` / `famboook_app`; Production reported as `famboook` / `famboook_user` | D-UI-9 | New SQL names only identity roles and never hard-codes the application role. Reconcile the runbook in the U1 documentation commit. | U1 |

## 4. Open items (no decision needed to start U1)

| # | Item | Proposed handling | Decide by |
|---|---|---|---|
| O-1 | The worker writes control-plane rows (requests, audit) through the application connection, so it needs the application database credentials as well as the import login | **Least-privilege option, recommended:** a dedicated control-plane login with INSERT/UPDATE on `civil_import_requests` / `_worker_heartbeats` and INSERT on `civil_import_audit_events` only, in the same root-owned file. The alternative is group access to the backend `.env`. | **Decided at U2 start (2026-10-26): the least-privilege option** — `famboook_civil_control` / `_login` (07-control-plane.dba.sql): SELECT/INSERT/UPDATE on the catalogue and heartbeats, SELECT and column-limited UPDATE on requests (no INSERT/DELETE), INSERT on the audit; in the worker's root-owned file. The worker never reads `backend/.env`. |
| O-2 | D-UI-8 and source directory labels: a `civil-import.stage` holder must recognize a source to stage it | Show the directory label to `civil-import.stage` holders. File names inside a source, and line numbers, still require `civil-import.locations.view`. | **Confirmed by the owner (U1 brief): safe source labels for stage holders**, never full paths or sensitive file names. Implemented in U2. |
| O-3 | The two-person rule with SUPER_ADMIN-only defaults needs at least two SUPER_ADMIN accounts on Production (unknown) | Verify before U3; otherwise grant `publish.request` to ADMINISTRATOR through the seeder | **Closed by D-UI-12a (2026-10-27):** one active SUPER_ADMIN suffices; no second approver account is needed or to be created. |
| O-4 | The monitor credentials file is readable by the PHP-FPM account, hence by any application code | Accepted: it grants metadata views only (no identity value); one repository class uses it | Accepted risk (record at U1) |

## 5. Unchanged by this register

- The FB-CIVIL-01A schema objects, privileges, triggers and policy `IDREF-POLICY-1`; the P4 runbook gates G1–G9.
- No change to persons, families, memberships or RBAC outside the new `civil-import.*` permissions.
- No identity value (national ID, name, date of birth, phone, address, religion, raw row) in any API response, page, log line or audit event of this project.
