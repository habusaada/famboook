<?php

namespace App\Support\IdentityRef;

/**
 * Read-only validation pass over every file of a manifest
 * (FB-CIVIL-01A-P2), before any staging row is written: one streaming read
 * per file checks the BOM, the exact header, every record's structure
 * (36 fields, quoting, UTF-8, no NUL), the record count and the SHA-256.
 *
 * Problems are issue rows without values: code, safe file name, line.
 */
final class IdentitySourceVerifier
{
    public const CHECKSUM_MISMATCH = 'CHECKSUM_MISMATCH';

    public const ROW_COUNT_MISMATCH = 'ROW_COUNT_MISMATCH';

    /**
     * @return list<array{file: string, code: string, line: ?int}> empty when every file is valid
     */
    public function verify(IdentityManifest $manifest): array
    {
        $problems = [];
        foreach ($manifest->files as $file) {
            $problem = $this->verifyFile($manifest, $file);
            if ($problem !== null) {
                $problems[] = $problem;
            }
        }

        return $problems;
    }

    /**
     * @param  array{file: string, rows: int, sha256: string}  $file
     * @return array{file: string, code: string, line: ?int}|null
     */
    public function verifyFile(IdentityManifest $manifest, array $file): ?array
    {
        $reader = new IdentityCsvReader($manifest->path($file['file']), $file['file']);
        try {
            if ($reader->open() !== IdentityManifest::COLUMNS) {
                return ['file' => $file['file'], 'code' => IdentityCsvException::HEADER_MISMATCH, 'line' => 1];
            }
            $count = 0;
            foreach ($reader->records() as $record) {
                self::assertFieldCount($record, $file['file']);
                $count++;
            }
        } catch (IdentityCsvException $e) {
            return ['file' => $file['file'], 'code' => $e->reason, 'line' => $e->lineNo];
        } finally {
            $reader->close();
        }
        if ($reader->sha256() !== $file['sha256']) {
            return ['file' => $file['file'], 'code' => self::CHECKSUM_MISMATCH, 'line' => null];
        }
        if ($count !== $file['rows']) {
            return ['file' => $file['file'], 'code' => self::ROW_COUNT_MISMATCH, 'line' => null];
        }

        return null;
    }

    /** @param array{line_no: int, fields: list<string|null>} $record */
    public static function assertFieldCount(array $record, string $fileName): void
    {
        if (count($record['fields']) !== count(IdentityManifest::COLUMNS)) {
            throw new IdentityCsvException(IdentityCsvException::FIELD_COUNT, $fileName, $record['line_no']);
        }
    }
}
