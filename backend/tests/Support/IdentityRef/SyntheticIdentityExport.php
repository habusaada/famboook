<?php

namespace Tests\Support\IdentityRef;

use App\Support\IdentityRef\IdentityManifest;
use RuntimeException;

/**
 * Builds a SYNTHETIC identity export directory (FB-CIVIL-01A-P2 tests):
 * UTF-8-with-BOM CSV files with the 36 approved columns and a matching
 * manifest.json (SHA-256 and row counts). Values are generated test data —
 * never real identities. A NULL value is written as an unquoted empty
 * field, '' as a quoted empty field, and fields containing a comma, quote
 * or line break are quoted, as RFC 4180 requires.
 */
final class SyntheticIdentityExport
{
    public readonly string $dir;

    /** @var array<string, mixed> */
    public array $manifest;

    /** @param array<string, iterable<array<string, string|null>>> $files file name => rows (column => value), possibly generated lazily */
    private function __construct(array $files, private readonly string $eol)
    {
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'famboook-p2-'.bin2hex(random_bytes(6));
        if (! mkdir($this->dir, 0700) && ! is_dir($this->dir)) {
            throw new RuntimeException('cannot create the synthetic export directory');
        }
        $entries = [];
        foreach ($files as $name => $rows) {
            $count = $this->writeCsv($name, $rows);
            $entries[] = ['file' => $name, 'rows' => $count, 'sha256' => hash_file('sha256', $this->path($name))];
        }
        $this->manifest = ['table' => 't03maindata', 'columns' => IdentityManifest::COLUMNS,
            'total_rows' => array_sum(array_column($entries, 'rows')), 'encoding' => 'UTF-8 with BOM', 'files' => $entries];
        $this->writeManifest();
    }

    /** @param array<string, list<array<string, string|null>>> $files */
    public static function withFiles(array $files, string $eol = "\r\n"): self
    {
        return new self($files, $eol);
    }

    /**
     * $fileCount files of $rowsPerFile generated rows each (the last file may
     * hold $lastFileRows instead), mainid 9 digits from $firstId. Rows are
     * generated while writing, so any size streams in constant memory.
     */
    public static function generated(int $fileCount, int $rowsPerFile, int $firstId = 900_000_001, ?int $lastFileRows = null, ?\Closure $mutate = null): self
    {
        $files = [];
        $id = $firstId;
        for ($f = 1; $f <= $fileCount; $f++) {
            $n = $f === $fileCount && $lastFileRows !== null ? $lastFileRows : $rowsPerFile;
            // $mutate(int $id, array $row): ?array — change a row, or return null to leave it out.
            $files[sprintf('t03maindata_part_%05d.csv', $f)] = (static function () use ($id, $n, $mutate) {
                for ($i = 0; $i < $n; $i++) {
                    $row = self::row((string) ($id + $i));
                    $row = $mutate === null ? $row : $mutate($id + $i, $row);
                    if ($row !== null) {
                        yield $row;
                    }
                }
            })();
            $id += $n;
        }

        return new self($files, "\r\n");
    }

    /** @return array<string, string|null> one synthetic source row */
    public static function row(string $mainid, array $overrides = []): array
    {
        $row = array_fill_keys(IdentityManifest::COLUMNS, null);

        return array_merge($row, [
            'sert1' => '7'.substr(str_pad($mainid, 6, '0', STR_PAD_LEFT), -6),
            'mainid' => $mainid,
            'name1' => 'اسم-تجريبي',
            'father' => 'أب-تجريبي',
            'family' => 'عائلة-تجريبية',
            'gender' => '1',
            'govern' => '2',
            'city' => '15',
            'datebirth' => '1990-01-15T00:00:00',
            'age' => '35',
            'worker' => '0',
            'select' => '0',
            'fullname' => 'اسم-تجريبي أب-تجريبي عائلة-تجريبية',
        ], $overrides);
    }

    public function path(string $name): string
    {
        return $this->dir.DIRECTORY_SEPARATOR.$name;
    }

    public function writeManifest(): void
    {
        file_put_contents($this->path(IdentityManifest::FILE_NAME), json_encode($this->manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /** Replaces a file's raw bytes and (optionally) re-declares its checksum in the manifest. */
    public function replaceBytes(string $name, string $bytes, bool $updateManifest = true): void
    {
        file_put_contents($this->path($name), $bytes);
        if ($updateManifest) {
            foreach ($this->manifest['files'] as &$entry) {
                if ($entry['file'] === $name) {
                    $entry['sha256'] = hash('sha256', $bytes);
                }
            }
            unset($entry);
            $this->writeManifest();
        }
    }

    public function delete(): void
    {
        foreach (scandir($this->dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $p = $this->path($entry);
                is_dir($p) && ! is_link($p) ? rmdir($p) : unlink($p);
            }
        }
        rmdir($this->dir);
    }

    /** @param iterable<array<string, string|null>> $rows */
    private function writeCsv(string $name, iterable $rows): int
    {
        $h = fopen($this->path($name), 'wb');
        fwrite($h, "\xEF\xBB\xBF".implode(',', IdentityManifest::COLUMNS).$this->eol);
        $count = 0;
        foreach ($rows as $row) {
            fwrite($h, self::line($row).$this->eol);
            $count++;
        }
        fclose($h);

        return $count;
    }

    /** @param array<string, string|null> $row */
    public static function line(array $row): string
    {
        return implode(',', array_map(static function (?string $v): string {
            if ($v === null) {
                return '';
            }
            if ($v === '' || strpbrk($v, ",\"\r\n") !== false) {
                return '"'.str_replace('"', '""', $v).'"';
            }

            return $v;
        }, array_map(fn ($c) => $row[$c] ?? null, IdentityManifest::COLUMNS)));
    }
}
