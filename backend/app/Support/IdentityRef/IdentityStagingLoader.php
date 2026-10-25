<?php

namespace App\Support\IdentityRef;

use Closure;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Loads one verified source file into identity_ref_staging in chunks
 * (FB-CIVIL-01A-P2), resuming after the last committed checkpoint.
 *
 * One chunk = one transaction: the checkpoint row (record range, byte range,
 * SHA-256 of the raw bytes), the rows via COPY FROM STDIN, and the file/run
 * progress counters. The database refuses a checkpoint whose rows are not
 * all present at COMMIT, so a crash leaves either the whole chunk or nothing.
 *
 * Resume: the staged state must agree with the checkpoints (row count,
 * highest record, the file's loaded_rows), and while re-reading the file
 * every checkpointed chunk's raw bytes must hash to its recorded SHA-256 at
 * the same byte range. Any disagreement resets the file (its staging is
 * deleted and it is loaded again from the first record) — rows are never
 * skipped or duplicated on trust.
 */
final class IdentityStagingLoader
{
    /**
     * @param  Closure(string, array<string, mixed>): void  $hook  test seam: 'chunk.before_commit', 'chunk.committed'
     */
    public function __construct(
        private readonly Connection $db,
        private readonly int $chunkSize,
        private readonly Closure $hook,
    ) {}

    /**
     * @param  array{id: int, run_id: string, file_name: string, expected_rows: int, expected_sha256: string, loaded_rows: int}  $file
     * @return array{chunks_verified: int, chunks_loaded: int, rows_loaded: int, reset: bool}
     *
     * @throws IdentitySourceChangedException
     */
    public function load(string $path, array $file): array
    {
        $reset = false;
        if (! $this->checkpointsConsistent($file)) {
            $this->reset($file);
            $reset = true;
        }
        try {
            $result = $this->stream($path, $file);
        } catch (IdentityCheckpointMismatchException) {
            $this->reset($file);
            $reset = true;
            $result = $this->stream($path, $file);
        }

        return $result + ['reset' => $reset];
    }

    /** @param array{id: int, run_id: string, loaded_rows: int} $file */
    private function checkpointsConsistent(array $file): bool
    {
        $c = $this->db->selectOne('SELECT count(*) AS n, coalesce(sum(row_count), 0) AS rows, coalesce(max(last_record), 0) AS last
            FROM identity_ref_staging.file_chunks WHERE file_id = ?', [$file['id']]);
        $r = $this->db->selectOne('SELECT count(*) AS n, coalesce(max(record_no), 0) AS last FROM identity_ref_staging.rows WHERE file_id = ?', [$file['id']]);
        $loaded = (int) $this->db->selectOne('SELECT loaded_rows FROM identity_ref.import_files WHERE id = ?', [$file['id']])->loaded_rows;

        return (int) $c->rows === (int) $r->n && (int) $c->last === (int) $r->last && (int) $c->rows === $loaded;
    }

    /** @param array{id: int, run_id: string} $file */
    private function reset(array $file): void
    {
        $this->db->transaction(function () use ($file) {
            $this->db->delete('DELETE FROM identity_ref_staging.rows WHERE file_id = ?', [$file['id']]);
            $this->db->delete('DELETE FROM identity_ref_staging.file_chunks WHERE file_id = ?', [$file['id']]);
            $this->db->update('UPDATE identity_ref.import_files SET loaded_rows = 0 WHERE id = ?', [$file['id']]);
            $this->updateRunProgress($file['run_id']);
        });
    }

    /**
     * @param  array{id: int, run_id: string, file_name: string, expected_rows: int, expected_sha256: string}  $file
     * @return array{chunks_verified: int, chunks_loaded: int, rows_loaded: int}
     */
    private function stream(string $path, array $file): array
    {
        $checkpoints = array_map(fn ($c) => [
            'chunk_no' => (int) $c->chunk_no, 'first' => (int) $c->first_record, 'last' => (int) $c->last_record,
            'byte_start' => (int) $c->byte_start, 'byte_end' => (int) $c->byte_end, 'sha256' => $c->sha,
        ], $this->db->select("SELECT chunk_no, first_record, last_record, byte_start, byte_end, encode(chunk_sha256, 'hex') AS sha
            FROM identity_ref_staging.file_chunks WHERE file_id = ? ORDER BY chunk_no", [$file['id']]));
        $resumeAfter = $checkpoints === [] ? 0 : end($checkpoints)['last'];

        $reader = new IdentityCsvReader($path, $file['file_name']);
        $verified = 0;
        $loadedChunks = 0;
        $loadedRows = 0;
        $chunkNo = count($checkpoints);
        $buffer = [];
        $bufferRaw = null;
        $bufferStart = 0;
        $bufferEnd = 0;
        $check = null;
        $count = 0;
        try {
            if ($reader->open() !== IdentityManifest::COLUMNS) {
                throw new IdentitySourceChangedException($file['file_name']);
            }
            foreach ($reader->records() as $record) {
                $count++;
                try {
                    IdentitySourceVerifier::assertFieldCount($record, $file['file_name']);
                } catch (IdentityCsvException) {
                    throw new IdentitySourceChangedException($file['file_name']);
                }

                if ($record['record_no'] <= $resumeAfter) {
                    // Verify the committed checkpoint this record belongs to.
                    $cp = $checkpoints[$verified];
                    if ($record['record_no'] === $cp['first']) {
                        if ($record['byte_start'] !== $cp['byte_start']) {
                            throw new IdentityCheckpointMismatchException;
                        }
                        $check = hash_init('sha256');
                    }
                    hash_update($check, $record['raw']);
                    if ($record['record_no'] === $cp['last']) {
                        if ($record['byte_end'] !== $cp['byte_end'] || hash_final($check) !== $cp['sha256']) {
                            throw new IdentityCheckpointMismatchException;
                        }
                        $verified++;
                    }

                    continue;
                }

                if ($buffer === []) {
                    $bufferRaw = hash_init('sha256');
                    $bufferStart = $record['byte_start'];
                }
                hash_update($bufferRaw, $record['raw']);
                $bufferEnd = $record['byte_end'];
                $buffer[] = $record;
                if (count($buffer) >= $this->chunkSize) {
                    $this->commitChunk($file, ++$chunkNo, $buffer, $bufferStart, $bufferEnd, hash_final($bufferRaw));
                    $loadedChunks++;
                    $loadedRows += count($buffer);
                    $buffer = [];
                }
            }
            if ($buffer !== []) {
                $this->commitChunk($file, ++$chunkNo, $buffer, $bufferStart, $bufferEnd, hash_final($bufferRaw));
                $loadedChunks++;
                $loadedRows += count($buffer);
            }
        } catch (IdentityCsvException) {
            throw new IdentitySourceChangedException($file['file_name']);
        } finally {
            $reader->close();
        }
        if ($verified !== count($checkpoints)) {
            throw new IdentityCheckpointMismatchException;
        }
        if ($reader->sha256() !== $file['expected_sha256'] || $count !== $file['expected_rows']) {
            throw new IdentitySourceChangedException($file['file_name']);
        }

        return ['chunks_verified' => $verified, 'chunks_loaded' => $loadedChunks, 'rows_loaded' => $loadedRows];
    }

    /**
     * @param  array{id: int, run_id: string, file_name: string}  $file
     * @param  list<array{record_no: int, line_no: int, fields: list<string|null>}>  $records
     */
    private function commitChunk(array $file, int $chunkNo, array $records, int $byteStart, int $byteEnd, string $sha256): void
    {
        $lines = [];
        foreach ($records as $r) {
            $json = json_encode(array_combine(IdentityManifest::COLUMNS, $r['fields']),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $lines[] = $file['id']."\t".$chunkNo."\t".$r['record_no']."\t".$r['line_no']."\t".self::copyText($json);
        }
        $first = $records[0]['record_no'];
        $last = $records[count($records) - 1]['record_no'];
        $context = ['file' => $file['file_name'], 'chunk_no' => $chunkNo, 'rows' => count($records)];

        $this->db->transaction(function () use ($file, $chunkNo, $first, $last, $records, $byteStart, $byteEnd, $sha256, $lines, $context) {
            $this->db->insert("INSERT INTO identity_ref_staging.file_chunks
                    (file_id, chunk_no, first_record, last_record, row_count, byte_start, byte_end, chunk_sha256)
                VALUES (?, ?, ?, ?, ?, ?, ?, decode(?, 'hex'))",
                [$file['id'], $chunkNo, $first, $last, count($records), $byteStart, $byteEnd, $sha256]);
            if (! $this->db->getPdo()->pgsqlCopyFromArray('identity_ref_staging.rows', $lines, "\t", '\\\\N',
                'file_id,chunk_no,record_no,line_no,source_payload')) {
                throw new RuntimeException('IDENTITY_REF_STAGING_COPY_FAILED');
            }
            $this->db->update('UPDATE identity_ref.import_files SET loaded_rows = loaded_rows + ? WHERE id = ?', [count($records), $file['id']]);
            $this->updateRunProgress($file['run_id']);
            ($this->hook)('chunk.before_commit', $context);
        });
        ($this->hook)('chunk.committed', $context);
    }

    private function updateRunProgress(string $runId): void
    {
        $this->db->update('UPDATE identity_ref.import_runs
            SET rows_loaded = (SELECT coalesce(sum(loaded_rows), 0) FROM identity_ref.import_files WHERE run_id = ?) WHERE id = ?', [$runId, $runId]);
    }

    /** PostgreSQL COPY text format: backslash and control characters escaped. */
    private static function copyText(string $value): string
    {
        return strtr($value, ['\\' => '\\\\', "\t" => '\\t', "\n" => '\\n', "\r" => '\\r']);
    }
}
