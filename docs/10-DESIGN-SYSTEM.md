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

## 7a. Brand assets (FB-BRAND-01, FB-BRAND-02)

- The official identity, used **untouched** (never redrawn, recoloured,
  cropped, distorted or mirrored in RTL), transparent backgrounds:
  - full logo, light surfaces: `frontend/public/brand/FamboookLogoColoredLight.svg`
    (viewBox 432 × 144, 3 : 1);
  - full logo, dark surfaces: `frontend/public/brand/FamboookLogoColoredDark.svg`
    (432 × 144);
  - standalone icon: `frontend/public/brand/FamboookLogoColoredIcon.svg`
    (143.57 × 144.39);
  - `FamboookLogoColored.svg` (the FB-BRAND-01 full logo) remains the source
    of the PDF and Filament copies below.
- One component renders them: `BrandLogo` (`components/shared/brand-logo.tsx`,
  `variant` full / icon). Only the HEIGHT is set; the width follows. The full
  logo follows the EXISTING theme mechanism — the `.dark` class behind
  Tailwind's `dark:` variant (`globals.css`): the light file by default, the
  dark file only under `.dark`. No theme is enabled by the brand; today the
  light file is always shown. Readable from about 28 px tall: Staff sidebar,
  mobile sheet and Family header `h-8`, public pages `h-12`.
- The full logo stands alone on the Family authentication pages and in the
  Family Portal header (one row: logo at the RTL start, logout at the end).
  «بوابة الأسرة» stays only under the logo on the Family Card and the public
  verification page; the Staff lockups keep «بوابة الطاقم». Role labels such
  as «رب الأسرة» belong to user-information areas («حسابي», «بياناتي
  الشخصية», member cards), never beside or under the logo.
- **The logo's colours stay inside the assets.** They are never extracted
  into the application palette: tokens, Tailwind, `globals.css`, the
  Filament colours and every UI colour are unchanged by the brand.
- Icons generated from the official icon (no artwork edits): `app/icon.svg`
  (byte copy), `app/favicon.ico` (16 / 32 / 48), `app/apple-icon.png` (180),
  and `public/icons/v2/` (192, 512, maskable 192 / 512, Apple 180, SVG).
  Maskable and Apple icons place the unchanged icon on the manifest
  `background_color` `#F7F7FA`, inset so its silhouette survives the
  platform mask. The legacy `public/icons/*` and `public/brand/famboook-logo.svg`
  stay for one release (installed apps).
- Copies elsewhere, byte for byte: `backend/public/brand/` (Filament logo
  and favicon), `backend/resources/pdf/famboook-logo-full.svg` (Family Card
  PDF, mPDF, 30 mm wide).
- Copyright footer: `SiteFooter` (`components/shared/site-footer.tsx`) —
  «© 2026 فامبوك. جميع الحقوق محفوظة.» and «تطوير المركز الفلسطيني للبيانات
  والتنمية المجتمعية – PCDCD» linked to https://www.facebook.com/pcdcd.gaza (new
  tab, `rel="noopener noreferrer"`); small text, existing tokens. On PUBLIC
  pages only (FB-BRAND-02): the Family authentication pages (login,
  activation, password recovery), the Staff login and the public
  verification page — never inside the authenticated Family Portal or Staff
  application.

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

## 11a. Family Portal (PWA-0 direction)

Approved 2026-10-02 as direction; not implemented. Full text:
`11-FAMILY-PORTAL.md` §23–§24.

- Same brand, not a sub-brand: formal, trusted, clean, digital, modern,
  mobile-first. Not a playful consumer app and not a shrunken admin
  dashboard.
- **Palette amendment (scoped).** §3 documents — and the Staff application
  implements — a teal brand scale (`#176B63`). The Family Portal is
  approved with the kingfisher / spring palette: UI primary `#751BD5`,
  hover `#651CAD`, brand purple `#892CF1`; spring (`#07F49E`, strong
  `#00BB76`) is semantic only. Kingfisher Purple is the Family Portal
  identity (docs/11 FP-ADR-022). §3 is not changed: the Staff UI keeps its
  teal scale during the Family PWA program, and no Staff palette migration
  is started unless separately authorized.
- Neutrals: background `#F7F7FA`, surface `#FFFFFF`, text `#18181B` /
  `#52525B` / `#71717A`, border `#E4E4E7` / `#F0F0F2`. About 80% neutral,
  15% purple, 5% semantic.
- Type direction: page title ~24/700, section ~18/600, card title
  ~16/600, body ~15–16/400, label ~14/500, caption ~12–13/400. Larger than
  the Staff ramp of §6 because the Portal is read on phones.
- Geometry: page padding 16px, section gap 24px, card padding 16px, card
  radius 14–16px, control radius 10–12px, badge radius ~8px. Borders and
  spacing carry hierarchy; little or no shadow.
- One primary action per screen; long forms are stepped.
- Status language: icon + text + color (§11). Green verified / approved /
  completed; amber pending / under review; orange needs attention /
  returned; red rejected; gray draft; kingfisher informational.
- Codes and identifiers render LTR (§10).

These become tokens in PWA-3; no raw hex in components (§4).

## 12. Change Log

| Version | Date | Description |
|---|---|---|
| 1.0 | 2026-09-27 | Foundations (surfaces, elevation, radius, type, motion) and Dashboard components; applied to the Operational Dashboard only |
| 1.1 | 2026-10-02 | §11a Family Portal visual direction (PWA-0); kingfisher / spring palette scoped to the Family Portal — the Staff teal scale of §3 is unchanged. Direction only, not implemented |
| 1.2 | 2026-10-11 | §7a Brand assets (FB-BRAND-01): official full logo and icon, `BrandLogo`, generated favicon / PWA icons (`/icons/v2`), `SiteFooter`; the brand's colours stay inside the assets — no palette change |
| 1.3 | 2026-10-12 | §7a FB-BRAND-02: light / dark full-logo variants through the existing `.dark` mechanism (no theme enabled); the logo alone on the Family auth pages and portal header; footer on public pages only |
