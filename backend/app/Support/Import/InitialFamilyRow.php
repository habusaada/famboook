<?php

namespace App\Support\Import;

use App\Enums\ImportRowStatus;
use App\Support\ImportRawPayload;

/**
 * Staging representation of one parsed source row (docs/02 §88a, docs/03
 * §96a) — Phase 2A only.
 *
 * - raw_payload: the kept source cells by column letter
 *   ({"A": {"header": "المفتاح", "value": "…", "formula": true}, …}); wife
 *   columns keep their own positions, so repeated headers never collide.
 * - normalized_payload: the explicit field set below. Text is trimmed and
 *   internal whitespace collapsed; nothing is mapped to domain enums, no
 *   Arabic spelling is changed and nothing is inferred (Phase 2C validates).
 * - source_family_key: المفتاح with whitespace normalization ONLY — no
 *   fuzzy matching, no ة/ه or hamza changes, never derived from the name.
 *   A key produced by an Excel formula keeps Excel's cached value but is
 *   marked (origin FORMULA) and flagged for the administrator's review.
 *
 * Classification is structural only: female, widowed, divorced or married
 * heads are never refused, and a missing key never drops the row.
 */
final class InitialFamilyRow
{
    public const KEY_MAX_LENGTH = 150;

    // Issue codes (codes and field names only — never values).
    public const MISSING_FULL_NAME = 'MISSING_FULL_NAME';

    public const MISSING_FAMILY_KEY = 'MISSING_FAMILY_KEY';

    public const FAMILY_KEY_FROM_FORMULA = 'FAMILY_KEY_FROM_FORMULA';

    public const FAMILY_KEY_TOO_LONG = 'FAMILY_KEY_TOO_LONG';

    public const CELL_ERROR = 'CELL_ERROR';

    public const EXTRA_CELLS = 'EXTRA_CELLS';

    private const COUNTS = ['declared_household_size', 'declared_living_sons', 'declared_living_daughters'];

    /**
     * @param  array{row_number: int, cells: array<string, array>, wives: list<array{id: ?array, name: ?array}>, extra_cells: bool}  $row
     * @return array{row_number: int, raw_payload: array, normalized_payload: array, source_family_key: ?string, status: ImportRowStatus, issues: ?list<array{code: string, severity: string, field?: string}>}
     */
    public static function stage(array $row): array
    {
        $issues = [];
        $raw = [];
        $keep = function (?array $cell) use (&$raw): void {
            if ($cell !== null) {
                $raw[$cell['column']] = array_diff_key($cell, ['column' => true]);
            }
        };

        $normalized = [];
        foreach (InitialFamilyWorkbook::SCALAR_FIELDS as $field) {
            $cell = $row['cells'][$field] ?? null;
            $keep($cell);
            if ($cell !== null && isset($cell['error'])) {
                $issues[] = ['code' => self::CELL_ERROR, 'severity' => 'REJECT', 'field' => $field];
            }
            $normalized[$field] = in_array($field, self::COUNTS, true)
                ? self::count($cell['value'] ?? null)
                : self::text($cell['value'] ?? null);
        }

        foreach (range(1, InitialFamilyWorkbook::MAX_WIVES) as $slot) {
            $wife = $row['wives'][$slot - 1] ?? ['id' => null, 'name' => null];
            $keep($wife['id']);
            $keep($wife['name']);
            foreach (['id' => "wife_{$slot}_national_id", 'name' => "wife_{$slot}_name"] as $part => $field) {
                if ($wife[$part] !== null && isset($wife[$part]['error'])) {
                    $issues[] = ['code' => self::CELL_ERROR, 'severity' => 'REJECT', 'field' => $field];
                }
                $normalized[$field] = self::text($wife[$part]['value'] ?? null);
            }
        }

        // المفتاح: whitespace normalization only.
        $keyCell = $row['cells']['source_family_key'] ?? null;
        $key = $normalized['source_family_key'];
        $normalized['source_family_key_origin'] = $key === null ? null : (isset($keyCell['formula']) ? 'FORMULA' : 'VALUE');
        $normalized['formula_fields'] = array_keys(array_filter($row['cells'], fn ($c) => isset($c['formula'])));

        if ($key === null) {
            $issues[] = ['code' => self::MISSING_FAMILY_KEY, 'severity' => 'FLAG'];
        } elseif (mb_strlen($key) > self::KEY_MAX_LENGTH) {
            $issues[] = ['code' => self::FAMILY_KEY_TOO_LONG, 'severity' => 'FLAG'];
            $key = null;
        } elseif ($normalized['source_family_key_origin'] === 'FORMULA') {
            $issues[] = ['code' => self::FAMILY_KEY_FROM_FORMULA, 'severity' => 'FLAG'];
        }

        if ($normalized['full_name'] === null) {
            $issues[] = ['code' => self::MISSING_FULL_NAME, 'severity' => 'FLAG'];
        }
        if ($row['extra_cells']) {
            $issues[] = ['code' => self::EXTRA_CELLS, 'severity' => 'REJECT'];
        }

        $severities = array_column($issues, 'severity');
        $status = match (true) {
            in_array('REJECT', $severities, true) => ImportRowStatus::REJECTED,
            in_array('FLAG', $severities, true) => ImportRowStatus::FLAGGED,
            default => ImportRowStatus::PENDING,
        };

        ksort($raw, SORT_NATURAL);

        return [
            'row_number' => $row['row_number'],
            'raw_payload' => ImportRawPayload::sanitize(['cells' => $raw]),
            'normalized_payload' => ImportRawPayload::sanitize($normalized),
            'source_family_key' => $key,
            'status' => $status,
            'issues' => $issues === [] ? null : $issues,
        ];
    }

    /** Trim and collapse internal whitespace (including non-breaking spaces). Nothing else. */
    public static function normalizeKey(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim(preg_replace('/[\s\p{Z}]+/u', ' ', $value) ?? $value);

        return $value === '' ? null : $value;
    }

    private static function text(?string $value): ?string
    {
        return self::normalizeKey($value);
    }

    /** A non-negative whole number, else null (the source text stays in raw_payload). */
    private static function count(?string $value): ?int
    {
        $value = self::normalizeKey($value);

        return $value !== null && preg_match('/^\d{1,5}$/', $value) ? (int) $value : null;
    }
}
