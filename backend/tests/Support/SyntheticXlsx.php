<?php

namespace Tests\Support;

use DateTimeInterface;
use RuntimeException;
use ZipArchive;

/**
 * Test-only builder of small, SYNTHETIC .xlsx workbooks shaped like the
 * initial-family source: duplicate headers, gaps between rows, date-styled
 * cells, error cells and formula cells WITH a cached value (which the
 * OpenSpout writer cannot produce). Never used with real data.
 *
 * A cell is a plain scalar/DateTimeInterface/null, or one of:
 *   ['formula' => 'TEXT(C2)', 'cached' => 'أبو سعادة']            formula with cached value
 *   ['shared_formula' => 0, 'cached' => 'البريم']                  shared-formula child ("=" cell)
 *   ['error' => '#VALUE!']
 */
final class SyntheticXlsx
{
    /**
     * @param  array<string, array<int, list<mixed>>>  $sheets  sheet name => [row number => cells]
     */
    public static function write(array $sheets): string
    {
        $path = tempnam(sys_get_temp_dir(), 'synxlsx').'.xlsx';
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create synthetic workbook.');
        }

        $overrides = '';
        $sheetsXml = '';
        $rels = '';
        $n = 0;
        foreach ($sheets as $name => $rows) {
            $n++;
            $zip->addFromString("xl/worksheets/sheet{$n}.xml", self::sheet($rows));
            $overrides .= '<Override PartName="/xl/worksheets/sheet'.$n.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $sheetsXml .= '<sheet name="'.self::esc($name).'" sheetId="'.$n.'" r:id="rId'.$n.'"/>';
            $rels .= '<Relationship Id="rId'.$n.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$n.'.xml"/>';
        }
        $styleRel = $n + 1;

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'.$overrides.'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$sheetsXml.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'<Relationship Id="rId'.$styleRel.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        // Style 1 = a built-in date format (numFmtId 14), so date cells read as dates.
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="1"><font/></fonts><fills count="1"><fill/></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0"/><xf numFmtId="14" applyNumberFormat="1"/></cellXfs></styleSheet>');
        $zip->close();

        return $path;
    }

    /** @param array<int, list<mixed>> $rows */
    private static function sheet(array $rows): string
    {
        $xml = '';
        foreach ($rows as $r => $cells) {
            $xml .= '<row r="'.$r.'">';
            foreach (array_values($cells) as $i => $cell) {
                $xml .= self::cell(self::ref($i).$r, $cell);
            }
            $xml .= '</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$xml.'</sheetData></worksheet>';
    }

    private static function cell(string $ref, mixed $cell): string
    {
        return match (true) {
            $cell === null => '',
            is_array($cell) && isset($cell['formula']) => '<c r="'.$ref.'" t="str"><f>'.self::esc($cell['formula']).'</f><v>'.self::esc((string) $cell['cached']).'</v></c>',
            is_array($cell) && isset($cell['shared_formula']) => '<c r="'.$ref.'" t="str"><f t="shared" si="'.$cell['shared_formula'].'"/><v>'.self::esc((string) $cell['cached']).'</v></c>',
            is_array($cell) && isset($cell['error']) => '<c r="'.$ref.'" t="e"><v>'.self::esc($cell['error']).'</v></c>',
            $cell instanceof DateTimeInterface => '<c r="'.$ref.'" s="1"><v>'.self::serial($cell).'</v></c>',
            is_int($cell) || is_float($cell) => '<c r="'.$ref.'"><v>'.$cell.'</v></c>',
            default => '<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.self::esc((string) $cell).'</t></is></c>',
        };
    }

    private static function serial(DateTimeInterface $date): int
    {
        return (int) (($date->getTimestamp() / 86400) + 25569);
    }

    private static function ref(int $index): string
    {
        $letter = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letter = chr(65 + ($n - 1) % 26).$letter;
        }

        return $letter;
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
