# FB-GEO-01 — Stage 1A: Geographic Dataset Validation

**Audit date:** 2026-10-15
**Mode:** read-only data audit (no code, schema, database, permission or
Production change; the workbook was opened read-only and its checksum is
unchanged after the audit)
**Baseline:** `CLAUDE.md`; `docs/adr/FB-GEO-01-GAZA-GEOGRAPHIC-REFERENCE.md`
(ADR-FB-GEO-01, Phase 0)
**Source:** `data/references/gaza-geographic-classification.xlsx`
**Answers ADR validations:** V1 (source review), V2 (level fit), V3 (code
scheme), V4 (single table feasibility), V5 (partial selection) — see §10.

**Result: CONDITIONAL PASS** (§11).

> **Stage 1B amendment (2026-10-15).** Source authority recorded as the
> Palestinian Ministry of Social Development (MoSD), confirmed by the
> project owner. Issuing department, release date, licence and version
> remain unverified (§2). The owner approved data-governance principles
> G1–G10 (ADR-FB-GEO-01 §3.2). Decisions D-1 to D-10 (§11) are reviewed in
> `docs/audits/FB-GEO-01-STAGE-1B-DECISION-REGISTER.md`:
> - APPROVED: D-5, D-6, D-8
> - PARTIALLY RESOLVED: D-1, D-2, D-3, D-4, D-7
> - PENDING: D-9, D-10
>
> The findings below are unchanged and remain the Stage 1A record.

Row numbers in this report are worksheet row numbers: row 1 is the header,
data rows are 2–1847.

---

## 1. Executive summary

The workbook is a single, clean, fully populated export (1,846 data rows,
13 columns, no blanks, no formulas, no hidden content). It describes a
**strict tree**: 5 Gaza governorates → 26 cities → 28 areas → 234
neighborhoods → 1,846 landmarks. Every city, area, neighborhood and landmark
code has exactly one name and exactly one parent; no level is skipped and no
code path is duplicated. It therefore fits ADR D2 (Governorate → City →
Area → Neighborhood → Landmark) without restructuring, and a single
self-referencing table is technically feasible.

No finding blocks building the reference schema. Several findings need an
explicit decision before seeding, because the ADR forbids correcting source
records:

- 10 neighborhoods whose names point to a different place than their
  parent (7 "… رفح" neighborhoods under Khan Younis; 3 "جباليا …" under Beit
  Lahia / Regional) — W1;
- 5 landmarks typed with clearly non-landmark categories (e.g. «قتل»,
  «مرض») — W2;
- 4 same-parent neighborhood pairs that differ only by spaces — W3;
- leading/trailing/double spaces in names (whitespace only, no spelling
  issue) — W4;
- **completeness cannot be proven**: the export is landmark-grained, so a
  neighborhood with no landmark cannot appear in it — W5;
- provenance (publisher, data date, licence) is **UNKNOWN** — W6.

Codes are unique **within** each level but not **across** levels (134
codes appear at more than one level), so the ADR's single `code` column must
be namespaced by level (W8).

---

## 2. Dataset inventory and provenance

| Item | Value |
|---|---|
| File | `data/references/gaza-geographic-classification.xlsx` |
| Size | 134,090 bytes |
| SHA-256 | `37826e400b77e583dbd0c2e7fe34274db6465a293437ce11d0c4d20c4f945e38` (identical before and after the audit) |
| File modified (local) | 2026-10-15 11:03:43 +03:00 (before the audit started) |
| Workbook created | 2026-08-26T09:06:12Z (`docProps/core.xml`) |
| Generator | Apache POI (`creator` and `Application`) — a programmatic export |
| Sheets | 1: `ALL_GEO_LAND_VW` (visible) — name suggests a database view export |
| Dimensions | `A1:M1847`; 1 header row + **1,846 data rows**, 13 columns |
| Publisher / owner | **UNKNOWN** at audit time. *Stage 1B amendment:* **Palestinian Ministry of Social Development (MoSD)**, provided and confirmed by the Famboook project owner; not independently verified |
| Issuing department | **Unverified** (Stage 1B) |
| Collection / reference date of the data | **UNKNOWN**; formal release date **unverified** (Stage 1B) |
| Licence / terms of use | **UNKNOWN**; **unverified** (Stage 1B) |
| Dataset version | **UNKNOWN**; **unverified** (Stage 1B) |
| Standing (Stage 1B) | Authoritative baseline for Famboook (ADR D1); external geographic accuracy **not independently verified** |
| Git status | `data/` is untracked and not git-ignored |

Structural checks:

| Check | Result |
|---|---|
| Header equals the 13 expected columns, in order | ✅ `GOV_PK, GOV_TITLE, CIT_PK, CIT_TITLE, GEO_PK, GEO_TITLE, NEI_PK, NEI_TITLE, LAN_TITLE, LAN_PK, LAN_TYPE, LAN_TYPE_TITLE, LAN_TITLE_NEW` |
| Formula cells | 0 |
| Hidden rows / hidden columns | 0 / 0 |
| Merged cells, auto-filter, data validations, defined names | none |
| Fully blank records | 0 (all 1,846 rows populated) |
| Exact duplicate rows | 0 |

---

## 3. Counts by level and governorate

### 3.1 Level totals

| Level | Column | Distinct codes | Distinct names | (code, name) pairs |
|---|---|---|---|---|
| Governorate | `GOV_PK` | **5** | 5 | 5 |
| City | `CIT_PK` | **26** | 26 | 26 |
| Area | `GEO_PK` | **28** | 28 | 28 |
| Neighborhood | `NEI_PK` | **234** | 227 | 234 |
| Landmark | `LAN_PK` | **1,846** | 1,588 (`LAN_TITLE_NEW`) / 1,688 (`LAN_TITLE`) | 1,846 |
| Landmark type | `LAN_TYPE` | 60 | 60 | 60 |

A single-table load would hold 5 + 26 + 28 + 234 + 1,846 = **2,139 nodes**.

### 3.2 By governorate

| `GOV_PK` | Governorate | Rows (= landmarks) | Cities | Areas | Neighborhoods |
|---|---|---|---|---|---|
| 41 | شمال غزة | 298 | 5 | 5 | 45 |
| 42 | غزة | 555 | 4 | 6 | 36 |
| 43 | الوسطى | 191 | 7 | 7 | 57 |
| 44 | خان يونس | 610 | 7 | 7 | 70 |
| 45 | رفح | 192 | 3 | 3 | 26 |
| | **Total** | **1,846** | **26** | **28** | **234** |

✅ Exactly the five Gaza Strip governorates (ADR D6); no other governorate
appears.

### 3.3 Cities

| Governorate | Cities (`CIT_PK` name) |
|---|---|
| شمال غزة (41) | 256 جباليا · 257 بيت لاهيا · 258 بيت حانون · 259 ام النصر · 260 المنطقة الإقليمية |
| غزة (42) | 213 غزة · 214 الزهراء · 215 المغراقة · 253 وادي غزة |
| الوسطى (43) | 218 النصيرات · 219 دير البلح · 220 البريج · 251 الزوايدة · 252 المغازي · 254 المصدر · 255 وادي السلقا |
| خان يونس (44) | 217 خان يونس · 261 القرارة · 264 الفخاري · 265 بني سهيلا · 266 خزاعة · 267 عبسان الجديدة · 268 عبسان الكبيرة |
| رفح (45) | 216 رفح · 262 الشوكة · 263 النصر |

### 3.4 Areas

Only city 213 «غزة» has real sub-areas: 1 غرب غزة (9 neighborhoods), 2 شرق
غزة (6), 3 جنوب غزة (4). **Each of the other 25 cities has exactly one
area with the city's own name** (e.g. city 217 خان يونس → area 14 خان
يونس). The Area level is a pass-through everywhere except Gaza city (W7).

---

## 4. Column-level quality

| Column | Stored type | Nulls | Blank | Edge whitespace | Double space | Notes |
|---|---|---|---|---|---|---|
| `GOV_PK` | integer-valued float | 0 | 0 | – | – | 41–45 |
| `GOV_TITLE` | text | 0 | 0 | 0 | 0 | |
| `CIT_PK` | integer-valued float | 0 | 0 | – | – | 213–268; gap 221–250 |
| `CIT_TITLE` | text | 0 | 0 | 0 | 0 | |
| `GEO_PK` | integer-valued float | 0 | 0 | – | – | 1–29; gap 4 |
| `GEO_TITLE` | text | 0 | 0 | 0 | 0 | |
| `NEI_PK` | integer-valued float | 0 | 0 | – | – | 5–574 |
| `NEI_TITLE` | text | 0 | 0 | **14** | 0 | rows 525, 1320, 1321, 1325, 1326, 1336–1339, 1344–1346, 1398, 1715 |
| `LAN_TITLE` | text | 0 | 0 | **88** | **107** | derived column (§6.3) |
| `LAN_PK` | integer-valued float | 0 | 0 | – | – | 1–2903; unique |
| `LAN_TYPE` | integer-valued float | 0 | 0 | – | – | 7–543 |
| `LAN_TYPE_TITLE` | text | 0 | 0 | 0 | 0 | |
| `LAN_TITLE_NEW` | text | 0 | 0 | **180** | **67** | e.g. rows 12, 18, 21, 581–617, 1132–1159 |

- **Codes / leading zeros:** every code is stored as a number (float with no
  fractional part; maximum 4 digits). No code is stored as text, so no
  leading zero exists in the file. Whether the upstream system ever used
  zero-padded codes cannot be determined from the file (INFO I1).
- **Unicode:** every text value is already NFC; no zero-width, bidi-control
  or no-break-space characters; no Arabic presentation forms; no
  Arabic-Indic digits.
- **Latin letters** (intentional, not defects): neighborhood «C بلوك» /
  «F بلوك» (rows 1197, 1199, 1204, 1219, 1223, 1226, 1230, 1232, 1240, 1243,
  1254, 1260, 1266); landmarks rows 62 («Brainy Bunch»), 308 («M.L.S»),
  1059 («eta»).
- **Tatweel** only in legitimate abbreviations: «" هـ "» (rows 1116, 1203,
  1215) and «منطقة الـ 86» (rows 1706, 1707). **Diacritic:** row 148
  («حركياً»).
- No placeholder / sentinel values (`-`, `0`, `غير محدد`, `n/a`, …) and no
  zero or negative codes.

---

## 5. Hierarchy integrity

| Check | Result |
|---|---|
| Code → exactly one name (GOV, CIT, GEO, NEI, LAN, LAN_TYPE) | ✅ 0 violations at every level |
| Each CIT under exactly one GOV | ✅ 26/26 |
| Each GEO under exactly one CIT | ✅ 28/28 |
| Each NEI under exactly one GEO | ✅ 234/234 |
| Each LAN under exactly one NEI | ✅ 1,846/1,846 |
| Level presence pattern | ✅ all 1,846 rows have all five levels (no skipped level; ADR V2 answered) |
| Code present without name, or name without code | ✅ 0 |
| Duplicate code paths (GOV, CIT, GEO, NEI, LAN) | ✅ 0 |
| Codes unique **across** levels | ❌ no — 134 codes are used at more than one level (e.g. `CIT_PK` ∩ `NEI_PK` = 12, `NEI_PK` ∩ `LAN_PK` = 101) → W8 |
| Duplicate name paths | 1 — rows 375 / 400 (W9) |
| Row order grouped by hierarchy | no — neighborhoods recur non-contiguously 903 times (irrelevant to loading; INFO I6) |

**Codes are proven unique within their level.** They are **not** globally
unique and must not be treated as such.

---

## 6. Landmarks and types

### 6.1 Landmark keys

- `LAN_PK`: 1,846 distinct values for 1,846 rows — ✅ unique, no nulls.
- Every row is a landmark: the workbook has **no row for a neighborhood
  without a landmark** (see W5).
- Landmarks per neighborhood: 68 neighborhoods have exactly 1, 60 have
  2–4, 106 have 5 or more; maximum 73.

### 6.2 Landmark types (60)

Largest types: مصلى 467 · مسجد/جامع 374 · مدرسة حكومية 300 · مدرسة وكالة
178 · مدرسة خاصة 49 · عيادة حكومية 47 · مقابر 43 · مبنى حكومي 40 · كلية 27 ·
مؤسسات 27 · جامعة 24 · منتزه 24 · محطة 23 · أبار 21 (… 46 further types of
1–18 rows each).

**Five rows carry types that are not landmark categories** (codes 7–51, far
below the landmark range 76–543) — W2:

| Row | `LAN_PK` | Type | `LAN_TITLE` | Neighborhood / City |
|---|---|---|---|---|
| 159 | 1218 | 8 «قتل» | قتل - فهد الاحمد الصباح | التفاح / غزة |
| 266 | 1208 | 40 «طبيعة المسكن» | طبيعة المسكن - الطوارئ | التفاح / غزة |
| 390 | 1204 | 49 «عامل» | عامل - مستشفى الأهلي العربي | البلدة القديمة / غزة |
| 947 | 2341 | 51 «العائلة جنوب الوادي» | العائلة جنوب الوادي - نقطة عبدين | المواصي الجنوبي - فش فرش الشمالي رفح / خان يونس |
| 1455 | 964 | 7 «مرض» | مرض - بلال بن رباح | النزهة - جباليا البلد / جباليا |

The names themselves look like ordinary places (a school, a hospital, a
mosque, a medical point); the **type** looks taken from an unrelated lookup.
Not corrected here.

### 6.3 `LAN_TITLE` vs `LAN_TITLE_NEW`

- **1,846 / 1,846 rows:** `LAN_TITLE` = `LAN_TYPE_TITLE` + `" - "` +
  `LAN_TITLE_NEW`, exactly, character for character. `LAN_TITLE` is a
  derived display string; `LAN_TITLE_NEW` is the landmark's own name.
- Therefore the whitespace in `LAN_TITLE` comes from `LAN_TITLE_NEW`
  (leading spaces in NEW become the double spaces after the dash in
  `LAN_TITLE`).
- Implication: store the type and `LAN_TITLE_NEW`; `LAN_TITLE` need not be
  stored (no redundant derived data).

### 6.4 Repeated landmark names

- `LAN_TITLE_NEW`: 133 names used by more than one landmark (e.g. «الهدى» ×9,
  «خالد بن الوليد» ×6). With the type prefix (`LAN_TITLE`): 109 names. These
  are overwhelmingly common mosque/school names in **different**
  neighborhoods — expected, not defects (INFO I3).
- **Same type + same name + same neighborhood, different `LAN_PK`** (possible
  duplicate landmarks, or genuinely two facilities) — W9:

| Neighborhood | Type | Name | Rows |
|---|---|---|---|
| 10 | مدرسة حكومية | الزيتون (one with trailing space) | 312, 313 |
| 218 | مسجد/جامع | السيد هاشم | 375, 400 (identical name path) |
| 464 | مصلى | السلام | 590, 635 |
| 464 | مصلى | الفاروق | 595, 637 |
| 428 | مصلى | النور | 598, 646 |
| 468 | مصلى | الشافعي | 1144, 1183 |
| 408 | مسجد/جامع | أبو بكر الصديق | 1815, 1830 |

---

## 7. Duplicates and naming anomalies

### 7.1 W1 — Neighborhood names that point to a different parent place

| `NEI_PK` | Neighborhood | Recorded parent (area / city / gov) | Rows |
|---|---|---|---|
| 558 | التحرير - الاقليمي رفح | خان يونس / خان يونس / خان يونس | 1045–1048 |
| 562 | التحرير - تجمع العطار رفح | خان يونس | 1049, 1050 |
| 564 | التحرير - تجمع الكنيس رفح | خان يونس | 1051–1053 |
| 566 | التحرير - تجمع المسلخ رفح | خان يونس | 1054–1056 |
| 560 | التحرير - تجمع بئر 19 رفح | خان يونس | 1057–1061 |
| 546 | المواصي الجنوبي - فش فرش الجنوبي رفح | خان يونس | 940–942, 1062–1067 |
| 556 | المواصي الجنوبي - فش فرش الشمالي رفح | خان يونس | 943–951 |
| 170 | الزهور - جباليا البلد | بيت لاهيا / بيت لاهيا / شمال غزة | 1571 |
| 156 | القواسمة - جباليا النزلة | بيت لاهيا / بيت لاهيا / شمال غزة | 1626, 1643 |
| 204 | المنطقة الإقليمية - جباليا النزلة | المنطقة الإقليمية / شمال غزة | 1697 |

These may be legitimate (the Mawasi coastal area spans the Rafah / Khan
Younis boundary, and «تجمع» suggests shelter clusters; Jabalia al-Nazla
borders Beit Lahia), or they may be mis-assigned. **Domain confirmation
needed; not changed.** NEI 170 also shares its exact name with NEI 184
«الزهور - جباليا البلد» under Jabalia (12 rows).

(The check also flagged NEI 338 «النصر (مواصي النصر) - مواصي النصر» under
Khan Younis; that is a false positive — «النصر» there is part of a Mawasi
locality name, not the Rafah city «النصر».)

### 7.2 W3 — Same-parent neighborhoods differing only by spaces

| Parent | Codes and stored names | Rows |
|---|---|---|
| البريج | 348 «منطقة رقم 7 » / 352 «منطقة رقم 7» | 348: 1320–1346 (11 rows); 352: 1341 |
| المغازي | 326 «منطقة رقم 8» / 356 «منطقة رقم8» | 1362 / 1356 |
| المغازي | 322 «منطقة رقم 13» / 398 «منطقة رقم13» | 1357, 1358 / 1360–1373 (8 rows) |
| وادي السلقا | 528 «أبو خيشة» / 400 «أبو خيشة » | 1393, 1394, 1399 / 1398 |

Each pair has distinct codes. Shown as-is, a user would see two identical
options under the same parent. Merging is forbidden; a display or
deactivation decision is needed.

### 7.3 INFO — Homonyms in different parents (expected)

Seven neighborhood names are used by two codes **under different
parents**: «منطقة رقم 6» (252 الزهراء / 422 البريج), «منطقة رقم 4» (250 /
530), «الرحمة» (236 المغراقة / 316 الزوايدة), «الهدى» (240 المغراقة / 244
وادي غزة), «الزهور - جباليا البلد» (170 / 184), «السلام - الشرقية» (262 بني
سهيلا / 340 عبسان الجديدة), «أبو ريدة - الشرقية» (268 خزاعة / 500 عبسان
الكبيرة); also «منطقة رقم 5» (254 الزهراء / 486 البريج). Selection lists
must always show the parent path.

Cross-level homonyms: the same name is a city and an area in 25 cases
(pass-through areas), a city and a governorate in 3 («غزة», «خان يونس»,
«رفح»), and «النصر» is a city (263) and also part of a neighborhood name.
Display must state the level.

### 7.4 INFO — Arabic spelling variants

Folding hamza forms (أ/إ/آ → ا), ى/ي, ة/ه, tatweel and spaces produces 5
neighborhood candidate groups (all listed in §7.2 / §7.3) and **67 landmark
candidate groups**, e.g. «أحمد ياسين» / «احمد ياسين» (`LAN_PK` 52 / 233,
415), «التقوى» / «التقوي» (2646, 366, 77 / 1803), «الهدى» / «الهدي» (22, 2686,
1830 / 1831), «عباد الرحمن» / «عبادالرحمن» (1723, 68, 1843 / 2118). Each is a
distinct landmark code, usually in a different neighborhood. **Not
normalized.** Search should be variant-tolerant at query time, not by
editing names.

---

## 8. Findings register

| ID | Severity | Finding | Evidence (rows) |
|---|---|---|---|
| — | **BLOCKER** | **None.** No finding prevents building the reference schema. | — |
| W1 | WARNING | 10 neighborhoods named after a different place than their parent | §7.1 |
| W2 | WARNING | 5 landmarks with non-landmark type categories | 159, 266, 390, 947, 1455 |
| W3 | WARNING | 4 same-parent neighborhood pairs differing only by spaces | §7.2 |
| W4 | WARNING | Edge/double whitespace in names: NEI 14 rows (codes 254, 348, 400, 310), `LAN_TITLE_NEW` 180 edge / 67 double | §4 |
| W5 | WARNING | Completeness unprovable: landmark-grained export; neighborhoods without a landmark cannot appear; code gaps (CIT 221–250, GEO 4); 68 neighborhoods rest on a single landmark | §3, §6.1 |
| W6 | WARNING | Provenance UNKNOWN: publisher, data reference date, licence, version; Gaza's built environment has changed, so the data date matters | §2 |
| W7 | WARNING | Area level is a pass-through for 25 of 26 cities | §3.4 |
| W8 | WARNING | Codes are unique per level only (134 cross-level collisions); ADR §4.1 `code UNIQUE` must be level-namespaced | §5 |
| W9 | WARNING | 7 possible duplicate landmarks (same type, name and neighborhood) | §6.4 |
| I1 | INFO | Codes stored as integer-valued floats; no leading-zero evidence | §4 |
| I2 | INFO | `LAN_TITLE` is 100% derived from type + `LAN_TITLE_NEW` | §6.3 |
| I3 | INFO | Repeated landmark names across neighborhoods (133 names) are expected | §6.4 |
| I4 | INFO | Homonyms across parents and levels | §7.3 |
| I5 | INFO | Orthographic variants (67 landmark groups); Unicode otherwise clean | §4, §7.4 |
| I6 | INFO | Rows not ordered by hierarchy | §5 |
| I7 | INFO | `data/` is untracked and not git-ignored; whether the workbook is committed is undecided (ADR §3.2: committed only after review) | §2 |
| I8 | INFO | Landmark names include people's names (schools / mosques named after persons) — public facility names, not personal data | §6 |

---

## 9. Database design feasibility (assessment only — no table or migration created)

### 9.1 Single self-referencing `gaza_locations` (ADR D7 preference)

**Feasible.** The data is a strict tree with exactly one parent per node, so
an adjacency list represents it without loss.

| Concern | Assessment |
|---|---|
| Parent integrity | Enforceable on a **new** table in both PostgreSQL and SQLite: `UNIQUE (id, level)` plus a composite FK `(parent_id, parent_level) → (id, level)` and a CHECK that `parent_level` is the level above `level` (GOVERNORATE: both NULL). SQLite supports composite FKs and CHECKs at `CREATE TABLE`; the SQLite limitation in this codebase applies only to adding CHECKs to *existing* tables. A trigger is not required. |
| Stable codes | Source codes are unique per level only (W8). Use `UNIQUE (level, source_code)` and a namespaced public `code`, e.g. `GOV-41`, `CIT-213`, `GEO-14`, `NEI-218`, `LAN-1189`. This refines ADR §4.1 (`code UNIQUE`) and §14 V3. |
| Optional landmarks | Landmarks are ordinary leaf nodes; nothing requires a residence to select one. |
| Landmark type | Only landmarks have a type: a small `gaza_landmark_types` reference (60 codes) with a nullable `landmark_type_id` and `CHECK ((level = 'LANDMARK') = (landmark_type_id IS NOT NULL))`. |
| `governorate_id` | Each node's governorate is unambiguous (single parent chain), so the ADR's denormalized governorate is reliable. |
| Size | 2,139 rows — trivially small. |
| Derived columns | `LAN_TITLE` should not be stored (I2). |

### 9.2 Separate tables per level

Also feasible: governorates, cities, areas, neighborhoods, landmarks (+
types), each with a plain FK to its parent and a plain `UNIQUE
(source_code)` (codes are unique per level, so the namespacing problem
disappears). It gives per-level column sets (type only on landmarks)
without CHECK logic, but needs five tables, five models and a polymorphic
or multi-column residence reference — the ADR rejected multi-column
residence references (§11).

### 9.3 Assessment

The single table satisfies every concern once codes are namespaced by
level; ADR §14 V4 is answered **positively**. A hybrid (one table for the
four administrative levels plus a separate landmarks table) is a reasonable
variant if landmarks are later decoupled — it would not change the
residence reference design. The choice remains an ADR/Stage-1B decision.

---

## 10. Residence usage assessment (no family data read or changed)

- **Partial selection (V5) — feasible.** Every node has a complete ancestor
  chain, so storing the deepest known node (city only, neighborhood, or
  landmark) always derives the full path. Because the Area level is a
  pass-through for 25 cities, a UI can auto-select the area when a city has
  exactly one.
- **Landmarks stay optional — confirmed.** Every neighborhood exists as a
  node independent of choosing a landmark. Landmarks (schools, mosques,
  clinics) are useful as a "near" reference — particularly for displaced
  families sheltering at facilities — but are never required.
- **Legacy coexistence.** The existing free-text fields
  (`original_residence_text`, current-address text,
  `displacement_location_text`) are untouched by this reference. Typical
  free-text values («مواصي خانيونس», «بني سهيلا – خانيونس») would need
  variant-tolerant matching to relate to this dataset (spelling variants,
  homonyms, pass-through areas), which confirms ADR D10: **no automatic
  inference or backfill.** Any future suggestion tool must be read-only and
  separately approved.
- **Displacement (ADR D4).** The dataset contains displacement-relevant
  localities (Mawasi neighborhoods, «تجمع» clusters), so `current_location_id`
  can represent displacement locations from the same reference — subject to
  W1 confirmation for the Rafah-named Khan Younis neighborhoods.

---

## 11. Recommendation

**CONDITIONAL PASS.**

The workbook is structurally sound and fits the approved hierarchy and
schema direction. Stage 1B (schema and seeder) may proceed **after** these
decisions are recorded (none requires editing the source):

| # | Decision needed | Suggested option |
|---|---|---|
| D-1 | Provenance (W6): publisher, data reference date, licence, version | Owner supplies them; recorded in the seeder header and ADR §3.2 |
| D-2 | Whitespace (W4): store verbatim or trimmed? | Keep `source_name` verbatim; display name trimmed and inner spaces collapsed (whitespace only, never spelling) — requires approval because ADR §3.2 says "as supplied" |
| D-3 | Non-landmark types (W2, 5 rows) | Load them **inactive** (kept, traceable, not selectable) — or exclude; owner decides |
| D-4 | Same-parent near-duplicates (W3, 4 pairs) | Load both codes; owner states which (if any) is inactive |
| D-5 | Parent anomalies (W1, 10 neighborhoods) | Load as supplied; domain owner confirms or supplies a corrected source revision |
| D-6 | Possible duplicate landmarks (W9, 7 pairs) | Load as supplied; owner confirms |
| D-7 | Code scheme (W8) | `UNIQUE (level, source_code)` + namespaced public code |
| D-8 | Completeness (W5) | Accept as the V1 reference; missing places are added only from an authoritative source revision (ADR D1/D13), never by hand |
| D-9 | Area pass-through (W7) | Keep the level (ADR D2); UI auto-selects a single area |
| D-10 | Committing the workbook (I7) | Commit under `data/references/` after D-1, or keep outside Git with its checksum recorded |

*Stage 1B status (2026-10-15):* see
`docs/audits/FB-GEO-01-STAGE-1B-DECISION-REGISTER.md` §3. The "suggested
options" above are Stage 1A proposals. They are approved only where the
register says so.

---

## 12. Methodology (reproducible)

- **Tools:** Python 3.13.0 and `openpyxl` 3.1.5, installed in an isolated
  virtual environment **outside the repository** (session scratchpad); no
  project dependency or configuration was changed.
- **Access:** `openpyxl.load_workbook(..., read_only=True)` and the raw
  `docProps/*.xml` parts via `zipfile`; the workbook was never saved.
  SHA-256 computed before and after: identical.
- **Scope:** only `data/references/gaza-geographic-classification.xlsx`.
  No private family import file, database or Production system was
  accessed.
- **Codes** were compared as integer text (`41.0` → `41`); names were
  compared **exactly as stored**. Normalization (trim, hamza/ya/ta-marbuta
  folding, tatweel removal) was only *simulated* to find candidates; no
  value was changed.
- **Checks performed:** header match; cell types; nulls/blanks; whitespace;
  formulas; hidden rows/columns; merged cells; code→name and name→code
  cardinality per level; parent uniqueness per level; composite
  (parent path, code) uniqueness; level presence patterns; duplicate rows,
  code paths and name paths; cross-level code collisions; landmark key
  uniqueness; type distribution; `LAN_TITLE` derivation; repeated names
  (global and per neighborhood); Unicode (NFC/NFKC, invisible characters,
  presentation forms, digits, Latin letters, tatweel, diacritics);
  spelling-variant folding; neighborhood-name suffix vs parent; code gaps.

Headline numbers can be reproduced with this script (run it outside the
repository; the workbook path is an argument):

```python
"""FB-GEO-01 Stage 1A — headline reproduction (read-only). python -I -X utf8 geo_repro.py <xlsx>"""
import hashlib, sys
from collections import Counter, defaultdict
import openpyxl

src = sys.argv[1]
print("sha256", hashlib.sha256(open(src, "rb").read()).hexdigest())
wb = openpyxl.load_workbook(src, read_only=True)          # never saved
print("sheets", wb.sheetnames)
rows = list(wb.worksheets[0].iter_rows(values_only=True))
H, data = rows[0], [(i + 2, dict(zip(rows[0], r))) for i, r in enumerate(rows[1:])]
print("columns", len(H), "data_rows", len(data))
K = lambda v: str(int(v)) if isinstance(v, float) else v
L = [("GOV", "GOV_PK", "GOV_TITLE"), ("CIT", "CIT_PK", "CIT_TITLE"), ("GEO", "GEO_PK", "GEO_TITLE"),
     ("NEI", "NEI_PK", "NEI_TITLE"), ("LAN", "LAN_PK", "LAN_TITLE_NEW")]
for lvl, pk, t in L:
    c2n, n2c = defaultdict(set), defaultdict(set)
    for _, r in data:
        c2n[K(r[pk])].add(r[t]); n2c[r[t]].add(K(r[pk]))
    print(lvl, "codes", len(c2n), "names", len(n2c),
          "code>1name", sum(len(v) > 1 for v in c2n.values()), "name>1code", sum(len(v) > 1 for v in n2c.values()))
for (_, ppk, _), (lvl, pk, _) in zip(L, L[1:]):
    par = defaultdict(set)
    for _, r in data:
        par[K(r[pk])].add(K(r[ppk]))
    print(lvl, "codes with >1 parent", sum(len(v) > 1 for v in par.values()))
print("nulls", sum(v is None for _, r in data for v in r.values()))
print("LAN_TITLE == TYPE - NEW", sum(r["LAN_TITLE"] == f"{r['LAN_TYPE_TITLE']} - {r['LAN_TITLE_NEW']}" for _, r in data))
for col in ("NEI_TITLE", "LAN_TITLE", "LAN_TITLE_NEW"):
    print(col, "edge_ws", sum(r[col] != r[col].strip() for _, r in data),
          "double_space", sum("  " in r[col] for _, r in data))
print("types", len({r["LAN_TYPE"] for _, r in data}))
print("per_gov", sorted(Counter((K(r["GOV_PK"]), r["GOV_TITLE"]) for _, r in data).items()))
```

Expected output: sha256 `37826e40…f945e38`; 13 columns, 1,846 rows; GOV
5/5, CIT 26/26, GEO 28/28, NEI 234 codes / 227 names (7 names with >1
code), LAN 1,846 codes / 1,588 names; 0 codes with >1 parent at every
level; 0 nulls; 1,846 derived titles; NEI edge whitespace 14; LAN_TITLE
88/107; LAN_TITLE_NEW 180/67; 60 types; per governorate 298 / 555 / 191 /
610 / 192.

---

## 13. Next steps

1. Owner records decisions D-1 … D-10 (§11), in particular provenance
   (D-1) and the handling of W1–W4.
2. Amend ADR-FB-GEO-01 §4.1 / §14 with the outcomes: namespaced codes
   (V3), single table confirmed feasible (V4), partial selection confirmed
   (V5), landmark-type reference.
3. Stage 1B (separately approved, local only): `gaza_locations` (+ landmark
   types) migration, model and idempotent seeder that reads this exact file
   (checksum-verified), plus schema/seeder tests asserting the counts in §3.
4. No Production migration or seeding without explicit approval.
