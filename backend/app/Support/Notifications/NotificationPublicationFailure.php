<?php

namespace App\Support\Notifications;

use App\Enums\NotificationFailureCode;
use RuntimeException;

/**
 * Internal to NotificationPublisher (FB-NOTIFY-01B.2b.5c): aborts T2 — the
 * transaction rolls back — and carries the stable failure code (and an
 * optional cause CODE for the audit metadata) to T3. Never a message, a
 * value or another exception's text.
 */
final class NotificationPublicationFailure extends RuntimeException
{
    public function __construct(
        public readonly NotificationFailureCode $failure,
        public readonly ?string $cause = null,
    ) {
        parent::__construct($failure->value);
    }
}
