<?php

namespace App\Support\FamilyPortal\RegistryAdmission;

use App\Enums\Gender;
use App\Enums\RegistryChildReason as Reason;
use App\Enums\RegistryChildStatus;
use App\Support\CivilLookup\CivilRecord;
use App\Support\CivilLookup\CivilRecordPage;
use App\Support\CivilLookup\CivilRegistrySource;
use App\Support\FamilyAuth\FamilyAccessResult;
use App\Support\FamilyAuth\FamilyNationalId;
use App\Support\NationalIdMask;
use InvalidArgumentException;
use LogicException;

/**
 * Internal, read-only discovery of the signed-in household head's suggested children
 * (FB-FAMILY-ADD-01 ADD-WP-06A; ADR §5, §7 Discovery, §8). Not wired to any route,
 * job, command or listener, and it does not read the flag: that is the entry point's
 * job (E-1), in a later work package.
 *
 * - The parent is the head of the family.context result, never a supplied value.
 * - Parentage is fatherid / motherid only, exact (G-03 open: provisional). A record
 *   naming the head as neither parent is dropped. Marriage or heading another
 *   household never hides a child.
 * - Every child is classified by RegistryChildEligibility::evaluate(); discovery adds
 *   only the cross-record reasons it alone can see (duplicate rows, contradictory
 *   parent links). Nothing can be admitted from here.
 * - It reads at most CivilRegistrySource::LINKS_CAP source records in one call,
 *   orders them by National ID and evaluates only the requested page, reading the
 *   page's registry facts in one batch (RegistryChildFacts, ADD-WP-06B-1).
 * - It writes nothing and stores or caches nothing.
 * - Release integrity (ADD-WP-06B-3R): the release is the one the source's own page
 *   reports, never a supplied value. No release, a row or the head's record from
 *   another release, rows on a too-broad answer, or a total that does not match the
 *   rows throw RegistryChildSourceIntegrityException before anything is evaluated.
 */
final class SuggestedChildrenDiscovery
{
    public const PER_PAGE = 25;

    public const MAX_PER_PAGE = CivilRegistrySource::LINKS_CAP;

    public function __construct(private readonly RegistryChildSource $source) {}

    public function discover(FamilyAccessResult $context, int $page = 1, int $perPage = self::PER_PAGE): SuggestedChildrenPage
    {
        if ($page < 1 || $perPage < 1 || $perPage > self::MAX_PER_PAGE) {
            throw new InvalidArgumentException('The page must be at least 1 and the page size between 1 and '.self::MAX_PER_PAGE.'.');
        }
        if (! $context->hasFamilyContext()) {
            throw new LogicException('Suggested children are discovered only for an eligible household head.');
        }
        $headId = FamilyNationalId::normalize($context->person->national_id)
            ?? throw new LogicException('An eligible household head has a nine-digit National ID.');

        $found = $this->source->childrenOf($headId, 0, CivilRegistrySource::LINKS_CAP);
        $this->assertConsistent($found);
        if ($found->tooBroad()) {
            return new SuggestedChildrenPage([], $page, $perPage, 0, tooBroad: true, releaseId: $found->releaseId);
        }

        // One entry per National ID; the lowest record id stands for its duplicates.
        $groups = [];
        foreach (array_slice($found->rows, 0, CivilRegistrySource::LINKS_CAP) as $record) {
            if (CivilRegistryValues::parentLink($record, $headId) !== null) {
                $groups[$record->mainid][] = $record;
            }
        }
        ksort($groups, SORT_STRING);

        $headRecord = $this->source->recordOf($headId);
        if ($headRecord !== null && $headRecord->releaseId !== $found->releaseId) {
            throw new RegistryChildSourceIntegrityException;
        }
        $headParents = $this->parentsOf($headRecord);
        $siblings = array_map('strval', array_keys($groups));
        $pageGroups = [];
        foreach (array_slice($groups, ($page - 1) * $perPage, $perPage, true) as $records) {
            usort($records, fn (CivilRecord $a, CivilRecord $b) => $a->recordId <=> $b->recordId);
            $pageGroups[] = $records;
        }
        $facts = RegistryChildFacts::load($context->family, array_values(array_map(
            fn (array $records) => $records[0]->mainid,
            array_filter($pageGroups, fn (array $records) => RegistryChildEligibility::readsRegistry($context, $records[0])),
        )));

        $children = [];
        foreach ($pageGroups as $records) {
            $children[] = $this->present($context, $records[0], count($records) > 1, $headParents, $siblings, $headId, $facts);
        }

        return new SuggestedChildrenPage($children, $page, $perPage, count($groups), releaseId: $found->releaseId);
    }

    /** The page names one valid release, and its rows and total agree with it. */
    private function assertConsistent(CivilRecordPage $found): void
    {
        $cap = CivilRegistrySource::LINKS_CAP;
        $consistent = $found->releaseId !== null && $found->releaseId >= 1 && $found->cap === $cap
            && ($found->tooBroad()
                ? $found->rows === [] && $found->total === $cap + 1
                : $found->total >= 0 && count($found->rows) === $found->total);
        foreach ($found->rows as $record) {
            $consistent = $consistent && $record->releaseId === $found->releaseId;
        }
        if (! $consistent) {
            throw new RegistryChildSourceIntegrityException;
        }
    }

    /**
     * @param  list<string>  $headParents
     * @param  list<string>  $siblings
     */
    private function present(FamilyAccessResult $context, CivilRecord $record, bool $duplicated, array $headParents, array $siblings, string $headId, RegistryChildFacts $facts): SuggestedChild
    {
        $assessment = RegistryChildEligibility::evaluate($context, $record, $facts);
        $extra = [];
        if ($duplicated) {
            $extra[] = Reason::DUPLICATE_SOURCE_ROWS;
        }
        // The head's own record names this child as a parent, or the child's other parent
        // is the child itself or one of the head's other suggested children.
        $otherParents = array_diff($this->parentsOf($record), [$headId]);
        if (in_array($record->mainid, $headParents, true)
            || in_array($record->mainid, $otherParents, true)
            || array_intersect($otherParents, $siblings) !== []) {
            $extra[] = Reason::PARENTAGE_ANOMALY;
        }
        if ($extra !== []) {
            $assessment = new RegistryChildAssessment([...$assessment->reasons, ...$extra], $assessment->parentLink, $assessment->gender);
        }

        $status = $assessment->publicStatus();

        return new SuggestedChild(
            CivilRegistryValues::fullName($record),
            NationalIdMask::mask($record->mainid),
            CivilRegistryValues::date($record->payload['datebirth'] ?? null),
            $this->relationship($assessment, $status),
            $status,
        );
    }

    /** Only an APPROVED gender mapping names the relationship, and never for a refusal. */
    private function relationship(RegistryChildAssessment $assessment, RegistryChildStatus $status): ?string
    {
        if (! CivilRegistryValues::MAPPINGS_APPROVED || $status === RegistryChildStatus::NOT_ELIGIBLE) {
            return null;
        }

        return match ($assessment->gender) {
            Gender::MALE => 'SON',
            Gender::FEMALE => 'DAUGHTER',
            default => null,
        };
    }

    /** @return list<string> the non-empty fatherid / motherid of a record */
    private function parentsOf(?CivilRecord $record): array
    {
        if ($record === null) {
            return [];
        }

        return array_values(array_filter(
            [$record->payload['fatherid'] ?? null, $record->payload['motherid'] ?? null],
            fn ($id) => is_string($id) && $id !== '',
        ));
    }
}
