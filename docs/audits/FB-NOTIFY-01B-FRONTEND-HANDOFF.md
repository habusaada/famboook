# FB-NOTIFY-01B.2b.7 — Notifications Frontend Integration Handoff

| | |
|---|---|
| Audience | Next.js engineers building the Staff, Coordinator and Family notification screens |
| Backend baseline | `feature/fb-geo-02` at `022acc8` (FB-NOTIFY-01B.2b.2 … 2b.6d), committed locally, **not deployed**. *Updated for FB-NOTIFY-01B.2b.8a:* public `family_code` audience references (local code baseline only, not deployed) |
| Architecture | `docs/adr/FB-NOTIFY-01-UNIFIED-NOTIFICATIONS.md` (ADR-01 … ADR-10, §2a, §2b) |
| Permissions | `docs/06-PERMISSIONS.md` §22b "Unified notifications", AUTH-ADR-097 |
| Product rules | `docs/11-FAMILY-PORTAL.md` §21–§22 |
| Operations | `docs/deployment/FB-NOTIFY-01-PILOT-READINESS-CHECKLIST.md` |

Everything below was read from the code: routes (`backend/routes/api.php`), FormRequests, controllers, `AnnouncementResource`, `FamilyNotificationResource`, the Domain Actions and the exception classes. A field, code or status that is not listed here does not exist. **Do not add, rename or infer API fields in the client.** §1 lists what the backend does not support yet.

---

## 1. Blockers and missing contracts — read first

| # | Gap | Effect on the frontend | Backend decision needed |
|---|---|---|---|
| **B1** | ***Families resolved in FB-NOTIFY-01B.2b.8a*** (local code baseline only). Audiences name families by their public **`family_code`** (`family_codes`); the server maps them to internal ids (`NotificationAudienceReferences`). Internal id keys (`family_ids`, `branch_ids`, `branch_group_ids`, `clan_ids`) are **refused** at the HTTP boundary. **Still open:** selecting a branch, branch group or clan. Coordinator Space exposes structure codes only (no uuid), and branch codes repeat across clans. | `STAFF_FAMILIES` (ADMINISTRATOR included) and `COORDINATOR_SELECTION` of families are usable through `family_codes` taken from the existing family lists (§3.2, §4.2). There is no structure-level selection; use `COORDINATOR_SCOPE` for the whole scope. | Structure references are deferred: they need coordinator-visible structure uuids and a mapping, which need approval. **Never send internal ids.** |
| **B2** | **No readiness or capability endpoint for notifications.** Publication availability (mode OFF / PILOT allow-list / queue / worker heartbeat) is learned only from `503 NOTIFICATION_PUBLICATION_UNAVAILABLE` on `/publish` or `/retry`. | Compose and draft work while the feature is OFF, and the user discovers the outage only when publishing. | Optional: a boolean `publication_available` (no reasons), for example in a capabilities response. Until then, handle the 503 as in §8. |
| **B3** | **The family-side `/family/me` returns no permissions.** Whether a coordinator holds `coordinator-announcement.*` is not discoverable. | The Coordinator notifications entry cannot be shown or hidden from data. | Optional: a flag on `/family/me` or `/family/coordinator/context`. Until then, probe `GET /family/coordinator/notifications?per_page=1`; a 403 hides the entry. |
| B4 | **No edit of a draft.** There is no PATCH or PUT. | "Edit draft" means cancel and create a new draft. | — (as designed; confirm with the product owner) |
| B5 | **No catalog of cancellation `reason_code`s.** The API accepts any `^[A-Z][A-Z0-9_]{1,49}$`, or nothing. | Send no `reason_code` until the product owner approves a list. | An approved list of reason codes |
| B6 | **No recipient detail.** Senders get aggregate counts only, never which families. | No per-family delivery list. | — (as designed: ADR-03 / ADR-10) |
| B7 | **Filters are minimal.** Sender lists filter by `status` only. The family inbox has no filters (no unread-only, level or kind) and no "mark all read". | Do these client-side over the loaded page only, or not at all. | Only if the product owner asks |
| B8 | **SYSTEM notifications and follow-ups are not implemented.** No discovery command, no follow-up API, `notification-followup.*` not seeded. `action_path` is therefore always `null` today. | Build no follow-up screens. Render `action_path` only when it is non-null (§5.3). | Later phases |

---

## 2. Conventions shared by all three APIs

- **Transport:** the existing `apiClient`: Sanctum first-party session cookie, `X-XSRF-TOKEN`, `credentials: "include"`. Tokens are never read or stored by JavaScript.
- **Caching:** every response carries `Cache-Control: no-store, private`. Do not persist responses in `localStorage` or the PWA cache, and do not prefetch the family detail or view endpoints.
- **Identifiers:** a message's public `id` is its **uuid**. A non-uuid or unowned id is `404`, the same as a nonexistent one. `notice_code` (`NTF-000001`) is for display only.
- **Times:** ISO-8601 strings with an offset, or `null`. Display them in the device time zone (§9).
- **Error envelope** (domain refusals): `{ "message": "<fixed Arabic text>", "code": "<STABLE_CODE>" }`. Show `message`; branch on `code` and the HTTP status, never on the text.
- **Validation:** `422 { "message", "errors": { "<field>": ["…"] } }` (Laravel). Domain 422s use the error envelope with a `code` instead.
- **Middleware refusals:** some 403s have no `code`: `staff.side`, `family.side`, `can:<permission>`, and the FormRequest send-all check. Treat any 403 without a `code` as "not permitted".
- **Rate limits** (per signed-in user, per minute):

  | API | Limiter | Default | Shape of the 429 |
  |---|---|---|---|
  | Staff | `staff-announcement-read`: list, detail, preview | 60 | Laravel default `{ "message" }` with `Retry-After` |
  | Staff | `staff-announcement-write`: draft, publish, cancel, retry | 20 | as above |
  | Coordinator | `coordinator-announcement-read` | 60 | `{ "message", "code": "TOO_MANY_REQUESTS" }` |
  | Coordinator | `coordinator-announcement-write` | 20 | as above |
  | Family | `family-notification-read`: inbox, archive, detail | 60 | `{ "message", "code": "TOO_MANY_REQUESTS" }` |
  | Family | `family-notification-action`: view, acknowledge | 30 | as above |

- **Pagination** (all lists): Laravel paginated resources.
  - **Query:** `page` (≥ 1) and `per_page` (1–50, default 20). A value out of range is a 422.
  - **Response:**
    ```json
    { "data": [ … ],
      "links": { "first", "last", "prev", "next" },
      "meta": { "current_page", "from", "last_page", "links", "path", "per_page", "to", "total" } }
    ```
  - **Unknown query keys** are ignored, and are never trusted as a family or sender filter.

---

## 3. Staff announcements — `/api/v1/notifications`

### 3.1 Routes

Stack: `auth:sanctum` + `staff.side`, then:

| Method | Path | Permission (route) | Throttle | Success |
|---|---|---|---|---|
| GET | `/api/v1/notifications` | `announcement.view` | read | 200 page of summaries |
| POST | `/api/v1/notifications/audience-preview` | `announcement.create` | read | 200 `{ "data": { "recipient_count": int } }` |
| POST | `/api/v1/notifications` | `announcement.create` | write | **201** created · **200** replay |
| GET | `/api/v1/notifications/{id}` | `announcement.view` | read | 200 detail |
| POST | `/api/v1/notifications/{id}/publish` | `announcement.send` + `notifications.publication-ready` | write | **202** queued · **200** replay |
| POST | `/api/v1/notifications/{id}/cancel` | `announcement.create` (the action adds `announcement.send` once queued) | write | 200 |
| POST | `/api/v1/notifications/{id}/retry` | `announcement.send` + `notifications.publication-ready` | write | **202** requeued · **200** replay |

**Holders seeded in the code baseline** (`RolePermissionSeeder` at `022acc8`; **not seeded in Production**):
- SUPER_ADMIN: `view`, `create`, `send` and `send-all`.
- ADMINISTRATOR: `view`, `create` and `send`, never `send-all`.
- Every other Staff role (DATA_ENTRY, REVIEWER, SOCIAL_WORKER, REPORTS_VIEWER): none, so their routes answer 403. Read the permissions from the existing Staff `/me` `permissions[]` and use them for navigation only (UX). The server decides.

**Ownership:** every list and lookup covers **the signed-in account's own announcements only**. There is no administrator override: SUPER_ADMIN does not see ADMINISTRATOR's messages (404).

### 3.2 Requests

**Create draft** — `POST /api/v1/notifications`
```json
{
  "title": "string, required — 1..150 characters after trimming",
  "body": "string, required — 1..2000 characters after trimming",
  "level": "NORMAL | IMPORTANT (optional, default NORMAL)",
  "audience": { "type": "STAFF_FAMILIES", "family_codes": ["FAM-000012", "FAM-000057"] },
  "expires_at": "ISO-8601 or null (optional; must be in the future)",
  "client_reference": "uuid or null (optional; idempotency key, §10)"
}
```
- **Staff audiences:**
  - `{ "type": "STAFF_FAMILIES", "family_codes": ["FAM-…", …] }`: public family codes, taken from `GET /api/v1/families?search=&status=ACTIVE` (`family.view`) as the assistance nomination picker already does.
  - `{ "type": "STAFF_ALL_ACTIVE" }`: no other key; needs `announcement.send-all`.
- **Code rules:**
  - each code is a string, trimmed on the server, at most 50 characters, and matched exactly;
  - duplicates are removed;
  - 1–500 **distinct** codes, and at most 1000 entries in the list.
- **Strict parsing:** the following give `422 NOTIFICATION_AUDIENCE_INVALID`:
  - an unknown key;
  - **any internal id key** (`family_ids`, `branch_ids`, `branch_group_ids`, `clan_ids`), alone or mixed with `family_codes`;
  - an empty, blank, non-string or over-long code;
  - more than 500 distinct codes or 1000 entries;
  - a coordinator or SYSTEM type.
- **Ineligible families:** an unknown, inactive or deleted family gives `422 NOTIFICATION_AUDIENCE_FAMILY_NOT_ELIGIBLE` for the **whole** audience. The response never says which code failed, so ask the user to re-check the selection.
- **Length limits:** the HTTP layer allows up to 500 / 5000 characters before trimming, and the domain enforces **150 / 2000** after trimming. Use 150 / 2000 for the counters.
- **Expiry lead:** `expires_at` is checked "in the future" at draft time. Publishing needs it **at least 1 hour ahead** (`422 NOTIFICATION_EXPIRY_TOO_SOON`), so enforce the 1-hour minimum in the picker.

**Preview count** — `POST /api/v1/notifications/audience-preview` with `{ "audience": { … } }`.
- Uses the same side, send-all, scope and eligibility rules as a draft.
- An audience that resolves to no family returns `recipient_count: 0`, not an error. An ineligible explicitly selected family is `422 NOTIFICATION_AUDIENCE_FAMILY_NOT_ELIGIBLE`.

**Cancel** — `POST …/{id}/cancel` with an optional `{ "reason_code": "UPPER_SNAKE" }` (see **B5**; omit it for now).

**Publish / retry**: no body.

### 3.3 Response: `AnnouncementResource`

**Summary** (list items, and the start of every detail):

| Field | Type | Notes |
|---|---|---|
| `id` | uuid | |
| `notice_code` | string | `NTF-000001` |
| `level` | `NORMAL` \| `IMPORTANT` | |
| `status` | `DRAFT` \| `QUEUED` \| `PUBLISHING` \| `PUBLISHED` \| `PUBLISH_FAILED` \| `ARCHIVED` \| `CANCELLED` | §6 |
| `title` | string | |
| `audience_type` | `STAFF_FAMILIES` \| `STAFF_ALL_ACTIVE` \| `COORDINATOR_SCOPE` \| `COORDINATOR_SELECTION` | |
| `expires_at` | ISO \| null | |
| `created_at` | ISO | |
| `queued_at` | ISO \| null | the latest queueing (a retry sets a new one) |
| `published_at` | ISO \| null | set only on successful publication |
| `recipient_count_at_publication` | int \| null | historical fact, never recomputed |
| `publish_attempts` | int | worker claims so far (0–4) |
| `publish_failure_code` | string \| null | the latest failure (§6.2); kept after a retry or a cancel |
| `publish_failed_at` | ISO \| null | |
| `archived_at` | ISO \| null | |
| `cancelled_at` | ISO \| null | |
| `cancellation_reason_code` | string \| null | |

**Detail** (GET `{id}` and **every write response**) adds:

| Field | Type | Notes |
|---|---|---|
| `body` | string | |
| `audience_selection` | object **or `[]`** | How many items each stored criterion selects, e.g. `{ "family_ids": 2 }` (the stored key name with a **count**, never the ids or the codes). For `STAFF_ALL_ACTIVE` / `COORDINATOR_SCOPE` it serializes as an **empty JSON array `[]`**, so accept both. |
| `statistics_basis` | `ALL_RECIPIENTS` \| `CURRENT_COORDINATOR_SCOPE` \| `NO_CURRENT_SCOPE` \| null | null before publication |
| `statistics` | object \| null | null before publication and for `NO_CURRENT_SCOPE` |
| `available_actions` | `("PUBLISH" \| "CANCEL" \| "RETRY")[]` | what the **state** allows (§6); permission still applies |

`statistics` object (published and archived only):
```json
{ "recipients": 0, "with_account": 0, "without_account": 0,
  "viewed": 0, "not_viewed": 0,
  "acknowledged": 0, "acknowledged_late": 0, "pending_acknowledgement": 0 }
```
- **IMPORTANT only:** `acknowledged`, `acknowledged_late` and `pending_acknowledgement` are `null` for NORMAL messages.
- **No account vs unviewed:** show "no activated family account" (`without_account`) separately from "available but unviewed" (ADR-03); never present them as one figure.

**Write responses** add `"meta": { "replayed": boolean }` next to `data`.

### 3.4 Screens

| Screen | Calls | Behaviour |
|---|---|---|
| List | `GET /notifications?status=&page=&per_page=` | Newest first by `created_at`. Status filter tabs use the exact enum values. Show `notice_code`, title, level badge, status badge, `published_at` or `queued_at`, and `recipient_count_at_publication`. |
| Compose | `POST /audience-preview` (debounced, on an explicit "count" action), then `POST /notifications` | Level selector; optional expiry ≥ now + 1 h; recipient count shown **before** saving (docs/11 §22). Generate one `client_reference` per compose session (§10). |
| Preview (before publish) | `GET /{id}` | Render title and body exactly as the family will see them (§9), with the level badge and the source «إدارة Famboook». |
| Detail | `GET /{id}` | Lifecycle timeline from the timestamps; statistics only when `statistics` is non-null; buttons from `available_actions` ∩ permissions. |
| Publish | `POST /{id}/publish` | Confirmation dialog with the latest preview count. **202 means queued, not published.** Show "قيد النشر" and poll `GET /{id}` (§6.3). |
| Retry | `POST /{id}/retry` | Only when `available_actions` contains `RETRY`. Explain that a manual retry is allowed **once** (§6.2). |
| Cancel | `POST /{id}/cancel` | Confirmation dialog. Allowed for DRAFT, QUEUED and PUBLISH_FAILED. A 409 means the worker started or finished first; refetch. |

---

## 4. Coordinator announcements — `/api/v1/family/coordinator/notifications`

### 4.1 Routes

Base stack: `auth:sanctum` + `family.side`. **Operations** also need `coordinator.space` (the CURRENT scope). **History and cancel are deliberately outside it.**

| Method | Path | Inside `coordinator.space` | Permission | Throttle | Success |
|---|---|---|---|---|---|
| GET | `…/coordinator/notifications` | **no** (history) | `coordinator-announcement.view` | read | 200 |
| GET | `…/coordinator/notifications/{id}` | **no** (history) | `coordinator-announcement.view` | read | 200 |
| POST | `…/coordinator/notifications/{id}/cancel` | **no** | `coordinator-announcement.create` (+ `.send` once queued) | write | 200 |
| POST | `…/coordinator/notifications/audience-preview` | yes | `coordinator-announcement.create` | read | 200 |
| POST | `…/coordinator/notifications` | yes | `coordinator-announcement.create` | write | 201 / 200 |
| POST | `…/coordinator/notifications/{id}/publish` | yes, + `notifications.publication-ready` | `coordinator-announcement.send` | write | 202 / 200 |
| POST | `…/coordinator/notifications/{id}/retry` | yes, + `notifications.publication-ready` | `coordinator-announcement.send` | write | 202 / 200 |

- **Holder seeded in the code baseline:** the COORDINATOR role only (not seeded in Production). `announcement.*` never authorizes a coordinator, and `coordinator-announcement.*` never authorizes Staff.
- **Same shapes as §3:** request and response shapes are identical (`AnnouncementResource`), except for the audiences and statistics below.

### 4.2 Scope rules the UI must reflect

- **Coordinator audiences:**
  - `{ "type": "COORDINATOR_SCOPE" }`: every ACTIVE family of the CURRENT scope. **Usable now.**
  - `{ "type": "COORDINATOR_SELECTION", "family_codes": ["FAM-…", …] }`: selected families of the CURRENT scope, taken from `GET /api/v1/family/coordinator/families?q=`. The code rules are as in §3.2. A family outside the scope, inactive or unknown gives `422 NOTIFICATION_AUDIENCE_FAMILY_NOT_ELIGIBLE` for the whole audience, and the response never says which code failed. **Usable now** (local code baseline).
  - **Deferred:** selecting a branch, branch group or clan. Internal `branch_ids`, `branch_group_ids` and `clan_ids` (and `family_ids`) are `422 NOTIFICATION_AUDIENCE_INVALID`.
- **A Staff audience type from a coordinator is `422 NOTIFICATION_AUDIENCE_INVALID`.**
- **Scope is re-checked at publication.** If the scope shrinks or is revoked before the worker runs, the message ends as `PUBLISH_FAILED` with `SENDER_NOT_AUTHORIZED` (permanent: cancel only) or with fewer recipients.
- **After revocation** (`coordinator.space` answers `403 COORDINATOR_SPACE_UNAVAILABLE`):
  - the list, the detail and cancel of own unpublished messages **keep working**;
  - preview, draft, publish and retry are refused.

  Show the history read-only and hide the compose entry.
- **Statistics** are counted over the families of the coordinator's **current** scope only:
  - `statistics_basis: "CURRENT_COORDINATOR_SCOPE"`: the counts are for families still in scope;
  - `statistics_basis: "NO_CURRENT_SCOPE"`: `statistics` is `null`. Show "لا يوجد نطاق تنسيق حالي" and keep `recipient_count_at_publication` as the publication-time fact.
- **Losing the COORDINATOR role** (or the permission) ends everything, including history and cancel (403).
- **Source label:** families see the source «منسق العائلة». Never style coordinator messages as an administrative decision (docs/11 §22).

---

## 5. Family inbox — `/api/v1/family/notifications`

### 5.1 Routes

Stack: `auth:sanctum` + `family.side` + `can:family-portal.access` + `family.context`. The family is **always** the server-resolved context family, never a parameter.

| Method | Path | Throttle | Success |
|---|---|---|---|
| GET | `/api/v1/family/notifications` | read | 200 active inbox page; `meta.unread_count` |
| GET | `/api/v1/family/notifications/archive` | read | 200 archive page |
| GET | `/api/v1/family/notifications/{id}` | read | 200 detail (**records no view**) |
| POST | `/api/v1/family/notifications/{id}/view` | action | 200 detail + `meta.replayed` |
| POST | `/api/v1/family/notifications/{id}/acknowledge` | action | 200 detail + `meta.replayed` |

- **No mode gate on reads:** the inbox works whatever the notification mode; while nothing is published it is simply empty.
- **Who may record actions:** only the active family-side account in its own family context records views and acknowledgements. A coordinator does so only for their **own** household; Staff never.

### 5.2 Which list a message is in

| List | Rule | Order |
|---|---|---|
| Active (`/notifications`) | `PUBLISHED`, and `expires_at` null or in the future | newest `published_at` first |
| Archive (`/notifications/archive`) | `ARCHIVED`, or `PUBLISHED` with `expires_at` reached (expiry is effective at `expires_at`, before the sweep runs) | newest first |
| `meta.unread_count` | active and `viewed_at` null; archived or expired never count | — |

### 5.3 Response: `FamilyNotificationResource`

| Field | Type | Notes |
|---|---|---|
| `id` | uuid | |
| `notice_code` | string | |
| `kind` | `SYSTEM` \| `ANNOUNCEMENT` | classification only (one inbox) |
| `source` | `"إدارة Famboook"` \| `"منسق العائلة"` | display as given |
| `level` | `NORMAL` \| `IMPORTANT` | |
| `title` | string | |
| `body` | string | **detail, view and acknowledge responses only**; not in lists |
| `action_path` | string \| null | family-relative path; null for announcements (and always today, **B8**). Render as an in-app link only when non-null. The destination re-authorizes. |
| `published_at` | ISO | |
| `expires_at` | ISO \| null | |
| `expired` | boolean | server-computed: archived or past `expires_at` |
| `viewed_at` | ISO \| null | |
| `requires_acknowledgement` | boolean | true for IMPORTANT |
| `acknowledged_at` | ISO \| null | |
| `acknowledged_late` | boolean | |
| `available_actions` | `("VIEW" \| "ACKNOWLEDGE")[]` | `VIEW` while unviewed; `ACKNOWLEDGE` while IMPORTANT and unacknowledged |

### 5.4 Flows

1. **Inbox:** load `GET /notifications`. Show an unread dot where `viewed_at` is null, an IMPORTANT badge, and «بانتظار التأكيد» where `ACKNOWLEDGE` is in `available_actions`. Use the same query on the Home "recent notifications" and header badge (`meta.unread_count`), with `per_page` small.
2. **Detail screen:** `GET /notifications/{id}`. If `available_actions` contains `VIEW`, send **one** `POST /{id}/view` **after the detail has rendered**, from the detail screen only. Never send it from a list, hover, prefetch or service-worker fetch: viewing is an explicit act (ADR-05). Replace the cached item with the POST response, and invalidate the inbox query so `unread_count` drops.
3. **Acknowledge (IMPORTANT):** show a primary button «تأكيد الاطلاع» only when `ACKNOWLEDGE` is present; opening the message is **not** acknowledgement. Ask for confirmation, then `POST /{id}/acknowledge`. That call also records the view if missing. On success, show `acknowledged_at`.
4. **Expired or archived:**
   - the message is still readable;
   - show «منتهي» when `expired` is true;
   - acknowledgement is still possible, and is then recorded as late (`acknowledged_late: true`); show «تم التأكيد بعد انتهاء المدة»;
   - opening an archived message still records a first view.
5. **NORMAL** messages have no acknowledge button. A POST would be `409 NOTIFICATION_NOT_ACKNOWLEDGEABLE`.

---

## 6. Lifecycle and retry, as the UI shows it

### 6.1 States and actions

| Status | Meaning for the sender | `available_actions` | Visible to families |
|---|---|---|---|
| `DRAFT` | saved, not requested | PUBLISH, CANCEL | no |
| `QUEUED` | publication requested; waiting for the worker | CANCEL | no |
| `PUBLISHING` | the worker is writing recipients (seconds) | — | no |
| `PUBLISHED` | delivered to the family inboxes; `published_at` set | — | yes |
| `PUBLISH_FAILED` | not published, nothing visible | CANCEL, and RETRY when allowed | no |
| `ARCHIVED` | published, then archived automatically at expiry | — | yes (archive) |
| `CANCELLED` | withdrawn; never published | — | no |

- A published message can never be cancelled, edited or archived manually.
- The family sees nothing until `PUBLISHED`. Recipients and `PUBLISHED` commit together, so there is no partial publication.

### 6.2 Failures and retry (`publish_failure_code`)

| Code | Category | What the sender can do |
|---|---|---|
| `SENDER_NOT_AUTHORIZED` | permanent | cancel only (permission or scope lost) |
| `AUDIENCE_INVALID` | permanent | cancel only |
| `EXPIRED_BEFORE_PUBLICATION` | permanent | cancel only |
| `AUDIENCE_EMPTY` | manual only | retry at once (once), or cancel |
| `PUBLICATION_ERROR` | transient | wait: retried **automatically** |
| `STALE_PUBLISHING` | transient | wait: retried **automatically** |

**Transient failures:**
- The system retries automatically: up to **3** attempts in all, waiting 1 min after the 1st failure and 5 min after the 2nd.
- After the 3rd failed attempt, `RETRY` appears for **one** manual retry, for a total of at most **4** attempts.
- Before that, a manual retry is `409 NOTIFICATION_AUTOMATIC_RETRY_PENDING`.

**Other retry refusals:**
- `409 NOTIFICATION_RETRY_NOT_ALLOWED`: a permanent failure.
- `409 NOTIFICATION_RETRY_LIMIT_REACHED`: the manual retry is used up or no attempt is left.
- `422 NOTIFICATION_EXPIRY_TOO_SOON`: `expires_at` is less than 1 h away.

**Never compute retry eligibility in the client:** show RETRY only when `available_actions` contains it.

### 6.3 After publish or retry (202)

Poll `GET /{id}` with backoff (for example 3 s, then 5 s, then 10 s), stopping at `PUBLISHED`, `PUBLISH_FAILED` or `CANCELLED`, or when the user leaves the screen. A message may legitimately stay `QUEUED` for minutes:
- the worker may be busy;
- a lost hand-off is re-dispatched after 3 min;
- a stale claim is recovered after 10 min.

Show «في انتظار النشر» and never "sent".

---

## 7. Error handling matrix

| Status | `code` | Where | UI |
|---|---|---|---|
| 401 | — | any | the existing session-expired flow |
| 403 | — (no code) | `staff.side`, `family.side`, `can:*`, send-all FormRequest | "لا تملك صلاحية" and hide the action |
| 403 | `NOTIFICATION_ACTOR_NOT_ALLOWED` | domain (side, permission, coordinator context; family actor) | as above |
| 403 | `NOTIFICATION_AUDIENCE_NOT_AUTHORIZED` | audience beyond the sender's rights (send-all, scope) | field-level error on the audience |
| 403 | `COORDINATOR_SPACE_UNAVAILABLE` | coordinator operations | switch to the read-only history (§4.2) |
| 403 | `FAMILY_CONTEXT_UNAVAILABLE` | family routes | the existing "no family context" state |
| 404 | `NOTIFICATION_NOT_FOUND` | any `{id}` | «الإشعار غير موجود»; never reveal more |
| 409 | `NOTIFICATION_INVALID_TRANSITION` | publish, cancel, retry | refetch the detail; show the new state |
| 409 | `NOTIFICATION_IDEMPOTENCY_CONFLICT` | create with a reused `client_reference` | new compose session (new reference) |
| 409 | `NOTIFICATION_RETRY_NOT_ALLOWED` / `NOTIFICATION_RETRY_LIMIT_REACHED` / `NOTIFICATION_AUTOMATIC_RETRY_PENDING` | retry | show `message`; refetch |
| 409 | `NOTIFICATION_NOT_ACKNOWLEDGEABLE` | family acknowledge | refetch (should not happen with `available_actions`) |
| 422 | — with `errors` | validation (title, body, level, expires_at, client_reference, per_page, reason_code) | field errors |
| 422 | `NOTIFICATION_AUDIENCE_INVALID` / `NOTIFICATION_AUDIENCE_FAMILY_NOT_ELIGIBLE` / `NOTIFICATION_AUDIENCE_EMPTY` | create (EMPTY is never returned by preview) | audience error |
| 422 | `NOTIFICATION_EXPIRY_TOO_SOON` | publish, retry | ask the sender to cancel and redraft with a later expiry (no edit, B4) |
| 429 | Staff: none · Coordinator and Family: `TOO_MANY_REQUESTS` | any | disable the action briefly; honour `Retry-After` when present |
| 503 | `NOTIFICATION_PUBLICATION_UNAVAILABLE` | publish, retry | «نشر الإشعارات غير متاح حاليًا»; the draft stays saved (§11) |

The publication gate runs **before** the controller. A repeated `/publish` on an already-queued message therefore answers 503, not a 200 replay, while publication is unavailable.

---

## 8. Loading, empty, expired and unavailable states

| State | Staff / Coordinator | Family |
|---|---|---|
| Loading | skeleton rows; disable the action buttons | skeleton cards; the badge shows nothing until loaded (never "0" while pending) |
| Empty | «لا توجد إعلانات بعد» + compose CTA (if permitted) | «لا توجد إشعارات» (inbox) / «لا توجد إشعارات مؤرشفة» (archive) |
| Expired | `ARCHIVED` badge; `expires_at` shown | `expired: true` → «منتهي»; message moves to the archive tab |
| Unavailable (503 on publish) | inline alert on the detail; keep the DRAFT; no automatic re-submit | n/a |
| Coordinator without current scope | read-only history banner; compose hidden | n/a |
| Not found (404) | «الإشعار غير موجود» with a link back to the list | same |
| Error (5xx / network) | retry button; never optimistic state | same; the inbox failure never blocks Home |

---

## 9. Arabic RTL presentation

- **Direction:** all screens are `dir="rtl"`, using the existing layouts and the Kingfisher colour system (ADR FB-UI-01, docs/10).
- **Title and body** are plain text. Render them as text, never as HTML or Markdown (no `dangerouslySetInnerHTML`). Keep line breaks with `white-space: pre-line`, and set `dir="auto"` on the body so mixed Latin text lays out correctly.
- **Codes and numbers:** wrap `notice_code`, numbers and ISO-derived dates in `<bdi>` or `dir="ltr"` spans so `NTF-000001` and dates do not flip.
- **Dates:** format with `Intl.DateTimeFormat("ar", …)` in the device time zone, following the family home convention.
- **Badges:**
  - level: IMPORTANT «مهم», NORMAL with no badge or «عادي»;
  - source: «إدارة Famboook» and «منسق العائلة», visually distinct, and a coordinator message never styled as administrative.
- **Status labels** (suggested copy, client-side only; the API returns the enum values):
  - DRAFT «مسودة», QUEUED «في انتظار النشر», PUBLISHING «قيد النشر», PUBLISHED «منشور»;
  - PUBLISH_FAILED «تعذّر النشر», ARCHIVED «مؤرشف», CANCELLED «ملغى».
- **Counters:** show character counters for title (150) and body (2000) in Arabic-Indic or Latin digits, consistently with the app.
- **Error text:** show the server's Arabic `message` as is.

---

## 10. Idempotency and double-submit protection

| Operation | Server guarantee | Client duty |
|---|---|---|
| Create draft | `client_reference` (uuid, unique per sender): the same reference with the same content returns the existing draft (200, `meta.replayed: true`); different content is a 409 | `crypto.randomUUID()` once per compose session, reused on network retry; a new one after success or after a 409 |
| Publish | state-based: QUEUED, PUBLISHING, PUBLISHED or ARCHIVED returns 200 `replayed` with no second dispatch | disable the button while pending; on timeout, refetch the detail instead of re-posting blindly |
| Retry | state-based, as for publish; the manual allowance is counted on the server | as above |
| Cancel | cancelling your own cancelled message again is a replay (200) | as above |
| View / acknowledge | first write wins under a row lock; a repeat is a 200 replay that writes nothing | send VIEW once per detail mount; disable the acknowledge button while pending |

Use TanStack Query mutations with `retry: 0` for writes; the idempotency above makes a user-initiated repeat safe. After every write, update the detail from the response and invalidate the matching list.

---

## 11. Feature flags and safe disabled behaviour

- **Server flags** (never read by the client):
  - `NOTIFICATIONS_MODE` OFF (default) / PILOT (allow-listed sender user ids) / ON, plus the queue, worker and heartbeat readiness;
  - `NOTIFICATIONS_SYSTEM_DISCOVERY_ENABLED` and `NOTIFICATIONS_FOLLOWUPS_ENABLED`, which have no API surface yet.
- **When publication is unavailable** (OFF, a sender not on the PILOT list, or the worker down): publish and retry answer 503. Draft, preview, list, detail and cancel keep working. The UI must:
  - keep the compose and draft flow usable;
  - on 503, show the unavailable alert and keep the message as `DRAFT`, or `PUBLISH_FAILED` for a retry;
  - never hide or delete the draft, and never retry automatically.
- **Navigation is by permission only** (B2). Staff: show «الإعلانات» when `/me.permissions` includes `announcement.view`. Coordinator: per B3.
- **Family:** the inbox, badge and Home section are always present for an authorized household; while nothing is published they show the empty state. **Treat a failure as "not available", never as zero unread.**
- **Frontend kill switch:** if a client-side flag is wanted, add it as a build-time environment variable that only hides entries (default visible in development, hidden in Production until the PILOT starts). It must never be used for authorization.

---

## 12. Test checklist for the frontend phase

- **Authorization:** role-only accounts per role (SUPER_ADMIN, ADMINISTRATOR, other Staff, COORDINATOR, FAMILY_USER):
  - navigation visibility;
  - 403 handling;
  - SUPER_ADMIN cannot open an ADMINISTRATOR message (404).
- **Coordinator after revocation:** the history is still readable, cancel works, and compose is hidden.
- **Family view recording:**
  - `/view` is not called from the list or a prefetch;
  - it is called once on the detail;
  - `unread_count` updates afterwards.
- **IMPORTANT acknowledgement:**
  - there is no button for NORMAL;
  - a late acknowledgement shows the late state;
  - the archive tab shows expired messages.
- **Double-submit:** double-clicking publish, retry, cancel or acknowledge sends one effective request.
- **RTL rendering:** a long Arabic body with Latin words, `notice_code` and dates.
