<?php

namespace App\Support\IdentityRef;

use JsonException;

/**
 * The manifest of an identity export directory (FB-CIVIL-01A-P2), validated
 * before anything touches a database: structure, the 36 approved columns in
 * order, totals, safe file names, and an exact file inventory (nothing
 * missing, nothing extra, no symlink, no path outside the directory).
 *
 * Contract (as profiled in P0): manifest.json =
 *   {"table": "...", "columns": [36 names | {"name": ...}], "total_rows": N,
 *    "encoding": "UTF-8 with BOM" (optional), "files": [{"file", "rows", "sha256"}]}
 *
 * `problems` holds fixed codes, optionally with a safe file name — never a
 * value from the export, never an unsafe name.
 */
final class IdentityManifest
{
    public const FILE_NAME = 'manifest.json';

    /** The 36 approved source columns, in order (identity_ref.source_columns()). */
    public const COLUMNS = [
        'sert1', 'mainid', 'name1', 'father', 'grandfath', 'family', 'gender', 'govern', 'city', 'street',
        'fatherid', 'motherid', 'partnerid', 'datebirth', 'age', 'maritalst', 'deathdate', 'presonstate',
        'relgion', 'birthplace', 'householdid', 'householdrel', 'prevfamily', 'phonenum', 'worker', 'att',
        'worktype', 'source', 'civilg', 'cwloc', 'mgrade', 'mstartw', 'msalary', 'select', 'job', 'fullname',
    ];

    /** Same rule as identity_ref.import_files.file_name, restricted to .csv. */
    public const SAFE_FILE_NAME = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,123}\.csv$/';

    private const MAX_MANIFEST_BYTES = 1_048_576;

    /**
     * @param  list<array{file: string, rows: int, sha256: string}>  $files
     */
    private function __construct(
        public readonly string $directory,
        public readonly string $sha256,
        public readonly string $table,
        public readonly int $totalRows,
        public readonly array $files,
    ) {}

    /**
     * @throws IdentityManifestException
     */
    public static function fromDirectory(string $directory, ?int $expectedFiles = null, ?int $expectedRows = null): self
    {
        $real = realpath($directory);
        if ($real === false || ! is_dir($real) || is_link(rtrim($directory, '\\/'))) {
            throw new IdentityManifestException(['DIRECTORY_INVALID']);
        }
        $manifestPath = $real.DIRECTORY_SEPARATOR.self::FILE_NAME;
        if (is_link($manifestPath) || ! is_file($manifestPath)) {
            throw new IdentityManifestException(['MANIFEST_MISSING']);
        }
        if (filesize($manifestPath) > self::MAX_MANIFEST_BYTES) {
            throw new IdentityManifestException(['MANIFEST_TOO_LARGE']);
        }
        $bytes = (string) file_get_contents($manifestPath);
        $sha256 = hash('sha256', $bytes);
        try {
            $data = json_decode(str_starts_with($bytes, "\xEF\xBB\xBF") ? substr($bytes, 3) : $bytes, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new IdentityManifestException(['MANIFEST_NOT_JSON']);
        }
        if (! is_array($data) || array_is_list($data)) {
            throw new IdentityManifestException(['MANIFEST_NOT_AN_OBJECT']);
        }

        $problems = [];
        $table = $data['table'] ?? null;
        if (! is_string($table) || preg_match('/^[A-Za-z0-9_]{1,64}$/', $table) !== 1) {
            $problems[] = 'MANIFEST_TABLE_INVALID';
        }
        $columns = $data['columns'] ?? null;
        $names = is_array($columns) ? array_map(fn ($c) => is_array($c) ? ($c['name'] ?? null) : $c, $columns) : null;
        if ($names !== self::COLUMNS) {
            $problems[] = 'MANIFEST_COLUMNS_MISMATCH';
        }
        if (isset($data['encoding']) && (! is_string($data['encoding']) || stripos($data['encoding'], 'UTF-8') === false)) {
            $problems[] = 'MANIFEST_ENCODING_NOT_UTF8';
        }
        $totalRows = $data['total_rows'] ?? null;
        if (! is_int($totalRows) || $totalRows < 0) {
            $problems[] = 'MANIFEST_TOTAL_ROWS_INVALID';
        }

        $files = [];
        $seen = [];
        $entries = $data['files'] ?? null;
        if (! is_array($entries) || $entries === [] || ! array_is_list($entries)) {
            $problems[] = 'MANIFEST_FILES_INVALID';
            $entries = [];
        }
        foreach ($entries as $entry) {
            $name = is_array($entry) ? ($entry['file'] ?? null) : null;
            $rows = is_array($entry) ? ($entry['rows'] ?? null) : null;
            $sha = is_array($entry) ? ($entry['sha256'] ?? null) : null;
            if (! is_string($name) || preg_match(self::SAFE_FILE_NAME, $name) !== 1) {
                $problems[] = 'MANIFEST_FILE_NAME_UNSAFE';

                continue;
            }
            if (isset($seen[strtolower($name)])) {
                $problems[] = 'MANIFEST_FILE_DUPLICATED '.$name;

                continue;
            }
            $seen[strtolower($name)] = true;
            if (! is_int($rows) || $rows < 0 || ! is_string($sha) || preg_match('/^[0-9a-fA-F]{64}$/', $sha) !== 1) {
                $problems[] = 'MANIFEST_FILE_ENTRY_INVALID '.$name;

                continue;
            }
            $files[] = ['file' => $name, 'rows' => $rows, 'sha256' => strtolower($sha)];
        }
        if (is_int($totalRows) && $files !== [] && count($files) === count($entries)
            && array_sum(array_column($files, 'rows')) !== $totalRows) {
            $problems[] = 'MANIFEST_TOTAL_ROWS_MISMATCH';
        }
        if ($expectedFiles !== null && count($entries) !== $expectedFiles) {
            $problems[] = 'MANIFEST_FILE_COUNT_NOT_APPROVED';
        }
        if ($expectedRows !== null && $totalRows !== $expectedRows) {
            $problems[] = 'MANIFEST_ROW_COUNT_NOT_APPROVED';
        }

        $problems = array_merge($problems, self::inventoryProblems($real, $files));
        if ($problems !== []) {
            throw new IdentityManifestException($problems);
        }

        return new self($real, $sha256, $table, $totalRows, $files);
    }

    public function path(string $fileName): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.$fileName;
    }

    /**
     * Exactly manifest.json plus the listed files: regular files, not
     * symlinks, resolving inside the directory.
     *
     * @param  list<array{file: string, rows: int, sha256: string}>  $files
     * @return list<string>
     */
    private static function inventoryProblems(string $real, array $files): array
    {
        $problems = [];
        $listed = [];
        foreach ($files as $f) {
            $listed[strtolower($f['file'])] = $f['file'];
        }
        $extra = 0;
        foreach (scandir($real) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === self::FILE_NAME) {
                continue;
            }
            if (! isset($listed[strtolower($entry)])) {
                $extra++;
            }
        }
        if ($extra > 0) {
            $problems[] = 'UNEXPECTED_ENTRIES '.$extra;
        }
        foreach ($files as $f) {
            $path = $real.DIRECTORY_SEPARATOR.$f['file'];
            if (is_link($path)) {
                $problems[] = 'FILE_IS_SYMLINK '.$f['file'];
            } elseif (! is_file($path)) {
                $problems[] = 'FILE_MISSING '.$f['file'];
            } elseif (dirname((string) realpath($path)) !== $real) {
                $problems[] = 'FILE_OUTSIDE_DIRECTORY '.$f['file'];
            }
        }

        return $problems;
    }
}
