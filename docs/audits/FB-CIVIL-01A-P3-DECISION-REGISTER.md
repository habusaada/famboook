# FB-CIVIL-01A-P3 — Decision Register

**What this register is:** it records every decision P3 depends on. **Nothing here was decided by the engineering work.**
- Where a decision is open, the code **fails closed**: it refuses to prepare.
- Where a choice was needed to build anything at all, it is marked **ENGINEERING DEFAULT**, listed with its effect, and open to change through a new comparison or policy version.

| ID | Decision | Status | Current behaviour (until decided) | Options implemented | Owner |
|---|---|---|---|---|---|
| **D-01** (OD-04) | **Blank-field policy.** Does a blank (NULL or `""`) in a later source replace a published non-blank value? | **APPROVED (P3.1): `PRESERVE_EXISTING_ON_BLANK`** | <ul><li>New identities are added and changed values updated.</li><li>Where an incoming field is blank and the existing value is not, the existing value is kept in the published (effective) version.</li><li>A row that differs only by blanks is UNCHANGED.</li><li>**The original incoming row is preserved exactly**, NULL or `""` as received, in `identity_ref.release_incoming` (insert-only), whenever the effective version differs from it.</li><li>History: earlier versions are untouched, and the new version is attributed to its run and release.</li><li>`verify_release` proves both the effective versions and the preserved rows.</li></ul> | Policy `IDREF-POLICY-1` (default). `UNRESOLVED`, `ACCEPT_SOURCE` and `QUARANTINE_ROW` remain implemented (`IDREF-POLICY-0` keeps the fail-closed baseline). | Approved by the project owner |
| **D-02** (OD-06) | **Quarantine policy.** May a release be published while excluding quarantined rows, and up to what threshold? | **APPROVED (P3.1): `ZERO_UNRESOLVED_QUARANTINE`** | **No release with any unresolved quarantined row is prepared or published** (`QUARANTINE_NOT_ALLOWED`). This is enforced in PHP and by the database (`max_rows = 0`). The source must be corrected and restaged. | Policy `IDREF-POLICY-1`. Threshold-based partial publication stays implemented but not approved. | Approved by the project owner |
| **D-03** | **Duplicate keys.** What happens to identical duplicate rows (P0 found none)? | OPEN | Every row of a duplicated key is quarantined, identical or not (so D-02 applies) | — | Source owner |
| **D-04** (OD-16) | **Non-9-digit keys.** Publish or exclude them? | Recommended by P0, not formally approved | Published; warning `MAINID_NOT_9_DIGITS`. The operational national-ID rule is not applied to reference keys. | — | Source owner |
| **D-05** (OD-02) | **Does `sert1` take part in change detection?** It is a source row serial of unknown meaning. | OPEN; **ENGINEERING DEFAULT**: included | All 36 columns are compared (IDREF-CMP-1). If `sert1` is renumbered between exports, every identity becomes CHANGED and gets a new version. That costs storage; no data is lost. | A new comparison version could exclude it; this needs the decision first | Source owner |
| **D-06** | **Does `age` take part in change detection?** It is derived at export time and changes yearly. | OPEN; **ENGINEERING DEFAULT**: included | As D-05: yearly re-exports would version most identities | As D-05 | Source owner |
| **D-07** | **NULL vs `""`.** Should they count as different values? | OPEN; **ENGINEERING DEFAULT**: different (exact comparison) | `null` ↔ `""` is a CHANGED version. Both count as blank for D-01. P2 maps unquoted empty to `null` and quoted `""` to `""` (P2-OI-05). | A new comparison version could treat them as equal | Source owner |
| **D-08** | **Control characters in values.** | **ENGINEERING DEFAULT**: quarantined (`VALUE_CONTROL_CHARACTER`) | So D-02 applies. P0 measured no NUL; other control characters were not profiled. | — | Data protection / source owner |
| **D-09** (OD-03) | **Parent references.** Do `fatherid`/`motherid` reference `mainid`? | OPEN | Stored raw. Unresolved references are counted as warnings. **No relationship is created.** | — | Source owner |
| **D-10** | **Spouse links.** | Decided by the brief: none | `partnerid` stored raw; a populated value is only a warning. No inference. | — | — |
| **D-11** (OD-05) | **Snapshot vs partial exports.** | OPEN | Snapshot semantics: identities absent from a source are CARRIED_FORWARD, never deleted. There is no row-count-drop gate yet. A partial export would be carried forward, not refused. | — | Source owner |
| **D-12** | **Retention of releases, presence and abandoned preparations** (P1-OI-06) | OPEN | Everything is kept. Each release stores its full presence set: about 2.4 million rows, roughly 0.2 GB per release at full scale. | — | Management / DBA |
| **D-13** | **Rollback to an earlier release** (P1-OI-07) | OPEN | Not implemented. One release per run; going back means a new run built from older data. | — | Operations |
| **D-14** | **Who may run prepare and publish, and in which window** (OD-18) | OPEN | CLI only, explicit `--run`/`--release`, interactive confirmation (or `--yes`) for publication, **refused in the production environment** | — | Management |
| **D-15** (OD-08) | **Lawful basis for loading the real dataset** | OPEN; **blocks any real-data use** | P3 was built and tested with synthetic data only | — | Legal / management |
| **D-16** | **Unattended automatic activation** | Excluded by the brief | Not implemented: publication requires the explicit operator step | — | — |

## Changes in P3.1

- **D-01 and D-02 approved,** and implemented as the new default policy `IDREF-POLICY-1`.
- **D-03 now resolved in practice:** under zero quarantine, a duplicated key blocks the release.
- **Earlier statement revised:** P3 called keeping the old value "a silent repair". With the raw incoming row preserved and verified, it is no longer silent.

## How a decision becomes effective

1. Record the decision (who, when, scope) in this register.
2. **Policy decisions (D-01, D-02):** add an entry to `IdentityImportPolicy::REGISTRY` with a new id, for example `IDREF-POLICY-1`. Releases record which policy prepared them.
3. **Comparison decisions (D-05, D-06, D-07):** create a new comparison version, for example `IDREF-CMP-2`, with its rules in `assess_run`, a reviewed SQL change, and tests. Existing releases keep their version.
4. Run the P3 test suite and a synthetic rehearsal before any use.
