<?php

namespace App\Support\FamilyPortal\RegistryAdmission;

use App\Support\CivilLookup\CivilRecord;
use App\Support\CivilLookup\CivilRecordPage;

/**
 * The read-only civil records that suggested-children discovery may read
 * (FB-FAMILY-ADD-01 ADD-WP-06A). Deliberately NOT bound in the container: only a
 * caller that passes an implementation explicitly can run discovery, so the real
 * registry can never be reached by accident. ADD-WP-06A has synthetic test sources
 * only; an adapter over CivilRegistrySource needs its own approved work package.
 *
 * Contract, mirroring identity_ref_lookup.children(): exact fatherid / motherid
 * matches in the active release, a deterministic order, at most
 * CivilRegistrySource::LINKS_CAP matches, and no rows when there are more.
 */
interface RegistryChildSource
{
    public function childrenOf(string $parentNationalId, int $offset, int $limit): CivilRecordPage;

    /** The record of one National ID in the active release, or NULL. */
    public function recordOf(string $nationalId): ?CivilRecord;
}
