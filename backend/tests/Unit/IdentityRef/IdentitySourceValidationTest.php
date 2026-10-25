<?php

namespace Tests\Unit\IdentityRef;

use App\Support\IdentityRef\IdentityCsvException;
use App\Support\IdentityRef\IdentityCsvReader;
use App\Support\IdentityRef\IdentityManifest;
use App\Support\IdentityRef\IdentityManifestException;
use App\Support\IdentityRef\IdentitySourceVerifier;
use PHPUnit\Framework\TestCase;
use Tests\Support\IdentityRef\SyntheticIdentityExport;

/**
 * FB-CIVIL-01A-P2 — manifest validation and the streaming CSV reader
 * (P2 test list items 1–9, 11–14). No database. SYNTHETIC data only.
 */
class IdentitySourceValidationTest extends TestCase
{
    /** @var list<SyntheticIdentityExport> */
    private array $exports = [];

    protected function tearDown(): void
    {
        foreach ($this->exports as $e) {
            $e->delete();
        }
        parent::tearDown();
    }

    private function export(?array $files = null): SyntheticIdentityExport
    {
        return $this->exports[] = $files === null
            ? SyntheticIdentityExport::generated(3, 4)
            : SyntheticIdentityExport::withFiles($files);
    }

    /** @return list<string> */
    private function manifestProblems(SyntheticIdentityExport $e, ?int $files = null, ?int $rows = null): array
    {
        try {
            IdentityManifest::fromDirectory($e->dir, $files, $rows);
        } catch (IdentityManifestException $ex) {
            return $ex->problems;
        }

        return [];
    }

    /** @return list<string> "CODE file line" */
    private function sourceProblems(SyntheticIdentityExport $e): array
    {
        return array_map(fn ($p) => trim($p['code'].' '.$p['file'].' '.($p['line'] ?? '')),
            (new IdentitySourceVerifier)->verify(IdentityManifest::fromDirectory($e->dir)));
    }

    /** @return list<array{record_no: int, line_no: int, fields: list<string|null>}> */
    private function records(SyntheticIdentityExport $e, string $name): array
    {
        $reader = new IdentityCsvReader($e->path($name), $name);
        $this->assertSame(IdentityManifest::COLUMNS, $reader->open());

        return iterator_to_array($reader->records(), false);
    }

    private function bodyWith(string $rawRecordLines, string $eol = "\r\n"): string
    {
        return "\xEF\xBB\xBF".implode(',', IdentityManifest::COLUMNS).$eol.$rawRecordLines;
    }

    /** 1 */
    public function test_a_valid_export_passes_manifest_and_source_validation(): void
    {
        $e = $this->export();
        $manifest = IdentityManifest::fromDirectory($e->dir, 3, 12);

        $this->assertSame('t03maindata', $manifest->table);
        $this->assertSame(12, $manifest->totalRows);
        $this->assertCount(3, $manifest->files);
        $this->assertSame(hash_file('sha256', $e->path(IdentityManifest::FILE_NAME)), $manifest->sha256);
        $this->assertSame([], (new IdentitySourceVerifier)->verify($manifest));
        $this->assertSame([], $this->manifestProblems($e, 3, 12));
        $this->assertSame(['MANIFEST_FILE_COUNT_NOT_APPROVED', 'MANIFEST_ROW_COUNT_NOT_APPROVED'], $this->manifestProblems($e, 24, 2399891));
    }

    /** 2 */
    public function test_an_incorrect_checksum_is_rejected(): void
    {
        $e = $this->export();
        $e->manifest['files'][1]['sha256'] = str_repeat('0', 64);
        $e->writeManifest();
        $this->assertSame(['CHECKSUM_MISMATCH t03maindata_part_00002.csv'], $this->sourceProblems($e));
    }

    /** 3 and 4 */
    public function test_missing_extra_duplicated_and_unsafe_files_are_rejected(): void
    {
        $e = $this->export();
        unlink($e->path('t03maindata_part_00002.csv'));
        $this->assertSame(['FILE_MISSING t03maindata_part_00002.csv'], $this->manifestProblems($e));

        $e2 = $this->export();
        file_put_contents($e2->path('t03maindata_part_00099.csv'), 'x');
        mkdir($e2->path('nested'));
        $this->assertSame(['UNEXPECTED_ENTRIES 2'], $this->manifestProblems($e2));

        $e3 = $this->export();
        $e3->manifest['files'][] = $e3->manifest['files'][0];
        $e3->manifest['files'][] = ['file' => '../outside.csv', 'rows' => 1, 'sha256' => str_repeat('a', 64)];
        $e3->manifest['files'][] = ['file' => 'C:\\Windows\\x.csv', 'rows' => 1, 'sha256' => str_repeat('a', 64)];
        $e3->manifest['files'][] = ['file' => '/etc/passwd', 'rows' => 1, 'sha256' => str_repeat('a', 64)];
        $e3->writeManifest();
        $problems = $this->manifestProblems($e3);
        $this->assertContains('MANIFEST_FILE_DUPLICATED t03maindata_part_00001.csv', $problems);
        $this->assertSame(3, count(array_keys($problems, 'MANIFEST_FILE_NAME_UNSAFE')));
        foreach ($problems as $p) {
            $this->assertStringNotContainsString('outside', $p, 'unsafe names are never echoed');
            $this->assertStringNotContainsString('passwd', $p);
        }
    }

    public function test_symlinked_files_are_rejected(): void
    {
        $e = $this->export();
        $target = $e->path('t03maindata_part_00003.csv');
        $outside = sys_get_temp_dir().DIRECTORY_SEPARATOR.'famboook-p2-outside-'.bin2hex(random_bytes(4)).'.csv';
        rename($target, $outside);
        try {
            if (! @symlink($outside, $target)) {
                $this->markTestSkipped('This machine does not allow creating symlinks (Windows without Developer Mode).');
            }
            $this->assertSame(['FILE_IS_SYMLINK t03maindata_part_00003.csv'], $this->manifestProblems($e));
        } finally {
            if (is_link($target)) {
                unlink($target);
            }
            rename($outside, $target);
        }
    }

    public function test_manifest_structure_is_validated(): void
    {
        $e = $this->export();
        file_put_contents($e->path(IdentityManifest::FILE_NAME), '{not json');
        $this->assertSame(['MANIFEST_NOT_JSON'], $this->manifestProblems($e));

        $e->manifest['columns'][1] = 'MainId';
        $e->manifest['total_rows'] = 13;
        $e->manifest['table'] = 'x; drop';
        $e->manifest['encoding'] = 'windows-1256';
        $e->writeManifest();
        $this->assertSame(['MANIFEST_TABLE_INVALID', 'MANIFEST_COLUMNS_MISMATCH', 'MANIFEST_ENCODING_NOT_UTF8', 'MANIFEST_TOTAL_ROWS_MISMATCH'],
            $this->manifestProblems($e));

        unlink($e->path(IdentityManifest::FILE_NAME));
        $this->assertSame(['MANIFEST_MISSING'], $this->manifestProblems($e));
    }

    /** 5 and 6 */
    public function test_invalid_header_and_missing_bom_are_rejected(): void
    {
        $e = $this->export();
        $csv = (string) file_get_contents($e->path('t03maindata_part_00001.csv'));
        $e->replaceBytes('t03maindata_part_00001.csv', str_replace('mainid,name1', 'name1,mainid', $csv));
        $e->replaceBytes('t03maindata_part_00002.csv', substr((string) file_get_contents($e->path('t03maindata_part_00002.csv')), 3));
        $this->assertSame(['HEADER_MISMATCH t03maindata_part_00001.csv 1', 'BOM_MISSING t03maindata_part_00002.csv'], $this->sourceProblems($e));
    }

    /** 7 */
    public function test_a_wrong_row_count_is_rejected(): void
    {
        $e = $this->export();
        $e->manifest['files'][2]['rows'] = 5;
        $e->manifest['total_rows'] = 13;
        $e->writeManifest();
        $this->assertSame(['ROW_COUNT_MISMATCH t03maindata_part_00003.csv'], $this->sourceProblems($e));
    }

    /** 8 */
    public function test_malformed_and_truncated_csv_is_rejected_with_its_line(): void
    {
        $ok = SyntheticIdentityExport::line(SyntheticIdentityExport::row('900000001'))."\r\n";
        $cases = [
            'UNTERMINATED_QUOTE t.csv 3' => $ok.'"'.str_repeat('x,', 35)."x\r\n",
            'QUOTE_IN_UNQUOTED_FIELD t.csv 3' => $ok.'a"b'.str_repeat(',x', 35)."\r\n",
            'TEXT_AFTER_CLOSING_QUOTE t.csv 2' => '"a"b'.str_repeat(',x', 35)."\r\n",
            'FIELD_COUNT t.csv 3' => $ok.str_repeat('x,', 34)."x\r\n",
            'FIELD_COUNT t.csv 2' => "\r\n".$ok,
            'INVALID_UTF8 t.csv 2' => "\xC3\x28".str_repeat(',x', 35)."\r\n",
            'NUL_BYTE t.csv 3' => $ok."a\0b".str_repeat(',x', 35)."\r\n",
            // Truncated mid-record: the last record is cut inside its fields.
            'FIELD_COUNT t.csv 3 (truncated)' => $ok.substr($ok, 0, 40),
        ];
        foreach ($cases as $expected => $body) {
            $e = $this->export(['t.csv' => []]);
            $e->replaceBytes('t.csv', $this->bodyWith($body));
            $e->manifest['files'][0]['rows'] = 2;
            $e->manifest['total_rows'] = 2;
            $e->writeManifest();
            $this->assertSame([preg_replace('/ \(.*\)$/', '', $expected)], $this->sourceProblems($e), $expected);
        }
    }

    public function test_an_oversized_record_is_rejected_without_reading_it_whole(): void
    {
        $e = $this->export(['t.csv' => []]);
        $e->replaceBytes('t.csv', $this->bodyWith(str_repeat('x', 3000).str_repeat(',x', 35)."\r\n"));
        $reader = new IdentityCsvReader($e->path('t.csv'), 't.csv', 2048);
        $reader->open();
        try {
            iterator_to_array($reader->records());
            $this->fail('expected RECORD_TOO_LONG');
        } catch (IdentityCsvException $ex) {
            $this->assertSame([IdentityCsvException::RECORD_TOO_LONG, 2], [$ex->reason, $ex->lineNo]);
        }
    }

    /** 9 — quoted delimiters, doubled quotes and embedded line breaks; logical vs physical numbering. */
    public function test_quoted_delimiters_and_embedded_newlines_are_preserved(): void
    {
        $tricky = [
            'street' => 'شارع, رقم "5"',
            'fullname' => "سطر أول\nسطر ثان\r\nسطر ثالث",
            'job' => ',,,',
            'householdrel' => '"',
        ];
        $e = $this->export(['t.csv' => [
            SyntheticIdentityExport::row('900000001', $tricky),
            SyntheticIdentityExport::row('900000002'),
        ]]);
        $this->assertSame([], $this->sourceProblems($e));

        $records = $this->records($e, 't.csv');
        $this->assertCount(2, $records);
        $first = array_combine(IdentityManifest::COLUMNS, $records[0]['fields']);
        foreach ($tricky as $col => $value) {
            $this->assertSame($value, $first[$col], $col);
        }
        $this->assertSame([1, 2], [$records[0]['record_no'], $records[0]['line_no']]);
        $this->assertSame([2, 5], [$records[1]['record_no'], $records[1]['line_no']], 'the second record starts after the 3 physical lines of the first');
    }

    /** 11, 12, 13, 14 — values come back exactly as written. */
    public function test_leading_zeros_lengths_unicode_and_null_versus_empty_are_preserved(): void
    {
        $special = [
            'sert1' => '0000001',
            'fatherid' => '000000042',
            'name1' => 'مُحَمَّد',                       // combining marks
            'father' => "e\u{0301}",                  // decomposed é
            'grandfath' => '𝔘𝔫𝔦𝔠𝔬𝔡𝔢 😀',               // astral plane
            'street' => '  مسافات  ',
            'city' => '',                              // empty string (quoted "")
            'motherid' => null,                         // NULL (unquoted empty)
            'householdrel' => 'X9',                     // unknown code
            'datebirth' => '1890-02-30T00:00:00',      // invalid date, kept
        ];
        $e = $this->export(['t.csv' => [
            SyntheticIdentityExport::row('012345678', $special),
            SyntheticIdentityExport::row('7'),
            SyntheticIdentityExport::row('123'),
            SyntheticIdentityExport::row('12345678901234'),
        ]]);
        $this->assertSame([], $this->sourceProblems($e));
        $records = $this->records($e, 't.csv');

        $this->assertSame(['012345678', '7', '123', '12345678901234'], array_map(fn ($r) => $r['fields'][1], $records));
        $first = array_combine(IdentityManifest::COLUMNS, $records[0]['fields']);
        foreach ($special as $col => $value) {
            $this->assertSame($value, $first[$col], $col);
        }
        $this->assertNull($first['partnerid']);
    }

    public function test_the_reader_hash_matches_the_file_and_lf_endings_are_accepted(): void
    {
        $e = $this->exports[] = SyntheticIdentityExport::withFiles(['t.csv' => [SyntheticIdentityExport::row('900000001')]], "\n");
        $reader = new IdentityCsvReader($e->path('t.csv'), 't.csv');
        $reader->open();
        $records = iterator_to_array($reader->records(), false);
        $this->assertCount(1, $records);
        $this->assertSame(hash_file('sha256', $e->path('t.csv')), $reader->sha256());
        $this->assertSame(filesize($e->path('t.csv')), $records[0]['byte_end']);
    }
}
