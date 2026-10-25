# FB-CIVIL-01A-P1 — Open Issues and Decisions

P1 is complete on the disposable test cluster. **No item below is blocking P1, and none may be resolved by
starting P2 or by importing the real identity dataset without a new explicit approval.**

| ID | Item | Type | Needed before | Recommendation |
|---|---|---|---|---|
| P1-OI-01 | **Production role creation and schema application.** The scripts have only run on the test cluster. Applying them anywhere else needs a DBA, a recorded approval, and a backup taken first. | Decision / operations | Any non-test use | A DBA runs `00-roles.dba.sql`, then `SET ROLE famboook_identity_owner` and `01-schema.sql`, under a change ticket. The writer password is set interactively and stored outside Git. |
| P1-OI-02 | **Is the Production application role a superuser, or a member of any role that owns `identity_ref`?** A superuser bypasses every control here. Not verifiable from the repository. | Verification | P1-OI-01 | Run `SELECT rolsuper, rolcreaterole FROM pg_roles WHERE rolname = current_user` on Production, read-only, by the DBA. If it is a superuser, fix that first. |
| P1-OI-03 | **PostgreSQL server log can contain values.** The application-side redactor does not cover the server log: `log_min_error_statement` logs failing statements, and `log_statement` / `auto_explain` would add more. | Security configuration | P2 import | Set per role, by the DBA: `ALTER ROLE famboook_identity_writer SET log_min_error_statement = 'panic'` and `SET log_statement = 'none'`. P2 should use bound parameters and the redactor for every application log line. |
| P1-OI-04 | **Staging and `TEMPORARY`.** PostgreSQL grants `TEMPORARY` to `PUBLIC` by default, so the writer can create temporary tables. P2's staging design (`COPY` into a temporary table vs a dedicated staging table) is not decided. Revoking `TEMPORARY` from `PUBLIC` would affect every role in the database. | Decision | P2 design | Decide with the P2 design. If temporary staging is chosen, keep the default and document it. Otherwise, a DBA revokes `TEMPORARY` from `PUBLIC` after checking other roles' needs. |
| P1-OI-05 | **Inserts not tied to a `RUNNING` run.** The writer can insert anchors and versions referencing a finished run. They are inert (never visible unless a published release references them), but they are permanent. | Hardening | P2 | Optional trigger: `first_seen_run_id` / `created_run_id` must be `RUNNING`. Deferred to keep P1 minimal; decide with P2. |
| P1-OI-06 | **Retention and storage growth.** Each release stores its full presence set (estimated 60–80 MB per million identities per release). Under immutable history nothing can be pruned. | Decision (retention policy) | Before the 2nd or 3rd real release | Decide a retention policy, for example keeping the presence rows of all published releases while allowing a DBA-run, audited archive of `ABANDONED` releases. Any deletion path needs an explicit architectural decision. |
| P1-OI-07 | **Rolling back to an earlier release.** There is no "unpublish"; going back means publishing a new release built from an older release's versions. | Design | P2 / operations | Specify as a P2 operator procedure. It must go through `publish_release()`. |
| P1-OI-08 | **Reader access (FB-CIVIL-01B).** `famboook_identity_reader` exists and holds nothing. `active_records` has no grant. Who reads, which fields, and with what audit is undecided. | Decision | FB-CIVIL-01B | Grant the reader `SELECT` on a narrow view only, and only after the 01B approval. The application role stays without access. |
| P1-OI-09 | **PostgreSQL 16 verification.** Tests ran on 18.6. The features used all exist in 16, but this was not executed on 16. | Verification | P1-OI-01 | Run the same suite once against a disposable PostgreSQL 16 cluster. |
| P1-OI-10 | **Deployment integration.** The schema is intentionally outside Laravel migrations, so `deploy-backend.sh` does not apply it, and a new environment will not have it. | Operations | P1-OI-01 | Add a DBA runbook entry in `docs/08-PILOT-DEPLOYMENT.md` when Production use is approved. No change was made to deployment files in P1. |
| P1-OI-11 | **Hash stability.** `content_sha256` hashes the canonical `jsonb::text`. A future PostgreSQL major version that changed `jsonb` text output would make unchanged rows look changed. That costs redundant versions, never lost data. | Risk (low) | Major upgrades | Re-verify after any PostgreSQL major upgrade with a no-change re-import of a synthetic release. |

## BLOCKED

None. Every required P1 item was completed on the disposable test cluster. Production verification
(P1-OI-01/02/09) is outside P1 by instruction and is not counted as blocked.

## Documentation conflicts

None found.
- The schema lives in a separate PostgreSQL schema owned by a dedicated role.
- It does not touch `Person`, `Family`, `User` or RBAC.
- It is not exposed through any API. This is consistent with the baseline documents and the
  FB-CIVIL-01A design documents.
- The deliberate deviation from "Laravel migrations" for this one schema is justified in the implementation
  document §1 and needs reviewer acknowledgement.
