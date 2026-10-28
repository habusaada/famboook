<?php

namespace App\Support\CivilLookup;

use App\Exceptions\CivilLookupException;

/**
 * The read-only civil registry behind the lookup (ADR §L-2): only the ACTIVE
 * published release, through the identity_ref_lookup functions. Bound to
 * CivilRegistryReader; SQLite tests bind a fake.
 *
 * Every method throws CivilLookupException (NOT_CONFIGURED / UNAVAILABLE) when
 * the registry cannot be read; nothing is charged for such a failure.
 */
interface CivilRegistrySource
{
    /** Matches beyond which a name search is TOO_BROAD (enforced in the database too). */
    public const SEARCH_CAP = 500;

    public const LINKS_CAP = 100;

    /** @throws CivilLookupException */
    public function activeReleaseId(): ?int;

    /** @throws CivilLookupException */
    public function byMainid(string $mainid): ?CivilRecord;

    /** @throws CivilLookupException */
    public function byRecord(int $recordId): ?CivilRecord;

    /**
     * @param  list<string>  $mainids  at most 10
     * @return array{release_id: ?int, mainids: list<string>}
     *
     * @throws CivilLookupException
     */
    public function existingMainids(array $mainids): array;

    /**
     * @param  list<string>  $tokens  normalized
     *
     * @throws CivilLookupException
     */
    public function searchFullname(array $tokens, int $offset, int $limit): CivilRecordPage;

    /**
     * @param  array{name1: ?string, father: ?string, grandfath: ?string, family: ?string}  $parts  normalized, NULL = not given
     *
     * @throws CivilLookupException
     */
    public function searchParts(array $parts, int $offset, int $limit): CivilRecordPage;

    /** @throws CivilLookupException */
    public function householdMembers(string $householdId, string $excludeMainid, int $offset, int $limit): CivilRecordPage;

    /** @throws CivilLookupException */
    public function children(string $mainid, int $offset, int $limit): CivilRecordPage;
}
