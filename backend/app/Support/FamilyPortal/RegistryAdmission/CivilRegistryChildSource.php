<?php

namespace App\Support\FamilyPortal\RegistryAdmission;

use App\Exceptions\CivilLookupException;
use App\Support\CivilLookup\CivilRecord;
use App\Support\CivilLookup\CivilRecordPage;
use App\Support\CivilLookup\CivilRegistrySource;

/**
 * The published civil registry as the suggested-children source (FB-FAMILY-ADD-01
 * RR-02), over CivilRegistrySource (in operation CivilRegistryReader and its
 * identity_ref_lookup functions, 08-lookup.dba.sql). Bound ONLY by
 * RegistryAdmissionBindings when `family_registry_admission.civil_registry_binding` is
 * true, which it is not by default. It never reads anything else.
 *
 * - childrenOf(): identity_ref_lookup.children() pages at most 25 rows, so the bounded
 *   read of the contract (offset 0, every match up to LINKS_CAP) is read in pages of 25.
 *   The first page carries the capped total; a too-broad answer (total > cap) is
 *   returned as is, with no rows. Every further page must name the SAME release and the
 *   same total: a release published in between is RELEASE_CHANGED, never a mix.
 * - recordOf(): by_mainid() reads the ACTIVE release only. A record of another release,
 *   or no record while another release is active, is RELEASE_CHANGED: never a record of
 *   another release.
 * - Every failure to read stays a CivilLookupException (the reader's own).
 */
final class CivilRegistryChildSource implements RegistryChildSource
{
    /** The page size of identity_ref_lookup.children() (assert_page). */
    public const SOURCE_PAGE = 25;

    public function __construct(private readonly CivilRegistrySource $registry) {}

    public function childrenOf(#[\SensitiveParameter] string $parentNationalId, int $offset, int $limit): CivilRecordPage
    {
        $cap = CivilRegistrySource::LINKS_CAP;
        $first = $this->registry->children($parentNationalId, 0, self::SOURCE_PAGE);
        if ($first->releaseId === null || $first->total > $cap) {
            return $first;
        }
        $rows = $first->rows;
        for ($at = self::SOURCE_PAGE; $at < $first->total; $at += self::SOURCE_PAGE) {
            $next = $this->registry->children($parentNationalId, $at, self::SOURCE_PAGE);
            if ($next->releaseId !== $first->releaseId || $next->total !== $first->total) {
                throw new CivilLookupException(CivilLookupException::RELEASE_CHANGED);
            }
            array_push($rows, ...$next->rows);
        }

        return new CivilRecordPage($first->releaseId, $first->total, array_slice($rows, max(0, $offset), max(0, $limit)), $cap);
    }

    public function recordOf(#[\SensitiveParameter] string $nationalId, int $releaseId): ?CivilRecord
    {
        $record = $this->registry->byMainid($nationalId);
        if ($record !== null) {
            if ($record->releaseId !== $releaseId) {
                throw new CivilLookupException(CivilLookupException::RELEASE_CHANGED);
            }

            return $record;
        }
        if ($this->registry->activeReleaseId() !== $releaseId) {
            throw new CivilLookupException(CivilLookupException::RELEASE_CHANGED);
        }

        return null;
    }
}
