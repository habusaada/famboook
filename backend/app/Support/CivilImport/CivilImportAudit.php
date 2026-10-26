<?php

namespace App\Support\CivilImport;

use App\Models\CivilImportAuditEvent;
use App\Models\User;
use InvalidArgumentException;

/**
 * The only writer of civil_import_audit_events (FB-CIVIL-01A-UI, ADR §8).
 * Details are restricted to allow-listed keys holding codes and integers, so
 * no identity value, file name, path or SQL can ever be recorded.
 */
final class CivilImportAudit
{
    public const LOCATIONS_VIEWED = 'LOCATIONS_VIEWED';

    /** U1 writes LOCATIONS_VIEWED only; the other types arrive with U2/U3. */
    public const EVENT_TYPES = [
        'SOURCE_CATALOGUED', 'SOURCE_UNAVAILABLE', 'REQUEST_QUEUED', 'REQUEST_CANCELLED', 'REQUEST_STARTED', 'REQUEST_SUCCEEDED',
        'REQUEST_FAILED', 'PUBLICATION_REQUESTED', 'PUBLICATION_WITHDRAWN', 'PUBLICATION_APPROVED', 'PUBLICATION_REJECTED',
        'PUBLICATION_EXPIRED', 'PUBLICATION_REFUSED', 'RELEASE_PUBLISHED', self::LOCATIONS_VIEWED,
    ];

    /** Allowed detail keys: a code (uppercase) or a non-negative integer each. */
    public const DETAIL_KEYS = ['scope', 'code', 'type', 'count', 'rows', 'files', 'file_no', 'page', 'duration_seconds', 'expected_presence'];

    /**
     * @param  array<string, string|int>  $details
     */
    public static function record(string $eventType, ?User $actor, ?string $runId = null, ?int $releaseId = null,
        ?string $outcomeCode = null, array $details = []): CivilImportAuditEvent
    {
        if (! in_array($eventType, self::EVENT_TYPES, true)) {
            throw new InvalidArgumentException('Unknown civil import audit event type.');
        }
        foreach ($details as $key => $value) {
            $valid = in_array($key, self::DETAIL_KEYS, true)
                && ((is_int($value) && $value >= 0) || (is_string($value) && preg_match('/^[A-Z][A-Z0-9_]{0,63}$/', $value) === 1));
            if (! $valid) {
                throw new InvalidArgumentException('Civil import audit details accept allow-listed codes and counts only.');
            }
        }

        return CivilImportAuditEvent::create([
            'event_type' => $eventType,
            'actor_user_id' => $actor?->id,
            'actor_side' => $actor === null ? 'SYSTEM' : 'STAFF',
            'run_id' => $runId,
            'release_id' => $releaseId,
            'outcome_code' => $outcomeCode,
            'details' => $details === [] ? null : $details,
        ]);
    }
}
