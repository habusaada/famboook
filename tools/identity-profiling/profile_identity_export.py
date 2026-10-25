#!/usr/bin/env python3
"""FB-CIVIL-01A-P0 — read-only profiling of the t03maindata CSV export.

Aggregate statistics only. The tool:

* never writes to any database or application data, never connects anywhere;
* validates the manifest, checksums, headers, row counts and CSV structure
  BEFORE profiling, and writes no report when validation fails;
* streams the CSV files (bounded memory) and keeps an on-disk SQLite index in
  a private temporary directory holding only KEYED HASHES (BLAKE2b with a
  random per-run key that lives in memory only) — never raw IDs, names,
  phones or records — and deletes it in all cases;
* reports counts, percentages and format SHAPES only (digits -> 9, letters ->
  A); no field value is printed, logged, raised in an exception or written;
* writes its outputs atomically, so an interrupted run leaves no report.

Usage:
  python -I profile_identity_export.py <export-dir> --out-dir <dir>
"""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
import os
import re
import secrets
import shutil
import sqlite3
import sys
import tempfile
from collections import Counter
from dataclasses import dataclass, field
from datetime import date, datetime, timezone

EXPECTED_COLUMNS = [
    "sert1", "mainid", "name1", "father", "grandfath", "family", "gender", "govern", "city", "street",
    "fatherid", "motherid", "partnerid", "datebirth", "age", "maritalst", "deathdate", "presonstate",
    "relgion", "birthplace", "householdid", "householdrel", "prevfamily", "phonenum", "worker", "att",
    "worktype", "source", "civilg", "cwloc", "mgrade", "mstartw", "msalary", "select", "job", "fullname",
]
IDX = {name: i for i, name in enumerate(EXPECTED_COLUMNS)}
DATE_COLUMNS = ["datebirth", "deathdate", "mstartw"]
REL_COLUMNS = ["fatherid", "motherid", "partnerid"]
NAME_KEY_COLUMNS = ["name1", "father", "grandfath", "family", "datebirth"]
DATE_FORMATS = [
    "%Y-%m-%dT%H:%M:%S", "%Y-%m-%dT%H:%M:%S.%f", "%Y-%m-%d", "%Y-%m-%d %H:%M:%S", "%d/%m/%Y", "%d/%m/%Y %H:%M:%S", "%m/%d/%Y", "%m/%d/%Y %H:%M:%S",
    "%Y/%m/%d", "%Y/%m/%d %H:%M:%S", "%d-%m-%Y", "%d.%m.%Y", "%Y%m%d",
]
NINE_DIGITS = re.compile(r"^[0-9]{9}$")
BATCH = 20_000

REPORT_NAME = "FB-CIVIL-01A-P0-PROFILING-REPORT.md"
METRICS_NAME = "FB-CIVIL-01A-P0-QUALITY-METRICS.json"


class ValidationError(Exception):
    """A pre-profiling check failed. Messages never contain field values."""


@dataclass
class Hasher:
    key: bytes = field(default_factory=lambda: secrets.token_bytes(32))

    def __call__(self, value: str) -> bytes:
        return hashlib.blake2b(value.encode("utf-8"), key=self.key, digest_size=16).digest()


def shape(value: str) -> str:
    """Format shape without content: digits -> 9, letters (any script) -> A."""
    out = []
    for ch in value[:40]:
        if ch.isdigit():
            out.append("9")
        elif ch.isalpha():
            out.append("A")
        elif ch == " ":
            out.append("_")
        else:
            out.append(ch)
    return "".join(out) + ("…" if len(value) > 40 else "")


def sha256_file(path: str) -> str:
    h = hashlib.sha256()
    with open(path, "rb") as fh:
        for chunk in iter(lambda: fh.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


def write_atomic(path: str, text: str) -> None:
    tmp = path + ".partial"
    with open(tmp, "w", encoding="utf-8", newline="\n") as fh:
        fh.write(text)
    os.replace(tmp, path)


# --------------------------------------------------------------------------- validation

def validate(export_dir: str) -> dict:
    manifest_path = os.path.join(export_dir, "manifest.json")
    if not os.path.isfile(manifest_path):
        raise ValidationError("manifest.json not found")
    try:
        with open(manifest_path, encoding="utf-8-sig") as fh:
            manifest = json.load(fh)
    except (ValueError, UnicodeDecodeError):
        raise ValidationError("manifest.json is not valid JSON") from None

    for key in ("columns", "files", "total_rows"):
        if key not in manifest:
            raise ValidationError(f"manifest is missing '{key}'")
    columns = [c["name"] if isinstance(c, dict) else c for c in manifest["columns"]]
    if columns != EXPECTED_COLUMNS:
        raise ValidationError("manifest columns differ from the 36 approved column names")
    files = manifest["files"]
    if not files:
        raise ValidationError("manifest lists no files")
    if sum(int(f["rows"]) for f in files) != int(manifest["total_rows"]):
        raise ValidationError("manifest total_rows differs from the sum of file rows")

    listed = {f["file"] for f in files}
    present = {n for n in os.listdir(export_dir) if n.lower().endswith(".csv")}
    if listed - present:
        raise ValidationError(f"{len(listed - present)} manifest file(s) missing from the directory")
    if present - listed:
        raise ValidationError(f"{len(present - listed)} CSV file(s) present but not in the manifest")

    checked = []
    for f in files:
        path = os.path.join(export_dir, f["file"])
        if sha256_file(path) != f["sha256"].lower():
            raise ValidationError(f"SHA-256 mismatch: {f['file']}")
        with open(path, "rb") as fh:
            bom = fh.read(3) == b"\xef\xbb\xbf"
        if not bom:
            raise ValidationError(f"missing UTF-8 BOM: {f['file']}")
        with open(path, encoding="utf-8-sig", newline="") as fh:
            try:
                header = next(csv.reader(fh), None)
            except (csv.Error, UnicodeDecodeError):
                raise ValidationError(f"unreadable header: {f['file']}") from None
        if header is None:
            raise ValidationError(f"empty file (no header): {f['file']}")
        if header != EXPECTED_COLUMNS:
            raise ValidationError(f"header differs from the manifest columns: {f['file']}")
        checked.append({"file": f["file"], "rows": int(f["rows"]), "sha256": f["sha256"].lower()})
    return {"manifest": manifest, "files": checked}


# --------------------------------------------------------------------------- profiling

class Profiler:
    def __init__(self, export_dir: str, files: list[dict], tmp_dir: str, interrupt_after: int | None = None):
        self.export_dir = export_dir
        self.files = files
        self.hash = Hasher()
        self.db = sqlite3.connect(os.path.join(tmp_dir, "index.sqlite"))
        self.db.executescript(
            "PRAGMA journal_mode=OFF; PRAGMA synchronous=OFF; PRAGMA temp_store=FILE; PRAGMA cache_size=-65536;"
            "CREATE TABLE r (id INTEGER PRIMARY KEY, mid BLOB, sert BLOB, fid BLOB, moid BLOB, pid BLOB,"
            " rowh BLOB, rowh_ns BLOB, nameh BLOB);"
        )
        self.interrupt_after = interrupt_after  # test hook: simulate an interruption
        self.populated = Counter()
        self.whitespace_only = Counter()
        self.cardinality_probe = {c: set() for c in EXPECTED_COLUMNS}  # capped hash sets, see note
        self.cardinality_capped = set()
        self.id_len = {"mainid": Counter(), "sert1": Counter()}
        self.id_class = {"mainid": Counter(), "sert1": Counter()}
        self.rel_class = {c: Counter() for c in REL_COLUMNS}
        self.date_shapes = {c: Counter() for c in DATE_COLUMNS}
        self.date_parse = {c: Counter() for c in DATE_COLUMNS}
        self.date_years = {c: Counter() for c in DATE_COLUMNS}
        self.age = Counter()
        self.death_before_birth = 0
        self.structural = Counter()
        self.rows_total = 0
        self.per_file = []

    # -- helpers
    def _id_class(self, kind: str, raw: str) -> None:
        v = raw
        if v == "":
            self.id_class[kind]["missing"] += 1
            return
        self.id_len[kind][len(v)] += 1
        if v != v.strip():
            self.id_class[kind]["leading_or_trailing_space"] += 1
        s = v.strip()
        if s.isdigit() and s.isascii():
            self.id_class[kind]["ascii_digits_only"] += 1
            if NINE_DIGITS.match(s):
                self.id_class[kind]["exactly_9_digits"] += 1
                if len(set(s)) == 1:
                    self.id_class[kind]["9_digits_all_same_digit"] += 1
            else:
                self.id_class[kind]["digits_not_9_long"] += 1
            if s.startswith("0"):
                self.id_class[kind]["leading_zero"] += 1
        elif s.isdigit():
            self.id_class[kind]["non_ascii_digits"] += 1
        elif re.fullmatch(r"[0-9]+\.0+", s):
            self.id_class[kind]["decimal_suffix_like_1234.0"] += 1
        else:
            self.id_class[kind]["contains_non_digit"] += 1

    def _date(self, col: str, raw: str) -> date | None:
        v = raw.strip()
        if v == "":
            return None
        self.date_shapes[col][shape(v)] += 1
        matched = []
        for fmt in DATE_FORMATS:
            try:
                matched.append((fmt, datetime.strptime(v, fmt)))
            except ValueError:
                pass
        if not matched:
            self.date_parse[col]["unparseable"] += 1
            return None
        fams = {m[0].split(" ")[0] for m in matched}
        if "%d/%m/%Y" in fams and "%m/%d/%Y" in fams:
            self.date_parse[col]["ambiguous_day_month"] += 1
        self.date_parse[col]["parseable"] += 1
        d = matched[0][1].date()
        decade = (d.year // 10) * 10
        self.date_years[col][f"{decade}s" if 1850 <= d.year <= 2030 else "out_of_range"] += 1
        return d

    def _card(self, col: str, raw: str) -> None:
        # Cardinality estimate without values: keyed hashes, capped to bound memory.
        if col in self.cardinality_capped:
            return
        probe = self.cardinality_probe[col]
        probe.add(self.hash(raw))
        if len(probe) > 1000:
            self.cardinality_capped.add(col)
            probe.clear()

    # -- main pass
    def run(self) -> None:
        processed = 0
        for f in self.files:
            path = os.path.join(self.export_dir, f["file"])
            rows_in_file = malformed = 0
            batch = []
            with open(path, encoding="utf-8-sig", newline="") as fh:
                reader = csv.reader(fh)
                next(reader)  # header (validated)
                line_start = reader.line_num
                while True:
                    try:
                        rec = next(reader)
                    except StopIteration:
                        break
                    except csv.Error:
                        self.structural["csv_parse_error"] += 1
                        malformed += 1
                        continue
                    multiline = reader.line_num - line_start > 1
                    line_start = reader.line_num
                    if multiline:
                        self.structural["record_spans_multiple_lines"] += 1
                    if len(rec) != len(EXPECTED_COLUMNS):
                        self.structural["wrong_field_count"] += 1
                        malformed += 1
                        continue
                    if any("\x00" in v for v in rec):
                        self.structural["nul_character"] += 1
                    rows_in_file += 1
                    batch.append(self._profile_row(rec))
                    if len(batch) >= BATCH:
                        self._flush(batch)
                    processed += 1
                    if self.interrupt_after is not None and processed >= self.interrupt_after:
                        raise KeyboardInterrupt("simulated interruption (test hook)")
            self._flush(batch)
            self.per_file.append({"file": f["file"], "manifest_rows": f["rows"], "parsed_rows": rows_in_file,
                                  "malformed_rows": malformed, "row_count_matches": rows_in_file == f["rows"]})
            self.rows_total += rows_in_file

    def _profile_row(self, rec: list[str]) -> tuple:
        for col, v in zip(EXPECTED_COLUMNS, rec):
            if v.strip() != "":
                self.populated[col] += 1
            elif v != "":
                self.whitespace_only[col] += 1
            self._card(col, v)
        mid, sert = rec[IDX["mainid"]].strip(), rec[IDX["sert1"]].strip()
        self._id_class("mainid", rec[IDX["mainid"]])
        self._id_class("sert1", rec[IDX["sert1"]])
        rels = []
        for col in REL_COLUMNS:
            v = rec[IDX[col]].strip()
            if v == "":
                self.rel_class[col]["missing"] += 1
                rels.append(None)
            else:
                self.rel_class[col]["exactly_9_digits" if NINE_DIGITS.match(v) else "other_format"] += 1
                rels.append(self.hash(v))
        birth = self._date("datebirth", rec[IDX["datebirth"]])
        death = self._date("deathdate", rec[IDX["deathdate"]])
        self._date("mstartw", rec[IDX["mstartw"]])
        if birth and death and death < birth:
            self.death_before_birth += 1
        a = rec[IDX["age"]].strip()
        if a == "":
            self.age["missing"] += 1
        elif a.isdigit():
            n = int(a)
            self.age["0-17" if n < 18 else "18-59" if n < 60 else "60-110" if n <= 110 else "over_110"] += 1
        else:
            self.age["non_integer"] += 1
        full = "\x1f".join(rec)
        no_sert = "\x1f".join(rec[1:])
        name_key = "\x1f".join(rec[IDX[c]].strip() for c in NAME_KEY_COLUMNS)
        return (
            self.hash(mid) if mid else None,
            self.hash(sert) if sert else None,
            *rels,
            self.hash(full),
            self.hash(no_sert),
            self.hash(name_key) if all(rec[IDX[c]].strip() for c in NAME_KEY_COLUMNS) else None,
        )

    def _flush(self, batch: list) -> None:
        if batch:
            self.db.executemany("INSERT INTO r (mid, sert, fid, moid, pid, rowh, rowh_ns, nameh) VALUES (?,?,?,?,?,?,?,?)", batch)
            batch.clear()

    # -- set-based analysis (SQLite, hashes only)
    def analyse(self) -> dict:
        q = lambda sql: self.db.execute(sql).fetchone()[0] or 0  # noqa: E731
        self.db.executescript(
            "CREATE INDEX i_mid ON r(mid); CREATE INDEX i_sert ON r(sert); CREATE INDEX i_rowh ON r(rowh);"
            "CREATE INDEX i_rowhns ON r(rowh_ns); CREATE INDEX i_nameh ON r(nameh);"
            "CREATE TABLE m AS SELECT mid, COUNT(*) c, COUNT(DISTINCT rowh_ns) v FROM r WHERE mid IS NOT NULL GROUP BY mid;"
            "CREATE UNIQUE INDEX i_m ON m(mid);"
        )
        res: dict = {}
        res["mainid"] = {
            "rows_with_value": q("SELECT COUNT(*) FROM r WHERE mid IS NOT NULL"),
            "missing": q("SELECT COUNT(*) FROM r WHERE mid IS NULL"),
            "unique_values": q("SELECT COUNT(*) FROM m"),
            "values_occurring_once": q("SELECT COUNT(*) FROM m WHERE c = 1"),
            "duplicated_values": q("SELECT COUNT(*) FROM m WHERE c > 1"),
            "rows_in_duplicated_values": q("SELECT SUM(c) FROM m WHERE c > 1"),
            "duplicated_values_identical_rows_ignoring_sert1": q("SELECT COUNT(*) FROM m WHERE c > 1 AND v = 1"),
            "duplicated_values_conflicting_rows": q("SELECT COUNT(*) FROM m WHERE c > 1 AND v > 1"),
            "max_rows_for_one_value": q("SELECT MAX(c) FROM m"),
        }
        res["sert1"] = {
            "rows_with_value": q("SELECT COUNT(*) FROM r WHERE sert IS NOT NULL"),
            "missing": q("SELECT COUNT(*) FROM r WHERE sert IS NULL"),
            "unique_values": q("SELECT COUNT(DISTINCT sert) FROM r WHERE sert IS NOT NULL"),
            "duplicated_values": q("SELECT COUNT(*) FROM (SELECT sert FROM r WHERE sert IS NOT NULL GROUP BY sert HAVING COUNT(*) > 1)"),
            "rows_where_sert1_equals_mainid": q("SELECT COUNT(*) FROM r WHERE sert IS NOT NULL AND sert = mid"),
        }
        rel = {}
        for col, c in (("fatherid", "fid"), ("motherid", "moid"), ("partnerid", "pid")):
            rel[col] = {
                "populated": q(f"SELECT COUNT(*) FROM r WHERE {c} IS NOT NULL"),
                "missing": q(f"SELECT COUNT(*) FROM r WHERE {c} IS NULL"),
                "resolves_to_existing_mainid": q(f"SELECT COUNT(*) FROM r JOIN m ON r.{c} = m.mid"),
                "resolves_to_duplicated_mainid_ambiguous": q(f"SELECT COUNT(*) FROM r JOIN m ON r.{c} = m.mid WHERE m.c > 1"),
                "unresolved": q(f"SELECT COUNT(*) FROM r LEFT JOIN m ON r.{c} = m.mid WHERE r.{c} IS NOT NULL AND m.mid IS NULL"),
                "self_reference": q(f"SELECT COUNT(*) FROM r WHERE {c} IS NOT NULL AND {c} = mid"),
                "distinct_referenced_values": q(f"SELECT COUNT(DISTINCT {c}) FROM r WHERE {c} IS NOT NULL"),
            }
        self.db.executescript(
            "CREATE INDEX i_fid ON r(fid); CREATE INDEX i_moid ON r(moid); CREATE INDEX i_pid ON r(pid);"
        )
        unique_join = "JOIN m mb ON mb.mid = b.mid AND mb.c = 1"
        patterns = {
            "father_equals_mother": q("SELECT COUNT(*) FROM r WHERE fid IS NOT NULL AND fid = moid"),
            "father_equals_partner": q("SELECT COUNT(*) FROM r WHERE fid IS NOT NULL AND fid = pid"),
            "mother_equals_partner": q("SELECT COUNT(*) FROM r WHERE moid IS NOT NULL AND moid = pid"),
            "ids_referenced_both_as_father_and_as_mother": q(
                "SELECT COUNT(*) FROM (SELECT DISTINCT fid AS x FROM r WHERE fid IS NOT NULL INTERSECT SELECT DISTINCT moid FROM r WHERE moid IS NOT NULL)"),
            "parent_child_two_cycles_father": q(
                f"SELECT COUNT(*) FROM r a JOIN r b ON a.fid = b.mid {unique_join} WHERE b.fid = a.mid"),
            "parent_child_two_cycles_mother": q(
                f"SELECT COUNT(*) FROM r a JOIN r b ON a.moid = b.mid {unique_join} WHERE b.moid = a.mid"),
            "partner_reciprocal": q(
                f"SELECT COUNT(*) FROM r a JOIN r b ON a.pid = b.mid {unique_join} WHERE b.pid = a.mid"),
            "partner_target_has_other_partner": q(
                f"SELECT COUNT(*) FROM r a JOIN r b ON a.pid = b.mid {unique_join} WHERE b.pid IS NOT NULL AND b.pid <> a.mid"),
            "partner_target_has_no_partner": q(
                f"SELECT COUNT(*) FROM r a JOIN r b ON a.pid = b.mid {unique_join} WHERE b.pid IS NULL"),
            "ids_named_as_partner_by_more_than_one_person": q(
                "SELECT COUNT(*) FROM (SELECT pid FROM r WHERE pid IS NOT NULL GROUP BY pid HAVING COUNT(DISTINCT mid) > 1)"),
        }
        dups = {
            "exact_duplicate_row_groups": q("SELECT COUNT(*) FROM (SELECT rowh FROM r GROUP BY rowh HAVING COUNT(*) > 1)"),
            "rows_in_exact_duplicate_groups": q("SELECT SUM(c) FROM (SELECT COUNT(*) c FROM r GROUP BY rowh HAVING c > 1)"),
            "duplicate_groups_ignoring_sert1": q("SELECT COUNT(*) FROM (SELECT rowh_ns FROM r GROUP BY rowh_ns HAVING COUNT(*) > 1)"),
            "rows_in_duplicate_groups_ignoring_sert1": q("SELECT SUM(c) FROM (SELECT COUNT(*) c FROM r GROUP BY rowh_ns HAVING c > 1)"),
            "same_name_parents_family_birthdate_with_different_mainid_groups": q(
                "SELECT COUNT(*) FROM (SELECT nameh FROM r WHERE nameh IS NOT NULL GROUP BY nameh HAVING COUNT(DISTINCT mid) > 1)"),
            "rows_in_those_groups": q(
                "SELECT SUM(c) FROM (SELECT COUNT(*) c FROM r WHERE nameh IS NOT NULL GROUP BY nameh HAVING COUNT(DISTINCT mid) > 1)"),
        }
        res["relationships"] = rel
        res["relationship_patterns"] = patterns
        res["suspicious_duplicates"] = dups
        return res

    def metrics(self, validation: dict) -> dict:
        set_based = self.analyse()
        total = self.rows_total
        completeness = {}
        for col in EXPECTED_COLUMNS:
            pop = self.populated[col]
            completeness[col] = {
                "populated": pop,
                "empty": total - pop,
                "whitespace_only": self.whitespace_only[col],
                "completeness_pct": round(100 * pop / total, 2) if total else 0.0,
                "distinct_values": ">1000" if col in self.cardinality_capped else len(self.cardinality_probe[col]),
            }
        return {
            "tool": "FB-CIVIL-01A-P0 profile_identity_export.py",
            "generated_at_utc": datetime.now(timezone.utc).replace(microsecond=0).isoformat(),
            "privacy": "aggregate counts and format shapes only; no field value is included",
            "validation": {
                "manifest_total_rows": int(validation["manifest"]["total_rows"]),
                "files": len(validation["files"]),
                "sha256_verified": len(validation["files"]),
                "headers_verified": len(validation["files"]),
                "columns": len(EXPECTED_COLUMNS),
            },
            "structure": {
                "status": "PASS" if total == int(validation["manifest"]["total_rows"]) and not self.structural["wrong_field_count"]
                and not self.structural["csv_parse_error"] else "FAIL",
                "rows_parsed": total,
                "rows_match_manifest": total == int(validation["manifest"]["total_rows"]),
                **{k: self.structural[k] for k in ("csv_parse_error", "wrong_field_count", "record_spans_multiple_lines", "nul_character")},
                "per_file": self.per_file,
            },
            "identity_keys": {
                "mainid": {**set_based["mainid"], "format": dict(self.id_class["mainid"]),
                           "length_distribution": {str(k): v for k, v in sorted(self.id_len["mainid"].items())}},
                "sert1": {**set_based["sert1"], "format": dict(self.id_class["sert1"]),
                          "length_distribution": {str(k): v for k, v in sorted(self.id_len["sert1"].items())}},
            },
            "relationships": {col: {**set_based["relationships"][col], "format": dict(self.rel_class[col])} for col in REL_COLUMNS},
            "relationship_patterns": set_based["relationship_patterns"],
            "suspicious_duplicates": set_based["suspicious_duplicates"],
            "completeness": completeness,
            "dates": {col: {"parse": dict(self.date_parse[col]), "top_shapes": dict(self.date_shapes[col].most_common(8)),
                            "decades": dict(sorted(self.date_years[col].items()))} for col in DATE_COLUMNS},
            "death_before_birth": self.death_before_birth,
            "age": dict(self.age),
        }


# --------------------------------------------------------------------------- report

def render_report(m: dict) -> str:
    pct = lambda a, b: f"{100 * a / b:.2f}%" if b else "—"  # noqa: E731
    total = m["structure"]["rows_parsed"]
    mid, sert = m["identity_keys"]["mainid"], m["identity_keys"]["sert1"]
    lines = [
        "# FB-CIVIL-01A-P0 — Identity Dataset Profiling Report",
        "",
        f"**Generated:** {m['generated_at_utc']} by `tools/identity-profiling/profile_identity_export.py`.",
        "**Privacy:** aggregate counts and format shapes only (digits → `9`, letters → `A`). No value, name, ID, phone or record appears in this report.",
        "**Mode:** read-only, local. No database, Production or application data was touched.",
        "",
        "## 1. Validation (before profiling)",
        "",
        f"- Manifest: {m['validation']['files']} files, {m['validation']['manifest_total_rows']:,} rows, {m['validation']['columns']} columns.",
        f"- SHA-256 verified: {m['validation']['sha256_verified']}/{m['validation']['files']}; headers verified: {m['validation']['headers_verified']}/{m['validation']['files']}.",
        f"- **Structure status: {m['structure']['status']}** (PASS = every row parsed into 36 fields and the parsed count equals the manifest).",
        f"- Rows parsed: {total:,} — matches manifest: **{m['structure']['rows_match_manifest']}**.",
        f"- CSV parse errors: {m['structure']['csv_parse_error']}; wrong field count: {m['structure']['wrong_field_count']}; "
        f"records spanning several lines: {m['structure']['record_spans_multiple_lines']}; NUL characters: {m['structure']['nul_character']}.",
        "",
        "## 2. Identity keys",
        "",
        "| Metric | `mainid` | `sert1` |",
        "|---|---|---|",
        f"| Rows with a value | {mid['rows_with_value']:,} | {sert['rows_with_value']:,} |",
        f"| Missing | {mid['missing']:,} | {sert['missing']:,} |",
        f"| Unique values | {mid['unique_values']:,} | {sert['unique_values']:,} |",
        f"| Duplicated values | {mid['duplicated_values']:,} | {sert['duplicated_values']:,} |",
        "",
        f"- `mainid` rows in duplicated values: {mid['rows_in_duplicated_values']:,}; duplicated values whose rows are identical (ignoring `sert1`): "
        f"{mid['duplicated_values_identical_rows_ignoring_sert1']:,}; with conflicting rows: {mid['duplicated_values_conflicting_rows']:,}; "
        f"max rows for one value: {mid['max_rows_for_one_value']}.",
        f"- Rows where `sert1` equals `mainid`: {sert['rows_where_sert1_equals_mainid']:,}.",
        f"- `mainid` format: {', '.join(f'{k} {v:,}' for k, v in sorted(mid['format'].items()))}.",
        f"- `mainid` length distribution: {', '.join(f'{k} chars: {v:,}' for k, v in mid['length_distribution'].items())}.",
        f"- `sert1` format: {', '.join(f'{k} {v:,}' for k, v in sorted(sert['format'].items()))}.",
        f"- `sert1` length distribution: {', '.join(f'{k} chars: {v:,}' for k, v in sert['length_distribution'].items())}.",
        "",
        "## 3. Relationships",
        "",
        "| Metric | `fatherid` | `motherid` | `partnerid` |",
        "|---|---|---|---|",
    ]
    rel = m["relationships"]
    for key, label in (("populated", "Populated"), ("missing", "Missing"), ("resolves_to_existing_mainid", "Resolves to an existing `mainid`"),
                       ("resolves_to_duplicated_mainid_ambiguous", "…of which the target `mainid` is duplicated (ambiguous)"),
                       ("unresolved", "Unresolved"), ("self_reference", "Self-reference"), ("distinct_referenced_values", "Distinct referenced values")):
        lines.append(f"| {label} | " + " | ".join(f"{rel[c][key]:,}" for c in REL_COLUMNS) + " |")
    lines.append("| Resolution rate of populated | " + " | ".join(pct(rel[c]["resolves_to_existing_mainid"], rel[c]["populated"]) for c in REL_COLUMNS) + " |")
    lines += ["", "**Patterns** (counts of rows or IDs; computed on keyed hashes, targets restricted to non-duplicated `mainid`):", ""]
    lines += [f"- {k.replace('_', ' ')}: {v:,}" for k, v in m["relationship_patterns"].items()]
    lines += ["", "## 4. Suspicious duplicates", ""]
    lines += [f"- {k.replace('_', ' ')}: {v:,}" for k, v in m["suspicious_duplicates"].items()]
    lines += ["", "## 5. Completeness (all 36 columns)", "", "| Column | Populated | Empty | Whitespace-only | Completeness | Distinct values |", "|---|---|---|---|---|---|"]
    for col, c in m["completeness"].items():
        lines.append(f"| `{col}` | {c['populated']:,} | {c['empty']:,} | {c['whitespace_only']:,} | {c['completeness_pct']}% | {c['distinct_values']} |")
    lines += ["", "## 6. Dates (values unchanged; shapes only)", ""]
    for col, d in m["dates"].items():
        lines.append(f"- `{col}` — parse: {', '.join(f'{k} {v:,}' for k, v in d['parse'].items()) or 'no values'}; "
                     f"top shapes: {', '.join(f'`{k}` {v:,}' for k, v in d['top_shapes'].items()) or '—'}; "
                     f"decades: {', '.join(f'{k} {v:,}' for k, v in d['decades'].items()) or '—'}.")
    lines += [f"- Death date before birth date: {m['death_before_birth']:,}.",
              f"- `age`: {', '.join(f'{k} {v:,}' for k, v in sorted(m['age'].items()))}.", "",
              "## 7. Per file", "", "| File | Manifest rows | Parsed rows | Malformed | Match |", "|---|---|---|---|---|"]
    for f in m["structure"]["per_file"]:
        lines.append(f"| {f['file']} | {f['manifest_rows']:,} | {f['parsed_rows']:,} | {f['malformed_rows']} | {f['row_count_matches']} |")
    lines += ["", "Interpretation and recommendations: `FB-CIVIL-01A-P0-RECOMMENDATIONS.md`.", ""]
    return "\n".join(lines)


# --------------------------------------------------------------------------- entry point

def profile(export_dir: str, out_dir: str, interrupt_after: int | None = None) -> dict:
    validation = validate(export_dir)  # raises before any profiling or output
    tmp_dir = tempfile.mkdtemp(prefix="fbcivil-p0-")  # private (0700 on POSIX; user profile on Windows)
    profiler = None
    try:
        profiler = Profiler(export_dir, validation["files"], tmp_dir, interrupt_after)
        profiler.run()
        metrics = profiler.metrics(validation)
        os.makedirs(out_dir, exist_ok=True)
        write_atomic(os.path.join(out_dir, METRICS_NAME), json.dumps(metrics, ensure_ascii=False, indent=2) + "\n")
        write_atomic(os.path.join(out_dir, REPORT_NAME), render_report(metrics))
        return metrics
    finally:
        if profiler is not None:
            profiler.db.close()
        shutil.rmtree(tmp_dir, ignore_errors=True)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="FB-CIVIL-01A-P0 aggregate-only profiling of the identity export.")
    parser.add_argument("export_dir")
    parser.add_argument("--out-dir", required=True)
    args = parser.parse_args(argv)
    try:
        sys.stdout.reconfigure(encoding="utf-8")
    except AttributeError:
        pass
    try:
        m = profile(args.export_dir, args.out_dir)
    except ValidationError as e:
        print(f"VALIDATION FAILED: {e}. No report was written.", file=sys.stderr)
        return 2
    except KeyboardInterrupt:
        print("Interrupted. Temporary index deleted; no report was written.", file=sys.stderr)
        return 130
    except Exception as e:  # never echo data: type only
        print(f"FAILED: {type(e).__name__}. Temporary index deleted; no report was written.", file=sys.stderr)
        return 1
    print(f"{'OK' if m['structure']['status'] == 'PASS' else 'STRUCTURE FAIL'}: {m['structure']['rows_parsed']:,} rows profiled; "
          f"reports written to {args.out_dir}")
    return 0 if m["structure"]["status"] == "PASS" else 3


if __name__ == "__main__":
    sys.exit(main())
