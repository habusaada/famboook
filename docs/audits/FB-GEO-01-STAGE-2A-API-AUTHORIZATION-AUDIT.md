# FB-GEO-01 — Stage 2A: API Contract & Authorization Audit

**Date:** 2026-10-15
**Mode:** read-only audit, documentation only. No code, route, permission,
role mapping, schema, data or configuration was changed. Nothing ran
against any database.
**Baseline:** `CLAUDE.md`; ADR-FB-GEO-01 (§4.3–§4.4 as built locally);
Stage 1A report; Stage 1B decision register; code at `main` 8305b61 plus the
uncommitted FB-GEO-01 Stage 1C / 1C.1 work.
**Result:** Stage 2B readiness **CONDITIONAL** (§11).

All statements about existing behaviour cite the code that implements it.
Proposals are marked **PROPOSED** and are not implemented.

---

## 1. Executive summary

- The geographic reference (`gaza_locations`, 2,139 records) exists
  **locally only**. No API reads it today.
- A read-only API fits existing conventions:
  - staff routes under `/api/v1/reference/…`, inside the existing
    `auth:sanctum` + `staff.side` group;
  - later, a Family PWA mirror inside the existing family boundary, as
    already done for relationship types.
- **One authorization gap needs a decision.** SOCIAL_WORKER may correct
  residences (`residence.update`) but does not hold `reference-data.view`,
  so the plain reference-data rule would lock them out of the location
  picker.
  - The codebase already solves this exact pattern with a union check in
    the controller (`reference-data.view` **or** the feature permission:
    assessment domains, need categories, assistance categories).
  - Recommended: `reference-data.view` **or** `residence.update`. This needs
    no role-mapping change.
- **Family-side accounts can never use a Staff route.** `staff.side` refuses
  FAMILY_USER and COORDINATOR whatever they hold. Family PWA access needs
  its own route in the `/api/v1/family` group (precedent:
  `/family/change-requests/relationship-types`).
- **Review flags, import history, source codes and governance metadata stay
  out of lookup responses** through an explicit API Resource whitelist.
  Flagged records remain selectable (Stage 1B Q1).
- **No new role and no role-mapping change are needed** for the recommended
  Stage 2B scope. Residence APIs and RESIDENCE_UPDATE v1 are untouched.

---

## 2. Existing API architecture (as implemented)

| Aspect | Actual behaviour | Evidence |
|---|---|---|
| Prefix | `/api/v1` for every API route | `bootstrap/app.php` `apiPrefix: 'api/v1'` |
| Authentication | Sanctum session (stateful SPA); 401 JSON `{"message":"Unauthenticated."}` for `/api/*` | `statefulApi()`, `redirectGuestsTo(... null)`, `withExceptions` → `shouldRenderJsonWhen(api/*)` |
| Deactivated accounts | Refused on every API request | `appendToGroup('api', EnsureUserIsActive::class)` |
| Staff boundary | `Route::middleware(['auth:sanctum', 'staff.side'])`; refuses FAMILY (FAMILY_USER ± COORDINATOR), INVALID (Staff + family-side role, or COORDINATOR alone) and NONE, before any permission check: 403 `هذا الحساب غير مصرح له باستخدام واجهة الطاقم.` | `routes/api.php:271`, `EnsureStaffSideAccount` |
| Family boundary | `/api/v1/family` + `auth:sanctum` + `family.side` (mirror). Household data additionally needs `can:family-portal.access` + `family.context` (403 `FAMILY_CONTEXT_UNAVAILABLE`, `Cache-Control: no-store`) | `routes/api.php:66,109,114`, `EnsureFamilySideAccount`, `EnsureFamilyContext` |
| Coordinator boundary | `/api/v1/family/coordinator` + `coordinator.space` (own Family context, COORDINATOR, `coordinator-space.access`, an effective scope); 403 `COORDINATOR_SPACE_UNAVAILABLE` | `routes/api.php:202`, `EnsureCoordinatorSpace` |
| Staff reference routes | `GET /reference/relationship-types`, `/reference/disability-types` (`can:reference-data.view`); `/reference/assessment-domains`, `/need-categories`, `/assistance-categories` (`reference-data.view` **or** the domain `*.view`, checked in `ReferenceController` with `abort_unless($user->canAny([...]), 403)`); `/reference/clans` (`can:clan.view`) | `routes/api.php:561–583`, `ReferenceController` |
| Family-side reference routes | `GET /family/change-requests/relationship-types` (family-portal.access + family.context); coordinator `GET /family/coordinator/requests/relationship-types` and `/requests/placements` (`can:coordinator-request.submit` + `throttle:coordinator-request-read`); all answer `Cache-Control: no-store, private` | `routes/api.php:175,248–251`, `FamilyChangeRequestController::relationshipTypes`, `CoordinatorChangeRequestController` |
| Public reference endpoints | **None.** Only `/health`, staff login and public credential verification are unauthenticated | `routes/api.php:1–61` |
| Response shape | API Resources wrapped in `{"data": [...]}`; flat reference resources expose `id`, `code`, `name` (e.g. `DisabilityTypeResource`); active rows only, `orderBy('sort_order')` | `ReferenceController`, `app/Http/Resources/*TypeResource.php` |
| Pagination | Laravel paginator: `data`, `links`, `meta`; `per_page` default 15–25, max 50 (100 for nominees), validated `min:1` | `FamilyController::index`, `AssessmentController`, `ChangeRequestIndexRequest` |
| Search | Parameter `search`, trimmed, `max:100`, case-insensitive "contains" via `RegistrySearch::contains` (ILIKE on PostgreSQL, `%`/`_`/`!` escaped); **no Arabic normalization** (PDD-003 open). Nominee search requires `min:2` | `RegistrySearch`, `FamilyController::index`, `AssistanceNomineeController:117` |
| Errors | 401 / 403 `{"message": …}` (some with `code`); 404 for missing models; 422 `{"message", "errors": {field: [...]}}` | `withExceptions`, middleware above |
| Rate limiting | No default API limiter; named per-route limiters (`coordinator-read`, `family-change-request-submit`, …) in `AppServiceProvider` | `routes/api.php`, `AppServiceProvider:198+` |
| Caching | Staff reference endpoints set no `Cache-Control`; family and coordinator reference endpoints set `no-store, private` | controllers above |
| Existing tests | `tests/Feature/Reference/ReferenceApiTest.php`: listing, inactive excluded, 401 unauthenticated, 403 without permission | file |

---

## 3. Existing permission and authorization matrix

Role grants are copied from `RolePermissionSeeder::ROLE_PERMISSIONS`. They
are relevant to a geographic lookup but are **not** changed by this audit.

| Permission | SUPER_ADMIN | ADMINISTRATOR | DATA_ENTRY | REVIEWER | SOCIAL_WORKER | REPORTS_VIEWER | FAMILY_USER | COORDINATOR |
|---|---|---|---|---|---|---|---|---|
| `reference-data.view` | ✓ | ✓ | ✓ | – | – | – | – | – |
| `residence.update` | ✓ | ✓ | ✓ | – | ✓ | – | – | – |
| `residence.view` | – (declared, unassigned) | – | – | – | – | – | – | – |
| `family.create` | ✓ | ✓ | ✓ | – | – | – | – | – |
| `family.view` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | – | – |
| `clan.view` | ✓ | ✓ | ✓ | – | ✓ | – | – | – |
| `report.view` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | – | – |
| `family-portal.access` | – | – | – | – | – | – | ✓ | – |
| `change-request.submit` | – | – | – | – | – | – | ✓ | – |
| `coordinator-request.submit` | – | – | – | – | – | – | – | ✓ |
| API side | Staff | Staff | Staff | Staff | Staff | Staff | Family | Family (a coordinator account also holds FAMILY_USER, CO-02) |

Facts:
- `residence.view` is declared in the catalog but **assigned to no role**.
- REVIEWER and REPORTS_VIEWER hold neither `reference-data.view` nor
  `residence.update`.
- A Coordinator reaches its own household through `/family` (FAMILY_USER)
  and other Families only through `coordinator.space`.

### 3.1 Geographic read access by user type

Access for each user type under the **recommended** rule (§5.2) and
endpoint placement. Nothing here is implemented.

| User type | Needs the lookup for | Staff route `reference-data.view` only | **Recommended:** `reference-data.view` OR `residence.update` | Family route (later) |
|---|---|---|---|---|
| SUPER_ADMIN | registration, correction | ✓ | ✓ | – (staff.side) |
| ADMINISTRATOR | registration, correction | ✓ | ✓ | – |
| DATA_ENTRY | registration, correction | ✓ | ✓ | – |
| SOCIAL_WORKER | residence correction (holds `residence.update`) | ✗ **gap** | ✓ | – |
| REVIEWER | reviewing RESIDENCE_UPDATE v2 (Stage 5): names are rendered server-side in the request presentation, so no lookup is needed | ✗ | ✗ (not needed in 2B) | – |
| REPORTS_VIEWER | geographic report filters (Stage 6) | ✗ | ✗ (not needed in 2B; Stage 6 can add `report.view` to the union) | – |
| FAMILY_USER | RESIDENCE_UPDATE v2 form (Stage 5) | ✗ (staff.side refuses) | ✗ (staff.side refuses) | ✓ via `family-portal.access` + `family.context` |
| COORDINATOR | not needed: REGISTER_FAMILY captures the original residence as text (CO-02 D10); changing that is a workflow change | ✗ | ✗ | own household only, as FAMILY_USER |

---

## 4. Geographic data exposure rules

Source: migrations `2026_10_22_090000/090001/090002`, `GazaLocation`,
`GazaLocationLevel`, `GazaLocationReviewFlag`, `GazaGeographyImportRun`.

| Field / data | Expose in lookups? | Rule |
|---|---|---|
| `gaza_locations.id` (deterministic UUIDv5) | **Yes**: the API identifier | Stable across environments and re-imports (D-7) |
| `level` | Yes | GOVERNORATE / CITY / AREA / NEIGHBORHOOD / LANDMARK |
| `name` | Yes, as `GazaLocation::displayName()` (edge-trimmed) | The stored source value is never altered (G5). Inner double spaces stay; collapsing them is pending (D-2) |
| `parent_id` | Yes | Lets clients walk up the tree; the full path comes from the show endpoint |
| `is_active` | Only on the show endpoint | Lists, children and search return active records only |
| `landmark_type_name` | **Decision API-D4** | Helps tell same-named landmarks apart, but 5 flagged types are non-landmark categories (e.g. «قتل», «مرض», Stage 1A W2) |
| `source_code`, `landmark_type_code` | **No** (recommended, API-D4) | Ministry codes are governance metadata; clients use the UUID |
| `parent_level`, `sort_order`, `created_at`, `updated_at` | No | Internal |
| `gaza_location_review_flags` (any field) | **Never** in lookups | Internal review data; flags never filter or hide a record (Q1) |
| `gaza_geography_import_runs` (any field) | **Never** in lookups | Import governance, including checksums and error summaries |
| Source workbook path, checksum, authority details | Never | Governance |
| Family, person or residence data | Never | Endpoints do not join or read any family table |

Implementation rules for Stage 2B:
- One `GazaLocationResource` with an explicit field whitelist; never return
  a raw model or `toArray()`.
- No `with('reviewFlags')`, and no relation to import runs.

---

## 5. Proposed endpoint contract (PROPOSED, not implemented)

### 5.1 Staff endpoints (Stage 2B scope)

All of these sit inside the existing `auth:sanctum` + `staff.side` group
and are read-only (GET).

| # | Method and route | Purpose | Result |
|---|---|---|---|
| E1 | `GET /api/v1/reference/gaza-locations` | Top level: the governorates | Active governorates, unpaginated (5) |
| E2 | `GET /api/v1/reference/gaza-locations/{location}/children` | Children of one node (any level except LANDMARK) | Active children, unpaginated (largest observed: 73 landmarks in one neighborhood; hard cap §7) |
| E3 | `GET /api/v1/reference/gaza-locations/search` | Search by Arabic name | Paginated active matches, each with its path |
| E4 | `GET /api/v1/reference/gaza-locations/{location}` | One node with its complete ancestor path | Node (active or inactive) plus `path` from governorate down |

Route rules:
- `/search` is registered **before** `/{location}`.
- `{location}` is constrained with `whereUuid('location')`. Without it, a
  malformed id would reach PostgreSQL's `uuid` column and fail with SQLSTATE
  22P02 (a 500) instead of a 404.
- E2 on a LANDMARK returns an empty list. E2 on an inactive node returns 404,
  consistent with `ClanStructureController::groups`.

### 5.2 Authorization (staff)

**Recommended (decision API-D1):** check in the controller with
`abort_unless($request->user()->canAny(['reference-data.view',
'residence.update']), 403)`, exactly as `ReferenceController` does for
assessment domains, need categories and assistance categories.
- Covers SUPER_ADMIN, ADMINISTRATOR, DATA_ENTRY and SOCIAL_WORKER.
- No role or permission mapping changes.
- REVIEWER and REPORTS_VIEWER are refused until a later stage needs them.

Alternatives (§10):
- (b) Add `family.view` to the union, so every Staff role can read.
- (c) Grant `reference-data.view` to SOCIAL_WORKER. This is a role-mapping
  change, against this stage's constraints.

### 5.3 Query parameters and validation

| Endpoint | Parameter | Rule |
|---|---|---|
| E1 | none | — |
| E2 | none | — |
| E3 | `search` | **required**, string, trimmed, `min:2`, `max:100` (RegistrySearch::MAX_TERM) |
| E3 | `level` | optional, `Rule::enum(GazaLocationLevel::class)` |
| E3 | `within` | optional UUID of an active node; restricts results to its descendants (decision API-D7, may be deferred) |
| E3 | `page` | optional, integer `min:1` |
| E3 | `per_page` | optional, integer `min:1`, `max:50`; default 20 |
| E4 | none | — |

Unknown parameters are ignored, as elsewhere. No parameter reads family,
person or residence data.

### 5.4 Sorting

Proposed (decision API-D6):
- Governorates by numeric source code (41 → 45: North Gaza, Gaza, Middle,
  Khan Younis, Rafah, i.e. north to south). The code is used only for
  ordering, never exposed.
- Children by `sort_order` (NULL last), then the trimmed name, then id
  (stable tie-break).
- Search results by level (governorate first), then name, then id.

### 5.5 Response shape (`GazaLocationResource`)

```json
{
  "id": "uuid",
  "level": "NEIGHBORHOOD",
  "name": "منطقة رقم 7",
  "parent_id": "uuid | null",
  "has_children": true,
  "landmark_type": "مصلى | null"
}
```

- `has_children` uses a `withExists` query. It lets the UI show a further
  step or stop, so landmarks stay optional.
- `landmark_type` is present only if API-D4 approves it; otherwise the key is
  omitted.
- E4 adds `is_active` and `path`: ancestors from governorate to parent, each
  `{id, level, name}`.
- E3 items add `path` so homonyms are shown with their parents (Stage 1A
  §7.3: e.g. «الهدى» exists under two cities).

### 5.6 Errors

| Case | Status | Body |
|---|---|---|
| Not authenticated | 401 | `{"message":"Unauthenticated."}` |
| Family-side or invalid account on a staff route | 403 | `{"message":"هذا الحساب غير مصرح له باستخدام واجهة الطاقم."}` |
| Staff user without the union permissions | 403 | `{"message":"This action is unauthorized."}` (Laravel default, as for `abort_unless(..., 403)` today) |
| Malformed UUID | 404 | (`whereUuid`) |
| Unknown id; inactive node on E2 | 404 | `{"message": …}` |
| Invalid `search` / `level` / `per_page` | 422 | `{"message", "errors": {…}}` |

### 5.7 Family PWA endpoints (contract only, Stage 5)

Read-only mirrors of E1–E4 inside the existing family group:
- Middleware: `auth:sanctum` + `family.side` + `can:family-portal.access` +
  `family.context`.
- Proposed paths follow the relationship-types precedent of the change
  request form:
  - `GET /api/v1/family/change-requests/gaza-locations`
  - `GET /api/v1/family/change-requests/gaza-locations/{location}/children`
  - `GET /api/v1/family/change-requests/gaza-locations/search`
  - `GET /api/v1/family/change-requests/gaza-locations/{location}`
- Responses use the same resource, with `Cache-Control: no-store, private`
  like the existing family reference routes.
- This needs no permission change (`family-portal.access` is FAMILY_USER's).
- **Recommended: implement them with RESIDENCE_UPDATE v2 (Stage 5)**, when a
  family form first needs them (decision API-D2).

### 5.8 Coordinators

No endpoint is proposed. Coordinator REGISTER_FAMILY keeps the text
original residence (CO-02 D10); adding a structured picker would change an
approved workflow (ADR D12) and needs its own decision. A future endpoint
would follow `/family/coordinator/requests/placements`
(`can:coordinator-request.submit` + `throttle:coordinator-request-read`).

---

## 6. Example requests and responses (illustrative; ids shortened)

**E1: governorates**

```http
GET /api/v1/reference/gaza-locations
```
```json
{
  "data": [
    {"id": "a1…", "level": "GOVERNORATE", "name": "شمال غزة", "parent_id": null, "has_children": true},
    {"id": "a2…", "level": "GOVERNORATE", "name": "غزة", "parent_id": null, "has_children": true},
    {"id": "a3…", "level": "GOVERNORATE", "name": "الوسطى", "parent_id": null, "has_children": true},
    {"id": "a4…", "level": "GOVERNORATE", "name": "خان يونس", "parent_id": null, "has_children": true},
    {"id": "a5…", "level": "GOVERNORATE", "name": "رفح", "parent_id": null, "has_children": true}
  ]
}
```

**E2: children of a city** (Khan Younis city → its single area)

```http
GET /api/v1/reference/gaza-locations/c7…/children
```
```json
{"data": [{"id": "g14…", "level": "AREA", "name": "خان يونس", "parent_id": "c7…", "has_children": true}]}
```

**E3: search**

```http
GET /api/v1/reference/gaza-locations/search?search=الهدى&level=NEIGHBORHOOD&per_page=20
```
```json
{
  "data": [
    {"id": "n240…", "level": "NEIGHBORHOOD", "name": "الهدى", "parent_id": "g12…", "has_children": true,
     "path": [{"id": "a2…", "level": "GOVERNORATE", "name": "غزة"}, {"id": "c215…", "level": "CITY", "name": "المغراقة"}, {"id": "g12…", "level": "AREA", "name": "المغراقة"}]},
    {"id": "n244…", "level": "NEIGHBORHOOD", "name": "الهدى", "parent_id": "g11…", "has_children": true,
     "path": [{"id": "a2…", "level": "GOVERNORATE", "name": "غزة"}, {"id": "c253…", "level": "CITY", "name": "وادي غزة"}, {"id": "g11…", "level": "AREA", "name": "وادي غزة"}]}
  ],
  "links": {"first": "…", "last": "…", "prev": null, "next": null},
  "meta": {"current_page": 1, "per_page": 20, "total": 2, "last_page": 1, "from": 1, "to": 2, "path": "…", "links": []}
}
```

**E4: one node with its hierarchy** (a flagged neighborhood: no flag data is
exposed)

```http
GET /api/v1/reference/gaza-locations/n558…
```
```json
{
  "data": {
    "id": "n558…", "level": "NEIGHBORHOOD", "name": "التحرير - الاقليمي رفح", "parent_id": "g14…",
    "has_children": true, "is_active": true,
    "path": [
      {"id": "a4…", "level": "GOVERNORATE", "name": "خان يونس"},
      {"id": "c217…", "level": "CITY", "name": "خان يونس"},
      {"id": "g14…", "level": "AREA", "name": "خان يونس"}
    ]
  }
}
```

**Errors**

```http
GET /api/v1/reference/gaza-locations/search?search=ا        → 422 {"message":"…","errors":{"search":["…"]}}
GET /api/v1/reference/gaza-locations/not-a-uuid           → 404
GET /api/v1/reference/gaza-locations   (REPORTS_VIEWER)   → 403 {"message":"This action is unauthorized."}
GET /api/v1/reference/gaza-locations   (FAMILY_USER)      → 403 {"message":"هذا الحساب غير مصرح له باستخدام واجهة الطاقم."}
```

---

## 7. Search, pagination and caching recommendations

**Search**
- Reuse `RegistrySearch::contains($q, 'name', $term)`: case-insensitive
  "contains" with escaped wildcards. That is the established behaviour.
- Edge whitespace in source names does not affect matches, because
  "contains" ignores the surrounding spaces.
- **Hamza and alef / ya / ta-marbuta variants are not matched**: «احمد» does
  not find «أحمد», and 67 landmark variant groups exist (Stage 1A §7.4).
  This follows PDD-003 (open). A normalized search column would be a schema
  change needing approval (API-D5).
- Volume is small (2,139 rows). A sequential scan for `ILIKE '%…%'` is
  acceptable, and no trigram index is needed now.

**Pagination and limits**
- E3 is paginated (`per_page` default 20, max 50, the established maximum).
- E1 and E2 are unpaginated, because the data bounds them (5 governorates;
  at most 73 children observed).
- A defensive hard cap of 500 rows on E2 protects against a future source
  revision; exceeding it is a server-side configuration error, not silent
  truncation.

**Rate limiting**
- No staff reference endpoint is throttled today; none is proposed for E1,
  E2 or E4.
- For E3 a per-user limiter is optional (API-D8); the existing
  `coordinator-read` pattern shows the shape.

**Caching**
- The reference changes only through the import command.
- Recommended for Stage 2B: follow current conventions (no explicit
  `Cache-Control` on staff reference endpoints; `no-store, private` on
  family endpoints), with client-side caching through TanStack Query.
- No server-side cache is needed at this volume.
- A later ETag could derive from the latest SUCCEEDED
  `gaza_geography_import_runs.finished_at` plus any deactivation.
  Optional, not proposed for 2B.

---

## 8. Compatibility considerations

| Requirement | How the proposal satisfies it |
|---|---|
| Existing residence APIs unchanged | No change to `PATCH /families/{family}/residence`, `FamilyDetailResource` or `FamilyHouseholdProfileResource`; residence linkage is Stage 3 |
| RESIDENCE_UPDATE v1 unaffected | No handler, payload, route or permission of the Change Request engine changes; family endpoints come only with v2 (Stage 5) |
| No family or person data exposed | Endpoints read only `gaza_locations`; no join to any family table |
| No new RBAC roles | None; the recommended rule only combines existing permissions in a controller check (existing pattern) |
| Supports the staff UI and the Family PWA | E1–E4 give a cascading picker (governorate → … → optional landmark), search with paths, and display of a stored selection. The family mirrors use the same resource |
| Flagged records selectable | No query filters on flags; flagged records are active (Q1) |
| Review flags not exposed | Resource whitelist; no relation loaded (§4) |
| Stable UUID identifiers | `gaza_locations.id` (deterministic UUIDv5) is the only identifier exposed |

---

## 9. Security risks

| # | Risk | Severity | Mitigation |
|---|---|---|---|
| S1 | Malformed UUID reaches PostgreSQL (22P02) → 500 and a noisy log | Medium | `whereUuid('location')` on every route (§5.1) |
| S2 | A future change returns the model or `toArray()`, leaking flags, source codes or timestamps | Medium | Explicit `GazaLocationResource` whitelist; tests asserting the exact key set and that no flag or import field appears |
| S3 | Family-side access through a staff route | Low | Already prevented by `staff.side` (fails closed); test it |
| S4 | SOCIAL_WORKER blocked from correcting residences (availability, not security) | Medium | API-D1 union rule |
| S5 | Over-broad read access if `family.view` is used | Low | Reference geography is not personal data; still, the narrower recommended rule is preferred |
| S6 | Search abuse (short terms, large pages) | Low | `min:2`, `max:100`, `per_page ≤ 50`, escaped wildcards; optional limiter (API-D8) |
| S7 | Unusual landmark types (e.g. «قتل», «مرض») shown to families | Low–Medium (reputational) | API-D4: omit `landmark_type` for family responses, or overall |
| S8 | Landmark names that are personal names (schools and mosques named after people) | Low | Public facility names, not personal data (Stage 1A I8) |
| S9 | Shared caches storing responses | Low | Family `no-store, private` (existing); staff sessions are authenticated |
| S10 | An inactive node returned by E4 used for a new selection | Low | E4 exposes `is_active`; Stage 3 validation accepts only active nodes for new selections (ADR §13.3) |

---

## 10. Decisions requiring approval

| # | Decision | Recommendation | Alternatives |
|---|---|---|---|
| API-D1 | Staff read authorization | `reference-data.view` **OR** `residence.update`, checked in the controller (existing pattern); no mapping change | (b) add `family.view` (all Staff roles); (c) grant `reference-data.view` to SOCIAL_WORKER (role-mapping change) |
| API-D2 | When to build the Family PWA mirrors | With RESIDENCE_UPDATE v2 (Stage 5), under `/family/change-requests/gaza-locations…` | Build in 2B, unused until Stage 5 |
| API-D3 | Coordinator access | None now (REGISTER_FAMILY keeps text, CO-02 D10) | A coordinator-scoped mirror with a structured original residence (a workflow change) |
| API-D4 | Exposure of `landmark_type` and `source_code` | Expose `landmark_type` (name only) on staff responses only; never expose `source_code` or `landmark_type_code` — *amended 2026-10-17 by ADR FB-GEO-03: families see the type name too, except while an UNUSUAL_LANDMARK_TYPE flag is OPEN or CORRECTION_REQUIRED* | Expose the type to families too; or omit it everywhere |
| API-D5 | Arabic search normalization | Accept PDD-003 behaviour (no normalization) for 2B | A normalized search column (schema change) |
| API-D6 | Sorting | Governorates north → south by source code; others by name | Name only everywhere |
| API-D7 | `within` filter on search | Include (cheap ancestor filter) | Defer |
| API-D8 | Search rate limit | None for staff (consistent with current staff reference routes) | Per-user limiter |
| API-D9 | Staff HTTP caching | No explicit header (current convention); client cache | `Cache-Control: private, max-age=300` |
| API-D10 | REVIEWER / REPORTS_VIEWER lookup | Not in 2B; revisit at Stage 5 (review presentation) / Stage 6 (`report.view` in the union) | Include now via `family.view` |

---

## 11. Stage 2B readiness: **CONDITIONAL**

The codebase supports the proposed contract without new roles, mapping
changes or schema changes. Stage 2B can start once these are approved:

1. **API-D1**, the authorization rule. It is the only point touching RBAC
   semantics, and it needs an explicit decision even though no mapping
   changes.
2. **API-D4**, the field exposure (landmark type, source codes).

The remaining decisions have recommended defaults that match existing
conventions; approving the recommendations as a set would suffice.

Recommended Stage 2B scope:
- E1–E4 (staff only), `GazaLocationResource`, a `whereUuid` constraint and
  controller authorization.
- Tests: authentication and side boundaries, the union rule per role,
  validation, the exact key set (no flag, import or source-code fields),
  flagged records present, inactive handling, paths and homonyms, sorting,
  pagination, and the PostgreSQL malformed-UUID case.
- Documentation updates: docs/06 note, AUTH-ADR entry, ADR §8.

Not in scope: the family mirrors (Stage 5), residence linkage (Stage 3), UI
(Stage 4).

---

## 12. Stage 2B decision status (2026-10-15)

Stage 2B was implemented **locally** (ADR-FB-GEO-01 §8.1). Production is not
deployed.

| Decision | Status | As implemented |
|---|---|---|
| API-D1 Authorization | **APPROVED, implemented** | `canAny(['reference-data.view', 'residence.update'])` in `GazaLocationController`; REVIEWER and REPORTS_VIEWER get 403; family-side accounts are refused by `staff.side` |
| API-D4 Exposure | **APPROVED, implemented** | `id`, `level`, `name` (trimmed), `parent_id`, `has_children`, `landmark_type` (Staff only), `path` (search and details); no source or type codes |
| API-D2 Family mirrors | **Deferred** to Stage 5, as recommended | **Built 2026-10-16** (governorates, children, details; no search, no `landmark_type`): ADR §8.5 |
| API-D3 Coordinator access | **None**, as recommended | Not built |
| API-D5 Arabic normalization | **None** (PDD-003), as recommended | Tested: «الهدي» does not match «الهدى» |
| API-D6 Sorting | **As recommended** | Governorates by official code 41 → 45; children by trimmed name, then id; search by level, then name, then id |
| API-D7 Search scope | **Implemented** as `parent_id` (see deviations) | All descendants, not only immediate children |
| API-D8 Rate limit | **None**, as recommended | — |
| API-D9 Caching | **None added**, as recommended | No `Cache-Control` header (existing staff convention) |
| API-D10 REVIEWER / REPORTS_VIEWER | **Not included**, as recommended | 403 |

**Deviations from the §5 proposal** (from the Stage 2B instruction, which
takes precedence):

| §5 proposal | Implemented |
|---|---|
| `GET /reference/gaza-locations` (governorates) | `GET /gaza-locations/governorates` |
| `GET /reference/gaza-locations/{location}/children` | `GET /gaza-locations/{location}/children` |
| `GET /reference/gaza-locations/search?search=&within=` | `GET /gaza-locations/search?q=&parent_id=` |
| `GET /reference/gaza-locations/{location}` | `GET /gaza-locations/{location}` |
| Details adds `is_active` | **Not exposed.** The approved Stage 2B field list does not include it (open item below) |
| `landmark_type` key omitted when not applicable | Always present, `null` for non-landmarks (one consistent shape) |
| Hard cap on children | Kept (500); exceeding it throws, never truncates |

**Open items:**
- **Expose `is_active` on the details endpoint?** An inactive location is
  retrievable by id, but the response does not say it is inactive. A client
  displaying a stored selection cannot tell, and Stage 3 validation will
  refuse inactive nodes for new selections. Recommend adding it at Stage 3.
  **Resolved in Stage 3.2 (2026-10-15, API-D4 amended):** the details
  endpoint now carries `is_active` for the node and each `path` item.
  Governorates, children and search still never carry it.
  `GazaLocationApiTest` was updated deliberately: the exposure test exempts
  the details response, and the details tests assert the field.
- **Name-ordering collation:** ordering follows each database's collation
  (`C` on the test database, `English_United States.1252` locally). It is
  deterministic per database but may differ between environments. A fixed
  collation would be a later decision.

---

## Appendix: evidence index

- `backend/routes/api.php` (lines 54–61 public, 66–198 family, 202–263 coordinator, 271+ staff, 561–583 reference)
- `backend/bootstrap/app.php` (prefix, statefulApi, aliases, exceptions)
- `backend/app/Http/Middleware/EnsureStaffSideAccount.php`, `EnsureFamilySideAccount.php`, `EnsureFamilyContext.php`, `EnsureCoordinatorSpace.php`
- `backend/app/Http/Controllers/Api/V1/ReferenceController.php`, `ClanStructureController.php`, `Family/FamilyChangeRequestController.php`, `Family/CoordinatorChangeRequestController.php`, `FamilyController.php`
- `backend/app/Support/RegistrySearch.php`
- `backend/database/seeders/RolePermissionSeeder.php` (`ROLES`, `ROLE_PERMISSIONS`)
- `backend/app/Models/GazaLocation.php`, `GazaLocationReviewFlag.php`, `GazaGeographyImportRun.php`; migrations `2026_10_22_090000/090001/090002`
- `backend/tests/Feature/Reference/ReferenceApiTest.php`
