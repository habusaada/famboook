<?php

namespace Tests\Support\FamilyPortal;

use App\Exceptions\CivilLookupException;
use App\Support\CivilLookup\CivilRecord;
use App\Support\CivilLookup\CivilRecordPage;
use App\Support\CivilLookup\CivilRegistrySource;
use App\Support\FamilyPortal\RegistryAdmission\RegistryChildSource;
use Closure;
use InvalidArgumentException;
use LogicException;

/**
 * A synthetic-only RegistryChildSource adapter (FB-FAMILY-ADD-01 ADD-WP-06B-4).
 * It lives under tests/ (autoload-dev: never in a --no-dev build), is bound by no
 * provider, and refuses to be built outside the test runner. It reads no table:
 * its records come from a deterministic synthetic generator.
 *
 * It implements the source contract the way identity_ref_lookup.children() does,
 * so discovery can be validated against it:
 *
 * - Only records of the declared published release are read; others are skipped.
 *   No published release: a page with no release (discovery answers 503).
 * - A bounded probe: the scan stops at the LINKS_CAP + 1st match, so it never
 *   holds more than LINKS_CAP rows and never learns a larger total. total is
 *   min(matches, LINKS_CAP + 1); more than LINKS_CAP returns no rows.
 * - Exact, non-empty fatherid / motherid only, in the generator's order.
 * - recordOf() reads only the release the caller names; RELEASE_CHANGED when that
 *   release is no longer the published one.
 *
 * Limitations: the scan is linear in the dataset (a real adapter uses an index),
 * the release is a constructor value (a real one reads it per query), and
 * duplicates or anomalies exist only if the generator makes them.
 */
final class SyntheticRegistryChildAdapter implements RegistryChildSource
{
    /** @var list<array{string, string}> every call, in order */
    public array $calls = [];

    /** Records read by the last childrenOf() and the last recordOf(), and the most rows held at once. */
    public int $scanned = 0;

    public int $recordScanned = 0;

    public int $held = 0;

    /** Runs after childrenOf() returns: a release published between the two reads. */
    public ?Closure $afterChildren = null;

    /** @param  Closure(): iterable<CivilRecord>  $records */
    public function __construct(private readonly Closure $records, private ?int $publishedRelease)
    {
        if (! app()->runningUnitTests()) {
            throw new LogicException('The synthetic registry child adapter runs in tests only.');
        }
    }

    /**
     * A deterministic household: $children children of $head in $release, each also present
     * in the previous release, and $others children of other parents interleaved with them.
     */
    public static function household(string $head, int $children, int $release = 3, int $others = 0, array $fields = []): self
    {
        return new self(function () use ($head, $children, $release, $others, $fields) {
            yield self::make($release, 1, $head, ['fatherid' => '700000009']);
            for ($i = 1, $id = 2; $i <= max($children, $others); $i++) {
                if ($i <= $others) {
                    yield self::make($release, $id++, sprintf('6%08d', $i), ['fatherid' => '711111110']);
                }
                if ($i <= $children) {
                    $mainid = sprintf('5%08d', $i);
                    yield self::make($release - 1, $id++, $mainid, ['fatherid' => $head]);
                    yield self::make($release, $id++, $mainid, ['fatherid' => $head, 'fullname' => 'ابن-تجريبي '.$i.' اسم-الأب', ...$fields]);
                }
            }
        }, $release);
    }

    public static function make(int $release, int $recordId, string $mainid, array $fields = []): CivilRecord
    {
        return new CivilRecord($release, $recordId, $mainid, [
            'mainid' => $mainid, 'fullname' => 'اسم-تجريبي '.$recordId, 'gender' => 'ذكر', 'maritalst' => 'اعزب',
            'datebirth' => '2010-01-15T00:00:00', 'fatherid' => '', 'motherid' => '', 'householdid' => '', 'partnerid' => '',
            'phonenum' => '0590000000', 'deathdate' => '', 'presonstate' => '', ...$fields,
        ]);
    }

    public function publish(?int $release): void
    {
        $this->publishedRelease = $release;
    }

    public function childrenOf(string $parentNationalId, int $offset, int $limit): CivilRecordPage
    {
        $cap = CivilRegistrySource::LINKS_CAP;
        if ($offset < 0 || $limit < 1 || $limit > $cap) {
            throw new InvalidArgumentException('The offset must be at least 0 and the limit between 1 and '.$cap.'.');
        }
        $this->calls[] = ['childrenOf', $parentNationalId];
        $this->scanned = 0;
        $release = $this->publishedRelease;
        if ($release === null) {
            return new CivilRecordPage(null, 0, [], $cap);
        }

        $matches = 0;
        $rows = [];
        foreach (($this->records)() as $record) {
            $this->scanned++;
            if ($record->releaseId !== $release || ! self::isChildOf($record, $parentNationalId)) {
                continue;
            }
            if (++$matches > $cap) {
                $rows = [];
                break;
            }
            if ($matches > $offset && count($rows) < $limit) {
                $rows[] = $record;
                $this->held = max($this->held, count($rows));
            }
        }
        $page = new CivilRecordPage($release, min($matches, $cap + 1), $rows, $cap);
        if ($this->afterChildren !== null) {
            ($this->afterChildren)($this);
        }

        return $page;
    }

    public function recordOf(string $nationalId, int $releaseId): ?CivilRecord
    {
        $this->calls[] = ['recordOf', $nationalId];
        $this->recordScanned = 0;
        if ($releaseId !== $this->publishedRelease) {
            throw new CivilLookupException(CivilLookupException::RELEASE_CHANGED);
        }
        foreach (($this->records)() as $record) {
            $this->recordScanned++;
            if ($record->releaseId === $releaseId && $record->mainid === $nationalId) {
                return $record;
            }
        }

        return null;
    }

    private static function isChildOf(CivilRecord $record, string $parent): bool
    {
        foreach (['fatherid', 'motherid'] as $field) {
            $value = $record->payload[$field] ?? null;
            if (is_string($value) && $value !== '' && $value === $parent) {
                return true;
            }
        }

        return false;
    }
}
