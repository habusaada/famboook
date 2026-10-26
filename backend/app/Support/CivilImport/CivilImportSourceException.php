<?php

namespace App\Support\CivilImport;

use RuntimeException;

/** A source cannot be used now (FB-CIVIL-01A-UI U2): a reason code and problem codes only. */
final class CivilImportSourceException extends RuntimeException
{
    /** @param array<string, int> $problems */
    public function __construct(public readonly string $reason, public readonly array $problems = [])
    {
        parent::__construct($reason);
    }
}
