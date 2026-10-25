<?php

namespace App\Support\IdentityRef;

use RuntimeException;

/** The manifest or the directory inventory was refused; `problems` are fixed codes (safe file names at most). */
final class IdentityManifestException extends RuntimeException
{
    /** @param list<string> $problems */
    public function __construct(public readonly array $problems)
    {
        parent::__construct('Identity manifest refused: '.implode('; ', $problems));
    }
}
