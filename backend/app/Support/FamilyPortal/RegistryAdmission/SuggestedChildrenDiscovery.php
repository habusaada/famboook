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
 * (FB-FAMILY-ADD-01 ADD-WP-06A; ADR §5, §7 Discovery, §8). Its only caller is the
 * guarded GET /api/v1/family/household/registry-children (ADD-WP-06B-3, ADR §7b),
 * which checks the flag (OFF by default), the permission and the limits; discovery
 * itself reads none of them. No operational source is bound, so that route is not
 * operational, and there is no admission endpoint. No job, command or listener.
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
 *   The head's record is then read in that same release (ADD-WP-06B-4): a release
 *   published in between is RELEASE_CHANGED from the source.
 */
final class SuggestedChildrenDiscovery
{
    public const PER_PAGE = 25;

    public const MAX_PER_PAGE = CivilRegistrySource::LINKS_CAP;

    /**
     * $admissibility (RR-02) decides whether a reference is issued: the SAME decision the
     * admission takes. Outside the test runner only the gated one is accepted.
     */
    public function __construct(
        private readonly RegistryChildSource $source,
        private readonly RegistryChildAdmissibility $admissibility = new GatedRegistryChildAdmissibility,
    ) {
        if (! $admissibility instanceof GatedRegistryChildAdmissibility && ! app()->runningUnitTests()) {
            throw new LogicException('Only the gated admissibility may issue references outside the test runner.');
        }
    }

    /**
     * $issueRefs (RR-02): issue an admission reference for each ELIGIBLE child. The
     * caller asks only when an admission could succeed at all (a release pin verifier
     * is bound and a declaration is approved); otherwise every `ref` stays NULL.
     */
    public function discover(FamilyAccessResult $context, int $page = 1, int $perPage = self::PER_PAGE, bool $issueRefs = false): SuggestedChildrenPage
    {
        if ($page < 1 || $perPage < 1 || $perPage > self::MAX_PER_PAGE) {
            throw new InvalidArgumentException('The page must be at least 1 and the page size between 1 and '.self::MAX_PER_PAGE.'.');
        }
        if (! $context->hasFamilyContext()) {
            throw new LogicException('Suggested children are discovered only for an eligible household head.');
        }
        [$headId, $found, $groups, $headParents, $siblings] = $this->read($context);
        if ($found->tooBroad()) {
            return new SuggestedChildrenPage([], $page, $perPage, 0, tooBroad: true, releaseId: $found->releaseId);
        }

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
            $children[] = $this->present($context, $records[0], count($records) > 1, $headParents, $siblings, $headId, $facts,
                $issueRefs ? $found->releaseId : null);
        }

        return new SuggestedChildrenPage($children, $page, $perPage, count($groups), releaseId: $found->releaseId);
    }

    /**
     * The head's suggested children for an admission (ADD-WP-07B-2c): the same source
     * read, release checks and cross-record reasons as discover(), for every child of the
     * release at once, keyed by National ID. Nothing else is evaluated here; the admission
     * evaluates its child again under its own locks.
     */
    public function candidates(FamilyAccessResult $context): AdmissionCandidates
    {
        if (! $context->hasFamilyContext()) {
            throw new LogicException('Suggested children are discovered only for an eligible household head.');
        }
        [$headId, $found, $groups, $headParents, $siblings] = $this->read($context);
        if ($found->tooBroad()) {
            return new AdmissionCandidates($found->releaseId, true, []);
        }
        $candidates = [];
        foreach ($groups as $nationalId => $records) {
            usort($records, fn (CivilRecord $a, CivilRecord $b) => $a->recordId <=> $b->recordId);
            $candidates[(string) $nationalId] = new AdmissionCandidate($records[0],
                $this->sourceReasons($records[0], count($records) > 1, $headParents, $siblings, $headId));
        }

        return new AdmissionCandidates($found->releaseId, false, $candidates);
    }

    /**
     * One source read for the head: the checked page, the records naming the head as a
     * parent grouped by National ID (sorted), and the head's own record in the same release.
     *
     * @return array{0: string, 1: CivilRecordPage, 2: array<string, list<CivilRecord>>, 3: list<string>, 4: list<string>}
     */
    private function read(FamilyAccessResult $context): array
    {
        $headId = FamilyNationalId::normalize($context->person->national_id)
            ?? throw new LogicException('An eligible household head has a nine-digit National ID.');

        $found = $this->source->childrenOf($headId, 0, CivilRegistrySource::LINKS_CAP);
        $this->assertConsistent($found);
        if ($found->tooBroad()) {
            return [$headId, $found, [], [], []];
        }

        // One entry per National ID; the lowest record id stands for its duplicates.
        $groups = [];
        foreach (array_slice($found->rows, 0, CivilRegistrySource::LINKS_CAP) as $record) {
            if (CivilRegistryValues::parentLink($record, $headId) !== null) {
                $groups[$record->mainid][] = $record;
            }
        }
        ksort($groups, SORT_STRING);

        // The head is read in the page's release (ADD-WP-06B-4): a release published in between is RELEASE_CHANGED.
        $headRecord = $this->source->recordOf($headId, $found->releaseId);
        if ($headRecord !== null && $headRecord->releaseId !== $found->releaseId) {
            throw new RegistryChildSourceIntegrityException;
        }

        return [$headId, $found, $groups, $this->parentsOf($headRecord), array_map('strval', array_keys($groups))];
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
    private function present(FamilyAccessResult $context, CivilRecord $record, bool $duplicated, array $headParents, array $siblings, string $headId, RegistryChildFacts $facts, ?int $refRelease): SuggestedChild
    {
        $assessment = RegistryChildEligibility::evaluate($context, $record, $facts);
        $extra = $this->sourceReasons($record, $duplicated, $headParents, $siblings, $headId);
        if ($extra !== []) {
            $assessment = new RegistryChildAssessment([...$assessment->reasons, ...$extra], $assessment->parentLink, $assessment->gender, $assessment->livingProven, $assessment->livingByDeclaration);
        }

        $status = $assessment->publicStatus();
        // RR-02: a reference only when the admission's own admissibility admits this child
        // now (no reason, a proven life, a FATHER / MOTHER link, a mapped gender), bound to
        // this head, this release and a short expiry (RegistryAdmissionRefs, ADR §7a). The
        // admission evaluates it again under its locks.
        $ref = null;
        if ($refRelease !== null) {
            $admission = $this->admissibility->assess($context, $record, $extra);
            if ($admission->admissible()) {
                $status = RegistryChildStatus::ELIGIBLE;
                $ref = RegistryAdmissionRefs::issue($context, $refRelease, $record->mainid, $admission->parentLink);
            }
        }

        return new SuggestedChild(
            CivilRegistryValues::fullName($record),
            NationalIdMask::mask($record->mainid),
            CivilRegistryValues::date($record->payload['datebirth'] ?? null),
            $this->relationship($assessment, $status),
            $status,
            $ref,
        );
    }

    /**
     * The cross-record reasons only discovery can see.
     *
     * @param  list<string>  $headParents
     * @param  list<string>  $siblings
     * @return list<Reason>
     */
    private function sourceReasons(CivilRecord $record, bool $duplicated, array $headParents, array $siblings, #[\SensitiveParameter] string $headId): array
    {
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

        return $extra;
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
