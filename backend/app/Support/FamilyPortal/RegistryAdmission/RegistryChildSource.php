<?php

namespace App\Support\FamilyPortal\RegistryAdmission;

use App\Exceptions\CivilLookupException;
use App\Support\CivilLookup\CivilRecord;
use App\Support\CivilLookup\CivilRecordPage;

/**
 * The read-only civil records that suggested-children discovery may read
 * (FB-FAMILY-ADD-01 ADD-WP-06A). Deliberately NOT bound in the container: only a
 * caller that passes an implementation explicitly can run discovery, so the real
 * registry can never be reached by accident. Only synthetic sources exist (tests/,
 * ADD-WP-06B-4); an adapter over the real registry needs its own approved work package.
 *
 * Contract (ADD-WP-06B-4; mirrors identity_ref_lookup.children()), checked by
 * SuggestedChildrenDiscovery, which fails closed on any breach:
 *
 * - childrenOf(): exact, non-empty fatherid / motherid matches in ONE published
 *   release, named by the page's releaseId (NULL only when none is published), in a
 *   deterministic order, with cap = CivilRegistrySource::LINKS_CAP. A bounded probe:
 *   read at most cap + 1 matches; total = min(matches, cap + 1), so a larger count
 *   is never learnt or reported; more than cap returns NO rows; otherwise every
 *   match (offset 0, limit cap) and count(rows) = total. Every row carries the
 *   page's release.
 * - recordOf(): the record of one National ID in the release the caller names (the
 *   page's), or NULL when it has none there. If that release is no longer the
 *   published one, throw CivilLookupException RELEASE_CHANGED: never a record of
 *   another release.
 * - Failures to read are CivilLookupException; nothing else is thrown for them.
 */
interface RegistryChildSource
{
    public function childrenOf(#[\SensitiveParameter] string $parentNationalId, int $offset, int $limit): CivilRecordPage;

    /**
     * The record of one National ID in $releaseId, or NULL.
     *
     * @throws CivilLookupException RELEASE_CHANGED when $releaseId is no longer published
     */
    public function recordOf(#[\SensitiveParameter] string $nationalId, int $releaseId): ?CivilRecord;
}
