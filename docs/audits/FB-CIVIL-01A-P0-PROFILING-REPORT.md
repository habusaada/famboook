# FB-CIVIL-01A-P0 — Identity Dataset Profiling Report

**Generated:** 2026-10-25T11:34:11+00:00 by `tools/identity-profiling/profile_identity_export.py`.
**Privacy:** aggregate counts and format shapes only (digits → `9`, letters → `A`). No value, name, ID, phone or record appears in this report.
**Mode:** read-only, local. No database, Production or application data was touched.

## 1. Validation (before profiling)

- Manifest: 24 files, 2,399,891 rows, 36 columns.
- SHA-256 verified: 24/24; headers verified: 24/24.
- **Structure status: PASS** (PASS = every row parsed into 36 fields and the parsed count equals the manifest).
- Rows parsed: 2,399,891 — matches manifest: **True**.
- CSV parse errors: 0; wrong field count: 0; records spanning several lines: 0; NUL characters: 0.

## 2. Identity keys

| Metric | `mainid` | `sert1` |
|---|---|---|
| Rows with a value | 2,399,891 | 2,399,891 |
| Missing | 0 | 0 |
| Unique values | 2,399,891 | 2,399,891 |
| Duplicated values | 0 | 0 |

- `mainid` rows in duplicated values: 0; duplicated values whose rows are identical (ignoring `sert1`): 0; with conflicting rows: 0; max rows for one value: 1.
- Rows where `sert1` equals `mainid`: 0.
- `mainid` format: ascii_digits_only 2,399,891, digits_not_9_long 2, exactly_9_digits 2,399,889, leading_zero 1.
- `mainid` length distribution: 1 chars: 1, 3 chars: 1, 9 chars: 2,399,889.
- `sert1` format: ascii_digits_only 2,399,891, digits_not_9_long 2,399,891.
- `sert1` length distribution: 7 chars: 2,399,891.

## 3. Relationships

| Metric | `fatherid` | `motherid` | `partnerid` |
|---|---|---|---|
| Populated | 2,078,200 | 1,972,788 | 0 |
| Missing | 321,691 | 427,103 | 2,399,891 |
| Resolves to an existing `mainid` | 1,817,713 | 1,888,569 | 0 |
| …of which the target `mainid` is duplicated (ambiguous) | 0 | 0 | 0 |
| Unresolved | 260,487 | 84,219 | 0 |
| Self-reference | 0 | 0 | 0 |
| Distinct referenced values | 446,599 | 467,108 | 0 |
| Resolution rate of populated | 87.47% | 95.73% | — |

**Patterns** (counts of rows or IDs; computed on keyed hashes, targets restricted to non-duplicated `mainid`):

- father equals mother: 0
- father equals partner: 0
- mother equals partner: 0
- ids referenced both as father and as mother: 1
- parent child two cycles father: 12
- parent child two cycles mother: 20
- partner reciprocal: 0
- partner target has other partner: 0
- partner target has no partner: 0
- ids named as partner by more than one person: 0

## 4. Suspicious duplicates

- exact duplicate row groups: 0
- rows in exact duplicate groups: 0
- duplicate groups ignoring sert1: 0
- rows in duplicate groups ignoring sert1: 0
- same name parents family birthdate with different mainid groups: 8,217
- rows in those groups: 16,463

## 5. Completeness (all 36 columns)

| Column | Populated | Empty | Whitespace-only | Completeness | Distinct values |
|---|---|---|---|---|---|
| `sert1` | 2,399,891 | 0 | 0 | 100.0% | >1000 |
| `mainid` | 2,399,891 | 0 | 0 | 100.0% | >1000 |
| `name1` | 2,399,891 | 0 | 0 | 100.0% | >1000 |
| `father` | 2,399,283 | 608 | 1 | 99.97% | >1000 |
| `grandfath` | 2,399,248 | 643 | 2 | 99.97% | >1000 |
| `family` | 2,399,883 | 8 | 0 | 100.0% | >1000 |
| `gender` | 2,399,890 | 1 | 0 | 100.0% | 4 |
| `govern` | 2,399,890 | 1 | 0 | 100.0% | 9 |
| `city` | 2,399,711 | 180 | 0 | 99.99% | 61 |
| `street` | 2,126,942 | 272,949 | 4 | 88.63% | >1000 |
| `fatherid` | 2,078,200 | 321,691 | 0 | 86.6% | >1000 |
| `motherid` | 1,972,788 | 427,103 | 0 | 82.2% | >1000 |
| `partnerid` | 0 | 2,399,891 | 0 | 0.0% | 1 |
| `datebirth` | 2,399,482 | 409 | 0 | 99.98% | >1000 |
| `age` | 2,399,482 | 409 | 0 | 99.98% | 126 |
| `maritalst` | 2,399,870 | 21 | 0 | 100.0% | 7 |
| `deathdate` | 0 | 2,399,891 | 0 | 0.0% | 1 |
| `presonstate` | 0 | 2,399,891 | 0 | 0.0% | 1 |
| `relgion` | 2,399,781 | 110 | 0 | 100.0% | 7 |
| `birthplace` | 2,352,728 | 47,163 | 0 | 98.03% | 150 |
| `householdid` | 2,228,724 | 171,167 | 0 | 92.87% | >1000 |
| `householdrel` | 2,328,049 | 71,842 | 0 | 97.01% | 6 |
| `prevfamily` | 0 | 2,399,891 | 0 | 0.0% | 1 |
| `phonenum` | 0 | 2,399,891 | 0 | 0.0% | 1 |
| `worker` | 2,399,891 | 0 | 0 | 100.0% | 1 |
| `att` | 0 | 2,399,891 | 0 | 0.0% | 1 |
| `worktype` | 0 | 2,399,891 | 0 | 0.0% | 1 |
| `source` | 0 | 2,399,891 | 0 | 0.0% | 1 |
| `civilg` | 0 | 2,399,891 | 0 | 0.0% | 1 |
| `cwloc` | 0 | 2,399,891 | 0 | 0.0% | 1 |
| `mgrade` | 0 | 2,399,891 | 0 | 0.0% | 1 |
| `mstartw` | 0 | 2,399,891 | 0 | 0.0% | 1 |
| `msalary` | 0 | 2,399,891 | 0 | 0.0% | 1 |
| `select` | 2,399,891 | 0 | 0 | 100.0% | 1 |
| `job` | 0 | 2,399,891 | 0 | 0.0% | 1 |
| `fullname` | 2,399,891 | 0 | 0 | 100.0% | >1000 |

## 6. Dates (values unchanged; shapes only)

- `datebirth` — parse: parseable 2,399,482; top shapes: `9999-99-99A99:99:99` 2,399,482; decades: 1890s 138, 1900s 371, 1910s 837, 1920s 2,353, 1930s 7,977, 1940s 28,621, 1950s 65,240, 1960s 120,457, 1970s 180,337, 1980s 288,690, 1990s 410,086, 2000s 480,630, 2010s 600,402, 2020s 213,343.
- `deathdate` — parse: no values; top shapes: —; decades: —.
- `mstartw` — parse: no values; top shapes: —; decades: —.
- Death date before birth date: 0.
- `age`: 0-17 913,446, 18-59 1,311,109, 60-110 174,151, missing 409, over_110 776.

## 7. Per file

| File | Manifest rows | Parsed rows | Malformed | Match |
|---|---|---|---|---|
| t03maindata_part_00001.csv | 100,000 | 100,000 | 0 | True |
| t03maindata_part_00002.csv | 100,000 | 100,000 | 0 | True |
| t03maindata_part_00003.csv | 100,000 | 100,000 | 0 | True |
| t03maindata_part_00004.csv | 100,000 | 100,000 | 0 | True |
| t03maindata_part_00005.csv | 100,000 | 100,000 | 0 | True |
| t03maindata_part_00006.csv | 100,000 | 100,000 | 0 | True |
| t03maindata_part_00007.csv | 100,000 | 100,000 | 0 | True |
| t03maindata_part_00008.csv | 100,000 | 100,000 | 0 | True |
| t03maindata_part_00009.csv | 100,000 | 100,000 | 0 | True |
| t03maindata_part_00010.csv | 100,000 | 100,000 | 0 | True |
| t03maindata_part_00011.csv | 100,000 | 100,000 | 0 | True |
| t03maindata_part_00012.csv | 100,000 | 100,000 | 0 | True |
| t03maindata_part_00013.csv | 100,000 | 100,000 | 0 | True |
| t03maindata_part_00014.csv | 100,000 | 100,000 | 0 | True |
| t03maindata_part_00015.csv | 100,000 | 100,000 | 0 | True |
| t03maindata_part_00016.csv | 100,000 | 100,000 | 0 | True |
| t03maindata_part_00017.csv | 100,000 | 100,000 | 0 | True |
| t03maindata_part_00018.csv | 100,000 | 100,000 | 0 | True |
| t03maindata_part_00019.csv | 100,000 | 100,000 | 0 | True |
| t03maindata_part_00020.csv | 100,000 | 100,000 | 0 | True |
| t03maindata_part_00021.csv | 100,000 | 100,000 | 0 | True |
| t03maindata_part_00022.csv | 100,000 | 100,000 | 0 | True |
| t03maindata_part_00023.csv | 100,000 | 100,000 | 0 | True |
| t03maindata_part_00024.csv | 99,891 | 99,891 | 0 | True |

Interpretation and recommendations: `FB-CIVIL-01A-P0-RECOMMENDATIONS.md`.
