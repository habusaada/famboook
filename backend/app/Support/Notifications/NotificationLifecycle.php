<?php

namespace App\Support\Notifications;

use App\Enums\NotificationStatus as Status;
use App\Exceptions\NotificationPublicationException;
use App\Models\NotificationMessage;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

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

    /**
     * PostgreSQL: bound every lock wait and statement of a publication-side
     * transaction well below the worker's 60 s job timeout (lock_timeout 5s,
     * statement_timeout 45s, both transaction-local).
     */
    public static function limitTransaction(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement("SET LOCAL statement_timeout = '45s'");
        }
    }

    /**
     * The message re-read under FOR UPDATE SKIP LOCKED (PostgreSQL), or NULL
     * when another session holds it — a live worker or another reconciler.
     * Never waits. (SQLite has no row locks: the plain read.)
     */
    public static function lockOrSkip(int $messageId): ?NotificationMessage
    {
        return NotificationMessage::query()->whereKey($messageId)
            ->lock(DB::getDriverName() === 'pgsql' ? 'for update skip locked' : true)
            ->first();
    }

    /** R2: an expiry, when set, at least an hour after now. */
    public static function assertExpiryLead(?DateTimeInterface $expiresAt): void
    {
        if ($expiresAt !== null && $expiresAt->getTimestamp() < now()->getTimestamp() + self::MIN_EXPIRY_LEAD_SECONDS) {
            throw new NotificationPublicationException(NotificationPublicationException::EXPIRY_TOO_SOON);
        }
    }
}
