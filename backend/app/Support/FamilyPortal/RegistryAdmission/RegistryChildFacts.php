<?php

namespace App\Support\FamilyPortal\RegistryAdmission;

use App\Enums\ChangeRequestStatus;
use App\Enums\ChangeRequestType;
use App\Models\ChangeRequest;
use App\Models\Family;
use App\Models\Person;
use App\Support\NationalId;
use App\Support\NationalIdGuard;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * The application-registry facts of one discovery page, read in a bounded number of
 * queries (FB-FAMILY-ADD-01 ADD-WP-06B-1). It answers exactly what
 * RegistryChildEligibility asks per child, with the same semantics:
 *
 * - holders(): NationalIdGuard::equivalentMatches() — non-deleted Persons whose stored
 *   National ID is equivalent under NationalId::normalize(), at most MAX_MATCHES + 1,
 *   by id, with the same relations loaded;
 * - heldByDeleted(): NationalIdGuard::heldByDeleted() — a soft-deleted Person with
 *   exactly this stored value;
 * - requestOpen(): an open ADD_FAMILY_MEMBER request of this Family whose
 *   submitted_data national_id is exactly this value.
 *
 * Holder matching uses one of two strategies, decided per build:
 *
 * - INDEXED_EXACT, on PostgreSQL only when the catalog shows the VALIDATED format
 *   CHECK chk_person_national_id_format and the unique index uq_persons_national_id
 *   on persons. Then every stored value is nine ASCII digits, on which normalize() is
 *   the identity, so an exact match is the equivalent match. A migration file alone
 *   proves nothing; the catalog is read every time.
 * - NORMALIZED_SCAN otherwise (SQLite, constraints absent or NOT VALID): ONE chunked
 *   pass over persons per page, instead of one per child. Still proportional to the
 *   number of Persons.
 *
 * Read-only, no locks: for display. The admission action must evaluate again under
 * its locks (ADR §10).
 */
final class RegistryChildFacts
{
    public const INDEXED_EXACT = 'INDEXED_EXACT';

    public const NORMALIZED_SCAN = 'NORMALIZED_SCAN';

    public const MAX_IDS = SuggestedChildrenDiscovery::MAX_PER_PAGE;

    /**
     * @param  array<string, Collection<int, Person>>  $holders
     * @param  array<string, true>  $deleted
     * @param  array<string, true>  $open
     */
    private function __construct(
        public readonly int $familyId,
        public readonly string $strategy,
        private readonly array $holders,
        private readonly array $deleted,
        private readonly array $open,
    ) {}

    /** @param  list<string>  $nationalIds  canonical nine-digit values */
    public static function load(Family $family, #[\SensitiveParameter] array $nationalIds): self
    {
        $ids = array_values(array_unique($nationalIds));
        if (count($ids) > self::MAX_IDS) {
            throw new InvalidArgumentException('At most '.self::MAX_IDS.' National IDs per page.');
        }
        foreach ($ids as $id) {
            if (! CivilRegistryValues::validNationalId($id)) {
                throw new InvalidArgumentException('Facts are read for valid nine-digit National IDs only.');
            }
        }

        $strategy = self::canonicalStorageProven() ? self::INDEXED_EXACT : self::NORMALIZED_SCAN;
        $holders = [];
        $deleted = [];
        $open = [];
        if ($ids !== []) {
            $holders = self::readHolders($ids, $strategy);
            $deleted = array_fill_keys(
                array_map('strval', Person::onlyTrashed()->whereIn('national_id', $ids)->pluck('national_id')->all()), true);
            $open = self::openRequests($family, $ids);
        }

        return new self((int) $family->getKey(), $strategy, $holders, $deleted, $open);
    }

    /** @return Collection<int, Person> */
    public function holders(#[\SensitiveParameter] string $nationalId): Collection
    {
        return $this->holders[$nationalId] ?? throw new LogicException('No facts were read for this National ID.');
    }

    public function heldByDeleted(#[\SensitiveParameter] string $nationalId): bool
    {
        $this->holders($nationalId);

        return isset($this->deleted[$nationalId]);
    }

    public function requestOpen(#[\SensitiveParameter] string $nationalId): bool
    {
        $this->holders($nationalId);

        return isset($this->open[$nationalId]);
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, Collection<int, Person>>
     */
    private static function readHolders(array $ids, string $strategy): array
    {
        $byTarget = array_fill_keys($ids, []);
        if ($strategy === self::INDEXED_EXACT) {
            foreach (Person::query()->whereIn('national_id', $ids)->select(['id', 'national_id'])->orderBy('id')->get() as $person) {
                $byTarget[(string) $person->national_id][] = $person->id;
            }
        } else {
            $pending = count($ids);
            Person::query()->whereNotNull('national_id')->select(['id', 'national_id'])
                ->lazyById(1000)
                ->each(function (Person $person) use (&$byTarget, &$pending) {
                    $key = NationalId::normalize($person->national_id);
                    if (isset($byTarget[$key]) && count($byTarget[$key]) <= NationalIdGuard::MAX_MATCHES) {
                        $byTarget[$key][] = $person->id;
                        if (count($byTarget[$key]) > NationalIdGuard::MAX_MATCHES) {
                            $pending--;
                        }
                    }

                    // As equivalentMatches(): stop once every target is known to be ambiguous.
                    return $pending > 0;
                });
        }

        $all = array_merge(...array_values($byTarget));
        $people = $all === [] ? new Collection : Person::query()->whereKey($all)
            ->with(['activeMembership.family', 'activeMembership.relationshipType'])
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        return array_map(fn (array $personIds) => new Collection(array_map(fn (int $id) => $people[$id], $personIds)), $byTarget);
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, true>
     */
    private static function openRequests(Family $family, array $ids): array
    {
        $open = [];
        $rows = ChangeRequest::query()
            ->where('family_id', $family->getKey())
            ->where('type', ChangeRequestType::ADD_FAMILY_MEMBER)
            ->whereIn('status', ChangeRequestStatus::open())
            ->whereIn('submitted_data->national_id', $ids)
            ->get(['id', 'submitted_data']);
        foreach ($rows as $row) {
            // The SQL filter decides the match; this only says for which ID.
            $value = $row->submitted_data['national_id'] ?? null;
            if (is_scalar($value) && in_array((string) $value, $ids, true)) {
                $open[(string) $value] = true;
            }
        }

        return $open;
    }

    /**
     * Whether THIS database guarantees canonical storage now: PostgreSQL with the
     * validated format CHECK and the unique index on persons.national_id.
     */
    private static function canonicalStorageProven(): bool
    {
        if (DB::getDriverName() !== 'pgsql') {
            return false;
        }

        return (bool) DB::scalar(<<<'SQL'
            SELECT EXISTS (
                SELECT 1 FROM pg_constraint c
                WHERE c.conrelid = 'persons'::regclass AND c.conname = 'chk_person_national_id_format'
                  AND c.contype = 'c' AND c.convalidated
                  AND pg_get_constraintdef(c.oid) LIKE '%''^[0-9]{9}$''%'
            ) AND EXISTS (
                SELECT 1 FROM pg_index i JOIN pg_class x ON x.oid = i.indexrelid
                WHERE i.indrelid = 'persons'::regclass AND x.relname = 'uq_persons_national_id' AND i.indisunique AND i.indisvalid
            )
            SQL);
    }
}
