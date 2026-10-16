# FB-GEO-01 — Stage 4C: Staff Geography Integration Verification

**Date:** 2026-10-16
**Mode:** local verification and regression testing. Stage 3.2 + 4A + 4B.
No Production access, no deployment, no commit, no push. The development
database was not touched (§2).
**Baseline:**
- `CLAUDE.md` and ADR-FB-GEO-01 (§4.2, §8.1–§8.3);
- Stage 3.1 linkage audit and Stage 3A selector design;
- the working tree at commit `8305b61` plus the uncommitted FB-GEO-01
  stages.

**Verdict:** **CONDITIONAL** (§13).

---

## 1. Executive summary

The geographic residence slice works end to end against the real Laravel
API, a real PostgreSQL database and a real browser:

- **Real API (51 of 51 checks):**
  - a full sweep of all 2,139 active locations through `/children` found
    every level listing only the direct children of its parent;
  - registration and correction persist UUIDs and read them back;
  - an omitted field preserves its value, an explicit `null` clears it, and
    an inactive saved UUID is preserved;
  - an inactive new selection, an invalid UUID and an unknown UUID all
    return 422.
- **Real browser (25 of 25 checks):** headless Microsoft Edge at desktop,
  tablet and mobile widths. Registration through the UI, read-back on the
  residence tab, and every cancellation and confirmation path, checked
  against the requests the page actually sent.
- **Defects found and fixed (Stage 3.2–4B):**
  - **D-1 (Stage 4A):** geography Select triggers rendered **32px** tall
    (not touch-sized, and shorter than the inputs beside them);
  - **D-2 (Stage 4A/4B tests):** geography tests timed out under load.
- **Pre-existing finding, not fixed (outside Stage 3.2–4B):**
  - **PX-1:** reopening a residence correction dialog before the family
    refetch completes resends stale **text** fields, silently overwriting
    a correction made seconds earlier;
  - geographic references are not affected.
- **Observation:** an active location under an inactive ancestor is
  accepted (OBS-1). This state is unreachable today (nothing deactivates
  locations), but it needs a decision before any deactivation workflow.
- **Regression:**
  - frontend: typecheck clean; lint 0 errors (1 existing warning);
    selector and Stage 4B suites pass; in the full suite only existing
    Family PWA tests flaked on timing (different ones per run; all pass in
    isolation; §8);
  - backend SQLite: 2,713 tests, 0 failures;
  - backend PostgreSQL: Geography 73 passed; the full suite shows only the
    10 known legacy fixture failures (docs/11 FU-12), none in geography,
    residence, registration or correction code.

---

## 2. Environment and database

| Item | Value |
|---|---|
| OS | Windows 11 Pro (developer workstation) |
| Backend | PHP 8.3.33, Laravel 12.69.2, served by the PHP built-in server on `localhost:8010` |
| Frontend | Node 26.10.0, Next.js 16.3.6 (`next dev`) on `localhost:3010`, `NEXT_PUBLIC_API_URL=http://localhost:8010` |
| Browser | Microsoft Edge 155.0.4283.45, headless, driven over the DevTools protocol by a scratch script (no new dependency); a throwaway profile, never the user's |
| Database | The **separate test instance** `famboook-pg-test` (PostgreSQL 18.6, `127.0.0.1:5433`, localhost only, docs/08 §16a) |
| Verification database | **`famboook_stage4c_test`**: created for this stage on the test instance; all migrations applied; reference seeders; official workbook imported (2,139 locations, 228 flags, checksum verified) |
| PHPUnit PostgreSQL | `phpunit.pgsql.xml` → `famboook_test` on the same test instance |
| Data | Synthetic only: role users `*@stage4c.test` (random password kept outside the repository), synthetic families and National IDs |

**Isolation:**
- Every process received `DB_HOST=127.0.0.1 DB_PORT=5433
  DB_DATABASE=famboook_stage4c_test` through its environment. `.env` was
  not modified.
- The API script asserts `current_database()` and the server port before
  any SQL.
- The development database (`5432/famboook`) was never connected to, and
  no migration ran on it. The full migration set ran only in the new
  disposable database.
- The test instance stopped when the workstation rebooted during the
  stage. It was restarted with its own `pg_ctl` (port 5433), as before.

---

## 3. Real API verification

All requests went through Sanctum session authentication
(`/sanctum/csrf-cookie` → `/api/v1/auth/login`, Origin
`localhost:3010`).

| # | Check | Result |
|---|---|---|
| A1 | Unauthenticated `GET /gaza-locations/governorates` | 401 ✓ |
| A2–A3 | DATA_ENTRY login, `/api/v1/me` | 200 ✓ |
| C1 | Governorate listing | 5 governorates, `parent_id` null ✓ |
| C2 | **Full sweep** through `/{uuid}/children` only: 293 requests reached all 2,139 active locations (5 governorates, 26 cities, 28 areas, 234 neighborhoods, 1,846 landmarks). Every child's `parent_id` equals the requested parent, its level is the next level, `has_children` is consistent, and no list item exposes `is_active`, codes or flags | 0 violations ✓ |
| C3–C6 | City, area, neighborhood and landmark loading by parent UUID | ✓ |
| D1 | Details of a landmark: full path governorate → neighborhood, ids equal the chain | ✓ |
| D2 | `is_active` on the details node and every path item | ✓ |
| S1 | Staff family details: `original_location` / `current_location` summaries with full path and `is_active`; no codes or flags | ✓ |
| I1–I4 | Inactive location: details `is_active=false`; absent from its parent's list; its `/children` → 404; a descendant's path flags the inactive ancestor | ✓ |
| I5 | Family details show the inactive saved location with `is_active=false` | ✓ |

---

## 4. Residence persistence matrix (real API, read after write)

Every row was written through the API and re-read with
`GET /api/v1/families/{code}`. In every row the eight text fields came back
unchanged (or, for a text correction, exactly as corrected).

| # | Case | Result |
|---|---|---|
| P1 | Registration, both NULL | 201; null / null ✓ |
| P2 | Registration, original only (AREA) | 201; UUID / null ✓ |
| P3 | Registration, current only (LANDMARK) | 201; null / UUID ✓ |
| P4 | Registration, both | 201; both UUIDs ✓ |
| P5 | Registration, keys omitted (legacy client) | 201; null / null ✓ |
| U1 | Update only original | 200; current untouched ✓ |
| U2 | Update only current | 200; original untouched ✓ |
| U3 | Omitted keys (text-only correction) | 200; both preserved; text corrected ✓ |
| U4 | Explicit clear (`null`) | 200; that reference only, summary null ✓ |
| U5 | Displacement status → NOT_DISPLACED | `current_location_id` kept (ADR §5); displacement text cleared ✓ |
| I6 | Text-only correction with an **inactive** saved UUID | preserved ✓ |
| I7 | Re-sending the unchanged inactive UUID | 200, kept ✓ |
| I8 | Correction: inactive **new** selection | 422, stored value kept ✓ |
| I9 | Registration: inactive selection | 422 ✓ |
| V1 / V3 | Invalid UUID (registration / correction) | 422 ✓ |
| V2 / V4 | Unknown UUID (registration / correction) | 422 ✓ |
| V5 | Rejected corrections leave stored values unchanged | ✓ |
| B7 | **Registration through the browser UI**, both selectors (longest names in the dataset) | Both UUIDs and the typed text persisted ✓ |

**OBS-1:** registration with an **active** location whose ancestor is
inactive returns **201**.
- The approved rule (Stage 3.1 D-7, `SelectableGazaLocation` and
  `GazaLocationSelection::assertSelectable`) checks the location's own
  `is_active` only.
- The state is unreachable today: the import never deactivates locations
  (`ImportGazaGeographyAction`). It was produced here only by a direct
  update in the disposable database.
- The UI cannot reach such a location either, because children of an
  inactive parent return 404.
- **Decision needed** before any deactivation workflow: should deactivation
  cascade, or should validation check ancestors? This is a business-rule
  change, so it was not made here.

---

## 5. Browser and visual verification

Headless Edge, real UI, real API. Viewports: desktop 1440×900, tablet
820×1180 (touch), mobile 390×844 (touch). Screenshots were reviewed by eye,
and geometry was measured in the page.

| Check | Desktop | Tablet | Mobile |
|---|---|---|---|
| Horizontal overflow | none | none | none |
| Overlapping labels or legends | 0 | 0 | 0 |
| Trigger height (after D-1) | 40px | 44px | 44px |
| Trigger width in the residence blocks | 153px (3 columns inside a half-width block) | 340px | 298px (one column) |
| Open Select menu inside the viewport, RTL | ✓ (menu sized to its options, about 400px, scrollable) | ✓ | ✓ (352px) |
| Residence tab: both full paths read back, long names wrap | ✓ | ✓ | ✓ |

- **Theme:** `--brand-900` = `#123c3a`, `<html dir="rtl" lang="ar">`, IBM
  Plex Sans Arabic. There is no diff in `globals.css` or the theme files.
- **Every Radix Select** renders with `dir="rtl"`, and its options use RTL
  direction.
- **Long Arabic names:**
  - the longest neighborhood (51 characters) and landmark (50 characters)
    were selected through the UI;
  - in the 153px desktop triggers the name truncates on one line;
  - the full name is shown in the open menu and in the path line below,
    which wraps onto further lines;
  - no clipping beyond the trigger's own truncation.
- **Half-width desktop grid:** usable. Three columns of 153px hold short
  names fully and truncate long ones. Option: a two-column grid at `xl`, or
  a `title` on the trigger (UX enhancement, not a defect; not done).
- **States:**
  - loading: «جارٍ التحميل…», busy and disabled, under 2.5s latency;
  - error (children blocked at the network layer): «تعذّر تحميل قائمة
    المدينة.» with «إعادة المحاولة»;
  - the retry recovers once the network works;
  - inactive: «غير مفعّلة» on the residence tab and in the dialog.
- **Requests:** every API request went to `localhost:8010`, none to the
  default `:8000`. There were 0 console errors.

---

## 6. Cancellation and React Hook Form behaviour

Real dialogs on the residence tab. The PATCH bodies the page actually sent
were captured from the network.

| # | Behaviour | Result |
|---|---|---|
| F1 | Open and close a correction dialog | 0 requests ✓ |
| F2 | Start a replacement, cancel | Back to the saved value ✓ |
| F3 | Choose a candidate, cancel | Original path kept ✓ |
| F4 | Unrelated text edit after a cancelled replacement, save | Body has no `current_location_id` ✓ |
| F5 | Confirm a replacement, save | Only `current_location_id` (new UUID); no `original_location_id`; text as saved ✓ |
| F6 | Cancel a clear, save | Reference not sent ✓ |
| F7 | Confirm a clear, save | `current_location_id: null`; text as saved ✓ |
| F8 | Tab after the clear | «لم يُحدَّد موقع جغرافي» ✓ |
| F9 | Inactive saved original on the tab | «غير مفعّلة» ✓ |
| F10 | Text-only edit in the displacement dialog with an inactive original | `original_location_id` never sent ✓ |

React Hook Form `dirtyFields` after cancellation is asserted in
`tests/gaza-location-selector.test.tsx` ("leaves dirtyFields unchanged after
a cancelled replacement …"), which passes.

### PX-1 — stale reopen overwrites text (pre-existing; not fixed)

**Reproduction (browser, real API):**
1. Open a correction dialog, change «العنوان التفصيلي», save.
2. Reopen the dialog before the family refetch returns.
3. The dialog shows the **previous** text. Saving any change resends it,
   so the correction from step 1 is silently overwritten.

**Evidence:**
- A network timeline captured the order: PATCH completes, GET refetch
  issued, dialog reopened, GET returns.
- Waiting until the tab shows the new value makes the next dialog open
  with fresh data, every time.
- The stored row confirmed `address_text` reset to NULL.
- The window equals the refetch latency: about 10s on the loaded,
  single-threaded verification server; normally sub-second locally;
  potentially seconds on slow field networks.

**Root cause:**
- `EditCurrentResidenceDialog` / `EditDisplacementDialog` reset from the
  `residence` prop on open.
- `useUpdateFamilyResidence` only invalidates the query.
- `useGuardedSave` closes the dialog on the PATCH response.
- Each dialog always sends all of its text fields.

**Scope:**
- This pattern is in the committed code that predates Stage 3.2–4B.
- The geographic references are **safe**: an unchanged reference is
  omitted, so a stale baseline sends nothing.

**Recommendation (separate approval):** seed the family query from the
PATCH response, or await the refetch before closing, in
`useUpdateFamilyResidence`. The same pattern should be checked in the other
edit dialogs.

---

## 7. Authorization (real API; existing tests also pass)

| Actor | Result |
|---|---|
| Unauthenticated | 401 on geography endpoints |
| SUPER_ADMIN, SOCIAL_WORKER | 200 on list and details; SOCIAL_WORKER (`residence.update`) can correct a reference (200) |
| DATA_ENTRY | 200; registers with references |
| REVIEWER, REPORTS_VIEWER | 403 on list and details; REVIEWER cannot correct a reference (403) |
| FAMILY_USER, COORDINATOR | Staff login refused (422); geography endpoints 401 |
| Family / coordinator mirrors | `/api/v1/family/gaza-locations/…`, `/api/v1/family/change-requests/gaza-locations/…`, `/api/v1/coordinator/gaza-locations/…` → 404 (do not exist) |

No permission, role, seeder, middleware or route-guard change:
`git diff` of `backend/database/seeders`, `app/Http/Middleware` and
`bootstrap` is empty. `GazaLocationApiTest` and
`ApiUnauthenticatedTest` pass on SQLite and PostgreSQL.

---

## 8. Frontend test results

| Check | Result |
|---|---|
| TypeScript (`tsc --noEmit`) | Clean |
| ESLint | 0 errors; 1 warning in `components/family/requests/family-person-correction-form.tsx` (existing, unrelated) |
| `tests/gaza-location-selector.test.tsx` | 27 passed (D-1 regression test added) |
| `tests/staff-residence-geography.test.tsx` | 18 passed |
| Full suite (`vitest run`), two runs | 815 tests: run 1 → 814 passed, 1 failed (`family-home.test.tsx`); run 2 → 813 passed, 2 failed (`activation-flow.test.tsx`). All geography tests passed in both runs |

The failing tests differ between runs, belong to the Family PWA (Family
Auth, household), import nothing that Stage 3.2–4C touched, and pass in
isolation (`family-home` 22/22 three times; `activation-flow` 46/46 twice).
One timed out at 5.77s against the 5s default. They are classified as
**existing timing flakiness**, not a regression, and were not changed
(outside scope; R6).

---

## 9. Backend test results

| Suite | Result |
|---|---|
| SQLite, full (`phpunit`) | **2,713 tests, 26,136 assertions, 0 failures, 0 errors**, 107 skipped (PostgreSQL-only classes); 23m09s |
| PostgreSQL, Geography (`-c phpunit.pgsql.xml tests/Feature/Geography`) | **73 tests, 2,818 assertions, passed**; 1 skipped by design (`test_without_postgresql_a_running_run_blocks_the_import`) |
| PostgreSQL, full | 2,713 tests: **9 errors + 1 failure = 10**, all legacy fixture issues (below); 1 skipped |

The 10 PostgreSQL problems match the documented baseline: docs/11 FU-12,
"seven legacy PostgreSQL test-fixture failures beyond the three recorded in
docs/07 (hard-coded reference ids, a check constraint)".

| Test | Cause |
|---|---|
| `FamilyActivityLogTest::test_rejected_person_correction_creates_no_activity` | `chk_health_record_shape` check |
| `AssistanceApprovalTest::test_existing_v1a_assistances_default_to_internal` | Hard-coded `assistance_category_id` (FK) |
| `AssistanceTargetingTest` (2 tests) | Hard-coded `disability_type_id` (FK) |
| `OperationalDashboardTest` (2 tests) | Hard-coded `disability_type_id` (FK) |
| `PopulationAndHealthReportTest::test_health_is_aggregate_distinct_current_and_private` | Hard-coded `disability_type_id` (FK) |
| `AssistanceAndDataQualityReportTest::test_status_filter_covers_open_and_completed_by_default` | `chk_assistance_completion` check |
| `PublicIdentifierTest::test_reserved_id_is_kept_when_the_sequence_runs_ahead` | SQLite-only `sqlite_sequence` |
| `ImportStagingTest::test_creates_rows_with_json_payload_and_issues` (failure) | JSON array identity (key order) |

None is in geography, residence, registration or correction code, and
Stage 4C changed no backend file.

---

## 10. Defects discovered and fixed

### D-1 — Select triggers 32px tall (Stage 4A selector)

- **Reproduction:** `/families/new` at any viewport; measure the geography
  triggers → 32px. The text inputs beside them are 40px, and the selector
  intends 44px on mobile and 40px from `sm`.
- **Root cause:** `SelectTrigger` carries `data-[size=default]:h-8`. That
  attribute-variant selector outranks the selector's plain `h-11 sm:h-10`.
- **Fix:** `gaza-location-selector.tsx` uses one `TRIGGER` class,
  `h-11! w-full sm:h-10!`, the project's existing important-modifier
  convention (`h-11!` in the registry filters and coordinator forms). The
  shared `components/ui/select.tsx` is unchanged.
- **Regression:**
  - a unit test asserts every trigger carries `h-11!` / `sm:h-10!` (jsdom
    has no CSS);
  - the browser re-measurement found no undersized trigger at 1440, 820 or
    390.

### D-2 — Geography tests timed out under load (Stage 4A/4B tests)

- **Reproduction:** run the frontend tests while the machine is busy:
  6–9 tests time out at the 5s default (they take about 2s idle).
- **Root cause:** multi-level cascades and full-form flows with many real
  user events, close to the default timeout.
- **Fix:**
  - an explicit 20s timeout on the two Stage 4B describe blocks;
  - `vi.setConfig({ testTimeout: 20_000 })` in the selector test file;
  - no assertion changed.
- **Regression:** 45 of 45 passed under the same load.

**Not fixed (outside scope):** PX-1 (§6) and OBS-1 (§4).

---

## 11. Unverified items

- Real phones and tablets (touch was emulated) and other engines (Safari,
  Firefox). Only Chromium-based Edge was used.
- Screen readers. Accessible names, roles and `aria-*` were checked
  programmatically only.
- A production-like stack (nginx / PHP-FPM). The PHP built-in server is
  single-threaded, which also stretched the PX-1 window.
- PostgreSQL 16 (the instance is 18.6).
- Dark mode rendering.
- Production data volume (the reference itself is the full official
  dataset).

---

## 12. Remaining risks

| # | Risk | Mitigation |
|---|---|---|
| R1 | PX-1 stale reopen can overwrite residence **text** | Separate fix (§6); before a field pilot of Staff residence correction |
| R2 | OBS-1 active child under an inactive ancestor | Decide cascade or ancestor validation before any deactivation feature |
| R3 | Long names truncate in 153px desktop triggers | Full name is in the menu and path; optional `xl` two-column grid or `title` |
| R4 | Ten legacy PostgreSQL fixture failures mask nothing today but reduce signal | FU-12 cleanup |
| R5 | Not tested on real devices or other engines | Manual device pass before Production |
| R6 | Existing Family PWA tests (`activation-flow`, `family-home`) flake near the 5s default timeout in full runs | Separate test-hardening task (same approach as D-2) |

---

## 13. Readiness verdict for Stage 5: **CONDITIONAL**

Stage 3.2–4B is verified end to end, with D-1 and D-2 fixed. Stage 5
(Family PWA, RESIDENCE_UPDATE v2) may be planned. Implementation needs:
1. explicit approval of the family-side geography endpoints (API-D2, ADR
   §12 stage 5);
2. a decision on OBS-1 if Stage 5 introduces any deactivation or relies on
   ancestor activity;
3. a fix for PX-1, recommended before Staff residence correction is used in
   a pilot. It is independent of Stage 5 but affects the same residence
   data.

Stage 5 was not started. RESIDENCE_UPDATE v1 is unchanged.

---

## Appendix: artefacts (local, outside the repository)

- Verification scripts (`api-verify.mjs`, `browser-verify.mjs`,
  `browser-states.mjs`, probes), JSON results and screenshots are in the
  session scratchpad.
- The disposable database `famboook_stage4c_test` remains on the test
  instance. It can be removed with
  `dropdb -h 127.0.0.1 -p 5433 -U postgres famboook_stage4c_test`.
