<?php

namespace App\Support\CivilImport;

use RuntimeException;

/**
 * The import worker refuses to start (FB-CIVIL-01A-UI U2): a fixed code only,
 * e.g. CONTROL_LOGIN_NOT_ISOLATED or WORKER_ENTRYPOINT_REQUIRED.
 */
final class CivilImportWorkerException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('['.$reason.'] The civil import worker refused to start.');
    }
}
