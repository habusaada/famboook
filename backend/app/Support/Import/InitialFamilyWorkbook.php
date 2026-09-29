<?php

namespace App\Support\Import;

use App\Exceptions\InvalidImportWorkbookException;
use App\Support\ImportRawPayload;
use DateInterval;
use DateTimeInterface;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\ErrorCell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use Throwable;

/**
 * Reads the family source workbook (docs/03 §96a) for the Import Wizard.
 *
 * - inspect(): worksheet and header STRUCTURE only (sheet names, column
 *   letters/positions, header labels, row counts) — never cell values. The
 *   header row is row 1. One obvious data sheet is suggested; several
 *   plausible sheets need an explicit choice.
 * - samples(): a few MASKED preview values per column for the mapping step.
 * - rows(): the data rows of the chosen sheet through the CONFIRMED column
 *   mapping (canonical field → column letter). Columns are identified by
 *   position/letter, never by header text alone, so repeated headers (the
 *   four "هوية الزوجة" / "الزوجة" pairs) never collide.
 *
 * Header normalization (spacing, tatweel, diacritics, alef/yeh/teh-marbuta
 * forms) is used ONLY to suggest mappings. Cell VALUES are never normalized
 * here. Excluded columns (هويتك, الديانة) are never offered for mapping and
 * their values are never read into any result. Formula cells give Excel's
 * cached value and stay marked as formulas; no formula is evaluated. Blank
 * rows are skipped and row numbers are the real sheet rows.
 *
 * No database access: reading never creates any record.
 */
final class InitialFamilyWorkbook
{
    public const MAX_ROWS = 20000;

    public const MAX_WIVES = 4;

    public const SAMPLE_ROWS = 3;

    /** Canonical single-value fields, in display order. */
    public const SCALAR_FIELDS = [
        'source_family_key', 'national_id', 'full_name', 'birth_date', 'gender', 'marital_status',
        // The family's ORIGINAL city / residence (never current, never displacement).
        'original_residence_text',
        'life_status_source', 'death_date',
        'declared_household_size', 'declared_living_sons', 'declared_living_daughters', 'mobile',
    ];

    /** Fields whose mapping is required to confirm (approved import rules). */
    public const REQUIRED_FIELDS = ['source_family_key', 'national_id', 'full_name'];

    /** Known header labels → suggested canonical field. */
    public const SUGGESTIONS = [
        'المفتاح' => 'source_family_key',
        'رقم الهوية' => 'national_id',
        'الاسم' => 'full_name',
        'الميلاد' => 'birth_date',
        'الجنس' => 'gender',
        'الحالة الاجتماعية' => 'marital_status',
        'المدينة' => 'original_residence_text',
        'حالة الوفاة' => 'life_status_source',
        'الوفاة' => 'death_date',
        'أفراد الأسرة' => 'declared_household_size',
        'أبناء ذكور أحياء' => 'declared_living_sons',
        'أبناء إناث أحياء' => 'declared_living_daughters',
        'الجوال' => 'mobile',
    ];

    public const WIFE_ID_HEADER = 'هوية الزوجة';

    public const WIFE_NAME_HEADER = 'الزوجة';

    /** @return list<string> every canonical field */
    public static function fields(): array
    {
        $fields = self::SCALAR_FIELDS;
        foreach (range(1, self::MAX_WIVES) as $k) {
            $fields[] = "wife_{$k}_national_id";
            $fields[] = "wife_{$k}_name";
        }

        return $fields;
    }

    /**
     * Structure of every worksheet (no cell values).
     *
     * @return array{worksheets: list<array{name: string, position: int, data_rows: int, too_many_rows: bool, plausible: bool, recognized: bool, columns: list<array{letter: string, position: int, header: string, excluded: bool, suggested_field: ?string}>}>, suggested_worksheet: ?string}
     *
     * @throws InvalidImportWorkbookException
     */
    public static function inspect(string $path): array
    {
        $sheets = [];
        self::each($path, function ($sheet, int $position) use (&$sheets) {
            $columns = null;
            $dataRows = 0;
            foreach ($sheet->getRowIterator() as $rowNumber => $row) {
                if ($columns === null) {
                    $columns = $rowNumber === 1 ? self::columns($row->getCells()) : [];

                    continue;
                }
                if (! self::isBlank($row->getCells(), $columns)) {
                    $dataRows++;
                }
                if ($dataRows > self::MAX_ROWS) {
                    break;
                }
            }
            $columns ??= [];
            $suggested = array_filter(array_column($columns, 'suggested_field'));

            $sheets[] = [
                'name' => $sheet->getName(),
                'position' => $position,
                'data_rows' => min($dataRows, self::MAX_ROWS + 1),
                'too_many_rows' => $dataRows > self::MAX_ROWS,
                'plausible' => $columns !== [] && $dataRows > 0,
                'recognized' => array_diff(self::REQUIRED_FIELDS, $suggested) === [],
                'columns' => $columns,
            ];
        });

        $candidates = array_values(array_filter($sheets, fn ($s) => $s['plausible']));
        if ($candidates === []) {
            throw new InvalidImportWorkbookException('لم يُعثر على ورقة عمل تحتوي على صف عناوين وبيانات.');
        }
        $recognized = array_values(array_filter($candidates, fn ($s) => $s['recognized']));
        $suggested = match (true) {
            count($candidates) === 1 => $candidates[0]['name'],
            count($recognized) === 1 => $recognized[0]['name'],
            default => null, // several plausible sheets: the administrator chooses
        };

        return ['worksheets' => $sheets, 'suggested_worksheet' => $suggested];
    }

    /**
     * A few masked preview values per kept column of one sheet.
     *
     * @return array<string, list<string>> column letter => samples
     */
    public static function samples(string $path, string $worksheet): array
    {
        $samples = [];
        self::onSheet($path, $worksheet, function ($sheet) use (&$samples) {
            $columns = null;
            $taken = 0;
            foreach ($sheet->getRowIterator() as $rowNumber => $row) {
                $cells = $row->getCells();
                if ($columns === null) {
                    $columns = $rowNumber === 1 ? self::columns($cells) : [];

                    continue;
                }
                if (self::isBlank($cells, $columns)) {
                    continue;
                }
                foreach ($columns as $column) {
                    if ($column['excluded']) {
                        continue; // never read
                    }
                    $value = isset($cells[$column['position']]) ? self::scalar($cells[$column['position']])['value'] : null;
                    if ($value !== null) {
                        $samples[$column['letter']][] = self::mask($value);
                    }
                }
                if (++$taken >= self::SAMPLE_ROWS) {
                    break;
                }
            }
        });

        return $samples;
    }

    /**
     * Data rows of the chosen sheet through a confirmed mapping.
     *
     * @param  array<string, string>  $mapping  canonical field => column letter
     * @return list<array{row_number: int, cells: array<string, array>, wives: list<array{id: ?array, name: ?array}>, extra_cells: bool}>
     *
     * @throws InvalidImportWorkbookException
     */
    public static function rows(string $path, string $worksheet, array $mapping): array
    {
        $rows = [];
        self::onSheet($path, $worksheet, function ($sheet) use ($mapping, &$rows) {
            $columns = null;
            foreach ($sheet->getRowIterator() as $rowNumber => $row) {
                $cells = $row->getCells();
                if ($columns === null) {
                    $columns = $rowNumber === 1 ? self::columns($cells) : [];

                    continue;
                }
                $parsed = self::row($rowNumber, $cells, $columns, $mapping);
                if ($parsed === null) {
                    continue; // blank row
                }
                if (count($rows) >= self::MAX_ROWS) {
                    throw new InvalidImportWorkbookException('عدد الصفوف يتجاوز الحد المسموح ('.self::MAX_ROWS.').');
                }
                $rows[] = $parsed;
            }
        });

        return $rows;
    }

    /** Cosmetic header normalization, used ONLY to suggest mappings. */
    public static function normalizeHeader(string $header): string
    {
        $header = preg_replace('/[\x{064B}-\x{0652}\x{0670}\x{0640}]/u', '', $header) ?? $header;
        $header = strtr($header, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه']);

        return preg_replace('/\s+/u', '', $header) ?? $header;
    }

    public static function columnLetter(int $position): string
    {
        $letter = '';
        for ($n = $position + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letter = chr(65 + ($n - 1) % 26).$letter;
        }

        return $letter;
    }

    /**
     * Header row → columns with stable identity (letter + position) and suggestions.
     *
     * @param  array<int, Cell>  $cells
     * @return list<array{letter: string, position: int, header: string, excluded: bool, suggested_field: ?string}>
     */
    private static function columns(array $cells): array
    {
        $known = [];
        foreach (self::SUGGESTIONS as $header => $field) {
            $known[self::normalizeHeader($header)] = $field;
        }
        $wifeId = self::normalizeHeader(self::WIFE_ID_HEADER);
        $wifeName = self::normalizeHeader(self::WIFE_NAME_HEADER);

        $columns = [];
        $used = [];
        $wifeIds = 0;
        $wifeNames = 0;
        foreach ($cells as $position => $cell) {
            $header = trim((string) self::scalar($cell)['value']);
            if ($header === '') {
                continue;
            }
            $excluded = ImportRawPayload::isExcluded($header);
            $suggestion = null;
            if (! $excluded) {
                $key = self::normalizeHeader($header);
                if (isset($known[$key])) {
                    $suggestion = $known[$key];
                } elseif ($key === $wifeId && $wifeIds < self::MAX_WIVES) {
                    // The k-th occurrence suggests wife slot k (by position).
                    $suggestion = 'wife_'.(++$wifeIds).'_national_id';
                } elseif ($key === $wifeName && $wifeNames < self::MAX_WIVES) {
                    $suggestion = 'wife_'.(++$wifeNames).'_name';
                }
                // A field is suggested for its first column only.
                if ($suggestion !== null && isset($used[$suggestion])) {
                    $suggestion = null;
                }
                if ($suggestion !== null) {
                    $used[$suggestion] = true;
                }
            }
            $columns[] = [
                'letter' => self::columnLetter($position),
                'position' => $position,
                'header' => $header,
                'excluded' => $excluded,
                'suggested_field' => $suggestion,
            ];
        }

        return $columns;
    }

    /**
     * @param  array<int, Cell>  $cells
     * @param  list<array{letter: string, position: int, header: string, excluded: bool}>  $columns
     * @param  array<string, string>  $mapping
     */
    private static function row(int $rowNumber, array $cells, array $columns, array $mapping): ?array
    {
        $byLetter = [];
        foreach ($columns as $column) {
            $byLetter[$column['letter']] = $column;
        }

        $pick = function (?string $letter) use ($cells, $byLetter): ?array {
            $column = $letter !== null ? ($byLetter[$letter] ?? null) : null;
            if ($column === null || $column['excluded']) {
                return null;
            }
            $cell = $cells[$column['position']] ?? null;
            if ($cell === null) {
                return null;
            }
            $value = self::scalar($cell);
            if ($value['value'] === null && ! isset($value['error'])) {
                return null;
            }

            return ['column' => $column['letter'], 'header' => $column['header'], ...$value];
        };

        $out = [];
        foreach (self::SCALAR_FIELDS as $field) {
            if (($entry = $pick($mapping[$field] ?? null)) !== null) {
                $out[$field] = $entry;
            }
        }
        $wives = [];
        foreach (range(1, self::MAX_WIVES) as $k) {
            $wives[] = ['id' => $pick($mapping["wife_{$k}_national_id"] ?? null), 'name' => $pick($mapping["wife_{$k}_name"] ?? null)];
        }

        // A value under no header at all is structurally unexpected.
        $headed = array_flip(array_column($columns, 'position'));
        $extra = false;
        foreach ($cells as $position => $cell) {
            if (! isset($headed[$position]) && self::scalar($cell)['value'] !== null) {
                $extra = true;
            }
        }

        $hasWife = collect($wives)->contains(fn ($w) => $w['id'] !== null || $w['name'] !== null);
        if ($out === [] && ! $hasWife && ! $extra && self::isBlank($cells, $columns)) {
            return null;
        }

        return ['row_number' => $rowNumber, 'cells' => $out, 'wives' => $wives, 'extra_cells' => $extra];
    }

    /**
     * A row with no value in any kept column (excluded columns never count).
     *
     * @param  array<int, Cell>  $cells
     * @param  list<array{position: int, excluded: bool}>|null  $columns
     */
    private static function isBlank(array $cells, ?array $columns = null): bool
    {
        $excluded = [];
        foreach ($columns ?? [] as $column) {
            if ($column['excluded']) {
                $excluded[$column['position']] = true;
            }
        }
        foreach ($cells as $position => $cell) {
            if (! isset($excluded[$position]) && self::scalar($cell)['value'] !== null) {
                return false;
            }
        }

        return true;
    }

    /** Samples never reveal a full ID or phone number: long digit runs are masked. */
    private static function mask(string $value): string
    {
        $value = mb_substr($value, 0, 40);

        return preg_replace_callback('/\d{5,}/', fn ($m) => str_repeat('•', strlen($m[0]) - 3).substr($m[0], -3), $value) ?? $value;
    }

    /** @param callable(mixed, int): void $callback */
    private static function each(string $path, callable $callback): void
    {
        $reader = self::open($path);
        try {
            $position = 0;
            foreach ($reader->getSheetIterator() as $sheet) {
                $callback($sheet, $position++);
            }
        } catch (InvalidImportWorkbookException $e) {
            throw $e;
        } catch (Throwable) {
            throw new InvalidImportWorkbookException('تعذّرت قراءة محتوى الملف.');
        } finally {
            $reader->close();
        }
    }

    /** @param callable(mixed): void $callback */
    private static function onSheet(string $path, string $worksheet, callable $callback): void
    {
        $found = false;
        self::each($path, function ($sheet) use ($worksheet, $callback, &$found) {
            if (! $found && $sheet->getName() === $worksheet) {
                $found = true;
                $callback($sheet);
            }
        });
        if (! $found) {
            throw new InvalidImportWorkbookException('ورقة العمل المحددة غير موجودة في الملف.');
        }
    }

    private static function open(string $path): Reader
    {
        $options = new Options;
        // Keep empty rows so the iterator key is the real sheet row number.
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
        $reader = new Reader($options);
        try {
            $reader->open($path);
        } catch (Throwable) {
            throw new InvalidImportWorkbookException('تعذّرت قراءة الملف. يجب أن يكون ملف Excel بصيغة ‎.xlsx‎ صالحًا.');
        }

        return $reader;
    }

    /**
     * A cell as text, never evaluated: formulas give their cached result.
     *
     * @return array{value: string|null, formula?: true, error?: true}
     */
    private static function scalar(Cell $cell): array
    {
        if ($cell instanceof ErrorCell) {
            return ['value' => null, 'error' => true];
        }
        $formula = $cell instanceof FormulaCell;
        $value = $formula ? $cell->getComputedValue() : $cell->getValue();

        $text = match (true) {
            $value === null => null,
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            $value instanceof DateInterval => $value->format('%h:%I:%S'),
            is_bool($value) => $value ? '1' : '0',
            is_int($value) => (string) $value,
            // Integral floats (IDs, counts) without exponent notation.
            is_float($value) => floor($value) === $value && abs($value) < 1e15 ? sprintf('%.0f', $value) : (string) $value,
            default => (string) $value,
        };
        if ($text !== null && trim($text) === '') {
            $text = null;
        }

        return $formula ? ['value' => $text, 'formula' => true] : ['value' => $text];
    }
}
