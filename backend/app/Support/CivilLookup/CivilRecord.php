<?php

namespace App\Support\CivilLookup;

/** One record of the active published release, as the lookup functions return it (relgion already removed). */
final class CivilRecord
{
    /** @param  array<string, string|null>  $payload */
    public function __construct(
        public readonly int $releaseId,
        public readonly int $recordId,
        public readonly string $mainid,
        public readonly array $payload,
    ) {}
}
