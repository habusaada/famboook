<?php

namespace App\Actions\CivilLookup;

use App\Exceptions\CivilLookupException;
use App\Models\User;
use App\Support\CivilLookup\CivilLookupAccess;
use App\Support\CivilLookup\CivilLookupAudit;
use App\Support\CivilLookup\CivilLookupContext;
use App\Support\CivilLookup\CivilLookupDisclosure;
use App\Support\CivilLookup\CivilLookupFieldPolicy;
use App\Support\CivilLookup\CivilLookupGate;
use App\Support\CivilLookup\CivilLookupQuota;
use App\Support\CivilLookup\CivilLookupRefs;
use App\Support\CivilLookup\CivilRecord;
use App\Support\CivilLookup\CivilRecordPage;
use App\Support\CivilLookup\CivilRegistrySource;
use App\Support\CivilLookup\EffectivePolicy;
use Closure;

/**
 * The Civil Registry Lookup (FB-CIVIL-02A, docs/adr/FB-CIVIL-02A-CIVIL-REGISTRY-LOOKUP.md).
 *
 * Every operation authorizes first (CivilLookupAccess), reads the ACTIVE
 * published release only (CivilRegistrySource) and is audited
 * (CivilLookupAudit). Charged operations follow check → execute → consume
 * (§L-6): a failed read consumes nothing; when the atomic consume fails the
 * result is withheld (QUOTA_EXHAUSTED).
 *
 *   charged   byNationalId, byName, byParts (including NOT_FOUND / TOO_BROAD),
 *             openLink (a linked citizen), links (a reverse-link list)
 *   free      page (same search), openRecord (listed in an authorized result) — except
 *             FB-CIVIL-02F: at STANDARD the first full-ID disclosure of a record each
 *             Gaza day is charged once (CivilLookupDisclosure), unless the requester
 *             supplied that record's exact National ID (an exact-ID result reference)
 *
 * Callers pass validated, normalized criteria; the National ID and terms
 * never leave this class except inside encrypted references.
 *
 * FB-CIVIL-02B (ADR 02B §B-1): every operation takes the lookup context the
 * route fixed (CivilLookupContext). The context selects the authorization
 * rules and the field profile, is bound into every reference and recorded in
 * every audit row; the quota and the registry read are the same for all.
 */
final class LookupCivilRegistryAction
{
    public const PER_PAGE = 25;

    /** Relation => source field of the forward links (§L-8). */
    private const FORWARD_LINKS = ['FATHER' => 'fatherid', 'MOTHER' => 'motherid', 'SPOUSE' => 'partnerid'];

    public const REVERSE_LINKS = ['HOUSEHOLD', 'CHILDREN'];

    public function __construct(private readonly CivilRegistrySource $registry) {}

    /** @return array<string, mixed> */
    public function byNationalId(User $user, string $context, #[\SensitiveParameter] string $nationalId, ?string $ip): array
    {
        $policy = $this->authorize($user, $context, CivilLookupAccess::SEARCH, 'SEARCH_BY_ID', $ip);
        [$record, $used] = $this->charged($user, $context, $policy, 'SEARCH_BY_ID', 'id:'.$nationalId, $ip,
            fn () => $this->registry->byMainid($nationalId) ?? $this->requireRelease(),
            fn ($r) => $r instanceof CivilRecord ? ['FOUND', $r->releaseId, $r->recordId, 1] : ['NOT_FOUND', $r, null, 0]);

        $found = $record instanceof CivilRecord;

        return [
            'release_id' => $found ? $record->releaseId : $record,
            'outcome' => $found ? 'FOUND' : 'NOT_FOUND',
            // FB-CIVIL-02F: the requester supplied this exact ID, so opening it discloses nothing new.
            'results' => $found ? [$this->listEntry($user, $context, $record, exactId: true)] : [],
            'meta' => ['page' => 1, 'per_page' => self::PER_PAGE, 'total' => $found ? 1 : 0, 'last_page' => 1],
            'search_ref' => null,
            'quota' => CivilLookupQuota::status($user, $context) + ['used_after' => $used],
        ];
    }

    /**
     * @param  list<string>  $tokens  normalized (ArabicNameNormalizer)
     * @return array<string, mixed>
     */
    public function byName(User $user, string $context, array $tokens, ?string $ip): array
    {
        return $this->search($user, $context, 'SEARCH_BY_NAME', 'NAME', ['tokens' => $tokens], 'name:'.implode(' ', $tokens), $ip);
    }

    /**
     * @param  array{name1: ?string, father: ?string, grandfath: ?string, family: ?string}  $parts  normalized; NULL = not given
     * @return array<string, mixed>
     */
    public function byParts(User $user, string $context, array $parts, ?string $ip): array
    {
        return $this->search($user, $context, 'SEARCH_BY_PARTS', 'PARTS', ['parts' => $parts], 'parts:'.json_encode($parts, JSON_UNESCAPED_UNICODE), $ip);
    }

    /** Another page of an authorized search: not charged (§L-6). @return array<string, mixed> */
    public function page(User $user, string $context, mixed $searchRef, int $page, ?string $ip): array
    {
        $this->authorize($user, $context, CivilLookupAccess::SEARCH, 'SEARCH_PAGE', $ip);
        $ref = CivilLookupRefs::read($searchRef, CivilLookupRefs::SEARCH, $user, $context);
        $kind = (string) ($ref['data']['kind'] ?? '');
        $cap = in_array($kind, self::REVERSE_LINKS, true) ? CivilRegistrySource::LINKS_CAP : CivilRegistrySource::SEARCH_CAP;
        if ($page < 1 || $page > intdiv($cap, self::PER_PAGE)) {
            throw new CivilLookupException(CivilLookupException::REFERENCE_INVALID);
        }
        if (in_array($kind, self::REVERSE_LINKS, true) && ! CivilLookupGate::reverseLinksEnabled()) {
            throw new CivilLookupException(CivilLookupException::LINKS_DISABLED);
        }
        $result = $this->execute($kind, $ref['data'], ($page - 1) * self::PER_PAGE);
        if ($result->releaseId !== $ref['release_id']) {
            throw new CivilLookupException(CivilLookupException::RELEASE_CHANGED);
        }
        CivilLookupAudit::record('SEARCH_PAGE', $user, $context, $result->tooBroad() ? 'TOO_BROAD' : 'RESULTS', $result->releaseId,
            criteria: $this->criteria($kind, $ref['data']), resultCount: count($result->rows), quotaUsedAfter: CivilLookupQuota::used($user), ip: $ip);

        return $this->listResponse($user, $context, $result, $page, $searchRef);
    }

    /** A record listed in an authorized result: not charged (§L-6). @return array<string, mixed> */
    public function openRecord(User $user, string $context, mixed $recordRef, ?string $ip): array
    {
        $policy = $this->authorize($user, $context, CivilLookupAccess::VIEW, 'RECORD_OPENED', $ip);
        $ref = CivilLookupRefs::read($recordRef, CivilLookupRefs::RECORD, $user, $context);
        $record = $this->registry->byRecord((int) ($ref['data']['i'] ?? 0));
        if ($record === null || $record->releaseId !== $ref['release_id']) {
            throw new CivilLookupException(CivilLookupException::RELEASE_CHANGED);
        }
        // FB-CIVIL-02F: read → charge → disclose. A refused charge withholds the record.
        if (CivilLookupFieldPolicy::chargesDisclosure($this->level($user, $context))) {
            try {
                CivilLookupDisclosure::charge($user, $policy, $record, exempt: ($ref['data']['e'] ?? null) === 1);
            } catch (CivilLookupException $e) {
                CivilLookupAudit::record('RECORD_OPENED', $user, $context, $e->reason, $record->releaseId, $record->recordId,
                    quotaUsedAfter: CivilLookupQuota::used($user), ip: $ip);

                throw $e;
            }
        }
        CivilLookupAudit::record('RECORD_OPENED', $user, $context, 'FOUND', $record->releaseId, $record->recordId,
            resultCount: 1, quotaUsedAfter: CivilLookupQuota::used($user), ip: $ip);

        return ['outcome' => 'FOUND', 'release_id' => $record->releaseId, 'record' => $this->detail($user, $context, $record),
            'quota' => CivilLookupQuota::status($user, $context)];
    }

    /** A linked citizen: a new lookup, authorized and charged again (§L-8). @return array<string, mixed> */
    public function openLink(User $user, string $context, mixed $linkRef, ?string $ip): array
    {
        $policy = $this->authorize($user, $context, CivilLookupAccess::VIEW, 'LINK_OPENED', $ip);
        $ref = CivilLookupRefs::read($linkRef, CivilLookupRefs::LINK, $user, $context);
        $mainid = (string) ($ref['data']['m'] ?? '');
        [$record, $used] = $this->charged($user, $context, $policy, 'LINK_OPENED', 'id:'.$mainid, $ip,
            function () use ($mainid, $ref) {
                $record = $this->registry->byMainid($mainid);
                if ($record !== null && $record->releaseId !== $ref['release_id']) {
                    throw new CivilLookupException(CivilLookupException::RELEASE_CHANGED);
                }

                return $record ?? $this->requireRelease();
            },
            fn ($r) => $r instanceof CivilRecord ? ['FOUND', $r->releaseId, $r->recordId, 1] : ['NOT_FOUND', $r, null, 0]);
        $found = $record instanceof CivilRecord;
        // FB-CIVIL-02F: the link lookup was charged; reopening this record today is then free.
        if ($found && CivilLookupFieldPolicy::chargesDisclosure($this->level($user, $context))) {
            CivilLookupDisclosure::mark($user, $record);
        }

        return ['outcome' => $found ? 'FOUND' : 'NOT_FOUND', 'release_id' => $found ? $record->releaseId : $record,
            'relation' => (string) ($ref['data']['rel'] ?? ''), 'record' => $found ? $this->detail($user, $context, $record) : null,
            'quota' => CivilLookupQuota::status($user, $context) + ['used_after' => $used]];
    }

    /** A reverse-link list (household members, children): charged; disabled unless enabled (§L-8). @return array<string, mixed> */
    public function links(User $user, string $context, mixed $linksRef, string $relation, ?string $ip): array
    {
        if (! CivilLookupGate::reverseLinksEnabled()) {
            throw new CivilLookupException(CivilLookupException::LINKS_DISABLED);
        }
        $policy = $this->authorize($user, $context, CivilLookupAccess::VIEW, 'LINKS_LISTED', $ip);
        $ref = CivilLookupRefs::read($linksRef, CivilLookupRefs::LINKS, $user, $context);
        $data = ['mainid' => (string) ($ref['data']['m'] ?? ''), 'householdid' => (string) ($ref['data']['h'] ?? '')];
        if (! in_array($relation, self::REVERSE_LINKS, true) || ($relation === 'HOUSEHOLD' && $data['householdid'] === '')) {
            throw new CivilLookupException(CivilLookupException::REFERENCE_INVALID);
        }
        [$result, $used] = $this->charged($user, $context, $policy, 'LINKS_LISTED', strtolower($relation).':'.$data['mainid'], $ip,
            function () use ($relation, $data, $ref) {
                $result = $this->execute($relation, $data, 0);
                if ($result->releaseId !== $ref['release_id']) {
                    throw new CivilLookupException(CivilLookupException::RELEASE_CHANGED);
                }

                return $result;
            },
            fn (CivilRecordPage $p) => [$p->tooBroad() ? 'TOO_BROAD' : (count($p->rows) > 0 ? 'RESULTS' : 'NOT_FOUND'), $p->releaseId, null, count($p->rows)]);
        $searchRef = CivilLookupRefs::issue(CivilLookupRefs::SEARCH, $user, $context, (int) $result->releaseId, ['kind' => $relation] + $data);

        return ['relation' => $relation] + $this->listResponse($user, $context, $result, 1, $searchRef, $used);
    }

    // ---------------------------------------------------------------- internals

    /**
     * @param  array<string, mixed>  $criteria
     * @return array<string, mixed>
     */
    private function search(User $user, string $context, string $event, string $kind, array $criteria, string $fingerprint, ?string $ip): array
    {
        $policy = $this->authorize($user, $context, CivilLookupAccess::SEARCH, $event, $ip);
        [$result, $used] = $this->charged($user, $context, $policy, $event, $fingerprint, $ip,
            fn () => $this->execute($kind, $criteria, 0),
            fn (CivilRecordPage $p) => [$p->tooBroad() ? 'TOO_BROAD' : (count($p->rows) > 0 ? 'RESULTS' : 'NOT_FOUND'), $p->releaseId, null, count($p->rows)]);
        $searchRef = CivilLookupRefs::issue(CivilLookupRefs::SEARCH, $user, $context, (int) $result->releaseId, ['kind' => $kind] + $criteria);

        return $this->listResponse($user, $context, $result, 1, $searchRef, $used);
    }

    /** @param  array<string, mixed>  $data */
    private function execute(string $kind, array $data, int $offset): CivilRecordPage
    {
        $page = match ($kind) {
            'NAME' => $this->registry->searchFullname(array_values($data['tokens'] ?? []), $offset, self::PER_PAGE),
            'PARTS' => $this->registry->searchParts($data['parts'] ?? [], $offset, self::PER_PAGE),
            'HOUSEHOLD' => $this->registry->householdMembers((string) $data['householdid'], (string) $data['mainid'], $offset, self::PER_PAGE),
            'CHILDREN' => $this->registry->children((string) $data['mainid'], $offset, self::PER_PAGE),
            default => throw new CivilLookupException(CivilLookupException::REFERENCE_INVALID),
        };
        if ($page->releaseId === null) {
            throw new CivilLookupException(CivilLookupException::NO_PUBLISHED_RELEASE);
        }

        return $page;
    }

    /** @param  array<string, mixed>  $data */
    private function criteria(string $kind, array $data): string
    {
        return match ($kind) {
            'NAME' => 'name:'.implode(' ', $data['tokens'] ?? []),
            'PARTS' => 'parts:'.json_encode($data['parts'] ?? [], JSON_UNESCAPED_UNICODE),
            default => strtolower($kind).':'.($data['mainid'] ?? ''),
        };
    }

    /** The active release id when a lookup found nothing; refuses when no release is published (nothing charged). */
    private function requireRelease(): int
    {
        return $this->registry->activeReleaseId() ?? throw new CivilLookupException(CivilLookupException::NO_PUBLISHED_RELEASE);
    }

    /** @throws CivilLookupException NOT_ALLOWED (audited) */
    private function authorize(User $user, string $context, string $permission, string $event, ?string $ip): EffectivePolicy
    {
        $result = CivilLookupAccess::evaluate($user, $context, $permission);
        if ($result['allowed']) {
            return $result['policy'];
        }
        if ($result['reason'] === CivilLookupException::NOT_ALLOWED) {
            CivilLookupAudit::record('LOOKUP_REFUSED', $user, $context, 'NOT_ALLOWED', ip: $ip);
        }
        throw new CivilLookupException((string) $result['reason']);
    }

    /**
     * check → execute → consume, audited with the outcome.
     *
     * @param  Closure(): mixed  $execute
     * @param  Closure(mixed): array{0: string, 1: ?int, 2: ?int, 3: int}  $outcome  [code, release, record, count]
     * @return array{0: mixed, 1: int}
     */
    private function charged(User $user, string $context, EffectivePolicy $policy, string $event, #[\SensitiveParameter] string $criteria, ?string $ip,
        Closure $execute, Closure $outcome): array
    {
        try {
            CivilLookupQuota::assertAvailable($user, $policy);
            $result = $execute();
            [$code, $release, $record, $count] = $outcome($result);
            $used = CivilLookupQuota::consume($user, $policy);
        } catch (CivilLookupException $e) {
            if ($e->reason === CivilLookupException::QUOTA_EXHAUSTED) {
                CivilLookupAudit::record($event, $user, $context, 'QUOTA_EXHAUSTED', criteria: $criteria, quotaUsedAfter: CivilLookupQuota::used($user), ip: $ip);
            }
            throw $e;
        }
        CivilLookupAudit::record($event, $user, $context, $code, $release, $record, $criteria, $count, $used, $ip);

        return [$result, $used];
    }

    /** @return array<string, mixed> */
    private function listResponse(User $user, string $context, CivilRecordPage $result, int $page, string $searchRef, ?int $used = null): array
    {
        $total = $result->tooBroad() ? null : $result->total;

        return [
            'release_id' => $result->releaseId,
            'outcome' => $result->tooBroad() ? 'TOO_BROAD' : ($result->total > 0 ? 'RESULTS' : 'NOT_FOUND'),
            'results' => array_map(fn (CivilRecord $r) => $this->listEntry($user, $context, $r), $result->rows),
            'meta' => ['page' => $page, 'per_page' => self::PER_PAGE, 'total' => $total,
                'last_page' => $total === null ? 1 : max(1, (int) ceil($total / self::PER_PAGE))],
            'search_ref' => $total !== null && $total > self::PER_PAGE ? $searchRef : null,
            'quota' => CivilLookupQuota::status($user, $context) + ($used === null ? [] : ['used_after' => $used]),
        ];
    }

    /** @return array<string, mixed> */
    /** @param  bool  $exactId  FB-CIVIL-02F: an exact National ID result; the exemption is bound to this record inside the encrypted reference */
    private function listEntry(User $user, string $context, CivilRecord $record, bool $exactId = false): array
    {
        return ['ref' => CivilLookupRefs::issue(CivilLookupRefs::RECORD, $user, $context, $record->releaseId,
            ['i' => $record->recordId] + ($exactId ? ['e' => 1] : []))]
            + CivilLookupFieldPolicy::summary($record);
    }

    /** @return array<string, mixed> */
    private function detail(User $user, string $context, CivilRecord $record): array
    {
        $level = $this->level($user, $context);

        return [
            'ref' => CivilLookupRefs::issue(CivilLookupRefs::RECORD, $user, $context, $record->releaseId, ['i' => $record->recordId]),
            'national_id' => CivilLookupFieldPolicy::identifier('mainid', $record->mainid, $level),
            'fullname' => is_string($record->payload['fullname'] ?? null) && trim($record->payload['fullname']) !== '' ? $record->payload['fullname'] : null,
            'level' => $level,
            'sections' => CivilLookupFieldPolicy::sections($record, $level),
            'links' => $this->linkEntries($user, $context, $record, $level),
        ];
    }

    /**
     * The field profile: Staff by civil-lookup.view-full (FULL / STANDARD); a family-side context by its own
     * mapping (EXTENDED_CONTROLLED), never view-full — a family-side role never holds it.
     */
    private function level(User $user, string $context): string
    {
        if ($context !== CivilLookupContext::STAFF) {
            return CivilLookupFieldPolicy::CONTEXT_LEVELS[$context];
        }

        return $user->can(CivilLookupAccess::VIEW_FULL) ? CivilLookupFieldPolicy::FULL : CivilLookupFieldPolicy::STANDARD;
    }

    /**
     * Forward links resolved for existence (no charge, no data beyond existence); reverse links as references.
     *
     * @return list<array<string, mixed>>
     */
    private function linkEntries(User $user, string $context, CivilRecord $record, string $level): array
    {
        $ids = [];
        foreach (self::FORWARD_LINKS as $relation => $field) {
            $value = trim((string) ($record->payload[$field] ?? ''));
            $ids[$relation] = $value;
        }
        $candidates = array_values(array_unique(array_filter($ids, fn (string $v) => preg_match('/^[0-9]{9}$/', $v) === 1)));
        $existing = $this->registry->existingMainids($candidates);
        $present = $existing['release_id'] === $record->releaseId ? $existing['mainids'] : [];

        $links = [];
        foreach ($ids as $relation => $value) {
            $field = self::FORWARD_LINKS[$relation];
            if ($value === '') {
                $links[] = ['relation' => $relation, 'status' => 'EMPTY'];
            } elseif (! in_array($value, $present, true)) {
                $links[] = ['relation' => $relation, 'status' => 'NOT_IN_RELEASE', 'national_id' => CivilLookupFieldPolicy::identifier($field, $value, $level)];
            } else {
                $links[] = ['relation' => $relation, 'status' => 'AVAILABLE', 'national_id' => CivilLookupFieldPolicy::identifier($field, $value, $level),
                    'ref' => CivilLookupRefs::issue(CivilLookupRefs::LINK, $user, $context, $record->releaseId, ['m' => $value, 'rel' => $relation])];
            }
        }
        $householdId = trim((string) ($record->payload['householdid'] ?? ''));
        $reverse = CivilLookupGate::reverseLinksEnabled();
        $linksRef = $reverse ? CivilLookupRefs::issue(CivilLookupRefs::LINKS, $user, $context, $record->releaseId, ['m' => $record->mainid, 'h' => $householdId]) : null;
        $links[] = ['relation' => 'HOUSEHOLD', 'status' => $householdId === '' ? 'EMPTY' : ($reverse ? 'AVAILABLE' : 'DISABLED')]
            + ($reverse && $householdId !== '' ? ['ref' => $linksRef] : []);
        $links[] = ['relation' => 'CHILDREN', 'status' => $reverse ? 'AVAILABLE' : 'DISABLED'] + ($reverse ? ['ref' => $linksRef] : []);

        return $links;
    }
}
