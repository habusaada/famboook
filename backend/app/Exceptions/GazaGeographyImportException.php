<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The Gaza geographic import was refused (ADR-FB-GEO-01 §3.3, §4.4). No
 * geographic record was written: the source file is missing, its checksum
 * or structure is not the expected one, a record is invalid or contradicts
 * itself, the source changes an already imported record (which needs
 * explicit authorization, Stage 1B G10), or another import is running.
 * `problems` lists every reason found, with source rows. `runId` is the
 * REJECTED import run that records the refusal (NULL for a missing file or a
 * dry run, which are not recorded).
 */
class GazaGeographyImportException extends RuntimeException
{
    public const MISSING_FILE = 'MISSING_FILE';

    public const CHECKSUM_MISMATCH = 'CHECKSUM_MISMATCH';

    public const INVALID_STRUCTURE = 'INVALID_STRUCTURE';

    public const INVALID_RECORD = 'INVALID_RECORD';

    public const SOURCE_CONFLICT = 'SOURCE_CONFLICT';

    public const REVISION_CONFLICT = 'REVISION_CONFLICT';

    public const CONCURRENT_IMPORT = 'CONCURRENT_IMPORT';

    public ?string $runId = null;

    /** @param list<string> $problems */
    public function __construct(public readonly string $reason, string $message, public readonly array $problems = [])
    {
        parent::__construct($message);
    }
}
