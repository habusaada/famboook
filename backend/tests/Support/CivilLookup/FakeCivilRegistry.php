<?php

namespace Tests\Support\CivilLookup;

use App\Exceptions\CivilLookupException;
use App\Support\CivilLookup\ArabicNameNormalizer;
use App\Support\CivilLookup\CivilRecord;
use App\Support\CivilLookup\CivilRecordPage;
use App\Support\CivilLookup\CivilRegistrySource;

/**
 * An in-memory stand-in for the identity_ref_lookup functions (FB-CIVIL-02A),
 * for the SQLite API tests: one active release, the same normalization, token
 * matching, ordering, page size and caps. SYNTHETIC data only. The real
 * functions are covered by CivilLookupPostgresTest.
 */
final class FakeCivilRegistry implements CivilRegistrySource
{
    /** @var array<string, array{id: int, payload: array<string, string>}> mainid => record */
    private array $records = [];

    public ?int $releaseId = 1;

    public bool $fail = false;

    public int $reads = 0;

    /** @param  array<string, string>  $fields */
    public function add(string $mainid, array $fields = []): self
    {
        $this->records[$mainid] = ['id' => count($this->records) + 1, 'payload' => array_merge(['fullname' => 'اسم-تجريبي عائلة-تجريبية',
            'name1' => 'اسم-تجريبي', 'father' => '', 'grandfath' => '', 'family' => 'عائلة-تجريبية', 'gender' => '1', 'datebirth' => '1990-01-15T00:00:00',
            'fatherid' => '', 'motherid' => '', 'partnerid' => '', 'householdid' => '', 'phonenum' => '', 'worker' => '0'], $fields, ['mainid' => $mainid])];

        return $this;
    }

    public function activeReleaseId(): ?int
    {
        $this->read();

        return $this->releaseId;
    }

    public function byMainid(string $mainid): ?CivilRecord
    {
        $this->read();

        return $this->releaseId === null || ! isset($this->records[$mainid]) ? null : $this->record($mainid);
    }

    public function byRecord(int $recordId): ?CivilRecord
    {
        $this->read();
        foreach ($this->records as $mainid => $r) {
            if ($r['id'] === $recordId && $this->releaseId !== null) {
                return $this->record($mainid);
            }
        }

        return null;
    }

    public function existingMainids(array $mainids): array
    {
        $this->read();

        return ['release_id' => $this->releaseId, 'mainids' => $this->releaseId === null ? [] : array_values(array_intersect($mainids, array_keys($this->records)))];
    }

    public function searchFullname(array $tokens, int $offset, int $limit): CivilRecordPage
    {
        return $this->list(fn (array $p) => $this->containsAll(ArabicNameNormalizer::normalize($p['fullname'] ?? ''), $tokens), $offset, $limit, self::SEARCH_CAP);
    }

    public function searchParts(array $parts, int $offset, int $limit): CivilRecordPage
    {
        return $this->list(function (array $p) use ($parts) {
            foreach ($parts as $key => $value) {
                if ($value !== null && ! str_contains(ArabicNameNormalizer::normalize($p[$key] ?? ''), $value)) {
                    return false;
                }
            }

            return true;
        }, $offset, $limit, self::SEARCH_CAP);
    }

    public function householdMembers(string $householdId, string $excludeMainid, int $offset, int $limit): CivilRecordPage
    {
        return $this->list(fn (array $p) => ($p['householdid'] ?? '') === $householdId && $p['mainid'] !== $excludeMainid, $offset, $limit, self::LINKS_CAP);
    }

    public function children(string $mainid, int $offset, int $limit): CivilRecordPage
    {
        return $this->list(fn (array $p) => ($p['fatherid'] ?? '') === $mainid || ($p['motherid'] ?? '') === $mainid, $offset, $limit, self::LINKS_CAP);
    }

    private function list(\Closure $match, int $offset, int $limit, int $cap): CivilRecordPage
    {
        $this->read();
        if ($this->releaseId === null) {
            return new CivilRecordPage(null, 0, [], $cap);
        }
        $hits = array_keys(array_filter($this->records, fn (array $r) => $match($r['payload'])));
        usort($hits, fn ($a, $b) => [ArabicNameNormalizer::normalize($this->records[$a]['payload']['fullname'] ?? ''), $this->records[$a]['id']]
            <=> [ArabicNameNormalizer::normalize($this->records[$b]['payload']['fullname'] ?? ''), $this->records[$b]['id']]);
        $total = min(count($hits), $cap + 1);
        $rows = $total > $cap ? [] : array_map(fn (string $m) => $this->record($m), array_slice($hits, $offset, $limit));

        return new CivilRecordPage($this->releaseId, $total, $rows, $cap);
    }

    /** @param  list<string>  $tokens */
    private function containsAll(string $haystack, array $tokens): bool
    {
        foreach ($tokens as $token) {
            if (! str_contains($haystack, $token)) {
                return false;
            }
        }

        return true;
    }

    private function record(string $mainid): CivilRecord
    {
        return new CivilRecord((int) $this->releaseId, $this->records[$mainid]['id'], $mainid, $this->records[$mainid]['payload']);
    }

    private function read(): void
    {
        $this->reads++;
        if ($this->fail) {
            throw new CivilLookupException(CivilLookupException::UNAVAILABLE);
        }
    }
}
