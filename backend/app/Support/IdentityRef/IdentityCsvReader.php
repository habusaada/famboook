<?php

namespace App\Support\IdentityRef;

use Generator;

/**
 * Bounded-memory reader for one identity source file (FB-CIVIL-01A-P2):
 * UTF-8 with BOM, a header line, comma-separated RFC 4180 records.
 *
 * - Streams line by line; at most one record (≤ $maxRecordBytes) is held.
 * - Values are returned exactly as written: never trimmed, cast or decoded,
 *   so leading zeros, spaces and unknown codes survive. Quoted fields may
 *   contain commas, doubled quotes and line breaks.
 * - NULL vs '': an UNQUOTED empty field is NULL; a quoted empty field ("")
 *   is the empty string — the only distinction CSV can carry.
 * - Every byte read is fed to a SHA-256 context, so one pass both parses and
 *   verifies the file (sha256() after the last record).
 * - Errors are IdentityCsvException: code, file name, line — never a value.
 *
 * Each record: record_no (1 = first after the header), line_no (physical
 * line where it starts; the header is line 1), the fields, its raw bytes
 * (for the chunk checksum) and its byte range in the file.
 */
final class IdentityCsvReader
{
    private const BOM = "\xEF\xBB\xBF";

    /** @var resource|null */
    private $handle = null;

    private \HashContext $hash;

    private int $offset = 0;

    private int $line = 0;

    private ?string $digest = null;

    public function __construct(
        private readonly string $path,
        private readonly string $fileName,
        private readonly int $maxRecordBytes = 1_048_576,
    ) {}

    /**
     * Opens the file, checks the BOM and returns the header fields.
     *
     * @return list<string|null>
     */
    public function open(): array
    {
        $handle = @fopen($this->path, 'rb');
        if ($handle === false) {
            throw new IdentityCsvException(IdentityCsvException::UNREADABLE, $this->fileName);
        }
        $this->handle = $handle;
        $this->hash = hash_init('sha256');

        $bom = fread($handle, 3);
        if ($bom === false || $bom === '') {
            throw new IdentityCsvException(IdentityCsvException::EMPTY_FILE, $this->fileName);
        }
        $this->consume($bom);
        if ($bom !== self::BOM) {
            throw new IdentityCsvException(IdentityCsvException::BOM_MISSING, $this->fileName);
        }
        $header = $this->nextRecord();
        if ($header === null) {
            throw new IdentityCsvException(IdentityCsvException::EMPTY_FILE, $this->fileName, 1);
        }

        return $header['fields'];
    }

    /**
     * @return Generator<int, array{record_no: int, line_no: int, fields: list<string|null>, raw: string, byte_start: int, byte_end: int}>
     */
    public function records(): Generator
    {
        $recordNo = 0;
        while (($record = $this->nextRecord()) !== null) {
            $record['record_no'] = ++$recordNo;
            yield $record;
        }
        $this->digest = hash_final($this->hash);
        fclose($this->handle);
        $this->handle = null;
    }

    /** Lower-case hex SHA-256 of the whole file; available after records() is exhausted. */
    public function sha256(): ?string
    {
        return $this->digest;
    }

    public function close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    /** @return array{line_no: int, fields: list<string|null>, raw: string, byte_start: int, byte_end: int}|null */
    private function nextRecord(): ?array
    {
        $start = $this->offset;
        $lineNo = $this->line + 1;
        $raw = $this->readLine($lineNo);
        if ($raw === null) {
            return null;
        }
        while (true) {
            $body = self::stripTerminator($raw);
            if (str_contains($body, "\0")) {
                throw new IdentityCsvException(IdentityCsvException::NUL_BYTE, $this->fileName, $lineNo);
            }
            $fields = $this->parse($body, $lineNo);
            if ($fields !== null) {
                break;
            }
            // Inside a quoted field: the line break belongs to the value.
            $more = $this->readLine($lineNo, strlen($raw));
            if ($more === null) {
                throw new IdentityCsvException(IdentityCsvException::UNTERMINATED_QUOTE, $this->fileName, $lineNo);
            }
            $raw .= $more;
        }
        if (! mb_check_encoding($raw, 'UTF-8')) {
            throw new IdentityCsvException(IdentityCsvException::INVALID_UTF8, $this->fileName, $lineNo);
        }

        return ['line_no' => $lineNo, 'fields' => $fields, 'raw' => $raw, 'byte_start' => $start, 'byte_end' => $this->offset];
    }

    private function readLine(int $recordLine, int $alreadyRead = 0): ?string
    {
        $limit = $this->maxRecordBytes - $alreadyRead;
        if ($limit <= 0) {
            throw new IdentityCsvException(IdentityCsvException::RECORD_TOO_LONG, $this->fileName, $recordLine);
        }
        $line = fgets($this->handle, $limit + 1);
        if ($line === false) {
            if (! feof($this->handle)) {
                throw new IdentityCsvException(IdentityCsvException::UNREADABLE, $this->fileName, $recordLine);
            }

            return null;
        }
        if (strlen($line) >= $limit && ! str_ends_with($line, "\n") && ! feof($this->handle)) {
            throw new IdentityCsvException(IdentityCsvException::RECORD_TOO_LONG, $this->fileName, $recordLine);
        }
        $this->consume($line);
        $this->line++;

        return $line;
    }

    private function consume(string $bytes): void
    {
        hash_update($this->hash, $bytes);
        $this->offset += strlen($bytes);
    }

    private static function stripTerminator(string $raw): string
    {
        if (str_ends_with($raw, "\r\n")) {
            return substr($raw, 0, -2);
        }

        return str_ends_with($raw, "\n") ? substr($raw, 0, -1) : $raw;
    }

    /**
     * Parses one record body; NULL when it ends inside a quoted field (the
     * caller then appends the next physical line).
     *
     * @return list<string|null>|null
     */
    private function parse(string $s, int $lineNo): ?array
    {
        if (! str_contains($s, '"')) {
            return array_map(static fn (string $v): ?string => $v === '' ? null : $v, explode(',', $s));
        }

        $fields = [];
        $len = strlen($s);
        $i = 0;
        while (true) {
            if ($i < $len && $s[$i] === '"') {
                $value = '';
                $i++;
                while (true) {
                    $q = strpos($s, '"', $i);
                    if ($q === false) {
                        return null;
                    }
                    $value .= substr($s, $i, $q - $i);
                    if ($q + 1 < $len && $s[$q + 1] === '"') {
                        $value .= '"';
                        $i = $q + 2;

                        continue;
                    }
                    $i = $q + 1;
                    break;
                }
                $fields[] = $value;
                if ($i === $len) {
                    break;
                }
                if ($s[$i] !== ',') {
                    throw new IdentityCsvException(IdentityCsvException::TEXT_AFTER_CLOSING_QUOTE, $this->fileName, $lineNo);
                }
            } else {
                $comma = strpos($s, ',', $i);
                $end = $comma === false ? $len : $comma;
                $value = substr($s, $i, $end - $i);
                if (str_contains($value, '"')) {
                    throw new IdentityCsvException(IdentityCsvException::QUOTE_IN_UNQUOTED_FIELD, $this->fileName, $lineNo);
                }
                $fields[] = $value === '' ? null : $value;
                if ($comma === false) {
                    break;
                }
                $i = $comma;
            }
            // $s[$i] is a comma: another field follows (possibly empty at the end).
            $i++;
            if ($i === $len) {
                $fields[] = null;
                break;
            }
        }

        return $fields;
    }
}
