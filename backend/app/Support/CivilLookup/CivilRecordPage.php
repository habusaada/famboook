<?php

namespace App\Support\CivilLookup;

/**
 * One page of a list search: $total matches (capped at $cap + 1), and the page
 * rows — none when the search is too broad ($total > $cap).
 */
final class CivilRecordPage
{
    /** @param  list<CivilRecord>  $rows */
    public function __construct(
        public readonly ?int $releaseId,
        public readonly int $total,
        public readonly array $rows,
        public readonly int $cap,
    ) {}

    public function tooBroad(): bool
    {
        return $this->total > $this->cap;
    }
}
