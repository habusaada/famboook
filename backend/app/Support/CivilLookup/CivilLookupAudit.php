<?php

namespace App\Support\CivilLookup;

use App\Enums\FingerprintContext;
use App\Models\CivilLookupAuditEvent;
use App\Models\User;
use App\Support\AccountSide;
use App\Support\FamilyAuth\KeyedFingerprint;
use InvalidArgumentException;

/**
 * The only writer of civil_lookup_audit_events (FB-CIVIL-02A, ADR §L-10).
 * The searched value is recorded as a keyed fingerprint only (KeyedFingerprint,
 * context civil-lookup.query.v1), the IP likewise; the record is identified by
 * its internal identity_ref id. Never a National ID, a name, a term or a payload.
 * FB-CIVIL-02B: every row also names the lookup context (STAFF, FAMILY,
 * COORDINATOR) it was made from — one audit for every side.
 */
final class CivilLookupAudit
{
    public const EVENT_TYPES = ['SEARCH_BY_ID', 'SEARCH_BY_NAME', 'SEARCH_BY_PARTS', 'SEARCH_PAGE', 'RECORD_OPENED', 'LINK_OPENED',
        'LINKS_LISTED', 'LOOKUP_REFUSED'];

    public static function record(string $eventType, User $actor, string $context, string $outcome, ?int $releaseId = null, ?int $recordId = null,
        #[\SensitiveParameter] ?string $criteria = null, ?int $resultCount = null, ?int $quotaUsedAfter = null, ?string $ip = null): CivilLookupAuditEvent
    {
        if (! in_array($eventType, self::EVENT_TYPES, true) || preg_match('/^[A-Z][A-Z0-9_]{1,63}$/', $outcome) !== 1
            || ! in_array($context, CivilLookupContext::ALL, true)) {
            throw new InvalidArgumentException('Unknown civil lookup audit event or outcome.');
        }

        return CivilLookupAuditEvent::create([
            'event_type' => $eventType,
            'actor_user_id' => $actor->id,
            'actor_side' => AccountSide::of($actor) === AccountSide::FAMILY ? 'FAMILY' : 'STAFF',
            'outcome_code' => $outcome,
            'release_id' => $releaseId,
            'record_id' => $recordId,
            'query_fingerprint' => $criteria === null || $criteria === '' ? null : KeyedFingerprint::of(FingerprintContext::CIVIL_LOOKUP_QUERY, $criteria),
            'result_count' => $resultCount,
            'quota_used_after' => $quotaUsedAfter,
            'ip_hash' => $ip === null || $ip === '' ? null : KeyedFingerprint::of(FingerprintContext::CIVIL_LOOKUP_IP, $ip),
            'lookup_context' => $context,
        ]);
    }
}
