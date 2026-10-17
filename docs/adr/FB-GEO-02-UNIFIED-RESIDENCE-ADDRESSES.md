# ADR FB-GEO-02 — Unified Family Residence Addresses (RESIDENCE_UPDATE payload v3)

| | |
|---|---|
| Status | Approved by the owner 2026-10-17; implemented locally, not deployed |
| Amends | docs/02 §19 (`original_residence_text`, `displacement_location_text`) and §47 (RESIDENCE_UPDATE `submitted_data`); docs/03 residence labels; docs/04 §24 (displacement CHECK); ADR FB-GEO-01 §5 and §7 |
| Schema change | One schema-only migration drops the PostgreSQL CHECK `chk_residence_displacement_location` (G2-6). No data migration, backfill or text rewrite |
| RBAC / workflow change | None |

## 1. Decisions

| # | Decision |
|---|---|
| G2-1 | `original_residence_text` is the residence written on the **national identity card**. It is **not** the residence before displacement. Its label is «السكن حسب بطاقة الهوية». Stored values are kept as they are: nothing is migrated, re-labelled in data, inferred or cleared. |
| G2-2 | `original_location_id` is the Family's **actual residence before displacement** (structured), «الموقع الجغرافي للسكن الفعلي قبل النزوح». It is decoupled from `original_residence_text` (this amends FB-GEO-01 §5, which said they have the same meaning). |
| G2-3 | `current_location_id` is the authoritative geographic reference for the family's **latest approved actual residence**, whatever the displacement status: the current displacement residence when DISPLACED, the current actual residence when NOT_DISPLACED. Label «الموقع الجغرافي للسكن الحالي». A change of displacement status never clears it. FB-GEO-01 D4/D5 are unchanged. |
| G2-4 | The Family PWA shows the identity-card residence **read-only**. A family can neither propose nor clear it. Staff correction paths are unchanged. |
| G2-5 | New Family RESIDENCE_UPDATE submissions use **payload v3** (§2). Stored v1 and v2 requests still review, approve and apply with their own validation and base. |
| G2-6 | *Amended 2026-10-17 (supersedes the first G2-6, which cleared the text with a reviewer warning).* `displacement_location_text` is **historical** data. A change of displacement status (to NOT_DISPLACED or unknown) never clears or overwrites it: not through a v3 request, not through the Staff correction, not in `UpdateFamilyResidenceAction`. It changes only through an explicit value: a Staff correction while DISPLACED, or an explicit `null` through the Staff API. Entering a NEW text still requires DISPLACED (validation unchanged). The PostgreSQL CHECK `chk_residence_displacement_location` is dropped (migration `2026_10_23_090000`). |
| G2-7 | A kept text of a family that is not DISPLACED is presented as «مكان النزوح السابق (نص تاريخي)», never as «مكان النزوح الحالي». This applies to the Staff overview and correction dialog, the Family PWA household view and residence form, and the targeting results («سابقًا: …»). Targeting by «مكان النزوح الحالي» matches DISPLACED families only. Reports already count locations for DISPLACED only. |

## 2. Payload v3

The keys `original_location_id`, `current_location_id`, `address_text` and `displacement_status` are each optional:

- **Omitted** means unchanged. A null is never built for an omitted key.
- **Explicit `null`** clears a location reference or the detailed address.
- **`displacement_status`** stays nullable only while the registry has no status. A known status cannot be cleared.
- **Location ids:**
  - a new id must exist and be active;
  - an unchanged stored id is accepted even if inactive;
  - ids are normalized to lower case.
- **Any other key is refused (422)**, even when it is the only key sent. This covers `original_residence_text`, `governorate`, `city`, `area`, `neighborhood`, `displacement_location_text` and unknown keys.
- **Stored proposal:** the changed keys only, with `payload_version = 3`. An unchanged submission is refused.
- **Base (staleness):** the residence id and the four v3 fields. A Staff change to any of these after submission is `BASE_CHANGED`. A Staff change to historical text (`governorate` … `original_residence_text`, `displacement_location_text`) does not block approval, and a v3 APPLY never overwrites it.
- **APPLY:** unchanged. It goes through `UpdateFamilyResidenceAction`.
- **Presentation:**
  - locations are shown as names and paths, never UUIDs;
  - nothing is announced as deleted, because a v3 APPLY deletes nothing;
  - the corrected labels apply to v1/v2 requests too.

No endpoint was added: the payload goes through `POST /api/v1/family/change-requests` and the existing engine.

## 3. Compatibility

- `acceptedPayloadVersions()` is `[1, 2, 3]`.
- `dataRules()` (re-run at approve and apply) is the version-agnostic union of every key any version may carry. A stored v1/v2 request therefore keeps validating.
- `baseValuesForVersion()`:
  - v1 uses the eight text fields;
  - v2 uses the eight fields plus both ids;
  - v3 uses §2.
- Open v1/v2 requests are never rewritten.
- **v1/v2 application semantics are unchanged.** Whenever the v1/v2 handler cleared a displacement text, it stored an explicit `displacement_location_text: null` in the proposal. APPLY writes that stored null, so such a request still clears exactly as approved. Where no null was stored, the text was already null at submission. The v1/v2 base includes the text, so a later Staff change to it is `BASE_CHANGED`.
- **Rollback:** the migration's `down()` restores the CHECK. It refuses while any non-displaced residence keeps a historical text, so a rollback never deletes data silently.
- A cached older Family PWA build that still posts the v2 shape receives a 422 and must reload. This is accepted for the local pilot.

## 4. Not changed (follow-up)

These surfaces still describe `original_residence_text` as the home before displacement. Each needs its own small, approved change:

- Staff family overview / residence tab / correction dialogs / registration page;
- the Family PWA household view («السكن الأصلي»);
- the coordinator REGISTER_FAMILY form;
- the export catalog (`original_residence`). The `displacement_location` export column carries the raw text whatever the status; read it together with `displacement_status`;
- the import mapping «المدينة» → `original_residence_text` (docs/03). Whether the import source column is the identity-card residence must be confirmed before re-labelling.
