<?php

namespace App\Support\CivilImport;

use RuntimeException;

/** Thrown at a committed chunk boundary when the operator cancelled the RUNNING request (FB-CIVIL-01A-UI U2). */
final class CivilImportCancelled extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('CANCELLED_BY_OPERATOR');
    }
}
