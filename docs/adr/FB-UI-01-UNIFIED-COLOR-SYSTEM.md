# ADR FB-UI-01 — Unified Famboook Color System

| | |
|---|---|
| Status | Approved by the owner 2026-10-17; implemented locally, not deployed |
| Supersedes | The Staff-teal restriction of FP-ADR-022, PFP-013 and A-08 (docs/11), and docs/10 §3 / §4 / §11a |
| Scope | Light-mode color tokens only (`frontend/app/globals.css`) |
| Not changed | Logos, typography, spacing, radii, shadows, layout, navigation, components, dark mode |

## 1. Context

The Staff application used a teal brand scale, "Calm Institutional" (primary `#176B63`).

The Family Portal was approved in 2026-10 with the Kingfisher palette, scoped to `[data-portal="family"]`. FP-ADR-022, PFP-013 and A-08 kept the Staff UI teal "unless separately authorized". By then the brand assets were already Kingfisher purple everywhere, including in the Staff shell.

## 2. Decision

The owner explicitly authorizes the existing Family Portal Kingfisher palette as the **single Famboook color system for both portals**. No new color is introduced.

## 3. The palette (light mode)

| Group | Tokens |
|---|---|
| Brand | `brand-900 #42047E`, `brand-800 #53188B`, `brand-700 #751BD5`, `brand-600 #892CF1`, `brand-500 #9F4FFD`, `brand-100 #F2E7FF`, `brand-50 #F9F5FF` |
| Surfaces | `canvas #F7F7FA`, `surface-1 / surface-3 #FFFFFF`, `surface-2 #FAFAFC`, `surface-hover #F4F4F7`, `surface-pressed #ECECF0`, `surface-selected = brand-50`, `stroke-subtle #F0F0F2` |
| Text and lines | `foreground` / `card-foreground` / `secondary-foreground #18181B`, `muted-foreground #52525B`, `subtle-foreground #71717A`, `border #E4E4E7`, `input #D4D4D8`, `secondary` / `muted #F4F4F5` |
| Interaction | `primary = brand-700`, `ring = brand-600`, `accent = brand-50`, `accent-foreground = brand-900` |
| Success | `success #09724D`, `success-soft #EEFFF8` |

## 4. Token architecture

- **`:root` is the only definition** of the brand, neutral, interaction and semantic colors. The Tailwind `@theme inline` mappings are unchanged and resolve to it.
- **Shared references, not literals:** `popover-foreground` and `sidebar-foreground` reference `foreground`, and `sidebar-border` references `border`.
- **`[data-portal="family"]` keeps only what is Family-specific:**
  - `--radius: 0.75rem`;
  - its lighter `--elevation-1` / `--elevation-2`;
  - `--family-primary-hover #651CAD`, `--family-spring #00BB76`, `--family-spring-bright #07F49E`.
- **Staff keeps its own geometry and shadows:** `--radius: 0.5rem` and elevation 1–3.

## 5. Semantic exceptions

Colors that carry meaning are never brand-colored:
- `warning #9A5B06` / `#FDF3E1`;
- `danger #B42318` / `#FDECEA`;
- `info #1D5F8C` / `#E8F1F8`;
- `destructive #B42318`.

These keep their values. `chart-3` / `chart-4` / `chart-5` keep theirs too, since no Kingfisher equivalent exists. Success uses the Kingfisher spring step.

## 6. Family overlays

Select, Dialog, Sheet and DropdownMenu render into `document.body`, outside `[data-portal="family"]`. Inside the Family Portal they therefore used the Staff tokens: a teal-tinted focused option, a teal focus ring and `#17201E` text.

They now resolve the shared purple palette. **This alignment is explicitly approved.** Portal mounting, positioning and accessibility semantics are unchanged.

## 7. Dark mode — out of scope

The `.dark` block is not active (nothing sets `.dark`). It is left **unchanged**, with its earlier teal-based values. A dark palette for the unified system needs its own architectural decision; none is invented here.

## 8. Regression and accessibility requirements

`frontend/tests/color-system.test.ts` guards the following:
- the canonical `:root` values;
- no color redefined in the Family scope;
- unchanged warning, danger, info and destructive;
- Staff and Family radius and shadows;
- valid Tailwind mappings;
- no teal value in the active light theme;
- the dark block kept as is.

WCAG contrast of the key pairs, computed:

| Pair | Ratio |
|---|---|
| white on primary | 7.31 |
| brand-800 on brand-50 | 10.44 |
| muted text on canvas | 7.23 |
| subtle text on white | 4.83 |
| success on success-soft | 5.76 |
| focus ring brand-600 against white | 5.68 |

Each is equal to or better than the previous Staff value. A visual pass of both portals is required before release.
