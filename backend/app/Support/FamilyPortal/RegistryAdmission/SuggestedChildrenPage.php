<?php

namespace App\Support\FamilyPortal\RegistryAdmission;

/** One page of suggested children (FB-FAMILY-ADD-01 ADD-WP-06A). Internal only. */
final class SuggestedChildrenPage
{
    /** @param  list<SuggestedChild>  $children */
    public function __construct(
        public readonly array $children,
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $total,
        /** More matches than the source cap: nothing is listed, and nothing is guessed. */
        public readonly bool $tooBroad = false,
    ) {}
}
