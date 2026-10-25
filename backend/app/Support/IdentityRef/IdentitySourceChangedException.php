<?php

namespace App\Support\IdentityRef;

use RuntimeException;

/** A verified source file no longer matches its manifest entry while being staged (content, structure or count). */
final class IdentitySourceChangedException extends RuntimeException
{
    public function __construct(public readonly string $fileName)
    {
        parent::__construct('SOURCE_CHANGED '.$fileName);
    }
}
