# Famboook
## Design System v1

**Document:** `10-DESIGN-SYSTEM.md`  
**Version:** 1.0 (prototype — Dashboard only)  
**Date:** 2026-09-27  
**Status:** Proposed; applied to the Operational Dashboard for owner review

Practical rules for building Famboook screens. Implementation: Tailwind CSS
tokens in `frontend/app/globals.css`, shadcn/ui + Radix primitives, Lucide
icons, shared components in `frontend/components/shared/`.

---

## 1. Philosophy

Famboook is a light, Arabic-first enterprise application. **The information
is the hero**: surfaces, elevation and color exist to make data scannable,
never as decoration. Screens should feel modern, layered and calm — not
flat CRUD, not a marketing page, not a dark admin theme.

## 2. Fluent 2 as reference (not as library)

Adopted principles — implemented with our own stack; Microsoft Fluent UI is
**not** installed:

- a deliberate **surface/elevation hierarchy** instead of borders everywhere;
- **interaction discipline**: every interactive element has default, hover,
  pressed, focus-visible, disabled and selected states;
- **restrained motion**: short (≈150 ms) functional transitions; none under
  `prefers-reduced-motion`; metrics never animate;
- clear **type ramp** and a **4 px spacing grid**.

Not adopted: Fluent's blue brand, Segoe typography, Microsoft 365 visuals.

## 3. Brand adaptations

- Teal brand scale `brand-900 … brand-50`; primary action `#176B63`
  (`brand-700`).
- IBM Plex Sans Arabic (self-hosted via `next/font`).
- Light shell only: white sidebar and top bar on a light canvas.

## 4. Color and surfaces

| Token | Use |
|---|---|
| `canvas` `#F4F6F5` | page background |
| `surface-1` `#FFFFFF` | cards, sidebar, top bar |
| `surface-2` `#F8FAF9` | nested groups inside a card, filter fields, chips |
| `surface-3` `#FFFFFF` + elevation 2 | emphasized / elevated surfaces |
| `surface-hover` / `surface-pressed` | interactive feedback |
| `surface-selected` (`brand-50`) | selected navigation item |
| `stroke-subtle` `#E9EDEB` | card edges and dividers |
| `foreground` / `muted-foreground` / `subtle-foreground` | primary / secondary / metadata text |
| `success` `warning` `danger` `info` (+ `-soft`) | **meaning only** |

Never write raw hex in components; add a token instead.

## 5. Elevation

| Level | Token | Use |
|---|---|---|
| 0 | none | flat bordered sections (registry, profile screens) |
| 1 | `shadow-e1` | standard dashboard widget, sidebar |
| 2 | `shadow-e2` | interactive hover, emphasized surfaces |
| 3 | `shadow-e3` | floating UI (menus, dialogs) where needed |

Shadows are soft and short; no floating marketing cards, no glassmorphism.

## 6. Typography

| Role | Size / weight |
|---|---|
| Display metric | `text-display` 32 px / 700 |
| Page title | 24 px / 700 |
| Section / card title | 16 px / 600 |
| Body, table | 14 px / 400 |
| Label | 13 px / 500 |
| Caption, metadata | 12 px |
| Micro (chart axis) | 11 px |

Body line-height 1.6 for Arabic. Numbers use `tabular-nums`.

## 7. Spacing and radius

- 4 px grid. Inside cards 16–20 px; between widgets 20 px; related items
  8–12 px. Spacing expresses hierarchy — not identical gaps everywhere.
- Radius: `rounded-control` 6 px (controls, chips, badges), `rounded-lg`
  8 px (panels, nested groups), `rounded-widget` 12 px (dashboard widgets).
  No pill-shaped containers.

## 8. Icons

- Lucide, stroke 1.75. Navigation 18 px, inline 14–16 px.
- `IconBox` (soft tint + stronger glyph, 32/40/44 px) only where emphasis
  aids scanning: KPI cards, widget headers. Not for every icon.

## 9. Components (`components/shared/`)

| Component | Purpose |
|---|---|
| `AppCard` | the surface primitive: `flat` · `standard` · `elevated` · `subtle`, optional `interactive` |
| `Panel` | `AppCard flat` for registry/profile screens |
| `PageHeader`, `SectionHeader` (optional icon) | page and section headings |
| `KpiCard` | icon, label, display metric, context, optional visual — secondary data only when the API provides it; no invented trends |
| `ProgressBar`, `SegmentedBar`, `LegendItem` | CSS data visuals with text alternatives; no chart library |
| `IconBox` | contextual icon emphasis with semantic tone |
| `StatusBadge` | compact semantic status: dot + text, soft tint |
| `ActivityItem` | timeline row: title › entity › actor/time |
| `Initials` | decorative initials avatar (name always shown as text) |
| `StatStrip`, `EmptyState`, `DetailList`, `Code` | registry summaries, empty states, label/value lists, bidi-safe codes |

Rules: compose, don't build one universal component; add a component only
when a screen uses it now.

## 10. RTL

- Logical properties only (`ms-`, `pe-`, `start-`, `border-s`), never
  left/right.
- Bars grow from the reading start (right). Age/ordinal sequences read
  right→left.
- Codes, dates, phone numbers and adjacent numbers are isolated with
  `<bdi>` (`Code`, `LegendItem`) so RTL never reorders or merges them.
- Back arrows point right; "open/more" chevrons point left.
- Radix components that support it get `dir="rtl"` (menus, tabs, selects).

## 11. Accessibility

- Contrast: `foreground` and `muted-foreground` (#66716E, ≈5:1) meet WCAG AA
  on white. `subtle-foreground` (#737E7B) is ≈4.2:1 — below AA for small
  text — so it is limited to non-essential metadata (timestamps, hints) that
  is always repeated or non-critical. Open item: darken it or restrict it
  further before rollout.
- Never color alone: statuses carry text; data visuals carry `aria-label`
  summaries; legends show values.
- Visible focus (`outline-ring` / 2 px ring) on every interactive element;
  keyboard order follows reading order.
- Semantic landmarks and headings: one `h1` per page (page header).

## 12. Change Log

| Version | Date | Description |
|---|---|---|
| 1.0 | 2026-09-27 | Foundations (surfaces, elevation, radius, type, motion) and Dashboard components; applied to the Operational Dashboard only |
