<?php

namespace Tests\Support\FamilyPortal;

use App\Support\CivilLookup\CivilRecord;
use App\Support\CivilLookup\CivilRecordPage;
use App\Support\CivilLookup\CivilRegistrySource;
use App\Support\FamilyPortal\RegistryAdmission\RegistryChildSource;

/**
 * An in-memory RegistryChildSource for ADD-WP-06A: SYNTHETIC records only. Mirrors the
 * identity_ref_lookup.children() contract (exact fatherid / motherid, cap 100, no rows
 * when too broad) and records every call. It can also return rows it should not
 * (a non-child, duplicate rows) so discovery's defences can be tested.
 */
final class SyntheticRegistryChildSource implements RegistryChildSource
{
    /** @var list<CivilRecord> */
    private array $records = [];

    /** @var list<CivilRecord> rows returned for any parent, as a faulty source would */
    public array $stray = [];

    /** @var list<array{string, string, int, int}> */
    public array $calls = [];

    /** A synthetic record; fields override a complete, plausible default. */
    public function add(string $mainid, array $fields = []): self
    {
        $this->records[] = $this->make($mainid, $fields);

        return $this;
    }

    public function make(string $mainid, array $fields = []): CivilRecord
    {
        return new CivilRecord(1, 1000 + count($this->records) + count($this->stray), $mainid, [
            'mainid' => $mainid, 'fullname' => 'اسم-تجريبي '.$mainid, 'gender' => 'ذكر', 'maritalst' => 'اعزب',
            'datebirth' => '2010-01-15T00:00:00', 'fatherid' => '', 'motherid' => '', 'householdid' => '', 'partnerid' => '',
            'phonenum' => '0590000000', 'deathdate' => '', 'presonstate' => '', ...$fields,
        ]);
    }

    public function childrenOf(string $parentNationalId, int $offset, int $limit): CivilRecordPage
    {
        $this->calls[] = ['childrenOf', $parentNationalId, $offset, $limit];
        $hits = array_values(array_filter($this->records, fn (CivilRecord $r) => ($r->payload['fatherid'] !== '' && $r->payload['fatherid'] === $parentNationalId)
            || ($r->payload['motherid'] !== '' && $r->payload['motherid'] === $parentNationalId)));
        $hits = [...array_reverse($hits), ...$this->stray];
        $cap = CivilRegistrySource::LINKS_CAP;
        $total = min(count($hits), $cap + 1);

        return new CivilRecordPage(1, $total, $total > $cap ? [] : array_slice($hits, $offset, $limit), $cap);
    }

    public function recordOf(string $nationalId): ?CivilRecord
    {
        $this->calls[] = ['recordOf', $nationalId, 0, 1];
        foreach ($this->records as $record) {
            if ($record->mainid === $nationalId) {
                return $record;
            }
        }

        return null;
    }
}
