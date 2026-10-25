<?php

namespace App\Support\IdentityRef;

use RuntimeException;

/** A committed staging checkpoint does not match the file being re-read; the file is reset and reloaded. */
final class IdentityCheckpointMismatchException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('CHECKPOINT_MISMATCH');
    }
}
