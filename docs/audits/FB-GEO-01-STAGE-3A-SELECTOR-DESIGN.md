# FB-GEO-01 — Stage 3A: Geographic Selector Design & Integration Audit

**Date:** 2026-10-15
**Mode:** documentation only. No application code, API, database, migration,
permission or frontend change. Nothing ran against a database.
**Baseline:**
- `CLAUDE.md`, ADR-FB-GEO-01 (§4.3–§4.4, §8.1);
- Stage 1A, 1B, 2A and 2B documents;
- Stage 2B code (`GazaLocationController`, `GazaLocationResource`,
  `GazaLocationApiTest`);
- the frontend at the current working tree.

**Verdict:** **CONDITIONAL** (§17).

> **Universal rule.** The selector is fully generic and data-driven. It
> behaves identically for every governorate, city, area, neighborhood and
> landmark, and derives everything from the stored hierarchy (`id`,
> `parent_id`, `level`, `has_children`) returned by the API. There is no
> place-, name- or count-specific logic anywhere. Any place name in this
> document is an illustration only.

---

## 1. Executive summary

- **One reusable component, `GazaLocationSelector`.** It loads governorates
  from `GET /api/v1/gaza-locations/governorates` and each further level from
  `GET /api/v1/gaza-locations/{uuid}/children`, always keyed by the selected
  parent's UUID.
  - Each level shows exactly the direct children returned by the API.
  - Changing a level clears every level below it.
  - Each control stays disabled until its parent is chosen.
- **It fits the existing frontend:**
  - TanStack Query hooks in `lib/api/*` and `apiClient`;
  - React Hook Form `Controller` integration with Zod (UX only; Laravel is
    authoritative);
  - shadcn/ui `Select` on Radix, Famboook tokens, RTL root
    (`<html lang="ar" dir="rtl">`).
  - The closest precedent is `components/shared/clan-branch-fields.tsx`, a
    two-level Clan → Branch cascade with a "kept, inactive" display.
- **No new dependency is needed** for the recommended first version. Every
  level is a Radix `Select`; the largest child list in the official data is
  73 landmarks. A searchable combobox is an optional later enhancement:
  `cmdk` is not installed, while Radix Popover is already available.
- **Two API gaps condition the design:**
  1. **G1:** the details endpoint does not expose `is_active`, so a saved
     inactive location cannot be told apart. The minimum change is to add
     `is_active` to the details response (node and path items).
  2. **G2:** the endpoints are **staff-only**. The Family PWA cannot use them
     until family-side mirrors exist (API-D2, Stage 5), and coordinators have
     no access (API-D3).
- **Roadmap is unchanged:** Stage 3 = residence linkage, Stage 4 = Staff
  picker UI. This document is an **advance design deliverable**. It
  authorizes neither (§2).

---

## 2. Roadmap / ADR stage-numbering clarification

The ADR roadmap (ADR-FB-GEO-01 §12) is **not renumbered**.

| ADR stage | Scope | Status |
|---|---|---|
| 1A–1C.1 | Dataset, governance, schema, import, import history | Done locally |
| 2 (2A, 2B) | API audit; read-only Staff API | Done locally |
| **3** | Residence linkage: `original_location_id` / `current_location_id`, Actions, Requests, Resources | **Not started** |
| **4** | Staff UI: cascading picker in registration and residence correction | **Not started** |
| 5 | RESIDENCE_UPDATE v2 and Family PWA (with family-side endpoints) | Not started |
| 6 | Reporting, data quality, exports | Not started |

"Stage 3A" in this document's name is the request's label for an
**advance design and audit for the Stage 4 picker**, informed by the
Stage 3 linkage it will depend on. It authorizes no Stage 3 or Stage 4
implementation. If the label should be recorded in the ADR, that is a
separate documentation decision (§14, UI-D11).

---

## 3. Existing frontend inventory (actual files; none modified)

### 3.1 Platform and conventions

| Concern | Actual implementation | File |
|---|---|---|
| Framework | Next.js 16.3.6, React 19.2.8 (the repo warns of breaking changes vs. older Next.js) | `frontend/package.json`, `frontend/AGENTS.md` |
| RTL / language | `<html lang="ar" dir="rtl">`, IBM Plex Sans Arabic | `frontend/app/layout.tsx` |
| Theme tokens | Brand scale `--brand-900 #123C3A` … `--brand-50`, radius tokens (`--radius-control`, `--radius-widget`), Tailwind v4 `@theme` | `frontend/app/globals.css` |
| HTTP client | `apiClient.get<T>(path)` (fetch, Sanctum cookie session, CSRF for writes, `ApiError` with `status`, `validationErrors`); **no AbortSignal parameter** | `frontend/lib/api/client.ts` |
| Query defaults | `staleTime: 30s`; one retry except 401 / 403; global 401 clears the session | `frontend/components/providers/query-provider.tsx` |
| Reference hooks | `useQuery({ queryKey: ["reference", …], staleTime: 5 min })` | `frontend/lib/api/reference.ts`, `frontend/lib/api/clans.ts` |
| API types | `frontend/lib/types/api/*.ts` (e.g. `reference.ts`, `clan.ts`, `family.ts`) | — |
| Forms | React Hook Form + `zodResolver`; `Controller` for Selects; `useWatch` for dependent fields; Zod is UX-only ("Laravel … authoritative") | `frontend/app/(staff)/families/new/page.tsx`, `frontend/lib/schemas/*.ts` |
| Select primitive | shadcn `Select` on `radix-ui` (`SelectTrigger`, `SelectContent` with `position="item-aligned"`, `SelectGroup` / `SelectLabel`); Radix cannot use `""` as an item value (sentinel pattern `__none__`) | `frontend/components/ui/select.tsx`, `clan-branch-fields.tsx` |
| Field parts | `FieldLabel` (with `(اختياري)`), `FieldError`, `SaveError` | `frontend/components/shared/edit-dialog-parts.tsx` |
| Other UI primitives | `input`, `button`, `dialog`, `sheet`, `alert`, `skeleton`, `badge`, `card`, `tabs`; **no Popover / Command / Combobox component** (`cmdk` not installed; `@radix-ui/react-popover` is present through `radix-ui`) | `frontend/components/ui/` |
| Direction provider | **None.** Radix primitives default to LTR keyboard and alignment semantics unless given `dir` | (no `DirectionProvider` in `app/` or `components/`) |
| Tests | Vitest + Testing Library; `renderWithClient` (QueryClient, `retry: false`) | `frontend/tests/helpers.tsx`, `frontend/tests/*.test.tsx` |

### 3.2 Screens and components touching residence or geography

| Area | File(s) | Current responsibility (free text only) |
|---|---|---|
| Staff family registration | `frontend/app/(staff)/families/new/page.tsx`; schema `frontend/lib/schemas/family-registration.ts` | One RHF form: original residence text, displaced yes/no, displacement location text, current address (`governorate`, `city`, `area`, `neighborhood`, `address_text`). Grid `grid-cols-1 sm:grid-cols-2 xl:grid-cols-3`, Select triggers `h-10` |
| Family details (display) | `frontend/components/families/family-overview.tsx` (`ResidenceSummary`), `frontend/components/families/family-identity-header.tsx` | Three blocks: original residence / displacement / current address. The current address is joined most-specific-first with «، » (`currentParts.join("، ")`) |
| Residence tab | `frontend/components/families/family-residence-tab.tsx` | Summary plus edit actions (`residence.update`), field rows with «غير مسجّل» |
| Staff residence correction | `frontend/components/families/edit-residence-dialogs.tsx`; schema `frontend/lib/schemas/edit-residence.ts` | Two dialogs: displacement (status Select, text inputs) and current address (four text inputs + detail). Partial PATCH to `/families/{code}/residence` |
| Family PWA household | `frontend/components/family/household/family-household.tsx`; `frontend/lib/api/family-household.ts` | Read-only display of the family's residence |
| Family PWA RESIDENCE_UPDATE (v1) | `frontend/components/family/requests/family-residence-update-form.tsx`; schema `frontend/lib/schemas/family-residence-update.ts` | Eight-field v1 proposal (text and status); inputs `h-11 rounded-xl` (touch-sized) |
| Coordinator registration (REGISTER_FAMILY) | `frontend/components/family/coordinator/requests/coordinator-register-family-form.tsx`; page `frontend/app/family/(portal)/coordinator/requests/new/register-family/page.tsx`; schema `frontend/lib/schemas/coordinator-register-family.ts` | Original residence as **required text** only (CO-02 D10) |
| Two-level cascade precedent | `frontend/components/shared/clan-branch-fields.tsx`; `frontend/lib/api/clans.ts` | Clan → Branch: child cleared on parent change; inactive current value kept and labelled «(غير مفعّلة)»; opt-in `preselectSingleClan` |
| Geographic filtering | `frontend/components/assistances/assistance-targeting-tab.tsx`, `frontend/lib/schemas/assistance.ts` (displacement status and location **text** criteria); `frontend/components/dashboard/*`, `frontend/components/reports/population-report.tsx` (displacement counts / text) | No structured geography; Stage 6 scope |
| Import wizard labels | `frontend/components/administration/import-wizard/labels.ts` | Maps «المدينة» → `original_residence_text` |

No frontend file references `gaza-locations` yet.

---

## 4. API integration assessment (Stage 2B, as built)

| Need | Endpoint | Fit |
|---|---|---|
| Governorates | `GET /api/v1/gaza-locations/governorates` | ✓ Active, north → south |
| Next level | `GET /api/v1/gaza-locations/{uuid}/children` | ✓ Active direct children; `[]` for a leaf; **404 for an inactive parent** |
| Initialize from a saved UUID | `GET /api/v1/gaza-locations/{uuid}` | ✓ Node plus `path` (governorate → parent). ✗ **no `is_active`** (G1) |
| Search (optional enhancement) | `GET /api/v1/gaza-locations/search?q=&level=&parent_id=` | ✓ Paginated, with paths; no Arabic normalization |
| Item fields | `id`, `level`, `name` (trimmed), `parent_id`, `has_children`, `landmark_type` (Staff) | ✓ Enough for a generic cascade |
| Authorization | `reference-data.view` OR `residence.update`; `staff.side` | ✓ Staff residence editors. ✗ Family / Coordinator (G2) |
| Ordering | Governorates by official code; children by trimmed name, then id | ✓ Deterministic per database (collation note, ADR §8.1) |

Everything the selector needs is in the response. Level labels come from
`level`. Whether a further level exists comes from `has_children`. Parent
checks use `parent_id`. No name is ever parsed.

---

## 5. Reusable selector architecture

```text
components/geography/
  gaza-location-selector.tsx     GazaLocationSelector (public; RHF-friendly)
  gaza-location-level-field.tsx  one level: label + Select + states (internal)
  gaza-location-path.tsx         read-only RTL path display (reused by summaries)
lib/api/gaza-locations.ts        useGazaGovernorates, useGazaLocationChildren, useGazaLocation
lib/types/api/gaza-location.ts   GazaLocation, GazaLocationLevel, GazaLocationPathItem
```

These locations follow existing conventions: `components/shared/`-style
domain folders, hooks in `lib/api/`, types in `lib/types/api/`.

**Generic rendering rule:**
- Level controls are generated from the selection chain, not from a
  hard-coded list of five fields.
- Control *k + 1* exists once control *k* has a selected location whose
  `has_children` is `true`.
- Each control's label comes from the `level` of the items it lists (all
  children of one parent share one level; ADR §3.1, verified in Stage 1A
  §5).

A fixed Arabic label map is keyed by `level` only:

| `level` | Label |
|---|---|
| GOVERNORATE | المحافظة |
| CITY | المدينة |
| AREA | المنطقة |
| NEIGHBORHOOD | الحي |
| LANDMARK | المعلم (اختياري) |

---

## 6. TypeScript component contract (proposed)

```ts
// lib/types/api/gaza-location.ts
export type GazaLocationLevel = "GOVERNORATE" | "CITY" | "AREA" | "NEIGHBORHOOD" | "LANDMARK";

export interface GazaLocationPathItem {
  id: string;            // UUID
  level: GazaLocationLevel;
  name: string;          // display-trimmed by the API
}

export interface GazaLocation extends GazaLocationPathItem {
  parent_id: string | null;
  has_children: boolean;
  landmark_type: string | null;      // Staff responses only
  path?: GazaLocationPathItem[];     // details / search only
  is_active?: boolean;               // NOT returned today — gap G1
}

// components/geography/gaza-location-selector.tsx
export interface GazaLocationSelection {
  id: string;                        // deepest selected location UUID
  level: GazaLocationLevel;
  path: GazaLocationPathItem[];      // governorate → selected (inclusive)
}

export interface GazaLocationSelectorProps {
  /** Controlled value: the deepest selected location UUID, or null. */
  value: string | null;
  /** Emits the new deepest selection (null when cleared). Never auto-fires on mount. */
  onChange: (selection: GazaLocationSelection | null) => void;
  /** DOM id prefix for labels / aria wiring (several selectors per form). */
  idPrefix: string;
  /** Visible group label, e.g. «السكن الأصلي» / «السكن الحالي». */
  label: string;
  disabled?: boolean;
  /** Field error from RHF / Laravel (`original_location_id` …). */
  error?: string;
  /** Whether the LANDMARK level is offered at all (default true; always optional). */
  allowLandmark?: boolean;
  /** Visual density: Staff forms (h-10) vs. touch (h-11) — tokens only, no new colors. */
  size?: "default" | "touch";
}
```

The component is **controlled** (`value` + `onChange`), so it drops into
RHF `Controller` the way `ClanBranchFields` is used today. Selections below
the deepest one are internal UI state, rebuilt from `value` (§9).

---

## 7. Generic cascading selection state model

```text
state.chain: string[]   // selected UUIDs by depth: [governorateId, cityId, areaId, neighborhoodId, landmarkId?]
```

| Event | Rule |
|---|---|
| Select item at depth *k* | `chain = chain.slice(0, k).concat(item.id)`. Every descendant selection is cleared. `onChange` fires with the new deepest selection |
| Clear depth *k* | `chain = chain.slice(0, k)` (the selection becomes the parent's) |
| Control at depth *k + 1* | Rendered and enabled only when `chain[k]` is set **and** that item's `has_children` is `true` |
| Leaf reached (`has_children = false`) | No further control. The selection is complete for this branch |
| External `value` changes | Re-initialize from the details endpoint (§9) |

- **No automatic selection.** A single available child is shown but not
  pre-selected (UI-D5).
- **No name-based logic.** Only `id`, `parent_id`, `level` and
  `has_children` drive behaviour.
- **Flagged records** are ordinary items: they are active, so they are
  listed and selectable (Stage 1B Q1). No flag data exists in responses.

---

## 8. Query loading and invalidation rules

| Hook | Query key | Enabled when | Notes |
|---|---|---|---|
| `useGazaGovernorates()` | `["gaza-locations", "governorates"]` | always | `staleTime` 5 min (reference convention) |
| `useGazaLocationChildren(parentId)` | `["gaza-locations", "children", parentId]` | `parentId !== null && parent.has_children` | One cache entry per parent UUID |
| `useGazaLocation(id)` | `["gaza-locations", "detail", id]` | `id !== null` | Initialization and path display |
| `useGazaLocationSearch(params)` (optional) | `["gaza-locations", "search", q, level, parentId, page]` | `q.trim().length >= 2` | Only with UI-D1 option B |

**Stale-response protection:**
- Each level reads the query for the **currently selected** parent's UUID.
  A late response for a previous parent lands in that parent's cache entry
  and is never rendered for the new parent.
- `apiClient.get` has no AbortSignal today. Cancelling superseded requests is
  a later client enhancement and is not needed for correctness.

**Errors and retry:**
- Per level: an inline error with a «إعادة المحاولة» button (`refetch`);
  the levels below stay disabled.
- The global default retries once, except 401 / 403.
- A **404 on children** means the parent is no longer available (inactive or
  removed): show it as such, not as a network error. The hook should set
  `retry: false` for 404.

**Invalidation:**
- The reference changes only through the import command, so there are no
  mutation-driven invalidations in the UI.
- A 5-minute `staleTime` matches the other reference hooks. No HTTP caching
  headers are needed (API-D9).

---

## 9. Existing-selection initialization

1. `value` (a saved UUID) → `useGazaLocation(value)` → `path` + node.
2. `chain = [...path.map(p => p.id), node.id]`.
3. The governorates query and the children queries for each `chain[k]` load
   through the normal enable rules, so every control shows its options with
   the saved item selected.
4. If a saved ancestor's or the node's children request returns **404**, the
   chain contains an inactive node (§10).
5. **A details 404** (unknown UUID): show «الموقع المحفوظ غير متاح» with a
   «تغيير» (change) action that resets to an empty governorate selection. It
   never silently changes the stored value.
6. Initialization **never** calls `onChange`. Only a user action does, so
   opening and saving a form does not rewrite a stored selection.

---

## 10. Inactive location handling

**Current API behaviour:**
- Lists, children and search return **active** locations only.
- Details returns a location **whatever its status** but does **not**
  expose `is_active`.
- Children of an inactive parent → 404.

**Consequence:** for a saved selection, the selector can detect inactivity
only indirectly: the saved item is missing from its parent's active children
list, or a children request returns 404. That is fragile, and it cannot tell
"inactive" from "parent list failed".

**Minimum API change (gap G1; proposal only, not implemented):**
- Add `is_active` (boolean) to the **details** response, for the node **and**
  each `path` item.
- Lists, children and search are unchanged: they return only active items,
  so the field would always be `true` there.
- This is one additive field in `GazaLocationResource` on the details
  endpoint. It needs API-D4 to be amended (the Stage 2B list excluded it).

**Safe behaviour (designed for when G1 exists):**

| Situation | Behaviour |
|---|---|
| Saved node and ancestors active | Normal editable cascade with the saved chain selected |
| Saved node (or an ancestor) inactive | Show the saved path **read-only** as a historical value, labelled «(غير مفعّل)» like `ClanBranchFields`. It is **never** added as a selectable option. A «تغيير» action clears the chain and starts a fresh active selection |
| User changes the selection | Only active items can be chosen; the inactive value disappears once replaced |
| Form saved without touching the field | The stored UUID is sent unchanged (or not sent, per the form's partial-PATCH convention); never auto-replaced |

Laravel stays authoritative. Stage 3 validation must refuse inactive UUIDs
for **new** selections (ADR §13.3) while leaving an unchanged stored value
alone.

---

## 11. RTL, accessibility and mobile design

**RTL:**
- The page is RTL. Controls flow right to left in the existing
  `grid-cols-1 sm:grid-cols-2 xl:grid-cols-3` pattern, governorate first
  (rightmost).
- Pass `dir="rtl"` to each Radix `Select`, since no `DirectionProvider`
  exists, so keyboard navigation and alignment follow RTL. An app-wide
  provider is a separate, wider decision (UI-D10).

**Path display (`GazaLocationPath`):**
- General → specific: «المحافظة ‹ المدينة ‹ المنطقة ‹ الحي ‹ المعلم».
- Separators come from a Lucide chevron (`ChevronLeft`, mirrored for RTL),
  in the muted foreground token.
- Compact summaries may keep the existing «، » joined form, most specific
  first, for consistency with `ResidenceSummary` (UI-D8).

**Accessibility:**
- Wrap the selector in `<fieldset>` with `<legend>{label}</legend>`.
- Each level has a `FieldLabel htmlFor` id and a `SelectTrigger id`.
- `aria-invalid` and `aria-describedby` point to `FieldError`.
- `aria-busy` while loading.
- A disabled control's placeholder states why: «اختر المدينة أولًا» (from
  the parent's level label, generic).
- Radix Select gives arrow keys, Home/End, typeahead on item text, and
  Escape to close.
- The «تغيير» and «إعادة المحاولة» actions are `Button`s.

**Mobile:**
- One column below `sm`.
- `size="touch"` uses the Family PWA's `h-11 rounded-xl`; Staff uses the
  registration page's `h-10`. Both are existing token usages, with no new
  colors or typography.
- Lists are short (≤ 73 items in the official data). Radix Select scrolls
  within the viewport.

---

## 12. Residence integration considerations (design only; Stage 3 implements the backend)

- **Two independent selector instances:**
  - «السكن الأصلي (قبل النزوح)» → `original_location_id`;
  - «السكن الحالي» → `current_location_id`.
- When the family is **DISPLACED**, the current-residence selector is
  labelled as the displacement location (e.g. «السكن الحالي / مكان
  النزوح»). The value is still `current_location_id` (ADR D4).
- There is **no `displacement_location_id`** and no third selector.
- `current_location_id` is valid for DISPLACED, NOT_DISPLACED and unknown
  status. Changing the displacement status never clears it (ADR §5).
- **Free text is kept:** `original_residence_text`, `governorate`, `city`,
  `area`, `neighborhood`, `address_text` and `displacement_location_text`
  keep their inputs and meaning (ADR D9).
  - Recommended: show the structured selector above the existing text
    fields. `address_text` remains the street-level detail.
  - Legacy text is displayed alongside, never prefilled from, and never
    parsed into the selector.
- **No inference:** no UUID is derived from existing text, and no automatic
  matching or backfill happens (ADR D10). A family with only text shows an
  empty selector.
- **RESIDENCE_UPDATE v1 is untouched.** The Family PWA form keeps its eight
  text/status fields; structured fields arrive only with v2 (Stage 5).
- **Coordinator REGISTER_FAMILY is untouched**: text original residence
  (CO-02 D10, ADR D12).
- **Prerequisites (Stage 3, not done):** the migration adding both nullable
  UUID columns, the Action / Request / Resource changes, and validation that
  accepts only active UUIDs for new selections.
- **Partial selection (ADR §14 V5 / G9):**
  - The selector emits the deepest selection at any level.
  - Whether a form *requires* a given depth is a validation decision for
    Stage 3 (Laravel) and the Zod mirror (UX). It is not hard-coded in the
    component.

---

## 13. Authorization boundaries

| User type | Can the selector work today? | Path |
|---|---|---|
| SUPER_ADMIN, ADMINISTRATOR, DATA_ENTRY | Yes | Stage 2B endpoints (`reference-data.view`) |
| SOCIAL_WORKER | Yes | `residence.update` (API-D1) |
| REVIEWER, REPORTS_VIEWER | No (403) | Not needed until Stage 5 / 6 (API-D10). A review screen shows names server-rendered in the request presentation |
| FAMILY_USER | **No.** `staff.side` refuses | **Gap G2:** family-side mirrors under `/api/v1/family/change-requests/gaza-locations…` with `family-portal.access` + `family.context` (API-D2, Stage 5) |
| COORDINATOR | **No** | No coordinator geography workflow (API-D3); REGISTER_FAMILY stays text |

Design consequences:
- `GazaLocationSelector` takes its data hooks from one module, so the Family
  PWA can later bind the same component to the family-side endpoints, via a
  `source: "staff" | "family"` setting or two thin hook sets, without
  duplicating UI logic.
- Until G2 is closed, the selector must not be placed on any family or
  coordinator screen.
- The frontend's permission checks remain UX only. Staff screens already
  gate residence editing on `can("residence.update")`.

---

## 14. UI decision register

| # | Decision | Recommendation | Alternatives |
|---|---|---|---|
| UI-D1 | Select vs. searchable combobox | **A:** the existing Radix `Select` per level (no new dependency; ≤ 73 options) | **B:** a search-first combobox on `/search` (Radix Popover + `Input`, custom listbox; or adopt `cmdk`, a new dependency) as a later enhancement |
| UI-D2 | Partial selection | The component allows stopping at any level; the **required depth is form validation** (Stage 3, ADR V5) | Fixed minimum depth in the component (rejected: not generic) |
| UI-D3 | Landmark | Optional. Its control appears only when the neighborhood `has_children`; a «بدون معلم» sentinel (`__none__`, like `NO_BRANCH`) clears it | Hide landmarks entirely (`allowLandmark=false` per form) |
| UI-D4 | Empty child collection | When `has_children` is false, no further control (complete). When `has_children` is true but children return `[]` (all inactive), show «لا توجد خيارات متاحة في هذا المستوى» and treat the parent as the selection | — |
| UI-D5 | Single available child | **No automatic selection.** Show the single option normally (generic, any level) | Opt-in auto-select like `preselectSingleClan` (needs approval; must still be generic and must never fire on initialization) |
| UI-D6 | Inactive saved location | Read-only historical display plus «تغيير»; never selectable (§10). Needs G1 | Detect via missing-from-list / 404 heuristics (fragile; not recommended) |
| UI-D7 | Errors and retry | Inline per-level error with retry; no retry on 404; lower levels disabled | Form-level alert only |
| UI-D8 | Path display | Chevron path general → specific in the selector; existing «، » most-specific-first join in compact summaries | One format everywhere |
| UI-D9 | Free-text coexistence | Selector above the existing text fields; text kept, shown, never prefilled from or parsed into the selector | Hide text fields once a structured value exists (needs a separate decision) |
| UI-D10 | RTL direction for Radix | `dir="rtl"` on each Select within the component | App-wide `DirectionProvider` (affects every Radix component; separate decision) |
| UI-D11 | Recording "Stage 3A" in the ADR | Keep this as an audit document; add a one-line ADR reference only if approved | Add a 3A row to ADR §12 |

---

## 15. API gaps and dependencies

| # | Gap / dependency | Needed for | Minimum change (not implemented) |
|---|---|---|---|
| G1 | `is_active` not exposed on details | Safe inactive-selection display (§10) | Add `is_active` to the details response (node and path items); amend API-D4 |
| G2 | Endpoints are staff-only | Family PWA selector | Family-side mirrors (API-D2), Stage 5 |
| G3 | Children of an inactive parent → 404 | Rebuilding a saved chain under an inactive ancestor | None if G1 exists (the chain comes from details `path`); the frontend treats the 404 as "unavailable" |
| G4 | No AbortSignal in `apiClient.get` | Cancelling superseded requests | Optional `signal` parameter; not needed for correctness (§8) |
| G5 | No `DirectionProvider` | RTL keyboard semantics in Radix | Per-component `dir` (UI-D10) |
| D1 | `original_location_id` / `current_location_id` columns and API fields | Saving a selection | ADR Stage 3 |
| D2 | Required selection depth per form | Validation | Stage 3 decision (ADR V5) |
| D3 | Database collation differences | Identical option order across environments | Later decision (Stage 2A §12) |

---

## 16. Recommended implementation sequence (aligned with ADR §12)

1. **Approve** the open decisions: G1 / API-D4 amendment, and UI-D1 to
   UI-D11 (§17).
2. **ADR Stage 3, backend linkage (separate approval):**
   - migration adding nullable UUID `original_location_id` /
     `current_location_id`, FK RESTRICT, no backfill;
   - Create/Update residence Actions, Requests (active-only validation,
     required depth per ADR V5), `FamilyDetailResource` fields;
   - the G1 `is_active` field on details if approved;
   - tests on PostgreSQL and SQLite.
3. **ADR Stage 4, Staff UI (separate approval):**
   - `lib/types/api/gaza-location.ts`, `lib/api/gaza-locations.ts` hooks;
   - `GazaLocationSelector`, `GazaLocationPath`;
   - integration in `app/(staff)/families/new/page.tsx` and
     `components/families/edit-residence-dialogs.tsx`, beside the existing
     text fields;
   - Vitest tests: cascade reset at every depth, disabled states, stale
     response ignored, initialization from a saved UUID, inactive display,
     empty and error states, no auto-selection, RTL labels.
4. **ADR Stage 5:** family-side endpoints, RESIDENCE_UPDATE v2, and the
   same selector in the Family PWA.
5. **ADR Stage 6:** reporting and filters (separate approvals).

---

## 17. Readiness verdict: **CONDITIONAL**

The existing API and frontend conventions support a generic, data-driven
selector without new dependencies, roles or name-based logic. Implementation
(Stage 4) should start only after:

1. **ADR Stage 3 (residence linkage)** is approved and implemented, so a
   selection can be saved;
2. **G1** is decided (`is_active` on details), or inactive saved selections
   remain undetectable;
3. **UI-D1, UI-D2, UI-D5, UI-D6 and UI-D10** are approved (the others have
   defaults matching existing conventions).

The Family PWA and coordinator use stays **blocked** on G2 / API-D2 / API-D3
by design.

---

## 18. Stage 4A status (2026-10-15)

`GazaLocationSelector` was implemented **locally** as a reusable component,
not yet placed in any form (ADR-FB-GEO-01 §8.2). The UI decisions were taken
as recommended:
- UI-D1 A: Radix Select per level, no new dependency.
- UI-D2: any depth; `requiredLevel` is presentation only.
- UI-D3: «بدون معلم».
- UI-D4: Arabic empty state.
- UI-D5: no auto-selection.
- UI-D6: read-only historical path plus «تغيير».
- UI-D7: per-level retry, no retry on 404.
- UI-D8: chevron path.
- UI-D10: `dir="rtl"` on each Select.

**Deviations from §6:**

| §6 design | Implemented | Reason |
|---|---|---|
| `onChange(selection: {id, level, path} \| null)` | `onChange(uuid \| null)` | Stage 4A contract; forms store only the UUID, and the path comes from the API |
| `allowLandmark` | `showLandmark` | Stage 4A contract naming |
| `size: "default" \| "touch"` | No prop; `h-11` below `sm`, `h-10` from `sm` | Touch-friendly by default with existing utilities only |
| `label` required | `label` optional (default «الموقع الجغرافي»), `id` optional (generated) | Simpler integration |
| — | `requiredLevel` (presentation only: labels and `aria-required`) | Stage 4A contract; validation stays in Zod (UX) and Laravel |
| Inactive saved value: «تغيير» | «تغيير» plus «إلغاء». Since Stage 4B (§19), a replacement is a staged candidate: «تأكيد الاختيار» emits once and «إلغاء» emits nothing | Cancellation leaves the stored UUID and the form's dirty state untouched |

**Stale-response protection:** each level's query is keyed by its parent's
UUID, and only items whose `parent_id` equals that parent are rendered. The
API client has no AbortSignal, which correctness does not need.

**Tests:** `frontend/tests/gaza-location-selector.test.tsx` (21 tests,
Vitest + Testing Library, synthetic data).

---

## 19. Stage 4B status (2026-10-15)

The selector is integrated locally in the Staff registration form and both
residence correction dialogs, and the residence summaries are displayed
(ADR-FB-GEO-01 §8.3):
- §12 is followed: two instances, text kept, no inference, no third
  selector, and the current selector is labelled as the displacement
  location when DISPLACED.
- UI-D9 (text coexistence) is applied with the selectors **below** their
  text fields rather than above. The text stays the primary record of the
  paper form, and a note says the selection does not replace it.
- Replacing a committed value is now staged (confirm / cancel). Cancelling
  emits nothing.
- The Family PWA and coordinator stay blocked on G2 / API-D2 / API-D3.

---

## Appendix: evidence index

- Backend (Stage 2B):
  - `backend/routes/api.php` (`gaza-locations` group);
  - `backend/app/Http/Controllers/Api/V1/GazaLocationController.php`;
  - `backend/app/Http/Resources/GazaLocationResource.php`;
  - `backend/tests/Feature/Geography/GazaLocationApiTest.php`.
- Frontend platform:
  - `frontend/package.json`, `frontend/AGENTS.md`;
  - `frontend/app/layout.tsx`, `frontend/app/globals.css`;
  - `frontend/components/providers/query-provider.tsx`;
  - `frontend/lib/api/client.ts`, `frontend/lib/api/reference.ts`,
    `frontend/lib/api/clans.ts`;
  - `frontend/components/ui/select.tsx`;
  - `frontend/components/shared/edit-dialog-parts.tsx`,
    `frontend/components/shared/clan-branch-fields.tsx`;
  - `frontend/tests/helpers.tsx`.
- Residence screens:
  - `frontend/app/(staff)/families/new/page.tsx`;
  - `frontend/components/families/{family-overview,family-identity-header,family-residence-tab,edit-residence-dialogs}.tsx`;
  - `frontend/components/family/household/family-household.tsx`;
  - `frontend/components/family/requests/family-residence-update-form.tsx`;
  - `frontend/components/family/coordinator/requests/coordinator-register-family-form.tsx`;
  - `frontend/lib/schemas/{family-registration,edit-residence,family-residence-update,coordinator-register-family,assistance}.ts`.
- Geography filters:
  - `frontend/components/assistances/assistance-targeting-tab.tsx`;
  - `frontend/components/dashboard/*`;
  - `frontend/components/reports/population-report.tsx`.
