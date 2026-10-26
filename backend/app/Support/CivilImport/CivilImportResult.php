<?php

namespace App\Support\CivilImport;

/**
 * The allow-list for civil_import_requests.result (FB-CIVIL-01A-UI U2): the
 * FB-CIVIL-01A reports reduced to counts, codes, ids and flags. Anything else
 * is dropped, never stored. Problem strings such as "FILE_MISSING <name>" are
 * reduced to their code, so no file name, path, line, value or SQL can reach
 * the control plane or the API.
 */
final class CivilImportResult
{
    private const CODE = '/^[A-Z][A-Z0-9_]{0,63}$/';

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /** Non-negative integers. */
    private const COUNTS = ['files', 'files_staged_now', 'files_reset', 'chunks_verified', 'chunks_loaded', 'rows_loaded_now', 'rows_staged',
        'total_rows', 'record_count', 'rows_purged', 'chunks_materialized', 'release_id', 'base_release', 'active_release',
        'abandoned_release', 'duration_seconds'];

    private const FLAGS = ['resumed', 'publishable'];

    private const UUIDS = ['run_id', 'aborted_run'];

    /** A single code. */
    private const CODES = ['release_status', 'preparation', 'manifest'];

    /** Policy ids (IdentityImportPolicy: IDREF-POLICY-1). */
    private const POLICY = '/^[A-Z][A-Z0-9_-]{0,63}$/';

    /** CODE => count maps. */
    private const CODE_MAPS = ['issues', 'conflicts', 'warnings', 'problems'];

    /** Lists of codes. */
    private const CODE_LISTS = ['fatal', 'blocked'];

    /** The comparison counts (FB-CIVIL-01A assessment / preparation). */
    private const COMPARISON = ['staged', 'new', 'changed', 'unchanged', 'conflict', 'carried_forward', 'blank_over_value', 'expected_presence'];

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public static function sanitize(array $report): array
    {
        $out = [];
        foreach ($report as $key => $value) {
            $clean = match (true) {
                in_array($key, self::COUNTS, true) => self::count($value),
                in_array($key, self::FLAGS, true) => is_bool($value) ? $value : null,
                in_array($key, self::UUIDS, true) => is_string($value) && preg_match(self::UUID, $value) === 1 ? $value : null,
                in_array($key, self::CODES, true) => is_string($value) ? self::code($value) : null,
                $key === 'policy_id' => is_string($value) && preg_match(self::POLICY, $value) === 1 ? $value : null,
                in_array($key, self::CODE_MAPS, true) => is_array($value) ? self::codeMap($value) : null,
                in_array($key, self::CODE_LISTS, true) => is_array($value) ? self::codeList($value) : null,
                $key === 'counts' => is_array($value) ? self::comparison($value) : null,
                default => null,
            };
            if ($clean !== null) {
                $out[$key] = $clean;
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * Problem strings or ['code' => …] entries counted by code.
     *
     * @param  list<string|array<string, mixed>>  $problems
     * @return array<string, int>
     */
    public static function problemCodes(array $problems): array
    {
        $codes = [];
        foreach ($problems as $problem) {
            $code = self::code(is_array($problem) ? (string) ($problem['code'] ?? '') : (string) $problem) ?? 'OTHER';
            $codes[$code] = ($codes[$code] ?? 0) + 1;
        }
        ksort($codes);

        return $codes;
    }

    /** The leading code of a problem string ("FILE_MISSING x.csv" → FILE_MISSING), or null. */
    public static function code(string $text): ?string
    {
        $token = strtok(trim($text), " \t:");

        return is_string($token) && preg_match(self::CODE, $token) === 1 ? $token : null;
    }

    private static function count(mixed $value): ?int
    {
        if (is_float($value)) {
            $value = (int) round($value);
        }

        return is_int($value) && $value >= 0 ? $value : null;
    }

    /** @return array<string, int> */
    private static function codeMap(array $map): array
    {
        $out = [];
        foreach ($map as $code => $n) {
            $code = self::code((string) $code) ?? 'OTHER';
            $n = self::count($n);
            if ($n !== null) {
                $out[$code] = ($out[$code] ?? 0) + $n;
            }
        }
        ksort($out);

        return $out;
    }

    /** @return list<string> */
    private static function codeList(array $list): array
    {
        $out = [];
        foreach ($list as $item) {
            $out[] = self::code(is_scalar($item) ? (string) $item : '') ?? 'OTHER';
        }

        return array_values(array_unique($out));
    }

    /** @return array<string, int> */
    private static function comparison(array $counts): array
    {
        $out = [];
        foreach (self::COMPARISON as $key) {
            $n = self::count($counts[$key] ?? null);
            if ($n !== null) {
                $out[$key] = $n;
            }
        }

        return $out;
    }
}
