<?php

namespace App\Enums;

// Why a publication attempt failed (FB-NOTIFY-01B.2b.4): the controlled code
// stored in notification_messages.publish_failure_code (chk_nm_codes) and on
// the PUBLISH_FAILED event — never exception text. Written by the
// publication worker (FB-NOTIFY-01B.2b.5); read by RetryPublicationAction.
enum NotificationFailureCode: string
{
    // The sender lost the sending permission or the coordinator scope (ADR-04).
    case SENDER_NOT_AUTHORIZED = 'SENDER_NOT_AUTHORIZED';

    // The stored audience no longer fits the message (malformed, wrong side).
    case AUDIENCE_INVALID = 'AUDIENCE_INVALID';

    // The audience resolved to no family at publication.
    case AUDIENCE_EMPTY = 'AUDIENCE_EMPTY';

    // expires_at was reached before the message could be published.
    case EXPIRED_BEFORE_PUBLICATION = 'EXPIRED_BEFORE_PUBLICATION';

    // An unexpected, possibly transient error (the transaction rolled back).
    case PUBLICATION_ERROR = 'PUBLICATION_ERROR';

    // A PUBLISHING claim went stale (R5) and was recovered.
    case STALE_PUBLISHING = 'STALE_PUBLISHING';

    /**
     * A permanent failure is never retried: the sender must cancel. A retry
     * re-checks permission, scope, audience and expiry anyway; a permanent
     * code says that doing so cannot succeed on this message.
     */
    public function isPermanent(): bool
    {
        return match ($this) {
            self::SENDER_NOT_AUTHORIZED, self::AUDIENCE_INVALID, self::EXPIRED_BEFORE_PUBLICATION => true,
            self::AUDIENCE_EMPTY, self::PUBLICATION_ERROR, self::STALE_PUBLISHING => false,
        };
    }
}
