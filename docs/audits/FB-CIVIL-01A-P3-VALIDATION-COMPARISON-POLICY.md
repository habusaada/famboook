# FB-CIVIL-01A-P3 — Validation and Comparison Policy

**Status:** implemented and tested with synthetic data on the disposable local test cluster.
**Applies to:** releases prepared under comparison policy **IDREF-CMP-1**, with the import policies of §5.

**Not decided here:** this document records **no** business decision. Every point that needs one is listed in `FB-CIVIL-01A-P3-DECISION-REGISTER.md`, and the code fails closed on all of them.

## 1. Where the policy lives

| Element | Implementation |
|---|---|
| Classification | `identity_ref_staging.assess_run(run, base_release, blank_field_policy)`. **One SQL function**, used by preview (`--preview`), preparation (`begin_preparation`) and verification (`verify_release`), so they cannot disagree. |
| Warnings | `identity_ref_staging.assess_warnings(run, base_release)` |
| Policy gates | `identity_ref.assert_policy_allows(…)`, called by `begin_preparation` and again by `verify_release` |
| Release-level checks | `identity_ref.assert_run_complete(run)`, plus the PHP checks in `PublishIdentityReleaseAction::assessRun` (same codes) |
| Policy record | `identity_ref.release_preparations`: policy id, SHA-256 fingerprint, comparison version, blank-field policy, quarantine thresholds. Frozen once written. |

## 2. Comparison policy IDREF-CMP-1

| Aspect | Rule |
|---|---|
| **Identity key** | `mainid` as **opaque text**. No integer cast, no zero stripping or padding, no 9-digit rule; `012345678`, `7` and `123` are distinct, valid keys. |
| **Compared attributes** | **All 36 source columns** as stored, including `sert1` and `age` (see D-05 and D-06). |
| **Canonical representation** | The stored `jsonb` object of the 36 raw values. Its text form is deterministic in PostgreSQL: keys ordered by length then bytes, `": "` and `", "` separators. Source column order does not affect it. |
| **Encoding** | UTF-8 (`convert_to(payload::text, 'UTF8')`) |
| **Hash** | **SHA-256.** It is the same `content_sha256` the P1 trigger stores on `record_versions`, so "unchanged" means "the version already exists". |
| **Whitespace** | Significant: never trimmed or collapsed. `'شارع '` ≠ `'شارع'`. |
| **NULL vs empty string** | Different values for comparison: `null` ≠ `""`. Both count as **blank** for the blank-over-value rule (§4). Unquoted empty becomes `null`, quoted `""` stays `""` (P2). |
| **Dates** | Opaque text: no parsing or reformatting. `1990-01-15T00:00:00` ≠ `1990-01-15`. |
| **Unicode** | Byte-exact: no normalization (NFC and NFD forms differ). |
| **Versioning of the rules** | The comparison version is recorded on every preparation, and the database accepts only `IDREF-CMP-1` (CHECK constraint plus a function check). Any change to the rules needs a new version, a code change in `assess_run`, and a new decision-register entry. Releases keep the version they were prepared with. |

**Original values** are stored unchanged in `record_versions.source_payload`. Nothing is normalized or repaired.

## 3. Classification of each staged row

Each staged row is compared with the release it is built on (the active release at preparation; none for a first release).

| Class | Condition |
|---|---|
| **CONFLICT** | Any quarantine rule in §4 applies |
| **NEW** | Not a conflict, and the key is absent from the base release |
| **UNCHANGED** | Not a conflict, and the content hash equals the base release's version for the key |
| **CHANGED** | Not a conflict, and the content hash differs from the base version |

**Identities in the base release but not accepted from this source** (missing from the source, or quarantined) are **CARRIED_FORWARD** with their unchanged base version. They are never deleted.

**Versions:**
- **NEW:** an anchor plus version 1.
- **CHANGED:** version n+1. If the content equals an older version, that version is reused, so a payload is never duplicated.
- **UNCHANGED:** no version is written; the release points at the existing version.

## 4. Validation and quarantine

### 4.1 Release-level (fatal): nothing is prepared

| Code | Meaning |
|---|---|
| `RUN_NOT_FOUND`, `RUN_NOT_RUNNING` | Unknown run, or a finished one (REJECTED, FAILED, SUCCEEDED) |
| `SOURCE_TABLE_UNSUPPORTED` | The manifest's source table is not `t03maindata` (invalid source mapping) |
| `STAGING_INCOMPLETE` | A file is not `STAGED`, or the run has no file |
| `CHECKSUM_NOT_VERIFIED` | A file's actual SHA-256 is missing or differs from the manifest |
| `ROW_COUNT_INCONSISTENT` | Staged rows, checkpoints, file counters, run counter and manifest disagree |
| `STAGING_EMPTY` | No staged row |
| `RELEASE_STATE_INVALID` | The run already has a release that is not a BUILDING release prepared under the same policy |
| `COMPARISON_POLICY_UNSUPPORTED` | The comparison version is not implemented |

The 36-column mapping is enforced by the staging CHECK constraint (`is_valid_source_payload`), so a row with other columns cannot exist.

### 4.2 Row-level (quarantine): the row is excluded, and an existing identity keeps its version

| Code | Rule | Reason |
|---|---|---|
| `MAINID_MISSING` | Key NULL or `""` | No identity |
| `MAINID_DUPLICATE` | Key appears more than once in the run; **every** row of it is quarantined | Ambiguous source; never pick one silently |
| `MAINID_SURROUNDING_SPACE` | Leading or trailing whitespace | Refused by `identity_ref.records`; never trimmed |
| `MAINID_TOO_LONG` | More than 64 characters | Refused by `identity_ref.records` |
| `VALUE_CONTROL_CHARACTER` | A C0 control character (except TAB, LF, CR) or DEL in any value | Unsupported, likely corrupted value |
| `BLANK_OVER_VALUE` | Only under blank-field policy `QUARANTINE_ROW`: a non-blank base value becomes blank | OD-04 |

**Not quarantined:**
- non-9-digit keys;
- unresolved father or mother identifiers;
- a populated `partnerid`;
- invalid-looking dates;
- unknown codes.

### 4.3 Informational warnings: counted, never blocking

| Code | Meaning |
|---|---|
| `MAINID_NOT_9_DIGITS` | A legitimate reference key that differs from the operational national-ID format |
| `FATHERID_UNRESOLVED`, `MOTHERID_UNRESOLVED` | The value is neither a key of this run nor of the base release. It is kept as a candidate identifier; no relationship is inferred or stored. |
| `PARENT_IS_SELF` | Father or mother equals the row's own key |
| `PARTNERID_POPULATED` | `partnerid` has a value; P0 found it always empty. No spouse is inferred. |
| `VALUE_REPLACEMENT_CHARACTER` | U+FFFD in a value (possible earlier encoding damage) |
| `P0_EMPTY_COLUMN_POPULATED` | One of the 14 columns P0 found always empty now has a value (new sensitive data may be entering) |

Warnings are recorded as aggregate counts on the preparation, not as issue rows, so no per-row trail of these values is created.

### 4.4 Issue rows (value-free)

- **Recorded:** P2 records the key findings (`MAINID_*`) with file and line. P3 adds `VALUE_CONTROL_CHARACTER` and `BLANK_OVER_VALUE` quarantines.
- **Content:** code, file and line only. No key, no value and no fingerprint.

## 5. Import policies

| Policy | Blank-field (OD-04) | Quarantine (OD-06) | Status |
|---|---|---|---|
| **`IDREF-POLICY-1`** (default since P3.1) | **`PRESERVE_EXISTING_ON_BLANK`** (D-01): an incoming blank keeps the existing non-blank value in the effective version. Classification and the version hash use the effective payload. The raw incoming row is preserved in `identity_ref.release_incoming` whenever it differs. | **`ZERO_UNRESOLVED_QUARANTINE`** (D-02): any quarantined row → `QUARANTINE_NOT_ALLOWED`, nothing prepared | **Approved** (P3.1) |
| `IDREF-POLICY-0` | `UNRESOLVED`: any blank-over-value change → `POLICY_BLANK_FIELD_UNRESOLVED`, nothing prepared | `UNRESOLVED`: any quarantined row → `POLICY_QUARANTINE_UNRESOLVED`, nothing prepared | The earlier **no-decision baseline**; fails closed |
| `TEST-SYNTHETIC` | any | any | **Tests only.** `IdentityImportPolicy::synthetic()` refuses outside the `testing` environment; the CLI accepts registry ids only; verify and publish refuse it outside tests. |

**Under `IDREF-POLICY-1`:**
- A release can be published only if it has **no quarantined row**.
- Blank incoming fields never erase existing values, and the original incoming row stays recorded.
- Missing identities are carried forward.

**Other policy options implemented** (not approved):

| Option | Meaning |
|---|---|
| `ACCEPT_SOURCE` | The source's blank becomes the new version |
| `QUARANTINE_ROW` | The row is quarantined and the identity keeps its previous version |
| Quarantine thresholds | Publish while excluding quarantined rows if `conflicts ≤ max_rows` and `conflicts ≤ max_ratio × staged`; otherwise `QUARANTINE_THRESHOLD_EXCEEDED` |

**Keeping the previous value (P3.1, approved).** The effective version combines the existing non-blank values with the incoming row. This is not a silent repair, because:
- the exact incoming row is kept in `release_incoming`;
- `verify_release` checks that it was;
- earlier versions are untouched.

**Adding an approved policy** means one entry in `IdentityImportPolicy::REGISTRY`, backed by a recorded decision.

## 6. Enforcement points

1. **Preview / validate** (read-only): the same function and gates, so the operator sees the verdict before anything is written.
2. **`begin_preparation`** (SECURITY DEFINER): re-runs the release-level checks and the gates, and raises before anything is written.
3. **`verify_release`** (SECURITY DEFINER):
   - recomputes the classification from staging and the base release;
   - re-applies the gates;
   - proves the release equals the classification exactly.
4. **`dataset_releases_publication_gate`** trigger: refuses `PUBLISHED` unless the preparation is VERIFIED, the record count equals the verified expectation, and the run is RUNNING with every file STAGED.
