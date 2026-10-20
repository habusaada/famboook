# ADR FB-NOTIFY-01 — Unified In-App Notifications and Follow-up

| | |
|---|---|
| Status | Approved by the owner 2026-10-18 (architecture and business rules). Documentation only in FB-NOTIFY-01A; aligned 2026-10-18 with the FB-NOTIFY-01B.1 schema and authorization design (§2a). **Updated 2026-10-19 (FB-NOTIFY-01B.2b.7): the schema, manual announcements (Staff and Coordinator), the publication worker, recovery, expiry and the Family inbox are implemented in local commits `29a0b12` … `022acc8` (§2b); NOT deployed, Production not migrated or seeded, mode OFF. SYSTEM notifications and follow-ups are not implemented**. *FB-NOTIFY-01E.3-F1 (local, uncommitted): ADR-06 clarified — one dynamic primary owner per family, CENTRAL fallback (§2c); follow-up permissions, reason catalogues, ownership service and cutoff configuration built; still no follow-up opening, API or screen* |
| Supersedes / amends | FP-ADR-018 and docs/11 §21 ("two kinds"); docs/02 §59 and docs/04 §56 (Laravel database notifications as the default store); the "Notifications and announcements" rules of docs/03 and the Announcements flow of docs/05; docs/08 §16 (no worker, no cron) for this feature; docs/06 proposed `announcement.*` permissions (still proposed) |
| Keeps | docs/03 §90–§92, docs/05 §85–§87, docs/06 §126–§127 (backend-originated, after commit, minimal content, own-identity reads); docs/11 §22 (coordinator sending rules, preserved unchanged); "audiences resolved on the server"; the family-visible sources «إدارة Famboook» / «منسق العائلة» |
| Schema / data change | None in FB-NOTIFY-01A. *Since FB-NOTIFY-01B.2a (DB-ADR-067):* four additive tables, migrations `2026_10_24_090000`–`090003` (docs/04 §56) |
| RBAC change | None in FB-NOTIFY-01A. *Since FB-NOTIFY-01B.2b.6d (AUTH-ADR-097 amended):* `announcement.view` / `.create` / `.send` / `.send-all` and `coordinator-announcement.view` / `.create` / `.send` are seeded (SUPER_ADMIN, ADMINISTRATOR, COORDINATOR); `notification-followup.*` and `coordinator-notification-followup.*` stay PROPOSED and not seeded; no role added. *Since FB-NOTIFY-01E.3-F1 (local):* `notification-followup.view` / `.manage` seeded to SUPER_ADMIN and ADMINISTRATOR, `coordinator-notification-followup.view` / `.manage` to COORDINATOR only (§2c); still no role added |
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
- **Proposed permissions** (not seeded; reviewed in the implementation phase). Coordinator permissions follow the existing `coordinator-*` naming convention, which keeps the family-side coordinator permission set separate from Staff permissions (as `coordinator-request.*` is from `change-request.*`):

| Holder | Proposed permissions |
|---|---|
| Staff (administration) | `announcement.view`, `announcement.create`, `announcement.send`, `announcement.send-all`, `notification-followup.view`, `notification-followup.manage` |
| COORDINATOR (existing role) | `coordinator-announcement.view`, `coordinator-announcement.create`, `coordinator-announcement.send`, `coordinator-notification-followup.view`, `coordinator-notification-followup.manage` |
| FAMILY_USER | none new: the inbox, viewing and acknowledgement use the existing `family-portal.access` + `family.context` |

- **Revalidation before publication:** the coordinator's permission and CURRENT assignment scope (and, for Staff, the sending permission) are revalidated by the publication worker immediately before the recipient snapshot is written, in the publication transaction (ADR-08 rule 2). A sender who is no longer authorized gets `PUBLISH_FAILED`, never a publication.
- **Follow-ups:** every follow-up operation (list, view, record a contact, close) re-checks the permission and, for a coordinator, that the family is in the CURRENT scope (`CoordinatorScopes`). Revoking an assignment removes access on the next request.
- **Own family:** a coordinator cannot manually close a follow-up for their own family, even when it is in their scope.
- *Clarified 2026-10-19 (FB-NOTIFY-01E.3-F1, §2c)* — "Follow-ups" and "Own family" above now read:
   - **Current scope permits VIEWING:** a coordinator with `coordinator-notification-followup.view` sees the follow-ups of every family in their CURRENT scope, their own household included.
   - **Only the primary coordinator MANAGES:** recording a contact or closing manually needs `coordinator-notification-followup.manage` AND being the family's dynamically resolved primary coordinator.
   - **Never their own household:** a coordinator is never the primary coordinator of, and never manages, their own household's follow-ups. Ownership falls to the next eligible coordinator, or CENTRAL.
   - **Central Staff manage all:** SUPER_ADMIN and ADMINISTRATOR (`notification-followup.view` / `.manage`) oversee and manage every follow-up, CENTRAL ones included.
   - With no eligible coordinator the family's follow-ups are CENTRAL. The management restrictions are enforced by the follow-up Domain Actions (via `FollowupOwnership`), never only by a UI.
- **No acting for the family:** coordinators, like Staff, cannot view-record or acknowledge a message for any family (ADR-05).
- **SOCIAL_WORKER:** no follow-up access until a separately approved Staff assignment model exists (none exists in the repository).
- **Revocation is not serialized:** the coordinator's assignment rows can be locked against a concurrent scope revocation during publication. Spatie role and permission revocation is NOT claimed to be transactionally serialized with publication: the permission tables are not locked, so publication is ordered at the moment of the check. A stronger guarantee needs the revocation writes to use compatible locking, which is not part of this design.

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
- **Manual actions:** authorized staff (and coordinators within their current scope, ADR-04) may record manual follow-up actions. A staff contact attempt stays distinct from a family acknowledgement, and never impersonates a viewing or acknowledgement event.
- **Closing outcomes stay distinct:** satisfied by the family, expired, or closed manually by an authorized user with a reason code. A manual closure never sets viewing or acknowledgement and never changes delivery or acknowledgement metrics, which come only from the recipient timestamps.
- **Reason codes before notes:** manual actions and closures use structured reason codes. A free-text note is optional, short and visible only to authorized follow-up users (never to families). Sensitive personal information (health, identity documents, national IDs, contact data) must not be entered in follow-up notes, and the interface says so.
- **Note correction:** an incorrect or sensitive note is never edited or deleted. An authorized administrator records a `NOTE_CORRECTION` event that references it; application views then suppress the corrected note. This is audit-preserving suppression in application views, not physical database erasure.
- **Closing:** a follow-up closes automatically once its condition is met. History is kept.
- **Clarified 2026-10-19 (FB-NOTIFY-01E.3-F1):** "the currently responsible" coordinator is the family's ONE primary coordinator, derived dynamically; no eligible coordinator means CENTRAL ownership. "Manual actions … coordinators within their current scope" is narrowed: the current scope permits viewing; only the primary coordinator (never for their own household) or central Staff manage. Contact outcomes and manual closure reasons are fixed catalogues, and follow-ups never cover recipients published before a configured cutoff. Details and limitations: §2c.

### ADR-07 — Expiration, archiving and late acknowledgement
- **Optional expiry:** `expires_at` is optional and applies to `PUBLISHED` messages only (expiration and automatic archiving never act on a message that was not successfully published). At expiry the system:
  - archives the message automatically;
  - stops creating reminders;
  - closes incomplete follow-ups with an explicit **expired** outcome;
  - keeps recipient, viewing, acknowledgement and follow-up history.
- **After expiry:** archived messages stay viewable, and acknowledgement remains possible but is marked **late**. A follow-up closed as expired is never reopened by a late acknowledgement.
- **Archived is not acknowledged:** an archived message does not count as successfully acknowledged.
- **Expiry is effective at `expires_at`:** `expires_at` is an absolute time chosen by the sender and is never shifted. From that moment, read models treat the message as expired even before the scheduler runs:
  - it leaves the active inbox and the active unread count immediately;
  - it appears in the family archive view even while its stored status is still `PUBLISHED`;
  - an IMPORTANT message can still be acknowledged, marked late;
  - no new follow-up is ever opened for an expired message.
- **The scheduler remains authoritative:** it performs the persistent `PUBLISHED → ARCHIVED` transition and closes incomplete follow-ups as expired. It is the only way to archive in this release (no manual archive).
- **Retention:** the retention period needs separate confirmation against the existing data-retention policy. docs/08 records 24 months for authentication and security events; no permanent retention is decided here. No automatic deletion of the immutable notification audit history is authorized. Operational failed-job records are separate from that history (30 days recommended, §2a).

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
| `ARCHIVED` | a published message archived automatically at expiry (no manual archive in this release) |
| `CANCELLED` | withdrawn before publication (from `DRAFT`, `QUEUED` or `PUBLISH_FAILED`, the failure history kept); never published. A published message is never cancelled |

Rules:
1. A sender may prepare a `DRAFT` and request publication (`QUEUED`); the request is idempotent (client reference).
2. The worker moves the message to `PUBLISHING` and, through an idempotent workflow, re-checks on the backend the sender's authorization and CURRENT scope — immediately before writing the snapshot, inside the publication transaction — and resolves the target families.
3. **Initial implementation: one database transaction** writes the whole recipient snapshot (deduplicated: one row per family) and sets `PUBLISHED` with `published_at`. Either all of it commits, or none of it does.
4. `published_at` is set only when publication completes successfully. All follow-up deadlines and expiry are measured from it.
5. A family sees a message only once it is `PUBLISHED` (or later `ARCHIVED`). Sender-facing published lists and published dashboard counts exclude `QUEUED`, `PUBLISHING` and `PUBLISH_FAILED`; a sender may see their own pending and failed attempts in a separate operational view.
6. A failure rolls the transaction back and records `PUBLISH_FAILED`: no partially visible message, no recipient rows. A retry is idempotent and never duplicates recipients (unique message + family).
7. `CANCELLED`, `ARCHIVED` and `PUBLISH_FAILED` are distinct: never published, published then archived, and not (yet) published.
8. If chunked publication is ever needed for very large audiences, the `PUBLISHED` transition (and `published_at`) happens only after every chunk is verified complete; until then nothing is visible. Not part of the initial implementation.

SYSTEM messages (ADR-09) are discovered and created asynchronously, enter the lifecycle at `QUEUED` (never `DRAFT`), and are published by the same queued workflow: their single recipient row and the `PUBLISHED` transition are written atomically in one transaction (rule 3).
- **Order of work:** local development and testing come first. Production configuration must be verified independently, and activating the worker and the scheduler needs a separate deployment plan and explicit approval. No Production change is authorized here.
- **Computed counts:** dashboard follow-up counts may be computed from the authoritative timestamps so they stay correct if a scheduled task is late. This never replaces the persistent follow-up and audit records.

### ADR-09 — Automatic system notifications
- **Source:** system notifications are derived from the existing append-only `workflow_events`.
- **Idempotency:** ONE system message per unique origin event; the origin (the `workflow_events` row) is unique on the message, so a replay or retry never creates a second message. Its recipient is the family associated with the originating request. It is not "one message per origin event per family" in general.
- **Access:** system messages use the same family inbox and the same family-side access controls (`family.context`) as announcements.
- **Candidate types:** the supported event types are the nine listed in §1. The initial family-facing mapping is decided in FB-NOTIFY-01B; candidates are RETURNED (clarification needed), APPROVED (approved, not yet applied — APPROVED ≠ APPLIED), APPLIED, REJECTED and SUBMITTED (receipt).
- **Initial mapping (FB-NOTIFY-01B.1):** `RETURNED`, `REJECTED` and `APPLIED` only. No other workflow event is notified automatically.
- **Asynchronous generation:** nothing is added to the change-request actions or to `WorkflowEventRecorder`:
  - **discovery:** a scheduled command finds eligible `workflow_events` that have no message yet, so events recorded before it runs are picked up later;
  - **creation:** one transaction per event creates the SYSTEM message as `QUEUED` (the unique origin makes a replay a no-op) and dispatches the publication job after commit;
  - **publication:** the ADR-08 queued workflow writes the recipient and `PUBLISHED` atomically; nothing is visible before;
  - **reconciliation:** re-dispatches stalled `QUEUED` messages and recovers stale `PUBLISHING` ones.
  No queue worker ever runs inside an HTTP or workflow transaction.
- **Activation cutoff:** events recorded before the feature's activation cutoff are never backfilled.
- **Content:** fixed, safe templates per event type with a family-relative link to the authorized request view. The `internal_note` is never copied.
- **Recipient eligibility (this release):**
  1. A SYSTEM notification needs a valid originating `workflow_events` row (an eligible type, after the activation cutoff) and a resolvable recipient: the originating change request's own `family_id`.
  2. Requests with `family_id = null` are skipped — `REGISTER_FAMILY` requests from coordinators (whose new family is recorded only in `created_family_id` at APPLY) and any other family-less request. No recipient is fabricated, and none is inferred from `created_family_id`, from the coordinator's own family or from anything else.
  3. Coordinator-submitted requests about an existing family are eligible when the request has a valid `family_id` and the event is RETURNED, REJECTED or APPLIED.
  4. A notification grants no access. The `action_path` is set only when the family is authorized to open the destination under the existing family-side rules. Today the family request view is scoped to the context family's `family_id`, which includes coordinator-submitted requests about it; otherwise the notification is sent without an action link. Opening the link is always re-authorized by that endpoint, and a notification never enables a family action on a coordinator's request.
  5. A skipped event never interrupts, delays or modifies the change-request workflow, its transitions or `workflow_events`.
- **Skipped events are not failures:**
  - Eligibility is part of the discovery query (eligible type, after the cutoff, a non-null `family_id`, no existing message), so an ineligible event is never selected. It does not fail, does not retry, and is not re-processed as an error on each run.
  - Skips are reported only as non-sensitive operational diagnostics: per-run counts by reason code (e.g. `NO_FAMILY`) in the structured application log, and the count of skipped events since the cutoff in the notifications health command. These contain counts and codes only — no request, family or person identifiers.
  - No fifth notification table and no message row are created for a skipped event.
  - The decision is deterministic and idempotent: a request's `family_id` is not assigned later by APPLY, so a skipped event stays skipped.
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
  - acknowledgement and expiration are race-safe (row locks); Spatie permission and role revocation is not serialized with publication (ADR-04);
  - per-user rate limits apply to sending and acknowledgement.

## 2a. Design alignment (FB-NOTIFY-01B.1, 2026-10-18)

The owner provisionally approved the physical design directions below. They refine ADR-01 to ADR-10 without changing them; the physical schema is implemented only in FB-NOTIFY-01B.2 after final approval.

| ID | Direction |
|---|---|
| D1 | A human-readable notice code (`NTF-000001`) |
| D2 | `recipient_count` stored as a historical fact of successful publication |
| D3 | `PUBLISH_FAILED → CANCELLED` allowed, failure history kept |
| D4 | No manual archive; automatic archive at expiry only |
| D5 | SYSTEM messages default to NORMAL and are not exempt from follow-up rules |
| D6 | Administrators and coordinators (current scope) manage follow-ups; SOCIAL_WORKER only under a separately approved assignment model |
| D7 | Initial automatic events: RETURNED, REJECTED, APPLIED |
| D8 | SYSTEM generation asynchronous through the queue, idempotent (ADR-09) |
| D9 | 30-day retention for operational failed-job records, separate from the immutable audit history |
| D10 | Coordinator permissions named `coordinator-*` (ADR-04) |
| D11 | ARCHIVED and expired messages excluded from the active unread count, kept in the archive |
| D12 | FIRST and SECOND follow-up stages may coexist; a valid family acknowledgement closes both |
| R1 | A coordinator cannot manage follow-ups for their own family |
| R2 | Minimum lead of 1 h for `expires_at` when publication is requested |
| R3 | Length limits: title 150, body 2000, follow-up note 1000 characters |
| R4 | Three automatic publication attempts, then manual retry or cancel |
| R5 | A `PUBLISHING` message older than 10 minutes, with no live worker lock, is recovered as stale |
| R6 | `NOTE_CORRECTION` suppression for incorrect or sensitive notes (ADR-06) |
| R7 | This alignment of ADR-04, ADR-08 and ADR-09 |
| R8 | A SYSTEM message to a family that is not ACTIVE is published and recorded, but opens no follow-up |
| E1 | SYSTEM recipient eligibility: the request's own non-null `family_id` only; family-less requests skipped as diagnostics, never failures; the action link only where the family is already authorized (ADR-09) |

## 2b. Implementation status (FB-NOTIFY-01B.2, as built — 2026-10-19)

Recorded in FB-NOTIFY-01B.2b.7 from the code at `022acc8`, branch `feature/fb-geo-02`. **Local commits only:** not merged, not deployed. Production is not migrated or seeded, and the mode is OFF. The decisions above are unchanged. This section records how they were built and the decisions taken during implementation.

| Phase | Commit | Delivered |
|---|---|---|
| 01B.2a | `29a0b12` | four tables, CHECKs, append-only trigger, `NTF-` sequence (docs/02 §59, docs/04 §56) |
| 01B.2b.2 | `60f62bd` | `config/notifications.php`, `NotificationReadiness`, worker heartbeat, `famboook:notifications-check`, `notifications.publication-ready` middleware |
| 01B.2b.3 | `d71f595` | `NotificationAudienceResolver`, `NotificationEventRecorder` |
| 01B.2b.4 | `da77d93` | Domain Actions: draft, request publication, cancel, retry |
| 01B.2b.5b | `281ec85` | `NotificationRetryPolicy` (R4 as decided, below) |
| 01B.2b.5c | `5246484` | `PublishNotificationJob` + `NotificationPublisher` (T1 claim / T2 atomic publication / T3 failure) |
| 01B.2b.5d | `27890a1` | `famboook:notifications-reconcile` (stale recovery, automatic retry, orphan re-dispatch) |
| 01B.2b.6b | `6fadbd7` | Family inbox, archive, detail, explicit view, acknowledgement; `famboook:notifications-expire` |
| 01B.2b.6c | `3ee4958` | Staff and Coordinator announcement APIs |
| 01B.2b.6d | `022acc8` | RBAC seeding and verifier (docs/06 AUTH-ADR-097) |

**Implemented as decided:**
- **ADR-01:** one inbox, `kind` SYSTEM / ANNOUNCEMENT, sources «إدارة Famboook» / «منسق العائلة».
- **ADR-02:** custom tables.
- **ADR-03:** family recipients snapshotted by one `INSERT … SELECT`, deduplicated.
- **ADR-04:** the sender re-read in T2, with a coordinator's assignment rows locked `FOR SHARE`.
- **ADR-05:** list and detail never record a view; view and acknowledge are explicit POSTs.
- **ADR-07:** expiry effective at `expires_at` in read models; the sweep archives; late acknowledgement allowed.
- **ADR-08:** the queued lifecycle and no synchronous fallback.
- **ADR-10:** ownership 404 on every lookup, per-user rate limits, codes-only audit metadata.

**Decisions taken during implementation** (approved in the phase briefs):
1. **Retry policy (R4 as decided, 2b.5b):**
   - `publish_attempts` counts worker claims;
   - **3** automatic attempts for transient failures (`PUBLICATION_ERROR`, `STALE_PUBLISHING`), with a 60 s / 300 s backoff;
   - **1** manual retry, counted from `REQUEUED` events by STAFF or COORDINATOR, and available for a transient failure only after the automatic attempts are used;
   - `AUDIENCE_EMPTY` is manual-only;
   - `SENDER_NOT_AUTHORIZED`, `AUDIENCE_INVALID` and `EXPIRED_BEFORE_PUBLICATION` are permanent (cancel only);
   - never more than **4** claims.
2. **Reconciliation (R5):**
   - stale `PUBLISHING` after 600 s becomes `PUBLISH_FAILED` / `STALE_PUBLISHING`;
   - an orphaned `QUEUED` message is re-dispatched after 180 s, at most once per 300 s, throttled by `updated_at` (no migration);
   - automatic requeues are attributed to SYSTEM;
   - steps that could lead to publication pause while dispatching is unsafe.
3. **Readiness:**
   - mode OFF (default) / PILOT (sender allow-list) / ON;
   - a `sync` or `null` queue is refused;
   - the heartbeat must be fresh (≤ 300 s);
   - timing must satisfy job timeout 60 s < queue `retry_after` < stale 600 s;
   - readiness is checked after authorization and never grants anything. **Accepted limitation:** a mode switched OFF between T1 and T2 makes T2 refuse, and the attempt is consumed as a transient `PUBLICATION_ERROR`.
4. **Coordinator history and cancel after revocation (2b.4 / 2b.6c):**
   - the list, the detail and `/cancel` of their own unpublished messages are routed **outside** `coordinator.space`, so they remain after a scope revocation;
   - statistics there are counted over the CURRENT scope only (`NO_CURRENT_SCOPE`: none);
   - preview, draft, publish and retry need the current scope;
   - losing the COORDINATOR role or permission ends all of it.
5. **Idempotency:**
   - `client_reference` (uuid, unique per sender) makes **draft creation** idempotent;
   - publish, retry, cancel, view and acknowledge are idempotent by state (replays answer 200 with `meta.replayed`).
6. **Statistics:** aggregate counts only (`recipients`, `with_account`, `without_account`, `viewed`, `not_viewed`, and for IMPORTANT `acknowledged`, `acknowledged_late`, `pending_acknowledgement`); `recipient_count` is the publication-time fact (D2).

**Not implemented:**
- **SYSTEM notifications (ADR-09):** no discovery command. The gates, cutoff and `SYSTEM_ORIGIN` audience exist and stay closed.
- **Follow-ups (ADR-06):** the table exists, with no automation or API. *Foundations since FB-NOTIFY-01E.3-F1 (local): permissions seeded, reason catalogues, dynamic ownership and the cutoff configuration (§2c). Engine since FB-NOTIFY-01E.3-F2 (local, uncommitted, gate OFF): opening, satisfaction, expiry and the scheduled command (§2d); still no follow-up API.*
- **Notice correction (R6).**
- **Any frontend.**

**Known gaps** (`docs/audits/FB-NOTIFY-01B-FRONTEND-HANDOFF.md` §1):
- **Audience references:** the stored audience keeps canonical integer ids, and no API exposes them.
  - *Families resolved in FB-NOTIFY-01B.2b.8a (local code baseline only, not deployed):* the HTTP API takes public `family_codes` for `STAFF_FAMILIES` and `COORDINATOR_SELECTION`. `NotificationAudienceReferences` maps them in one query over the families the sender may address now (ACTIVE for Staff; `CoordinatorScopes::families()` for a coordinator) before the preview and the draft action.
  - **Refusals:** internal id keys are refused (`NOTIFICATION_AUDIENCE_INVALID`). An unknown, inactive, deleted or out-of-scope code refuses the whole audience with `NOTIFICATION_AUDIENCE_FAMILY_NOT_ELIGIBLE`.
  - **Unchanged:** the stored audience, the publication request, the worker's T2 re-validation and idempotency.
  - **Deferred:** structure references (branch, branch group, clan).
- **No discovery:** neither publication availability nor the coordinator's notification permission is discoverable.

**Contracts:**
- **API:** docs/11 §21 "As built".
- **Permissions:** docs/06 §22b "Unified notifications".
- **Operations:** docs/08 §16 and `docs/deployment/FB-NOTIFY-01-PILOT-READINESS-CHECKLIST.md`.

## 2c. Follow-up foundations — ADR-06 clarification (FB-NOTIFY-01E.3-F1, 2026-10-19)

Approved implementation baseline (owner, FB-NOTIFY-01E.2 reconciliation). **Local code only:** not committed, not deployed, nothing activated. Production is not migrated or seeded. It clarifies ADR-04 ("Follow-ups", "Own family") and ADR-06 ("Alerting", "Manual actions") and changes no other decision.

**Ownership (`App\Support\Notifications\FollowupOwnership`):**
1. **One primary coordinator per family**, or none. Only the primary coordinator records a contact or closes manually; every other coordinator whose CURRENT scope covers the family may view only.
2. **Candidates:** the users of the non-revoked assignments covering the family (its Clan; the Branch Group of its Branch; its Branch). Each user is ranked by their most specific covering assignment: **BRANCH > BRANCH_GROUP > CLAN**, then the earliest `assigned_at`, then the lowest assignment id. The order is deterministic, including on equal timestamps.
3. **Eligibility**, checked per candidate in order (the first who passes is primary):
   - `CoordinatorScopes::context()` allowed, which covers the household head, the COORDINATOR role, `coordinator-space.access` and an effective assignment;
   - `CoordinatorScopes::canAccessFamily()`, the very same scope rule (nothing is duplicated);
   - `coordinator-notification-followup.manage`;
   - the family is NOT the coordinator's own household.

   An ineligible candidate is skipped, never inherited.
4. **CENTRAL:** if no candidate is eligible, or the family is not ACTIVE, the follow-ups are CENTRAL. SUPER_ADMIN and ADMINISTRATOR (`notification-followup.*`) oversee and manage every follow-up in any case. No new role.
5. **Dynamic:**
   - ownership is derived on every call;
   - no `owner_user_id` or assignee column, and no cache;
   - a revocation, a role or permission removal, a moved family or an inactive structure takes effect on the next call;
   - the resolver is a pure read; callers that act lock first (recipient, follow-up, actor, assignments);
   - the owner is a server-side value, and no internal id is exposed by any resource.
6. **View versus manage (the rule every later action and endpoint follows):**
   - **Current scope permits VIEWING:** a coordinator with `coordinator-notification-followup.view` sees the follow-ups of every family in their CURRENT scope, their own household included.
   - **Only the primary coordinator MANAGES:** recording a contact or closing manually needs `coordinator-notification-followup.manage` AND being the family's dynamically resolved primary coordinator.
   - **Never their own household:** a coordinator is never the primary coordinator of, and never manages, their own household's follow-ups. Ownership falls to the next eligible coordinator, or CENTRAL.
   - **Central Staff manage all:** SUPER_ADMIN and ADMINISTRATOR (`notification-followup.view` / `.manage`) oversee and manage every follow-up, CENTRAL ones included.
   - The management restrictions are enforced in the future contact and closure Domain Actions (`coordinatorMayManage()` under their locks), never only in a UI.
7. **Cost:** one ranking query, then a few queries per examined candidate. It stops at the first eligible candidate and is exact for one family. Listing many families needs a set-based form, which is deferred.

**Permissions** (seeded locally, verified by `famboook:verify-permissions`; catalog 158 → 162):

| Permission | Holders |
|---|---|
| `notification-followup.view` / `.manage` | SUPER_ADMIN, ADMINISTRATOR (central oversight and management) |
| `coordinator-notification-followup.view` / `.manage` | COORDINATOR only (view: current scope; manage: primary owner only) |

DATA_ENTRY, REVIEWER, SOCIAL_WORKER, REPORTS_VIEWER and FAMILY_USER hold none of them. SOCIAL_WORKER waits for a Staff assignment model. Direct (model) grants are refused by the verifier.

**Reason catalogues (codes only; any free text is the internal note):**
- **Contact outcome** (`CONTACT_RECORDED.reason_code`, `NotificationFollowupContactOutcome`): `CALLED_INFORMED`, `CALLED_NO_ANSWER`, `VISITED`, `MESSAGE_LEFT`, `OTHER`. A contact never closes the follow-up. `CALLED_INFORMED` means "told", not "acknowledged".
- **Manual closure** (`close_reason_code`, outcome `CLOSED_MANUALLY`, `NotificationFollowupCloseReason`): `INFORMED_BY_PHONE`, `INFORMED_IN_PERSON`, `FAMILY_UNREACHABLE`, `FAMILY_INACTIVE`, `NOT_APPLICABLE`.
- **No impersonation:** neither catalogue can impersonate a family view or acknowledgement. Those are recorded only by the family's own household head (FAMILY side), and a manual closure is never `SATISFIED`.

**Cutoff (`NOTIFICATIONS_FOLLOWUPS_CUTOFF`, `notifications.followups_cutoff`):**
- **Scope:** recipients published before the cutoff never get a follow-up. There is no backfill.
- **Default:** unset.
- **Format:** ISO-8601 with an explicit offset, the same as the SYSTEM cutoff and independent of it.
- **Readiness:** `NotificationReadiness::forFollowups()` adds `FOLLOWUPS_CUTOFF_MISSING` or `FOLLOWUPS_CUTOFF_INVALID` to the existing gate (`FOLLOWUPS_DISABLED`), the mode and the infrastructure reasons.
- **No activation:** a cutoff alone activates nothing. The gate stays `false`, and the mode OFF closes everything.
- **Health check:** `famboook:notifications-check` reports "Follow-up cutoff: NOT SET / INVALID / VALID" and warns when it is invalid.

**Not built in F1:** follow-up opening, the scheduler task, workers or cron, APIs, screens, any change to the view or acknowledge actions, and migrations.

**Limitations and open points:**
- **Not serialized:** Spatie role and permission revocation is not serialized with a follow-up action (as for publication, ADR-04).
- **Family inactivity:** a family that leaves ACTIVE keeps its OPEN follow-ups. They become CENTRAL and are closed manually with `FAMILY_INACTIVE`.
- **Earlier wording:** ADR-04 "Follow-ups" and AUTH-ADR-097 still say a coordinator may act "within the current scope". That reading is now narrowed to the primary owner, and the earlier text is superseded.
- **Not yet recorded:** the reason codes are not yet checked at the database level, and are enforced by the future actions. Contact-attempt limits and alerting the owner are still unspecified.

**Performance and concurrency boundaries (binding on the later stages):**
- **One family per call:** ownership is resolved dynamically for ONE family. A set-based resolver is not required in F1.
- **Lists:** a future list endpoint must paginate with a fixed upper bound and must not resolve ownership per row without that bound (no unbounded N+1). A set-based form comes with such an endpoint if needed.
- **Revocation races:** role, permission and assignment revocation racing a contact or closure must be handled before those actions are implemented (lock order recipient → follow-up → actor user FOR SHARE → assignments FOR SHARE, ownership re-resolved under the locks).
- **PostgreSQL tests:** PostgreSQL locking and concurrency tests are mandatory for the contact and closure actions.

## 2d. Follow-up engine (FB-NOTIFY-01E.3-F2, 2026-10-20)

**Local code only:** not committed, not deployed, nothing activated. Follow-ups stay OFF by default. No migration: the existing schema is enough. Built in `App\Support\Notifications\NotificationFollowupEngine`, the command `famboook:notifications-followups` and two hooks in the family view and acknowledge actions.

**Transitions (and nothing else):**

| From | To | When | Event |
|---|---|---|---|
| none | `OPEN` | the stage is due and unmet, and the gate is ready (below) | `FOLLOWUP_OPENED` (SYSTEM; metadata `stage`, `trigger_reason`, `level`, `had_family_account` — the account state AT OPENING, diagnostic only) |
| `OPEN` | `CLOSED` / `SATISFIED` | the family's own view (NORMAL, FIRST) or acknowledgement (IMPORTANT, every OPEN stage), strictly before `expires_at` | `FOLLOWUP_CLOSED` (SYSTEM; `outcome` SATISFIED, `closed_by` VIEWED / ACKNOWLEDGED) |
| `OPEN` | `CLOSED` / `EXPIRED` | the message reached `expires_at` (or is ARCHIVED), whatever the gate | `FOLLOWUP_CLOSED` (SYSTEM; `outcome` EXPIRED, `closed_by` EXPIRES_AT) |

- **Stages:** NORMAL FIRST `UNVIEWED` at `published_at` + 72 h; IMPORTANT FIRST `UNACKNOWLEDGED` + 24 h and SECOND + 72 h. `due_at` is derived from `published_at`, never from the run time.
- **Independent stages:** FIRST and SECOND are independent. A closed FIRST, whatever its outcome, is never reopened and never suppresses SECOND. After a scheduler outage both stages open in the same run.
- **Expiry wins:** at or after `expires_at`, a late acknowledgement is still recorded (ACKNOWLEDGED_LATE) but changes no follow-up. The scheduler closes them EXPIRED. Nothing opens for an expired message.
- **Manual closure** (a later stage) changes the follow-up only, never the recipient.

**Opening conditions:**
- **Gate:** `NotificationReadiness::forFollowups()` is empty, meaning a mode other than OFF, `NOTIFICATIONS_FOLLOWUPS_ENABLED`, a valid cutoff and a ready worker. PILOT opens like ON once ready; there is no per-sender restriction for follow-ups.
- **Message:** PUBLISHED, `published_at` at or after the cutoff (no backfill) and not expired.
- **Family:** ACTIVE at publication (`family_status_at_publication`) AND at opening (not soft-deleted). A family that leaves ACTIVE keeps its OPEN follow-ups: they stay OPEN pending central review (manual closure `FAMILY_INACTIVE`, §2c) or the message's actual expiry. Family inactivity alone never produces EXPIRED.
- **Accountless families:** accountless ACTIVE families remain eligible, because contact is most needed there. `had_family_account` is diagnostic metadata only. It reflects the account state at the time of opening, using the publication snapshot's definition (`NotificationRecipientSnapshot::activatedAccount()`), not the publication-time snapshot itself, and it never decides eligibility. *Confirmed as the F2 business baseline, 2026-10-20.*
- **Uniqueness:** never a second row per (recipient, stage). The unique index is the last guard.

**Lock order (every path):**
1. **The recipient row `FOR UPDATE`.** The family actions already hold it; the scheduler takes it with `SKIP LOCKED` and leaves a busy recipient to the next run.
2. **That recipient's follow-up rows `FOR UPDATE`, by id.**
3. **Inserts:** the follow-up row (FK key-share on the recipient, already held) and its event (FK key-share on the message, the recipient and the follow-up).

- **The message row is never locked by the engine.** The expiry sweep and the publication worker lock messages and never then lock a recipient, so no cycle can form. At most an event insert waits for a sweep's short message transaction.
- **Expiry closure is deliberately not part of the message sweep.** Locking recipients after the message would invert the family actions' order: recipient, then key-share on the message.
- **Timeouts:** scheduler transactions set `lock_timeout` 5 s and `statement_timeout` 45 s locally. The family hooks run inside the actions' existing transaction.
- **No partial state:** each transition and its event commit together. A failed event rolls back the transition, including the family action that caused it. Errors are counted, logged with codes only and give exit code 1; they are never swallowed.

**Concurrency review (F2 hardening, 2026-10-20):** a review against the code, not a proof of deadlock freedom.
- **No message → recipient inversion.** The paths that lock a message row FOR UPDATE never lock a recipient or follow-up row:
  - the expiry sweep;
  - the publication worker's claim and T2, which only INSERTs new recipient rows for a message that is not yet PUBLISHED, so the engine cannot see them before commit;
  - the reconciler.

  The engine and the family actions lock recipient → follow-ups and only take FK key-share on the message. Family rows are read without locks, and `had_family_account` is a plain read.
- **Same-session FK locks:**
  - the follow-up INSERT's key-share on the recipient is covered by the recipient lock the transaction already holds;
  - the event INSERT's key-share on the follow-up is covered by its FOR UPDATE (or is a row this transaction inserted);
  - the only cross-session wait left is key-share on a message held by the sweep or the worker, bounded by `lock_timeout` (5 s) in scheduler transactions.
- **Timestamps:** every decision uses a PHP timestamp taken AFTER the recipient lock is held (the acknowledgement's `$now`, the view's `$now`, the engine's per-transaction `now`).
  - Two transactions on one recipient therefore decide in lock order. An expiry closure committed first means a later acknowledgement's `$now` ≥ `expires_at`, so it is late; an acknowledgement before `expires_at` that commits first leaves nothing OPEN to expire.
  - Times come from the application server's clock (single server, docs/08 §1), not from the database.
- **Residual boundary:** the opening step reads the message without a lock. A sweep archiving it within the same instant of `expires_at` could, in theory, race an opening decided with a `now` a few milliseconds earlier. The result is self-healing: the next run closes that follow-up EXPIRED. No event is duplicated and nothing is reopened.
- **Family hooks:** they run inside the existing actions' transactions, which set no lock or statement timeout (unchanged behaviour). They only wait on their own recipient's follow-up rows, already serialized by the recipient lock.

**Scheduler:** `famboook:notifications-followups --limit=1..1000` (default 100) runs every minute with `withoutOverlapping(10)` and `onOneServer()`.
- **Order:** expiry first, then opening.
- **Bounded:** at most `limit` recipients per phase, oldest publication first, each recipient its own short transaction.
- **Recovery:** an interrupted run leaves nothing behind, and the next run resumes. Repeated runs are idempotent.
- **Output:** counters only (`expiry_scanned`, `expired`, `expiry_skipped`, `expiry_deferred`, `opening_scanned`, `opened`, `opening_skipped`, `opening_deferred`, `errors`).
- **Fairness:** each candidate is tried at most once per run.
  - A recipient whose step FAILED is deferred for 10 minutes (`RETRY_BACKOFF_SECONDS`) and then retried.
  - Deferred recipients are excluded from the candidate queries before the limit, so a healthy recipient behind a failing one still progresses.
  - The deferral list is one shared-cache entry per phase (`…:retry-backoff:expiry` / `:opening`), bounded to 1,000 ids. Losing it, or an overflow, only means an earlier retry: nothing is deleted, excluded for good or lost.
    - **Production store:** `CACHE_STORE=database`, shared by every scheduler process, with atomic locks in `cache_locks`.
    - **No overwrites:** a run merges only its OWN new failures into the freshly re-read list, under that phase's cache lock (at most about 5 s of bounded attempts, then it logs `FOLLOWUP_BACKOFF_BUSY` and records nothing). Concurrent runs never overwrite each other's deferrals.
    - **No idle writes:** nothing is written when nothing failed, so mode OFF never creates or changes the opening list.
    - **Bounded reads:** entries further ahead than one backoff period, or malformed, are ignored, so a corrupt value can never defer a recipient for longer.
  - Failures stay visible in `errors`, the `*_deferred` counters and the logs (code `FOLLOWUP_STEP_ERROR`).
- **Not active without the cron**, which is a separate approval.

**Health (`famboook:notifications-check`):** informational counts only. There is no warning and no threshold (none is approved), so the exit code and the health script's states are unchanged, and an OFF-mode backlog is never a failure.
- **`open_followups`:** every OPEN follow-up.
- **`expired_open`:** OPEN follow-ups whose message reached `expires_at` or is ARCHIVED. The follow-up command closes them EXPIRED whatever the gate.
- **`due_unopened`:** (recipient, stage) pairs the opening rules would open now (cutoff, due, unmet, not expired, family ACTIVE at publication and now) with no follow-up yet.
  - It is annotated "expected while opening is not ready: <reasons>" or "opening ready".
  - It reads "not applicable" without a valid cutoff.

**Correction (pre-existing):** `famboook:notifications-check` now resets its warnings at the start of every run. The command instance is reused within one process, so a previous run's warnings could leak into the next run's exit code. Production runs one check per process and was not affected.

**Not built:** Staff or Coordinator follow-up APIs, contact and manual-closure actions, owner alerts, UI, the SYSTEM producer and any external channel.

## 2e. Follow-up contact and manual closure actions (FB-NOTIFY-01E.3-F3.1, 2026-10-20)

**Local code only:** not committed, not deployed. There is **no route and no screen**, and no migration. Assignment creation now takes the clan lock (below). Two Domain Actions share one lock-then-authorize step (`App\Support\Notifications\FollowupManagement`):

| Action | Effect | Event |
|---|---|---|
| `RecordFollowupContactAction` | none on the follow-up or the recipient; it never closes | `CONTACT_RECORDED`: actor STAFF / COORDINATOR, `reason_code` a contact outcome (`CALLED_INFORMED`, `CALLED_NO_ANSWER`, `VISITED`, `MESSAGE_LEFT`, `OTHER`), optional note, metadata `stage` |
| `CloseFollowupManuallyAction` | OPEN → CLOSED / CLOSED_MANUALLY; `closed_at`, `closed_by_user_id`, `close_reason_code` (`INFORMED_BY_PHONE`, `INFORMED_IN_PERSON`, `FAMILY_UNREACHABLE`, `FAMILY_INACTIVE`, `NOT_APPLICABLE`) | exactly one `FOLLOWUP_CLOSED`: same actor, same reason code, optional note, metadata `stage`, `outcome` |

- **Domain-boundary validation:** reason codes are validated against their enum before any lock. The note is trimmed and must be 1..1000 characters; otherwise it is refused. Notes are never logged.
- **Refusals write nothing:** each refusal is a `NotificationFollowupException` with a stable code (`FOLLOWUP_NOT_FOUND`, `_ACTOR_NOT_ALLOWED`, `_NOT_OPEN`, `_EXPIRED`, `_REASON_INVALID`, `_NOTE_INVALID`, `_OWNERSHIP_CHANGED`) and leaves no event and no state change. A failed event rolls the closure back.
- **Never the recipient:** neither action touches the recipient: no view, no acknowledgement, no impersonation of the family. A CLOSED stage is never reopened. Closing FIRST never suppresses an IMPORTANT SECOND.
- **Expiry wins:** at or after `expires_at`, or for an ARCHIVED message, both actions are refused with `FOLLOWUP_EXPIRED`; the scheduler closes the stage EXPIRED.

**Authorization (re-evaluated under the locks):**

| Actor | Contact / manual closure |
|---|---|
| SUPER_ADMIN, ADMINISTRATOR (active, Staff side, `notification-followup.manage`) | every follow-up, CENTRAL ones included |
| COORDINATOR, the family's CURRENT primary coordinator (`coordinator-notification-followup.manage`, current family context, `coordinator-space.access`, effective scope) | allowed |
| COORDINATOR in scope but not primary; out of scope; only a revoked (historical) assignment; their own household | refused |
| DATA_ENTRY, REVIEWER, SOCIAL_WORKER, REPORTS_VIEWER, FAMILY_USER, any inactive account, a role that lost the permission | refused |

An inactive family is CENTRAL (§2c): only Staff close it, e.g. with `FAMILY_INACTIVE`.

**Clan-row ownership protocol (FB-NOTIFY-01E.3-F3.1b, 2026-10-20).** It prevents a phantom primary owner created by a concurrent assignment.
- **Why the clan row:** every assignment that covers a family carries that family's `clan_id`. That equality is enforced both in the ranking and in `CoordinatorScopes`. `clan_id` is NOT NULL on assignments and never updated, and the composite FKs keep a group or branch inside its clan. A branch or group row would not work: a CLAN-level assignment never references them, and a family may have no branch.
- **Assignment creation** (`AssignCoordinatorScopeAction`, the only writer that creates assignments) locks the target's clan row FOR NO KEY UPDATE, before the target user, for CLAN, BRANCH_GROUP and BRANCH alike.
  - NO KEY UPDATE, not UPDATE, so the FK key-share locks taken by inserts under the clan (families, branches, change requests) are not blocked.
- **Coordinator follow-up actions** lock the family's clan row FOR SHARE first, then resolve ownership under it.
- **Authorization point:** the ownership ranking that runs while the clan row (and the rows below) are locked. A new assignment either committed before it, so the ranking sees it, or waits for the action to commit. The action never completes under an owner that a committed assignment has already replaced.
- **Rule for future code:** any future path that creates an assignment, or edits an assignment's scope, MUST take the same clan lock first. Editing a scope would mean both clans, in id order.

**Lock order:**
- **Coordinator action:**
  0. **The family's clan row FOR SHARE**, before the recipient. A coordinator action waiting for an assignment therefore never holds up the family's view or acknowledgement or the scheduler. The family's `clan_id` is re-checked after the recipient lock; if the family moved, the action refuses with `FOLLOWUP_OWNERSHIP_CHANGED`.
  1. **The recipient row FOR UPDATE.**
  2. **The follow-up row FOR UPDATE.**
  3. **Users FOR SHARE, by id:** the actor and every CURRENT ownership candidate. Locking the candidates, not only the actor, avoids a multi-row lock cycle with a role revocation of another candidate.
  4. **Those users' non-revoked assignments FOR SHARE, by id.**
  5. **Ownership resolved again under the locks;** the action is allowed only if the actor is still the eligible primary coordinator. An unlocked candidate gives `FOLLOWUP_OWNERSHIP_CHANGED`, which is defensive: the clan lock makes it unreachable through assignment creation.
- **Staff action (unchanged):** recipient FOR UPDATE → follow-up FOR UPDATE → the actor's user row FOR SHARE.
- **Timeouts:** transactions set `lock_timeout` 5 s and `statement_timeout` 45 s locally. A lock wait that times out raises SQLSTATE **`55P03`** (a `QueryException`, not a `NotificationFollowupException`). **The API stage (F3.2) must answer it as retryable** (for example 409 or 503 with retry) and write nothing.

How each coordinator administration action interacts:

| Admin action | Locks (order) | Against a coordinator follow-up action |
|---|---|---|
| Assign scope | clan NO KEY UPDATE → target user FOR UPDATE → INSERT | waits on the clan row (or the action waits for it) |
| Revoke scope | that assignment FOR UPDATE | waits on the assignment FOR SHARE (or the action waits for it) |
| Revoke role | user FOR UPDATE → the user's assignments FOR UPDATE | waits on the user FOR SHARE (or the action waits for it) |
| Grant role | user FOR UPDATE | waits on the user FOR SHARE for a current candidate |

**Lock-order audit (from the code):**
- **No user-then-clan path.** No path takes a user lock and then a clan lock. The only clan lockers are this action (FOR SHARE), the change-request placement check (request FOR UPDATE → clan FOR SHARE → branch FOR SHARE; no user, recipient or follow-up locks) and assignment creation (clan → user).
- **Structure and placement edits:** clan, group and branch structure edits and family placement updates (`UpdateFamilyAction`) are plain row UPDATEs with no earlier lock. A clan UPDATE briefly waits for these share locks.
- **Change requests:** change-request family locks never lead to a clan or user lock.
- **Notifications:** family view and acknowledgement, the F2 engine, the expiry sweep, the reconciler and the publication worker take no clan or user locks.

No cycle was found. This is not a claim of system-wide deadlock freedom: PostgreSQL's deadlock detection and the lock timeout remain the backstop.

The family view and acknowledgement and the expiry closure take the recipient first, so they serialize on it.

**Verified on PostgreSQL** (`FollowupManagementConcurrencyTest`). Waiting is proven through `pg_stat_activity`, and for the clan lock through the waiter's tuple lock on `clans` in `pg_locks`, not by timing:

| Scenario | Action first | The other session first |
|---|---|---|
| New, more specific assignment (a non-candidate) vs contact, and vs manual closure | the assignment waits on the clan row; the action completes; the assignment then commits | the action waits on the clan row, sees the new primary and is refused |
| CLAN- and GROUP-level assignments | they wait on the clan row too | — |
| Scope revocation vs contact or closure | the revocation waits; the action completes | the action waits, then is refused |
| Role revocation vs contact or closure | the revocation waits; the action completes | the action waits, then is refused |
| Two assignments in one clan | serialized; an identical one gets `SCOPE_EXISTS` | — |
| Family acknowledgement vs closure | the acknowledgement waits and satisfies only the stage still OPEN | the closure gets `FOLLOWUP_NOT_OPEN` |
| Expiry vs closure | the scheduler skips the held recipient, so a stage is never closed twice | the closure is refused |
| Two closures | one wins; the other gets `FOLLOWUP_NOT_OPEN` | — |
| Three sessions: assignment, action, acknowledgement | the action holds the clan row and the recipient; both others wait, then proceed; no deadlock | the assignment holds the clan row; the action waits WITHOUT the recipient, so the acknowledgement goes through at once; the action is then refused |

**Limitations: ownership changes that are NOT serialized.** Any of these may still let one action complete under an owner they change:
- **Family placement changes:** a family moved after the clan re-check, or a branch change within the clan (`UpdateFamilyAction`, plain UPDATE).
- **Structural changes:** a branch or group activated or deactivated, or a branch regrouped (`ManageClanStructureAction`), changes coverage and eligibility without touching an assignment.
- **The coordinator's own household eligibility:** household-head membership, person link, identity, or the status of their own family.
- **Permission mappings:** role–permission changes (seeder) and Staff role changes made outside these actions (Spatie, ADR-04). The Staff user row is locked FOR SHARE, which only serializes writers that lock or update it.
- **Clock:** time decisions use the application server's clock (§2d).

These do not involve an assignment mutation and are outside the clan-row protocol. Complete serialization of every possible ownership change is **not** claimed.

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
1. Owner review of this ADR and of the physical schema (FB-NOTIFY-01B). *Done (01B.1 / 01B.2a).*
2. Approval and seeding of the proposed permissions, Staff and `coordinator-*` (docs/06 AUTH-ADR-097). *Done for `announcement.*` and `coordinator-announcement.*` (01B.2b.6d, local); follow-up permissions seeded locally in FB-NOTIFY-01E.3-F1 (§2c, uncommitted).*
3. A queue worker and a scheduler cron verified locally, then a separate, approved Production deployment plan (docs/08 §16). *Open: checklist in `docs/deployment/FB-NOTIFY-01-PILOT-READINESS-CHECKLIST.md`; no plan approved.*
4. The family-facing `workflow_events` mapping (ADR-09: RETURNED, REJECTED, APPLIED; provisionally approved). *Open: SYSTEM discovery not implemented.*
5. A retention confirmation (ADR-07). *Open.*
6. *Added 2026-10-19:* a server-side mapping of public audience references (family code, structure uuid) to canonical ids, before any frontend can address selected families (§2b). *Families done (FB-NOTIFY-01B.2b.8a, `family_codes`, local code baseline only); structure references deferred.*
