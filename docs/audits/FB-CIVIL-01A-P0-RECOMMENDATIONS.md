# FB-CIVIL-01A-P0 — Profiling Recommendations

**Date:** 2026-10-25.
**Basis:**
- `FB-CIVIL-01A-P0-PROFILING-REPORT.md` and `FB-CIVIL-01A-P0-QUALITY-METRICS.json`, generated 2026-10-25T11:34:11Z;
- the full export: 2,399,891 rows, 24/24 checksums and headers verified, structure PASS.

**Status:** recommendations only. Every open decision still needs explicit approval (`FB-CIVIL-01A-OPEN-DECISIONS.md`).

**Labels:**
- **[MEASURED]** — an aggregate fact from the profiling.
- **[PROPOSED]** — a recommendation.
- **[OPEN]** — still needs a decision or the source owner's confirmation.

## 1. Identity keys (OD-01, OD-02, OD-16)

| Finding [MEASURED] | Recommendation |
|---|---|
| `mainid`: 2,399,891 values, 0 missing, **0 duplicates** (every row unique) | **[PROPOSED] Use `mainid` as `source_key`.** It is complete and unique in this export. Uniqueness must be re-checked on every import: a duplicate in a later export is a CONFLICT, per the design |
| `mainid` format: 2,399,889 are exactly 9 ASCII digits; **2 values are 1 and 3 digits long**; 1 value has a leading zero | <ul><li>Keep raw strings (the leading zero is preserved).</li><li>Flag the 2 short values as `INVALID_KEY` issues and don't publish them until the source owner explains them [OPEN].</li><li>Add no check-digit rule: none is evidenced.</li></ul> |
| `sert1`: complete, unique, always 7 digits, never equal to `mainid` | **[PROPOSED]** Treat `sert1` as a **source row serial**, not an identity: store it raw, but never use it as a key or for matching. Its meaning needs the source owner's confirmation [OPEN] |

## 2. Relationships (OD-03)

| Finding [MEASURED] | Recommendation |
|---|---|
| `fatherid` populated in 2,078,200 rows; 87.47% resolve to an existing `mainid`; **260,487 unresolved** | <ul><li>References point to `mainid` in most cases.</li><li>Unresolved references are expected for parents outside this dataset (e.g. not registered, older generations, outside the region), but this is **not proven** [OPEN].</li><li>Store unresolved references raw; never create placeholder persons.</li></ul> |
| `motherid` populated in 1,972,788 rows; 95.73% resolve; 84,219 unresolved | Same as `fatherid` |
| Self-references 0; father = mother 0 | Good structural sign |
| **1 ID** used both as a father and as a mother; **6 father pairs and 10 mother pairs** in which each person is listed as the other's parent (12 and 20 rows) | **[PROPOSED]** Record these as `RELATIONSHIP_INCONSISTENT` issues in the import. Keep the rows; never feed them to 01C or 01D |
| Reference formats: 3 `fatherid` and 1 `motherid` values aren't 9 digits | Flag them as issues |
| **`partnerid` is empty in all 2,399,891 rows** | Spouse links cannot come from this export. Any future spouse feature needs another source [OPEN] |
| `householdid` 92.87% populated; `householdrel` has 6 distinct values (including empty) | Store raw. Household semantics need the source owner's code list [OPEN] |

**Conclusion:** parent links are usable for **future** analysis only after OD-03 is decided. The 01A engine builds no relationship structure.

## 3. Completeness and column scope (data minimization)

| Finding [MEASURED] | Recommendation |
|---|---|
| **Empty in every row:** `partnerid`, `deathdate`, `presonstate`, `prevfamily`, `phonenum`, `att`, `worktype`, `source`, `civilg`, `cwloc`, `mgrade`, `mstartw`, `msalary`, `job` (14 columns) | <ul><li>Store them raw, to preserve the source structure as approved.</li><li>Verify on every import that they stay empty, and report any column that becomes populated, especially `phonenum`, `msalary` and `presonstate`, since new sensitive data would then enter the store.</li><li>Whether to drop always-empty columns entirely is a minimization decision [OPEN].</li></ul> |
| **A single value in every row:** `worker`, `select` | Constant columns; store raw. Meaning unknown [OPEN] |
| Highly complete: names, `gender` (4 distinct including empty), `govern` (9), `city` (61), `maritalst` (7), `relgion` (7), `birthplace` (150), `datebirth` / `age` 99.98% | Code lists for the categorical columns must come from the source owner before any display [OPEN] |
| `relgion` is populated in 2,399,781 rows | **Special-category data.** No use in 01A; any future display needs an explicit privacy decision (OD-08) |

## 4. Dates and life status

| Finding [MEASURED] | Recommendation |
|---|---|
| `datebirth`: one shape (`YYYY-MM-DDThh:mm:ss`), all 2,399,482 non-empty values parse; 409 missing; birth decades 1890s–2020s | Store raw. A future derived `birth_date` column is safe to compute from this single format |
| `deathdate` and `presonstate` are **empty everywhere**; 776 rows have `age` over 110 | **The export carries no reliable life status.** People born in the 1890s–1910s are listed with no death information. Any future feature must **not** treat a reference record as a living person [OPEN, important for 01B and 01C] |
| `age` is populated exactly where `datebirth` is (409 missing in both) | `age` is probably derived at export time and goes stale over time. Never use it; derive age from `datebirth` if ever needed |

## 5. Duplicates and possible double identities

| Finding [MEASURED] | Recommendation |
|---|---|
| Exact duplicate rows: 0; duplicates ignoring `sert1`: 0 | The export has no copied rows |
| **8,217 groups (16,463 rows)** share the same first name, father's name, grandfather's name, family name and birth date, but have different `mainid` | These may be twins with the same name (unlikely at this scale), data-entry duplicates, or one person registered under two IDs. **[PROPOSED]** Report this count on every import as an aggregate metric only; never merge records automatically (consistent with "never automatically merge duplicate Persons"); leave review to a future, separately approved process [OPEN] |

## 6. Consequences for the 01A design

1. **Key:** `mainid` is the recommended `source_key`. It's still configurable, and duplicates are checked every run.
2. **Gates (OD-06), proposed starting values:**
   - `INVALID_KEY` on non-9-digit `mainid` (2 rows today);
   - reject the run if a `mainid` is duplicated in more than 0.1% of rows (0 today);
   - reject if any always-empty column becomes populated, until reviewed.
3. **Snapshot mode (OD-05):** the export is a complete 2.4-million-row table, consistent with snapshot semantics. Confirm with the source owner.
4. **Blank values (OD-04):** keep the "don't overwrite non-blank with blank" default. The export cannot tell NULL from empty, so a blank in a later export may be a loss of information.
5. **Storage:** 14 columns are always empty, so the effective row size is smaller than the 36 columns suggest. The capacity estimate in the database proposal is conservative.

## 7. Still unresolved (needs the source owner or management)

- **Source owner:** what `sert1`, `worker` and `select` mean, and the code lists for `gender`, `maritalst`, `relgion`, `householdrel`, `govern`, `city` and `birthplace`.
- **The 2 short `mainid` values** and the 4 non-9-digit parent references.
- **Unresolved parent references:** why 260,487 `fatherid` and 84,219 `motherid` values match no `mainid`.
- **Life status:** the absence of death and status data, and whether another source provides it.
- **Possible double identities:** the 8,217 same-name, same-birth-date groups.
- **Import model:** whether future exports are full snapshots (OD-05).
- **Legal basis (OD-08):** lawful basis, source authority and Privacy Policy coverage still gate any Production load. This profiling processed the data **locally and in aggregate only**.
