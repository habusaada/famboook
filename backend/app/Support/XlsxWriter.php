<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

/**
 * A deliberately small XLSX (Office Open XML) writer: right-to-left sheets,
 * a bold header row and plain cells. Text is written as inline strings (so
 * IDs and phone numbers keep leading zeros); integers as numbers. Used by
 * issued beneficiary lists and Reports V1 exports. No external dependency,
 * nothing is written to public storage — rows are streamed into temp
 * files, zipped and returned as bytes.
 */
class XlsxWriter
{
    /**
     * One-sheet workbook.
     *
     * @param  list<string>  $headers
     * @param  iterable<list<string|int|float|null>>  $rows
     */
    public static function build(string $sheetName, array $headers, iterable $rows): string
    {
        return self::workbook([['name' => $sheetName, 'headers' => $headers, 'rows' => $rows]]);
    }

    /**
     * @param  list<array{name: string, headers: list<string>, rows: iterable<list<string|int|float|null>>}>  $sheets
     */
    public static function workbook(array $sheets): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create XLSX archive.');
        }

        $overrides = '';
        $sheetEntries = '';
        $relations = '';
        foreach (array_values($sheets) as $i => $sheet) {
            $n = $i + 1;
            $overrides .= '<Override PartName="/xl/worksheets/sheet'.$n.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $sheetEntries .= '<sheet name="'.self::escape(self::sheetName($sheet['name'])).'" sheetId="'.$n.'" r:id="rId'.$n.'"/>';
            $relations .= '<Relationship Id="rId'.$n.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$n.'.xml"/>';
        }
        $stylesId = count($sheets) + 1;

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .$overrides
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$sheetEntries.'</sheets>'
            .'</workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .$relations
            .'<Relationship Id="rId'.$stylesId.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2"><font><sz val="11"/><name val="Arial"/></font><font><b/><sz val="11"/><name val="Arial"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            .'</styleSheet>');

        $parts = [];
        foreach (array_values($sheets) as $i => $sheet) {
            // Rows are streamed to a temp file, never held as one string.
            $part = tempnam(sys_get_temp_dir(), 'xlsx-sheet');
            $out = fopen($part, 'wb');
            fwrite($out, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                .'<sheetViews><sheetView workbookViewId="0" rightToLeft="1"/></sheetViews>'
                .'<sheetData>'
                .self::row(1, $sheet['headers'], bold: true));
            $number = 2;
            foreach ($sheet['rows'] as $row) {
                fwrite($out, self::row($number++, $row));
            }
            fwrite($out, '</sheetData></worksheet>');
            fclose($out);
            $zip->addFile($part, 'xl/worksheets/sheet'.($i + 1).'.xml');
            $parts[] = $part;
        }
        $zip->close();

        $bytes = file_get_contents($path);
        @unlink($path);
        foreach ($parts as $part) {
            @unlink($part);
        }

        return $bytes;
    }

    /** Excel sheet names: max 31 characters, no []:*?/\ characters. */
    private static function sheetName(string $name): string
    {
        return mb_substr(str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', $name), 0, 31);
    }

    /** @param  list<string|int|float|null>  $values */
    private static function row(int $number, array $values, bool $bold = false): string
    {
        $xml = '<row r="'.$number.'">';
        foreach (array_values($values) as $i => $value) {
            $ref = self::column($i).$number;
            $style = $bold ? ' s="1"' : '';
            if ($value === null || $value === '') {
                $xml .= '<c r="'.$ref.'"'.$style.'/>';
            } elseif (is_int($value) || is_float($value)) {
                $xml .= '<c r="'.$ref.'"'.$style.'><v>'.$value.'</v></c>';
            } else {
                $xml .= '<c r="'.$ref.'"'.$style.' t="inlineStr"><is><t xml:space="preserve">'.self::escape((string) $value).'</t></is></c>';
            }
        }

        return $xml.'</row>';
    }

    /** 0 → A, 25 → Z, 26 → AA. */
    private static function column(int $index): string
    {
        $name = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + ($n - 1) % 26).$name;
        }

        return $name;
    }

    private static function escape(string $value): string
    {
        // Strip characters that are invalid in XML 1.0.
        $value = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $value) ?? '';

        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
