<?php

namespace App\Support\Notifications;

use App\Enums\NotificationStatus as Status;
use App\Exceptions\NotificationPublicationException;
use App\Models\NotificationMessage;
use DateTimeInterface;

/**
 * The publication lifecycle of a message (FB-NOTIFY-01B.2b.4; ADR
 * FB-NOTIFY-01 ADR-08 F1, D3, D4). The only legal moves:
 *
 *   DRAFT          → QUEUED (request) · CANCELLED
 *   QUEUED         → PUBLISHING (worker claim, 2b.5) · CANCELLED
 *   PUBLISHING     → PUBLISHED · PUBLISH_FAILED (worker, 2b.5)
 *   PUBLISH_FAILED → QUEUED (retry) · CANCELLED (history kept, D3)
 *   PUBLISHED      → ARCHIVED (expiry sweep only, D4)
 *   ARCHIVED, CANCELLED: final
 *
 * A published message is never cancelled; PUBLISHING is never cancelled
 * either (the worker owns it). The PostgreSQL CHECKs guard each state's
 * columns; this table guards the moves between them.
 */
final class NotificationLifecycle
{
    /** R2: expires_at at least this far ahead when publication is requested. */
    public const MIN_EXPIRY_LEAD_SECONDS = 3600;

    private const MOVES = [
        'DRAFT' => [Status::QUEUED, Status::CANCELLED],
        'QUEUED' => [Status::PUBLISHING, Status::CANCELLED],
        'PUBLISHING' => [Status::PUBLISHED, Status::PUBLISH_FAILED],
        'PUBLISH_FAILED' => [Status::QUEUED, Status::CANCELLED],
        'PUBLISHED' => [Status::ARCHIVED],
        'ARCHIVED' => [],
        'CANCELLED' => [],
    ];

    public static function allows(Status $from, Status $to): bool
    {
        return in_array($to, self::MOVES[$from->value], true);
    }

    public static function assertAllowed(Status $from, Status $to): void
    {
        if (! self::allows($from, $to)) {
            throw new NotificationPublicationException(NotificationPublicationException::INVALID_TRANSITION);
        }
    }

    /** The message re-read under a row lock: every transition starts here. */
    public static function lock(NotificationMessage $message): NotificationMessage
    {
        return NotificationMessage::query()->whereKey($message->getKey())->lockForUpdate()->first()
            ?? throw new NotificationPublicationException(NotificationPublicationException::NOT_FOUND);
    }

    /**
     * The publication worker's claim condition (2b.5): under the row lock,
     * the message is still QUEUED. A message cancelled after it was
     * dispatched is CANCELLED here, so a stale job publishes nothing.
     */
    public static function mayStartPublishing(NotificationMessage $locked): bool
    {
        return $locked->status === Status::QUEUED;
    }

    /** R2: an expiry, when set, at least an hour after now. */
    public static function assertExpiryLead(?DateTimeInterface $expiresAt): void
    {
        if ($expiresAt !== null && $expiresAt->getTimestamp() < now()->getTimestamp() + self::MIN_EXPIRY_LEAD_SECONDS) {
            throw new NotificationPublicationException(NotificationPublicationException::EXPIRY_TOO_SOON);
        }
    }
}
