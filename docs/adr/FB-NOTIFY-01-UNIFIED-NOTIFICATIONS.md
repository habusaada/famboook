# ADR FB-NOTIFY-01 — Unified In-App Notifications and Follow-up

| | |
|---|---|
| Status | Approved by the owner 2026-10-18 (architecture and business rules). **Documentation only (FB-NOTIFY-01A); nothing implemented** |
| Supersedes / amends | FP-ADR-018 and docs/11 §21 ("two kinds"); docs/02 §59 and docs/04 §56 (Laravel database notifications as the default store); the "Notifications and announcements" rules of docs/03 and the Announcements flow of docs/05; docs/08 §16 (no worker, no cron) for this feature; docs/06 proposed `announcement.*` permissions (still proposed) |
| Keeps | docs/03 §90–§92, docs/05 §85–§87, docs/06 §126–§127 (backend-originated, after commit, minimal content, own-identity reads); docs/11 §22 (coordinator sending rules, preserved unchanged); "audiences resolved on the server"; the family-visible sources «إدارة Famboook» / «منسق العائلة» |
| Schema / data change | None in this phase. Logical entities only; the physical schema is reviewed in FB-NOTIFY-01B |
| RBAC change | None in this phase. Permission names below are PROPOSED and not seeded |
| Production | No Production change is authorized by this ADR |

Registered: docs/06 AUTH-ADR-097 (authorization), docs/11 FP-ADR-018 (amended).

## 1. Context and problem

The baseline already describes notifications:
- **Kinds:** two kinds, system notifications and manual announcements (docs/11 §21, FP-ADR-018).
- **Storage:** a default of Laravel's standard database notifications (docs/02 §59, docs/04 §56).
- **Origin:** backend-originated, after commit, with minimal content (docs/03 §90–§92, docs/05 §85–§87, docs/06 §126–§127).
- **Permissions:** proposed `announcement.*` names (docs/06, PFP-022), not approved.

None of it is implemented. The read-only repository audit (2026-10-18, HEAD `bb6cb0d`) verified:
- **Nothing exists yet:** no notification table, model, event, listener or job, and no bell or inbox in either portal.
- **`users` is `Notifiable`:** `App\Models\User` uses `Illuminate\Notifications\Notifiable`, so a custom table named `notifications` would collide with its relation.
- **Change-request transitions are logged append-only:** every transition is recorded by `WorkflowEventRecorder::record` in `workflow_events`, inside the action's transaction.
  - Types (`WorkflowEventType`): SUBMITTED, REVIEW_STARTED, RETURNED, RESUBMITTED, APPROVED, REJECTED, APPLIED, APPLY_FAILED, CANCELLED.
  - Actor sides: FAMILY, STAFF, SYSTEM, COORDINATOR.
  - On PostgreSQL a trigger blocks UPDATE and DELETE.
- **Coordinator scope:** resolved by `App\Support\FamilyAuth\CoordinatorScopes` (`families()`, `canAccessFamily()`, `coversPlacement()`, `requests()`), always against the **current** assignments. Scope covers ACTIVE families only.
- **Family reads:** behind `auth:sanctum` + `family.side` + `can:family-portal.access` + `family.context` (`FamilyAccessResolver`).
- **Background processing:** the Production environment template sets `QUEUE_CONNECTION=sync`. docs/08 §16 records no queue worker and no scheduler cron for the Pilot. `routes/console.php` already defines two scheduled commands that do not run in Production without cron.

The approved product needs things Laravel's notification table cannot express on its own: two levels, distinct viewing and acknowledgement, family recipient snapshots, follow-ups with deadlines, expiration with late acknowledgement, and coordinator-scoped bulk sending.

## 2. Decisions

### ADR-01 — One unified notification system
- **One system, one inbox:** Famboook has one in-app notification system and one family inbox, not separate notification and formal-notice modules.
- **Classification:** each message has a **kind**:
  - `SYSTEM`: generated from an application event;
  - `ANNOUNCEMENT`: written by authorized staff or a coordinator.
- **Visible source:** the family-visible **source** stays distinct: «إدارة Famboook» (administration and system) or «منسق العائلة» (coordinator). A coordinator's message never appears as an administrative decision (docs/11 §22, unchanged).
- **Not separate inboxes:** kind and source are classification and presentation attributes only.

### ADR-02 — Custom notification persistence
- **Dedicated model:** a dedicated data model is used, not Laravel's database notifications alone. No table is named `notifications` (it would collide with `Notifiable`).
- **Proposed logical entities** (physical schema reviewed in FB-NOTIFY-01B):
  - `notification_messages`: kind, source, level, title, body, an optional link to an authorized view, sender user, sender scope snapshot, publication **status** (lifecycle in ADR-08), `published_at` (set only on successful publication), optional expiry, archive time, publication idempotency key, and a unique origin for SYSTEM messages.
  - `notification_recipients`: one row per family per message (unique), with the recipient snapshot, the household head at publication, availability, first viewing, acknowledgement, and the late-acknowledgement flag.
  - `notification_recipient_events`: append-only history of availability, viewing, acknowledgement, late acknowledgement, expiration and staff follow-up actions.
  - `notification_followups`: one row per recipient and stage (unique), with due time, status, closing time, outcome (satisfied, expired, closed manually) and the responsible party.
- **Must support:** publication and lifecycle, recipient snapshots, viewing, explicit and late acknowledgement, follow-ups, reminder stages, immutable audit history, automatic expiration and archiving, and idempotent publication.

### ADR-03 — The family is the recipient
- **Snapshot at publication** (written by the publication workflow of ADR-08 and committed before the message becomes `PUBLISHED`):
  - resolve authorized target families on the backend;
  - deduplicate overlapping criteria (one recipient row per family);
  - enforce the coordinator's scope;
  - target ACTIVE families by default;
  - store a stable snapshot.
- **History kept:** recipient rows are never rewritten when assignments or family status change later.
- **No account yet:** a message stays with the family even when no household-head account exists. An eligible household head later reaches the family's retained messages through the existing family boundary (`family.context`).
- **Succession out of scope:** this decision introduces no household-head succession workflow (FU-01).
- **Reporting states:**
  1. **No activated family account.**
  2. **Available but unviewed.**
  3. **Viewed.**
  4. **Acknowledged**, where required.
- **Availability is not proof:** being available is not proof that a message was delivered or viewed.

### ADR-04 — Who may send
- **RBAC unchanged:** existing roles and unrelated permission mappings stay as they are, and no new role is introduced (COORDINATOR already exists).
- **Coordinators:** may send individual and bulk messages directly, without preapproval, strictly within their **current** assignment scope (`CoordinatorScopes`).
- **Administrators:** global sending requires an explicit permission.
- **Proposed permissions** (not seeded; reviewed in the implementation phase): `announcement.view`, `announcement.create`, `announcement.send`, `announcement.send-all`, `notification-followup.view`, `notification-followup.manage`.

### ADR-05 — Levels, viewing and acknowledgement
| Level | Viewing | Acknowledgement | Follow-up |
|---|---|---|---|
| NORMAL | first opening of the details records `viewed_at`; viewing satisfies the requirement | none | opens 72 h after `published_at` if unviewed |
| IMPORTANT | first opening of the details records `viewed_at`; viewing alone does not satisfy it | explicit, required | opens 24 h after `published_at` if unacknowledged; second responsible-staff reminder 72 h after `published_at` if still unacknowledged |

- **Opening is not acknowledging:** opening the details is never an acknowledgement.
- **No acting for the family:** staff and coordinators can never mark a message viewed or acknowledged for the household head.
- **Separate operations:** listing or reading a message never records a view. Viewing is an explicit, non-cacheable operation the details screen performs, so prefetching or caching cannot record a view.

### ADR-06 — Follow-up lifecycle
- **In-app only:** follow-up happens inside Famboook only, with no SMS, push, email or other external messaging in this feature.
- **Opening:** the system finds families that haven't met the level's condition and opens follow-up records after the deadlines in ADR-05.
- **Alerting:** it alerts the **currently** responsible authorized staff member or coordinator in-app.
- **No duplicates:** at most one follow-up per recipient and stage, and no duplicate reminders.
- **Manual actions:** authorized staff may record manual follow-up actions. A staff contact attempt stays distinct from a family acknowledgement, and never impersonates a viewing or acknowledgement event.
- **Closing:** a follow-up closes automatically once its condition is met. History is kept.

### ADR-07 — Expiration, archiving and late acknowledgement
- **Optional expiry:** `expires_at` is optional and applies to `PUBLISHED` messages only (expiration and automatic archiving never act on a message that was not successfully published). At expiry the system:
  - archives the message automatically;
  - stops creating reminders;
  - closes incomplete follow-ups with an explicit **expired** outcome;
  - keeps recipient, viewing, acknowledgement and follow-up history.
- **After expiry:** archived messages stay viewable, and acknowledgement remains possible but is marked **late**. A follow-up closed as expired is never reopened by a late acknowledgement.
- **Archived is not acknowledged:** an archived message does not count as successfully acknowledged.
- **Retention:** the retention period needs separate confirmation against the existing data-retention policy. docs/08 records 24 months for authentication and security events; no permanent retention is decided here.

### ADR-08 — Queue, worker and scheduler
- **Target architecture:**
  - Laravel Queue with a managed worker for **all** manual publication — single-family and bulk alike use the same queued lifecycle; there is no synchronous HTTP publication fallback;
  - Laravel Scheduler for follow-up deadlines, reminders, expiration and archiving;
  - database-backed idempotency and deduplication;
  - transaction-safe dispatch (after commit) with safe retries.
- **Kept and extended requirement:** manual publication (single-family and bulk) is not enabled before the worker is operational (docs/11 §21).

**Publication lifecycle (F1).** States of a message:

| State | Meaning |
|---|---|
| `DRAFT` | prepared by the sender; not queued; invisible to families |
| `QUEUED` | publication requested; a job is queued after commit |
| `PUBLISHING` | the worker is processing it |
| `PUBLISHED` | the full recipient snapshot is committed; `published_at` set |
| `PUBLISH_FAILED` | publication did not complete; no recipient visible; retryable |
| `ARCHIVED` | a published message archived (e.g. at expiry) |
| `CANCELLED` | withdrawn before publication (from `DRAFT` or `QUEUED`); never published |

Rules:
1. A sender may prepare a `DRAFT` and request publication (`QUEUED`); the request is idempotent (client reference).
2. The worker moves the message to `PUBLISHING` and, through an idempotent workflow, re-checks on the backend the sender's authorization and CURRENT scope and resolves the target families.
3. **Initial implementation: one database transaction** writes the whole recipient snapshot (deduplicated: one row per family) and sets `PUBLISHED` with `published_at`. Either all of it commits, or none of it does.
4. `published_at` is set only when publication completes successfully. All follow-up deadlines and expiry are measured from it.
5. A family sees a message only once it is `PUBLISHED` (or later `ARCHIVED`). Sender-facing published lists and published dashboard counts exclude `QUEUED`, `PUBLISHING` and `PUBLISH_FAILED`; a sender may see their own pending and failed attempts in a separate operational view.
6. A failure rolls the transaction back and records `PUBLISH_FAILED`: no partially visible message, no recipient rows. A retry is idempotent and never duplicates recipients (unique message + family).
7. `CANCELLED`, `ARCHIVED` and `PUBLISH_FAILED` are distinct: never published, published then archived, and not (yet) published.
8. If chunked publication is ever needed for very large audiences, the `PUBLISHED` transition (and `published_at`) happens only after every chunk is verified complete; until then nothing is visible. Not part of the initial implementation.

SYSTEM messages (ADR-09) are created by the same workflow with their single recipient in one transaction; they never pass through `DRAFT`.
- **Order of work:** local development and testing come first. Production configuration must be verified independently, and activating the worker and the scheduler needs a separate deployment plan and explicit approval. No Production change is authorized here.
- **Computed counts:** dashboard follow-up counts may be computed from the authoritative timestamps so they stay correct if a scheduled task is late. This never replaces the persistent follow-up and audit records.

### ADR-09 — Automatic system notifications
- **Source:** system notifications are derived from the existing append-only `workflow_events`.
- **Idempotency:** ONE system message per unique origin event; the origin (the `workflow_events` row) is unique on the message, so a replay or retry never creates a second message. Its recipient is the family associated with the originating request. It is not "one message per origin event per family" in general.
- **Access:** system messages use the same family inbox and the same family-side access controls (`family.context`) as announcements.
- **Candidate types:** the supported event types are the nine listed in §1. The initial family-facing mapping is decided in FB-NOTIFY-01B; candidates are RETURNED (clarification needed), APPROVED (approved, not yet applied — APPROVED ≠ APPLIED), APPLIED, REJECTED and SUBMITTED (receipt).
- **Not covered:** docs/02 §60 also lists ACCOUNT_ACTIVATED / ACCOUNT_SUSPENDED. These are authentication events, not `workflow_events`, and are deferred.
- **No workflow change:** change-request transitions, reviewer permissions and workflow rules don't change.
- **Shared inbox:** system messages and announcements share the same family inbox.

### ADR-10 — Security and auditability
- **Authentication and isolation:** Sanctum authentication. A family reads only its own family's messages through `family.context`, with no route-model binding: out of context means not found. There is no cross-family access.
- **Coordinator authorization:**
  - scope is enforced on the backend at sending time;
  - follow-up actions require the **current** scope;
  - sender identity and sender scope are snapshotted, as are recipients.
- **Audit:** append-only audit events, server-generated timestamps only, and safe metadata (codes and counts; never message bodies, identifiers or contact data in metadata).
- **Integrity:**
  - publication is idempotent (a client reference, as for change requests);
  - acknowledgement and expiration are race-safe (row locks);
  - per-user rate limits apply to sending and acknowledgement.

## 3. Rationale and alternatives
| Alternative | Why not |
|---|---|
| Laravel database notifications only (docs/02 §59 default) | One row per notifiable user: no family recipient, no snapshot, no levels, acknowledgement, follow-ups or expiry, and recipients without an account would be lost |
| Separate modules for notifications and formal notices | Two inboxes for the family, duplicated logic and audit; the owner chose one system |
| Synchronous bulk fan-out in the HTTP request | Contradicts docs/11 §21 and risks timeouts and partial sends |
| Hooking notifications into each change-request action | Changes workflow code; deriving from `workflow_events` keeps the workflow untouched and idempotent |
| Recipient = user account | Families without an activated account, and head changes, would lose messages |

## 4. Consequences and trade-offs
- **New tables and a new operational dependency:** a worker and the scheduler. Manual publication (single-family and bulk) stays disabled until the worker is verified in Production, and follow-up automation until the scheduler is.
- **Past scope still visible:** a coordinator's past messages stay visible to them as history after a scope change, but follow-up actions need the current scope.
- **Accountless families:** families without an activated account are counted and reported separately, never as "unviewed by choice".

## 5. Compatibility
- **No changes to:** RBAC roles, existing permission mappings, change-request workflows, the `workflow_events` contract, family authorization, or `CoordinatorScopes`.
- **Kept:** the family-visible distinction of sources.

## 6. Deferred and out of scope
- **Channels:** SMS, Web Push, email and any external delivery.
- **Other system events:** account and authentication messages (ACCOUNT_ACTIVATED / ACCOUNT_SUSPENDED) and events other than change requests.
- **Related workflows:** household-head succession (FU-01).
- **Retention:** the retention period (ADR-07).
- **Physical design:** schema, indexes and constraint names (FB-NOTIFY-01B).

## 7. Implementation prerequisites
1. Owner review of this ADR and of the physical schema (FB-NOTIFY-01B).
2. Approval and seeding of the proposed permissions (docs/06 AUTH-ADR-097).
3. A queue worker and a scheduler cron verified locally, then a separate, approved Production deployment plan (docs/08 §16).
4. The confirmed family-facing `workflow_events` mapping (ADR-09).
5. A retention confirmation (ADR-07).
