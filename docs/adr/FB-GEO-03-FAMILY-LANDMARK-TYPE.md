# ADR FB-GEO-03 — Family-Safe Landmark Type (API-D4 amended)

| | |
|---|---|
| Status | Approved by the owner 2026-10-17; implemented locally, not deployed |
| Amends | API-D4 (`docs/audits/FB-GEO-01-STAGE-2A-API-AUTHORIZATION-AUDIT.md` §12; ADR FB-GEO-01 §8.1 and §8.6); docs/06 AUTH-ADR-095 exposure |
| Schema / data change | None. No migration, re-import, normalization or flag change |
| RBAC change | None |

## 1. Context

Every landmark already stores its type: `landmark_type_code` and `landmark_type_name`, taken from `LAN_TYPE` and `LAN_TYPE_TITLE` and required by a CHECK. Its own name is `LAN_TITLE_NEW`.

API-D4 showed the type to Staff only. The reason: five source landmarks carry types from a non-landmark category (Stage 1A W2: «قتل», «مرض», …). They are kept as supplied and flagged `UNUSUAL_LANDMARK_TYPE`.

The read-only Production audit (2026-10-17) found:
- 1,846 landmarks;
- 0 without a type;
- exactly 5 `UNUSUAL_LANDMARK_TYPE` flags, all OPEN.

## 2. Decision — the family-safe type rule

Family-facing responses carry `landmark_type`, decided by one rule, `App\Support\Geography\FamilyLandmarkType`:

| Location | `landmark_type` for families |
|---|---|
| Not a LANDMARK | `null` |
| LANDMARK with an `UNUSUAL_LANDMARK_TYPE` flag whose `review_status` is OPEN or CORRECTION_REQUIRED | `null` (withheld) |
| Any other LANDMARK, including one whose flag is CONFIRMED or DISMISSED | the stored type **name**, edge-trimmed for display |

- **Never exposed:** the type code, the review flag, its status, its notes or its issue.
- **Never altered:** nothing is guessed, corrected or derived from the landmark's name, and the name itself is shown exactly as before.
- **Where it applies:**
  - the Family PWA location mirrors (`/family/change-requests/gaza-locations`: governorates, children, details);
  - the structured `original_location` and `current_location` summaries of the Family household profile.
- **Staff:** responses are unchanged. The Staff lookups still carry the full type name, and the Staff family-detail summary has no type.
- **Queries:** a list resolves every landmark's visibility in one query (`loadExists` on the review flags), with no N+1. A location that was not preloaded is still resolved safely.

## 3. Limitation — CONFIRMED / DISMISSED

A CONFIRMED or DISMISSED flag only means the review **no longer blocks display**. It does **not** mean the type was independently verified. Famboook holds no verification record for any landmark type.

Types are the ministry's values as supplied. A wrong type can only be corrected through an authorized source revision via the governed import (Stage 1B G10), never through a flag.

## 4. Display (Family PWA)

The household residence card shows the type as its own labelled tile, «نوع المعلم», after the landmark tile, for both the original and the current location. When the type is withheld there is no type tile; every level stays visible.

The residence form's selector shows «name (type)» in its options, as Staff did, whenever the API provides a type.

## 5. Not changed

- **Data:** location ids, names, parent relationships and family residence references are unchanged.
- **Change requests:** the reviewer rows (names only) are unchanged.
- **Review flags:** no workflow sets a flag's status yet; the import creates OPEN flags only. Until a reviewed status is recorded, the 5 flagged types stay hidden from families.
