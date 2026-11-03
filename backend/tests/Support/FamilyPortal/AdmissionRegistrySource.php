<?php

namespace Tests\Support\FamilyPortal;

use App\Exceptions\CivilLookupException;
use App\Support\CivilLookup\CivilRecord;
use App\Support\CivilLookup\CivilRecordPage;
use App\Support\CivilLookup\CivilRegistrySource;
use App\Support\FamilyPortal\RegistryAdmission\RegistryChildSource;
use LogicException;

/**
 * TESTS ONLY (ADD-WP-07B-2c): an in-memory RegistryChildSource of SYNTHETIC records in
 * one published release that a test can replace (publish()), honouring the
 * RegistryChildSource contract: exact fatherid / motherid, bounded probe, the page's
 * release on every row, recordOf() in a replaced release is RELEASE_CHANGED. Bound
 * nowhere; refused outside the test runner. It can be rebuilt from a plain array, which is
 * how a separate test process receives it.
 */
final class AdmissionRegistrySource implements RegistryChildSource
{
    /** @var array<string, array<string, string>> mainid => payload */
    private array $records = [];

    public int $reads = 0;

    /** @var list<string> mainids returned twice, as a source with duplicate rows would */
    public array $duplicated = [];

    public function __construct(public int $release = 7)
    {
        if (! app()->runningUnitTests()) {
            throw new LogicException('The synthetic admission source runs in tests only.');
        }
    }

    /** @param  array{release: int, records: array<string, array<string, string>>}  $spec */
    public static function fromArray(array $spec): self
    {
        $source = new self($spec['release']);
        foreach ($spec['records'] as $mainid => $fields) {
            $source->records[(string) $mainid] = $fields;
        }

        return $source;
    }

    /** @return array{release: int, records: array<string, array<string, string>>} */
    public function toArray(): array
    {
        return ['release' => $this->release, 'records' => $this->records];
    }

    /** A synthetic record; fields override a complete, plausible default. */
    public function add(string $mainid, array $fields = []): self
    {
        $this->records[$mainid] = [
            'mainid' => $mainid, 'fullname' => 'اسم-تجريبي '.$mainid, 'gender' => 'ذكر', 'maritalst' => 'اعزب',
            'datebirth' => '2010-01-15T00:00:00', 'fatherid' => '', 'motherid' => '', 'householdid' => '', 'partnerid' => '',
            'phonenum' => '0590000000', 'deathdate' => '', 'presonstate' => '', ...$fields,
        ];

        return $this;
    }

    public function publish(int $release): void
    {
        $this->release = $release;
    }

    public function childrenOf(#[\SensitiveParameter] string $parentNationalId, int $offset, int $limit): CivilRecordPage
    {
        $this->reads++;
        $hits = [];
        $id = 1000;
        foreach ($this->records as $mainid => $payload) {
            $id++;
            if (($payload['fatherid'] !== '' && $payload['fatherid'] === $parentNationalId) || ($payload['motherid'] !== '' && $payload['motherid'] === $parentNationalId)) {
                $hits[] = new CivilRecord($this->release, $id, (string) $mainid, $payload);
                if (in_array((string) $mainid, $this->duplicated, true)) {
                    $hits[] = new CivilRecord($this->release, $id + 500, (string) $mainid, $payload);
                }
            }
        }
        $cap = CivilRegistrySource::LINKS_CAP;
        $total = min(count($hits), $cap + 1);

        return new CivilRecordPage($this->release, $total, $total > $cap ? [] : array_slice($hits, $offset, $limit), $cap);
    }

    public function recordOf(#[\SensitiveParameter] string $nationalId, int $releaseId): ?CivilRecord
    {
        $this->reads++;
        if ($releaseId !== $this->release) {
            throw new CivilLookupException(CivilLookupException::RELEASE_CHANGED);
        }
        $payload = $this->records[$nationalId] ?? null;

        return $payload === null ? null : new CivilRecord($this->release, 1, $nationalId, $payload);
    }
}
