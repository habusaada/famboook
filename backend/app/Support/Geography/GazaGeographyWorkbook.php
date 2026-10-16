<?php

namespace App\Support\Geography;

use App\Enums\GazaLocationLevel;
use App\Exceptions\GazaGeographyImportException;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use Throwable;

/**
 * Reads the Gaza geographic classification workbook into one node per
 * (level, source code) — read-only, no database access (ADR-FB-GEO-01
 * §3.3).
 *
 * - The file must exist and match the expected SHA-256 before it is opened.
 * - Exactly one worksheet, named GazaGeographySource::SHEET, whose row 1 is
 *   exactly the audited 13 columns.
 * - Every data row must be complete: codes are integers (stored as text, as
 *   supplied) and names non-blank text. Values are never trimmed or
 *   normalized. Blank or invalid rows are never skipped: they abort.
 * - A code seen on several rows must always carry the same name, parent and
 *   (landmarks) type; a landmark code appears once. LAN_TITLE must be exactly
 *   "LAN_TYPE_TITLE - LAN_TITLE_NEW" (Stage 1A I2), so storing the type and
 *   LAN_TITLE_NEW loses nothing.
 *
 * Problems are collected with their sheet row numbers and reported together.
 */
final class GazaGeographyWorkbook
{
    /** Problems listed before the report is cut short. */
    private const MAX_PROBLEMS = 50;

    /**
     * Nodes keyed "LEVEL:code", parents before children, in source order.
     *
     * @return array<string, array{level: GazaLocationLevel, source_code: string, name: string, parent_key: ?string, landmark_type_code: ?string, landmark_type_name: ?string, rows: list<int>}>
     *
     * @throws GazaGeographyImportException
     */
    public static function read(GazaGeographySource $source): array
    {
        if (! is_file($source->path)) {
            throw new GazaGeographyImportException(
                GazaGeographyImportException::MISSING_FILE,
                "Source workbook not found: {$source->path}",
            );
        }
        $actual = hash_file('sha256', $source->path);
        if (! hash_equals($source->expectedSha256, (string) $actual)) {
            throw new GazaGeographyImportException(
                GazaGeographyImportException::CHECKSUM_MISMATCH,
                'Source workbook checksum does not match the audited version.',
                ["expected {$source->expectedSha256}", "actual {$actual}"],
            );
        }

        $rows = self::rows($source->path);

        $nodes = array_fill_keys(array_map(fn (GazaLocationLevel $l) => $l->value, GazaLocationLevel::ordered()), []);
        $problems = [];
        foreach ($rows as $rowNumber => $values) {
            $parsed = self::parseRow($rowNumber, $values, $problems);
            if ($parsed === null) {
                continue;
            }
            $parentKey = null;
            foreach (GazaLocationLevel::ordered() as $level) {
                $node = $parsed[$level->value] + ['parent_key' => $parentKey];
                $key = $level->value.':'.$node['source_code'];
                $existing = $nodes[$level->value][$key] ?? null;
                if ($existing === null) {
                    $nodes[$level->value][$key] = $node + ['rows' => [$rowNumber]];
                } elseif ($level === GazaLocationLevel::LANDMARK) {
                    $problems[] = "Row {$rowNumber}: landmark code {$node['source_code']} already appears on row {$existing['rows'][0]}.";
                } else {
                    foreach (['name', 'parent_key', 'landmark_type_code', 'landmark_type_name'] as $field) {
                        if ($existing[$field] !== $node[$field]) {
                            $problems[] = "Row {$rowNumber}: {$level->value} {$node['source_code']} has a different {$field} than on row {$existing['rows'][0]}.";
                        }
                    }
                    $nodes[$level->value][$key]['rows'][] = $rowNumber;
                }
                $parentKey = $key;
            }
        }
        self::checkLandmarkTypes($nodes['LANDMARK'], $problems);

        if ($problems !== []) {
            throw new GazaGeographyImportException(
                GazaGeographyImportException::INVALID_RECORD,
                'The source workbook contains invalid or contradictory records; nothing was imported.',
                self::cap($problems),
            );
        }

        return array_merge(...array_values($nodes));
    }

    /**
     * Data rows of the only worksheet, keyed by sheet row number.
     *
     * @return array<int, list<mixed>>
     */
    private static function rows(string $path): array
    {
        $options = new Options;
        // Keep empty rows so a blank row is seen (and refused) and row numbers are real.
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
        $reader = new Reader($options);
        try {
            $reader->open($path);
        } catch (Throwable) {
            throw self::structure('The source file is not a readable .xlsx workbook.');
        }

        try {
            $sheets = [];
            $rows = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                $sheets[] = $sheet->getName();
                if (count($sheets) > 1) {
                    continue;
                }
                foreach ($sheet->getRowIterator() as $rowNumber => $row) {
                    $rows[$rowNumber] = array_map(fn ($cell) => $cell->getValue(), $row->getCells());
                }
            }
        } catch (GazaGeographyImportException $e) {
            throw $e;
        } catch (Throwable) {
            throw self::structure('The source workbook could not be read.');
        } finally {
            $reader->close();
        }

        if ($sheets !== [GazaGeographySource::SHEET]) {
            throw self::structure('Expected exactly one worksheet named '.GazaGeographySource::SHEET.'.', ['found: '.implode(', ', $sheets)]);
        }
        $header = $rows[1] ?? [];
        unset($rows[1]);
        if ($header !== GazaGeographySource::COLUMNS) {
            throw self::structure('Unexpected header row: the workbook must have exactly the audited 13 columns, in order.', [
                'expected: '.implode(', ', GazaGeographySource::COLUMNS),
                'found: '.implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : gettype($v), $header)),
            ]);
        }
        if ($rows === []) {
            throw self::structure('The worksheet has no data rows.');
        }

        return $rows;
    }

    /**
     * @param  list<mixed>  $values
     * @param  list<string>  $problems
     * @return array<string, array{level: GazaLocationLevel, source_code: string, name: string, landmark_type_code: ?string, landmark_type_name: ?string}>|null
     */
    private static function parseRow(int $rowNumber, array $values, array &$problems): ?array
    {
        $cells = [];
        foreach (GazaGeographySource::COLUMNS as $i => $column) {
            $cells[$column] = $values[$i] ?? null;
        }
        if (count($values) > count(GazaGeographySource::COLUMNS)
            && array_filter(array_slice($values, count(GazaGeographySource::COLUMNS)), fn ($v) => $v !== null && $v !== '') !== []) {
            $problems[] = "Row {$rowNumber}: values outside the 13 audited columns.";

            return null;
        }
        if (array_filter($cells, fn ($v) => $v !== null && $v !== '') === []) {
            $problems[] = "Row {$rowNumber}: blank row.";

            return null;
        }

        $ok = true;
        $code = function (string $column) use ($cells, $rowNumber, &$problems, &$ok): string {
            $value = $cells[$column];
            $text = match (true) {
                is_int($value) && $value > 0 => (string) $value,
                is_float($value) && is_finite($value) && $value > 0 && floor($value) === $value && $value < PHP_INT_MAX => (string) (int) $value,
                is_string($value) && preg_match('/^[0-9]+$/', $value) === 1 => $value,
                default => null,
            };
            if ($text === null) {
                $problems[] = "Row {$rowNumber}: {$column} is not a positive integer code.";
                $ok = false;

                return '';
            }

            return $text;
        };
        $name = function (string $column) use ($cells, $rowNumber, &$problems, &$ok): string {
            $value = $cells[$column];
            if (! is_string($value) || trim($value) === '' || mb_strlen($value) > 255) {
                $problems[] = "Row {$rowNumber}: {$column} must be non-blank text of at most 255 characters.";
                $ok = false;

                return '';
            }

            return $value;
        };

        $parsed = [];
        foreach (GazaGeographySource::LEVEL_COLUMNS as $level => [$codeColumn, $nameColumn]) {
            $isLandmark = $level === GazaLocationLevel::LANDMARK->value;
            $parsed[$level] = [
                'level' => GazaLocationLevel::from($level),
                'source_code' => $code($codeColumn),
                'name' => $name($nameColumn),
                'landmark_type_code' => $isLandmark ? $code('LAN_TYPE') : null,
                'landmark_type_name' => $isLandmark ? $name('LAN_TYPE_TITLE') : null,
            ];
        }
        $title = $name('LAN_TITLE');
        if (! $ok) {
            return null;
        }
        if ($title !== $cells['LAN_TYPE_TITLE'].' - '.$cells['LAN_TITLE_NEW']) {
            $problems[] = "Row {$rowNumber}: LAN_TITLE is not \"LAN_TYPE_TITLE - LAN_TITLE_NEW\".";

            return null;
        }

        return $parsed;
    }

    /**
     * One type code always carries the same type name.
     *
     * @param  array<string, array<string, mixed>>  $landmarks
     * @param  list<string>  $problems
     */
    private static function checkLandmarkTypes(array $landmarks, array &$problems): void
    {
        $names = [];
        foreach ($landmarks as $landmark) {
            $code = $landmark['landmark_type_code'];
            $names[$code] ??= [$landmark['landmark_type_name'], $landmark['rows'][0]];
            if ($names[$code][0] !== $landmark['landmark_type_name']) {
                $problems[] = "Row {$landmark['rows'][0]}: landmark type {$code} has a different name than on row {$names[$code][1]}.";
            }
        }
    }

    /** @param list<string> $problems */
    private static function cap(array $problems): array
    {
        if (count($problems) <= self::MAX_PROBLEMS) {
            return $problems;
        }

        return [...array_slice($problems, 0, self::MAX_PROBLEMS), '… and '.(count($problems) - self::MAX_PROBLEMS).' more.'];
    }

    /** @param list<string> $problems */
    private static function structure(string $message, array $problems = []): GazaGeographyImportException
    {
        return new GazaGeographyImportException(GazaGeographyImportException::INVALID_STRUCTURE, $message, $problems);
    }
}
