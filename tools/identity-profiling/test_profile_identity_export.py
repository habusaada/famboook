"""Tests for profile_identity_export.py — SYNTHETIC records only.

Run:  python -I -m unittest discover -s tools/identity-profiling -v
"""

import csv
import glob
import hashlib
import io
import json
import os
import shutil
import sys
import tempfile
import tracemalloc
import unittest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import profile_identity_export as p  # noqa: E402

COLS = p.EXPECTED_COLUMNS


def row(**values):
    r = {c: "" for c in COLS}
    r.update(values)
    return [r[c] for c in COLS]


def person(i, **values):
    """A synthetic person: obviously fake names, IDs built from i."""
    base = dict(sert1=str(i), mainid=f"9{i:08d}", name1=f"اسم-تجريبي-{i}", father="أب-تجريبي", grandfath="جد-تجريبي",
                family="عائلة-تجريبية", gender="1", datebirth="1990-01-15", age="35", phonenum=f"0590000{i % 1000:03d}")
    base.update(values)
    return row(**base)


class Export:
    """Builds a synthetic export directory with a correct manifest."""

    def __init__(self, files, *, header=None, bom=True, raw=None):
        self.dir = tempfile.mkdtemp(prefix="fbcivil-test-export-")
        manifest_files = []
        for n, rows in enumerate(files, start=1):
            name = f"t03maindata_part_{n:05d}.csv"
            path = os.path.join(self.dir, name)
            if raw and name in raw:
                data = raw[name]
            else:
                buf = io.StringIO()
                w = csv.writer(buf, lineterminator="\r\n")
                w.writerow(header or COLS)
                w.writerows(rows)
                data = (("﻿" if bom else "") + buf.getvalue()).encode("utf-8")
            with open(path, "wb") as fh:
                fh.write(data)
            manifest_files.append({"file": name, "rows": len(rows), "sha256": hashlib.sha256(data).hexdigest()})
        self.manifest = {"table": "t03maindata", "columns": COLS, "total_rows": sum(f["rows"] for f in manifest_files),
                         "encoding": "UTF-8 with BOM", "files": manifest_files}
        self.write_manifest()

    def write_manifest(self):
        with open(os.path.join(self.dir, "manifest.json"), "w", encoding="utf-8") as fh:
            json.dump(self.manifest, fh, ensure_ascii=False)

    def cleanup(self):
        shutil.rmtree(self.dir, ignore_errors=True)


class Base(unittest.TestCase):
    def setUp(self):
        self.out = tempfile.mkdtemp(prefix="fbcivil-test-out-")
        self.exports = []

    def tearDown(self):
        shutil.rmtree(self.out, ignore_errors=True)
        for e in self.exports:
            e.cleanup()

    def export(self, files, **kw):
        e = Export(files, **kw)
        self.exports.append(e)
        return e

    def run_profile(self, e, **kw):
        return p.profile(e.dir, self.out, **kw)

    def outputs(self):
        return sorted(os.listdir(self.out))


class IdentityKeyTests(Base):
    def test_duplicate_ids_identical_and_conflicting(self):
        rows = [person(1), person(2), person(3)]
        rows.append(person(4, mainid=rows[0][1]))                       # same mainid, different content -> conflict
        dup = list(rows[1]); dup[0] = "999"; rows.append(dup)           # same row except sert1 -> identical
        m = self.run_profile(self.export([rows]))["identity_keys"]["mainid"]
        self.assertEqual(m["unique_values"], 3)
        self.assertEqual(m["duplicated_values"], 2)
        self.assertEqual(m["rows_in_duplicated_values"], 4)
        self.assertEqual(m["duplicated_values_identical_rows_ignoring_sert1"], 1)
        self.assertEqual(m["duplicated_values_conflicting_rows"], 1)
        self.assertEqual(m["max_rows_for_one_value"], 2)

    def test_missing_ids(self):
        m = self.run_profile(self.export([[person(1), person(2, mainid=""), person(3, mainid="   ")]]))["identity_keys"]["mainid"]
        self.assertEqual(m["missing"], 2)
        self.assertEqual(m["rows_with_value"], 1)
        self.assertEqual(m["format"]["missing"], 1)  # the whitespace-only one is classified separately
        self.assertEqual(m["format"]["leading_or_trailing_space"], 1)

    def test_leading_zeros_are_preserved_as_strings(self):
        rows = [person(1, mainid="012345678"), person(2, mainid="12345678"), person(3, mainid="000000000")]
        m = self.run_profile(self.export([rows]))["identity_keys"]["mainid"]
        self.assertEqual(m["unique_values"], 3)           # "012345678" and "12345678" are different strings
        self.assertEqual(m["duplicated_values"], 0)
        self.assertEqual(m["format"]["leading_zero"], 2)
        self.assertEqual(m["format"]["exactly_9_digits"], 2)
        self.assertEqual(m["format"]["digits_not_9_long"], 1)
        self.assertEqual(m["format"]["9_digits_all_same_digit"], 1)
        self.assertEqual(m["length_distribution"], {"8": 1, "9": 2})

    def test_other_id_formats(self):
        rows = [person(1, mainid="123456789.0"), person(2, mainid="AB1234567"), person(3, mainid="١٢٣٤٥٦٧٨٩")]
        f = self.run_profile(self.export([rows]))["identity_keys"]["mainid"]["format"]
        self.assertEqual(f["decimal_suffix_like_1234.0"], 1)
        self.assertEqual(f["contains_non_digit"], 1)
        self.assertEqual(f["non_ascii_digits"], 1)

    def test_sert1_profile(self):
        rows = [person(1), person(2, sert1="1"), person(3, sert1=""), person(4, sert1="900000004")]
        s = self.run_profile(self.export([rows]))["identity_keys"]["sert1"]
        self.assertEqual(s["missing"], 1)
        self.assertEqual(s["duplicated_values"], 1)
        self.assertEqual(s["rows_where_sert1_equals_mainid"], 1)


class RelationshipTests(Base):
    def test_broken_resolved_ambiguous_and_self_references(self):
        father, mother = person(10), person(11)
        rows = [father, mother,
                person(12, fatherid=father[1], motherid=mother[1]),       # both resolve
                person(13, fatherid="955555555"),                         # unresolved
                person(14, fatherid=f"9{14:08d}"),                        # self-reference (own mainid)
                person(15, motherid=" " + mother[1] + " ")]               # resolves after trimming
        dup_target = person(16); rows += [dup_target, person(17, mainid=dup_target[1]), person(18, partnerid=dup_target[1])]
        m = self.run_profile(self.export([rows]))["relationships"]
        self.assertEqual(m["fatherid"]["populated"], 3)
        self.assertEqual(m["fatherid"]["resolves_to_existing_mainid"], 2)  # father + self
        self.assertEqual(m["fatherid"]["unresolved"], 1)
        self.assertEqual(m["fatherid"]["self_reference"], 1)
        self.assertEqual(m["motherid"]["resolves_to_existing_mainid"], 2)
        self.assertEqual(m["partnerid"]["resolves_to_duplicated_mainid_ambiguous"], 1)
        self.assertEqual(m["fatherid"]["missing"], len(rows) - 3)

    def test_contradictory_patterns(self):
        a, b, c = person(21), person(22), person(23)
        a[COLS.index("partnerid")] = b[1]
        b[COLS.index("partnerid")] = a[1]                                  # reciprocal pair
        c[COLS.index("partnerid")] = a[1]                                  # a already has another partner
        x = person(24, fatherid=a[1], motherid=a[1])                       # same id as father and mother
        y = person(25, fatherid=a[1], partnerid=a[1])                      # father equals partner
        z1 = person(26); z2 = person(27, fatherid=z1[1]); z1[COLS.index("fatherid")] = z2[1]   # 2-cycle
        pat = self.run_profile(self.export([[a, b, c, x, y, z1, z2]]))["relationship_patterns"]
        self.assertEqual(pat["father_equals_mother"], 1)
        self.assertEqual(pat["father_equals_partner"], 1)
        self.assertEqual(pat["partner_reciprocal"], 2)
        self.assertEqual(pat["partner_target_has_other_partner"], 2)       # c -> a (a's partner is b); y -> a
        self.assertEqual(pat["ids_referenced_both_as_father_and_as_mother"], 1)
        self.assertEqual(pat["parent_child_two_cycles_father"], 2)
        self.assertGreaterEqual(pat["ids_named_as_partner_by_more_than_one_person"], 1)


class QualityTests(Base):
    def test_completeness_dates_and_duplicates(self):
        rows = [person(1, datebirth="15/01/1990", deathdate="01/01/1980"),     # death before birth
                person(2, datebirth="1990-13-45"),                              # unparseable
                person(3, datebirth="03/04/1990"),                              # ambiguous day/month
                person(4, age="abc"),
                person(5, mainid="955550001"), person(6, mainid="955550002")]   # same name key? no: names differ
        twin1 = person(7, name1="توأم"); twin2 = person(8, name1="توأم")        # same name/father/grandfather/family/birthdate, different mainid
        rows += [twin1, twin2, list(twin1)]                                     # + an exact duplicate row
        m = self.run_profile(self.export([rows]))
        self.assertEqual(m["death_before_birth"], 1)
        self.assertEqual(m["dates"]["datebirth"]["parse"]["unparseable"], 1)
        self.assertEqual(m["dates"]["datebirth"]["parse"]["ambiguous_day_month"], 1)
        self.assertIn("99/99/9999", m["dates"]["datebirth"]["top_shapes"])
        self.assertEqual(m["age"]["non_integer"], 1)
        self.assertEqual(m["suspicious_duplicates"]["exact_duplicate_row_groups"], 1)
        self.assertEqual(m["suspicious_duplicates"]["same_name_parents_family_birthdate_with_different_mainid_groups"], 1)
        c = m["completeness"]
        self.assertEqual(c["mainid"]["completeness_pct"], 100.0)
        self.assertEqual(c["street"]["populated"], 0)
        self.assertEqual(c["street"]["empty"], len(rows))

    def test_iso_datetime_with_t_separator_is_parsed(self):
        # The Access exporter writes dates as YYYY-MM-DDThh:mm:ss (found on the real export's shapes).
        rows = [person(1, datebirth="1990-01-15T00:00:00"), person(2, datebirth="2001-12-31T00:00:00")]
        d = self.run_profile(self.export([rows]))["dates"]["datebirth"]
        self.assertEqual(d["parse"], {"parseable": 2})
        self.assertEqual(d["top_shapes"], {"9999-99-99A99:99:99": 2})
        self.assertEqual(d["decades"], {"1990s": 1, "2000s": 1})

    def test_malformed_csv_rows_are_counted_and_flagged(self):
        good = [person(1), person(2)]
        buf = io.StringIO()
        w = csv.writer(buf, lineterminator="\r\n")
        w.writerow(COLS); w.writerows(good)
        w.writerow(person(3)[:35])                                   # wrong field count
        buf.write('"unterminated,' + ",".join([""] * 35) + "\r\n")   # broken quoting
        data = ("﻿" + buf.getvalue()).encode("utf-8")
        e = self.export([good + [person(3), person(4)]], raw={"t03maindata_part_00001.csv": data})
        e.manifest["files"][0]["sha256"] = hashlib.sha256(data).hexdigest()
        e.write_manifest()
        m = self.run_profile(e)
        self.assertEqual(m["structure"]["status"], "FAIL")
        self.assertGreaterEqual(m["structure"]["wrong_field_count"] + m["structure"]["csv_parse_error"], 1)
        self.assertFalse(m["structure"]["rows_match_manifest"])
        self.assertEqual(p.main([e.dir, "--out-dir", self.out]), 3)

    def test_multiline_and_nul_detection(self):
        rows = [person(1, street="سطر\nثاني"), person(2)]
        m = self.run_profile(self.export([rows]))
        self.assertEqual(m["structure"]["record_spans_multiple_lines"], 1)
        self.assertEqual(m["structure"]["status"], "PASS")


class ValidationTests(Base):
    def assert_rejected(self, e, fragment):
        with self.assertRaises(p.ValidationError) as ctx:
            self.run_profile(e)
        self.assertIn(fragment, str(ctx.exception))
        self.assertEqual(self.outputs(), [])
        self.assertEqual(p.main([e.dir, "--out-dir", self.out]), 2)

    def test_incorrect_checksum(self):
        e = self.export([[person(1)]])
        e.manifest["files"][0]["sha256"] = "0" * 64
        e.write_manifest()
        self.assert_rejected(e, "SHA-256 mismatch")

    def test_inconsistent_header(self):
        header = list(COLS); header[1], header[2] = header[2], header[1]
        self.assert_rejected(self.export([[person(1)]], header=header), "header differs")

    def test_missing_bom(self):
        self.assert_rejected(self.export([[person(1)]], bom=False), "missing UTF-8 BOM")

    def test_manifest_columns_and_totals(self):
        e = self.export([[person(1)]])
        e.manifest["total_rows"] = 5
        e.write_manifest()
        self.assert_rejected(e, "total_rows")

    def test_missing_and_extra_files(self):
        e = self.export([[person(1)], [person(2)]])
        os.remove(os.path.join(e.dir, "t03maindata_part_00002.csv"))
        self.assert_rejected(e, "missing from the directory")
        e2 = self.export([[person(1)]])
        open(os.path.join(e2.dir, "extra.csv"), "w").close()
        self.assert_rejected(e2, "not in the manifest")

    def test_empty_files(self):
        m = self.run_profile(self.export([[person(1)], []]))           # header-only file: valid, 0 rows
        self.assertEqual(m["structure"]["status"], "PASS")
        self.assertEqual(m["structure"]["per_file"][1]["parsed_rows"], 0)
        for name in self.outputs():                                     # fresh output dir for the rejection case
            os.remove(os.path.join(self.out, name))
        e = self.export([[person(1)], [person(2)]], raw={"t03maindata_part_00002.csv": b""})   # zero bytes
        self.assert_rejected(e, "missing UTF-8 BOM")


class LifecycleTests(Base):
    def temp_dirs(self):
        return set(glob.glob(os.path.join(tempfile.gettempdir(), "fbcivil-p0-*")))

    def test_interrupted_execution_leaves_no_report_and_no_index(self):
        before = self.temp_dirs()
        e = self.export([[person(i) for i in range(1, 60)]])
        with self.assertRaises(KeyboardInterrupt):
            self.run_profile(e, interrupt_after=25)
        self.assertEqual(self.outputs(), [])
        self.assertEqual(self.temp_dirs(), before)

    def test_successful_run_removes_index_and_writes_two_outputs(self):
        before = self.temp_dirs()
        self.run_profile(self.export([[person(1), person(2)]]))
        self.assertEqual(self.outputs(), sorted([p.METRICS_NAME, p.REPORT_NAME]))
        self.assertEqual(self.temp_dirs(), before)

    def test_outputs_contain_no_record_values(self):
        rows = [person(i) for i in range(100, 140)]
        self.run_profile(self.export([rows]))
        text = "".join(open(os.path.join(self.out, n), encoding="utf-8").read() for n in self.outputs())
        for r in rows:
            for col in ("mainid", "name1", "phonenum"):
                self.assertNotIn(r[COLS.index(col)], text, col)
        self.assertNotIn("تجريبي", text)

    def test_memory_is_bounded(self):
        def peak(n):
            e = self.export([[person(i) for i in range(n)]])
            out = tempfile.mkdtemp(prefix="fbcivil-test-out-")
            try:
                tracemalloc.start()
                p.profile(e.dir, out)
                return tracemalloc.get_traced_memory()[1]
            finally:
                tracemalloc.stop()
                shutil.rmtree(out, ignore_errors=True)

        small, large = peak(20_000), peak(120_000)
        # Six times the rows must not mean anything near six times the Python memory.
        self.assertLess(large, small * 1.6 + 2_000_000, (small, large))


if __name__ == "__main__":
    unittest.main()
